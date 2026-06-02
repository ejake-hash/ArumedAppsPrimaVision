<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\InventoryPrice;
use App\Models\InventoryPriceSetting;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\SystemLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryPriceService
{
    public function getPpnRate(): float
    {
        return (float) InventoryPriceSetting::current()->ppn_rate;
    }

    public function setPpnRate(float $rate): InventoryPriceSetting
    {
        $setting = InventoryPriceSetting::current();
        $setting->ppn_rate = $rate;
        $setting->save();

        if ($rate >= 0) {
            $this->recomputeAllHja($rate);
        }
        return $setting;
    }

    /**
     * Daftar item per tipe (MEDICATION / BHP / IOL) di-LEFT JOIN dgn inventory_prices.
     * Item yang belum di-price tetap tampil (hpp/hja null).
     */
    public function listForType(string $type, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $search  = trim($filters['search'] ?? '');

        $query = match ($type) {
            'MEDICATION' => $this->buildMedicationQuery($search),
            'BHP'        => $this->buildBhpQuery($search),
            'IOL'        => $this->buildIolQuery($search),
            default      => throw new \Exception('Tipe tidak dikenal: ' . $type, 422),
        };

        return $query->paginate($perPage);
    }

    private function buildMedicationQuery(string $search)
    {
        $q = Medication::query()
            ->select([
                'medications.id',
                'medications.code',
                'medications.name',
                'medications.generic_name',
                'medications.form_sediaan',
                'medications.unit_besar',
                'medications.unit_kecil',
                'medications.konversi',
                'medications.is_active',
                'inventory_prices.id as price_id',
                'inventory_prices.hpp',
                'inventory_prices.margin_percent',
                'inventory_prices.ppn_enabled',
                'inventory_prices.hja',
                'inventory_prices.effective_date',
                'inventory_prices.notes',
            ])
            ->leftJoin('inventory_prices', function ($j) {
                $j->on('inventory_prices.item_id', '=', 'medications.id')
                  ->where('inventory_prices.item_type', '=', 'MEDICATION');
            })
            ->orderBy('medications.name');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('medications.name', 'like', "%$search%")
                  ->orWhere('medications.code', 'like', "%$search%")
                  ->orWhere('medications.generic_name', 'like', "%$search%");
            });
        }
        return $q;
    }

    private function buildBhpQuery(string $search)
    {
        $q = BhpItem::query()
            ->select([
                'bhp_items.id',
                'bhp_items.code',
                'bhp_items.name',
                'bhp_items.category',
                'bhp_items.unit',
                'bhp_items.is_active',
                'inventory_prices.id as price_id',
                'inventory_prices.hpp',
                'inventory_prices.margin_percent',
                'inventory_prices.ppn_enabled',
                'inventory_prices.hja',
                'inventory_prices.effective_date',
                'inventory_prices.notes',
            ])
            ->leftJoin('inventory_prices', function ($j) {
                $j->on('inventory_prices.item_id', '=', 'bhp_items.id')
                  ->where('inventory_prices.item_type', '=', 'BHP');
            })
            ->orderBy('bhp_items.name');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('bhp_items.name', 'like', "%$search%")
                  ->orWhere('bhp_items.code', 'like', "%$search%")
                  ->orWhere('bhp_items.category', 'like', "%$search%");
            });
        }
        return $q;
    }

    private function buildIolQuery(string $search)
    {
        $q = IolItem::query()
            ->select([
                'iol_items.id',
                'iol_items.brand',
                'iol_items.model',
                'iol_items.iol_type',
                'iol_items.power',
                'iol_items.cylinder',
                'iol_items.serial_number',
                'iol_items.is_active',
                'inventory_prices.id as price_id',
                'inventory_prices.hpp',
                'inventory_prices.margin_percent',
                'inventory_prices.ppn_enabled',
                'inventory_prices.hja',
                'inventory_prices.effective_date',
                'inventory_prices.notes',
            ])
            ->leftJoin('inventory_prices', function ($j) {
                $j->on('inventory_prices.item_id', '=', 'iol_items.id')
                  ->where('inventory_prices.item_type', '=', 'IOL');
            })
            ->orderBy('iol_items.brand')
            ->orderBy('iol_items.model');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('iol_items.brand', 'like', "%$search%")
                  ->orWhere('iol_items.model', 'like', "%$search%")
                  ->orWhere('iol_items.serial_number', 'like', "%$search%");
            });
        }
        return $q;
    }

    /**
     * Upsert harga item. Hitung HJA dari ppn_rate global aktif.
     */
    public function upsert(string $itemType, string $itemId, array $data): InventoryPrice
    {
        $this->assertItemExists($itemType, $itemId);

        $ppnRate     = $this->getPpnRate();
        $hpp         = (float) ($data['hpp'] ?? 0);
        $margin      = (float) ($data['margin_percent'] ?? 0);
        $ppnEnabled  = (bool) ($data['ppn_enabled'] ?? true);
        $hja         = InventoryPrice::computeHja($hpp, $margin, $ppnEnabled, $ppnRate);

        $price = InventoryPrice::firstOrNew([
            'item_type' => $itemType,
            'item_id'   => $itemId,
        ]);

        $price->fill([
            'hpp'            => $hpp,
            'margin_percent' => $margin,
            'ppn_enabled'    => $ppnEnabled,
            'hja'            => $hja,
            'notes'          => $data['notes'] ?? null,
            'effective_date' => $data['effective_date'] ?? null,
            'updated_by'     => auth('api')->id(),
        ])->save();

        return $price;
    }

    public function delete(string $itemType, string $itemId): void
    {
        InventoryPrice::where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->delete();
    }

    private function assertItemExists(string $itemType, string $itemId): void
    {
        $exists = match ($itemType) {
            'MEDICATION' => Medication::whereKey($itemId)->exists(),
            'BHP'        => BhpItem::whereKey($itemId)->exists(),
            'IOL'        => IolItem::whereKey($itemId)->exists(),
            default      => false,
        };
        if (!$exists) {
            throw new \Exception('Item tidak ditemukan untuk tipe ' . $itemType, 404);
        }
    }

    // =========================================================================
    // CSV — Template / Export / Import
    // =========================================================================
    //
    // Format kolom (case-insensitive header):
    //   kode, nama, hpp, margin_persen, ppn, hja
    // - `kode`        : kunci lookup item ke master (WAJIB). MEDICATION/BHP pakai
    //                   kolom `code`; IOL pakai `serial_number`.
    // - `nama`        : referensi saja (di-ignore saat import).
    // - `hpp`         : harga pokok (>= 0).
    // - `margin_persen`: margin % (0–1000).
    // - `ppn`         : 1/0 / ya/tidak / true/false — apakah HJA kena PPN.
    // - `hja`         : hanya di export (auto-compute), di-ignore saat import.
    //
    // RULE: item dengan `kode` yang tidak ada di master data DILEWATI (skipped)
    //       dan dicatat di errors[]. Harga hanya boleh diset untuk item master.

    private const CSV_HEADERS = ['kode', 'nama', 'hpp', 'margin_persen', 'ppn', 'hja'];

    public function templateCsv(string $type): string
    {
        $this->assertType($type);
        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::CSV_HEADERS, ',', '"', '\\');
        // Satu baris contoh (komentar) supaya user paham format.
        fputcsv($output, ['(isi kode item)', '(nama — opsional)', '0', '0', '1', '(auto)'], ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function exportCsv(string $type): string
    {
        $this->assertType($type);
        $rows = $this->listForType($type, ['per_page' => 100000])->items();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::CSV_HEADERS, ',', '"', '\\');
        foreach ($rows as $r) {
            // Hanya export item yang sudah punya harga (price_id != null).
            if (($r->price_id ?? null) === null) {
                continue;
            }
            fputcsv($output, [
                $this->rowKode($type, $r),
                $this->rowNama($type, $r),
                (string) $r->hpp,
                (string) $r->margin_percent,
                $r->ppn_enabled ? '1' : '0',
                (string) $r->hja,
            ], ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function importCsv(string $type, string $csvContent): array
    {
        $this->assertType($type);

        // Buang BOM UTF-8 (Excel "Save as CSV") agar header kolom pertama tak rusak.
        if (str_starts_with($csvContent, "\xEF\xBB\xBF")) {
            $csvContent = substr($csvContent, 3);
        }
        $lines = array_filter(explode("\n", str_replace("\r", '', trim($csvContent))));
        if (empty($lines)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        foreach (['kode', 'hpp', 'margin_persen'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new \Exception("Header CSV harus mengandung kolom '{$required}'.", 422);
            }
        }

        $ppnRate  = $this->getPpnRate();
        $keyCol   = $this->lookupKeyColumn($type);
        $itemTable = $this->itemTable($type);

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        DB::beginTransaction();
        try {
            foreach ($lines as $idx => $line) {
                $lineNum = $idx + 2;
                if (trim($line) === '') continue;

                $values = str_getcsv($line, ',', '"', '\\');
                if (count($values) !== count($headers)) {
                    $errors[] = "Baris {$lineNum}: jumlah kolom tidak sesuai header";
                    $skipped++;
                    continue;
                }
                $row = array_combine($headers, $values);

                $kode   = trim((string) ($row['kode'] ?? ''));
                $hppRaw = trim((string) ($row['hpp'] ?? ''));
                $mrgRaw = trim((string) ($row['margin_persen'] ?? ''));

                // Lewati baris contoh dari template.
                if ($kode === '' || str_starts_with($kode, '(')) {
                    $skipped++;
                    continue;
                }
                if ($hppRaw === '' || $mrgRaw === '') {
                    $errors[] = "Baris {$lineNum}: 'hpp' atau 'margin_persen' kosong";
                    $skipped++;
                    continue;
                }
                if (! is_numeric($hppRaw) || (float) $hppRaw < 0) {
                    $errors[] = "Baris {$lineNum}: 'hpp' harus angka >= 0";
                    $skipped++;
                    continue;
                }
                if (! is_numeric($mrgRaw) || (float) $mrgRaw < 0 || (float) $mrgRaw > 1000) {
                    $errors[] = "Baris {$lineNum}: 'margin_persen' harus angka 0–1000";
                    $skipped++;
                    continue;
                }

                // RULE: item harus ada di master data.
                $item = DB::table($itemTable)
                    ->whereRaw("LOWER({$keyCol}) = ?", [strtolower($kode)])
                    ->whereNull('deleted_at')
                    ->first();

                if (! $item) {
                    $errors[] = "Baris {$lineNum}: item dengan {$keyCol} '{$kode}' tidak ditemukan di master {$type}";
                    $skipped++;
                    continue;
                }

                $ppnEnabled = $this->parseBool($row['ppn'] ?? '1');
                $hpp        = (float) $hppRaw;
                $margin     = (float) $mrgRaw;
                $hja        = InventoryPrice::computeHja($hpp, $margin, $ppnEnabled, $ppnRate);

                $existing = InventoryPrice::where('item_type', $type)->where('item_id', $item->id)->first();
                InventoryPrice::updateOrCreate(
                    ['item_type' => $type, 'item_id' => $item->id],
                    [
                        'hpp'            => $hpp,
                        'margin_percent' => $margin,
                        'ppn_enabled'    => $ppnEnabled,
                        'hja'            => $hja,
                        'updated_by'     => auth('api')->id(),
                    ]
                );
                if ($existing) $updated++; else $inserted++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->log(auth('api')->id(), 'IMPORT_HARGA_CSV', null, null, "type:{$type} new:{$inserted} upd:{$updated} skip:{$skipped}");

        return compact('inserted', 'updated', 'skipped', 'errors');
    }

    // ---- CSV helpers ---------------------------------------------------------

    private function assertType(string $type): void
    {
        if (! in_array($type, ['MEDICATION', 'BHP', 'IOL'], true)) {
            throw new \Exception('Tipe harus MEDICATION, BHP, atau IOL', 422);
        }
    }

    private function itemTable(string $type): string
    {
        return match ($type) {
            'MEDICATION' => 'medications',
            'BHP'        => 'bhp_items',
            'IOL'        => 'iol_items',
        };
    }

    /** Kolom kunci untuk lookup item dari CSV. IOL tidak punya code → serial_number. */
    private function lookupKeyColumn(string $type): string
    {
        return $type === 'IOL' ? 'serial_number' : 'code';
    }

    private function rowKode(string $type, $r): string
    {
        return (string) ($type === 'IOL' ? ($r->serial_number ?? '') : ($r->code ?? ''));
    }

    private function rowNama(string $type, $r): string
    {
        if ($type === 'IOL') {
            return trim(($r->brand ?? '') . ' ' . ($r->model ?? '') . ($r->power !== null ? " ({$r->power}D)" : ''));
        }
        return (string) ($r->name ?? '');
    }

    private function parseBool($v): bool
    {
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'ya', 'y', 'yes', 'aktif'], true);
    }

    /** Audit log helper — pakai SystemLog (selaras MasterDataService). */
    private function log(?string $userId, string $action, ?string $model = null, ?string $modelId = null, ?string $description = null): void
    {
        try {
            SystemLog::create([
                'user_id'     => $userId,
                'action'      => $action,
                'model'       => $model,
                'model_id'    => $modelId,
                'description' => $description,
            ]);
        } catch (\Throwable $e) {
            // Logging tidak boleh menggagalkan operasi utama.
        }
    }

    /**
     * Saat ppn_rate global berubah, recompute HJA semua row yang ppn_enabled=true.
     */
    private function recomputeAllHja(float $ppnRate): void
    {
        InventoryPrice::query()->chunkById(500, function ($rows) use ($ppnRate) {
            foreach ($rows as $r) {
                $newHja = InventoryPrice::computeHja(
                    (float) $r->hpp,
                    (float) $r->margin_percent,
                    (bool) $r->ppn_enabled,
                    $ppnRate
                );
                if ((float) $r->hja !== $newHja) {
                    $r->hja = $newHja;
                    $r->save();
                }
            }
        });
    }
}
