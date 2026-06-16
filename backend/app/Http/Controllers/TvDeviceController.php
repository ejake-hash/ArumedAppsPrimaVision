<?php

namespace App\Http\Controllers;

use App\Events\TvMediaUpdated;
use App\Models\TvDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registry TV per-perangkat untuk Antrean TV. Memungkinkan media (slideshow/
 * video) berbeda antar-TV. Read (register/show) PUBLIC karena TV lobi tanpa
 * login; write (rename/atur media/hapus) wajib permission:antrian_tv.write.
 */
class TvDeviceController extends Controller
{
    /**
     * POST /antrean-tv/device/register — public.
     * TV melapor saat mount: upsert by device_key + update heartbeat. Mengembalikan
     * media efektif (global bila synced, override bila tidak) supaya TV langsung
     * menampilkan yang benar tanpa menunggu broadcast.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_key' => 'required|string|max:64',
            'name'       => 'nullable|string|max:120',
        ]);

        $device = TvDevice::firstOrNew(['device_key' => $data['device_key']]);
        if (! $device->exists && ! empty($data['name'])) {
            $device->name = $data['name'];
        }
        $device->last_seen_at = now();
        $device->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'device' => $device->toListItem(),
                'media'  => $device->effectiveMedia(),
            ],
        ]);
    }

    /**
     * GET /antrean-tv/device/{deviceKey} — public.
     * Heartbeat + ambil media efektif terbaru (dipakai sebagai jaring pengaman
     * poll FE bila broadcast terlewat). 404 bila perangkat belum terdaftar.
     */
    public function show(string $deviceKey): JsonResponse
    {
        $device = TvDevice::where('device_key', $deviceKey)->first();
        if (! $device) {
            return response()->json(['success' => false, 'message' => 'TV belum terdaftar'], 404);
        }
        $device->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'device' => $device->toListItem(),
                'media'  => $device->effectiveMedia(),
            ],
        ]);
    }

    /**
     * GET /antrean-tv/devices — protected. Daftar TV untuk pemilih target di
     * panel kontrol (tab Media).
     */
    public function index(): JsonResponse
    {
        $devices = TvDevice::orderByDesc('last_seen_at')->get()
            ->map(fn (TvDevice $d) => $d->toListItem());

        return response()->json(['success' => true, 'data' => $devices]);
    }

    /**
     * PUT /antrean-tv/devices/{id} — protected. Ubah nama / sinkronisasi / media
     * override satu TV. Broadcast media efektif HANYA ke TV tersebut (device_key).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $device = TvDevice::findOrFail($id);

        $validated = $request->validate([
            'name'               => 'sometimes|string|max:120',
            'media_synced'       => 'sometimes|boolean',
            'media_mode'         => 'sometimes|string|in:placeholder,youtube,localvideo,slideshow',
            'youtube_embed_url'  => 'nullable|string|max:500',
            'video_autoplay'     => 'sometimes|boolean',
            'video_loop'         => 'sometimes|boolean',
            'external_video_url' => 'nullable|url|max:500',
            'slides'             => 'nullable|array',
            'slides.*.url'       => 'required_with:slides|string|max:1000',
            'slide_interval'     => 'sometimes|integer|min:3|max:60',
            'slide_scope'           => 'sometimes|string|in:panel,fullscreen',
            'flash_over_fullscreen' => 'sometimes|boolean',
        ]);

        // Menyetel media apa pun (selain hanya rename) menyiratkan TV ini mandiri.
        $touchesMedia = collect($validated)->keys()
            ->intersect(['media_mode', 'youtube_embed_url', 'video_autoplay', 'video_loop',
                'external_video_url', 'slides', 'slide_interval', 'slide_scope', 'flash_over_fullscreen'])
            ->isNotEmpty();
        if ($touchesMedia && ! array_key_exists('media_synced', $validated)) {
            $validated['media_synced'] = false;
        }

        $device->fill($validated)->save();

        $media = $device->effectiveMedia();
        broadcast(new TvMediaUpdated($media, $device->device_key))->toOthers();

        return response()->json([
            'success' => true,
            'data'    => ['device' => $device->toListItem(), 'media' => $media],
            'message' => 'Pengaturan TV tersimpan.',
        ]);
    }

    /**
     * DELETE /antrean-tv/devices/{id} — protected. Hapus pendaftaran TV. TV yang
     * masih hidup akan mendaftar ulang otomatis (synced ke global) saat reload.
     */
    public function destroy(string $id): JsonResponse
    {
        TvDevice::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'TV dihapus.']);
    }
}
