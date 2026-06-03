<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alur bedah perioperatif (PAB STARKES 2024 / WHO Surgical Safety Checklist).
 *
 * Tambah 3 kolom JSONB ke surgery_records (pola sama surgery_anesthesia_reports.form_data):
 *   - safety_checklist     : { sign_in:{}, time_out:{}, sign_out:{}, bypass:{} }  (3 gerbang WHO)
 *   - operation_report     : laporan operasi terstruktur (isi minimal PAB)
 *   - recovery_assessment  : skor pemulihan Aldrete + nyeri + vital (PACU)
 *
 * AMAN: hanya ADD kolom nullable, tak drop apa pun → tak ada risiko regresi.
 * Kolom lama (operation_notes/has_complication/complication_detail) tetap dipakai
 * sebagai ringkasan backward-compatible bagi Kasir/RME.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_records', function (Blueprint $table) {
            $table->jsonb('safety_checklist')->nullable()->after('finalized_at');
            $table->jsonb('operation_report')->nullable()->after('safety_checklist');
            $table->jsonb('recovery_assessment')->nullable()->after('operation_report');
        });
    }

    public function down(): void
    {
        Schema::table('surgery_records', function (Blueprint $table) {
            $table->dropColumn(['safety_checklist', 'operation_report', 'recovery_assessment']);
        });
    }
};
