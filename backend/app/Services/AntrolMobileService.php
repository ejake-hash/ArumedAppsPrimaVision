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
    /**
     * Poli RS Mata ditetapkan WAJIB validasi sidik jari (sesuai setting HFIS faskes
     * & alur yang berjalan). SEP poli-FP hanya terbit setelah fingerprint tervalidasi.
     * Set false bila faskes mematikan validasi sidik jari di kiosk.
     */
    private const KIOSK_FP_REQUIRED = true;

    public function __construct(private readonly AntreanKuotaService $kuota) {}

    // =========================================================================
    // B2 — STATUS ANTREAN (perencanaan kedatangan)
    //   Request: { kodepoli, kodedokter, tanggalperiksa, jampraktek }
    // =========================================================================
    public function statusAntrean(array $data): array
    {
        if ($err = $this->validateDateInput($data['tanggalperiksa'] ?? null)) {
            return $this->fail($err);
        }
        $tanggal = $this->normalizeDate($data['tanggalperiksa'] ?? null);

        $res = $this->resolveScheduleDiagnosed($data['kodepoli'] ?? null, $data['kodedokter'] ?? null, $data['jampraktek'] ?? null, $tanggal);
        if ($res['error']) {
            return $this->fail($res['error']);
        }
        $sched = $res['schedule'];

        $stat    = $this->statistik($sched, $tanggal);
        $kuota   = $this->kuota->ringkasanKuota($sched->poli_code, $sched->employee_id, $tanggal);

        return $this->ok([
            'namapoli'        => $sched->poliklinik,
            'namadokter'      => $sched->employee?->name,
            'totalantrean'    => $stat['total'],
            'sisaantrean'     => $stat['sisa'],
            'antreanpanggil'  => $this->labelPanggil($sched, $stat['panggil']),
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
        if ($err = $this->validateDateInput($data['tanggalperiksa'] ?? null)) {
            return $this->fail($err);
        }
        $tanggal = $this->normalizeDate($data['tanggalperiksa'] ?? null);

        $res = $this->resolveScheduleDiagnosed($data['kodepoli'] ?? null, $data['kodedokter'] ?? null, $data['jampraktek'] ?? null, $tanggal);
        if ($res['error']) {
            return $this->fail($res['error']);
        }
        $sched = $res['schedule'];

        // Validasi jam tutup pendaftaran (hanya untuk hari H).
        if ($closed = $this->registrationClosedMessage($sched, $tanggal)) {
            return $this->fail($closed);
        }

        // Cek kuota JKN sebelum menerbitkan antrean.
        $kuota = $this->kuota->ringkasanKuota($sched->poli_code, $sched->employee_id, $tanggal);
        if ($kuota['sisakuotajkn'] <= 0) {
            return $this->fail('Kuota JKN untuk poli/dokter pada tanggal tersebut sudah penuh.');
        }

        // Cocokkan pasien: NIK dulu, lalu nomor kartu BPJS.
        $patient = $this->matchPatient($data['nik'] ?? null, $data['nomorkartu'] ?? null);

        try {
            return DB::transaction(function () use ($data, $sched, $tanggal, $patient) {
            // Serialize penomoran antrean per (room, tanggal) via advisory lock scoped
            // transaksi: nextDoctorSequence membaca MAX(angka) TANPA lock, sehingga dua
            // booking konkuren (endpoint publik BPJS) bisa dapat nomor KEMBAR. Lock ini
            // membuat pembacaan MAX + INSERT atomik lintas request (juga aman utk booking
            // pertama hari itu, beda dgn range-lock baris yang butuh baris eksisting).
            // Hanya di Postgres (prod); no-op di SQLite (test) yang tak punya advisory lock.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?)) AS l', ["antrol_daftar:{$sched->room}:{$tanggal}"]);
            }

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
                    return $this->fail('Nomor Antrean Hanya Dapat Diambil 1 Kali Pada Tanggal Yang Sama');
                }
            }

            // Reservasi nomor DOKTER (D{room}-NNN) lewat sumber TERPADU — nomor yang
            // sama dipakai papan dokter & ditampilkan di Mobile JKN (anti-bingung,
            // aman utk poli ber-banyak dokter). Dipakai-ulang saat masuk stasiun DOKTER.
            $dok          = app(QueueService::class)->nextDoctorSequence($sched->room, $tanggal);
            $angka        = $dok['queue_sequence'];
            $nomorAntrean = $dok['queue_number'];
            // kodebooking tetap pakai prefix poli BPJS (kode booking ≠ nomor antrean).
            $bookingPrefix = BpjsPoliMapping::bpjsCodeFor($sched->poli_code) ?: 'RS';
            $kodebooking  = $this->generateKodebooking($bookingPrefix, $tanggal);

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
            $msg  = $patient ? 'Ok' : 'Data pasien ini tidak ditemukan, silahkan Melakukan Registrasi Pasien Baru';

            return $this->ok($response, $code, $msg);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race: dua request paralel utk NIK sama lolos cek eksplisit lalu sama-sama
            // create → partial unique index DB menolak yang kedua. Balas ramah ke BPJS.
            return $this->fail('Nomor Antrean Hanya Dapat Diambil 1 Kali Pada Tanggal Yang Sama');
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
            return $this->fail('Antrean Tidak Ditemukan');
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
            return $this->fail('Antrean Tidak Ditemukan');
        }
        if ($booking->status === AntreanBooking::STATUS_BATAL) {
            return $this->fail('Antrean Tidak Ditemukan atau Sudah Dibatalkan');
        }
        if ($booking->status === AntreanBooking::STATUS_SELESAI) {
            return $this->fail('Pasien Sudah Dilayani, Antrean Tidak Dapat Dibatalkan');
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

        $visit = $this->materializeVisit($booking);

        // Booking Mobile JKN sudah terdaftar di BPJS (saat ambilAntrean) → cukup lapor
        // task 3 (mulai tunggu poli). Non-blocking. Dipanggil post-commit.
        app(QueueService::class)->reportTask($visit, 3);

        return $this->ok(null, 200, 'Ok');
    }

    /**
     * Materialisasi booking → Visit + antrean fisik (TRIASE+REFRAKSIONIS). TIDAK
     * melapor task ke BPJS (caller yang atur urutan: untuk onsite WAJIB add dulu
     * baru task). Kembalikan Visit yang sudah fresh.
     */
    private function materializeVisit(AntreanBooking $booking): Visit
    {
        return DB::transaction(function () use ($booking) {
            // Idempotensi: /checkin dipanggil BPJS Mobile JKN yang RETRY saat timeout.
            // Kunci booking & cek ulang di bawah lock — bila sudah ter-materialize,
            // kembalikan Visit yang ADA (jangan buat Visit + antrean + reportTask ganda).
            $booking = AntreanBooking::whereKey($booking->getKey())->lockForUpdate()->first() ?? $booking;
            if ($booking->visit_id) {
                $existing = Visit::find($booking->visit_id);
                if ($existing) {
                    return $existing;
                }
            }

            // Bawa nomor referensi dari booking ke visit agar tak hilang saat terbit
            // SEP. jeniskunjungan BPJS Antrean: '3'=Kontrol → no_surat_kontrol;
            // selain itu (rujukan FKTP/antar-RS) → no_rujukan.
            $isKontrol = (string) $booking->jenis_kunjungan === '3';

            $visit = Visit::create([
                'patient_id'         => $booking->patient_id,
                'doctor_schedule_id' => $booking->doctor_schedule_id,
                'visit_date'         => $booking->tanggal_periksa->toDateString(),
                'classification'     => 'Lama',
                'visit_type'         => 'REGULAR',
                'current_station'    => 'TRIASE',
                'guarantor_type'     => 'BPJS',
                'bpjs_booking_code'  => $booking->kodebooking,
                'no_rujukan'         => $isKontrol ? null : $booking->nomor_referensi,
                'no_surat_kontrol'   => $isKontrol ? $booking->nomor_referensi : null,
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

            return $visit->fresh();
        });
    }

    // =========================================================================
    // KIOSK — CHECK-IN ONSITE (Anjungan Mandiri). Resolusi booking by kodebooking
    // atau NIK/kartu untuk hari ini, lalu reuse checkin(). Balikan data siap-cetak.
    //   Request: { kodebooking? , nik?, nomorkartu? }
    // =========================================================================
    public function kioskCheckin(array $data): array
    {
        $kode  = trim((string) ($data['kodebooking'] ?? ''));
        $nik   = trim((string) ($data['nik'] ?? ''));
        $kartu = trim((string) ($data['nomorkartu'] ?? ''));

        $booking = null;
        if ($kode !== '') {
            $booking = $this->findBooking($kode);
        } elseif ($nik !== '' || $kartu !== '') {
            $today = now('Asia/Jakarta')->toDateString();
            $booking = AntreanBooking::with('doctorSchedule.employee')
                ->whereDate('tanggal_periksa', $today)
                ->whereIn('status', [AntreanBooking::STATUS_DIBOOK, AntreanBooking::STATUS_CHECKIN])
                ->where(function ($q) use ($nik, $kartu) {
                    if ($nik !== '')   { $q->orWhere('nik', $nik); }
                    if ($kartu !== '') { $q->orWhere('nomorkartu', $kartu); }
                })
                ->orderByDesc('created_at')
                ->first();
        }

        if (! $booking) {
            return $this->fail('Booking tidak ditemukan. Pastikan kode booking benar atau ambil antrean via Mobile JKN terlebih dahulu.');
        }
        if (! $booking->tanggal_periksa->isToday()) {
            return $this->fail('Antrean ini untuk tanggal ' . $booking->tanggal_periksa->format('d-m-Y') . '. Check-in hanya pada hari pelayanan.');
        }
        // Pasien baru (booking belum tertaut RM) tak bisa self check-in → arahkan ke loket.
        if (! $booking->patient_id && $booking->status !== AntreanBooking::STATUS_CHECKIN) {
            return $this->fail('Data rekam medis belum lengkap. Silakan menuju Loket Admisi untuk dilayani petugas.');
        }

        // Reuse alur check-in inti (buat Visit + antrean + lapor task 3). Idempoten.
        $res = $this->checkin(['kodebooking' => $booking->kodebooking]);
        if (($res['code'] ?? 201) !== 200) {
            return $res; // teruskan pesan (batal / RM belum lengkap → arahkan ke admisi)
        }

        $booking->refresh();
        $sched = $booking->doctorSchedule;
        $queueNumber = $booking->visit_id
            ? Queue::byStation(Queue::STATION_TRIASE)->where('visit_id', $booking->visit_id)->value('queue_number')
            : null;

        $sep = $this->attemptAutoSep($booking->visit_id);

        return $this->ok([
            'kodebooking'  => $booking->kodebooking,
            'nomorantrean' => $booking->nomor_antrean,
            'namapoli'     => $sched?->poliklinik,
            'namadokter'   => $sched?->employee?->name,
            'queue_number' => $queueNumber,
            'sep'          => $sep['sep'],
            'sep_error'    => $sep['error'],
            'fp_required'  => $sep['fp_required'] ?? false,
        ], 200, 'Check-in berhasil');
    }

    /**
     * KIOSK — terbitkan SEP setelah pasien validasi sidik jari (FRISTA).
     * Dipanggil saat pasien menekan "Lanjutkan" di langkah fingerprint. Mengulang
     * gate FP: bila sudah tervalidasi → SEP terbit; bila belum → fp_required lagi.
     *   Request: { kodebooking }
     */
    public function kioskTerbitkanSep(array $data): array
    {
        $booking = $this->findBooking(trim((string) ($data['kodebooking'] ?? '')));
        if (! $booking || ! $booking->visit_id) {
            return $this->fail('Data kunjungan tidak ditemukan.');
        }

        $sep = $this->attemptAutoSep($booking->visit_id);

        return $this->ok([
            'sep'         => $sep['sep'],
            'sep_error'   => $sep['error'],
            'fp_required' => $sep['fp_required'] ?? false,
        ], 200, ! empty($sep['fp_required']) ? 'Menunggu validasi sidik jari.' : ($sep['sep'] ? 'SEP terbit.' : 'Lanjut ke admisi.'));
    }

    // =========================================================================
    // KIOSK — AMBIL ANTREAN ONSITE (walk-in BPJS tanpa Mobile JKN).
    // Pasien LAMA (punya RM) input NIK/kartu + no rujukan + pilih dokter.
    // Alur: buat booking → materialisasi Visit → WAJIB hit BPJS /antrean/add
    // (Tambah Antrian Onsite) → baru lapor task 3 (urutan add→task wajib).
    // Pasien BARU (belum ada RM) diarahkan ke Loket Admisi (alur SEP + task 1-2-3).
    //   Request: { doctor_schedule_id, nik? | nomorkartu?, nomorreferensi? }
    // =========================================================================
    public function kioskAmbilOnsite(array $data): array
    {
        $schedId = $data['doctor_schedule_id'] ?? null;
        $nik     = trim((string) ($data['nik'] ?? ''));
        $kartu   = trim((string) ($data['nomorkartu'] ?? ''));
        $noRujuk = trim((string) ($data['nomorreferensi'] ?? ''));
        $nohp    = trim((string) ($data['nohp'] ?? ''));

        $sched = $schedId ? DoctorSchedule::with('employee')->find($schedId) : null;
        if (! $sched || ! $sched->is_active) {
            return $this->fail('Jadwal dokter tidak ditemukan atau tidak aktif hari ini.');
        }
        if ($nik === '' && $kartu === '') {
            return $this->fail('Masukkan NIK atau nomor kartu BPJS.');
        }

        // Onsite kiosk hanya untuk pasien LAMA (sudah punya RM). Pasien baru → admisi.
        $patient = $this->matchPatient($nik ?: null, $kartu ?: null);
        if (! $patient) {
            return $this->fail('Data pasien belum terdaftar (kemungkinan pasien baru). Silakan menuju Loket Admisi.');
        }

        // No. HP WAJIB untuk BPJS /antrean/add. Lengkapi data pasien bila masih kosong.
        if ($nohp !== '' && empty($patient->phone)) {
            $patient->forceFill(['phone' => $nohp])->save();
        }
        if (empty($patient->phone) && $nohp === '') {
            return $this->fail('No. HP wajib diisi untuk antrean BPJS.');
        }

        // Rujukan WAJIB untuk RJ JKN (jeniskunjungan Rujukan FKTP). Tanpa referensi,
        // BPJS menolak /antrean/add & SEP tak bisa terbit. Pasien surat-kontrol / tanpa
        // rujukan diarahkan ke Loket Admisi (alur khusus di sana).
        if ($noRujuk === '') {
            return $this->fail('Nomor rujukan wajib untuk antrean BPJS onsite. Silakan menuju Loket Admisi bila membawa surat kontrol.');
        }

        $tanggal = now('Asia/Jakarta')->toDateString();
        $kuota   = $this->kuota->ringkasanKuota($sched->poli_code, $sched->employee_id, $tanggal);
        if ($kuota['sisakuotajkn'] <= 0) {
            return $this->fail('Kuota JKN untuk dokter ini hari ini sudah penuh.');
        }

        try {
            $booking = DB::transaction(function () use ($sched, $tanggal, $patient, $nik, $kartu, $noRujuk) {
                // Serialize penomoran per (room, tanggal) — lihat catatan di ambilAntrean.
                // Cegah nomor kembar antar booking onsite/Mobile-JKN konkuren.
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?)) AS l', ["antrol_daftar:{$sched->room}:{$tanggal}"]);
                }

                if ($nik !== '') {
                    $dup = AntreanBooking::where('nik', $nik)
                        ->where('doctor_schedule_id', $sched->id)
                        ->whereDate('tanggal_periksa', $tanggal)
                        ->whereIn('status', [AntreanBooking::STATUS_DIBOOK, AntreanBooking::STATUS_CHECKIN])
                        ->first();
                    if ($dup) {
                        throw new \RuntimeException('Pasien sudah memiliki antrean aktif untuk dokter ini hari ini.');
                    }
                }

                // Nomor DOKTER kanonik D{room}-NNN via sumber TERPADU — sama dengan
                // Mobile JKN & papan dokter (anti-bingung utk poli ber-banyak dokter),
                // dipakai-ulang saat masuk stasiun DOKTER.
                $dok           = app(QueueService::class)->nextDoctorSequence($sched->room, $tanggal);
                $angka         = $dok['queue_sequence'];
                $nomorAntrean  = $dok['queue_number'];
                $bookingPrefix = BpjsPoliMapping::bpjsCodeFor($sched->poli_code) ?: 'RS';

                return AntreanBooking::create([
                    'kodebooking'        => $this->generateKodebooking($bookingPrefix, $tanggal),
                    'nik'                => $nik ?: ($patient->nik ?? null),
                    'nomorkartu'         => $kartu ?: ($patient->bpjs_number ?? null),
                    'nohp'               => $patient->phone ?: ($nohp ?: null),
                    'norm'               => $patient->no_rm,
                    'patient_id'         => $patient->id,
                    'poli_code'          => $sched->poli_code,
                    'doctor_schedule_id' => $sched->id,
                    'tanggal_periksa'    => $tanggal,
                    'jam_praktek'        => $this->jamPraktek($sched),
                    'jenis_kunjungan'    => 1, // Rujukan FKTP (default onsite RJ)
                    'nomor_referensi'    => $noRujuk,
                    'nomor_antrean'      => $nomorAntrean,
                    'angka_antrean'      => $angka,
                    'status'             => AntreanBooking::STATUS_DIBOOK,
                ]);
            });
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $this->fail('Pasien sudah memiliki antrean aktif untuk dokter ini hari ini.');
        }

        // Materialisasi Visit, lalu lapor BPJS dengan urutan WAJIB: add → task 3.
        $visit = $this->materializeVisit($booking);
        $qs    = app(QueueService::class);
        $qs->reportAntreanAdd($visit); // Tambah Antrian Onsite (WS BPJS /antrean/add)
        $qs->reportTask($visit, 3);    // pasien lama → mulai waktu tunggu poli

        $booking->refresh();
        $queueNumber = Queue::byStation(Queue::STATION_TRIASE)->where('visit_id', $visit->id)->value('queue_number');

        $sep = $this->attemptAutoSep($visit->id);

        return $this->ok([
            'kodebooking'  => $booking->kodebooking,
            'nomorantrean' => $booking->nomor_antrean,
            'namapoli'     => $sched->poliklinik,
            'namadokter'   => $sched->employee?->name,
            'queue_number' => $queueNumber,
            'sep'          => $sep['sep'],
            'sep_error'    => $sep['error'],
            'fp_required'  => $sep['fp_required'] ?? false,
        ], 200, 'Antrean berhasil diambil');
    }

    // =========================================================================
    // AUTO-SEP KIOSK — terbitkan SEP otomatis setelah Visit BPJS dibuat.
    // Gate aman: hanya bila ada rujukan/surat kontrol & VClaim aktif. bpjsGenerateSep
    // sudah menarik diagnosa dari rujukan + kelas dari hak peserta secara otomatis.
    // Gagal/ada kendala → tidak fatal: pasien diarahkan ke Loket Admisi.
    // =========================================================================
    private function attemptAutoSep(?string $visitId): array
    {
        if (! $visitId) {
            return ['sep' => null, 'error' => null];
        }

        $visit = Visit::with('patient')->find($visitId);
        if (! $visit) {
            return ['sep' => null, 'error' => null];
        }
        if ($visit->no_sep) {
            return ['sep' => $this->sepDisplay($visit), 'error' => null]; // sudah ada
        }
        // SEP butuh rujukan / surat kontrol. Tanpa itu → diselesaikan admisi.
        if (empty($visit->no_rujukan) && empty($visit->no_surat_kontrol)) {
            return ['sep' => null, 'error' => 'SEP diterbitkan di Loket Admisi (tidak ada nomor rujukan/kontrol).'];
        }
        if (! app(\App\Services\BpjsVClaimService::class)->isEnabled()) {
            return ['sep' => null, 'error' => null]; // VClaim nonaktif → lewati diam-diam
        }

        // Gate fingerprint: poli wajib FP → SEP HANYA boleh terbit setelah sidik jari
        // tervalidasi di BPJS (lewat aplikasi FRISTA, di luar SIMRS). Urutan resmi:
        // fingerprint dulu → baru SEP. Kalau belum → tahan, frontend tampilkan langkah FP.
        if (self::KIOSK_FP_REQUIRED && ! $this->fingerprintValidated($visit)) {
            return ['sep' => null, 'error' => null, 'fp_required' => true];
        }

        try {
            $res   = app(\App\Services\AdmisiService::class)->bpjsGenerateSep(['visit_id' => $visit->id]);
            $noSep = $res['response']['sep']['noSep'] ?? null;
            if ($noSep) {
                return ['sep' => $this->sepDisplay($visit->fresh('patient')), 'error' => null];
            }

            return ['sep' => null, 'error' => $res['metaData']['message'] ?? 'SEP belum bisa terbit otomatis — silakan ke Loket Admisi.'];
        } catch (\Throwable $e) {
            return ['sep' => null, 'error' => 'SEP perlu diproses di Loket Admisi: ' . $e->getMessage()];
        }
    }

    /**
     * Cek apakah sidik jari peserta SUDAH tervalidasi di BPJS untuk hari ini
     * (via VClaim getFingerprint). Validasi fisik dilakukan aplikasi FRISTA di luar
     * SIMRS; di sini kita hanya MEMBACA statusnya dari BPJS sebagai gerbang SEP.
     *
     * DEFAULT AMAN: ragu/gagal/non-aktif → anggap BELUM tervalidasi (tahan SEP),
     * supaya tidak pernah menerbitkan SEP poli-FP tanpa sidik jari (klaim ditolak).
     */
    private function fingerprintValidated(Visit $visit): bool
    {
        $noKartu = $visit->patient?->bpjs_number;
        if (! $noKartu) {
            return false;
        }
        try {
            $tgl = now('Asia/Jakarta')->toDateString();
            $res = app(\App\Services\BpjsVClaimService::class)->getFingerprint($noKartu, $tgl);

            // BPJS getFingerprint SELALU balas metaData.code 200 (baik sudah/belum).
            // Status sebenarnya ada di response.kode (terverifikasi di BPJS dev):
            //   "0" = "Peserta belum melakukan validasi finger print"
            //   "1" = sudah tervalidasi
            // DEFAULT AMAN: hanya "1" yang dianggap valid; selain itu tahan SEP.
            return (($res['is_success'] ?? false) === true)
                && (string) ($res['response']['kode'] ?? '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Ringkasan SEP untuk ditampilkan di layar kiosk (bukan untuk cetak). */
    private function sepDisplay(Visit $visit): array
    {
        $snap = (array) ($visit->sep_data ?? []);

        return [
            'no_sep'   => $visit->no_sep,
            'tgl_sep'  => $snap['tglSep'] ?? now('Asia/Jakarta')->toDateString(),
            'peserta'  => $visit->patient?->name,
            'diagnosa' => $visit->diagnosa_awal_nama ?: ($snap['diagAwal'] ?? ''),
            'kelas'    => $snap['klsRawatHak'] ?? '',
        ];
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
        if ($akhir < $awal) {
            return $this->fail('Tanggal Akhir Tidak Boleh Lebih Kecil dari Tanggal Awal');
        }

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
        if (! preg_match('/^\d{13}$/', $nopeserta)) {
            return $this->fail('Nomor Kartu Tidak Valid');
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
        if (! $booking) {
            return $this->fail('Kode Booking tidak ditemukan');
        }
        if (! $booking->visit_id) {
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
        if (! $booking) {
            return $this->fail('kodebooking Tidak Ditemukan');
        }
        if (! $booking->visit_id) {
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
    /**
     * Validasi input tanggalperiksa sesuai UAT BPJS:
     *   - Format wajib yyyy-mm-dd → "Format Tanggal Tidak Sesuai..."
     *   - Tidak boleh mundur dari hari ini (WIB) → "Tanggal Periksa Tidak Berlaku"
     * Mengembalikan pesan error (untuk fail()) atau null bila valid.
     */
    private function validateDateInput($raw): ?string
    {
        $s = trim((string) ($raw ?? ''));
        if ($s === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return 'Format Tanggal Tidak Sesuai, format yang benar adalah yyyy-mm-dd';
        }
        try {
            $d = Carbon::createFromFormat('Y-m-d', $s, 'Asia/Jakarta')->startOfDay();
        } catch (\Throwable $e) {
            return 'Format Tanggal Tidak Sesuai, format yang benar adalah yyyy-mm-dd';
        }
        if ($d->lt(now('Asia/Jakarta')->startOfDay())) {
            return 'Tanggal Periksa Tidak Berlaku';
        }
        return null;
    }

    /**
     * Resolusi jadwal dengan diagnosa kegagalan berlapis (membaca hari praktek),
     * selaras skenario UAT BPJS:
     *   - poli tak dipetakan        → "Poli Tidak Ditemukan"
     *   - poli tak praktek hari itu → "Pendaftaran ke Poli Ini Sedang Tutup"
     *   - dokter tak praktek hari itu → "Jadwal Dokter {nama} Tersebut Belum Tersedia, ..."
     * @return array{schedule: ?DoctorSchedule, error: ?string}
     */
    private function resolveScheduleDiagnosed(?string $kodePoli, $kodeDokter, ?string $jamPraktek, string $tanggal): array
    {
        $poliCode = BpjsPoliMapping::localCodeFor($kodePoli);
        if (! $poliCode) {
            return ['schedule' => null, 'error' => 'Poli Tidak Ditemukan'];
        }

        $dow = (int) Carbon::parse($tanggal, 'Asia/Jakarta')->format('N'); // 1=Sen..7=Min

        $poliOpen = DoctorSchedule::where('poli_code', $poliCode)
            ->where('is_active', true)
            ->where('day_of_week', $dow)
            ->exists();
        if (! $poliOpen) {
            return ['schedule' => null, 'error' => 'Pendaftaran ke Poli Ini Sedang Tutup'];
        }

        $q = DoctorSchedule::with('employee')
            ->where('poli_code', $poliCode)
            ->where('is_active', true)
            ->where('day_of_week', $dow);

        $employee = null;
        if ($kodeDokter !== null && $kodeDokter !== '') {
            $employee = Employee::where('bpjs_dpjp_code', (string) $kodeDokter)->first();
            if ($employee) {
                $q->where('employee_id', $employee->id);
            }
        }

        if ($jamPraktek) {
            [$buka] = array_pad(explode('-', $jamPraktek), 1, null);
            if ($buka) {
                $q->where('start_time', 'like', trim($buka) . '%');
            }
        }

        $sched = $q->orderByDesc('week_start')->first();
        if (! $sched) {
            $nama = $employee?->name
                ?? ($kodeDokter !== null && $kodeDokter !== '' ? (string) $kodeDokter : '');
            $msg = $nama !== ''
                ? "Jadwal Dokter {$nama} Tersebut Belum Tersedia, Silahkan Reschedule Tanggal dan Jam Praktek Lainnya"
                : 'Jadwal Dokter Tersebut Belum Tersedia, Silahkan Reschedule Tanggal dan Jam Praktek Lainnya';
            return ['schedule' => null, 'error' => $msg];
        }

        return ['schedule' => $sched, 'error' => null];
    }

    /**
     * Validasi jam tutup pendaftaran (hanya untuk hari H). Bila waktu kini sudah
     * melewati end_time terakhir jadwal poli/dokter hari itu → pesan tutup.
     */
    private function registrationClosedMessage(DoctorSchedule $sched, string $tanggal): ?string
    {
        if ($tanggal !== now('Asia/Jakarta')->toDateString()) {
            return null; // hanya berlaku saat pengambilan di hari H
        }

        $dow     = (int) Carbon::parse($tanggal, 'Asia/Jakarta')->format('N');
        $lastEnd = DoctorSchedule::where('poli_code', $sched->poli_code)
            ->where('is_active', true)
            ->where('day_of_week', $dow)
            ->when($sched->employee_id, fn ($q) => $q->where('employee_id', $sched->employee_id))
            ->max('end_time');

        if (! $lastEnd) {
            return null;
        }

        $closeAt = Carbon::parse($tanggal . ' ' . $lastEnd, 'Asia/Jakarta');
        if (now('Asia/Jakarta')->gt($closeAt)) {
            $jam = Carbon::parse($lastEnd)->format('H.i');
            return "Pendaftaran Ke Poli {$sched->poliklinik} Sudah Tutup Jam {$jam}";
        }

        return null;
    }

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

        $prefix = ($sched->room !== null && $sched->room !== '')
            ? 'D' . $sched->room
            : Queue::prefixFor(Queue::STATION_DOKTER);

        // total = nomor antrean terbesar yang sudah dikeluarkan untuk ruang+tanggal
        // (gabungan reservasi Mobile JKN + pasien yang dapat nomor saat tiba dokter).
        $next  = app(QueueService::class)->nextDoctorSequence($sched->room, $tanggal);
        $total = max(0, $next['queue_sequence'] - 1);

        // panggil = nomor DOKTER yang sedang/terakhir dipanggil hari itu (ruang ini).
        $panggil = Queue::byStation(Queue::STATION_DOKTER)
            ->where('queue_prefix', $prefix)
            ->whereDate('created_at', $tanggal)
            ->whereIn('status', [Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS, Queue::STATUS_COMPLETED])
            ->max('queue_sequence') ?? 0;

        return [
            'total'   => $total,
            'sisa'    => max(0, $total - (int) $panggil),
            'panggil' => (int) $panggil,
        ];
    }

    private function labelPanggil(?DoctorSchedule $sched, int $angka): string
    {
        if (! $sched || $angka <= 0) {
            return '-';
        }
        $prefix = ($sched->room !== null && $sched->room !== '')
            ? 'D' . $sched->room
            : Queue::prefixFor(Queue::STATION_DOKTER);

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
