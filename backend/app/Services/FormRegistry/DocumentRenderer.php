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
        // Kunci yang nilainya MEMANG HTML (mis. <img> dari image_url) — dikecualikan
        // dari HTML-escaping di substitute(). Field lain (teks bebas klinis, nama
        // pasien) WAJIB di-escape agar tidak jadi vektor stored-XSS di v-html FE
        // maupun SSRF via <img>/<style url()> saat dompdf me-render PDF.
        $rawHtmlKeys = [];
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

            // multi_checkbox (array): render tiap item tercentang sebagai "☑ item"
            // satu per baris (pakai white-space:pre-line di layout). Hindari join
            // koma yang rancu karena teks item bisa mengandung koma sendiri.
            // Hanya untuk nilai ARRAY — checkbox legacy (skalar) tetap via stringify.
            if ($type === 'multi_checkbox' && array_key_exists($key, $documentPayload) && is_array($documentPayload[$key])) {
                $values[$key] = implode("\n", array_map(
                    fn ($x) => '☑ ' . (is_scalar($x) ? (string) $x : json_encode($x)),
                    $documentPayload[$key]
                ));
                continue;
            }

            // Static dengan documentPayload override (jawaban user, signature, dst).
            if (is_array($binding) && ($binding['kind'] ?? null) === 'static' && array_key_exists($key, $documentPayload)) {
                $payloadVal = $this->stringify($documentPayload[$key]);
                // Payload KOSONG + field punya prefill → resolve ulang dari data
                // TERKINI. Kunci agar regenerateFinalized/rewire-published bisa
                // mengisi field resume yang dulu kosong saat TTD (mis. Terapi yang
                // resepnya baru dibuat pasca "Buka Kembali") — nilai yang DITULIS/
                // disetujui dokter (non-kosong) tetap menang, tidak pernah ditimpa.
                if (trim($payloadVal) === '') {
                    $fresh = $this->resolvePrefill($visit, $field);
                    if ($fresh !== null && $fresh !== '') {
                        $values[$key] = $fresh;
                        continue;
                    }
                }
                $values[$key] = $payloadVal;
                continue;
            }

            // Static TANPA payload (kunci tak pernah disubmit — mis. field baru di
            // template, atau dokumen lama) → coba prefill dari data terkini.
            if (is_array($binding) && ($binding['kind'] ?? null) === 'static') {
                $fresh = $this->resolvePrefill($visit, $field);
                if ($fresh !== null && $fresh !== '') {
                    $values[$key] = $fresh;
                    continue;
                }
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
                $rawHtmlKeys[] = $key; // nilai sudah berupa <img> tersanitasi → jangan di-escape lagi
                continue;
            }

            $values[$key] = $resolved === null ? '' : (string) $resolved;
        }

        return $this->substitute($layout, $values, /* keepUnknown */ true, $rawHtmlKeys);
    }

    /**
     * Resolve konfigurasi `prefill` sebuah field editable (via db/aggregate/static)
     * terhadap data visit TERKINI — dipakai render() sebagai fallback saat nilai
     * payload kosong. Null bila field tak punya prefill / sumber tak menghasilkan apa-apa.
     */
    private function resolvePrefill(Visit $visit, array $field): ?string
    {
        $prefill = $field['prefill'] ?? null;
        if (! is_array($prefill)) {
            return null;
        }

        return $this->resolver->resolve($visit, [
            'kind'   => (string) ($prefill['via'] ?? 'db'),
            'source' => (string) ($prefill['source'] ?? ''),
            'format' => $prefill['format'] ?? null,
            'value'  => $prefill['value'] ?? null,
        ]);
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
     * Embed bukti TTD inline ke posisi placeholder `{{key}}`.
     *
     * Dua mode bukti TTD (lihat SignatureService):
     *   - DRAW (pasien/saksi)  → goresan SVG inline, vector-crisp saat print.
     *   - PIN  (nakes internal) → KOTAK STEMPEL "Ditandatangani secara
     *     elektronik" berisi nama + jabatan/SIP + tanggal-jam + QR verifikasi.
     *
     * @param string  $html              HTML hasil substitusi placeholder text
     * @param array   $fieldsByKey       ['key' => signer_type] field signature_canvas
     * @param array   $signaturesByType  ['signer_type' => DocumentSignature]
     * @param ?string $verifyUrl         URL verifikasi dokumen (untuk QR per-stempel).
     *                                   Null saat preview (QR di-skip).
     */
    public function embedSignatures(
        string $html,
        array $fieldsByKey = [],
        array $signaturesByType = [],
        ?string $verifyUrl = null,
    ): string {
        // Replace `{{key}}` di fieldsByKey dengan bukti TTD kalau ada, atau
        // string kosong kalau belum ter-capture. Placeholder yang tidak terdaftar
        // sebagai signature field di-skip (biarkan utuh — kemungkinan placeholder
        // lain yang tidak ke-substitute di render()).
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function (array $m) use ($fieldsByKey, $signaturesByType, $verifyUrl): string {
                $key = $m[1];
                $signerType = $fieldsByKey[$key] ?? null;
                if (!$signerType) return $m[0]; // bukan signature field

                $sig = $signaturesByType[$signerType] ?? null;
                if (!$sig) return ''; // belum TTD → kosongkan

                // Mode PIN → stempel digital.
                if (($sig->sign_method ?? null) === 'PIN') {
                    return $this->renderPinStamp($sig, $verifyUrl);
                }

                // Mode DRAW → goresan SVG.
                if (empty($sig->signature_svg)) return '';
                return '<div style="display:inline-block;max-width:180px;max-height:80px;overflow:hidden;">'
                    . $this->normalizeSvg($sig->signature_svg)
                    . '</div>';
            },
            $html
        ) ?? $html;
    }

    /**
     * Kotak stempel TTD elektronik (mode PIN). Layout sesuai desain yang
     * disetujui: badge "Ditandatangani elektronik" + nama + jabatan/SIP +
     * tanggal-jam WIB, dengan QR verifikasi di sisi kanan.
     *
     * Inline-style semua (tidak ada <style> eksternal) supaya konsisten saat
     * render Puppeteer / snapshot HTML.
     */
    private function renderPinStamp(object $sig, ?string $verifyUrl): string
    {
        $nama = htmlspecialchars((string) ($sig->signer_name_snapshot ?? '—'), ENT_QUOTES);
        $jabatan = $sig->signer_role_snapshot ?? null;
        $jabatanHtml = $jabatan ? '<div style="font-size:8px;color:#555;">' . htmlspecialchars((string) $jabatan, ENT_QUOTES) . '</div>' : '';

        // Tanggal-jam WIB (Asia/Jakarta) dari captured_at server.
        $waktu = '';
        if (!empty($sig->captured_at)) {
            try {
                $dt = $sig->captured_at instanceof \Carbon\CarbonInterface
                    ? $sig->captured_at->copy()
                    : \Illuminate\Support\Carbon::parse($sig->captured_at);
                $waktu = $dt->timezone('Asia/Jakarta')->format('d-m-Y H:i') . ' WIB';
            } catch (\Throwable) {
                $waktu = '';
            }
        }

        // QR verifikasi (kalau ada URL). SVG inline, no-GD.
        $qrHtml = '';
        if ($verifyUrl) {
            $qrHtml = '<div style="flex:0 0 auto;width:44px;height:44px;">'
                . QrCodeHelper::svg($verifyUrl, 44)
                . '</div>';
        }

        $sigId = htmlspecialchars((string) ($sig->signature_id ?? ''), ENT_QUOTES);

        return <<<HTML
<div style="display:inline-flex;align-items:center;gap:7px;border:1px solid #1FAAE0;border-radius:5px;padding:5px 8px;background:#f5fbfe;max-width:240px;">
  <div style="flex:1 1 auto;text-align:left;line-height:1.3;">
    <div style="font-size:8px;font-weight:700;color:#0E3A66;letter-spacing:.2px;">✓ DITANDATANGANI SECARA ELEKTRONIK</div>
    <div style="font-size:10.5px;font-weight:700;color:#111;margin-top:1px;">{$nama}</div>
    {$jabatanHtml}
    <div style="font-size:8px;color:#555;margin-top:1px;">{$waktu}</div>
    <div style="font-size:7px;color:#999;margin-top:1px;">ID: {$sigId}</div>
  </div>
  {$qrHtml}
</div>
HTML;
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

        // SANITASI (defense-in-depth): signature_svg dikirim klien & hanya
        // divalidasi `nullable|string`, lalu di-embed apa adanya ke HTML dokumen
        // yang dirender via v-html di FE (layar TTD/bulk-sign). Buang vektor XSS.
        $svg = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<\s*script\b[^>]*/?>#is', '', $svg) ?? $svg;
        // <foreignObject>/<iframe>/<use> bisa menyisipkan HTML/JS atau external ref.
        $svg = preg_replace('#<\s*(foreignObject|iframe|use)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<\s*(foreignObject|iframe|use)\b[^>]*/?>#is', '', $svg) ?? $svg;
        // Atribut event handler on*="..." (onload/onclick/dst).
        $svg = preg_replace('#\son[a-zA-Z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $svg) ?? $svg;
        // href/xlink:href berskema javascript: (SVG <a>).
        $svg = preg_replace('#\s(?:xlink:)?href\s*=\s*("|\')?\s*javascript:[^"\'>\s]*("|\')?#is', '', $svg) ?? $svg;

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
    private function substitute(string $template, array $values, bool $keepUnknown = false, array $rawHtmlKeys = []): string
    {
        $rawSet = array_flip($rawHtmlKeys);
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            static function (array $m) use ($values, $keepUnknown, $rawSet): string {
                $key = $m[1];
                if (array_key_exists($key, $values)) {
                    $val = (string) $values[$key];
                    // Nilai HTML sengaja (image_url) lolos; teks bebas di-escape agar
                    // markup yang diketik user tidak dieksekusi (XSS di v-html / SSRF
                    // via <img> saat dompdf render). \n dipertahankan (white-space:pre-line).
                    return array_key_exists($key, $rawSet)
                        ? $val
                        : htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
                }
                return $keepUnknown ? $m[0] : '';
            },
            $template
        ) ?? $template;
    }
}
