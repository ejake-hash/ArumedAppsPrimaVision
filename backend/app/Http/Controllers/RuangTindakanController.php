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

    // GET /ruang-tindakan/history?tanggal=YYYY-MM-DD — riwayat pasien tindakan per tanggal
    public function history(Request $request): JsonResponse
    {
        $tanggal = $request->query('tanggal') ?: now('Asia/Jakarta')->toDateString();
        return $this->ok($this->service->getPatientHistory($tanggal));
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

    // PUT /ruang-tindakan/antrian/{id}/lewati
    public function lewatiAntrian(string $id): JsonResponse
    {
        try {
            return $this->ok($this->service->lewatiAntrian($id), 'Pasien dilewati');
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

    // GET /ruang-tindakan/jadwal?date_from=&date_to=  (tab "Tindakan Terjadwal", per minggu)
    public function jadwal(Request $request): JsonResponse
    {
        return $this->ok($this->service->getScheduledTindakan(
            $request->only(['date_from', 'date_to'])
        ));
    }

    // GET /ruang-tindakan/daftar-obat?search=  (picker resep obat pulang)
    public function daftarObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->getDaftarObat($request->query('search')));
    }

    // POST /ruang-tindakan/jadwal/{id}/resep  (resep obat pulang → Farmasi setelah Kasir)
    public function storeResep(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.medication_id'  => 'required|uuid|exists:medications,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.dose'           => 'nullable|string|max:100',
            'items.*.frequency'      => 'nullable|string|max:100',
            'items.*.route'          => 'nullable|string|max:100',
            'items.*.duration_days'  => 'nullable|integer|min:1',
            'items.*.notes'          => 'nullable|string|max:255',
            'pharmacy_note'          => 'nullable|string|max:500',
        ]);

        try {
            $resep = $this->service->storeResep($id, $validated['items'], [
                'notes'         => 'Obat pulang pasca-laser',
                'pharmacy_note' => $validated['pharmacy_note'] ?? null,
            ]);
            return $this->ok($resep, 'Resep tersimpan → akan muncul di Farmasi setelah Kasir');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
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
