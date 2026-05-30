<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_records', function (Blueprint $table) {
            // Penanda laporan operasi sudah dikunci. Sebelumnya tidak ada kolom
            // ini sehingga finalizeRecord tak punya efek nyata; advance antrean ke
            // Farmasi/Kasir kini terjadi saat finalize (bukan saat Time Out).
            $table->timestamp('finalized_at')->nullable()->after('followup_date');
        });
    }

    public function down(): void
    {
        Schema::table('surgery_records', function (Blueprint $table) {
            $table->dropColumn('finalized_at');
        });
    }
};
