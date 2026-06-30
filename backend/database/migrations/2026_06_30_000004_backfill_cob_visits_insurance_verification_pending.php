<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: COB (penjamin-1 BPJS + penjamin-2 asuransi/perusahaan) yang TERLANJUR
 * terdaftar sebelum fix tak pernah masuk antrean Verifikasi Asuransi.
 *
 * Akar: `insurance_verification_status` di-set 'PENDING' hanya bila guarantor_type
 * ASURANSI/PERUSAHAAN. Untuk COB guarantor_type='BPJS' → 'NONE' → tak muncul di modul
 * Asuransi → coverage penjamin-2 tak pernah diinput → covered_amount=0 → kasir keliru
 * menagih ekses = seluruh tagihan. Go-forward sudah diperbaiki di AdmisiService
 * (registrasi/walk-in/ubah-penjamin kini PENDING bila ada COB aktif).
 *
 * Migrasi ini hanya menyentuh COB yang MASIH BERJALAN (belum SELESAI) & masih 'NONE',
 * agar pasien in-flight (mis. yang sedang di kasir) langsung muncul di antrean Asuransi.
 * Kunjungan yang sudah SELESAI/closed sengaja TIDAK disentuh. Idempoten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('visits')
            ->where('insurance_verification_status', 'NONE')
            ->where('current_station', '<>', 'SELESAI')
            ->whereNull('deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('visit_cob as vc')
                    ->whereColumn('vc.visit_id', 'visits.id')
                    ->where('vc.is_active', true)
                    ->whereNotNull('vc.penjamin2_insurer_id')
                    ->whereNull('vc.deleted_at');
            })
            ->update(['insurance_verification_status' => 'PENDING']);
    }

    public function down(): void
    {
        // Tidak di-reverse: status verifikasi mencerminkan kebutuhan operasional COB
        // (penjamin-2 wajib diverifikasi). Tak ada nilai 'NONE' lama yang perlu dipulihkan.
    }
};
