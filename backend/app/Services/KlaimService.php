<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BpjsClaim;
use App\Models\ClaimAuditLog;
use App\Models\DocumentTemplate;
use App\Models\InacbgsGroupingLog;
use App\Models\IntegrationConfig;
use App\Models\PatientDocument;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Services\BpjsVClaimService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KlaimService
{
    public function __construct(
        private readonly Request $request,
        private readonly InaCbgsService $eklaim,
        private readonly BpjsVClaimService $vclaim,
    ) {}

    // =========================================================================
    // LIST & DETAIL
    // =========================================================================

    public function getClaimList(array $filters = []): LengthAwarePaginator
    {
        $query = BpjsClaim::with(['visit.patient', 'visit.insurer', 'visit.doctorExamination', 'assignedTo']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter Rawat Jalan (RAJAL) / Rawat Inap (RANAP) via visit.
        if (! empty($filters['jenis_pelayanan'])) {
            $jp = $filters['jenis_pelayanan'];
            $query->whereHas('visit', fn ($v) => $v->where('jenis_pelayanan', $jp));
        }

        // Gating workspace: hanya tampilkan klaim yang sudah "Kirim ke Klaim" dari
        // Rekap Kunjungan (visit.klaim_sent_at). Klaim DRAFT auto-dibuat (resume
        // di-TTD via FormRegistry / kasir billing) JANGAN muncul sebelum dikirim —
        // konsisten dgn tab "DIVA & Berkas" (only_sent). Klaim yang sudah bergerak
        // dari DRAFT (REVIEW/VERIFIED/SUBMITTED/dst) tetap tampil walau penanda kirim
        // kosong (data lama sebelum fitur klaim_sent_at) agar tak hilang dari daftar.
        $query->where(fn ($q) => $q
            ->whereHas('visit', fn ($v) => $v->whereNotNull('klaim_sent_at'))
            ->orWhere('status', '!=', 'DRAFT'));

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('no_sep', 'like', "%{$keyword}%")
                ->orWhere('patient_nik', 'like', "%{$keyword}%")
                ->orWhereHas('visit.patient', fn ($p) => $p->where('name', 'ilike', "%{$keyword}%"))
            );
        }

        if (! empty($filters['tanggal_from'])) {
            $query->whereDate('created_at', '>=', $filters['tanggal_from']);
        }

        if (! empty($filters['tanggal_to'])) {
            $query->whereDate('created_at', '<=', $filters['tanggal_to']);
        }

        $page = $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);

        // Self-heal: klaim yang auto-dibuat (ensureClaimForVisit) tak menyalin
        // diagnosa → "Perlu Diagnosis" padahal dokter sudah memilih. Isi dari
        // pemeriksaan dokter bila kolom klaim kosong (sekali, lalu persist).
        $page->getCollection()->each(fn ($c) => $this->backfillClaimCodingFromExam($c, $c->visit?->doctorExamination));

        return $page;
    }

    public function getClaimById(string $id): BpjsClaim
    {
        $claim = BpjsClaim::with([
            'visit.patient',
            'visit.doctorExamination.doctor',
            'visit.billingInvoice',
            'assignedTo',
            'auditLogs.performedBy',
        ])->findOrFail($id);

        $this->backfillClaimCodingFromExam($claim, $claim->visit?->doctorExamination);

        return $claim;
    }

    /**
     * Isi koding klaim dari pemeriksaan dokter bila kolom klaim MASIH KOSONG —
     * klaim yang auto-dibuat minimal (ensureClaimForVisit, mis. saat resume di-TTD)
     * tak menyalin diagnosa, jadi tampak "Perlu Diagnosis" walau dokter sudah
     * memilih di doctor_examinations. TIDAK menimpa bila koder sudah mengisi, dan
     * tak menyentuh klaim yang sudah dikirim/selesai.
     */
    private function backfillClaimCodingFromExam(BpjsClaim $claim, ?\App\Models\DoctorExamination $exam = null): void
    {
        if (! empty($claim->diagnosis_utama) || in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            return;
        }
        $exam = $exam ?: $claim->visit?->doctorExamination;
        if (! $exam?->diagnosis_utama) {
            return;
        }
        $claim->forceFill([
            'diagnosis_utama'    => $exam->diagnosis_utama,
            'diagnosis_sekunder' => $this->stripToCodes($exam->diagnosis_sekunder) ?: ($claim->diagnosis_sekunder ?? []),
            'procedure_codes'    => $this->stripToCodes($exam->tindakan_codes) ?: ($claim->procedure_codes ?? []),
        ])->save();
    }

    // =========================================================================
    // REKAP KUNJUNGAN BPJS — screening pra-klaim (semua kunjungan BPJS per tgl)
    // =========================================================================

    /**
     * Sinkron SEP dari BPJS untuk rentang tanggal aktif di rekap. Banyak SEP
     * diterbitkan langsung di portal VClaim (bukan via app) → `visit.no_sep`
     * kosong ("–" di tabel). Method ini menarik daftar SEP terbit per tanggal
     * (Monitoring Kunjungan), mencocokkannya ke kunjungan BPJS lewat No.Kartu +
     * tanggal, lalu menautkan (`no_sep` + snapshot `sep_data`). Cetak SEP nanti
     * melengkapi detail kanonik via getSep.
     *
     * Pencocokan: kunci "noKartu|tglSep". Satu SEP hanya dipakai sekali (cegah
     * dua kunjungan pasien yang sama di hari yang sama menyalin SEP yang sama).
     *
     * @param array $filters  tanggal | tanggal_from + tanggal_to ; jenis (RAJAL|RANAP)
     * @return array{linked:int,unmatched:int,sep_found:int,scanned:int,api_errors:int,from:string,to:string}
     */
    public function sinkronSepRekap(array $filters): array
    {
        $cfg = IntegrationConfig::where('system_name', 'VCLAIM')->first();
        if (! $cfg || ! $cfg->is_enabled) {
            throw new \Exception('Integrasi VCLAIM belum diaktifkan.', 503);
        }

        // Rentang tanggal dari filter rekap (single → from=to).
        $from = ! empty($filters['tanggal']) ? $filters['tanggal'] : ($filters['tanggal_from'] ?? null);
        $to   = ! empty($filters['tanggal']) ? $filters['tanggal'] : ($filters['tanggal_to'] ?? null);
        if (! $from || ! $to) {
            throw new \Exception('Tentukan tanggal atau rentang terlebih dahulu.', 422);
        }
        $start = \Illuminate\Support\Carbon::parse($from)->startOfDay();
        $end   = \Illuminate\Support\Carbon::parse($to)->startOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > 62) {
            throw new \Exception('Rentang maksimal 62 hari untuk sinkron SEP.', 422);
        }

        // jnsPelayanan yang ditarik mengikuti tab jenis (1=Inap, 2=Jalan).
        $jenis   = $filters['jenis'] ?? '';
        $jnsList = $jenis === 'RANAP' ? ['1'] : ($jenis === 'RAJAL' ? ['2'] : ['1', '2']);

        // 1) Kumpulkan SEP terbit dari BPJS → index "noKartu|tgl" => daftar rec.
        $index    = [];
        $sepFound = 0;
        $apiErr   = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $tgl = $d->toDateString();
            foreach ($jnsList as $jns) {
                try {
                    $resp = $this->vclaim->monitoringKunjungan($tgl, $jns);
                } catch (\Throwable $e) {
                    $apiErr++;
                    continue;
                }
                foreach ($this->extractMonitoringList($resp) as $item) {
                    $noSep   = trim((string) ($item['noSep'] ?? ''));
                    $noKartu = preg_replace('/\D+/', '', (string) ($item['noKartu'] ?? ''));
                    $tglSep  = substr((string) ($item['tglSep'] ?? $item['tglSEP'] ?? $tgl), 0, 10);
                    if ($noSep === '' || $noKartu === '') {
                        continue;
                    }
                    $sepFound++;
                    $index[$noKartu . '|' . $tglSep][] = [
                        'noSep'  => $noSep,
                        'tglSep' => $tglSep,
                        'poli'   => (string) ($item['poli'] ?? $item['namaPoli'] ?? ''),
                    ];
                }
            }
        }

        // 2) Kunjungan BPJS tanpa SEP dalam rentang → cocokkan & tautkan.
        $visits = Visit::query()
            ->where('guarantor_type', 'BPJS')
            ->whereNull('no_sep')
            ->whereDate('visit_date', '>=', $start->toDateString())
            ->whereDate('visit_date', '<=', $end->toDateString())
            ->when($jenis !== '', fn ($q) => $q->where('jenis_pelayanan', $jenis))
            ->with('patient:id,name,bpjs_number')
            ->get();

        $usedSep   = [];   // noSep yang sudah ditaut → tak dipakai ganda
        $linked    = 0;
        $unmatched = 0;

        foreach ($visits as $v) {
            $kartu = preg_replace('/\D+/', '', (string) ($v->patient?->bpjs_number ?? ''));
            $vtgl  = $v->visit_date?->toDateString();
            if ($kartu === '' || ! $vtgl) {
                $unmatched++;
                continue;
            }

            // Ambil SEP pertama utk kartu+tgl yang belum terpakai.
            $rec = null;
            foreach ($index[$kartu . '|' . $vtgl] ?? [] as $cand) {
                if (! in_array($cand['noSep'], $usedSep, true)) {
                    $rec = $cand;
                    break;
                }
            }
            if (! $rec) {
                $unmatched++;
                continue;
            }

            $usedSep[] = $rec['noSep'];
            $v->update([
                'no_sep'   => $rec['noSep'],
                'sep_data' => array_merge((array) ($v->sep_data ?? []), [
                    'noSep'       => $rec['noSep'],
                    'noKartu'     => $kartu,
                    'tglSep'      => $rec['tglSep'],
                    'poliTujuan'  => $rec['poli'],
                    'namaPeserta' => $v->patient?->name,
                    'sumber'      => 'SINKRON_MONITORING',
                ]),
            ]);
            $linked++;
        }

        return [
            'linked'     => $linked,
            'unmatched'  => $unmatched,
            'sep_found'  => $sepFound,
            'scanned'    => $visits->count(),
            'api_errors' => $apiErr,
            'from'       => $start->toDateString(),
            'to'         => $end->toDateString(),
        ];
    }

    /**
     * Ekstrak daftar record dari berbagai bentuk respons Monitoring Kunjungan
     * VClaim (response.list / response.kunjungan / array langsung / record tunggal).
     */
    private function extractMonitoringList(array $resp): array
    {
        $r = $resp['response'] ?? null;
        if (! is_array($r)) {
            return [];
        }
        foreach (['list', 'kunjungan', 'data', 'sep'] as $k) {
            if (isset($r[$k]) && is_array($r[$k]) && array_is_list($r[$k])) {
                return $r[$k];
            }
        }
        if (array_is_list($r)) {
            return $r;
        }
        return isset($r['noSep']) ? [$r] : [];
    }

    /**
     * Kirim massal kunjungan BPJS → daftar klaim (KlaimView) untuk rentang aktif.
     * Hanya kunjungan SIAP (ada `no_sep` + `diagnosis_utama` dari Dokter) yang
     * diproses; sisanya dilewati (skipped), bukan digagalkan. Per kunjungan dibuat
     * via prepareClaimData (idempoten — updateOrCreate `bpjs_claims`).
     *
     * @return array{sent:int,skipped:int,failed:int,scanned:int}
     */
    public function kirimKlaimMassal(array $filters): array
    {
        $from = ! empty($filters['tanggal']) ? $filters['tanggal'] : ($filters['tanggal_from'] ?? null);
        $to   = ! empty($filters['tanggal']) ? $filters['tanggal'] : ($filters['tanggal_to'] ?? null);
        if (! $from || ! $to) {
            throw new \Exception('Tentukan tanggal atau rentang terlebih dahulu.', 422);
        }

        $visits = Visit::query()
            ->where('guarantor_type', 'BPJS')
            ->whereNotNull('no_sep')
            ->whereDate('visit_date', '>=', $from)
            ->whereDate('visit_date', '<=', $to)
            ->when(! empty($filters['jenis']), fn ($q) => $q->where('jenis_pelayanan', $filters['jenis']))
            ->with('doctorExamination:id,visit_id,diagnosis_utama')
            ->get();

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($visits as $v) {
            if (empty($v->doctorExamination?->diagnosis_utama)) {
                $skipped++;   // belum ada diagnosis utama dari Dokter
                continue;
            }
            try {
                $this->prepareClaimData($v->id);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed, 'scanned' => $visits->count()];
    }

    /**
     * Daftar SEMUA kunjungan pasien BPJS (termasuk yang belum punya klaim),
     * difilter tanggal/rentang + pencarian, untuk layar rekap pra-klaim.
     */
    public function getBpjsVisitRecap(array $filters = []): LengthAwarePaginator
    {
        $query = Visit::query()
            ->where('guarantor_type', 'BPJS')
            ->with([
                'patient:id,name,no_rm,bpjs_number,nik,date_of_birth,gender',
                'dpjp:id,name',
                'doctorExamination:id,visit_id,doctor_id,diagnosis_utama',
                'doctorExamination.doctor:id,name',
                'doctorSchedule.employee:id,name',
                'billingInvoice:id,visit_id,status',
                'bpjsClaim:id,visit_id,diagnosis_utama',
                'bpjsClaim.attachments',
                'surgerySchedule:id,surgery_package_id',
                'surgerySchedule.surgeryPackage:id,name,surgery_type',
                // Dokumen RM pendukung klaim (live) — untuk status siap-klaim per baris.
                'patientDocuments' => fn ($q) => $q
                    ->whereIn('template_code', self::CLAIM_DOC_CODES)
                    ->whereNotIn('status', self::DOC_ARCHIVED_STATUSES)
                    ->select('id', 'visit_id', 'template_code', 'status'),
            ])
            // Hasil penunjang terstruktur: jumlah order vs order yang sudah ada hasil final.
            ->withCount([
                'diagnosticOrders as penunjang_order_count',
                'diagnosticOrders as penunjang_done_count' => fn ($q) => $q
                    ->whereHas('results', fn ($r) => $r
                        ->whereIn('result_status', ['COMPLETED', 'REVIEWED', 'APPROVED'])),
            ]);

        if (! empty($filters['tanggal'])) {
            $query->whereDate('visit_date', $filters['tanggal']);
        }
        if (! empty($filters['tanggal_from'])) {
            $query->whereDate('visit_date', '>=', $filters['tanggal_from']);
        }
        if (! empty($filters['tanggal_to'])) {
            $query->whereDate('visit_date', '<=', $filters['tanggal_to']);
        }
        // Tab jenis pelayanan: RAJAL / RANAP (IGD ikut hanya pada "Semua").
        if (! empty($filters['jenis'])) {
            $query->where('jenis_pelayanan', $filters['jenis']);
        }
        // Tab "DIVA & Berkas" KlaimView: HANYA kunjungan yang sudah "Kirim ke Klaim".
        if (! empty($filters['only_sent'])) {
            $query->whereNotNull('klaim_sent_at');
        }

        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('no_sep', 'like', "%{$kw}%")
                ->orWhereHas('patient', fn ($p) => $p
                    ->where('name', 'ilike', "%{$kw}%")
                    ->orWhere('bpjs_number', 'like', "%{$kw}%"))
            );
        }

        // Urut No SEP menaik (rekap per bulan). Kunjungan tanpa SEP ditaruh paling bawah.
        $page = $query->orderByRaw('no_sep IS NULL')->orderBy('no_sep')->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        // Label ICD-10 HANYA untuk kode yang muncul di halaman ini — hindari memuat
        // SELURUH tabel ICD (~14k baris) tiap request (endpoint dipanggil juga oleh
        // polling/refresh KlaimView). Kode dx: prioritas koding KLAIM (koreksi koder)
        // atas exam dokter — selaras tampilan diagnosa di baris (lihat $dxCode).
        $dxCodes = $page->getCollection()->map(fn ($v) =>
            $v->bpjsClaim?->diagnosis_utama ?? $v->doctorExamination?->diagnosis_utama
        )->filter()->unique()->values()->all();
        $icd10   = \App\Models\Icd10Code::whereIn('code', $dxCodes)->pluck('description', 'code');
        $icd10Id = \App\Models\Icd10Code::whereIn('code', $dxCodes)->pluck('indonesian_description', 'code');

        $jenisMap  = ['RANAP' => 'Rawat Inap', 'IGD' => 'Gawat Darurat', 'RAJAL' => 'Rawat Jalan'];
        $kelasMap  = ['1' => 'Kelas 1', '2' => 'Kelas 2', '3' => 'Kelas 3'];
        $genderMap = ['L' => 'Laki-laki', 'P' => 'Perempuan'];

        $page->getCollection()->transform(function ($v) use ($icd10, $icd10Id, $jenisMap, $kelasMap, $genderMap) {
            $v->append('dpjp_name');
            $sep = (array) ($v->sep_data ?? []);

            $dxCode = $v->bpjsClaim?->diagnosis_utama ?? $v->doctorExamination?->diagnosis_utama;
            $diagnosa = $dxCode
                ? trim($dxCode.' — '.(($icd10Id->get($dxCode) ?: $icd10->get($dxCode)) ?? ''))
                : null;
            $att = $v->bpjsClaim?->attachments ?? collect();

            // Tgl SEP: dari snapshot sep_data saat terbit, fallback tgl kunjungan.
            $tglSep = $sep['tglSep'] ?? $v->visit_date?->toDateString();
            // Kelas rawat (hak): snapshot dahulu, lalu kolom visit.
            $kls = (string) ($sep['klsRawatHak'] ?? $v->kelas_rawat_hak ?? '');
            // No rujukan: snapshot dahulu, lalu kolom visit.
            $noRujukan = $sep['noRujukan'] ?? $v->no_rujukan;
            $dob = $v->patient?->date_of_birth;

            // Penanda bedah: label dari tipe operasi paket (KATARAK/VITREORETINA/…),
            // fallback ke nama paket. Tanpa surgery_schedule → kunjungan tunggal.
            $pkg = $v->surgerySchedule?->surgeryPackage;
            $isBedah = $v->surgery_schedule_id !== null;
            $bedahLabel = $isBedah ? ($pkg?->surgery_type ?: $pkg?->name ?: 'Bedah') : null;

            // Status siap-klaim otomatis (dari data eager-loaded → tanpa N+1).
            $signedCodes = $v->patientDocuments
                ->filter(fn ($d) => in_array($d->status, ['FINALIZED', 'FINAL'], true))
                ->pluck('template_code')->unique()->values()->all();
            $penunjangOrderCount = (int) ($v->penunjang_order_count ?? 0);
            $penunjangDoneCount  = (int) ($v->penunjang_done_count ?? 0);
            $penunjangOk = $penunjangOrderCount === 0 || $penunjangDoneCount >= $penunjangOrderCount;
            $readiness = $this->rowClaimReadiness($v, $signedCodes, $penunjangOk);

            return [
                'visit_id'           => $v->id,
                'nama'               => $v->patient?->name,
                'no_rm'              => $v->patient?->no_rm,
                'tgl_lahir'          => $dob ? \Illuminate\Support\Carbon::parse($dob)->format('d-m-Y') : null,
                'gender'             => $genderMap[$v->patient?->gender] ?? $v->patient?->gender,
                'no_sep'             => $v->no_sep,
                'tgl_sep'            => $tglSep ? \Illuminate\Support\Carbon::parse($tglSep)->format('d-m-Y') : null,
                'jenis'              => $jenisMap[$v->jenis_pelayanan] ?? ($v->jenis_pelayanan ?? '—'),
                'jenis_kode'         => $v->jenis_pelayanan,
                'is_bedah'           => $isBedah,
                'bedah_label'        => $bedahLabel,
                'kelas'              => $kelasMap[$kls] ?? ($kls !== '' ? $kls : null),
                'no_rujukan'         => $noRujukan ?: null,
                'bpjs_number'        => $v->patient?->bpjs_number,
                'dpjp'               => $v->dpjp_name,
                'diagnosa'           => $diagnosa,
                'claim_id'           => $v->bpjsClaim?->id,
                'penunjang_count'    => $att->where('category', 'PENUNJANG')->count(),
                'penunjang_struct_count' => $penunjangDoneCount,
                'dokpendukung_count' => $att->where('category', '!=', 'PENUNJANG')->count(),
                // Status siap-klaim otomatis (dokumen wajib ber-TTD + penunjang final).
                'claim_ready'         => $readiness['claim_ready'],
                'docs_signed_count'   => $readiness['docs_signed_count'],
                'docs_required_count' => $readiness['docs_required_count'],
                'has_invoice'        => (bool) $v->billingInvoice,
                'invoice_status'     => $v->billingInvoice?->status,
                'is_paid'            => $v->billingInvoice?->status === 'PAID',
                // Resume medis (tab History): ada dokumen RESUME_MEDIS? sudah TTD?
                'has_resume'         => $v->patientDocuments->contains(fn ($d) => $d->template_code === 'RESUME_MEDIS'),
                'resume_signed'      => in_array('RESUME_MEDIS', $signedCodes, true),
                // Screening pra-klaim (manual): null=belum dicek, true=Lengkap, false=Belum.
                'berkas_lengkap'     => $v->berkas_lengkap,
                'keterangan'         => $v->rekap_keterangan,
                // Pipeline berkas klaim: penanda terkirim & jejak dikembalikan + pesan.
                'klaim_sent_at'      => $v->klaim_sent_at?->toIso8601String(),
                'klaim_returned_at'  => $v->klaim_returned_at?->toIso8601String(),
                'klaim_return_note'  => $v->klaim_return_note,
            ];
        });

        return $page;
    }

    /**
     * Kunjungan BPJS untuk bundel berkas (ZIP kwitansi/resume) di tab History.
     * Mengembalikan model Visit (bukan array rekap) berikut relasi yang diperlukan
     * untuk render PDF. Filter identik dgn getBpjsVisitRecap (tanggal/rentang +
     * jenis + search), tanpa paginasi. Urut No SEP menaik.
     */
    public function getRecapVisitsForBundle(array $filters = []): \Illuminate\Support\Collection
    {
        $query = Visit::query()
            ->where('guarantor_type', 'BPJS')
            ->with([
                'patient:id,name,no_rm,bpjs_number',
                'billingInvoice:id,visit_id,status',
                'surgerySchedule:id,surgery_package_id',
                'surgerySchedule.surgeryPackage:id,surgery_type,name',
            ]);

        if (! empty($filters['tanggal'])) {
            $query->whereDate('visit_date', $filters['tanggal']);
        }
        if (! empty($filters['tanggal_from'])) {
            $query->whereDate('visit_date', '>=', $filters['tanggal_from']);
        }
        if (! empty($filters['tanggal_to'])) {
            $query->whereDate('visit_date', '<=', $filters['tanggal_to']);
        }
        if (! empty($filters['jenis'])) {
            $query->where('jenis_pelayanan', $filters['jenis']);
        }
        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('no_sep', 'like', "%{$kw}%")
                ->orWhereHas('patient', fn ($p) => $p
                    ->where('name', 'ilike', "%{$kw}%")
                    ->orWhere('bpjs_number', 'like', "%{$kw}%"))
            );
        }

        return $query->orderByRaw('no_sep IS NULL')->orderBy('no_sep')->orderBy('id')->get();
    }

    /**
     * Kode dokumen RM untuk bundel ZIP Resume: resume medis + laporan operasi
     * (untuk kunjungan bedah, sesuai tipe operasi). TANPA checklist kesiapan bedah
     * (bukan resume). Bandingkan requiredDocCodes() yang dipakai untuk siap-klaim.
     */
    public function resumeBundleDocCodes(Visit $visit): array
    {
        $codes = ['RESUME_MEDIS'];
        if ($visit->surgery_schedule_id !== null) {
            $type = strtoupper((string) ($visit->surgerySchedule?->surgeryPackage?->surgery_type ?? ''));
            $codes[] = str_contains($type, 'KATARAK') ? 'CATATAN_OPERASI_KATARAK'
                : (str_contains($type, 'VITREO') ? 'LAPORAN_OPERASI_VITREO_RETINA' : 'LAPORAN_PEMBEDAHAN');
        }

        return $codes;
    }

    /**
     * Pastikan visit punya BpjsClaim (untuk lampiran rekap). Buat DRAFT minimal
     * bila belum ada. HANYA dipanggil pada jalur tulis (upload/hapus lampiran)
     * supaya daftar klaim tidak banjir DRAFT phantom dari sekadar melihat.
     */
    public function ensureClaimForVisit(string $visitId): BpjsClaim
    {
        $visit = Visit::with(['patient', 'doctorExamination'])->findOrFail($visitId);

        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }
        if (empty($visit->no_sep)) {
            throw new \Exception('Nomor SEP belum ada — generate SEP di Admisi terlebih dahulu.', 422);
        }

        $existing = BpjsClaim::where('visit_id', $visit->id)->first();
        if ($existing) {
            // Backfill diagnosa dari pemeriksaan dokter bila klaim lama masih kosong.
            $this->backfillClaimCodingFromExam($existing, $visit->doctorExamination);

            return $existing;
        }

        // Default koding klaim = koding dokter (koder bisa menyesuaikan nanti) →
        // klaim tak lahir "Perlu Diagnosis" padahal dokter sudah memilih.
        $exam = $visit->doctorExamination;

        return BpjsClaim::create([
            'visit_id'           => $visit->id,
            'no_sep'             => $visit->no_sep,
            'patient_nik'        => $visit->patient?->nik,
            'diagnosis_utama'    => $exam?->diagnosis_utama,
            'diagnosis_sekunder' => $this->stripToCodes($exam?->diagnosis_sekunder),
            'procedure_codes'    => $this->stripToCodes($exam?->tindakan_codes),
            'status'             => 'DRAFT',
        ]);
    }

    /**
     * Screening pra-klaim: petugas menandai kelengkapan berkas + keterangan (KET)
     * pada kunjungan BPJS. Murni manual, disimpan di kolom `visits`. `$lengkap`
     * null = belum dicek, true = Lengkap, false = Belum Lengkap.
     */
    public function setRekapKelengkapan(string $visitId, ?bool $lengkap, ?string $keterangan, ?string $userId): array
    {
        $visit = Visit::findOrFail($visitId);
        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }

        $visit->berkas_lengkap = $lengkap;
        $visit->rekap_keterangan = $keterangan;
        $visit->berkas_lengkap_by = $lengkap === null ? null : $userId;
        $visit->berkas_lengkap_at = $lengkap === null ? null : now();
        $visit->save();

        return [
            'visit_id'       => $visit->id,
            'berkas_lengkap' => $visit->berkas_lengkap,
            'keterangan'     => $visit->rekap_keterangan,
        ];
    }

    // =========================================================================
    // BERKAS KUNJUNGAN (LIVE) — dokumen RM + hasil penunjang + lampiran manual
    // =========================================================================

    /** Dokumen Form Registry yang relevan sebagai berkas pendukung klaim. */
    public const CLAIM_DOC_CODES = [
        'RESUME_MEDIS', 'RESUME_KLAIM', 'LAPORAN_PEMBEDAHAN',
        'CATATAN_OPERASI_KATARAK', 'LAPORAN_OPERASI_VITREO_RETINA', 'CHECKLIST_KESIAPAN_BEDAH',
        // Pengkajian Gawat Darurat (RM 3.7) — dokumen klinis pendukung klaim IGD BPJS.
        'PENGKAJIAN_IGD_3_7',
    ];

    /** Status arsip dokumen — dikecualikan dari daftar berkas aktif. */
    public const DOC_ARCHIVED_STATUSES = ['SUPERSEDED', 'VOID', 'REJECTED'];

    private function docStatusLabel(string $status): string
    {
        return match ($status) {
            'DRAFT'                      => 'Draf',
            'RENDERED', 'PENDING_SIGNATURE' => 'Menunggu TTD',
            'FINALIZED', 'FINAL'         => 'Sudah TTD',
            default                      => $status,
        };
    }

    /**
     * Kode dokumen WAJIB ber-TTD untuk klaim, sesuai jenis kunjungan:
     * - Semua: RESUME_MEDIS.
     * - Bedah: laporan operasi sesuai surgery_type + checklist bedah.
     */
    private function requiredDocCodes(Visit $visit): array
    {
        $codes = ['RESUME_MEDIS'];
        if ($visit->surgery_schedule_id !== null) {
            $type = strtoupper((string) ($visit->surgerySchedule?->surgeryPackage?->surgery_type ?? ''));
            $codes[] = str_contains($type, 'KATARAK') ? 'CATATAN_OPERASI_KATARAK'
                : (str_contains($type, 'VITREO') ? 'LAPORAN_OPERASI_VITREO_RETINA' : 'LAPORAN_PEMBEDAHAN');
            $codes[] = 'CHECKLIST_KESIAPAN_BEDAH';
        }
        return $codes;
    }

    /**
     * Agregasi berkas pendukung klaim untuk SATU kunjungan — dibaca LIVE dari
     * sumber aslinya (tidak menyalin file):
     *  - documents : PatientDocument Form Registry (resume/laporan operasi/checklist)
     *  - penunjang : diagnostic_results terstruktur
     *  - manual    : ClaimAttachment (berkas luar yang di-upload)
     *  - checklist : kelengkapan otomatis (wajib TTD + sesuai jenis)
     */
    public function getVisitBerkas(string $visitId): array
    {
        $visit = Visit::with([
            'surgerySchedule.surgeryPackage:id,name,surgery_type',
            'bpjsClaim',
        ])->findOrFail($visitId);

        $docs = PatientDocument::where('visit_id', $visitId)
            ->whereIn('template_code', self::CLAIM_DOC_CODES)
            ->whereNotIn('status', self::DOC_ARCHIVED_STATUSES)
            ->orderBy('template_code')
            ->orderByDesc('created_at')
            ->get();

        $tplNames = DocumentTemplate::whereIn('code', self::CLAIM_DOC_CODES)->pluck('name', 'code');
        $claim = $visit->bpjsClaim;

        $documents = $docs->map(function (PatientDocument $d) use ($tplNames) {
            $signed = in_array($d->status, ['FINALIZED', 'FINAL'], true);
            return [
                'id'            => $d->id,
                'source'        => 'document',
                'template_code' => $d->template_code,
                'type_label'    => $tplNames[$d->template_code] ?? $d->template_code,
                'status'        => $d->status,
                'status_label'  => $this->docStatusLabel($d->status),
                'signed'        => $signed,
                'claim_ready'   => $signed,
                'revision'      => $d->revision,
                'signed_at'     => $d->finalized_at?->toIso8601String(),
                'coding_synced' => null,
            ];
        })->values()->all();

        $penunjang = app(\App\Services\RmeAggregatorService::class)->penunjangForVisit($visitId);
        $manual = $claim ? $this->getAttachments($claim->id) : [];
        $checklist = $this->computeClaimChecklist($visit, $docs);

        return [
            // Lembar INA-CBG (luaran E-Klaim) dirakit ke bundel Vedika & bisa di-preview
            // via /klaim/{claim_id}/cetak — HANYA bila klaim sudah di-grouping (ada kode).
            'claim_id'     => $claim?->id,
            'inacbgs_kode' => $claim?->inacbgs_kode,
            'documents' => $documents,
            'penunjang' => $penunjang,
            'manual'    => $manual,
            'checklist' => $checklist,
        ];
    }

    /**
     * Kelengkapan klaim otomatis: tiap dokumen wajib harus FINALIZED; penunjang
     * (bila ada order) harus ada hasil COMPLETED/REVIEWED/APPROVED.
     * @param \Illuminate\Support\Collection<PatientDocument> $docs
     */
    public function computeClaimChecklist(Visit $visit, $docs): array
    {
        // template_code → signed? (true bila ada salah satu yg FINALIZED)
        $signedByCode = [];
        $presentByCode = [];
        foreach ($docs as $d) {
            $presentByCode[$d->template_code] = true;
            if (in_array($d->status, ['FINALIZED', 'FINAL'], true)) {
                $signedByCode[$d->template_code] = true;
            }
        }

        $tplNames = DocumentTemplate::whereIn('code', self::CLAIM_DOC_CODES)->pluck('name', 'code');
        $required = [];
        foreach ($this->requiredDocCodes($visit) as $code) {
            $required[] = [
                'key'     => $code,
                'label'   => $tplNames[$code] ?? $code,
                'present' => $presentByCode[$code] ?? false,
                'signed'  => $signedByCode[$code] ?? false,
            ];
        }

        // Penunjang: wajib hasil final bila ada order.
        $orders = \App\Models\DiagnosticOrder::where('visit_id', $visit->id)
            ->withCount(['results as done_count' => fn ($q) => $q
                ->whereIn('result_status', ['COMPLETED', 'REVIEWED', 'APPROVED'])])
            ->get();
        if ($orders->isNotEmpty()) {
            $allDone = $orders->every(fn ($o) => $o->done_count > 0);
            $required[] = [
                'key'     => 'PENUNJANG',
                'label'   => 'Hasil Penunjang',
                'present' => $orders->contains(fn ($o) => $o->done_count > 0),
                'signed'  => $allDone,
            ];
        }

        $missing = collect($required)->reject(fn ($r) => $r['signed'])->pluck('label')->values()->all();

        return [
            'required' => $required,
            'ready'    => count($missing) === 0,
            'missing'  => $missing,
        ];
    }

    /**
     * Status siap-klaim ringkas dari data yang SUDAH di-eager-load pada baris rekap
     * (tanpa query tambahan → aman dari N+1). $signedCodes = template_code dokumen
     * FINALIZED milik visit; $penunjangOk dari withCount order vs hasil selesai.
     */
    private function rowClaimReadiness(Visit $visit, array $signedCodes, bool $penunjangOk): array
    {
        $required = $this->requiredDocCodes($visit);
        $signedRequired = array_values(array_intersect($required, $signedCodes));
        $docsReady = count($signedRequired) === count($required);

        return [
            'docs_required_count' => count($required),
            'docs_signed_count'   => count($signedRequired),
            'claim_ready'         => $docsReady && $penunjangOk,
        ];
    }

    /**
     * Verifikator minta dokter mengoreksi diagnosa/dokumen (grouper mismatch).
     * Catat keterangan + tandai belum-lengkap + notifikasi DPJP. Koreksi nyata
     * dilakukan dokter via "Buka Kembali" (pra-bayar) / "Revisi & TTD Ulang".
     */
    public function requestCorrection(string $visitId, ?string $catatan, ?string $userId): array
    {
        $visit = Visit::with(['patient:id,name', 'doctorExamination:id,visit_id,doctor_id', 'surgerySchedule:id,lead_surgeon_id'])
            ->findOrFail($visitId);
        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }

        $note = trim((string) $catatan);
        $visit->rekap_keterangan = $note !== ''
            ? $note
            : ($visit->rekap_keterangan ?: 'Perlu koreksi diagnosa/dokumen untuk klaim');
        $visit->berkas_lengkap = false;
        $visit->save();

        $dpjpEmployeeId = $visit->doctorExamination?->doctor_id ?? $visit->surgerySchedule?->lead_surgeon_id;
        $recipientId = $dpjpEmployeeId
            ? \App\Models\User::where('employee_id', $dpjpEmployeeId)->value('id')
            : null;

        if ($recipientId) {
            \App\Models\Notification::create([
                'recipient_id' => $recipientId,
                'type'         => 'KLAIM_KOREKSI',
                'title'        => 'Permintaan koreksi untuk klaim BPJS',
                'message'      => 'Verifikator meminta koreksi diagnosa/dokumen kunjungan '
                    . ($visit->patient?->name ?? '')
                    . ($note !== '' ? ' — ' . $note : '')
                    . '. Buka kembali RME & finalisasi ulang.',
            ]);
        }

        $this->log($userId, 'KLAIM_MINTA_KOREKSI', Visit::class, $visit->id, $note ?: null);

        return [
            'visit_id'   => $visit->id,
            'notified'   => (bool) $recipientId,
            'keterangan' => $visit->rekap_keterangan,
        ];
    }

    // =========================================================================
    // PREPARE — build klaim dari data kunjungan
    // =========================================================================

    /**
     * Normalisasi list diagnosa/prosedur ke KODE telanjang. Exam kini menyimpan
     * {code,name} (sub-diagnosa), tapi klaim/SEP hanya butuh kode kanonik. Toleran
     * elemen string (data lama) maupun objek.
     */
    private function stripToCodes($arr): array
    {
        return collect(is_array($arr) ? $arr : [])
            ->map(fn ($el) => is_array($el) ? ($el['code'] ?? $el['kode'] ?? null) : $el)
            ->map(fn ($c) => is_string($c) ? trim($c) : null)
            ->filter()->unique()->values()->all();
    }

    /**
     * Create or update BpjsClaim from visit doctor examination data.
     * Source: visit.no_sep, doctorExamination.{diagnosis_utama, diagnosis_sekunder, tindakan_codes}
     */
    public function prepareClaimData(string $visitId): BpjsClaim
    {
        $visit = Visit::with([
            'patient',
            'doctorExamination',
            'billingInvoice',
        ])->findOrFail($visitId);

        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }

        if (empty($visit->no_sep)) {
            throw new \Exception('Nomor SEP belum ada — generate SEP terlebih dahulu di Admisi.', 422);
        }

        $exam = $visit->doctorExamination;

        if (! $exam?->diagnosis_utama) {
            throw new \Exception('Diagnosis utama belum diisi oleh Dokter.', 422);
        }

        $user = auth('api')->user();

        $claim = DB::transaction(function () use ($visit, $exam, $user) {
            $existing = BpjsClaim::where('visit_id', $visit->id)->first();
            $oldStatus = $existing?->status;

            // Klaim/SEP = KODE kanonik saja (BPJS-safe). Exam menyimpan {code,name}
            // (sub-diagnosa) → strip ke kode telanjang; nama spesifik hidup di RME.
            if ($existing) {
                // Kirim ulang (mis. setelah dikembalikan ke Rekap): JANGAN timpa koding
                // yang mungkin sudah disesuaikan koder lewat updateClaimCoding — selaras
                // backfillClaimCodingFromExam yang sengaja tak menimpa koder. Hanya segarkan
                // identitas, dan isi koding dari dokter HANYA bila klaim masih kosong.
                $existing->no_sep      = $visit->no_sep;
                $existing->patient_nik = $visit->patient->nik;
                if (empty($existing->diagnosis_utama)) {
                    $existing->diagnosis_utama    = $exam->diagnosis_utama;
                    $existing->diagnosis_sekunder = $this->stripToCodes($exam->diagnosis_sekunder);
                    $existing->procedure_codes    = $this->stripToCodes($exam->tindakan_codes);
                }
                $existing->save();
                $claim = $existing;
            } else {
                $claim = BpjsClaim::create([
                    'visit_id'           => $visit->id,
                    'no_sep'             => $visit->no_sep,
                    'patient_nik'        => $visit->patient->nik,
                    'diagnosis_utama'    => $exam->diagnosis_utama,
                    'diagnosis_sekunder' => $this->stripToCodes($exam->diagnosis_sekunder),
                    'procedure_codes'    => $this->stripToCodes($exam->tindakan_codes),
                    'status'             => 'DRAFT',
                ]);
            }

            $this->addAuditLog(
                $claim->id,
                $user?->employee_id,
                'PREPARE',
                $oldStatus,
                $claim->status,
                'Data klaim disiapkan dari data kunjungan'
            );

            return $claim;
        });

        // Tandai kunjungan SUDAH dikirim ke klaim → tab "DIVA & Berkas" KlaimView
        // hanya menampilkan yang berpenanda ini. Hapus jejak "dikembalikan" bila ada
        // (kunjungan dikirim ulang setelah dikembalikan).
        $visit->forceFill([
            'klaim_sent_at'     => now(),
            'klaim_sent_by'     => $user?->id,
            'klaim_returned_at' => null,
            'klaim_return_note' => null,
        ])->save();

        $this->log($user?->id, 'PREPARE_CLAIM', BpjsClaim::class, $claim->id, "SEP {$visit->no_sep}");

        return $claim->fresh(['visit.patient', 'auditLogs.performedBy']);
    }

    /**
     * Kembalikan kunjungan dari Berkas Klaim (KlaimView) ke Rekap Kunjungan BPJS
     * beserta pesan. Mengosongkan penanda kirim → kunjungan hilang dari tab Berkas
     * dan muncul lagi di Rekap dengan badge "Dikembalikan dari Klaim" + pesan, serta
     * ditandai Belum Lengkap. Tidak menotifikasi DPJP (murni antrean verifikator).
     */
    public function returnClaimToRekap(string $visitId, ?string $note, ?string $userId): array
    {
        $visit = Visit::findOrFail($visitId);
        if ($visit->guarantor_type !== 'BPJS') {
            throw new \Exception('Kunjungan bukan pasien BPJS.', 422);
        }
        if (empty($visit->klaim_sent_at)) {
            throw new \Exception('Kunjungan ini belum dikirim ke klaim.', 422);
        }

        $msg = trim((string) $note);
        $visit->forceFill([
            'klaim_sent_at'     => null,
            'klaim_sent_by'     => null,
            'klaim_returned_at' => now(),
            'klaim_return_note' => $msg !== '' ? $msg : 'Dikembalikan dari Klaim untuk dilengkapi.',
            'berkas_lengkap'    => false,
        ])->save();

        $this->log($userId, 'KLAIM_KEMBALIKAN_REKAP', Visit::class, $visit->id, $msg ?: null);

        return [
            'visit_id'          => $visit->id,
            'klaim_returned_at' => $visit->klaim_returned_at?->toIso8601String(),
            'klaim_return_note' => $visit->klaim_return_note,
        ];
    }

    // =========================================================================
    // ASSIGNMENT — tandai "dikerjakan oleh siapa" (soft, anti double-work)
    // =========================================================================

    /**
     * Tandai/lepas penanggung jawab klaim.
     * $userId = null → lepaskan. Soft (tidak mengunci).
     */
    public function assignClaim(string $claimId, ?string $assignToId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);
        $actor = auth('api')->user();

        $claim->update([
            'assigned_to_id' => $assignToId,
            'assigned_at'    => $assignToId ? now() : null,
        ]);

        $note = $assignToId
            ? 'Klaim ditandai dikerjakan oleh ' . (\App\Models\User::find($assignToId)?->name ?? $assignToId)
            : 'Penanda pengerjaan dilepas';
        $this->addAuditLog($claim->id, $actor?->employee_id, 'ASSIGN', $claim->status, $claim->status, $note);

        return $claim->fresh(['assignedTo', 'auditLogs.performedBy']);
    }

    // =========================================================================
    // KODING — edit diagnosis/tindakan klaim (oleh verifikator/koder)
    // =========================================================================

    /**
     * Perbarui koding klaim (diagnosis utama/sekunder + tindakan ICD-9).
     * Tidak menyentuh doctorExamination (rekam medis). Karena koding berubah,
     * hasil grouping & LUPIS direset — wajib grouping ulang.
     */
    public function updateClaimCoding(string $claimId, array $data): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim ke BPJS, koding tidak bisa diubah.', 422);
        }

        // Validasi kode ICD ada di master (koding BPJS wajib kode valid).
        $icd10Codes = array_filter(array_merge([$data['diagnosis_utama']], $data['diagnosis_sekunder'] ?? []));
        $icd9Codes  = array_filter($data['procedure_codes'] ?? []);

        $known10 = \App\Models\Icd10Code::whereIn('code', $icd10Codes)->pluck('code')->all();
        $unknown10 = array_diff($icd10Codes, $known10);
        if ($unknown10) {
            throw new \Exception('Kode ICD-10 tidak ditemukan di master: ' . implode(', ', $unknown10), 422);
        }

        $known9 = \App\Models\Icd9Code::whereIn('code', $icd9Codes)->pluck('code')->all();
        $unknown9 = array_diff($icd9Codes, $known9);
        if ($unknown9) {
            throw new \Exception('Kode ICD-9 tidak ditemukan di master: ' . implode(', ', $unknown9), 422);
        }

        $user      = auth('api')->user();
        $oldUtama  = $claim->diagnosis_utama;

        $claim->update([
            'diagnosis_utama'    => $data['diagnosis_utama'],
            'diagnosis_sekunder' => array_values($data['diagnosis_sekunder'] ?? []),
            'procedure_codes'    => array_values($data['procedure_codes'] ?? []),
            // Koding berubah → grouping & LUPIS tidak valid lagi.
            'inacbgs_kode'       => null,
            'inacbgs_tarif'      => null,
            'lupis_data'         => null,
        ]);

        $this->addAuditLog(
            $claim->id,
            $user?->employee_id,
            'EDIT_CODING',
            $claim->status,
            $claim->status,
            "Koding klaim diperbarui (Dx utama: {$oldUtama} → {$data['diagnosis_utama']}). Grouping direset."
        );
        $this->log($user?->id, 'EDIT_CLAIM_CODING', BpjsClaim::class, $claimId);

        // Koding klaim (oleh koder) berubah TIDAK menyentuh resume medis dokter:
        // resume medis = dokumen klinis milik dokter. Bila perlu disesuaikan, dokter
        // merevisi resume medisnya sendiri — bukan di-reset otomatis dari sini.

        return $claim->fresh(['auditLogs.performedBy']);
    }

    // =========================================================================
    // DOKUMEN KLAIM — bukti pendukung BPJS = RESUME MEDIS ber-TTD milik kunjungan
    // (RM-6.1 / RESUME_MEDIS), yang sudah dokter tandatangani saat pelayanan.
    // TIDAK ada "lembar klaim" terpisah: dokter cukup TTD resume medis sekali (tak
    // kerja dua kali). Bila resume tak sesuai koding, dokter merevisi resume medis
    // itu — bukan menandatangani ulang dokumen klaim khusus.
    // =========================================================================

    private const CLAIM_RESUME_CODE = 'RESUME_MEDIS';

    /** Status resume medis (bukti klaim) untuk satu klaim (dipakai detail klaim & FE). */
    public function claimResumeStatus(string $claimId): array
    {
        $claim = BpjsClaim::findOrFail($claimId);
        return $this->claimResumeSummary($this->findClaimResumeDoc($claim), $claim);
    }

    /** Ringkasan status lembar klaim (exists/status/signed/sinkron-koding). */
    public function claimResumeSummary(?PatientDocument $doc, BpjsClaim $claim): array
    {
        if (! $doc) {
            return [
                'exists' => false, 'document_id' => null, 'status' => null,
                'signed' => false, 'signed_at' => null, 'coding_synced' => false,
            ];
        }
        return [
            'exists'        => true,
            'document_id'   => $doc->id,
            'status'        => $doc->status,
            'signed'        => $doc->status === 'FINALIZED',
            'signed_at'     => $doc->finalized_at?->toIso8601String(),
            // Resume medis klinis = bukti klaim; tak distempel koding klaim → selalu sinkron.
            'coding_synced' => true,
        ];
    }

    private function findClaimResumeDoc(BpjsClaim $claim): ?PatientDocument
    {
        // Bukti klaim = RESUME MEDIS ber-TTD milik kunjungan (dibuat dokter saat
        // pelayanan), bukan dokumen klaim terpisah.
        return PatientDocument::where('visit_id', $claim->visit_id)
            ->where('template_code', self::CLAIM_RESUME_CODE)
            ->whereNotIn('status', self::DOC_ARCHIVED_STATUSES)
            ->orderByDesc('created_at')
            ->first();
    }

    /** Guard sebelum finalisasi BPJS: resume medis kunjungan wajib ADA & sudah di-TTD dokter. */
    private function assertClaimResumeReady(BpjsClaim $claim): void
    {
        $doc = $this->findClaimResumeDoc($claim);
        if (! $doc || $doc->status !== 'FINALIZED') {
            throw new \Exception('Resume medis belum ditandatangani dokter. Minta dokter melengkapi & menandatangani resume medis (RME / Tanda Tangan Dokumen) sebelum finalisasi klaim.', 422);
        }
    }

    // =========================================================================
    // INA-CBGs GROUPING
    // =========================================================================

    /**
     * Grouping INA-CBGs via WS E-Klaim resmi (BUKAN grouper mock).
     *
     * Satu tombol "Jalankan Grouping" di UI = rangkaian WS lengkap:
     *   new_claim → set_claim_data → grouper
     * sehingga kode CBG + tarif yang tersimpan adalah hasil aplikasi E-Klaim,
     * bukan angka placeholder. set_claim_data idempoten di sisi E-Klaim
     * (mengirim ulang payload terkini), jadi aman dipanggil saat grouping ulang.
     */
    public function runInaCbgsGrouping(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'])) {
            throw new \Exception('Klaim sudah disubmit, tidak bisa grouping ulang.', 422);
        }

        if (! $claim->diagnosis_utama) {
            throw new \Exception('Diagnosis utama belum ada.', 422);
        }

        // 1) Registrasi klaim ke E-Klaim (idempoten: klaim yang sudah ada akan
        //    dijawab "sudah terdaftar" oleh ws.php — tetap lanjut).
        $this->eklaimNewClaim($claimId);

        // 2) Kirim/refresh data klaim (diagnosa, prosedur, tarif RS, dll).
        $this->eklaimSetData($claimId);

        // 3) Jalankan grouper resmi → simpan CBG + tarif balik ke klaim.
        return $this->eklaimGrouper($claimId);
    }

    // =========================================================================
    // E-KLAIM INA-CBG (Web Service ws.php)
    // =========================================================================
    //
    // Alur: eklaimNewClaim -> eklaimSetData -> eklaimGrouper -> eklaimFinal,
    // dengan eklaimStatus / eklaimReedit untuk sinkron & koreksi. Semua call
    // didelegasikan ke InaCbgsService (WS client) dan tercatat di
    // inacbgs_grouping_logs. Hasil grouping disimpan balik ke kolom klaim.

    /** new_claim — registrasi klaim (SEP/RM) ke E-Klaim. */
    public function eklaimNewClaim(string $claimId): array
    {
        $claim = $this->guardEklaimReady($claimId);

        $res = $this->eklaim->newClaim([
            'nomor_sep' => $claim->no_sep,
            'nomor_rm'  => $claim->visit?->patient?->no_rm ?? $claim->patient_nik,
            'nomor_kartu' => $claim->visit?->patient?->bpjs_number,
            'nama_pasien' => $claim->visit?->patient?->name,
        ], $claim->id, $claim->visit_id);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_NEW',
            $claim->status, $claim->status, $res['message'] ?? ('code ' . ($res['code'] ?? '-')));

        return $res;
    }

    /** set_claim_data — kirim payload klaim lengkap (builder Fase 4). */
    public function eklaimSetData(string $claimId): array
    {
        $claim = $this->guardEklaimReady($claimId);

        $payload = $this->buildEklaimPayload($claim);
        $res = $this->eklaim->setClaimData($payload, $claim->id, $claim->visit_id);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_SET_DATA',
            $claim->status, $claim->status, $res['message'] ?? ('code ' . ($res['code'] ?? '-')));

        return $res;
    }

    /** grouper — jalankan grouper E-Klaim, simpan CBG + tarif balik ke klaim. */
    public function eklaimGrouper(string $claimId, int $stage = 1, ?string $specialCmg = null): BpjsClaim
    {
        $claim = $this->guardEklaimReady($claimId);

        $res = $this->eklaim->grouper($claim->no_sep, $stage, $specialCmg, $claim->id, $claim->visit_id);

        // Bentuk respons grouper E-Klaim. Terverifikasi dari get_claim_data:
        // grouper.response_inacbg.cbg.{code,tariff}. Sediakan beberapa fallback
        // karena method grouper langsung bisa membungkus berbeda dari get_claim_data.
        $resp = $res['raw']['response'] ?? $res['data'] ?? [];
        $cbg = $res['data']['cbg']
            ?? $res['data']['response_inacbg']['cbg']
            ?? $res['raw']['response']['cbg']
            ?? $res['raw']['response']['response_inacbg']['cbg']
            ?? $res['raw']['response']['grouper']['response_inacbg']['cbg']
            ?? [];
        $code  = $cbg['code']   ?? $cbg['kode']  ?? null;
        $tarif = $cbg['tariff'] ?? $cbg['tarif'] ?? $cbg['base_tariff'] ?? null;

        // Special CMG (top-up): Stage 1 mengembalikan daftar opsi; Stage 2 menerapkan
        // pilihan sehingga total tarif berubah. Cari di beberapa lokasi pembungkus.
        $cmgOptions = $resp['special_cmg_option']
            ?? $resp['response_inacbg']['special_cmg_option']
            ?? $cbg['special_cmg_option']
            ?? null;

        $update = [];
        if ($res['success'] && $code) {
            $update['inacbgs_kode']  = $code;
            $update['inacbgs_tarif'] = $tarif;
        }
        if (is_array($cmgOptions)) {
            // Normalisasi ke [{code,type,description,tariff}] bila bentuknya beragam.
            $update['special_cmg_options'] = array_values(array_map(fn ($o) => is_array($o) ? [
                'code'        => $o['code'] ?? $o['special_cmg'] ?? $o['kode'] ?? null,
                'type'        => $o['type'] ?? $o['special_cmg_type'] ?? null,
                'description' => $o['description'] ?? $o['name'] ?? $o['deskripsi'] ?? null,
                'tariff'      => $o['tariff'] ?? $o['tarif'] ?? null,
            ] : ['code' => (string) $o], $cmgOptions));
        }
        if ($stage === 2 && $specialCmg) {
            $update['special_cmg'] = $specialCmg;
            // Top-up = total (tarif Stage 2) − tarif dasar tersimpan sebelumnya.
            $base = (float) ($claim->inacbgs_tarif ?? 0);
            if ($tarif !== null && $base > 0) {
                $update['tarif_top_up'] = max(0, (float) $tarif - $base);
            }
            $tcw = $cbg['total_cost_weight'] ?? $cbg['total_weight'] ?? $cbg['cost_weight'] ?? null;
            if ($tcw !== null) {
                $update['total_cost_weight'] = (float) $tcw;
            }
        }

        if ($update) {
            $claim->update($update);
        }

        $cmgNote = $stage === 2 && $specialCmg ? " — Special CMG: {$specialCmg}" : '';
        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_GROUPER',
            $claim->status, $claim->status,
            $code ? "CBG: {$code} — Tarif: " . number_format((float) $tarif) . $cmgNote : ($res['message'] ?? 'Grouper gagal'));

        if (! $res['success']) {
            throw new \Exception('Grouper E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /** claim_final — finalisasi (irreversible). Tandai klaim SUBMITTED. */
    public function eklaimFinal(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Idempotensi: klaim yang sudah final/selesai jangan difinalisasi ulang
        // (mencegah re-stamp submitted_at & panggilan WS claim_final berulang).
        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah difinalisasi. Gunakan Re-edit bila perlu koreksi.', 422);
        }

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Grouping E-Klaim belum dilakukan sebelum finalisasi.', 422);
        }
        if ((float) $claim->inacbgs_tarif <= 0) {
            throw new \Exception('Tarif INA-CBGs belum valid (Rp 0). Jalankan ulang Grouping sebelum finalisasi.', 422);
        }

        // Dokumen pendukung wajib sahih: lembar klaim sudah di-TTD dokter & koding
        // belum berubah sejak TTD (diagnosa grouping = dokumen pendukung).
        $this->assertClaimResumeReady($claim);

        $res = $this->eklaim->claimFinal($claim->no_sep, $claim->id, $claim->visit_id);

        if (! $res['success']) {
            $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_FINAL_GAGAL',
                $claim->status, $claim->status, $res['message'] ?? 'Finalisasi gagal');
            throw new \Exception('Finalisasi E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        $user = auth('api')->user();
        $old  = $claim->status;
        $claim->update([
            'status'        => 'SUBMITTED',
            'bpjs_status'   => 'FINAL',
            'bpjs_response' => $res['raw'] ?? null,
            'submitted_at'  => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'EKLAIM_FINAL', $old, 'SUBMITTED',
            'Klaim difinalisasi di E-Klaim. ' . ($res['message'] ?? ''));
        $this->log($user?->id, 'EKLAIM_FINAL', BpjsClaim::class, $claim->id, "SEP {$claim->no_sep}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /** get_claim_status — status klaim di E-Klaim (tidak mengubah data lokal). */
    public function eklaimStatus(string $claimId): array
    {
        $claim = BpjsClaim::findOrFail($claimId);

        return $this->eklaim->getClaimStatus($claim->no_sep, $claim->id, $claim->visit_id);
    }

    /** reedit_claim — buka kembali klaim final untuk koreksi. */
    public function eklaimReedit(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        $res = $this->eklaim->reeditClaim($claim->no_sep, $claim->id, $claim->visit_id);

        if (! $res['success']) {
            throw new \Exception('Re-edit E-Klaim gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        // Kembalikan ke DRAFT agar bisa dikoreksi & diproses ulang.
        $old = $claim->status;
        $claim->update(['status' => 'DRAFT', 'bpjs_status' => null, 'submitted_at' => null]);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_REEDIT',
            $old, 'DRAFT', 'Klaim dibuka kembali dari E-Klaim untuk koreksi.');

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Sinkron status pengiriman DC (Pusat Data Kemenkes & BPJS) via get_claim_data.
     * Membaca kemenkes_dc_status_cd / bpjs_dc_status_cd / klaim_status_cd lalu
     * menyimpan snapshot ke bpjs_response & memetakan bpjs_status. Read-only di
     * sisi E-Klaim (tak mengubah klaim).
     *
     * @return array{kemenkes_dc:?string, bpjs_dc:?string, klaim_status:?string, terkirim:bool}
     */
    public function syncDcStatus(string $claimId): array
    {
        $claim = BpjsClaim::findOrFail($claimId);

        $res  = $this->eklaim->getClaimData($claim->no_sep, $claim->id, $claim->visit_id);
        $data = $res['data']['data'] ?? $res['data'] ?? [];

        $kemenkesDc = $data['kemenkes_dc_status_cd'] ?? null; // 'sent' = terkirim
        $bpjsDc     = $data['bpjs_dc_status_cd'] ?? null;
        $klaimSt    = $data['klaim_status_cd'] ?? null;        // 'final'
        $terkirim   = $kemenkesDc === 'sent' || $bpjsDc === 'sent';

        $snapshot = array_merge((array) $claim->bpjs_response, [
            'kemenkes_dc_status_cd' => $kemenkesDc,
            'kemenkes_dc_sent_dttm' => $data['kemenkes_dc_sent_dttm'] ?? null,
            'bpjs_dc_status_cd'     => $bpjsDc,
            'bpjs_dc_sent_dttm'     => $data['bpjs_dc_sent_dttm'] ?? null,
            'klaim_status_cd'       => $klaimSt,
            'synced_at'             => now()->toIso8601String(),
        ]);

        $claim->update([
            'bpjs_response' => $snapshot,
            'bpjs_status'   => $terkirim ? 'TERKIRIM' : ($klaimSt === 'final' ? 'FINAL' : $claim->bpjs_status),
        ]);

        return [
            'kemenkes_dc'  => $kemenkesDc,
            'bpjs_dc'      => $bpjsDc,
            'klaim_status' => $klaimSt,
            'terkirim'     => $terkirim,
            'message'      => $res['message'] ?? null,
        ];
    }

    /**
     * "Kirim Klaim Online" — dorong klaim final ke Pusat Data Kemenkes/BPJS.
     * Memanggil WS (method dari config eklaim.send_method), lalu sinkron status
     * DC. Hanya untuk klaim yang SUDAH final (SUBMITTED).
     *
     * ⚠️ Nama method WS belum diverifikasi dari WS live. Jalankan 1x uji
     * terkontrol; log inacbgs_grouping_logs menangkap REQ+RESP untuk konfirmasi.
     */
    public function sendClaimOnline(string $claimId): array
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (! in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim harus difinalisasi dulu sebelum dikirim online.', 422);
        }

        $res = $this->eklaim->sendClaimOnline($claim->no_sep, $claim->id, $claim->visit_id);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_KIRIM_ONLINE',
            $claim->status, $claim->status,
            $res['success'] ? ('Klaim dikirim online. ' . ($res['message'] ?? '')) : ('Kirim online gagal: ' . ($res['message'] ?? 'Unknown')));

        if (! $res['success']) {
            throw new \Exception('Kirim Klaim Online gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        // Sinkron status DC pasca-kirim (kemenkes_dc_status_cd → TERKIRIM).
        $dc = $this->syncDcStatus($claimId);

        return array_merge($dc, ['message' => $res['message'] ?? 'Klaim dikirim online.']);
    }

    // =========================================================================
    // K2 — kirim individual/kolektif + upload berkas digital ke DC BPJS
    // =========================================================================

    /**
     * Kirim klaim INDIVIDUAL per SEP (send_claim_individual) + simpan status DC
     * (kemkes/bpjs/cob). Klaim harus sudah final (SUBMITTED/SELESAI).
     */
    public function sendClaimIndividual(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (! in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim harus difinalisasi dulu sebelum dikirim.', 422);
        }

        $res  = $this->eklaim->sendClaimIndividual($claim->no_sep, $claim->id, $claim->visit_id);
        $data = $res['data'] ?? $res['raw']['response'] ?? [];

        $kemkes = $data['kemkes_dc_status'] ?? $data['kemenkes_dc_status'] ?? null;
        $bpjs   = $data['bpjs_dc_status'] ?? null;
        $cob    = $data['cob_dc_status'] ?? null;

        $claim->update([
            'kemkes_dc_status' => $kemkes,
            'bpjs_dc_status'   => $bpjs,
            'cob_dc_status'    => $cob,
            'dc_sent_at'       => now(),
            'bpjs_status'      => $res['success'] ? 'TERKIRIM' : $claim->bpjs_status,
        ]);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'EKLAIM_KIRIM_INDIVIDUAL',
            $claim->status, $claim->status,
            $res['success']
                ? "Klaim dikirim (DC kemkes:{$kemkes} bpjs:{$bpjs})"
                : 'Kirim individual gagal: ' . ($res['message'] ?? 'Unknown'));

        if (! $res['success']) {
            throw new \Exception('Kirim klaim individual gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Kirim klaim KOLEKTIF per rentang tanggal (send_claim). Tidak terikat 1 klaim;
     * mengembalikan ringkasan dari Data Center.
     */
    public function sendClaimCollective(string $startDt, string $stopDt, int $jenisRawat = 2, string $dateType = 'tgl_pulang'): array
    {
        $res = $this->eklaim->sendClaimCollective($startDt, $stopDt, $jenisRawat, $dateType);

        if (! $res['success']) {
            throw new \Exception('Kirim klaim kolektif gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        return [
            'message' => $res['message'] ?? 'Klaim kolektif terkirim.',
            'data'    => $res['data'] ?? null,
        ];
    }

    /**
     * Unggah satu lampiran (ClaimAttachment) ke DC BPJS via file_upload.
     * Membaca file dari storage → base64 → kirim; simpan status upload_dc_bpjs.
     */
    public function uploadBerkasToDc(string $attachmentId): \App\Models\ClaimAttachment
    {
        $att   = \App\Models\ClaimAttachment::with('claim')->findOrFail($attachmentId);
        $claim = $att->claim;

        if (! $claim || empty($claim->no_sep)) {
            throw new \Exception('Lampiran tidak terkait klaim ber-SEP.', 422);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        if (! $disk->exists($att->file_path)) {
            throw new \Exception('File lampiran tidak ditemukan di storage.', 422);
        }
        $base64 = base64_encode($disk->get($att->file_path));

        $res = $this->eklaim->fileUpload(
            $claim->no_sep,
            $att->resolveFileClass(),
            $att->file_name,
            $base64,
            $claim->id,
            $claim->visit_id
        );

        $data = $res['data'] ?? $res['raw']['response'] ?? [];
        $ok   = ((string) ($data['upload_dc_bpjs'] ?? '')) === '1' || $res['success'];

        $att->update([
            'dc_upload_status'   => $ok,
            'dc_upload_response' => $data['upload_dc_bpjs_response'] ?? ($res['message'] ?? null),
            'dc_uploaded_at'     => $ok ? now() : null,
        ]);

        if (! $ok) {
            throw new \Exception('Upload berkas ke DC BPJS gagal: ' . ($res['message'] ?? 'Unknown'), 422);
        }

        return $att->fresh();
    }

    // =========================================================================
    // K3 — verifikasi (status), dispute/pending, rekonsiliasi pembayaran
    // =========================================================================

    /**
     * Tarik status verifikasi kasar via get_claim_status (kdStatusSep/nmStatusSep)
     * lalu simpan. CATATAN: respons tak memuat nominal/BAHV — hanya status proses.
     */
    public function refreshVerifStatus(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        $res  = $this->eklaim->getClaimStatus($claim->no_sep, $claim->id, $claim->visit_id);
        $data = $res['data'] ?? $res['raw']['response'] ?? [];

        $claim->update([
            'verif_status_code' => $data['kdStatusSep'] ?? $data['status_code'] ?? null,
            'verif_status_name' => $data['nmStatusSep'] ?? $data['status_name'] ?? null,
            'verif_checked_at'  => now(),
        ]);

        return $claim->fresh();
    }

    /**
     * Set status dispute/pending klaim (kelola internal — tak ada API BPJS).
     * $data: jenis_dispute (medis/koding/obat/cob), dispute_state (PENDING/DISPUTE/
     * SEPAKAT), bahv_no, pending_note.
     */
    public function setDispute(string $claimId, array $data): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        $claim->update(array_filter([
            'jenis_dispute' => $data['jenis_dispute'] ?? null,
            'dispute_state' => $data['dispute_state'] ?? null,
            'bahv_no'       => $data['bahv_no'] ?? null,
            'pending_note'  => $data['pending_note'] ?? null,
        ], fn ($v) => $v !== null));

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'KLAIM_DISPUTE',
            $claim->status, $claim->status,
            "Dispute/pending: {$claim->dispute_state} ({$claim->jenis_dispute}) " . ($claim->bahv_no ? "BAHV {$claim->bahv_no}" : ''));

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Catat hasil pembayaran (manual/import Berita Acara Pembayaran). Selisih
     * ajuan vs disetujui dihitung di FE/laporan dari nominal_diajukan vs disetujui.
     */
    public function setPayment(string $claimId, array $data): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        $claim->update(array_filter([
            'nominal_diajukan'        => $data['nominal_diajukan'] ?? $claim->inacbgs_tarif,
            'nominal_disetujui'       => $data['nominal_disetujui'] ?? null,
            'paid_at'                 => $data['paid_at'] ?? null,
            'berita_acara_bayar_ref'  => $data['berita_acara_bayar_ref'] ?? null,
        ], fn ($v) => $v !== null));

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'KLAIM_PEMBAYARAN',
            $claim->status, $claim->status,
            'Pembayaran dicatat: Rp ' . number_format((float) $claim->nominal_disetujui) . ($claim->berita_acara_bayar_ref ? " (BA {$claim->berita_acara_bayar_ref})" : ''));

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Klaim mendekati kedaluwarsa (6 bulan sejak tgl pelayanan, Perpres 82/2018
     * Pasal 77) yang BELUM terkirim/terbayar — untuk pengingat tindak lanjut.
     */
    public function klaimMendekatiKedaluwarsa(int $daysAhead = 30): \Illuminate\Support\Collection
    {
        $batas = today()->subMonths(6)->addDays($daysAhead); // tgl layanan <= batas → segera kedaluwarsa
        return BpjsClaim::with('visit:id,visit_date')
            ->whereNotIn('status', ['SELESAI'])
            ->whereNull('paid_at')
            ->whereHas('visit', fn ($q) => $q->whereDate('visit_date', '<=', $batas->toDateString()))
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'no_sep'     => $c->no_sep,
                'visit_date' => $c->visit?->visit_date,
                'kedaluwarsa'=> $c->visit?->visit_date
                    ? \Illuminate\Support\Carbon::parse($c->visit->visit_date)->addMonths(6)->toDateString()
                    : null,
                'status'     => $c->status,
            ]);
    }

    /**
     * Data "Berkas Klaim Individual Pasien" (replika cetak resmi E-Klaim) dari
     * get_claim_data. BPJS/E-Klaim tak menyediakan API cetak PDF → dibangun ulang
     * dari data klaim (pola sama Cetak SEP). Dipakai blade pdf.klaim-individual.
     */
    public function buildBerkasKlaimPrintData(string $claimId): array
    {
        $claim = BpjsClaim::with('visit.patient')->findOrFail($claimId);

        $res = $this->eklaim->getClaimData($claim->no_sep, $claim->id, $claim->visit_id);
        if (! $res['success']) {
            throw new \Exception('Gagal ambil data klaim dari E-Klaim: ' . ($res['message'] ?? 'Unknown'), 422);
        }
        $d = $res['data']['data'] ?? $res['data'] ?? [];

        $logoPath = resource_path('images/kemenkes-logo.png');
        $logo = is_file($logoPath)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath))
            : null;

        $splitCodes = function (?string $s): array {
            return collect(explode('#', (string) $s))->map(fn ($x) => trim($x))->filter()->values()->all();
        };

        $grouper = $d['grouper']['response_inacbg'] ?? [];

        return [
            'logo'        => $logo,
            'no_sep'      => $d['nomor_sep'] ?? $claim->no_sep,
            'no_kartu'    => $d['nomor_kartu'] ?? null,
            'no_rm'       => $d['nomor_rm'] ?? null,
            'nama_pasien' => $d['nama_pasien'] ?? $claim->visit?->patient?->name,
            'kode_rs'     => $d['kode_rs'] ?? null,
            'kelas_rs'    => $d['kelas_rs'] ?? null,
            'nama_rs'     => config('app.facility_name', 'RSK MATA PRIMA VISION'),
            'jenis_tarif' => $d['kode_tarif'] ?? null,
            'tgl_lahir'   => $d['tgl_lahir'] ?? null,
            'tgl_masuk'   => $d['tgl_masuk'] ?? null,
            'tgl_pulang'  => $d['tgl_pulang'] ?? null,
            'umur_tahun'  => $d['umur_tahun'] ?? null,
            'umur_hari'   => $d['umur_hari'] ?? null,
            'gender'      => ((string) ($d['gender'] ?? '')) === '2' ? '2 - Perempuan' : '1 - Laki-laki',
            'jenis_rawat' => ((string) ($d['jenis_rawat'] ?? '')) === '1' ? '1 - Rawat Inap' : '2 - Rawat Jalan',
            'kelas_rawat' => $d['kelas_rawat'] ?? null,
            'cara_pulang' => $d['discharge_status'] ?? null,
            'los'         => $d['los'] ?? null,
            'berat_lahir' => $d['berat_lahir'] ?? '-',
            'adl_sub_acute' => $d['adl_sub_acute'] ?? '-',
            'adl_chronic'   => $d['adl_chronic'] ?? '-',
            'diagnosa'    => $splitCodes($d['diagnosa'] ?? ''),
            'procedure'   => $splitCodes($d['procedure'] ?? ''),
            'inacbg_code' => $grouper['cbg']['code'] ?? $claim->inacbgs_kode,
            'inacbg_desc' => $grouper['cbg']['description'] ?? null,
            'tarif'       => $grouper['tariff'] ?? $claim->inacbgs_tarif,
            'dc_status'   => $d['kemenkes_dc_status_cd'] ?? null,
            'klaim_status' => $d['klaim_status_cd'] ?? null,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Fase 4 — Builder payload set_claim_data dari relasi Visit Arumed.
     *
     * Struktur field & format DIVERIFIKASI EMPIRIS dari respons get_claim_data
     * WS E-Klaim build 5.10.7 (klaim nyata RSK Mata Prima Vision), bukan tebakan:
     *   - separator diagnosa/prosedur = '#' (BUKAN ';')
     *   - tarif_rs = OBJEK 18 komponen (BUKAN angka flat)
     *   - gender int (1=L, 2=P), jenis_rawat int (1=inap, 2=jalan, 3=IGD)
     *   - payor_id '3'=JKN, coder_nm/coder_nik, cara_masuk 'gp'=rujukan FKTP
     *   - nama_dokter = NAMA DPJP (bukan kode)
     *
     * Format tanggal SET: 'Y-m-d H:i:s' (masuk/pulang) & 'Y-m-d' (lahir) sesuai
     * standar set_claim_data; get_claim_data menampilkan ulang ke 'd/m/Y'.
     */
    public function buildEklaimPayload(BpjsClaim $claim): array
    {
        $visit   = $claim->visit;
        $patient = $visit?->patient;
        $exam    = $visit?->doctorExamination;
        $invoice = $visit?->billingInvoice;

        $isRanap = ($visit?->jenis_pelayanan ?? 'RAJAL') === 'RANAP';

        $masuk = $visit?->admission_at
            ? \Illuminate\Support\Carbon::parse($visit->admission_at)
            : \Illuminate\Support\Carbon::parse($visit?->visit_date ?? now());
        $pulang = $visit?->discharge_at
            ? \Illuminate\Support\Carbon::parse($visit->discharge_at)
            : $masuk->copy();
        $los = max(1, $masuk->copy()->startOfDay()->diffInDays($pulang->copy()->startOfDay()) + ($isRanap ? 1 : 0));

        // Diagnosa & prosedur: separator '#' (terverifikasi dari get_claim_data).
        $dxSekunder = collect($claim->diagnosis_sekunder ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c)
            ->filter()->values()->all();
        $diagnosa = implode('#', array_filter(array_merge([$claim->diagnosis_utama], $dxSekunder)));

        $prosedur = collect($claim->procedure_codes ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c)
            ->filter()->values()->all();

        return [
            'nomor_sep'    => $claim->no_sep,
            'nomor_kartu'  => $patient?->bpjs_number,
            'nomor_rm'     => $patient?->no_rm ?? $claim->patient_nik,
            'nama_pasien'  => $patient?->name,
            'tgl_lahir'    => $patient?->date_of_birth?->format('Y-m-d'),
            'gender'       => ($patient?->gender === 'P') ? 2 : 1,
            'tgl_masuk'    => $masuk->format('Y-m-d H:i:s'),
            'tgl_pulang'   => $pulang->format('Y-m-d H:i:s'),
            'los'          => (string) $los,
            'jenis_rawat'  => $isRanap ? 1 : 2,
            'kelas_rawat'  => (int) ($visit?->kelas_rawat_hak ?? 3),
            'cara_masuk'   => $this->mapCaraMasuk($visit),
            'discharge_status' => (int) $this->mapCaraPulang($visit?->discharge_type),
            'diagnosa'     => $diagnosa,
            'procedure'    => implode('#', $prosedur),
            'tarif_rs'     => $this->buildTarifRsBreakdown($invoice),
            'tarif_poli_eks' => 0,
            'kode_tarif'   => config('eklaim.kode_tarif', 'CS'),
            'nama_dokter'  => $exam?->doctor?->name,
            // Vitals (Data Klinis) — default 0 bila tak terekam.
            'sistole'      => 0,
            'diastole'     => 0,
            'berat_lahir'  => 0,
            'adl_sub_acute' => 0,
            'adl_chronic'   => 0,
            // Penjamin & koder.
            'payor_id'     => config('eklaim.payor_id', '3'), // 3 = JKN
            'payor_cd'     => config('eklaim.payor_cd', 'JKN'),
            'cob_cd'       => '0', // COB belum didukung; default bukan-COB
            'coder_nik'    => config('eklaim.coder_nik', '00001'),
        ];
    }

    /**
     * Susun objek tarif_rs (18 komponen INA-CBG) dari item billing RS.
     * Pemetaan item_type/category Arumed -> bucket E-Klaim; sisa tak terpetakan
     * masuk prosedur_non_bedah agar total tarif RS tetap utuh.
     */
    private function buildTarifRsBreakdown(?BillingInvoice $invoice): array
    {
        $buckets = [
            'prosedur_non_bedah' => 0, 'prosedur_bedah' => 0, 'konsultasi' => 0,
            'tenaga_ahli' => 0, 'keperawatan' => 0, 'penunjang' => 0,
            'radiologi' => 0, 'laboratorium' => 0, 'pelayanan_darah' => 0,
            'rehabilitasi' => 0, 'kamar' => 0, 'rawat_intensif' => 0,
            'obat' => 0, 'obat_kronis' => 0, 'obat_kemoterapi' => 0,
            'alkes' => 0, 'bmhp' => 0, 'sewa_alat' => 0,
        ];

        if (! $invoice) {
            return $buckets;
        }

        // Pra-resolve ICD-9-CM untuk item TINDAKAN (reference_id → Procedure) supaya
        // tindakan BEDAH masuk bucket prosedur_bedah. Kaidah ICD-9-CM: bab 01–86 =
        // operasi (bedah), 87–99 = diagnostik/terapeutik (non-bedah). Contoh:
        // trabekulektomi 12.64 → bedah; pemeriksaan mata 95.01 → non-bedah.
        $tindakanIcd9 = [];
        $refIds = $invoice->items
            ->where('item_type', 'TINDAKAN')
            ->pluck('reference_id')->filter()->unique()->values()->all();
        if ($refIds) {
            $tindakanIcd9 = \App\Models\Procedure::whereIn('id', $refIds)
                ->pluck('icd9_code', 'id')->all();
        }

        foreach ($invoice->items as $it) {
            $amount = (float) ($it->net_price ?? $it->total_price ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $icd9 = $it->item_type === 'TINDAKAN' ? ($tindakanIcd9[$it->reference_id] ?? null) : null;
            $bucket = $this->mapBillingToTarifBucket($it->item_type, (string) $it->category, $icd9);
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + $amount;
        }

        // Bulatkan ke rupiah (E-Klaim menyimpan integer).
        return array_map(fn ($v) => (int) round($v), $buckets);
    }

    /** Map item billing Arumed -> nama bucket tarif_rs E-Klaim. */
    private function mapBillingToTarifBucket(?string $itemType, string $category, ?string $icd9 = null): string
    {
        $cat = mb_strtolower($category);

        // Pemetaan halus berdasarkan kategori (lebih spesifik) dulu.
        if (str_contains($cat, 'kamar') || str_contains($cat, 'sewa kamar')) return 'kamar';
        if (str_contains($cat, 'visite') || str_contains($cat, 'konsul')) return 'konsultasi';
        if (str_contains($cat, 'administ') || str_contains($cat, 'registr')) return 'konsultasi';
        if (str_contains($cat, 'sewa peralatan') || str_contains($cat, 'sewa alat')) return 'sewa_alat';
        if (str_contains($cat, 'cssd') || str_contains($cat, 'habis pakai') || str_contains($cat, 'bmhp')) return 'bmhp';
        if (str_contains($cat, 'iol') || str_contains($cat, 'lensa') || str_contains($cat, 'implan')) return 'alkes';
        if (str_contains($cat, 'obat')) return 'obat';
        if (str_contains($cat, 'rehab')) return 'rehabilitasi';
        if (str_contains($cat, 'radiolog')) return 'radiologi';
        if (str_contains($cat, 'lab')) return 'laboratorium';
        // Kategori bedah/operasi eksplisit -> prosedur_bedah (apa pun item_type).
        if (str_contains($cat, 'bedah') || str_contains($cat, 'operasi') || str_contains($cat, 'operatif')) return 'prosedur_bedah';

        // TINDAKAN: pisahkan bedah vs non-bedah dari kode ICD-9-CM (otoritatif).
        if ($itemType === 'TINDAKAN') {
            return $this->isSurgicalIcd9($icd9) ? 'prosedur_bedah' : 'prosedur_non_bedah';
        }

        // Fallback berdasarkan item_type.
        return match ($itemType) {
            'ROOM'              => 'kamar',
            'VISITE'            => 'konsultasi',
            'OBAT', 'MEDICATION' => 'obat',
            'BHP'               => 'bmhp',
            'IOL', 'MEDICAL_EQUIPMENT' => 'alkes',
            default             => 'prosedur_non_bedah',
        };
    }

    /**
     * Apakah kode ICD-9-CM tergolong tindakan BEDAH (operasi)?
     * Kaidah ICD-9-CM: bab 01–86 = "Operations" (bedah); 87–99 = diagnostik/
     * terapeutik non-bedah. Bab = angka sebelum titik (mis. '12.64'→12, '95.01'→95).
     */
    private function isSurgicalIcd9(?string $code): bool
    {
        $code = trim((string) $code);
        if ($code === '') return false;

        $head = explode('.', $code)[0];
        if (! ctype_digit($head)) {
            if (! preg_match('/(\d{1,2})/', $code, $m)) return false;
            $head = $m[1];
        }
        $chapter = (int) $head;

        return $chapter >= 1 && $chapter <= 86;
    }

    /**
     * Map cara masuk E-Klaim ('gp' = rujukan FKTP/dokter umum, 'hosp-trans' =
     * rujukan antar-RS/FKRTL, 'mp' = datang sendiri). Terverifikasi: pasien
     * rujukan FKTP = 'gp'.
     */
    private function mapCaraMasuk(?Visit $visit): string
    {
        // Pasien kontrol/rujukan FKRTL (punya surat kontrol) = transfer antar-RS.
        if (! empty($visit?->no_surat_kontrol)) {
            return 'hosp-trans';
        }

        return 'gp';
    }

    /** Map discharge_type Arumed -> kode cara pulang/discharge_status E-Klaim. */
    private function mapCaraPulang(?string $type): string
    {
        if ($type === null) {
            return '1'; // default: atas persetujuan dokter (hindari null array offset)
        }

        return [
            'PULANG_SEHAT' => '1', // atas persetujuan dokter
            'RUJUK'        => '2',
            'APS'          => '3', // atas permintaan sendiri
            'MENINGGAL'    => '4',
        ][$type] ?? '1';
    }

    /** Guard umum sebelum call WS: klaim ada, SEP & diagnosis utama terisi. */
    private function guardEklaimReady(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::with(['visit.patient', 'visit.doctorExamination.doctor', 'visit.billingInvoice.items'])
            ->findOrFail($claimId);

        if (empty($claim->no_sep)) {
            throw new \Exception('Nomor SEP belum ada pada klaim.', 422);
        }
        if (empty($claim->diagnosis_utama)) {
            throw new \Exception('Diagnosis utama belum diisi.', 422);
        }

        return $claim;
    }

    // =========================================================================
    // LUPIS — format data utilisasi untuk VClaim
    // =========================================================================

    public function generateLupis(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::with(['visit.patient', 'visit.billingInvoice.items'])->findOrFail($claimId);

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Jalankan INA-CBGs grouping terlebih dahulu sebelum generate LUPIS.', 422);
        }

        $visit   = $claim->visit;
        $patient = $visit->patient;

        // Conditional rawat inap: jnsPelayanan '1' + field inap (tglMasuk/los/
        // kelasRawat/caraKeluar). Kelas rawat = kelas HAK (kelas_rawat_hak).
        $isRanap = ($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP';
        $tglMasuk = $visit->admission_at
            ? \Illuminate\Support\Carbon::parse($visit->admission_at)->format('Y-m-d')
            : null;
        $tglPulang = $visit->discharge_at
            ? \Illuminate\Support\Carbon::parse($visit->discharge_at)->format('Y-m-d')
            : $visit->updated_at?->format('Y-m-d'); // RAJAL: fallback ke updated_at (perilaku lama)

        // LOS = malam admission..discharge, minimum 1 (masuk dihitung, pulang tidak).
        $los = null;
        if ($isRanap && $visit->admission_at && $visit->discharge_at) {
            $los = max(1, \Illuminate\Support\Carbon::parse($visit->admission_at)->startOfDay()
                ->diffInDays(\Illuminate\Support\Carbon::parse($visit->discharge_at)->startOfDay()));
        }

        // Cara keluar dari discharge_type (PULANG_SEHAT|RUJUK|APS|MENINGGAL).
        $caraKeluarMap = [
            'PULANG_SEHAT' => '1', // atas persetujuan dokter
            'RUJUK'        => '2',
            'APS'          => '3', // atas permintaan sendiri
            'MENINGGAL'    => '4',
        ];

        // LUPIS format (struktur sesuai spesifikasi BPJS)
        $lupisData = [
            'noSep'            => $claim->no_sep,
            'nik'              => $claim->patient_nik,
            'nama'             => $patient->name,
            'tglLahir'         => $patient->date_of_birth?->format('Y-m-d'),
            'jnsPelayanan'     => $isRanap ? '1' : '2', // 1=rawat inap, 2=rawat jalan
            'diagnosaUtama'    => $claim->diagnosis_utama,
            'diagnosaSekunder' => $claim->diagnosis_sekunder ?? [],
            'procedureCodes'   => $claim->procedure_codes ?? [],
            'cbgCode'          => $claim->inacbgs_kode,
            'cbgTarif'         => $claim->inacbgs_tarif,
            'totalBiaya'       => $visit->billingInvoice?->total ?? 0,
            'tglPulang'        => $tglPulang,
        ];

        // Field khusus rawat inap.
        if ($isRanap) {
            $lupisData['tglMasuk']   = $tglMasuk;
            $lupisData['los']        = $los;
            $lupisData['kelasRawat'] = (string) ($visit->kelas_rawat_hak ?? '3');
            $lupisData['caraKeluar'] = $visit->discharge_type ? ($caraKeluarMap[$visit->discharge_type] ?? '1') : '1';
        }

        $user = auth('api')->user();

        $claim->update(['lupis_data' => $lupisData]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'LUPIS_GENERATED', $claim->status, $claim->status);
        $this->log($user?->id, 'GENERATE_LUPIS', BpjsClaim::class, $claimId);

        return $claim->fresh();
    }

    // =========================================================================
    // WORKFLOW STATUS
    // =========================================================================

    public function setReview(string $claimId): BpjsClaim
    {
        return $this->transitionStatus($claimId, 'DRAFT', 'REVIEW', 'REVIEW');
    }

    public function setVerifikasi(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'REVIEW') {
            throw new \Exception('Klaim harus dalam status REVIEW untuk diverifikasi.', 422);
        }

        if (! $claim->inacbgs_kode) {
            throw new \Exception('Grouping INA-CBGs belum dilakukan.', 422);
        }

        // Grouper bisa mengembalikan kode tanpa tarif (shape WS tak terduga) → tarif null/0.
        // Jangan loloskan ke VERIFIED dgn tarif kosong (akan submit & LUPIS bertarif 0).
        if ((float) $claim->inacbgs_tarif <= 0) {
            throw new \Exception('Tarif INA-CBGs belum valid (Rp 0). Jalankan ulang Grouping INA-CBGs.', 422);
        }

        if (! $claim->lupis_data) {
            throw new \Exception('Data LUPIS belum di-generate.', 422);
        }

        return $this->transitionStatus($claimId, 'REVIEW', 'VERIFIED', 'VERIFIKASI');
    }

    /**
     * Tolak/kembalikan klaim oleh VERIFIKATOR INTERNAL (sebelum submit ke BPJS).
     * Status → DIKEMBALIKAN. Bisa di-resubmit (perbaiki lalu ajukan ulang).
     */
    public function setReject(string $claimId, string $reason): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim ke BPJS, tidak bisa dikembalikan internal. Gunakan respons BPJS.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;

        $claim->update([
            'status'           => 'DIKEMBALIKAN',
            'rejection_reason' => $reason,
            'rejected_at'      => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'RETURN_INTERNAL', $oldStatus, 'DIKEMBALIKAN', $reason);
        $this->log($user?->id, 'RETURN_CLAIM', BpjsClaim::class, $claimId, $reason);

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Tandai klaim DITOLAK oleh BPJS (setelah submit). Dipanggil saat memproses
     * respons VClaim / monitoring. Status → DITOLAK_BPJS.
     */
    public function markBpjsRejected(string $claimId, string $reason, ?array $bpjsResponse = null): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'SUBMITTED') {
            throw new \Exception('Hanya klaim berstatus SUBMITTED yang bisa ditandai ditolak BPJS.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;

        $claim->update([
            'status'           => 'DITOLAK_BPJS',
            'bpjs_status'      => 'DITOLAK',
            'bpjs_response'    => $bpjsResponse ?? $claim->bpjs_response,
            'rejection_reason' => $reason,
            'rejected_at'      => now(),
        ]);

        $this->addAuditLog($claim->id, $user?->employee_id, 'REJECT_BPJS', $oldStatus, 'DITOLAK_BPJS', $reason);
        $this->log($user?->id, 'REJECT_BPJS_CLAIM', BpjsClaim::class, $claimId, $reason);

        return $claim->fresh(['auditLogs.performedBy']);
    }

    /**
     * Ajukan ulang klaim yang DIKEMBALIKAN (internal) atau DITOLAK_BPJS.
     * Mengembalikan ke DRAFT untuk diperbaiki & diproses ulang
     * (grouping → LUPIS → verifikasi → submit). resubmission_count bertambah.
     * Pola mengikuti AsuransiService::resubmitKlaim.
     */
    public function resubmitClaim(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Dukung status baru + 'DITOLAK' lama (backward-compat).
        if (! in_array($claim->status, ['DIKEMBALIKAN', 'DITOLAK_BPJS', 'DITOLAK'], true)) {
            throw new \Exception('Hanya klaim yang dikembalikan/ditolak yang bisa diajukan ulang.', 422);
        }

        $user      = auth('api')->user();
        $oldStatus = $claim->status;
        $newCount  = ($claim->resubmission_count ?? 0) + 1;

        $claim->update([
            'status'             => 'DRAFT',
            'resubmission_count' => $newCount,
            // Bersihkan jejak penolakan agar siklus baru bersih.
            'rejection_reason'   => null,
            'rejected_at'        => null,
            'bpjs_status'        => null,
            'bpjs_response'      => null,
            // Reset hasil grouping/LUPIS — wajib di-run ulang setelah perbaikan data.
            'inacbgs_kode'       => null,
            'inacbgs_tarif'      => null,
            'lupis_data'         => null,
            'submitted_at'       => null,
        ]);

        $this->addAuditLog(
            $claim->id,
            $user?->employee_id,
            'RESUBMIT',
            $oldStatus,
            'DRAFT',
            "Klaim diajukan ulang (pengajuan ke-{$newCount}). Perbaiki data lalu jalankan grouping → LUPIS → verifikasi."
        );
        $this->log($user?->id, 'RESUBMIT_CLAIM', BpjsClaim::class, $claimId, "resubmission #{$newCount}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    // =========================================================================
    // SUBMIT KE VCLAIM
    // =========================================================================

    /**
     * Kirim klaim final ke E-Klaim INA-CBG (BUKAN mock VClaim).
     *
     * Tombol "Kirim ke BPJS" di UI = claim_final WS E-Klaim (irreversible,
     * hanya bisa dibuka via Re-edit). Delegasi penuh ke eklaimFinal() yang
     * memanggil ws.php, menandai klaim SUBMITTED + bpjs_status FINAL, dan
     * mencatat audit. Guard status VERIFIED dipertahankan di sini.
     */
    public function submitClaim(string $claimId): BpjsClaim
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if ($claim->status !== 'VERIFIED') {
            throw new \Exception('Klaim harus dalam status VERIFIED sebelum dikirim.', 422);
        }

        // eklaimFinal: cek inacbgs_kode, call claim_final WS, set SUBMITTED + audit.
        return $this->eklaimFinal($claimId);
    }

    // =========================================================================
    // LAMPIRAN BERKAS KLAIM (upload PDF/gambar: resume RJ, hasil penunjang, dll)
    // =========================================================================

    /** Daftar lampiran klaim (terbaru dulu). */
    public function getAttachments(string $claimId): array
    {
        BpjsClaim::findOrFail($claimId); // 404 bila klaim tak ada

        return \App\Models\ClaimAttachment::with('uploadedBy:id,name')
            ->where('bpjs_claim_id', $claimId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id'        => $d->id,
                'category'  => $d->category,
                'title'     => $d->title,
                'file_name' => $d->file_name,
                'file_url'  => $d->file_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($d->file_path) : null,
                'mime_type' => $d->mime_type,
                'file_size' => $d->file_size,
                'by'        => $d->uploadedBy?->name,
                'at'        => $d->created_at?->toIso8601String(),
                // K2 — status unggah ke DC BPJS (file_upload).
                'dc_upload_status' => $d->dc_upload_status,
                'dc_uploaded_at'   => $d->dc_uploaded_at?->toIso8601String(),
            ])->all();
    }

    /** Upload lampiran (PDF/gambar) ke klaim. */
    public function uploadAttachment(string $claimId, array $data, $file): \App\Models\ClaimAttachment
    {
        $claim = BpjsClaim::findOrFail($claimId);

        // Klaim yang sudah final/selesai tidak boleh ditambah lampiran.
        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim/selesai — lampiran tidak bisa ditambah.', 422);
        }

        $category = in_array($data['category'] ?? null, \App\Models\ClaimAttachment::CATEGORIES, true)
            ? $data['category']
            : 'LAINNYA';

        $path = $file->store('claim-attachments', 'public');

        $att = \App\Models\ClaimAttachment::create([
            'bpjs_claim_id'  => $claim->id,
            'category'       => $category,
            'title'          => $data['title'] ?? $file->getClientOriginalName(),
            'file_path'      => $path,
            'file_name'      => $file->getClientOriginalName(),
            'mime_type'      => $file->getClientMimeType(),
            'file_size'      => $file->getSize(),
            'uploaded_by_id' => auth('api')->user()?->employee_id,
        ]);

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'UPLOAD_LAMPIRAN',
            $claim->status, $claim->status, "Lampiran {$category}: {$att->file_name}");

        return $att;
    }

    /** Hapus lampiran (beserta file fisik). */
    public function deleteAttachment(string $claimId, string $attachmentId): void
    {
        $claim = BpjsClaim::findOrFail($claimId);

        if (in_array($claim->status, ['SUBMITTED', 'SELESAI'], true)) {
            throw new \Exception('Klaim sudah dikirim/selesai — lampiran tidak bisa dihapus.', 422);
        }

        $att = \App\Models\ClaimAttachment::where('bpjs_claim_id', $claimId)->findOrFail($attachmentId);

        if ($att->file_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($att->file_path);
        }

        $name = $att->file_name;
        $att->delete();

        $this->addAuditLog($claim->id, auth('api')->user()?->employee_id, 'HAPUS_LAMPIRAN',
            $claim->status, $claim->status, "Hapus lampiran: {$name}");
    }

    // =========================================================================
    // MONITORING & LOGS
    // =========================================================================

    public function getAuditLog(string $claimId): \Illuminate\Database\Eloquent\Collection
    {
        BpjsClaim::findOrFail($claimId); // 404 if not found

        return ClaimAuditLog::with('performedBy')
            ->where('bpjs_claim_id', $claimId)
            ->orderBy('created_at')
            ->get();
    }

    public function getGroupingLog(string $claimId): \Illuminate\Database\Eloquent\Collection
    {
        return InacbgsGroupingLog::where('bpjs_claim_id', $claimId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getVclaimLog(array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = DB::table('bpjs_vclaim_logs');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['status'])) {
            $query->where('http_status', $filters['status']);
        }

        return $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function icareMonitoring(): array
    {
        $this->assertIcareEnabled();

        // TODO: Call IntegrasiService::getIcareMonitoring()
        return [
            'message' => 'iCare monitoring belum terhubung. Aktifkan integrasi iCare terlebih dahulu.',
            'status'  => 'NOT_CONFIGURED',
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function transitionStatus(
        string $claimId,
        string $fromStatus,
        string $toStatus,
        string $action
    ): BpjsClaim {
        $claim = BpjsClaim::findOrFail($claimId);
        $user  = auth('api')->user();

        if ($claim->status !== $fromStatus) {
            throw new \Exception("Klaim harus dalam status {$fromStatus} untuk tindakan ini.", 422);
        }

        $claim->update(['status' => $toStatus]);

        $this->addAuditLog($claim->id, $user?->employee_id, $action, $fromStatus, $toStatus);
        $this->log($user?->id, $action . '_CLAIM', BpjsClaim::class, $claimId, "{$fromStatus} → {$toStatus}");

        return $claim->fresh(['auditLogs.performedBy']);
    }

    private function addAuditLog(
        string $claimId,
        ?string $employeeId,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $notes = null
    ): void {
        ClaimAuditLog::create([
            'bpjs_claim_id'   => $claimId,
            'performed_by_id' => $employeeId,
            'action'          => $action,
            'old_status'      => $oldStatus,
            'new_status'      => $newStatus,
            'notes'           => $notes,
        ]);
    }

    private function assertIcareEnabled(): void
    {
        $config = IntegrationConfig::where('system_name', 'ICARE')->first();

        if (! $config?->is_enabled) {
            throw new \Exception('Integrasi iCare belum diaktifkan.', 503);
        }
    }

    private function log(
        ?string $userId,
        string $action,
        ?string $model = null,
        ?string $modelId = null,
        ?string $description = null
    ): void {
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
