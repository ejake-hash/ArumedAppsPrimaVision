<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * surgery_packages.surgery_type — klasifikasi klinis paket bedah (enum aplikasi:
 * KATARAK / VITREORETINA / GLAUKOMA / LAINNYA). BERBEDA dari `category` yang
 * free-text untuk label/filter tarif; surgery_type adalah PENENTU FORM resmi:
 * VITREORETINA -> Laporan Operasi Vitreo Retina (RM 10.1), KATARAK -> Catatan
 * Operasi (RM 2.3), dst. Backfill = best-guess dari nama paket; admin dapat ubah
 * lewat UI master paket bedah (sumber kebenaran = kolom eksplisit, bukan nama).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('surgery_packages', 'surgery_type')) {
            Schema::table('surgery_packages', function (Blueprint $table) {
                $table->string('surgery_type', 30)->nullable()->after('category');
            });
        }

        // Backfill auto-saran dari nama paket. Urutan PENTING: VITREORETINA
        // diperiksa DULU agar kasus gabungan (mis. "Phaco + Vitrektomi") jatuh
        // ke retina (butuh RM 10.1), bukan katarak. Hanya mengisi baris yang
        // surgery_type-nya masih NULL -> idempoten (aman re-run).
        $rules = [
            'VITREORETINA' => ['vitrek', 'vitrec', 'vitreous', 'ppv', 'pars plana', 'vitreoretina', 'retina', 'buckle', 'bakel'],
            'KATARAK'      => ['phaco', 'fako', 'katarak', 'cataract', 'iol', 'sics', 'lensa intraokular'],
            'GLAUKOMA'     => ['glaukoma', 'glaucoma', 'trabekulekto', 'trabeculecto', 'trabekulo', 'iridekto', 'iridecto', 'ahmed', 'baerveldt'],
        ];

        foreach ($rules as $type => $keywords) {
            DB::table('surgery_packages')
                ->whereNull('surgery_type')
                ->where(function ($q) use ($keywords) {
                    foreach ($keywords as $kw) {
                        $q->orWhere('name', 'ilike', "%{$kw}%");
                    }
                })
                ->update(['surgery_type' => $type]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('surgery_packages', 'surgery_type')) {
            Schema::table('surgery_packages', function (Blueprint $table) {
                $table->dropColumn('surgery_type');
            });
        }
    }
};
