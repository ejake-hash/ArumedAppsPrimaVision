<?php

namespace App\Http\Controllers;

use App\Events\TvMediaUpdated;
use App\Models\TvMediaSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TvMediaSettingController extends Controller
{
    /**
     * GET /antrean-tv/media-settings — public.
     * Dipanggil saat AntreanTVView mount supaya semua TV langsung sinkron
     * dengan setting terakhir tanpa tunggu broadcast.
     */
    public function show(): JsonResponse
    {
        $row = TvMediaSetting::singleton();

        return response()->json([
            'success' => true,
            'data'    => $this->serialize($row),
        ]);
    }

    /**
     * PUT /antrean-tv/media-settings — protected (auth:api).
     * Update mode, YouTube URL, video options, slides list. Video lokal
     * di-upload terpisah via uploadVideo() agar payload non-multipart bersih.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_mode'         => 'sometimes|string|in:placeholder,youtube,localvideo,slideshow',
            'youtube_embed_url'  => 'nullable|string|max:500',
            'video_autoplay'     => 'sometimes|boolean',
            'video_loop'         => 'sometimes|boolean',
            // URL eksternal ke MP4 — kosongkan ('') untuk hapus & kembali ke file upload.
            'external_video_url' => 'nullable|url|max:500',
            'slides'             => 'nullable|array',
            'slides.*.url'       => 'required_with:slides|string|max:1000',
            'slide_interval'     => 'sometimes|integer|min:3|max:60',
            // Cakupan tampilan: panel kiri vs fullscreen, + apakah flash panggilan
            // tetap muncul di atas slideshow fullscreen.
            'slide_scope'           => 'sometimes|string|in:panel,fullscreen',
            'flash_over_fullscreen' => 'sometimes|boolean',
            // Running text bawah layar — array of string (boleh kosong = hapus semua).
            'ticker_messages'    => 'sometimes|array|max:30',
            'ticker_messages.*'  => 'string|max:200',
        ]);

        $row = TvMediaSetting::singleton();
        $row->fill($validated)->save();

        $payload = $this->serialize($row);
        broadcast(new TvMediaUpdated($payload))->toOthers();

        return response()->json([
            'success' => true,
            'data'    => $payload,
            'message' => 'Setting media TV tersimpan.',
        ]);
    }

    /**
     * POST /antrean-tv/media-settings/video — protected (auth:api, multipart).
     * Upload file video lokal. Replace-on-upload: file lama dihapus dulu
     * supaya storage tidak menumpuk (hanya 1 file aktif).
     */
    public function uploadVideo(Request $request): JsonResponse
    {
        $request->validate([
            // 500 MB cap (KB). Cukup untuk video promo HD 5-10 menit.
            // php.ini juga harus: upload_max_filesize=500M, post_max_size=520M.
            'video' => 'required|file|mimetypes:video/mp4,video/webm,video/ogg,video/quicktime|max:512000',
        ]);

        $row = TvMediaSetting::singleton();

        // Hapus video lama bila ada
        if ($row->local_video_path && Storage::disk('public')->exists($row->local_video_path)) {
            Storage::disk('public')->delete($row->local_video_path);
        }

        $path = $request->file('video')->store('tv-media', 'public');

        $row->fill([
            'local_video_path' => $path,
            'media_mode'       => 'localvideo',
        ])->save();

        $payload = $this->serialize($row);
        broadcast(new TvMediaUpdated($payload))->toOthers();

        return response()->json([
            'success' => true,
            'data'    => $payload,
            'message' => 'Video tersimpan dan disiarkan ke TV.',
        ]);
    }

    /**
     * DELETE /antrean-tv/media-settings/video — protected (auth:api).
     * Hapus file video dari disk + reset path. Bila mode aktif localvideo,
     * fallback ke placeholder.
     */
    public function deleteVideo(): JsonResponse
    {
        $row = TvMediaSetting::singleton();

        if ($row->local_video_path && Storage::disk('public')->exists($row->local_video_path)) {
            Storage::disk('public')->delete($row->local_video_path);
        }

        $row->fill([
            'local_video_path' => null,
            'media_mode'       => $row->media_mode === 'localvideo' ? 'placeholder' : $row->media_mode,
        ])->save();

        $payload = $this->serialize($row);
        broadcast(new TvMediaUpdated($payload))->toOthers();

        return response()->json([
            'success' => true,
            'data'    => $payload,
            'message' => 'Video lokal dihapus.',
        ]);
    }

    private function serialize(TvMediaSetting $row): array
    {
        // External URL diutamakan kalau ada (operator paste link Drive/Dropbox/CDN).
        // local_video_url di response: external_video_url || uploaded file URL || null.
        $videoUrl = $row->external_video_url
            ?: ($row->local_video_path ? Storage::disk('public')->url($row->local_video_path) : null);
        $videoName = $row->external_video_url
            ? basename(parse_url($row->external_video_url, PHP_URL_PATH) ?: 'video-eksternal')
            : ($row->local_video_path ? basename($row->local_video_path) : null);

        return [
            'media_mode'         => $row->media_mode,
            'youtube_embed_url'  => $row->youtube_embed_url,
            'video_autoplay'     => (bool) $row->video_autoplay,
            'video_loop'         => (bool) $row->video_loop,
            'local_video_url'    => $videoUrl,
            'local_video_name'   => $videoName,
            'external_video_url' => $row->external_video_url,
            'has_uploaded_file'  => (bool) $row->local_video_path,
            'slides'             => $row->slides ?? [],
            'slide_interval'     => (int) $row->slide_interval,
            'slide_scope'           => $row->slide_scope ?: 'panel',
            'flash_over_fullscreen' => (bool) $row->flash_over_fullscreen,
            // Null (baris lama sebelum migrasi) → fallback ke pesan bawaan supaya
            // ticker tidak kosong sampai operator menyimpan untuk pertama kali.
            'ticker_messages'    => $row->ticker_messages ?? TvMediaSetting::defaultTickerMessages(),
        ];
    }

    /**
     * POST /antrean-tv/media-settings/image — protected (auth:api, multipart).
     * Upload satu gambar untuk slideshow (global ATAU per-TV). Hanya menyimpan
     * file & mengembalikan URL-nya; penyusunan daftar slide dilakukan FE lalu
     * dikirim balik via update() (global) atau update device (per-TV). Dengan
     * begitu gambar yang sama bisa dipakai TV mana pun tanpa kolom per-target.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            // 10 MB cukup untuk foto promo resolusi tinggi.
            'image' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:10240',
        ]);

        $path = $request->file('image')->store('tv-media/images', 'public');
        $url  = Storage::disk('public')->url($path);

        return response()->json([
            'success' => true,
            'data'    => ['url' => $url, 'path' => $path],
            'message' => 'Gambar terunggah.',
        ]);
    }
}
