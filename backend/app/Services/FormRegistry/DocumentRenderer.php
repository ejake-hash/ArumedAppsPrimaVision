<?php

namespace App\Services\FormRegistry;

use App\Models\DocumentTemplate;
use App\Models\Visit;
use RuntimeException;

/**
 * Render `layout_html` template → HTML final dengan {{placeholder}} ter-substitusi.
 *
 * Placeholder syntax: `{{key}}` di mana `key` cocok dengan
 *   `field_schema.fields[].key` atau salah satu key di
 *   `field_schema.pages[].fields[].key` (multi-page layout).
 *
 * Field tanpa binding (atau binding null) → di-replace string kosong, bukan error.
 */
final class DocumentRenderer
{
    public function __construct(
        private readonly BindingResolver $resolver,
        private readonly ScoringEngine $scoring = new ScoringEngine(),
    ) {}

    /**
     * Render template untuk visit tertentu.
     *
     * @param array $documentPayload  Optional — static_payload dari patient_document
     *                                (jawaban scored_radio, signature SVG, dst).
     *                                Saat render dipanggil dari finalize/snapshot,
     *                                ini berisi data konkret untuk substitusi.
     */
    public function render(DocumentTemplate $template, int|string $visitId, array $documentPayload = []): string
    {
        $visit = Visit::query()->find($visitId);
        if ($visit === null) {
            throw new RuntimeException("Visit {$visitId} tidak ditemukan.");
        }

        $layout = (string) ($template->layout_html ?? '');
        if ($layout === '') {
            return '';
        }

        $schema = $template->field_schema ?? [];
        $fields = $this->flattenFields($schema);

        // Step 1: compute scored fields dari documentPayload (kalau ada).
        $computed = !empty($documentPayload)
            ? $this->scoring->computeAll($schema, $documentPayload)
            : [];

        // Step 2: resolve nilai per field.
        // PENTING: field signature_canvas / signature_placeholder TIDAK di-substitute
        // di sini — biarkan placeholder `{{ttd_*}}` tetap utuh agar
        // `embedSignatures()` (dipanggil setelah render() di finalize) bisa
        // replace dengan SVG. Kalau substitute di sini, SVG akan diganti string
        // kosong duluan.
        $values = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if (!is_string($key) || $key === '') continue;

            $type    = $field['type']    ?? null;
            $binding = $field['binding'] ?? null;

            // SKIP signature_* — biarkan placeholder utuh untuk embedSignatures().
            if ($type === 'signature_canvas' || $type === 'signature_placeholder') {
                continue;
            }

            // Computed type punya prioritas — pakai hasil ScoringEngine.
            if (str_starts_with((string) $type, 'computed_')) {
                $val = $computed[$key] ?? null;
                $values[$key] = $val === null ? '' : (string) $val;
                continue;
            }

            // Static dengan documentPayload override (jawaban user, signature, dst).
            if (is_array($binding) && ($binding['kind'] ?? null) === 'static' && array_key_exists($key, $documentPayload)) {
                $values[$key] = $this->stringify($documentPayload[$key]);
                continue;
            }

            // Default: resolve via BindingResolver (db/clinic/aggregate).
            if (!is_array($binding)) {
                $values[$key] = '';
                continue;
            }
            $resolved = $this->resolver->resolve($visit, $binding);

            // Type image_url: wrap value (URL) dengan <img> tag supaya logo/
            // signature/stamp ter-render sebagai gambar di HTML output.
            if ($type === 'image_url' && is_string($resolved) && $resolved !== '') {
                $alt = htmlspecialchars((string) ($field['label'] ?? 'Image'), ENT_QUOTES);
                $maxH = $field['max_height_px'] ?? 80;
                $values[$key] = '<img src="' . htmlspecialchars($resolved, ENT_QUOTES)
                    . '" alt="' . $alt . '" style="max-height:' . (int) $maxH . 'px;height:auto;display:inline-block;"/>';
                continue;
            }

            $values[$key] = $resolved === null ? '' : (string) $resolved;
        }

        return $this->substitute($layout, $values, /* keepUnknown */ true);
    }

    private function stringify(mixed $v): string
    {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? 'Ya' : 'Tidak';
        if (is_array($v)) return implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v));
        return (string) $v;
    }

    /**
     * Render template TANPA Visit di DB — pakai nilai dari form (mis. pasien
     * baru di Admisi yang belum punya patient_id/visit_id).
     *
     * Binding di-resolve sebagai berikut:
     *   - db (patient.* / visit.*) → ambil dari $formValues[<column>] berdasarkan
     *     kolom terakhir source (mis. 'patient.name' → $formValues['name']).
     *   - clinic.* → resolve normal via BindingResolver (tidak butuh Visit).
     *   - static → dari $staticPayload kalau ada, else kosong.
     *   - signature_canvas → di-embed dari $signatureSvgByType (preview TTD).
     *
     * @param array<string,mixed> $formValues        Map kolom → nilai (name, nik, gender, …)
     * @param array<string,mixed> $staticPayload     Jawaban static (checkbox, saksi, …)
     * @param array<string,string> $signatureSvgByType  signer_type → SVG string
     */
    public function renderForPreview(
        DocumentTemplate $template,
        array $formValues,
        array $staticPayload = [],
        array $signatureSvgByType = [],
    ): string {
        $layout = (string) ($template->layout_html ?? '');
        if ($layout === '') {
            return '';
        }

        $schema = $template->field_schema ?? [];
        $fields = $this->flattenFields($schema);

        $values = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if (!is_string($key) || $key === '') continue;

            $type    = $field['type']    ?? null;
            $binding = $field['binding'] ?? null;

            // signature_canvas → biarkan placeholder utuh; embed belakangan.
            if ($type === 'signature_canvas' || $type === 'signature_placeholder') {
                continue;
            }

            if (!is_array($binding)) {
                $values[$key] = '';
                continue;
            }

            $kind   = $binding['kind']   ?? null;
            $source = (string) ($binding['source'] ?? '');

            if ($kind === 'db') {
                // Ambil kolom terakhir dari source (patient.name → name).
                $col = str_contains($source, '.') ? substr($source, strrpos($source, '.') + 1) : $source;
                $values[$key] = array_key_exists($col, $formValues)
                    ? $this->stringify($formValues[$col])
                    : '';
                continue;
            }

            if ($kind === 'clinic') {
                $resolved = $this->resolver->resolveClinicPublic($source);
                $values[$key] = $resolved === null ? '' : (string) $resolved;
                continue;
            }

            if ($kind === 'static') {
                $values[$key] = array_key_exists($key, $staticPayload)
                    ? $this->stringify($staticPayload[$key])
                    : '';
                continue;
            }

            // aggregate/computed butuh Visit — preview kosongkan.
            $values[$key] = '';
        }

        // Substitusi text, sisakan placeholder signature (keepUnknown) untuk embed.
        $html = $this->substitute($layout, $values, /* keepUnknown */ true);

        // Embed signature dari SVG preview (bungkus jadi DocumentSignature-like).
        if (!empty($signatureSvgByType)) {
            $fieldMap = $this->extractSignatureFieldMap($schema);
            $sigByType = [];
            foreach ($signatureSvgByType as $signerType => $svg) {
                if (is_string($svg) && $svg !== '') {
                    $sigByType[$signerType] = (object) ['signature_svg' => $svg];
                }
            }
            $html = $this->embedSignatures($html, $fieldMap, $sigByType);
        }

        // Placeholder signature yang belum diteken → kosongkan (jangan biarkan {{ttd_*}}).
        $html = $this->substitute($html, [], /* keepUnknown */ false);

        return $html;
    }

    /**
     * Embed SVG signature inline ke posisi placeholder `{{key}}`.
     *
     * Fase 5: map field signature_canvas → DocumentSignature record berdasarkan
     * `signer_type`. Render SVG inline (bukan PNG base64 — supaya tetap vector-
     * crisp di print).
     *
     * @param string $html               HTML hasil substitusi placeholder text
     * @param array  $fieldsByKey        ['key' => signer_type] mapping field
     *                                   signature_canvas dari template
     * @param array  $signaturesByType   ['signer_type' => DocumentSignature]
     */
    public function embedSignatures(string $html, array $fieldsByKey = [], array $signaturesByType = []): string
    {
        // Replace `{{key}}` di fieldsByKey dengan SVG kalau ada signature, atau
        // string kosong kalau belum ter-capture. Placeholder yang tidak terdaftar
        // sebagai signature field di-skip (biarkan utuh — kemungkinan placeholder
        // lain yang tidak ke-substitute di render()).
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function (array $m) use ($fieldsByKey, $signaturesByType): string {
                $key = $m[1];
                $signerType = $fieldsByKey[$key] ?? null;
                if (!$signerType) return $m[0]; // bukan signature field

                $sig = $signaturesByType[$signerType] ?? null;
                if (!$sig || empty($sig->signature_svg)) return ''; // belum TTD → kosongkan

                return '<div style="display:inline-block;max-width:180px;max-height:80px;overflow:hidden;">'
                    . $this->normalizeSvg($sig->signature_svg)
                    . '</div>';
            },
            $html
        ) ?? $html;
    }

    /**
     * Pastikan SVG punya viewBox/width yang seragam. signature_pad output
     * biasanya sudah punya viewBox, tapi kita force width 100% supaya
     * fit ke container.
     */
    private function normalizeSvg(string $svg): string
    {
        // Strip outer XML declaration kalau ada.
        $svg = preg_replace('/<\?xml[^>]*\?>/', '', $svg) ?? $svg;
        // Tambah/replace width="100%" + height="auto" di tag <svg>.
        $svg = preg_replace(
            '/<svg([^>]*)>/',
            '<svg$1 style="width:100%;height:auto;">',
            $svg,
            1
        ) ?? $svg;
        return $svg;
    }

    /**
     * Helper: extract { fieldKey: signer_type } dari field_schema untuk
     * field signature_canvas saja.
     */
    public function extractSignatureFieldMap(array $schema): array
    {
        $out = [];
        foreach ($this->flattenFields($schema) as $f) {
            if (($f['type'] ?? null) === 'signature_canvas') {
                $key = $f['key'] ?? null;
                $st  = $f['signer_type'] ?? null;
                if ($key && $st) $out[$key] = $st;
            }
        }
        return $out;
    }

    /**
     * Ratakan field_schema (single_page atau multi_page) jadi flat list of fields.
     */
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

    /**
     * Replace `{{key}}` (whitespace toleran) dengan nilai dari $values.
     *
     * @param bool $keepUnknown  true → placeholder yang tidak ada di $values
     *                           dibiarkan utuh (untuk pipeline render → embedSignatures).
     *                           false → di-replace dengan string kosong (default lama).
     */
    private function substitute(string $template, array $values, bool $keepUnknown = false): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            static function (array $m) use ($values, $keepUnknown): string {
                $key = $m[1];
                if (array_key_exists($key, $values)) return (string) $values[$key];
                return $keepUnknown ? $m[0] : '';
            },
            $template
        ) ?? $template;
    }
}
