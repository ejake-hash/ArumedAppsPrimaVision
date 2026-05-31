<?php

namespace App\Http\Controllers;

use App\Services\FarmasiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmasiController extends Controller
{
    public function __construct(private readonly FarmasiService $service) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    /** GET /farmasi/antrian */
    public function indexAntrian(): JsonResponse
    {
        return $this->ok($this->service->getPatientQueue());
    }

    public function panggilAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->panggilAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dipanggil');
    }

    public function selesaiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->selesaiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Antrian selesai');
    }

    // =========================================================================
    // RESEP OBAT
    // =========================================================================

    /**
     * GET /farmasi/resep
     * Query: tanggal, status (DRAFT|SUBMITTED|DISPENSING|DISPENSED|CANCELLED), search, per_page
     */
    public function indexResep(Request $request): JsonResponse
    {
        return $this->ok($this->service->getPrescriptions(
            $request->only(['tanggal', 'status', 'search', 'per_page'])
        ));
    }

    /** GET /farmasi/resep/{id} */
    public function showResep(string $id): JsonResponse
    {
        return $this->ok($this->service->getPrescriptionById($id));
    }

    /** PUT /farmasi/resep/{id}/dispensing — mulai proses */
    public function startDispensing(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->startDispensing($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep mulai diproses');
    }

    /** PUT /farmasi/resep/{id}/selesai — selesai dispensing + potong stok */
    public function selesaiDispensing(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->selesaiDispensing($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep diselesaikan. Stok obat dikurangi.');
    }

    /** PUT /farmasi/resep/{id}/cancel */
    public function cancelResep(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->cancelResep($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep dibatalkan');
    }

    // -------------------------------------------------------------------------
    // Item dispensing

    /**
     * POST /farmasi/resep/{resepId}/item
     * Body: { items: [{medication_id, quantity, dosage, instructions, notes, source?}] }
     * source: RESEP (default) | TAMBAHAN (obat tambahan apotek, golongan BEBAS/BEBAS_TERBATAS).
     */
    public function storeItemDispensing(Request $request, string $resepId): JsonResponse
    {
        $validated = $request->validate([
            'items'                    => 'required|array|min:1',
            'items.*.medication_id'    => 'required|uuid|exists:medications,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.dosage'           => 'nullable|string|max:100',
            'items.*.instructions'     => 'nullable|string|max:255',
            'items.*.notes'            => 'nullable|string|max:255',
            'items.*.source'           => 'nullable|in:RESEP,TAMBAHAN',
        ]);

        try {
            $items = $this->service->storeItemDispensing($resepId, $validated['items']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($items, 'Item resep ditambahkan', 201);
    }

    /**
     * POST /farmasi/kunjungan/{visitId}/resep-otc
     * Penjualan obat tambahan (OTC) untuk pasien antrean Farmasi tanpa resep dokter.
     * Body: { items: [{medication_id, quantity, dosage, instructions, notes}] }
     */
    public function storeOtcPrescription(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'items'                    => 'required|array|min:1',
            'items.*.medication_id'    => 'required|uuid|exists:medications,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.dosage'           => 'nullable|string|max:100',
            'items.*.instructions'     => 'nullable|string|max:255',
            'items.*.notes'            => 'nullable|string|max:255',
        ]);

        try {
            $prescription = $this->service->createOtcPrescription($visitId, $validated['items']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Penjualan obat tambahan dibuat', 201);
    }

    /** PUT /farmasi/resep-item/{id} */
    public function updateItemDispensing(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'quantity'     => 'sometimes|integer|min:1',
            'dosage'       => 'nullable|string|max:100',
            'instructions' => 'nullable|string|max:255',
            'notes'        => 'nullable|string|max:255',
        ]);

        try {
            $item = $this->service->updateItemDispensing($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($item, 'Item diperbarui');
    }

    /** DELETE /farmasi/resep-item/{id} */
    public function deleteItemDispensing(string $id): JsonResponse
    {
        try {
            $this->service->deleteItemDispensing($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Item dihapus');
    }

    // =========================================================================
    // SURGERY REQUEST (BHP + IOL dari Bedah)
    // =========================================================================

    /**
     * GET /farmasi/surgery-request
     * Query: status (default: REQUESTED), tanggal
     */
    public function indexSurgeryRequest(Request $request): JsonResponse
    {
        return $this->ok($this->service->getSurgeryRequests(
            $request->only(['status', 'tanggal'])
        ));
    }

    /** GET /farmasi/surgery-request/{id} */
    public function showSurgeryRequest(string $id): JsonResponse
    {
        return $this->ok($this->service->getSurgeryRequestById($id));
    }

    /**
     * PUT /farmasi/surgery-request/{id}/siapkan
     * Tandai Farmasi sedang menyiapkan item (audit trail only, no status change).
     */
    public function siapkanSurgeryRequest(string $id): JsonResponse
    {
        try {
            $surgeryRequest = $this->service->siapkanSurgeryRequest($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'Penyiapan BHP+IOL dimulai');
    }

    /**
     * POST /farmasi/surgery-request/{id}/assign-iol
     * Assign IOL item spesifik ke satu IOL line di request.
     * Body: { request_iol_id, iol_item_id }
     * Validasi power ±0.5 D, is_used = false.
     */
    public function assignIol(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'request_iol_id' => 'required|uuid|exists:surgery_request_iol,id',
            'iol_item_id'    => 'required|uuid|exists:iol_items,id',
        ]);

        try {
            $requestIol = $this->service->assignIolToRequest(
                $validated['request_iol_id'],
                $validated['iol_item_id']
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($requestIol, 'IOL berhasil di-assign');
    }

    /**
     * POST /farmasi/surgery-request/{id}/kirim
     * Kirim ke Bedah (REQUESTED → SENT).
     * Guard: semua IOL harus sudah di-assign.
     * Side-effect: deduct BHP stock.
     */
    public function kirimSurgeryRequest(string $id): JsonResponse
    {
        try {
            $surgeryRequest = $this->service->kirimSurgeryRequest($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'BHP+IOL dikirim ke Bedah. Stok BHP dikurangi.');
    }

    // =========================================================================
    // MASTER STOK — OBAT
    // =========================================================================

    /**
     * GET /farmasi/stok/obat
     * Query: search, formularium, alert (boolean), per_page
     */
    public function indexStokObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->getStokObat(
            $request->only(['search', 'formularium', 'alert', 'per_page'])
        ));
    }

    public function showStokObat(string $id): JsonResponse
    {
        return $this->ok(\App\Models\Medication::findOrFail($id));
    }

    /**
     * PUT /farmasi/stok/obat/{id}
     * Body: { stock, min_stock, price, expiry_date, batch_number }
     */
    public function updateStokObat(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'stock'        => 'nullable|integer|min:0',
            'min_stock'    => 'nullable|integer|min:0',
            'price'        => 'nullable|numeric|min:0',
            'expiry_date'  => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
        ]);

        try {
            $medication = $this->service->updateStokObat($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($medication, 'Stok obat diperbarui');
    }

    // =========================================================================
    // MASTER STOK — BHP
    // =========================================================================

    /**
     * GET /farmasi/stok/bhp
     * Query: search, alert (boolean), per_page
     */
    public function indexStokBhp(Request $request): JsonResponse
    {
        return $this->ok($this->service->getStokBhp(
            $request->only(['search', 'alert', 'per_page'])
        ));
    }

    /**
     * PUT /farmasi/stok/bhp/{id}
     * Body: { stock, min_stock, price }
     */
    public function updateStokBhp(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'stock'     => 'nullable|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'price'     => 'nullable|numeric|min:0',
        ]);

        try {
            $bhp = $this->service->updateStokBhp($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($bhp, 'Stok BHP diperbarui');
    }

    // =========================================================================
    // MASTER STOK — IOL
    // =========================================================================

    /**
     * GET /farmasi/stok/iol
     * Query: available_only (boolean), iol_type, brand, power, per_page
     */
    public function indexStokIol(Request $request): JsonResponse
    {
        return $this->ok($this->service->getStokIol(
            $request->only(['available_only', 'iol_type', 'brand', 'power', 'per_page'])
        ));
    }

    /**
     * PUT /farmasi/stok/iol/{id}
     * Body: { brand, model, iol_type, material, power, lot_number, serial_number, gs1_barcode, price, is_active }
     */
    public function updateStokIol(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'brand'         => 'nullable|string|max:100',
            'model'         => 'nullable|string|max:100',
            'iol_type'      => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'material'      => 'nullable|in:Acrylic,Silicone,PMMA',
            'power'         => 'nullable|numeric|between:0,40',
            'lot_number'    => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'gs1_barcode'   => 'nullable|string|max:255',
            'price'         => 'nullable|numeric|min:0',
            'is_active'     => 'nullable|boolean',
        ]);

        try {
            $iol = $this->service->updateStokIol($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($iol, 'Data IOL diperbarui');
    }

    /** GET /farmasi/stok/alert — semua item di bawah min_stock */
    public function stokAlert(): JsonResponse
    {
        return $this->ok($this->service->getStokAlert());
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
