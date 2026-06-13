<?php

namespace App\Http\Controllers;

use App\Services\StockOpnameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * StockOpnameSessionController — sesi opname (Berita Acara) + detail per item.
 * Gating: inventori_farmasi.read (index/show) & inventori_farmasi.write (store)
 * di grup route api.php.
 */
class StockOpnameSessionController extends Controller
{
    public function __construct(private readonly StockOpnameService $service) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'location'  => 'nullable|in:INVENTORI,FARMASI,BEDAH',
            'item_type' => 'nullable|in:MEDICATION,BHP',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'search'    => 'nullable|string|max:60',
            'per_page'  => 'nullable|integer|min:5|max:200',
            'page'      => 'nullable|integer|min:1',
        ]);
        $p = $this->service->index($f);
        return response()->json([
            'success' => true,
            'data'    => $p->items(),
            'meta'    => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->show($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location'           => 'required|in:INVENTORI,FARMASI,BEDAH',
            'item_type'          => 'required|in:MEDICATION,BHP',
            'opname_date'        => 'nullable|date',
            'notes'              => 'nullable|string|max:1000',
            'items'              => 'required|array|min:1',
            'items.*.item_id'    => 'required|uuid',
            'items.*.physical_qty' => 'required|numeric|min:0',
            'items.*.note'       => 'nullable|string|max:500',
        ]);

        $session = $this->service->createSession($data);

        return $this->ok($this->service->show($session->id), 'Berita Acara opname tersimpan');
    }

    private function ok(mixed $data, string $message = 'Berhasil'): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => $message, 'errors' => null]);
    }
}
