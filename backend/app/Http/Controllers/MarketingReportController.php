<?php

namespace App\Http\Controllers;

use App\Services\MarketingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Laporan Marketing — daftar pasien siap-olah untuk campaign (READ-ONLY + export).
 * Gating: permission:marketing.read (grup route di api.php).
 */
class MarketingReportController extends Controller
{
    public function __construct(private readonly MarketingReportService $service) {}

    // GET /laporan-marketing?service_type=RJ|RI|BEDAH&from=YYYY-MM-DD&to=YYYY-MM-DD
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => 'nullable|in:RJ,RI,BEDAH',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->service->getList([
                'service_type' => $validated['service_type'] ?? 'RJ',
                'from'         => $validated['from'] ?? null,
                'to'           => $validated['to'] ?? null,
            ]),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    // GET /laporan-marketing/notifications
    // Daftar notifikasi siap-hubungi (kontrol, tindakan terjadwal, ulang tahun, follow-up nyeri).
    public function notifications(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getNotifications(),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    // GET /laporan-marketing/export-csv?service_type=&from=&to=  (?format=xlsx untuk Excel)
    public function export(Request $request): Response
    {
        $validated = $request->validate([
            'service_type' => 'nullable|in:RJ,RI,BEDAH',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
        ]);

        $csv = $this->service->getCsvExport([
            'service_type' => $validated['service_type'] ?? 'RJ',
            'from'         => $validated['from'] ?? null,
            'to'           => $validated['to'] ?? null,
        ]);

        return $this->csvOrXlsx(
            $request,
            $csv,
            'laporan-marketing-' . now()->format('Ymd'),
            'Marketing'
        );
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
