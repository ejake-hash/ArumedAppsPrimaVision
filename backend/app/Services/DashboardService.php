<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BpjsClaim;
use App\Models\IntegrationConfig;
use App\Models\Medication;
use App\Models\BhpItem;
use App\Models\NurseAssessment;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    // =========================================================================
    // DAILY SUMMARY — stat cards utama
    // =========================================================================

    public function getDailySummary(): array
    {
        $today = today();

        // Kunjungan hari ini
        $totalKunjungan = Visit::whereDate('visit_date', $today)->count();
        $perStation     = Visit::whereDate('visit_date', $today)
            ->selectRaw('current_station, COUNT(*) as total')
            ->groupBy('current_station')
            ->pluck('total', 'current_station');

        $perKlasifikasi = Visit::whereDate('visit_date', $today)
            ->selectRaw('classification, COUNT(*) as total')
            ->groupBy('classification')
            ->pluck('total', 'classification');

        $kunjunganSelesai = Visit::whereDate('visit_date', $today)
            ->where('current_station', 'SELESAI')
            ->count();

        // Operasi hari ini
        $totalOperasi   = SurgerySchedule::whereDate('scheduled_date', $today)->count();
        $operasiSelesai = SurgerySchedule::whereDate('scheduled_date', $today)
            ->where('status', 'DONE')
            ->count();
        $operasiBerjalan = SurgerySchedule::whereDate('scheduled_date', $today)
            ->where('status', 'IN_PROGRESS')
            ->count();

        // Pendapatan hari ini
        $pendapatanHariIni = BillingInvoice::whereDate('created_at', $today)
            ->whereIn('status', ['PAID', 'PARTIALLY_PAID'])
            ->sum('paid_amount');

        $invoiceCount = BillingInvoice::whereDate('created_at', $today)->count();

        // Klaim BPJS
        $klaimBaru      = BpjsClaim::whereDate('created_at', $today)->count();
        $klaimSubmitted = BpjsClaim::where('status', 'SUBMITTED')->count();
        $klaimDitolak   = BpjsClaim::where('status', 'DITOLAK')
            ->whereDate('updated_at', $today)->count();

        // Antrian aktif per station
        $antrianAktif = Queue::whereDate('created_at', $today)
            ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
            ->selectRaw('station, COUNT(*) as total')
            ->groupBy('station')
            ->pluck('total', 'station');

        // Follow-up hari ini (quick count)
        $followUpHariIni = Visit::followUpToday()->count();

        // Pasien baru hari ini
        $pasienBaru = Patient::whereDate('created_at', $today)->count();

        // Stok alert count
        $stokAlertObat = Medication::whereColumn('stock', '<=', 'min_stock')->where('is_active', true)->count();
        $stokAlertBhp  = BhpItem::whereColumn('stock', '<=', 'min_stock')->where('is_active', true)->count();

        return [
            'kunjungan' => [
                'total'          => $totalKunjungan,
                'selesai'        => $kunjunganSelesai,
                'per_station'    => $perStation,
                'per_klasifikasi' => $perKlasifikasi,
                'pasien_baru'    => $pasienBaru,
            ],
            'operasi' => [
                'total'    => $totalOperasi,
                'selesai'  => $operasiSelesai,
                'berjalan' => $operasiBerjalan,
            ],
            'pendapatan' => [
                'hari_ini'     => (float) $pendapatanHariIni,
                'total_invoice' => $invoiceCount,
            ],
            'klaim' => [
                'baru'      => $klaimBaru,
                'submitted' => $klaimSubmitted,
                'ditolak'   => $klaimDitolak,
            ],
            'antrian_aktif'    => $antrianAktif,
            'follow_up_today'  => $followUpHariIni,
            'stok_alert'       => $stokAlertObat + $stokAlertBhp,
        ];
    }

    // =========================================================================
    // KUNJUNGAN HARI INI (full list untuk widget)
    // =========================================================================

    public function getKunjunganHariIni(array $filters = []): Collection
    {
        $query = Visit::with(['patient', 'queues'])
            ->whereDate('visit_date', today());

        if (! empty($filters['station'])) {
            $query->where('current_station', $filters['station']);
        }

        return $query->orderBy('created_at')->get();
    }

    public function getAntrianAktif(): array
    {
        return Queue::whereDate('created_at', today())
            ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
            ->with(['visit.patient'])
            ->orderBy('queue_sequence')
            ->get()
            ->groupBy('station')
            ->map(fn ($queues) => $queues->values())
            ->toArray();
    }

    // =========================================================================
    // WEEKLY VISITS — grafik 7 hari
    // =========================================================================

    public function getWeeklyVisits(): array
    {
        $days = collect();

        for ($i = 6; $i >= 0; $i--) {
            $date  = today()->subDays($i);
            $label = $date->format('D d/m');

            $total = Visit::whereDate('visit_date', $date)->count();
            $bpjs  = Visit::whereDate('visit_date', $date)->where('guarantor_type', 'BPJS')->count();
            $umum  = Visit::whereDate('visit_date', $date)->where('guarantor_type', 'UMUM')->count();

            $days->push([
                'date'  => $date->toDateString(),
                'label' => $label,
                'total' => $total,
                'bpjs'  => $bpjs,
                'umum'  => $umum,
                'lain'  => $total - $bpjs - $umum,
            ]);
        }

        return $days->toArray();
    }

    // =========================================================================
    // TOP DIAGNOSES — ICD-10 terbanyak bulan berjalan
    // =========================================================================

    public function getTopDiagnoses(int $limit = 10): array
    {
        return DB::table('doctor_examinations')
            ->join('visits', 'doctor_examinations.visit_id', '=', 'visits.id')
            ->whereMonth('visits.visit_date', now()->month)
            ->whereYear('visits.visit_date', now()->year)
            ->whereNotNull('doctor_examinations.diagnosis_utama')
            ->whereNull('doctor_examinations.deleted_at')
            ->whereNull('visits.deleted_at')
            ->selectRaw('doctor_examinations.diagnosis_utama as kode, COUNT(*) as total')
            ->groupBy('doctor_examinations.diagnosis_utama')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'kode'  => $row->kode,
                'total' => (int) $row->total,
            ])
            ->toArray();
    }

    // =========================================================================
    // REVENUE SUMMARY — pendapatan per penjamin
    // =========================================================================

    public function getRevenueSummary(array $filters = []): array
    {
        $from = $filters['from'] ?? today()->startOfMonth()->toDateString();
        $to   = $filters['to']   ?? today()->toDateString();

        $perPenjamin = BillingInvoice::join('visits', 'billing_invoices.visit_id', '=', 'visits.id')
            ->whereBetween(DB::raw('DATE(billing_invoices.created_at)'), [$from, $to])
            ->whereIn('billing_invoices.status', ['PAID', 'PARTIALLY_PAID'])
            ->whereNull('billing_invoices.deleted_at')
            ->selectRaw('visits.guarantor_type, SUM(billing_invoices.paid_amount) as total, COUNT(*) as count')
            ->groupBy('visits.guarantor_type')
            ->get()
            ->map(fn ($r) => [
                'guarantor_type' => $r->guarantor_type,
                'total'          => (float) $r->total,
                'count'          => (int) $r->count,
            ])
            ->toArray();

        $grandTotal = array_sum(array_column($perPenjamin, 'total'));

        $harian = BillingInvoice::whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereIn('status', ['PAID', 'PARTIALLY_PAID'])
            ->selectRaw('DATE(created_at) as tanggal, SUM(paid_amount) as total')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('tanggal')
            ->get()
            ->map(fn ($r) => [
                'tanggal' => $r->tanggal,
                'total'   => (float) $r->total,
            ])
            ->toArray();

        return [
            'periode'      => ['from' => $from, 'to' => $to],
            'grand_total'  => $grandTotal,
            'per_penjamin' => $perPenjamin,
            'harian'       => $harian,
        ];
    }

    // =========================================================================
    // FOLLOW-UP / KONTROL ULANG — widget BARU
    // =========================================================================

    /**
     * List pasien yang seharusnya kontrol hari ini.
     * Query: visits WHERE follow_up_date = CURRENT_DATE AND planning_follow_up = TRUE
     */
    public function getFollowUpToday(): Collection
    {
        return Visit::followUpToday()
            ->with(['patient', 'doctorExamination.doctor'])
            ->select([
                'visits.id',
                'visits.patient_id',
                'visits.visit_date',
                'visits.follow_up_date',
                'visits.follow_up_reason',
                'visits.guarantor_type',
                'visits.classification',
            ])
            ->orderBy('visits.follow_up_date')
            ->get();
    }

    /**
     * List pasien kontrol dalam 7 hari ke depan.
     * Query: visits WHERE follow_up_date BETWEEN TODAY AND TODAY+7 AND planning_follow_up = TRUE
     */
    public function getFollowUpThisWeek(): Collection
    {
        return Visit::followUpThisWeek()
            ->with(['patient', 'doctorExamination.doctor'])
            ->select([
                'visits.id',
                'visits.patient_id',
                'visits.visit_date',
                'visits.follow_up_date',
                'visits.follow_up_reason',
                'visits.guarantor_type',
                'visits.classification',
            ])
            ->selectRaw('(visits.follow_up_date - CURRENT_DATE) AS hari_tersisa')
            ->orderBy('visits.follow_up_date')
            ->get();
    }

    /**
     * Statistik follow-up per bulan + per penjamin.
     * Query: GROUP BY month, guarantor_type WHERE planning_follow_up = TRUE
     */
    public function getFollowUpAnalytics(array $filters = []): array
    {
        $bulan  = (int) ($filters['bulan'] ?? 6); // default 6 bulan ke belakang
        $since  = today()->subMonths($bulan)->startOfMonth();

        // Per bulan & penjamin
        $perBulan = Visit::hasFollowUp()
            ->where('follow_up_date', '>=', $since)
            ->selectRaw("DATE_TRUNC('month', follow_up_date) AS bulan, guarantor_type, COUNT(*) AS total")
            ->groupBy(DB::raw("DATE_TRUNC('month', follow_up_date)"), 'guarantor_type')
            ->orderBy('bulan')
            ->get()
            ->map(fn ($r) => [
                'bulan'          => substr($r->bulan, 0, 7),
                'guarantor_type' => $r->guarantor_type,
                'total'          => (int) $r->total,
            ])
            ->toArray();

        // Rata-rata jarak (visit_date → follow_up_date)
        $avgJarak = Visit::hasFollowUp()
            ->where('follow_up_date', '>=', $since)
            ->selectRaw('AVG(follow_up_date - visit_date) AS avg_days')
            ->value('avg_days');

        // Total keseluruhan
        $grandTotal = Visit::hasFollowUp()
            ->where('follow_up_date', '>=', $since)
            ->count();

        // Breakdown per tipe penjamin
        $perPenjamin = Visit::hasFollowUp()
            ->where('follow_up_date', '>=', $since)
            ->selectRaw('guarantor_type, COUNT(*) AS total')
            ->groupBy('guarantor_type')
            ->pluck('total', 'guarantor_type');

        return [
            'periode'       => ['bulan' => $bulan, 'since' => $since->toDateString()],
            'grand_total'   => $grandTotal,
            'avg_hari'      => round((float) $avgJarak, 1),
            'per_penjamin'  => $perPenjamin,
            'per_bulan'     => $perBulan,
        ];
    }

    // =========================================================================
    // ALERT WIDGETS
    // =========================================================================

    public function getStokAlert(): array
    {
        $obat = Medication::whereColumn('stock', '<=', 'min_stock')
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'stock', 'min_stock', 'unit']);

        $bhp = BhpItem::whereColumn('stock', '<=', 'min_stock')
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'stock', 'min_stock', 'unit']);

        return [
            'obat'  => $obat,
            'bhp'   => $bhp,
            'total' => $obat->count() + $bhp->count(),
        ];
    }

    public function getBpjsExpiredAlert(): array
    {
        $today = today();

        $rujukanExpired = DB::table('bpjs_referrals_in')
            ->where('tgl_expired', '<=', $today->addDays(7))
            ->where('status', 'VALID')
            ->whereNull('deleted_at')
            ->select(['id', 'no_rujukan', 'tgl_expired', 'visit_id'])
            ->get();

        $suratKontrolExpired = DB::table('bpjs_control_letters')
            ->where('tgl_expired', '<=', $today)
            ->whereIn('status', ['SUBMITTED', 'SUCCESS'])
            ->whereNull('deleted_at')
            ->select(['id', 'no_surat_kontrol', 'tgl_expired', 'visit_id'])
            ->get();

        return [
            'rujukan_expired'       => $rujukanExpired,
            'surat_kontrol_expired' => $suratKontrolExpired,
            'total'                 => $rujukanExpired->count() + $suratKontrolExpired->count(),
        ];
    }

    public function getSatusehatStatus(): array
    {
        $lastSync = DB::table('satusehat_sync_logs')
            ->orderByDesc('sync_date')
            ->first();

        $config = IntegrationConfig::where('system_name', 'SATUSEHAT')->first();

        return [
            'is_enabled'       => $config?->is_enabled ?? false,
            'last_sync_date'   => $lastSync?->sync_date,
            'last_sync_status' => $lastSync?->status,
            'total_sent'       => $lastSync?->total_sent ?? 0,
            'total_failed'     => $lastSync?->total_failed ?? 0,
        ];
    }

    // =========================================================================
    // LAPORAN
    // =========================================================================

    public function getLaporanKunjungan(array $filters = []): array
    {
        $from = $filters['from'] ?? today()->startOfMonth()->toDateString();
        $to   = $filters['to']   ?? today()->toDateString();

        $perHari = Visit::whereBetween(DB::raw('DATE(visit_date)'), [$from, $to])
            ->selectRaw('DATE(visit_date) AS tanggal, COUNT(*) AS total, guarantor_type')
            ->groupBy(DB::raw('DATE(visit_date)'), 'guarantor_type')
            ->orderBy('tanggal')
            ->get()
            ->toArray();

        $total   = Visit::whereBetween(DB::raw('DATE(visit_date)'), [$from, $to])->count();
        $totalBpjs = Visit::whereBetween(DB::raw('DATE(visit_date)'), [$from, $to])
            ->where('guarantor_type', 'BPJS')->count();

        return [
            'periode'    => ['from' => $from, 'to' => $to],
            'total'      => $total,
            'total_bpjs' => $totalBpjs,
            'total_umum' => $total - $totalBpjs,
            'per_hari'   => $perHari,
        ];
    }

    public function getLaporanPendapatan(array $filters = []): array
    {
        return (new KasirService(app('request')))->getLaporanRekap($filters);
    }

    public function getLaporanKlaim(array $filters = []): array
    {
        $from = $filters['from'] ?? today()->startOfMonth()->toDateString();
        $to   = $filters['to']   ?? today()->toDateString();

        $perStatus = BpjsClaim::whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'periode'   => ['from' => $from, 'to' => $to],
            'per_status' => $perStatus,
            'total'      => $perStatus->sum(),
        ];
    }
}
