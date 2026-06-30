<?php

namespace Tests\Feature\Kasir;

use App\Models\BillingInvoice;
use App\Models\BillingItem;
use App\Models\InventoryStock;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\InventoryStockService;
use App\Services\KasirService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Karakterisasi pembatalan invoice & pengembalian stok.
 *
 * Temuan audit 30 Jun 2026: BHP/obat yang DITAMBAH MANUAL oleh kasir memotong stok
 * FARMASI seketika (disimpan di consumed_batches). Saat invoice dibatalkan terminal,
 * cancelInvoice TIDAK memanggil restock → stok bocor permanen. deleteItemInvoice
 * sudah restock; cancelInvoice harus konsisten.
 */
class CancelInvoiceRestockTest extends TestCase
{
    use RefreshDatabase;

    private function stockQty(string $bhpId): float
    {
        return (float) InventoryStock::query()
            ->where('item_type', 'BHP')
            ->where('item_id', $bhpId)
            ->where('location', InventoryStock::LOC_FARMASI)
            ->sum('qty_on_hand');
    }

    public function test_cancel_invoice_restocks_kasir_added_bhp(): void
    {
        $bhpId = (string) Str::uuid();

        // Kondisi awal: dari 100, kasir sudah consume 5 → tersisa 95 di FARMASI.
        app(InventoryStockService::class)
            ->upsertStock('BHP', $bhpId, InventoryStock::LOC_FARMASI, 'B1', 95, '2027-12-31');

        $patient = new Patient();
        $patient->forceFill(['name' => 'Pasien Uji'])->save();

        $visit = new Visit();
        $visit->forceFill([
            'patient_id'     => $patient->id,
            'visit_date'     => today()->toDateString(),
            'classification' => 'RAWAT_JALAN',
            'guarantor_type' => 'UMUM',
        ])->save();

        $invoice = new BillingInvoice();
        $invoice->forceFill([
            'visit_id'       => $visit->id,
            'invoice_number' => 'INV-TEST-001',
            'status'         => 'FINALIZED',
        ])->save();

        $item = new BillingItem();
        $item->forceFill([
            'billing_invoice_id' => $invoice->id,
            'item_type'          => 'BHP',
            'description'        => 'BHP Uji',
            'reference_id'       => $bhpId,
            'quantity'           => 5,
            'unit_price'         => 1000,
            'is_kasir_manual'    => true,
            'consumed_batches'   => [['batch_no' => 'B1', 'qty' => 5, 'expiry_date' => '2027-12-31']],
        ])->save();

        $this->assertSame(95.0, $this->stockQty($bhpId), 'Pra-kondisi: stok 95 setelah kasir consume 5.');

        app(KasirService::class)->cancelInvoice($invoice->id);

        $this->assertSame('CANCELLED', $invoice->fresh()->status);
        $this->assertSame(100.0, $this->stockQty($bhpId), 'Stok BHP kasir harus kembali ke 100 setelah invoice dibatalkan.');
    }
}
