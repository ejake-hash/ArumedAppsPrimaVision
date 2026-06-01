<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom `priority` untuk triase berlevel IGD (bukan FIFO).
     *
     * Konvensi: makin KECIL angka = makin gawat / didahulukan.
     *   1 = MERAH (resusitasi/emergent), 2 = KUNING (urgent), 3 = HIJAU (non-urgent),
     *   4 = HITAM (DOA/expectant). RANAP & RAJAL = 0 (netral, urutan tetap queue_sequence).
     *
     * Default 0 → row antrean lama otomatis valid & urutan rawat jalan TIDAK berubah
     * (sort sekunder tetap queue_sequence).
     */
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->smallInteger('priority')->default(0)->after('queue_number');
            $table->index(['station', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->dropIndex(['station', 'priority']);
            $table->dropColumn('priority');
        });
    }
};
