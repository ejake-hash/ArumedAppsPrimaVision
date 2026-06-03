<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Refactor harga jual: pindahkan harga jual obat/BHP dari modul "Penentuan Harga"
 * (inventory_prices.hja) ke Buku Tarif (medication_tariffs / bhp_tariffs, baris
 * insurer UMUM = harga tunggal), lalu DROP tabel inventory_prices &
 * inventory_price_settings.
 *
 * Data: tiap inventory_prices.hja > 0 disalin ke *_tariffs UMUM bila baris UMUM
 * untuk item itu BELUM ada (hormati harga yang sudah diisi manual di Buku Tarif).
 * IOL tak ada di inventory_prices → tak ada migrasi IOL.
 *
 * IRREVERSIBLE secara data: down() merekonstruksi STRUKTUR tabel saja (kosong),
 * tidak mengembalikan baris hja yang sudah dipindah.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Migrasi data hja → *_tariffs UMUM (hanya bila tabel sumber masih ada).
        if (Schema::hasTable('inventory_prices')) {
            $umumId = DB::table('insurers')
                ->where('is_system', true)->where('type', 'UMUM')
                ->value('id');

            if ($umumId) {
                $map = [
                    'MEDICATION' => ['table' => 'medication_tariffs', 'fk' => 'medication_id'],
                    'BHP'        => ['table' => 'bhp_tariffs',        'fk' => 'bhp_item_id'],
                ];

                foreach ($map as $itemType => $cfg) {
                    $rows = DB::table('inventory_prices')
                        ->where('item_type', $itemType)
                        ->where('hja', '>', 0)
                        ->get(['item_id', 'hja']);

                    foreach ($rows as $row) {
                        // Soft-delete aware: cek baris UMUM existing (termasuk trashed).
                        $existing = DB::table($cfg['table'])
                            ->where($cfg['fk'], $row->item_id)
                            ->where('insurer_id', $umumId)
                            ->first();

                        if ($existing) {
                            // Sudah ada baris UMUM → JANGAN timpa harga manual; cuma
                            // restore bila ter-soft-delete & harga lama 0 (belum diisi).
                            if ($existing->deleted_at !== null) {
                                DB::table($cfg['table'])->where('id', $existing->id)->update([
                                    'deleted_at' => null,
                                    'price'      => $existing->price > 0 ? $existing->price : $row->hja,
                                    'is_active'  => true,
                                    'updated_at' => now(),
                                ]);
                            }
                            continue;
                        }

                        DB::table($cfg['table'])->insert([
                            'id'           => (string) Str::uuid(),
                            $cfg['fk']     => $row->item_id,
                            'insurer_id'   => $umumId,
                            'price'        => $row->hja,
                            'is_active'    => true,
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                    }
                }
            }
        }

        // 2) Drop tabel Penentuan Harga.
        Schema::dropIfExists('inventory_prices');
        Schema::dropIfExists('inventory_price_settings');
    }

    public function down(): void
    {
        // Rekonstruksi STRUKTUR (mirror 2026_05_26_000023). Data hja TIDAK dikembalikan.
        if (! Schema::hasTable('inventory_prices')) {
            Schema::create('inventory_prices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('item_type', 20);            // MEDICATION | BHP | IOL
                $table->uuid('item_id');
                $table->decimal('hpp', 14, 2)->default(0);
                $table->decimal('margin_percent', 6, 2)->default(0);
                $table->boolean('ppn_enabled')->default(false);
                $table->decimal('hja', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->date('effective_date')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['item_type', 'item_id']);
            });
        }

        if (! Schema::hasTable('inventory_price_settings')) {
            Schema::create('inventory_price_settings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->decimal('ppn_rate', 5, 2)->default(11);
                $table->timestamps();
            });
        }
    }
};
