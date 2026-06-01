<?php

namespace App\Services;

use App\Models\BpjsControlLetter;
use App\Models\BpjsPoliMapping;
use App\Models\BpjsReferralIn;
use App\Models\Queue;
use App\Models\Visit;
use Illuminate\Support\Str;

/**
 * Penyusun payload BPJS Antrean (antrean/add) + generator kodebooking — murni
 * dari data lokal. TIDAK menyentuh BPJS / jaringan, jadi aman dipanggil meski
 * bridging belum aktif (mengikuti prinsip non-blocking, lihat QueueService).
 *
 * Sumber field payload: Docs/Antrol.md:238-262.
 */
class AntrolBuilderService
{
    public function __construct(private readonly AntreanKuotaService $kuota) {}

    // =========================================================================
    // KODEBOOKING
    // =========================================================================

    /**
     * Generate kodebooking unik & deterministik-aman: {kodepoliBPJS|RS}{YYMMDD}{6 hex}.
     * Kodepoli BPJS dipakai bila terpetakan; jika tidak, fallback "RS".
     * Contoh: "MAT2605313F9A1C".
     */
    public function generateKodebooking(Visit $visit): string
    {
        $schedule = $visit->doctorSchedule;
        $bpjsPoli = $schedule ? BpjsPoliMapping::bpjsCodeFor($schedule->poli_code) : null;
        $prefix   = $bpjsPoli ?: 'RS';
        $tgl      = ($visit->visit_date ?? now('Asia/Jakarta'))->format('ymd');
        $rand     = strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 6));

        return "{$prefix}{$tgl}{$rand}";
    }

    /**
     * Pastikan visit punya kodebooking. Generate & simpan bila kosong (lokal,
     * tidak tergantung BPJS). Idempoten — kalau sudah ada, kembalikan apa adanya.
     */
    public function ensureKodebooking(Visit $visit): string
    {
        if (! empty($visit->bpjs_booking_code)) {
            return $visit->bpjs_booking_code;
        }

        $code = $this->generateKodebooking($visit);
        $visit->forceFill(['bpjs_booking_code' => $code])->save();

        return $code;
    }

    // =========================================================================
    // PAYLOAD antrean/add
    // =========================================================================

    /**
     * Susun payload lengkap antrean/add dari Visit. Mengembalikan null bila data
     * inti belum cukup untuk lapor BPJS (poli belum dipetakan / dokter tanpa kode
     * DPJP) — caller cukup skip (tidak fatal).
     *
     * @return array<string,mixed>|null
     */
    public function buildAddPayload(Visit $visit): ?array
    {
        $visit->loadMissing(['patient', 'doctorSchedule.employee']);

        $patient  = $visit->patient;
        $schedule = $visit->doctorSchedule;
        if (! $patient || ! $schedule) {
            return null;
        }

        $bpjsPoli = BpjsPoliMapping::bpjsCodeFor($schedule->poli_code);
        $dpjp     = $schedule->employee?->bpjs_dpjp_code;
        if (! $bpjsPoli || ! $dpjp) {
            return null; // belum bisa lapor: butuh mapping poli + kode DPJP
        }

        $isBpjs        = $visit->guarantor_type === 'BPJS';
        $tanggal       = ($visit->visit_date ?? now('Asia/Jakarta'))->format('Y-m-d');
        $kodebooking   = $this->ensureKodebooking($visit);
        $queue         = $this->resolveQueue($visit);
        $angkaAntrean  = (int) ($queue->queue_sequence ?? 1);

        $kuota = $this->kuota->ringkasanKuota($schedule->poli_code, $schedule->employee_id, $tanggal);

        return [
            'kodebooking'      => $kodebooking,
            'jenispasien'      => $isBpjs ? 'JKN' : 'NON JKN',
            'nomorkartu'       => $isBpjs ? (string) ($patient->bpjs_number ?? '') : '',
            'nik'              => (string) ($patient->nik ?? ''),
            'nohp'             => (string) ($patient->phone ?? ''),
            'kodepoli'         => $bpjsPoli,
            'namapoli'         => (string) ($schedule->poliklinik ?? ''),
            'pasienbaru'       => $this->isPasienBaru($visit) ? 1 : 0,
            'norm'             => (string) ($patient->no_rm ?? ''),
            'tanggalperiksa'   => $tanggal,
            'kodedokter'       => $dpjp,
            'namadokter'       => (string) ($schedule->employee?->name ?? ''),
            'jampraktek'       => $this->jamPraktek($schedule),
            'jeniskunjungan'   => $this->jenisKunjungan($visit),
            'nomorreferensi'   => $isBpjs ? $this->nomorReferensi($visit) : '',
            'nomorantrean'     => (string) ($queue->queue_number ?? ''),
            'angkaantrean'     => $angkaAntrean,
            'estimasidilayani' => $this->kuota->estimasiDilayaniMs($schedule->poli_code, $schedule->employee_id, $angkaAntrean),
            'sisakuotajkn'     => $kuota['sisakuotajkn'],
            'kuotajkn'         => $kuota['kuotajkn'],
            'sisakuotanonjkn'  => $kuota['sisakuotanonjkn'],
            'kuotanonjkn'      => $kuota['kuotanonjkn'],
            'keterangan'       => 'Peserta harap 30 menit lebih awal guna pencatatan administrasi.',
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Pasien baru bila ini satu-satunya visit-nya (belum punya riwayat lain). */
    public function isPasienBaru(Visit $visit): bool
    {
        return Visit::where('patient_id', $visit->patient_id)
            ->where('id', '!=', $visit->id)
            ->count() === 0;
    }

    /** Queue paling awal milik visit (acuan nomor antrean awal — TR/A). */
    private function resolveQueue(Visit $visit): ?Queue
    {
        return Queue::where('visit_id', $visit->id)
            ->orderBy('created_at')
            ->first();
    }

    private function jamPraktek($schedule): string
    {
        $start = substr((string) $schedule->start_time, 0, 5);
        $end   = substr((string) $schedule->end_time, 0, 5);

        return ($start && $end) ? "{$start}-{$end}" : '';
    }

    /**
     * jeniskunjungan BPJS: 1 Rujukan FKTP, 2 Rujukan Internal, 3 Kontrol, 4 Rujukan Antar RS.
     * Dipetakan dari konteks visit: kontrol (punya control letter) → 3; rujukan
     * internal antar-poli → 2; default rujukan FKTP → 1.
     */
    public function jenisKunjungan(Visit $visit): int
    {
        if (! empty($visit->bpjs_control_letter_id)) {
            return 3; // Kontrol
        }
        if (! empty($visit->parent_visit_id) || ! empty($visit->internal_referral_from_schedule_id)) {
            return 2; // Rujukan Internal
        }

        return 1; // Rujukan FKTP (default RJ)
    }

    /** Nomor rujukan/kontrol BPJS (kosong jika tidak ada). */
    public function nomorReferensi(Visit $visit): string
    {
        if (! empty($visit->bpjs_control_letter_id)) {
            $no = BpjsControlLetter::whereKey($visit->bpjs_control_letter_id)->value('no_surat_kontrol');
            if ($no) {
                return (string) $no;
            }
        }

        $no = $visit->bpjs_referral_in_id
            ? BpjsReferralIn::whereKey($visit->bpjs_referral_in_id)->value('no_rujukan')
            : BpjsReferralIn::where('visit_id', $visit->id)->value('no_rujukan');

        return (string) ($no ?? '');
    }
}
