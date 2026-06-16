<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * TV terdaftar (lihat migration create_tv_devices_table). Menyimpan override
 * media per-perangkat. Bila `media_synced` true, TV ikut media global
 * (TvMediaSetting singleton); bila false, pakai kolom di model ini.
 */
class TvDevice extends Model
{
    use HasUuids;

    protected $fillable = [
        'device_key',
        'name',
        'media_synced',
        'media_mode',
        'youtube_embed_url',
        'video_autoplay',
        'video_loop',
        'local_video_path',
        'external_video_url',
        'slides',
        'slide_interval',
        'slide_scope',
        'flash_over_fullscreen',
        'last_seen_at',
    ];

    protected $casts = [
        'media_synced'          => 'boolean',
        'video_autoplay'        => 'boolean',
        'video_loop'            => 'boolean',
        'slides'                => 'array',
        'slide_interval'        => 'integer',
        'flash_over_fullscreen' => 'boolean',
        'last_seen_at'          => 'datetime',
    ];

    /**
     * Media efektif untuk TV ini: bila synced → media global; bila tidak →
     * override perangkat. Bentuk identik dengan TvMediaSettingController::serialize
     * supaya AntreanTVView.applyMediaPayload() bisa pakai apa adanya.
     */
    public function effectiveMedia(): array
    {
        if ($this->media_synced) {
            return array_merge(self::serializeGlobal(), [
                'synced'      => true,
                'device_key'  => $this->device_key,
                'device_name' => $this->name,
            ]);
        }

        $videoUrl = $this->external_video_url
            ?: ($this->local_video_path ? Storage::disk('public')->url($this->local_video_path) : null);
        $videoName = $this->external_video_url
            ? basename(parse_url($this->external_video_url, PHP_URL_PATH) ?: 'video-eksternal')
            : ($this->local_video_path ? basename($this->local_video_path) : null);

        // ticker tetap dari global (info klinik berlaku semua TV).
        $global = self::serializeGlobal();

        return [
            'media_mode'            => $this->media_mode,
            'youtube_embed_url'     => $this->youtube_embed_url,
            'video_autoplay'        => (bool) $this->video_autoplay,
            'video_loop'            => (bool) $this->video_loop,
            'local_video_url'       => $videoUrl,
            'local_video_name'      => $videoName,
            'external_video_url'    => $this->external_video_url,
            'has_uploaded_file'     => (bool) $this->local_video_path,
            'slides'                => $this->slides ?? [],
            'slide_interval'        => (int) $this->slide_interval,
            'slide_scope'           => $this->slide_scope ?: 'panel',
            'flash_over_fullscreen' => (bool) $this->flash_over_fullscreen,
            'ticker_messages'       => $global['ticker_messages'],
            'synced'                => false,
            'device_key'            => $this->device_key,
            'device_name'           => $this->name,
        ];
    }

    /** Serialisasi media global (singleton) dalam bentuk payload AntreanTVView. */
    public static function serializeGlobal(): array
    {
        $row = TvMediaSetting::singleton();
        $videoUrl = $row->external_video_url
            ?: ($row->local_video_path ? Storage::disk('public')->url($row->local_video_path) : null);
        $videoName = $row->external_video_url
            ? basename(parse_url($row->external_video_url, PHP_URL_PATH) ?: 'video-eksternal')
            : ($row->local_video_path ? basename($row->local_video_path) : null);

        return [
            'media_mode'            => $row->media_mode,
            'youtube_embed_url'     => $row->youtube_embed_url,
            'video_autoplay'        => (bool) $row->video_autoplay,
            'video_loop'            => (bool) $row->video_loop,
            'local_video_url'       => $videoUrl,
            'local_video_name'      => $videoName,
            'external_video_url'    => $row->external_video_url,
            'has_uploaded_file'     => (bool) $row->local_video_path,
            'slides'                => $row->slides ?? [],
            'slide_interval'        => (int) $row->slide_interval,
            'slide_scope'           => $row->slide_scope ?: 'panel',
            'flash_over_fullscreen' => (bool) $row->flash_over_fullscreen,
            'ticker_messages'       => $row->ticker_messages ?? TvMediaSetting::defaultTickerMessages(),
        ];
    }

    /** Ringkasan untuk daftar TV di panel kontrol (tab Media). */
    public function toListItem(): array
    {
        return [
            'id'           => $this->id,
            'device_key'   => $this->device_key,
            'name'         => $this->name,
            'media_synced' => (bool) $this->media_synced,
            'media_mode'   => $this->media_synced ? 'global' : $this->media_mode,
            'slide_scope'  => $this->slide_scope ?: 'panel',
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'online'       => $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(2)),
        ];
    }
}
