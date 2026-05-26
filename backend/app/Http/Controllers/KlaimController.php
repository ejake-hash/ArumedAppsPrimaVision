<?php

namespace App\Http\Controllers;

use App\Services\KlaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KlaimController extends Controller
{
    public function __construct(private readonly KlaimService $service) {}

    // =========================================================================
    // LIST & DETAIL
    // =========================================================================

    /**
     * GET /klaim
     * Query: status, search, tanggal_from, tanggal_to, per_page
     */
    public function index(Request $request): JsonResponse
    {
        return $this->ok($this->service->getClaimList(
            $request->only(['status', 'search', 'tanggal_from', 'tanggal_to', 'per_page'])
        ));
    }

    /** GET /klaim/{id} */
    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->getClaimById($id));
    }

    // =========================================================================
    // PREPARE
    // =========================================================================

    /**
     * POST /klaim/grouping (sebenarnya prepare — buat klaim dari kunjungan)
     * Body: { visit_id }
     *
     * Ambil data dari:
     *   - visit.no_sep
     *   - doctorExamination.diagnosis_utama / sekunder / tindakan_codes
     *   - visit.patient.nik
     */
    public function runGrouping(Request $request): JsonResponse
    {
        $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
        ]);

        try {
            $claim = $this->service->prepareClaimData($request->visit_id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Data klaim disiapkan dari kunjungan', 201);
    }

    // =========================================================================
    // INA-CBGs GROUPING
    // =========================================================================

    /**
     * POST /klaim/{id}/grouping
     * Jalankan INA-CBGs grouper → dapatkan CBG code + tarif + severity.
     * Placeholder saat ini; engine JAR/API diintegrasikan terpisah.
     */
    public function runInaCbgsGrouping(string $id): JsonResponse
    {
        try {
            $claim = $this->service->runInaCbgsGrouping($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, "Grouping selesai. CBG: {$claim->inacbgs_kode}");
    }

    /** GET /klaim/grouping-log/{klaimId} */
    public function groupingLog(string $klaimId): JsonResponse
    {
        return $this->ok($this->service->getGroupingLog($klaimId));
    }

    // =========================================================================
    // WORKFLOW STATUS
    // =========================================================================

    /** PUT /klaim/{id}/review → DRAFT → REVIEW */
    public function setReview(string $id): JsonResponse
    {
        try {
            $claim = $this->service->setReview($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Klaim dalam proses review');
    }

    /** PUT /klaim/{id}/verifikasi → REVIEW → VERIFIED */
    public function setVerifikasi(string $id): JsonResponse
    {
        try {
            $claim = $this->service->setVerifikasi($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Klaim terverifikasi. Siap disubmit.');
    }

    /** PUT /klaim/{id}/reject */
    public function setReject(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|string|min:5|max:500',
        ]);

        try {
            $claim = $this->service->setReject($id, $request->alasan);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Klaim ditolak');
    }

    // =========================================================================
    // LUPIS
    // =========================================================================

    /**
     * POST /klaim/{id}/lupis
     * Generate format LUPIS dari data klaim + billing.
     */
    public function generateLupis(string $id): JsonResponse
    {
        try {
            $claim = $this->service->generateLupis($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Data LUPIS berhasil di-generate');
    }

    // =========================================================================
    // SUBMIT
    // =========================================================================

    /**
     * POST /klaim/{id}/submit
     * Submit ke VClaim API (harus VERIFIED + VClaim enabled).
     * Placeholder — actual VClaim call ada di IntegrasiService.
     */
    public function submitKlaim(string $id): JsonResponse
    {
        try {
            $claim = $this->service->submitClaim($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($claim, 'Klaim berhasil disubmit ke VClaim. Status: SUBMITTED.');
    }

    // =========================================================================
    // AUDIT LOG & MONITORING
    // =========================================================================

    /** GET /klaim/{id}/audit-log */
    public function auditLog(string $id): JsonResponse
    {
        try {
            $logs = $this->service->getAuditLog($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }

        return $this->ok($logs);
    }

    /**
     * GET /klaim/vclaim-log
     * Query: action, status, per_page
     */
    public function vclaimpLog(Request $request): JsonResponse
    {
        return $this->ok($this->service->getVclaimLog(
            $request->only(['action', 'status', 'per_page'])
        ));
    }

    /**
     * GET /klaim/icare/monitoring
     * Placeholder — aktif saat integrasi iCare dikonfigurasi.
     */
    public function icareMonitoring(): JsonResponse
    {
        try {
            $data = $this->service->icareMonitoring();
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 503);
        }

        return $this->ok($data);
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
