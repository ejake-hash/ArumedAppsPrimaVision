<?php

namespace App\Http\Controllers;

use App\Services\JadwalDokterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class JadwalDokterController extends Controller
{
    public function __construct(private readonly JadwalDokterService $service) {}

    // GET /jadwal-dokter?week_start=YYYY-MM-DD&service_type=BPJS|EKSEKUTIF
    public function index(Request $request): JsonResponse
    {
        $weekStart   = $request->query('week_start');
        $serviceType = $request->query('service_type');

        return response()->json([
            'success' => true,
            'data'    => $this->service->getAll($weekStart, $serviceType),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    // GET /jadwal-dokter/minggu-tersedia
    public function availableWeeks(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->availableWeeks(),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    // GET /jadwal-dokter/aktif-hari-ini
    public function aktifHariIni(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getAktifHariIni(),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    // GET /jadwal-dokter/{id}
    public function show(string $id): JsonResponse
    {
        $schedule = $this->service->getById($id);

        return response()->json([
            'success' => true,
            'data'    => $schedule,
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    // POST /jadwal-dokter
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'  => 'required|uuid|exists:employees,id',
            'day_of_week'  => 'required|integer|min:1|max:7',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i|after:start_time',
            'room'         => 'nullable|string|max:50',
            'poliklinik'   => 'nullable|string|max:100',
            'poli_code'    => 'nullable|string|max:10',
            'service_type' => 'nullable|in:BPJS,EKSEKUTIF',
            'week_start'   => 'nullable|date_format:Y-m-d',
            'is_active'    => 'nullable|boolean',
        ]);

        try {
            $schedule = $this->service->create($validated);
            return response()->json([
                'success' => true,
                'data'    => $schedule->load('employee'),
                'message' => 'Jadwal berhasil ditambahkan',
                'errors'  => null,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => null,
            ], $e->getCode() ?: 422);
        }
    }

    // PUT /jadwal-dokter/{id}
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'  => 'sometimes|uuid|exists:employees,id',
            'day_of_week'  => 'sometimes|integer|min:1|max:7',
            'start_time'   => 'sometimes|date_format:H:i',
            'end_time'     => 'sometimes|date_format:H:i',
            'room'         => 'nullable|string|max:50',
            'poliklinik'   => 'nullable|string|max:100',
            'poli_code'    => 'nullable|string|max:10',
            'service_type' => 'sometimes|in:BPJS,EKSEKUTIF',
            'week_start'   => 'sometimes|date_format:Y-m-d',
            'is_active'    => 'nullable|boolean',
        ]);

        try {
            $schedule = $this->service->update($id, $validated);
            return response()->json([
                'success' => true,
                'data'    => $schedule,
                'message' => 'Jadwal berhasil diperbarui',
                'errors'  => null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => null,
            ], $e->getCode() ?: 422);
        }
    }

    // DELETE /jadwal-dokter/{id}
    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Jadwal berhasil dihapus',
            'errors'  => null,
        ]);
    }

    // PATCH /jadwal-dokter/{id}/toggle
    public function toggle(string $id): JsonResponse
    {
        $schedule = $this->service->toggleAktif($id);

        return response()->json([
            'success' => true,
            'data'    => $schedule,
            'message' => $schedule->is_active ? 'Dokter diaktifkan' : 'Dokter dinonaktifkan',
            'errors'  => null,
        ]);
    }

    // POST /jadwal-dokter/salin-minggu-depan  { week_start: YYYY-MM-DD }
    public function copyToNextWeek(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'week_start' => 'nullable|date_format:Y-m-d',
        ]);

        $result = $this->service->copyToNextWeek($validated['week_start'] ?? null);

        return response()->json([
            'success' => true,
            'data'    => $result,
            'message' => "Disalin ke minggu {$result['target_week']}: {$result['copied']} jadwal baru, {$result['skipped']} dilewati (sudah ada).",
            'errors'  => null,
        ]);
    }

    // GET /jadwal-dokter/template-csv  (?format=xlsx untuk Excel)
    public function template(Request $request): Response
    {
        return $this->csvOrXlsx($request, $this->service->getCsvTemplate(), 'template-jadwal-dokter', 'Jadwal');
    }

    // GET /jadwal-dokter/export-csv?week_start=&service_type=  (?format=xlsx untuk Excel)
    public function export(Request $request): Response
    {
        $csv = $this->service->getCsvExport(
            $request->query('week_start'),
            $request->query('service_type'),
        );

        return $this->csvOrXlsx($request, $csv, 'jadwal-dokter-' . now()->format('Ymd'), 'Jadwal');
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

    // POST /jadwal-dokter/import-csv  (multipart: file CSV/XLSX + week_start)
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'       => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120',
            'week_start' => 'nullable|date_format:Y-m-d',
        ]);

        try {
            // CSV/XLSX/ODS → CSV string ternormalisasi → tulis tmp → jalur importer existing.
            $csv = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
            $tmp = tempnam(sys_get_temp_dir(), 'jadwal_') . '.csv';
            file_put_contents($tmp, $csv);

            try {
                $result = $this->service->importCsv($tmp, $request->input('week_start'));
            } finally {
                @unlink($tmp);
            }

            $msg = "Import selesai: {$result['imported']} jadwal masuk, {$result['skipped']} dilewati.";
            if (! empty($result['errors'])) {
                $msg .= ' Ada ' . count($result['errors']) . ' baris bermasalah.';
            }

            return response()->json([
                'success' => true,
                'data'    => $result,
                'message' => $msg,
                'errors'  => $result['errors'] ?: null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => null,
            ], $e->getCode() ?: 422);
        }
    }
}
