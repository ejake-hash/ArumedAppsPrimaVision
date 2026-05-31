<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPRI (Surat Perintah Rawat Inap) BPJS untuk pasien rawat inap.
 *
 * Berbeda dari bpjs_control_letters (Surat Kontrol rawat jalan): SPRI dibuat
 * dari modul RANAP via VClaim /RencanaKontrol/InsertSPRI. no_spri terisi setelah
 * submit sukses. Persistensi lokal supaya bisa dilihat/diedit ulang di tab History.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_spri', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('no_spri', 50)->unique()->nullable(); // terisi setelah VClaim submit sukses
            $table->date('tgl_rencana');                          // tglRencana yang dikirim ke VClaim
            $table->string('poli_kontrol', 10)->nullable();       // kode BPJS poli kontrol (resolved)
            $table->string('kode_dokter', 20)->nullable();        // bpjs_dpjp_code DPJP (resolved)
            $table->string('status', 20)->default('DRAFT');       // DRAFT / SUCCESS / FAILED
            $table->jsonb('vclaim_response')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('status');
            $table->index('tgl_rencana');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_spri');
    }
};
