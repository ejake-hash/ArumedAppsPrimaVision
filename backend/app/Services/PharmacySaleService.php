<?php

namespace App\Services;

use App\Models\ClinicProfile;
use App\Models\InventoryStock;
use App\Models\Medication;
use App\Models\PharmacySale;
use App\Models\PharmacySaleItem;
use App\Models\SystemLog;
use App\Services\Concerns\RetriesUniqueNumber;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PharmacySaleService — Penjualan obat bebas lepas (POS apotek).
 *
 * Berdiri sendiri, TIDAK terikat ke Visit/RME/Prescription/BillingInvoice.
 * Pembeli boleh anonim. Hanya obat golongan bebas yang boleh dijual.
 * Harga = harga jual obat dari Buku Tarif (medication_tariffs, baris insurer UMUM).
 * Stok dipotong dari unit FARMASI (FEFO) saat checkout; dikembalikan saat batal.
 */
class PharmacySaleService
{
    use RetriesUniqueNumber;

    public function __construct(
        private readonly Request $request,
        private readonly InventoryStockService $stockService,
    ) {}

    // =========================================================================
    // CHECKOUT (jual + bayar, 1-step)
    // =========================================================================

    public function checkout(array $data): PharmacySale
    {
        [$resolved, $subtotal] = $this->resolveItems($data['items'] ?? []);
        [$gDiscAmt, $gDiscPct, $total] = $this->applyGlobalDiscount($subtotal, $data);

        $paid = (float) ($data['paid_amount'] ?? 0);
        if ($paid < $total) {
            throw new \Exception('Uang dibayar kurang dari total tagihan.', 422);
        }

        $userId = auth('api')->id();

        // Nomor sale via count+1 bisa tabrakan saat 2 kasir checkout berbarengan →
        // unique-violation. Retry SELURUH transaksi (generate nomor baru tiap attempt).
        return $this->createWithRetry(fn () => DB::transaction(function () use ($resolved, $subtotal, $gDiscAmt, $gDiscPct, $total, $paid, $data, $userId) {
            $sale = $this->persistSale($resolved, [
                'buyer_name'       => $data['buyer_name']  ?? null,
                'buyer_phone'      => $data['buyer_phone'] ?? null,
                'subtotal'         => $subtotal,
                'discount'         => $gDiscAmt,
                'discount_percent' => $gDiscPct,
                'total'            => $total,
                'payment_method'   => $data['payment_method'] ?? 'CASH',
                'paid_amount'      => $paid,
                'change_amount'    => $paid - $total,
                'status'           => PharmacySale::STATUS_PAID,
                'channel'          => PharmacySale::CHANNEL_FARMASI,
                'sold_by_id'       => auth('api')->user()?->employee_id,
                'notes'            => $data['notes'] ?? null,
            ]);

            $this->log($userId, 'PHARMACY_SALE', PharmacySale::class, $sale->id,
                "Penjualan obat bebas {$sale->sale_number} — " . count($resolved) . " item, total Rp " . number_format($total, 0, ',', '.'));

            return $sale->load('items.medication', 'soldBy');
        }));
    }

    // =========================================================================
    // HANDOFF FARMASI → KASIR (obat bebas dibayar di kasir)
    //
    // Farmasi menyiapkan keranjang lalu "Tagih ke Kasir": stok LANGSUNG dipotong
    // (reserve, konsisten dgn checkout) & sale dibuat status PENDING/channel KASIR
    // tanpa nilai bayar. Kasir menutup pembayaran (settlePayment) → PAID + kwitansi.
    // Bila batal sebelum/ sesudah bayar, cancel() mengembalikan stok seperti biasa.
    // =========================================================================

    public function createPending(array $data): PharmacySale
    {
        [$resolved, $subtotal] = $this->resolveItems($data['items'] ?? []);
        [$gDiscAmt, $gDiscPct, $total] = $this->applyGlobalDiscount($subtotal, $data);

        $userId = auth('api')->id();

        return $this->createWithRetry(fn () => DB::transaction(function () use ($resolved, $subtotal, $gDiscAmt, $gDiscPct, $total, $data, $userId) {
            $sale = $this->persistSale($resolved, [
                'buyer_name'       => $data['buyer_name']  ?? null,
                'buyer_phone'      => $data['buyer_phone'] ?? null,
                'subtotal'         => $subtotal,
                'discount'         => $gDiscAmt,
                'discount_percent' => $gDiscPct,
                'total'            => $total,
                'payment_method'   => 'CASH',   // sementara; ditentukan Kasir saat settle
                'paid_amount'      => 0,
                'change_amount'    => 0,
                'status'           => PharmacySale::STATUS_PENDING,
                'channel'          => PharmacySale::CHANNEL_KASIR,
                'sold_by_id'       => auth('api')->user()?->employee_id,
                'notes'            => $data['notes'] ?? null,
            ]);

            $this->log($userId, 'PHARMACY_SALE_TO_KASIR', PharmacySale::class, $sale->id,
                "Obat bebas ditagihkan ke Kasir {$sale->sale_number} — " . count($resolved) . " item, total Rp " . number_format($total, 0, ',', '.'));

            return $sale->load('items.medication', 'soldBy');
        }));
    }

    /**
     * Kasir menutup pembayaran penjualan PENDING → PAID (catat metode/bayar/kembali
     * + kasir & waktu). Lock baris + cek ulang status di dalam transaksi (anti
     * double-settle). Stok TIDAK disentuh (sudah dipotong saat createPending).
     */
    public function settlePayment(string $saleId, array $data): PharmacySale
    {
        $sale = PharmacySale::findOrFail($saleId);

        $userId     = auth('api')->id();
        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($sale, $data, $userId, $employeeId) {
            $locked = PharmacySale::whereKey($sale->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== PharmacySale::STATUS_PENDING) {
                throw new \Exception('Penjualan ini sudah dibayar atau dibatalkan.', 422);
            }

            $total = (float) $locked->total;
            $paid  = (float) ($data['paid_amount'] ?? 0);
            if ($paid < $total) {
                throw new \Exception('Uang dibayar kurang dari total tagihan.', 422);
            }

            $method = in_array($data['payment_method'] ?? 'CASH', PharmacySale::PAYMENT_METHODS, true)
                ? $data['payment_method'] : 'CASH';

            $locked->update([
                'payment_method' => $method,
                'paid_amount'    => $paid,
                'change_amount'  => $paid - $total,
                'status'         => PharmacySale::STATUS_PAID,
                'settled_by_id'  => $employeeId,
                'settled_at'     => now(),
            ]);

            $this->log($userId, 'PHARMACY_SALE_SETTLE', PharmacySale::class, $locked->id,
                "Kasir menutup pembayaran obat bebas {$locked->sale_number} — total Rp " . number_format($total, 0, ',', '.'));

            return $locked->fresh(['items.medication', 'soldBy', 'settledBy']);
        });
    }

    /** Antrean Kasir: penjualan obat bebas PENDING yang menunggu pembayaran. */
    public function listPending(): \Illuminate\Support\Collection
    {
        return PharmacySale::with(['items.medication', 'soldBy:id,name'])
            ->where('status', PharmacySale::STATUS_PENDING)
            ->where('channel', PharmacySale::CHANNEL_KASIR)
            ->orderBy('created_at')
            ->get();
    }

    /** Data kwitansi obat bebas (kop klinik + transaksi + item) untuk cetak Kasir. */
    public function saleReceipt(string $id): array
    {
        $sale   = PharmacySale::with(['items.medication', 'soldBy', 'settledBy'])->findOrFail($id);
        $clinic = ClinicProfile::first();

        return [
            'clinic' => [
                'name'    => $clinic?->clinic_name,
                'address' => $clinic?->address,
                'phone'   => $clinic?->phone,
                'email'   => $clinic?->email,
            ],
            'sale' => [
                'id'             => $sale->id,
                'sale_number'    => $sale->sale_number,
                'status'         => $sale->status,
                'channel'        => $sale->channel,
                'buyer_name'     => $sale->buyer_name,
                'buyer_phone'    => $sale->buyer_phone,
                'subtotal'       => (float) $sale->subtotal,
                'discount'       => (float) $sale->discount,
                'total'          => (float) $sale->total,
                'payment_method' => $sale->payment_method,
                'paid_amount'    => (float) $sale->paid_amount,
                'change_amount'  => (float) $sale->change_amount,
                'created_at'     => $sale->created_at,
                'settled_at'     => $sale->settled_at,
                'sold_by'        => $sale->soldBy?->name,
                'settled_by'     => $sale->settledBy?->name,
            ],
            'items' => $sale->items->map(fn ($it) => [
                'medication_name' => $it->medication_name,
                'unit_price'      => (float) $it->unit_price,
                'quantity'        => (int) $it->quantity,
                'discount_amount' => (float) $it->discount_amount,
                'total_price'     => (float) $it->total_price,
            ])->all(),
        ];
    }

    // =========================================================================
    // CANCEL / VOID (kembalikan stok)
    // =========================================================================

    public function cancel(string $saleId, ?string $reason = null): PharmacySale
    {
        $userId     = auth('api')->id();
        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($saleId, $reason, $userId, $employeeId) {
            // Kunci baris + recheck status DI DALAM transaksi (anti race double-cancel): tanpa
            // ini dua cancel() konkuren sama-sama lolos guard luar-txn → restockFarmasi 2×
            // (stok hantu). Pola sama settlePayment (:129); idempoten via recheck CANCELLED.
            $sale = PharmacySale::with('items')->whereKey($saleId)->lockForUpdate()->first();
            if (! $sale) {
                throw new \Exception('Penjualan tidak ditemukan.', 404);
            }
            if ($sale->status === PharmacySale::STATUS_CANCELLED) {
                throw new \Exception('Penjualan ini sudah dibatalkan.', 422);
            }
            // Penjualan LUNAS (PAID) = terminal — tak boleh dibatalkan langsung: cancel
            // me-restock stok tapi TIDAK membalik pembayaran (paid_amount/change tetap,
            // tanpa catatan refund) → stok bertambah hantu + revenue tetap terhitung
            // (desync uang↔stok). Sejajar KasirService::cancelInvoice yang juga menolak
            // invoice PAID. Hanya PENDING (channel KASIR, paid_amount=0) yang aman dibatalkan
            // (melepas reserve stok). Void kas harus lewat jalur retur/refund yg mencatat pembalikan.
            if ($sale->status === PharmacySale::STATUS_PAID) {
                throw new \Exception('Penjualan sudah LUNAS — tidak bisa dibatalkan langsung. Gunakan jalur retur/refund agar pembayaran ikut dibalik.', 422);
            }

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

    /**
     * Reaper: batalkan penjualan PENDING (channel KASIR) yang menggantung > TTL jam.
     * createPending() sudah MENGONSUMSI stok (reserve) tapi tanpa auto-release — bila
     * kasir tak pernah settlePayment/cancel, stok fisik vs sistem drift & alert low-stock
     * salah. cancel() (yang kini menolak PAID) me-restock via consumed_batches. Dipanggil
     * scheduler pharmacy:release-stale-pending. Aman: hanya PENDING (paid_amount=0).
     *
     * @return array{released:int,failed:int,scanned:int}
     */
    public function releaseStalePending(int $ttlHours = 24): array
    {
        $cutoff = now()->subHours(max(1, $ttlHours));
        $ids = PharmacySale::where('status', PharmacySale::STATUS_PENDING)
            ->where('channel', PharmacySale::CHANNEL_KASIR)
            ->where('created_at', '<', $cutoff)
            ->pluck('id');

        $released = 0;
        $failed   = 0;
        foreach ($ids as $id) {
            try {
                $this->cancel($id, "Auto-release: PENDING > {$ttlHours} jam tanpa pembayaran di kasir");
                $released++;
            } catch (\Throwable $e) {
                $failed++;
                \Illuminate\Support\Facades\Log::warning("release-stale-pending gagal sale {$id}: " . $e->getMessage());
            }
        }

        return ['released' => $released, 'failed' => $failed, 'scanned' => $ids->count()];
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

    /**
     * Resolve + validasi tiap item (golongan, HJA, stok cukup) sebelum simpan.
     * Return [resolved[], subtotal]. Dipakai bersama checkout & createPending.
     */
    private function resolveItems(array $rawItems): array
    {
        if (empty($rawItems)) {
            throw new \Exception('Keranjang penjualan kosong.', 422);
        }

        $resolved = [];
        $subtotal = 0.0;
        foreach ($rawItems as $row) {
            $med = Medication::findOrFail($row['medication_id']);
            $this->assertObatBolehDijualBebas($med);

            $hja = $this->resolveHja($med->id);
            if ($hja <= 0) {
                throw new \Exception(
                    "Obat {$med->name} belum punya harga jual. Set dulu di Buku Tarif (penjamin UMUM).",
                    422
                );
            }

            $qty = (int) ($row['quantity'] ?? 0);
            if ($qty < 1) {
                throw new \Exception("Jumlah obat {$med->name} minimal 1.", 422);
            }

            // excludeExpired: cek kecukupan HARUS mengabaikan batch kedaluwarsa agar cocok
            // dgn consume() (kalau tidak: lolos di sini lalu gagal 422 "stok tak cukup").
            $onHand = $this->stockService->onHand('MEDICATION', $med->id, InventoryStock::LOC_FARMASI, true);
            if ($onHand < $qty) {
                throw new \Exception(
                    "Stok unit FARMASI untuk {$med->name} tidak mencukupi. Tersedia: {$onHand}, dibutuhkan: {$qty}.",
                    422
                );
            }

            $gross   = $hja * $qty;
            $discAmt = (float) ($row['discount_amount'] ?? 0);
            $discPct = (float) ($row['discount_percent'] ?? 0);
            // Bila persen diisi tanpa amount → derive amount dari gross.
            if ($discPct > 0 && $discAmt <= 0) {
                $discAmt = round($gross * $discPct / 100, 2);
            }
            $discAmt = min($discAmt, $gross);           // diskon tak boleh > gross
            $netLine = $gross - $discAmt;

            $resolved[] = [
                'med'      => $med,
                'hja'      => $hja,
                'qty'      => $qty,
                'disc_amt' => $discAmt,
                'disc_pct' => $discPct,
                'total'    => $netLine,
            ];
            $subtotal += $netLine;
        }

        return [$resolved, $subtotal];
    }

    /** Terapkan diskon global (Rp / persen) → [amount, percent, total]. */
    private function applyGlobalDiscount(float $subtotal, array $data): array
    {
        $gDiscAmt = (float) ($data['discount'] ?? 0);
        $gDiscPct = (float) ($data['discount_percent'] ?? 0);
        if ($gDiscPct > 0 && $gDiscAmt <= 0) {
            $gDiscAmt = round($subtotal * $gDiscPct / 100, 2);
        }
        $gDiscAmt = min($gDiscAmt, $subtotal);

        return [$gDiscAmt, $gDiscPct, $subtotal - $gDiscAmt];
    }

    /**
     * Buat PharmacySale + item + potong stok FARMASI (FEFO). Nomor sale digenerate
     * di sini (dalam createWithRetry transaksi pemanggil). consumed_batches disimpan
     * agar cancel() bisa restock ke batch asli.
     */
    private function persistSale(array $resolved, array $attrs): PharmacySale
    {
        $sale = PharmacySale::create(array_merge(
            ['sale_number' => $this->generateSaleNumber()],
            $attrs
        ));

        foreach ($resolved as $r) {
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

        return $sale;
    }

    /**
     * Resolve harga jual obat dari Buku Tarif (medication_tariffs, baris insurer UMUM).
     * Harga obat = harga tunggal (non per-penjamin) → disimpan di baris UMUM.
     */
    private function resolveHja(string $medicationId): float
    {
        $umumId = $this->umumInsurerId();
        if (! $umumId) {
            return 0;
        }
        return (float) (DB::table('medication_tariffs')
            ->where('medication_id', $medicationId)
            ->where('insurer_id', $umumId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->value('price') ?? 0);
    }

    /** Cache id insurer sistem UMUM. */
    private ?string $umumInsurerIdCache = null;
    private function umumInsurerId(): ?string
    {
        if ($this->umumInsurerIdCache === null) {
            $this->umumInsurerIdCache = \App\Models\Insurer::where('is_system', true)
                ->where('type', 'UMUM')->value('id') ?? '';
        }
        return $this->umumInsurerIdCache ?: null;
    }

    /**
     * Kebijakan POS: SEMUA golongan obat boleh dijual di Penjualan Obat Bebas
     * (keputusan owner — RS mata tidak punya stok narkotika/psikotropika; master
     * `golongan` juga tak seragam sehingga filter kata-kunci dulu menyembunyikan
     * hampir semua obat). Gate nyata = harga jual (HJA) > 0, dicek di checkout().
     *
     * Method dipertahankan sebagai satu titik kebijakan bila kelak perlu dibatasi
     * lagi (mis. blokir narkotika/psikotropika saat stok semacam itu diadakan).
     */
    private function assertObatBolehDijualBebas(Medication $med): void
    {
        // Tidak ada pembatasan golongan — sengaja no-op.
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
                $addQty = (float) ($b['qty'] ?? 0);
                if ($addQty <= 0) continue;
                // upsertStock = sumber tunggal (batch_no NULL tidak diduplikasi).
                $this->stockService->upsertStock(
                    'MEDICATION', $medicationId, InventoryStock::LOC_FARMASI,
                    $b['batch_no'] ?? null, $addQty, $b['expiry_date'] ?? null
                );
            }
            return;
        }

        // Fallback (tak ada catatan batch): tambah batch retur.
        $this->stockService->upsertStock(
            'MEDICATION', $medicationId, InventoryStock::LOC_FARMASI,
            'RETUR-' . now()->format('Ymd'), (float) $qty
        );
    }

    /** Nomor transaksi: INV-APT/{clinic_code}/{Y}/{m}/{seq} (counter per bulan). */
    private function generateSaleNumber(): string
    {
        $code  = ClinicProfile::first()?->clinic_code ?? 'KMA';
        $year  = now()->format('Y');
        $month = now()->format('m');
        $prefix = "INV-APT/{$code}/";

        // withTrashed: row yang di-soft-delete tetap menempati nomor (unique
        // constraint mencakup baris soft-deleted) → count tanpa trashed bisa
        // menghasilkan nomor yang menabrak nomor lama. Pakai MAX seq, bukan count,
        // agar tetap benar walau ada gap akibat penghapusan.
        $last = PharmacySale::withTrashed()
            ->where('sale_number', 'like', $prefix . $year . '/' . $month . '/%')
            ->orderByDesc('sale_number')
            ->value('sale_number');

        $next = 1;
        if ($last && preg_match('#/(\d+)$#', $last, $m)) {
            $next = (int) $m[1] + 1;
        }

        $seq = str_pad((string) $next, 3, '0', STR_PAD_LEFT);

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
