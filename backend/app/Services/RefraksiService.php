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
        return Queue::with(['visit.patient', 'visit.refractionRecord'])
            ->where('station', 'REFRAKSIONIS')
            ->whereDate('created_at', today())
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

        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_REFRAKSIONIS);
    }

    public function mulaiAntrian(string $queueId): Queue
    {
        $queue = Queue::where('station', 'REFRAKSIONIS')->findOrFail($queueId);

        if ($queue->status !== 'CALLED') {
            throw new \Exception('Panggil pasien terlebih dahulu.', 422);
        }

        $queue->update([
            'status'     => 'IN_PROGRESS',
            'started_at' => now(),
        ]);

        return $queue->fresh(['visit.patient']);
    }

    /**
     * Lewati antrian REFRAKSIONIS — pindah ke akhir antrean (reset queue_sequence ke MAX+1).
     */
    public function lewatiAntrian(string $queueId): Queue
    {
        $queue = Queue::where('station', 'REFRAKSIONIS')
            ->whereIn('status', ['WAITING', 'CALLED'])
            ->findOrFail($queueId);

        $maxSeq = Queue::where('station', 'REFRAKSIONIS')
            ->whereDate('created_at', today())
            ->max('queue_sequence') ?? 0;

        $queue->update([
            'queue_sequence' => $maxSeq + 1,
            'status'         => 'WAITING',
            'called_at'      => null,
        ]);

        return $queue->fresh(['visit.patient', 'visit.refractionRecord']);
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

            // Keratometri OD
            'keratometri1_od'    => $data['keratometri1_od'] ?? null,
            'keratometri2_od'    => $data['keratometri2_od'] ?? null,
            'keratometri_axis_od' => $data['keratometri_axis_od'] ?? null,
            // Keratometri OS
            'keratometri1_os'    => $data['keratometri1_os'] ?? null,
            'keratometri2_os'    => $data['keratometri2_os'] ?? null,
            'keratometri_axis_os' => $data['keratometri_axis_os'] ?? null,

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
            // Kacamata Lama OS
            'old_glasses_os_sph'  => $data['old_glasses_os_sph'] ?? null,
            'old_glasses_os_cyl'  => $data['old_glasses_os_cyl'] ?? null,
            'old_glasses_os_axis' => $data['old_glasses_os_axis'] ?? null,
            'old_glasses_add_os'  => $data['old_glasses_add_os'] ?? null,

            // IOP
            'iop_od'     => $data['iop_od'] ?? null,
            'iop_os'     => $data['iop_os'] ?? null,
            'iop_method' => $data['iop_method'] ?? null,

            // Shared
            'pd_distance'    => $data['pd_distance'] ?? null,
            'clinical_notes' => $data['clinical_notes'] ?? null,

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
     */
    public function finalizeRefraction(string $recordId): RefractionRecord
    {
        $record = RefractionRecord::with('visit')->findOrFail($recordId);

        if ($record->is_finalized) {
            throw new \Exception('Data refraksi sudah dikunci.', 422);
        }

        // Minimal data: setidaknya perception_type + salah satu visus
        if (
            ! $record->perception_type ||
            (! $record->visus_akhir_od && ! $record->visus_akhir_os)
        ) {
            throw new \Exception(
                'Lengkapi minimal perception_type dan visus akhir (OD atau OS) sebelum mengunci.',
                422
            );
        }

        $user = auth('api')->user();

        DB::transaction(function () use ($record, $user) {
            $record->update([
                'is_finalized'    => true,
                'finalized_at'    => now(),
                'finalized_by_id' => $user->employee_id,
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
            "Rekam refraksi dikunci untuk kunjungan {$record->visit_id}"
        );

        // Fire parallel check — may create DOKTER queue
        $this->checkReadyForDoctor($record->visit_id);

        return $record->fresh(['examinedBy', 'finalizedBy', 'prescription']);
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
        $visit = Visit::findOrFail($visitId);

        if ($visit->ready_for_doctor) {
            return true;
        }

        $triaseDone   = NurseAssessment::where('visit_id', $visitId)->where('is_finalized', true)->exists();
        $refraksiDone = RefractionRecord::where('visit_id', $visitId)->where('is_finalized', true)->exists();

        if (! $triaseDone || ! $refraksiDone) {
            return false;
        }

        DB::transaction(function () use ($visit) {
            $visit->update([
                'ready_for_doctor'      => true,
                'triase_completed_at'   => $visit->triase_completed_at ?? now(),
                'refraksi_completed_at' => $visit->refraksi_completed_at ?? now(),
                'current_station'       => 'DOKTER',
            ]);

            $lastSeq  = Queue::where('station', 'DOKTER')->whereDate('created_at', today())->max('queue_sequence') ?? 0;
            $sequence = $lastSeq + 1;

            Queue::create([
                'visit_id'       => $visit->id,
                'station'        => 'DOKTER',
                'queue_prefix'   => 'D',
                'queue_sequence' => $sequence,
                'queue_number'   => 'D-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
                'status'         => 'WAITING',
            ]);
        });

        $this->log(
            null,
            'READY_FOR_DOCTOR',
            Visit::class,
            $visit->id,
            'Triase + Refraksionis selesai — antrian Dokter dibuat otomatis'
        );

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
