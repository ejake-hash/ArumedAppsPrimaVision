<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kotak masuk hasil penunjang yang TAK bisa dicocokkan otomatis oleh bridge/watcher
 * (accession tak ketemu, USG tanpa No.RM, atau No.RM ambigu). Operator penunjang
 * menautkannya manual ke order yang benar lewat tab Inbox di PenunjangView.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penunjang_ingest_inbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('attachment_path', 500);                 // file sudah tersimpan di disk public
            $table->string('source', 20)->default('OCT');           // OCT | USG_WATCHER
            $table->string('accession_number', 16)->nullable();     // dikirim bridge tapi tak match
            $table->string('claimed_no_rm', 50)->nullable();        // No.RM dari nama file (USG) yang tak resolve
            $table->string('original_filename', 255)->nullable();
            $table->string('external_ref', 191)->nullable();        // idempotensi (study/SOP UID) — anti dobel
            $table->string('status', 20)->default('UNMATCHED');     // UNMATCHED | ASSIGNED | DISCARDED
            $table->foreignUuid('assigned_order_id')->nullable()->constrained('diagnostic_orders')->nullOnDelete();
            $table->foreignUuid('assigned_by_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('external_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penunjang_ingest_inbox');
    }
};
