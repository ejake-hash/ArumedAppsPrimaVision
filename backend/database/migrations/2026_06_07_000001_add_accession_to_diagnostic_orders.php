<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accession number untuk order penunjang — kunci pencocokan DICOM Modality Worklist.
 *
 * DICOM AccessionNumber maks 16 char; id order = UUID 36 char (tak muat). Maka kita
 * generate kode pendek `A` + base36(nextval sequence) — atomik (anti-race), unik.
 * Dipakai feeder worklist (alat tampilkan pasien) + bridge ingest (cocokkan hasil).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_orders', function (Blueprint $table) {
            $table->string('accession_number', 16)->nullable()->unique()->after('test_type');
        });

        // Sequence atomik untuk generate accession (lihat AccessionService::next()).
        DB::statement('CREATE SEQUENCE IF NOT EXISTS diagnostic_order_accession_seq');

        // Backfill order yang masih terbuka agar worklist tak kosong di hari pertama.
        $open = DB::table('diagnostic_orders')
            ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
            ->whereNull('accession_number')
            ->pluck('id');

        foreach ($open as $id) {
            $n   = (int) DB::selectOne("SELECT nextval('diagnostic_order_accession_seq') AS v")->v;
            $acc = 'A' . str_pad(strtoupper(base_convert((string) $n, 10, 36)), 7, '0', STR_PAD_LEFT);
            DB::table('diagnostic_orders')->where('id', $id)->update(['accession_number' => $acc]);
        }
    }

    public function down(): void
    {
        Schema::table('diagnostic_orders', function (Blueprint $table) {
            $table->dropUnique(['accession_number']);
            $table->dropColumn('accession_number');
        });

        DB::statement('DROP SEQUENCE IF EXISTS diagnostic_order_accession_seq');
    }
};
