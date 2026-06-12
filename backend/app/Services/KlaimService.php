<?php

namespace App\Services;

use App\Models\BpjsClaim;
use App\Models\ClaimAuditLog;
use App\Models\DocumentTemplate;
use App\Models\InacbgsGroupingLog;
use App\Models\IntegrationConfig;
use App\Models\PatientDocument;
use App\Models\SystemLog;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KlaimService
{
    public function __construct(
        private readonly Request $request,
        private readonly InaCbgsService $eklaim,
    ) {}

    // =========================================================================
    // LIST & DETAIL
    // =========================================================================

    public function getClaimList(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsClaim::with(['visit.patient', 'visit.insurer', 'assignedTo']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter Rawat Jalan (RAJAL) / Rawat Inap (RANAP) via visit.
        if (! empty($filters['jenis_pelayanan'])) {
            $jp = $filters['jenis_pelayanan'];
            $query->whereHas('visit', fn ($v) => $v->where('jenis_pelayanan', $jp));
        }

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('no_sep', 'like', "%{$keyword}%")
                ->orWhere('patient_nik', 'like', "%{$keyword}%")
                ->orWhereHas('visit.patient', fn ($p) => $p->where('name', 'ilike', "%{$keyword}%"))
            );
        }

        if (! empty($filters['tanggal_from'])) {
            $query->whereDate('created_at', '>=', $filters['tanggal_from']);
        }

        if (! empty($filters['tanggal_to'])) {
            $query->whereDate('created_at', '<=', $filters['tanggal_to']);
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);
    }

    public function getClaimById(string $id): BpjsClaim
    {
        return BpjsClaim::with([
            'visit.patient',
            'visit.doctorExamination.doctor',
            'visit.billingInvoice',
            'assignedTo',
            'auditLogs.performedBy',
        ])->findOrFail($id);
    }

    // =========================================================================
    // REKAP KUNJUNGAN BPJS — screening pra-klaim (semua kunjungan BPJS per tgl)
    // =========================================================================

    /**
     * Daftar SEMUA kunjungan pasien BPJS (termasuk yang belum punya klaim),
     * difilter tanggal/rentang + pencarian, untuk layar rekap pra-klaim.
     */
    public function getBpjsVisitRecap(array $filters = []): LengthAwarePaginator
    {
        $query = Visit::query()
            ->where('guarantor_type', 'BPJS')
            ->with([
                'patient:id,name,no_rm,bpjs_number,nik,date_of_birth,gender',
                'dpjp:id,name',
                'doctorExamination:id,visit_id,doctor_id,diagnosis_utama',
                'doctorExamination.doctor:id,name',
                'doctorSchedule.employee:id,name',
                'billingInvoice:id,visit_id,status',
                'bpjsClaim:id,visit_id,diagnosis_utama',
                'bpjsClaim.attachments',
                'surgerySchedule:id,surgery_package_id',
                'surgerySchedule.surgeryPackage:id,name,surgery_type',
                // Dokumen RM pendukung klaim (live) — untuk status siap-klaim per baris.
                'patientDocuments' => fn ($q) => $q
                    ->whereIn('template_code', self::CLAIM_DOC_CODES)
                    ->whereNotIn('status', self::DOC_ARCHIVED_STATUSES)
                    ->select('id', 'visit_id', 'template_code', 'status'),
            ])
            // Hasil penunjang terstruktur: jumlah order vs order yang sudah ada hasil final.
            ->withCount([
                'diagnosticOrders as penunjang_order_count',
                'diagnosticOrders as penunjang_done_count' => fn ($q) => $q
                    ->whereHas('results', fn ($r) => $r
                        ->whereIn('result_status', ['COMPLETED', 'REVIEWED', 'APPROVED'])),
            ]);

        if (! empty($filters['tanggal'])) {
            $query->whereDate('visit_date', $filters['tanggal']);
        }
        if (! empty($filters['tanggal_from'])) {
            $query->whereDate('visit_date', '>=', $filters['tanggal_from']);
        }
        if (! empty($filters['tanggal_to'])) {
            $query->whereDate('visit_date', '<=', $filters['tanggal_to']);
        }
        // Tab jenis pelayanan: RAJAL / RANAP (IGD ikut hanya pada "Semua").
        if (! empty($filters['jenis'])) {
            $query->where('jenis_pelayanan', $filters['jenis']);
        }

        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('no_sep', 'like', "%{$kw}%")
                ->orWhereHas('patient', fn ($p) => $p
                    ->where('name', 'ilike', "%{$kw}%")
                    ->orWhere('bpjs_number', 'like', "%{$kw}%"))
            );
        }

        // Urut No SEP menaik (rekap per bulan). Kunjungan tanpa SEP ditaruh paling bawah.
        $page = $query->orderByRaw('no_sep IS NULL')->orderBy('no_sep')->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        // Label ICD-10 (DB hanya simpan kode). Cache per-request (pola show()).
        $icd10   = \App\Models\Icd10Code::pluck('description', 'code');
        $icd10Id = \App\Models\Icd10Code::pluck('indonesian_description', 'code');

        $jenisMap  = ['RANAP' => 'Rawat Inap', 'IGD' => 'Gawat Darurat', 'RAJAL' => 'Rawat Jalan'];
        $kelasMap  = ['1' => 'Kelas 1', '2' => 'Kelas 2', '3' => 'Kelas 3'];
        $genderMap = ['L' => 'Laki-laki', 'P' => 'Perempuan'];

        $page->getCollection()->transform(function ($v) use ($icd10, $icd10Id, $jenisMap, $kelasMap, $genderMap) {
            $v->append('dpjp_name');
            $sep = (array) ($v->sep_data ?? []);

            $dxCode = $v->doctorExamination?->diagnosis_utama ?? $v->bpjsClaim?->diagnosis_utama;
            $diagnosa = $dxCode
                ? trim($dxCode.' — '.(($icd10Id->get($dxCode) ?: $icd10->get($dxCode)) ?? ''))
                : null;
            $att = $v->bpjsClaim?->attachments ?? collect();

            // Tgl SEP: dari snapshot sep_data saat terbit, fallback tgl kunjungan.
            $tglSep = $sep['tglSep'] ?? $v->visit_date?->toDateString();
            // Kelas rawat (hak): snapshot dahulu, lalu kolom visit.
            $kls = (string) ($sep['klsRawatHak'] ?? $v->kelas_rawat_hak ?? '');
            // No rujukan: snapshot dahulu, lalu kolom visit.
            $noRujukan = $sep['noRujukan'] ?? $v->no_rujukan;
            $dob = $v->patient?->date_of_birth;

            // Penanda bedah: label dari tipe operasi paket (KATARAK/VITREORETINA/…),
            // fallback ke nama paket. Tanpa surgery_schedule → kunjungan tunggal.
            $pkg = $v->surgerySchedule?->surgeryPackage;
            $isBedah = $v->surgery_schedule_id !== null;
            $bedahLabel = $isBedah ? ($pkg?->surgery_type ?: $pkg?->name ?: 'Bedah') : null;

            // Status siap-klaim otomatis (dari data eager-loaded → tanpa N+1).
            $signedCodes = $v->patientDocuments
                ->filter(fn ($d) => in_array($d->status, ['FINALIZED', 'FINAL'], true))
                ->pluck('template_code')->unique()->values()->all();
            $penunjangOrderCount = (int) ($v->penunjang_order_count ?? 0);
            $penunjangDoneCount  = (int) ($v->penunjang_done_count ?? 0);
            $penunjangOk = $penunjangOrderCount === 0 || $penunjangDoneCount >= $penunjangOrderCount;
            $readiness = $this->rowClaimReadiness($v, $signedCodes, $penunjangOk);

            return [
                'visit_id'           => $v->id,
                'nama'               => $v->patient?->name,
                'no_rm'              => $v->patient?->no_rm,
                'tgl_lahir'          => $dob ? \Illuminate\Support\Carbon::parse($dob)->format('d-m-Y') : null,
                'gender'             => $genderMap[$v->patient?->gender] ?? $v->patient?->gender,
                'no_sep'             => $v->no_sep,
                'tgl_sep'            => $tglSep ? \Illuminate\Support\Carbon::parse($tglSep)->format('d-m-Y') : null,
                'jenis'              => $jenisMap[$v->jenis_pelayanan] ?? ($v->jenis_pelayanan ?? '—'),
                'jenis_kode'         => $v->jenis_pelayanan,
                'is_bedah'           => $isBedah,
                'bedah_label'        => $bedahLabel,
                'kelas'              => $kelasMap[$kls] ?? ($kls !== '' ? $kls : null),
                'no_rujukan'         => $noRujukan ?: null,
                'bpjs_number'        => $v->patient?->bpjs_number,
                'dpjp'               => $v->dpjp_name,
                'diagnosa'           => $diagnosa,
                'claim_id'           => $v->bpjsClaim?->id,
                'penunjang_count'    => $att->where('category', 'PENUNJANG')->count(),
                'penunjang_struct_count' => $penunjangDoneCount,
                'dokpendukung_count' => $att->where('category', '!=', 'PENUNJANG')->count(),
                // Status siap-klaim otomatis (dokumen wajib ber-TTD + penunjang final).
                'claim_ready'         => $readiness['claim_ready'],
                'docs_signed_count'   => $readiness['docs_signed_count'],
                'docs_required_count' => $readiness['docs_required_count'],
                'has_invoice'        => (bool) $v->billingInvoice,
                'invoice_status'     => $v->billingInvoice?->status,
                'is_paid'            => $v->billingInvoice?->status === 'PAID',
                // Screening pra-klaim (manual): null=belum dicek, true=Lengkap, false=Belum.
                'berkas_lengkap'     => $v->berkas_lengkap,
                'keterangan'         => $v->rekap_keterangan,
            ];
        });

        return $page;
    }

    /**
     * Pastikan visit punya BpjsClaim (untuk lampiran rekap). Buat DRAFT minimal
     * bila belum ada. HANYA dipanggil pada jalur tulis (upload/hapus lampiran)
     * supaya daftar klaim tidak banjir DRAFT phantom dari sekadar melihat.
     */
    public function ensureClaimForVisit(string $visitId): BpjsClaim
    {
        $visit = Visit::with('patient')->findOrFail($visitId);

        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }
        if (empty($visit->no_sep)) {
            throw new \Exception('Nomor SEP belum ada — generate SEP di Admisi terlebih dahulu.', 422);
        }

        $existing = BpjsClaim::where('visit_id', $visit->id)->first();
        if ($existing) {
            return $existing;
        }

        return BpjsClaim::create([
            'visit_id'    => $visit->id,
            'no_sep'      => $visit->no_sep,
            'patient_nik' => $visit->patient?->nik,
            'status'      => 'DRAFT',
        ]);
    }

    /**
     * Screening pra-klaim: petugas menandai kelengkapan berkas + keterangan (KET)
     * pada kunjungan BPJS. Murni manual, disimpan di kolom `visits`. `$lengkap`
     * null = belum dicek, true = Lengkap, false = Belum Lengkap.
     */
    public function setRekapKelengkapan(string $visitId, ?bool $lengkap, ?string $keterangan, ?string $userId): array
    {
        $visit = Visit::findOrFail($visitId);
        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }

        $visit->berkas_lengkap = $lengkap;
        $visit->rekap_keterangan = $keterangan;
        $visit->berkas_lengkap_by = $lengkap === null ? null : $userId;
        $visit->berkas_lengkap_at = $lengkap === null ? null : now();
        $visit->save();

        return [
            'visit_id'       => $visit->id,
            'berkas_lengkap' => $visit->berkas_lengkap,
            'keterangan'     => $visit->rekap_keterangan,
        ];
    }

    // =========================================================================
    // BERKAS KUNJUNGAN (LIVE) — dokumen RM + hasil penunjang + lampiran manual
    // =========================================================================

    /** Dokumen Form Registry yang relevan sebagai berkas pendukung klaim. */
    public const CLAIM_DOC_CODES = [
        'RESUME_MEDIS', 'RESUME_KLAIM', 'LAPORAN_PEMBEDAHAN',
        'CATATAN_OPERASI_KATARAK', 'LAPORAN_OPERASI_VITREO_RETINA', 'CHECKLIST_KESIAPAN_BEDAH',
    ];

    /** Status arsip dokumen — dikecualikan dari daftar berkas aktif. */
    public const DOC_ARCHIVED_STATUSES = ['SUPERSEDED', 'VOID', 'REJECTED'];

    private function docStatusLabel(string $status): string
    {
        return match ($status) {
            'DRAFT'                      => 'Draf',
            'RENDERED', 'PENDING_SIGNATURE' => 'Menunggu TTD',
            'FINALIZED', 'FINAL'         => 'Sudah TTD',
            default                      => $status,
        };
    }

    /**
     * Kode dokumen WAJIB ber-TTD untuk klaim, sesuai jenis kunjungan:
     * - Semua: RESUME_MEDIS.
     * - Bedah: laporan operasi sesuai surgery_type + checklist bedah.
     */
    private function requiredDocCodes(Visit $visit): array
    {
        $codes = ['RESUME_MEDIS'];
        if ($visit->surgery_schedule_id !== null) {
            $type = strtoupper((string) ($visit->surgerySchedule?->surgeryPackage?->surgery_type ?? ''));
            $codes[] = str_contains($type, 'KATARAK') ? 'CATATAN_OPERASI_KATARAK'
                : (str_contains($type, 'VITREO') ? 'LAPORAN_OPERASI_VITREO_RETINA' : 'LAPORAN_PEMBEDAHAN');
            $codes[] = 'CHECKLIST_KESIAPAN_BEDAH';
        }
        return $codes;
    }

    /**
     * Agregasi berkas pendukung klaim untuk SATU kunjungan — dibaca LIVE dari
     * sumber aslinya (tidak menyalin file):
     *  - documents : PatientDocument Form Registry (resume/laporan operasi/checklist)
     *  - penunjang : diagnostic_results terstruktur
     *  - manual    : ClaimAttachment (berkas luar yang di-upload)
     *  - checklist : kelengkapan otomatis (wajib TTD + sesuai jenis)
     */
    public function getVisitBerkas(string $visitId): array
    {
        $visit = Visit::with([
            'surgerySchedule.surgeryPackage:id,name,surgery_type',
            'bpjsClaim',
        ])->findOrFail($visitId);

        $docs = PatientDocument::where('visit_id', $visitId)
            ->whereIn('template_code', self::CLAIM_DOC_CODES)
            ->whereNotIn('status', self::DOC_ARCHIVED_STATUSES)
            ->orderBy('template_code')
            ->orderByDesc('created_at')
            ->get();

        $tplNames = DocumentTemplate::whereIn('code', self::CLAIM_DOC_CODES)->pluck('name', 'code');
        $claim = $visit->bpjsClaim;

        $documents = $docs->map(function (PatientDocument $d) use ($tplNames, $claim) {
            $signed = in_array($d->status, ['FINALIZED', 'FINAL'], true);
            $codingSynced = null;
            if ($d->template_code === self::CLAIM_RESUME_CODE && $claim) {
                $codingSynced = $d->claim_coding_hash === $this->claimCodingHash($claim);
            }
            return [
                'id'            => $d->id,
                'source'        => 'document',
                'template_code' => $d->template_code,
                'type_label'    => $tplNames[$d->template_code] ?? $d->template_code,
                'status'        => $d->status,
                'status_label'  => $this->docStatusLabel($d->status),
                'signed'        => $signed,
                'claim_ready'   => $signed,
                'revision'      => $d->revision,
                'signed_at'     => $d->finalized_at?->toIso8601String(),
                'coding_synced' => $codingSynced,
            ];
        })->values()->all();

        $penunjang = app(\App\Services\RmeAggregatorService::class)->penunjangForVisit($visitId);
        $manual = $claim ? $this->getAttachments($claim->id) : [];
        $checklist = $this->computeClaimChecklist($visit, $docs);

        return [
            'documents' => $documents,
            'penunjang' => $penunjang,
            'manual'    => $manual,
            'checklist' => $checklist,
        ];
    }

    /**
     * Kelengkapan klaim otomatis: tiap dokumen wajib harus FINALIZED; penunjang
     * (bila ada order) harus ada hasil COMPLETED/REVIEWED/APPROVED.
     * @param \Illuminate\Support\Collection<PatientDocument> $docs
     */
    public function computeClaimChecklist(Visit $visit, $docs): array
    {
        // template_code → signed? (true bila ada salah satu yg FINALIZED)
        $signedByCode = [];
        $presentByCode = [];
        foreach ($docs as $d) {
            $presentByCode[$d->template_code] = true;
            if (in_array($d->status, ['FINALIZED', 'FINAL'], true)) {
                $signedByCode[$d->template_code] = true;
            }
        }

        $tplNames = DocumentTemplate::whereIn('code', self::CLAIM_DOC_CODES)->pluck('name', 'code');
        $required = [];
        foreach ($this->requiredDocCodes($visit) as $code) {
            $required[] = [
                'key'     => $code,
                'label'   => $tplNames[$code] ?? $code,
                'present' => $presentByCode[$code] ?? false,
                'signed'  => $signedByCode[$code] ?? false,
            ];
        }

        // Penunjang: wajib hasil final bila ada order.
        $orders = \App\Models\DiagnosticOrder::where('visit_id', $visit->id)
            ->withCount(['results as done_count' => fn ($q) => $q
                ->whereIn('result_status', ['COMPLETED', 'REVIEWED', 'APPROVED'])])
            ->get();
        if ($orders->isNotEmpty()) {
            $allDone = $orders->every(fn ($o) => $o->done_count > 0);
            $required[] = [
                'key'     => 'PENUNJANG',
                'label'   => 'Hasil Penunjang',
                'present' => $orders->contains(fn ($o) => $o->done_count > 0),
                'signed'  => $allDone,
            ];
        }

        $missing = collect($required)->reject(fn ($r) => $r['signed'])->pluck('label')->values()->all();

        return [
            'required' => $required,
            'ready'    => count($missing) === 0,
            'missing'  => $missing,
        ];
    }

    /**
     * Status siap-klaim ringkas dari data yang SUDAH di-eager-load pada baris rekap
     * (tanpa query tambahan → aman dari N+1). $signedCodes = template_code dokumen
     * FINALIZED milik visit; $penunjangOk dari withCount order vs hasil selesai.
     */
    private function rowClaimReadiness(Visit $visit, array $signedCodes, bool $penunjangOk): array
    {
        $required = $this->requiredDocCodes($visit);
        $signedRequired = array_values(array_intersect($required, $signedCodes));
        $docsReady = count($signedRequired) === count($required);

        return [
            'docs_required_count' => count($required),
            'docs_signed_count'   => count($signedRequired),
            'claim_ready'         => $docsReady && $penunjangOk,
        ];
    }

    /**
     * Verifikator minta dokter mengoreksi diagnosa/dokumen (grouper mismatch).
     * Catat keterangan + tandai belum-lengkap + notifikasi DPJP. Koreksi nyata
     * dilakukan dokter via "Buka Kembali" (pra-bayar) / "Revisi & TTD Ulang".
     */
    public function requestCorrection(string $visitId, ?string $catatan, ?string $userId): array
    {
        $visit = Visit::with(['patient:id,name', 'doctorExamination:id,visit_id,doctor_id', 'surgerySchedule:id,lead_surgeon_id'])
            ->findOrFail($visitId);
        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }

        $note = trim((string) $catatan);
        $visit->rekap_keterangan = $note !== ''
            ? $note
            : ($visit->rekap_keterangan ?: 'Perlu koreksi diagnosa/dokumen untuk klaim');
        $visit->berkas_lengkap = false;
        $visit->save();

        $dpjpEmployeeId = $visit->doctorExamination?->doctor_id ?? $visit->surgerySchedule?->lead_surgeon_id;
        $recipientId = $dpjpEmployeeId
            ? \App\Models\User::where('employee_id', $dpjpEmployeeId)->value('id')
            : null;

        if ($recipientId) {
            \App\Models\Notification::create([
                'recipient_id' => $recipientId,
                'type'         => 'KLAIM_KOREKSI',
                'title'        => 'Permintaan koreksi untuk klaim BPJS',
                'message'      => 'Verifikator meminta koreksi diagnosa/dokumen kunjungan '
                    . ($visit->patient?->name ?? '')
                    . ($note !== '' ? ' — ' . $note : '')
                    . '. Buka kembali RME & finalisasi ulang.',
            ]);
        }

        $this->log($userId, 'KLAIM_MINTA_KOREKSI', Visit::class, $visit->id, $note ?: null);

        return [
            'visit_id'   => $visit->id,
            'notified'   => (bool) $recipientId,
            'keterangan' => $visit->rekap_keterangan,
        ];
    }

    // =========================================================================
    // PREPARE — build klaim dari data kunjungan
    // =========================================================================

    /**
     * Create or update BpjsClaim from visit doctor examination data.
     * Source: visit.no_sep, doctorExamination.{diagnosis_utama, diagnosis_sekunder, tindakan_codes}
     */
    public function prepareClaimData(string $visitId): BpjsClaim
    {
        $visit = Visit::with([
            'patient',
            'doctorExamination',
            'billingInvoice',
        ])->findOrFail($visitId);

        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }

        if (empty($visit->no_sep)) {
            throw new \Exception('Nomor SEP belum ada — generate SEP terlebih dahulu di Admisi.', 422);
        }

        $exam = $visit->doctorExamination;

        if (! $exam?->diagnosis_utama) {
            throw new \Exception('Diagnosis utama belum diisi oleh Dokter.', 422);
        }

        $user = auth('api')->user();

        $claim = DB::transaction(function () use ($visit, $exam, $user) {
            $existing = BpjsClaim::where('visit_id', $visit->id)->first();
            $oldStatus = $existing?->status;

            $claim = BpjsClaim::updateOrCreate(
                ['visit_id' => $visit->id],
                [
                    'no_sep'             => $visit->no_sep,
                    'patient_nik'        => $visit->patient->nik,
                    'diagnosis_utama'    => $exam->diagnosis_utama,
                    'diagnosis_sekunder' => $exam->diagnosis_sekunder ?? [],
                    'procedure_codes'    => $exam->tindakan_codes ?? [],
                    'status'             => $existing?->status ?? 'DRAFT',
                ]
            );

            $this->addAuditLog(
                $claim->id,
                $user?->employee_id,
                'PREPARE',
                $oldStatus,
                $claim->status,
                'Data klaim disiapkan dari data kunjungan'
            );

            return $claim;
        });

        $this->log($user?->id, 'PREPARE_CLAIM', BpjsClaim::class, $claim->id, "SEP {$visit->no_sep}");

        return $claim->fresh(['visit.patient', 'auditLogs.performedBy']);
    }

    // =========================================================================
    // ASSIGNMENT — tandai "dikerjakan oleh siapa" (soft, anti double-work)
    // =========================================================================

    /**
     * Tandai/lepas penanggung jawab klaim.
     * $userId = null → lepaskan. Soft (tidak mengunci).
     */
    public function assignClaim(string $claimId, ?string $assignToId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);
        $actor = auth('api')->user();

        $claim->update([
            'assigned_to_id' => $assignToId,
            'assigned_at'    => $assignToId ? now() : null,
        ]);

        $note = $assignToId
            ? 'Klaim ditandai dikerjakan oleh ' . (\App\Models\User::find($assignToId)?->name ?? $assignToId)
            : 'Penanda pengerjaan dilepas';
        $this->addAuditLog($claim->id, $actor?->employee_id, 'ASSIGN', $claim->status, $claim->status, $note);

        return $claim->fresh(['assignedTo', 'auditLogs.performedBy']);
    }

    // =========================================================================
    // KODING — edit diagnosis/tindakan klaim (oleh verifikator/koder)
    // =========================================================================

    /**
     * Perbarui koding klaim (diagnosis utama/sekunder + tindakan ICD-9).
     * Tidak menyentuh doctorExamination (rekam medis). Karena koding berubah,
     * hasil grouping & LUPIS direset — wajib grouping ulang.
     */
    public function updateClaimCoding(string $claimId, array $data): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim ke BPJS, koding tidak bisa diubah.', 422);
        }

        // Validasi kode ICD ada di master (koding BPJS wajib kode valid).
        $icd10Codes = array_filter(array_merge([$data['diagnosis_utama']], $data['diagnosis_sekunder'] ?? []));
        $icd9Codes  = array_filter($data['procedure_codes'] ?? []);

        $known10 = \App\Models\Icd10Code::whereIn('code', $icd10Codes)->pluck('code')->all();
        $unknown10 = array_diff($icd10Codes, $known10);
        if ($unknown10) {
            throw new \Exception('Kode ICD-10 tidak ditemukan di master: ' . implode(', ', $unknown10), 422);
        }

        $known9 = \App\Models\Icd9Code::whereIn('code', $icd9Codes)->pluck('code')->all();
        $unknown9 = array_diff($icd9Codes, $known9);
        if ($unknown9) {
            throw new \Exception('Kode ICD-9 tidak ditemukan di master: ' . implode(', ', $unknown9), 422);
        }

        $user      = auth('api')->user();
        $oldUtama  = $claim->diagnosis_utama;

        $claim->update([
            'diagnosis_utama'    => $data['diagnosis_utama'],
            'diagnosis_sekunder' => array_values($data['diagnosis_sekunder'] ?? []),
            'procedure_codes'    => array_values($data['procedure_codes'] ?? []),
            // Koding berubah → grouping & LUPIS tidak valid lagi.
            'inacbgs_kode'       => null,
            'inacbgs_tarif'      => null,
            'lupis_data'         => null,
        ]);

        $this->addAuditLog(
            $claim->id,
            $user?->employee_id,
            'EDIT_CODING',
            $claim->status,
            $claim->status,
            "Koding klaim diperbarui (Dx utama: {$oldUtama} → {$data['diagnosis_utama']}). Grouping direset."
        );
        $this->log($user?->id, 'EDIT_CLAIM_CODING', BpjsClaim::class, $claimId);

        // Koding berubah → lembar klaim (bila sudah dibuat) jadi tidak sah:
        // batalkan TTD & reset ke RENDERED supaya wajib di-TTD ulang oleh dokter.
        $this->refreshClaimResumeAfterRecoding($claim);

        return $claim->fresh(['auditLogs.performedBy']);
    }

    // =========================================================================
    // LEMBAR KLAIM — Resume Medis versi klaim (diagnosa/ICD dari koding koder),
    // di-TTD dokter via TtdDokumenView. Resume Medis dokter ASLI tetap utuh.
    // Dokumen pendukung BPJS selalu konsisten dengan angka grouping.
    // =========================================================================

    private const CLAIM_RESUME_CODE = 'RESUME_KLAIM';

    /**
     * Buat/refresh "Lembar Klaim" untuk klaim → PatientDocument RESUME_KLAIM
     * berstatus RENDERED, masuk antrian TTD dokter otomatis. Bila lembar sudah
     * ada (termasuk yang sudah FINALIZED), di-reset (void TTD lama) + di-stamp
     * ulang dengan sidik koding terkini.
     */
    public function generateClaimResume(string $claimId): array
    {
        $claim = BpjsClaim::with(['visit.patient'])->findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim ke BPJS — lembar klaim tidak bisa diubah.', 422);
        }
        if (empty($claim->diagnosis_utama)) {
            throw new \Exception('Diagnosis utama klaim belum diisi. Lengkapi koding dulu.', 422);
        }
        $visit = $claim->visit;
        if (! $visit) {
            throw new \Exception('Klaim tidak tertaut kunjungan.', 422);
        }

        $tpl = DocumentTemplate::where('code', self::CLAIM_RESUME_CODE)
            ->where('is_active', true)->whereNull('deprecated_at')->first();
        if (! $tpl) {
            throw new \Exception('Template Lembar Klaim (RESUME_KLAIM) belum tersedia — jalankan FormTemplateSeeder.', 500);
        }

        $hash = $this->claimCodingHash($claim);
        $user = auth('api')->user();

        $doc = DB::transaction(function () use ($claim, $visit, $tpl, $hash) {
            $existing = $this->findClaimResumeDoc($claim);
            if ($existing) {
                $this->resetClaimResumeDoc($existing, $hash);
                return $existing->fresh();
            }
            return PatientDocument::create([
                'patient_id'         => $visit->patient_id,
                'visit_id'           => $visit->id,
                'bpjs_claim_id'      => $claim->id,
                'document_type_id'   => $tpl->document_type_id,
                'template_code'      => $tpl->code,
                'template_version'   => $tpl->version,
                'status'             => 'RENDERED',
                'created_by_station' => 'klaim',
                'claim_coding_hash'  => $hash,
            ]);
        });

        $this->addAuditLog($claim->id, $user?->employee_id, 'LEMBAR_KLAIM',
            $claim->status, $claim->status, 'Lembar klaim dibuat/diperbarui — menunggu TTD dokter.');
        $this->log($user?->id, 'GENERATE_CLAIM_RESUME', PatientDocument::class, $doc->id);

        return $this->claimResumeSummary($doc, $claim);
    }

    /** Status lembar klaim untuk satu klaim (dipakai detail klaim & FE). */
    public function claimResumeStatus(string $claimId): array
    {
        $claim = BpjsClaim::findOrFail($claimId);
        return $this->claimResumeSummary($this->findClaimResumeDoc($claim), $claim);
    }

    /** Ringkasan status lembar klaim (exists/status/signed/sinkron-koding). */
    public function claimResumeSummary(?PatientDocument $doc, BpjsClaim $claim): array
    {
        if (! $doc) {
            return [
                'exists' => false, 'document_id' => null, 'status' => null,
                'signed' => false, 'signed_at' => null, 'coding_synced' => false,
            ];
        }
        return [
            'exists'        => true,
            'document_id'   => $doc->id,
            'status'        => $doc->status,
            'signed'        => $doc->status === 'FINALIZED',
            'signed_at'     => $doc->finalized_at?->toIso8601String(),
            // Koding klaim terkini masih sama dengan yang tertanam saat lembar dibuat?
            'coding_synced' => $doc->claim_coding_hash === $this->claimCodingHash($claim),
        ];
    }

    private function findClaimResumeDoc(BpjsClaim $claim): ?PatientDocument
    {
        return PatientDocument::where('bpjs_claim_id', $claim->id)
            ->where('template_code', self::CLAIM_RESUME_CODE)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Sidik koding klaim (Dx utama + sekunder + prosedur, ternormalisasi & terurut).
     * Beda hash = koding berubah → lembar wajib TTD ulang.
     */
    private function claimCodingHash(BpjsClaim $claim): string
    {
        $norm = fn ($arr) => collect($arr ?? [])
            ->map(fn ($x) => is_array($x) ? ($x['kode'] ?? $x['code'] ?? '') : $x)
            ->map(fn ($x) => trim((string) $x))
            ->filter()->unique()->sort()->values()->all();

        return hash('sha256', implode('|', [
            trim((string) $claim->diagnosis_utama),
            implode(',', $norm($claim->diagnosis_sekunder)),
            implode(',', $norm($claim->procedure_codes)),
        ]));
    }

    /**
     * Reset lembar klaim ke RENDERED + void TTD lama. DocumentSignature &
     * DocumentVerification append-only di model → hapus via raw query.
     */
    private function resetClaimResumeDoc(PatientDocument $doc, string $hash): void
    {
        DB::table('document_signatures')->where('patient_document_id', $doc->id)->delete();
        DB::table('document_verifications')->where('patient_document_id', $doc->id)->delete();
        $doc->update([
            'status'               => 'RENDERED',
            'rendered_html'        => null,
            'rendered_html_gz'     => null,
            'finalized_at'         => null,
            'final_integrity_hash' => null,
            'claim_coding_hash'    => $hash,
        ]);
    }

    /** Koding berubah → reset lembar klaim yang ADA (jangan auto-buat bila belum ada). */
    private function refreshClaimResumeAfterRecoding(BpjsClaim $claim): void
    {
        $doc = $this->findClaimResumeDoc($claim);
        if (! $doc) {
            return;
        }
        $this->resetClaimResumeDoc($doc, $this->claimCodingHash($claim));
        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'LEMBAR_KLAIM_RESET',
            $claim->status, $claim->status, 'Koding berubah → TTD lembar klaim dibatalkan; perlu TTD ulang.');
    }

    /** Guard sebelum finalisasi BPJS: lembar klaim wajib ADA, FINALIZED, & koding sinkron. */
    private function assertClaimResumeReady(BpjsClaim $claim): void
    {
        $doc = $this->findClaimResumeDoc($claim);
        if (! $doc) {
            throw new \Exception('Lembar klaim belum dibuat. Buat lembar klaim & minta TTD dokter sebelum finalisasi.', 422);
        }
        if ($doc->status !== 'FINALIZED') {
            throw new \Exception('Lembar klaim belum ditandatangani dokter. Tunggu TTD dokter sebelum finalisasi.', 422);
        }
        if ($doc->claim_coding_hash !== $this->claimCodingHash($claim)) {
            throw new \Exception('Koding klaim berubah setelah lembar di-TTD. Perbarui lembar klaim & minta TTD ulang.', 422);
        }
    }

    // =========================================================================
    // INA-CBGs GROUPING
    // =========================================================================

    /**
     * Grouping INA-CBGs via WS E-Klaim resmi (BUKAN grouper mock).
     *
     * Satu tombol "Jalankan Grouping" di UI = rangkaian WS lengkap:
     *   new_claim → set_claim_data → grouper
     * sehingga kode CBG + tarif yang tersimpan adalah hasil aplikasi E-Klaim,
     * bukan angka placeholder. set_claim_data idempoten di sisi E-Klaim
     * (mengirim ulang payload terkini), jadi aman dipanggil saat grouping ulang.
     */
    public function runInaCbgsGrouping(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'])) {
            throw new \Exception('Klaim sudah disubmit, tidak bisa grouping ulang.', 422);
        }

        if (! $claim->diagnosis_utama) {
            throw new \Exception('Diagnosis utama belum ada.', 422);
        }

        // 1) Registrasi klaim ke E-Klaim (idempoten: klaim yang sudah ada akan
        //    dijawab "sudah terdaftar" oleh ws.php — tetap lanjut).
        $this->eklaimNewClaim($claimId);

        // 2) Kirim/refresh data klaim (diagnosa, prosedur, tarif RS, dll).
        $this->eklaimSetData($claimId);

        // 3) Jalankan grouper resmi → simpan CBG + tarif balik ke klaim.
        return $this->eklaimGrouper($claimId);
    }

    // =========================================================================
    // E-KLAIM INA-CBG (Web Service ws.php)
    // =========================================================================
    //
    // Alur: eklaimNewClaim -> eklaimSetData -> eklaimGrouper -> eklaimFinal,
    // dengan eklaimStatus / eklaimReedit untuk sinkron & koreksi. Semua call
    // didelegasikan ke InaCbgsService (WS client) dan tercatat di
    // inacbgs_grouping_logs. Hasil grouping disimpan balik ke kolom klaim.

    /** new_claim — registrasi klaim (SEP/RM) ke E-Klaim. */
    public function eklaimNewClaim(string $claimId): array
    {
        $claim = $this->guardEklaimReady($claimId);

        $res = $this->eklaim->newClaim([
            'nomor_sep' => $claim->no_sep,
            'nomor_rm'  => $claim->visit?->patient?->no_rm ?? $claim->patient_nik,
            'nomor_kartu' => $claim->visit?->patient?->bpjs_number,
            'nama_pasien' => $claim->visit?->patient?->name,
        ], $claim->id, $claim->visit_id);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_NEW',
            $claim->status, $claim->status, $res['message'] ?? ('code ' . ($res['code'] ?? '-')));

        return $res;
    }

    /** set_claim_data — kirim payload klaim lengkap (builder Fase 4). */
    public function eklaimSetData(string $claimId): array
    {
        $claim = $this->guardEklaimReady($claimId);

        $payload = $this->buildEklaimPayload($claim);
        $res = $this->eklaim->setClaimData($payload, $claim->id, $claim->visit_id);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_SET_DATA',
            $claim->status, $claim->status, $res['message'] ?? ('code ' . ($res['code'] ?? '-')));

        return $res;
    }

    /** grouper — jalankan grouper E-Klaim, simpan CBG + tarif balik ke klaim. */
    public function eklaimGrouper(string $claimId, int $stage = 1): BpjsClaim
    {
        $claim = $this->guardEklaimReady($claimId);

        $res = $this->eklaim->grouper($claim->no_sep, $stage, $claim->id, $claim->visit_id);

        // Bentuk respons grouper E-Klaim (umum): data.cbg.code / .tariff / .description.
        $cbg = $res['data']['cbg'] ?? $res['raw']['response']['cbg'] ?? [];
        $code  = $cbg['code']   ?? $cbg['kode']  ?? null;
        $tarif = $cbg['tariff'] ?? $cbg['tarif'] ?? null;

        if ($res['success'] && $code) {
            $claim->update([
                'inacbgs_kode'  => $code,
                'inacbgs_tarif' => $tarif,
            ]);
        }

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_GROUPER',
            $claim->status, $claim->status,
            $code ? "CBG: {$code} — Tarif: " . number_format((float) $tarif) : ($res['message'] ?? 'Grouper gagal'));

        if (! $res['success']) {
            throw new \Exception('Grouper E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /** claim_final — finalisasi (irreversible). Tandai klaim SUBMITTED. */
    public function eklaimFinal(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Idempotensi: klaim yang sudah final/selesai jangan difinalisasi ulang
        // (mencegah re-stamp submitted_at & panggilan WS claim_final berulang).
        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah difinalisasi. Gunakan Re-edit bila perlu koreksi.', 422);
        }

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Grouping E-Klaim belum dilakukan sebelum finalisasi.', 422);
        }

        // Dokumen pendukung wajib sahih: lembar klaim sudah di-TTD dokter & koding
        // belum berubah sejak TTD (diagnosa grouping = dokumen pendukung).
        $this->assertClaimResumeReady($claim);

        $res = $this->eklaim->claimFinal($claim->no_sep, $claim->id, $claim->visit_id);

        if (! $res['success']) {
            $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_FINAL_GAGAL',
                $claim->status, $claim->status, $res['message'] ?? 'Finalisasi gagal');
            throw new \Exception('Finalisasi E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        $user = auth('api')->user();
        $old  = $claim->status;
        $claim->update([
            'status'        => 'SUBMITTED',
            'bpjs_status'   => 'FINAL',
            'bpjs_response' => $res['raw'] ?? null,
            'submitted_at'  => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'EKLAIM_FINAL', $old, 'SUBMITTED',
            'Klaim difinalisasi di E-Klaim. ' . ($res['message'] ?? ''));
        $this->log($user?->id, 'EKLAIM_FINAL', BpjsClaim::class, $claim->id, "SEP {$claim->no_sep}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /** get_claim_status — status klaim di E-Klaim (tidak mengubah data lokal). */
    public function eklaimStatus(string $claimId): array
    {
        $claim = BpjsClaim::findOrFail($claimId);

        return $this->eklaim->getClaimStatus($claim->no_sep, $claim->id, $claim->visit_id);
    }

    /** reedit_claim — buka kembali klaim final untuk koreksi. */
    public function eklaimReedit(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        $res = $this->eklaim->reeditClaim($claim->no_sep, $claim->id, $claim->visit_id);

        if (! $res['success']) {
            throw new \Exception('Re-edit E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        // Kembalikan ke DRAFT agar bisa dikoreksi & diproses ulang.
        $old = $claim->status;
        $claim->update(['status' => 'DRAFT', 'bpjs_status' => null, 'submitted_at' => null]);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_REEDIT',
            $old, 'DRAFT', 'Klaim dibuka kembali dari E-Klaim untuk koreksi.');

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Fase 4 — Builder payload set_claim_data dari relasi Visit Arumed.
     *
     * ⚠️ PERLU VERIFIKASI ke Manual WS E-Klaim build 5.10.x: nama field,
     * format tanggal, kode gender, separator diagnosa/prosedur, dan apakah
     * tarif dikirim per-komponen atau total. Struktur di bawah memakai bentuk
     * umum WS E-Klaim; sesuaikan setelah Manual WS tersedia.
     */
    public function buildEklaimPayload(BpjsClaim $claim): array
    {
        $visit   = $claim->visit;
        $patient = $visit?->patient;
        $exam    = $visit?->doctorExamination;
        $invoice = $visit?->billingInvoice;

        $isRanap = ($visit?->jenis_pelayanan ?? 'RAJAL') === 'RANAP';

        $tglMasuk = $visit?->admission_at
            ? \Illuminate\Support\Carbon::parse($visit->admission_at)->format('Y-m-d')
            : \Illuminate\Support\Carbon::parse($visit?->visit_date ?? now())->format('Y-m-d');
        $tglPulang = $visit?->discharge_at
            ? \Illuminate\Support\Carbon::parse($visit->discharge_at)->format('Y-m-d')
            : $tglMasuk;

        // Diagnosa: utama + sekunder digabung separator ';' (umum WS E-Klaim).
        $dxSekunder = collect($claim->diagnosis_sekunder ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c)
            ->filter()->values()->all();
        $diagnosa = implode(';', array_filter(array_merge([$claim->diagnosis_utama], $dxSekunder)));

        $prosedur = collect($claim->procedure_codes ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c)
            ->filter()->values()->all();

        return [
            'nomor_sep'      => $claim->no_sep,
            'nomor_kartu'    => $patient?->bpjs_number,
            'nomor_rm'       => $patient?->no_rm ?? $claim->patient_nik,
            'nama_pasien'    => $patient?->name,
            'tgl_lahir'      => $patient?->date_of_birth?->format('Y-m-d'),
            // E-Klaim: 1=laki-laki, 2=perempuan. Patient Arumed: L/P.
            'gender'         => ($patient?->gender === 'P') ? '2' : '1',
            'tgl_masuk'      => $tglMasuk,
            'tgl_pulang'     => $tglPulang,
            'jenis_rawat'    => $isRanap ? '1' : '2', // 1=inap, 2=jalan
            'kelas_rawat'    => (string) ($visit?->kelas_rawat_hak ?? '3'),
            'diagnosa'       => $diagnosa,
            'procedure'      => implode(';', $prosedur),
            'tarif_rs'       => (float) ($invoice?->total ?? 0),
            'kode_tarif'     => config('eklaim.kode_tarif', 'CS'),
            'cara_pulang'    => $this->mapCaraPulang($visit?->discharge_type),
            'dpjp'           => $exam?->doctor?->name,
        ];
    }

    /** Map discharge_type Arumed -> kode cara pulang E-Klaim. */
    private function mapCaraPulang(?string $type): string
    {
        if ($type === null) {
            return '1'; // default: atas persetujuan dokter (hindari null array offset)
        }

        return [
            'PULANG_SEHAT' => '1', // atas persetujuan dokter
            'RUJUK'        => '2',
            'APS'          => '3', // atas permintaan sendiri
            'MENINGGAL'    => '4',
        ][$type] ?? '1';
    }

    /** Guard umum sebelum call WS: klaim ada, SEP & diagnosis utama terisi. */
    private function guardEklaimReady(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::with(['visit.patient', 'visit.doctorExamination.doctor', 'visit.billingInvoice'])
            ->findOrFail($claimId);

        if (empty($claim->no_sep)) {
            throw new \Exception('Nomor SEP belum ada pada klaim.', 422);
        }
        if (empty($claim->diagnosis_utama)) {
            throw new \Exception('Diagnosis utama belum diisi.', 422);
        }

        return $claim;
    }

    // =========================================================================
    // LUPIS — format data utilisasi untuk VClaim
    // =========================================================================

    public function generateLupis(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::with(['visit.patient', 'visit.billingInvoice.items'])->findOrFail($claimId);

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Jalankan INA-CBGs grouping terlebih dahulu sebelum generate LUPIS.', 422);
        }

        $visit   = $claim->visit;
        $patient = $visit->patient;

        // Conditional rawat inap: jnsPelayanan '1' + field inap (tglMasuk/los/
        // kelasRawat/caraKeluar). Kelas rawat = kelas HAK (kelas_rawat_hak).
        $isRanap = ($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP';
        $tglMasuk = $visit->admission_at
            ? \Illuminate\Support\Carbon::parse($visit->admission_at)->format('Y-m-d')
            : null;
        $tglPulang = $visit->discharge_at
            ? \Illuminate\Support\Carbon::parse($visit->discharge_at)->format('Y-m-d')
            : $visit->updated_at?->format('Y-m-d'); // RAJAL: fallback ke updated_at (perilaku lama)

        // LOS = malam admission..discharge, minimum 1 (masuk dihitung, pulang tidak).
        $los = null;
        if ($isRanap && $visit->admission_at && $visit->discharge_at) {
            $los = max(1, \Illuminate\Support\Carbon::parse($visit->admission_at)->startOfDay()
                ->diffInDays(\Illuminate\Support\Carbon::parse($visit->discharge_at)->startOfDay()));
        }

        // Cara keluar dari discharge_type (PULANG_SEHAT|RUJUK|APS|MENINGGAL).
        $caraKeluarMap = [
            'PULANG_SEHAT' => '1', // atas persetujuan dokter
            'RUJUK'        => '2',
            'APS'          => '3', // atas permintaan sendiri
            'MENINGGAL'    => '4',
        ];

        // LUPIS format (struktur sesuai spesifikasi BPJS)
        $lupisData = [
            'noSep'            => $claim->no_sep,
            'nik'              => $claim->patient_nik,
            'nama'             => $patient->name,
            'tglLahir'         => $patient->date_of_birth?->format('Y-m-d'),
            'jnsPelayanan'     => $isRanap ? '1' : '2', // 1=rawat inap, 2=rawat jalan
            'diagnosaUtama'    => $claim->diagnosis_utama,
            'diagnosaSekunder' => $claim->diagnosis_sekunder ?? [],
            'procedureCodes'   => $claim->procedure_codes ?? [],
            'cbgCode'          => $claim->inacbgs_kode,
            'cbgTarif'         => $claim->inacbgs_tarif,
            'totalBiaya'       => $visit->billingInvoice?->total ?? 0,
            'tglPulang'        => $tglPulang,
        ];

        // Field khusus rawat inap.
        if ($isRanap) {
            $lupisData['tglMasuk']   = $tglMasuk;
            $lupisData['los']        = $los;
            $lupisData['kelasRawat'] = (string) ($visit->kelas_rawat_hak ?? '3');
            $lupisData['caraKeluar'] = $visit->discharge_type ? ($caraKeluarMap[$visit->discharge_type] ?? '1') : '1';
        }

        $user = auth('api')->user();

        $claim->update(['lupis_data' => $lupisData]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'LUPIS_GENERATED', $claim->status, $claim->status);
        $this->log($user?->id, 'GENERATE_LUPIS', BpjsClaim::class, $claimId);

        return $claim->fresh();
    }

    // =========================================================================
    // WORKFLOW STATUS
    // =========================================================================

    public function setReview(string $claimId): BpjsClaim
    {
        return $this->transitionStatus($claimId, 'DRAFT', 'REVIEW', 'REVIEW');
    }

    public function setVerifikasi(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'REVIEW') {
            throw new \Exception('Klaim harus dalam status REVIEW untuk diverifikasi.', 422);
        }

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Grouping INA-CBGs belum dilakukan.', 422);
        }

        if (! $claim->lupis_data) {
            throw new \Exception('Data LUPIS belum di-generate.', 422);
        }

        return $this->transitionStatus($claimId, 'REVIEW', 'VERIFIED', 'VERIFIKASI');
    }

    /**
     * Tolak/kembalikan klaim oleh VERIFIKATOR INTERNAL (sebelum submit ke BPJS).
     * Status → DIKEMBALIKAN. Bisa di-resubmit (perbaiki lalu ajukan ulang).
     */
    public function setReject(string $claimId, string $reason): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim ke BPJS, tidak bisa dikembalikan internal. Gunakan respons BPJS.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;

        $claim->update([
            'status'           => 'DIKEMBALIKAN',
            'rejection_reason' => $reason,
            'rejected_at'      => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'RETURN_INTERNAL', $oldStatus, 'DIKEMBALIKAN', $reason);
        $this->log($user?->id, 'RETURN_CLAIM', BpjsClaim::class, $claimId, $reason);

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Tandai klaim DITOLAK oleh BPJS (setelah submit). Dipanggil saat memproses
     * respons VClaim / monitoring. Status → DITOLAK_BPJS.
     */
    public function markBpjsRejected(string $claimId, string $reason, ?array $bpjsResponse = null): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'SUBMITTED') {
            throw new \Exception('Hanya klaim berstatus SUBMITTED yang bisa ditandai ditolak BPJS.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;

        $claim->update([
            'status'           => 'DITOLAK_BPJS',
            'bpjs_status'      => 'DITOLAK',
            'bpjs_response'    => $bpjsResponse ?? $claim->bpjs_response,
            'rejection_reason' => $reason,
            'rejected_at'      => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'REJECT_BPJS', $oldStatus, 'DITOLAK_BPJS', $reason);
        $this->log($user?->id, 'REJECT_BPJS_CLAIM', BpjsClaim::class, $claimId, $reason);

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Ajukan ulang klaim yang DIKEMBALIKAN (internal) atau DITOLAK_BPJS.
     * Mengembalikan ke DRAFT untuk diperbaiki & diproses ulang
     * (grouping → LUPIS → verifikasi → submit). resubmission_count bertambah.
     * Pola mengikuti AsuransiService::resubmitKlaim.
     */
    public function resubmitClaim(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Dukung status baru + 'DITOLAK' lama (backward-compat).
        if (! in_array($claim->status, ['DIKEMBALIKAN', 'DITOLAK_BPJS', 'DITOLAK'], true)) {
            throw new \Exception('Hanya klaim yang dikembalikan/ditolak yang bisa diajukan ulang.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;
        $newCount  = ($claim->resubmission_count ?? 0) + 1;

        $claim->update([
            'status'             => 'DRAFT',
            'resubmission_count' => $newCount,
            // Bersihkan jejak penolakan agar siklus baru bersih.
            'rejection_reason'   => null,
            'rejected_at'        => null,
            'bpjs_status'        => null,
            'bpjs_response'      => null,
            // Reset hasil grouping/LUPIS — wajib di-run ulang setelah perbaikan data.
            'inacbgs_kode'       => null,
            'inacbgs_tarif'      => null,
            'lupis_data'         => null,
            'submitted_at'       => null,
        ]);

        $this->addAuditLog(
            $claim->id,
            $user?->employee_id,
            'RESUBMIT',
            $oldStatus,
            'DRAFT',
            "Klaim diajukan ulang (pengajuan ke-{$newCount}). Perbaiki data lalu jalankan grouping → LUPIS → verifikasi."
        );
        $this->log($user?->id, 'RESUBMIT_CLAIM', BpjsClaim::class, $claimId, "resubmission #{$newCount}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    // =========================================================================
    // SUBMIT KE VCLAIM
    // =========================================================================

    /**
     * Kirim klaim final ke E-Klaim INA-CBG (BUKAN mock VClaim).
     *
     * Tombol "Kirim ke BPJS" di UI = claim_final WS E-Klaim (irreversible,
     * hanya bisa dibuka via Re-edit). Delegasi penuh ke eklaimFinal() yang
     * memanggil ws.php, menandai klaim SUBMITTED + bpjs_status FINAL, dan
     * mencatat audit. Guard status VERIFIED dipertahankan di sini.
     */
    public function submitClaim(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'VERIFIED') {
            throw new \Exception('Klaim harus dalam status VERIFIED sebelum dikirim.', 422);
        }

        // eklaimFinal: cek inacbgs_kode, call claim_final WS, set SUBMITTED + audit.
        return $this->eklaimFinal($claimId);
    }

    // =========================================================================
    // LAMPIRAN BERKAS KLAIM (upload PDF/gambar: resume RJ, hasil penunjang, dll)
    // =========================================================================

    /** Daftar lampiran klaim (terbaru dulu). */
    public function getAttachments(string $claimId): array
    {
        BpjsClaim::findOrFail($claimId); // 404 bila klaim tak ada

        return \App\Models\ClaimAttachment::with('uploadedBy:id,name')
            ->where('bpjs_claim_id', $claimId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id'        => $d->id,
                'category'  => $d->category,
                'title'     => $d->title,
                'file_name' => $d->file_name,
                'file_url'  => $d->file_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($d->file_path) : null,
                'mime_type' => $d->mime_type,
                'file_size' => $d->file_size,
                'by'        => $d->uploadedBy?->name,
                'at'        => $d->created_at?->toIso8601String(),
            ])->all();
    }

    /** Upload lampiran (PDF/gambar) ke klaim. */
    public function uploadAttachment(string $claimId, array $data, $file): \App\Models\ClaimAttachment
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Klaim yang sudah final/selesai tidak boleh ditambah lampiran.
        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim/selesai — lampiran tidak bisa ditambah.', 422);
        }

        $category = in_array($data['category'] ?? null, \App\Models\ClaimAttachment::CATEGORIES, true)
            ? $data['category']
            : 'LAINNYA';

        $path = $file->store('claim-attachments', 'public');

        $att = \App\Models\ClaimAttachment::create([
            'bpjs_claim_id'  => $claim->id,
            'category'       => $category,
            'title'          => $data['title'] ?? $file->getClientOriginalName(),
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'mime_type'      => $file->getClientMimeType(),
            'file_size'      => $file->getSize(),
            'uploaded_by_id' => auth('api')->user()?->employee_id,
        ]);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'UPLOAD_LAMPIRAN',
            $claim->status, $claim->status, "Lampiran {$category}: {$att->file_name}");

        return $att;
    }

    /** Hapus lampiran (beserta file fisik). */
    public function deleteAttachment(string $claimId, string $attachmentId): void
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim/selesai — lampiran tidak bisa dihapus.', 422);
        }

        $att = \App\Models\ClaimAttachment::where('bpjs_claim_id', $claimId)->findOrFail($attachmentId);

        if ($att->file_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($att->file_path);
        }

        $name = $att->file_name;
        $att->delete();

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'HAPUS_LAMPIRAN',
            $claim->status, $claim->status, "Hapus lampiran: {$name}");
    }

    // =========================================================================
    // MONITORING & LOGS
    // =========================================================================

    public function getAuditLog(string $claimId): \Illuminate\Database\Eloquent\Collection
    {
        BpjsClaim::findOrFail($claimId); // 404 if not found

        return ClaimAuditLog::with('performedBy')
            ->where('bpjs_claim_id', $claimId)
            ->orderBy('created_at')
            ->get();
    }

    public function getGroupingLog(string $claimId): \Illuminate\Database\Eloquent\Collection
    {
        return InacbgsGroupingLog::where('bpjs_claim_id', $claimId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getVclaimLog(array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = DB::table('bpjs_vclaim_logs');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['status'])) {
            $query->where('http_status', $filters['status']);
        }

        return $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function icareMonitoring(): array
    {
        $this->assertIcareEnabled();

        // TODO: Call IntegrasiService::getIcareMonitoring()
        return [
            'message' => 'iCare monitoring belum terhubung. Aktifkan integrasi iCare terlebih dahulu.',
            'status'  => 'NOT_CONFIGURED',
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function transitionStatus(
        string $claimId,
        string $fromStatus,
        string $toStatus,
        string $action
    ): BpjsClaim {
        $claim = BpjsClaim::findOrFail($claimId);
        $user  = auth('api')->user();

        if ($claim->status !== $fromStatus) {
            throw new \Exception("Klaim harus dalam status {$fromStatus} untuk tindakan ini.", 422);
        }

        $claim->update(['status' => $toStatus]);

        $this->addAuditLog($claim->id, $user?->employee_id, $action, $fromStatus, $toStatus);
        $this->log($user?->id, $action . '_CLAIM', BpjsClaim::class, $claimId, "{$fromStatus} → {$toStatus}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    private function addAuditLog(
        string $claimId,
        ?string $employeeId,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $notes = null
    ): void {
        ClaimAuditLog::create([
            'bpjs_claim_id'   => $claimId,
            'performed_by_id' => $employeeId,
            'action'          => $action,
            'old_status'      => $oldStatus,
            'new_status'      => $newStatus,
            'notes'           => $notes,
        ]);
    }

    private function assertIcareEnabled(): void
    {
        $config = IntegrationConfig::where('system_name', 'ICARE')->first();

        if (! $config?->is_enabled) {
            throw new \Exception('Integrasi iCare belum diaktifkan.', 503);
        }
    }

    private function log(
        ?string $userId,
        string $action,
        ?string $model = null,
        ?string $modelId = null,
        ?string $description = null
    ): void {
        SystemLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $description,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
