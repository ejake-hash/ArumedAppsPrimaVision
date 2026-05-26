<?php

namespace App\Http\Controllers;

use App\Services\IntegrasiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrasiController extends Controller
{
    public function __construct(private readonly IntegrasiService $service) {}

    // =========================================================================
    // STATUS & CONFIG
    // =========================================================================

    /**
     * GET /integrasi/status
     * Status semua 6 sistem integrasi: enabled, last_test, has_credentials.
     */
    public function statusSemua(): JsonResponse
    {
        return $this->ok($this->service->getStatusSemua());
    }

    /**
     * POST /integrasi/test/{system}
     * system: VCLAIM | ANTREAN | ICARE | LUPIS | INACBGS | SATUSEHAT
     * Test koneksi + update last_test_status di integration_configs.
     */
    public function testKoneksi(string $system): JsonResponse
    {
        try {
            $result = $this->service->testKoneksi($system);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, "Test koneksi {$system} selesai");
    }

    /** GET /integrasi/config */
    public function indexConfig(): JsonResponse
    {
        return $this->ok($this->service->indexConfig());
    }

    /**
     * PUT /integrasi/config/{id}
     * Update credentials, base_url, is_enabled.
     * Body: { is_enabled, base_url, credentials: {...}, configuration: {...}, notes }
     */
    public function updateConfig(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled'    => 'nullable|boolean',
            'base_url'      => 'nullable|string|max:500',
            'credentials'   => 'nullable|array',
            'configuration' => 'nullable|array',
            'notes'         => 'nullable|string|max:500',
        ]);

        return $this->ok($this->service->updateConfig($id, $validated), 'Konfigurasi integrasi diperbarui');
    }

    // =========================================================================
    // BPJS — VCLAIM LOGS
    // =========================================================================

    /**
     * GET /integrasi/bpjs/vclaim-log
     * Query: action, is_success, tanggal, per_page
     */
    public function vclaimpLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getVclaimLog(
            $request->only(['action', 'is_success', 'tanggal', 'per_page'])
        ));
    }

    /** GET /integrasi/bpjs/vclaim-log/{id} */
    public function showVclaimpLog(string $id): JsonResponse
    {
        return $this->ok($this->service->showVclaimLog($id));
    }

    // =========================================================================
    // BPJS — ANTREAN LOGS
    // =========================================================================

    /**
     * GET /integrasi/bpjs/antrean-log
     * Query: action, per_page
     */
    public function antreanLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getAntreanLog(
            $request->only(['action', 'per_page'])
        ));
    }

    // =========================================================================
    // BPJS — ICARE LOGS
    // =========================================================================

    /**
     * GET /integrasi/bpjs/icare-log
     * Query: action, per_page
     */
    public function icareLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getIcareLog(
            $request->only(['action', 'per_page'])
        ));
    }

    // =========================================================================
    // INA-CBGs LOGS
    // =========================================================================

    /**
     * GET /integrasi/bpjs/inacbgs-log
     * Query: status, per_page
     */
    public function inacbgsLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getInacbgsLog(
            $request->only(['status', 'per_page'])
        ));
    }

    // =========================================================================
    // RUJUKAN BPJS
    // =========================================================================

    /** GET /integrasi/bpjs/rujukan-masuk */
    public function indexRujukanMasuk(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexRujukanMasuk(
            $request->only(['status', 'per_page'])
        ));
    }

    /** GET /integrasi/bpjs/rujukan-masuk/{id} */
    public function showRujukanMasuk(string $id): JsonResponse
    {
        return $this->ok($this->service->showRujukanMasuk($id));
    }

    /** GET /integrasi/bpjs/rujukan-keluar */
    public function indexRujukanKeluar(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexRujukanKeluar(
            $request->only(['status', 'per_page'])
        ));
    }

    /** GET /integrasi/bpjs/rujukan-keluar/{id} */
    public function showRujukanKeluar(string $id): JsonResponse
    {
        return $this->ok($this->service->showRujukanKeluar($id));
    }

    // =========================================================================
    // SURAT KONTROL BPJS
    // =========================================================================

    /** GET /integrasi/bpjs/surat-kontrol */
    public function indexSuratKontrol(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexSuratKontrol(
            $request->only(['status', 'per_page'])
        ));
    }

    /** GET /integrasi/bpjs/surat-kontrol/{id} */
    public function showSuratKontrol(string $id): JsonResponse
    {
        return $this->ok($this->service->showSuratKontrol($id));
    }

    /**
     * POST /integrasi/bpjs/surat-kontrol/{id}/submit
     * Submit Surat Kontrol ke VClaim → dapat nomor resmi dari BPJS.
     */
    public function submitSuratKontrol(string $id): JsonResponse
    {
        try {
            $letter = $this->service->submitSuratKontrol($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($letter, 'Surat Kontrol disubmit ke VClaim');
    }

    // =========================================================================
    // SATU SEHAT
    // =========================================================================

    /**
     * GET /integrasi/satusehat/sync-log
     * Query: status (SUCCESS|PARTIAL|FAILED|RUNNING), per_page
     */
    public function satusehatSyncLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getSatusehatSyncLog(
            $request->only(['status', 'per_page'])
        ));
    }

    /** GET /integrasi/satusehat/sync-log/{id} */
    public function showSatusehatSyncLog(string $id): JsonResponse
    {
        return $this->ok($this->service->showSatusehatSyncLog($id));
    }

    /**
     * GET /integrasi/satusehat/resource-log
     * Query: status, resource_type (Encounter|Condition|...), per_page
     */
    public function satusehatResourceLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getSatusehatResourceLog(
            $request->only(['status', 'resource_type', 'per_page'])
        ));
    }

    /**
     * POST /integrasi/satusehat/sync-manual
     * Trigger manual batch sync Satu Sehat (semua kunjungan SELESAI hari ini).
     */
    public function satusehatSyncManual(): JsonResponse
    {
        try {
            $syncLog = $this->service->satusehatSyncManual();
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($syncLog, 'Manual sync Satu Sehat dimulai');
    }

    /**
     * POST /integrasi/satusehat/retry/{logId}
     * Retry failed sync log.
     */
    public function satusehatRetry(string $logId): JsonResponse
    {
        try {
            $syncLog = $this->service->satusehatRetry($logId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($syncLog, 'Retry sync Satu Sehat selesai');
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
