<?php

namespace App\Http\Controllers;

use App\Services\UnitReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitReturnController extends Controller
{
    public function __construct(private readonly UnitReturnService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'station', 'status', 'date_from', 'date_to', 'per_page']);
        return $this->ok($this->service->index($filters));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->show($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'returning_station'    => 'required|in:ADMISI,TRIASE,REFRAKSIONIS,DOKTER,PENUNJANG,BEDAH,KASIR,FARMASI',
            'unit_request_id'      => 'nullable|uuid|exists:unit_requests,id',
            'return_date'          => 'nullable|date',
            'reason'               => 'nullable|string|max:100',
            'notes'                => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.item_type'    => 'required|in:MEDICATION,BHP,IOL',
            'items.*.item_id'      => 'required|uuid',
            'items.*.qty_returned' => 'required|numeric|min:0.01',
            'items.*.batch_no'     => 'nullable|string|max:50',
            'items.*.expiry_date'  => 'nullable|date',
            'items.*.condition'    => 'nullable|in:GOOD,DAMAGED,EXPIRED,NEAR_EXPIRY',
            'items.*.notes'        => 'nullable|string',
        ]);

        $ret = $this->service->create($data);
        return $this->ok($this->service->show($ret->id), 'Retur dibuat (DRAFT)', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'returning_station'    => 'nullable|in:ADMISI,TRIASE,REFRAKSIONIS,DOKTER,PENUNJANG,BEDAH,KASIR,FARMASI',
            'unit_request_id'      => 'nullable|uuid|exists:unit_requests,id',
            'return_date'          => 'nullable|date',
            'reason'               => 'nullable|string|max:100',
            'notes'                => 'nullable|string',
            'items'                => 'nullable|array',
            'items.*.item_type'    => 'required_with:items|in:MEDICATION,BHP,IOL',
            'items.*.item_id'      => 'required_with:items|uuid',
            'items.*.qty_returned' => 'required_with:items|numeric|min:0.01',
            'items.*.batch_no'     => 'nullable|string|max:50',
            'items.*.expiry_date'  => 'nullable|date',
            'items.*.condition'    => 'nullable|in:GOOD,DAMAGED,EXPIRED,NEAR_EXPIRY',
            'items.*.notes'        => 'nullable|string',
        ]);

        $ret = $this->service->update($id, $data);
        return $this->ok($this->service->show($ret->id), 'Retur diperbarui');
    }

    public function submit(string $id): JsonResponse
    {
        $ret = $this->service->submit($id);
        return $this->ok($this->service->show($ret->id), 'Retur di-submit, menunggu verifikasi admin inventori');
    }

    public function receive(string $id): JsonResponse
    {
        $ret = $this->service->receive($id);
        return $this->ok($this->service->show($ret->id), 'Retur diterima & stok kembali ke inventori');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $ret = $this->service->reject($id, $data['reason'] ?? null);
        return $this->ok($this->service->show($ret->id), 'Retur ditolak');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return $this->ok(null, 'Retur dihapus');
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
