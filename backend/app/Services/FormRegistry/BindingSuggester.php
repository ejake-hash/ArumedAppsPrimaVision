<?php

namespace App\Services\FormRegistry;

/**
 * Tiered auto-suggest binding berdasarkan kemiripan label field hasil parser
 * dengan label di FieldRegistry::columns().
 *
 * Tier (Section 10.5 design doc):
 *   - high  (≥ 90% similar) — auto-pick, admin tinggal konfirmasi
 *   - medium (60–89%)        — dropdown pre-highlight kandidat top-3
 *   - low/none (< 60%)       — empty dropdown, admin pilih manual
 *
 * Algoritma:
 *   - Normalisasi: lowercase, hilangkan tanda baca, trim
 *   - similar_text() PHP (ratio 0-100), pakai max(label_field, key_field_normalized)
 *   - Hanya pertimbangkan whitelist FieldRegistry — tidak ada DB introspection
 */
final class BindingSuggester
{
    /** @var array<string, array{path:string,label:string,normalized:string,type:string}> */
    private array $haystack;

    public function __construct()
    {
        $this->haystack = $this->buildHaystack();
    }

    /**
     * Suggest binding untuk satu label field hasil parser.
     *
     * @return array{tier:string,confidence:float,suggestions:list<array{path:string,label:string,similarity:float}>}
     */
    public function suggestForLabel(string $rawLabel): array
    {
        $needle = $this->normalize($rawLabel);
        if ($needle === '') {
            return ['tier' => 'none', 'confidence' => 0.0, 'suggestions' => []];
        }

        $scored = [];
        foreach ($this->haystack as $entry) {
            $byLabel = $this->similarity($needle, $entry['normalized']);
            $byPath  = $this->similarity($needle, $this->normalize($entry['path']));
            $sim     = max($byLabel, $byPath);
            $scored[] = [
                'path'       => $entry['path'],
                'label'      => $entry['label'],
                'similarity' => round($sim, 2),
            ];
        }

        usort($scored, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $top = array_slice($scored, 0, 3);
        $best = $top[0]['similarity'] ?? 0.0;

        $tier = match (true) {
            $best >= 90 => 'high',
            $best >= 60 => 'medium',
            default     => 'low',
        };

        return [
            'tier'        => $tier,
            'confidence'  => $best,
            'suggestions' => $top,
        ];
    }

    /**
     * Suggest binding untuk batch field labels (bulk dari hasil parser).
     *
     * @param list<string> $labels
     * @return array<int, array{label:string,tier:string,confidence:float,suggestions:list<array{path:string,label:string,similarity:float}>}>
     */
    public function suggestBulk(array $labels): array
    {
        $out = [];
        foreach ($labels as $i => $label) {
            $res = $this->suggestForLabel($label);
            $out[$i] = array_merge(['label' => $label], $res);
        }
        return $out;
    }

    /** @return array<int, array{path:string,label:string,normalized:string,type:string}> */
    private function buildHaystack(): array
    {
        $out = [];
        foreach (FieldRegistry::columns() as $resource => $fields) {
            foreach ($fields as $key => $meta) {
                $path = $resource . '.' . $key;
                $label = (string) ($meta['label'] ?? $key);
                $out[] = [
                    'path'       => $path,
                    'label'      => $label,
                    'normalized' => $this->normalize($label),
                    'type'       => (string) ($meta['type'] ?? 'text'),
                ];
            }
        }
        return $out;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** similar_text → percent (0-100). */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        $pct = 0.0;
        similar_text($a, $b, $pct);
        return (float) $pct;
    }
}
