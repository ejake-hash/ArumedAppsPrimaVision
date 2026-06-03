<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * cleanup:demo-users — buang akun & pegawai DEMO bawaan seeder dev,
 * sisakan superadmin. Untuk membersihkan DB sebelum go-live / saat
 * sebuah instance dev mau dijadikan "kosong".
 *
 * AMAN:
 *  - Pakai SoftDeletes (User & Employee) → data tidak hilang permanen,
 *    relasi (pasien, pemeriksaan) tidak rusak; bisa di-restore.
 *  - Whitelist eksplisit by username/nip → user/pegawai ASLI yang Anda
 *    input manual TIDAK ikut terhapus walau command dijalankan ulang.
 *  - superadmin TIDAK PERNAH disentuh.
 *
 * Pakai:
 *   php artisan cleanup:demo-users           (tanya konfirmasi)
 *   php artisan cleanup:demo-users --force    (langsung jalan)
 */
class CleanupDemoUsers extends Command
{
    protected $signature = 'cleanup:demo-users {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Hapus (soft-delete) user & pegawai demo bawaan seeder, sisakan superadmin';

    /** Username demo dari UserSeeder (superadmin sengaja TIDAK didaftar). */
    private const DEMO_USERNAMES = [
        'dokter', 'dokter2', 'dokter3', 'dokter_umum', 'dr_anes',
        'perawat', 'refraksionis', 'penunjang', 'farmasi',
        'kasir', 'verifikator', 'admisi',
    ];

    /** NIP pegawai demo dari EmployeeSeeder + demo lain. */
    private const DEMO_NIPS = [
        'EMP-DOK-001', 'EMP-DOK-002', 'EMP-DOK-003',
        'EMP-PER-001', 'EMP-REF-001', 'EMP-ADM-001',
        'DEMO-APT-01',
    ];

    /** Pegawai demo TANPA NIP (seeder anestesi) — hanya bisa dikenali by nama. */
    private const DEMO_NAMES = [
        'dr. Rahmat Hidayat, Sp.An',
        'dr Anastesi',
    ];

    public function handle(): int
    {
        $users = User::whereIn('username', self::DEMO_USERNAMES)->get();
        $emps  = Employee::where(function ($q) {
            $q->whereIn('nip', self::DEMO_NIPS)
              ->orWhereIn('name', self::DEMO_NAMES);
        })->get();

        if ($users->isEmpty() && $emps->isEmpty()) {
            $this->info('Tidak ada user/pegawai demo yang tersisa. DB sudah bersih.');
            return self::SUCCESS;
        }

        $this->warn('Akan di-hapus (soft-delete):');
        $this->line('  User    : ' . ($users->isEmpty() ? '-' : $users->pluck('username')->implode(', ')));
        $this->line('  Pegawai : ' . ($emps->isEmpty() ? '-' : $emps->map(fn ($e) => $e->nip ?: $e->name)->implode(', ')));
        $this->newLine();
        $this->info('superadmin & data asli (pasien, tarif, dll) TIDAK disentuh.');

        if (!$this->option('force') && !$this->confirm('Lanjutkan?', false)) {
            $this->line('Dibatalkan.');
            return self::SUCCESS;
        }

        $uCount = 0;
        foreach ($users as $u) {
            $u->delete();   // soft delete
            $uCount++;
        }

        $eCount = 0;
        foreach ($emps as $e) {
            $e->delete();   // soft delete
            $eCount++;
        }

        $this->newLine();
        $this->info("Selesai: {$uCount} user + {$eCount} pegawai demo di-soft-delete.");
        $this->line('Sisa user aktif: ' . User::count() . ' | pegawai aktif: ' . Employee::count());
        $this->newLine();
        $this->comment('Pulihkan bila perlu: User::withTrashed()->where(...)->restore()');

        return self::SUCCESS;
    }
}
