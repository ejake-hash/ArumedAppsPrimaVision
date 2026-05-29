<?php

namespace App\Services\FormRegistry;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;

/**
 * Parse file .docx → draft JSON Form Registry.
 *
 * Output struktur:
 *   {
 *     parse_id: "fp_xxxx",      — id untuk poll endpoint (cache 1 jam)
 *     status:   "READY"|"ERROR",
 *     warnings: string[],
 *     draft: {
 *       suggested_code: "...",
 *       suggested_name: "...",
 *       fields: [
 *         { key, label, type, binding_suggestion: { tier, confidence, suggestions:[] } }
 *       ],
 *       layout_html: "<div>…</div>"
 *     }
 *   }
 *
 * Scope Fase 2 — heuristik tabel 2-kolom (label : value), paragraf statis,
 * tandatangan detection. Tabel nested, multi-page, scored radio belum dipreserve
 * sepenuhnya (warnings dilempar). Field kompleks tetap diparse jadi `text`
 * default — admin bisa edit di mapper UI.
 */
final class FormParserService
{
    private const CACHE_PREFIX = 'form_parser_result:';
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly BindingSuggester $suggester,
    ) {}

    /**
     * Parse file .docx (sync) dan simpan hasil ke cache. Return parse_id.
     */
    public function parse(string $absoluteFilePath, ?string $originalFileName = null): string
    {
        if (!is_file($absoluteFilePath)) {
            throw new RuntimeException("File tidak ditemukan: {$absoluteFilePath}");
        }

        $warnings = [];
        $fields = [];
        $layoutFragments = [];

        $ext = strtolower(pathinfo($originalFileName ?? $absoluteFilePath, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            // Best-effort PDF text extraction → tiap baris non-kosong jadi
            // field text default. Admin WAJIB review hasil di mapper UI.
            $this->parsePdf($absoluteFilePath, $fields, $layoutFragments, $warnings);
        } else {
            try {
                $phpWord = IOFactory::load($absoluteFilePath);
            } catch (\Throwable $e) {
                throw new RuntimeException('Gagal memuat .docx: ' . $e->getMessage(), 0, $e);
            }

            foreach ($phpWord->getSections() as $section) {
                $this->walkSection($section, $fields, $layoutFragments, $warnings);
            }
        }

        // Suggest binding untuk semua field yang punya label.
        foreach ($fields as &$field) {
            $field['binding_suggestion'] = $this->suggester->suggestForLabel($field['label']);
        }
        unset($field);

        $draft = [
            'suggested_code' => $this->suggestCode($originalFileName ?? basename($absoluteFilePath), $fields),
            'suggested_name' => $this->suggestName($originalFileName ?? basename($absoluteFilePath)),
            'fields'         => array_values($fields),
            'layout_html'    => $this->wrapLayout($layoutFragments),
        ];

        $parseId = 'fp_' . Str::ulid()->toBase32();
        $payload = [
            'parse_id' => $parseId,
            'status'   => 'READY',
            'warnings' => $warnings,
            'draft'    => $draft,
            'created_at' => now()->toIso8601String(),
        ];

        Cache::put(self::CACHE_PREFIX . $parseId, $payload, self::CACHE_TTL_SECONDS);
        return $parseId;
    }

    public function getResult(string $parseId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $parseId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Section walker
    // ─────────────────────────────────────────────────────────────────────────

    private function walkSection(Section $section, array &$fields, array &$layoutFragments, array &$warnings): void
    {
        foreach ($section->getElements() as $el) {
            if ($el instanceof Table) {
                $this->parseTable($el, $fields, $layoutFragments, $warnings);
            } else {
                $text = $this->extractText($el);
                if ($text !== '') {
                    $layoutFragments[] = '<p>' . $this->escapeText($text) . '</p>';
                }
            }
        }
    }

    /**
     * Tabel di Word — heuristik:
     *   - 2 kolom: label kiri (berakhir ":" atau jelas label) → field
     *   - >2 kolom: belum dihandle penuh, render sebagai HTML table statis +
     *     warning
     */
    private function parseTable(Table $table, array &$fields, array &$layoutFragments, array &$warnings): void
    {
        $rows = $table->getRows();
        if (empty($rows)) {
            return;
        }

        $colCount = count($rows[0]->getCells());

        if ($colCount === 2) {
            $tableHtml = '<table style="border-collapse:collapse;width:100%;">';
            foreach ($rows as $row) {
                $cells = $row->getCells();
                $labelRaw = trim($this->extractCellText($cells[0] ?? null));
                $valueRaw = trim($this->extractCellText($cells[1] ?? null));

                if ($labelRaw === '' && $valueRaw === '') {
                    continue;
                }

                $isField = $labelRaw !== '';
                if ($isField) {
                    $label = rtrim($labelRaw, " :\t");
                    $type  = $this->inferFieldType($label, $valueRaw);
                    $key   = $this->slugifyKey($label);

                    // Hindari key duplikat — tambah suffix _2, _3 dst.
                    $finalKey = $key;
                    $i = 2;
                    while (isset($fields[$finalKey])) {
                        $finalKey = $key . '_' . $i++;
                    }
                    $fields[$finalKey] = [
                        'key'     => $finalKey,
                        'label'   => $label,
                        'type'    => $type,
                        'binding' => ['kind' => 'static', 'value' => null],
                    ];

                    $tableHtml .= '<tr>'
                        . '<td style="border:1px solid #999;padding:4px;width:30%;">' . $this->escapeText($label) . '</td>'
                        . '<td style="border:1px solid #999;padding:4px;">{{' . $finalKey . '}}</td>'
                        . '</tr>';
                } else {
                    $tableHtml .= '<tr><td style="border:1px solid #999;padding:4px;" colspan="2">' . $this->escapeText($valueRaw) . '</td></tr>';
                }
            }
            $tableHtml .= '</table>';
            $layoutFragments[] = $tableHtml;
            return;
        }

        // Tabel multi-kolom: render apa adanya, tidak ekstrak field.
        $warnings[] = "Tabel {$colCount}-kolom dideteksi — diparse sebagai layout statis, field harus ditambah manual.";
        $html = '<table style="border-collapse:collapse;width:100%;">';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row->getCells() as $cell) {
                $html .= '<td style="border:1px solid #999;padding:4px;">' . $this->escapeText(trim($this->extractCellText($cell))) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        $layoutFragments[] = $html;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Heuristik field type
    // ─────────────────────────────────────────────────────────────────────────

    private function inferFieldType(string $label, string $valueRaw): string
    {
        $l = mb_strtolower($label, 'UTF-8');

        if (str_contains($l, 'tandatangan') || str_contains($l, 'tanda tangan') || str_contains($l, 'ttd')) {
            return 'signature_canvas';
        }
        if (preg_match('/\btgl\b|tanggal|date/u', $l) === 1) {
            return 'date';
        }
        if (preg_match('/\bjam\b|waktu|time/u', $l) === 1) {
            return 'time';
        }
        if (str_contains($l, 'l/p') || str_contains($l, 'pria/wanita') || str_contains($l, 'jenis kelamin')) {
            return 'enum_gender';
        }
        if (preg_match('/jumlah|qty|berat|tinggi|suhu|tensi|nadi/u', $l) === 1) {
            return 'number';
        }
        if (mb_strlen($valueRaw) > 50 || preg_match('/anamne[s]e|keluhan|riwayat|catatan|diagnosa|teknik|instruksi/u', $l) === 1) {
            return 'longtext';
        }
        return 'text';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Text extraction helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function extractText(mixed $el): string
    {
        if (is_null($el)) {
            return '';
        }
        if (method_exists($el, 'getText')) {
            $t = $el->getText();
            if (is_string($t)) {
                return trim($t);
            }
        }
        if ($el instanceof TextRun) {
            $parts = [];
            foreach ($el->getElements() as $child) {
                $parts[] = $this->extractText($child);
            }
            return trim(implode(' ', array_filter($parts)));
        }
        if (method_exists($el, 'getElements')) {
            $parts = [];
            foreach ($el->getElements() as $child) {
                $parts[] = $this->extractText($child);
            }
            return trim(implode(' ', array_filter($parts)));
        }
        return '';
    }

    private function extractCellText(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }
        $parts = [];
        foreach ($cell->getElements() as $child) {
            $parts[] = $this->extractText($child);
        }
        return trim(implode(' ', array_filter($parts)));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Misc
    // ─────────────────────────────────────────────────────────────────────────

    private function slugifyKey(string $label): string
    {
        $slug = Str::slug($label, '_');
        // Pastikan diawali huruf (validasi placeholder regex).
        if ($slug === '' || preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 'f_' . ($slug === '' ? Str::random(6) : $slug);
        }
        return mb_substr($slug, 0, 60, 'UTF-8');
    }

    private function suggestCode(string $sourceName, array $fields): string
    {
        $base = pathinfo($sourceName, PATHINFO_FILENAME);
        $upper = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $base) ?? '');
        $upper = trim($upper, '_');
        if ($upper === '') {
            $upper = 'FORM_' . strtoupper(Str::random(6));
        }
        return mb_substr($upper, 0, 50, 'UTF-8');
    }

    private function suggestName(string $sourceName): string
    {
        $base = pathinfo($sourceName, PATHINFO_FILENAME);
        $clean = preg_replace('/[_\-]+/', ' ', $base) ?? $base;
        return ucwords(trim($clean));
    }

    private function escapeText(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param list<string> $fragments */
    private function wrapLayout(array $fragments): string
    {
        if (empty($fragments)) {
            return '<div style="font-family: Arial, sans-serif; padding: 24px;"></div>';
        }
        return '<div style="font-family: Arial, sans-serif; padding: 24px;">' . implode("\n", $fragments) . '</div>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PDF parser (best-effort text extraction)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Best-effort PDF parser tanpa dependency baru — pakai PdfParser kalau
     * library terinstall, fallback ke regex stream extraction. Hasil PDF
     * jauh lebih kasar dibanding .docx (no table structure). Admin WAJIB
     * review & lengkapi binding di mapper UI.
     */
    private function parsePdf(string $absoluteFilePath, array &$fields, array &$layoutFragments, array &$warnings): void
    {
        $warnings[] = 'PDF parsing bersifat best-effort — struktur tabel/layout tidak terdeteksi seperti .docx. '
            . 'Review dan lengkapi field secara manual di mapper UI.';

        $text = $this->extractPdfText($absoluteFilePath, $warnings);
        if ($text === '') {
            $warnings[] = 'Tidak ada teks terdeteksi di PDF. Kemungkinan file scan/image — gunakan OCR atau convert ke .docx manual.';
            return;
        }

        // Pecah per baris non-kosong. Setiap baris dianggap label calon field
        // kalau berakhir ":" atau punya pola "Label: value" (1-baris). Lainnya
        // jadi paragraf statis di layout.
        $usedKeys = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $isField = false;
            $label   = $line;
            $value   = '';

            if (preg_match('/^(.+?)\s*:\s*(.*)$/u', $line, $m)) {
                $label   = trim($m[1]);
                $value   = trim($m[2]);
                $isField = $label !== '' && mb_strlen($label) <= 80;
            } elseif (str_ends_with($line, ':') && mb_strlen($line) <= 80) {
                $label   = rtrim($line, ' :');
                $isField = $label !== '';
            }

            if ($isField) {
                $type = $this->inferFieldType($label, $value);
                $key  = $this->slugifyKey($label);
                $final = $key;
                $i = 2;
                while (isset($usedKeys[$final])) {
                    $final = $key . '_' . $i++;
                }
                $usedKeys[$final] = true;

                $fields[$final] = [
                    'key'     => $final,
                    'label'   => $label,
                    'type'    => $type,
                    'required'=> false,
                    'binding' => ['kind' => 'static', 'value' => $value],
                ];
                $layoutFragments[] = '<p>' . $this->escapeText($label) . ': &#123;&#123;' . $final . '&#125;&#125;</p>';
            } else {
                $layoutFragments[] = '<p>' . $this->escapeText($line) . '</p>';
            }
        }
    }

    /**
     * Extract text dari PDF. Coba PdfParser dulu (kalau ada), fallback regex.
     * Regex ekstraksi pattern `(text)Tj` & `(text)TJ` — bekerja untuk PDF
     * teks-baru (non-encoded). PDF terkompres/encrypted → mostly empty.
     */
    private function extractPdfText(string $path, array &$warnings): string
    {
        if (class_exists('Smalot\\PdfParser\\Parser')) {
            try {
                $parserClass = 'Smalot\\PdfParser\\Parser';
                $parser = new $parserClass();
                $pdf = $parser->parseFile($path);
                return (string) $pdf->getText();
            } catch (\Throwable $e) {
                $warnings[] = 'PdfParser error: ' . $e->getMessage() . ' — fallback ke regex extractor.';
            }
        }

        // Fallback: raw scan untuk pattern `(...)Tj`. Tidak handle UTF-16 BOM
        // atau encrypted content. Akurasi rendah, tapi tetap memberi sinyal awal.
        $raw = @file_get_contents($path);
        if ($raw === false) return '';

        $chunks = [];
        if (preg_match_all('/\(((?:\\\\.|[^()\\\\])*)\)\s*T[jJ]/u', $raw, $m)) {
            foreach ($m[1] as $chunk) {
                $decoded = stripcslashes($chunk);
                if (trim($decoded) !== '') {
                    $chunks[] = $decoded;
                }
            }
        }
        if (empty($chunks)) {
            $warnings[] = 'PDF text extractor tidak menemukan teks decodable. Install `smalot/pdfparser` via Composer untuk hasil lebih baik.';
            return '';
        }
        return implode("\n", $chunks);
    }
}
