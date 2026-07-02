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
        $location = $data['location'] ?? InventoryStock::LOC_INVENTORI;

        return DB::transaction(function () use ($type, $itemId, $location, $data) {
            $this->assertItemExists($type, $itemId);

            $existing = InventoryStock::where('item_type', $type)
                ->where('item_id', $itemId)
                ->where('location', $location)
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
                            'location'    => $location,
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
                $this->applyDelta($type, $itemId, $delta, $existing, $location);
                $touched = abs($delta) > 0.0001 ? 1 : 0;
            }

            $newTotal = (float) InventoryStock::where('item_type', $type)
                ->where('item_id', $itemId)
                ->where('location', $location)
                ->sum('qty_on_hand');

            $this->log(
                'OPNAME_STOCK',
                'InventoryStock',
                $itemId,
                "type:{$type} loc:{$location} before:{$existingTotal} after:{$newTotal} touched:{$touched} reason:" . ($data['reason'] ?? '-')
            );

            return [
                'item_id'   => $itemId,
                'item_type' => $type,
                'location'  => $location,
                'before'    => $existingTotal,
                'after'     => $newTotal,
                'touched'   => $touched,
            ];
        });
    }

    /**
     * Total stok on-hand suatu item (semua batch) di `inventory_stocks`
     * pada satu lokasi (default gudang INVENTORI).
     */
    public function onHand(string $type, string $itemId, string $location = InventoryStock::LOC_INVENTORI, bool $excludeExpired = false): float
    {
        // $excludeExpired=true → cerminkan predikat consume() (batch expired dilewati),
        // WAJIB dipakai cek kecukupan DISPENSING/penjualan supaya tidak lolos di sini lalu
        // gagal 422 di dalam consume ("stok tidak cukup" padahal on-hand penuh tapi expired).
        // Default false: total apa adanya untuk opname/laporan/pergerakan stok.
        return (float) InventoryStock::where('item_type', $type)
            ->where('item_id', $itemId)
            ->where('location', $location)
            ->when($excludeExpired, function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('expiry_date')
                      ->orWhereDate('expiry_date', '>=', now('Asia/Jakarta')->toDateString());
                });
            })
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
     * Konsumsi STRICT per lokasi: hanya batch di `$location` yang dipakai. Bila
     * stok lokasi itu kurang → 422 (tidak ada fallback ke gudang).
     *
     * @return list<array{batch_no:?string,expiry_date:mixed,qty:float}> batch yg dipakai
     */
    /**
     * @param bool $excludeExpired  TRUE (default) untuk jalur DISPENSING/konsumsi ke
     *   pasien: batch kedaluwarsa dilewati (keselamatan pasien). FALSE untuk jalur
     *   pergerakan stok murni yang WAJIB memotong batch apa pun kondisinya — mis.
     *   terima retur unit barang rusak/kedaluwarsa (UnitReturnService::removeUnitStock).
     */
    public function consume(string $type, string $itemId, float $qty, string $location = InventoryStock::LOC_INVENTORI, bool $excludeExpired = true): array
    {
        if ($qty <= 0) return [];

        // Bungkus transaksi sendiri agar lockForUpdate atomik walau dipanggil
        // standalone (di luar transfer()). DB::transaction nested = savepoint,
        // jadi aman dipanggil dari dalam transaksi pemanggil juga.
        return DB::transaction(function () use ($type, $itemId, $qty, $location, $excludeExpired) {
            $remaining = $qty;
            $used = [];

            $stocks = InventoryStock::where('item_type', $type)
                ->where('item_id', $itemId)
                ->where('location', $location)
                ->where('qty_on_hand', '>', 0)
                // Keselamatan pasien: JANGAN dispensing batch yang sudah kedaluwarsa.
                // FEFO mengurutkan expiry paling awal duluan → tanpa filter ini justru
                // batch kedaluwarsa yang keluar lebih dulu. Batch tanpa tanggal (NULL)
                // tetap boleh; batch kedaluwarsa harus di-quarantine/transfer fresh dulu.
                // Dilewati saat $excludeExpired=false (jalur retur barang rusak/expired).
                ->when($excludeExpired, function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('expiry_date')
                          ->orWhereDate('expiry_date', '>=', now('Asia/Jakarta')->toDateString());
                    });
                })
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
                $locLabel = $location === InventoryStock::LOC_INVENTORI ? 'gudang' : "unit {$location}";
                abort(422, "Stok {$locLabel} tidak mencukupi untuk item {$type}. Kurang " . round($remaining, 2) . " unit. Minta transfer dari gudang dulu.");
            }

            return $used;
        });
    }

    /**
     * Tambah stok ke satu (item_type, location, item_id, batch_no) — SUMBER TUNGGAL
     * penambahan stok. Jangan pakai InventoryStock::firstOrNew(...) langsung di
     * pemanggil: saat batch_no NULL, UNIQUE Postgres MENGABAIKAN baris NULL sehingga
     * firstOrNew tak menemukan baris lama → tercipta baris duplikat tiap kali.
     * Helper ini mencocokkan batch_no NULL secara eksplisit (whereNull) sehingga
     * baris NULL yang sudah ada dipakai ulang, bukan diduplikasi.
     */
    public function upsertStock(string $type, string $itemId, string $location, ?string $batchNo, float $qty, $expiryDate = null): InventoryStock
    {
        if ($qty <= 0) {
            abort(422, 'Qty upsert stok harus > 0.');
        }

        $query = InventoryStock::where('item_type', $type)
            ->where('location', $location)
            ->where('item_id', $itemId)
            ->lockForUpdate();

        // Cocokkan batch_no NULL secara eksplisit (UNIQUE Postgres abaikan NULL).
        $batchNo === null ? $query->whereNull('batch_no') : $query->where('batch_no', $batchNo);

        $stock = $query->first() ?? new InventoryStock([
            'item_type' => $type,
            'location'  => $location,
            'item_id'   => $itemId,
            'batch_no'  => $batchNo,
            'qty_on_hand' => 0,
        ]);

        if ($expiryDate !== null) {
            if (! $stock->exists || $stock->expiry_date === null) {
                // Baris baru, atau baris lama belum ber-expiry → isi (melengkapi).
                $stock->expiry_date = $expiryDate;
            } elseif (\Illuminate\Support\Carbon::parse($stock->expiry_date)->toDateString()
                   !== \Illuminate\Support\Carbon::parse($expiryDate)->toDateString()) {
                // Batch_no sama tapi expiry BERBEDA: dulu expiry ditimpa utk SELURUH qty →
                // stok lama near-expiry "berubah" jadi jauh → obat kedaluwarsa lolos
                // FEFO/consume ke pasien & laporan expiry salah. Tolak agar operator
                // merekonsiliasi (pakai batch_no berbeda / betulkan expiry) — jangan gabung.
                abort(422, "Batch '{$batchNo}' sudah ada dgn tanggal kedaluwarsa berbeda. Gunakan batch_no berbeda atau betulkan tanggal kedaluwarsa (jangan gabungkan expiry berbeda dalam satu batch).");
            }
        }
        $stock->qty_on_hand = (float) ($stock->qty_on_hand ?? 0) + $qty;
        $stock->last_received_at = now();
        $stock->save();

        return $stock;
    }

    /**
     * Pindahkan stok antar lokasi (mis. INVENTORI → FARMASI saat deliver request
     * unit). FEFO-deduct dari `$from`, lalu tambahkan ke `$to` dengan
     * MEMPERTAHANKAN batch_no & expiry_date tiap batch yang diambil — sehingga
     * traceability batch tetap utuh di lokasi tujuan.
     *
     * @return list<array{batch_no:?string,expiry_date:mixed,qty:float}> batch yg dipindah
     */
    public function transfer(string $type, string $itemId, float $qty, string $from, string $to): array
    {
        if ($qty <= 0) return [];
        if ($from === $to) {
            abort(422, "Lokasi asal dan tujuan transfer tidak boleh sama ({$from}).");
        }

        return DB::transaction(function () use ($type, $itemId, $qty, $from, $to) {
            // Ambil dari lokasi asal (FEFO, strict) — throw 422 bila kurang.
            $moved = $this->consume($type, $itemId, $qty, $from);

            // Tambahkan ke lokasi tujuan via upsertStock (sumber tunggal) agar
            // batch_no NULL tidak menciptakan baris duplikat. Jaga batch_no + expiry.
            foreach ($moved as $m) {
                $this->upsertStock($type, $itemId, $to, $m['batch_no'], (float) $m['qty'], $m['expiry_date'] ?? null);
            }

            return $moved;
        });
    }

    /**
     * Tambah / kurangi total stok item lewat selisih.
     * - delta > 0 → batch baru OPNAME-{tgl}.
     * - delta < 0 → kurangi FEFO dari batch existing.
     */
    private function applyDelta(string $type, string $itemId, float $delta, $existing, string $location = InventoryStock::LOC_INVENTORI): void
    {
        if (abs($delta) < 0.0001) return;

        if ($delta > 0) {
            // Lewat upsertStock (sumber tunggal penambahan stok): kalau batch
            // 'OPNAME-{Ymd}' sudah ada (opname positif KE-2 di hari sama untuk
            // item+lokasi yg sama), increment baris itu — JANGAN create lagi
            // (raw create() → unique violation 23505 (location,type,item,batch_no),
            //  ditelan jadi "skipped" saat import CSV). upsertStock juga lock baris.
            $this->upsertStock($type, $itemId, $location, 'OPNAME-' . now()->format('Ymd'), $delta, null);
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

    public function exportCsv(string $type, string $location = InventoryStock::LOC_INVENTORI): string
    {
        $this->assertCsvType($type);
        $itemType = $this->itemTypeOf($type);

        $mastersQ = $this->mastersFor($type);
        // Lokasi unit (non-gudang): hanya item yang pernah dikirim ke lokasi itu
        // (punya baris inventory_stocks di sana). Gudang INVENTORI = semua master.
        if ($location !== InventoryStock::LOC_INVENTORI) {
            $locationItemIds = InventoryStock::where('item_type', $itemType)
                ->where('location', $location)
                ->distinct()
                ->pluck('item_id');
            $mastersQ->whereIn('id', $locationItemIds);
        }
        $masters = $mastersQ->get();
        $totals  = InventoryStock::where('item_type', $itemType)
            ->where('location', $location)
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
    public function importCsv(string $type, string $csvContent, string $location = InventoryStock::LOC_INVENTORI): array
    {
        $this->assertCsvType($type);
        $itemType = $this->itemTypeOf($type);

        // Buang BOM UTF-8 (Excel "Save as CSV") agar header kolom pertama tak rusak.
        if (str_starts_with($csvContent, "\xEF\xBB\xBF")) {
            $csvContent = substr($csvContent, 3);
        }
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
                    'location'  => $location,
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
