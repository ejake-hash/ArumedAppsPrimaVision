<?php

namespace App\Http\Controllers;

use App\Models\UnitRequest;
use App\Services\InventoriReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Laporan Inventori Farmasi — ringkasan + pemesanan + retur (READ-ONLY + export).
 * Gating: permission:inventori_farmasi.read (grup route di api.php).
 */
class InventoriReportController extends Controller
{
    public function __construct(private readonly InventoriReportService $service) {}

    private const TYPES = ['MEDICATION', 'BHP', 'IOL'];

    public function summary(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);
        return $this->ok($this->service->summary($f));
    }

    public function pemesanan(Request $request): JsonResponse
    {
        $f = $this->validateList($request);
        $res = $this->service->pemesananList($f);
        return response()->json(['success' => true, 'data' => $res['data'], 'meta' => $res['meta'], 'message' => 'Berhasil', 'errors' => null]);
    }

    public function pemesananExport(Request $request): Response
    {
        $f = $this->validateList($request);
        return $this->csvOrXlsx($request, $this->service->pemesananCsv($f), 'laporan-pemesanan-' . now()->format('Ymd'), 'Pemesanan');
    }

    public function retur(Request $request): JsonResponse
    {
        $f = $this->validateList($request, true);
        $res = $this->service->returList($f);
        return response()->json(['success' => true, 'data' => $res['data'], 'meta' => $res['meta'], 'message' => 'Berhasil', 'errors' => null]);
    }

    public function returExport(Request $request): Response
    {
        $f = $this->validateList($request, true);
        return $this->csvOrXlsx($request, $this->service->returCsv($f), 'laporan-retur-' . now()->format('Ymd'), 'Retur');
    }

    public function selisih(Request $request): JsonResponse
    {
        $f = $this->validateSelisih($request);
        $res = $this->service->selisihList($f);
        return response()->json(['success' => true, 'data' => $res['data'], 'kpi' => $res['kpi'], 'meta' => $res['meta'], 'message' => 'Berhasil', 'errors' => null]);
    }

    public function selisihExport(Request $request): Response
    {
        $f = $this->validateSelisih($request);
        return $this->csvOrXlsx($request, $this->service->selisihCsv($f), 'laporan-selisih-opname-' . now()->format('Ymd'), 'Selisih Opname');
    }

    private function validateSelisih(Request $request): array
    {
        return $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
            'location'  => 'nullable|in:INVENTORI,FARMASI,BEDAH',
            'item_type' => 'nullable|in:MEDICATION,BHP',
            'status'    => 'nullable|in:LEBIH,KURANG',
            'search'    => 'nullable|string|max:60',
            'per_page'  => 'nullable|integer|min:10|max:200',
            'page'      => 'nullable|integer|min:1',
        ]);
    }

    private function validateList(Request $request, bool $isRetur = false): array
    {
        return $request->validate([
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
            'station'   => 'nullable|in:' . implode(',', UnitRequest::STATIONS),
            'item_type' => 'nullable|in:' . implode(',', self::TYPES),
            'status'    => 'nullable|string|max:20',
            'condition' => $isRetur ? 'nullable|in:GOOD,DAMAGED,EXPIRED,NEAR_EXPIRY' : 'prohibited',
            'search'    => 'nullable|string|max:60',
            'per_page'  => 'nullable|integer|min:10|max:200',
            'page'      => 'nullable|integer|min:1',
        ]);
    }

    private function ok(mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => 'Berhasil', 'errors' => null]);
    }

    /** Kirim CSV string sbg file CSV (default) atau XLSX bila ?format=xlsx. */
    private function csvOrXlsx(Request $request, string $csv, string $baseName, string $sheetTitle): Response
    {
        if (strtolower((string) $request->query('format')) === 'xlsx') {
            $xlsx = \App\Support\SpreadsheetHelper::csvToXlsx($csv, $sheetTitle);
            return response($xlsx, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$baseName}.xlsx\"",
            ]);
        }
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$baseName}.csv\"",
        ]);
    }
}
