<?php

namespace App\Services;

use App\Models\Icd10Code;
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

    /**
     * @param array{service_type?:string,from?:?string,to?:?string} $filters
     * @return array{service_type:string,periode:array{from:?string,to:?string},rows:array<int,array<string,mixed>>}
     */
    public function getList(array $filters): array
    {
        $type = $this->normalizeType($filters['service_type'] ?? 'RJ');
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        $visits = $this->baseQuery($type, $from, $to)->get();
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
            fputcsv($fh, [
                $r['no'],
                $r['nama'],
                $r['usia'] !== null ? (string) $r['usia'] : '',
                $r['no_hp'],
                $r['penjamin'],
                $r['dokter'],
                $r['diagnosa'],
                $r['kategori_bedah'],
                $r['tgl_kontrol'],
            ], ',', '"', '\\');
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
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
    public function getNotifications(): array
    {
        $items = array_merge(
            $this->notifKontrol(),
            $this->notifTindakan(),
            $this->notifUlangTahun(),
            $this->notifNyeri(),
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

    /** Follow-up kontrol: planning_follow_up + follow_up_date dalam [hari ini .. +7]. */
    private function notifKontrol(): array
    {
        $today = Carbon::today();
        $until = $today->copy()->addDays(self::UPCOMING_DAYS);

        $visits = Visit::query()
            ->with(['patient', 'doctorExamination.doctor'])
            ->where('planning_follow_up', true)
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '>=', $today)
            ->whereDate('follow_up_date', '<=', $until)
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

    /** Tindakan terjadwal: surgery_schedules.scheduled_date dalam [hari ini .. +7], belum dibatalkan/selesai. */
    private function notifTindakan(): array
    {
        $today = Carbon::today();
        $until = $today->copy()->addDays(self::UPCOMING_DAYS);

        $schedules = SurgerySchedule::query()
            ->with(['visit.patient', 'leadSurgeon', 'surgeryPackage'])
            ->whereNotNull('scheduled_date')
            ->whereDate('scheduled_date', '>=', $today)
            ->whereDate('scheduled_date', '<=', $until)
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

    /** Ulang tahun: pasien aktif yang berulang tahun dalam [hari ini .. +7]. */
    private function notifUlangTahun(): array
    {
        $today = Carbon::today();
        $window = [];
        for ($i = 0; $i <= self::UPCOMING_DAYS; $i++) {
            $d = $today->copy()->addDays($i);
            $window[$d->format('m-d')] = $d->toDateString();
        }

        // Filter di DB pakai bulan-tanggal (cocok lintas-tahun) lalu pasangkan ke tanggal due tahun ini.
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
    private function notifNyeri(): array
    {
        $now   = Carbon::now();
        $since = $now->copy()->subDays(self::UPCOMING_DAYS);

        $visits = Visit::query()
            ->with(['patient', 'surgerySchedule.surgeryPackage', 'billingInvoice'])
            ->whereNotNull('surgery_schedule_id')
            ->whereHas('billingInvoice', function ($q) use ($now, $since) {
                $q->where('status', 'PAID')
                  ->whereNotNull('paid_at')
                  // paid_at + 6 jam berada di rentang [since .. now] → due sekarang/baru lewat.
                  ->whereRaw("paid_at + interval '" . self::NYERI_DELAY_HOURS . " hours' <= ?", [$now])
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
    private function baseQuery(string $type, ?string $from, ?string $to)
    {
        $query = Visit::query()
            ->with($this->eagerFor($type))
            ->when($from, fn ($q) => $q->whereDate('visit_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('visit_date', '<=', $to))
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
