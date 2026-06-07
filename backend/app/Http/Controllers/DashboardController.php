<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    // =========================================================================
    // STAT CARDS
    // =========================================================================

    /**
     * GET /dashboard/statistik
     * All-in-one stat cards: kunjungan, operasi, pendapatan, klaim, antrian, follow-up count, stok alert.
     */
    public function statistik(): JsonResponse
    {
        return $this->ok($this->service->getDailySummary());
    }

    /**
     * GET /dashboard/kunjungan-hari-ini
     * Query: station
     */
    public function kunjunganHariIni(Request $request): JsonResponse
    {
        return $this->ok($this->service->getKunjunganHariIni(
            $request->only(['station'])
        ));
    }

    /**
     * GET /dashboard/antrian-aktif
     * All active queues grouped by station.
     */
    public function antrianAktif(): JsonResponse
    {
        return $this->ok($this->service->getAntrianAktif());
    }

    /**
     * GET /dashboard/pendapatan
     * Query: from, to
     */
    public function pendapatan(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        return $this->ok($this->service->getRevenueSummary(
            $request->only(['from', 'to'])
        ));
    }

    // =========================================================================
    // CHARTS
    // =========================================================================

    /**
     * GET /dashboard/kunjungan-chart
     * Grafik kunjungan 7 hari terakhir (label, total, bpjs, umum, lain).
     */
    public function getVisitChart(): JsonResponse
    {
        return $this->ok($this->service->getWeeklyVisits());
    }

    /**
     * GET /dashboard/diagnosis-stats
     * Top 10 ICD-10 terbanyak bulan berjalan.
     */
    public function getDiagnosisStats(): JsonResponse
    {
        return $this->ok($this->service->getTopDiagnoses());
    }

    /**
     * GET /dashboard/pendapatan-chart
     * Tren pendapatan (kas) 7 hari terakhir — sum paid_amount per hari.
     */
    public function getRevenueChart(): JsonResponse
    {
        return $this->ok($this->service->getWeeklyRevenue());
    }

    /**
     * GET /dashboard/distribusi-penjamin
     * Query: range = hari | minggu | bulan | tahun (default hari)
     * Jumlah kunjungan per penjamin untuk rentang terpilih (donut Distribusi).
     */
    public function distribusiPenjamin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => 'nullable|in:hari,minggu,bulan,tahun',
        ]);

        return $this->ok($this->service->getGuarantorDistribution($validated['range'] ?? 'hari'));
    }

    /**
     * GET /dashboard/jam-tersibuk
     * Query: days = 1..365 (default 30)
     * Rata-rata kunjungan per jam (08–19) selama N hari terakhir.
     */
    public function jamTersibuk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        return $this->ok($this->service->getBusiestHours((int) ($validated['days'] ?? 30)));
    }

    /**
     * GET /dashboard/laporan/kunjungan
     * Query: from, to
     */
    public function laporanKunjungan(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        return $this->ok($this->service->getLaporanKunjungan(
            $request->only(['from', 'to'])
        ));
    }

    /**
     * GET /dashboard/laporan/pendapatan
     * Query: from, to
     */
    public function laporanPendapatan(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        return $this->ok($this->service->getLaporanPendapatan(
            $request->only(['from', 'to'])
        ));
    }

    /**
     * GET /dashboard/laporan/klaim
     * Query: from, to
     */
    public function laporanKlaim(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        return $this->ok($this->service->getLaporanKlaim(
            $request->only(['from', 'to'])
        ));
    }

    // =========================================================================
    // FOLLOW-UP WIDGETS ← BARU
    // =========================================================================

    /**
     * GET /dashboard/follow-up/hari-ini
     *
     * Returns list of patients who should return for a follow-up TODAY.
     * Query: visits WHERE follow_up_date = CURRENT_DATE AND planning_follow_up = TRUE
     *
     * Response shape per item:
     * {
     *   id, patient: {no_rm, name, phone},
     *   follow_up_date, follow_up_reason,
     *   guarantor_type, classification,
     *   dokter: {name}
     * }
     */
    public function followUpHariIni(): JsonResponse
    {
        $data = $this->service->getFollowUpToday();

        return $this->ok(
            $data,
            "Ditemukan {$data->count()} pasien kontrol hari ini."
        );
    }

    /**
     * GET /dashboard/follow-up/minggu-ini
     *
     * Upcoming follow-ups in the next 7 days with days-remaining indicator.
     * Query: visits WHERE follow_up_date BETWEEN TODAY AND TODAY+7 AND planning_follow_up = TRUE
     *
     * Extra field per item: hari_tersisa (integer)
     */
    public function followUpMingguIni(): JsonResponse
    {
        $data = $this->service->getFollowUpThisWeek();

        return $this->ok(
            $data,
            "Ditemukan {$data->count()} jadwal kontrol dalam 7 hari ke depan."
        );
    }

    /**
     * GET /dashboard/follow-up/statistik
     * Query: bulan (default 6) — berapa bulan ke belakang yang ditampilkan
     *
     * Returns:
     * {
     *   grand_total, avg_hari,
     *   per_penjamin: { BPJS: N, UMUM: N, ... },
     *   per_bulan: [{ bulan, guarantor_type, total }]
     * }
     *
     * Untuk chart: bar/line per bulan, breakdown per penjamin.
     */
    public function followUpStatistik(Request $request): JsonResponse
    {
        $request->validate([
            'bulan' => 'nullable|integer|min:1|max:24',
        ]);

        return $this->ok($this->service->getFollowUpAnalytics(
            $request->only(['bulan'])
        ));
    }

    // =========================================================================
    // ALERT WIDGETS
    // =========================================================================

    /**
     * GET /dashboard/stok-alert
     * Items with stock <= min_stock (obat + BHP).
     */
    public function stokAlert(): JsonResponse
    {
        return $this->ok($this->service->getStokAlert());
    }

    /**
     * GET /dashboard/bpjs-expired
     * Rujukan BPJS dan Surat Kontrol yang akan/sudah expired.
     */
    public function bpjsExpiredAlert(): JsonResponse
    {
        return $this->ok($this->service->getBpjsExpiredAlert());
    }

    /**
     * GET /dashboard/satusehat-status
     * Status sync Satu Sehat terakhir.
     */
    public function satusehatStatus(): JsonResponse
    {
        return $this->ok($this->service->getSatusehatStatus());
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
}
