<?php

namespace App\Services;

use App\Models\BpjsControlLetter;
use App\Models\BpjsReferralOut;
use App\Models\DiagnosticOrder;
use App\Models\DoctorExamination;
use App\Models\DocumentType;
use App\Models\DocumentVerification;
use App\Models\IolRecommendation;
use App\Models\MedicalResume;
use App\Models\Notification;
use App\Models\PatientDocument;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Queue;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Models\VisitService;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DokterService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
    ) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function getPatientQueue(): Collection
    {
        return Queue::with(['visit.patient', 'visit.nurseAssessment', 'visit.refractionRecord'])
            ->where('station', 'DOKTER')
            ->whereDate('created_at', today())
            ->orderBy('queue_sequence')
            ->get();
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Dokter → advance ke PENUNJANG / BEDAH / KASIR
     * (lihat QueueService::resolveNextStation Section 11.3).
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)->findOrFail($queueId);
        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_DOKTER);
    }

    // =========================================================================
    // TAB 1 — DATA PASIEN (READONLY: triase + refraksi)
    // =========================================================================

    public function getPatientData(string $visitId): Visit
    {
        return Visit::with([
            'patient',
            'insurer',
            'queues'            => fn ($q) => $q->where('station', 'DOKTER'),
            'nurseAssessment'   => fn ($q) => $q->with('assessedBy'),
            'refractionRecord'  => fn ($q) => $q->with(['examinedBy', 'prescription']),
            'iolRecommendations',
            'diagnosticOrders'  => fn ($q) => $q->with('results'),
            'doctorExamination' => fn ($q) => $q->with(['doctor', 'surgeryPackage']),
        ])->findOrFail($visitId);
    }

    // =========================================================================
    // TAB 2 — ANAMNESE + SEGMEN ANTERIOR/POSTERIOR
    // =========================================================================

    public function getTab2(string $visitId): ?DoctorExamination
    {
        return DoctorExamination::where('visit_id', $visitId)->first();
    }

    /**
     * Create Tab 2 (anamnese + segmen). One per visit.
     */
    public function storeExamination(string $visitId, array $data): DoctorExamination
    {
        Visit::findOrFail($visitId);

        if (DoctorExamination::where('visit_id', $visitId)->exists()) {
            throw new \Exception('Data pemeriksaan sudah ada. Gunakan update.', 422);
        }

        $user       = auth('api')->user();
        $segmenEnum = ['Normal', 'Tidak Normal', 'Tidak Dapat Dinilai'];

        $examination = DoctorExamination::create([
            'visit_id'  => $visitId,
            'doctor_id' => $user->employee_id,

            'anamnese'       => $data['anamnese'] ?? null,
            'slitlamp_notes' => $data['slitlamp_notes'] ?? null,

            // Segmen Anterior OD
            'sa_kornea_od' => $data['sa_kornea_od'] ?? null,
            'sa_coa_od'    => $data['sa_coa_od'] ?? null,
            'sa_iris_od'   => $data['sa_iris_od'] ?? null,
            'sa_pupil_od'  => $data['sa_pupil_od'] ?? null,
            'sa_lensa_od'  => $data['sa_lensa_od'] ?? null,
            // Segmen Anterior OS
            'sa_kornea_os' => $data['sa_kornea_os'] ?? null,
            'sa_coa_os'    => $data['sa_coa_os'] ?? null,
            'sa_iris_os'   => $data['sa_iris_os'] ?? null,
            'sa_pupil_os'  => $data['sa_pupil_os'] ?? null,
            'sa_lensa_os'  => $data['sa_lensa_os'] ?? null,

            // Segmen Posterior OD
            'sp_papil_od'    => $data['sp_papil_od'] ?? null,
            'sp_macula_od'   => $data['sp_macula_od'] ?? null,
            'sp_retina_od'   => $data['sp_retina_od'] ?? null,
            'sp_vitreous_od' => $data['sp_vitreous_od'] ?? null,
            // Segmen Posterior OS
            'sp_papil_os'    => $data['sp_papil_os'] ?? null,
            'sp_macula_os'   => $data['sp_macula_os'] ?? null,
            'sp_retina_os'   => $data['sp_retina_os'] ?? null,
            'sp_vitreous_os' => $data['sp_vitreous_os'] ?? null,

            'is_finalized' => false,
        ]);

        $this->log($user->id, 'STORE_TAB2', DoctorExamination::class, $examination->id, "Tab 2 dibuat untuk kunjungan {$visitId}");

        return $examination->load('doctor');
    }

    public function updateExamination(string $visitId, array $data): DoctorExamination
    {
        $examination = DoctorExamination::where('visit_id', $visitId)->firstOrFail();

        if ($examination->is_finalized) {
            throw new \Exception('Pemeriksaan sudah dikunci, tidak bisa diubah.', 422);
        }

        $examination->update(array_intersect_key($data, array_flip($examination->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_TAB2', DoctorExamination::class, $examination->id);

        return $examination->fresh('doctor');
    }

    // =========================================================================
    // TAB 3 — TINDAKAN + RESEP OBAT
    // =========================================================================

    public function getVisitServices(string $visitId): Collection
    {
        return VisitService::with('procedure')->where('visit_id', $visitId)->get();
    }

    public function storeVisitServices(string $visitId, array $services): Collection
    {
        $visit = Visit::findOrFail($visitId);
        $user  = auth('api')->user();

        return DB::transaction(function () use ($visitId, $services, $user) {
            $created = [];

            foreach ($services as $item) {
                $created[] = VisitService::create([
                    'visit_id'       => $visitId,
                    'procedure_id'   => $item['procedure_id'],
                    'performed_by_id' => $user->employee_id,
                    'quantity'       => $item['quantity'] ?? 1,
                    'price'          => $item['price'] ?? 0,
                    'notes'          => $item['notes'] ?? null,
                ]);
            }

            $this->log($user->id, 'STORE_TINDAKAN', Visit::class, $visitId, count($created) . ' tindakan disimpan');

            return collect($created)->load('procedure');
        });
    }

    public function deleteVisitService(string $id): void
    {
        $service = VisitService::findOrFail($id);
        $service->delete();
        $this->log(auth('api')->id(), 'DELETE_TINDAKAN', VisitService::class, $id);
    }

    public function getPrescriptions(string $visitId): Collection
    {
        return Prescription::with('items.medication')->where('visit_id', $visitId)->get();
    }

    public function storePrescription(string $visitId, array $data): Prescription
    {
        $user = auth('api')->user();

        return DB::transaction(function () use ($visitId, $data, $user) {
            $prescription = Prescription::create([
                'visit_id'        => $visitId,
                'prescribed_by_id' => $user->employee_id,
                'status'          => 'DRAFT',
                'notes'           => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] ?? [] as $item) {
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'medication_id'   => $item['medication_id'],
                    'quantity'        => $item['quantity'],
                    'dose'            => $item['dose'] ?? null,
                    'frequency'       => $item['frequency'] ?? null,
                    'route'           => $item['route'] ?? null,
                    'duration_days'   => $item['duration_days'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            $this->log($user->id, 'STORE_RESEP', Prescription::class, $prescription->id, "Resep dibuat untuk kunjungan {$visitId}");

            return $prescription->load('items.medication');
        });
    }

    // =========================================================================
    // TAB 4 — SOAP + ICD + PLANNING (KRITIS: include follow-up logic)
    // =========================================================================

    public function getTab4(string $visitId): ?DoctorExamination
    {
        return DoctorExamination::with(['doctor', 'surgeryPackage', 'surgerySchedule'])
            ->where('visit_id', $visitId)
            ->first();
    }

    /**
     * Store Tab 4 data. If doctor_examination doesn't exist yet, create it.
     * Handles follow-up logic completely.
     */
    public function storePlanning(string $visitId, array $data): array
    {
        $visit = Visit::with('patient')->findOrFail($visitId);
        $user  = auth('api')->user();

        return DB::transaction(function () use ($visit, $data, $user) {
            // Upsert doctor_examination Tab 4 fields
            $examination = DoctorExamination::firstOrCreate(
                ['visit_id' => $visit->id],
                ['doctor_id' => $user->employee_id]
            );

            if ($examination->is_finalized) {
                throw new \Exception('Pemeriksaan sudah dikunci, tidak bisa diubah.', 422);
            }

            $examination->update([
                'soap_subjective'    => $data['soap_subjective'] ?? null,
                'soap_objective'     => $data['soap_objective'] ?? null,
                'soap_assessment'    => $data['soap_assessment'] ?? null,
                'soap_plan'          => $data['soap_plan'] ?? null,
                'diagnosis_utama'    => $data['diagnosis_utama'],
                'diagnosis_sekunder' => $data['diagnosis_sekunder'] ?? [],
                'tindakan_codes'     => $data['tindakan_codes'] ?? [],
                'planning'           => $data['planning'],
                'surgery_package_id' => $data['surgery_package_id'] ?? null,
                'surgery_schedule_id' => $data['surgery_schedule_id'] ?? null,
            ]);

            // Handle planning-specific side-effects
            $this->handlePlanningFollowUp($visit, $data, $examination);

            $this->log($user->id, 'STORE_TAB4', DoctorExamination::class, $examination->id, "Planning: {$data['planning']} — kunjungan {$visit->id}");

            return [
                'examination' => $examination->fresh(['doctor', 'surgeryPackage']),
                'visit'       => $visit->fresh(),
            ];
        });
    }

    public function updatePlanning(string $visitId, array $data): array
    {
        return $this->storePlanning($visitId, $data);
    }

    /**
     * Handle all follow-up side effects after planning is saved.
     */
    private function handlePlanningFollowUp(Visit $visit, array $data, DoctorExamination $examination): void
    {
        $hasFollowUp = ! empty($data['follow_up_date']);

        if ($hasFollowUp) {
            // 1. Update visit follow-up fields
            $visit->update([
                'planning_follow_up' => true,
                'follow_up_date'     => $data['follow_up_date'],
                'follow_up_reason'   => $data['follow_up_reason'] ?? null,
            ]);

            // 2. Append to medical_resume.resume_p (if resume exists)
            $resume = MedicalResume::where('visit_id', $visit->id)->first();
            if ($resume && $resume->is_editable) {
                $appendText = "\nKontrol Ulang: {$data['follow_up_date']}";
                if (! empty($data['follow_up_reason'])) {
                    $appendText .= " — {$data['follow_up_reason']}";
                }
                $resume->update(['resume_p' => $resume->resume_p . $appendText]);
            }

            // 3. If BPJS → create BpjsControlLetter (DRAFT)
            if ($visit->guarantor_type === 'BPJS') {
                $existingLetter = BpjsControlLetter::where('visit_id', $visit->id)
                    ->whereNotIn('status', ['SUBMITTED', 'SUCCESS'])
                    ->first();

                if (! $existingLetter) {
                    BpjsControlLetter::create([
                        'visit_id'                => $visit->id,
                        'tanggal_rencana_kontrol' => $data['follow_up_date'],
                        'status'                  => 'DRAFT',
                        'is_notified_expired'     => false,
                    ]);
                } else {
                    $existingLetter->update(['tanggal_rencana_kontrol' => $data['follow_up_date']]);
                }
            }

            // 4. Create PatientDocument (Surat Kontrol) — DRAFT
            $docType = DocumentType::where('code', 'FOLLOW_UP_LETTER')
                ->orWhere('name', 'like', '%Surat Kontrol%')
                ->first();

            if ($docType) {
                PatientDocument::firstOrCreate(
                    [
                        'visit_id'         => $visit->id,
                        'document_type_id' => $docType->id,
                    ],
                    [
                        'patient_id'           => $visit->patient_id,
                        'status'               => 'DRAFT',
                        'created_by_station'   => 'DOKTER',
                        'pending_signature_roles' => ['DOCTOR'],
                        'signatures'           => [],
                        'printed_count'        => 0,
                    ]
                );
            }
        } else {
            // Clear follow-up fields
            $visit->update([
                'planning_follow_up' => false,
                'follow_up_date'     => null,
                'follow_up_reason'   => null,
            ]);
        }
    }

    // =========================================================================
    // FOLLOW-UP STANDALONE ENDPOINTS
    // =========================================================================

    public function storeFollowUp(string $visitId, array $data): Visit
    {
        $visit = Visit::findOrFail($visitId);

        if ($visit->guarantor_type !== 'BPJS' && empty($data['follow_up_date'])) {
            throw new \Exception('Tanggal kontrol ulang wajib diisi.', 422);
        }

        $examination = DoctorExamination::where('visit_id', $visitId)->first();

        $this->handlePlanningFollowUp($visit, $data, $examination ?? new DoctorExamination());

        $this->log(auth('api')->id(), 'STORE_FOLLOW_UP', Visit::class, $visitId);

        return $visit->fresh();
    }

    public function updateFollowUp(string $visitId, array $data): Visit
    {
        return $this->storeFollowUp($visitId, $data);
    }

    public function deleteFollowUp(string $visitId): Visit
    {
        $visit = Visit::findOrFail($visitId);

        $visit->update([
            'planning_follow_up' => false,
            'follow_up_date'     => null,
            'follow_up_reason'   => null,
        ]);

        // Revoke draft BPJS control letter if exists
        BpjsControlLetter::where('visit_id', $visitId)
            ->where('status', 'DRAFT')
            ->delete();

        // Soft-delete draft follow-up documents
        $docType = DocumentType::where('code', 'FOLLOW_UP_LETTER')
            ->orWhere('name', 'like', '%Surat Kontrol%')
            ->first();

        if ($docType) {
            PatientDocument::where('visit_id', $visitId)
                ->where('document_type_id', $docType->id)
                ->where('status', 'DRAFT')
                ->delete();
        }

        $this->log(auth('api')->id(), 'DELETE_FOLLOW_UP', Visit::class, $visitId);

        return $visit->fresh();
    }

    // =========================================================================
    // FINALIZE KUNJUNGAN
    // =========================================================================

    public function finalizeKunjungan(string $visitId): DoctorExamination
    {
        $examination = DoctorExamination::where('visit_id', $visitId)->firstOrFail();

        if ($examination->is_finalized) {
            throw new \Exception('Pemeriksaan sudah dikunci.', 422);
        }

        if (! $examination->diagnosis_utama || ! $examination->planning) {
            throw new \Exception('Diagnosis utama dan planning wajib diisi sebelum mengunci.', 422);
        }

        DB::transaction(function () use ($examination, $visitId) {
            $examination->update([
                'is_finalized' => true,
                'finalized_at' => now(),
            ]);

            // Route to next station based on planning
            $nextStation = match ($examination->planning) {
                'BEDAH'              => 'BEDAH',
                'RUJUK'              => 'FARMASI',
                default              => 'FARMASI', // PULANG_BEROBAT_JALAN
            };

            // Queue for PENUNJANG is handled separately per order
            // Here we route to FARMASI (or BEDAH) after doctor is done
            Visit::where('id', $visitId)->update(['current_station' => $nextStation]);

            $lastSeq  = Queue::where('station', $nextStation)->whereDate('created_at', today())->max('queue_sequence') ?? 0;
            $sequence = $lastSeq + 1;
            $prefix   = match ($nextStation) {
                'BEDAH'   => 'B',
                'FARMASI' => 'F',
                default   => 'F',
            };

            Queue::create([
                'visit_id'       => $visitId,
                'station'        => $nextStation,
                'queue_prefix'   => $prefix,
                'queue_sequence' => $sequence,
                'queue_number'   => $prefix . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
                'status'         => 'WAITING',
            ]);
        });

        $this->log(auth('api')->id(), 'FINALIZE_KUNJUNGAN', DoctorExamination::class, $examination->id, "Planning: {$examination->planning}");

        return $examination->fresh(['doctor', 'surgeryPackage']);
    }

    // =========================================================================
    // ORDER PENUNJANG
    // =========================================================================

    public function getOrderPenunjang(string $visitId): Collection
    {
        return DiagnosticOrder::with(['orderedBy', 'results'])
            ->where('visit_id', $visitId)
            ->get();
    }

    public function storeOrderPenunjang(array $data): DiagnosticOrder
    {
        $user  = auth('api')->user();
        $order = DiagnosticOrder::create([
            'visit_id'      => $data['visit_id'],
            'ordered_by_id' => $user->employee_id,
            'test_type'     => $data['test_type'],
            'eye_side'      => $data['eye_side'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'status'        => 'REQUESTED',
        ]);

        // Route visit to PENUNJANG station (but keep in DOKTER until comes back)
        // Create PENUNJANG queue so penunjang can see the order
        $lastSeq  = Queue::where('station', 'PENUNJANG')->whereDate('created_at', today())->max('queue_sequence') ?? 0;
        $sequence = $lastSeq + 1;

        Queue::create([
            'visit_id'       => $data['visit_id'],
            'station'        => 'PENUNJANG',
            'queue_prefix'   => 'P',
            'queue_sequence' => $sequence,
            'queue_number'   => 'P-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
            'status'         => 'WAITING',
        ]);

        $this->log($user->id, 'ORDER_PENUNJANG', DiagnosticOrder::class, $order->id, "Order {$data['test_type']} untuk kunjungan {$data['visit_id']}");

        return $order->load('orderedBy');
    }

    public function cancelOrderPenunjang(string $id): void
    {
        $order = DiagnosticOrder::findOrFail($id);

        if ($order->status !== 'REQUESTED') {
            throw new \Exception('Order tidak bisa dibatalkan — sudah diproses.', 422);
        }

        $order->update(['status' => 'CANCELLED']);
        $this->log(auth('api')->id(), 'CANCEL_ORDER_PENUNJANG', DiagnosticOrder::class, $id);
    }

    public function getHasilPenunjang(string $visitId): Collection
    {
        return DiagnosticOrder::with('results')
            ->where('visit_id', $visitId)
            ->whereIn('status', ['COMPLETED', 'IN_PROGRESS'])
            ->get();
    }

    public function getIolRekomendasi(string $visitId): Collection
    {
        return IolRecommendation::with('approvedBy')
            ->where('visit_id', $visitId)
            ->get();
    }

    // =========================================================================
    // MEDICAL RESUME
    // =========================================================================

    public function getResumeMedis(string $visitId): ?MedicalResume
    {
        return MedicalResume::where('visit_id', $visitId)->first();
    }

    /**
     * Auto-generate resume from all available visit data.
     */
    public function generateMedicalResume(string $visitId): MedicalResume
    {
        $visit = Visit::with([
            'patient',
            'nurseAssessment',
            'refractionRecord',
            'doctorExamination',
            'diagnosticOrders.results',
        ])->findOrFail($visitId);

        $nurse     = $visit->nurseAssessment;
        $refraksi  = $visit->refractionRecord;
        $doctor    = $visit->doctorExamination;
        $user      = auth('api')->user();

        // S — Subjective: anamnese
        $s = $doctor->anamnese ?? $nurse?->chief_complaint ?? '-';

        // O — Objective: TTV + visus + IOP
        $tvvParts  = [];
        if ($nurse) {
            $tvvParts[] = "TD: {$nurse->td_sistol}/{$nurse->td_diastol} mmHg";
            $tvvParts[] = "Nadi: {$nurse->nadi} x/mnt";
            $tvvParts[] = "Suhu: {$nurse->suhu} °C";
            $tvvParts[] = "SpO2: {$nurse->spo2}%";
        }

        $visusParts = [];
        if ($refraksi) {
            $visusParts[] = "Visus OD: " . ($refraksi->visus_akhir_od ?? '-') . ", OS: " . ($refraksi->visus_akhir_os ?? '-');
            if ($refraksi->iop_od || $refraksi->iop_os) {
                $visusParts[] = "IOP OD: {$refraksi->iop_od} mmHg, OS: {$refraksi->iop_os} mmHg";
            }
        }

        $o = implode('. ', array_merge($tvvParts, $visusParts)) ?: '-';

        // A — Assessment: ICD-10
        $aParts = [];
        if ($doctor?->diagnosis_utama) {
            $aParts[] = $doctor->diagnosis_utama;
        }
        foreach ($doctor->diagnosis_sekunder ?? [] as $kode) {
            $aParts[] = $kode;
        }
        $a = implode(', ', $aParts) ?: '-';

        // P — Plan: ICD-9 + planning + follow-up
        $pParts = [];
        foreach ($doctor->tindakan_codes ?? [] as $kode) {
            $pParts[] = $kode;
        }
        if ($doctor?->planning) {
            $pParts[] = "Planning: {$doctor->planning}";
        }

        $p = implode('. ', $pParts) ?: '-';

        // Append follow-up to resume_p
        $visit->refresh();
        if ($visit->planning_follow_up && $visit->follow_up_date) {
            $p .= "\nKontrol Ulang: {$visit->follow_up_date->format('Y-m-d')}";
            if ($visit->follow_up_reason) {
                $p .= " — {$visit->follow_up_reason}";
            }
        }

        // Penunjang results JSONB
        $penunjangResults = $visit->diagnosticOrders
            ->filter(fn ($o) => $o->status === 'COMPLETED')
            ->flatMap(fn ($o) => $o->results->map(fn ($r) => [
                'test_type'  => $o->test_type,
                'eye_side'   => $o->eye_side,
                'result'     => $r->expertise_data ?? [],
                'date'       => $r->created_at?->toDateString(),
            ]))
            ->values()
            ->toArray();

        // Upsert MedicalResume
        $resume = MedicalResume::updateOrCreate(
            ['visit_id' => $visitId],
            [
                'doctor_id'          => $user->employee_id,
                'resume_s'           => $s,
                'resume_o'           => $o,
                'resume_a'           => $a,
                'resume_p'           => $p,
                'penunjang_results'  => $penunjangResults,
                'is_editable'        => true,
                'is_finalized'       => false,
                'generated_at'       => now(),
            ]
        );

        // Link to doctor_examination
        if ($doctor) {
            $doctor->update(['medical_resume_id' => $resume->id]);
        }

        $this->log($user->id, 'GENERATE_RESUME', MedicalResume::class, $resume->id);

        return $resume->fresh();
    }

    public function updateResumeMedis(string $id, array $data): MedicalResume
    {
        $resume = MedicalResume::findOrFail($id);

        if ($resume->is_finalized) {
            throw new \Exception('Resume medis sudah dikunci, tidak bisa diubah.', 422);
        }

        if (! $resume->is_editable) {
            throw new \Exception('Resume medis tidak bisa diedit.', 422);
        }

        $resume->update(array_intersect_key($data, array_flip(['resume_s', 'resume_o', 'resume_a', 'resume_p'])));

        $this->log(auth('api')->id(), 'UPDATE_RESUME', MedicalResume::class, $id);

        return $resume->fresh();
    }

    public function finalizeResumeMedis(string $id): MedicalResume
    {
        $resume = MedicalResume::findOrFail($id);

        if ($resume->is_finalized) {
            throw new \Exception('Resume medis sudah dikunci.', 422);
        }

        $resume->update([
            'is_finalized' => true,
            'is_editable'  => false,
            'finalized_at' => now(),
        ]);

        $this->log(auth('api')->id(), 'FINALIZE_RESUME', MedicalResume::class, $id);

        return $resume->fresh();
    }

    // =========================================================================
    // RUJUKAN KELUAR
    // =========================================================================

    public function storeRujukanKeluar(array $data): BpjsReferralOut
    {
        $user     = auth('api')->user();
        $rujukan  = BpjsReferralOut::create([
            'visit_id'            => $data['visit_id'],
            'faskes_tujuan_kode'  => $data['faskes_tujuan_kode'],
            'faskes_tujuan_nama'  => $data['faskes_tujuan_nama'] ?? null,
            'kode_spesialis'      => $data['kode_spesialis'] ?? null,
            'urgency'             => $data['urgency'] ?? 'ELEKTIF',
            'diagnosa_rujukan'    => $data['diagnosa_rujukan'],
            'diagnosa_nama'       => $data['diagnosa_nama'] ?? null,
            'catatan_rujukan'     => $data['catatan_rujukan'] ?? null,
            'status'              => 'DRAFT',
        ]);

        $this->log($user->id, 'STORE_RUJUKAN_KELUAR', BpjsReferralOut::class, $rujukan->id);

        return $rujukan;
    }

    // =========================================================================
    // INBOX TTD
    // =========================================================================

    public function getInboxNotifications(): Collection
    {
        $userId = auth('api')->id();

        return Notification::with(['patientDocument.patient', 'patientDocument.documentType'])
            ->where('recipient_id', $userId)
            ->orderByRaw('is_read ASC, created_at DESC')
            ->limit(50)
            ->get();
    }

    public function markNotificationRead(string $id): Notification
    {
        $notif = Notification::where('recipient_id', auth('api')->id())->findOrFail($id);

        if (! $notif->is_read) {
            $notif->update(['is_read' => true, 'read_at' => now()]);
        }

        return $notif->fresh();
    }

    /**
     * Sign document with PIN verification.
     */
    public function signDocument(string $documentId, string $pin): PatientDocument
    {
        $user     = auth('api')->user()->loadMissing('employee');
        $document = PatientDocument::findOrFail($documentId);

        if (! in_array($document->status, ['WAITING_SIGNATURE', 'DRAFT'])) {
            throw new \Exception('Dokumen tidak dalam status menunggu TTD.', 422);
        }

        // Verify PIN (pin is hidden in JSON but accessible in PHP)
        if (! Hash::check($pin, $user->pin)) {
            throw new \Exception('PIN tidak sesuai.', 401);
        }

        $pendingRoles = $document->pending_signature_roles ?? [];
        $doctorRole   = 'DOCTOR';

        if (! in_array($doctorRole, $pendingRoles)) {
            throw new \Exception('Dokter tidak termasuk dalam daftar penandatangan dokumen ini.', 422);
        }

        return DB::transaction(function () use ($document, $user, $pendingRoles, $doctorRole) {
            // Add signature entry
            $signatures   = $document->signatures ?? [];
            $signatures[] = [
                'role'      => $doctorRole,
                'name'      => $user->employee?->name ?? $user->name,
                'sign_type' => 'PIN',
                'signed_at' => now()->toIso8601String(),
                'status'    => 'SIGNED',
            ];

            // Remove DOCTOR from pending
            $remainingPending = array_values(array_filter($pendingRoles, fn ($r) => $r !== $doctorRole));

            $updateData = [
                'signatures'              => $signatures,
                'pending_signature_roles' => $remainingPending,
                'status'                  => count($remainingPending) === 0 ? 'FINAL' : 'WAITING_SIGNATURE',
            ];

            if (count($remainingPending) === 0) {
                $updateData['finalized_at'] = now();
            }

            $document->update($updateData);

            // Create DocumentVerification QR when FINAL
            if ($document->status === 'FINAL') {
                DocumentVerification::create([
                    'patient_document_id' => $document->id,
                    'verification_token'  => \Illuminate\Support\Str::uuid(),
                    'verification_url'    => url('/api/v1/rekam-medis/verifikasi/' . \Illuminate\Support\Str::uuid()),
                    'document_hash'       => hash('sha256', json_encode($document->toArray())),
                    'is_valid'            => true,
                    'scan_count'          => 0,
                ]);

                // Notify: DOCUMENT_FINAL
                Notification::where('patient_document_id', $document->id)
                    ->update(['is_read' => true, 'read_at' => now()]);
            }

            $this->log(auth('api')->id(), 'SIGN_DOCUMENT', PatientDocument::class, $document->id, "Dokumen ditandatangani oleh dokter");

            return $document->fresh(['patient', 'documentType']);
        });
    }

    /**
     * Reject document with reason.
     */
    public function rejectDocument(string $documentId, string $reason): PatientDocument
    {
        $user     = auth('api')->user()->loadMissing('employee');
        $document = PatientDocument::findOrFail($documentId);

        if (! in_array($document->status, ['WAITING_SIGNATURE', 'DRAFT'])) {
            throw new \Exception('Dokumen tidak dalam status yang dapat ditolak.', 422);
        }

        $signatures   = $document->signatures ?? [];
        $signatures[] = [
            'role'        => 'DOCTOR',
            'name'        => $user->employee?->name ?? $user->name,
            'sign_type'   => 'PIN',
            'signed_at'   => now()->toIso8601String(),
            'status'      => 'REJECTED',
            'reject_note' => $reason,
        ];

        $document->update([
            'status'       => 'REJECTED',
            'reject_reason' => $reason,
            'signatures'   => $signatures,
        ]);

        // Notify staff that document was rejected
        Notification::create([
            'recipient_id'        => null, // Broadcast ke stasiun pembuat (implementasi nanti via Reverb)
            'type'                => 'SIGNATURE_REJECTED',
            'patient_document_id' => $document->id,
            'title'               => 'Dokumen Ditolak',
            'message'             => "Dokter menolak dokumen: {$reason}",
            'is_read'             => false,
            'resend_count'        => 0,
        ]);

        $this->log(auth('api')->id(), 'REJECT_DOCUMENT', PatientDocument::class, $documentId, "Alasan: {$reason}");

        return $document->fresh(['patient', 'documentType']);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

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
