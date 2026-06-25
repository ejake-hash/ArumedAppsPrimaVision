<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Status pengiriman rekam medis ke BPJS per kunjungan (idempotensi per noSEP).
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('bpjs_rm_status', 20)->nullable()->after('no_sep'); // PENDING | SENT | FAILED
            $table->timestamp('bpjs_rm_sent_at')->nullable()->after('bpjs_rm_status');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['bpjs_rm_status', 'bpjs_rm_sent_at']);
        });
    }
};
