<?php

namespace App\Http\Controllers;

use App\Models\Queue;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic queue controller — cross-cutting endpoints di /v1/antrian/*.
 * Per-station endpoints (admisi/perawat/refraksi/...) tetap di controller masing-masing.
 */
class QueueController extends Controller
{
    public function __construct(private readonly QueueService $service) {}

    /** GET /antrian — snapshot semua station hari ini (Antrean TV / Dashboard). */
    public function index(): JsonResponse
    {
        return $this->ok($this->service->getAllActive());
    }

    /** GET /antrian/station/{station} — antrian per station. */
    public function byStation(string $station): JsonResponse
    {
        try {
            $rows = $this->service->getByStation(strtoupper($station));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($rows);
    }

    /** GET /antrian/{id} — detail antrian. */
    public function show(string $id): JsonResponse
    {
        return $this->ok($this->service->getStatus($id));
    }

    /** PUT /antrian/{id}/panggil — WAITING → CALLED. */
    public function panggil(string $id): JsonResponse
    {
        try {
            $q = $this->service->panggil($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($q, 'Pasien dipanggil');
    }

    /** PUT /antrian/{id}/mulai — CALLED → IN_PROGRESS. */
    public function mulai(string $id): JsonResponse
    {
        try {
            $q = $this->service->mulai($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($q, 'Pasien sedang dilayani');
    }

    /** PUT /antrian/{id}/lewati — pindah ke akhir antrean. */
    public function lewati(string $id): JsonResponse
    {
        try {
            $q = $this->service->lewati($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($q, 'Pasien dilewati');
    }

    /** PUT /antrian/{id}/selesai — COMPLETED + advance ke station berikutnya. */
    public function selesai(string $id): JsonResponse
    {
        try {
            $queue  = Queue::findOrFail($id);
            $result = $this->service->advanceFromStation($id, $queue->station);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Antrian selesai — pasien diteruskan');
    }

    /** PUT /antrian/{id}/batal — CANCELLED, tidak buat antrian baru. */
    public function batal(string $id, Request $request): JsonResponse
    {
        try {
            $q = $this->service->batal($id, $request->input('reason'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($q, 'Antrian dibatalkan');
    }

    // =========================================================================
    // RESPONSE HELPERS — envelope standar Arumed
    // =========================================================================

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        // Coerce non-int status (e.g. PDO SQLSTATE string from QueryException) to a valid HTTP code.
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 500;
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
