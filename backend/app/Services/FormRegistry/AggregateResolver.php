<?php

namespace App\Services\FormRegistry;

use App\Models\BpjsClaim;
use App\Models\Icd9Code;
use App\Models\Icd10Code;
use App\Models\Visit;

/**
 * Resolver untuk binding kind="aggregate".
 *
 * Aggregate beda dengan binding "db" — return value bisa berupa string
 * multi-line, atau HTML (untuk format `*_html`). Whitelist key di
 * FieldRegistry::aggregates().
 *
 * Format yang diimplementasi (Fase 2):
 *   prescriptions:
 *     - items_pretty       — "1. Amoxicillin 500mg | 3x1 sehari | qty:10"
 *     - items_table_html   — <table>…</table>
 *   doctorExamination.icd10_diagnoses:
 *     - icd_with_desc_join_newline — "H25.0 Senile cataract\nH40.9 …"
 *     - icd_only_join_comma        — "H25.0, H40.9"
 *   visitServices:
 *     - list_simple      — "1. Operasi Katarak Phaco x1\n2. …"
 *     - list_with_tarif  — "1. Operasi Katarak Phaco x1 — Rp 5.000.000"
 *   diagnosticResults.summary:
 *     - summary_per_jenis — "Biometri: AL 23.4 mm…\nUSG B: …" (1 baris per jenis)
 */
final class AggregateResolver
{
    /** Cache ICD-10 description lookup per request (kode → description). */
    private array $icd10DescCache = [];

    /** Cache ICD-9 description lookup per request (kode → description). */
    private array $icd9DescCache = [];

    public function resolve(Visit $visit, string $source, ?string $format): ?string
    {
        return match ($source) {
            'prescriptions'                       => $this->resolvePrescriptions($visit, $format),
            'doctorExamination.icd10_diagnoses'   => $this->resolveIcd10Diagnoses($visit, $format),
            'doctorExamination.icd9_procedures'   => $this->resolveIcd9Procedures($visit, $format),
            'claim.icd10_diagnoses'               => $this->resolveClaimIcd10($visit, $format),
            'claim.icd9_procedures'               => $this->resolveClaimIcd9($visit, $format),
            'visitServices'                       => $this->resolveVisitServices($visit, $format),
            'diagnosticResults.summary'           => $this->resolveDiagnosticResults($visit, $format),
            'surgery_iol_usage'                   => $this->resolveIolUsage($visit, $format),
            'surgery_operation_summary'           => $this->resolveOperationSummary($visit, $format),
            'surgery_identity'                    => $this->resolveSurgeryIdentity($visit, $format),
            'planning_instruction'                => $this->resolvePlanningInstruction($visit),
            'physical_exam'                       => $this->resolvePhysicalExam($visit),
            'allergy'                             => $this->resolveAllergy($visit),
            default                               => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // allergy — Alergi Obat Resume Medis: detail alergi triase (nurse_assessments
    // .allergy_detail) dgn fallback catatan alergi master pasien (patients
    // .allergy_notes). Kosong bila keduanya tak ada (keputusan user: blank,
    // bukan "Tidak ada"). Sebelumnya binding `db` 1 jalur → fallback mustahil.
    // ─────────────────────────────────────────────────────────────────────────
    private function resolveAllergy(Visit $visit): ?string
    {
        $detail = trim((string) ($visit->nurseAssessment?->allergy_detail ?? ''));
        if ($detail !== '') {
            return $detail;
        }
        $notes = trim((string) ($visit->patient?->allergy_notes ?? ''));

        return $notes !== '' ? $notes : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // physical_exam — "Pemeriksaan Fisik" Resume Medis = refraksi objektif (RO,
    // sumber tunggal refraction_records.soap_o yang ditulis RefraksionisView) +
    // pemeriksaan segmen mata dokter (doctorExamination.soap_objective). Tetap
    // EDITABLE di FormRMRenderer (prefill saja). Urutan RO mengikuti soap_o.
    // ─────────────────────────────────────────────────────────────────────────
    private function resolvePhysicalExam(Visit $visit): ?string
    {
        $parts = [];
        $rec = $visit->refractionRecord;
        // RO: utamakan soap_o (sumber tunggal), fallback bangun dari record (visit lama).
        $ro = trim((string) ($rec?->soap_o ?? ''));
        if ($ro === '' && $rec !== null) {
            $ro = (string) $this->buildRefraksiObjektif($rec);
        }
        if ($ro !== '') {
            $parts[] = $ro;
        }
        $seg = trim((string) ($visit->doctorExamination?->soap_objective ?? ''));
        if ($seg !== '') {
            $parts[] = $seg;
        }

        // TTV triase (TD/Nadi/SpO2/Suhu/KGD) — fallback agar Pemeriksaan Fisik tak
        // kosong saat dokter tidak menyentuh SOAP O & refraksi tak ada (mis. kontrol
        // cepat). Hanya ditambahkan bila belum ada bagian yang memuat TTV ("TD"/"TD:")
        // — soap_o/soap_objective lama yang sudah memuat TTV tidak akan dobel.
        $sudahAdaTtv = (bool) preg_grep('/\bTD\b\s*[:\d]/', $parts);
        if (! $sudahAdaTtv) {
            $ttv = $this->buildTtvTriase($visit);
            if ($ttv !== '') {
                array_unshift($parts, $ttv);
            }
        }

        return $parts ? implode("\n", $parts) : null;
    }

    /** Baris TTV dari asesmen triase — hanya field yang terisi. */
    private function buildTtvTriase(Visit $visit): string
    {
        $na = $visit->nurseAssessment;
        if ($na === null) {
            return '';
        }
        $p = [];
        if ($na->td_sistol && $na->td_diastol) {
            $p[] = "TD: {$na->td_sistol}/{$na->td_diastol} mmHg";
        }
        if ($na->nadi)      { $p[] = "Nadi: {$na->nadi} x/mnt"; }
        if ($na->spo2)      { $p[] = 'SpO2: ' . rtrim(rtrim((string) $na->spo2, '0'), '.') . '%'; }
        if ($na->suhu)      { $p[] = 'T: ' . rtrim(rtrim((string) $na->suhu, '0'), '.') . '°C'; }
        if ($na->respirasi) { $p[] = "RR: {$na->respirasi} x/mnt"; }
        if ($na->kgd)       { $p[] = 'KGD: ' . rtrim(rtrim((string) $na->kgd, '0'), '.') . ' mg/dL'; }

        return implode(', ', $p);
    }

    /**
     * Bangun teks refraksi objektif dari record (fallback bila soap_o kosong).
     * Urutan SELARAS dgn RefraksionisView::oDerived & RmeAggregator::refraksiObjektif:
     * Visus awal → Refraksi subjektif (S/C/X) → Visus akhir → ADD → IOP → PD.
     */
    private function buildRefraksiObjektif(\App\Models\RefractionRecord $r): string
    {
        $sg = fn ($n) => $n === null ? null : (($n >= 0 ? '+' : '') . $n);
        $scx = function ($sph, $cyl, $axis) use ($sg) {
            if ($sph === null && $cyl === null && $axis === null) return '';
            $p = [];
            if ($sph !== null)  { $p[] = 'S ' . $sg($sph); }
            if ($cyl !== null)  { $p[] = 'C ' . $sg($cyl); }
            if ($axis !== null) { $p[] = "X {$axis}"; }
            return implode(' / ', $p);
        };
        $parts = [];
        if ($r->visus_awal_od || $r->visus_awal_os) {
            $parts[] = 'Visus awal OD ' . ($r->visus_awal_od ?? '–') . ' / OS ' . ($r->visus_awal_os ?? '–');
        }
        $rxOd = $scx($r->refraksi_subjektif_od_sph, $r->refraksi_subjektif_od_cyl, $r->refraksi_subjektif_od_axis);
        $rxOs = $scx($r->refraksi_subjektif_os_sph, $r->refraksi_subjektif_os_cyl, $r->refraksi_subjektif_os_axis);
        if ($rxOd || $rxOs) {
            $parts[] = 'Refraksi subjektif OD ' . ($rxOd ?: '–') . ' | OS ' . ($rxOs ?: '–');
        }
        if ($r->visus_akhir_od || $r->visus_akhir_os) {
            $parts[] = 'Visus akhir OD ' . ($r->visus_akhir_od ?? '–') . ' / OS ' . ($r->visus_akhir_os ?? '–');
        }
        $hasAdd = ($r->add_power_od !== null && (float) $r->add_power_od != 0.0)
            || ($r->add_power_os !== null && (float) $r->add_power_os != 0.0);
        if ($hasAdd) {
            $parts[] = 'Add OD ' . ($sg($r->add_power_od) ?? '–') . ' / OS ' . ($sg($r->add_power_os) ?? '–');
        }
        if ($r->iop_od || $r->iop_os) {
            $parts[] = 'TIO OD ' . ($r->iop_od ?? '–') . ' / OS ' . ($r->iop_os ?? '–') . ' mmHg' . ($r->iop_method ? " ({$r->iop_method})" : '');
        }
        if ($r->pd_distance !== null && $r->pd_distance !== '') {
            $pd = rtrim(rtrim((string) $r->pd_distance, '0'), '.');
            $parts[] = 'PD ' . $pd . ' mm';
        }
        return $parts ? implode("\n", $parts) : '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // planning_instruction — "Instruksi/Anjuran" Resume Medis dari rencana
    // tatalaksana dokter (doctorExamination.planning). Binding `db` lama hanya
    // mengeluarkan enum mentah ("BEDAH"/"PULANG_BEROBAT_JALAN"); di sini diubah
    // jadi kalimat siap-pakai, mis. "Kontrol kembali tanggal 7 Juni 2026" atau
    // "Rencana operasi Phaco + IOL tanggal 10 Juni 2026". Tetap EDITABLE — dokter
    // boleh sunting di FormRMRenderer.
    // ─────────────────────────────────────────────────────────────────────────
    private function resolvePlanningInstruction(Visit $visit): string
    {
        $exam = $visit->doctorExamination;
        if ($exam === null) {
            return '';
        }

        return match ((string) ($exam->planning ?? '')) {
            'PULANG_BEROBAT_JALAN', 'PULANG' => $this->planningPulang($visit),
            'BEDAH'                          => $this->planningBedah($exam),
            'RAWAT_INAP'                     => 'Rawat inap untuk observasi/tatalaksana lebih lanjut.',
            'RUJUK'                          => $this->planningRujuk($exam),
            default                          => '',
        };
    }

    private function planningPulang(Visit $visit): string
    {
        if ($visit->follow_up_date) {
            $line = 'Kontrol kembali tanggal ' . $this->idDate($visit->follow_up_date);
            $reason = trim((string) ($visit->follow_up_reason ?? ''));
            return $line . ($reason !== '' ? " ({$reason})" : '') . '.';
        }
        return 'Pulang, berobat jalan. Kontrol kembali bila ada keluhan.';
    }

    private function planningBedah(\App\Models\DoctorExamination $exam): string
    {
        $sched = $exam->surgerySchedule;
        $pkg   = $exam->surgeryPackage;

        $verb = ($sched && $sched->location_type === 'RUANG_TINDAKAN') ? 'Rencana tindakan' : 'Rencana operasi';
        $name = $pkg?->name ? ' ' . trim((string) $pkg->name) : '';
        $date = $sched?->scheduled_date ? ' tanggal ' . $this->idDate($sched->scheduled_date) : '';
        $time = $sched?->scheduled_time ? ' pukul ' . substr((string) $sched->scheduled_time, 0, 5) : '';

        return trim($verb . $name . $date . $time) . '.';
    }

    private function planningRujuk(\App\Models\DoctorExamination $exam): string
    {
        $fac    = trim((string) ($exam->external_referral_facility ?? ''));
        $reason = trim((string) ($exam->external_referral_reason ?? ''));
        $line   = $fac !== '' ? "Rujuk ke {$fac}" : 'Rujuk untuk penanganan lebih lanjut';
        return $line . ($reason !== '' ? " — {$reason}" : '') . '.';
    }

    /** Format tanggal Indonesia ("7 Juni 2026") tanpa bergantung locale Carbon global. */
    private function idDate(mixed $d): string
    {
        if (! $d) {
            return '';
        }
        $c = \Illuminate\Support\Carbon::parse($d);
        $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return $c->day . ' ' . ($months[$c->month] ?? '') . ' ' . $c->year;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // prescriptions
    // ─────────────────────────────────────────────────────────────────────────

    private function resolvePrescriptions(Visit $visit, ?string $format): string
    {
        // Terapi Resume Medis = resep DOKTER Tab 3 saja (keputusan user): tanpa
        // resep pre-op dokter jaga / pasca-bedah, dan tanpa resep CANCELLED.
        $prescriptions = $visit->prescriptions()
            ->where('status', '!=', 'CANCELLED')
            ->where('is_pre_op', false)
            ->where('is_post_op', false)
            ->with(['items.medication'])
            ->get();
        $items = [];
        foreach ($prescriptions as $rx) {
            foreach ($rx->items as $item) {
                // Aturan pakai: resep dokter menyimpan field granular dose/frequency/
                // route/duration_days — dosage/instructions legacy hanya fallback
                // (item TAMBAHAN Farmasi / data lama). Tanpa ini Terapi tampil
                // "nama | qty" saja tanpa aturan.
                $aturan = implode(' ', array_filter([
                    $item->frequency,
                    $item->route,
                    $item->duration_days ? "selama {$item->duration_days} hari" : null,
                ]));
                $items[] = [
                    'name'         => $item->medication?->name ?? '(obat tidak ditemukan)',
                    'generic'      => $item->medication?->generic_name ?? '',
                    'qty'          => $item->quantity,
                    'unit'         => $item->medication?->unit ?? '',
                    'dosage'       => $item->dose ?: $item->dosage,
                    'instructions' => $aturan !== '' ? $aturan : $item->instructions,
                ];
            }
        }

        if (empty($items)) {
            return '';
        }

        return match ($format) {
            'items_table_html' => $this->renderPrescriptionsTable($items),
            default            => $this->renderPrescriptionsPretty($items),    // items_pretty
        };
    }

    /** @param list<array<string,mixed>> $items */
    private function renderPrescriptionsPretty(array $items): string
    {
        $lines = [];
        foreach ($items as $i => $it) {
            $parts = [trim($it['name'] . ' ' . ($it['generic'] ? "({$it['generic']})" : ''))];
            if ($it['dosage'])       $parts[] = $it['dosage'];
            if ($it['instructions']) $parts[] = $it['instructions'];
            $parts[] = "qty: {$it['qty']} {$it['unit']}";
            $lines[] = ($i + 1) . '. ' . implode(' | ', array_map('trim', $parts));
        }
        return implode("\n", $lines);
    }

    /** @param list<array<string,mixed>> $items */
    private function renderPrescriptionsTable(array $items): string
    {
        $rows = '';
        foreach ($items as $i => $it) {
            $no   = $i + 1;
            $name = htmlspecialchars((string) $it['name'], ENT_QUOTES);
            $gen  = $it['generic'] ? ' <em>(' . htmlspecialchars((string) $it['generic'], ENT_QUOTES) . ')</em>' : '';
            $dose = htmlspecialchars((string) $it['dosage'], ENT_QUOTES);
            $inst = htmlspecialchars((string) $it['instructions'], ENT_QUOTES);
            $qty  = htmlspecialchars((string) $it['qty'], ENT_QUOTES);
            $unit = htmlspecialchars((string) $it['unit'], ENT_QUOTES);
            $rows .= "<tr><td>{$no}</td><td>{$name}{$gen}</td><td>{$dose}</td><td>{$inst}</td><td>{$qty} {$unit}</td></tr>";
        }
        return '<table style="border-collapse:collapse;width:100%;font-size:13px;">'
            . '<thead><tr>'
            . '<th style="border:1px solid #999;padding:4px;">#</th>'
            . '<th style="border:1px solid #999;padding:4px;">Obat</th>'
            . '<th style="border:1px solid #999;padding:4px;">Dosis</th>'
            . '<th style="border:1px solid #999;padding:4px;">Aturan Pakai</th>'
            . '<th style="border:1px solid #999;padding:4px;">Qty</th>'
            . '</tr></thead><tbody>'
            . str_replace('<td>', '<td style="border:1px solid #999;padding:4px;">', $rows)
            . '</tbody></table>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // doctorExamination.icd10_diagnoses
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveIcd10Diagnoses(Visit $visit, ?string $format): string
    {
        $exam = $visit->doctorExamination;
        if ($exam === null) {
            return '';
        }

        $codes = [];
        if (!empty($exam->diagnosis_utama)) {
            $codes[] = $exam->diagnosis_utama;
        }
        if (is_array($exam->diagnosis_sekunder)) {
            foreach ($exam->diagnosis_sekunder as $sec) {
                if (is_string($sec) && $sec !== '') {
                    $codes[] = $sec;
                }
            }
        }
        $codes = array_values(array_unique($codes));
        if (empty($codes)) {
            return '';
        }

        return match ($format) {
            'icd_only_join_comma' => implode(', ', $codes),
            default               => $this->joinIcdWithDesc($codes),  // icd_with_desc_join_newline
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // doctorExamination.icd9_procedures — Tindakan/Prosedur ICD-9 yang dipilih
    // dokter (doctorExamination.tindakan_codes). Dipakai Resume Medis Rawat Jalan
    // agar baris "Tindakan" tampil "kode — nama" (sebelumnya binding `db` hanya
    // mengeluarkan kode mentah tanpa deskripsi).
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveIcd9Procedures(Visit $visit, ?string $format): string
    {
        $exam = $visit->doctorExamination;
        if ($exam === null) {
            return '';
        }

        $codes = [];
        foreach ((array) ($exam->tindakan_codes ?? []) as $c) {
            if (is_string($c) && $c !== '') {
                $codes[] = $c;
            }
        }
        $codes = array_values(array_unique($codes));
        if (empty($codes)) {
            // KOSONG bila dokter tidak memilih ICD-9 — JANGAN fallback ke
            // visit_services: itu item TAGIHAN (Admisi/Konsultasi/Visus/NCT dsb.),
            // bukan tindakan medis. Kolom "Tindakan" resume = murni prosedur ICD-9
            // (koreksi user 11 Jun 2026 — fallback sempat dipasang lalu dicabut).
            return '';
        }

        return match ($format) {
            'icd_only_join_comma' => implode(', ', $codes),
            default               => $this->joinIcd9WithDesc($codes),  // icd_with_desc_join_newline
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // claim.* — diagnosa/prosedur dari KODING KLAIM (bpjs_claims), BUKAN dari
    // doctorExamination. Dipakai template "Lembar Klaim" (RESUME_KLAIM) agar
    // dokumen pendukung BPJS selalu = angka grouping yang disetel koder, sementara
    // Resume Medis dokter tetap utuh. Satu klaim per visit (updateOrCreate by visit).
    // ─────────────────────────────────────────────────────────────────────────

    private function claimForVisit(Visit $visit): ?BpjsClaim
    {
        return BpjsClaim::query()
            ->where('visit_id', $visit->id)
            ->latest('created_at')
            ->first();
    }

    private function resolveClaimIcd10(Visit $visit, ?string $format): string
    {
        $claim = $this->claimForVisit($visit);
        if ($claim === null) {
            return '';
        }

        $codes = [];
        if (!empty($claim->diagnosis_utama)) {
            $codes[] = $claim->diagnosis_utama;
        }
        foreach ((array) ($claim->diagnosis_sekunder ?? []) as $sec) {
            $code = is_array($sec) ? ($sec['kode'] ?? $sec['code'] ?? null) : $sec;
            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        if (empty($codes)) {
            return '';
        }

        return match ($format) {
            'icd_only_join_comma' => implode(', ', $codes),
            default               => $this->joinIcdWithDesc($codes),  // icd_with_desc_join_newline
        };
    }

    private function resolveClaimIcd9(Visit $visit, ?string $format): string
    {
        $claim = $this->claimForVisit($visit);
        if ($claim === null) {
            return '';
        }

        $codes = [];
        foreach ((array) ($claim->procedure_codes ?? []) as $p) {
            $code = is_array($p) ? ($p['kode'] ?? $p['code'] ?? null) : $p;
            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        if (empty($codes)) {
            return '';
        }

        return match ($format) {
            'icd_only_join_comma' => implode(', ', $codes),
            default               => $this->joinIcd9WithDesc($codes),  // icd_with_desc_join_newline
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // visitServices
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveVisitServices(Visit $visit, ?string $format): string
    {
        $services = $visit->visitServices()->with('procedure')->get();
        if ($services->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($services as $i => $svc) {
            $name = $svc->procedure?->name ?? '(tindakan tidak dikenali)';
            $qty  = $svc->quantity ?: 1;
            $base = ($i + 1) . '. ' . $name . " x{$qty}";

            if ($format === 'list_with_tarif') {
                $price    = (float) $svc->price * (int) $qty;
                $priceStr = 'Rp ' . number_format($price, 0, ',', '.');
                $lines[] = "{$base} — {$priceStr}";
            } else {
                // list_simple (default)
                $lines[] = $base;
            }
        }
        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // diagnosticResults.summary
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveDiagnosticResults(Visit $visit, ?string $format): string
    {
        $orders = $visit->diagnosticOrders()->with('results')->get();
        if ($orders->isEmpty()) {
            return '';
        }

        // Resolusi nama jenis penunjang (kode → nama) sekali, bulk — agar baris
        // tampil "Biometri (OD)" bukan kode mentah "BIOM".
        $typeCodes = $orders->pluck('test_type')->filter()->unique()->values()->all();
        $typeNames = empty($typeCodes)
            ? []
            : \App\Models\DiagnosticTestType::query()->whereIn('code', $typeCodes)
                ->pluck('name', 'code')->all();

        // summary_per_jenis (default & satu-satunya format) — 1 baris per penunjang
        // yang SUDAH dikerjakan (COMPLETED atau punya hasil). Bila hasil terstruktur
        // ada → "Nama (OD): ringkasan"; bila tidak → minimal "Nama (OD)" agar baris
        // Hasil Penunjang resume tidak kosong selama penunjang memang dilakukan.
        $lines = [];
        foreach ($orders as $order) {
            $isDone = $order->status === 'COMPLETED' || $order->results->isNotEmpty();
            if (! $isDone) {
                continue;
            }
            $name = $typeNames[$order->test_type] ?? (string) ($order->test_type ?? 'Penunjang');
            $eye  = $order->eye_side ? ' (' . strtoupper((string) $order->eye_side) . ')' : '';

            $details = [];
            foreach ($order->results as $res) {
                $parts = [];
                if (!empty($res->notes)) {
                    $parts[] = trim((string) $res->notes);
                }
                if (is_array($res->expertise_data)) {
                    // Flatten 1 level — ambil key:val yang scalar saja.
                    foreach ($res->expertise_data as $k => $v) {
                        if (is_scalar($v) && $v !== '' && $v !== null) {
                            $parts[] = "{$k}: {$v}";
                        }
                    }
                }
                if (!empty($parts)) {
                    $details[] = implode(' | ', $parts);
                }
            }

            $lines[] = $details
                ? "{$name}{$eye}: " . implode(' / ', $details)
                : "{$name}{$eye}";
        }

        return implode("\n", array_values(array_unique($lines)));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // surgery_iol_usage — "Stiker Implant" RM 10.1 (hasil scan UDI)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Daftar IOL/implan terpasang pada operasi visit ini — menggantikan stiker
     * fisik di laporan. Sumber = surgery_iol_usage (hasil scan barcode UDI:
     * brand/power dari master + serial/lot/gtin/expiry dari label). 1 baris per
     * lensa. Kosong bila operasi tanpa IOL (mis. vitrektomi murni) → operator
     * dapat isi manual (field editable).
     */
    private function resolveIolUsage(Visit $visit, ?string $format): string
    {
        $records = \App\Models\SurgeryRecord::query()
            ->where('visit_id', $visit->id)
            ->with(['iolUsages.iolItem'])
            ->get();

        $lines = [];
        foreach ($records as $rec) {
            foreach ($rec->iolUsages as $u) {
                $item  = $u->iolItem;
                $brand = trim(((string) ($item->brand ?? '')) . ' ' . ((string) ($item->model ?? '')));
                $power = $item->power ?? null;
                $head  = trim(
                    ($u->eye_side ? $u->eye_side . ' — ' : '')
                    . ($brand !== '' ? $brand : '(IOL)')
                    . ($power !== null && $power !== '' ? " {$power} D" : '')
                );
                $meta = [];
                if (!empty($u->serial_number)) $meta[] = 'SN: ' . $u->serial_number;
                if (!empty($u->lot_number))    $meta[] = 'Lot: ' . $u->lot_number;
                if (!empty($u->gtin))          $meta[] = 'GTIN: ' . $u->gtin;
                if (!empty($u->expiry_date)) {
                    $meta[] = 'Exp: ' . ($u->expiry_date instanceof \Carbon\CarbonInterface
                        ? $u->expiry_date->toDateString()
                        : (string) $u->expiry_date);
                }
                $lines[] = $head . (empty($meta) ? '' : ' | ' . implode(' | ', $meta));
            }
        }
        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // surgery_operation_summary — narasi "Teknik Operasi & Temuan" RM 2.2
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ringkasan operasi (teknik/temuan/komplikasi/vitrektomi) dari operation_report
     * JSONB surgery_records — "auto-generate" narasi RM 2.2 dari data yang sudah
     * diisi dokter di tab Laporan BedahView. Kosong bila belum ada record/laporan.
     */
    private function resolveOperationSummary(Visit $visit, ?string $format): string
    {
        $rec = \App\Models\SurgeryRecord::query()
            ->where('visit_id', $visit->id)
            ->latest('created_at')
            ->first();
        if (! $rec) {
            return '';
        }
        $r = is_array($rec->operation_report) ? $rec->operation_report : [];

        $lines = [];
        if (! empty($r['technique'])) {
            $lines[] = "[Teknik Operasi]\n" . trim((string) $r['technique']);
        }
        if (! empty($r['findings'])) {
            $lines[] = "[Temuan Intraoperatif]\n" . trim((string) $r['findings']);
        }
        $compl = $r['complication'] ?? [];
        if (is_array($compl) && (! empty($compl['ada']) || ! empty($compl['type']))) {
            $lines[] = '[Komplikasi] ' . trim(((string) ($compl['type'] ?? '')) . ' ' . ((string) ($compl['management'] ?? '')));
        }
        $vit = $r['vitrectomy_details'] ?? null;
        if (is_array($vit) && ! empty($vit['tamponade'])) {
            $lines[] = '[Vitrektomi] Tamponade: ' . $vit['tamponade']
                . (! empty($vit['endolaser']) ? ' · Endolaser' : '')
                . (! empty($vit['membrane_peeling']) ? ' · Membrane peeling' : '');
        }
        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // surgery_identity — identitas operasi (operator/asisten/jam/durasi/anestesi)
    // dipakai prefill form RM (RM 10.1/2.2/2.3) agar TIDAK diketik ulang — sumber
    // tunggal = BedahView (surgery_records). format = field yg diminta.
    // ─────────────────────────────────────────────────────────────────────────
    private function resolveSurgeryIdentity(Visit $visit, ?string $format): string
    {
        $rec = \App\Models\SurgeryRecord::query()
            ->where('visit_id', $visit->id)
            ->latest('created_at')
            ->first();
        if (! $rec) {
            return '';
        }
        $r = is_array($rec->operation_report) ? $rec->operation_report : [];

        // Jam mulai/selesai = kolom time_in/time_out; durasi dihitung darinya.
        $timeStr = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('H:i') : '';

        return match ($format) {
            'operator'         => trim((string) ($r['operator'] ?? '')),
            'asisten'          => is_array($r['asisten'] ?? null)
                                    ? implode(', ', array_filter($r['asisten']))
                                    : trim((string) ($r['asisten'] ?? '')),
            'asisten1'         => trim((string) ((is_array($r['asisten'] ?? null) ? ($r['asisten'][0] ?? '') : ''))),
            'asisten2'         => trim((string) ((is_array($r['asisten'] ?? null) ? ($r['asisten'][1] ?? '') : ''))),
            'anesthesiologist' => trim((string) ($r['anesthesiologist'] ?? '')),
            'anesthesia_type'  => trim((string) ($r['anesthesia_type'] ?? '')),
            'procedure'        => trim((string) ($r['procedure_name'] ?? '')),
            'diagnosis_post'   => trim((string) ($r['diagnosis_post'] ?? '')),
            'time_in'          => $timeStr($rec->time_in),
            'time_out'         => $timeStr($rec->time_out),
            'duration'         => $this->formatDuration($rec->time_in, $rec->time_out),
            default            => '',
        };
    }

    private function formatDuration($timeIn, $timeOut): string
    {
        if (! $timeIn || ! $timeOut) {
            return '';
        }
        $in  = \Illuminate\Support\Carbon::parse($timeIn);
        $out = \Illuminate\Support\Carbon::parse($timeOut);
        // diffInMinutes (Carbon ≥2.x) mengembalikan float — bulatkan ke int menit.
        $mins = (int) round(abs($in->diffInMinutes($out)));
        if ($mins <= 0) {
            return '';
        }
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return $h > 0 ? "{$h} jam {$m} menit" : "{$m} menit";
    }

    /** @param list<string> $codes */
    private function joinIcdWithDesc(array $codes): string
    {
        // Bulk-fetch yang belum di-cache.
        $missing = array_values(array_diff($codes, array_keys($this->icd10DescCache)));
        if (!empty($missing)) {
            $rows = Icd10Code::query()->whereIn('code', $missing)->pluck('description', 'code')->all();
            foreach ($missing as $code) {
                $this->icd10DescCache[$code] = $rows[$code] ?? null;
            }
        }

        $lines = [];
        foreach ($codes as $code) {
            $desc = $this->icd10DescCache[$code] ?? null;
            $lines[] = $desc ? "{$code} — {$desc}" : $code;
        }
        return implode("\n", $lines);
    }

    /** @param list<string> $codes — ICD-9-CM (prosedur/tindakan), 1 baris per kode. */
    private function joinIcd9WithDesc(array $codes): string
    {
        $missing = array_values(array_diff($codes, array_keys($this->icd9DescCache)));
        if (!empty($missing)) {
            // Utamakan deskripsi Indonesia (sama dgn yang dipilih dokter di picker),
            // fallback ke deskripsi (Inggris) bila kosong.
            $rows = Icd9Code::query()->whereIn('code', $missing)
                ->get(['code', 'indonesian_description', 'description'])
                ->keyBy('code');
            foreach ($missing as $code) {
                $row = $rows->get($code);
                $this->icd9DescCache[$code] = $row?->indonesian_description ?: $row?->description;
            }
        }

        $lines = [];
        foreach ($codes as $code) {
            $desc = $this->icd9DescCache[$code] ?? null;
            $lines[] = $desc ? "{$code} — {$desc}" : $code;
        }
        return implode("\n", $lines);
    }
}
