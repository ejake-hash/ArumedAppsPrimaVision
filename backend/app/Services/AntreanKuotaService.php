<?php

namespace App\Services;

use App\Models\AntreanKuota;
use App\Models\AntreanSpm;
use App\Models\DoctorSchedule;
use App\Models\Visit;

/**
 * Resolver kuota & SPM antrean — sumber data field BPJS Antrol:
 *   - kuotajkn / sisakuotajkn / kuotanonjkn / sisakuotanonjkn (antrean/add)
 *   - estimasidilayani (antrean/add)        : now + spm_detik * sisa_antrean
 *   - waktutunggu (Sisa Antrean)            : spm_detik * (sisa_antrean - 1)
 *
 * Tidak menyentuh BPJS — murni hitung dari data lokal. Aman dipanggil
 * non-blocking dari AntrolBuilderService maupun endpoint Mobile JKN (Sisi B).
 */
class AntreanKuotaService
{
    /** Fallback bila tidak ada master kuota sama sekali. */
    private const DEFAULT_KUOTA_JKN    = 30;
    private const DEFAULT_KUOTA_NONJKN = 30;

    /** Fallback SPM bila tidak ada master SPM (menit per pasien). */
    private const DEFAULT_SPM_MENIT = 15;

    // =========================================================================
    // KUOTA
    // =========================================================================

    /**
     * Plafon kuota [jkn, nonjkn] untuk poli/dokter/tanggal — paling spesifik menang.
     * Urutan: (poli+dokter+tanggal) → (poli+dokter, tanggal NULL) → (poli, dokter NULL) → default.
     */
    public function kuota(string $poliCode, ?string $employeeId, string $tanggal): array
    {
        $base = AntreanKuota::where('poli_code', $poliCode)->where('is_active', true);

        $row = (clone $base)->where('employee_id', $employeeId)->whereDate('tanggal', $tanggal)->first()
            ?? (clone $base)->where('employee_id', $employeeId)->whereNull('tanggal')->first()
            ?? (clone $base)->whereNull('employee_id')->whereNull('tanggal')->first();

        return [
            'jkn'    => (int) ($row->kuota_jkn ?? self::DEFAULT_KUOTA_JKN),
            'nonjkn' => (int) ($row->kuota_nonjkn ?? self::DEFAULT_KUOTA_NONJKN),
        ];
    }

    /** Jumlah antrean terpakai hari itu untuk poli/dokter, dipisah JKN vs non-JKN. */
    public function terpakai(string $poliCode, ?string $employeeId, string $tanggal): array
    {
        $q = Visit::query()
            ->whereDate('visit_date', $tanggal)
            ->whereHas('doctorSchedule', function ($s) use ($poliCode, $employeeId) {
                $s->where('poli_code', $poliCode);
                if ($employeeId) {
                    $s->where('employee_id', $employeeId);
                }
            });

        $jkn    = (clone $q)->where('guarantor_type', 'BPJS')->count();
        $nonjkn = (clone $q)->where('guarantor_type', '!=', 'BPJS')->count();

        return ['jkn' => $jkn, 'nonjkn' => $nonjkn];
    }

    /**
     * Ringkasan kuota lengkap untuk payload BPJS:
     *   [ kuotajkn, sisakuotajkn, kuotanonjkn, sisakuotanonjkn ].
     */
    public function ringkasanKuota(string $poliCode, ?string $employeeId, string $tanggal): array
    {
        $kuota    = $this->kuota($poliCode, $employeeId, $tanggal);
        $terpakai = $this->terpakai($poliCode, $employeeId, $tanggal);

        return [
            'kuotajkn'        => $kuota['jkn'],
            'sisakuotajkn'    => max(0, $kuota['jkn'] - $terpakai['jkn']),
            'kuotanonjkn'     => $kuota['nonjkn'],
            'sisakuotanonjkn' => max(0, $kuota['nonjkn'] - $terpakai['nonjkn']),
        ];
    }

    // =========================================================================
    // SPM & ESTIMASI
    // =========================================================================

    /** Menit per pasien untuk poli/dokter — paling spesifik menang. */
    public function spmMenit(string $poliCode, ?string $employeeId): int
    {
        $base = AntreanSpm::where('poli_code', $poliCode)->where('is_active', true);

        $row = (clone $base)->where('employee_id', $employeeId)->first()
            ?? (clone $base)->whereNull('employee_id')->first();

        return (int) ($row->menit_per_pasien ?? self::DEFAULT_SPM_MENIT);
    }

    /**
     * Estimasi waktu dilayani (epoch milidetik) untuk pasien di posisi $angkaAntrean.
     * estimasi = now + spm_detik * (angkaAntrean - 1).
     */
    public function estimasiDilayaniMs(string $poliCode, ?string $employeeId, int $angkaAntrean): int
    {
        $spmDetik = $this->spmMenit($poliCode, $employeeId) * 60;
        $offset   = max(0, $angkaAntrean - 1) * $spmDetik;

        return (int) (now('Asia/Jakarta')->addSeconds($offset)->valueOf());
    }

    /**
     * Waktu tunggu (detik) untuk Sisa Antrean BPJS = spm_detik * (sisaAntrean - 1).
     */
    public function waktuTungguDetik(string $poliCode, ?string $employeeId, int $sisaAntrean): int
    {
        $spmDetik = $this->spmMenit($poliCode, $employeeId) * 60;

        return max(0, $sisaAntrean - 1) * $spmDetik;
    }
}
