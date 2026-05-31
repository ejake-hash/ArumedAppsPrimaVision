<?php

namespace App\Http\Controllers;

use App\Services\PharmacySaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PharmacySaleController extends Controller
{
    public function __construct(private readonly PharmacySaleService $service) {}

    /** GET /farmasi/penjualan — riwayat penjualan obat bebas (default hari ini). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['tanggal', 'status', 'search', 'per_page', 'all']);
        return $this->ok($this->service->list($filters));
    }

    /** POST /farmasi/penjualan — checkout (jual + bayar 1-step). */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'buyer_name'               => 'nullable|string|max:120',
            'buyer_phone'              => 'nullable|string|max:30',
            'payment_method'           => 'nullable|in:CASH,CARD,TRANSFER',
            'paid_amount'              => 'required|numeric|min:0',
            'discount'                 => 'nullable|numeric|min:0',
            'discount_percent'         => 'nullable|numeric|min:0|max:100',
            'notes'                    => 'nullable|string|max:255',
            'items'                    => 'required|array|min:1',
            'items.*.medication_id'    => 'required|uuid|exists:medications,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.discount_amount'  => 'nullable|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $sale = $this->service->checkout($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($sale, 'Penjualan berhasil', 201);
    }

    /** GET /farmasi/penjualan/{id} */
    public function show(string $id): JsonResponse
    {
        try {
            $sale = $this->service->show($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }

        return $this->ok($sale);
    }

    /** POST /farmasi/penjualan/{id}/batal — batalkan & kembalikan stok. */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $sale = $this->service->cancel($id, $validated['reason'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($sale, 'Penjualan dibatalkan, stok dikembalikan');
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
