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
            $request->only(['tanggal', 'status', 'search', 'per_page'])
        ));
    }

    /** GET /kasir/invoice/{visitId} */
    public function showInvoice(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getInvoiceByVisit($visitId));
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
            'discount' => 'nullable|numeric|min:0',
            'tax'      => 'nullable|numeric|min:0',
            'notes'    => 'nullable|string|max:500',
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
     * POST /kasir/invoice/{id}/bayar
     * Body: { paid_amount, payment_method, notes }
     */
    public function bayarInvoice(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'paid_amount'    => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:CASH,CREDIT_CARD,TRANSFER,BPJS',
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
            'item_type'    => 'required|in:REGISTRASI,TINDAKAN,OBAT,IOL,BHP,LAINNYA',
            'reference_id' => 'nullable|uuid',
            'description'  => 'required|string|max:255',
            'quantity'     => 'nullable|integer|min:1',
            'unit_price'   => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:255',
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
            'description' => 'sometimes|string|max:255',
            'quantity'    => 'sometimes|integer|min:1',
            'unit_price'  => 'sometimes|numeric|min:0',
            'notes'       => 'nullable|string|max:255',
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

    private function error(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
