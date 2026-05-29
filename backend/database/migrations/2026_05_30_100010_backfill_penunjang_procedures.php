<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unifikasi master penunjang ke `procedures` kategori "Penunjang".
 *
 * Sebelumnya jenis penunjang hidup di `diagnostic_test_types` (OCT/USG/BIOM/dst).
 * Arsitektur baru: master = procedures kategori "Penunjang" (punya harga base,
 * tampil di Tarif Tindakan). Migration ini:
 *   1. Pastikan kategori "Penunjang" ada di procedure_categories (prefix PNJ).
 *   2. Untuk tiap diagnostic_test_types yang BELUM punya procedure dgn kode sama →
 *      buat procedure (kategori Penunjang, base_price 0, kode & nama sama).
 *   3. ProcedureObserver akan menjaga cermin diagnostic_test_types tetap sinkron.
 *
 * Idempotent: aman dijalankan ulang (cek existence by code).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1. Kategori "Penunjang" (prefix PNJ) — buat bila belum ada.
        $cat = DB::table('procedure_categories')->where('name', 'Penunjang')->first();
        if (! $cat) {
            DB::table('procedure_categories')->insert([
                'id'          => (string) Str::uuid(),
                'name'        => 'Penunjang',
                'code_prefix' => 'PNJ',
                'description' => 'Pemeriksaan penunjang diagnostik',
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // 2. Promote setiap jenis penunjang (diagnostic_test_types) jadi procedure
        //    bila belum ada procedure berkode sama.
        $types = DB::table('diagnostic_test_types')->get(['code', 'name', 'is_active']);
        foreach ($types as $t) {
            $exists = DB::table('procedures')->where('code', $t->code)->exists();
            if ($exists) {
                continue;
            }
            DB::table('procedures')->insert([
                'id'          => (string) Str::uuid(),
                'code'        => $t->code,
                'name'        => $t->name,
                'category'    => 'Penunjang',
                'base_price'  => 0,
                'is_active'   => (bool) $t->is_active,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // 2b. Arah balik: procedure kategori Penunjang yg sudah ada (mis. PNJ-001)
        //     tapi belum punya cermin → buat cermin di diagnostic_test_types.
        $penunjangProcs = DB::table('procedures')->where('category', 'Penunjang')->get(['code', 'name', 'is_active']);
        foreach ($penunjangProcs as $p) {
            $hasMirror = DB::table('diagnostic_test_types')->where('code', $p->code)->exists();
            if ($hasMirror) {
                continue;
            }
            DB::table('diagnostic_test_types')->insert([
                'id'         => (string) Str::uuid(),
                'code'       => $p->code,
                'name'       => $p->name,
                'category'   => 'Penunjang',
                'is_active'  => (bool) $p->is_active,
                'sort_order' => (int) (DB::table('diagnostic_test_types')->max('sort_order') ?? 0) + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 3. Pastikan BIOM ada sebagai procedure (penanda biometri). Bila belum ter-cover.
        if (! DB::table('procedures')->where('code', 'BIOM')->exists()) {
            DB::table('procedures')->insert([
                'id'         => (string) Str::uuid(),
                'code'       => 'BIOM',
                'name'       => 'Biometri',
                'category'   => 'Penunjang',
                'base_price' => 0,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        // Pastikan baris cermin BIOM di diagnostic_test_types juga ada (dipakai alur biometri).
        if (! DB::table('diagnostic_test_types')->where('code', 'BIOM')->exists()) {
            DB::table('diagnostic_test_types')->insert([
                'id'         => (string) Str::uuid(),
                'code'       => 'BIOM',
                'name'       => 'Biometri',
                'category'   => 'Penunjang',
                'is_active'  => true,
                'sort_order' => (int) (DB::table('diagnostic_test_types')->max('sort_order') ?? 0) + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Tidak menghapus procedures hasil promote (bisa sudah dipakai tarif/visit).
    }
};
