<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\Room;
use Illuminate\Http\JsonResponse;

/**
 * GET /antrean-tv/bed-availability — public (TV lobi tanpa login).
 *
 * Papan ketersediaan tempat tidur untuk transparansi ke pasien. SENGAJA
 * agregat TANPA PII: hanya kamar/kelas + status bed (AVAILABLE/OCCUPIED/...).
 * TIDAK boleh ada nama/No. RM/visit_id pasien — beda dengan RanapService::bedBoard()
 * (internal, ber-PII) yang dipakai RawatInapView. Status okupansi diambil langsung
 * dari kolom Bed::status (sudah dijaga konsisten oleh admit/transfer/discharge),
 * jadi tidak perlu join ke BedAssignment/visit/patient sama sekali.
 */
class TvBedAvailabilityController extends Controller
{
    public function index(): JsonResponse
    {
        $rooms = Room::with(['beds' => fn ($q) => $q->where('is_active', true)->orderBy('code')])
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $data = $rooms->map(function (Room $room) {
            $beds = $room->beds->map(fn (Bed $bed) => [
                'label'  => $bed->label,
                'status' => $bed->status,
            ]);

            return [
                'room_id'     => $room->id,
                'room_name'   => $room->name,
                'kelas_rawat' => $room->kelas_rawat,
                'type'        => $room->type,
                'total'       => $beds->count(),
                'occupied'    => $beds->where('status', Bed::STATUS_OCCUPIED)->count(),
                'available'   => $beds->where('status', Bed::STATUS_AVAILABLE)->count(),
                'beds'        => $beds->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}
