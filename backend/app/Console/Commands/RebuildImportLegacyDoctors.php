<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Buat record employee NON-AKTIF untuk dokter LAMA Prima Vision yang sudah keluar
 * tetapi punya banyak data historis (pemeriksaan + resep) di runningprima.
 *
 * Tujuan: atribusi DPJP historis (doctor_examinations.doctor_id, prescriptions.prescribed_by_id)
 * tetap AKURAT saat inject data klinis. Mereka is_active=false → tidak muncul di
 * Jadwal Dokter / antrean / picker, tanpa akun login/PIN (attribution-only).
 *
 * Dipetakan dari nama sumber via `Docs/migrasi data/dokter-alias.csv` (nama di sini
 * HARUS sama persis dengan kolom `arumed` di alias).
 *
 * Idempotent: updateOrCreate by name. DEV/REHEARSAL ONLY (tolak production/arumed_primavision).
 *
 * Contoh:
 *   php artisan rebuild:import-legacy-doctors            # dry-run
 *   php artisan rebuild:import-legacy-doctors --force    # eksekusi
 */
class RebuildImportLegacyDoctors extends Command
{
    protected $signature = 'rebuild:import-legacy-doctors {--force : Apply (default: dry-run preview)}';

    protected $description = 'Buat 5 dokter LAMA (non-aktif) untuk atribusi data klinis historis. DEV ONLY.';

    /** Dokter lama (sudah keluar) + jenis. Nama HARUS cocok kolom `arumed` di dokter-alias.csv. */
    private array $doctors = [
        ['name' => 'dr. Sujan Ali Fing, Sp.M',          'profession' => 'Dokter Spesialis Mata', 'doctor_type' => Employee::DT_SPESIALIS_MATA],
        ['name' => 'dr. Monika Ayuningrum, Sp.M',        'profession' => 'Dokter Spesialis Mata', 'doctor_type' => Employee::DT_SPESIALIS_MATA],
        ['name' => 'dr. Callina F.Y. Br. Bangun, Sp.M',  'profession' => 'Dokter Spesialis Mata', 'doctor_type' => Employee::DT_SPESIALIS_MATA],
        ['name' => 'dr. Annisa Putri, Sp.M',             'profession' => 'Dokter Spesialis Mata', 'doctor_type' => Employee::DT_SPESIALIS_MATA],
        ['name' => 'dr. Davis Pratama',                  'profession' => 'Dokter Umum',           'doctor_type' => Employee::DT_UMUM],
    ];

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $db === 'arumed_primavision') {
            $this->error("REFUSED: must not run on production / arumed_primavision (db={$db}).");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $this->info("DB={$db}  MODE=" . ($force ? 'FORCE' : 'DRY-RUN'));

        $created = 0;
        $updated = 0;
        foreach ($this->doctors as $d) {
            $existing = Employee::withTrashed()->where('name', $d['name'])->first();
            $this->line(sprintf('  %-38s %-22s %s', $d['name'], $d['doctor_type'], $existing ? '(sudah ada)' : '(BARU)'));
            if (! $force) {
                continue;
            }
            $emp = Employee::updateOrCreate(
                ['name' => $d['name']],
                [
                    'profession'  => $d['profession'],
                    'doctor_type' => $d['doctor_type'],
                    'is_active'   => false, // non-aktif: attribution-only
                ]
            );
            $existing ? $updated++ : $created++;
        }

        if (! $force) {
            $this->warn("\nDRY-RUN — belum ada yang dibuat. Jalankan dgn --force untuk eksekusi.");

            return self::SUCCESS;
        }

        $this->info("\nDONE. Dibuat: {$created}  Diupdate: {$updated}  Total employees: " . Employee::count());

        return self::SUCCESS;
    }
}
