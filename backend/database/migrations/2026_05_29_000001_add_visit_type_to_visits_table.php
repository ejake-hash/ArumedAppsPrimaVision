<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // REGULAR | PREOP_BEDAH
            $table->string('visit_type', 20)->default('REGULAR')->after('classification');

            $table->foreignUuid('surgery_schedule_id')
                ->nullable()
                ->after('visit_type')
                ->constrained('surgery_schedules')
                ->nullOnDelete();

            $table->index('visit_type');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['surgery_schedule_id']);
            $table->dropIndex(['visit_type']);
            $table->dropColumn(['surgery_schedule_id', 'visit_type']);
        });
    }
};
