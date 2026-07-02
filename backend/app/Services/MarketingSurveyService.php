<?php

namespace App\Services;

use App\Models\IntegrationConfig;
use App\Models\MarketingSurveyResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Survei Kepuasan — sinkron tanggapan dari Google Sheet (lewat GoogleSheetCsvService)
 * + agregasi untuk dashboard FE. Deteksi kolom tanggal/skor/nama secara heuristik
 * sehingga tahan terhadap variasi judul kolom Google Form.
 */
class MarketingSurveyService
{
    public function __construct(private readonly GoogleSheetCsvService $sheets) {}

    /** system_name di IntegrationConfig untuk simpan URL Sheet (override env). */
    private const CONFIG_KEY = 'MARKETING_GOOGLE';

    /** URL Sheet survei efektif: DB (IntegrationConfig) → fallback env config. */
    public function getSheetUrl(): string
    {
        $db = IntegrationConfig::where('system_name', self::CONFIG_KEY)->value('base_url');

        return trim((string) ($db ?: config('marketing.survey_sheet_url')));
    }

    /** Simpan URL Sheet survei ke DB (dipakai UI konfigurasi). */
    public function setSheetUrl(?string $url): void
    {
        IntegrationConfig::updateOrCreate(
            ['system_name' => self::CONFIG_KEY],
            ['base_url' => $url ? trim($url) : null, 'is_enabled' => (bool) $url],
        );
    }

    private const DATE_HINTS  = ['timestamp', 'stempel waktu', 'tanggal', 'waktu'];
    private const NAME_HINTS  = ['nama'];
    private const SCORE_HINTS = ['skor', 'rating', 'nilai', 'kepuasan', 'puas', 'score', 'bintang'];

    /**
     * Tarik Sheet survei (config marketing.survey_sheet_url) dan upsert tanggapan.
     *
     * @return array{ok:bool,fetched:int,inserted:int,message:?string}
     */
    public function sync(?string $sheetUrl = null): array
    {
        $url = $sheetUrl ?: $this->getSheetUrl();
        $res = $this->sheets->fetchAssoc($url);

        if (! $res['ok']) {
            Log::info('[marketing] sync survei dilewati', ['message' => $res['message']]);

            return ['ok' => false, 'fetched' => 0, 'inserted' => 0, 'message' => $res['message']];
        }

        $header   = $res['header'];
        $dateKey  = $this->matchKey($header, self::DATE_HINTS);
        $nameKey  = $this->matchKey($header, self::NAME_HINTS);

        // Kolom skor dideteksi BERBASIS NILAI (kolom yang mayoritas nilainya angka
        // 1..10 = pertanyaan Likert) — bukan keyword header, supaya tahan terhadap
        // form apa pun. Skor per-responden = rata-rata semua kolom Likert tsb.
        $scoreCols = $this->detectScoreColumns($res['rows'], array_filter([$dateKey, $nameKey]));

        $inserted = 0;
        $now = Carbon::now();
        foreach ($res['rows'] as $assoc) {
            $hash = $this->sheets->rowHash($assoc);

            // updateOrCreate (bukan firstOrCreate) supaya baris lama ikut terkoreksi
            // saat logika skor/kolom berubah; "baru" = wasRecentlyCreated.
            $row = MarketingSurveyResponse::updateOrCreate(
                ['row_hash' => $hash],
                [
                    'submitted_at'    => $this->parseDate($dateKey ? ($assoc[$dateKey] ?? null) : null),
                    'respondent_name' => $nameKey ? ($assoc[$nameKey] ?? null) : null,
                    'score'           => $this->avgOver($assoc, $scoreCols),
                    'payload'         => $assoc,
                    'synced_at'       => $now,
                ]
            );

            if ($row->wasRecentlyCreated) {
                $inserted++;
            }
        }

        return ['ok' => true, 'fetched' => count($res['rows']), 'inserted' => $inserted, 'message' => null];
    }

    /**
     * Agregasi untuk FE: KPI (rata-rata skor, total responden), tren harian, baris terbaru.
     *
     * @return array<string,mixed>
     */
    public function getReport(?string $from, ?string $to, int $recentLimit = 100): array
    {
        $rows = MarketingSurveyResponse::query()
            ->when($from, fn ($x) => $x->whereDate('submitted_at', '>=', $from))
            ->when($to, fn ($x) => $x->whereDate('submitted_at', '<=', $to))
            ->orderByDesc('submitted_at')
            ->get(['id', 'submitted_at', 'respondent_name', 'score', 'payload']);

        $total = $rows->count();
        $scored = $rows->whereNotNull('score');
        $avg = $scored->count() ? $scored->avg('score') : null;

        // Kolom-kolom dari payload (union) + deteksi peran tiap kolom.
        $payloads = $rows->pluck('payload')->filter()->all();
        $dateKey  = $this->matchKey($this->unionKeys($payloads), self::DATE_HINTS);
        $nameKey  = $this->matchKey($this->unionKeys($payloads), self::NAME_HINTS);
        $scoreCols = $this->detectScoreColumns($payloads, array_filter([$dateKey, $nameKey]));

        // ── Dashboard: rata-rata per aspek (tiap kolom Likert) ──
        $aspects = [];
        foreach ($scoreCols as $col) {
            $vals = [];
            foreach ($payloads as $p) {
                $v = $p[$col] ?? '';
                if (is_numeric($v)) {
                    $vals[] = (float) $v;
                }
            }
            if ($vals) {
                $aspects[] = [
                    'label' => $this->aspectLabel($col),
                    'full'  => $col,
                    'avg'   => round(array_sum($vals) / count($vals), 2),
                    'count' => count($vals),
                ];
            }
        }
        usort($aspects, fn ($a, $b) => $a['avg'] <=> $b['avg']); // menaik → aspek terlemah di atas (bar horizontal)

        // ── Distribusi skor (1..N) ──
        // Ukuran bucket mengikuti skor maksimum teramati (min 5): skala Likert 1–10 tak
        // lagi membuang skor 6–10 (dulu hardcode 1..5 → total distribusi ≠ total responden).
        $maxScore = max(5, (int) collect($rows)->max('score'));
        $distribution = array_fill(1, $maxScore, 0);
        foreach ($rows as $r) {
            $s = (int) $r->score;
            if (isset($distribution[$s])) {
                $distribution[$s]++;
            }
        }

        // ── Tren harian ──
        $trendMap = [];
        foreach ($rows as $r) {
            if (! $r->submitted_at) {
                continue;
            }
            $d = $r->submitted_at->format('Y-m-d');
            $trendMap[$d] ??= ['sum' => 0, 'n' => 0, 'tot' => 0];
            $trendMap[$d]['tot']++;
            if ($r->score !== null) {
                $trendMap[$d]['sum'] += $r->score;
                $trendMap[$d]['n']++;
            }
        }
        ksort($trendMap);
        $trend = [];
        foreach ($trendMap as $d => $v) {
            $trend[] = [
                'tgl'       => $d,
                'total'     => $v['tot'],
                'avg_score' => $v['n'] ? round($v['sum'] / $v['n'], 2) : null,
            ];
        }

        // ── Kolom tampil tabel (buang junk col_*, kolom skor, tanggal, nama) ──
        $displayCols = $this->pickDisplayColumns($this->unionKeys($payloads), array_merge(array_filter([$dateKey, $nameKey]), $scoreCols));

        $recent = $rows->take($recentLimit)->map(fn ($r) => [
            'id'           => $r->id,
            'submitted_at' => optional($r->submitted_at)->format('Y-m-d H:i'),
            'nama'         => $r->respondent_name,
            'score'        => $r->score,
            'payload'      => $r->payload,
        ])->values()->all();

        return [
            'configured'  => $this->getSheetUrl() !== '',
            'sheet_url'   => $this->getSheetUrl(),
            'kpi'         => [
                'total_responden' => $total,
                'avg_score'       => $avg !== null ? round((float) $avg, 2) : null,
                'aspek_dinilai'   => count($aspects),
            ],
            'aspects'      => $aspects,
            'distribution' => array_values($distribution), // [n1,n2,n3,n4,n5]
            'trend'        => $trend,
            'columns'      => $displayCols, // [{key,label}]
            'recent'       => $recent,
            'periode'      => ['from' => $from, 'to' => $to],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function matchKey(array $header, array $hints): ?string
    {
        foreach ($header as $h) {
            $lo = mb_strtolower($h);
            foreach ($hints as $hint) {
                if (str_contains($lo, $hint)) {
                    return $h;
                }
            }
        }

        return null;
    }

    /** Gabungan semua key dari sekumpulan payload (urutan jsonb tak terjamin). */
    private function unionKeys(array $payloads): array
    {
        $keys = [];
        foreach ($payloads as $p) {
            foreach (array_keys((array) $p) as $k) {
                $keys[$k] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Kolom skor = kolom yang ≥70% nilainya (non-kosong) berupa angka 1..10
     * (skala Likert). Tahan terhadap form apa pun; mengecualikan kolom tanggal/nama.
     *
     * @param array<int,array<string,string>> $rows
     * @param array<int,string> $excludeKeys
     * @return array<int,string>
     */
    private function detectScoreColumns(array $rows, array $excludeKeys): array
    {
        if (empty($rows)) {
            return [];
        }

        $out = [];
        foreach ($this->unionKeys($rows) as $k) {
            if ($k === '' || in_array($k, $excludeKeys, true) || preg_match('/^col_\d+$/', $k)) {
                continue;
            }
            $num = 0;
            $tot = 0;
            foreach ($rows as $r) {
                $v = $r[$k] ?? '';
                if (trim((string) $v) === '') {
                    continue;
                }
                $tot++;
                if (is_numeric($v) && (float) $v >= 1 && (float) $v <= 10) {
                    $num++;
                }
            }
            if ($tot > 0 && ($num / $tot) >= 0.7) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /** Rata-rata nilai numerik satu baris atas kolom-kolom tertentu (null bila kosong). */
    private function avgOver(array $row, array $cols): ?int
    {
        $vals = [];
        foreach ($cols as $c) {
            $v = $row[$c] ?? '';
            if (is_numeric($v)) {
                $vals[] = (float) $v;
            }
        }

        if (empty($vals)) {
            return null;
        }

        return (int) round(array_sum($vals) / count($vals));
    }

    /** Label aspek ringkas dari pertanyaan panjang (buang basa-basi, potong). */
    private function aspectLabel(string $q): string
    {
        $q = trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', $q)));
        // Buang awalan umum & nama RS agar label fokus ke aspeknya.
        $q = preg_replace('/^(Bagaimana|Apakah|Apa)\s+(penilaian Anda terhadap|tingkat)?\s*/i', '', $q);
        $q = preg_replace('/\s*(di|pada)?\s*RSK\.?\s*Mata\s*Prima\s*Vision\??/i', '', $q);
        $q = rtrim($q, " ?.");
        if (mb_strlen($q) > 50) {
            $q = mb_substr($q, 0, 48) . '…';
        }

        return ucfirst($q ?: 'Aspek');
    }

    /**
     * Kolom tampil tabel: buang junk (col_*), kolom kosong, dan kolom yang sudah
     * diringkas (tanggal/nama/skor). Prioritaskan kritik/saran lalu dokter.
     * @return array<int,array{key:string,label:string}>
     */
    private function pickDisplayColumns(array $keys, array $exclude): array
    {
        $cand = [];
        foreach ($keys as $k) {
            if ($k === '' || preg_match('/^col_\d+$/', $k) || in_array($k, $exclude, true)) {
                continue;
            }
            $cand[] = $k;
        }

        // Prioritas: saran/kritik/masukan/komentar → dokter → sisanya.
        $priority = fn ($k) => match (true) {
            (bool) preg_match('/saran|kritik|masukan|komentar|feedback/i', $k) => 0,
            (bool) preg_match('/dokter/i', $k) => 1,
            default => 2,
        };
        usort($cand, fn ($a, $b) => $priority($a) <=> $priority($b));

        return array_map(fn ($k) => ['key' => $k, 'label' => $this->aspectLabel($k)], array_slice($cand, 0, 4));
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            // Format Google ID lokal "26/06/2026 14:30:05" → coba d/m/Y.
            try {
                return Carbon::createFromFormat('d/m/Y H:i:s', $raw);
            } catch (\Throwable $e2) {
                return null;
            }
        }
    }
}
