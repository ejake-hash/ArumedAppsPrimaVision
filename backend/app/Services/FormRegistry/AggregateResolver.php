<?php

namespace App\Services\FormRegistry;

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

    public function resolve(Visit $visit, string $source, ?string $format): ?string
    {
        return match ($source) {
            'prescriptions'                       => $this->resolvePrescriptions($visit, $format),
            'doctorExamination.icd10_diagnoses'   => $this->resolveIcd10Diagnoses($visit, $format),
            'visitServices'                       => $this->resolveVisitServices($visit, $format),
            'diagnosticResults.summary'           => $this->resolveDiagnosticResults($visit, $format),
            default                               => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // prescriptions
    // ─────────────────────────────────────────────────────────────────────────

    private function resolvePrescriptions(Visit $visit, ?string $format): string
    {
        $prescriptions = $visit->prescriptions()->with(['items.medication'])->get();
        $items = [];
        foreach ($prescriptions as $rx) {
            foreach ($rx->items as $item) {
                $items[] = [
                    'name'         => $item->medication?->name ?? '(obat tidak ditemukan)',
                    'generic'      => $item->medication?->generic_name ?? '',
                    'qty'          => $item->quantity,
                    'unit'         => $item->medication?->unit ?? '',
                    'dosage'       => $item->dosage,
                    'instructions' => $item->instructions,
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

        // summary_per_jenis (default & satu-satunya format) — group by test_type,
        // 1 baris per jenis berisi ringkasan hasil (notes + expertise_data inti).
        $byType = [];
        foreach ($orders as $order) {
            $type = (string) ($order->test_type ?? '?');
            foreach ($order->results as $res) {
                $byType[$type] ??= [];
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
                    $byType[$type][] = implode(' | ', $parts);
                }
            }
        }

        if (empty($byType)) {
            return '';
        }

        $lines = [];
        foreach ($byType as $type => $entries) {
            $lines[] = "{$type}: " . implode(' / ', $entries);
        }
        return implode("\n", $lines);
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
}
