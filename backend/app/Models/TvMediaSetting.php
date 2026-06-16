<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton media untuk AntreanTVView — apa yang ditampilkan di panel video
 * (placeholder/YouTube/video lokal/slideshow). Operator atur dari panel
 * kontrol di TV (atau komputer), TV lain subscribe broadcast TvMediaUpdated.
 */
class TvMediaSetting extends Model
{
    protected $fillable = [
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
        'ticker_messages',
    ];

    protected $casts = [
        'video_autoplay'        => 'boolean',
        'video_loop'            => 'boolean',
        'slides'                => 'array',
        'slide_interval'        => 'integer',
        'flash_over_fullscreen' => 'boolean',
        'ticker_messages'       => 'array',
    ];

    public static function singleton(): self
    {
        return static::firstOrCreate(['id' => 1], static::defaults());
    }

    public static function defaults(): array
    {
        return [
            'media_mode'         => 'placeholder',
            'youtube_embed_url'  => null,
            'video_autoplay'     => true,
            'video_loop'         => true,
            'local_video_path'   => null,
            'external_video_url' => null,
            'slides'             => [],
            'slide_interval'     => 8,
            'slide_scope'        => 'panel',
            'flash_over_fullscreen' => true,
            'ticker_messages'    => static::defaultTickerMessages(),
        ];
    }

    /**
     * Pesan running text bawaan — dipakai saat singleton baru dibuat dan sebagai
     * fallback saat kolom masih null (baris lama sebelum migrasi ticker).
     */
    public static function defaultTickerMessages(): array
    {
        return [
            'Pendaftaran dibuka pukul 07.00 WIB',
            'Harap siapkan kartu BPJS, KTP, dan rujukan asli',
            'Layanan Bedah Phaco buka Senin–Sabtu',
            'Untuk pertanyaan hubungi loket informasi',
        ];
    }
}
