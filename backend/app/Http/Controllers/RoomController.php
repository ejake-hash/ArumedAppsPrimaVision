<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\BedAssignment;
use App\Models\Room;
use App\Models\RoomTariff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Master Room & Bed — dikelola dari halaman Profil Klinik.
 * Struktur 2 level: Room (kelas melekat di sini) → Bed (banyak per room).
 */
class RoomController extends Controller
{
    // ---------------------------------------------------------------- ROOM

    /** GET /master/room — daftar room + ringkasan bed/occupancy. */
    public function index(): JsonResponse
    {
        $rooms = Room::withCount([
            'beds',
            'beds as occupied_count' => fn ($q) => $q->where('status', Bed::STATUS_OCCUPIED),
        ])->with(['beds' => fn ($q) => $q->orderBy('code')])
            ->orderBy('code')
            ->get();

        return $this->ok($rooms);
    }

    /** POST /master/room */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRoom($request);
        $room = Room::create($data);

        return $this->ok($room->fresh('beds'), 'Room dibuat');
    }

    /** PUT /master/room/{id} */
    public function update(string $id, Request $request): JsonResponse
    {
        $room = Room::findOrFail($id);
        $data = $this->validateRoom($request, $id);
        $room->update($data);

        return $this->ok($room->fresh('beds'), 'Room diperbarui');
    }

    /** DELETE /master/room/{id} — tolak jika ada bed terisi. */
    public function destroy(string $id): JsonResponse
    {
        $room = Room::withCount([
            'beds as occupied_count' => fn ($q) => $q->where('status', Bed::STATUS_OCCUPIED),
        ])->findOrFail($id);

        if ($room->occupied_count > 0) {
            return $this->error('Room masih punya bed terisi — tidak bisa dihapus.', 422);
        }

        DB::transaction(function () use ($room) {
            $room->beds()->delete(); // soft delete bed
            $room->delete();
        });

        return $this->ok(null, 'Room dihapus');
    }

    // ---------------------------------------------------------------- BED

    /** POST /master/room/{roomId}/bed */
    public function storeBed(string $roomId, Request $request): JsonResponse
    {
        $room = Room::findOrFail($roomId);
        $data = $request->validate([
            'code' => 'required|string|max:20',
        ]);

        // Cegah duplikat code dalam room (unique sudah di DB; beri pesan ramah).
        $exists = Bed::where('room_id', $room->id)->where('code', $data['code'])->exists();
        if ($exists) {
            return $this->error("Bed dengan kode {$data['code']} sudah ada di room ini.", 422);
        }

        $bed = Bed::create([
            'room_id'   => $room->id,
            'code'      => $data['code'],
            'label'     => "{$room->code}.{$data['code']}", // auto: 305.A
            'status'    => Bed::STATUS_AVAILABLE,
            'is_active' => true,
        ]);

        return $this->ok($bed, 'Bed ditambahkan');
    }

    /** PUT /master/bed/{id} — ubah status/aktif (bukan code, agar label konsisten). */
    public function updateBed(string $id, Request $request): JsonResponse
    {
        $bed  = Bed::findOrFail($id);
        $data = $request->validate([
            'status'    => 'nullable|in:AVAILABLE,OCCUPIED,CLEANING,MAINTENANCE,RESERVED',
            'is_active' => 'nullable|boolean',
        ]);

        // Tidak boleh ubah status OCCUPIED secara manual jika ada pasien aktif.
        if (($data['status'] ?? null) && $data['status'] !== Bed::STATUS_OCCUPIED) {
            $hasActive = BedAssignment::where('bed_id', $bed->id)->whereNull('released_at')->exists();
            if ($hasActive) {
                return $this->error('Bed sedang ditempati pasien aktif — tidak bisa ubah status manual.', 422);
            }
        }

        $bed->update(array_filter($data, fn ($v) => $v !== null));

        return $this->ok($bed->fresh(), 'Bed diperbarui');
    }

    /** DELETE /master/bed/{id} — tolak jika sedang ditempati. */
    public function destroyBed(string $id): JsonResponse
    {
        $bed = Bed::findOrFail($id);

        if ($bed->status === Bed::STATUS_OCCUPIED) {
            return $this->error('Bed sedang terisi — tidak bisa dihapus.', 422);
        }

        $bed->delete();

        return $this->ok(null, 'Bed dihapus');
    }

    // ---------------------------------------------------------------- TARIF KAMAR

    /** GET /master/room-tariff — daftar tarif kamar per kelas per insurer. */
    public function indexTariff(): JsonResponse
    {
        $tariffs = RoomTariff::with('insurer:id,name,type')
            ->orderBy('room_class')
            ->get();

        return $this->ok($tariffs);
    }

    /** POST /master/room-tariff — upsert tarif (unik per room_class+insurer+classification). */
    public function storeTariff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_class'     => 'required|string|max:5',
            'insurer_id'     => 'nullable|uuid',
            'classification' => 'required|string|max:20',
            'price'          => 'required|numeric|min:0',
            'is_active'      => 'nullable|boolean',
        ]);

        // Jika insurer_id kosong, resolve ke sistem insurer dari classification
        // (UMUM/BPJS/SOSIAL) agar KasirService::getPrice('room', ...) bisa menemukannya
        // lewat resolveTariffInsurerId. Selain itu biarkan null (fallback UMUM jalan).
        $insurerId = $data['insurer_id'] ?? null;
        if (! $insurerId && in_array($data['classification'], ['UMUM', 'BPJS', 'SOSIAL'], true)) {
            $insurerId = \App\Models\Insurer::where('is_system', true)
                ->where('type', $data['classification'])
                ->value('id');
        }

        $tariff = RoomTariff::updateOrCreate(
            [
                'room_class'     => $data['room_class'],
                'insurer_id'     => $insurerId,
                'classification' => $data['classification'],
            ],
            [
                'price'     => $data['price'],
                'is_active' => $data['is_active'] ?? true,
            ]
        );

        return $this->ok($tariff->fresh('insurer'), 'Tarif kamar disimpan');
    }

    /** DELETE /master/room-tariff/{id} */
    public function destroyTariff(string $id): JsonResponse
    {
        RoomTariff::findOrFail($id)->delete();
        return $this->ok(null, 'Tarif kamar dihapus');
    }

    // ---------------------------------------------------------------- helpers

    private function validateRoom(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code'            => 'required|string|max:20',
            'name'            => 'required|string|max:100',
            'kelas_rawat'     => 'required|string|max:5',
            'type'            => 'nullable|in:KAMAR,ICU,ISOLASI,HCU',
            'bpjs_kelas_code' => 'nullable|string|max:10',
            'bpjs_ruang_code' => 'nullable|string|max:20',
            'gender_policy'   => 'nullable|string|max:10',
            'is_active'       => 'nullable|boolean',
        ]);
    }

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
