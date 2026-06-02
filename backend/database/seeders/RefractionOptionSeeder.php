<?php

namespace Database\Seeders;

use App\Models\RefractionOption;
use Illuminate\Database\Seeder;

/**
 * RefractionOptionSeeder — default master OPSI REFRAKSI untuk combobox RefraksionisView.
 *
 * Default (bisa disesuaikan admin RO via UI Master Data):
 *   - sphere      : RANGE -25.00 .. +25.00 step 0.25, format signed_diopter
 *   - cylinder    : RANGE -25.00 .. +25.00 step 0.25, format signed_diopter
 *   - axis        : RANGE 0 .. 180 step 5, format plain
 *   - keratometri : RANGE 30.00 .. 60.00 step 0.25, format plain
 *   - add         : RANGE 0.00 .. 4.00 step 0.25, format signed_diopter
 *   - visus       : LIST (6/6 … NLP), format plain
 *
 * IDEMPOTEN via kind (firstOrCreate). Jalankan ulang aman; TIDAK menimpa
 * penyesuaian admin yang sudah ada (hanya membuat baris yang belum ada).
 *
 * Jalankan: php artisan db:seed --class=RefractionOptionSeeder
 */
class RefractionOptionSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'kind' => 'sphere', 'label' => 'Sphere (S)', 'mode' => 'range', 'format' => 'signed_diopter',
                'min_value' => -25.00, 'max_value' => 25.00, 'step' => 0.25, 'values' => null,
            ],
            [
                'kind' => 'cylinder', 'label' => 'Cylinder (C)', 'mode' => 'range', 'format' => 'signed_diopter',
                'min_value' => -25.00, 'max_value' => 25.00, 'step' => 0.25, 'values' => null,
            ],
            [
                'kind' => 'axis', 'label' => 'Axis (X)', 'mode' => 'range', 'format' => 'plain',
                'min_value' => 0, 'max_value' => 180, 'step' => 5, 'values' => null,
            ],
            [
                'kind' => 'keratometri', 'label' => 'Keratometri (K1/K2)', 'mode' => 'range', 'format' => 'plain',
                'min_value' => 30.00, 'max_value' => 60.00, 'step' => 0.25, 'values' => null,
            ],
            [
                'kind' => 'add', 'label' => 'Addisi (ADD)', 'mode' => 'range', 'format' => 'signed_diopter',
                'min_value' => 0.00, 'max_value' => 4.00, 'step' => 0.25, 'values' => null,
            ],
            [
                'kind' => 'visus', 'label' => 'Visus (Snellen)', 'mode' => 'list', 'format' => 'plain',
                'min_value' => null, 'max_value' => null, 'step' => null,
                'values' => ['6/6', '6/7.5', '6/9', '6/12', '6/15', '6/18', '6/24', '6/36', '6/60', '3/60', '2/60', '1/60', 'CF', 'HM', 'LP', 'NLP'],
            ],
            // Pinhole TERPISAH dari Visus: pakai notasi Snellen ketajaman yg dicapai
            // lewat pinhole, TANPA HM/LP/NLP (tak relevan untuk uji pinhole).
            [
                'kind' => 'pinhole', 'label' => 'Pinhole (Snellen)', 'mode' => 'list', 'format' => 'plain',
                'min_value' => null, 'max_value' => null, 'step' => null,
                'values' => ['6/6', '6/7.5', '6/9', '6/12', '6/15', '6/18', '6/24', '6/36', '6/60', '3/60', '2/60', '1/60', 'CF'],
            ],
        ];

        foreach ($defaults as $d) {
            RefractionOption::firstOrCreate(
                ['kind' => $d['kind']],
                array_merge($d, ['is_active' => true]),
            );
        }

        // Migrasi default lama → baru utk 'visus': baris yg masih memakai daftar
        // default LAMA (12 nilai, tanpa CF/6-7.5/2-60) di-upgrade ke daftar baru.
        // Baris yg sudah disesuaikan admin TIDAK disentuh.
        $oldVisus = ['6/6', '6/9', '6/12', '6/18', '6/24', '6/36', '6/60', '3/60', '1/60', 'HM', 'LP', 'NLP'];
        $visus = RefractionOption::where('kind', 'visus')->first();
        if ($visus && $visus->values === $oldVisus) {
            $visus->update([
                'values' => ['6/6', '6/7.5', '6/9', '6/12', '6/15', '6/18', '6/24', '6/36', '6/60', '3/60', '2/60', '1/60', 'CF', 'HM', 'LP', 'NLP'],
            ]);
            $this->command?->info('  → visus default lama di-upgrade ke daftar baru (16 nilai).');
        }

        $this->command?->info('RefractionOptionSeeder: ' . count($defaults) . ' opsi refraksi default siap.');
    }
}
