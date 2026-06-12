<?php

namespace App\Services;

use App\Models\IntegrationConfig;
use App\Models\SatusehatResourceLog;
use App\Models\SatusehatSyncLog;
use App\Models\Visit;
use App\Services\Satusehat\SatusehatClient;
use Illuminate\Support\Facades\DB;

/**
 * Satu Sehat (SATUSEHAT) — FHIR R4 integration.
 *
 * PLACEHOLDER — implementasi setelah credentials (client_id, client_secret,
 * organization_id) tersedia dari dashboard Satu Sehat.
 *
 * Flow:
 *   Batch auto: setiap 23:59 via Laravel Scheduler
 *   Retry auto: setiap 01:00 jika batch sebelumnya PARTIAL/FAILED
 *   Manual:     POST /integrasi/satusehat/sync-manual
 *
 * 5 FHIR R4 resources wajib per kunjungan:
 *   1. Encounter          — data kunjungan
 *   2. Condition          — diagnosis (ICD-10)
 *   3. MedicationRequest  — resep obat
 *   4. MedicationDispense — pemberian obat (farmasi)
 *   5. ImagingStudy       — hasil penunjang (OCT, USG, dll)
 *
 * @see https://dev.satusehat.kemkes.go.id/
 */
class SatusehatService
{
    private ?IntegrationConfig $config = null;
    private ?string $accessToken       = null;
    private ?SatusehatClient $clientInstance = null;

    public function boot(): void
    {
        $this->config = IntegrationConfig::where('system_name', 'SATUSEHAT')->first();
    }

    /** Lazy client — dibuat saat dibutuhkan (baca config terbaru tiap kali). */
    private function client(): SatusehatClient
    {
        return $this->clientInstance ??= new SatusehatClient();
    }

    public function isEnabled(): bool
    {
        return $this->config?->is_enabled ?? false;
    }

    // =========================================================================
    // BATCH SYNC (dipanggil oleh Laravel Scheduler 23:59)
    // =========================================================================

    /**
     * Sync all unsynced/failed visits for today.
     * Called by: app/Console/Kernel.php or Laravel Scheduler
     */
    public function batchSync(string $syncType = 'AUTO'): SatusehatSyncLog
    {
        $this->assertEnabled();

        // Via HTTP (sync-manual) run bisa ratusan detik (1 Bundle/visit ke Kemenkes)
        // — jangan dipotong max_execution_time FPM. No-op di CLI/scheduler.
        @set_time_limit(0);

        $syncLog = SatusehatSyncLog::create([
            'sync_date'    => today(),
            'sync_type'    => $syncType,
            'status'       => 'RUNNING',
            'total_sent'   => 0,
            'total_failed' => 0,
            'retry_count'  => 0,
        ]);

        try {
            // Visit SELESAI hari ini yg belum/ gagal sync. PENDING|FAILED dikelompokkan
            // dgn benar (where bersarang) agar filter tanggal+station tidak bocor.
            $visits = Visit::whereIn('satusehat_sync_status', ['PENDING', 'FAILED'])
                ->whereDate('visit_date', today())
                ->where('current_station', 'SELESAI')
                ->with(['patient', 'doctorExamination.doctor', 'prescriptions.items.medication', 'prescriptions.prescribedBy', 'prescriptions.dispensedBy'])
                ->get();

            $sent   = 0;
            $failed = 0;
            $skippedReasons  = []; // reason => jumlah visit dilewati (data tidak lengkap)
            $kfaSkippedNames = [];   // nama obat ter-skip karena tak ber-KFA (unik se-batch)
            $visitsWithSkip  = 0;    // jumlah kunjungan yang punya obat ter-skip

            foreach ($visits as $visit) {
                // Data tidak lengkap = PASTI gagal (NIK/diagnosis adalah syarat
                // regulasi) — jangan ditembak ke Kemenkes & jangan dihitung
                // "gagal": angka gagal di Riwayat Batch harus berarti gagal
                // teknis yang perlu ditindaklanjuti, bukan gap data berulang.
                if ($reason = $this->precheckSkipReason($visit)) {
                    $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
                    continue;
                }

                $result = $this->syncVisit($visit, $syncLog->id);
                $result ? $sent++ : $failed++;

                // Diagnostik KFA: obat tanpa kfa_code tak ikut terkirim (MedicationRequest
                // di-skip). Kumpulkan agar tampil sebagai warning di sync log/UI.
                $skipped = $this->skippedNoKfaItems($visit);
                if ($skipped) {
                    $visitsWithSkip++;
                    $kfaSkippedNames = array_merge($kfaSkippedNames, $skipped);
                }
            }

            $status = match (true) {
                $failed === 0            => 'SUCCESS',
                $sent === 0             => 'FAILED',
                default                  => 'PARTIAL',
            };

            $notes = trim(implode(' ', array_filter([
                $this->buildFailureBreakdownNote($syncLog->id),
                $this->buildKfaWarningNote($visitsWithSkip, $kfaSkippedNames),
                $this->buildSkipNote($skippedReasons),
            ])));

            $syncLog->update([
                'status'       => $status,
                'total_sent'   => $sent,
                'total_failed' => $failed,
                'notes'        => $notes ?: null,
                'next_retry_at' => $status !== 'SUCCESS'
                    ? now()->addHours(2)->setTime(1, 0)
                    : null,
            ]);
        } catch (\Throwable $e) {
            $syncLog->update(['status' => 'FAILED']);
        }

        return $syncLog->fresh();
    }

    // =========================================================================
    // BACKFILL — sync kunjungan HISTORIS (puluhan ribu) sesuai regulasi Satu Sehat
    // =========================================================================

    /**
     * Query kunjungan HISTORIS yang LAYAK di-backfill ke Satu Sehat.
     *
     * Regulasi Satu Sehat: Encounter WAJIB punya subject Patient/{ihs} +
     * participant Practitioner/{ihs} + diagnosis (Condition). IHS di-resolve
     * otomatis dari NIK saat sync. Maka eligibilitas operasional =
     *   - Visit SELESAI & belum SYNCED (PENDING/FAILED/null),
     *   - pasien punya NIK (→ IHS pasien dapat di-resolve),
     *   - dokter pemeriksa punya NIK (→ IHS dokter dapat di-resolve),
     *   - ada diagnosis utama (diagnosis_utama terisi).
     * Resep ikut otomatis di Bundle bila ada & ber-KFA (bukan syarat Encounter).
     *
     * @param  string|null  $from  filter visit_date >= (YYYY-MM-DD), opsional
     * @param  string|null  $to    filter visit_date <= (YYYY-MM-DD), opsional
     */
    public function backfillEligibleQuery(?string $from = null, ?string $to = null)
    {
        $q = Visit::query()
            ->where('current_station', 'SELESAI')
            ->where(fn ($w) => $w->whereNull('satusehat_sync_status')
                ->orWhereIn('satusehat_sync_status', ['PENDING', 'FAILED']))
            // pasien ber-NIK (untuk resolve IHS pasien)
            ->whereHas('patient', fn ($p) => $p->whereNotNull('nik')->where('nik', '!=', ''))
            // ada pemeriksaan dokter: diagnosis utama + dokter ber-NIK (resolve IHS dokter)
            ->whereHas('doctorExamination', function ($e) {
                $e->whereNotNull('diagnosis_utama')->where('diagnosis_utama', '!=', '')
                    ->whereHas('doctor', fn ($d) => $d->whereNotNull('nik')->where('nik', '!=', ''));
            });

        if ($from) {
            $q->whereDate('visit_date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('visit_date', '<=', $to);
        }

        return $q->orderBy('visit_date'); // historis: kirim dari yang terlama
    }

    /**
     * Hitung kunjungan layak backfill (untuk tombol "Cek dulu" di UI sebelum
     * eksekusi). Mengembalikan total eligible + diagnostik penyebab tak-eligible.
     */
    public function countBackfillEligible(?string $from = null, ?string $to = null): array
    {
        $eligible = (clone $this->backfillEligibleQuery($from, $to))->count();

        // Diagnostik: visit SELESAI belum-SYNCED yang TERSARING (kenapa tak eligible).
        $base = Visit::query()
            ->where('current_station', 'SELESAI')
            ->where(fn ($w) => $w->whereNull('satusehat_sync_status')
                ->orWhereIn('satusehat_sync_status', ['PENDING', 'FAILED']));
        if ($from) { $base->whereDate('visit_date', '>=', $from); }
        if ($to) { $base->whereDate('visit_date', '<=', $to); }

        $pendingTotal = (clone $base)->count();
        $noPatientNik = (clone $base)->whereDoesntHave('patient', fn ($p) => $p->whereNotNull('nik')->where('nik', '!=', ''))->count();
        $noDiagOrDoctor = (clone $base)->whereDoesntHave('doctorExamination', function ($e) {
            $e->whereNotNull('diagnosis_utama')->where('diagnosis_utama', '!=', '')
                ->whereHas('doctor', fn ($d) => $d->whereNotNull('nik')->where('nik', '!=', ''));
        })->count();

        // Pecahan $noDiagOrDoctor — operator perlu tahu mana yang BISA diperbaiki
        // (NIK dokter bisa diisi; diagnosis kosong pada data import lama tidak).
        $noExam = (clone $base)->whereDoesntHave('doctorExamination')->count();
        $noDiagnosis = (clone $base)->whereHas('doctorExamination', fn ($e) => $e
            ->where(fn ($q) => $q->whereNull('diagnosis_utama')->orWhere('diagnosis_utama', '')))->count();
        $doctorNoNik = (clone $base)->whereHas('doctorExamination', function ($e) {
            $e->whereNotNull('diagnosis_utama')->where('diagnosis_utama', '!=', '')
                ->whereDoesntHave('doctor', fn ($d) => $d->whereNotNull('nik')->where('nik', '!=', ''));
        })->count();

        return [
            'eligible'                 => $eligible,
            'pending_total'            => $pendingTotal,
            'skipped_no_patient_nik'   => $noPatientNik,
            'skipped_no_diag_or_doctor'=> $noDiagOrDoctor,
            'skipped_no_exam'          => $noExam,
            'skipped_no_diagnosis'     => $noDiagnosis,
            'skipped_doctor_no_nik'    => $doctorNoNik,
            'range'                    => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * Jalankan backfill: sync N kunjungan historis eligible (terlama dulu),
     * dibungkus satu SatusehatSyncLog bertipe BACKFILL. Dibatasi $limit agar
     * operator bisa mencicil puluhan ribu data secara terkendali.
     *
     * @param  int  $limit  jumlah maksimum visit diproses dalam panggilan ini
     */
    public function backfillSync(int $limit = 100, ?string $from = null, ?string $to = null): SatusehatSyncLog
    {
        $this->assertEnabled();

        // Dipanggil via HTTP (tombol Backfill) — ratusan visit = menit-an; jangan
        // dipotong max_execution_time FPM.
        @set_time_limit(0);

        $limit = max(1, min($limit, 5000)); // pagar atas: hindari satu run kelewat besar

        $syncLog = SatusehatSyncLog::create([
            'sync_date'    => today(),
            'sync_type'    => 'BACKFILL',
            'status'       => 'RUNNING',
            'total_sent'   => 0,
            'total_failed' => 0,
            'retry_count'  => 0,
        ]);

        try {
            $visits = $this->backfillEligibleQuery($from, $to)
                ->with(['patient', 'doctorExamination.doctor', 'prescriptions.items.medication', 'prescriptions.prescribedBy', 'prescriptions.dispensedBy'])
                ->limit($limit)
                ->get();

            $sent = 0;
            $failed = 0;
            $kfaSkippedNames = [];
            $visitsWithSkip = 0;

            foreach ($visits as $visit) {
                $result = $this->syncVisit($visit, $syncLog->id);
                $result ? $sent++ : $failed++;

                $skipped = $this->skippedNoKfaItems($visit);
                if ($skipped) {
                    $visitsWithSkip++;
                    $kfaSkippedNames = array_merge($kfaSkippedNames, $skipped);
                }
            }

            $status = match (true) {
                $visits->isEmpty() => 'SUCCESS', // tak ada yg eligible → sukses kosong
                $failed === 0      => 'SUCCESS',
                $sent === 0        => 'FAILED',
                default            => 'PARTIAL',
            };

            // Sisa eligible setelah run ini → tampilkan agar operator tahu perlu lanjut.
            $remaining = (clone $this->backfillEligibleQuery($from, $to))->count();
            $kfaNote   = $this->buildKfaWarningNote($visitsWithSkip, $kfaSkippedNames);
            $failNote  = $this->buildFailureBreakdownNote($syncLog->id);
            $note = "Backfill: {$sent} terkirim, {$failed} gagal dari " . $visits->count()
                . " diproses. Sisa eligible: {$remaining}."
                . ($failNote ? ' ' . $failNote : '')
                . ($kfaNote ? ' ' . $kfaNote : '');

            $syncLog->update([
                'status'       => $status,
                'total_sent'   => $sent,
                'total_failed' => $failed,
                'notes'        => $note,
                'next_retry_at'=> null,
            ]);
        } catch (\Throwable $e) {
            $syncLog->update(['status' => 'FAILED', 'notes' => 'Backfill error: ' . $e->getMessage()]);
        }

        return $syncLog->fresh();
    }

    // =========================================================================
    // PER-VISIT SYNC
    // =========================================================================

    /**
     * Alasan visit DILEWATI sebelum kirim (null = layak kirim). Syarat regulasi
     * Satu Sehat yang murni soal kelengkapan data lokal — tanpa ini Bundle pasti
     * ditolak, jadi tak perlu menembak Kemenkes / membakar resolve IHS tiap run.
     * Selaras kriteria {@see backfillEligibleQuery}.
     */
    private function precheckSkipReason(Visit $visit): ?string
    {
        if (trim((string) $visit->patient?->nik) === '') {
            return 'tanpa NIK pasien';
        }

        $exam = $visit->doctorExamination;
        if (! $exam || trim((string) $exam->diagnosis_utama) === '') {
            return 'tanpa diagnosis ICD-10';
        }
        if (trim((string) $exam->doctor?->nik) === '') {
            return 'dokter tanpa NIK';
        }

        return null;
    }

    /** Kalimat ringkas utk notes sync log dari hasil precheck (null bila kosong). */
    private function buildSkipNote(array $skippedReasons): ?string
    {
        if (! $skippedReasons) {
            return null;
        }

        $detail = collect($skippedReasons)
            ->map(fn ($n, $reason) => "{$n} {$reason}")
            ->implode(', ');

        return 'Dilewati (data tidak lengkap): ' . array_sum($skippedReasons)
            . " kunjungan — {$detail}. Lengkapi via Kesiapan Data / modul Dokter.";
    }

    /**
     * Ringkasan SEBAB GAGAL aktual sebuah batch — dibaca dari resource log
     * (error_message), dikelompokkan per kategori & dihitung per kunjungan unik.
     * Ditaruh di depan notes agar kolom "Catatan" di UI menjelaskan KENAPA gagal,
     * bukan cuma menampilkan warning KFA (yang BUKAN penyebab kegagalan).
     */
    private function buildFailureBreakdownNote(?string $syncLogId): ?string
    {
        if (! $syncLogId) {
            return null;
        }

        $rows = SatusehatResourceLog::where('satusehat_sync_log_id', $syncLogId)
            ->where('status', 'FAILED')
            ->get(['visit_id', 'error_message']);

        if ($rows->isEmpty()) {
            return null;
        }

        // Urutan = prioritas pelabelan bila satu kunjungan punya >1 pesan.
        $cats = [
            'obat duplikat (KFA berulang)' => fn (string $m) => str_contains($m, 'duplicate: Medication'),
            'Encounter sudah ada'          => fn (string $m) => str_contains($m, 'duplicate resource: Encounter'),
            'NIK pasien belum ter-resolve' => fn (string $m) => str_contains($m, 'IHS pasien belum'),
            'diagnosis ICD-10 kosong'      => fn (string $m) => str_contains($m, 'Encounter.diagnosis') || str_contains($m, 'Diagnosis (ICD-10) belum'),
            'kode KFA obat tak valid'      => fn (string $m) => str_contains($m, 'kemkes.go.id/kfa for element'),
            'kode ICD-10 tak valid'        => fn (string $m) => str_contains($m, 'sid/icd-10'),
        ];

        $buckets = [];
        foreach ($rows->groupBy('visit_id') as $logs) {
            $msgs  = $logs->pluck('error_message')->filter()->map(fn ($m) => (string) $m);
            $label = 'lainnya';
            foreach ($cats as $lbl => $match) {
                if ($msgs->contains(fn ($m) => $match($m))) {
                    $label = $lbl;
                    break;
                }
            }
            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        arsort($buckets);
        $detail = collect($buckets)->map(fn ($n, $l) => "{$n} {$l}")->implode(', ');

        return "Sebab gagal: {$detail}.";
    }

    /**
     * Sync satu visit via FHIR transaction Bundle (Encounter+Condition+obat).
     * Delegasi ke {@see syncVisitBundle} — jalur tunggal yg terbukti (4 statistik).
     * Status visit di-update di dalam syncVisitBundle (SYNCED/FAILED + encounter_id).
     */
    public function syncVisit(Visit $visit, ?string $syncLogId = null): bool
    {
        try {
            $result = $this->syncVisitBundle($visit, $syncLogId);
        } catch (\Throwable $e) {
            $visit->forceFill(['satusehat_sync_status' => 'FAILED'])->save();

            // Catat SEBAB gagal pra-kirim (IHS belum resolve / Location kosong / dst).
            // Tanpa baris ini visit cuma jadi FAILED tanpa jejak alasan, dan retry()
            // — yang membaca visit gagal dari resource log — tak menemukan apa pun.
            try {
                SatusehatResourceLog::create([
                    'satusehat_sync_log_id' => $syncLogId,
                    'visit_id'              => $visit->id,
                    'resource_type'         => 'Bundle',
                    'status'                => 'FAILED',
                    'error_message'         => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // best-effort: kegagalan menulis log tak boleh mengubah hasil sync
            }

            return false;
        }

        return (bool) ($result['is_success'] ?? false);
    }

    // =========================================================================
    // RESOURCE SENDERS (FHIR R4)
    // =========================================================================

    /**
     * POST /fhir-r4/Encounter. Idempoten: kalau visit sudah punya
     * satusehat_encounter_id → skip POST, kembalikan id lama. Saat sukses,
     * simpan id ke visits.satusehat_encounter_id.
     */
    public function sendEncounter(Visit $visit): array
    {
        if (! empty($visit->satusehat_encounter_id)) {
            return ['is_success' => true, 'id' => $visit->satusehat_encounter_id, 'skipped' => true];
        }

        $result = $this->postFhir('Encounter', $this->buildEncounterPayload($visit), $visit->id);

        if (($result['is_success'] ?? false) && ! empty($result['id'])) {
            $visit->forceFill(['satusehat_encounter_id' => $result['id']])->save();
        }

        return $result;
    }

    /**
     * POST semua Condition visit (utama + sekunder). Mengembalikan ringkasan
     * { is_success (semua sukses), sent, results[] }.
     */
    public function sendCondition(Visit $visit): array
    {
        $payloads = $this->buildConditionPayloads($visit);
        $results  = [];
        $allOk    = true;

        foreach ($payloads as $payload) {
            $r = $this->postFhir('Condition', $payload, $visit->id);
            $results[] = $r;
            $allOk = $allOk && ($r['is_success'] ?? false);
        }

        return ['is_success' => $allOk && count($payloads) > 0, 'sent' => count($payloads), 'results' => $results];
    }

    // (MedicationRequest/Dispense/ImagingStudy per-POST DIHAPUS — semua obat kini
    //  dikirim lewat transaction Bundle via syncVisitBundle, lihat buildMedicationEntries.)

    // =========================================================================
    // RETRY
    // =========================================================================

    /**
     * Retry a specific sync log (PARTIAL or FAILED).
     * Called manually from IntegrasiController or by scheduler at 01:00.
     */
    public function retry(string $syncLogId): SatusehatSyncLog
    {
        // Konsisten dengan batchSync(): jangan proses retry kalau integrasi nonaktif
        // (else tiap visit dipaksa sampai client lempar 503 satu-per-satu + set
        // next_retry_at walau mati).
        $this->assertEnabled();

        // Via HTTP (tombol Retry) bisa lama — jangan dipotong max_execution_time FPM.
        @set_time_limit(0);

        $syncLog = SatusehatSyncLog::findOrFail($syncLogId);

        if ($syncLog->status === 'SUCCESS') {
            throw new \Exception('Sync sudah berhasil, tidak perlu retry.', 422);
        }

        $visitIds = SatusehatResourceLog::where('satusehat_sync_log_id', $syncLogId)
            ->where('status', 'FAILED')
            ->pluck('visit_id')
            ->unique()
            ->filter();

        $failedVisits = Visit::whereIn('id', $visitIds)
            ->with(['patient', 'doctorExamination.doctor', 'prescriptions.items.medication', 'prescriptions.prescribedBy', 'prescriptions.dispensedBy'])
            ->get();

        $sent   = 0;
        $failed = 0;
        $skippedReasons = [];

        foreach ($failedVisits as $visit) {
            // Data tidak lengkap → biarkan FAILED apa adanya, jangan ditembak
            // ulang tiap retry (alasannya sudah tercatat di resource log; akan
            // terkirim via backfill begitu datanya dilengkapi).
            if ($reason = $this->precheckSkipReason($visit)) {
                $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
                continue;
            }

            // Reset agar Bundle dikirim ulang (encounter belum tersimpan saat gagal).
            $visit->forceFill(['satusehat_sync_status' => 'PENDING'])->save();
            $result = $this->syncVisit($visit, $syncLogId);
            $result ? $sent++ : $failed++;
        }

        $newStatus = $failed === 0 ? 'SUCCESS' : 'PARTIAL';

        // Timpa kalimat "Sebab gagal" & skip lama di notes (kalau ada) agar tidak
        // menumpuk antar-retry; sisakan catatan lain (mis. warning KFA).
        $baseNotes = trim((string) preg_replace(
            ['/Sebab gagal:.*?\.(?=\s|$)/s', '/Dilewati \(data tidak lengkap\):.*$/s'],
            '', (string) $syncLog->notes));
        $notes = trim(implode(' ', array_filter([
            $this->buildFailureBreakdownNote($syncLog->id),
            $baseNotes,
            $this->buildSkipNote($skippedReasons),
        ])));

        $syncLog->update([
            'status'       => $newStatus,
            'retry_count'  => $syncLog->retry_count + 1,
            // total_sent diakumulasi; total_failed = gagal AKTUAL retry ini (bukan
            // selisih total_failed - sent yang bisa ngawur saat visit sukses lalu
            // gagal lagi atau jumlah retry ≠ total_failed lama).
            'total_sent'   => $syncLog->total_sent + $sent,
            'total_failed' => $failed,
            'notes'        => $notes ?: null,
            'next_retry_at' => $newStatus !== 'SUCCESS' ? now()->addHours(2) : null,
        ]);

        return $syncLog->fresh();
    }

    /**
     * Retry sync-log PARTIAL/FAILED terakhir (dipakai scheduler 01:00).
     * Return null bila tidak ada yang perlu di-retry.
     */
    public function retryLatestUnfinished(): ?SatusehatSyncLog
    {
        // Scheduler retry jalan 01:00 HARI BERIKUTNYA — batch utama 23:59 punya
        // sync_date kemarin. Dulu whereDate(today()) → tak pernah ketemu, retry
        // otomatis efektif mati. Cakup kemarin+hari ini.
        $log = SatusehatSyncLog::whereIn('status', ['PARTIAL', 'FAILED'])
            ->whereDate('sync_date', '>=', today()->subDay())
            ->latest()
            ->first();

        return $log ? $this->retry($log->id) : null;
    }

    // =========================================================================
    // LOCATION — auto-register ke Satu Sehat (helper item #17)
    // =========================================================================

    /**
     * Daftarkan satu Location ke Satu Sehat (POST /Location) → terima UUID.
     * Bila $saveToConfig true, simpan UUID ke configuration.location_id config
     * SATUSEHAT (jadi default Encounter). managingOrganization = org_id config.
     *
     * @param  string  $name          nama ruang/unit (mis. "Poliklinik Mata")
     * @param  string  $physicalType  kode HL7 physical-type: ro=Room, wa=Ward, wi=Wing
     */
    public function registerLocation(string $name, string $physicalType = 'ro', bool $saveToConfig = true): array
    {
        $client = $this->client();
        $orgId  = $client->organizationId();

        if ($orgId === '') {
            return ['success' => false, 'message' => 'Organization ID belum diisi di Konfigurasi.'];
        }

        $payload = [
            'resourceType' => 'Location',
            'status'       => 'active',
            'name'         => $name,
            'physicalType' => [
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/location-physical-type',
                    'code'    => $physicalType,
                    'display' => $physicalType === 'ro' ? 'Room' : $physicalType,
                ]],
            ],
            'managingOrganization' => ['reference' => "Organization/{$orgId}"],
        ];

        $result = $client->post('/Location', $payload);
        $resp   = $result['response'] ?? [];
        $id     = is_array($resp) ? ($resp['id'] ?? null) : null;

        if (! ($result['is_success'] ?? false) || ! $id) {
            return [
                'success'     => false,
                'message'     => 'Gagal daftar Location: HTTP ' . ($result['http_status'] ?? 0),
                'response'    => $resp,
            ];
        }

        if ($saveToConfig) {
            $this->setActiveLocation($id);
        }

        return ['success' => true, 'location_id' => $id, 'name' => $name];
    }

    /**
     * Daftar Location milik Organization klinik (GET /Location?organization=).
     * Tandai mana yang sedang dipakai (config.location_id = active).
     */
    public function listLocations(): array
    {
        $client = $this->client();
        $orgId  = $client->organizationId();

        if ($orgId === '') {
            return ['success' => false, 'message' => 'Organization ID belum diisi.', 'items' => [], 'active_id' => null];
        }

        $result = $client->get('Location', ['organization' => $orgId, '_count' => 100]);

        if (! ($result['is_success'] ?? false)) {
            return ['success' => false, 'message' => 'Gagal ambil Location: HTTP ' . ($result['http_status'] ?? 0), 'items' => [], 'active_id' => $client->locationId() ?: null];
        }

        $items = collect($result['response']['entry'] ?? [])->map(function ($e) {
            $r = $e['resource'] ?? [];
            return [
                'id'            => $r['id'] ?? null,
                'name'          => $r['name'] ?? '(tanpa nama)',
                'status'        => $r['status'] ?? null,
                'physical_type' => $r['physicalType']['coding'][0]['code'] ?? null,
            ];
        })->filter(fn ($i) => ! empty($i['id']))->values()->all();

        return [
            'success'   => true,
            'items'     => $items,
            'active_id' => $client->locationId() ?: null,
        ];
    }

    /** Update nama/status Location (PUT /Location/{id}) — FHIR butuh full resource. */
    public function updateLocation(string $id, string $name, string $status = 'active', string $physicalType = 'ro'): array
    {
        $client = $this->client();
        $orgId  = $client->organizationId();

        $payload = [
            'resourceType' => 'Location',
            'id'           => $id,
            'status'       => $status,
            'name'         => $name,
            'physicalType' => [
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/location-physical-type',
                    'code'    => $physicalType,
                    'display' => $physicalType === 'ro' ? 'Room' : $physicalType,
                ]],
            ],
            'managingOrganization' => ['reference' => "Organization/{$orgId}"],
        ];

        $result = $client->put('/Location/' . $id, $payload);

        if (! ($result['is_success'] ?? false)) {
            return ['success' => false, 'message' => 'Gagal update Location: HTTP ' . ($result['http_status'] ?? 0), 'response' => $result['response'] ?? []];
        }

        return ['success' => true, 'location_id' => $id, 'name' => $name, 'status' => $status];
    }

    /**
     * "Hapus" Location — FHIR Satu Sehat tidak hard-delete; set status=inactive
     * (PUT). Bila lokasi yg dinonaktifkan sedang aktif di config → kosongkan.
     */
    public function deactivateLocation(string $id, string $name = '', string $physicalType = 'ro'): array
    {
        $res = $this->updateLocation($id, $name ?: 'Location', 'inactive', $physicalType);

        if (($res['success'] ?? false) && $this->client()->locationId() === $id) {
            $this->setActiveLocation('');
        }

        return $res;
    }

    /** Jadikan satu Location sebagai default Encounter (configuration.location_id). */
    public function setActiveLocation(string $id): void
    {
        $cfg  = IntegrationConfig::where('system_name', 'SATUSEHAT')->first();
        $conf = $cfg->configuration ?? [];
        $conf['location_id'] = $id;
        $cfg->update(['configuration' => $conf]);
    }

    /**
     * Test koneksi nyata: ambil OAuth token dari Kemenkes & cek status approved.
     * Dipanggil dari UI Konfigurasi Bridging (kartu SATUSEHAT → tombol Test).
     */
    public function testConnection(): array
    {
        $client = $this->client();

        try {
            $info = $client->fetchTokenInfo();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'system' => 'SATUSEHAT', 'message' => $e->getMessage()];
        }

        $json = $info['json'];

        $hasToken = ! empty($json['access_token']);
        // Kemenkes mengembalikan status:"approved" untuk app yang aktif. Sebagian
        // env tidak menyertakan field ini — adanya access_token sudah cukup.
        $approved = ($json['status'] ?? null) === 'approved' || $hasToken;

        if (! $hasToken) {
            $msg = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $info['http_status']);

            return [
                'success' => false,
                'system'  => 'SATUSEHAT',
                'message' => 'Gagal autentikasi Satu Sehat: ' . $msg,
                'env'     => $client->env(),
            ];
        }

        return [
            'success' => $approved,
            'system'  => 'SATUSEHAT',
            'message' => 'Koneksi Satu Sehat OK — token diterima (' . $client->env() . ')'
                . (isset($json['status']) ? ', status: ' . $json['status'] : ''),
            'env'     => $client->env(),
            'status'  => $json['status'] ?? 'approved',
        ];
    }

    // =========================================================================
    // DASHBOARD STATS — Fase 6 (GET /integrasi/satusehat/dashboard)
    // =========================================================================

    /**
     * Statistik monitoring Satu Sehat untuk BridgingSatusehatView.
     * Filter rentang ?from&to (default hari ini). Tidak menyentuh API Kemenkes
     * (murni baca log lokal) — kecuali status koneksi (config + last_test).
     *
     * @return array
     */
    public function dashboardStats(?string $from = null, ?string $to = null): array
    {
        $this->boot();
        $client = $this->client();

        $from = $from ?: today()->toDateString();
        $to   = $to ?: today()->toDateString();
        $fromDt = \Illuminate\Support\Carbon::parse($from)->startOfDay();
        $toDt   = \Illuminate\Support\Carbon::parse($to)->endOfDay();

        // ── Koneksi ──────────────────────────────────────────────────────────
        $connection = [
            'env'              => $client->env(),
            'is_enabled'       => $this->config?->is_enabled ?? false,
            'has_credentials'  => $client->hasCredentials(),
            'organization_id'  => $client->organizationId(),
            'location_id'      => $client->locationId(),
            'last_test_status' => $this->config?->last_test_status,
            'last_tested_at'   => $this->config?->last_tested_at?->toIso8601String(),
        ];

        // ── 4 kartu resource (SUCCESS/FAILED dari resource_logs) ──────────────
        $resourceTypes = ['Encounter', 'Condition', 'MedicationRequest', 'MedicationDispense'];
        $cards = [];
        foreach ($resourceTypes as $rt) {
            $base = SatusehatResourceLog::where('resource_type', $rt)
                ->whereBetween('created_at', [$fromDt, $toDt]);
            $cards[$rt] = [
                'success' => (clone $base)->where('status', 'SUCCESS')->count(),
                'failed'  => (clone $base)->where('status', 'FAILED')->count(),
            ];
        }

        // ── Ringkas kunjungan (visit sync_status, SELESAI di rentang) ─────────
        $visitBase = Visit::whereBetween('visit_date', [$fromDt->toDateString(), $toDt->toDateString()])
            ->where('current_station', 'SELESAI');
        $visits = [
            'total'   => (clone $visitBase)->count(),
            'synced'  => (clone $visitBase)->where('satusehat_sync_status', 'SYNCED')->count(),
            'pending' => (clone $visitBase)->where(fn ($q) => $q->whereNull('satusehat_sync_status')->orWhere('satusehat_sync_status', 'PENDING'))->count(),
            'failed'  => (clone $visitBase)->where('satusehat_sync_status', 'FAILED')->count(),
        ];

        // ── Tren sync harian (7 hari terakhir, by resource_logs SUCCESS) ──────
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = today()->subDays($i);
            $trend[] = [
                'date'    => $d->toDateString(),
                'success' => SatusehatResourceLog::whereDate('created_at', $d)->where('status', 'SUCCESS')->count(),
                'failed'  => SatusehatResourceLog::whereDate('created_at', $d)->where('status', 'FAILED')->count(),
            ];
        }

        // ── Kesiapan data (kenapa ada yang ke-SKIP) ───────────────────────────
        // Selain angka, kirim DAFTAR (cap 50) agar tombol "Atur" di dashboard bisa
        // langsung menunjukkan item mana yang perlu dibereskan (dulu cuma link ke
        // halaman generik — bahkan link obat salah sasaran kena redirect permission).
        $doctorsNoNikQ = \App\Models\Employee::where('is_active', true)
            ->where('profession', 'like', '%okter%')
            ->where(fn ($q) => $q->whereNull('nik')->orWhere('nik', ''));
        $medsNoKfaQ = \App\Models\Medication::where(fn ($q) => $q->whereNull('kfa_code')->orWhere('kfa_code', ''));

        $readiness = [
            // Hanya dokter AKTIF yang relevan utk Satu Sehat (dokter lama/inactive — mis. atribusi
            // historis tanpa akun — tak akan jadi DPJP encounter baru, jadi tak dihitung).
            'doctors_without_nik' => (clone $doctorsNoNikQ)->count(),
            'doctors_without_nik_list' => (clone $doctorsNoNikQ)->orderBy('name')->limit(50)
                ->get(['id', 'name', 'profession'])->all(),
            'medications_without_kfa' => (clone $medsNoKfaQ)->count(),
            'medications_without_kfa_list' => (clone $medsNoKfaQ)->orderBy('name')->limit(50)
                ->get(['id', 'name'])->all(),
            'patients_without_ihs' => \App\Models\Patient::whereNull('satusehat_ihs')->count(),
            // Yang BISA di-resolve massal (punya NIK): target tombol "Resolve IHS".
            'patients_resolvable_ihs' => \App\Models\Patient::whereNull('satusehat_ihs')
                ->whereNotNull('nik')->where('nik', '!=', '')->count(),
        ];

        // ── Riwayat batch terakhir ────────────────────────────────────────────
        $batches = SatusehatSyncLog::latest()->take(10)->get()->map(fn ($l) => [
            'id'           => $l->id,
            'sync_date'    => $l->sync_date?->toDateString(),
            'sync_type'    => $l->sync_type,
            'status'       => $l->status,
            'total_sent'   => $l->total_sent,
            'total_failed' => $l->total_failed,
            'retry_count'  => $l->retry_count,
            'notes'        => $l->notes,
            'next_retry_at'=> $l->next_retry_at?->toIso8601String(),
            'created_at'   => $l->created_at?->toIso8601String(),
        ])->all();

        return [
            'connection' => $connection,
            'range'      => ['from' => $from, 'to' => $to],
            'cards'      => $cards,
            'visits'     => $visits,
            'trend'      => $trend,
            'readiness'  => $readiness,
            'batches'    => $batches,
        ];
    }

    // =========================================================================
    // RESOLVE IHS (NIK → IHS Number) — Fase 2
    // =========================================================================

    /** System identifier NIK resmi Kemenkes (untuk query Patient/Practitioner). */
    private const NIK_SYSTEM = 'https://fhir.kemkes.go.id/id/nik';

    /**
     * Resolve IHS pasien dari NIK. Cek cache kolom dulu; kalau kosong → GET ke
     * Kemenkes → simpan → return. Null bila NIK kosong / tidak ditemukan.
     */
    public function resolvePatientIhs(\App\Models\Patient $patient): ?string
    {
        if (! empty($patient->satusehat_ihs)) {
            return $patient->satusehat_ihs;
        }

        $ihs = $this->resolveIhsByNik('Patient', (string) $patient->nik);
        if ($ihs) {
            $patient->forceFill(['satusehat_ihs' => $ihs])->save();
        }

        return $ihs;
    }

    /**
     * Cek/resolve IHS satu pasien secara manual (tombol "Resolve IHS" di Admisi),
     * untuk memastikan NIK valid SEBELUM backfill massal. Memaksa hit ke Kemenkes
     * walau cache kosong; lempar 503 bila integrasi belum aktif.
     *
     * @return array{ihs: ?string, resolved: bool, patient: \App\Models\Patient}
     */
    public function checkPatientIhs(\App\Models\Patient $patient): array
    {
        $this->assertEnabled();

        if (trim((string) $patient->nik) === '') {
            throw new \Exception('Pasien belum punya NIK — lengkapi NIK dulu sebelum resolve IHS.', 422);
        }

        $ihs = $this->resolvePatientIhs($patient);

        return [
            'ihs'      => $ihs,
            'resolved' => $ihs !== null,
            'patient'  => $patient->fresh(),
        ];
    }

    /**
     * Resolve IHS dokter dari NIK. Cek cache kolom dulu; kalau kosong → GET ke
     * Kemenkes → simpan → return. Null bila NIK kosong / tidak ditemukan.
     */
    public function resolvePractitionerIhs(\App\Models\Employee $employee): ?string
    {
        if (! empty($employee->satusehat_ihs)) {
            return $employee->satusehat_ihs;
        }

        $ihs = $this->resolveIhsByNik('Practitioner', (string) $employee->nik);
        if ($ihs) {
            $employee->forceFill(['satusehat_ihs' => $ihs])->save();
        }

        return $ihs;
    }

    /**
     * Resolve IHS MASSAL untuk pasien ber-NIK yang belum punya IHS (tombol
     * "Resolve IHS" di dashboard Kesiapan Data). Per pasien = 1 GET ke Kemenkes.
     *
     * Antrean tanpa migrasi: orderBy updated_at ASC + touch() pasien yang TIDAK
     * ketemu — yang gagal pindah ke belakang antrean sehingga batch berikutnya
     * memproses pasien lain (tidak macet mengulang 100 NIK invalid yang sama).
     *
     * @return array{processed:int,resolved:int,not_found:int,error:?string,remaining_resolvable:int,remaining_total:int}
     */
    public function resolveIhsBatch(int $limit = 100): array
    {
        $this->assertEnabled();
        @set_time_limit(0); // jalur HTTP; 500 pasien ≈ menit-an

        $limit = max(1, min($limit, 1000));

        $patients = \App\Models\Patient::whereNull('satusehat_ihs')
            ->whereNotNull('nik')->where('nik', '!=', '')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $resolved = 0;
        $notFound = 0;
        $error    = null;
        $sample   = []; // bukti per-pasien (cap 20) utk ditampilkan di modal hasil

        foreach ($patients as $p) {
            try {
                $ihs = $this->resolvePatientIhs($p);
                if ($ihs) {
                    $resolved++;
                } else {
                    $notFound++;
                    $p->touch(); // ke belakang antrean (lihat docblock)
                }
                if (count($sample) < 20) {
                    $sample[] = [
                        'name' => $p->name,
                        // NIK dimask (privasi): cukup utk identifikasi visual.
                        'nik'  => substr((string) $p->nik, 0, 6) . str_repeat('•', 10),
                        'ihs'  => $ihs,
                    ];
                }
            } catch (\Throwable $e) {
                // Token/jaringan bermasalah → sisa batch pasti gagal juga; berhenti
                // dan laporkan, jangan menghantam Kemenkes ratusan kali percuma.
                $error = $e->getMessage();
                break;
            }
        }

        $resolvableQ = \App\Models\Patient::whereNull('satusehat_ihs')
            ->whereNotNull('nik')->where('nik', '!=', '');

        return [
            'processed'            => $resolved + $notFound,
            'resolved'             => $resolved,
            'not_found'            => $notFound,
            'error'                => $error,
            'sample'               => $sample,
            'remaining_resolvable' => $resolvableQ->count(),
            'remaining_total'      => \App\Models\Patient::whereNull('satusehat_ihs')->count(),
        ];
    }

    /**
     * GET {fhir}/{resource}?identifier=nikSystem|{nik} → ambil id dari Bundle entry pertama.
     *
     * @param  string  $resource  'Patient' | 'Practitioner'
     */
    private function resolveIhsByNik(string $resource, string $nik): ?string
    {
        $nik = trim($nik);
        if ($nik === '') {
            return null;
        }

        $result = $this->client()->get($resource, [
            'identifier' => self::NIK_SYSTEM . '|' . $nik,
        ]);

        if (! ($result['is_success'] ?? false)) {
            return null;
        }

        // FHIR search → Bundle. Ambil entry[0].resource.id.
        $entries = $result['response']['entry'] ?? [];
        $id      = $entries[0]['resource']['id'] ?? null;

        return $id ? (string) $id : null;
    }

    /**
     * Cari produk KFA (untuk tombol "Cari KFA" di master Obat). Mengembalikan
     * daftar ringkas {kfa_code, name, nama_dagang, manufacturer, dosage_form}.
     */
    public function searchKfa(string $keyword): array
    {
        if (trim($keyword) === '') {
            return ['success' => true, 'items' => []];
        }

        try {
            $res = $this->client()->kfaSearch($keyword);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'items' => []];
        }

        $items = collect($res['items'] ?? [])->map(fn ($it) => [
            'kfa_code'     => $it['kfa_code'] ?? null,
            'name'         => $it['name'] ?? null,
            'nama_dagang'  => $it['nama_dagang'] ?? null,
            'manufacturer' => $it['manufacturer'] ?? null,
            'dosage_form'  => $it['dosage_form']['name'] ?? null,
        ])->filter(fn ($i) => ! empty($i['kfa_code']))->values()->all();

        return [
            'success' => (bool) ($res['is_success'] ?? false),
            'items'   => $items,
        ];
    }

    /**
     * Helper uji manual: resolve NIK mentah → IHS + status, untuk dipanggil dari
     * tinker/endpoint test tanpa harus punya model. Tidak menyimpan ke DB.
     */
    public function probeIhsByNik(string $resource, string $nik): array
    {
        try {
            $ihs = $this->resolveIhsByNik($resource, $nik);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success'  => $ihs !== null,
            'resource' => $resource,
            'nik'      => $nik,
            'ihs'      => $ihs,
            'message'  => $ihs ? "IHS {$resource} ditemukan" : "IHS {$resource} tidak ditemukan untuk NIK tersebut",
        ];
    }

    /**
     * Preview payload FHIR (Encounter + semua Condition) untuk satu visit TANPA
     * mengirim. Dipakai untuk uji/validasi struktur sebelum POST sungguhan.
     *
     * @return array{encounter: array, conditions: array<int,array>}
     */
    public function previewPayloads(Visit $visit): array
    {
        return [
            'encounter'        => $this->buildEncounterPayload($visit),
            'conditions'       => $this->buildConditionPayloads($visit, requireEncounter: false),
            // Obat: entry Bundle (Medication+MedReq+MedDisp). Hitung ringkas juga.
            'medicationEntries'    => $this->buildMedicationEntries($visit, 'urn:uuid:PREVIEW-ENC'),
            'medicationItemCount'  => $this->countMedicationItems($visit),
            'dispensedItemCount'   => $this->countMedicationItems($visit, dispensedOnly: true),
        ];
    }

    /**
     * Bangun FHIR transaction Bundle: Condition[] + Encounter dalam satu transaksi
     * atomik. Encounter.diagnosis refer tiap Condition via urn:uuid (memecah
     * dependensi melingkar Encounter↔Condition). Condition.encounter refer
     * Encounter via urn:uuid juga.
     */
    public function buildVisitBundle(Visit $visit): array
    {
        $encUuid     = 'urn:uuid:' . \Illuminate\Support\Str::uuid()->toString();
        $encounter   = $this->buildEncounterPayload($visit);
        $conditions  = $this->buildConditionPayloads($visit, requireEncounter: false);

        // Satu Sehat production MEWAJIBKAN Encounter.diagnosis (RuleNumber 10457)
        // — tanpa Condition, Bundle pasti ditolak setelah round-trip dengan pesan
        // kriptik. Gagal-jelas di sini: pesan tercatat ke resource log via syncVisit.
        if (! $conditions) {
            throw new \RuntimeException(
                'Diagnosis (ICD-10) belum diisi pada pemeriksaan dokter — '
                . 'Encounter.diagnosis wajib di Satu Sehat. Lengkapi diagnosa utama '
                . 'via modul Dokter sebelum kunjungan bisa dikirim.', 412);
        }

        $entries        = [];
        $diagnosisRefs  = [];

        foreach ($conditions as $i => $cond) {
            $condUuid = 'urn:uuid:' . \Illuminate\Support\Str::uuid()->toString();
            // Condition refer ke Encounter via urn:uuid (bukan id final).
            $cond['encounter'] = ['reference' => $encUuid];

            $entries[] = [
                'fullUrl'  => $condUuid,
                'resource' => $cond,
                'request'  => ['method' => 'POST', 'url' => 'Condition'],
            ];

            $diagnosisRefs[] = [
                'condition' => ['reference' => $condUuid],
                'use'       => [
                    'coding' => [[
                        'system'  => 'http://terminology.hl7.org/CodeSystem/diagnosis-role',
                        'code'    => $i === 0 ? 'DD' : 'AD',
                        'display' => $i === 0 ? 'Discharge diagnosis' : 'Admission diagnosis',
                    ]],
                ],
                'rank'      => $i + 1,
            ];
        }

        // Encounter.diagnosis (WAJIB, RuleNumber 10457) refer Condition di Bundle.
        if ($diagnosisRefs) {
            $encounter['diagnosis'] = $diagnosisRefs;
        }

        // Obat: Medication + MedicationRequest + MedicationDispense (saling refer urn:uuid).
        foreach ($this->buildMedicationEntries($visit, $encUuid) as $medEntry) {
            $entries[] = $medEntry;
        }

        // Encounter diletakkan TERAKHIR agar urn:uuid resource lain sudah dikenal.
        $entries[] = [
            'fullUrl'  => $encUuid,
            'resource' => $encounter,
            'request'  => ['method' => 'POST', 'url' => 'Encounter'],
        ];

        return [
            'resourceType' => 'Bundle',
            'type'         => 'transaction',
            'entry'        => $entries,
        ];
    }

    /**
     * Kirim seluruh visit (Encounter + Condition) sebagai transaction Bundle ke
     * root {fhir}/. Atomik: semua berhasil atau semua batal di sisi server.
     * Simpan satusehat_encounter_id dari entry Encounter pada response.
     */
    public function syncVisitBundle(Visit $visit, ?string $syncLogId = null): array
    {
        if (! empty($visit->satusehat_encounter_id)) {
            return ['is_success' => true, 'encounter_id' => $visit->satusehat_encounter_id, 'skipped' => true];
        }

        $bundle = $this->buildVisitBundle($visit);
        $result = $this->client()->post('/', $bundle);

        $resp        = $result['response'] ?? [];
        $isSuccess   = (bool) ($result['is_success'] ?? false);
        $encounterId = $this->extractEncounterId($resp);

        // Idempoten by-identifier: server menolak duplikat (RuleNumber 20002) bila
        // Encounter dgn identifier sama sudah pernah terkirim. Itu BUKAN gagal —
        // resolve encounter id yang sudah ada lalu anggap sukses.
        $isDuplicate = $this->isDuplicateOutcome($resp);
        if (! $isSuccess && $isDuplicate) {
            $encounterId = $this->findEncounterIdByIdentifier($visit);
            $isSuccess   = $encounterId !== null;
        }

        // Log per-resource hasil Bundle (untuk audit) — hanya bila ada entry response.
        if (! empty($resp['entry'])) {
            $this->logBundleEntries($visit->id, $bundle, $resp, $syncLogId);
        } elseif (! $isSuccess) {
            // Bundle ditolak UTUH (OperationOutcome tanpa entry) — wajib dicatat:
            // insiden "Encounter.location 10120" tak terlihat operator karena alur
            // ini dulu tak menulis log apa pun, dan retry() jadi tak menemukan
            // visit gagal (membaca dari resource log).
            SatusehatResourceLog::create([
                'satusehat_sync_log_id' => $syncLogId,
                'visit_id'              => $visit->id,
                'resource_type'         => 'Bundle',
                'response_payload'      => $resp,
                'http_status'           => (int) ($result['http_status'] ?? 0),
                'status'                => 'FAILED',
                'error_message'         => collect($resp['issue'] ?? [])
                    ->map(fn ($i) => $i['details']['text'] ?? $i['diagnostics'] ?? null)
                    ->filter()->implode('; ') ?: null,
            ]);
        }

        if ($isSuccess && $encounterId) {
            $visit->forceFill([
                'satusehat_encounter_id' => $encounterId,
                'satusehat_sync_status'  => 'SYNCED',
                'satusehat_synced_at'    => now(),
            ])->save();
        } elseif (! $isSuccess) {
            $visit->forceFill(['satusehat_sync_status' => 'FAILED'])->save();
        }

        // Peringatan: obat tanpa kfa_code TIDAK ikut terkirim (MedicationRequest
        // di-skip). Beri tahu pemanggil/UI agar tidak terlihat "sukses penuh"
        // padahal peresepan kosong.
        $skippedNoKfa = $this->skippedNoKfaItems($visit);

        return [
            'is_success'    => $isSuccess,
            'encounter_id'  => $encounterId,
            'duplicate'     => $isDuplicate,
            'http_status'   => $result['http_status'] ?? 0,
            'response'      => $resp,
            'skipped_no_kfa' => $skippedNoKfa,
            'warning'       => $skippedNoKfa
                ? count($skippedNoKfa) . ' obat tidak terkirim ke Satu Sehat (belum punya kode KFA): ' . implode(', ', $skippedNoKfa)
                : null,
        ];
    }

    /** Deteksi OperationOutcome bertipe duplicate (RuleNumber 20002). */
    private function isDuplicateOutcome(array $resp): bool
    {
        if (($resp['resourceType'] ?? null) !== 'OperationOutcome') {
            return false;
        }
        foreach ($resp['issue'] ?? [] as $issue) {
            if (($issue['code'] ?? null) === 'duplicate') {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve Encounter id yang SUDAH ada di Satu Sehat berdasarkan identifier
     * lokal kunjungan (dipakai saat server menolak duplikat).
     */
    private function findEncounterIdByIdentifier(Visit $visit): ?string
    {
        $orgId = $this->client()->organizationId();
        $ident = $visit->no_registrasi ?: $visit->no_antreen ?: $visit->id;

        $result = $this->client()->get('Encounter', [
            'identifier' => "http://sys-ids.kemkes.go.id/encounter/{$orgId}|{$ident}",
        ]);

        if (! ($result['is_success'] ?? false)) {
            return null;
        }

        $id = $result['response']['entry'][0]['resource']['id'] ?? null;

        return $id ? (string) $id : null;
    }

    /**
     * Ambil id Encounter dari response Bundle. Satu Sehat menaruh hasil di
     * entry[].response (bukan entry[].resource) dengan field resourceType +
     * resourceID, atau di header `location` (…/Encounter/{id}/_history/…).
     */
    private function extractEncounterId(array $bundleResponse): ?string
    {
        foreach ($bundleResponse['entry'] ?? [] as $entry) {
            $resp = $entry['response'] ?? [];

            // Format Satu Sehat: response.resourceType + response.resourceID.
            if (($resp['resourceType'] ?? null) === 'Encounter' && ! empty($resp['resourceID'])) {
                return (string) $resp['resourceID'];
            }

            // Fallback: id langsung di resource (FHIR standar).
            $res = $entry['resource'] ?? [];
            if (($res['resourceType'] ?? null) === 'Encounter' && ! empty($res['id'])) {
                return (string) $res['id'];
            }

            // Fallback: parse dari header location .../Encounter/{id}/_history/...
            $loc = $resp['location'] ?? null;
            if (is_string($loc) && preg_match('#/Encounter/([^/]+)#', $loc, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** Catat tiap resource Bundle ke satusehat_resource_logs. */
    private function logBundleEntries(string $visitId, array $bundle, array $response, ?string $syncLogId): void
    {
        $respEntries = $response['entry'] ?? [];
        foreach ($bundle['entry'] as $i => $entry) {
            $type   = $entry['resource']['resourceType'] ?? 'Unknown';
            $respE  = $respEntries[$i]['response'] ?? [];
            // Satu Sehat: status string spt "201 Created".
            $status = (string) ($respE['status'] ?? '');
            $code   = (int) (preg_match('/^(\d+)/', $status, $m) ? $m[1] : 0);
            $ok     = $code >= 200 && $code < 300;

            SatusehatResourceLog::create([
                'satusehat_sync_log_id' => $syncLogId,
                'visit_id'              => $visitId,
                'resource_type'         => $type,
                'fhir_payload'          => $entry['resource'],
                'response_payload'      => $respE,
                'http_status'           => $code,
                'status'                => $ok ? 'SUCCESS' : 'FAILED',
            ]);
        }
    }

    // =========================================================================
    // PRIVATE — FHIR payload builders
    // =========================================================================

    /**
     * FHIR R4 Encounter (rawat jalan). WAJIB: subject (Patient IHS) + participant
     * (Practitioner IHS) + serviceProvider (Organization). Location di-SKIP bila
     * location_id kosong (keputusan user — jangan kirim Location/ kosong).
     *
     * @throws \RuntimeException bila IHS pasien/dokter belum bisa di-resolve (412).
     */
    private function buildEncounterPayload(Visit $visit): array
    {
        $patient = $visit->patient;
        $exam    = $visit->doctorExamination;
        $doctor  = $exam?->doctor;

        $patientIhs = $patient ? $this->resolvePatientIhs($patient) : null;
        $doctorIhs  = $doctor ? $this->resolvePractitionerIhs($doctor) : null;

        if (! $patientIhs) {
            throw new \RuntimeException("IHS pasien belum bisa di-resolve (NIK: {$patient?->nik}).", 412);
        }
        if (! $doctorIhs) {
            throw new \RuntimeException("IHS dokter belum bisa di-resolve (NIK: {$doctor?->nik}).", 412);
        }

        $client  = $this->client();
        $orgId   = $client->organizationId();
        $locId   = $client->locationId();

        // Satu Sehat production MEWAJIBKAN Encounter.location (RuleNumber 10120) —
        // dulu elemen ini di-skip diam-diam bila location_id kosong ("keputusan
        // 2026-05-30") sehingga SEMUA Bundle ditolak 400 tanpa jejak. Gagal-jelas
        // di sini: pesan tercatat ke resource log via syncVisit().
        if ($locId === '') {
            throw new \RuntimeException(
                'Location Satu Sehat belum dikonfigurasi (configuration.location_id kosong). '
                . 'Set lokasi aktif di Bridging → Satu Sehat → Location.', 412);
        }

        // Class + period conditional by jenis pelayanan:
        //   RANAP → IMP (inpatient), period.start=admission_at, end=discharge_at??now()
        //   IGD   → EMER (emergency)
        //   else  → AMB (ambulatory) — perilaku lama rawat jalan
        $jenis = $visit->jenis_pelayanan ?? 'RAJAL';
        [$classCode, $classDisplay] = match ($jenis) {
            'RANAP' => ['IMP', 'inpatient encounter'],
            'IGD'   => ['EMER', 'emergency'],
            default => ['AMB', 'ambulatory'],
        };

        if ($jenis === 'RANAP') {
            $start = $this->toWibIso($visit->admission_at ?? $visit->visit_date ?? $visit->created_at);
            $end   = $this->toWibIso($visit->discharge_at ?? now());
        } else {
            $start = $this->toWibIso($visit->visit_date ?? $visit->created_at);
            $end   = $this->toWibIso($visit->satusehat_synced_at ?? now());
        }

        $payload = [
            'resourceType' => 'Encounter',
            // identifier kunjungan lokal (WAJIB Satu Sehat, RuleNumber 10117).
            'identifier' => [[
                'system' => "http://sys-ids.kemkes.go.id/encounter/{$orgId}",
                'value'  => $visit->no_registrasi ?: $visit->no_antreen ?: $visit->id,
            ]],
            'status'       => 'finished',
            'class'        => [
                'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code'    => $classCode,
                'display' => $classDisplay,
            ],
            'subject' => [
                'reference' => "Patient/{$patientIhs}",
                'display'   => $patient->name,
            ],
            'participant' => [[
                'type' => [[
                    'coding' => [[
                        'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType',
                        'code'    => 'ATND',
                        'display' => 'attender',
                    ]],
                ]],
                'individual' => [
                    'reference' => "Practitioner/{$doctorIhs}",
                    'display'   => $doctor->name,
                ],
            ]],
            'period' => array_filter([
                'start' => $start,
                'end'   => $end,
            ]),
            'statusHistory' => [[
                'status' => 'finished',
                'period' => array_filter(['start' => $start, 'end' => $end]),
            ]],
        ];

        if ($orgId !== '') {
            $payload['serviceProvider'] = ['reference' => "Organization/{$orgId}"];
        }
        // location WAJIB (sudah dijamin non-kosong oleh guard 412 di atas).
        $payload['location'] = [[
            'location' => ['reference' => "Location/{$locId}"],
        ]];

        return $payload;
    }

    /**
     * FHIR R4 Condition (satu kunjungan bisa banyak diagnosa). Builder ini
     * mengembalikan SATU Condition untuk diagnosa utama; sekunder dibangun lewat
     * {@see buildConditionPayloads()} (jamak). diagnosis_utama = string ICD-10.
     *
     * @throws \RuntimeException bila IHS pasien / encounter belum siap (412).
     */
    private function buildConditionPayload(Visit $visit): array
    {
        $list = $this->buildConditionPayloads($visit);

        return $list[0] ?? ['resourceType' => 'Condition'];
    }

    /**
     * Semua Condition untuk satu visit: utama (encounter-diagnosis) + sekunder.
     * Mengembalikan array of payload FHIR.
     *
     * @return array<int,array>
     */
    private function buildConditionPayloads(Visit $visit, bool $requireEncounter = true): array
    {
        $patient = $visit->patient;
        $exam    = $visit->doctorExamination;

        $patientIhs  = $patient ? $this->resolvePatientIhs($patient) : null;
        $encounterId = $visit->satusehat_encounter_id;

        if (! $patientIhs) {
            throw new \RuntimeException('IHS pasien belum bisa di-resolve untuk Condition.', 412);
        }
        // Jalur Bundle tidak butuh id final — encounter ref di-set via urn:uuid.
        if ($requireEncounter && ! $encounterId) {
            throw new \RuntimeException('Encounter belum dibuat — kirim Encounter dulu sebelum Condition.', 412);
        }

        $subject   = ['reference' => "Patient/{$patientIhs}", 'display' => $patient->name];
        // Placeholder; di Bundle ditimpa dgn urn:uuid oleh buildVisitBundle().
        $encounter = $encounterId ? ['reference' => "Encounter/{$encounterId}"] : [];
        $recorded  = $this->toWibIso($visit->visit_date ?? $visit->created_at);

        $out = [];

        // Diagnosa UTAMA (encounter-diagnosis).
        $primary = is_string($exam?->diagnosis_utama) ? trim($exam->diagnosis_utama) : null;
        if ($primary) {
            $out[] = $this->oneCondition($primary, $subject, $encounter, $recorded, true);
        }

        // Diagnosa SEKUNDER (array of code).
        foreach ((array) ($exam?->diagnosis_sekunder ?? []) as $sec) {
            $code = is_array($sec) ? ($sec['code'] ?? $sec['kode'] ?? null) : $sec;
            $code = is_string($code) ? trim($code) : null;
            if ($code) {
                $out[] = $this->oneCondition($code, $subject, $encounter, $recorded, false);
            }
        }

        return $out;
    }

    /** Bentuk satu resource Condition FHIR R4 dari kode ICD-10. */
    private function oneCondition(string $icd10, array $subject, array $encounter, ?string $recorded, bool $isPrimary): array
    {
        return array_filter([
            'resourceType' => 'Condition',
            'clinicalStatus' => [
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                    'code'    => 'active',
                    'display' => 'Active',
                ]],
            ],
            'category' => [[
                'coding' => [[
                    'system'  => 'http://terminology.hl7.org/CodeSystem/condition-category',
                    'code'    => 'encounter-diagnosis',
                    'display' => 'Encounter Diagnosis',
                ]],
            ]],
            'code' => [
                'coding' => [[
                    'system'  => 'http://hl7.org/fhir/sid/icd-10',
                    'code'    => $icd10,
                ]],
            ],
            'subject'      => $subject,
            'encounter'    => $encounter,
            'recordedDate' => $recorded,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Petakan satuan obat lokal → kode UCUM resmi (Satu Sehat tolak annotation
     * {tablet} dsb). Unit kemasan tanpa UCUM bersih → fallback ke kode UCUM
     * netral 'U' (unit) yg valid. Pemetaan minimal; lengkapi sesuai master obat.
     */
    private function ucumCode(?string $unit): string
    {
        $u = strtolower(trim((string) $unit));

        return match (true) {
            str_contains($u, 'ml')                              => 'mL',
            str_contains($u, 'mg')                              => 'mg',
            $u === 'g' || str_contains($u, 'gram')              => 'g',
            str_contains($u, 'mcg') || str_contains($u, 'µg')   => 'ug',
            str_contains($u, 'tetes') || str_contains($u, 'drop') => '[drp]', // tetes (UCUM drop)
            // 'unit'/'iu'/kemasan lain → arbitrary unit (UCUM [arb'U], terverifikasi OK).
            default                                              => '[arb\'U]',
        };
    }

    /** Format tanggal/datetime ke ISO8601 WIB (Asia/Jakarta) untuk FHIR. */
    private function toWibIso($value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)
                ->timezone('Asia/Jakarta')
                ->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** KFA system identifier resmi Kemenkes (obat). */
    private const KFA_SYSTEM = 'http://sys-ids.kemkes.go.id/kfa';

    /**
     * Bangun entry Bundle untuk obat: per prescription_item ber-KFA hasilkan
     * TIGA resource yang saling refer via urn:uuid —
     *   1. Medication  (medicationCodeableConcept KFA) — Satu Sehat WAJIB resource
     *      terpisah, di-refer via medicationReference (RuleNumber 10135/10138).
     *   2. MedicationRequest  → medicationReference Medication, encounter, requester,
     *      identifier (10388).
     *   3. MedicationDispense (hanya jika prescription DISPENSED) → medicationReference,
     *      authorizingPrescription→MedicationRequest (10393), identifier (10389),
     *      performer, quantity (system UCUM), whenHandedOver.
     * Item tanpa KFA di-SKIP. Encounter di-refer via $encUuid.
     *
     * @return array<int,array>  entry siap-Bundle (fullUrl/resource/request)
     */
    private function buildMedicationEntries(Visit $visit, string $encUuid): array
    {
        $patient = $visit->patient;
        $patientIhs = $patient ? $this->resolvePatientIhs($patient) : null;
        if (! $patientIhs) {
            return [];
        }

        $subject = ['reference' => "Patient/{$patientIhs}", 'display' => $patient->name];
        $orgId   = $this->client()->organizationId();
        $entries = [];
        // Peta KFA → urn:uuid Medication agar 1 obat (1 KODE KFA) hanya tercipta
        // SEKALI per Bundle, sekalipun diresepkan di banyak baris item.
        $medUuidByKfa = [];

        foreach ($visit->prescriptions ?? [] as $presc) {
            // Resep batal jangan dikirim sebagai MedicationRequest "active"
            // (konsisten dgn skippedNoKfaItems yang juga skip CANCELLED).
            if ($presc->status === 'CANCELLED') {
                continue;
            }

            $authoredOn = $this->toWibIso($presc->created_at);
            $doctor     = $presc->prescribedBy;
            $doctorIhs  = $doctor ? $this->resolvePractitionerIhs($doctor) : null;
            $requester  = $doctorIhs
                ? ['reference' => "Practitioner/{$doctorIhs}", 'display' => $doctor->name]
                : null;

            $isDispensed    = $presc->status === 'DISPENSED';
            $whenHandedOver = $this->toWibIso($presc->dispensed_at);
            $petugas        = $presc->dispensedBy;
            $petugasIhs     = $petugas ? $this->resolvePractitionerIhs($petugas) : null;

            foreach ($presc->items ?? [] as $item) {
                $kfa = $item->medication?->kfa_code;
                if (empty($kfa)) {
                    continue; // SKIP item tanpa KFA (skip-aman, lihat note Fase 4).
                }

                $reqUuid = 'urn:uuid:' . \Illuminate\Support\Str::uuid()->toString();
                $medName = $item->medication->name;
                $kfa     = (string) $kfa;

                // 1. Medication — DEDUP per KODE KFA dalam satu Bundle.
                //    Satu Sehat menolak >1 Medication ber-KFA sama dalam satu
                //    transaction (RuleNumber 20002, "duplicate … due to previous").
                //    Identifier unik per item TIDAK menolong: dedup server berbasis
                //    CODE, bukan identifier. Obat yang sama diresepkan di banyak baris
                //    (lazim: tetes OD/OS, tapering dosis) → seluruh Bundle (termasuk
                //    Encounter) di-rollback dan visit stuck FAILED permanen. Maka cukup
                //    1 resource Medication per KFA; semua MedicationRequest/Dispense
                //    obat itu mereferensikannya.
                if (! isset($medUuidByKfa[$kfa])) {
                    $medUuid            = 'urn:uuid:' . \Illuminate\Support\Str::uuid()->toString();
                    $medUuidByKfa[$kfa] = $medUuid;

                    // extension medicationType WAJIB (RuleNumber 10031): NC = non-racikan.
                    $entries[] = [
                        'fullUrl'  => $medUuid,
                        'resource' => [
                            'resourceType' => 'Medication',
                            'extension'    => [[
                                'url' => 'https://fhir.kemkes.go.id/r4/StructureDefinition/MedicationType',
                                'valueCodeableConcept' => [
                                    'coding' => [[
                                        'system'  => 'http://terminology.kemkes.go.id/CodeSystem/medication-type',
                                        'code'    => 'NC',
                                        'display' => 'Non-compound',
                                    ]],
                                ],
                            ]],
                            'identifier' => [[
                                'system' => "http://sys-ids.kemkes.go.id/medication/{$orgId}",
                                // Unik per (kunjungan, KFA) — aman lintas-Bundle.
                                'value'  => $item->id,
                            ]],
                            'code' => [
                                'coding' => [[
                                    'system'  => self::KFA_SYSTEM,
                                    'code'    => $kfa,
                                    'display' => $medName,
                                ]],
                            ],
                            'status' => 'active',
                        ],
                        'request' => ['method' => 'POST', 'url' => 'Medication'],
                    ];
                }
                $medUuid = $medUuidByKfa[$kfa];

                // 2. MedicationRequest.
                $entries[] = [
                    'fullUrl'  => $reqUuid,
                    'resource' => array_filter([
                        'resourceType' => 'MedicationRequest',
                        'identifier'   => [[
                            'system' => "http://sys-ids.kemkes.go.id/prescription/{$orgId}",
                            'value'  => $presc->id . ':' . $item->id,
                        ]],
                        'status'             => 'active',
                        'intent'             => 'order',
                        'medicationReference'=> ['reference' => $medUuid, 'display' => $medName],
                        'subject'            => $subject,
                        'encounter'          => ['reference' => $encUuid],
                        'authoredOn'         => $authoredOn,
                        'requester'          => $requester,
                        'dosageInstruction'  => [array_filter([
                            'text' => trim(($item->dose ?? '') . ' ' . ($item->frequency ?? '') . ' ' . ($item->route ?? '') . ' ' . ($item->instructions ?? $item->dosage ?? '')) ?: null,
                        ], fn ($v) => $v !== null && $v !== [])],
                    ], fn ($v) => $v !== null && $v !== []),
                    'request' => ['method' => 'POST', 'url' => 'MedicationRequest'],
                ];

                // 3. MedicationDispense (hanya bila sudah diserahkan).
                if ($isDispensed) {
                    $performer = $petugasIhs
                        ? [['actor' => ['reference' => "Practitioner/{$petugasIhs}", 'display' => $petugas->name]]]
                        : null;

                    $entries[] = [
                        'fullUrl'  => 'urn:uuid:' . \Illuminate\Support\Str::uuid()->toString(),
                        'resource' => array_filter([
                            'resourceType' => 'MedicationDispense',
                            'identifier'   => [[
                                'system' => "http://sys-ids.kemkes.go.id/prescription/{$orgId}",
                                'value'  => 'disp:' . $presc->id . ':' . $item->id,
                            ]],
                            'status'              => 'completed',
                            'medicationReference' => ['reference' => $medUuid, 'display' => $medName],
                            'subject'             => $subject,
                            'context'             => ['reference' => $encUuid],
                            'authorizingPrescription' => [['reference' => $reqUuid]],
                            'performer'           => $performer,
                            'quantity'            => array_filter([
                                'value'  => $item->quantity !== null ? (float) $item->quantity : null,
                                'unit'   => $item->medication->unit ?: 'unit',
                                'system' => 'http://unitsofmeasure.org',
                                // Satu Sehat hanya terima kode UCUM standar (mL/mg/…),
                                // bukan annotation {tablet}. Map unit lokal → UCUM.
                                'code'   => $this->ucumCode($item->medication->unit),
                            ], fn ($v) => $v !== null),
                            'whenHandedOver'      => $whenHandedOver,
                        ], fn ($v) => $v !== null && $v !== []),
                        'request' => ['method' => 'POST', 'url' => 'MedicationDispense'],
                    ];
                }
            }
        }

        return $entries;
    }

    /** Hitung jumlah obat ber-KFA yg akan dikirim (utk preview/diagnostik). */
    private function countMedicationItems(Visit $visit, bool $dispensedOnly = false): int
    {
        $n = 0;
        foreach ($visit->prescriptions ?? [] as $presc) {
            if ($dispensedOnly && $presc->status !== 'DISPENSED') {
                continue;
            }
            foreach ($presc->items ?? [] as $item) {
                if (! empty($item->medication?->kfa_code)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * Daftar nama obat yang DILEWATI saat membangun bundle karena medication
     * belum punya kfa_code (lihat buildMedicationEntries: skip-aman). Dipakai
     * untuk warning di hasil sync — agar petugas tahu peresepan tak terkirim,
     * bukan diam-diam kosong. Lihat memory project-satusehat-kfa-resep-gap.
     *
     * @return list<string> nama obat unik tanpa KFA
     */
    public function skippedNoKfaItems(Visit $visit): array
    {
        $names = [];
        foreach ($visit->prescriptions ?? [] as $presc) {
            if ($presc->status === 'CANCELLED') {
                continue;
            }
            foreach ($presc->items ?? [] as $item) {
                if (empty($item->medication?->kfa_code)) {
                    $names[] = $item->medication?->name ?? ('item ' . $item->id);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Ringkasan warning KFA untuk kolom notes sync log. Null bila tak ada yg
     * ter-skip (notes dibiarkan kosong). Daftar nama obat dibatasi agar notes
     * tak membengkak.
     */
    private function buildKfaWarningNote(int $visitsWithSkip, array $skippedNames): ?string
    {
        $names = array_values(array_unique($skippedNames));
        if (empty($names)) {
            return null;
        }

        $shown = array_slice($names, 0, 20);
        $more  = count($names) - count($shown);

        return sprintf(
            '⚠ %d obat tanpa kode KFA tidak terkirim ke Satu Sehat (di %d kunjungan): %s%s. '
            . 'Isi kfa_code via master Obat → Cari KFA, atau jalankan `php artisan satusehat:isi-kfa --apply`.',
            count($names),
            $visitsWithSkip,
            implode(', ', $shown),
            $more > 0 ? " (+{$more} lainnya)" : ''
        );
    }

    /**
     * POST resource FHIR ke Satu Sehat + catat ke satusehat_resource_logs.
     * Return: [ 'is_success' => bool, 'id' => ?string, 'http_status' => int, 'response' => array ].
     */
    private function postFhir(string $resourceType, array $payload, string $visitId, ?string $syncLogId = null): array
    {
        $result = $this->client()->post('/' . $resourceType, $payload);

        $resp       = $result['response'] ?? [];
        $httpStatus = (int) ($result['http_status'] ?? 0);
        $isSuccess  = (bool) ($result['is_success'] ?? false);
        $id         = is_array($resp) ? ($resp['id'] ?? null) : null;

        SatusehatResourceLog::create([
            'satusehat_sync_log_id' => $syncLogId,
            'visit_id'              => $visitId,
            'resource_type'         => $resourceType,
            'fhir_payload'          => $payload,
            'response_payload'      => is_array($resp) ? $resp : ['raw' => $result['raw'] ?? null],
            'http_status'           => $httpStatus,
            'status'                => $isSuccess ? 'SUCCESS' : 'FAILED',
        ]);

        return [
            'is_success'  => $isSuccess,
            'id'          => $id,
            'http_status' => $httpStatus,
            'response'    => is_array($resp) ? $resp : [],
        ];
    }

    private function assertEnabled(): void
    {
        $this->boot();

        if (! $this->isEnabled()) {
            throw new \Exception('Integrasi Satu Sehat belum diaktifkan.', 503);
        }
    }
}
