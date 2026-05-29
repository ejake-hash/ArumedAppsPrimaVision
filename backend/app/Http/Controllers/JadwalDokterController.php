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

    // GET /jadwal-dokter/template-csv
    public function template(): Response
    {
        $csv = $this->service->getCsvTemplate();

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-jadwal-dokter.csv"',
        ]);
    }

    // POST /jadwal-dokter/import-csv  (multipart: file + week_start)
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'       => 'required|file|mimes:csv,txt|max:5120',
            'week_start' => 'nullable|date_format:Y-m-d',
        ]);

        try {
            $result = $this->service->importCsv(
                $request->file('file')->getRealPath(),
                $request->input('week_start')
            );

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
