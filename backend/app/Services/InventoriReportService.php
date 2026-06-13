<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\Medication;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * InventoriReportService — laporan pemesanan & retur unit + tracking konsumsi.
 *
 * Tujuan: melacak aliran Obat/BHP/IOL gudang→unit agar stok tak bocor. Sumber data
 * MURNI dari request (qty_delivered) & retur (qty_returned) — bukan kwitansi pasien.
 *
 *   Konsumsi bersih unit = Σ qty_delivered (request DELIVERED/CLOSED)
 *                        − Σ qty_returned  (retur RECEIVED)
 */
class InventoriReportService
{
    /**
     * Status request yang stoknya benar-benar keluar ke unit.
     * APPROVED ikut: kirim bertahap (partial deliver) membiarkan status tetap
     * APPROVED walau qty_delivered sudah bertambah & stok fisik sudah keluar gudang
     * (lihat UnitRequestService::deliver). Selalu dipasangkan dgn filter
     * qty_delivered > 0 agar APPROVED yg belum dikirim apa pun tak ikut terhitung.
     */
    private const DELIVERED_STATUSES = ['APPROVED', 'DELIVERED', 'CLOSED'];
    /** Status request yang dianggap "diminta" (sudah disubmit, bukan draft/tolak). */
    private const ACTIVE_REQ_STATUSES = ['SUBMITTED', 'APPROVED', 'DELIVERED', 'CLOSED'];
    /** Kondisi retur yang TIDAK masuk stok lagi (rugi/waste). */
    private const WASTE_CONDITIONS = ['DAMAGED', 'EXPIRED'];

    private const TYPE_LABELS = ['MEDICATION' => 'Obat', 'BHP' => 'BHP', 'IOL' => 'IOL'];

    // =========================================================================
    // RINGKASAN (KPI + data grafik)
    // =========================================================================
    public function summary(array $f): array
    {
        [$from, $to] = $this->range($f);

        // ── Dikirim (qty_delivered) per tipe / station / item / bucket waktu ──
        $delivered = DB::table('unit_request_items as uri')
            ->join('unit_requests as ur', 'ur.id', '=', 'uri.unit_request_id')
            ->whereNull('ur.deleted_at')
            ->whereIn('ur.status', self::DELIVERED_STATUSES)
            ->where('uri.qty_delivered', '>', 0)
            ->whereBetween('ur.request_date', [$from, $to]);

        $deliveredTotal   = (float) (clone $delivered)->sum('uri.qty_delivered');
        $requestedTotal   = (float) DB::table('unit_request_items as uri')
            ->join('unit_requests as ur', 'ur.id', '=', 'uri.unit_request_id')
            ->whereNull('ur.deleted_at')
            ->whereIn('ur.status', self::ACTIVE_REQ_STATUSES)
            ->whereBetween('ur.request_date', [$from, $to])
            ->sum('uri.qty_requested');

        $deliveredByType    = (clone $delivered)->groupBy('uri.item_type')
            ->selectRaw('uri.item_type, SUM(uri.qty_delivered) as q')->pluck('q', 'item_type');
        $deliveredByStation = (clone $delivered)->groupBy('ur.requesting_station')
            ->selectRaw('ur.requesting_station as station, SUM(uri.qty_delivered) as q')->pluck('q', 'station');

        // ── Retur (qty_returned) per tipe / station, pisah waste ──
        $returns = DB::table('unit_return_items as uti')
            ->join('unit_returns as ut', 'ut.id', '=', 'uti.unit_return_id')
            ->whereNull('ut.deleted_at')
            ->where('ut.status', 'RECEIVED')
            ->whereBetween('ut.return_date', [$from, $to]);

        $returnedTotal = (float) (clone $returns)->sum('uti.qty_returned');
        $wasteTotal    = (float) (clone $returns)->whereIn('uti.condition', self::WASTE_CONDITIONS)->sum('uti.qty_returned');
        $returnedByType    = (clone $returns)->groupBy('uti.item_type')
            ->selectRaw('uti.item_type, SUM(uti.qty_returned) as q')->pluck('q', 'item_type');
        $returnedByStation = (clone $returns)->groupBy('ut.returning_station')
            ->selectRaw('ut.returning_station as station, SUM(uti.qty_returned) as q')->pluck('q', 'station');

        // ── Tren pengiriman per bucket waktu (day/week/month sesuai panjang rentang) ──
        $bucketExpr = $this->bucketExpr($from, $to, 'ur.request_date');
        $trendRows  = (clone $delivered)
            ->selectRaw("$bucketExpr as bucket, SUM(uri.qty_delivered) as q")
            ->groupByRaw($bucketExpr)->orderByRaw($bucketExpr)->get();

        // ── Top 10 item paling banyak dikirim (+ nama master) ──
        $topRaw = (clone $delivered)->groupBy('uri.item_type', 'uri.item_id')
            ->selectRaw('uri.item_type, uri.item_id, SUM(uri.qty_delivered) as q')
            ->orderByDesc('q')->limit(10)->get();
        $names = $this->resolveNamesForRows($topRaw);
        $topItems = $topRaw->map(fn ($r) => [
            'label' => $names[$r->item_type][$r->item_id]['name'] ?? '-',
            'type'  => self::TYPE_LABELS[$r->item_type] ?? $r->item_type,
            'qty'   => (float) $r->q,
        ])->values();

        // ── Komposisi per tipe & konsumsi bersih per unit ──
        $types = ['MEDICATION', 'BHP', 'IOL'];
        $byType = array_map(fn ($t) => [
            'type'      => self::TYPE_LABELS[$t],
            'delivered' => (float) ($deliveredByType[$t] ?? 0),
            'returned'  => (float) ($returnedByType[$t] ?? 0),
        ], $types);

        $stations = collect($deliveredByStation->keys())->merge($returnedByStation->keys())->unique()->values();
        $byStation = $stations->map(fn ($s) => [
            'station'   => $s,
            'delivered' => (float) ($deliveredByStation[$s] ?? 0),
            'returned'  => (float) ($returnedByStation[$s] ?? 0),
            'net'       => (float) ($deliveredByStation[$s] ?? 0) - (float) ($returnedByStation[$s] ?? 0),
        ])->sortByDesc('net')->values();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'kpi' => [
                'requested'      => round($requestedTotal, 2),
                'delivered'      => round($deliveredTotal, 2),
                'returned'       => round($returnedTotal, 2),
                'returned_waste' => round($wasteTotal, 2),
                'returned_good'  => round($returnedTotal - $wasteTotal, 2),
                'net_consumed'   => round($deliveredTotal - $returnedTotal, 2),
                'active_units'   => $byStation->count(),
            ],
            'by_type'    => $byType,
            'by_station' => $byStation,
            'trend'      => $trendRows->map(fn ($r) => ['bucket' => $r->bucket, 'qty' => (float) $r->q])->values(),
            'top_items'  => $topItems,
        ];
    }

    // =========================================================================
    // LAPORAN PEMESANAN
    // =========================================================================
    private function pemesananQuery(array $f)
    {
        [$from, $to] = $this->range($f);
        $q = DB::table('unit_request_items as uri')
            ->join('unit_requests as ur', 'ur.id', '=', 'uri.unit_request_id')
            ->whereNull('ur.deleted_at')
            ->whereBetween('ur.request_date', [$from, $to]);

        if (!empty($f['station']))   $q->where('ur.requesting_station', $f['station']);
        if (!empty($f['item_type'])) $q->where('uri.item_type', $f['item_type']);
        if (!empty($f['status']))    $q->where('ur.status', $f['status']);
        else                         $q->whereIn('ur.status', self::ACTIVE_REQ_STATUSES);
        if (!empty($f['search']))    $q->where('ur.request_number', 'ilike', '%' . trim($f['search']) . '%');

        return $q->select(
            'uri.id', 'ur.request_number', 'ur.request_date', 'ur.requesting_station as station',
            'ur.status', 'uri.item_type', 'uri.item_id', 'uri.qty_requested', 'uri.qty_delivered',
            'uri.batch_no', 'uri.expiry_date',
        )->orderByDesc('ur.request_date')->orderByDesc('ur.request_number');
    }

    public function pemesananList(array $f): array
    {
        $perPage = min(200, max(10, (int) ($f['per_page'] ?? 50)));
        $p = $this->pemesananQuery($f)->paginate($perPage);
        $names = $this->resolveNamesForRows(collect($p->items()));

        return [
            'data' => collect($p->items())->map(fn ($r) => [
                'request_number' => $r->request_number,
                'date'           => $r->request_date,
                'station'        => $r->station,
                'type'           => self::TYPE_LABELS[$r->item_type] ?? $r->item_type,
                'code'           => $names[$r->item_type][$r->item_id]['code'] ?? null,
                'name'           => $names[$r->item_type][$r->item_id]['name'] ?? '-',
                'qty_requested'  => (float) $r->qty_requested,
                'qty_delivered'  => (float) $r->qty_delivered,
                'status'         => $r->status,
                'batch_no'       => $r->batch_no,
                'expiry_date'    => $r->expiry_date ? Carbon::parse($r->expiry_date)->toDateString() : null,
            ])->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function pemesananCsv(array $f): string
    {
        $rows  = $this->pemesananQuery($f)->limit(50000)->get();
        $names = $this->resolveNamesForRows($rows);
        $header = ['No. Permintaan', 'Tanggal', 'Unit', 'Jenis', 'Kode', 'Barang', 'Qty Diminta', 'Qty Dikirim', 'Status', 'Batch', 'Exp'];
        return $this->buildCsv($header, $rows->map(fn ($r) => [
            $r->request_number,
            $r->request_date,
            $r->station,
            self::TYPE_LABELS[$r->item_type] ?? $r->item_type,
            $names[$r->item_type][$r->item_id]['code'] ?? '',
            $names[$r->item_type][$r->item_id]['name'] ?? '-',
            (float) $r->qty_requested,
            (float) $r->qty_delivered,
            $r->status,
            $r->batch_no ?? '',
            $r->expiry_date ? Carbon::parse($r->expiry_date)->toDateString() : '',
        ]));
    }

    // =========================================================================
    // LAPORAN RETUR
    // =========================================================================
    private function returQuery(array $f)
    {
        [$from, $to] = $this->range($f);
        $q = DB::table('unit_return_items as uti')
            ->join('unit_returns as ut', 'ut.id', '=', 'uti.unit_return_id')
            ->whereNull('ut.deleted_at')
            ->whereBetween('ut.return_date', [$from, $to]);

        if (!empty($f['station']))   $q->where('ut.returning_station', $f['station']);
        if (!empty($f['item_type'])) $q->where('uti.item_type', $f['item_type']);
        if (!empty($f['condition'])) $q->where('uti.condition', $f['condition']);
        if (!empty($f['status']))    $q->where('ut.status', $f['status']);
        if (!empty($f['search']))    $q->where('ut.return_number', 'ilike', '%' . trim($f['search']) . '%');

        return $q->select(
            'uti.id', 'ut.return_number', 'ut.return_date', 'ut.returning_station as station',
            'ut.status', 'ut.reason', 'uti.item_type', 'uti.item_id', 'uti.qty_returned',
            'uti.condition', 'uti.batch_no', 'uti.expiry_date',
        )->orderByDesc('ut.return_date')->orderByDesc('ut.return_number');
    }

    public function returList(array $f): array
    {
        $perPage = min(200, max(10, (int) ($f['per_page'] ?? 50)));
        $p = $this->returQuery($f)->paginate($perPage);
        $names = $this->resolveNamesForRows(collect($p->items()));

        return [
            'data' => collect($p->items())->map(fn ($r) => [
                'return_number' => $r->return_number,
                'date'          => $r->return_date,
                'station'       => $r->station,
                'type'          => self::TYPE_LABELS[$r->item_type] ?? $r->item_type,
                'code'          => $names[$r->item_type][$r->item_id]['code'] ?? null,
                'name'          => $names[$r->item_type][$r->item_id]['name'] ?? '-',
                'qty_returned'  => (float) $r->qty_returned,
                'condition'     => $r->condition,
                'is_waste'      => in_array($r->condition, self::WASTE_CONDITIONS, true),
                'reason'        => $r->reason,
                'status'        => $r->status,
                'batch_no'      => $r->batch_no,
                'expiry_date'   => $r->expiry_date ? Carbon::parse($r->expiry_date)->toDateString() : null,
            ])->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function returCsv(array $f): string
    {
        $rows  = $this->returQuery($f)->limit(50000)->get();
        $names = $this->resolveNamesForRows($rows);
        $header = ['No. Retur', 'Tanggal', 'Unit', 'Jenis', 'Kode', 'Barang', 'Qty Retur', 'Kondisi', 'Alasan', 'Status', 'Batch', 'Exp'];
        return $this->buildCsv($header, $rows->map(fn ($r) => [
            $r->return_number,
            $r->return_date,
            $r->station,
            self::TYPE_LABELS[$r->item_type] ?? $r->item_type,
            $names[$r->item_type][$r->item_id]['code'] ?? '',
            $names[$r->item_type][$r->item_id]['name'] ?? '-',
            (float) $r->qty_returned,
            $r->condition ?? '',
            $r->reason ?? '',
            $r->status,
            $r->batch_no ?? '',
            $r->expiry_date ? Carbon::parse($r->expiry_date)->toDateString() : '',
        ]));
    }

    // =========================================================================
    // LAPORAN SELISIH OPNAME (dari stock_opname_items + sessions)
    // =========================================================================
    private function selisihQuery(array $f)
    {
        [$from, $to] = $this->range($f);
        $q = DB::table('stock_opname_items as soi')
            ->join('stock_opname_sessions as sos', 'sos.id', '=', 'soi.stock_opname_session_id')
            ->whereNull('sos.deleted_at')
            ->whereBetween('sos.opname_date', [$from, $to]);

        if (!empty($f['location']))  $q->where('sos.location', $f['location']);
        if (!empty($f['item_type'])) $q->where('soi.item_type', $f['item_type']);
        if (!empty($f['status']))    $q->where('soi.status', $f['status']);
        if (!empty($f['search'])) {
            $term = '%' . trim($f['search']) . '%';
            $q->where(fn ($qq) => $qq
                ->where('sos.session_number', 'ilike', $term)
                ->orWhere('soi.item_name', 'ilike', $term)
                ->orWhere('soi.item_code', 'ilike', $term));
        }

        return $q->select(
            'soi.id', 'sos.session_number', 'sos.opname_date', 'sos.location',
            'soi.item_type', 'soi.item_code', 'soi.item_name',
            'soi.system_qty', 'soi.physical_qty', 'soi.delta', 'soi.status', 'soi.note',
        )->orderByDesc('sos.opname_date')->orderByDesc('sos.session_number');
    }

    public function selisihList(array $f): array
    {
        [$from, $to] = $this->range($f);
        $perPage = min(200, max(10, (int) ($f['per_page'] ?? 50)));
        $p = $this->selisihQuery($f)->paginate($perPage);

        // KPI seluruh rentang (bukan hanya halaman aktif).
        $kpiBase = DB::table('stock_opname_items as soi')
            ->join('stock_opname_sessions as sos', 'sos.id', '=', 'soi.stock_opname_session_id')
            ->whereNull('sos.deleted_at')
            ->whereBetween('sos.opname_date', [$from, $to]);
        if (!empty($f['location']))  $kpiBase->where('sos.location', $f['location']);
        if (!empty($f['item_type'])) $kpiBase->where('soi.item_type', $f['item_type']);

        $sessions = (clone $kpiBase)->distinct('soi.stock_opname_session_id')->count('soi.stock_opname_session_id');
        $itemsSelisih = (clone $kpiBase)->count();
        $qtyLebih  = (float) (clone $kpiBase)->where('soi.status', 'LEBIH')->sum('soi.delta');
        $qtyKurang = (float) (clone $kpiBase)->where('soi.status', 'KURANG')->sum('soi.delta');

        return [
            'data' => collect($p->items())->map(fn ($r) => [
                'session_number' => $r->session_number,
                'date'           => $r->opname_date,
                'location'       => $r->location,
                'type'           => self::TYPE_LABELS[$r->item_type] ?? $r->item_type,
                'code'           => $r->item_code,
                'name'           => $r->item_name ?? '-',
                'system_qty'     => (float) $r->system_qty,
                'physical_qty'   => (float) $r->physical_qty,
                'delta'          => (float) $r->delta,
                'status'         => $r->status,
                'note'           => $r->note,
            ])->values(),
            'kpi' => [
                'sessions'      => $sessions,
                'items_selisih' => $itemsSelisih,
                'qty_lebih'     => round($qtyLebih, 2),
                'qty_kurang'    => round(abs($qtyKurang), 2),
            ],
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function selisihCsv(array $f): string
    {
        $rows = $this->selisihQuery($f)->limit(50000)->get();
        $header = ['No. BA', 'Tanggal', 'Lokasi', 'Jenis', 'Kode', 'Barang', 'Stok Sistem', 'Stok Fisik', 'Selisih', 'Status', 'Catatan'];
        return $this->buildCsv($header, $rows->map(fn ($r) => [
            $r->session_number,
            $r->opname_date,
            $r->location,
            self::TYPE_LABELS[$r->item_type] ?? $r->item_type,
            $r->item_code ?? '',
            $r->item_name ?? '-',
            (float) $r->system_qty,
            (float) $r->physical_qty,
            (float) $r->delta,
            $r->status,
            $r->note ?? '',
        ]));
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================
    private function range(array $f): array
    {
        $to   = !empty($f['to'])   ? Carbon::parse($f['to'])->toDateString()   : Carbon::now()->toDateString();
        $from = !empty($f['from']) ? Carbon::parse($f['from'])->toDateString() : Carbon::now()->startOfMonth()->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    /** Pilih granularitas tren: harian ≤31h, mingguan ≤184h, bulanan selebihnya (Postgres). */
    private function bucketExpr(string $from, string $to, string $col): string
    {
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        return match (true) {
            $days <= 31  => "to_char($col, 'YYYY-MM-DD')",
            $days <= 184 => "to_char(date_trunc('week', $col), 'YYYY-MM-DD')",
            default      => "to_char($col, 'YYYY-MM')",
        };
    }

    /**
     * Resolusi nama master per-tipe utk sekumpulan baris (anti N+1: 1 query per tipe).
     * @return array<string, array<string, array{name:string, code:?string}>>
     */
    private function resolveNamesForRows($rows): array
    {
        $idsByType = [];
        foreach ($rows as $r) {
            $idsByType[$r->item_type][] = $r->item_id;
        }
        $out = [];
        foreach ($idsByType as $type => $ids) {
            $ids = array_values(array_unique($ids));
            $out[$type] = match ($type) {
                'MEDICATION' => Medication::whereIn('id', $ids)->get(['id', 'code', 'name'])
                    ->mapWithKeys(fn ($m) => [$m->id => ['name' => $m->name, 'code' => $m->code]])->toArray(),
                'BHP' => BhpItem::whereIn('id', $ids)->get(['id', 'code', 'name'])
                    ->mapWithKeys(fn ($m) => [$m->id => ['name' => $m->name, 'code' => $m->code]])->toArray(),
                'IOL' => IolItem::whereIn('id', $ids)->get(['id', 'brand', 'model', 'power'])
                    ->mapWithKeys(fn ($m) => [$m->id => [
                        'name' => trim(($m->brand ?? '') . ($m->power ? ' · ' . $m->power . 'D' : '')) ?: ($m->model ?? '-'),
                        'code' => $m->model,
                    ]])->toArray(),
                default => [],
            };
        }
        return $out;
    }

    private function buildCsv(array $header, $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        // Escape eksplisit '\\' → hindari deprecation PHP 8.4 (default escape akan berubah).
        fputcsv($fh, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ',', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }
}
