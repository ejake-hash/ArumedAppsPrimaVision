<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpan data terstruktur hasil parser alat (mis. biometri Quantel: AL/K/tabel IOL)
 * pada baris Inbox. Sebelumnya, hasil yang gagal cocok otomatis masuk Inbox HANYA
 * sebagai file .jpg — saat operator menautkannya manual, blok biometri parsed HILANG
 * karena tak ikut tersimpan. Kolom ini menahan patch expertise_data agar bisa
 * diterapkan kembali ke order saat assign (lihat PenunjangIngestService + assignInbox).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penunjang_ingest_inbox', function (Blueprint $table) {
            if (! Schema::hasColumn('penunjang_ingest_inbox', 'parsed_expertise')) {
                $table->jsonb('parsed_expertise')->nullable()->after('external_ref');
            }
        });
    }

    public function down(): void
    {
        Schema::table('penunjang_ingest_inbox', function (Blueprint $table) {
            if (Schema::hasColumn('penunjang_ingest_inbox', 'parsed_expertise')) {
                $table->dropColumn('parsed_expertise');
            }
        });
    }
};
