<?php

namespace Database\Seeders;

use App\Models\Insurer;
use App\Models\Room;
use App\Models\RoomTariff;
use Illuminate\Database\Seeder;

/**
 * RoomTariffDemoSeeder — data demo Tarif Kamar (insurer-only) agar UI
 * menu Tarif → Tarif Kamar Inap tampil bervariasi (UMUM/BPJS sistem +
 * ASURANSI + PERUSAHAAN), lengkap dengan pill warna per tipe penjamin.
 *
 * Pola tarif insurer-only: identitas (room_class, insurer_id) — TANPA classification.
 * Idempoten: insurer by `code`, tarif via updateOrCreate.
 * Jalankan: php artisan db:seed --class=RoomTariffDemoSeeder
 */
class RoomTariffDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Pastikan ada room dgn kelas standar (kalau RanapDemoSeeder belum jalan) ──
        $roomDefs = [
            ['code' => 'VIP-1', 'name' => 'VIP Anggrek 1', 'kelas' => 'VIP'],
            ['code' => '201',   'name' => 'Ruang 201',     'kelas' => '1'],
            ['code' => '305',   'name' => 'Ruang 305',     'kelas' => '2'],
            ['code' => '410',   'name' => 'Ruang 410',     'kelas' => '3'],
        ];
        foreach ($roomDefs as $rd) {
            Room::firstOrCreate(
                ['code' => $rd['code']],
                ['name' => $rd['name'], 'kelas_rawat' => $rd['kelas'], 'type' => 'KAMAR', 'is_active' => true]
            );
        }

        // ── Insurer sistem (UMUM/BPJS) ──
        $umum = Insurer::where('is_system', true)->where('type', 'UMUM')->first();
        $bpjs = Insurer::where('is_system', true)->where('type', 'BPJS')->first();

        // ── Insurer demo NON-sistem: ASURANSI + PERUSAHAAN (idempoten by code) ──
        $allianz = Insurer::updateOrCreate(
            ['code' => 'ASR-ALLIANZ'],
            [
                'name'       => 'Allianz Life',
                'type'       => 'ASURANSI',
                'is_system'  => false,
                'is_active'  => true,
                'parent_id'  => null,
                'phone'      => '021-29268888',
                'email'      => 'claim@allianz.co.id',
                'sla_days'   => 14,
            ]
        );
        $perusahaan = Insurer::updateOrCreate(
            ['code' => 'PRU-SEJAHTERA'],
            [
                'name'       => 'PT Sejahtera Abadi',
                'type'       => 'PERUSAHAAN',
                'is_system'  => false,
                'is_active'  => true,
                'parent_id'  => null,
                'phone'      => '061-4567890',
                'email'      => 'hrd@sejahtera.co.id',
                'sla_days'   => 30,
            ]
        );

        // ── Matriks tarif demo: kelas × penjamin ──────────────────────────────
        // [room_class => [insurer_id => harga]]. Hanya insurer yang ada yang diisi.
        $base = ['VIP' => 800000, '1' => 500000, '2' => 350000, '3' => 200000];

        // Pembulatan ke ribuan terdekat agar angka demo rapi.
        $rb = fn (float $n) => (int) (round($n / 1000) * 1000);

        $rows = [];
        foreach ($base as $kelas => $harga) {
            if ($umum)       $rows[] = [$kelas, $umum->id,       $harga];              // UMUM = harga dasar
            if ($bpjs)       $rows[] = [$kelas, $bpjs->id,       $rb($harga * 0.9)];   // BPJS = 90%
            $rows[] = [$kelas, $allianz->id,    $rb($harga * 1.25)];                   // Asuransi = +25%
            $rows[] = [$kelas, $perusahaan->id, $rb($harga * 1.15)];                   // Perusahaan = +15%
        }

        $count = 0;
        foreach ($rows as [$kelas, $insurerId, $harga]) {
            RoomTariff::updateOrCreate(
                ['room_class' => $kelas, 'insurer_id' => $insurerId],
                ['price' => $harga, 'is_active' => true]
            );
            $count++;
        }

        $this->command?->info(
            "RoomTariffDemoSeeder selesai: 2 insurer demo (Allianz/ASURANSI + PT Sejahtera/PERUSAHAAN) + {$count} tarif kamar "
            . '(UMUM/BPJS/ASURANSI/PERUSAHAAN × VIP/1/2/3). Lihat di menu Tarif → Tarif Kamar Inap.'
        );
    }
}
