<?php

namespace App\Services;

use App\Models\ClinicProfile;
use App\Models\InventoryPrice;
use App\Models\InventoryStock;
use App\Models\Medication;
use App\Models\PharmacySale;
use App\Models\PharmacySaleItem;
use App\Models\SystemLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PharmacySaleService — Penjualan obat bebas lepas (POS apotek).
 *
 * Berdiri sendiri, TIDAK terikat ke Visit/RME/Prescription/BillingInvoice.
 * Pembeli boleh anonim. Hanya obat golongan bebas yang boleh dijual.
 * Harga = HJA dari modul Penentuan Harga (inventory_prices.hja).
 * Stok dipotong dari unit FARMASI (FEFO) saat checkout; dikembalikan saat batal.
 */
class PharmacySaleService
{
    public function __construct(
        private readonly Request $request,
        private readonly InventoryStockService $stockService,
    ) {}

    // =========================================================================
    // CHECKOUT (jual + bayar, 1-step)
    // =========================================================================

    public function checkout(array $data): PharmacySale
    {
        $rawItems = $data['items'] ?? [];
        if (empty($rawItems)) {
            throw new \Exception('Keranjang penjualan kosong.', 422);
        }

        // Resolve + validasi tiap item dulu (golongan, HJA, stok cukup) sebelum simpan.
        $resolved = [];
        $subtotal = 0.0;
        foreach ($rawItems as $row) {
            $med = Medication::findOrFail($row['medication_id']);
            $this->assertObatBolehDijualBebas($med);

            $hja = $this->resolveHja($med->id);
            if ($hja <= 0) {
                throw new \Exception(
                    "Obat {$med->name} belum punya harga jual (HJA). Set dulu di Penentuan Harga.",
                    422
                );
            }

            $qty = (int) ($row['quantity'] ?? 0);
            if ($qty < 1) {
                throw new \Exception("Jumlah obat {$med->name} minimal 1.", 422);
            }

            $onHand = $this->stockService->onHand('MEDICATION', $med->id, InventoryStock::LOC_FARMASI);
            if ($onHand < $qty) {
                throw new \Exception(
                    "Stok unit FARMASI untuk {$med->name} tidak mencukupi. Tersedia: {$onHand}, dibutuhkan: {$qty}.",
                    422
                );
            }

            $gross    = $hja * $qty;
            $discAmt  = (float) ($row['discount_amount'] ?? 0);
            $discPct  = (float) ($row['discount_percent'] ?? 0);
            // Bila persen diisi tanpa amount → derive amount dari gross.
            if ($discPct > 0 && $discAmt <= 0) {
                $discAmt = round($gross * $discPct / 100, 2);
            }
            $discAmt  = min($discAmt, $gross);          // diskon tak boleh > gross
            $netLine  = $gross - $discAmt;

            $resolved[] = [
                'med'        => $med,
                'hja'        => $hja,
                'qty'        => $qty,
                'disc_amt'   => $discAmt,
                'disc_pct'   => $discPct,
                'total'      => $netLine,
            ];
            $subtotal += $netLine;
        }

        // Diskon global.
        $gDiscAmt = (float) ($data['discount'] ?? 0);
        $gDiscPct = (float) ($data['discount_percent'] ?? 0);
        if ($gDiscPct > 0 && $gDiscAmt <= 0) {
            $gDiscAmt = round($subtotal * $gDiscPct / 100, 2);
        }
        $gDiscAmt = min($gDiscAmt, $subtotal);
        $total    = $subtotal - $gDiscAmt;

        $paid = (float) ($data['paid_amount'] ?? 0);
        if ($paid < $total) {
            throw new \Exception('Uang dibayar kurang dari total tagihan.', 422);
        }
        $change = $paid - $total;

        $userId     = auth('api')->id();
        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($resolved, $subtotal, $gDiscAmt, $gDiscPct, $total, $paid, $change, $data, $userId, $employeeId) {
            $sale = PharmacySale::create([
                'sale_number'      => $this->generateSaleNumber(),
                'buyer_name'       => $data['buyer_name']  ?? null,
                'buyer_phone'      => $data['buyer_phone'] ?? null,
                'subtotal'         => $subtotal,
                'discount'         => $gDiscAmt,
                'discount_percent' => $gDiscPct,
                'total'            => $total,
                'payment_method'   => $data['payment_method'] ?? 'CASH',
                'paid_amount'      => $paid,
                'change_amount'    => $change,
                'status'           => PharmacySale::STATUS_PAID,
                'sold_by_id'       => $employeeId,
                'notes'            => $data['notes'] ?? null,
            ]);

            foreach ($resolved as $r) {
                // Potong stok unit FARMASI (FEFO) — simpan batch utk restock saat batal.
                $consumed = $this->stockService->consume('MEDICATION', $r['med']->id, (float) $r['qty'], InventoryStock::LOC_FARMASI);

                PharmacySaleItem::create([
                    'pharmacy_sale_id' => $sale->id,
                    'medication_id'    => $r['med']->id,
                    'medication_name'  => $r['med']->name,
                    'unit_price'       => $r['hja'],
                    'quantity'         => $r['qty'],
                    'discount_amount'  => $r['disc_amt'],
                    'discount_percent' => $r['disc_pct'],
                    'total_price'      => $r['total'],
                    'consumed_batches' => $consumed,
                ]);
            }

            $this->log($userId, 'PHARMACY_SALE', PharmacySale::class, $sale->id,
                "Penjualan obat bebas {$sale->sale_number} — " . count($resolved) . " item, total Rp " . number_format($total, 0, ',', '.'));

            return $sale->load('items.medication', 'soldBy');
        });
    }

    // =========================================================================
    // CANCEL / VOID (kembalikan stok)
    // =========================================================================

    public function cancel(string $saleId, ?string $reason = null): PharmacySale
    {
        $sale = PharmacySale::with('items')->findOrFail($saleId);

        if ($sale->status === PharmacySale::STATUS_CANCELLED) {
            throw new \Exception('Penjualan ini sudah dibatalkan.', 422);
        }

        $userId     = auth('api')->id();
        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($sale, $reason, $userId, $employeeId) {
            // Kembalikan stok ke unit FARMASI, jaga batch_no + expiry asli (dari consumed_batches).
            foreach ($sale->items as $item) {
                $this->restockFarmasi($item->medication_id, $item->consumed_batches ?? [], (int) $item->quantity);
            }

            $sale->update([
                'status'          => PharmacySale::STATUS_CANCELLED,
                'cancelled_by_id' => $employeeId,
                'cancelled_at'    => now(),
                'cancel_reason'   => $reason,
            ]);

            $this->log($userId, 'PHARMACY_SALE_CANCEL', PharmacySale::class, $sale->id,
                "Batal penjualan {$sale->sale_number}" . ($reason ? " — {$reason}" : ''));

            return $sale->fresh(['items.medication', 'soldBy', 'cancelledBy']);
        });
    }

    // =========================================================================
    // LIST / SHOW
    // =========================================================================

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = PharmacySale::with('soldBy:id,name')
            ->withCount('items')
            ->orderByDesc('created_at');

        // Default: hari ini. Bila filter tanggal diisi → pakai itu.
        if (! empty($filters['tanggal'])) {
            $query->whereDate('created_at', $filters['tanggal']);
        } elseif (empty($filters['all'])) {
            $query->whereDate('created_at', today());
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('sale_number', 'ilike', "%{$kw}%")
                ->orWhere('buyer_name', 'ilike', "%{$kw}%")
            );
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function show(string $id): PharmacySale
    {
        return PharmacySale::with(['items.medication', 'soldBy', 'cancelledBy'])->findOrFail($id);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Resolve HJA (Harga Jual Apotek) dari modul Penentuan Harga. */
    private function resolveHja(string $medicationId): float
    {
        return (float) (InventoryPrice::where('item_type', InventoryPrice::TYPE_MEDICATION)
            ->where('item_id', $medicationId)
            ->value('hja') ?? 0);
    }

    /**
     * Hanya obat bebas/bebas terbatas/suplemen/jamu yang boleh dijual lepas.
     * Master `golongan` tidak seragam → cek via kata kunci (mirror guard OTC).
     */
    private function assertObatBolehDijualBebas(Medication $med): void
    {
        $g = strtoupper(trim((string) $med->golongan));

        $terlarang = $g === ''
            || str_contains($g, 'KERAS')
            || str_contains($g, 'NARKOTIKA')
            || str_contains($g, 'PSIKOTROPIKA');

        $boleh = ! $terlarang && (
            str_contains($g, 'BEBAS')
            || str_contains($g, 'SUPLEMEN')
            || str_contains($g, 'JAMU')
        );

        if (! $boleh) {
            $label = $g === '' ? 'tanpa golongan' : "golongan {$med->golongan}";
            throw new \Exception(
                "Obat {$med->name} ({$label}) tidak boleh dijual bebas tanpa resep dokter. " .
                "Hanya obat bebas/bebas terbatas/suplemen/jamu yang boleh dijual lepas.",
                422
            );
        }
    }

    /**
     * Kembalikan stok ke unit FARMASI saat batal, mempertahankan batch_no & expiry
     * asli (dari consumed_batches). Fallback: bila batch tidak tercatat, buat batch
     * retur baru. Pola mirror InventoryStockService::transfer (sisi tambah).
     */
    private function restockFarmasi(string $medicationId, array $batches, int $qty): void
    {
        if (! empty($batches)) {
            foreach ($batches as $b) {
                $stock = InventoryStock::firstOrNew([
                    'item_type' => 'MEDICATION',
                    'location'  => InventoryStock::LOC_FARMASI,
                    'item_id'   => $medicationId,
                    'batch_no'  => $b['batch_no'] ?? null,
                ]);
                $stock->expiry_date = $b['expiry_date'] ?? $stock->expiry_date;
                $stock->qty_on_hand = (float) ($stock->qty_on_hand ?? 0) + (float) ($b['qty'] ?? 0);
                $stock->last_received_at = now();
                $stock->save();
            }
            return;
        }

        // Fallback (tak ada catatan batch): tambah batch retur.
        $stock = InventoryStock::firstOrNew([
            'item_type' => 'MEDICATION',
            'location'  => InventoryStock::LOC_FARMASI,
            'item_id'   => $medicationId,
            'batch_no'  => 'RETUR-' . now()->format('Ymd'),
        ]);
        $stock->qty_on_hand = (float) ($stock->qty_on_hand ?? 0) + $qty;
        $stock->last_received_at = now();
        $stock->save();
    }

    /** Nomor transaksi: INV-APT/{clinic_code}/{Y}/{m}/{seq} (counter per bulan). */
    private function generateSaleNumber(): string
    {
        $code  = ClinicProfile::first()?->clinic_code ?? 'KMA';
        $year  = now()->format('Y');
        $month = now()->format('m');
        $prefix = "INV-APT/{$code}/";

        $count = PharmacySale::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->where('sale_number', 'like', $prefix . '%')
            ->count();

        $seq = str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);

        return "{$prefix}{$year}/{$month}/{$seq}";
    }

    private function log(?string $userId, string $action, ?string $model, ?string $modelId, ?string $description): void
    {
        SystemLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $description,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
