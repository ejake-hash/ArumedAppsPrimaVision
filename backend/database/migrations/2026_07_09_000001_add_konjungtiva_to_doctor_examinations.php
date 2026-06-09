<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah field segmen anterior `Konjungtiva` (OD/OS) pada pemeriksaan mata dokter.
 *
 * Urutan UI segmen anterior: Palpebra → Konjungtiva → Kornea → COA → Iris → Pupil
 * → Lensa. Kolom text bebas (dokter ketik temuan sendiri), nullable → prod-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->text('sa_konjungtiva_od')->nullable()->after('sa_palpebra_os');
            $table->text('sa_konjungtiva_os')->nullable()->after('sa_konjungtiva_od');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_examinations', function (Blueprint $table) {
            $table->dropColumn(['sa_konjungtiva_od', 'sa_konjungtiva_os']);
        });
    }
};
