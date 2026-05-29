<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\InventoryStock;
use App\Models\Medication;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * InventoryStockService — opname & CSV upsert untuk stok per-batch.
 *
 * Stok di sistem ini per-batch (`inventory_stocks`). Opname per item bisa
 * berakhir dengan: (1) override qty per batch existing, atau (2) penambahan
 * batch baru `OPNAME-{tgl}` kalau selisih positif tanpa target batch jelas.
 * Selisih negatif yang tidak di-target ke batch tertentu dikurangi FEFO.
 */
class InventoryStockService
{
    public function __construct(private Request $request)
    {
    }

    /**
     * Opname per item.
     *
     * Payload:
     *  - item_type: MEDICATION|BHP
     *  - item_id  : uuid master
     *  - reason   : string (alasan opname)
     *  - batches  : array of { stock_id?, batch_no?, expiry_date?, qty_physical }
     *  - new_qty  : optional float — kalau diisi, override semua batch ke total ini
     *               (mode "set total"). Mutually exclusive dgn batches detail.
     */
    public function opname(array $data): array
    {
        $type = $data['item_type'];
        $itemId = $data['item_id'];

        return DB::transaction(function () use ($type, $itemId, $data) {
            $this->assertItemExists($type, $itemId);

            $existing = InventoryStock::where('item_type', $type)
                ->where('item_id', $itemId)
                ->lockForUpdate()
                ->get();
            $existingTotal = (float) $existing->sum('qty_on_hand');

            $touched = 0;

            if (!empty($data['batches'])) {
                // Mode per-batch: override qty per row
                foreach ($data['batches'] as $row) {
                    $physical = (float) ($row['qty_physical'] ?? 0);
                    if (!empty($row['stock_id'])) {
                        $stock = $existing->firstWhere('id', $row['stock_id']);
                        if (!$stock) continue;
                        $stock->qty_on_hand = $physical;
                        $stock->save();
                        $touched++;
                    } else {
                        // Batch baru
                        if ($physical <= 0) continue;
                        InventoryStock::create([
                            'item_type'   => $type,
                            'item_id'     => $itemId,
                            'batch_no'    => $row['batch_no'] ?? ('OPNAME-' . now()->format('Ymd-His')),
                            'expiry_date' => $row['expiry_date'] ?? null,
                            'qty_on_hand' => $physical,
                            'last_received_at' => now(),
                        ]);
                        $touched++;
                    }
                }
            } elseif (array_key_exists('new_qty', $data)) {
                // Mode set-total (dipakai oleh CSV)
                $target = (float) $data['new_qty'];
                $delta  = $target - $existingTotal;
                $this->applyDelta($type, $itemId, $delta, $existing);
                $touched = abs($delta) > 0.0001 ? 1 : 0;
            }

            $newTotal = (float) InventoryStock::where('item_type', $type)
                ->where('item_id', $itemId)
                ->sum('qty_on_hand');

            $this->log(
                'OPNAME_STOCK',
                'InventoryStock',
                $itemId,
                "type:{$type} before:{$existingTotal} after:{$newTotal} touched:{$touched} reason:" . ($data['reason'] ?? '-')
            );

            return [
                'item_id'   => $itemId,
                'item_type' => $type,
                'before'    => $existingTotal,
                'after'     => $newTotal,
                'touched'   => $touched,
            ];
        });
    }

    /**
     * Total stok on-hand suatu item (semua batch) di `inventory_stocks`.
     */
    public function onHand(string $type, string $itemId): float
    {
        return (float) InventoryStock::where('item_type', $type)
            ->where('item_id', $itemId)
            ->sum('qty_on_hand');
    }

    /**
     * Konsumsi stok item sebanyak $qty dari `inventory_stocks` secara FEFO
     * (batch paling cepat kedaluwarsa lebih dulu). Dipakai dispensing farmasi
     * & alur lain yang mengeluarkan stok. Throw 422 kalau stok total tak cukup.
     *
     * Catatan: ini sumber stok yang BENAR pasca-redesign inventori. Kolom
     * legacy `medications.stock`/`bhp_items.stock` TIDAK lagi otoritatif.
     *
     * @return list<array{batch_no:?string,expiry_date:mixed,qty:float}> batch yg dipakai
     */
    public function consume(string $type, string $itemId, float $qty): array
    {
        if ($qty <= 0) return [];

        $remaining = $qty;
        $used = [];

        $stocks = InventoryStock::where('item_type', $type)
            ->where('item_id', $itemId)
            ->where('qty_on_hand', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->lockForUpdate()
            ->get();

        foreach ($stocks as $st) {
            if ($remaining <= 0) break;
            $take = min($remaining, (float) $st->qty_on_hand);
            $st->decrement('qty_on_hand', $take);
            $remaining -= $take;
            $used[] = ['batch_no' => $st->batch_no, 'expiry_date' => $st->expiry_date, 'qty' => $take];
        }

        if ($remaining > 0.001) {
            abort(422, "Stok tidak mencukupi untuk item {$type}. Kurang " . round($remaining, 2) . " unit.");
        }

        return $used;
    }

    /**
     * Tambah / kurangi total stok item lewat selisih.
     * - delta > 0 → batch baru OPNAME-{tgl}.
     * - delta < 0 → kurangi FEFO dari batch existing.
     */
    private function applyDelta(string $type, string $itemId, float $delta, $existing): void
    {
        if (abs($delta) < 0.0001) return;

        if ($delta > 0) {
            InventoryStock::create([
                'item_type'   => $type,
                'item_id'     => $itemId,
                'batch_no'    => 'OPNAME-' . now()->format('Ymd'),
                'expiry_date' => null,
                'qty_on_hand' => $delta,
                'last_received_at' => now(),
            ]);
            return;
        }

        // delta < 0 → FEFO deduct
        $remaining = abs($delta);
        $stocks = $existing
            ->where('qty_on_hand', '>', 0)
            ->sortBy([
                fn ($a, $b) => ($a->expiry_date === null) <=> ($b->expiry_date === null)
                    ?: ($a->expiry_date <=> $b->expiry_date),
            ])
            ->values();

        foreach ($stocks as $st) {
            if ($remaining <= 0) break;
            $take = min($remaining, (float) $st->qty_on_hand);
            $st->decrement('qty_on_hand', $take);
            $remaining -= $take;
        }

        if ($remaining > 0.001) {
            abort(422, "Stok existing tidak cukup untuk dikurangi sebesar " . abs($delta));
        }
    }

    private function assertItemExists(string $type, string $itemId): void
    {
        $exists = match ($type) {
            'MEDICATION' => Medication::where('id', $itemId)->exists(),
            'BHP'        => BhpItem::where('id', $itemId)->exists(),
            default      => false,
        };
        if (!$exists) {
            abort(404, "Item {$type} tidak ditemukan");
        }
    }

    // =========================================================================
    // CSV (Obat & BHP; IOL skip)
    // =========================================================================

    public function csvTemplate(string $type): string
    {
        $this->assertCsvType($type);
        return $this->makeCsv([['code', 'name', 'qty']]);
    }

    public function exportCsv(string $type): string
    {
        $this->assertCsvType($type);
        $itemType = $this->itemTypeOf($type);

        $masters = $this->mastersFor($type)->get();
        $totals  = InventoryStock::where('item_type', $itemType)
            ->whereIn('item_id', $masters->pluck('id'))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(qty_on_hand) as total')
            ->pluck('total', 'item_id');

        $rows = [['code', 'name', 'qty']];
        foreach ($masters as $m) {
            $rows[] = [$m->code, $m->name, (float) ($totals[$m->id] ?? 0)];
        }
        return $this->makeCsv($rows);
    }

    /**
     * Import CSV → opname per item.
     *
     * Lookup tier:
     *   1. code (jika terisi)
     *   2. LOWER(name) (jika code kosong); error kalau ambigu (>1 match).
     *
     * Selisih qty diaplikasikan via applyDelta (positif → batch OPNAME, negatif → FEFO).
     */
    public function importCsv(string $type, string $csvContent): array
    {
        $this->assertCsvType($type);
        $itemType = $this->itemTypeOf($type);

        $lines = array_filter(explode("\n", str_replace("\r", '', trim($csvContent))));
        if (empty($lines)) {
            abort(422, 'File CSV kosong.');
        }

        $headers = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ',', '"', '\\'));
        foreach (['name', 'qty'] as $req) {
            if (!in_array($req, $headers, true)) {
                abort(422, "Header CSV harus mengandung '{$req}'.");
            }
        }

        $masters = $this->mastersFor($type)->get(['id', 'code', 'name']);
        $byCode  = $masters->keyBy(fn ($m) => strtolower($m->code ?? ''));
        $byName  = $masters->groupBy(fn ($m) => strtolower($m->name ?? ''));

        $applied = 0;
        $skipped = 0;
        $errors  = [];

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

            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $qty  = $row['qty'] ?? '';

            if ($qty === '' || !is_numeric($qty)) {
                $errors[] = "Baris {$lineNum}: 'qty' kosong atau bukan angka";
                $skipped++;
                continue;
            }
            $qtyNum = (float) $qty;
            if ($qtyNum < 0) {
                $errors[] = "Baris {$lineNum}: 'qty' tidak boleh negatif";
                $skipped++;
                continue;
            }

            $master = null;
            if ($code !== '') {
                $master = $byCode->get(strtolower($code));
                if (!$master) {
                    $errors[] = "Baris {$lineNum}: code '{$code}' tidak ditemukan";
                    $skipped++;
                    continue;
                }
            } elseif ($name !== '') {
                $matches = $byName->get(strtolower($name), collect());
                if ($matches->isEmpty()) {
                    $errors[] = "Baris {$lineNum}: nama '{$name}' tidak ditemukan";
                    $skipped++;
                    continue;
                }
                if ($matches->count() > 1) {
                    $errors[] = "Baris {$lineNum}: nama '{$name}' ambigu (ada " . $matches->count() . " match), isi code";
                    $skipped++;
                    continue;
                }
                $master = $matches->first();
            } else {
                $errors[] = "Baris {$lineNum}: code & name kosong";
                $skipped++;
                continue;
            }

            try {
                $this->opname([
                    'item_type' => $itemType,
                    'item_id'   => $master->id,
                    'new_qty'   => $qtyNum,
                    'reason'    => "Import CSV opname",
                ]);
                $applied++;
            } catch (\Throwable $e) {
                $errors[] = "Baris {$lineNum}: " . $e->getMessage();
                $skipped++;
            }
        }

        return compact('applied', 'skipped', 'errors');
    }

    // =========================================================================
    // helpers
    // =========================================================================

    private const CSV_TYPES = ['obat', 'bhp'];

    private function assertCsvType(string $type): void
    {
        if (!in_array($type, self::CSV_TYPES, true)) {
            abort(422, "Tipe CSV stok tidak didukung: {$type}");
        }
    }

    private function itemTypeOf(string $type): string
    {
        return $type === 'obat' ? 'MEDICATION' : 'BHP';
    }

    private function mastersFor(string $type)
    {
        return $type === 'obat'
            ? Medication::query()->where('is_active', true)->orderBy('name')
            : BhpItem::query()->where('is_active', true)->orderBy('name');
    }

    private function makeCsv(array $rows): string
    {
        $output = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($output, $r, ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    private function log(string $action, ?string $model, ?string $modelId, ?string $desc): void
    {
        SystemLog::create([
            'user_id'     => auth('api')->id(),
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $desc,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
