<?php

namespace App\Services;

/**
 * Early Warning Score (EWS) — varian NEWS2 (Royal College of Physicians) yang
 * disesuaikan dengan parameter tanda vital yang TERSEDIA di CPPT rawat inap.
 *
 * NEWS2 penuh butuh 7 parameter (RR, SpO2 + skala, suplemen O2, TD sistol, nadi,
 * suhu, tingkat kesadaran ACVPU). Sistem ini baru menyimpan 5 dari triase/CPPT
 * (RR, SpO2, suhu, TD sistol, nadi) — maka skor dihitung dari ke-5 parameter itu
 * (EWS parsial). Kesadaran & suplemen O2 belum tercatat → diabaikan (skor 0).
 *
 * Murni hitung (stateless), dipanggil saat membaca CPPT (calc-on-read) — tanpa
 * tabel/penyimpanan. Dukungan STARKES PAP 3.1 (deteksi perburukan kondisi).
 */
class EwsCalculator
{
    /** Minimal parameter terisi agar skor bermakna ditampilkan. */
    private const MIN_PARAMS = 3;

    /**
     * @param  array  $v  ['respirasi','spo2','suhu','td_sistol','nadi'] (boleh null sebagian)
     * @return array|null  ['score','level','label','params','single_max'] atau null bila data kurang
     */
    public static function calculate(array $v): ?array
    {
        $parts = [];

        if (self::has($v, 'respirasi')) $parts[] = self::scoreRespirasi((float) $v['respirasi']);
        if (self::has($v, 'spo2'))      $parts[] = self::scoreSpo2((float) $v['spo2']);
        if (self::has($v, 'suhu'))      $parts[] = self::scoreSuhu((float) $v['suhu']);
        if (self::has($v, 'td_sistol')) $parts[] = self::scoreSistol((float) $v['td_sistol']);
        if (self::has($v, 'nadi'))      $parts[] = self::scoreNadi((float) $v['nadi']);

        if (count($parts) < self::MIN_PARAMS) {
            return null;
        }

        $score = array_sum($parts);
        $singleMax = max($parts);
        $level = self::band($score, $singleMax);

        return [
            'score'      => $score,
            'level'      => $level,                       // HIJAU | KUNING | MERAH
            'label'      => self::levelLabel($level),     // Rendah | Waspada/Sedang | Tinggi
            'params'     => count($parts),                // jumlah parameter terpakai (dari 5)
            'single_max' => $singleMax,                   // skor parameter tunggal tertinggi
        ];
    }

    private static function has(array $v, string $k): bool
    {
        return isset($v[$k]) && $v[$k] !== '' && is_numeric($v[$k]) && (float) $v[$k] > 0;
    }

    /** Banding skor → level risiko (mengikuti respons klinis NEWS2). */
    private static function band(int $score, int $singleMax): string
    {
        if ($score >= 7) return 'MERAH';
        if ($score >= 5) return 'KUNING';
        if ($singleMax >= 3) return 'KUNING'; // parameter tunggal merah → tetap waspada
        return 'HIJAU';
    }

    private static function levelLabel(string $level): string
    {
        return ['HIJAU' => 'Rendah', 'KUNING' => 'Waspada', 'MERAH' => 'Tinggi'][$level] ?? '-';
    }

    private static function scoreRespirasi(float $rr): int
    {
        if ($rr <= 8) return 3;
        if ($rr <= 11) return 1;
        if ($rr <= 20) return 0;
        if ($rr <= 24) return 2;
        return 3;
    }

    private static function scoreSpo2(float $spo2): int
    {
        if ($spo2 <= 91) return 3;
        if ($spo2 <= 93) return 2;
        if ($spo2 <= 95) return 1;
        return 0;
    }

    private static function scoreSuhu(float $t): int
    {
        if ($t <= 35.0) return 3;
        if ($t <= 36.0) return 1;
        if ($t <= 38.0) return 0;
        if ($t <= 39.0) return 1;
        return 2;
    }

    private static function scoreSistol(float $s): int
    {
        if ($s <= 90) return 3;
        if ($s <= 100) return 2;
        if ($s <= 110) return 1;
        if ($s <= 219) return 0;
        return 3;
    }

    private static function scoreNadi(float $hr): int
    {
        if ($hr <= 40) return 3;
        if ($hr <= 50) return 1;
        if ($hr <= 90) return 0;
        if ($hr <= 110) return 1;
        if ($hr <= 130) return 2;
        return 3;
    }
}
