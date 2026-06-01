<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * AdmisiConsentTestSeeder — 1 pasien demo + 1 kunjungan aktif di stasiun ADMISI,
 * khusus untuk menguji form RM 1.1 Persetujuan Umum (General Consent).
 *
 * Pasien UMUM (tanpa BPJS/insurer) supaya tidak butuh master penjamin.
 * Identitas lengkap (Nama/NIK/Tgl Lahir/JK/No.RM) agar binding {{nama_pasien}},
 * {{nik}}, {{tgl_lahir}}, {{jenis_kelamin}}, {{no_rm}} di template terisi.
 *
 * IDEMPOTEN: firstOrCreate via NIK (pasien) + cek visit aktif harian.
 *
 * Jalankan:  php artisan db:seed --class=AdmisiConsentTestSeeder
 */
class AdmisiConsentTestSeeder extends Seeder
{
    private const NIK   = '1271099999000011';
    private const NO_RM = 'GC-TEST-01';

    public function run(): void
    {
        $patient = Patient::firstOrCreate(
            ['nik' => self::NIK],
            [
                'no_rm'         => self::NO_RM,
                'identity_type' => 'KTP',
                'name'          => 'Budi Santoso (Tes Consent)',
                'gender'        => 'L',
                'date_of_birth' => '1985-07-21',
                'tempat_lahir'  => 'Medan',
                'pekerjaan'     => 'Wiraswasta',
                'phone'         => '0812-6000-0011',
                'address'       => 'Jl. Sei Deli No. 12, Medan',
                'province'      => 'Sumatera Utara',
                'nama_kab_kota' => 'Kota Medan',
                'blood_type'    => 'O',
                'is_active'     => true,
            ]
        );

        $today = Carbon::today();

        // Cari visit aktif hari ini (belum SELESAI) — supaya idempoten & tidak
        // melanggar guard "1 visit aktif per pasien".
        $visit = Visit::query()
            ->where('patient_id', $patient->id)
            ->whereDate('visit_date', $today)
            ->where('current_station', '!=', 'SELESAI')
            ->first();

        if (! $visit) {
            $visit = Visit::create([
                'patient_id'      => $patient->id,
                'visit_date'      => $today,
                'classification'  => 'Baru',
                'current_station' => 'ADMISI',
                'guarantor_type'  => 'UMUM',
                'no_antreen'      => 'A-001',
            ]);
        }

        $this->command?->info('AdmisiConsentTestSeeder selesai:');
        $this->command?->info("  Pasien : {$patient->name} (No.RM {$patient->no_rm}, NIK {$patient->nik})");
        $this->command?->info("  Visit  : {$visit->id} | stasiun {$visit->current_station} | {$visit->guarantor_type} | {$visit->visit_date}");
        $this->command?->info('  Buka modul Admisi → cari pasien ini → form RM 1.1 General Consent siap diuji.');
    }
}
