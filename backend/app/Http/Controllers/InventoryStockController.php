<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Services\InventoryStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InventoryStockController extends Controller
{
    public function __construct(private InventoryStockService $service)
    {
    }

    /** POST /inventori-farmasi/stock/opname */
    public function opname(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_type'             => 'required|in:MEDICATION,BHP',
            'item_id'               => 'required|uuid',
            'location'              => 'nullable|in:INVENTORI,BEDAH,FARMASI',
            'reason'                => 'nullable|string|max:255',
            'batches'               => 'nullable|array',
            'batches.*.stock_id'    => 'nullable|uuid',
            'batches.*.batch_no'    => 'nullable|string|max:50',
            'batches.*.expiry_date' => 'nullable|date',
            'batches.*.qty_physical'=> 'required_with:batches|numeric|min:0',
            'new_qty'               => 'nullable|numeric|min:0',
        ]);

        if (empty($data['batches']) && !array_key_exists('new_qty', $data)) {
            return $this->error('Harus isi `batches` (per-batch) atau `new_qty` (total)', 422);
        }

        return $this->ok($this->service->opname($data), 'Opname berhasil');
    }

    /** GET /inventori-farmasi/stock/{type}/template-csv  (type: obat|bhp) */
    public function templateCsv(string $type): Response
    {
        try {
            $csv = $this->service->csvTemplate($type);
        } catch (\Exception $e) {
            return response($e->getMessage(), $e->getCode() ?: 422);
        }
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"template-stok-{$type}.csv\"",
        ]);
    }

    /** GET /inventori-farmasi/stock/{type}/export-csv?location= */
    public function exportCsv(Request $request, string $type): Response
    {
        $location = $this->resolveLocation($request);
        try {
            $csv = $this->service->exportCsv($type, $location);
        } catch (\Exception $e) {
            return response($e->getMessage(), $e->getCode() ?: 422);
        }
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"stok-{$type}-{$location}-" . now()->format('Ymd') . ".csv\"",
        ]);
    }

    /** POST /inventori-farmasi/stock/{type}/import-csv  (location via query/body) */
    public function importCsv(Request $request, string $type): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);
        $location = $this->resolveLocation($request);

        try {
            // Terima CSV/TXT & Excel; helper normalisasi BOM + delimiter ';' → koma.
            $content = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
            $result  = $this->service->importCsv($type, $content, $location);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(
            $result,
            "Import selesai: {$result['applied']} item disesuaikan, {$result['skipped']} dilewati."
        );
    }

    /** Ambil lokasi dari query/body, default INVENTORI; tolak nilai tak dikenal → INVENTORI. */
    private function resolveLocation(Request $request): string
    {
        $loc = strtoupper(trim((string) $request->input('location', InventoryStock::LOC_INVENTORI)));
        return in_array($loc, InventoryStock::LOCATIONS, true) ? $loc : InventoryStock::LOC_INVENTORI;
    }

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function error(string $message, int|string $status = 422): JsonResponse
    {
        // Coerce non-int status (e.g. PDO SQLSTATE string from QueryException) to a valid HTTP code.
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 422;
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
