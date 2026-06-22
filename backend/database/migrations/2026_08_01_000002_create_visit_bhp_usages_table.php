<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemakaian BHP yang DIINPUT DOKTER pada kunjungan non-bedah (Tab Tindakan/Resep) —
 * paralel `visit_services` untuk tindakan. Sebelumnya BHP hanya bisa ditagih lewat
 * `surgery_requests` (alur bedah), jadi dokter rawat jalan tak bisa menagih BHP yang
 * dipakai (mis. spuit/kasa untuk injeksi/prosedur kecil). Tabel ini jadi SUMBER yang
 * dibaca KasirService::buildBhpLines → tertagih & tahan rebuild kwitansi.
 *
 * Stok unit FARMASI dipotong SEKETIKA saat dokter input (BHP dipakai di pelayanan),
 * rincian batch disimpan di consumed_batches untuk pengembalian presisi saat dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_bhp_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('bhp_item_id')->constrained('bhp_items');
            $table->uuid('performed_by_id')->nullable();   // employee penginput (dokter)
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);   // snapshot harga saat input (referensi)
            $table->json('consumed_batches')->nullable();        // [{batch_no,expiry_date,qty}] utk restock presisi
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_bhp_usages');
    }
};
