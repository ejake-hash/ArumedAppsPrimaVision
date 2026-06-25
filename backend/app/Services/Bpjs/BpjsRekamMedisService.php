<?php

namespace App\Services\Bpjs;

use App\Models\BpjsRmLog;
use App\Models\ClinicProfile;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * BpjsRekamMedisService — WS Rekam Medis BPJS (push RM ke BPJS, mengisi i-Care).
 *
 * Kanal jalur-B (opsional): kirim FHIR R4 "document" Bundle per-kunjungan ke
 *   POST {base}/eclaim/rekammedis/insert   (Content-Type: text/plain)
 *   body { request: { noSep, jnsPelayanan, bulan, tahun, dataMR } }
 * dataMR = Bundle → gZip → AES(consId+secret+koders) → base64 ({@see BpjsRmCrypto}).
 *
 * Format mengikuti TrustMark "Medical Record Format" — banyak kekhasan non-standar:
 *   - Composition.section = OBJECT ber-key "0".."7" (bukan array).
 *   - resource = ARRAY untuk MedicationRequest / Procedure / Device.
 *   - tanggal "Y-m-d H:i:s" (tanpa T/zone).
 *   - id/reference pola {kodePPK}-{noSEPnum}-{seq}-{uuid}.
 *   - kode lokal faskes untuk obat (medications.code) & device (bhp_items.code);
 *     ICD-10 untuk Condition; ICD-9-CM untuk Procedure (BPJS contoh memakai SNOMED
 *     PROCx — KONFIRMASI saat UAT, sementara kirim ICD-9 yang tersedia).
 *
 * DiagnosticReport (+Observation) BELUM dibangun (sumber penunjang lebih kompleks);
 * di-skip aman — Bundle tetap valid untuk klinik mata rawat jalan. TODO bila perlu.
 */
class BpjsRekamMedisService
{
    private BpjsClient $client;

    // State builder (di-reset tiap buildBundle).
    private string $ppk = '0000';
    private string $seqBase = '0';
    private int $seq = 0;

    private const DRUG_SYSTEM   = 'http://primavision.co.id/drug';
    private const DEVICE_SYSTEM = 'http://primavision.co.id/device';

    public function __construct(?BpjsClient $client = null)
    {
        if ($client) {
            $this->client = $client;
        } else {
            // WS Rekam Medis = service family 'ihs' (sama i-Care). Pakai config
            // REKAM_MEDIS bila admin sudah buat; fallback ke ICARE.
            $rm = BpjsClient::for('REKAM_MEDIS');
            $this->client = $rm->isEnabled() ? $rm : BpjsClient::for('ICARE');
        }
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    // =========================================================================
    // PUBLIC — kirim
    // =========================================================================

    /**
     * Bangun + kirim rekam medis 1 kunjungan ke BPJS. Idempoten via kolom
     * visits.bpjs_rm_status (SENT) — caller boleh memaksa kirim ulang dgn $force.
     *
     * @return array{is_success:bool, metaData:array, status:string}
     */
    public function insertForVisit(string $visitId, bool $force = false): array
    {
        $visit = Visit::with([
            'patient',
            'doctorExamination.doctor',
            'prescriptions.items.medication',
            'prescriptions.prescribedBy',
            'bhpUsages.bhpItem',
        ])->findOrFail($visitId);

        if (! $visit->no_sep) {
            throw new \Exception('Kunjungan tidak memiliki nomor SEP BPJS.', 422);
        }
        if (! $force && $visit->bpjs_rm_status === 'SENT') {
            return ['is_success' => true, 'metaData' => ['code' => '200', 'message' => 'Sudah dikirim'], 'status' => 'SENT'];
        }

        $koders = $this->koders();
        if ($koders === '') {
            throw new \Exception('Kode faskes (PPK) belum dikonfigurasi (IntegrationConfig.kode_faskes / ClinicProfile.clinic_code).', 422);
        }

        $bundle = $this->buildBundle($visit);
        $dataMr = BpjsRmCrypto::encryptDataMr($bundle, $this->client->consId(), $this->client->secretKey(), $koders);

        $tgl  = Carbon::parse($visit->visit_date ?? $visit->created_at);
        $body = ['request' => [
            'noSep'        => $visit->no_sep,
            'jnsPelayanan' => $this->jnsPelayanan($visit),
            'bulan'        => $tgl->format('m'),
            'tahun'        => $tgl->format('Y'),
            'dataMR'       => $dataMr,
        ]];

        $result = $this->postInsert($body);

        $ok = $result['is_success'];
        $visit->update([
            'bpjs_rm_status'  => $ok ? 'SENT' : 'FAILED',
            'bpjs_rm_sent_at' => $ok ? now() : $visit->bpjs_rm_sent_at,
        ]);

        BpjsRmLog::create([
            'visit_id'         => $visit->id,
            'no_sep'           => $visit->no_sep,
            'action'           => 'INSERT',
            'fhir_payload'     => $bundle, // Bundle mentah (pra-enkripsi) untuk audit.
            'response_payload' => $result['raw_json'] ?? null,
            'http_status'      => $result['http_status'],
            'status'           => $ok ? 'SUCCESS' : 'FAILED',
            'error_message'    => $ok ? null : ($result['metaData']['message'] ?? null),
        ]);

        if (! $ok) {
            throw new \Exception($result['metaData']['message'] ?? 'Gagal mengirim rekam medis ke BPJS.', 422);
        }

        return ['is_success' => true, 'metaData' => $result['metaData'], 'status' => 'SENT'];
    }

    // =========================================================================
    // TRANSPORT — POST text/plain (response JSON polos, TIDAK terenkripsi)
    // =========================================================================

    private function postInsert(array $body): array
    {
        if (! $this->client->isEnabled()) {
            throw new \RuntimeException('Integrasi BPJS Rekam Medis belum aktif / kredensial kosong.', 503);
        }

        $ts      = $this->client->timestamp();
        $headers = $this->client->headers($ts);
        $url     = $this->client->baseUrl() . '/eclaim/rekammedis/insert';

        try {
            $resp = Http::withHeaders($headers)
                ->timeout(60)
                ->withBody(json_encode($body), 'text/plain')
                ->post($url);
        } catch (\Throwable $e) {
            return ['is_success' => false, 'http_status' => 0, 'metaData' => ['code' => '0', 'message' => 'Koneksi BPJS gagal: ' . $e->getMessage()], 'raw_json' => null];
        }

        $json = json_decode($resp->body(), true);
        $meta = (is_array($json) ? ($json['metadata'] ?? $json['metaData'] ?? null) : null)
            ?? ['code' => (string) $resp->status(), 'message' => $resp->body()];
        $ok   = in_array((string) ($meta['code'] ?? ''), ['200', '1'], true);

        return [
            'is_success'  => $ok,
            'http_status' => $resp->status(),
            'metaData'    => $meta,
            'raw_json'    => is_array($json) ? $json : ['raw' => $resp->body()],
        ];
    }

    // =========================================================================
    // BUNDLE BUILDER
    // =========================================================================

    /** Bangun FHIR R4 document Bundle format BPJS untuk 1 kunjungan. */
    public function buildBundle(Visit $visit): array
    {
        $this->ppk     = $this->koders() ?: '0000';
        $this->seqBase = preg_replace('/\D/', '', (string) ($visit->no_sep ?? '')) ?: '0';
        $this->seq     = 0;

        $patient = $visit->patient;
        $exam    = $visit->doctorExamination;
        $doctor  = $exam?->doctor;

        // Mint id semua resource lebih dulu (agar bisa saling refer).
        $patientId      = $this->rid();
        $encounterId    = $this->rid();
        $practitionerId = $doctor ? $this->rid() : null;
        $organizationId = $this->rid();

        $conditions = $this->buildConditions($visit, $patientId, $encounterId);
        $meds       = $this->buildMedications($visit, $patientId, $encounterId, $practitionerId, $organizationId);
        $procedures = $this->buildProcedures($visit, $patientId, $encounterId, $practitionerId);
        $devices    = $this->buildDevices($visit, $patientId);

        $entries = [];
        $entries[] = ['resource' => $this->buildComposition($visit, $patientId, $encounterId, $practitionerId, $conditions, $meds)];
        $entries[] = ['resource' => $this->buildPatient($visit, $patientId, $organizationId)];
        $entries[] = ['resource' => $this->buildEncounter($visit, $patientId, $encounterId, $practitionerId, $conditions)];
        if ($practitionerId) {
            $entries[] = ['resource' => $this->buildPractitioner($doctor, $practitionerId)];
        }
        $entries[] = ['resource' => $this->buildOrganization($organizationId)];
        foreach ($conditions as $c) {
            $entries[] = ['resource' => $c['resource']];
        }
        if ($meds) {
            $entries[] = ['resource' => array_map(fn ($m) => $m['resource'], $meds)];
        }
        if ($procedures) {
            $entries[] = ['resource' => array_map(fn ($p) => $p['resource'], $procedures)];
        }
        if ($devices) {
            $entries[] = ['resource' => array_map(fn ($d) => $d['resource'], $devices)];
        }

        return [
            'resourceType' => 'Bundle',
            'id'           => $this->rid(),
            'meta'         => ['lastUpdated' => $this->dt(now())],
            'identifier'   => ['system' => 'sep', 'value' => $visit->no_sep],
            'type'         => 'document',
            'entry'        => $entries,
        ];
    }

    // ---- Composition (section = OBJECT ber-key) ----

    private function buildComposition(Visit $visit, string $patientId, string $encounterId, ?string $practitionerId, array $conditions, array $meds): array
    {
        $exam    = $visit->doctorExamination;
        $patient = $visit->patient;
        $doctor  = $exam?->doctor;

        $dxNames = array_filter(array_map(fn ($c) => $c['display'] ?? $c['code'], $conditions));
        $medNames = array_filter(array_map(fn ($m) => $m['display'], $meds));

        $sections = [];
        $sections['0'] = $this->section('Reason for admission', '29299-5', 'Reason for visit Narrative', $exam?->diagnosis_utama_name ?? '');
        $sections['1'] = $this->section('Chief complaint', '10154-3', 'Chief complaint Narrative', $exam?->soap_subjective ?? '');
        $sections['2'] = $this->section('Admission diagnosis', '42347-5', 'Admission diagnosis Narrative',
            implode(', ', $dxNames),
            $this->refEntries('Condition', array_column($conditions, 'id')));
        $sections['3'] = $this->section('Discharge diagnosis', '78375-3', 'Discharge diagnosis Narrative',
            implode(', ', $dxNames),
            $this->refEntries('Condition', array_column($conditions, 'id')));
        if ($meds) {
            $sections['4'] = $this->section('Medications on Discharge', '75311-1', 'Hospital discharge medications Narrative',
                implode(', ', $medNames),
                $this->refEntries('MedicationRequest', array_column($meds, 'id')), 'working');
        }
        $sections['5'] = $this->section('Plan of care', '18776-5', 'Plan of care', $exam?->soap_plan ?? '', [], 'working');
        if (! empty($patient?->allergy_notes)) {
            $sections['7'] = $this->section('Known allergies', '48765-2', 'Allergies and adverse reactions', $patient->allergy_notes);
        }

        return [
            'resourceType'    => 'Composition',
            'id'              => $this->rid(),
            'status'          => 'final',
            'type'            => [
                'coding' => [['system' => 'http://loinc.org', 'code' => '81218-0']],
                'text'   => 'Discharge Summary',
            ],
            'subject'         => ['reference' => "Patient/{$patientId}", 'display' => $patient?->name],
            'encounter'       => ['reference' => "Encounter/{$encounterId}"],
            'date'            => $this->dt($visit->visit_date ?? $visit->created_at),
            'author'          => $practitionerId ? [['reference' => "Practitioner/{$practitionerId}", 'display' => $doctor?->name]] : [],
            'title'           => 'Discharge Summary',
            'confidentiality' => 'N',
            // Cast OBJECT agar JSON keluar { "0": ... } bukan array (kekhasan BPJS).
            'section'         => (object) $sections,
        ];
    }

    private function section(string $title, string $loinc, string $display, string $text, array $entries = [], ?string $mode = null): array
    {
        $s = [
            'title' => $title,
            'code'  => ['coding' => [['system' => 'http://loinc.org', 'code' => $loinc, 'display' => $display]]],
            'text'  => ['status' => 'additional', 'div' => $this->div($text)],
        ];
        if ($mode) {
            $s['mode'] = $mode;
        }
        if ($entries) {
            $s['entry'] = $entries;
        }

        return $s;
    }

    /** @return array<int,array{reference:string}> */
    private function refEntries(string $type, array $ids): array
    {
        return array_values(array_map(fn ($id) => ['reference' => "{$type}/{$id}"], $ids));
    }

    // ---- Patient ----

    private function buildPatient(Visit $visit, string $patientId, string $organizationId): array
    {
        $p = $visit->patient;

        $identifier = [];
        if ($p?->no_rm) {
            $identifier[] = ['use' => 'usual', 'type' => ['coding' => [['system' => 'http://hl7.org/fhir/v2/0203', 'code' => 'MR']]], 'value' => $p->no_rm];
        }
        if ($p?->bpjs_number) {
            $identifier[] = ['use' => 'official', 'type' => ['coding' => [['system' => 'http://hl7.org/fhir/v2/0203', 'code' => 'MB']]], 'value' => $p->bpjs_number, 'assigner' => ['display' => 'BPJS KESEHATAN']];
        }
        if ($p?->nik) {
            $identifier[] = ['use' => 'official', 'type' => ['coding' => [['system' => 'http://hl7.org/fhir/v2/0203', 'code' => 'NNIDN']]], 'value' => $p->nik, 'assigner' => ['display' => 'KEMENDAGRI']];
        }

        return array_filter([
            'resourceType' => 'Patient',
            'id'           => $patientId,
            'identifier'   => $identifier,
            'active'       => true,
            'name'         => [['use' => 'official', 'text' => $p?->name]],
            'telecom'      => $p?->phone ? [['system' => 'phone', 'value' => $p->phone, 'use' => 'mobile']] : [],
            'gender'       => $this->gender($p?->gender),
            'birthDate'    => $p?->date_of_birth ? Carbon::parse($p->date_of_birth)->format('Y-m-d') : null,
            'address'      => $p?->address ? [['use' => 'home', 'text' => $p->address, 'line' => [$p->address]]] : [],
            'managingOrganization' => ['reference' => "Organization/{$organizationId}"],
        ], fn ($v) => $v !== null && $v !== []);
    }

    // ---- Encounter ----

    private function buildEncounter(Visit $visit, string $patientId, string $encounterId, ?string $practitionerId, array $conditions): array
    {
        $p = $visit->patient;
        // Klinik mata rawat jalan → AMB (ambulatory).
        [$code, $display] = match ($visit->jenis_pelayanan ?? 'RAJAL') {
            'RANAP' => ['IMP', 'inpatient encounter'],
            'IGD'   => ['EMER', 'emergency'],
            default => ['AMB', 'ambulatory'],
        };

        $diagnosis = [];
        $rank = 0;
        foreach ($conditions as $c) {
            $diagnosis[] = [
                'condition' => [
                    'reference' => "Condition/{$c['id']}",
                    'role'      => ['coding' => [['system' => 'http://hl7.org/fhir/diagnosis-role', 'code' => 'DD', 'display' => 'Discharge Diagnosis']]],
                    'rank'      => ++$rank,
                ],
            ];
        }

        return array_filter([
            'resourceType' => 'Encounter',
            'id'           => $encounterId,
            'identifier'   => [['system' => 'http://api.bpjs-kesehatan.go.id:8080/Vclaim-rest/SEP/', 'value' => $visit->no_sep]],
            'subject'      => ['reference' => "Patient/{$patientId}", 'display' => $p?->name, 'noSep' => $visit->no_sep],
            'class'        => ['system' => 'http://hl7.org/fhir/v3/ActCode', 'code' => $code, 'display' => $display],
            'diagnosis'    => $diagnosis,
            'period'       => ['start' => $this->dt($visit->visit_date ?? $visit->created_at), 'end' => $this->dt($visit->updated_at ?? now())],
            'status'       => 'finished',
        ], fn ($v) => $v !== null && $v !== []);
    }

    // ---- Practitioner ----

    private function buildPractitioner($doctor, string $practitionerId): array
    {
        $identifier = [];
        if ($doctor?->sip) {
            $identifier[] = ['use' => 'official', 'system' => 'urn:oid:nomor_sip', 'value' => $doctor->sip];
        }
        if ($doctor?->nik) {
            $identifier[] = ['use' => 'official', 'type' => ['coding' => [['system' => 'http://hl7.org/fhir/v2/0203', 'code' => 'NNIDN']]], 'value' => $doctor->nik, 'assigner' => ['display' => 'KEMENDAGRI']];
        }

        return array_filter([
            'resourceType' => 'Practitioner',
            'id'           => $practitionerId,
            'identifier'   => $identifier,
            'name'         => [['use' => 'official', 'text' => $doctor?->name]],
            'telecom'      => $doctor?->phone ? [['system' => 'phone', 'value' => $doctor->phone, 'use' => 'work']] : [],
        ], fn ($v) => $v !== null && $v !== []);
    }

    // ---- Organization ----

    private function buildOrganization(string $organizationId): array
    {
        $clinic = ClinicProfile::query()->first();

        $identifier = [['use' => 'official', 'system' => 'urn:oid:bpjs', 'value' => $this->ppk]];
        if ($clinic?->kemenkes_code) {
            $identifier[] = ['use' => 'official', 'system' => 'urn:oid:kemkes', 'value' => $clinic->kemenkes_code];
        }

        return array_filter([
            'resourceType' => 'Organization',
            'id'           => $organizationId,
            'identifier'   => $identifier,
            'type'         => [['coding' => [['system' => 'http://hl7.org/fhir/organization-type', 'code' => 'prov', 'display' => 'Healthcare Provider']]]],
            'name'         => $clinic?->clinic_name ?? 'RS KHUSUS MATA PRIMA VISION',
            'telecom'      => $clinic?->phone ? [['system' => 'phone', 'value' => $clinic->phone, 'use' => 'work']] : [],
            'address'      => $clinic?->address ? [['use' => 'work', 'text' => $clinic->address, 'line' => [$clinic->address], 'country' => 'IDN']] : [],
        ], fn ($v) => $v !== null && $v !== []);
    }

    // ---- Condition (ICD-10) ----

    /** @return array<int,array{id:string,code:string,display:?string,resource:array}> */
    private function buildConditions(Visit $visit, string $patientId, string $encounterId): array
    {
        $exam = $visit->doctorExamination;
        $out  = [];

        $primary = is_string($exam?->diagnosis_utama) ? trim($exam->diagnosis_utama) : null;
        if ($primary) {
            $out[] = $this->oneCondition($primary, $exam->diagnosis_utama_name, $patientId, $encounterId, $visit);
        }
        foreach ((array) ($exam?->diagnosis_sekunder ?? []) as $sec) {
            $code = is_array($sec) ? ($sec['code'] ?? $sec['kode'] ?? null) : $sec;
            $name = is_array($sec) ? ($sec['name'] ?? $sec['nama'] ?? null) : null;
            $code = is_string($code) ? trim($code) : null;
            if ($code) {
                $out[] = $this->oneCondition($code, $name, $patientId, $encounterId, $visit);
            }
        }

        return $out;
    }

    private function oneCondition(string $icd10, ?string $name, string $patientId, string $encounterId, Visit $visit): array
    {
        $id = $this->rid();

        return [
            'id'      => $id,
            'code'    => $icd10,
            'display' => $name,
            'resource' => array_filter([
                'resourceType'       => 'Condition',
                'id'                 => $id,
                'clinicalStatus'     => 'active',
                'verificationStatus' => 'confirmed',
                'category'           => [['coding' => [['system' => 'http://hl7.org/fhir/condition-category', 'code' => 'encounter-diagnosis', 'display' => 'Encounter Diagnosis']]]],
                'code'               => ['coding' => [['system' => 'http://hl7.org/fhir/sid/icd-10', 'code' => $icd10, 'display' => $name]], 'text' => $name],
                'subject'            => ['reference' => "Patient/{$patientId}"],
                'onsetDateTime'      => $this->dt($visit->visit_date ?? $visit->created_at),
            ], fn ($v) => $v !== null && $v !== []),
        ];
    }

    // ---- MedicationRequest (kode obat lokal) — resource = ARRAY ----

    /** @return array<int,array{id:string,display:string,resource:array}> */
    private function buildMedications(Visit $visit, string $patientId, string $encounterId, ?string $practitionerId, string $organizationId): array
    {
        $out = [];
        foreach ($visit->prescriptions ?? [] as $presc) {
            if ($presc->status === 'CANCELLED') {
                continue;
            }
            foreach ($presc->items ?? [] as $item) {
                $med = $item->medication;
                if (! $med) {
                    continue;
                }
                $id   = $this->rid();
                $name = $med->name;
                $out[] = [
                    'id'      => $id,
                    'display' => $name,
                    'resource' => array_filter([
                        'resourceType'  => 'MedicationRequest',
                        'id'            => $id,
                        'text'          => ['div' => $this->div($name)],
                        'identifier'    => ['system' => 'id_resep_pulang', 'value' => $presc->id . ':' . $item->id],
                        'subject'       => ['display' => $visit->patient?->name, 'reference' => "Patient/{$patientId}"],
                        'intent'        => 'final',
                        'medicationCodeableConcept' => [
                            'coding' => [['code' => $med->code, 'system' => self::DRUG_SYSTEM]],
                            'text'   => $name,
                        ],
                        'dosageInstruction' => [array_filter([
                            'doseQuantity' => $item->dose ? ['unit' => (string) $med->unit, 'value' => (string) $item->dose, 'system' => 'http://unitsofmeasure.org', 'code' => (string) $med->unit] : null,
                            'additionalInstruction' => ($item->frequency || $item->instructions) ? [['text' => trim(($item->frequency ?? '') . ' ' . ($item->instructions ?? ''))]] : null,
                        ], fn ($v) => $v !== null && $v !== [])],
                        'requester'     => $practitionerId ? [
                            'agent'     => ['display' => $presc->prescribedBy?->name ?? null, 'reference' => "Practitioner/{$practitionerId}"],
                            'onBehalfOf'=> ['reference' => "Organization/{$organizationId}"],
                        ] : null,
                        'meta'          => ['lastUpdated' => $this->dt($presc->created_at)],
                    ], fn ($v) => $v !== null && $v !== []),
                ];
            }
        }

        return $out;
    }

    // ---- Procedure (ICD-9-CM dari tindakan_codes) — resource = ARRAY ----

    /** @return array<int,array{id:string,resource:array}> */
    private function buildProcedures(Visit $visit, string $patientId, string $encounterId, ?string $practitionerId): array
    {
        $codes = (array) ($visit->doctorExamination?->tindakan_codes ?? []);
        $out   = [];
        foreach ($codes as $c) {
            $code = is_array($c) ? ($c['code'] ?? $c['kode'] ?? null) : $c;
            $name = is_array($c) ? ($c['name'] ?? $c['nama'] ?? null) : null;
            $code = is_string($code) ? trim($code) : null;
            if (! $code) {
                continue;
            }
            $id = $this->rid();
            $out[] = [
                'id' => $id,
                'resource' => array_filter([
                    'resourceType' => 'Procedure',
                    'id'           => $id,
                    'status'       => 'completed',
                    // CATATAN UAT: BPJS contoh memakai SNOMED PROCx; data kami ICD-9-CM.
                    'code'         => ['coding' => [['system' => 'http://hl7.org/fhir/sid/icd-9-cm', 'code' => $code, 'display' => $name]]],
                    'subject'      => ['reference' => "Patient/{$patientId}", 'display' => $visit->patient?->name],
                    'context'      => ['reference' => "Encounter/{$encounterId}"],
                    'performedPeriod' => ['start' => $this->dt($visit->visit_date ?? $visit->created_at), 'end' => $this->dt($visit->visit_date ?? $visit->created_at)],
                    'performer'    => $practitionerId ? [['actor' => ['reference' => "Practitioner/{$practitionerId}", 'display' => $visit->doctorExamination?->doctor?->name]]] : null,
                ], fn ($v) => $v !== null && $v !== []),
            ];
        }

        return $out;
    }

    // ---- Device (BHP/alkes — kode lokal) — resource = ARRAY ----

    /** @return array<int,array{id:string,resource:array}> */
    private function buildDevices(Visit $visit, string $patientId): array
    {
        $out = [];
        foreach ($visit->bhpUsages ?? [] as $usage) {
            $bhp = $usage->bhpItem;
            if (! $bhp) {
                continue;
            }
            $id = $this->rid();
            $out[] = [
                'id' => $id,
                'resource' => array_filter([
                    'resourceType' => 'Device',
                    'id'           => $id,
                    'identifier'   => [['system' => self::DEVICE_SYSTEM . '/serial', 'value' => $bhp->code]],
                    'type'         => ['coding' => [['system' => self::DEVICE_SYSTEM, 'code' => $bhp->code, 'display' => $bhp->name]]],
                    'lotNumber'    => $bhp->batch_number ?? '',
                    'manufacturer' => $bhp->manufacturer ?? '',
                    'patient'      => ['reference' => "Patient/{$patientId}"],
                ], fn ($v) => $v !== null && $v !== []),
            ];
        }

        return $out;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function koders(): string
    {
        $k = $this->client->kodeFaskes();
        if ($k === '') {
            $k = BpjsClient::for('VCLAIM')->kodeFaskes();
        }
        if ($k === '') {
            $k = (string) (ClinicProfile::query()->value('clinic_code') ?? '');
        }

        return $k;
    }

    private function jnsPelayanan(Visit $visit): string
    {
        return ($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP' ? '1' : '2';
    }

    /** id resource pola {kodePPK}-{noSEPnum}-{seq}-{uuid}. */
    private function rid(): string
    {
        return $this->ppk . '-' . $this->seqBase . '-' . (++$this->seq) . '-' . Str::uuid()->toString();
    }

    /** Tanggal format BPJS "Y-m-d H:i:s" (Asia/Jakarta, tanpa T/zone). */
    private function dt($value): string
    {
        return Carbon::parse($value ?? now())->timezone('Asia/Jakarta')->format('Y-m-d H:i:s');
    }

    /** Bungkus narasi ke xhtml div (FHIR Narrative). */
    private function div(?string $text): string
    {
        return '<div xmlns="http://www.w3.org/1999/xhtml">' . e((string) $text) . '</div>';
    }

    private function gender(?string $g): ?string
    {
        $g = strtoupper(trim((string) $g));
        if ($g === '') {
            return null;
        }

        return match (true) {
            in_array($g, ['L', 'LAKI', 'LAKI-LAKI', 'M', 'MALE'], true)      => 'male',
            in_array($g, ['P', 'PEREMPUAN', 'WANITA', 'F', 'FEMALE'], true)  => 'female',
            default                                                          => strtolower($g),
        };
    }
}
