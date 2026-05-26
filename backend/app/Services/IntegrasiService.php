<?php

namespace App\Services;

use App\Models\BpjsControlLetter;
use App\Models\BpjsReferralIn;
use App\Models\BpjsReferralOut;
use App\Models\BpjsVClaimLog;
use App\Models\InacbgsGroupingLog;
use App\Models\IntegrationConfig;
use App\Models\SatusehatResourceLog;
use App\Models\SatusehatSyncLog;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class IntegrasiService
{
    public function __construct(
        private readonly Request             $request,
        private readonly BpjsVClaimService  $vclaim,
        private readonly BpjsAntreanService $antrean,
        private readonly SatusehatService   $satusehat,
        private readonly InaCbgsService     $inacbgs,
    ) {}

    // =========================================================================
    // STATUS ALL SYSTEMS
    // =========================================================================

    public function getStatusSemua(): array
    {
        $configs = IntegrationConfig::orderBy('system_name')->get();

        return $configs->map(fn ($c) => [
            'id'               => $c->id,
            'system_name'      => $c->system_name,
            'is_enabled'       => $c->is_enabled,
            'last_test_status' => $c->last_test_status,
            'last_tested_at'   => $c->last_tested_at?->toIso8601String(),
            'has_credentials'  => ! empty($c->credentials),
            'base_url'         => $c->base_url,
        ])->toArray();
    }

    // =========================================================================
    // TEST CONNECTION
    // =========================================================================

    public function testKoneksi(string $system): array
    {
        $config = IntegrationConfig::where('system_name', strtoupper($system))->firstOrFail();

        try {
            $result = match (strtoupper($system)) {
                'VCLAIM'     => $this->vclaim->testConnection(),
                'ANTREAN'    => $this->antrean->testConnection(),
                'SATUSEHAT'  => $this->satusehat->testConnection(),
                'INACBGS'    => $this->inacbgs->testConnection(),
                'ICARE'      => ['success' => false, 'message' => 'iCare test — placeholder.', 'system' => 'ICARE'],
                'LUPIS'      => ['success' => false, 'message' => 'LUPIS test — placeholder.', 'system' => 'LUPIS'],
                default      => throw new \Exception("Sistem tidak dikenal: {$system}", 422),
            };

            $status = $result['success'] ? 'SUCCESS' : 'FAILED';
        } catch (\Exception $e) {
            $status = 'FAILED';
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        $config->update([
            'last_test_status' => $status,
            'last_tested_at'   => now(),
        ]);

        $this->log(auth('api')->id(), "TEST_KONEKSI_{$system}", null, null, $result['message'] ?? '');

        return array_merge($result, ['status' => $status, 'tested_at' => now()->toIso8601String()]);
    }

    // =========================================================================
    // CONFIG MANAGEMENT
    // =========================================================================

    public function indexConfig(): \Illuminate\Database\Eloquent\Collection
    {
        return IntegrationConfig::orderBy('system_name')->get([
            'id', 'system_name', 'is_enabled', 'base_url', 'last_test_status', 'last_tested_at', 'notes',
        ]);
    }

    public function updateConfig(string $id, array $data): IntegrationConfig
    {
        $config = IntegrationConfig::findOrFail($id);

        $config->update(array_filter([
            'is_enabled'    => $data['is_enabled'] ?? null,
            'base_url'      => $data['base_url'] ?? null,
            'credentials'   => $data['credentials'] ?? null,
            'configuration' => $data['configuration'] ?? null,
            'notes'         => $data['notes'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_INTEGRASI_CONFIG', IntegrationConfig::class, $id, $config->system_name);

        return $config->fresh();
    }

    // =========================================================================
    // VCLAIM LOGS
    // =========================================================================

    public function getVclaimLog(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsVClaimLog::with('visit.patient')
            ->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['is_success'])) {
            $query->where('is_success', (bool) $filters['is_success']);
        }

        if (! empty($filters['tanggal'])) {
            $query->whereDate('created_at', $filters['tanggal']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showVclaimLog(string $id): BpjsVClaimLog
    {
        return BpjsVClaimLog::with('visit.patient')->findOrFail($id);
    }

    // =========================================================================
    // ANTREAN LOGS
    // =========================================================================

    public function getAntreanLog(array $filters = []): LengthAwarePaginator
    {
        $query = DB::table('bpjs_antrean_logs')
            ->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    // =========================================================================
    // ICARE LOGS
    // =========================================================================

    public function getIcareLog(array $filters = []): LengthAwarePaginator
    {
        $query = DB::table('bpjs_icare_logs')->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    // =========================================================================
    // INA-CBGs GROUPING LOGS
    // =========================================================================

    public function getInacbgsLog(array $filters = []): LengthAwarePaginator
    {
        $query = InacbgsGroupingLog::with(['visit.patient', 'bpjsClaim'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    // =========================================================================
    // RUJUKAN BPJS
    // =========================================================================

    public function indexRujukanMasuk(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsReferralIn::with('visit.patient')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showRujukanMasuk(string $id): BpjsReferralIn
    {
        return BpjsReferralIn::with('visit.patient')->findOrFail($id);
    }

    public function indexRujukanKeluar(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsReferralOut::with('visit.patient')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showRujukanKeluar(string $id): BpjsReferralOut
    {
        return BpjsReferralOut::with('visit.patient')->findOrFail($id);
    }

    // =========================================================================
    // SURAT KONTROL BPJS
    // =========================================================================

    public function indexSuratKontrol(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsControlLetter::with('visit.patient')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showSuratKontrol(string $id): BpjsControlLetter
    {
        return BpjsControlLetter::with('visit.patient')->findOrFail($id);
    }

    /**
     * Submit Surat Kontrol ke VClaim → dapat nomor resmi.
     */
    public function submitSuratKontrol(string $id): BpjsControlLetter
    {
        $letter = BpjsControlLetter::with('visit.patient')->findOrFail($id);

        if ($letter->status !== 'DRAFT') {
            throw new \Exception('Surat Kontrol hanya bisa disubmit jika masih DRAFT.', 422);
        }

        $result = $this->vclaim->postSuratKontrol([
            'noSEP'        => $letter->visit->no_sep,
            'tglKontrol'   => $letter->tanggal_rencana_kontrol?->format('Y-m-d'),
            'keterangan'   => 'Surat Kontrol dari Klinik Mata Arunika',
        ], $letter->visit_id);

        $noSuratKontrol = $result['noSuratKontrol'] ?? null;

        $letter->update([
            'status'           => $noSuratKontrol ? 'SUCCESS' : 'FAILED',
            'no_surat_kontrol' => $noSuratKontrol,
            'vclaim_response'  => $result,
        ]);

        $this->log(auth('api')->id(), 'SUBMIT_SURAT_KONTROL', BpjsControlLetter::class, $id);

        return $letter->fresh();
    }

    // =========================================================================
    // SATU SEHAT
    // =========================================================================

    public function getSatusehatSyncLog(array $filters = []): LengthAwarePaginator
    {
        $query = SatusehatSyncLog::orderByDesc('sync_date');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function showSatusehatSyncLog(string $id): SatusehatSyncLog
    {
        return SatusehatSyncLog::findOrFail($id);
    }

    public function getSatusehatResourceLog(array $filters = []): LengthAwarePaginator
    {
        $query = SatusehatResourceLog::with('visit.patient')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['resource_type'])) {
            $query->where('resource_type', $filters['resource_type']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function satusehatSyncManual(): SatusehatSyncLog
    {
        return $this->satusehat->batchSync('MANUAL');
    }

    public function satusehatRetry(string $logId): SatusehatSyncLog
    {
        return $this->satusehat->retry($logId);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

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
