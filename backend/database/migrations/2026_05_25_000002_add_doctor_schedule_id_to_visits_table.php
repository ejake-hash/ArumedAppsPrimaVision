<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // Wajib diisi saat admisi — pasien harus pilih dokter
            $table->foreignUuid('doctor_schedule_id')
                  ->nullable()  // nullable untuk data lama yang belum ada assignment
                  ->after('registered_by_id')
                  ->constrained('doctor_schedules')
                  ->nullOnDelete();

            $table->index('doctor_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['doctor_schedule_id']);
            $table->dropIndex(['doctor_schedule_id']);
            $table->dropColumn('doctor_schedule_id');
        });
    }
};
