<?php

namespace App\Services;

use App\Models\DoctorFeeRule;
use App\Models\Employee;
use App\Models\SurgeryPackage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modul Keuangan — Rekap honor (jasa medis) dokter per periode + mesin aturan honor.
 *
 * Sumber data = snapshot billing historis (billing_items/billing_invoices), bukan
 * master tarif live → angka periode lampau reprodusibel walau tarif berubah.
 *
 * Atribusi dokter per item: pelaksana (visit_services.performed_by_id) bila ada,
 * fallback DPJP kunjungan (COALESCE dpjp_employee_id, doctor_examinations.doctor_id,
 * doctor_schedules.employee_id). OBAT/BHP/IOL & kategori non-jasa = info saja.
 *
 * Penjamin dikelompokkan 2: BPJS vs UMUM (semua selain BPJS). Realisasi berbeda:
 *  - UMUM : tagihan LUNAS (status PAID) berdasar paid_at.
 *  - BPJS : berbasis klaim — basis 'finalized' (default, pakai bulan visit_date) atau
 *           'paid' (status PAID + paid_at).
 *
 * Honor:
 *  - PERCENT_CATEGORY / PERCENT_PAYER : persen × jumlah tarif per kategori (PKS).
 *  - NOMINAL_PACKAGE : nominal tetap per kasus paket bedah (edaran). Visit ber-paket
 *    yang cocok NOMINAL_PACKAGE → baris kategorinya DIKECUALIKAN dari honor persen
 *    (cegah dobel bayar); honornya murni nominal × jumlah kasus.
 */
class KeuanganService
{
    /**
     * Kategori buku tarif yang layak honor dokter (cocokkan billing_items.category).
     * Edit di sini bila label kategori berubah. Kategori lain (Obat, BHP, IOL, Kamar,
     * Administrasi, dll.) tampil sebagai info tanpa honor.
     */
    public const HONOR_CATEGORIES = [
        'Konsultasi Dokter',
        'Visite Dokter',
        'Pemeriksaan Dasar Rutin',
        'Pemeriksaan Dasar Lainnya',
        'Pemeriksaan Penunjang Diagnostik Mata',
        'Tindakan Dokter',
    ];

    // =========================================================================
    // REKAP HONOR
    // =========================================================================

    /**
     * @param array{period?:string,employee_id?:string,payer_group?:string,bpjs_basis?:string} $filters
     */
    public function buildHonorRecap(array $filters): array
    {
        [$start, $end, $period] = $this->resolvePeriod($filters['period'] ?? null);
        $bpjsBasis  = ($filters['bpjs_basis'] ?? 'finalized') === 'paid' ? 'paid' : 'finalized';
        $payerOnly  = in_array($filters['payer_group'] ?? null, ['BPJS', 'UMUM'], true) ? $filters['payer_group'] : null;
        $employeeId = $filters['employee_id'] ?? null;

        $rules = DoctorFeeRule::query()->where('is_active', true)->get();

        // 1) Paket-case: visit ber-jadwal-bedah dgn paket, realisasi dlm periode.
        //    → tentukan visit mana yang "tertutup paket" (cocok NOMINAL_PACKAGE).
        $packageCases   = $this->fetchPackageCases($start, $end, $bpjsBasis);
        $coveredVisits  = [];   // visit_id => true (dikecualikan dari honor persen)
        $packageBuckets = [];   // "operator|package|payer" => agregat case

        foreach ($packageCases as $pc) {
            $payer = $pc->payer_group;
            if ($payerOnly && $payer !== $payerOnly) { continue; }
            $operator = $pc->lead_surgeon_id;
            if ($employeeId && $operator !== $employeeId) { continue; }
            $date = $payer === 'BPJS' && $bpjsBasis === 'finalized'
                ? Carbon::parse($pc->visit_date) : Carbon::parse($pc->realized_at ?? $pc->visit_date);
            $rule = $this->resolveNominalRule($rules, $operator, $pc->surgery_package_id, $payer, $date);
            if (! $rule) { continue; } // tanpa aturan nominal → bukan kasus tertutup paket

            $coveredVisits[$pc->visit_id] = true;
            $key = "{$operator}|{$pc->surgery_package_id}|{$payer}";
            if (! isset($packageBuckets[$key])) {
                $packageBuckets[$key] = [
                    'employee_id'  => $operator,
                    'package_id'   => $pc->surgery_package_id,
                    'payer_group'  => $payer,
                    'case_count'   => 0,
                    'nominal'      => (float) $rule->nominal,
                    'rule_label'   => $rule->label,
                    'rule_id'      => $rule->id,
                ];
            }
            $packageBuckets[$key]['case_count']++;
        }

        // 2) Detail per billing item.
        $rows = $this->fetchDetailRows($start, $end, $bpjsBasis, $payerOnly, $employeeId);

        // Resolve nama dokter (atribusi) dalam satu query.
        $empIds = collect($rows)->pluck('attributed_employee_id')
            ->merge(collect($packageBuckets)->pluck('employee_id'))
            ->filter()->unique()->values()->all();
        $empNames = Employee::whereIn('id', $empIds)->pluck('name', 'id')->all();

        $pkgNames = SurgeryPackage::whereIn('id', collect($packageBuckets)->pluck('package_id')->unique())
            ->pluck('name', 'id')->all();

        // 3) Susun struktur dokter → payer → kategori.
        $doctors = [];   // employee_id => struktur
        $details = [];   // baris flat utk CSV
        $unmatched = 0;

        $ensureDoctor = function (&$doctors, $empId, $empNames) {
            if (! isset($doctors[$empId])) {
                $doctors[$empId] = [
                    'employee_id' => $empId,
                    'doctor_name' => $empId ? ($empNames[$empId] ?? 'Dokter tak dikenal') : 'TANPA DPJP',
                    'payers' => [
                        'UMUM' => $this->emptyPayerBucket(),
                        'BPJS' => $this->emptyPayerBucket(),
                    ],
                    'noninfo' => [], // kategori non-honor: label => amount
                ];
            }
        };

        foreach ($rows as $r) {
            $empId   = $r->attributed_employee_id;
            $payer   = $r->payer_group;
            $honorOk = in_array($r->category, self::HONOR_CATEGORIES, true);
            $covered = isset($coveredVisits[$r->visit_id]); // tertutup paket nominal
            $amountGross = (float) $r->total_price;
            $amountNet   = (float) $r->net_price;

            $ensureDoctor($doctors, $empId, $empNames);
            $bucket = &$doctors[$empId]['payers'][$payer];

            $honor = null; $ruleLabel = null; $ruleMatched = null; $percent = null; $ruleType = null;

            if ($honorOk && ! $covered) {
                $date = $payer === 'BPJS' && $bpjsBasis === 'finalized'
                    ? Carbon::parse($r->visit_date) : Carbon::parse($r->realized_at ?? $r->visit_date);
                $rule = $this->resolvePercentRule($rules, $empId, $r->category, $payer, $date);
                if ($rule) {
                    $percent   = (float) $rule->percent;
                    $base      = $rule->basis === 'GROSS' ? $amountGross : $amountNet;
                    $honor     = round($base * $percent / 100, 2);
                    $ruleLabel = $rule->label;
                    $ruleType  = $rule->rule_type;
                    $ruleMatched = true;
                } else {
                    $honor = 0.0; $ruleMatched = false; $unmatched++;
                }

                $cat = $r->category;
                if (! isset($bucket['categories'][$cat])) {
                    $bucket['categories'][$cat] = [
                        'category' => $cat, 'amount_gross' => 0.0, 'amount_net' => 0.0,
                        'count' => 0, 'honor' => 0.0, 'percent' => $percent,
                        'rule_type' => $ruleType, 'rule_label' => $ruleLabel,
                        'rule_matched' => $ruleMatched,
                    ];
                }
                $bucket['categories'][$cat]['amount_gross'] += $amountGross;
                $bucket['categories'][$cat]['amount_net']   += $amountNet;
                $bucket['categories'][$cat]['count']++;
                $bucket['categories'][$cat]['honor'] += ($honor ?? 0.0);
                if ($ruleMatched === false) { $bucket['categories'][$cat]['rule_matched'] = false; }
                $bucket['subtotal_amount'] += $amountGross;
                $bucket['subtotal_honor']  += ($honor ?? 0.0);
            } else {
                // Info: obat/bhp/iol/kamar atau baris tertutup paket.
                $label = $covered ? ($r->category . ' (termasuk paket)') : ($r->category ?: $r->item_type);
                $doctors[$empId]['noninfo'][$label] = ($doctors[$empId]['noninfo'][$label] ?? 0.0) + $amountGross;
            }

            $details[] = [
                'doctor_name'  => $doctors[$empId]['doctor_name'],
                'payer_group'  => $payer,
                'category'     => $r->category ?: $r->item_type,
                'date'         => $payer === 'BPJS' && $bpjsBasis === 'finalized'
                                    ? (string) $r->visit_date
                                    : substr((string) ($r->realized_at ?? $r->visit_date), 0, 10),
                'patient'      => $r->patient_name,
                'procedure'    => $r->description,
                'quantity'     => (int) $r->quantity,
                'unit_price'   => (float) $r->unit_price,
                'total_price'  => $amountGross,
                'net_price'    => $amountNet,
                'honor'        => ($honorOk && ! $covered) ? ($honor ?? 0.0) : null,
                'rule'         => $covered ? 'termasuk paket' : ($honorOk ? ($ruleMatched ? $ruleLabel : 'TANPA ATURAN') : ''),
            ];
            unset($bucket);
        }

        // 4) Tambahkan honor paket ke struktur dokter.
        foreach ($packageBuckets as $pb) {
            $empId = $pb['employee_id'];
            $payer = $pb['payer_group'];
            $ensureDoctor($doctors, $empId, $empNames);
            $honor = round($pb['nominal'] * $pb['case_count'], 2);
            $doctors[$empId]['payers'][$payer]['packages'][] = [
                'package_id'  => $pb['package_id'],
                'package_name'=> $pkgNames[$pb['package_id']] ?? 'Paket',
                'case_count'  => $pb['case_count'],
                'nominal'     => $pb['nominal'],
                'honor'       => $honor,
                'rule_label'  => $pb['rule_label'],
            ];
            $doctors[$empId]['payers'][$payer]['subtotal_honor'] += $honor;
        }

        // 5) Finalisasi: totals per dokter + grand total. Normalisasi map→list.
        $grand = ['amount_bpjs' => 0.0, 'amount_umum' => 0.0, 'honor_bpjs' => 0.0, 'honor_umum' => 0.0, 'honor' => 0.0];
        $doctorList = [];
        foreach ($doctors as $d) {
            foreach (['UMUM', 'BPJS'] as $pg) {
                $d['payers'][$pg]['categories'] = array_values($d['payers'][$pg]['categories']);
            }
            $d['noninfo'] = collect($d['noninfo'])->map(fn ($amt, $label) => ['category' => $label, 'amount' => $amt])->values()->all();
            $tAmt = $d['payers']['UMUM']['subtotal_amount'] + $d['payers']['BPJS']['subtotal_amount'];
            $tHon = $d['payers']['UMUM']['subtotal_honor'] + $d['payers']['BPJS']['subtotal_honor'];
            $d['total_amount'] = round($tAmt, 2);
            $d['total_honor']  = round($tHon, 2);
            $grand['amount_umum'] += $d['payers']['UMUM']['subtotal_amount'];
            $grand['amount_bpjs'] += $d['payers']['BPJS']['subtotal_amount'];
            $grand['honor_umum']  += $d['payers']['UMUM']['subtotal_honor'];
            $grand['honor_bpjs']  += $d['payers']['BPJS']['subtotal_honor'];
            $doctorList[] = $d;
        }
        usort($doctorList, fn ($a, $b) => $b['total_honor'] <=> $a['total_honor']);
        $grand = array_map(fn ($v) => round($v, 2), $grand);
        $grand['honor'] = round($grand['honor_umum'] + $grand['honor_bpjs'], 2);

        return [
            'period'       => $period,
            'period_label' => $start->isoFormat('MMMM YYYY'),
            'bpjs_basis'   => $bpjsBasis,
            'doctors'      => $doctorList,
            'grand_total'  => $grand,
            'unmatched_count' => $unmatched,
            'details'      => $details,
        ];
    }

    /** Bangun CSV rekap honor (dikelompokkan dokter → penjamin → kategori). */
    public function buildHonorRecapCsv(array $filters): string
    {
        $recap = $this->buildHonorRecap($filters);
        $rows = [];
        $rows[] = ['Dokter', 'Penjamin', 'Kategori', 'Tanggal', 'Pasien', 'Prosedur', 'Qty', 'Harga', 'Total', 'Net', 'Honor', 'Aturan'];

        // Indeks detail per (dokter,penjamin) utk baris rincian di bawah subtotal.
        $detailsByKey = [];
        foreach ($recap['details'] as $d) {
            $detailsByKey[$d['doctor_name'] . '|' . $d['payer_group']][] = $d;
        }

        foreach ($recap['doctors'] as $doc) {
            foreach (['UMUM', 'BPJS'] as $pg) {
                $bucket = $doc['payers'][$pg];
                $hasData = ! empty($bucket['categories']) || ! empty($bucket['packages']);
                if (! $hasData) { continue; }

                $detKey = $doc['doctor_name'] . '|' . $pg;
                $byCat = [];
                foreach (($detailsByKey[$detKey] ?? []) as $d) { $byCat[$d['category']][] = $d; }

                foreach ($bucket['categories'] as $cat) {
                    foreach (($byCat[$cat['category']] ?? []) as $d) {
                        $rows[] = [
                            $doc['doctor_name'], $pg, $cat['category'], $d['date'], $d['patient'],
                            $d['procedure'], $d['quantity'], $this->num($d['unit_price']),
                            $this->num($d['total_price']), $this->num($d['net_price']),
                            $d['honor'] !== null ? $this->num($d['honor']) : '', $d['rule'],
                        ];
                    }
                    $pctLabel = $cat['percent'] !== null ? " {$cat['percent']}%" : '';
                    $rows[] = [
                        $doc['doctor_name'], $pg, 'SUBTOTAL ' . $cat['category'] . " ({$pg}{$pctLabel})",
                        '', '', '', $cat['count'], '', $this->num($cat['amount_gross']),
                        $this->num($cat['amount_net']), $this->num($cat['honor']),
                        $cat['rule_matched'] === false ? 'TANPA ATURAN' : (string) $cat['rule_label'],
                    ];
                }
                foreach ($bucket['packages'] as $pk) {
                    $rows[] = [
                        $doc['doctor_name'], $pg, 'PAKET ' . $pk['package_name'] . " × {$pk['case_count']} kasus",
                        '', '', '', $pk['case_count'], $this->num($pk['nominal']), '', '',
                        $this->num($pk['honor']), (string) $pk['rule_label'],
                    ];
                }
                $rows[] = [
                    $doc['doctor_name'], $pg, "SUBTOTAL {$pg}", '', '', '', '', '',
                    $this->num($bucket['subtotal_amount']), '', $this->num($bucket['subtotal_honor']), '',
                ];
            }
            $rows[] = [
                $doc['doctor_name'], '', 'TOTAL ' . $doc['doctor_name'], '', '', '', '', '',
                $this->num($doc['total_amount']), '', $this->num($doc['total_honor']), '',
            ];
        }

        $g = $recap['grand_total'];
        $rows[] = ['', '', 'GRAND TOTAL — Honor UMUM', '', '', '', '', '', $this->num($g['amount_umum']), '', $this->num($g['honor_umum']), ''];
        $rows[] = ['', '', 'GRAND TOTAL — Honor BPJS', '', '', '', '', '', $this->num($g['amount_bpjs']), '', $this->num($g['honor_bpjs']), ''];
        $rows[] = ['', '', 'GRAND TOTAL — Honor SEMUA', '', '', '', '', '', '', '', $this->num($g['honor']), ''];

        return $this->toCsv($rows);
    }

    // =========================================================================
    // QUERY HELPERS
    // =========================================================================

    /** Baris detail per billing item (honor-eligible & info), sudah teratribusi dokter. */
    private function fetchDetailRows(Carbon $start, Carbon $end, string $bpjsBasis, ?string $payerOnly, ?string $employeeId): array
    {
        $dpjpExpr = 'COALESCE(vs.performed_by_id, v.dpjp_employee_id, de.doctor_id, ds.employee_id)';
        $payerExpr = "CASE WHEN ins.type = 'BPJS' THEN 'BPJS' ELSE 'UMUM' END";

        $q = DB::table('billing_items as bi')
            ->join('billing_invoices as inv', 'inv.id', '=', 'bi.billing_invoice_id')
            ->join('visits as v', 'v.id', '=', 'inv.visit_id')
            ->leftJoin('patients as p', 'p.id', '=', 'v.patient_id')
            ->leftJoin('insurers as ins', 'ins.id', '=', 'v.insurer_id')
            ->leftJoin('doctor_schedules as ds', 'ds.id', '=', 'v.doctor_schedule_id')
            ->leftJoin('doctor_examinations as de', 'de.visit_id', '=', 'v.id')
            ->leftJoin('visit_services as vs', 'vs.id', '=', 'bi.reference_id')
            ->whereNull('bi.deleted_at')
            ->whereNull('inv.deleted_at')
            ->where(fn ($w) => $this->applyRealizationFilter($w, $start, $end, $bpjsBasis));

        if ($payerOnly) {
            $q->whereRaw("$payerExpr = ?", [$payerOnly]);
        }
        if ($employeeId) {
            $q->whereRaw("$dpjpExpr = ?", [$employeeId]);
        }

        return $q->selectRaw("
                bi.id, bi.item_type, bi.category, bi.description, bi.quantity,
                bi.unit_price, bi.total_price, bi.net_price,
                inv.status, inv.paid_at as realized_at,
                v.id as visit_id, v.visit_date,
                p.name as patient_name,
                $payerExpr as payer_group,
                $dpjpExpr as attributed_employee_id
            ")
            ->orderBy('attributed_employee_id')
            ->orderBy('payer_group')
            ->orderBy('bi.category')
            ->get()->all();
    }

    /** Visit ber-paket-bedah yang terealisasi dlm periode (utk honor nominal per kasus). */
    private function fetchPackageCases(Carbon $start, Carbon $end, string $bpjsBasis): array
    {
        $payerExpr = "CASE WHEN ins.type = 'BPJS' THEN 'BPJS' ELSE 'UMUM' END";

        return DB::table('visits as v')
            ->join('billing_invoices as inv', 'inv.visit_id', '=', 'v.id')
            ->join('surgery_schedules as ss', 'ss.id', '=', 'v.surgery_schedule_id')
            ->leftJoin('insurers as ins', 'ins.id', '=', 'v.insurer_id')
            ->whereNull('inv.deleted_at')
            ->whereNotNull('ss.surgery_package_id')
            ->whereNotNull('ss.lead_surgeon_id')
            ->where(fn ($w) => $this->applyRealizationFilter($w, $start, $end, $bpjsBasis))
            ->selectRaw("
                v.id as visit_id, v.visit_date, inv.paid_at as realized_at,
                ss.lead_surgeon_id, ss.surgery_package_id,
                $payerExpr as payer_group
            ")
            ->distinct()
            ->get()->all();
    }

    /** WHERE realisasi: UMUM=PAID by paid_at; BPJS=finalized(by visit_date) / paid(by paid_at). */
    private function applyRealizationFilter($w, Carbon $start, Carbon $end, string $bpjsBasis): void
    {
        // UMUM (semua selain BPJS): tagihan LUNAS dalam bulan.
        $w->where(function ($u) use ($start, $end) {
            $u->whereRaw("(ins.type IS NULL OR ins.type <> 'BPJS')")
              ->where('inv.status', 'PAID')
              ->whereBetween('inv.paid_at', [$start, $end]);
        });

        // BPJS.
        $w->orWhere(function ($b) use ($start, $end, $bpjsBasis) {
            $b->where('ins.type', 'BPJS');
            if ($bpjsBasis === 'paid') {
                $b->where('inv.status', 'PAID')->whereBetween('inv.paid_at', [$start, $end]);
            } else {
                $b->whereIn('inv.status', ['FINALIZED', 'PARTIALLY_PAID', 'PAID'])
                  ->whereBetween('v.visit_date', [$start->toDateString(), $end->toDateString()]);
            }
        });
    }

    // =========================================================================
    // RESOLUSI ATURAN (paling spesifik menang)
    // =========================================================================

    private function resolvePercentRule($rules, ?string $empId, ?string $category, string $payer, Carbon $date): ?DoctorFeeRule
    {
        $best = null; $bestScore = -1;
        foreach ($rules as $r) {
            if (! in_array($r->rule_type, [DoctorFeeRule::TYPE_PERCENT_CATEGORY, DoctorFeeRule::TYPE_PERCENT_PAYER], true)) { continue; }
            if ($r->surgery_package_id !== null) { continue; }
            if (! $this->effectiveOn($r, $date)) { continue; }
            if ($r->employee_id !== null && $r->employee_id !== $empId) { continue; }
            if ($r->category !== null && $r->category !== $category) { continue; }
            if ($r->payer_group !== null && $r->payer_group !== $payer) { continue; }

            $score = ($r->employee_id !== null ? 8 : 0)
                   + ($r->category !== null ? 2 : 0)
                   + ($r->payer_group !== null ? 1 : 0);
            if ($this->beats($score, $r, $bestScore, $best)) { $best = $r; $bestScore = $score; }
        }
        return $best;
    }

    private function resolveNominalRule($rules, ?string $empId, ?string $packageId, string $payer, Carbon $date): ?DoctorFeeRule
    {
        $best = null; $bestScore = -1;
        foreach ($rules as $r) {
            if ($r->rule_type !== DoctorFeeRule::TYPE_NOMINAL_PACKAGE) { continue; }
            if (! $this->effectiveOn($r, $date)) { continue; }
            if ($r->employee_id !== null && $r->employee_id !== $empId) { continue; }
            if ($r->surgery_package_id !== null && $r->surgery_package_id !== $packageId) { continue; }
            if ($r->payer_group !== null && $r->payer_group !== $payer) { continue; }

            $score = ($r->employee_id !== null ? 8 : 0)
                   + ($r->surgery_package_id !== null ? 4 : 0)
                   + ($r->payer_group !== null ? 1 : 0);
            if ($this->beats($score, $r, $bestScore, $best)) { $best = $r; $bestScore = $score; }
        }
        return $best;
    }

    private function effectiveOn(DoctorFeeRule $r, Carbon $date): bool
    {
        if ($r->effective_from && $date->lt(Carbon::parse($r->effective_from)->startOfDay())) { return false; }
        if ($r->effective_to && $date->gt(Carbon::parse($r->effective_to)->endOfDay())) { return false; }
        return true;
    }

    /** Tie-break: skor lebih tinggi; seri → effective_from lalu created_at terbaru. */
    private function beats(int $score, DoctorFeeRule $r, int $bestScore, ?DoctorFeeRule $best): bool
    {
        if ($score > $bestScore) { return true; }
        if ($score < $bestScore || ! $best) { return $score > $bestScore; }
        $rf = $r->effective_from ? Carbon::parse($r->effective_from) : Carbon::minValue();
        $bf = $best->effective_from ? Carbon::parse($best->effective_from) : Carbon::minValue();
        if (! $rf->eq($bf)) { return $rf->gt($bf); }
        return $r->created_at && $best->created_at && $r->created_at->gt($best->created_at);
    }

    // =========================================================================
    // CRUD ATURAN HONOR
    // =========================================================================

    public function listFeeRules(array $filters = []): array
    {
        $q = DoctorFeeRule::query()->with(['employee:id,name', 'surgeryPackage:id,name'])
            ->orderByDesc('effective_from')->orderByDesc('created_at');
        if (! empty($filters['employee_id'])) { $q->where('employee_id', $filters['employee_id']); }
        if (! empty($filters['rule_type'])) { $q->where('rule_type', $filters['rule_type']); }
        return $q->get()->all();
    }

    public function upsertFeeRule(array $data, ?string $id = null): DoctorFeeRule
    {
        $this->validateFeeRule($data);
        $rule = $id ? DoctorFeeRule::findOrFail($id) : new DoctorFeeRule();
        $rule->fill($data);
        $rule->save();
        return $rule->fresh(['employee:id,name', 'surgeryPackage:id,name']);
    }

    public function deleteFeeRule(string $id): void
    {
        DoctorFeeRule::findOrFail($id)->delete();
    }

    /** Opsi dropdown utk form aturan & filter rekap. */
    public function feeRuleOptions(): array
    {
        return [
            'doctors' => Employee::query()->where('is_active', true)
                ->where(fn ($q) => $q->where('profession', Employee::PPA_DOKTER)->orWhereNotNull('doctor_type'))
                ->orderBy('name')->get(['id', 'name'])->all(),
            'packages'   => SurgeryPackage::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'categories' => self::HONOR_CATEGORIES,
            'rule_types' => DoctorFeeRule::RULE_TYPES,
            'payer_groups' => DoctorFeeRule::PAYER_GROUPS,
            'bases'      => DoctorFeeRule::BASES,
        ];
    }

    private function validateFeeRule(array $data): void
    {
        $type = $data['rule_type'] ?? null;
        $err = [];
        if (! in_array($type, DoctorFeeRule::RULE_TYPES, true)) {
            $err['rule_type'] = ['Jenis aturan tidak valid.'];
        }
        if (empty($data['effective_from'])) {
            $err['effective_from'] = ['Tanggal berlaku wajib diisi.'];
        }
        if ($type === DoctorFeeRule::TYPE_NOMINAL_PACKAGE) {
            if (! isset($data['nominal']) || (float) $data['nominal'] < 0) {
                $err['nominal'] = ['Nominal honor wajib diisi (≥ 0) untuk aturan paket.'];
            }
        } else { // PERCENT_*
            $pct = $data['percent'] ?? null;
            if ($pct === null || (float) $pct < 0 || (float) $pct > 100) {
                $err['percent'] = ['Persen honor wajib 0–100 untuk aturan persentase.'];
            }
        }
        if ($err) { throw ValidationException::withMessages($err); }
    }

    // =========================================================================
    // UTIL
    // =========================================================================

    /** @return array{0:Carbon,1:Carbon,2:string} [start, end, periodNorm] */
    private function resolvePeriod(?string $period): array
    {
        $period = $period ?: now()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw ValidationException::withMessages(['period' => ['Format periode harus YYYY-MM.']]);
        }
        $start = Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfMonth();
        $end   = (clone $start)->endOfMonth();
        return [$start, $end, $period];
    }

    private function emptyPayerBucket(): array
    {
        return [
            'categories'     => [], // map sementara: category => agregat
            'packages'       => [],
            'subtotal_amount'=> 0.0,
            'subtotal_honor' => 0.0,
        ];
    }

    /** Angka polos (tanpa pemisah ribuan) agar tetap numerik di Excel. */
    private function num($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function toCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
