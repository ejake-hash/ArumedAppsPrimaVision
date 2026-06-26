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

        // Soft-delete aware: rooms.code UNIQUE plain (termasuk baris trashed). Bila room
        // dgn code sama pernah DIHAPUS → restore + update, bukan insert (cegah 23505
        // "duplicate" yg muncul saat user menambah kembali kamar yg sudah dihapus).
        // Validasi sudah mem-block duplikat AKTIF, jadi $existing di sini pasti trashed.
        $existing = Room::withTrashed()->where('code', $data['code'])->first();
        if ($existing && $existing->trashed()) {
            $existing->restore();
            $existing->update($data);

            return $this->ok($existing->fresh('beds'), 'Room dipulihkan & diperbarui');
        }

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

        // Soft-delete aware: unique(room_id, code) di DB termasuk baris trashed.
        // Cegah duplikat AKTIF (pesan ramah); bila bed dgn code sama pernah dihapus
        // → restore + reset, bukan insert (cegah 23505 saat menambah kembali bed).
        $existing = Bed::withTrashed()
            ->where('room_id', $room->id)
            ->where('code', $data['code'])
            ->first();

        if ($existing && ! $existing->trashed()) {
            return $this->error("Bed dengan kode {$data['code']} sudah ada di room ini.", 422);
        }

        if ($existing) {
            $existing->restore();
            $existing->update([
                'label'     => "{$room->code}.{$data['code']}",
                'status'    => Bed::STATUS_AVAILABLE,
                'is_active' => true,
            ]);

            return $this->ok($existing->fresh(), 'Bed dipulihkan');
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

    /** POST /master/room-tariff — upsert tarif kamar (unik per room_class + insurer). */
    public function storeTariff(Request $request): JsonResponse
    {
        // Insurer-only (post drop_classification 2026_06_08). UMUM/BPJS/SOSIAL kini
        // insurer sistem yang dipilih eksplisit; classification sudah tidak ada.
        $data = $request->validate([
            'room_class' => 'required|string|max:5',
            'insurer_id' => 'required|uuid|exists:insurers,id',
            'price'      => 'required|numeric|min:0',
            'is_active'  => 'nullable|boolean',
        ]);

        // Soft-delete aware: tabel pakai SoftDeletes + unique (room_class, insurer_id)
        // plain → updateOrCreate biasa tak lihat baris trashed → unique violation (23505)
        // saat tarif yang pernah dihapus diset ulang. Cari withTrashed → restore.
        $existing = RoomTariff::withTrashed()
            ->where('room_class', $data['room_class'])
            ->where('insurer_id', $data['insurer_id'])
            ->first();

        $values = ['price' => $data['price'], 'is_active' => $data['is_active'] ?? true];

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update($values);
            $tariff = $existing;
        } else {
            $tariff = RoomTariff::create(
                ['room_class' => $data['room_class'], 'insurer_id' => $data['insurer_id']] + $values
            );
        }

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
        // rooms.code UNIQUE di DB (termasuk baris soft-deleted). Validasi di sini agar
        // duplikat → 422 ramah, bukan 500 bocor SQLSTATE 23505.
        // CREATE: abaikan baris trashed (deleted_at NULL) → store() yang me-restore
        //   kamar lama yg pernah dihapus dgn code sama (alih-alih insert duplikat).
        // UPDATE: tetap strict (hitung trashed) supaya code yg masih dipegang baris
        //   trashed tak bisa dipakai ulang lewat update → hindari unique violation.
        $uniqueCode = $ignoreId
            ? "unique:rooms,code,{$ignoreId},id"
            : 'unique:rooms,code,NULL,id,deleted_at,NULL';

        return $request->validate([
            'code'            => "required|string|max:20|{$uniqueCode}",
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
