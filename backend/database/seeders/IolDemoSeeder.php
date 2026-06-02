<?php

namespace Database\Seeders;

use App\Models\InventoryStock;
use App\Models\IolItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * IolDemoSeeder — master IOL per-TIPE + stok di inventory_stocks (gudang & bedah)
 * untuk menguji alur scan barcode UDI (DataMatrix) end-to-end.
 *
 * Model PER-TIPE (pasca-redesign): 1 baris = 1 tipe lensa (brand+model+power),
 * stok = angka di `inventory_stocks` (BUKAN `iol_items.stock` legacy). Serial/lot
 * dicatat saat operasi.
 *
 * GTIN contoh diambil/diselaraskan dari label Alcon AcrySof (untuk uji scan).
 * IDEMPOTEN: aman dijalankan berulang.
 *
 * Jalankan: php artisan db:seed --class=IolDemoSeeder
 */
class IolDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $rows = [
                ['brand' => 'Alcon', 'model' => 'AcrySof SA60AT', 'iol_type' => 'MONOFOCAL', 'material' => 'Acrylic', 'power' => 21.0,  'gtin' => '00380652555821', 'price' => 2500000, 'stock' => 8],
                ['brand' => 'Alcon', 'model' => 'AcrySof IQ SN60WF', 'iol_type' => 'MONOFOCAL', 'material' => 'Acrylic', 'power' => 20.0, 'gtin' => '00380652010015', 'price' => 3200000, 'stock' => 6],
                ['brand' => 'Alcon', 'model' => 'AcrySof IQ Toric', 'iol_type' => 'TORIC', 'material' => 'Acrylic', 'power' => 22.5, 'cylinder' => 1.5, 'axis' => 90, 'gtin' => '00380652020014', 'price' => 6500000, 'stock' => 4],
                ['brand' => 'Johnson & Johnson', 'model' => 'TECNIS Monofocal', 'iol_type' => 'MONOFOCAL', 'material' => 'Acrylic', 'power' => 19.5, 'gtin' => '07612345000019', 'price' => 3000000, 'stock' => 5],
                ['brand' => 'Zeiss', 'model' => 'AT LISA tri', 'iol_type' => 'TRIFOCAL', 'material' => 'Acrylic', 'power' => 23.0, 'gtin' => '04030000123456', 'price' => 9500000, 'stock' => 3],
            ];

            foreach ($rows as $r) {
                $iol = IolItem::firstOrCreate(
                    // Identitas per-tipe = brand + model + power.
                    [
                        'brand' => $r['brand'],
                        'model' => $r['model'],
                        'power' => $r['power'],
                    ],
                    [
                        'manufacturer' => $r['brand'],
                        'iol_type'     => $r['iol_type'],
                        'material'     => $r['material'] ?? null,
                        'cylinder'     => $r['cylinder'] ?? null,
                        'axis'         => $r['axis'] ?? null,
                        'gtin'         => $r['gtin'],
                        'price'        => $r['price'],
                        'is_active'    => true,
                    ]
                );

                // Pastikan gtin terisi walau baris sudah ada sebelumnya (tanpa gtin).
                if (empty($iol->gtin) && ! empty($r['gtin'])) {
                    $iol->update(['gtin' => $r['gtin']]);
                }

                // Stok di gudang INVENTORI (sumber stok tunggal). Idempoten:
                // set qty target, bukan menambah berulang.
                InventoryStock::updateOrCreate(
                    [
                        'item_type' => InventoryStock::TYPE_IOL,
                        'item_id'   => $iol->id,
                        'location'  => InventoryStock::LOC_INVENTORI,
                        'batch_no'  => 'DEMO-' . substr($r['gtin'], -5),
                    ],
                    [
                        'expiry_date'      => '2029-12-31',
                        'qty_on_hand'      => $r['stock'],
                        'last_received_at' => now(),
                    ]
                );

                // Sebagian stok dipindahkan ke unit BEDAH (untuk uji decrement saat operasi).
                InventoryStock::updateOrCreate(
                    [
                        'item_type' => InventoryStock::TYPE_IOL,
                        'item_id'   => $iol->id,
                        'location'  => InventoryStock::LOC_BEDAH,
                        'batch_no'  => 'DEMO-' . substr($r['gtin'], -5),
                    ],
                    [
                        'expiry_date'      => '2029-12-31',
                        'qty_on_hand'      => max(1, intdiv($r['stock'], 2)),
                        'last_received_at' => now(),
                    ]
                );
            }

            $this->command?->info('IolDemoSeeder: ' . count($rows) . ' tipe IOL + stok inventory_stocks (INVENTORI & BEDAH) siap.');
        });
    }
}
