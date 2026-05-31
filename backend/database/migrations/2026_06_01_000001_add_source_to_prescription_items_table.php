<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            // Asal item: RESEP (dari resep dokter) | TAMBAHAN (obat tambahan apotek / OTC).
            $table->string('source', 20)->default('RESEP')->after('medication_id');
            // Petugas farmasi yang menambahkan item TAMBAHAN (audit). NULL untuk item resep dokter.
            $table->foreignUuid('added_by_id')->nullable()->after('source')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropForeign(['added_by_id']);
            $table->dropColumn(['added_by_id', 'source']);
        });
    }
};
