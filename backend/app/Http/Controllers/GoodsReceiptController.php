<?php

namespace App\Http\Controllers;

use App\Services\GoodsReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'supplier_id', 'po_id', 'date_from', 'date_to', 'per_page']);
        return $this->ok($this->service->index($filters));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->show($id));
    }

    /**
     * Prepare data untuk modal "Terima dari PO".
     * GET /inventori-farmasi/penerimaan/from-po/{poId}
     */
    public function prepareFromPo(string $poId): JsonResponse
    {
        return $this->ok($this->service->prepareFromPo($poId));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'po_id'                 => 'nullable|uuid|exists:purchase_orders,id',
            'supplier_id'           => 'required|uuid|exists:suppliers,id',
            'receipt_date'          => 'required|date',
            'invoice_number'        => 'nullable|string|max:50',
            'notes'                 => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.po_item_id'    => 'nullable|uuid',
            'items.*.item_type'     => 'required|in:MEDICATION,BHP,IOL',
            'items.*.item_id'       => 'required|uuid',
            'items.*.qty_received'  => 'required|numeric|min:0.01',
            'items.*.batch_no'      => 'nullable|string|max:50',
            'items.*.expiry_date'   => 'nullable|date|after:today',
            'items.*.unit_price'    => 'nullable|numeric|min:0',
            'items.*.notes'         => 'nullable|string',
        ]);

        $grn = $this->service->create($data);
        return $this->ok($this->service->show($grn->id), 'Penerimaan berhasil dicatat', 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return $this->ok(null, 'Penerimaan dihapus & stok dikembalikan');
    }

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }
}
