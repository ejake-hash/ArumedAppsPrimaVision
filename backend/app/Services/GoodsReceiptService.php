<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryStock;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Concerns\RetriesUniqueNumber;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GoodsReceiptService
{
    use RetriesUniqueNumber;

    public const ITEM_TYPES = ['MEDICATION', 'BHP', 'IOL'];

    public function index(array $filters = []): LengthAwarePaginator
    {
        $q = GoodsReceipt::query()->with(['supplier:id,code,name', 'purchaseOrder:id,po_number']);

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(function ($qq) use ($term) {
                $qq->where('grn_number', 'ilike', $term)
                   ->orWhere('invoice_number', 'ilike', $term)
                   ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term))
                   ->orWhereHas('purchaseOrder', fn ($p) => $p->where('po_number', 'ilike', $term));
            });
        }

        if (!empty($filters['supplier_id'])) {
            $q->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['po_id'])) {
            $q->where('po_id', $filters['po_id']);
        }

        if (!empty($filters['date_from'])) {
            $q->whereDate('receipt_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->whereDate('receipt_date', '<=', $filters['date_to']);
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        return $q->orderByDesc('receipt_date')->orderByDesc('grn_number')->paginate($perPage);
    }

    public function show(string $id): array
    {
        $grn = GoodsReceipt::with(['supplier', 'purchaseOrder', 'items'])->findOrFail($id);

        return [
            'id'             => $grn->id,
            'grn_number'     => $grn->grn_number,
            'po_id'          => $grn->po_id,
            'po_number'      => $grn->purchaseOrder?->po_number,
            'supplier_id'    => $grn->supplier_id,
            'supplier'       => $grn->supplier,
            'receipt_date'   => optional($grn->receipt_date)->toDateString(),
            'invoice_number' => $grn->invoice_number,
            'payment_method' => $grn->payment_method,
            'payment_term_days' => $grn->payment_term_days,
            'due_date'       => optional($grn->due_date)->toDateString(),
            'notes'          => $grn->notes,
            'total_amount'   => (float) $grn->total_amount,
            'discount_amount' => (float) $grn->discount_amount,
            'ppn_percent'    => (float) $grn->ppn_percent,
            'ppn_amount'     => (float) $grn->ppn_amount,
            'grand_total'    => (float) $grn->grand_total,
            'received_by'    => $grn->received_by,
            'created_at'     => $grn->created_at,
            'items'          => $grn->items->map(fn ($it) => $this->itemRow($it))->toArray(),
        ];
    }

    /**
     * Prepare data untuk modal "Terima dari PO" — return PO + items dengan
     * sisa qty (qty_ordered - qty_received) sebagai default qty diterima.
     */
    public function prepareFromPo(string $poId): array
    {
        $po = PurchaseOrder::with(['supplier', 'items'])->findOrFail($poId);

        if (in_array($po->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELED], true)) {
            abort(422, "PO dengan status {$po->status} tidak bisa diterima lagi.");
        }

        return [
            'po_id'         => $po->id,
            'po_number'     => $po->po_number,
            'supplier_id'   => $po->supplier_id,
            'supplier'      => $po->supplier,
            'po_date'       => optional($po->po_date)->toDateString(),
            'expected_date' => optional($po->expected_date)->toDateString(),
            'status'        => $po->status,
            'items'         => $po->items->map(function (PurchaseOrderItem $it) {
                $resolved = $this->resolveMasterRow($it->item_type, $it->item_id);
                $remaining = max(0, (float) $it->qty_ordered - (float) $it->qty_received);
                return [
                    'po_item_id'    => $it->id,
                    'item_type'     => $it->item_type,
                    'item_id'       => $it->item_id,
                    'item_code'     => $resolved['code'] ?? null,
                    'item_name'     => $resolved['name'] ?? '-',
                    'item_unit'     => $resolved['unit'] ?? null,
                    'qty_ordered'   => (float) $it->qty_ordered,
                    'qty_received'  => (float) $it->qty_received,
                    'qty_remaining' => $remaining,
                    'unit_price'    => (float) $it->unit_price,
                ];
            })->toArray(),
        ];
    }

    public function create(array $data): GoodsReceipt
    {
        Supplier::findOrFail($data['supplier_id']);

        $items = $data['items'] ?? [];
        $this->validateItems($items);

        $po = null;
        if (!empty($data['po_id'])) {
            $po = PurchaseOrder::with('items')->findOrFail($data['po_id']);
            if (in_array($po->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELED], true)) {
                abort(422, "PO dengan status {$po->status} tidak bisa diterima.");
            }
            if ($po->supplier_id !== $data['supplier_id']) {
                abort(422, 'Supplier GRN tidak sama dengan supplier PO.');
            }
            $this->validateAgainstPo($po, $items);
        }

        // Nomor GRN via MAX+1 bisa tabrakan saat 2 request berbarengan → retry
        // SELURUH transaksi saat unique-violation (Postgres membatalkan transaksi
        // begitu satu statement gagal, jadi retry harus transaksi baru).
        return $this->createWithRetry(function () use ($data, $items, $po) {
            return DB::transaction(function () use ($data, $items, $po) {
                $method   = $data['payment_method'] ?? GoodsReceipt::PAYMENT_TUNAI;
                $termDays = $method === GoodsReceipt::PAYMENT_KREDIT
                    ? (int) ($data['payment_term_days'] ?? 0)
                    : null;
                $receiptDate = $data['receipt_date'] ?? now()->toDateString();
                $dueDate = ($method === GoodsReceipt::PAYMENT_KREDIT && $termDays > 0)
                    ? Carbon::parse($receiptDate)->addDays($termDays)->toDateString()
                    : null;

                $grn = GoodsReceipt::create([
                    'grn_number'        => $this->generateGrnNumber($data['receipt_date'] ?? null),
                    'po_id'             => $data['po_id'] ?? null,
                    'supplier_id'       => $data['supplier_id'],
                    'receipt_date'      => $receiptDate,
                    'invoice_number'    => $data['invoice_number'] ?? null,
                    'payment_method'    => $method,
                    'payment_term_days' => $termDays,
                    'due_date'          => $dueDate,
                    'notes'             => $data['notes'] ?? null,
                    'total_amount'      => 0,
                    'discount_amount'   => 0, // diisi recalcTotal = agregat diskon per-item
                    'ppn_percent'       => max(0, (float) ($data['ppn_percent'] ?? 0)),
                    'ppn_amount'        => 0,
                    'grand_total'       => 0,
                    'received_by'       => auth('api')->id(),
                ]);

                foreach ($items as $row) {
                    $this->createItemAndApplyStock($grn, $row);
                }

                $this->recalcTotal($grn);

                if ($po) {
                    $this->updatePoStatus($po);
                }

                return $grn->fresh(['supplier', 'purchaseOrder', 'items']);
            });
        });
    }

    public function delete(string $id): void
    {
        $grn = GoodsReceipt::with('items.poItem')->findOrFail($id);

        DB::transaction(function () use ($grn) {
            // Reverse stok per item
            foreach ($grn->items as $it) {
                $this->reverseStock($it);

                // Decrement PO item qty_received kalau ada
                if ($it->poItem) {
                    $it->poItem->decrement('qty_received', (float) $it->qty_received);
                }
            }

            $poId = $grn->po_id;
            $grn->items()->delete();
            $grn->delete();

            // Recompute PO status
            if ($poId) {
                $po = PurchaseOrder::find($poId);
                if ($po) $this->updatePoStatus($po);
            }
        });
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private function validateItems(array $items): void
    {
        if (empty($items)) {
            abort(422, 'GRN harus berisi minimal 1 item.');
        }
        foreach ($items as $idx => $row) {
            $type = $row['item_type'] ?? null;
            $itemId = $row['item_id'] ?? null;
            $qty = (float) ($row['qty_received'] ?? 0);
            if (!in_array($type, self::ITEM_TYPES, true)) {
                abort(422, "Item baris #" . ($idx + 1) . ": item_type tidak valid.");
            }
            if (!$itemId) {
                abort(422, "Item baris #" . ($idx + 1) . ": item_id wajib.");
            }
            if ($qty <= 0) {
                abort(422, "Item baris #" . ($idx + 1) . ": qty_received harus > 0.");
            }
            if (!$this->itemExists($type, $itemId)) {
                abort(422, "Item baris #" . ($idx + 1) . ": item tidak ditemukan di master {$type}.");
            }
        }
    }

    /**
     * Validasi setiap item GRN tidak melebihi sisa qty PO item.
     */
    private function validateAgainstPo(PurchaseOrder $po, array $items): void
    {
        $poItems = $po->items->keyBy('id');

        foreach ($items as $idx => $row) {
            if (empty($row['po_item_id'])) {
                abort(422, "Item baris #" . ($idx + 1) . ": po_item_id wajib kalau terima dari PO.");
            }
            $poItem = $poItems->get($row['po_item_id']);
            if (!$poItem) {
                abort(422, "Item baris #" . ($idx + 1) . ": po_item_id tidak ditemukan di PO {$po->po_number}.");
            }
            if ($poItem->item_type !== $row['item_type'] || $poItem->item_id !== $row['item_id']) {
                abort(422, "Item baris #" . ($idx + 1) . ": tipe/id item tidak match dengan PO item.");
            }
            $remaining = (float) $poItem->qty_ordered - (float) $poItem->qty_received;
            if ((float) $row['qty_received'] > $remaining + 0.001) {
                abort(422, "Item baris #" . ($idx + 1) . ": qty diterima ({$row['qty_received']}) melebihi sisa PO ({$remaining}).");
            }
        }
    }

    private function itemExists(string $type, string $itemId): bool
    {
        return match ($type) {
            'MEDICATION' => Medication::whereKey($itemId)->exists(),
            'BHP'        => BhpItem::whereKey($itemId)->exists(),
            'IOL'        => IolItem::whereKey($itemId)->exists(),
        };
    }

    private function createItemAndApplyStock(GoodsReceipt $grn, array $row): void
    {
        $qty = (float) $row['qty_received'];
        $price = (float) ($row['unit_price'] ?? 0);
        // Diskon PER ITEM (persen): subtotal disimpan NET (sudah dipotong diskon).
        $discPct  = min(100, max(0, (float) ($row['discount_percent'] ?? 0)));
        $gross    = $qty * $price;
        $lineDisc = round($gross * $discPct / 100, 2);
        $subtotal = round(max(0, $gross - $lineDisc), 2);

        $item = GoodsReceiptItem::create([
            'grn_id'           => $grn->id,
            'po_item_id'       => $row['po_item_id'] ?? null,
            'item_type'        => $row['item_type'],
            'item_id'          => $row['item_id'],
            'qty_received'     => $qty,
            'batch_no'         => $row['batch_no'] ?? null,
            'expiry_date'      => $row['expiry_date'] ?? null,
            'unit_price'       => $price,
            'discount_percent' => $discPct,
            'subtotal'         => $subtotal,
            'notes'            => $row['notes'] ?? null,
        ]);

        // Apply ke stok GUDANG (INVENTORI) per (type, item, batch) via upsertStock
        // (sumber tunggal) agar batch_no NULL tidak menciptakan baris duplikat.
        app(InventoryStockService::class)->upsertStock(
            $item->item_type,
            $item->item_id,
            InventoryStock::LOC_INVENTORI,
            $item->batch_no,
            $qty,
            $item->expiry_date ?? null
        );

        // Update qty_received di PO item
        if (!empty($row['po_item_id'])) {
            PurchaseOrderItem::where('id', $row['po_item_id'])->increment('qty_received', $qty);
        }
    }

    private function reverseStock(GoodsReceiptItem $it): void
    {
        $stock = InventoryStock::where([
            'item_type' => $it->item_type,
            'location'  => InventoryStock::LOC_INVENTORI,
            'item_id'   => $it->item_id,
            'batch_no'  => $it->batch_no,
        ])->lockForUpdate()->first();

        $available = (float) ($stock->qty_on_hand ?? 0);
        $toReverse = (float) $it->qty_received;

        // Stok hasil GRN ini sudah terlanjur dipakai/ditransfer (sisa < qty yang
        // harus dikembalikan). Menolak penghapusan, bukan menghapus batch diam-diam
        // (yang membuat stok gudang jadi 0 & akuntansi inventori salah). Admin harus
        // selesaikan akuntansi stok yang sudah keluar dulu.
        if ($available + 0.001 < $toReverse) {
            $resolved = $this->resolveMasterRow($it->item_type, $it->item_id);
            $name = $resolved['name'] ?? $it->item_id;
            $batch = $it->batch_no ?? '(tanpa batch)';
            abort(422,
                "Penerimaan tidak bisa dibatalkan: stok '{$name}' batch {$batch} tinggal "
                . round($available, 2) . " dari " . round($toReverse, 2)
                . " yang diterima — sebagian sudah dipakai/ditransfer keluar gudang."
            );
        }

        $newQty = $available - $toReverse;
        if ($newQty <= 0.001) {
            $stock->delete();
        } else {
            $stock->update(['qty_on_hand' => $newQty]);
        }
    }

    private function updatePoStatus(PurchaseOrder $po): void
    {
        $po->refresh()->load('items');

        $totalOrdered = (float) $po->items->sum('qty_ordered');
        $totalReceived = (float) $po->items->sum('qty_received');

        if ($totalReceived <= 0) {
            // Balik ke SENT (atau DRAFT kalau belum pernah dikirim)
            $newStatus = $po->status === PurchaseOrder::STATUS_PARTIAL
                ? PurchaseOrder::STATUS_SENT
                : $po->status;
        } elseif ($totalReceived + 0.001 >= $totalOrdered) {
            $newStatus = PurchaseOrder::STATUS_RECEIVED;
        } else {
            $newStatus = PurchaseOrder::STATUS_PARTIAL;
        }

        if ($newStatus !== $po->status) {
            $po->update(['status' => $newStatus]);
        }
    }

    /**
     * Hitung ulang Subtotal (Σ subtotal item) lalu turunkan DPP/PPN/Grand Total.
     * Diskon kini PER ITEM (persen) — subtotal baris sudah NET. Header:
     *   total_amount    = Σ (qty × unit_price)            [bruto, basis nilai]
     *   discount_amount = Σ diskon per baris = bruto − Σ subtotal_net  [agregat]
     *   DPP             = Σ subtotal_net (= bruto − diskon, tak pernah negatif)
     *   PPN             = DPP × ppn_percent%
     *   GrandTotal      = DPP + PPN
     */
    private function recalcTotal(GoodsReceipt $grn): void
    {
        $items = $grn->items()->get(['qty_received', 'unit_price', 'subtotal']);
        $gross = round($items->reduce(fn ($c, $it) => $c + (float) $it->qty_received * (float) $it->unit_price, 0.0), 2);
        $net   = round($items->reduce(fn ($c, $it) => $c + (float) $it->subtotal, 0.0), 2);
        $discount = round(max(0, $gross - $net), 2);
        $dpp   = max(0, $net);
        $ppn   = round($dpp * ((float) $grn->ppn_percent) / 100, 2);

        $grn->update([
            'total_amount'    => $gross,
            'discount_amount' => $discount,
            'ppn_amount'      => $ppn,
            'grand_total'     => round($dpp + $ppn, 2),
        ]);
    }

    private function generateGrnNumber(?string $receiptDate): string
    {
        $date = $receiptDate ? Carbon::parse($receiptDate) : now();
        $prefix = 'GRN-' . $date->format('Ym') . '-';

        $last = GoodsReceipt::withTrashed()
            ->where('grn_number', 'like', $prefix . '%')
            ->orderByDesc('grn_number')
            ->value('grn_number');

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }
        return $prefix . sprintf('%04d', $next);
    }

    private function itemRow(GoodsReceiptItem $it): array
    {
        $resolved = $this->resolveMasterRow($it->item_type, $it->item_id);
        return [
            'id'           => $it->id,
            'po_item_id'   => $it->po_item_id,
            'item_type'    => $it->item_type,
            'item_id'      => $it->item_id,
            'item_code'    => $resolved['code'] ?? null,
            'item_name'    => $resolved['name'] ?? '-',
            'item_unit'    => $resolved['unit'] ?? null,
            'qty_received'     => (float) $it->qty_received,
            'batch_no'         => $it->batch_no,
            'expiry_date'      => optional($it->expiry_date)->toDateString(),
            'unit_price'       => (float) $it->unit_price,
            'discount_percent' => (float) $it->discount_percent,
            'subtotal'         => (float) $it->subtotal,
            'notes'            => $it->notes,
        ];
    }

    private function resolveMasterRow(string $type, string $itemId): ?array
    {
        $row = match ($type) {
            'MEDICATION' => Medication::find($itemId),
            'BHP'        => BhpItem::find($itemId),
            'IOL'        => IolItem::find($itemId),
        };
        if (!$row) return null;
        return [
            'code' => $row->code ?? null,
            'name' => $row->name ?? $row->brand ?? '-',
            'unit' => $row->unit_kecil ?? $row->unit ?? null,
        ];
    }
}
