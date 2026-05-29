<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            // BPJS | EKSEKUTIF — jenis layanan jadwal praktik
            $table->string('service_type', 20)->default('BPJS')->after('poliklinik');

            // Tanggal Senin dari minggu jadwal ini berlaku. NULL sementara untuk
            // baris lama, di-backfill di bawah lalu di-set NOT NULL.
            $table->date('week_start')->nullable()->after('service_type');
        });

        // Backfill baris lama: anggap milik minggu berjalan (Senin minggu ini, WIB).
        $currentWeekStart = Carbon::now('Asia/Jakarta')
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();

        DB::table('doctor_schedules')
            ->whereNull('week_start')
            ->update(['week_start' => $currentWeekStart]);

        // Setelah terisi, jadikan kolom wajib.
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->date('week_start')->nullable(false)->change();
        });

        Schema::table('doctor_schedules', function (Blueprint $table) {
            // Query "aktif hari ini" memfilter (week_start, day_of_week).
            $table->index(['week_start', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->dropIndex(['week_start', 'day_of_week']);
            $table->dropColumn(['service_type', 'week_start']);
        });
    }
};
