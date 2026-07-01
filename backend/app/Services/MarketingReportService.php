<?php

namespace App\Services;

use App\Models\Icd10Code;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Carbon\Carbon;

/**
 * Laporan Marketing — daftar pasien siap-olah untuk campaign tim marketing
 * (follow-up kontrol, reaktivasi). READ-ONLY, tanpa migrasi DB.
 *
 * 3 tipe layanan: RJ (RAJAL) / RI (RANAP) / BEDAH. IGD diabaikan (keputusan user).
 * Kolom: No | Nama | Usia | No. HP | Penjamin | Dokter/DPJP | Diagnosa |
 *        Kategori Bedah | Tgl Kontrol Selanjutnya.
 */
class MarketingReportService
{
    /** Header CSV/Excel — urutan kolom = urutan field di mapRow(). */
    private const CSV_HEADER = [
        'No', 'Nama', 'Usia', 'No. HP', 'Penjamin',
        'Dokter/DPJP', 'Diagnosa', 'Kategori Bedah', 'Tgl Kontrol Selanjutnya',
    ];

    /** Label penjamin fallback dari enum guarantor_type (insurer_id null = walk-in UMUM). */
    private const GUARANTOR_LABELS = [
        'UMUM'     => 'Umum',
        'BPJS'     => 'BPJS',
        'ASURANSI' => 'Asuransi',
    ];

    /** Label jenis penjamin (dashboard mode "per jenis") — cakup semua enum guarantor_type. */
    private const JENIS_LABELS = [
        'UMUM'       => 'Umum',
        'BPJS'       => 'BPJS',
        'ASURANSI'   => 'Asuransi',
        'PERUSAHAAN' => 'Perusahaan',
        'SOSIAL'     => 'Sosial',
    ];

    /**
     * @param array{service_type?:string,from?:?string,to?:?string,insurer_id?:?string} $filters
     * @return array{service_type:string,periode:array{from:?string,to:?string},rows:array<int,array<string,mixed>>}
     */
    public function getList(array $filters): array
    {
        $type      = $this->normalizeType($filters['service_type'] ?? 'RJ');
        $from      = $filters['from'] ?? null;
        $to        = $filters['to'] ?? null;
        $insurerId = $filters['insurer_id'] ?? null;

        // Batas aman: TANPA rentang tanggal, jangan muat seluruh riwayat visits ke
        // memori (OOM/timeout di produksi dgn puluhan/ratusan ribu kunjungan). Default
        // ke bulan berjalan bila keduanya kosong; periode dikembalikan agar UI jelas.
        if (empty($from) && empty($to)) {
            $from = now('Asia/Jakarta')->startOfMonth()->toDateString();
            $to   = now('Asia/Jakarta')->toDateString();
        }

        $visits = $this->baseQuery($type, $from, $to, $insurerId)->get();
        $icdMap = $this->icdDescriptionMap($visits);

        $rows = [];
        $no = 1;
        foreach ($visits as $visit) {
            $rows[] = $this->mapRow($no++, $visit, $type, $icdMap);
        }

        return [
            'service_type' => $type,
            'periode'      => ['from' => $from, 'to' => $to],
            'rows'         => $rows,
        ];
    }

    /**
     * CSV string — header + baris yang SAMA PERSIS dari getList() (single source of truth).
     * Jangan awali baris data dengan '#' (csvToXlsx membuangnya).
     */
    public function getCsvExport(array $filters): string
    {
        $list = $this->getList($filters);

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, self::CSV_HEADER, ',', '"', '\\');

        foreach ($list['rows'] as $r) {
            // Teks berasal-user (nama/penjamin/dokter/diagnosa/no_hp) di-guard terhadap
            // CSV formula injection — lihat App\Support\CsvGuard.
            fputcsv($fh, [
                $r['no'],
                \App\Support\CsvGuard::cell($r['nama']),
                $r['usia'] !== null ? (string) $r['usia'] : '',
                \App\Support\CsvGuard::cell($r['no_hp']),
                \App\Support\CsvGuard::cell($r['penjamin']),
                \App\Support\CsvGuard::cell($r['dokter']),
                \App\Support\CsvGuard::cell($r['diagnosa']),
                \App\Support\CsvGuard::cell($r['kategori_bedah']),
                $r['tgl_kontrol'],
            ], ',', '"', '\\');
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DASHBOARD ANALITIK — kunjungan per penjamin + top wilayah pasien (READ-ONLY).
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Dashboard kunjungan per penjamin dalam periode.
     *
     * $groupBy:
     *  - 'jenis'   (default): kelompokkan per jenis penjamin (guarantor_type) — semua
     *               pasien asuransi terhitung sebagai satu "Asuransi", dst. (BPJS/Umum/
     *               Asuransi/Perusahaan/Sosial). Pandangan ringkas.
     *  - 'penjamin': breakdown detail per penjamin (insurer.name), child TPA tampil
     *               sebagai dirinya. Untuk yang butuh rincian per perusahaan/asuransi.
     *
     * $insurerId opsional memfokuskan ke satu penjamin (+anak TPA-nya).
     *
     * @return array{group_by:string,rows:array<int,array<string,mixed>>,totals:array<string,int>,periode:array{from:?string,to:?string}}
     */
    public function getPayerDashboard(?string $from, ?string $to, string $groupBy = 'jenis', ?string $insurerId = null): array
    {
        $groupBy = $groupBy === 'penjamin' ? 'penjamin' : 'jenis';

        $counts = "count(*) as total_kunjungan,
             count(distinct patient_id) as total_pasien,
             count(*) filter (where jenis_pelayanan = 'RAJAL') as rj,
             count(*) filter (where jenis_pelayanan = 'RANAP') as ri,
             count(*) filter (where visit_type = 'PREOP_BEDAH' or surgery_schedule_id is not null) as bedah";

        $base = Visit::query()
            ->when($from, fn ($q) => $q->whereDate('visit_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('visit_date', '<=', $to))
            ->when($insurerId, fn ($q) => $q->whereIn('insurer_id', $this->insurerIdWithChildren($insurerId)));

        if ($groupBy === 'penjamin') {
            $grouped = $base->selectRaw("insurer_id, guarantor_type, {$counts}")
                ->groupBy('insurer_id', 'guarantor_type')
                ->get();

            $names = Insurer::whereIn('id', $grouped->pluck('insurer_id')->filter()->unique())
                ->pluck('name', 'id');

            $labelFor = fn ($r) => $r->insurer_id
                ? ($names[$r->insurer_id] ?? '-')
                : $this->jenisLabel($r->guarantor_type);
        } else { // jenis
            $grouped = $base->selectRaw("guarantor_type, {$counts}")
                ->groupBy('guarantor_type')
                ->get();

            $labelFor = fn ($r) => $this->jenisLabel($r->guarantor_type);
        }

        // Gabungkan baris berdasarkan label (mis. di mode jenis, insurer berbeda
        // dengan guarantor_type sama → satu bucket "Asuransi").
        $agg = [];
        foreach ($grouped as $r) {
            $label = $labelFor($r);
            $agg[$label] ??= [
                'penjamin' => $label, 'total_kunjungan' => 0, 'total_pasien' => 0,
                'rj' => 0, 'ri' => 0, 'bedah' => 0,
            ];
            $agg[$label]['total_kunjungan'] += (int) $r->total_kunjungan;
            $agg[$label]['total_pasien']    += (int) $r->total_pasien;
            $agg[$label]['rj']              += (int) $r->rj;
            $agg[$label]['ri']              += (int) $r->ri;
            $agg[$label]['bedah']           += (int) $r->bedah;
        }

        $rows = array_values($agg);
        usort($rows, fn ($a, $b) => $b['total_kunjungan'] <=> $a['total_kunjungan']);

        $totals = [
            'total_kunjungan' => array_sum(array_column($rows, 'total_kunjungan')),
            'total_pasien'    => array_sum(array_column($rows, 'total_pasien')),
            'penjamin_unik'   => count($rows),
        ];

        return ['group_by' => $groupBy, 'rows' => $rows, 'totals' => $totals, 'periode' => ['from' => $from, 'to' => $to]];
    }

    /** Label jenis penjamin dari guarantor_type (semua asuransi → satu "Asuransi"). */
    private function jenisLabel(?string $g): string
    {
        $g = strtoupper(trim((string) $g));

        return self::JENIS_LABELS[$g] ?? ($g ?: 'Lainnya');
    }

    /**
     * CSV dashboard penjamin (header + baris dari getPayerDashboard) untuk export.
     */
    public function getPayerDashboardCsv(?string $from, ?string $to, string $groupBy = 'jenis', ?string $insurerId = null): string
    {
        $data = $this->getPayerDashboard($from, $to, $groupBy, $insurerId);

        $fh = fopen('php://temp', 'r+');
        $head = $groupBy === 'penjamin' ? 'Penjamin' : 'Jenis Penjamin';
        fputcsv($fh, [$head, 'Kunjungan', 'Pasien', 'Rawat Jalan', 'Rawat Inap', 'Bedah'], ',', '"', '\\');
        foreach ($data['rows'] as $r) {
            fputcsv($fh, [
                $r['penjamin'], $r['total_kunjungan'], $r['total_pasien'], $r['rj'], $r['ri'], $r['bedah'],
            ], ',', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /**
     * Top wilayah asal pasien (kota/kabupaten atau kecamatan) dalam periode.
     * Kolom alamat = string legacy (nama_kab_kota / nama_kecamatan); dinormalisasi
     * UPPER(TRIM) agar variasi ejaan/spasi tidak terpecah.
     *
     * @return array{level:string,rows:array<int,array<string,mixed>>,periode:array{from:?string,to:?string}}
     */
    public function getTopWilayah(?string $from, ?string $to, string $level = 'kota', int $limit = 15): array
    {
        $col = $level === 'kecamatan' ? 'patients.nama_kecamatan' : 'patients.nama_kab_kota';

        $rows = Visit::query()
            ->join('patients', 'patients.id', '=', 'visits.patient_id')
            ->when($from, fn ($q) => $q->whereDate('visits.visit_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('visits.visit_date', '<=', $to))
            ->whereNotNull($col)
            ->whereRaw("trim({$col}) <> ''")
            ->selectRaw(
                "upper(trim({$col})) as wilayah,
                 count(*) as total_kunjungan,
                 count(distinct visits.patient_id) as total_pasien"
            )
            ->groupByRaw("upper(trim({$col}))")
            ->orderByDesc('total_kunjungan')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'wilayah'         => $r->wilayah,
                'total_kunjungan' => (int) $r->total_kunjungan,
                'total_pasien'    => (int) $r->total_pasien,
            ])
            ->all();

        return [
            'level'   => $level === 'kecamatan' ? 'kecamatan' : 'kota',
            'rows'    => $rows,
            'periode' => ['from' => $from, 'to' => $to],
        ];
    }

    /** Daftar id insurer + semua anak TPA-nya (untuk filter penjamin parent-aware). */
    private function insurerIdWithChildren(string $insurerId): array
    {
        $children = Insurer::where('parent_id', $insurerId)->pluck('id')->all();

        return array_merge([$insurerId], $children);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // NOTIFIKASI (Tab 1) — daftar pengingat siap-hubungi untuk tim marketing.
    // 4 jenis: follow-up kontrol, tindakan terjadwal, ulang tahun, follow-up nyeri.
    // READ-ONLY (tanpa migrasi). Ceklis "selesai" dikelola SEMENTARA di FE.
    // ═══════════════════════════════════════════════════════════════════════════

    /** Berapa jam setelah invoice LUNAS (kasir) baru memunculkan reminder nyeri. */
    private const NYERI_DELAY_HOURS = 6;

    /** Horizon "ke depan" untuk kontrol/tindakan/ulang tahun (hari). */
    private const UPCOMING_DAYS = 7;

    /**
     * Gabungan semua notifikasi, sudah diurutkan menaik per tanggal jatuh tempo.
     *
     * @return array{
     *   rows:array<int,array<string,mixed>>,
     *   counts:array{kontrol:int,tindakan:int,ulang_tahun:int,nyeri:int,total:int}
     * }
     */
    public function getNotifications(array $filters = []): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        $items = array_merge(
            $this->notifKontrol($from, $to),
            $this->notifTindakan($from, $to),
            $this->notifUlangTahun($from, $to),
            $this->notifNyeri($from, $to),
        );

        // Urut menaik berdasarkan tanggal jatuh tempo (string Y-m-d / Y-m-d H:i aman dibandingkan leksikografis).
        usort($items, fn ($a, $b) => strcmp((string) $a['due_at'], (string) $b['due_at']));

        $rows = [];
        $no = 1;
        $counts = ['kontrol' => 0, 'tindakan' => 0, 'ulang_tahun' => 0, 'nyeri' => 0];
        foreach ($items as $it) {
            $it['no'] = $no++;
            if (isset($counts[$it['type']])) {
                $counts[$it['type']]++;
            }
            $rows[] = $it;
        }
        $counts['total'] = count($rows);

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * Window "ke depan" untuk kontrol/tindakan/ulang tahun. Tanpa filter = [hari ini .. +7];
     * dengan from/to eksplisit pakai rentang itu.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function upcomingWindow(?string $from, ?string $to): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : Carbon::today();
        $end   = $to ? Carbon::parse($to)->startOfDay() : Carbon::today()->addDays(self::UPCOMING_DAYS);

        return [$start, $end];
    }

    /** Follow-up kontrol: planning_follow_up + follow_up_date dalam window. */
    private function notifKontrol(?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->upcomingWindow($from, $to);

        $visits = Visit::query()
            ->with(['patient', 'doctorExamination.doctor'])
            ->where('planning_follow_up', true)
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '>=', $start)
            ->whereDate('follow_up_date', '<=', $end)
            ->orderBy('follow_up_date')
            ->get();

        return $visits->map(fn (Visit $v) => $this->makeNotif(
            type: 'kontrol',
            label: 'Follow-up Kontrol',
            patient: $v->patient,
            dueDate: optional($v->follow_up_date)->toDateString(),
            keterangan: $v->follow_up_reason
                ?: (optional($v->doctorExamination?->doctor)->name
                    ? 'Kontrol ke ' . optional($v->doctorExamination?->doctor)->name
                    : 'Jadwal kontrol berikutnya'),
            refId: 'kontrol:' . $v->id,
        ))->all();
    }

    /** Tindakan terjadwal: surgery_schedules.scheduled_date dalam window, belum dibatalkan/selesai. */
    private function notifTindakan(?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->upcomingWindow($from, $to);

        $schedules = SurgerySchedule::query()
            ->with(['visit.patient', 'leadSurgeon', 'surgeryPackage'])
            ->whereNotNull('scheduled_date')
            ->whereDate('scheduled_date', '>=', $start)
            ->whereDate('scheduled_date', '<=', $end)
            ->whereNotIn('status', ['CANCELLED', 'SELESAI', 'COMPLETED', 'DONE'])
            ->orderBy('scheduled_date')
            ->get();

        return $schedules
            ->filter(fn (SurgerySchedule $s) => $s->visit?->patient !== null)
            ->map(function (SurgerySchedule $s) {
                $paket   = optional($s->surgeryPackage)->name;
                $dokter  = optional($s->leadSurgeon)->name;
                $waktu   = $s->scheduled_time ? ' pukul ' . substr((string) $s->scheduled_time, 0, 5) : '';
                $ket     = trim(($paket ?: 'Tindakan bedah')
                    . ($dokter ? ' — dr. ' . $dokter : '') . $waktu);

                return $this->makeNotif(
                    type: 'tindakan',
                    label: 'Tindakan Terjadwal',
                    patient: $s->visit->patient,
                    dueDate: optional($s->scheduled_date)->toDateString(),
                    keterangan: $ket,
                    refId: 'tindakan:' . $s->id,
                );
            })
            ->values()
            ->all();
    }

    /** Ulang tahun: pasien aktif yang berulang tahun dalam window. */
    private function notifUlangTahun(?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->upcomingWindow($from, $to);

        // Bangun peta MM-DD → tanggal due. Batasi maksimum 366 hari (cegah loop besar
        // bila rentang dipaksa lebih dari setahun).
        $window = [];
        $cursor = $start->copy();
        $guard = 0;
        while ($cursor->lte($end) && $guard <= 366) {
            $window[$cursor->format('m-d')] = $cursor->toDateString();
            $cursor->addDay();
            $guard++;
        }

        // Filter di DB pakai bulan-tanggal (cocok lintas-tahun) lalu pasangkan ke tanggal due.
        $monthDays = array_keys($window);

        $patients = Patient::query()
            ->where('is_active', true)
            ->whereNotNull('date_of_birth')
            ->whereNotNull('phone')
            ->whereRaw("to_char(date_of_birth, 'MM-DD') IN ('" . implode("','", $monthDays) . "')")
            ->get(['id', 'name', 'phone', 'date_of_birth']);

        return $patients->map(function (Patient $p) use ($window) {
            $md  = optional($p->date_of_birth)->format('m-d');
            $due = $window[$md] ?? null;
            $usia = $this->ageFromDob($p->date_of_birth);
            $umurBaru = $usia !== null ? ($usia + 1) : null; // umur yang akan dirayakan

            return $this->makeNotif(
                type: 'ulang_tahun',
                label: 'Ulang Tahun',
                patient: $p,
                dueDate: $due,
                keterangan: $umurBaru !== null ? "Berulang tahun ke-{$umurBaru}" : 'Berulang tahun',
                refId: 'ultah:' . $p->id,
            );
        })->all();
    }

    /**
     * Follow-up nyeri pasca-tindakan: visit bedah yang invoice-nya LUNAS (paid_at),
     * jatuh tempo = paid_at + 6 jam. Tampilkan yang due dari 7 hari lalu s/d sekarang
     * (reminder retrospektif — pasien sudah pulang, perlu ditelepon cek nyeri).
     */
    private function notifNyeri(?string $from = null, ?string $to = null): array
    {
        // Tanpa filter = retrospektif [now-7d .. now]; dengan from/to pakai rentang harian itu.
        $since = $from ? Carbon::parse($from)->startOfDay() : Carbon::now()->subDays(self::UPCOMING_DAYS);
        $until = $to ? Carbon::parse($to)->endOfDay() : Carbon::now();

        $visits = Visit::query()
            ->with(['patient', 'surgerySchedule.surgeryPackage', 'billingInvoice'])
            ->whereNotNull('surgery_schedule_id')
            ->whereHas('billingInvoice', function ($q) use ($until, $since) {
                $q->where('status', 'PAID')
                  ->whereNotNull('paid_at')
                  // paid_at + 6 jam berada di rentang [since .. until] → due dalam window.
                  ->whereRaw("paid_at + interval '" . self::NYERI_DELAY_HOURS . " hours' <= ?", [$until])
                  ->whereRaw("paid_at + interval '" . self::NYERI_DELAY_HOURS . " hours' >= ?", [$since]);
            })
            ->get();

        return $visits
            ->filter(fn (Visit $v) => $v->patient !== null && $v->billingInvoice?->paid_at !== null)
            ->map(function (Visit $v) {
                $dueAt = $v->billingInvoice->paid_at->copy()->addHours(self::NYERI_DELAY_HOURS);
                $paket = optional($v->surgerySchedule?->surgeryPackage)->name;

                return $this->makeNotif(
                    type: 'nyeri',
                    label: 'Follow-up Nyeri Pasca-Tindakan',
                    patient: $v->patient,
                    dueDate: $dueAt->toDateString(),
                    dueAt: $dueAt->format('Y-m-d H:i'),
                    keterangan: ($paket ? $paket . ' — ' : '') . 'Cek keluhan nyeri pasca-tindakan',
                    refId: 'nyeri:' . $v->id,
                );
            })
            ->values()
            ->all();
    }

    /**
     * Bentuk satu baris notifikasi seragam. $dueAt opsional (default = $dueDate 00:00)
     * dipakai untuk pengurutan presisi-jam (mis. nyeri).
     *
     * @return array<string,mixed>
     */
    private function makeNotif(
        string $type,
        string $label,
        ?Patient $patient,
        ?string $dueDate,
        string $keterangan,
        string $refId,
        ?string $dueAt = null,
    ): array {
        return [
            'ref_id'     => $refId,
            'type'       => $type,
            'type_label' => $label,
            'nama'       => $patient->name ?? '-',
            'usia'       => $this->ageFromDob($patient?->date_of_birth),
            'no_hp'      => $patient->phone ?? '-',
            'tgl'        => $dueDate,
            'due_at'     => $dueAt ?? ($dueDate ? $dueDate . ' 00:00' : '9999-12-31 23:59'),
            'keterangan' => $keterangan,
            'selesai'    => false, // status sementara, dikelola di FE
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function normalizeType(string $type): string
    {
        $type = strtoupper(trim($type));

        return in_array($type, ['RJ', 'RI', 'BEDAH'], true) ? $type : 'RJ';
    }

    /**
     * Visit::query() (Eloquent → soft-delete & eager-load aman) difilter periode,
     * dengan eager-load per tab.
     */
    private function baseQuery(string $type, ?string $from, ?string $to, ?string $insurerId = null)
    {
        $query = Visit::query()
            ->with($this->eagerFor($type))
            ->when($from, fn ($q) => $q->whereDate('visit_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('visit_date', '<=', $to))
            ->when($insurerId, fn ($q) => $q->whereIn('insurer_id', $this->insurerIdWithChildren($insurerId)))
            ->orderBy('visit_date', 'desc');

        if ($type === 'BEDAH') {
            // OR WAJIB dibungkus closure agar AND-tanggal tidak rusak.
            $query->where(function ($q) {
                $q->where('visit_type', 'PREOP_BEDAH')
                  ->orWhereNotNull('surgery_schedule_id');
            });
        } elseif ($type === 'RI') {
            $query->where('jenis_pelayanan', 'RANAP');
        } else { // RJ
            $query->where('jenis_pelayanan', 'RAJAL');
        }

        return $query;
    }

    /** @return array<int,string> */
    private function eagerFor(string $type): array
    {
        $common = ['patient', 'insurer', 'billingInvoice'];

        return match ($type) {
            'BEDAH' => array_merge($common, [
                'doctorExamination',
                'surgerySchedule.leadSurgeon',
                'surgerySchedule.surgeryPackage',
                'surgerySchedule.surgeryRecord',
            ]),
            'RI' => array_merge($common, [
                'dpjp',
                'doctorExamination.doctor',
            ]),
            default => array_merge($common, [ // RJ
                'doctorExamination.doctor',
                'doctorSchedule.employee',
            ]),
        };
    }

    /**
     * @param array<string,string> $icdMap
     * @return array<string,mixed>
     */
    private function mapRow(int $no, Visit $visit, string $type, array $icdMap): array
    {
        // Kwitansi hanya "terbit" bila invoice sudah LUNAS (PAID). Selain itu
        // pasien dianggap masih di Kasir (FE menampilkan notif, tidak mencetak).
        $invoice = $visit->billingInvoice;

        return [
            'no'             => $no,
            'nama'           => $visit->patient->name ?? '-',
            'nik'            => $visit->patient?->nik,
            'usia'           => $this->ageFromDob($visit->patient?->date_of_birth),
            'no_hp'          => $visit->patient->phone ?? '-',
            'penjamin'       => $this->resolvePenjamin($visit),
            'dokter'         => $this->resolveDoctor($visit, $type),
            'diagnosa'       => $this->resolveDiagnosis($visit, $type, $icdMap),
            'kategori_bedah' => $this->resolveKategoriBedah($visit, $type),
            'tgl_kontrol'    => $this->resolveTglKontrol($visit, $type),
            'visit_id'       => $visit->id,
            'invoice_id'     => $invoice?->id,
            'invoice_paid'   => $invoice !== null && $invoice->status === 'PAID',
        ];
    }

    private function resolvePenjamin(Visit $visit): string
    {
        if ($visit->insurer && $visit->insurer->name) {
            return $visit->insurer->name;
        }

        return self::GUARANTOR_LABELS[$visit->guarantor_type] ?? ($visit->guarantor_type ?: '-');
    }

    private function resolveDoctor(Visit $visit, string $type): string
    {
        if ($type === 'BEDAH') {
            return optional($visit->surgerySchedule?->leadSurgeon)->name ?? '-';
        }

        if ($type === 'RI') {
            return $visit->dpjp->name
                ?? optional($visit->doctorExamination?->doctor)->name
                ?? '-';
        }

        // RJ
        return optional($visit->doctorExamination?->doctor)->name
            ?? optional($visit->doctorSchedule?->employee)->name
            ?? '-';
    }

    /** @param array<string,string> $icdMap */
    private function resolveDiagnosis(Visit $visit, string $type, array $icdMap): string
    {
        $exam = $visit->doctorExamination;

        if ($type === 'BEDAH') {
            $code = $exam?->diagnosis_utama;
            if ($code) {
                return $this->labelIcd($code, $icdMap);
            }

            return optional($visit->surgerySchedule?->surgeryPackage)->name ?? '-';
        }

        // RJ / RI
        $code = $exam?->diagnosis_utama;
        if ($code) {
            return $this->labelIcd($code, $icdMap);
        }

        return $exam?->diagnosis_text ?: '-';
    }

    /** @param array<string,string> $icdMap */
    private function labelIcd(string $code, array $icdMap): string
    {
        $desc = $icdMap[$code] ?? null;

        return $desc ? "{$code} — {$desc}" : $code;
    }

    private function resolveKategoriBedah(Visit $visit, string $type): string
    {
        if ($type !== 'BEDAH') {
            return '-';
        }

        return optional($visit->surgerySchedule?->surgeryPackage)->category ?? '-';
    }

    private function resolveTglKontrol(Visit $visit, string $type): ?string
    {
        if ($type === 'BEDAH') {
            $followup = optional($visit->surgerySchedule?->surgeryRecord)->followup_date;
            if ($followup) {
                return $followup->toDateString();
            }
        }

        // RJ / RI (dan fallback Bedah): follow_up_date bila planning_follow_up.
        if ($visit->planning_follow_up && $visit->follow_up_date) {
            return optional($visit->follow_up_date)->toDateString();
        }

        return null;
    }

    /**
     * Ambil deskripsi ICD-10 sekali untuk semua kode (hindari N+1).
     * @return array<string,string> code => (indonesian_description ?: description)
     */
    private function icdDescriptionMap($visits): array
    {
        $codes = $visits
            ->map(fn ($v) => $v->doctorExamination?->diagnosis_utama)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($codes)) {
            return [];
        }

        return Icd10Code::whereIn('code', $codes)
            ->get(['code', 'description', 'indonesian_description'])
            ->mapWithKeys(fn ($r) => [
                $r->code => ($r->indonesian_description ?: $r->description) ?: '',
            ])
            ->all();
    }

    private function ageFromDob($dob): ?int
    {
        if (! $dob) {
            return null;
        }

        try {
            return Carbon::parse($dob)->age;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
