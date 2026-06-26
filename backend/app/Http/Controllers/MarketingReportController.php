<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\MarketingEvent;
use App\Services\KasirService;
use App\Services\MarketingMasterService;
use App\Services\MarketingReportService;
use App\Services\MarketingSurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Laporan Marketing — daftar pasien siap-olah untuk campaign (READ-ONLY + export).
 * Gating: permission:marketing.read (grup route di api.php).
 */
class MarketingReportController extends Controller
{
    public function __construct(
        private readonly MarketingReportService $service,
        private readonly KasirService $kasir,
        private readonly MarketingMasterService $master,
        private readonly MarketingSurveyService $survey,
    ) {}

    // GET /laporan-marketing?service_type=RJ|RI|BEDAH&from=&to=&insurer_id=
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => 'nullable|in:RJ,RI,BEDAH',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
            'insurer_id'   => 'nullable|uuid|exists:insurers,id',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->service->getList([
                'service_type' => $validated['service_type'] ?? 'RJ',
                'from'         => $validated['from'] ?? null,
                'to'           => $validated['to'] ?? null,
                'insurer_id'   => $validated['insurer_id'] ?? null,
            ]),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    // GET /laporan-marketing/notifications?from=&to=
    // Daftar notifikasi siap-hubungi (kontrol, tindakan terjadwal, ulang tahun, follow-up nyeri).
    // Tanpa from/to = perilaku default (7 hari ke depan; nyeri 7 hari ke belakang).
    public function notifications(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->service->getNotifications([
                'from' => $validated['from'] ?? null,
                'to'   => $validated['to'] ?? null,
            ]),
            'message' => 'Berhasil',
            'errors'  => null,
        ]);
    }

    // GET /laporan-marketing/kwitansi/{invoiceId}
    // Payload kwitansi cetak — IDENTIK dgn cetak Kasir (reuse KasirService::generateReceipt).
    // Hanya untuk invoice LUNAS (PAID); selain itu pasien dianggap masih di Kasir.
    public function kwitansi(string $invoiceId): JsonResponse
    {
        $invoice = BillingInvoice::find($invoiceId);

        if (! $invoice || $invoice->status !== 'PAID') {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Kwitansi belum terbit, Pasien Sedang di Kasir',
                'errors'  => null,
            ], 422);
        }

        try {
            $receipt = $this->kasir->generateReceipt($invoiceId);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage() ?: 'Gagal menyiapkan dokumen kwitansi',
                'errors'  => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $receipt,
            'message' => 'Data kwitansi siap cetak',
            'errors'  => null,
        ]);
    }

    // GET /laporan-marketing/export-csv?service_type=&from=&to=  (?format=xlsx untuk Excel)
    public function export(Request $request): Response
    {
        $validated = $request->validate([
            'service_type' => 'nullable|in:RJ,RI,BEDAH',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
            'insurer_id'   => 'nullable|uuid|exists:insurers,id',
        ]);

        $csv = $this->service->getCsvExport([
            'service_type' => $validated['service_type'] ?? 'RJ',
            'from'         => $validated['from'] ?? null,
            'to'           => $validated['to'] ?? null,
            'insurer_id'   => $validated['insurer_id'] ?? null,
        ]);

        return $this->csvOrXlsx(
            $request,
            $csv,
            'laporan-marketing-' . now()->format('Ymd'),
            'Marketing'
        );
    }

    // ═══════════════════════════ DASHBOARD & ANALITIK ═══════════════════════════

    // GET /laporan-marketing/dashboard-penjamin?from=&to=&group_by=jenis|penjamin&insurer_id=
    public function dashboardPenjamin(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from'       => 'nullable|date',
            'to'         => 'nullable|date|after_or_equal:from',
            'group_by'   => 'nullable|in:jenis,penjamin',
            'insurer_id' => 'nullable|uuid|exists:insurers,id',
        ]);

        return $this->json($this->service->getPayerDashboard(
            $v['from'] ?? null,
            $v['to'] ?? null,
            $v['group_by'] ?? 'jenis',
            $v['insurer_id'] ?? null,
        ));
    }

    // GET /laporan-marketing/dashboard-penjamin/export?from=&to=&group_by=&insurer_id=  (?format=xlsx)
    public function dashboardPenjaminExport(Request $request): Response
    {
        $v = $request->validate([
            'from'       => 'nullable|date',
            'to'         => 'nullable|date|after_or_equal:from',
            'group_by'   => 'nullable|in:jenis,penjamin',
            'insurer_id' => 'nullable|uuid|exists:insurers,id',
        ]);

        $csv = $this->service->getPayerDashboardCsv(
            $v['from'] ?? null,
            $v['to'] ?? null,
            $v['group_by'] ?? 'jenis',
            $v['insurer_id'] ?? null,
        );

        return $this->csvOrXlsx($request, $csv, 'dashboard-penjamin-' . now()->format('Ymd'), 'Penjamin');
    }

    // GET /laporan-marketing/top-wilayah?from=&to=&level=kota|kecamatan&limit=
    public function topWilayah(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from'  => 'nullable|date',
            'to'    => 'nullable|date|after_or_equal:from',
            'level' => 'nullable|in:kota,kecamatan',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return $this->json($this->service->getTopWilayah(
            $v['from'] ?? null,
            $v['to'] ?? null,
            $v['level'] ?? 'kota',
            $v['limit'] ?? 15,
        ));
    }

    // ═══════════════════════════ SURVEI KEPUASAN ═══════════════════════════

    // GET /laporan-marketing/survei?from=&to=
    public function survei(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        return $this->json($this->survey->getReport($v['from'] ?? null, $v['to'] ?? null));
    }

    // PUT /laporan-marketing/survei/config  (marketing.write) — set/ubah URL Sheet survei dari UI.
    public function surveiConfig(Request $request): JsonResponse
    {
        $v = $request->validate([
            'sheet_url' => 'nullable|url|max:1000',
        ]);

        $this->survey->setSheetUrl($v['sheet_url'] ?? null);

        return $this->json(['sheet_url' => $this->survey->getSheetUrl()], 'Konfigurasi survei disimpan');
    }

    // POST /laporan-marketing/survei/sync  (marketing.write) — tarik manual sekarang.
    public function syncSurvei(): JsonResponse
    {
        return $this->json($this->survey->sync());
    }

    // ═══════════════════════════ MONITORING KERJASAMA (CRUD) ═══════════════════════════

    public function kerjasamaIndex(Request $request): JsonResponse
    {
        $f = $request->only(['search', 'partner_type', 'is_active', 'per_page']);

        return $this->json($this->master->indexKerjasama($f));
    }

    public function kerjasamaStore(Request $request): JsonResponse
    {
        $v = $this->validateKerjasama($request);

        return $this->json($this->master->storeKerjasama($v), 'Kerjasama dibuat', 201);
    }

    public function kerjasamaUpdate(Request $request, string $id): JsonResponse
    {
        $v = $this->validateKerjasama($request);

        return $this->json($this->master->updateKerjasama($id, $v), 'Kerjasama diperbarui');
    }

    public function kerjasamaDestroy(string $id): JsonResponse
    {
        $this->master->deleteKerjasama($id);

        return $this->json(null, 'Kerjasama dihapus');
    }

    private function validateKerjasama(Request $request): array
    {
        return $request->validate([
            'insurer_id'     => 'nullable|uuid|exists:insurers,id',
            'partner_name'   => 'required|string|max:255',
            'partner_type'   => 'nullable|in:ASURANSI,PERUSAHAAN,TPA,LAINNYA',
            'pks_number'     => 'nullable|string|max:255',
            'pks_start_date' => 'nullable|date',
            'addendum_date'  => 'nullable|date',
            'pks_end_date'   => 'nullable|date',
            'notes'          => 'nullable|string',
            'pic_name'       => 'nullable|string|max:255',
            'pic_phone'      => 'nullable|string|max:30',
            'is_active'      => 'nullable|boolean',
        ]);
    }

    // ═══════════════════════════ PROGRAM & EVENT (CRUD) ═══════════════════════════

    public function eventIndex(Request $request): JsonResponse
    {
        $f = $request->only(['search', 'per_page']);

        return $this->json($this->master->indexEvent($f));
    }

    public function eventStore(Request $request): JsonResponse
    {
        $v = $this->validateEvent($request);

        return $this->json($this->master->storeEvent($v), 'Event dibuat', 201);
    }

    public function eventUpdate(Request $request, string $id): JsonResponse
    {
        $v = $this->validateEvent($request);

        return $this->json($this->master->updateEvent($id, $v), 'Event diperbarui');
    }

    public function eventDestroy(string $id): JsonResponse
    {
        $this->master->deleteEvent($id);

        return $this->json(null, 'Event dihapus');
    }

    // GET /laporan-marketing/events/{id}/participants
    public function eventParticipants(string $id): JsonResponse
    {
        return $this->json($this->master->participants($id));
    }

    // POST /laporan-marketing/events/{id}/sync  (marketing.write) — tarik peserta manual.
    public function eventSync(string $id): JsonResponse
    {
        $event = MarketingEvent::findOrFail($id);

        return $this->json($this->master->syncParticipants($event));
    }

    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'name'                  => 'required|string|max:255',
            'event_date'            => 'nullable|date',
            'location'              => 'nullable|string|max:255',
            'description'           => 'nullable|string',
            'participant_sheet_url' => 'nullable|url|max:1000',
            'participant_gid'       => 'nullable|string|max:50',
            'is_active'             => 'nullable|boolean',
        ]);
    }

    /** Bungkus JSON standar modul ini. */
    private function json(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => $status < 400,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    /** Kirim CSV string sbg file CSV (default) atau XLSX bila ?format=xlsx. */
    private function csvOrXlsx(Request $request, string $csv, string $baseName, string $sheetTitle): Response
    {
        if (strtolower((string) $request->query('format')) === 'xlsx') {
            $xlsx = \App\Support\SpreadsheetHelper::csvToXlsx($csv, $sheetTitle);

            return response($xlsx, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$baseName}.xlsx\"",
            ]);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$baseName}.csv\"",
        ]);
    }
}
