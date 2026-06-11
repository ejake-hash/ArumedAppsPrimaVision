<?php

namespace App\Services;

use App\Models\IolRecommendation;
use App\Models\NurseAssessment;
use App\Models\Queue;
use App\Models\RefractionPrescription;
use App\Models\RefractionRecord;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefraksiService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
    ) {}

    // =========================================================================
    // ANTRIAN REFRAKSIONIS
    // =========================================================================

    public function getPatientQueue(): Collection
    {
        // nurseAssessment WAJIB di-load: frontend mapQueueRow baca
        // visit.nurse_assessment.allergy_detail untuk badge "⚠ Alergi" + bar vital
        // triase. Tanpa ini badge/data triase tak pernah tampil di Refraksionis.
        // visit.queues (today): FE derive status stasiun pasangan (TRIASE) untuk badge
        // "sedang di Triase" + nonaktifkan tombol Panggil (cegah panggil-ganda paralel).
        return Queue::with([
            'visit.patient', 'visit.refractionRecord', 'visit.nurseAssessment', 'visit.doctorSchedule.employee',
            'visit.queues' => fn ($q) => $q->whereDate('created_at', today()),
        ])
            ->where('station', 'REFRAKSIONIS')
            ->boardVisibleOpenBilling()   // +pasien belum tutup kasir (Masih Aktif)
            ->whereHas('visit')   // exclude zombie row (visit soft-deleted)
            ->orderBy('queue_sequence')
            ->get();
    }

    public function getKunjungan(string $visitId): Visit
    {
        return Visit::with([
            'patient',
            'insurer',
            'queues'           => fn ($q) => $q->where('station', 'REFRAKSIONIS'),
            'refractionRecord' => fn ($q) => $q->with(['examinedBy', 'prescription']),
        ])->findOrFail($visitId);
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_REFRAKSIONIS)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Refraksionis (Section 11.3 step 3).
     *
     * Gate paralel:
     *   - jika RefractionRecord BELUM finalize → tolak
     *   - jika triase belum finalize → tutup queue Refraksi, tetap menunggu di TRIASE
     *   - jika keduanya finalize → tutup queue, advance ke DOKTER
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::with('visit')
            ->byStation(Queue::STATION_REFRAKSIONIS)
            ->findOrFail($queueId);

        $record = RefractionRecord::where('visit_id', $queue->visit_id)->first();
        if (! $record || ! $record->is_finalized) {
            throw new \Exception('Pemeriksaan refraksi belum di-finalize. Selesaikan dulu.', 422);
        }

        // finalizeRefraction()/skipRefraksi() SUDAH menutup queue REFRAKSIONIS + memajukan
        // antrean (checkReadyForDoctor). Bila baris sudah COMPLETED, ini no-op idempoten —
        // jangan lempar "Antrian sudah ditutup" dari advanceFromStation.
        if ($queue->status === Queue::STATUS_COMPLETED) {
            return [
                'queue'        => $queue->fresh(['visit.patient']),
                'visit'        => $queue->visit?->fresh(['patient', 'queues']),
                'next_station' => null,
                'next_queue'   => null,
            ];
        }

        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_REFRAKSIONIS);
    }

    /**
     * Data tiket Dokter (D-NNN + poliklinik/ruang/dokter) untuk dicetak di stasiun
     * TR setelah finalize. Null kalau antrian DOKTER belum dibuat (partner belum selesai).
     */
    public function doctorTicket(string $visitId): ?array
    {
        return $this->queueService->getDoctorTicket($visitId);
    }

    public function mulaiAntrian(string $queueId): Queue
    {
        // Guard station di sini, lalu delegasi ke QueueService biar broadcast
        // AntreanTvUpdated + TriaseQueueUpdated ikut ter-fire (TV station R).
        Queue::where('station', 'REFRAKSIONIS')->findOrFail($queueId);

        return $this->queueService->mulai($queueId);
    }

    /**
     * Lewati antrian REFRAKSIONIS — turunkan SATU posisi (tukar queue_sequence dengan
     * pasien aktif berikutnya). Lihat QueueService::lewati.
     */
    public function lewatiAntrian(string $queueId): Queue
    {
        // Delegasi ke QueueService (sumber tunggal reorder + broadcast TV).
        Queue::where('station', 'REFRAKSIONIS')->findOrFail($queueId);

        return $this->queueService->lewati($queueId);
    }

    // =========================================================================
    // REFRACTION RECORD
    // =========================================================================

    public function getRefractionRecord(string $visitId): ?RefractionRecord
    {
        return RefractionRecord::with(['examinedBy', 'finalizedBy', 'prescription'])
            ->where('visit_id', $visitId)
            ->first();
    }

    /**
     * Create refraction record. One per visit.
     */
    public function storeRefractionRecord(string $visitId, array $data): RefractionRecord
    {
        Visit::findOrFail($visitId);

        if (RefractionRecord::where('visit_id', $visitId)->exists()) {
            throw new \Exception('Data refraksi sudah ada. Gunakan update untuk mengubah.', 422);
        }

        $user   = auth('api')->user();
        $record = RefractionRecord::create([
            'visit_id'        => $visitId,
            'examined_by_id'  => $user->employee_id,
            'examination_date' => $data['examination_date'] ?? now(),
            'perception_type' => $data['perception_type'],

            // Autoref OD
            'autoref_od_sph'  => $data['autoref_od_sph'] ?? null,
            'autoref_od_cyl'  => $data['autoref_od_cyl'] ?? null,
            'autoref_od_axis' => $data['autoref_od_axis'] ?? null,
            // Autoref OS
            'autoref_os_sph'  => $data['autoref_os_sph'] ?? null,
            'autoref_os_cyl'  => $data['autoref_os_cyl'] ?? null,
            'autoref_os_axis' => $data['autoref_os_axis'] ?? null,

            // Keratometri OD (axis K1 + axis K2 terpisah)
            'keratometri1_od'    => $data['keratometri1_od'] ?? null,
            'keratometri2_od'    => $data['keratometri2_od'] ?? null,
            'keratometri_axis_od'  => $data['keratometri_axis_od'] ?? null,
            'keratometri_axis2_od' => $data['keratometri_axis2_od'] ?? null,
            // Keratometri OS
            'keratometri1_os'    => $data['keratometri1_os'] ?? null,
            'keratometri2_os'    => $data['keratometri2_os'] ?? null,
            'keratometri_axis_os'  => $data['keratometri_axis_os'] ?? null,
            'keratometri_axis2_os' => $data['keratometri_axis2_os'] ?? null,

            // Visus OD
            'visus_awal_od'  => $data['visus_awal_od'] ?? null,
            'visus_akhir_od' => $data['visus_akhir_od'] ?? null,
            'pinhole_od'     => $data['pinhole_od'] ?? null,
            'add_power_od'   => $data['add_power_od'] ?? null,
            // Visus OS
            'visus_awal_os'  => $data['visus_awal_os'] ?? null,
            'visus_akhir_os' => $data['visus_akhir_os'] ?? null,
            'pinhole_os'     => $data['pinhole_os'] ?? null,
            'add_power_os'   => $data['add_power_os'] ?? null,

            // Refraksi Subjektif OD
            'refraksi_subjektif_od_sph'  => $data['refraksi_subjektif_od_sph'] ?? null,
            'refraksi_subjektif_od_cyl'  => $data['refraksi_subjektif_od_cyl'] ?? null,
            'refraksi_subjektif_od_axis' => $data['refraksi_subjektif_od_axis'] ?? null,
            // Refraksi Subjektif OS
            'refraksi_subjektif_os_sph'  => $data['refraksi_subjektif_os_sph'] ?? null,
            'refraksi_subjektif_os_cyl'  => $data['refraksi_subjektif_os_cyl'] ?? null,
            'refraksi_subjektif_os_axis' => $data['refraksi_subjektif_os_axis'] ?? null,

            // Kacamata Lama OD
            'old_glasses_od_sph'  => $data['old_glasses_od_sph'] ?? null,
            'old_glasses_od_cyl'  => $data['old_glasses_od_cyl'] ?? null,
            'old_glasses_od_axis' => $data['old_glasses_od_axis'] ?? null,
            'old_glasses_add_od'  => $data['old_glasses_add_od'] ?? null,
            'old_glasses_visus_od' => $data['old_glasses_visus_od'] ?? null,
            // Kacamata Lama OS
            'old_glasses_os_sph'  => $data['old_glasses_os_sph'] ?? null,
            'old_glasses_os_cyl'  => $data['old_glasses_os_cyl'] ?? null,
            'old_glasses_os_axis' => $data['old_glasses_os_axis'] ?? null,
            'old_glasses_add_os'  => $data['old_glasses_add_os'] ?? null,
            'old_glasses_visus_os' => $data['old_glasses_visus_os'] ?? null,

            // IOP (pengukuran #1 + pengukuran berulang manual)
            'iop_od'     => $data['iop_od'] ?? null,
            'iop_os'     => $data['iop_os'] ?? null,
            'iop_method' => $data['iop_method'] ?? null,
            'iop_extra_readings' => $data['iop_extra_readings'] ?? null,

            // Shared
            'pd_distance'    => $data['pd_distance'] ?? null,
            'clinical_notes' => $data['clinical_notes'] ?? null,

            // SOAP refraksionis (PPA). O autofill dari data refraksi tapi editable & tersimpan.
            'soap_s'         => $data['soap_s'] ?? null,
            'soap_o'         => $data['soap_o'] ?? null,
            'soap_a'         => $data['soap_a'] ?? null,
            'soap_p'         => $data['soap_p'] ?? null,

            'is_finalized' => false,
        ]);

        $this->log(
            $user->id,
            'STORE_REFRAKSI',
            RefractionRecord::class,
            $record->id,
            "Rekam refraksi dibuat untuk kunjungan {$visitId}"
        );

        return $record->load('examinedBy');
    }

    /**
     * Update refraction record — rejected if already finalized.
     */
    public function updateRefractionRecord(string $id, array $data): RefractionRecord
    {
        $record = RefractionRecord::findOrFail($id);

        if ($record->is_finalized) {
            throw new \Exception('Data refraksi sudah dikunci, tidak bisa diubah.', 422);
        }

        // Only update fields that are explicitly present in the payload
        $record->update(array_intersect_key($data, array_flip($record->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_REFRAKSI', RefractionRecord::class, $id);

        return $record->fresh(['examinedBy', 'prescription']);
    }

    /**
     * Lock refraction record → update visit timestamps → trigger parallel check.
     * Finalisasi = tanda tangan refraksionis (paraf PIN): mengisi digital_signature
     * + signature_timestamp. PIN diverifikasi sejajar pola DokterController::verifyPin
     * (users.pin plaintext, hash_equals).
     */
    public function finalizeRefraction(string $recordId, ?string $pin = null): RefractionRecord
    {
        $record = RefractionRecord::with('visit')->findOrFail($recordId);

        if ($record->is_finalized) {
            throw new \Exception('Data refraksi sudah dikunci.', 422);
        }

        // Tidak ada field klinis yang wajib — refraksionis boleh mengunci dengan data
        // sebagian/kosong (mis. pasien tak perlu pemeriksaan lengkap). PIN tetap jadi
        // satu-satunya gate (paraf/tanda tangan PPA).

        $user = auth('api')->user();

        // Gate tanda tangan PIN (paraf refraksionis).
        $expected = $user?->pin;
        if (! $expected) {
            throw new \Exception('PIN belum diatur. Hubungi admin untuk mengatur PIN di Data Pengguna.', 422);
        }
        if (! is_string($pin) || ! hash_equals((string) $expected, (string) $pin)) {
            throw new \Exception('PIN salah.', 422);
        }

        // Susun tanda tangan tekstual: nama + STR/SIP bila ada.
        $emp = $user->employee;
        $signature = $emp?->name ?? $user->name;
        if ($emp?->str) {
            $signature .= ' · STR ' . $emp->str;
        } elseif ($emp?->sip) {
            $signature .= ' · SIP ' . $emp->sip;
        }

        DB::transaction(function () use ($record, $user, $signature) {
            $record->update([
                'is_finalized'        => true,
                'finalized_at'        => now(),
                'finalized_by_id'     => $user->employee_id,
                'digital_signature'   => $signature,
                'signature_timestamp' => now(),
            ]);

            // Close REFRAKSIONIS queue
            Queue::where('visit_id', $record->visit_id)
                ->where('station', 'REFRAKSIONIS')
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

            $record->visit->update(['refraksi_completed_at' => now()]);
        });

        $this->log(
            $user->id,
            'FINALIZE_REFRAKSI',
            RefractionRecord::class,
            $recordId,
            "Rekam refraksi ditandatangani (PIN) & dikunci untuk kunjungan {$record->visit_id}"
        );

        // Fire parallel check — may create DOKTER queue
        $this->checkReadyForDoctor($record->visit_id);

        return $record->fresh(['examinedBy', 'finalizedBy', 'prescription']);
    }

    /**
     * Buka kunci pemeriksaan refraksi (periksa ulang atas permintaan dokter).
     * is_finalized→false + hapus tanda tangan + buka kembali antrean REFRAKSIONIS
     * (COMPLETED → WAITING) supaya pasien bisa di-Panggil, direvisi, lalu finalisasi ulang.
     * TIDAK menyentuh ready_for_doctor / antrean DOKTER (slot dokter tetap); saat finalisasi
     * ulang checkReadyForDoctor no-op + alreadyQueued → tanpa tiket DOKTER dobel.
     * SOAP/CPPT (kind REFRAKSI) otomatis ikut data terbaru (aggregator baca record live).
     */
    public function reopenRefraction(string $recordId): RefractionRecord
    {
        $record = RefractionRecord::with('visit')->findOrFail($recordId);

        if (! $record->is_finalized) {
            throw new \Exception('Rekam refraksi belum dikunci.', 422);
        }

        $user = auth('api')->user();

        DB::transaction(function () use ($record) {
            $record->update([
                'is_finalized'        => false,
                'finalized_at'        => null,
                'finalized_by_id'     => null,
                'digital_signature'   => null,
                'signature_timestamp' => null,
            ]);

            // Buka kembali antrean REFRAKSIONIS yang sudah COMPLETED (tanpa filter
            // tanggal: visit lintas-hari yang di-finalize kemarin tetap bisa dibuka
            // untuk periksa ulang; status COMPLETED + visit_id sudah cukup membatasi).
            Queue::where('visit_id', $record->visit_id)
                ->where('station', Queue::STATION_REFRAKSIONIS)
                ->where('status', Queue::STATUS_COMPLETED)
                ->update([
                    'status'       => Queue::STATUS_WAITING,
                    'completed_at' => null,
                    'called_at'    => null,
                    'started_at'   => null,
                ]);
        });

        $this->log(
            $user->id,
            'REOPEN_REFRAKSI',
            RefractionRecord::class,
            $recordId,
            "Rekam refraksi dibuka kembali (periksa ulang) untuk kunjungan {$record->visit_id}"
        );

        return $record->fresh(['examinedBy', 'finalizedBy', 'prescription']);
    }

    /**
     * Lewati Refraksi (pasien tidak perlu refraksi) — finalize record TANPA data
     * klinis dengan is_skipped=true, tutup antrean REFRAKSIONIS, jalankan gate
     * paralel. Antrean tetap maju ke DOKTER tanpa fabrikasi data refraksi.
     */
    public function skipRefraksi(string $queueId): array
    {
        return DB::transaction(function () use ($queueId) {
            $queue = Queue::with('visit')->byStation(Queue::STATION_REFRAKSIONIS)->lockForUpdate()->findOrFail($queueId);
            $visit = $queue->visit;
            $user  = auth('api')->user();

            $record = RefractionRecord::firstOrNew(['visit_id' => $visit->id]);
            if ($record->is_finalized && ! $record->is_skipped) {
                throw new \Exception('Refraksi sudah difinalisasi — tidak bisa dilewati.', 422);
            }

            $record->fill([
                'examined_by_id'  => $record->examined_by_id ?? $user->employee_id,
                'clinical_notes'  => $record->clinical_notes ?: 'Refraksi dilewati — tidak diperlukan.',
                'is_skipped'      => true,
                'is_finalized'    => true,
                'finalized_at'    => now(),
                'finalized_by_id' => $user->employee_id,
            ]);
            $record->save();

            Queue::where('visit_id', $visit->id)
                ->where('station', Queue::STATION_REFRAKSIONIS)
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

            $visit->update(['refraksi_completed_at' => now()]);

            $this->log($user->id, 'SKIP_REFRAKSI', RefractionRecord::class, $record->id, 'Refraksi dilewati — tidak diperlukan');
            $this->checkReadyForDoctor($visit->id);

            return ['skipped' => true, 'visit_id' => $visit->id];
        });
    }

    // =========================================================================
    // RESEP KACAMATA
    // =========================================================================

    public function getRefractionPrescription(string $recordId): ?RefractionPrescription
    {
        return RefractionPrescription::where('refraction_record_id', $recordId)->first();
    }

    /**
     * Create prescription. One per refraction_record.
     * Can be created even before finalize (draft).
     */
    public function storeRefractionPrescription(string $recordId, array $data): RefractionPrescription
    {
        $record = RefractionRecord::findOrFail($recordId);

        if (RefractionPrescription::where('refraction_record_id', $recordId)->exists()) {
            throw new \Exception('Resep kacamata sudah ada. Gunakan update untuk mengubah.', 422);
        }

        $prescription = RefractionPrescription::create([
            'refraction_record_id' => $recordId,
            'visit_id'             => $record->visit_id,

            // Rx OD
            'rx_od_sph'  => $data['rx_od_sph'] ?? null,
            'rx_od_cyl'  => $data['rx_od_cyl'] ?? null,
            'rx_od_axis' => $data['rx_od_axis'] ?? null,
            'rx_od_add'  => $data['rx_od_add'] ?? null,
            // Rx OS
            'rx_os_sph'  => $data['rx_os_sph'] ?? null,
            'rx_os_cyl'  => $data['rx_os_cyl'] ?? null,
            'rx_os_axis' => $data['rx_os_axis'] ?? null,
            'rx_os_add'  => $data['rx_os_add'] ?? null,

            'glasses_type'  => $data['glasses_type'] ?? null,
            'lens_material' => $data['lens_material'] ?? null,
            'coating'       => $data['coating'] ?? null,
            'notes'         => $data['notes'] ?? null,
        ]);

        $this->log(
            auth('api')->id(),
            'STORE_RESEP_KACAMATA',
            RefractionPrescription::class,
            $prescription->id,
            "Resep kacamata dibuat untuk rekam refraksi {$recordId}"
        );

        return $prescription;
    }

    /**
     * Update prescription — rejected if parent record already finalized.
     */
    public function updateRefractionPrescription(string $id, array $data): RefractionPrescription
    {
        $prescription = RefractionPrescription::with('refractionRecord')->findOrFail($id);

        if ($prescription->refractionRecord?->is_finalized) {
            throw new \Exception('Resep tidak bisa diubah — data refraksi sudah dikunci.', 422);
        }

        $prescription->update(array_intersect_key($data, array_flip($prescription->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_RESEP_KACAMATA', RefractionPrescription::class, $id);

        return $prescription->fresh();
    }

    // =========================================================================
    // IOL REKOMENDASI (input biometri dari Refraksionis)
    // =========================================================================

    public function getIolRekomendasi(string $visitId): Collection
    {
        return IolRecommendation::with('approvedBy')
            ->where('visit_id', $visitId)
            ->get();
    }

    public function storeIolRekomendasi(array $data): IolRecommendation
    {
        $rekomendasi = IolRecommendation::create([
            'visit_id'             => $data['visit_id'],
            'diagnostic_result_id' => $data['diagnostic_result_id'] ?? null,
            'eye_side'             => $data['eye_side'],
            'recommended_power'    => $data['recommended_power'],
            'iol_type'             => $data['iol_type'] ?? null,
            'brand'                => $data['brand'] ?? null,
            'notes'                => $data['notes'] ?? null,
            'is_approved'          => false,
        ]);

        $this->log(
            auth('api')->id(),
            'STORE_IOL_REKOMENDASI',
            IolRecommendation::class,
            $rekomendasi->id
        );

        return $rekomendasi;
    }

    public function updateIolRekomendasi(string $id, array $data): IolRecommendation
    {
        $rekomendasi = IolRecommendation::findOrFail($id);

        if ($rekomendasi->is_approved) {
            throw new \Exception('Rekomendasi IOL sudah disetujui dokter, tidak bisa diubah.', 422);
        }

        $rekomendasi->update(array_intersect_key($data, array_flip($rekomendasi->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_IOL_REKOMENDASI', IolRecommendation::class, $id);

        return $rekomendasi->fresh();
    }

    // =========================================================================
    // RIWAYAT REFRAKSI
    // =========================================================================

    /**
     * Previous finalized refraction records for the same patient.
     */
    public function getRiwayatRefraksi(string $patientId): Collection
    {
        return RefractionRecord::whereHas(
            'visit',
            fn ($q) => $q->where('patient_id', $patientId)
        )
            ->with([
                'visit'        => fn ($q) => $q->select('id', 'visit_date', 'classification'),
                'examinedBy'   => fn ($q) => $q->select('id', 'name', 'profession'),
                'prescription',
            ])
            ->where('is_finalized', true)
            ->orderByDesc('examination_date')
            ->limit(10)
            ->get();
    }

    // =========================================================================
    // PARALLEL STATUS (mirror of PerawatService)
    // =========================================================================

    public function getStatusParallel(string $visitId): array
    {
        $visit        = Visit::findOrFail($visitId);
        $triaseDone   = NurseAssessment::where('visit_id', $visitId)->where('is_finalized', true)->exists();
        $refraksiDone = RefractionRecord::where('visit_id', $visitId)->where('is_finalized', true)->exists();

        return [
            'visit_id'              => $visitId,
            'triase_done'           => $triaseDone,
            'refraksi_done'         => $refraksiDone,
            'ready_for_doctor'      => $visit->ready_for_doctor,
            'triase_completed_at'   => $visit->triase_completed_at?->toIso8601String(),
            'refraksi_completed_at' => $visit->refraksi_completed_at?->toIso8601String(),
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Check if both parallel stations are done → create DOKTER queue.
     * Duplicated from PerawatService by design (shared QueueService TBD if 3rd caller needed).
     */
    private function checkReadyForDoctor(string $visitId): bool
    {
        // Cek murah di luar transaksi (fast path).
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
        // tunggu tombol manual "Kirim ke Bedah" di PerawatView.
        if ($visit->visit_type === 'PREOP_BEDAH') {
            return true;
        }

        // TOCTOU guard (sama dengan PerawatService): kunci baris visit + re-cek
        // di dalam transaksi agar finalize TR & REF yang nyaris bersamaan tidak
        // membuat 2 baris DOKTER. Enqueue DELEGASI ke QueueService (sumber tunggal
        // nomor D{room}-NNN per-ruang + broadcast TV) — JANGAN buat 'D-NNN' inline.
        $created = DB::transaction(function () use ($visitId) {
            $locked = Visit::where('id', $visitId)->lockForUpdate()->firstOrFail();
            if ($locked->ready_for_doctor) {
                return false;
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
            $this->log(
                null,
                'READY_FOR_DOCTOR',
                Visit::class,
                $visitId,
                'Triase + Refraksionis selesai — antrian Dokter dibuat otomatis'
            );
        }

        return true;
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
