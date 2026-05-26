<?php

namespace App\Http\Controllers;

use App\Services\JadwalDokterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JadwalDokterController extends Controller
{
    public function __construct(private readonly JadwalDokterService $service) {}

    // GET /jadwal-dokter
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->getAll(),
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
            'employee_id' => 'required|uuid|exists:employees,id',
            'day_of_week' => 'required|integer|min:1|max:7',
            'start_time'  => 'required|date_format:H:i',
            'end_time'    => 'required|date_format:H:i|after:start_time',
            'room'        => 'nullable|string|max:50',
            'poliklinik'  => 'nullable|string|max:100',
            'is_active'   => 'nullable|boolean',
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
            'employee_id' => 'sometimes|uuid|exists:employees,id',
            'day_of_week' => 'sometimes|integer|min:1|max:7',
            'start_time'  => 'sometimes|date_format:H:i',
            'end_time'    => 'sometimes|date_format:H:i',
            'room'        => 'nullable|string|max:50',
            'poliklinik'  => 'nullable|string|max:100',
            'is_active'   => 'nullable|boolean',
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
}
