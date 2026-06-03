<?php

namespace App\Http\Controllers;

use App\Services\RuangTindakanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stasiun "Ruang Tindakan" (Laser YAG / Laser Retina-PRP).
 * Gating: permission:ruang_tindakan.read (grup route di api.php), write per-endpoint.
 */
class RuangTindakanController extends Controller
{
    public function __construct(private readonly RuangTindakanService $service) {}

    // GET /ruang-tindakan/antrian
    public function indexAntrian(): JsonResponse
    {
        return $this->ok($this->service->getPatientQueue());
    }

    // PUT /ruang-tindakan/antrian/{id}/panggil
    public function panggilAntrian(string $id): JsonResponse
    {
        try {
            return $this->ok($this->service->panggilAntrian($id));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    // PUT /ruang-tindakan/jadwal/{id}/mulai
    public function mulaiTindakan(string $id): JsonResponse
    {
        try {
            return $this->ok($this->service->mulaiTindakan($id));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    // PUT /ruang-tindakan/jadwal/{id}/selesai
    public function selesaiTindakan(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'laporan'              => 'nullable|array',
            'procedure_ids'        => 'nullable|array',
            'procedure_ids.*'      => 'uuid|exists:procedures,id',
            'post_op_disposition'  => 'nullable|in:PULANG,RAWAT_INAP,LANJUT_RANAP,HCU',
            'followup_date'        => 'nullable|date',
            'complication'         => 'nullable|string|max:1000',
            'notes'                => 'nullable|string|max:2000',
        ]);

        try {
            return $this->ok($this->service->selesaiTindakan($id, $validated), 'Tindakan selesai');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    // GET /ruang-tindakan/record/{scheduleId}
    public function showRecord(string $scheduleId): JsonResponse
    {
        return $this->ok($this->service->getRecord($scheduleId));
    }

    // PUT /ruang-tindakan/record/{id}/laporan
    public function saveLaporan(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'laporan' => 'required|array',
        ]);

        try {
            return $this->ok($this->service->saveLaporan($id, $validated['laporan']), 'Laporan tersimpan');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    // GET /ruang-tindakan/procedures?search=
    public function procedures(Request $request): JsonResponse
    {
        return $this->ok($this->service->getProcedureOptions($request->query('search')));
    }

    // -------------------------------------------------------------------------

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 500;

        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
