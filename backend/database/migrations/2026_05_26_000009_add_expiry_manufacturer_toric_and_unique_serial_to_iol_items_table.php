<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iol_items', function (Blueprint $table) {
            $table->string('manufacturer', 255)->nullable()->after('brand');
            $table->decimal('cylinder', 5, 2)->nullable()->after('power');     // diopter cylinder (untuk TORIC)
            $table->integer('axis')->nullable()->after('cylinder');            // 0-180 derajat (untuk TORIC)
            $table->date('expiry_date')->nullable()->after('gs1_barcode');
        });

        // Partial unique pada serial_number (hanya unique untuk row dengan serial_number NOT NULL
        // dan tidak soft-deleted). Postgres mendukung partial index via raw SQL.
        DB::statement('CREATE UNIQUE INDEX iol_items_serial_number_unique
            ON iol_items (serial_number)
            WHERE serial_number IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS iol_items_serial_number_unique');

        Schema::table('iol_items', function (Blueprint $table) {
            $table->dropColumn(['manufacturer', 'cylinder', 'axis', 'expiry_date']);
        });
    }
};
