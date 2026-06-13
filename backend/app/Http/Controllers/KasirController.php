<?php

namespace App\Http\Controllers;

use App\Services\KasirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KasirController extends Controller
{
    public function __construct(private readonly KasirService $service) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    /** GET /kasir/antrian */
    public function indexAntrian(): JsonResponse
    {
        return $this->ok($this->service->getPatientQueue());
    }

    public function panggilAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->panggilAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dipanggil');
    }

    public function selesaiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->selesaiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Antrian selesai');
    }

    public function lewatiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->lewatiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dipindah ke akhir antrean');
    }

    // =========================================================================
    // INVOICE
    // =========================================================================

    /**
     * GET /kasir/invoice
     * Query: tanggal, status, search, per_page
     */
    public function indexInvoice(Request $request): JsonResponse
    {
        return $this->ok($this->service->getInvoiceList(
            $request->only(['tanggal', 'status', 'search', 'per_page', 'jenis_pelayanan'])
        ));
    }

    /** GET /kasir/invoice/{visitId} */
    public function showInvoice(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getInvoiceByVisit($visitId));
    }

    /**
     * GET /kasir/insurance-warning/{visitId}
     * Flag warning untuk UI kasir kalau visit pakai ASURANSI/PERUSAHAAN dan
     * status verifikasi-nya PENDING/ISSUE. Bukan blocker keras.
     */
    public function insuranceWarning(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getInsuranceWarning($visitId));
    }

    /**
     * GET /kasir/invoice/{id}/coverages
     * Split COB invoice: porsi tanggungan tiap penjamin + sisa pasien.
     * Non-COB → is_cob=false, patient_amount=total.
     */
    public function invoiceCoverages(string $id): JsonResponse
    {
        try {
            $invoice = \App\Models\BillingInvoice::with('coverages.insurer')->findOrFail($id);
        } catch (\Throwable $e) {
            return $this->error('Invoice tidak ditemukan.', 404);
        }
        return $this->ok($this->service->calculateCOB($invoice));
    }

    /**
     * POST /kasir/invoice/{visitId}/generate
     * Consolidate semua tindakan + obat + IOL → buat invoice baru.
     */
    public function generateInvoice(string $visitId): JsonResponse
    {
        try {
            $invoice = $this->service->consolidateBilling($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($invoice, 'Invoice berhasil di-generate', 201);
    }

    /**
     * PUT /kasir/invoice/{id}
     * Body: { discount, tax, notes }
     */
    public function updateInvoice(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'discount'         => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'tax'              => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string|max:500',
        ]);

        try {
            $invoice = $this->service->updateInvoice($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($invoice, 'Invoice diperbarui');
    }

    /** POST /kasir/invoice/{id}/finalize */
    public function finalizeInvoice(string $id): JsonResponse
    {
        try {
            $invoice = $this->service->finalizeInvoice($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($invoice, 'Invoice dikunci. Siap untuk pembayaran.');
    }

    /**
     * POST /kasir/invoice/{id}/resync-tarif
     * Sinkron harga SEMUA baris bertarif ke tarif terkini (Buku Tarif/PKS).
     * Tagihan belum dibayar (DRAFT/FINALIZED); PAID/PARTIALLY_PAID/CANCELLED ditolak.
     */
    public function resyncTarif(string $id): JsonResponse
    {
        try {
            $updated = $this->service->resyncTarifPrices($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        $msg = $updated > 0
            ? "{$updated} harga item diperbarui dari tarif terkini."
            : 'Harga sudah sesuai tarif terkini.';

        return $this->ok(['updated' => $updated], $msg);
    }

    /**
     * POST /kasir/invoice/{id}/bayar
     * Body: { paid_amount, payment_method, notes }
     */
    public function bayarInvoice(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'paid_amount'    => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:CASH,CREDIT_CARD,TRANSFER,BPJS',
            'cash_received'  => 'nullable|numeric|min:0',   // uang tunai fisik (utk kembalian kwitansi)
            'notes'          => 'nullable|string|max:255',
        ]);

        try {
            $invoice = $this->service->processPayment($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        $message = $invoice->status === 'PAID'
            ? 'Pembayaran lunas. Kunjungan selesai.'
            : 'Pembayaran sebagian diterima.';

        return $this->ok($invoice, $message);
    }

    /**
     * POST /kasir/invoice/{id}/confirm-coverage
     * Konfirmasi tagihan ditanggung penuh asuransi — pasien tidak membayar.
     * Body: { notes? }
     */
    public function confirmCoverage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:255',
        ]);

        try {
            $invoice = $this->service->confirmInsuranceCoverage($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($invoice, 'Tagihan dikonfirmasi ditanggung asuransi. Kunjungan selesai.');
    }

    /**
     * POST /kasir/invoice/{id}/confirm-bpjs
     * Konfirmasi kunjungan BPJS — pasien tidak membayar (ditagih via klaim INA-CBG).
     * Body: { notes? }
     */
    public function confirmBpjs(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:255',
        ]);

        try {
            $invoice = $this->service->confirmBpjsCoverage($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($invoice, 'Kunjungan BPJS dikonfirmasi. Kunjungan selesai.');
    }

    /**
     * POST /kasir/invoice/{id}/settle-zero
     * Selesaikan tagihan Rp 0 (diskon/penghapusan 100% RS/dokter, pasien UMUM).
     * Body: { notes? }
     */
    public function settleZero(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:255',
        ]);

        try {
            $invoice = $this->service->settleZeroInvoice($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($invoice, 'Tagihan Rp 0 diselesaikan (gratis/diskon 100%). Kunjungan selesai.');
    }

    /** POST /kasir/invoice/{id}/cancel */
    public function cancelInvoice(string $id): JsonResponse
    {
        try {
            $invoice = $this->service->cancelInvoice($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($invoice, 'Invoice dibatalkan');
    }

    // =========================================================================
    // BILLING ITEMS
    // =========================================================================

    /**
     * POST /kasir/invoice/{invoiceId}/item
     * Body: { item_type, reference_id, description, quantity, unit_price, notes }
     */
    public function storeItemInvoice(Request $request, string $invoiceId): JsonResponse
    {
        $validated = $request->validate([
            'item_type'        => 'required|in:REGISTRASI,TINDAKAN,OBAT,PENUNJANG,BHP,IOL,MEDICAL_EQUIPMENT,LAINNYA',
            'category'         => 'nullable|string|max:100',
            'reference_id'     => 'nullable|uuid',
            'description'      => 'required|string|max:255',
            'quantity'         => 'nullable|integer|min:1',
            'unit_price'       => 'required|numeric|min:0',
            'discount_amount'  => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'notes'            => 'nullable|string|max:255',
            // Khusus OBAT tambahan (Opsi A): aturan pakai → resep TAMBAHAN ke Dispensing Farmasi.
            'dosage'           => 'nullable|string|max:255',
            'instructions'     => 'nullable|string|max:255',
        ]);

        try {
            $item = $this->service->storeItemInvoice($invoiceId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($item, 'Item ditambahkan', 201);
    }

    /** PUT /kasir/invoice-item/{id} */
    public function updateItemInvoice(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'description'      => 'sometimes|string|max:255',
            'category'         => 'sometimes|nullable|string|max:100',
            'quantity'         => 'sometimes|integer|min:1',
            'unit_price'       => 'sometimes|numeric|min:0',
            'discount_amount'  => 'sometimes|nullable|numeric|min:0',
            'discount_percent' => 'sometimes|nullable|numeric|min:0|max:100',
            'notes'            => 'nullable|string|max:255',
        ]);

        try {
            $item = $this->service->updateItemInvoice($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($item, 'Item diperbarui');
    }

    /** DELETE /kasir/invoice-item/{id} */
    public function deleteItemInvoice(string $id): JsonResponse
    {
        try {
            $this->service->deleteItemInvoice($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Item dihapus');
    }

    /**
     * POST /kasir/invoice/{visitId}/absorb-item — toggle "terserap ke paket" baris
     * obat/BHP tambahan (flag di baris sumber + rebuild invoice; lihat
     * KasirService::absorbInvoiceItem).
     */
    public function absorbItem(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => 'required|in:OBAT,BHP',
            'source_id'   => 'required|uuid',
            'absorbed'    => 'required|boolean',
        ]);

        try {
            $invoice = $this->service->absorbInvoiceItem(
                $visitId,
                $validated['source_type'],
                $validated['source_id'],
                (bool) $validated['absorbed']
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($invoice, 'Status terserap paket diperbarui');
    }

    // =========================================================================
    // COB
    // =========================================================================

    /** GET /kasir/cob/{visitId} */
    public function showCob(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getCob($visitId));
    }

    /**
     * PUT /kasir/cob/{visitId}
     * Body: { penjamin1_type, penjamin1_insurer_id, penjamin2_type, penjamin2_insurer_id, notes }
     */
    public function updateCob(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'penjamin1_type'       => 'required|in:UMUM,BPJS,ASURANSI,PERUSAHAAN,SOSIAL',
            'penjamin1_insurer_id' => 'nullable|uuid|exists:insurers,id',
            'penjamin2_type'       => 'nullable|in:BPJS,ASURANSI,PERUSAHAAN',
            'penjamin2_insurer_id' => 'nullable|uuid|exists:insurers,id',
            'notes'                => 'nullable|string|max:500',
        ]);

        try {
            $cob = $this->service->updateCob($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($cob, 'COB diperbarui');
    }

    // =========================================================================
    // WATERMARK
    // =========================================================================

    /**
     * PUT /kasir/watermark
     * Body: { watermark_enabled, watermark_type (ORIGINAL|COPY|DRAFT) }
     */
    public function updateWatermark(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'watermark_enabled' => 'required|boolean',
            'watermark_type'    => 'required_if:watermark_enabled,true|nullable|in:ORIGINAL,COPY,DRAFT',
        ]);

        $this->service->updateWatermark($validated);

        return $this->ok(null, 'Setting watermark diperbarui');
    }

    /** GET /kasir/print-settings — toggle elemen cetak kwitansi/rincian. */
    public function getPrintSettings(): JsonResponse
    {
        return $this->ok($this->service->getReceiptPrintSettings());
    }

    /**
     * PUT /kasir/print-settings
     * Body: { show_logo?, show_stamp?, show_esign?, show_footer?, show_watermark? }
     */
    public function updatePrintSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'show_logo'      => 'sometimes|boolean',
            'show_stamp'     => 'sometimes|boolean',
            'show_esign'     => 'sometimes|boolean',
            'show_footer'    => 'sometimes|boolean',
            'show_watermark' => 'sometimes|boolean',
        ]);

        try {
            $settings = $this->service->updateReceiptPrintSettings($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($settings, 'Setting cetak diperbarui');
    }

    // =========================================================================
    // RECEIPT & LAPORAN
    // =========================================================================

    /**
     * GET /kasir/invoice/{id}/cetak
     * Returns structured data for PDF generation via Puppeteer.
     */
    public function cetakInvoice(string $id): JsonResponse
    {
        try {
            $receiptData = $this->service->generateReceipt($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($receiptData, 'Data kwitansi siap cetak');
    }

    /**
     * POST /kasir/invoice/{id}/email
     * Kirim kwitansi PDF ke email pasien (alternatif cetak fisik). Email
     * disimpan ke record pasien & pengiriman di-queue.
     */
    public function emailReceipt(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:255']);

        try {
            $result = $this->service->emailReceipt($id, $validated['email']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        $msg = $result['status'] === 'SENT'
            ? "Kwitansi terkirim ke {$validated['email']}"
            : "Kwitansi diantrekan untuk dikirim ke {$validated['email']}";

        return $this->ok($result, $msg);
    }

    /**
     * GET /kasir/tarif-tindakan?visit_id=…
     * Daftar tindakan + harga per-penjamin (untuk Edit Tagihan kasir).
     */
    public function tarifTindakan(Request $request): JsonResponse
    {
        $request->validate(['visit_id' => 'required|uuid|exists:visits,id']);

        return $this->ok($this->service->getTarifTindakan($request->query('visit_id')));
    }

    /**
     * GET /kasir/tarif-buku
     * Pencarian buku tarif lintas kategori (tindakan/obat/BHP/IOL/alkes) untuk
     * Edit Tagihan. Query: visit_id, q (teks), type (ALL|TINDAKAN|OBAT|BHP|IOL|MEDICAL_EQUIPMENT)
     */
    public function tarifBuku(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
            'q'        => 'required|string|max:100',
            'type'     => 'nullable|in:ALL,TINDAKAN,OBAT,BHP,IOL,MEDICAL_EQUIPMENT',
        ]);

        return $this->ok($this->service->searchTarifBuku(
            $validated['visit_id'],
            $validated['q'],
            $validated['type'] ?? 'ALL',
        ));
    }

    /**
     * GET /kasir/laporan
     * Query: tanggal
     */
    public function laporanHarian(Request $request): JsonResponse
    {
        return $this->ok($this->service->getLaporanHarian(
            $request->only(['tanggal'])
        ));
    }

    /**
     * GET /kasir/laporan/rekap
     * Query: from, to
     */
    public function laporanRekap(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        return $this->ok($this->service->getLaporanRekap(
            $request->only(['from', 'to'])
        ));
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        // Coerce non-int status (e.g. PDO SQLSTATE string from QueryException) to a valid HTTP code.
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 500;
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
