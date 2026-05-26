<?php

namespace App\Services;

use App\Events\AdmisiQueueUpdated;
use App\Events\AntreanTvUpdated;
use App\Models\ClinicProfile;
use App\Models\DoctorSchedule;
use App\Models\Employee;
use App\Models\IntegrationConfig;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Queue;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Services\QueueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdmisiService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
    ) {}

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    public function getDashboard(): array
    {
        $today = today();

        // Stat cards kunjungan hari ini
        $totalKunjungan = Visit::whereDate('visit_date', $today)->count();

        $perKlasifikasi = Visit::whereDate('visit_date', $today)
            ->selectRaw('classification, COUNT(*) as total')
            ->groupBy('classification')
            ->pluck('total', 'classification');

        $perStation = Visit::whereDate('visit_date', $today)
            ->selectRaw('current_station, COUNT(*) as total')
            ->groupBy('current_station')
            ->pluck('total', 'current_station');

        $perPenjamin = Visit::whereDate('visit_date', $today)
            ->selectRaw('guarantor_type, COUNT(*) as total')
            ->groupBy('guarantor_type')
            ->pluck('total', 'guarantor_type');

        $antrianAktif = Queue::where('station', 'ADMISI')
            ->whereDate('created_at', $today)
            ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
            ->count();

        $selesai = Visit::whereDate('visit_date', $today)
            ->where('current_station', 'SELESAI')
            ->count();

        // Kunjungan yang dibatalkan hari ini (soft-deleted)
        $cancelCount = Visit::onlyTrashed()
            ->whereDate('visit_date', $today)
            ->count();

        // SEP Terbit = visits hari ini yang punya no_sep
        $sepCount = Visit::whereDate('visit_date', $today)
            ->whereNotNull('no_sep')
            ->count();

        // Bedah (Pre-Op classification)
        $bedahCount = Visit::whereDate('visit_date', $today)
            ->where('classification', 'Pre-Op')
            ->count();

        // Asuransi/Lain-lain = ASURANSI + PERUSAHAAN + SOSIAL
        $asuransiCount = Visit::whereDate('visit_date', $today)
            ->whereIn('guarantor_type', ['ASURANSI', 'PERUSAHAAN', 'SOSIAL'])
            ->count();

        // BPJS system status
        $bpjsSystems = IntegrationConfig::whereIn('system_name', ['VCLAIM', 'ANTREAN', 'ICARE'])
            ->get(['system_name', 'is_enabled', 'last_test_status', 'last_tested_at']);

        return [
            'stat_cards' => [
                'total_kunjungan'  => $totalKunjungan,
                'bpjs_count'       => (int) ($perPenjamin['BPJS'] ?? 0),
                'asuransi_count'   => $asuransiCount,
                'bedah_count'      => $bedahCount,
                'sep_count'        => $sepCount,
                'cancel_count'     => $cancelCount,
                'antrian_aktif'    => $antrianAktif,
                'selesai'          => $selesai,
                'per_klasifikasi'  => $perKlasifikasi,
                'per_station'      => $perStation,
                'per_penjamin'     => $perPenjamin,
            ],
            'bpjs_status' => $bpjsSystems->map(fn ($s) => [
                'system'           => $s->system_name,
                'is_enabled'       => $s->is_enabled,
                'last_test_status' => $s->last_test_status,
                'last_tested_at'   => $s->last_tested_at?->toIso8601String(),
            ])->values(),
        ];
    }

    // =========================================================================
    // KUNJUNGAN
    // =========================================================================

    public function getKunjungan(array $filters): LengthAwarePaginator
    {
        $query = Visit::with([
            'patient',
            'insurer',
            'registeredBy',
            'doctorSchedule.employee:id,name',
            // Ambil semua queue hari ini (terbaru dulu) — mapper di bawah pilih
            // yang paling relevan: queue ADMISI kalau ada (walk-in kiosk), atau
            // queue terbaru di station saat ini (mis. TR-NNN untuk direct admisi).
            'queues' => fn ($q) => $q
                ->select(['id', 'visit_id', 'station', 'queue_number', 'status', 'created_at'])
                ->orderBy('created_at'),
        ])->whereDate('visit_date', $filters['tanggal'] ?? today());

        if (! empty($filters['station'])) {
            $query->where('current_station', $filters['station']);
        }

        if (! empty($filters['guarantor_type'])) {
            $query->where('guarantor_type', $filters['guarantor_type']);
        }

        if (! empty($filters['classification'])) {
            $query->where('classification', $filters['classification']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('patient', fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('no_rm', 'ilike', "%{$search}%")
                ->orWhere('nik', 'like', "%{$search}%")
            );
        }

        $result = $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);

        // Add general_consent_signed flag per visit
        $patientIds = collect($result->items())->pluck('patient_id')->unique()->values()->toArray();

        if (! empty($patientIds)) {
            $signedPatients = PatientDocument::whereIn('patient_id', $patientIds)
                ->where('status', 'FINAL')
                ->whereHas('documentType', fn ($q) => $q
                    ->where('code', 'RM-0.1')
                    ->orWhere('name', 'ilike', '%general consent%')
                )
                ->pluck('patient_id')
                ->unique()
                ->flip();

            foreach ($result->items() as $visit) {
                $visit->no_antrian = $this->pickPrimaryQueueNumber($visit);
                $visit->general_consent_signed = isset($signedPatients[$visit->patient_id]);
            }
        } else {
            foreach ($result->items() as $visit) {
                $visit->no_antrian = $this->pickPrimaryQueueNumber($visit);
                $visit->general_consent_signed = false;
            }
        }

        return $result;
    }

    /**
     * Pilih nomor antrean yang paling relevan untuk ditampilkan di list:
     *  - Kalau pasien masih di stage ADMISI (walk-in kiosk), pakai queue ADMISI.
     *  - Kalau sudah lewat ADMISI (direct daftar atau sudah selesai admisi),
     *    pakai queue TR-NNN (TRIASE/REFRAKSIONIS share prefix).
     *  - Fallback: queue pertama yang tercipta untuk visit ini.
     */
    private function pickPrimaryQueueNumber(Visit $visit): string
    {
        $queues = $visit->queues;
        if ($queues->isEmpty()) return '—';

        // Walk-in: prioritas queue ADMISI yang masih aktif/awal
        $admisi = $queues->firstWhere('station', 'ADMISI');
        if ($admisi && $visit->current_station === 'ADMISI') {
            return $admisi->queue_number;
        }

        // Direct daftar: pakai queue TR (TRIASE/REFRAKSIONIS) yang pasti share nomor
        $tr = $queues->first(fn ($q) => in_array($q->station, ['TRIASE', 'REFRAKSIONIS'], true));
        if ($tr) return $tr->queue_number;

        // Fallback: queue pertama
        return $queues->first()?->queue_number ?? '—';
    }

    public function getKunjunganById(string $id): Visit
    {
        return Visit::with([
            'patient',
            'insurer',
            'registeredBy',
            'queues',
            'visitCob',
            'nurseAssessment',
            'refractionRecord',
        ])->findOrFail($id);
    }

    public function cancelKunjungan(string $id): void
    {
        $visit = Visit::findOrFail($id);

        // Tidak boleh cancel kalau sudah SELESAI (audit-trail integrity)
        if ($visit->current_station === 'SELESAI') {
            throw new \Exception('Kunjungan sudah selesai — tidak bisa dibatalkan.', 422);
        }

        DB::transaction(function () use ($visit) {
            // Cancel semua antrean aktif (WAITING/CALLED/IN_PROGRESS) di station mana pun
            $visit->queues()
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->update(['status' => 'CANCELLED']);
            $visit->delete(); // soft delete
        });

        $this->log(
            auth('api')->id(),
            'CANCEL_KUNJUNGAN',
            Visit::class,
            $id,
            "Kunjungan dibatalkan (station: {$visit->current_station})"
        );
    }

    // =========================================================================
    // PASIEN
    // =========================================================================

    /**
     * Search patient by NIK, BPJS number, no_rm, or name.
     */
    public function cariPasien(string $keyword): Collection
    {
        return Patient::active()
            ->where(function ($q) use ($keyword) {
                $q->where('nik', 'like', "%{$keyword}%")
                    ->orWhere('bpjs_number', 'like', "%{$keyword}%")
                    ->orWhere('no_rm', 'ilike', "%{$keyword}%")
                    ->orWhere('name', 'ilike', "%{$keyword}%");
            })
            ->limit(15)
            ->get();
    }

    public function storePasien(array $data): Patient
    {
        $noRm = $this->generateNoRM();

        $patient = Patient::create([
            'no_rm'        => $noRm,
            'nik'          => $data['nik'],
            'name'         => $data['name'],
            'gender'       => $data['gender'],
            'date_of_birth' => $data['date_of_birth'],
            'phone'        => $data['phone'] ?? null,
            'address'      => $data['address'] ?? null,
            'province'     => $data['province'] ?? null,
            'bpjs_number'  => $data['bpjs_number'] ?? null,
            'blood_type'   => $data['blood_type'] ?? null,
            'allergy_notes' => $data['allergy_notes'] ?? null,
            'is_active'    => true,
        ]);

        $this->log(auth('api')->id(), 'CREATE_PASIEN', Patient::class, $patient->id, "Pasien baru: {$patient->name}");

        return $patient;
    }

    public function getPasienById(string $id): Patient
    {
        return Patient::with(['visits' => fn ($q) => $q->latest()->limit(5)])->findOrFail($id);
    }

    public function updatePasien(string $id, array $data): Patient
    {
        $patient = Patient::findOrFail($id);
        $patient->update($data);

        $this->log(auth('api')->id(), 'UPDATE_PASIEN', Patient::class, $id);

        return $patient->fresh();
    }

    // =========================================================================
    // DAFTAR KUNJUNGAN
    // =========================================================================

    /**
     * Register new visit (langsung daftar di Admisi, BUKAN dari kiosk).
     *
     * Pasien yang datang langsung ke loket admisi sudah teridentifikasi dan
     * langsung diproses petugas — tidak perlu lewat antrian ADMISI lagi.
     * Begitu didaftarkan, pasien langsung masuk antrian TRIASE + REFRAKSIONIS
     * paralel (skip station ADMISI sepenuhnya).
     *
     * Walk-in dari kiosk tetap melewati antrian ADMISI (lihat daftarkanWalkIn).
     */
    public function registerVisit(array $data): Visit
    {
        return DB::transaction(function () use ($data) {
            // Resolve patient
            if (! empty($data['patient_id'])) {
                $patient = Patient::findOrFail($data['patient_id']);
            } else {
                $patient = $this->storePasien($data);
            }

            $user = auth('api')->user();

            $visit = Visit::create([
                'patient_id'         => $patient->id,
                'insurer_id'         => $data['insurer_id'] ?? null,
                'registered_by_id'   => $user->employee_id,
                'doctor_schedule_id' => $data['doctor_schedule_id'],
                'visit_date'         => today(),
                'classification'     => $data['classification'],
                'current_station'    => 'TRIASE',       // skip ADMISI, langsung ke TR
                'guarantor_type'     => $data['guarantor_type'],
                'bpjs_booking_code'  => $data['bpjs_booking_code'] ?? null,
                'satusehat_sync_status' => 'PENDING',
            ]);

            // Langsung enqueue TRIASE + REFRAKSIONIS paralel dengan nomor antrean TR-NNN
            // yang shared (mengikuti pattern QueueService::advanceFromStation).
            $sharedNumber = $this->queueService->generateQueueNumber('TRIASE');
            $this->queueService->enqueue($visit->id, 'TRIASE', $sharedNumber);
            $this->queueService->enqueue($visit->id, 'REFRAKSIONIS', $sharedNumber);

            $this->log(
                $user->id,
                'DAFTAR_KUNJUNGAN',
                Visit::class,
                $visit->id,
                "Kunjungan baru (direct admisi → TR): {$patient->name} ({$data['guarantor_type']}) — {$sharedNumber['queue_number']}"
            );

            return $visit->load(['patient', 'queues']);
        });
    }

    // =========================================================================
    // DAFTARKAN WALK-IN (dari kiosk anjungan)
    // =========================================================================

    /**
     * Update Visit walk-in (dari kiosk) dengan data registrasi lengkap.
     *
     * Dua skenario:
     *  A. patient_id diisi (pasien lama) → ganti visit.patient_id ke pasien real,
     *     soft-delete placeholder patient
     *  B. patient_id kosong (pasien baru) → update placeholder patient dengan
     *     data baru (NIK, nama, gender, DOB, dll) DAN generate no_rm. Kiosk hanya
     *     menerbitkan nomor antrean — no_rm baru lahir saat pasien resmi terdaftar.
     *
     * Pre-condition:
     *  - Visit harus masih placeholder (patient.name === 'Belum Terdaftar')
     *  - Visit harus masih di station ADMISI
     */
    public function daftarkanWalkIn(string $visitId, array $data): Visit
    {
        return DB::transaction(function () use ($visitId, $data) {
            $visit = Visit::with('patient')->lockForUpdate()->findOrFail($visitId);

            if ($visit->patient->name !== 'Belum Terdaftar') {
                throw new \Exception('Kunjungan ini bukan walk-in dari kiosk (sudah terdaftar).', 422);
            }
            if ($visit->current_station !== 'ADMISI') {
                throw new \Exception('Pasien sudah melewati admisi, tidak bisa diubah.', 422);
            }

            // Workflow gate: pasien harus DIPANGGIL dulu sebelum boleh didaftarkan
            $admisiQueue = Queue::where('visit_id', $visit->id)
                ->where('station', Queue::STATION_ADMISI)
                ->whereDate('created_at', today())
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->orderByDesc('created_at')
                ->first();

            if (! $admisiQueue) {
                throw new \Exception('Antrian admisi tidak ditemukan untuk kunjungan ini.', 422);
            }
            if ($admisiQueue->status === Queue::STATUS_WAITING) {
                throw new \Exception('Pasien harus dipanggil terlebih dahulu sebelum didaftarkan.', 422);
            }

            $placeholder = $visit->patient;
            $user        = auth('api')->user();

            // ─── Resolve Patient ─────────────────────────────────────────
            if (! empty($data['patient_id'])) {
                // Skenario A — pasien lama
                $real = Patient::findOrFail($data['patient_id']);

                $visit->patient_id = $real->id;

                // Placeholder tidak dipakai lagi → soft-delete
                $placeholder->delete();
            } else {
                // Skenario B — pasien baru: cek NIK unique (kecuali placeholder sendiri)
                $existing = Patient::where('nik', $data['nik'])
                    ->where('id', '!=', $placeholder->id)
                    ->first();

                if ($existing) {
                    throw new \Exception("NIK {$data['nik']} sudah terdaftar atas nama {$existing->name}. Gunakan mode 'Pasien Lama'.", 422);
                }

                // Generate no_rm SEKARANG — kiosk hanya kasih nomor antrean, RM dibuat
                // begitu pasien resmi terdaftar.
                $placeholder->update([
                    'no_rm'         => $placeholder->no_rm ?? $this->generateNoRM(),
                    'nik'           => $data['nik'],
                    'name'          => $data['name'],
                    'gender'        => $data['gender'],
                    'date_of_birth' => $data['date_of_birth'],
                    'phone'         => $data['phone']         ?? null,
                    'address'       => $data['address']       ?? null,
                    'province'      => $data['province']      ?? null,
                    'bpjs_number'   => $data['bpjs_number']   ?? null,
                    'blood_type'    => $data['blood_type']    ?? null,
                    'allergy_notes' => $data['allergy_notes'] ?? null,
                ]);

                $real = $placeholder->fresh();
            }

            // ─── Update Visit ────────────────────────────────────────────
            $visit->update([
                'patient_id'        => $real->id,
                'insurer_id'        => $data['insurer_id'] ?? null,
                'registered_by_id'  => $user?->employee_id,
                'classification'    => $data['classification'],
                'guarantor_type'    => $data['guarantor_type'],
                'bpjs_booking_code' => $data['bpjs_booking_code'] ?? null,
            ]);

            // ─── Auto-advance ke TRIASE + REFRAKSIONIS ───────────────────
            // Selesaikan admisi otomatis — pasien sudah teridentifikasi & terdaftar
            $this->queueService->advanceFromStation($admisiQueue->id, Queue::STATION_ADMISI);

            $this->log(
                $user?->id,
                'DAFTAR_WALKIN',
                Visit::class,
                $visit->id,
                "Walk-in terdaftar: {$real->name} ({$data['guarantor_type']}) — auto-advance ke TRIASE+REFRAKSIONIS"
            );

            return $visit->fresh(['patient', 'queues']);
        });
    }

    // =========================================================================
    // ANJUNGAN MANDIRI (Kiosk — Public)
    // =========================================================================

    /**
     * Kiosk self-service: ambil tiket antrean UMUM untuk Loket Admisi.
     * Anonymous walk-in — petugas admisi akan lengkapi data pasien saat panggil.
     *
     * Flow:
     *  1. Buat Patient placeholder (NIK unik berbasis microtime, nama "Walk-In Anjungan #A-NNN")
     *  2. Buat Visit (UMUM, classification=Baru, current_station=ADMISI)
     *  3. Buat Queue ADMISI
     *  4. Broadcast AdmisiQueueUpdated (action=added) → AdmisiView auto-append
     */
    public function ambilTiketUmumKiosk(): array
    {
        return DB::transaction(function () {
            // Generate nomor antrean ADMISI (yg jadi suffix nama placeholder)
            $queueData = $this->generateQueueNumber('ADMISI');
            $queueNumber = $queueData['queue_number']; // e.g. A-007

            // 1. Patient placeholder — identitas BELUM ADA (akan diisi saat petugas admisi daftarkan)
            //    no_rm sengaja NULL — baru di-generate saat daftarkanWalkIn sukses.
            $placeholderNik = $this->generateWalkInNik();
            $patient = Patient::create([
                'no_rm'       => null,
                'nik'         => $placeholderNik,
                'name'        => 'Belum Terdaftar',
                'gender'      => null,
                'date_of_birth' => null,
                'is_active'   => true,
            ]);

            // 2. Visit
            $visit = Visit::create([
                'patient_id'        => $patient->id,
                'insurer_id'        => null,
                'registered_by_id'  => null, // kiosk anonymous
                'visit_date'        => today(),
                'classification'    => 'Baru',
                'current_station'   => 'ADMISI',
                'guarantor_type'    => 'UMUM',
                'satusehat_sync_status' => 'PENDING',
            ]);

            // 3. Queue ADMISI (pakai queueData yg sudah di-reserve di awal)
            $queue = Queue::create([
                'visit_id'       => $visit->id,
                'station'        => 'ADMISI',
                'queue_prefix'   => $queueData['queue_prefix'],
                'queue_sequence' => $queueData['queue_sequence'],
                'queue_number'   => $queueData['queue_number'],
                'status'         => 'WAITING',
            ]);

            // 4. Broadcast — dua channel:
            //    a) admisi-queue  → AdmisiView (full payload dengan visit details)
            //    b) antrean-tv    → AntreanTVView (payload lebih ramping)
            $queue->load('visit.patient');
            $admisiPayload = [
                'id'           => $queue->id,
                'visit_id'     => $queue->visit_id,
                'queue_number' => $queue->queue_number,
                'station'      => $queue->station,
                'status'       => $queue->status,
                'created_at'   => $queue->created_at?->toIso8601String(),
                'visit'        => [
                    'id'              => $visit->id,
                    'guarantor_type'  => $visit->guarantor_type,
                    'classification'  => $visit->classification,
                    'current_station' => $visit->current_station,
                    'patient'         => [
                        'id'    => $patient->id,
                        'name'  => $patient->name,
                        'no_rm' => $patient->no_rm,
                    ],
                ],
            ];
            broadcast(new AdmisiQueueUpdated($admisiPayload, 'added'));

            broadcast(new AntreanTvUpdated([
                'id'             => $queue->id,
                'visit_id'       => $queue->visit_id,
                'station'        => $queue->station,
                'queue_number'   => $queue->queue_number,
                'queue_sequence' => $queue->queue_sequence,
                'status'         => $queue->status,
                'called_at'      => null,
                'patient'        => [
                    'no_rm' => $patient->no_rm,
                    'name'  => $patient->name,
                ],
            ], 'added'));

            $this->log(
                null,
                'ANJUNGAN_TIKET_UMUM',
                Visit::class,
                $visit->id,
                "Kiosk: tiket umum {$queueNumber} — patient placeholder {$patient->id}"
            );

            return [
                'queue_number' => $queue->queue_number,
                'queue_id'     => $queue->id,
                'visit_id'     => $visit->id,
                'patient_id'   => $patient->id,
                'no_rm'        => $patient->no_rm,
                'station'      => 'ADMISI',
            ];
        });
    }

    /**
     * Generate NIK placeholder untuk walk-in kiosk (16 digit, unik).
     * Format: '9' + 9 digit unix timestamp + 6 digit random.
     * NIK asli warga selalu diawali kode wilayah 1-3, jadi '9' aman dibedakan.
     */
    private function generateWalkInNik(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $ts     = str_pad((string) time(), 9, '0', STR_PAD_LEFT);            // 9 digit
            $rand   = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); // 6 digit
            $candidate = '9' . substr($ts, -9) . $rand;                          // total 16 digit

            if (! Patient::where('nik', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Gagal generate NIK walk-in unik setelah 10 percobaan.');
    }

    // =========================================================================
    // ANTRIAN ADMISI
    // =========================================================================

    public function getAntrian(): Collection
    {
        return Queue::with(['visit.patient'])
            ->where('station', 'ADMISI')
            ->whereDate('created_at', today())
            ->where('status', '!=', Queue::STATUS_CANCELLED)
            ->whereHas('visit')                       // exclude queue dgn visit soft-deleted (zombie row)
            ->orderBy('queue_sequence')
            ->get();
    }

    public function createAntrianAdmisi(string $visitId): Queue
    {
        $queueData = $this->generateQueueNumber('ADMISI');

        return Queue::create([
            'visit_id'       => $visitId,
            'station'        => 'ADMISI',
            'queue_prefix'   => $queueData['queue_prefix'],
            'queue_sequence' => $queueData['queue_sequence'],
            'queue_number'   => $queueData['queue_number'],
            'status'         => 'WAITING',
        ]);
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_ADMISI)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Admisi (Section 11.3 step 2) → enqueue TRIASE & REFRAKSIONIS paralel.
     * Transisi sekarang di-delegate ke QueueService::advanceFromStation,
     * yang baca aturan dari resolveNextStation('ADMISI') => [TRIASE, REFRAKSIONIS].
     */
    public function selesaiAdmisi(string $queueId): Visit
    {
        $queue = Queue::with('visit')->byStation(Queue::STATION_ADMISI)->findOrFail($queueId);

        if ($queue->visit->current_station !== 'ADMISI') {
            throw new \Exception('Kunjungan ini sudah melewati admisi.', 422);
        }

        $result = $this->queueService->advanceFromStation($queue->id, Queue::STATION_ADMISI);

        $this->log(
            auth('api')->id(),
            'SELESAI_ADMISI',
            Visit::class,
            $result['visit']->id,
            "Admisi selesai — antrian TRIASE + REFRAKSIONIS dibuat"
        );

        return $result['visit'];
    }

    // =========================================================================
    // JADWAL DOKTER
    // =========================================================================

    public function getDoctorSchedules(): array
    {
        $todayDow = (int) date('N'); // 1=Mon..7=Sun

        $doctors = Employee::with(['doctorSchedules' => fn ($q) => $q
            ->where('is_active', true)
            ->orderBy('day_of_week'),
        ])
            ->where('is_active', true)
            ->whereHas('user', fn ($q) => $q
                ->whereHas('role', fn ($rq) => $rq->where('name', 'dokter'))
            )
            ->get();

        return $doctors->map(fn ($doc) => [
            'id'        => $doc->id,
            'name'      => $doc->name,
            'sip'       => $doc->sip ?? null,
            'schedules' => $doc->doctorSchedules->map(fn ($s) => [
                'id'          => $s->id,
                'day_of_week' => $s->day_of_week,
                'day_label'   => ['', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'][$s->day_of_week] ?? '',
                'start_time'  => $s->start_time,
                'end_time'    => $s->end_time,
                'room'        => $s->room,
                'is_active'   => $s->is_active,
                'is_today'    => $s->day_of_week === $todayDow,
            ])->values(),
        ])->toArray();
    }

    public function updateDoctorSchedule(string $id, array $data): DoctorSchedule
    {
        $schedule = DoctorSchedule::findOrFail($id);

        $schedule->update(array_filter([
            'start_time' => $data['start_time'] ?? null,
            'end_time'   => $data['end_time'] ?? null,
            'room'       => $data['room'] ?? null,
            'is_active'  => $data['is_active'] ?? null,
        ], fn ($v) => ! is_null($v)));

        return $schedule->fresh(['employee']);
    }

    public function createDoctorSchedule(string $employeeId, array $data): DoctorSchedule
    {
        $schedule = DoctorSchedule::create([
            'employee_id' => $employeeId,
            'day_of_week' => $data['day_of_week'],
            'start_time'  => $data['start_time'],
            'end_time'    => $data['end_time'],
            'room'        => $data['room'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
        ]);

        return $schedule->load('employee');
    }

    // =========================================================================
    // BPJS STUBS
    // — Placeholder: aktifkan di IntegrasiService saat credentials tersedia
    // =========================================================================

    public function bpjsCekPeserta(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');
        // TODO: call VClaim GET peserta API
        return [];
    }

    public function bpjsGenerateSep(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');
        // TODO: call VClaim POST generate SEP
        return [];
    }

    public function bpjsCancelSep(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');
        // TODO: call VClaim DELETE SEP
        return [];
    }

    public function bpjsCekRujukan(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');
        // TODO: call VClaim GET rujukan
        return [];
    }

    public function bpjsCekSuratKontrol(array $data): array
    {
        $this->assertBpjsEnabled('VCLAIM');
        // TODO: call VClaim GET surat kontrol
        return [];
    }

    public function bpjsValidasiBooking(array $data): array
    {
        $this->assertBpjsEnabled('ANTREAN');
        // TODO: call Antrean BPJS validate booking code
        return [];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Generate no_rm based on ClinicProfile sequence (thread-safe).
     * Format: {YYYYMM}{SEQ padded to rm_seq_length}
     *
     * Resilient: kalau counter clinic.rm_last_seq drift (mis. seeder insert pasien
     * langsung tanpa update sequence), retry-loop skip nomor yg sudah dipakai.
     */
    private function generateNoRM(): string
    {
        $noRm = '';

        DB::transaction(function () use (&$noRm) {
            $clinic = ClinicProfile::lockForUpdate()->firstOrFail();
            $pad    = $clinic->rm_seq_length ?? 4;
            $prefix = now()->format('Ym');
            $seq    = $clinic->rm_last_seq;

            for ($i = 0; $i < 100; $i++) {
                $seq++;
                $candidate = $prefix . str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);

                if (! Patient::withTrashed()->where('no_rm', $candidate)->exists()) {
                    $noRm = $candidate;
                    $clinic->update(['rm_last_seq' => $seq]);
                    return;
                }
            }

            throw new \RuntimeException('Gagal generate no_rm unik setelah 100 percobaan.');
        });

        return $noRm;
    }

    /**
     * Generate queue number — delegate ke QueueService supaya prefix map konsisten
     * (mis. TRIASE/REFRAKSIONIS share prefix "TR" via Queue::SHARED_PREFIX_GROUPS).
     */
    public function generateQueueNumber(string $station): array
    {
        return $this->queueService->generateQueueNumber($station);
    }

    private function assertBpjsEnabled(string $systemName): void
    {
        $config = IntegrationConfig::where('system_name', $systemName)->first();

        if (! $config || ! $config->is_enabled) {
            throw new \Exception("Integrasi {$systemName} belum diaktifkan. Konfigurasi credentials terlebih dahulu.", 503);
        }
    }

    private function log(
        ?string $userId,
        string $action,
        ?string $model = null,
        ?string $modelId = null,
        ?string $description = null
    ): void {
        SystemLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $description,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
