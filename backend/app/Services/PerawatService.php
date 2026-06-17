<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\Employee;
use App\Models\NurseAssessment;
use App\Models\NurseCpptEntry;
use App\Models\PatientDocument;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Queue;
use App\Models\RefractionRecord;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Models\VisitSurgeryPackage;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerawatService
{
    /** Ambang risiko "jadwal dokter hampir habis" untuk badge antrean Triase. */
    private const QUOTA_RISK       = 3;   // sisa kuota dokter <= ini → at-risk
    private const SESSION_RISK_MIN = 30;  // sesi (end_time) tersisa <= menit ini → at-risk

    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
        private readonly AntreanKuotaService $kuotaService,
    ) {}

    /** Cache sisa-kuota per (doctor_schedule_id|jkn|nonjkn) agar tak N+1 dalam 1 request. */
    private array $riskCache = [];

    // =========================================================================
    // ANTRIAN TRIASE
    // =========================================================================

    public function getPatientQueue(): array
    {
        $queues = Queue::with([
            'visit.patient',
            'visit.nurseAssessment',
            'visit.insurer',
            'visit.doctorSchedule.employee',
            'visit.refractionRecord',   // nilai Refraksi (visus/IOP) utk tampil di kartu pasien triase
            // sibling status (REFRAKSIONIS) untuk cegah panggil-ganda paralel
            'visit.queues' => fn ($q) => $q->whereDate('created_at', today()),
        ])
            ->where('station', 'TRIASE')
            ->boardVisibleOpenBilling()   // +pasien belum tutup kasir (Masih Aktif)
            ->whereHas('visit')   // exclude zombie row (visit soft-deleted)
            ->orderBy('queue_sequence')
            ->get()
            ->map(fn ($q) => $this->formatQueueItem($q));

        return [
            'stats' => [
                'belum_dipanggil' => $queues->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])->count(),
                'selesai'         => $queues->where('status', 'COMPLETED')->count(),
                'total'           => $queues->count(),
            ],
            'queues' => $queues->values(),
        ];
    }

    public function getKunjungan(string $visitId): Visit
    {
        return Visit::with([
            'patient',
            'insurer',
            'queues' => fn ($q) => $q->where('station', 'TRIASE'),
            'nurseAssessment.assessedBy',
        ])->findOrFail($visitId);
    }

   public function panggilAntrian(string $queueId): array
    {
        Queue::where('station', 'TRIASE')->findOrFail($queueId);

        // Delegate ke QueueService biar broadcast AntreanTvUpdated ikut ter-fire
        // (TV station TR). QueueService::panggil sudah handle re-call dari
        // WAITING/CALLED/IN_PROGRESS.
        $queue = $this->queueService->panggil($queueId);

        return (array) $this->formatQueueItem($queue);
    }

    public function mulaiAntrian(string $queueId): array
    {
        Queue::where('station', 'TRIASE')->findOrFail($queueId);

        $queue = $this->queueService->mulai($queueId);

        return (array) $this->formatQueueItem($queue);
    }

    /**
     * Selesai antrian Triase (Section 11.3 step 3).
     *
     * Gate paralel:
     *   - jika asesmen perawat BELUM finalize → tolak (perawat wajib finalize dulu)
     *   - jika refraksi belum finalize → tutup queue Triase, current_station tetap REFRAKSIONIS
     *   - jika keduanya sudah finalize → tutup queue, advance ke DOKTER
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::with('visit')->where('station', 'TRIASE')->findOrFail($queueId);

        $assessment = NurseAssessment::where('visit_id', $queue->visit_id)->first();
        if (! $assessment || ! $assessment->is_finalized) {
            throw new \Exception('Asesmen perawat belum di-finalize. Selesaikan asesmen dulu.', 422);
        }

        // finalizeAssessment()/skipTriase() SUDAH menutup queue TRIASE + memajukan antrean
        // (checkReadyForDoctor). Bila baris sudah COMPLETED, ini no-op idempoten — jangan
        // lempar "Antrian sudah ditutup" dari advanceFromStation.
        if ($queue->status === Queue::STATUS_COMPLETED) {
            return [
                'queue'        => $queue->fresh(['visit.patient']),
                'visit'        => $queue->visit?->fresh(['patient', 'queues']),
                'next_station' => null,
                'next_queue'   => null,
            ];
        }

        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_TRIASE);
    }

    /**
     * Data tiket Dokter (D-NNN + poliklinik/ruang/dokter) untuk dicetak di stasiun
     * TR setelah finalize. Null kalau antrian DOKTER belum dibuat (partner belum selesai).
     */
    public function doctorTicket(string $visitId): ?array
    {
        return $this->queueService->getDoctorTicket($visitId);
    }

    public function lewatiAntrian(string $queueId): array
    {
        Queue::where('station', 'TRIASE')->findOrFail($queueId);

        $queue = $this->queueService->lewati($queueId);

        return (array) $this->formatQueueItem($queue);
    }

    /**
     * Dahulukan pasien di antrean TRIASE (mis. jadwal dokternya hampir habis) →
     * naik ke paling atas band aktif. Delegasi ke QueueService::dahulukan.
     */
    public function dahulukanAntrian(string $queueId): array
    {
        Queue::where('station', 'TRIASE')->findOrFail($queueId);

        $queue = $this->queueService->dahulukan($queueId);

        return (array) $this->formatQueueItem($queue);
    }

    // =========================================================================
    // ASESMEN
    // =========================================================================

    public function getAsesmen(string $visitId): ?NurseAssessment
    {
        return NurseAssessment::with(['assessedBy', 'finalizedBy'])
            ->where('visit_id', $visitId)
            ->first();
    }

    public function storeAssessment(string $visitId, array $data): NurseAssessment
    {
        Visit::findOrFail($visitId);

        if (NurseAssessment::where('visit_id', $visitId)->exists()) {
            throw new \Exception('Asesmen sudah ada. Gunakan update untuk mengubah data.', 422);
        }

        $user = auth('api')->user();

        $assessment = NurseAssessment::create([
            'visit_id'         => $visitId,
            'assessed_by_id'   => $user->employee_id,
            'td_sistol'        => $data['td_sistol']  ?? null,
            'td_diastol'       => $data['td_diastol'] ?? null,
            'nadi'             => $data['nadi']      ?? null,
            'suhu'             => $data['suhu']      ?? null,
            'respirasi'        => $data['respirasi'] ?? null,
            'spo2'             => $data['spo2']      ?? null,
            'kgd'              => $data['kgd']       ?? null,
            'pain_scale'       => $data['pain_scale'] ?? null,
            'berat_badan'      => $data['berat_badan'] ?? null,
            'tinggi_badan'     => $data['tinggi_badan'] ?? null,
            'bmi'              => $this->calculateBmi($data['berat_badan'] ?? null, $data['tinggi_badan'] ?? null),
            'has_allergy'      => $data['has_allergy'] ?? false,
            'allergy_detail'   => ($data['has_allergy'] ?? false) ? ($data['allergy_detail'] ?? null) : null,
            'chief_complaint'  => $data['chief_complaint'] ?? null,
            'rps'              => $data['rps'] ?? null,
            'assessment_notes' => $data['assessment_notes'] ?? null,
            'is_finalized'     => false,
        ]);

        $this->log($user->id, 'STORE_ASESMEN', NurseAssessment::class, $assessment->id);

        return $assessment->load('assessedBy');
    }

    public function updateAssessment(string $id, array $data): NurseAssessment
    {
        $assessment = NurseAssessment::findOrFail($id);

        if ($assessment->is_finalized) {
            throw new \Exception('Asesmen sudah dikunci, tidak bisa diubah.', 422);
        }

        // Key-aware patch: hanya field yang BENAR-BENAR dikirim FE yang disentuh.
        // PENTING: jangan pakai array_filter(!is_null) — null eksplisit harus
        // tersimpan (mis. perawat hapus semua alergi → allergy_detail=null;
        // kosongkan BB/TB → kolom + BMI ikut di-clear). Lihat regresi audit.
        $patch = [];
        $simpleFields = [
            'td_sistol', 'td_diastol', 'nadi', 'suhu', 'respirasi', 'spo2', 'kgd',
            'pain_scale', 'berat_badan', 'tinggi_badan',
            'chief_complaint', 'rps', 'assessment_notes',
        ];
        foreach ($simpleFields as $field) {
            if (array_key_exists($field, $data)) {
                $patch[$field] = $data[$field];
            }
        }

        // Alergi: bila has_allergy dikirim, normalisasi allergy_detail.
        // has_allergy=false → detail di-null-kan (alergi lama tidak boleh "nempel").
        if (array_key_exists('has_allergy', $data)) {
            $patch['has_allergy']    = (bool) $data['has_allergy'];
            $patch['allergy_detail'] = $patch['has_allergy'] ? ($data['allergy_detail'] ?? null) : null;
        } elseif (array_key_exists('allergy_detail', $data)) {
            $patch['allergy_detail'] = $data['allergy_detail'];
        }

        // Recompute BMI saat BB atau TB di-update (termasuk saat di-clear → BMI null).
        if (array_key_exists('berat_badan', $data) || array_key_exists('tinggi_badan', $data)) {
            $bb = $patch['berat_badan'] ?? $assessment->berat_badan;
            $tb = $patch['tinggi_badan'] ?? $assessment->tinggi_badan;
            $patch['bmi'] = $this->calculateBmi(
                $bb !== null ? (float) $bb : null,
                $tb !== null ? (float) $tb : null,
            );
        }

        $assessment->update($patch);
        $this->log(auth('api')->id(), 'UPDATE_ASESMEN', NurseAssessment::class, $id);

        return $assessment->fresh(['assessedBy']);
    }

    public function finalizeAssessment(string $assessmentId): NurseAssessment
    {
        $assessment = NurseAssessment::with('visit')->findOrFail($assessmentId);

        if ($assessment->is_finalized) {
            throw new \Exception('Asesmen sudah dikunci.', 422);
        }

        // Tidak ada field wajib — perawat boleh mengunci asesmen tanpa TTV/keluhan
        // (mis. pasien tidak dapat diperiksa / hanya skrining minimal).

        $user = auth('api')->user();

        DB::transaction(function () use ($assessment, $user) {
            $assessment->update([
                'is_finalized'    => true,
                'finalized_at'    => now(),
                'finalized_by_id' => $user->employee_id,
            ]);

            Queue::where('visit_id', $assessment->visit_id)
                ->where('station', 'TRIASE')
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

            $assessment->visit->update(['triase_completed_at' => now()]);
        });

        $this->log($user->id, 'FINALIZE_ASESMEN', NurseAssessment::class, $assessmentId);
        $this->checkReadyForDoctor($assessment->visit_id);

        return $assessment->fresh(['assessedBy', 'finalizedBy']);
    }

    /**
     * Buka kunci asesmen triase (periksa ulang atas permintaan dokter).
     * is_finalized→false + hapus penanda finalisasi + buka kembali antrean TRIASE
     * (COMPLETED → WAITING) supaya pasien bisa di-Panggil, direvisi, lalu finalisasi ulang.
     * TIDAK menyentuh ready_for_doctor / antrean DOKTER (slot dokter tetap); saat finalisasi
     * ulang checkReadyForDoctor no-op + alreadyQueued → tanpa tiket DOKTER dobel.
     */
    public function reopenAssessment(string $assessmentId): NurseAssessment
    {
        $assessment = NurseAssessment::with('visit')->findOrFail($assessmentId);

        if (! $assessment->is_finalized) {
            throw new \Exception('Asesmen triase belum dikunci.', 422);
        }

        $user = auth('api')->user();

        DB::transaction(function () use ($assessment) {
            $assessment->update([
                'is_finalized'    => false,
                'finalized_at'    => null,
                'finalized_by_id' => null,
            ]);

            // Buka kembali antrean TRIASE yang sudah COMPLETED (tanpa filter tanggal:
            // visit lintas-hari yang di-finalize kemarin tetap bisa dibuka untuk
            // periksa ulang; status COMPLETED + visit_id sudah cukup membatasi).
            Queue::where('visit_id', $assessment->visit_id)
                ->where('station', Queue::STATION_TRIASE)
                ->where('status', Queue::STATUS_COMPLETED)
                ->update([
                    'status'       => Queue::STATUS_WAITING,
                    'completed_at' => null,
                    'called_at'    => null,
                    'started_at'   => null,
                ]);
        });

        $this->log($user->id, 'REOPEN_TRIASE', NurseAssessment::class, $assessmentId, "Asesmen triase dibuka kembali (periksa ulang) untuk kunjungan {$assessment->visit_id}");

        return $assessment->fresh(['assessedBy', 'finalizedBy']);
    }

    /**
     * Lewati Triase (pasien tidak perlu triase) — finalize asesmen TANPA data klinis
     * dengan is_skipped=true, tutup antrean TRIASE, lalu jalankan gate paralel.
     * Membuat antrean tetap maju ke DOKTER tanpa fabrikasi data vital.
     */
    public function skipTriase(string $queueId): array
    {
        return DB::transaction(function () use ($queueId) {
            $queue = Queue::with('visit')->byStation(Queue::STATION_TRIASE)->lockForUpdate()->findOrFail($queueId);
            $visit = $queue->visit;
            $user  = auth('api')->user();

            $assessment = NurseAssessment::firstOrNew(['visit_id' => $visit->id]);
            if ($assessment->is_finalized && ! $assessment->is_skipped) {
                throw new \Exception('Asesmen triase sudah difinalisasi — tidak bisa dilewati.', 422);
            }

            $assessment->fill([
                'assessed_by_id'  => $assessment->assessed_by_id ?? $user->employee_id,
                'chief_complaint' => $assessment->chief_complaint ?: 'Triase dilewati — tidak diperlukan.',
                'is_skipped'      => true,
                'is_finalized'    => true,
                'finalized_at'    => now(),
                'finalized_by_id' => $user->employee_id,
            ]);
            $assessment->save();

            Queue::where('visit_id', $visit->id)
                ->where('station', Queue::STATION_TRIASE)
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

            $visit->update(['triase_completed_at' => now()]);

            $this->log($user->id, 'SKIP_TRIASE', NurseAssessment::class, $assessment->id, 'Triase dilewati — tidak diperlukan');
            $this->checkReadyForDoctor($visit->id);

            return ['skipped' => true, 'visit_id' => $visit->id];
        });
    }

    // =========================================================================
    // PREOP BEDAH — Kirim ke Bedah (manual transition)
    // =========================================================================

    /**
     * Transisi manual TRIASE → BEDAH untuk visit PREOP_BEDAH.
     *
     * Dipanggil dari tombol "Kirim ke Bedah" di PerawatView. Boleh ditekan oleh
     * role 'perawat' atau 'dokter' (dokter umum boleh isi triase preop juga).
     *
     * Gate sama spt transisi ke DOKTER regular: NurseAssessment.is_finalized=true
     * DAN RefractionRecord.is_finalized=true. Anti-duplikat: refuse jika sudah ada
     * baris BEDAH aktif untuk visit ini hari ini.
     */
    public function kirimKeBedah(string $queueId): array
    {
        return DB::transaction(function () use ($queueId) {
            $user = auth('api')->user();

            // Role check: hanya perawat & dokter (superadmin bypass).
            // 'dokter_umum' bekerja di Triase (punya perawat.write) → wajib termasuk,
            // selaras pesan error & desain (lihat memory feature-data-pengguna-view).
            $roleName = $user?->role?->name;
            if (! in_array($roleName, ['perawat', 'dokter', 'dokter_umum', 'superadmin'], true)) {
                throw new \Exception('Hanya perawat atau dokter umum yang boleh mengirim pasien ke bedah.', 403);
            }

            $queue = Queue::with('visit')->lockForUpdate()->findOrFail($queueId);

            if ($queue->station !== Queue::STATION_TRIASE) {
                throw new \Exception("Tombol ini hanya untuk antrian TRIASE (saat ini: {$queue->station}).", 422);
            }

            $visit = $queue->visit;
            if ($visit->visit_type !== 'PREOP_BEDAH') {
                throw new \Exception('Pasien ini bukan PREOP_BEDAH — gunakan tombol Selesai Asesmen biasa.', 422);
            }

            // Gate paralel
            $triaseDone   = NurseAssessment::where('visit_id', $visit->id)->where('is_finalized', true)->exists();
            $refraksiDone = RefractionRecord::where('visit_id', $visit->id)->where('is_finalized', true)->exists();
            if (! $triaseDone) {
                throw new \Exception('Asesmen triase belum di-finalize.', 422);
            }
            if (! $refraksiDone) {
                throw new \Exception('Pemeriksaan refraksi belum di-finalize.', 422);
            }

            // Anti-duplikat (tanpa filter tanggal: visit lintas-hari bisa punya baris
            // Bedah aktif dari kemarin — harus tetap terdeteksi agar tak ganda).
            $alreadyBedah = Queue::byStation(Queue::STATION_BEDAH)
                ->where('visit_id', $visit->id)
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->exists();
            if ($alreadyBedah) {
                throw new \Exception('Pasien sudah ada di antrian Bedah.', 422);
            }

            // Tutup queue TRIASE & REFRAKSIONIS yg masih aktif untuk visit ini
            Queue::where('visit_id', $visit->id)
                ->whereIn('station', [Queue::STATION_TRIASE, Queue::STATION_REFRAKSIONIS])
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->update(['status' => Queue::STATUS_COMPLETED, 'completed_at' => now()]);

            // Enqueue BEDAH
            $bedahQueue = $this->queueService->enqueue($visit->id, Queue::STATION_BEDAH);

            // Update visit state
            $visit->update([
                'ready_for_doctor'      => true,  // semantik: gate pre-op selesai
                'triase_completed_at'   => $visit->triase_completed_at ?? now(),
                'refraksi_completed_at' => $visit->refraksi_completed_at ?? now(),
                'current_station'       => Queue::STATION_BEDAH,
            ]);

            $this->log(
                $user?->id,
                'KIRIM_KE_BEDAH',
                Visit::class,
                $visit->id,
                "Preop selesai (oleh {$roleName}) → enqueue BEDAH {$bedahQueue->queue_number}"
            );

            return [
                'queue'        => $queue->fresh(['visit.patient']),
                'bedah_queue'  => $bedahQueue->fresh(['visit.patient']),
                'visit'        => $visit->fresh(['patient', 'queues']),
            ];
        });
    }

    /**
     * Jalur B — Transisi manual TRIASE → DOKTER untuk visit PREOP_BEDAH yang perlu
     * diperiksa ULANG oleh dokter operator sebelum naik OT (sesuai kondisi pasien /
     * instruksi dokter). Alternatif dari kirimKeBedah (jalur A: langsung OT).
     *
     * Pasien masuk papan DokterView OPERATOR (surgery_schedules.lead_surgeon_id) —
     * scope papan & guard kepemilikan DokterService punya fallback lead_surgeon utk
     * visit preop tanpa doctor_schedule. Setelah dokter selesai (Kirim/Lanjutkan),
     * QueueService::nextAfterDokter cabang 2d merutekan ke BEDAH (jadwal hari ini).
     *
     * Gate sama spt kirimKeBedah: NurseAssessment & RefractionRecord finalized.
     */
    public function kirimKeDokter(string $queueId): array
    {
        return DB::transaction(function () use ($queueId) {
            $user = auth('api')->user();

            $roleName = $user?->role?->name;
            if (! in_array($roleName, ['perawat', 'dokter', 'dokter_umum', 'superadmin'], true)) {
                throw new \Exception('Hanya perawat atau dokter umum yang boleh mengirim pasien ke dokter.', 403);
            }

            $queue = Queue::with('visit.surgerySchedule')->lockForUpdate()->findOrFail($queueId);

            if ($queue->station !== Queue::STATION_TRIASE) {
                throw new \Exception("Tombol ini hanya untuk antrian TRIASE (saat ini: {$queue->station}).", 422);
            }

            $visit = $queue->visit;
            if ($visit->visit_type !== 'PREOP_BEDAH') {
                throw new \Exception('Pasien ini bukan PREOP_BEDAH — pasien biasa otomatis ke dokter saat asesmen final.', 422);
            }
            if ($visit->inpatient_reason === 'PRE_OP') {
                throw new \Exception('Pasien pre-op rawat inap — gunakan tombol Kirim ke Rawat Inap.', 422);
            }

            // Pasien harus muncul di papan dokter OPERATOR — tanpa operator tak ada
            // papan tujuan (visit preop tidak punya doctor_schedule).
            if (! $visit->surgerySchedule?->lead_surgeon_id) {
                throw new \Exception('Jadwal bedah pasien belum punya dokter operator.', 422);
            }

            // Gate paralel (sama dengan kirimKeBedah).
            $triaseDone   = NurseAssessment::where('visit_id', $visit->id)->where('is_finalized', true)->exists();
            $refraksiDone = RefractionRecord::where('visit_id', $visit->id)->where('is_finalized', true)->exists();
            if (! $triaseDone) {
                throw new \Exception('Asesmen triase belum di-finalize.', 422);
            }
            if (! $refraksiDone) {
                throw new \Exception('Pemeriksaan refraksi belum di-finalize.', 422);
            }

            // Anti-duplikat (tanpa filter tanggal, pola kirimKeBedah).
            $alreadyDokter = Queue::byStation(Queue::STATION_DOKTER)
                ->where('visit_id', $visit->id)
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->exists();
            if ($alreadyDokter) {
                throw new \Exception('Pasien sudah ada di antrian Dokter.', 422);
            }
            $alreadyBedah = Queue::byStation(Queue::STATION_BEDAH)
                ->where('visit_id', $visit->id)
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->exists();
            if ($alreadyBedah) {
                throw new \Exception('Pasien sudah ada di antrian Bedah.', 422);
            }

            // Tutup queue TRIASE & REFRAKSIONIS yg masih aktif untuk visit ini.
            Queue::where('visit_id', $visit->id)
                ->whereIn('station', [Queue::STATION_TRIASE, Queue::STATION_REFRAKSIONIS])
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->update(['status' => Queue::STATUS_COMPLETED, 'completed_at' => now()]);

            // Enqueue DOKTER (visit tanpa doctor_schedule → nomor fallback "D-NNN").
            $dokterQueue = $this->queueService->enqueue($visit->id, Queue::STATION_DOKTER);

            $visit->update([
                'ready_for_doctor'      => true,  // semantik: gate pre-op selesai
                'triase_completed_at'   => $visit->triase_completed_at ?? now(),
                'refraksi_completed_at' => $visit->refraksi_completed_at ?? now(),
                'current_station'       => Queue::STATION_DOKTER,
            ]);

            $this->log(
                $user?->id,
                'KIRIM_KE_DOKTER_PREOP',
                Visit::class,
                $visit->id,
                "Preop periksa ulang (oleh {$roleName}) → enqueue DOKTER {$dokterQueue->queue_number} (operator)"
            );

            return [
                'queue'        => $queue->fresh(['visit.patient']),
                'dokter_queue' => $dokterQueue->fresh(['visit.patient']),
                'visit'        => $visit->fresh(['patient', 'queues']),
            ];
        });
    }

    /**
     * Fase 8B — Transisi manual TRIASE → MENUNGGU_RANAP untuk pasien PRE-OP RAWAT INAP.
     *
     * Dipanggil dari tombol "Kirim ke Rawat Inap" di PerawatView setelah asesmen pre-op
     * (TRIASE + REFRAKSIONIS) selesai. Beda dari kirimKeBedah: pasien TIDAK ke antrean
     * BEDAH (operasi hari-H), melainkan masuk papan "Menunggu Kamar" (current_station=
     * MENUNGGU_RANAP) untuk diopname H-1. Petugas RANAP admit bed via RanapService::admit;
     * saat hari operasi, RANAP→Bedah (RanapService::sendToBedah) pakai jadwal dokter (8C).
     *
     * MENUNGGU_RANAP = PAPAN (nilai current_station), bukan station antrean → tutup baris
     * TR/REF langsung + set current_station, TANPA enqueue baris baru (pola disposisi
     * pasca-op BedahService).
     *
     * Gate sama spt kirimKeBedah: NurseAssessment.is_finalized & RefractionRecord.is_finalized.
     */
    public function kirimKeRanap(string $queueId): array
    {
        return DB::transaction(function () use ($queueId) {
            $user = auth('api')->user();

            $roleName = $user?->role?->name;
            if (! in_array($roleName, ['perawat', 'dokter', 'dokter_umum', 'superadmin'], true)) {
                throw new \Exception('Hanya perawat atau dokter umum yang boleh mengirim pasien ke rawat inap.', 403);
            }

            $queue = Queue::with('visit')->lockForUpdate()->findOrFail($queueId);

            if ($queue->station !== Queue::STATION_TRIASE) {
                throw new \Exception("Tombol ini hanya untuk antrian TRIASE (saat ini: {$queue->station}).", 422);
            }

            $visit = $queue->visit;
            if ($visit->visit_type !== 'PREOP_BEDAH' || $visit->inpatient_reason !== 'PRE_OP') {
                throw new \Exception('Pasien ini bukan pre-op rawat inap — gunakan tombol yang sesuai.', 422);
            }

            // Gate paralel (sama dengan kirimKeBedah).
            $triaseDone   = NurseAssessment::where('visit_id', $visit->id)->where('is_finalized', true)->exists();
            $refraksiDone = RefractionRecord::where('visit_id', $visit->id)->where('is_finalized', true)->exists();
            if (! $triaseDone) {
                throw new \Exception('Asesmen triase belum di-finalize.', 422);
            }
            if (! $refraksiDone) {
                throw new \Exception('Pemeriksaan refraksi belum di-finalize.', 422);
            }

            // Anti-duplikat: sudah di papan Menunggu Kamar / sudah RANAP.
            if (in_array($visit->current_station, ['MENUNGGU_RANAP', 'RANAP'], true)) {
                throw new \Exception('Pasien sudah berada di alur rawat inap.', 422);
            }

            // Tutup queue TRIASE & REFRAKSIONIS yg masih aktif untuk visit ini.
            Queue::where('visit_id', $visit->id)
                ->whereIn('station', [Queue::STATION_TRIASE, Queue::STATION_REFRAKSIONIS])
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->update(['status' => Queue::STATUS_COMPLETED, 'completed_at' => now()]);

            // Masuk papan Menunggu Kamar (TANPA enqueue baris baru).
            $visit->update([
                'ready_for_doctor'      => true,  // semantik: gate pre-op selesai
                'triase_completed_at'   => $visit->triase_completed_at ?? now(),
                'refraksi_completed_at' => $visit->refraksi_completed_at ?? now(),
                'current_station'       => 'MENUNGGU_RANAP',
            ]);

            $this->log(
                $user?->id,
                'KIRIM_KE_RANAP',
                Visit::class,
                $visit->id,
                "Pre-op rawat inap selesai (oleh {$roleName}) → papan Menunggu Kamar"
            );

            return [
                'queue' => $queue->fresh(['visit.patient']),
                'visit' => $visit->fresh(['patient', 'queues']),
            ];
        });
    }

    // =========================================================================
    // INSTRUKSI OBAT PRE-OP (dokter jaga di Triase — stat-dose, visit PREOP_BEDAH)
    // =========================================================================

    /**
     * Simpan instruksi obat pre-operasi (stat-dose, mis. obat tensi/gula) yang
     * diberikan dokter jaga di Triase untuk pasien PREOP_BEDAH. Cermin
     * BedahService::storePostOpPrescription tapi keyed by VISIT (surgery record
     * belum ada saat triase) dan ber-flag is_pre_op.
     *
     * Peresep = user login: WAJIB dokter (employees.doctor_type terisi) — perawat
     * mencatat pemberiannya di CPPT, bukan membuat resep. Resep lahir SUBMITTED →
     * langsung masuk antrean verifikasi Farmasi; billing digate verified_at
     * (KasirService::buildObatLines) dan assertObatVerified, jadi pelunasan tak
     * bisa terjadi sebelum Farmasi memverifikasi.
     *
     * `absorbed` per item = "terserap ke paket": baris TETAP tampil positif di
     * kwitansi & tetap lewat Farmasi (stok); nilainya ikut basis DISKON_PAKET
     * (KasirService::preopAbsorbedBasis) sehingga total bersih = harga jual paket.
     * Hanya di-set bila pasien punya paket bedah (snapshot BEDAH atau paket pada
     * jadwal bedahnya) — tanpa paket, item selalu ditagih aditif.
     *
     * @param array<int,array{medication_id?:string,quantity?:int,dose?:string,
     *              frequency?:string,route?:string,duration_days?:int,notes?:string,
     *              absorbed?:bool}> $items
     * @param array{notes?:string,pharmacy_note?:string} $opts
     */
    public function storePreopPrescription(string $visitId, array $items, array $opts = []): ?Prescription
    {
        $user = auth('api')->user();

        // Gate peresep: hanya dokter (jaga/umum/spesialis/anestesi). Sekaligus
        // menjamin prescribed_by_id NOT NULL terpenuhi.
        $doctorType = $user?->employee?->doctor_type;
        if (! $user?->employee_id || ! in_array($doctorType, Employee::DOCTOR_TYPES, true)) {
            throw new \Exception('Hanya dokter (dokter jaga) yang boleh menyimpan instruksi obat pre-op. Silakan login dengan akun dokter.', 403);
        }

        $visit = Visit::with('surgerySchedule')->findOrFail($visitId);
        if ($visit->visit_type !== 'PREOP_BEDAH') {
            throw new \Exception('Instruksi obat pre-op hanya untuk pasien PREOP_BEDAH.', 422);
        }

        $this->assertPreopRxEditable($visitId);

        // Penyerapan ke paket valid bila snapshot BEDAH sudah ada ATAU jadwal bedah
        // pasien membawa paket (snapshot baru dibuat di BedahView — saat triase
        // biasanya belum ada).
        $canAbsorb = VisitSurgeryPackage::where('visit_id', $visitId)
                ->where('package_type', VisitSurgeryPackage::TYPE_BEDAH)
                ->exists()
            || (bool) $visit->surgerySchedule?->surgery_package_id;

        $prescription = DB::transaction(function () use ($visitId, $items, $opts, $user, $canAbsorb) {
            // REPLACE resep pre-op lama yang masih bisa direvisi (DRAFT/SUBMITTED) +
            // itemnya. Delete+recreate → verified_at null = wajib verifikasi ulang.
            // Resep dokter Tab 3 / pasca-bedah pada visit ini TIDAK disentuh.
            $olds = Prescription::where('visit_id', $visitId)
                ->where('is_pre_op', true)
                ->whereIn('status', ['DRAFT', 'SUBMITTED'])
                ->get();
            foreach ($olds as $old) {
                PrescriptionItem::where('prescription_id', $old->id)->delete();
                $old->delete();
            }

            $valid = array_values(array_filter($items, fn ($it) => ! empty($it['medication_id'])));
            if (empty($valid)) {
                $this->log($user->id, 'STORE_PREOP_RESEP', Prescription::class, $visitId, 'Instruksi obat pre-op dikosongkan');
                return null;
            }

            $prescription = Prescription::create([
                'visit_id'         => $visitId,
                'prescribed_by_id' => $user->employee_id,
                'status'           => 'SUBMITTED',
                'is_pre_op'        => true,
                'notes'            => $opts['notes'] ?? 'Obat pre-operasi (dokter jaga)',
                'pharmacy_note'    => $opts['pharmacy_note'] ?? null,
            ]);

            foreach ($valid as $it) {
                $pi = new PrescriptionItem([
                    'prescription_id' => $prescription->id,
                    'medication_id'   => $it['medication_id'],
                    'quantity'        => $it['quantity'] ?? 1,
                    'dose'            => $it['dose'] ?? null,
                    'frequency'       => $it['frequency'] ?? 'stat',
                    'route'           => $it['route'] ?? null,
                    'duration_days'   => $it['duration_days'] ?? 1,
                    'notes'           => $it['notes'] ?? null,
                ]);
                // Di-set langsung (bukan mass-assignment) — kolom flag tidak masuk
                // $fillable PrescriptionItem.
                $pi->is_preop_absorbed = ($it['absorbed'] ?? false) && $canAbsorb;
                $pi->save();
            }

            $this->log(
                $user->id,
                'STORE_PREOP_RESEP',
                Prescription::class,
                $prescription->id,
                "Instruksi obat pre-op (replace) untuk kunjungan {$visitId}"
            );

            return $prescription->load('items.medication');
        });

        // Bila tagihan aktif (belum bayar) sudah ada — jarang saat triase — bangun
        // ulang agar kwitansi mengikuti. Tak melempar (pola reconsolidatePostOpRevision).
        $this->reconsolidatePreopRevision($visitId);

        return $prescription;
    }

    /**
     * Muat instruksi obat pre-op aktif + flag gating untuk panel PerawatView
     * (dokter: editable; perawat: read-only).
     */
    public function getPreopPrescription(string $visitId): array
    {
        $visit = Visit::with('surgerySchedule')->findOrFail($visitId);

        $rx = Prescription::with(['items.medication', 'prescribedBy:id,name'])
            ->where('visit_id', $visitId)
            ->where('is_pre_op', true)
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'DISPENSING', 'DISPENSED'])
            ->latest('created_at')
            ->first();

        $invoice = BillingInvoice::where('visit_id', $visitId)
            ->where('status', '!=', 'CANCELLED')->first();

        $items = [];
        foreach ($rx?->items ?? [] as $it) {
            $items[] = [
                'medication_id' => $it->medication_id,
                'nama'          => $it->medication?->name ?? '—',
                'jumlah'        => (int) $it->quantity,
                'dosis'         => $it->dose,
                'frequency'     => $it->frequency,
                'duration_days' => $it->duration_days,
                'route'         => $it->route,
                'absorbed'      => (bool) $it->is_preop_absorbed,
            ];
        }

        $canAbsorb = VisitSurgeryPackage::where('visit_id', $visitId)
                ->where('package_type', VisitSurgeryPackage::TYPE_BEDAH)
                ->exists()
            || (bool) $visit->surgerySchedule?->surgery_package_id;

        return [
            'prescription_id' => $rx?->id,
            'sent'            => (bool) $rx,
            'dispensing'      => $rx ? in_array($rx->status, ['DISPENSING', 'DISPENSED'], true) : false,
            'verified'        => (bool) $rx?->verified_at,
            'prescriber'      => $rx?->prescribedBy?->name,
            'items'           => $items,
            'can_absorb'      => $canAbsorb,
            'has_invoice'     => (bool) $invoice,
            'billing_paid'    => $invoice ? in_array($invoice->status, ['PAID', 'PARTIALLY_PAID'], true) : false,
        ];
    }

    /**
     * Tolak revisi instruksi pre-op bila pembayaran sudah dikonfirmasi atau obatnya
     * sudah diserahkan Farmasi (pola assertPostOpEditable).
     */
    private function assertPreopRxEditable(string $visitId): void
    {
        $paid = BillingInvoice::where('visit_id', $visitId)
            ->whereIn('status', ['PAID', 'PARTIALLY_PAID'])->exists();
        if ($paid) {
            throw new \Exception('Pembayaran sudah dikonfirmasi di kasir — instruksi obat pre-op tidak bisa diubah.', 422);
        }

        $dispensed = Prescription::where('visit_id', $visitId)
            ->where('is_pre_op', true)
            ->whereIn('status', ['DISPENSING', 'DISPENSED'])->exists();
        if ($dispensed) {
            throw new \Exception('Obat pre-op sudah diserahkan Farmasi, tidak bisa diubah.', 422);
        }
    }

    /** Rebuild kwitansi non-throwing pasca revisi pre-op (pola reconsolidatePostOpRevision). */
    private function reconsolidatePreopRevision(string $visitId): void
    {
        $invoice = BillingInvoice::where('visit_id', $visitId)
            ->where('status', '!=', 'CANCELLED')->first();
        if (! $invoice || in_array($invoice->status, ['PAID', 'PARTIALLY_PAID'], true)) {
            return;
        }
        try {
            if ($invoice->status === 'FINALIZED') {
                $invoice->update(['status' => 'DRAFT']);
            }
            app(KasirService::class)->reconsolidateInvoice($invoice->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('reconsolidate pre-op gagal: ' . $e->getMessage(), ['visit_id' => $visitId]);
        }
    }

    /**
     * Passthrough daftar obat untuk picker instruksi pre-op (sumber tunggal
     * DokterService::getDaftarObat, farmasiOnly — resep harus bisa dilayani Farmasi).
     */
    public function getDaftarObat(?string $search): array
    {
        return app(DokterService::class)->getDaftarObat($search, true);
    }

    // =========================================================================
    // REKAM MEDIS PASIEN
    // =========================================================================

    public function getRekamMedisPasien(string $patientId): array
    {
        $vitalHistory = NurseAssessment::whereHas('visit', fn ($q) => $q->where('patient_id', $patientId))
            ->with(['visit:id,visit_date,classification'])
            ->where('is_finalized', true)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get([
                'id', 'visit_id', 'td_sistol', 'td_diastol', 'nadi',
                'suhu', 'spo2', 'kgd', 'pain_scale', 'berat_badan',
                'tinggi_badan', 'bmi', 'chief_complaint', 'rps',
                'allergy_detail', 'has_allergy', 'created_at',
            ]);

        $documents = PatientDocument::where('patient_id', $patientId)
            ->with(['documentType:id,name,code,category', 'visit:id,visit_date,classification'])
            ->orderByDesc('created_at')
            ->get([
                'id', 'patient_id', 'visit_id', 'document_type_id',
                'document_number', 'status', 'created_by_station',
                'printed_count', 'finalized_at', 'created_at',
            ]);

        return [
            'vital_history' => $vitalHistory,
            'documents'     => $documents,
        ];
    }

    public function getDokumen(string $documentId): PatientDocument
    {
        return PatientDocument::with([
            'documentType',
            'patient:id,no_rm,name,date_of_birth,gender',
            'visit:id,visit_date,classification,guarantor_type',
            'verification:id,patient_document_id,verification_url,is_valid',
        ])->findOrFail($documentId);
    }

    // =========================================================================
    // PARALLEL LOGIC
    // =========================================================================

    public function checkReadyForDoctor(string $visitId): bool
    {
        // Cek murah di luar transaksi (fast path) — hindari lock kalau jelas belum siap.
        $visit = Visit::findOrFail($visitId);
        if ($visit->ready_for_doctor) {
            return true;
        }

        $triaseDone   = NurseAssessment::where('visit_id', $visitId)->where('is_finalized', true)->exists();
        $refraksiDone = RefractionRecord::where('visit_id', $visitId)->where('is_finalized', true)->exists();
        if (! $triaseDone || ! $refraksiDone) {
            return false;
        }

        // PREOP_BEDAH: gate paralel selesai tapi JANGAN enqueue DOKTER —
        // tunggu tombol manual "Kirim ke Bedah" (lihat kirimKeBedah()).
        if ($visit->visit_type === 'PREOP_BEDAH') {
            return true;
        }

        // TOCTOU guard: Triase & Refraksi bisa finalize hampir bersamaan → dua
        // checkReadyForDoctor lolos cek di atas → 2 baris DOKTER / 2 tiket
        // (tak ada unique constraint queues(visit_id,station)). Kunci baris visit
        // + re-cek ready_for_doctor & alreadyQueued DI DALAM transaksi.
        $created = DB::transaction(function () use ($visitId) {
            $locked = Visit::where('id', $visitId)->lockForUpdate()->firstOrFail();
            if ($locked->ready_for_doctor) {
                return false; // pemenang race sudah memproses
            }

            $locked->update([
                'ready_for_doctor'      => true,
                'triase_completed_at'   => $locked->triase_completed_at ?? now(),
                'refraksi_completed_at' => $locked->refraksi_completed_at ?? now(),
                'current_station'       => 'DOKTER',
            ]);

            // Cek baris DOKTER yang masih AKTIF (bukan filter tanggal) agar visit
            // lintas-hari tak dibuatkan baris DOKTER ganda.
            $alreadyQueued = Queue::byStation(Queue::STATION_DOKTER)
                ->where('visit_id', $locked->id)
                ->active()
                ->exists();

            if (! $alreadyQueued) {
                $this->queueService->enqueue($locked->id, Queue::STATION_DOKTER);
            }

            return true;
        });

        if ($created) {
            $this->log(null, 'READY_FOR_DOCTOR', Visit::class, $visitId,
                'Triase + Refraksionis selesai — antrian Dokter dibuat otomatis');
        }

        return true;
    }

    public function getStatusParallel(string $visitId): array
    {
        $visit = Visit::findOrFail($visitId);

        return [
            'visit_id'              => $visitId,
            'triase_done'           => NurseAssessment::where('visit_id', $visitId)->where('is_finalized', true)->exists(),
            'refraksi_done'         => RefractionRecord::where('visit_id', $visitId)->where('is_finalized', true)->exists(),
            'ready_for_doctor'      => $visit->ready_for_doctor,
            'triase_completed_at'   => $visit->triase_completed_at?->toIso8601String(),
            'refraksi_completed_at' => $visit->refraksi_completed_at?->toIso8601String(),
        ];
    }

    // =========================================================================
    // VITAL HISTORY
    // =========================================================================

    public function getVitalHistory(string $patientId): Collection
    {
        return NurseAssessment::whereHas('visit', fn ($q) => $q->where('patient_id', $patientId))
            ->with(['visit:id,visit_date,classification'])
            ->where('is_finalized', true)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get([
                'id', 'visit_id', 'td_sistol', 'td_diastol', 'nadi',
                'suhu', 'spo2', 'kgd', 'pain_scale', 'berat_badan',
                'tinggi_badan', 'bmi', 'chief_complaint', 'created_at',
            ]);
    }

    // =========================================================================
    // CPPT (Catatan Perkembangan Pasien Terintegrasi)
    // =========================================================================

    /**
     * Tambah CPPT entry baru untuk visit aktif.
     * Visit harus belum SELESAI; asesmen awal harus sudah ada (gate logis —
     * CPPT adalah observasi LANJUTAN setelah asesmen baseline).
     */
    public function addCpptEntry(string $visitId, array $data): array
    {
        $visit = Visit::with('nurseAssessment')->findOrFail($visitId);

        $this->assertVisitActive($visit);

        if (! $visit->nurseAssessment) {
            throw new \Exception('Asesmen awal triase belum ada. Isi asesmen awal dulu.', 422);
        }

        $user     = auth('api')->user();
        $employee = $user?->employee;

        $entry = NurseCpptEntry::create([
            'visit_id'            => $visit->id,
            'nurse_assessment_id' => $visit->nurseAssessment->id,
            // Peran PPA di-derive otomatis dari profesi employee (Perawat → PERAWAT).
            'ppa_role'            => $employee?->ppaRole() ?? Employee::PPA_LAINNYA,
            'td_sistol'           => $data['td_sistol']  ?? null,
            'td_diastol'          => $data['td_diastol'] ?? null,
            'nadi'                => $data['nadi']       ?? null,
            'suhu'                => $data['suhu']       ?? null,
            'respirasi'           => $data['respirasi']  ?? null,
            'spo2'                => $data['spo2']       ?? null,
            'kgd'                 => $data['kgd']        ?? null,
            'pain_scale'          => $data['pain_scale'] ?? null,
            'notes'               => $data['notes']      ?? null,
            // SOAP perawat (opsional bila hari itu hanya TTV). O = TTV terstruktur.
            'soap_s'              => $data['soap_s']     ?? null,
            'soap_o'              => $data['soap_o']     ?? null,
            'soap_a'              => $data['soap_a']     ?? null,
            'soap_p'              => $data['soap_p']     ?? null,
            'instruksi'           => $data['instruksi']  ?? null,
            'created_by_id'       => $employee?->id,
        ]);

        $this->log($user?->id, 'CREATE_CPPT', NurseCpptEntry::class, $entry->id);

        return $this->formatCpptEntry($entry->fresh(['createdBy', 'editedBy', 'signedBy']));
    }

    /**
     * Edit CPPT entry yang sudah ada — set edited_at + edited_by_id.
     * Visit harus masih aktif.
     */
    public function updateCpptEntry(string $entryId, array $data): array
    {
        $entry = NurseCpptEntry::with('visit')->findOrFail($entryId);

        $this->assertVisitActive($entry->visit);

        $user = auth('api')->user();

        $entry->fill([
            'td_sistol'    => $data['td_sistol']  ?? $entry->td_sistol,
            'td_diastol'   => $data['td_diastol'] ?? $entry->td_diastol,
            'nadi'         => $data['nadi']       ?? $entry->nadi,
            'suhu'         => $data['suhu']       ?? $entry->suhu,
            'respirasi'    => $data['respirasi']  ?? $entry->respirasi,
            'spo2'         => $data['spo2']       ?? $entry->spo2,
            'kgd'          => $data['kgd']        ?? $entry->kgd,
            'pain_scale'   => $data['pain_scale'] ?? $entry->pain_scale,
            'notes'        => $data['notes']      ?? $entry->notes,
            'soap_s'       => $data['soap_s']     ?? $entry->soap_s,
            'soap_o'       => $data['soap_o']     ?? $entry->soap_o,
            'soap_a'       => $data['soap_a']     ?? $entry->soap_a,
            'soap_p'       => $data['soap_p']     ?? $entry->soap_p,
            'instruksi'    => $data['instruksi']  ?? $entry->instruksi,
            'edited_at'    => now(),
            'edited_by_id' => $user?->employee_id,
        ])->save();

        $this->log($user?->id, 'UPDATE_CPPT', NurseCpptEntry::class, $entry->id);

        return $this->formatCpptEntry($entry->fresh(['createdBy', 'editedBy', 'signedBy']));
    }

    /**
     * Tanda tangan CPPT (paraf penulis via PIN). BEDA dari verifikasi DPJP.
     * Hanya penulis entri (created_by) yang boleh menandatangani entrinya sendiri.
     */
    public function signCpptEntry(string $entryId, ?string $pin = null): array
    {
        $entry = NurseCpptEntry::with('visit')->findOrFail($entryId);
        $this->assertVisitActive($entry->visit);

        $user = auth('api')->user();

        if ($entry->created_by_id && $user?->employee_id && $entry->created_by_id !== $user->employee_id) {
            throw new \Exception('Hanya penulis entri yang dapat menandatanganinya.', 403);
        }

        $expected = $user?->pin;
        if (! $expected) {
            throw new \Exception('PIN belum diatur. Hubungi admin untuk mengatur PIN di Data Pengguna.', 422);
        }
        if (! is_string($pin) || ! hash_equals((string) $expected, (string) $pin)) {
            throw new \Exception('PIN salah.', 422);
        }

        $entry->forceFill([
            'signed_at'    => now(),
            'signed_by_id' => $user->employee_id,
        ])->save();

        $this->log($user->id, 'SIGN_CPPT', NurseCpptEntry::class, $entry->id, "CPPT ditandatangani (PIN)");

        return $this->formatCpptEntry($entry->fresh(['createdBy', 'editedBy', 'signedBy']));
    }

    /**
     * Timeline CPPT untuk satu visit (descending — terbaru di atas).
     */
    public function getCpptTimeline(string $visitId): array
    {
        $entries = NurseCpptEntry::with(['createdBy', 'editedBy', 'signedBy'])
            ->where('visit_id', $visitId)
            ->orderByDesc('created_at')
            ->get();

        return $entries->map(fn ($e) => $this->formatCpptEntry($e))->toArray();
    }

    private function assertVisitActive(Visit $visit): void
    {
        if ($visit->current_station === 'SELESAI') {
            throw new \Exception('Kunjungan sudah selesai — CPPT tidak bisa ditambah/diubah.', 422);
        }
    }

    private function formatCpptEntry(NurseCpptEntry $e): array
    {
        return [
            'id'                  => $e->id,
            'visit_id'            => $e->visit_id,
            'nurse_assessment_id' => $e->nurse_assessment_id,
            'ppa_role'            => $e->ppa_role,
            'td_sistol'           => $e->td_sistol,
            'td_diastol'          => $e->td_diastol,
            'nadi'                => $e->nadi,
            'suhu'                => $e->suhu,
            'respirasi'           => $e->respirasi,
            'spo2'                => $e->spo2,
            'kgd'                 => $e->kgd,
            'pain_scale'          => $e->pain_scale,
            'notes'               => $e->notes,
            'soap_s'              => $e->soap_s,
            'soap_o'              => $e->soap_o,
            'soap_a'              => $e->soap_a,
            'soap_p'              => $e->soap_p,
            'instruksi'           => $e->instruksi,
            'created_at'          => $e->created_at?->toIso8601String(),
            'created_by'          => $e->createdBy ? [
                'id'   => $e->createdBy->id,
                'name' => $e->createdBy->name,
            ] : null,
            'edited_at'           => $e->edited_at?->toIso8601String(),
            'edited_by'           => $e->editedBy ? [
                'id'   => $e->editedBy->id,
                'name' => $e->editedBy->name,
            ] : null,
            'signed_at'           => $e->signed_at?->toIso8601String(),
            'signed_by'           => $e->signedBy ? [
                'id'   => $e->signedBy->id,
                'name' => $e->signedBy->name,
            ] : null,
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function formatQueueItem(Queue $queue): object
    {
        $visit   = $queue->visit;
        $patient = $visit?->patient;
        $dob     = $patient?->date_of_birth;
        $age     = $dob ? $dob->age : null;

        // Status stasiun pasangan (REFRAKSIONIS) — badge "sedang di Refraksi" + nonaktifkan
        // tombol Panggil (cegah panggil-ganda paralel). Derive dari visit.queues today.
        $siblingActive = $visit && $visit->relationLoaded('queues')
            ? $visit->queues
                ->where('station', Queue::STATION_REFRAKSIONIS)
                ->whereIn('status', [Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                ->isNotEmpty()
            : false;

        // Stasiun pasangan (REFRAKSIONIS) SUDAH selesai memeriksa? → badge "Selesai
        // Refraksionis" di kartu antrean triase (finalizeRefraction menutup queue
        // REFRAKSIONIS jadi COMPLETED).
        $siblingCompleted = $visit && $visit->relationLoaded('queues')
            ? $visit->queues
                ->where('station', Queue::STATION_REFRAKSIONIS)
                ->where('status', Queue::STATUS_COMPLETED)
                ->isNotEmpty()
            : false;

        // Hasil refraksi (visus/IOP) bila refraksionis sudah memeriksa — utk kartu pasien triase.
        $rfx = $visit?->refractionRecord;
        $refraksi = ($rfx && ($rfx->visus_akhir_od || $rfx->visus_akhir_os || $rfx->visus_awal_od || $rfx->visus_awal_os || $rfx->iop_od !== null || $rfx->iop_os !== null)) ? [
            'visus_od' => $rfx->visus_akhir_od ?: $rfx->visus_awal_od,
            'visus_os' => $rfx->visus_akhir_os ?: $rfx->visus_awal_os,
            'iop_od'   => $rfx->iop_od !== null ? (float) $rfx->iop_od : null,
            'iop_os'   => $rfx->iop_os !== null ? (float) $rfx->iop_os : null,
        ] : null;

        return (object) [
            'id'             => $queue->id,
            'queue_number'   => $queue->queue_number,
            'queue_sequence' => $queue->queue_sequence,
            'status'         => $queue->status,
            'called_at'      => $queue->called_at?->toIso8601String(),
            'started_at'     => $queue->started_at?->toIso8601String(),
            'completed_at'   => $queue->completed_at?->toIso8601String(),
            'created_at'     => $queue->created_at?->toIso8601String(),
            // Mutual-exclusion stasiun paralel (Triase ↔ Refraksionis)
            'sibling_active'        => $siblingActive,
            'sibling_completed'     => $siblingCompleted,
            'sibling_station_label' => 'Refraksionis',
            'refraksi'              => $refraksi,
            'visit'          => $visit ? [
                'id'             => $visit->id,
                'classification' => $visit->classification,
                'visit_type'     => $visit->visit_type,
                'inpatient_reason' => $visit->inpatient_reason,  // PRE_OP/OBSERVASI — gate tombol "Kirim ke Rawat Inap"
                'guarantor_type' => $visit->guarantor_type,
                'no_sep'         => $visit->no_sep,
                'insurer_name'   => $visit->insurer?->name,
                'has_assessment' => (bool) $visit->nurseAssessment,
                'assessment_finalized' => (bool) $visit->nurseAssessment?->is_finalized,
            ] : null,
            'patient' => $patient ? [
                'id'           => $patient->id,
                'no_rm'        => $patient->no_rm,
                'nik'          => $patient->nik,
                'name'         => $patient->name,
                'gender'       => $patient->gender,
                'age'          => $age,
                'address'      => $patient->address,
                'province'     => $patient->province,
                'bpjs_number'  => $patient->bpjs_number,
                'allergy_notes' => $patient->allergy_notes,
                'photo_url'    => $patient->photo_url,
            ] : null,
            // Konteks jadwal dokter terpilih + sinyal "hampir habis" (badge Triase).
            'doctor'        => $visit?->doctorSchedule?->employee?->name,
            'schedule_end'  => $visit?->doctorSchedule?->end_time
                ? substr((string) $visit->doctorSchedule->end_time, 0, 5)
                : null,
            'schedule_risk' => $visit ? $this->scheduleRiskFor($visit) : null,
        ];
    }

    /**
     * Sinyal "jadwal dokter hampir habis" untuk badge antrean Triase:
     * at_risk bila sisa kuota dokter <= QUOTA_RISK ATAU sesi (end_time) tersisa
     * <= SESSION_RISK_MIN menit. Di-cache per (jadwal|penjamin) agar tak N+1.
     */
    private function scheduleRiskFor(Visit $visit): ?array
    {
        $ds = $visit->doctorSchedule;
        if (! $ds || ! $ds->poli_code) {
            return null;
        }

        $isBpjs = $visit->guarantor_type === 'BPJS';
        $key    = $ds->id . '|' . ($isBpjs ? 'jkn' : 'nonjkn');

        if (! array_key_exists($key, $this->riskCache)) {
            $ring  = $this->kuotaService->ringkasanKuota($ds->poli_code, $ds->employee_id, today()->toDateString());
            $sisa  = (int) ($isBpjs ? $ring['sisakuotajkn'] : $ring['sisakuotanonjkn']);
            $kuota = (int) ($isBpjs ? $ring['kuotajkn'] : $ring['kuotanonjkn']);

            $endStr = $ds->end_time instanceof \DateTimeInterface
                ? $ds->end_time->format('H:i:s')
                : (string) $ds->end_time;
            $minutesLeft = null;
            if ($endStr !== '') {
                $end = today()->setTimeFromTimeString($endStr);
                $minutesLeft = (int) floor(($end->getTimestamp() - now()->getTimestamp()) / 60);
            }

            // Hanya tandai risiko kuota bila kuota penjamin ini memang ditetapkan (>0).
            // Jadwal dengan kuota 0 (mis. dokter tidak melayani JKN) BUKAN "hampir habis":
            // sisa = max(0, 0 - terpakai) = 0 → tanpa guard ini badge palsu muncul di
            // SETIAP pasien penjamin tsb. Default kuota (tak terkonfigurasi) = 30, jadi
            // jadwal normal tetap kena badge saat sisa <= QUOTA_RISK.
            $quotaRisk   = $kuota > 0 && $sisa <= self::QUOTA_RISK;
            $sessionRisk = $minutesLeft !== null && $minutesLeft >= 0 && $minutesLeft <= self::SESSION_RISK_MIN;

            $reasons = [];
            if ($quotaRisk)   { $reasons[] = "sisa {$sisa} slot"; }
            if ($sessionRisk) { $reasons[] = 'sesi s/d ' . substr($endStr, 0, 5); }

            $this->riskCache[$key] = [
                'at_risk'      => $quotaRisk || $sessionRisk,
                'sisa'         => $sisa,
                'minutes_left' => $minutesLeft,
                'reason'       => $reasons ? implode(' · ', $reasons) : null,
            ];
        }

        return $this->riskCache[$key];
    }

    private function calculateBmi(?float $bb, ?float $tb): ?float
    {
        if (! $bb || ! $tb || $tb == 0) {
            return null;
        }

        // Kolom bmi = decimal(5,2) → maks 999.99. Input ekstrem-tapi-valid
        // (mis. bb=300, tb=30 → 3333) bisa numeric-overflow 500. Clamp aman.
        $bmi = round($bb / pow($tb / 100, 2), 2);

        return min($bmi, 999.99);
    }

    private function generateQueueNumber(string $station): array
    {
        $prefix = match ($station) {
            'ADMISI'       => 'A',
            'TRIASE'       => 'T',
            'REFRAKSIONIS' => 'R',
            'DOKTER'       => 'D',
            'BEDAH'        => 'B',
            'FARMASI'      => 'F',
            'KASIR'        => 'K',
            default        => 'X',
        };

        $lastSeq  = Queue::where('station', $station)->whereDate('created_at', today())->max('queue_sequence') ?? 0;
        $sequence = $lastSeq + 1;

        return [
            'queue_prefix'   => $prefix,
            'queue_sequence' => $sequence,
            'queue_number'   => $prefix . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
        ];
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
