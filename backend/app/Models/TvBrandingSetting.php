<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton branding untuk AntreanTVView — logo + teks identitas klinik
 * (bar atas & panel placeholder). Semua TV pakai setting ini.
 * Diakses via TvBrandingSetting::singleton() agar selalu ada row (auto-create).
 */
class TvBrandingSetting extends Model
{
    protected $fillable = [
        'logo_data',
        'clinic_name',
        'clinic_subtitle',
        'placeholder_title',
        'placeholder_tagline',
    ];

    public static function singleton(): self
    {
        return static::firstOrCreate(['id' => 1], static::defaults());
    }

    public static function defaults(): array
    {
        return [
            'logo_data'           => null,
            'clinic_name'         => 'Klinik Mata Arunika',
            'clinic_subtitle'     => 'Cilegon · Layar Antrean',
            'placeholder_title'   => 'Klinik Mata Arunika Cilegon',
            'placeholder_tagline' => 'Spesialis kesehatan mata terpadu — PMK No. 24/2022',
        ];
    }
}
