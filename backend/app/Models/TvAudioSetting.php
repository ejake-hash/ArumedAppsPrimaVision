<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings audio untuk AntreanTVView — semua TV pakai setting ini.
 * Diakses via TvAudioSetting::singleton() agar selalu ada row (auto-create).
 */
class TvAudioSetting extends Model
{
    protected $fillable = [
        'sound_preset',
        'sound_volume',
        'audio_enabled',
        'flash_duration',
        'call_delay',
        'tts_voice_name',
        'tts_rate',
    ];

    protected $casts = [
        'sound_volume'   => 'float',
        'audio_enabled'  => 'boolean',
        'flash_duration' => 'integer',
        'call_delay'     => 'integer',
        'tts_rate'       => 'float',
    ];

    public static function singleton(): self
    {
        return static::firstOrCreate(['id' => 1], static::defaults());
    }

    public static function defaults(): array
    {
        return [
            'sound_preset'   => 'chime',
            'sound_volume'   => 0.45,
            'audio_enabled'  => true,
            'flash_duration' => 5,
            'call_delay'     => 7,
            'tts_voice_name' => null,
            'tts_rate'       => 0.95,
        ];
    }
}
