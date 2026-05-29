<?php

namespace App\Services\FormRegistry;

/**
 * Engine untuk SCORED_FORM (Section 4.3 design doc).
 *
 * Tiga tipe field yang dihandle:
 *   - scored_radio       — radio dengan skor per opsi (input dari user)
 *   - computed_sum       — total dari field scored_radio yang di-reference di `sum_of`
 *   - computed_threshold — interpretasi label dari nilai numerik via thresholds[]
 *
 * Engine ini stateless (semua input via array $data + $schema). Tidak akses DB.
 *
 * Dipanggil di 2 tempat:
 *   1. BindingResolver kind=computed (saat render OUTPUT) — supaya placeholder
 *      `{{total_score}}` dan `{{interpretasi}}` ter-resolve.
 *   2. SubmitRouter (saat submit INPUT scored form) — sanity-check & simpan
 *      hasil compute ke static_payload supaya audit log konsisten.
 */
final class ScoringEngine
{
    /**
     * Compute satu field computed dari $schema + $data answers.
     *
     * @param array $field    field definition dari field_schema.fields[]
     * @param array $data     payload jawaban user { key: value, key: value, ... }
     *                        Untuk scored_radio: value = score (number)
     * @return int|float|string|null
     */
    public function computeField(array $field, array $data): int|float|string|null
    {
        $type = $field['type'] ?? null;

        return match ($type) {
            'computed_sum'       => $this->computeSum($field, $data),
            'computed_threshold' => $this->computeThreshold($field, $data),
            'computed_duration'  => $this->computeDuration($field, $data),
            default              => null,
        };
    }

    /**
     * Compute SEMUA computed field di schema sekaligus. Iterasi sampai stable
     * (computed_threshold bisa reference computed_sum).
     *
     * @return array { fieldKey: computedValue }
     */
    public function computeAll(array $schema, array $data): array
    {
        $fields = $this->flattenFields($schema);
        $result = array_merge([], $data); // copy

        // Multi-pass karena threshold depend on sum.
        for ($pass = 0; $pass < 3; $pass++) {
            $changed = false;
            foreach ($fields as $f) {
                $key  = $f['key'] ?? null;
                $type = $f['type'] ?? null;
                if (!$key || !str_starts_with((string) $type, 'computed_')) continue;

                $val = $this->computeField($f, $result);
                if (($result[$key] ?? null) !== $val) {
                    $result[$key] = $val;
                    $changed = true;
                }
            }
            if (!$changed) break;
        }

        // Filter output: hanya field computed yang dikembalikan.
        $out = [];
        foreach ($fields as $f) {
            $key  = $f['key'] ?? null;
            $type = $f['type'] ?? null;
            if ($key && str_starts_with((string) $type, 'computed_')) {
                $out[$key] = $result[$key] ?? null;
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Primitives
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * computed_sum: total dari list field di `sum_of`.
     * Field `sum_of` = ['key1', 'key2', ...]. Value yang null / non-numeric di-skip.
     */
    private function computeSum(array $field, array $data): int|float
    {
        $sumOf = $field['sum_of'] ?? [];
        if (!is_array($sumOf)) return 0;

        $sum = 0;
        foreach ($sumOf as $key) {
            if (!is_string($key)) continue;
            $v = $data[$key] ?? null;
            if (is_numeric($v)) $sum += $v;
        }
        return $sum;
    }

    /**
     * computed_threshold: pilih label dari thresholds[] berdasarkan nilai
     * field `based_on`. Threshold sorted ascending by `max`.
     * Format: thresholds = [{ max: 24, label: 'Risiko Rendah' }, ...]
     */
    private function computeThreshold(array $field, array $data): ?string
    {
        $basedOn = $field['based_on'] ?? null;
        $thresholds = $field['thresholds'] ?? [];
        if (!$basedOn || !is_array($thresholds)) return null;

        $value = $data[$basedOn] ?? null;
        if (!is_numeric($value)) return null;

        // Sort ascending by max
        $sorted = $thresholds;
        usort($sorted, fn ($a, $b) => ($a['max'] ?? PHP_INT_MAX) <=> ($b['max'] ?? PHP_INT_MAX));

        foreach ($sorted as $t) {
            if (!isset($t['max'])) continue;
            if ($value <= $t['max']) {
                return (string) ($t['label'] ?? '');
            }
        }
        return null;
    }

    /**
     * computed_duration: hitung durasi (menit) dari 2 field time.
     * Format: { from: 'jam_mulai', to: 'jam_selesai' }
     */
    private function computeDuration(array $field, array $data): ?int
    {
        $from = $field['from'] ?? null;
        $to   = $field['to']   ?? null;
        if (!$from || !$to) return null;

        $fromVal = $data[$from] ?? null;
        $toVal   = $data[$to]   ?? null;
        if (!$fromVal || !$toVal) return null;

        try {
            $f = \Carbon\Carbon::parse((string) $fromVal);
            $t = \Carbon\Carbon::parse((string) $toVal);
            return $t->diffInMinutes($f, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function flattenFields(array $schema): array
    {
        if (isset($schema['fields']) && is_array($schema['fields'])) {
            return $schema['fields'];
        }
        if (isset($schema['pages']) && is_array($schema['pages'])) {
            $all = [];
            foreach ($schema['pages'] as $page) {
                if (isset($page['fields']) && is_array($page['fields'])) {
                    array_push($all, ...$page['fields']);
                }
            }
            return $all;
        }
        return [];
    }
}
