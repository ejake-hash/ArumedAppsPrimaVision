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
        $query = BpjsClaim::with(['visit.patient', 'visit.insurer']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
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
            'visit.doctorExamination',
            'visit.billingInvoice',
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

        // LUPIS format (struktur sesuai spesifikasi BPJS)
        $lupisData = [
            'noSep'            => $claim->no_sep,
            'nik'              => $claim->patient_nik,
            'nama'             => $patient->name,
            'tglLahir'         => $patient->date_of_birth?->format('Y-m-d'),
            'jnsPelayanan'     => '2', // 2 = rawat jalan
            'diagnosaUtama'    => $claim->diagnosis_utama,
            'diagnosaSekunder' => $claim->diagnosis_sekunder ?? [],
            'procedureCodes'   => $claim->procedure_codes ?? [],
            'cbgCode'          => $claim->inacbgs_kode,
            'cbgTarif'         => $claim->inacbgs_tarif,
            'totalBiaya'       => $visit->billingInvoice?->total ?? 0,
            'tglPulang'        => $visit->updated_at?->format('Y-m-d'),
        ];

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

    public function setReject(string $claimId, string $reason): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        $user     = auth('api')->user();
        $oldStatus = $claim->status;

        $claim->update(['status' => 'DITOLAK']);

        $this->addAuditLog($claim->id, $user->employee_id, 'REJECT', $oldStatus, 'DITOLAK', $reason);
        $this->log($user->id, 'REJECT_CLAIM', BpjsClaim::class, $claimId, $reason);

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
