<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TvDisplaySetting extends Model
{
    protected $fillable = [
        'station',
        'tts_template',
        'flash_label_top',
        'flash_badge_text',
        'custom_poli_label',
        'show_name_in_flash',
        'show_poly_in_flash',
        'show_name_in_card',
        'show_poly_in_card',
        'read_name_in_tts',
    ];

    protected $casts = [
        'show_name_in_flash' => 'boolean',
        'show_poly_in_flash' => 'boolean',
        'show_name_in_card'  => 'boolean',
        'show_poly_in_card'  => 'boolean',
        'read_name_in_tts'   => 'boolean',
    ];

    /**
     * Default per-stasiun. Dipakai oleh seeder + sebagai fallback kalau row
     * untuk stasiun tertentu belum ada di DB.
     *
     * Variabel template: {nomor}, {nama}, {poli}, {stasiun}
     */
    public static function defaults(): array
    {
        return [
            'ADMISI' => [
                'tts_template'       => 'Nomor antrean {nomor}, dipanggil ke {poli}.',
                'flash_label_top'    => 'Nomor Antrean Dipanggil',
                'flash_badge_text'   => 'Dipanggil ke {poli}',
                'custom_poli_label'  => 'Loket Admisi',
                'show_name_in_flash' => false,
                'show_poly_in_flash' => true,
                'show_name_in_card'  => false,
                'show_poly_in_card'  => false,
                'read_name_in_tts'   => false,
            ],
            'TRIASE' => [
                'tts_template'       => 'Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}.',
                'flash_label_top'    => 'Nomor Antrean Dipanggil',
                'flash_badge_text'   => 'Silakan menuju {poli}',
                'custom_poli_label'  => 'Ruang Triase Perawat',
                'show_name_in_flash' => true,
                'show_poly_in_flash' => true,
                'show_name_in_card'  => true,
                'show_poly_in_card'  => false,
                'read_name_in_tts'   => true,
            ],
            'REFRAKSIONIS' => [
                'tts_template'       => 'Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}.',
                'flash_label_top'    => 'Nomor Antrean Dipanggil',
                'flash_badge_text'   => 'Silakan menuju {poli}',
                'custom_poli_label'  => 'Ruang Refraksionis',
                'show_name_in_flash' => true,
                'show_poly_in_flash' => true,
                'show_name_in_card'  => true,
                'show_poly_in_card'  => false,
                'read_name_in_tts'   => true,
            ],
            'DOKTER' => [
                'tts_template'       => 'Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}.',
                'flash_label_top'    => 'Nomor Antrean Dipanggil',
                'flash_badge_text'   => 'Silakan menuju {poli}',
                'custom_poli_label'  => null, // auto dari jadwal dokter
                'show_name_in_flash' => true,
                'show_poly_in_flash' => true,
                'show_name_in_card'  => true,
                'show_poly_in_card'  => true,
                'read_name_in_tts'   => true,
            ],
            'PENUNJANG' => [
                'tts_template'       => 'Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}.',
                'flash_label_top'    => 'Nomor Antrean Dipanggil',
                'flash_badge_text'   => 'Silakan menuju {poli}',
                'custom_poli_label'  => 'Ruang Penunjang',
                'show_name_in_flash' => true,
                'show_poly_in_flash' => true,
                'show_name_in_card'  => true,
                'show_poly_in_card'  => false,
                'read_name_in_tts'   => true,
            ],
            'BEDAH' => [
                'tts_template'       => 'Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}.',
                'flash_label_top'    => 'Nomor Antrean Dipanggil',
                'flash_badge_text'   => 'Silakan menuju {poli}',
                'custom_poli_label'  => 'Ruang Operasi',
                'show_name_in_flash' => true,
                'show_poly_in_flash' => true,
                'show_name_in_card'  => true,
                'show_poly_in_card'  => false,
                'read_name_in_tts'   => true,
            ],
            'KASIR' => [
                'tts_template'       => 'Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}.',
                'flash_label_top'    => 'Nomor Antrean Dipanggil',
                'flash_badge_text'   => 'Silakan menuju {poli}',
                'custom_poli_label'  => 'Loket Kasir',
                'show_name_in_flash' => true,
                'show_poly_in_flash' => true,
                'show_name_in_card'  => true,
                'show_poly_in_card'  => false,
                'read_name_in_tts'   => true,
            ],
            'FARMASI' => [
                'tts_template'       => 'Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}.',
                'flash_label_top'    => 'Nomor Antrean Dipanggil',
                'flash_badge_text'   => 'Silakan menuju {poli}',
                'custom_poli_label'  => 'Apotek',
                'show_name_in_flash' => true,
                'show_poly_in_flash' => true,
                'show_name_in_card'  => true,
                'show_poly_in_card'  => false,
                'read_name_in_tts'   => true,
            ],
        ];
    }
}
