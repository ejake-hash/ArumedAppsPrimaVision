<?php

namespace App\Services;

use App\Support\SpreadsheetHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Penarik data Google Sheet sebagai CSV — TANPA kredensial. Sheet harus
 * dibagikan "Anyone with the link → Viewer" (atau Publish to web). Memakai
 * endpoint gviz: /gviz/tq?tqx=out:csv. Mengembalikan baris terasosiasi
 * (header → value) untuk diolah service pemanggil.
 */
class GoogleSheetCsvService
{
    /**
     * Tarik & parse Sheet menjadi array asosiatif per-baris.
     *
     * @return array{ok:bool,header:array<int,string>,rows:array<int,array<string,string>>,message:?string}
     */
    public function fetchAssoc(?string $sheetUrl, ?string $gid = null): array
    {
        $url = trim((string) $sheetUrl);
        if ($url === '') {
            return ['ok' => false, 'header' => [], 'rows' => [], 'message' => 'URL Sheet kosong'];
        }

        $sheetId = $this->extractSheetId($url);
        if (! $sheetId) {
            return ['ok' => false, 'header' => [], 'rows' => [], 'message' => 'URL Sheet tidak valid (id tidak ditemukan)'];
        }

        $gid = $gid ?: $this->extractGid($url) ?: '0';
        $csvUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv&gid={$gid}";

        try {
            $resp = Http::timeout(30)->get($csvUrl);
        } catch (\Throwable $e) {
            Log::warning('[marketing] gagal tarik Google Sheet', ['url' => $csvUrl, 'err' => $e->getMessage()]);

            return ['ok' => false, 'header' => [], 'rows' => [], 'message' => 'Gagal menghubungi Google: ' . $e->getMessage()];
        }

        // Sheet privat → Google balas 302/HTML login, bukan CSV. Deteksi & skip dengan aman.
        $body = (string) $resp->body();
        if ($resp->failed() || str_contains(strtolower(substr($body, 0, 200)), '<html')) {
            return [
                'ok'      => false,
                'header'  => [],
                'rows'    => [],
                'message' => 'Sheet belum dibagikan publik (anyone-with-link) atau tidak dapat diakses.',
            ];
        }

        $records = SpreadsheetHelper::parseCsvRecords($body);
        if (count($records) < 1) {
            return ['ok' => true, 'header' => [], 'rows' => [], 'message' => 'Sheet kosong'];
        }

        $header = array_map(fn ($h) => trim((string) $h), array_shift($records));
        $rows = [];
        foreach ($records as $rec) {
            $assoc = [];
            foreach ($header as $i => $key) {
                $assoc[$key !== '' ? $key : "col_{$i}"] = isset($rec[$i]) ? trim((string) $rec[$i]) : '';
            }
            $rows[] = $assoc;
        }

        return ['ok' => true, 'header' => $header, 'rows' => $rows, 'message' => null];
    }

    /** Hash baris stabil (idempotensi sync). */
    public function rowHash(array $assoc): string
    {
        ksort($assoc);

        return hash('sha256', json_encode($assoc, JSON_UNESCAPED_UNICODE));
    }

    private function extractSheetId(string $url): ?string
    {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractGid(string $url): ?string
    {
        // Delimiter '~' agar tidak bentrok dgn '#' di dalam character class.
        if (preg_match('~[?&#]gid=([0-9]+)~', $url, $m)) {
            return $m[1];
        }

        return null;
    }
}
