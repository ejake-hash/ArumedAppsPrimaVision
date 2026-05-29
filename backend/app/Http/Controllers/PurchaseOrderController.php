<?php

namespace App\Http\Controllers;

use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'statuses', 'exclude_statuses', 'supplier_id', 'date_from', 'date_to', 'per_page']);
        return $this->ok($this->service->index($filters));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->show($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id'           => 'required|uuid|exists:suppliers,id',
            'po_date'               => 'required|date',
            'expected_date'         => 'nullable|date|after_or_equal:po_date',
            'status'                => 'nullable|in:DRAFT,SENT',
            'notes'                 => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.item_type'     => 'required|in:MEDICATION,BHP,IOL',
            'items.*.item_id'       => 'required|uuid',
            'items.*.qty_ordered'   => 'required|numeric|min:0.01',
            'items.*.unit_price'    => 'nullable|numeric|min:0',
            'items.*.notes'         => 'nullable|string',
        ]);

        $po = $this->service->create($data);
        return $this->ok($this->service->show($po->id), 'PO dibuat', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'supplier_id'           => 'sometimes|uuid|exists:suppliers,id',
            'po_date'               => 'sometimes|date',
            'expected_date'         => 'nullable|date',
            'status'                => 'sometimes|in:DRAFT,SENT',
            'notes'                 => 'nullable|string',
            'items'                 => 'sometimes|array|min:1',
            'items.*.item_type'     => 'required_with:items|in:MEDICATION,BHP,IOL',
            'items.*.item_id'       => 'required_with:items|uuid',
            'items.*.qty_ordered'   => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price'    => 'nullable|numeric|min:0',
            'items.*.notes'         => 'nullable|string',
        ]);

        $po = $this->service->update($id, $data);
        return $this->ok($this->service->show($po->id), 'PO diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return $this->ok(null, 'PO dihapus');
    }

    public function cancel(string $id): JsonResponse
    {
        $po = $this->service->cancel($id);
        return $this->ok($po, 'PO di-cancel');
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
