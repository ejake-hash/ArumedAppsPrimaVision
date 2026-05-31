<?php

namespace App\Services;

use App\Models\BpjsClaim;
use App\Models\ClaimAuditLog;
use App\Models\InacbgsGroupingLog;
use App\Models\IntegrationConfig;
use App\Models\SystemLog;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KlaimService
{
    // INA-CBGs grouper version — update annually
    private const GROUPER_VERSION = '5.2';

    public function __construct(private readonly Request $request) {}

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
                $user->employee_id,
                'PREPARE',
                $oldStatus,
                $claim->status,
                'Data klaim disiapkan dari data kunjungan'
            );

            return $claim;
        });

        $this->log($user->id, 'PREPARE_CLAIM', BpjsClaim::class, $claim->id, "SEP {$visit->no_sep}");

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

        return $claim->fresh(['auditLogs.performedBy']);
    }

    // =========================================================================
    // INA-CBGs GROUPING
    // =========================================================================

    /**
     * Run INA-CBGs grouper on claim data → get CBG code + tariff + severity.
     * Placeholder: actual engine is JAR-based or API-based (configured per year).
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

        $config    = $this->getInaCbgsConfig();
        $inputData = [
            'diagnosis_utama'    => $claim->diagnosis_utama,
            'diagnosis_sekunder' => $claim->diagnosis_sekunder ?? [],
            'procedure_codes'    => $claim->procedure_codes ?? [],
        ];

        // Run grouper (placeholder — integrasikan JAR/API saat engine tersedia)
        $result = $this->callGrouper($inputData, $config);

        return DB::transaction(function () use ($claim, $inputData, $result, $config) {
            $user = auth('api')->user();

            // Simpan grouping log
            InacbgsGroupingLog::create([
                'visit_id'        => $claim->visit_id,
                'bpjs_claim_id'   => $claim->id,
                'grouper_version' => $config['version'] ?? self::GROUPER_VERSION,
                'input_diagnosis' => [
                    'utama'    => $inputData['diagnosis_utama'],
                    'sekunder' => $inputData['diagnosis_sekunder'],
                ],
                'input_tindakan'  => array_map(fn ($c) => ['code' => $c], $inputData['procedure_codes']),
                'cbg_code'        => $result['cbg_code'],
                'cbg_tarif'       => $result['cbg_tarif'],
                'severity_level'  => $result['severity_level'],
                'engine_type'     => $config['engine_type'] ?? 'JAR',
                'status'          => $result['success'] ? 'SUCCESS' : 'FAILED',
                'error_message'   => $result['error'] ?? null,
            ]);

            if (! $result['success']) {
                throw new \Exception('Grouper gagal: ' . ($result['error'] ?? 'Unknown error'), 500);
            }

            $oldStatus = $claim->status;

            $claim->update([
                'inacbgs_kode' => $result['cbg_code'],
                'inacbgs_tarif' => $result['cbg_tarif'],
            ]);

            $this->addAuditLog(
                $claim->id,
                $user->employee_id,
                'GROUPING',
                $oldStatus,
                $claim->status,
                "CBG: {$result['cbg_code']} — Tarif: " . number_format($result['cbg_tarif'])
            );

            return $claim;
        });
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
            $lupisData['caraKeluar'] = $caraKeluarMap[$visit->discharge_type] ?? '1';
        }

        $user = auth('api')->user();

        $claim->update(['lupis_data' => $lupisData]);

        $this->addAuditLog($claim->id, $user->employee_id, 'LUPIS_GENERATED', $claim->status, $claim->status);
        $this->log($user->id, 'GENERATE_LUPIS', BpjsClaim::class, $claimId);

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

        $this->addAuditLog($claim->id, $user->employee_id, 'RETURN_INTERNAL', $oldStatus, 'DIKEMBALIKAN', $reason);
        $this->log($user->id, 'RETURN_CLAIM', BpjsClaim::class, $claimId, $reason);

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
     * Submit klaim ke VClaim API.
     * Guard: harus VERIFIED + VClaim enabled.
     * Placeholder — implementasi actual VClaim call di IntegrasiService.
     */
    public function submitClaim(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'VERIFIED') {
            throw new \Exception('Klaim harus dalam status VERIFIED sebelum disubmit.', 422);
        }

        $this->assertVclaimEnabled();

        $user = auth('api')->user();

        // TODO: Panggil IntegrasiService::submitVClaimKlaim($claim)
        // Saat ini → placeholder response
        $mockResponse = [
            'noSep'    => $claim->no_sep,
            'status'   => 'submitted',
            'timestamp' => now()->toIso8601String(),
        ];

        return DB::transaction(function () use ($claim, $user, $mockResponse) {
            $oldStatus = $claim->status;

            $claim->update([
                'status'        => 'SUBMITTED',
                'bpjs_status'   => 'PENDING',
                'bpjs_response' => $mockResponse,
                'submitted_at'  => now(),
            ]);

            $this->addAuditLog(
                $claim->id,
                $user->employee_id,
                'SUBMIT',
                $oldStatus,
                'SUBMITTED',
                'Disubmit ke VClaim'
            );

            $this->log($user->id, 'SUBMIT_CLAIM', BpjsClaim::class, $claim->id, "SEP {$claim->no_sep}");

            return $claim;
        });
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

    /**
     * Mock grouper — replace with actual JAR/API call when engine is available.
     * Real implementation: spawn Java process or call external grouper API.
     */
    private function callGrouper(array $input, array $config): array
    {
        // Placeholder response — INA-CBGs engine TBD
        // In production: integrate JAR via proc_open() or API endpoint
        return [
            'success'        => true,
            'cbg_code'       => 'N-1-13-I-0-0', // placeholder CBG mata
            'cbg_tarif'      => 850000,
            'severity_level' => '1',
            'error'          => null,
        ];
    }

    private function getInaCbgsConfig(): array
    {
        $config = IntegrationConfig::where('system_name', 'INACBGS')->first();

        return [
            'version'     => self::GROUPER_VERSION,
            'engine_type' => $config?->configuration['engine_type'] ?? 'JAR',
            'is_enabled'  => $config?->is_enabled ?? false,
        ];
    }

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

        $this->addAuditLog($claim->id, $user->employee_id, $action, $fromStatus, $toStatus);
        $this->log($user->id, $action . '_CLAIM', BpjsClaim::class, $claimId, "{$fromStatus} → {$toStatus}");

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

    private function assertVclaimEnabled(): void
    {
        $config = IntegrationConfig::where('system_name', 'VCLAIM')->first();

        if (! $config?->is_enabled) {
            throw new \Exception('Integrasi VClaim belum diaktifkan. Konfigurasi credentials terlebih dahulu.', 503);
        }
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
