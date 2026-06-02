<?php

namespace App\Http\Controllers;

use App\Services\InventoryPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryPriceController extends Controller
{
    public function __construct(private readonly InventoryPriceService $service) {}

    public function getSettings(): JsonResponse
    {
        return $this->ok([
            'ppn_rate' => $this->service->getPpnRate(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ppn_rate' => 'required|numeric|min:0|max:100',
        ]);
        $setting = $this->service->setPpnRate((float) $validated['ppn_rate']);
        return $this->ok(['ppn_rate' => (float) $setting->ppn_rate], 'PPN diperbarui & HJA semua item dihitung ulang');
    }

    /**
     * List item by tipe (MEDICATION/BHP/IOL) lengkap dengan harga (kalau ada).
     * GET /api/inventori-farmasi/harga/{type}?search=&per_page=
     */
    public function index(Request $request, string $type): JsonResponse
    {
        $type = strtoupper($type);
        if (!in_array($type, ['MEDICATION', 'BHP', 'IOL'], true)) {
            return $this->fail('Tipe harus MEDICATION, BHP, atau IOL', 422);
        }

        $filters = $request->only(['search', 'per_page']);
        return $this->ok($this->service->listForType($type, $filters));
    }

    /**
     * Upsert harga untuk satu item.
     * PUT /api/inventori-farmasi/harga/{type}/{itemId}
     */
    public function upsert(Request $request, string $type, string $itemId): JsonResponse
    {
        $type = strtoupper($type);
        if (!in_array($type, ['MEDICATION', 'BHP', 'IOL'], true)) {
            return $this->fail('Tipe harus MEDICATION, BHP, atau IOL', 422);
        }

        $validated = $request->validate([
            'hpp'            => 'required|numeric|min:0',
            'margin_percent' => 'required|numeric|min:0|max:1000',
            'ppn_enabled'    => 'sometimes|boolean',
            'notes'          => 'nullable|string|max:500',
            'effective_date' => 'nullable|date',
        ]);

        $price = $this->service->upsert($type, $itemId, $validated);
        return $this->ok($price, 'Harga disimpan');
    }

    /**
     * Hapus harga (item kembali "belum diset").
     * DELETE /api/inventori-farmasi/harga/{type}/{itemId}
     */
    public function destroy(string $type, string $itemId): JsonResponse
    {
        $type = strtoupper($type);
        if (!in_array($type, ['MEDICATION', 'BHP', 'IOL'], true)) {
            return $this->fail('Tipe harus MEDICATION, BHP, atau IOL', 422);
        }
        $this->service->delete($type, $itemId);
        return $this->ok(null, 'Harga dihapus');
    }

    /**
     * Template CSV kosong per tipe.
     * GET /api/inventori-farmasi/harga/{type}/template-csv
     */
    public function templateCsv(string $type): \Symfony\Component\HttpFoundation\Response
    {
        $type = strtoupper($type);
        if (!in_array($type, ['MEDICATION', 'BHP', 'IOL'], true)) {
            return $this->fail('Tipe harus MEDICATION, BHP, atau IOL', 422);
        }
        $csv = $this->service->templateCsv($type);
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"template-harga-" . strtolower($type) . ".csv\"",
        ]);
    }

    /**
     * Export harga item yang sudah diset (per tipe) ke CSV.
     * GET /api/inventori-farmasi/harga/{type}/export-csv
     */
    public function exportCsv(string $type): \Symfony\Component\HttpFoundation\Response
    {
        $type = strtoupper($type);
        if (!in_array($type, ['MEDICATION', 'BHP', 'IOL'], true)) {
            return $this->fail('Tipe harus MEDICATION, BHP, atau IOL', 422);
        }
        $csv = $this->service->exportCsv($type);
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"harga-" . strtolower($type) . '-' . now()->format('Ymd') . ".csv\"",
        ]);
    }

    /**
     * Import harga dari CSV (upsert by kode/serial_number).
     * POST /api/inventori-farmasi/harga/{type}/import-csv
     */
    public function importCsv(Request $request, string $type): JsonResponse
    {
        $type = strtoupper($type);
        if (!in_array($type, ['MEDICATION', 'BHP', 'IOL'], true)) {
            return $this->fail('Tipe harus MEDICATION, BHP, atau IOL', 422);
        }
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);
        // Terima CSV/TXT & Excel; helper normalisasi BOM + delimiter ';' → koma.
        $csv = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
        $result = $this->service->importCsv($type, $csv);
        return $this->ok(
            $result,
            "Import selesai: {$result['inserted']} baru, {$result['updated']} update, {$result['skipped']} dilewati."
        );
    }

    // ---- Response helpers ----------------------------------------------------
    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    private function fail(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
