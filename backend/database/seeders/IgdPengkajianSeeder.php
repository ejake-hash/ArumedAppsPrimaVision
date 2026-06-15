<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * RM 3.7 Pengkajian Gawat Darurat — Fase 3.
 * Seed DocumentType (RM-3.7-IGD) + DocumentTemplate (PENGKAJIAN_IGD_3_7).
 *
 * Template kind=OUTPUT: dokumen di-generate server-side dari igd_assessments
 * (IgdService::generatePengkajianDocument) — bukan form input generik. Seluruh
 * isi ditanam sebagai satu static field {{body}} (HTML hasil render blade
 * pdf/igd_pengkajian_body). Placeholder {{ttd_dokter}} & {{qr_verifikasi}}
 * di-embed saat finalize oleh pipeline Form Registry (TTD PIN + QR verifikasi).
 *
 * Jalankan: php artisan db:seed --class=IgdPengkajianSeeder  (idempoten).
 */
class IgdPengkajianSeeder extends Seeder
{
    public function run(): void
    {
        $type = DocumentType::updateOrCreate(
            ['code' => 'RM-3.7-IGD'],
            [
                'name'                => 'Pengkajian Gawat Darurat',
                'fill_frequency'      => 'PER_VISIT',
                'generate_type'       => 'MANUAL',
                'category'            => 'KLINIS',
                'required_signatures' => [['role' => 'DOKTER', 'sign_type' => 'digital', 'is_required' => true]],
                'show_in_rme'         => true,
                'sort_order'          => 37,
                'is_active'           => true,
            ]
        );

        $layoutHtml = <<<'HTML'
<div style="font-family:'Times New Roman',serif;font-size:12px;color:#111;line-height:1.45;">
  <table style="width:100%;border:0;border-bottom:2px solid #111;margin-bottom:10px;"><tr>
    <td style="width:72px;border:0;vertical-align:middle;">{{klinik_logo}}</td>
    <td style="border:0;vertical-align:middle;">
      <div style="font-size:18px;font-weight:700;color:#0E3A66;">{{klinik_nama}}</div>
      <div style="font-size:11px;">{{klinik_alamat}}</div>
    </td>
  </tr></table>
  <div style="text-align:center;font-size:14px;font-weight:700;text-transform:uppercase;margin:6px 0 10px;">Pengkajian Gawat Darurat (RM 3.7)</div>
  {{body}}
  <table style="width:100%;margin-top:18px;border:0;"><tr>
    <td style="width:60%;border:0;vertical-align:top;"></td>
    <td style="width:40%;border:0;text-align:center;vertical-align:top;">
      <div style="margin-bottom:4px;">Dokter Jaga IGD</div>
      <div style="min-height:90px;">{{ttd_dokter}}</div>
    </td>
  </tr></table>
  <div style="margin-top:14px;text-align:center;">{{qr_verifikasi}}</div>
</div>
HTML;

        $fields = [
            [
                'key' => 'klinik_logo', 'label' => 'Logo Klinik', 'type' => 'image_url',
                'display_only' => true, 'max_height_px' => 56,
                'binding' => ['kind' => 'clinic', 'source' => 'clinic.logo_path'],
            ],
            [
                'key' => 'klinik_nama', 'label' => 'Nama Klinik', 'type' => 'text',
                'display_only' => true, 'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name'],
            ],
            [
                'key' => 'klinik_alamat', 'label' => 'Alamat Klinik', 'type' => 'text',
                'display_only' => true, 'binding' => ['kind' => 'clinic', 'source' => 'clinic.address'],
            ],
            [
                'key'          => 'body',
                'label'        => 'Isi Pengkajian',
                'type'         => 'longtext',
                'display_only' => true,
                'binding'      => ['kind' => 'static'],
            ],
            [
                'key'         => 'ttd_dokter',
                'label'       => 'Tanda Tangan Dokter Jaga IGD',
                'type'        => 'signature_canvas',
                'signer_type' => 'doctor',
                'required'    => true,
                'binding'     => ['kind' => 'static'],
            ],
        ];

        DocumentTemplate::updateOrCreate(
            ['code' => 'PENGKAJIAN_IGD_3_7'],
            [
                'name'                => 'Pengkajian Gawat Darurat (RM 3.7)',
                'document_type_id'    => $type->id,
                'kind'                => DocumentTemplate::KIND_OUTPUT,
                'complexity_kind'     => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
                'layout_html'         => $layoutHtml,
                'field_schema'        => ['layout_mode' => 'single_page', 'fields' => $fields],
                'station_assignments' => [],
                'page_size'           => 'A4',
                'orientation'         => 'portrait',
                'version'             => 1,
                'is_active'           => true,
                'code_locked_at'      => now(),
            ]
        );

        $this->command?->info('RM 3.7 (RM-3.7-IGD / PENGKAJIAN_IGD_3_7) siap.');
    }
}
