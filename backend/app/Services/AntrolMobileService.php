<?php

namespace App\Services;

use App\Models\AntreanBooking;
use App\Models\BpjsPoliMapping;
use App\Models\DoctorSchedule;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Logika endpoint WS Antrean sisi RS (Sisi B — dipanggil Mobile JKN).
 * Tiap method mengembalikan ['code'=>int, 'message'=>string, 'response'=>mixed]
 * (di-bungkus envelope BPJS oleh AntrolMobileController).
 *
 * Strategi "Ambil Antrean": buat RESERVASI di antrean_bookings (BUKAN Visit/Queue).
 * Visit & antrean fisik dibuat saat check-in (B6) / petugas admisi. Aman terhadap
 * no-show. Semua perhitungan murni lokal; tidak memanggil balik BPJS.
 */
class AntrolMobileService
{
    public function __construct(private readonly AntreanKuotaService $kuota) {}

    // =========================================================================
    // B2 — STATUS ANTREAN (perencanaan kedatangan)
    //   Request: { kodepoli, kodedokter, tanggalperiksa, jampraktek }
    // =========================================================================
    public function statusAntrean(array $data): array
    {
        $sched = $this->resolveSchedule($data['kodepoli'] ?? null, $data['kodedokter'] ?? null, $data['jampraktek'] ?? null);
        if (! $sched) {
            return $this->fail('Poli/dokter tidak ditemukan atau belum dipetakan.');
        }

        $tanggal = $this->normalizeDate($data['tanggalperiksa'] ?? null);
        $stat    = $this->statistik($sched, $tanggal);
        $kuota   = $this->kuota->ringkasanKuota($sched->poli_code, $sched->employee_id, $tanggal);

        return $this->ok([
            'namapoli'        => $sched->poliklinik,
            'namadokter'      => $sched->employee?->name,
            'totalantrean'    => $stat['total'],
            'sisaantrean'     => $stat['sisa'],
            'antreanpanggil'  => $stat['panggil'],
            'sisakuotajkn'    => $kuota['sisakuotajkn'],
            'kuotajkn'        => $kuota['kuotajkn'],
            'sisakuotanonjkn' => $kuota['sisakuotanonjkn'],
            'kuotanonjkn'     => $kuota['kuotanonjkn'],
            'keterangan'      => '',
        ]);
    }

    // =========================================================================
    // B3 — AMBIL ANTREAN  (reservasi; code 202 bila pasien baru)
    //   Request: { nomorkartu, nik, nohp, kodepoli, norm, tanggalperiksa,
    //              kodedokter, jampraktek, jeniskunjungan, nomorreferensi }
    // =========================================================================
    public function ambilAntrean(array $data): array
    {
        $sched = $this->resolveSchedule($data['kodepoli'] ?? null, $data['kodedokter'] ?? null, $data['jampraktek'] ?? null);
        if (! $sched) {
            return $this->fail('Poli/dokter tidak ditemukan atau belum dipetakan.');
        }

        $tanggal = $this->normalizeDate($data['tanggalperiksa'] ?? null);

        // Cek kuota JKN sebelum menerbitkan antrean.
        $kuota = $this->kuota->ringkasanKuota($sched->poli_code, $sched->employee_id, $tanggal);
        if ($kuota['sisakuotajkn'] <= 0) {
            return $this->fail('Kuota JKN untuk poli/dokter pada tanggal tersebut sudah penuh.');
        }

        // Cocokkan pasien: NIK dulu, lalu nomor kartu BPJS.
        $patient = $this->matchPatient($data['nik'] ?? null, $data['nomorkartu'] ?? null);

        try {
            return DB::transaction(function () use ($data, $sched, $tanggal, $patient) {
            // Cegah double-booking aktif untuk NIK + dokter + tanggal yang sama.
            // Cek eksplisit ini memberi pesan ramah pada kasus normal; jaring pengaman
            // ATOMIK terhadap race ada di partial unique index DB (antrean_bookings_
            // active_unique) yang ditangkap sebagai QueryException di bawah.
            if (! empty($data['nik'])) {
                $dup = AntreanBooking::where('nik', $data['nik'])
                    ->where('doctor_schedule_id', $sched->id)
                    ->whereDate('tanggal_periksa', $tanggal)
                    ->whereIn('status', [AntreanBooking::STATUS_DIBOOK, AntreanBooking::STATUS_CHECKIN])
                    ->first();
                if ($dup) {
                    return $this->fail('Pasien sudah memiliki antrean aktif untuk dokter & tanggal ini.');
                }
            }

            $angka       = $this->nextAngka($sched, $tanggal);
            $prefix      = BpjsPoliMapping::bpjsCodeFor($sched->poli_code) ?: 'RS';
            $nomorAntrean = $prefix . '-' . str_pad((string) $angka, 3, '0', STR_PAD_LEFT);
            $kodebooking = $this->generateKodebooking($prefix, $tanggal);

            $booking = AntreanBooking::create([
                'kodebooking'        => $kodebooking,
                'nik'                => $data['nik'] ?? null,
                'nomorkartu'         => $data['nomorkartu'] ?? null,
                'nohp'               => $data['nohp'] ?? null,
                'norm'               => $patient?->no_rm ?? ($data['norm'] ?? null),
                'patient_id'         => $patient?->id,
                'poli_code'          => $sched->poli_code,
                'doctor_schedule_id' => $sched->id,
                'tanggal_periksa'    => $tanggal,
                'jam_praktek'        => $this->jamPraktek($sched),
                'jenis_kunjungan'    => $data['jeniskunjungan'] ?? null,
                'nomor_referensi'    => $data['nomorreferensi'] ?? null,
                'nomor_antrean'      => $nomorAntrean,
                'angka_antrean'      => $angka,
                'status'             => AntreanBooking::STATUS_DIBOOK,
            ]);

            $estimasi = $this->kuota->estimasiDilayaniMs($sched->poli_code, $sched->employee_id, $angka);
            $kuota    = $this->kuota->ringkasanKuota($sched->poli_code, $sched->employee_id, $tanggal);

            $response = [
                'nomorantrean'    => $booking->nomor_antrean,
                'angkaantrean'    => $booking->angka_antrean,
                'kodebooking'     => $booking->kodebooking,
                'norm'            => $booking->norm ?? '',
                'namapoli'        => $sched->poliklinik,
                'namadokter'      => $sched->employee?->name,
                'estimasidilayani' => $estimasi,
                'sisakuotajkn'    => $kuota['sisakuotajkn'],
                'kuotajkn'        => $kuota['kuotajkn'],
                'sisakuotanonjkn' => $kuota['sisakuotanonjkn'],
                'kuotanonjkn'     => $kuota['kuotanonjkn'],
                'keterangan'      => 'Peserta harap 30 menit lebih awal guna pencatatan administrasi.',
            ];

            // Pasien belum punya RM → code 202 (Mobile JKN lanjut hit B7 Info Pasien Baru).
            $code = $patient ? 200 : 202;
            $msg  = $patient ? 'Ok' : 'Silahkan lengkapi data pasien baru.';

            return $this->ok($response, $code, $msg);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race: dua request paralel utk NIK sama lolos cek eksplisit lalu sama-sama
            // create → partial unique index DB menolak yang kedua. Balas ramah ke BPJS.
            return $this->fail('Pasien sudah memiliki antrean aktif untuk dokter & tanggal ini.');
        }
    }

    // =========================================================================
    // B4 — SISA ANTREAN (hari H, by kodebooking)
    //   Request: { kodebooking }
    // =========================================================================
    public function sisaAntrean(array $data): array
    {
        $booking = $this->findBooking($data['kodebooking'] ?? null);
        if (! $booking) {
            return $this->fail('Kode booking tidak ditemukan.');
        }

        $sched = $booking->doctorSchedule;
        $stat  = $this->statistik($sched, $booking->tanggal_periksa->toDateString());

        // Sisa di depan booking ini = angka_antrean - antrean yang sudah dipanggil.
        $sisaDepan = max(0, ($booking->angka_antrean ?? 0) - $stat['panggil']);
        $waktuTunggu = $this->kuota->waktuTungguDetik($booking->poli_code, $sched?->employee_id, $sisaDepan);

        return $this->ok([
            'nomorantrean'   => $booking->nomor_antrean,
            'namapoli'       => $sched?->poliklinik,
            'namadokter'     => $sched?->employee?->name,
            'sisaantrean'    => $sisaDepan,
            'antreanpanggil' => $this->labelPanggil($sched, $stat['panggil']),
            'waktutunggu'    => $waktuTunggu,
            'keterangan'     => '',
        ]);
    }

    // =========================================================================
    // B5 — BATAL ANTREAN (by kodebooking)
    //   Request: { kodebooking, keterangan }
    // =========================================================================
    public function batalAntrean(array $data): array
    {
        $booking = $this->findBooking($data['kodebooking'] ?? null);
        if (! $booking) {
            return $this->fail('Kode booking tidak ditemukan.');
        }
        if ($booking->status === AntreanBooking::STATUS_BATAL) {
            return $this->fail('Antrean sudah dibatalkan sebelumnya.');
        }
        if ($booking->status === AntreanBooking::STATUS_SELESAI) {
            return $this->fail('Antrean sudah selesai dilayani, tidak bisa dibatalkan.');
        }

        $booking->update([
            'status'           => AntreanBooking::STATUS_BATAL,
            'keterangan_batal' => $data['keterangan'] ?? null,
        ]);

        // Jika sudah jadi Visit (sudah check-in lalu batal) → lapor BPJS task 99 via QueueService.
        if ($booking->visit_id && ($visit = Visit::find($booking->visit_id))) {
            app(QueueService::class)->reportTask($visit, 99);
        }

        return $this->ok(null, 200, 'Ok');
    }

    // =========================================================================
    // B6 — CHECK IN (pasien datang) → buat Visit + antrean fisik
    //   Request: { kodebooking, waktu }
    // =========================================================================
    public function checkin(array $data): array
    {
        $booking = $this->findBooking($data['kodebooking'] ?? null);
        if (! $booking) {
            return $this->fail('Kode booking tidak ditemukan.');
        }
        if ($booking->status === AntreanBooking::STATUS_BATAL) {
            return $this->fail('Antrean sudah dibatalkan.');
        }
        if ($booking->status === AntreanBooking::STATUS_CHECKIN && $booking->visit_id) {
            return $this->ok(null, 200, 'Pasien sudah check-in.');
        }
        if (! $booking->patient_id) {
            return $this->fail('Data pasien belum lengkap (RM belum ada). Lengkapi via Info Pasien Baru.');
        }

        DB::transaction(function () use ($booking) {
            $visit = Visit::create([
                'patient_id'         => $booking->patient_id,
                'doctor_schedule_id' => $booking->doctor_schedule_id,
                'visit_date'         => $booking->tanggal_periksa->toDateString(),
                'classification'     => 'Lama',
                'visit_type'         => 'REGULAR',
                'current_station'    => 'TRIASE',
                'guarantor_type'     => 'BPJS',
                'bpjs_booking_code'  => $booking->kodebooking,
                'satusehat_sync_status' => 'PENDING',
                'insurance_verification_status' => 'NONE',
            ]);

            // Enqueue TRIASE + REFRAKSIONIS paralel (pola registrasi loket).
            $qs = app(QueueService::class);
            $shared = $qs->generateQueueNumber('TRIASE');
            $qs->enqueue($visit->id, 'TRIASE', $shared);
            $qs->enqueue($visit->id, 'REFRAKSIONIS', $shared);

            $booking->update([
                'status'     => AntreanBooking::STATUS_CHECKIN,
                'checkin_at' => now(),
                'visit_id'   => $visit->id,
            ]);

            // Check-in = pasien datang → lapor BPJS task 3 (mulai tunggu poli), non-blocking.
            $qs->reportTask($visit->fresh(), 3);
        });

        return $this->ok(null, 200, 'Ok');
    }

    // =========================================================================
    // B7 — INFO PASIEN BARU (buat RM, tautkan ke booking, balas norm)
    //   Request: { nomorkartu, nik, nomorkk, nama, jeniskelamin (L/P),
    //              tanggallahir, nohp, alamat, kodeprop, namaprop, kodedati2,
    //              namadati2, kodekec, namakec, kodekel, namakel, rw, rt }
    //   Dipanggil Mobile JKN setelah Ambil Antrean (B3) membalas code 202.
    // =========================================================================
    public function pasienBaru(array $data): array
    {
        $nik    = trim((string) ($data['nik'] ?? ''));
        $nama   = trim((string) ($data['nama'] ?? ''));
        $kartu  = trim((string) ($data['nomorkartu'] ?? ''));
        $gender = strtoupper(trim((string) ($data['jeniskelamin'] ?? '')));

        if ($nik === '' || $nama === '' || empty($data['tanggallahir'])) {
            return $this->fail('Data tidak lengkap: nik, nama, dan tanggallahir wajib diisi.');
        }
        if (! in_array($gender, ['L', 'P'], true)) {
            return $this->fail('jeniskelamin harus L atau P.');
        }

        return DB::transaction(function () use ($data, $nik, $nama, $kartu, $gender) {
            // Idempoten: jika pasien sudah ada (NIK / kartu BPJS), pakai norm yang ada.
            $patient = $this->matchPatient($nik, $kartu ?: null);

            if (! $patient) {
                $patient = app(\App\Services\AdmisiService::class)->storePasien([
                    'identity_type' => 'KTP',
                    'nik'           => $nik,
                    'name'          => $nama,
                    'gender'        => $gender,
                    'date_of_birth' => $this->normalizeDate($data['tanggallahir']),
                    'phone'         => $data['nohp']     ?? null,
                    'address'       => $data['alamat']   ?? null,
                    'province'      => $data['namaprop'] ?? null,
                    'bpjs_number'   => $kartu ?: null,
                ]);
            }

            // Tautkan ke booking aktif (DIBOOK) yang belum punya patient_id.
            $this->attachPatientToBookings($patient, $nik, $kartu);

            return $this->ok(['norm' => $patient->no_rm], 200, 'Harap datang ke admisi untuk melengkapi data rekam medis');
        });
    }

    /** Lengkapi patient_id & norm pada reservasi aktif milik NIK/kartu ini. */
    private function attachPatientToBookings(Patient $patient, string $nik, string $kartu): void
    {
        AntreanBooking::whereNull('patient_id')
            ->where('status', AntreanBooking::STATUS_DIBOOK)
            ->where(function ($q) use ($nik, $kartu) {
                if ($nik !== '')   { $q->orWhere('nik', $nik); }
                if ($kartu !== '') { $q->orWhere('nomorkartu', $kartu); }
            })
            ->update([
                'patient_id' => $patient->id,
                'norm'       => $patient->no_rm,
            ]);
    }

    // =========================================================================
    // B8 — JADWAL OPERASI RS (rentang tanggal)
    //   Request: { tanggalawal, tanggalakhir }
    //   Response.list[]: { kodebooking, tanggaloperasi, jenistindakan, kodepoli,
    //                      namapoli, terlaksana (1/0), nopeserta, lastupdate }
    // =========================================================================
    public function jadwalOperasi(array $data): array
    {
        if (empty($data['tanggalawal']) || empty($data['tanggalakhir'])) {
            return $this->fail('tanggalawal dan tanggalakhir wajib diisi.');
        }

        $awal  = $this->normalizeDate($data['tanggalawal']);
        $akhir = $this->normalizeDate($data['tanggalakhir']);

        $schedules = \App\Models\SurgerySchedule::with([
                'surgeryPackage',
                'visit.patient',
                'visit.doctorSchedule',
            ])
            ->whereBetween('scheduled_date', [$awal, $akhir])
            ->orderBy('scheduled_date')
            ->get();

        $list = $schedules->map(fn ($s) => $this->mapOperasi($s, withPeserta: true))->all();

        return $this->ok(['list' => $list]);
    }

    // =========================================================================
    // B9 — JADWAL OPERASI PASIEN (by nopeserta = no kartu BPJS)
    //   Request: { nopeserta }
    // =========================================================================
    public function jadwalOperasiPasien(array $data): array
    {
        $nopeserta = trim((string) ($data['nopeserta'] ?? ''));
        if ($nopeserta === '') {
            return $this->fail('nopeserta wajib diisi.');
        }

        $schedules = \App\Models\SurgerySchedule::with(['surgeryPackage', 'visit.patient', 'visit.doctorSchedule'])
            ->whereHas('visit.patient', fn ($p) => $p->where('bpjs_number', $nopeserta))
            ->orderBy('scheduled_date')
            ->get();

        // B9 tidak menyertakan nopeserta & lastupdate (sesuai contoh spec).
        $list = $schedules->map(fn ($s) => $this->mapOperasi($s, withPeserta: false))->all();

        return $this->ok(['list' => $list]);
    }

    /** Petakan SurgerySchedule → item jadwal operasi BPJS. */
    private function mapOperasi(\App\Models\SurgerySchedule $s, bool $withPeserta): array
    {
        $visit  = $s->visit;
        $sched  = $visit?->doctorSchedule;
        $poliBpjs = $sched ? BpjsPoliMapping::bpjsCodeFor($sched->poli_code) : null;

        $item = [
            'kodebooking'    => $visit?->bpjs_booking_code ?: $s->id,
            'tanggaloperasi' => optional($s->scheduled_date)->toDateString(),
            'jenistindakan'  => $s->surgeryPackage?->name ?? '',
            'kodepoli'       => $poliBpjs ?: '',
            'namapoli'       => $sched?->poliklinik ?? '',
            'terlaksana'     => in_array($s->status, ['DONE', 'IN_PROGRESS'], true) ? 1 : 0,
        ];

        if ($withPeserta) {
            $item['nopeserta']  = $visit?->patient?->bpjs_number ?? '';
            $item['lastupdate'] = (int) (($s->updated_at ?? $s->created_at)?->valueOf() ?? 0);
        }

        return $item;
    }

    // =========================================================================
    // B10 — AMBIL ANTREAN FARMASI (by kodebooking)
    //   Response: { jenisresep, nomorantrean, keterangan }
    // =========================================================================
    public function farmasiAmbil(array $data): array
    {
        $booking = $this->findBooking($data['kodebooking'] ?? null);
        if (! $booking || ! $booking->visit_id) {
            return $this->fail('Antrean farmasi belum tersedia untuk kode booking ini.');
        }

        $fq = $this->farmasiQueue($booking->visit_id);
        if (! $fq) {
            return $this->fail('Pasien belum masuk antrean farmasi.');
        }

        return $this->ok([
            'jenisresep'   => $this->resolveJenisResep($booking->visit_id),
            'nomorantrean' => (int) $fq->queue_sequence,
            'keterangan'   => '',
        ]);
    }

    // =========================================================================
    // B11 — STATUS ANTREAN FARMASI (by kodebooking)
    //   Response: { jenisresep, totalantrean, sisaantrean, antreanpanggil, keterangan }
    // =========================================================================
    public function farmasiStatus(array $data): array
    {
        $booking = $this->findBooking($data['kodebooking'] ?? null);
        if (! $booking || ! $booking->visit_id) {
            return $this->fail('Antrean farmasi belum tersedia untuk kode booking ini.');
        }

        $fq = $this->farmasiQueue($booking->visit_id);
        if (! $fq) {
            return $this->fail('Pasien belum masuk antrean farmasi.');
        }

        $tanggal = $fq->created_at->toDateString();

        $total = Queue::byStation(Queue::STATION_FARMASI)->whereDate('created_at', $tanggal)->count();
        $selesai = Queue::byStation(Queue::STATION_FARMASI)->whereDate('created_at', $tanggal)
            ->where('status', Queue::STATUS_COMPLETED)->count();
        $panggil = Queue::byStation(Queue::STATION_FARMASI)->whereDate('created_at', $tanggal)
            ->whereIn('status', [Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS, Queue::STATUS_COMPLETED])
            ->max('queue_sequence') ?? 0;

        return $this->ok([
            'jenisresep'     => $this->resolveJenisResep($booking->visit_id),
            'totalantrean'   => $total,
            'sisaantrean'    => max(0, $total - $selesai),
            'antreanpanggil' => (int) $panggil,
            'keterangan'     => '',
        ]);
    }

    /** Antrean FARMASI milik visit (paling baru hari ini). */
    private function farmasiQueue(string $visitId): ?Queue
    {
        return Queue::byStation(Queue::STATION_FARMASI)
            ->where('visit_id', $visitId)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Tentukan jenis resep (Racikan / Non racikan) untuk dikirim/ditampilkan BPJS.
     *
     * #TODO RACIKAN: model resep belum punya penanda racikan eksplisit. Untuk
     * sekarang SELALU 'Non racikan' (mayoritas resep klinik mata). Saat menu
     * racikan dibuat, cukup ubah SATU method ini (mis. cek Prescription->is_racikan
     * atau PrescriptionItem compound) — A8/B10/B11 otomatis ikut.
     */
    public function resolveJenisResep(string $visitId): string
    {
        return 'Non racikan';
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Resolve DoctorSchedule dari kode poli BPJS + kode DPJP BPJS (+ jam praktek). */
    private function resolveSchedule(?string $kodePoli, $kodeDokter, ?string $jamPraktek): ?DoctorSchedule
    {
        $poliCode = BpjsPoliMapping::localCodeFor($kodePoli);
        if (! $poliCode) {
            return null;
        }

        $employeeId = $kodeDokter !== null && $kodeDokter !== ''
            ? Employee::where('bpjs_dpjp_code', (string) $kodeDokter)->value('id')
            : null;

        $q = DoctorSchedule::with('employee')
            ->where('poli_code', $poliCode)
            ->where('is_active', true);

        if ($employeeId) {
            $q->where('employee_id', $employeeId);
        }

        // Cocokkan jam praktek bila dikirim (mis. "08:00-12:00").
        if ($jamPraktek) {
            [$buka] = array_pad(explode('-', $jamPraktek), 1, null);
            if ($buka) {
                $q->where('start_time', 'like', trim($buka) . '%');
            }
        }

        return $q->orderByDesc('week_start')->first();
    }

    /** Cocokkan pasien: NIK dulu, lalu nomor kartu BPJS. Null bila tidak ada. */
    private function matchPatient(?string $nik, ?string $nomorKartu): ?Patient
    {
        if ($nik) {
            $p = Patient::where('nik', $nik)->first();
            if ($p) {
                return $p;
            }
        }
        if ($nomorKartu) {
            return Patient::where('bpjs_number', $nomorKartu)->first();
        }

        return null;
    }

    private function findBooking(?string $kodebooking): ?AntreanBooking
    {
        return $kodebooking
            ? AntreanBooking::with('doctorSchedule.employee')->where('kodebooking', $kodebooking)->first()
            : null;
    }

    /** Statistik antrean hari itu: total booking, sisa (belum dilayani), angka dipanggil. */
    private function statistik(?DoctorSchedule $sched, string $tanggal): array
    {
        if (! $sched) {
            return ['total' => 0, 'sisa' => 0, 'panggil' => 0];
        }

        $total = AntreanBooking::where('doctor_schedule_id', $sched->id)
            ->whereDate('tanggal_periksa', $tanggal)
            ->whereIn('status', [AntreanBooking::STATUS_DIBOOK, AntreanBooking::STATUS_CHECKIN, AntreanBooking::STATUS_SELESAI])
            ->count();

        $selesai = AntreanBooking::where('doctor_schedule_id', $sched->id)
            ->whereDate('tanggal_periksa', $tanggal)
            ->where('status', AntreanBooking::STATUS_SELESAI)
            ->count();

        // Angka antrean DOKTER yang sedang/terakhir dipanggil hari ini (dari Queue nyata).
        $panggil = Queue::byStation(Queue::STATION_DOKTER)
            ->whereDate('created_at', $tanggal)
            ->whereIn('status', [Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS, Queue::STATUS_COMPLETED])
            ->whereHas('visit', fn ($v) => $v->where('doctor_schedule_id', $sched->id))
            ->max('queue_sequence') ?? 0;

        return [
            'total'   => $total,
            'sisa'    => max(0, $total - $selesai),
            'panggil' => (int) $panggil,
        ];
    }

    private function labelPanggil(?DoctorSchedule $sched, int $angka): string
    {
        if (! $sched || $angka <= 0) {
            return '';
        }
        $prefix = BpjsPoliMapping::bpjsCodeFor($sched->poli_code) ?: 'RS';

        return $prefix . '-' . str_pad((string) $angka, 3, '0', STR_PAD_LEFT);
    }

    /** Angka antrean berikutnya per dokter+tanggal (lanjut dari booking terakhir). */
    private function nextAngka(DoctorSchedule $sched, string $tanggal): int
    {
        $last = AntreanBooking::where('doctor_schedule_id', $sched->id)
            ->whereDate('tanggal_periksa', $tanggal)
            ->max('angka_antrean') ?? 0;

        return $last + 1;
    }

    private function generateKodebooking(string $prefix, string $tanggal): string
    {
        $tgl  = Carbon::parse($tanggal)->format('ymd');
        $rand = strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 6));

        return "{$prefix}{$tgl}{$rand}";
    }

    private function jamPraktek(?DoctorSchedule $sched): ?string
    {
        if (! $sched) {
            return null;
        }
        $start = substr((string) $sched->start_time, 0, 5);
        $end   = substr((string) $sched->end_time, 0, 5);

        return ($start && $end) ? "{$start}-{$end}" : null;
    }

    private function normalizeDate($tanggal): string
    {
        return $tanggal
            ? Carbon::parse($tanggal, 'Asia/Jakarta')->toDateString()
            : now('Asia/Jakarta')->toDateString();
    }

    // ── envelope return helpers ───────────────────────────────────────────────

    private function ok(mixed $response, int $code = 200, string $message = 'Ok'): array
    {
        return ['code' => $code, 'message' => $message, 'response' => $response];
    }

    private function fail(string $message): array
    {
        return ['code' => 201, 'message' => $message, 'response' => null];
    }

    private function todo(): never
    {
        throw new \BadMethodCallException('Endpoint Sisi B belum diimplementasi.');
    }
}
