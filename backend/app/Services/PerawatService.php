<?php

namespace App\Services;

use App\Events\TriaseQueueUpdated;
use App\Models\NurseAssessment;
use App\Models\NurseCpptEntry;
use App\Models\PatientDocument;
use App\Models\Queue;
use App\Models\RefractionRecord;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerawatService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
    ) {}

    // =========================================================================
    // ANTRIAN TRIASE
    // =========================================================================

    public function getPatientQueue(): array
    {
        $queues = Queue::with([
            'visit.patient',
            'visit.nurseAssessment',
            'visit.insurer',
        ])
            ->where('station', 'TRIASE')
            ->whereDate('created_at', today())
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
        $queue = Queue::where('station', 'TRIASE')->findOrFail($queueId);

        // Boleh panggil ulang dari status aktif (WAITING/CALLED/IN_PROGRESS).
        // Tolak hanya kalau queue sudah COMPLETED atau CANCELLED.
        if (! in_array($queue->status, ['WAITING', 'CALLED', 'IN_PROGRESS'])) {
            throw new \Exception('Antrian sudah selesai atau dibatalkan.', 422);
        }

        // WAITING/CALLED → CALLED (update called_at). IN_PROGRESS tetap IN_PROGRESS
        // (re-call saat pasien sedang diperiksa — hanya refresh waktu panggil).
        $newStatus = $queue->status === 'IN_PROGRESS' ? 'IN_PROGRESS' : 'CALLED';
        $queue->update(['status' => $newStatus, 'called_at' => now()]);
        $queue->load('visit.patient');

        $item = $this->formatQueueItem($queue);

        // Perbaikan: Gunakan casting (array) untuk mengonversi stdClass/objek menjadi array murni
        $itemArray = (array) $item;

        broadcast(new TriaseQueueUpdated($itemArray, 'updated'))->toOthers();

        return $itemArray;
    }

    public function mulaiAntrian(string $queueId): array
    {
        $queue = Queue::where('station', 'TRIASE')->findOrFail($queueId);

        if ($queue->status !== 'CALLED') {
            throw new \Exception('Panggil pasien terlebih dahulu.', 422);
        }

        $queue->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);
        $queue->load('visit.patient');

        $item = $this->formatQueueItem($queue);
        
        // Perbaikan: Gunakan casting (array)
        $itemArray = (array) $item;
        
        broadcast(new TriaseQueueUpdated($itemArray, 'updated'))->toOthers();

        return $itemArray;
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

        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_TRIASE);
    }

    public function lewatiAntrian(string $queueId): array
    {
        $queue = Queue::where('station', 'TRIASE')
            ->whereIn('status', ['WAITING', 'CALLED'])
            ->findOrFail($queueId);

        $maxSeq = Queue::where('station', 'TRIASE')
            ->whereDate('created_at', today())
            ->max('queue_sequence') ?? 0;

        $queue->update([
            'queue_sequence' => $maxSeq + 1,
            'status'         => 'WAITING',
            'called_at'      => null,
        ]);

        $queue->load('visit.patient');
        
        $item = $this->formatQueueItem($queue);
        
        // Perbaikan: Gunakan casting (array)
        $itemArray = (array) $item;
        
        broadcast(new TriaseQueueUpdated($itemArray, 'updated'))->toOthers();

        return $itemArray;
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
            'td_sistol'        => $data['td_sistol'],
            'td_diastol'       => $data['td_diastol'],
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
            'allergy_detail'   => $data['has_allergy'] ? ($data['allergy_detail'] ?? null) : null,
            'chief_complaint'  => $data['chief_complaint'],
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

        $patch = array_filter([
            'td_sistol'        => $data['td_sistol'] ?? null,
            'td_diastol'       => $data['td_diastol'] ?? null,
            'nadi'             => $data['nadi'] ?? null,
            'suhu'             => $data['suhu'] ?? null,
            'respirasi'        => $data['respirasi'] ?? null,
            'spo2'             => $data['spo2'] ?? null,
            'kgd'              => $data['kgd'] ?? null,
            'pain_scale'       => $data['pain_scale'] ?? null,
            'berat_badan'      => $data['berat_badan'] ?? null,
            'tinggi_badan'     => $data['tinggi_badan'] ?? null,
            'has_allergy'      => $data['has_allergy'] ?? null,
            'allergy_detail'   => ($data['has_allergy'] ?? false) ? ($data['allergy_detail'] ?? null) : null,
            'chief_complaint'  => $data['chief_complaint'] ?? null,
            'rps'              => $data['rps'] ?? null,
            'assessment_notes' => $data['assessment_notes'] ?? null,
        ], fn ($v) => ! is_null($v));

        $bb = $patch['berat_badan'] ?? $assessment->berat_badan;
        $tb = $patch['tinggi_badan'] ?? $assessment->tinggi_badan;
        if (isset($patch['berat_badan']) || isset($patch['tinggi_badan'])) {
            $patch['bmi'] = $this->calculateBmi((float) $bb, (float) $tb);
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

        // Gate finalize: hanya TD (sistol+diastol) + KGD + keluhan utama yang wajib.
        // Nadi/suhu/respirasi/SpO2/BB/TB optional (sesuai konvensi triase mata —
        // perawat fokus ke TD+KGD untuk skrining pre-op).
        foreach (['td_sistol', 'td_diastol', 'kgd', 'chief_complaint'] as $field) {
            if (is_null($assessment->{$field})) {
                throw new \Exception("Field {$field} wajib diisi sebelum mengunci asesmen.", 422);
            }
        }

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

            // Hindari double-enqueue jika baris DOKTER sudah ada hari ini
            $alreadyQueued = Queue::byStation(Queue::STATION_DOKTER)
                ->where('visit_id', $visit->id)
                ->today()
                ->exists();

            if (! $alreadyQueued) {
                $this->queueService->enqueue($visit->id, Queue::STATION_DOKTER);
            }
        });

        $this->log(null, 'READY_FOR_DOCTOR', Visit::class, $visit->id,
            'Triase + Refraksionis selesai — antrian Dokter dibuat otomatis');

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

        $user = auth('api')->user();

        $entry = NurseCpptEntry::create([
            'visit_id'            => $visit->id,
            'nurse_assessment_id' => $visit->nurseAssessment->id,
            'td_sistol'           => $data['td_sistol']  ?? null,
            'td_diastol'          => $data['td_diastol'] ?? null,
            'nadi'                => $data['nadi']       ?? null,
            'suhu'                => $data['suhu']       ?? null,
            'respirasi'           => $data['respirasi']  ?? null,
            'spo2'                => $data['spo2']       ?? null,
            'kgd'                 => $data['kgd']        ?? null,
            'pain_scale'          => $data['pain_scale'] ?? null,
            'notes'               => $data['notes'],
            'created_by_id'       => $user?->employee_id,
        ]);

        $this->log($user?->id, 'CREATE_CPPT', NurseCpptEntry::class, $entry->id);

        return $this->formatCpptEntry($entry->fresh(['createdBy', 'editedBy']));
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
            'edited_at'    => now(),
            'edited_by_id' => $user?->employee_id,
        ])->save();

        $this->log($user?->id, 'UPDATE_CPPT', NurseCpptEntry::class, $entry->id);

        return $this->formatCpptEntry($entry->fresh(['createdBy', 'editedBy']));
    }

    /**
     * Timeline CPPT untuk satu visit (descending — terbaru di atas).
     */
    public function getCpptTimeline(string $visitId): array
    {
        $entries = NurseCpptEntry::with(['createdBy', 'editedBy'])
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
            'td_sistol'           => $e->td_sistol,
            'td_diastol'          => $e->td_diastol,
            'nadi'                => $e->nadi,
            'suhu'                => $e->suhu,
            'respirasi'           => $e->respirasi,
            'spo2'                => $e->spo2,
            'kgd'                 => $e->kgd,
            'pain_scale'          => $e->pain_scale,
            'notes'               => $e->notes,
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

        return (object) [
            'id'             => $queue->id,
            'queue_number'   => $queue->queue_number,
            'queue_sequence' => $queue->queue_sequence,
            'status'         => $queue->status,
            'called_at'      => $queue->called_at?->toIso8601String(),
            'started_at'     => $queue->started_at?->toIso8601String(),
            'completed_at'   => $queue->completed_at?->toIso8601String(),
            'created_at'     => $queue->created_at?->toIso8601String(),
            'visit'          => $visit ? [
                'id'             => $visit->id,
                'classification' => $visit->classification,
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
            ] : null,
        ];
    }

    private function calculateBmi(?float $bb, ?float $tb): ?float
    {
        if (! $bb || ! $tb || $tb == 0) {
            return null;
        }

        return round($bb / pow($tb / 100, 2), 2);
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
