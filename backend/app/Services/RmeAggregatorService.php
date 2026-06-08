<?php

namespace App\Services;

use App\Models\DiagnosticTestType;
use App\Models\Icd10Code;
use App\Models\Icd9Code;
use App\Models\NurseCpptEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\RefractionRecord;
use App\Models\SurgeryRecord;
use App\Models\Visit;
use Illuminate\Support\Collection;

/**
 * Agregator data Rekam Medis Elektronik (RME).
 *
 * Mengumpulkan "seluruh aktivitas kunjungan" pasien menjadi riwayat lintas waktu,
 * dikelompokkan per jenis (kunjungan, refraksi, penunjang, obat, bedah, diagnosis,
 * dokumen) — masing-masing dikembalikan sebagai daftar "1 baris = 1 kunjungan"
 * untuk ditampilkan sebagai tabel di RekamMedisView (pola master-detail).
 *
 * Semua data ditarik dari tabel klinis nyata; tidak ada yang dikarang.
 * Lihat docs/spec-rekam-medis-rme.md untuk pemetaan field.
 */
class RmeAggregatorService
{
    // Cache lookup nama ICD/procedure agar tidak query berulang dalam satu request.
    private array $icd10Cache = [];
    private array $icd9Cache  = [];
    private array $procCache  = [];

    // =========================================================================
    // RINGKASAN — kartu, bukan tabel
    // =========================================================================

    public function ringkasan(string $patientId): array
    {
        $patient = Patient::findOrFail($patientId);

        $visits = Visit::with([
            'doctorExamination',
            'refractionRecord',
            'nurseAssessment',
            'doctorSchedule',
        ])
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->get();

        // Problem list — diagnosis ICD-10 unik lintas kunjungan (dedup by kode).
        $problems = [];
        foreach ($visits as $v) {
            $de = $v->doctorExamination;
            if (! $de) {
                continue;
            }
            $codes = array_filter(array_merge(
                [$de->diagnosis_utama],
                collect($de->diagnosis_sekunder ?? [])->map(fn ($d) => is_array($d) ? ($d['kode'] ?? $d['code'] ?? null) : $d)->all()
            ));
            foreach ($codes as $code) {
                if (! $code) {
                    continue;
                }
                if (! isset($problems[$code])) {
                    $problems[$code] = [
                        'kode'        => $code,
                        'nama'        => $this->icd10Name($code),
                        'first_date'  => $v->visit_date?->toDateString(),
                        'last_date'   => $v->visit_date?->toDateString(),
                        'count'       => 0,
                    ];
                }
                $problems[$code]['count']++;
                // visits sudah desc → first kali ketemu = last_date; update first_date terus.
                $problems[$code]['first_date'] = $v->visit_date?->toDateString();
            }
        }

        // Visus & TIO — dari 2 refraksi terakhir (untuk tren naik/turun).
        $refs = $visits->filter(fn ($v) => $v->refractionRecord)->take(2)->values();
        $latestRef = $refs[0]->refractionRecord ?? null;
        $prevRef   = $refs[1]->refractionRecord ?? null;

        $lastVisit = $visits->first();

        return [
            'patient' => [
                'allergy'   => $patient->allergy_notes,
                'blood_type' => $patient->blood_type,
            ],
            'allergy_latest_assessment' => $visits->firstWhere(fn ($v) => $v->nurseAssessment?->allergy_detail)
                ?->nurseAssessment?->allergy_detail,
            'problem_list' => array_values($problems),
            'visus_tio' => $latestRef ? [
                'date'     => $refs[0]->visit_date?->toDateString(),
                'visus_od' => $latestRef->visus_akhir_od,
                'visus_os' => $latestRef->visus_akhir_os,
                'tio_od'   => $latestRef->iop_od,
                'tio_os'   => $latestRef->iop_os,
                'prev'     => $prevRef ? [
                    'visus_od' => $prevRef->visus_akhir_od,
                    'visus_os' => $prevRef->visus_akhir_os,
                    'tio_od'   => $prevRef->iop_od,
                    'tio_os'   => $prevRef->iop_os,
                ] : null,
            ] : null,
            'last_visit' => $lastVisit ? [
                'date'           => $lastVisit->visit_date?->toDateString(),
                'classification' => $lastVisit->classification,
                'doctor'         => $this->doctorName($lastVisit),
                'poli'           => $lastVisit->doctorSchedule?->poliklinik,
                'planning'       => $lastVisit->doctorExamination?->planning,
                'follow_up_date' => $lastVisit->follow_up_date?->toDateString(),
            ] : null,
            'counts' => [
                'total_visits'   => $visits->count(),
                'total_surgery'  => SurgeryRecord::whereIn('visit_id', $visits->pluck('id'))->count(),
                'with_diagnosis' => $visits->filter(fn ($v) => $v->doctorExamination?->diagnosis_utama)->count(),
            ],
        ];
    }

    // =========================================================================
    // KUNJUNGAN — tabel
    // =========================================================================

    public function kunjungan(string $patientId): array
    {
        $visits = Visit::with([
            'doctorExamination',
            'nurseAssessment',
            'doctorSchedule.employee',
            'diagnosticOrders',
            'patientDocuments',
        ])
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->get();

        return $visits->map(function ($v) {
            $de = $v->doctorExamination;
            $na = $v->nurseAssessment;

            return [
                'visit_id'        => $v->id,
                'visit_date'      => $v->visit_date?->toDateString(),
                'classification'  => $v->classification,
                'guarantor_type'  => $v->guarantor_type,
                'doctor_name'     => $this->doctorName($v),
                'poli_name'       => $v->doctorSchedule?->poliklinik,
                'current_station' => $v->current_station,
                'no_sep'          => $v->no_sep,
                'diagnosis_utama' => $de?->diagnosis_utama,
                'diagnosis_utama_nama' => $de?->diagnosis_utama ? $this->icd10Name($de->diagnosis_utama) : null,
                'is_finalized'    => (bool) ($de?->is_finalized),
                'penunjang_count' => $v->diagnosticOrders->count(),
                'dokumen_count'   => $v->patientDocuments->count(),
                // expand
                'detail' => [
                    'keluhan' => $na?->chief_complaint ?? $de?->anamnese,
                    'ttv'     => $na ? [
                        'td'        => ($na->td_sistol && $na->td_diastol) ? "{$na->td_sistol}/{$na->td_diastol}" : null,
                        'nadi'      => $na->nadi,
                        'suhu'      => $na->suhu,
                        'spo2'      => $na->spo2,
                        'respirasi' => $na->respirasi,
                        'kgd'       => $na->kgd,
                    ] : null,
                    'soap' => $de ? [
                        's' => $de->soap_subjective,
                        'o' => $de->soap_objective,
                        'a' => $de->soap_assessment,
                        'p' => $de->soap_plan,
                    ] : null,
                    'planning'       => $de?->planning,
                    'follow_up_date' => $v->follow_up_date?->toDateString(),
                    'follow_up_reason' => $v->follow_up_reason,
                ],
            ];
        })->all();
    }

    // =========================================================================
    // CPPT LINTAS-EPISODE — satu timeline kronologis
    // =========================================================================

    /**
     * CPPT lintas-episode untuk satu pasien — SATU timeline kronologis menggabungkan:
     *  - nurse_cppt_entries (CPPT perawat/PPA) ber-badge episode (RAJAL/IGD/RANAP),
     *  - SOAP dokter poli (doctor_examinations) ber-badge POLI.
     * Diurutkan terbaru dulu. READ-ONLY — dipakai DokterView (rawat jalan) & modul RME
     * agar DPJP tahu perkembangan dari episode lain (IGD/RANAP) tanpa membuka tiap visit.
     */
    public function cppt(string $patientId): array
    {
        // 1) Entri CPPT (perawat/PPA) lintas SEMUA visit pasien. Badge episode dari visit.
        $cppt = NurseCpptEntry::with(['createdBy', 'verifiedBy', 'visit'])
            ->whereHas('visit', fn ($q) => $q->where('patient_id', $patientId))
            ->orderByDesc('created_at')
            ->get()
            ->map(function (NurseCpptEntry $e) {
                $v = $e->visit;
                return [
                    'kind'        => 'CPPT',
                    'episode'     => $v?->jenis_pelayanan ?? 'RAJAL',
                    'visit_id'    => $e->visit_id,
                    'datetime'    => $e->created_at?->toDateTimeString(),
                    'date'        => $e->created_at?->toDateString(),
                    'ppa_role'    => $e->ppa_role,
                    'author'      => $e->createdBy?->name,
                    'soap'        => ['s' => $e->soap_s, 'o' => $e->soap_o, 'a' => $e->soap_a, 'p' => $e->soap_p],
                    'vitals'      => $this->cpptVitals($e),
                    'instruksi'   => $e->instruksi,
                    'verified_by' => $e->verifiedBy?->name,
                    'verified_at' => $e->verified_at?->toDateTimeString(),
                    'edited_at'   => $e->edited_at?->toDateTimeString(),
                    'signed_at'   => $e->signed_at?->toDateTimeString(),
                ];
            });

        // 2) SOAP dokter poli (doctor_examinations) yang berisi minimal satu komponen SOAP.
        $soap = Visit::with(['doctorExamination', 'doctorSchedule.employee'])
            ->whereHas('doctorExamination', fn ($q) => $q->where(function ($w) {
                $w->whereNotNull('soap_subjective')->orWhereNotNull('soap_objective')
                  ->orWhereNotNull('soap_assessment')->orWhereNotNull('soap_plan');
            }))
            ->where('patient_id', $patientId)
            ->get()
            ->map(function (Visit $v) {
                $de   = $v->doctorExamination;
                $when = $de->finalized_at ?? $de->updated_at ?? $v->visit_date;
                return [
                    'kind'           => 'SOAP',
                    'episode'        => 'POLI',
                    'visit_id'       => $v->id,
                    'datetime'       => $when?->toDateTimeString(),
                    'date'           => ($de->finalized_at ?? $v->visit_date)?->toDateString(),
                    'ppa_role'       => 'DOKTER',
                    'author'         => $this->doctorName($v),
                    'soap'           => [
                        's' => $de->soap_subjective, 'o' => $de->soap_objective,
                        'a' => $de->soap_assessment, 'p' => $de->soap_plan,
                    ],
                    'diagnosis'      => $de->diagnosis_utama,
                    'diagnosis_nama' => $de->diagnosis_utama ? $this->icd10Name($de->diagnosis_utama) : null,
                    'is_finalized'   => (bool) $de->is_finalized,
                ];
            });

        // 3) SOAP Refraksionis (refraction_records terfinalisasi) — PPA Refraksionis.
        //    O di-derive otomatis dari data refraksi (visus/IOP/Rx subjektif).
        //    Tanda tangan (paraf PIN) = signature_timestamp.
        $refr = RefractionRecord::with(['examinedBy', 'visit'])
            ->whereHas('visit', fn ($q) => $q->where('patient_id', $patientId))
            ->where('is_finalized', true)
            ->where(function ($q) {
                $q->whereNotNull('soap_s')->orWhereNotNull('soap_o')->orWhereNotNull('soap_a')->orWhereNotNull('soap_p')
                  ->orWhereNotNull('visus_akhir_od')->orWhereNotNull('visus_akhir_os');
            })
            ->orderByDesc('finalized_at')
            ->get()
            ->map(function (RefractionRecord $r) {
                $v    = $r->visit;
                $when = $r->finalized_at ?? $r->signature_timestamp ?? $r->examination_date;
                return [
                    'kind'        => 'REFRAKSI',
                    'episode'     => $v?->jenis_pelayanan ?? 'RAJAL',
                    'visit_id'    => $r->visit_id,
                    'datetime'    => $when?->toDateTimeString(),
                    'date'        => ($when ?? $r->created_at)?->toDateString(),
                    'ppa_role'    => 'REFRAKSIONIS',
                    'author'      => $r->examinedBy?->name,
                    'soap'        => [
                        's' => $r->soap_s,
                        // O editable tersimpan dipakai apa adanya; bila kosong fallback
                        // derive dari data refraksi (backward-compat record lama).
                        'o' => $r->soap_o ?: $this->refraksiObjektif($r),
                        'a' => $r->soap_a,
                        'p' => $r->soap_p,
                    ],
                    'vitals'      => array_filter([
                        'visus_od' => $r->visus_akhir_od,
                        'visus_os' => $r->visus_akhir_os,
                        'iop_od'   => $r->iop_od,
                        'iop_os'   => $r->iop_os,
                    ], fn ($x) => $x !== null && $x !== ''),
                    'signed_at'   => $r->signature_timestamp?->toDateTimeString(),
                ];
            });

        // Urutan: tanggal DESCENDING (hari terbaru dulu). Untuk entri di HARI yang SAMA,
        // urutan PPA tetap: Dokter → Refraksionis → Perawat (lalu PPA lain). Tie-break
        // terakhir = datetime desc (entri terbaru dulu) bila PPA sama di hari sama.
        $ppaRank = ['DOKTER' => 0, 'REFRAKSIONIS' => 1, 'PERAWAT' => 2];

        return $cppt->concat($soap)->concat($refr)
            ->sort(function ($a, $b) use ($ppaRank) {
                $da = $a['date'] ?? '';
                $db = $b['date'] ?? '';
                if ($da !== $db) {
                    return $db <=> $da;                       // hari terbaru dulu
                }
                $ra = $ppaRank[$a['ppa_role'] ?? ''] ?? 99;
                $rb = $ppaRank[$b['ppa_role'] ?? ''] ?? 99;
                if ($ra !== $rb) {
                    return $ra <=> $rb;                       // Dokter, Refraksionis, Perawat
                }
                return ($b['datetime'] ?? '') <=> ($a['datetime'] ?? '');
            })
            ->values()
            ->all();
    }

    /** Ringkas Objektif refraksionis (visus akhir + refraksi subjektif + TIO) untuk timeline. */
    private function refraksiObjektif(RefractionRecord $r): ?string
    {
        $parts = [];
        if ($r->visus_akhir_od || $r->visus_akhir_os) {
            $parts[] = 'Visus akhir OD ' . ($r->visus_akhir_od ?? '–') . ' / OS ' . ($r->visus_akhir_os ?? '–');
        }
        $rxOd = $this->fmtRx($r->refraksi_subjektif_od_sph, $r->refraksi_subjektif_od_cyl, $r->refraksi_subjektif_od_axis, $r->add_power_od);
        $rxOs = $this->fmtRx($r->refraksi_subjektif_os_sph, $r->refraksi_subjektif_os_cyl, $r->refraksi_subjektif_os_axis, $r->add_power_os);
        if ($rxOd || $rxOs) {
            $parts[] = 'Refraksi subjektif OD ' . ($rxOd ?: '–') . ' | OS ' . ($rxOs ?: '–');
        }
        if ($r->iop_od || $r->iop_os) {
            $parts[] = 'TIO OD ' . ($r->iop_od ?? '–') . ' / OS ' . ($r->iop_os ?? '–') . ' mmHg' . ($r->iop_method ? " ({$r->iop_method})" : '');
        }
        return $parts ? implode("\n", $parts) : null;
    }

    /** Ringkas TTV + visus/IOP satu entri CPPT (buang yang kosong). */
    private function cpptVitals(NurseCpptEntry $e): array
    {
        return array_filter([
            'td'        => ($e->td_sistol && $e->td_diastol) ? "{$e->td_sistol}/{$e->td_diastol}" : null,
            'nadi'      => $e->nadi,
            'suhu'      => $e->suhu,
            'spo2'      => $e->spo2,
            'respirasi' => $e->respirasi,
            'kgd'       => $e->kgd,
            'pain'      => $e->pain_scale,
            'visus_od'  => $e->visus_od,
            'visus_os'  => $e->visus_os,
            'iop_od'    => $e->iop_od,
            'iop_os'    => $e->iop_os,
        ], fn ($v) => $v !== null && $v !== '');
    }

    // =========================================================================
    // REFRAKSI — tabel (OD/OS)
    // =========================================================================

    public function refraksi(string $patientId): array
    {
        $visits = Visit::with([
            'refractionRecord.prescription',
            'refractionRecord.examinedBy',
        ])
            ->whereHas('refractionRecord')
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->get();

        return $visits->map(function ($v) {
            $r  = $v->refractionRecord;
            $rx = $r?->prescription;

            return [
                'visit_id'   => $v->id,
                'visit_date' => ($r->examination_date ?? $v->visit_date)?->toDateString() ?? $v->visit_date?->toDateString(),
                'visus_od'   => $r->visus_akhir_od,
                'visus_os'   => $r->visus_akhir_os,
                'rx_od'      => $this->fmtRx($rx?->rx_od_sph, $rx?->rx_od_cyl, $rx?->rx_od_axis, $rx?->rx_od_add),
                'rx_os'      => $this->fmtRx($rx?->rx_os_sph, $rx?->rx_os_cyl, $rx?->rx_os_axis, $rx?->rx_os_add),
                'tio_od'     => $r->iop_od,
                'tio_os'     => $r->iop_os,
                'pd'         => $r->pd_distance,
                'examiner'   => $r->examinedBy?->name,
                'detail' => [
                    'visus_awal_od'   => $r->visus_awal_od,
                    'visus_awal_os'   => $r->visus_awal_os,
                    'pinhole_od'      => $r->pinhole_od,
                    'pinhole_os'      => $r->pinhole_os,
                    'autoref_od'      => $this->fmtRx($r->autoref_od_sph, $r->autoref_od_cyl, $r->autoref_od_axis, null),
                    'autoref_os'      => $this->fmtRx($r->autoref_os_sph, $r->autoref_os_cyl, $r->autoref_os_axis, null),
                    'subjektif_od'    => $this->fmtRx($r->refraksi_subjektif_od_sph, $r->refraksi_subjektif_od_cyl, $r->refraksi_subjektif_od_axis, null),
                    'subjektif_os'    => $this->fmtRx($r->refraksi_subjektif_os_sph, $r->refraksi_subjektif_os_cyl, $r->refraksi_subjektif_os_axis, null),
                    'keratometri_od'  => $this->fmtKerato($r->keratometri1_od, $r->keratometri2_od, $r->keratometri_axis_od, $r->keratometri_axis2_od),
                    'keratometri_os'  => $this->fmtKerato($r->keratometri1_os, $r->keratometri2_os, $r->keratometri_axis_os, $r->keratometri_axis2_os),
                    'old_glasses_od'  => $this->fmtRx($r->old_glasses_od_sph, $r->old_glasses_od_cyl, $r->old_glasses_od_axis, $r->old_glasses_add_od),
                    'old_glasses_os'  => $this->fmtRx($r->old_glasses_os_sph, $r->old_glasses_os_cyl, $r->old_glasses_os_axis, $r->old_glasses_add_os),
                    'iop_method'      => $r->iop_method,
                    'glasses_type'    => $rx?->glasses_type,
                    'lens_material'   => $rx?->lens_material,
                    'coating'         => $rx?->coating,
                    'clinical_notes'  => $r->clinical_notes,
                ],
            ];
        })->all();
    }

    // =========================================================================
    // PENUNJANG — tabel
    // =========================================================================

    public function penunjang(string $patientId): array
    {
        $visits = Visit::with([
            'diagnosticOrders.results.performedBy',
            'diagnosticOrders.results.reviewedBy',
        ])
            ->whereHas('diagnosticOrders')
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->get();

        $testNames = DiagnosticTestType::pluck('name', 'code')->all();

        $rows = [];
        foreach ($visits as $v) {
            foreach ($v->diagnosticOrders as $order) {
                $result = $order->results->first();
                $rows[] = [
                    'visit_id'    => $v->id,
                    'order_id'    => $order->id,
                    'visit_date'  => ($result?->uploaded_at ?? $v->visit_date)?->toDateString() ?? $v->visit_date?->toDateString(),
                    'test_type'   => $order->test_type,
                    'test_name'   => $testNames[$order->test_type] ?? $order->test_type,
                    'eye_side'    => $order->eye_side,
                    'summary'     => $this->summarizeExpertise($result?->expertise_data),
                    'attachment_url' => $result?->attachment_url,
                    'status'      => $result?->result_status ?? $order->status,
                    'examiner'    => $result?->performedBy?->name,
                    'detail' => [
                        'expertise_data' => $result?->expertise_data,
                        'notes'          => $result?->notes,
                        'reviewer'       => $result?->reviewedBy?->name,
                        'reviewed_at'    => $result?->reviewed_at?->toDateTimeString(),
                    ],
                ];
            }
        }

        return $rows;
    }

    // =========================================================================
    // OBAT — tabel (sumber: diresepkan)
    // =========================================================================

    public function obat(string $patientId): array
    {
        $visits = Visit::with([
            'prescriptions.items.medication',
            'prescriptions.prescribedBy',
        ])
            ->whereHas('prescriptions')
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->get();

        return $visits->map(function ($v) {
            $items = $v->prescriptions->flatMap(fn ($p) => $p->items->map(fn ($it) => [
                'nama'         => $it->medication?->name ?? '–',
                'quantity'     => $it->quantity,
                'unit'         => $it->medication?->unit,
                'dosage'       => $it->dosage,
                'instructions' => $it->instructions,
                'notes'        => $it->notes,
            ]))->values();

            return [
                'visit_id'    => $v->id,
                'visit_date'  => $v->visit_date?->toDateString(),
                'prescriber'  => $v->prescriptions->first()?->prescribedBy?->name,
                'item_count'  => $items->count(),
                'items'       => $items->all(),
            ];
        })->filter(fn ($r) => $r['item_count'] > 0)->values()->all();
    }

    // =========================================================================
    // BEDAH — tabel
    // =========================================================================

    public function bedah(string $patientId): array
    {
        $records = SurgeryRecord::with([
            'visit.doctorExamination',
            'iolUsages.iolItem',
        ])
            ->whereHas('visit', fn ($q) => $q->where('patient_id', $patientId))
            ->get()
            ->sortByDesc(fn ($r) => optional($r->visit)->visit_date)
            ->values();

        return $records->map(function ($rec) {
            $v  = $rec->visit;
            $de = $v?->doctorExamination;

            // Nama prosedur dari tindakan_codes ICD-9 (jika ada).
            $procedures = collect($de?->tindakan_codes ?? [])
                ->map(fn ($c) => is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c)
                ->filter()
                ->map(fn ($code) => $this->icd9Name($code) ?? $code)
                ->all();

            return [
                'visit_id'    => $v?->id,
                'record_id'   => $rec->id,
                'visit_date'  => $v?->visit_date?->toDateString(),
                'procedures'  => $procedures,
                'time_in'     => $rec->time_in?->format('H:i'),
                'time_out'    => $rec->time_out?->format('H:i'),
                'has_complication' => $rec->has_complication,
                // Ringkasan teks (backward-compat untuk tabel).
                // NB: power 0 (plano) tetap ditampilkan; hanya null/'' yang disembunyikan.
                // Daya negatif (minus) jangan dipaksa "+".
                'iol_used'    => $rec->iolUsages->map(function ($u) {
                    $power = $u->power;
                    $powerStr = ($power === null || $power === '')
                        ? ''
                        : ((float) $power >= 0 ? "+{$power}D" : "{$power}D");

                    return trim(($u->brand ?? '') . ' ' . ($u->model ?? '') . ' ' . $powerStr . ' (' . strtoupper($u->eye_side ?? '') . ')');
                })->all(),
                // Detail terstruktur untuk traceability implan (serial/lot/gtin wajib regulasi).
                'iol_details' => $rec->iolUsages->map(fn ($u) => [
                    'eye_side'      => $u->eye_side,
                    'brand'         => $u->brand,
                    'model'         => $u->model,
                    'power'         => $u->power,
                    'lot_number'    => $u->lot_number,
                    'serial_number' => $u->serial_number,
                    'gtin'          => $u->gtin,
                    'expiry_date'   => $u->expiry_date?->toDateString(),
                ])->values()->all(),
                'detail' => [
                    'operation_notes'      => $rec->operation_notes,
                    'complication_detail'  => $rec->complication_detail,
                    'post_op_instructions' => $rec->post_op_instructions,
                    'followup_date'        => $rec->followup_date?->toDateString(),
                ],
            ];
        })->all();
    }

    // =========================================================================
    // DIAGNOSIS — tabel
    // =========================================================================

    public function diagnosis(string $patientId): array
    {
        $visits = Visit::with(['doctorExamination'])
            ->whereHas('doctorExamination', fn ($q) => $q->whereNotNull('diagnosis_utama'))
            ->where('patient_id', $patientId)
            ->orderByDesc('visit_date')
            ->get();

        return $visits->map(function ($v) {
            $de = $v->doctorExamination;

            $sekunder = collect($de->diagnosis_sekunder ?? [])->map(function ($d) {
                $kode = is_array($d) ? ($d['kode'] ?? $d['code'] ?? null) : $d;
                return $kode ? ['kode' => $kode, 'nama' => $this->icd10Name($kode)] : null;
            })->filter()->values()->all();

            $tindakan = collect($de->tindakan_codes ?? [])->map(function ($c) {
                $kode = is_array($c) ? ($c['kode'] ?? $c['code'] ?? null) : $c;
                return $kode ? ['kode' => $kode, 'nama' => $this->icd9Name($kode)] : null;
            })->filter()->values()->all();

            return [
                'visit_id'   => $v->id,
                'visit_date' => $v->visit_date?->toDateString(),
                'utama'      => ['kode' => $de->diagnosis_utama, 'nama' => $this->icd10Name($de->diagnosis_utama)],
                'sekunder'   => $sekunder,
                'tindakan'   => $tindakan,
                'planning'   => $de->planning,
            ];
        })->all();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function doctorName(Visit $v): ?string
    {
        return $v->doctorExamination?->doctor?->name
            ?? $v->doctorSchedule?->employee?->name;
    }

    /** Format resep kacamata: "-1.50 / -0.75 x 180 Add +2.00" (kosong → null). */
    private function fmtRx($sph, $cyl, $axis, $add): ?string
    {
        if ($sph === null && $cyl === null && $axis === null && $add === null) {
            return null;
        }
        $parts = [];
        $parts[] = $this->signed($sph) ?? 'plano';
        if ($cyl !== null) {
            $parts[] = '/ ' . $this->signed($cyl) . ($axis !== null ? " x {$axis}" : '');
        }
        $s = implode(' ', $parts);
        if ($add !== null && (float) $add != 0.0) {
            $s .= ' Add ' . $this->signed($add);
        }
        return $s;
    }

    private function fmtKerato($k1, $k2, $axis1, $axis2 = null): ?string
    {
        if ($k1 === null && $k2 === null) {
            return null;
        }
        $p1 = ($k1 ?? '–') . ($axis1 !== null ? " @ {$axis1}°" : '');
        $p2 = ($k2 ?? '–') . ($axis2 !== null ? " @ {$axis2}°" : '');
        return trim("{$p1} / {$p2}");
    }

    private function signed($n): ?string
    {
        if ($n === null) {
            return null;
        }
        $f = (float) $n;
        return ($f > 0 ? '+' : '') . number_format($f, 2);
    }

    /** Ambil ringkasan singkat dari expertise_data (jsonb bebas bentuk). */
    private function summarizeExpertise($data): ?string
    {
        if (empty($data) || ! is_array($data)) {
            return null;
        }
        // Prioritas key umum.
        foreach (['hasil', 'kesimpulan', 'summary', 'kesan'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                return \Illuminate\Support\Str::limit($data[$key], 80);
            }
        }
        // Fallback: gabung beberapa pasangan key:value pertama.
        $pairs = [];
        foreach ($data as $k => $val) {
            if (is_scalar($val) && $val !== '' && $val !== null) {
                $pairs[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $val;
            }
            if (count($pairs) >= 3) {
                break;
            }
        }
        return $pairs ? \Illuminate\Support\Str::limit(implode(' · ', $pairs), 80) : null;
    }

    private function icd10Name(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        if (! array_key_exists($code, $this->icd10Cache)) {
            $row = Icd10Code::where('code', $code)->first();
            $this->icd10Cache[$code] = $row?->indonesian_description ?: $row?->description;
        }
        return $this->icd10Cache[$code];
    }

    private function icd9Name(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        if (! array_key_exists($code, $this->icd9Cache)) {
            $row = Icd9Code::where('code', $code)->first();
            $this->icd9Cache[$code] = $row?->indonesian_description ?: $row?->description;
        }
        return $this->icd9Cache[$code];
    }
}
