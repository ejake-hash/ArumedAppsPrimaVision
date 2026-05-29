<?php

namespace App\Services;

use App\Models\BpjsControlLetter;
use App\Models\BpjsReferralOut;
use App\Models\ClinicProfile;
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
use App\Models\Procedure;
use App\Models\Queue;
use App\Models\SurgerySchedule;
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
        private readonly KasirService $kasirService,
    ) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function getPatientQueue(): Collection
    {
        $user = auth('api')->user();

        $query = Queue::with(['visit.patient', 'visit.nurseAssessment', 'visit.refractionRecord'])
            ->where('station', 'DOKTER')
            ->whereDate('created_at', today());

        // Superadmin melihat seluruh antrean DOKTER. Dokter biasa hanya melihat
        // pasien yang memilih dirinya saat admisi
        // (visits.doctor_schedule_id → doctor_schedules.employee_id).
        if (! $user?->isSuperadmin()) {
            $employeeId = $user?->employee_id;
            $query->whereHas('visit.doctorSchedule', function ($q) use ($employeeId) {
                // employeeId null (user tanpa employee) → tidak match apa pun → antrean kosong.
                $q->where('employee_id', $employeeId);
            });
        }

        return $query->orderBy('queue_sequence')->get();
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->with('visit.doctorSchedule')
            ->findOrFail($queueId);
        $this->authorizeQueueOwnership($queue);

        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Dokter → advance ke PENUNJANG / BEDAH / KASIR
     * (lihat QueueService::resolveNextStation Section 11.3).
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->with('visit.doctorSchedule')
            ->findOrFail($queueId);
        $this->authorizeQueueOwnership($queue);

        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_DOKTER);
    }

    /**
     * Kirim pasien ke pemeriksaan penunjang: baris DOKTER di-pause (status DI_PENUNJANG)
     * dan diturunkan ke paling bawah antrean. Baris tetap milik dokter — saat semua
     * order penunjang selesai, PenunjangService menaikkannya kembali (SELESAI_PENUNJANG).
     */
    public function kirimKePenunjang(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->with('visit.doctorSchedule')
            ->findOrFail($queueId);
        $this->authorizeQueueOwnership($queue);

        $hasOpenOrder = DiagnosticOrder::where('visit_id', $queue->visit_id)
            ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
            ->exists();
        if (! $hasOpenOrder) {
            throw new \Exception('Belum ada order penunjang untuk pasien ini.', 422);
        }

        $maxSeq = Queue::byStation(Queue::STATION_DOKTER)
            ->whereDate('created_at', today())
            ->max('queue_sequence') ?? 0;

        $queue->update([
            'status'         => Queue::STATUS_AT_PENUNJANG,
            'queue_sequence' => $maxSeq + 1,
            'called_at'      => null,
            'started_at'     => null,
        ]);

        return $queue->fresh(['visit.patient']);
    }

    /**
     * Pastikan queue ini milik dokter yang sedang login.
     * Superadmin dikecualikan (boleh memanggil/menyelesaikan antrean siapa pun).
     * Dokter lain → tolak (403), konsisten dengan filter di getPatientQueue().
     */
    private function authorizeQueueOwnership(Queue $queue): void
    {
        $this->assertOwnedByCurrentDoctor($queue->visit?->doctorSchedule?->employee_id);
    }

    /**
     * Pastikan dokter login berhak atas kunjungan (visit) ini, lalu kembalikan
     * Visit-nya (doctorSchedule sudah ter-load) agar bisa dipakai ulang pemanggil.
     * Dipakai semua endpoint per-visit supaya dokter tidak bisa melihat / mengubah
     * pasien milik dokter lain. Superadmin dikecualikan.
     */
    private function authorizeVisitOwnership(string $visitId): Visit
    {
        $visit = Visit::with('doctorSchedule')->findOrFail($visitId);
        $this->assertOwnedByCurrentDoctor($visit->doctorSchedule?->employee_id);

        return $visit;
    }

    /**
     * Inti pengecekan kepemilikan: bandingkan employee_id pemilik kunjungan dengan
     * dokter login. Superadmin selalu lolos; pemilik null / berbeda → 403.
     */
    private function assertOwnedByCurrentDoctor(?string $ownerEmployeeId): void
    {
        $user = auth('api')->user();
        if ($user?->isSuperadmin()) {
            return;
        }

        if (! $ownerEmployeeId || $ownerEmployeeId !== $user?->employee_id) {
            throw new \Exception('Pasien ini bukan pasien Anda.', 403);
        }
    }

    // =========================================================================
    // TAB 1 — DATA PASIEN (READONLY: triase + refraksi)
    // =========================================================================

    public function getPatientData(string $visitId): Visit
    {
        $this->authorizeVisitOwnership($visitId);

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
        $this->authorizeVisitOwnership($visitId);

        return DoctorExamination::where('visit_id', $visitId)->first();
    }

    /**
     * Create Tab 2 (anamnese + segmen). One per visit.
     */
    public function storeExamination(string $visitId, array $data): DoctorExamination
    {
        $this->authorizeVisitOwnership($visitId);

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
        $this->authorizeVisitOwnership($visitId);

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

    /**
     * Daftar tindakan + tarif sesuai metode bayar kunjungan (guarantor_type + insurer).
     * Tarif diresolusi pakai logika kanonik KasirService::getPrice (fallback 3-level).
     */
    public function getTarifTindakan(string $visitId): array
    {
        $visit = $this->authorizeVisitOwnership($visitId);

        return Procedure::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category'])
            ->map(fn ($p) => [
                'id'       => $p->id,
                'code'     => $p->code,
                'name'     => $p->name,
                'category' => $p->category,
                'price'    => $this->kasirService->getPrice('procedure', $p->id, $visit->guarantor_type, $visit->insurer_id),
            ])
            ->all();
    }

    /**
     * Daftar obat yang sudah punya harga jual (inventori farmasi → penentuan harga).
     * Hanya obat ber-harga (inner join inventory_prices) yang bisa diresepkan dokter.
     */
    public function getDaftarObat(?string $search = null): array
    {
        return DB::table('medications as m')
            ->join('inventory_prices as ip', function ($j) {
                $j->on('ip.item_id', '=', 'm.id')->where('ip.item_type', '=', 'MEDICATION');
            })
            ->whereNull('m.deleted_at')
            ->where('m.is_active', true)
            ->when($search, function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('m.name', 'ilike', "%{$search}%")
                      ->orWhere('m.code', 'ilike', "%{$search}%")
                      ->orWhere('m.generic_name', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('m.name')
            ->limit(100)
            ->get(['m.id', 'm.code', 'm.name', 'm.form_sediaan', 'm.golongan', 'm.unit', 'm.stock', 'ip.hja'])
            ->map(fn ($r) => [
                'id'       => $r->id,
                'code'     => $r->code,
                'name'     => $r->name,
                'form'     => $r->form_sediaan,
                'golongan' => $r->golongan,
                'unit'     => $r->unit,
                'stock'    => $r->stock,
                'hja'      => (float) $r->hja,
            ])
            ->all();
    }

    public function getVisitServices(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return VisitService::with('procedure')->where('visit_id', $visitId)->get();
    }

    /**
     * Replace seluruh tindakan kunjungan dengan daftar baru (sinkron dgn UI dokter).
     * Aman: di tahap dokter belum ada billing yang merujuk visit_services.
     * Array kosong = hapus semua tindakan.
     */
    public function storeVisitServices(string $visitId, array $services): Collection
    {
        $this->authorizeVisitOwnership($visitId);
        $user = auth('api')->user();

        return DB::transaction(function () use ($visitId, $services, $user) {
            // Bersihkan tindakan lama lalu tulis ulang dari daftar terkini.
            VisitService::where('visit_id', $visitId)->delete();

            $created = [];
            foreach ($services as $item) {
                $created[] = VisitService::create([
                    'visit_id'        => $visitId,
                    'procedure_id'    => $item['procedure_id'],
                    'performed_by_id' => $user->employee_id,
                    'quantity'        => $item['quantity'] ?? 1,
                    'price'           => $item['price'] ?? 0,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            $this->log($user->id, 'STORE_TINDAKAN', Visit::class, $visitId, count($created) . ' tindakan disimpan (replace)');

            return Collection::make($created)->load('procedure');
        });
    }

    public function deleteVisitService(string $id): void
    {
        $service = VisitService::findOrFail($id);
        $this->authorizeVisitOwnership($service->visit_id);
        $service->delete();
        $this->log(auth('api')->id(), 'DELETE_TINDAKAN', VisitService::class, $id);
    }

    public function getPrescriptions(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return Prescription::with('items.medication')->where('visit_id', $visitId)->get();
    }

    /**
     * Replace resep DRAFT kunjungan dengan daftar baru (sinkron dgn UI dokter).
     * Hanya menyentuh resep berstatus DRAFT (yang sudah SUBMITTED/DISPENSING tidak diutak-atik).
     * items kosong = kosongkan resep (hapus draft, tidak buat baru).
     */
    public function storePrescription(string $visitId, array $data): ?Prescription
    {
        $this->authorizeVisitOwnership($visitId);
        $user = auth('api')->user();

        return DB::transaction(function () use ($visitId, $data, $user) {
            // Bersihkan resep DRAFT lama + itemnya.
            $drafts = Prescription::where('visit_id', $visitId)->where('status', 'DRAFT')->get();
            foreach ($drafts as $d) {
                PrescriptionItem::where('prescription_id', $d->id)->delete();
                $d->delete();
            }

            $items = $data['items'] ?? [];
            if (empty($items)) {
                $this->log($user->id, 'STORE_RESEP', Prescription::class, $visitId, 'Resep dikosongkan');
                return null;
            }

            $prescription = Prescription::create([
                'visit_id'         => $visitId,
                'prescribed_by_id' => $user->employee_id,
                'status'           => 'DRAFT',
                'notes'            => $data['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'medication_id'   => $item['medication_id'],
                    'quantity'        => $item['quantity'] ?? 1,
                    'dose'            => $item['dose'] ?? null,
                    'frequency'       => $item['frequency'] ?? null,
                    'route'           => $item['route'] ?? null,
                    'duration_days'   => $item['duration_days'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            $this->log($user->id, 'STORE_RESEP', Prescription::class, $prescription->id, "Resep disimpan (replace) untuk kunjungan {$visitId}");

            return $prescription->load('items.medication');
        });
    }

    // =========================================================================
    // TAB 4 — SOAP + ICD + PLANNING (KRITIS: include follow-up logic)
    // =========================================================================

    public function getTab4(string $visitId): ?DoctorExamination
    {
        $this->authorizeVisitOwnership($visitId);

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
        $this->authorizeVisitOwnership($visitId);
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

            // Planning BEDAH: buat/perbarui SurgerySchedule dari paket + tanggal yang dipilih
            // dokter. Routing ke stasiun BEDAH (jika tanggal = hari ini) bergantung pada
            // surgery_schedule_id ini (lihat QueueService::nextAfterDokter).
            $scheduleId = $this->resolveSurgerySchedule($examination, $data);

            $examination->update([
                'soap_subjective'    => $data['soap_subjective'] ?? null,
                'soap_objective'     => $data['soap_objective'] ?? null,
                'soap_assessment'    => $data['soap_assessment'] ?? null,
                'soap_plan'          => $data['soap_plan'] ?? null,
                'diagnosis_utama'    => $data['diagnosis_utama'],
                'diagnosis_sekunder' => $data['diagnosis_sekunder'] ?? [],
                'tindakan_codes'     => $data['tindakan_codes'] ?? [],
                'planning'           => $data['planning'],
                'surgery_package_id' => $data['planning'] === 'BEDAH' ? ($data['surgery_package_id'] ?? null) : null,
                'surgery_schedule_id' => $scheduleId,
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

    /**
     * Tentukan surgery_schedule_id untuk planning Tab 4.
     *
     * - planning != BEDAH               → null (dan jadwal lama yang masih SCHEDULED dibatalkan).
     * - surgery_schedule_id eksplisit   → dipakai apa adanya (preop flow / pilih jadwal existing).
     * - BEDAH + paket + tanggal         → buat baru, atau perbarui jadwal yang sudah terhubung
     *                                     ke examination ini selama belum mulai (status SCHEDULED).
     * - BEDAH tanpa paket/tanggal       → biarkan jadwal lama apa adanya (dokter belum lengkap isi).
     */
    private function resolveSurgerySchedule(DoctorExamination $examination, array $data): ?string
    {
        // Bukan bedah → lepas & batalkan jadwal yang sebelumnya dibuat dari examination ini.
        if (($data['planning'] ?? null) !== 'BEDAH') {
            if ($examination->surgery_schedule_id) {
                SurgerySchedule::where('id', $examination->surgery_schedule_id)
                    ->where('status', 'SCHEDULED')
                    ->update(['status' => 'CANCELLED']);
            }
            return null;
        }

        // Jadwal dipilih eksplisit (mis. preop flow) → hormati.
        if (! empty($data['surgery_schedule_id'])) {
            return $data['surgery_schedule_id'];
        }

        $packageId = $data['surgery_package_id'] ?? null;
        $date      = $data['surgery_date'] ?? null;

        // Belum lengkap untuk membuat jadwal → pertahankan yang lama (kalau ada).
        if (! $packageId || ! $date) {
            return $examination->surgery_schedule_id;
        }

        // Default ruang OK dari Profil Klinik (ambil yang pertama bila ada).
        $defaultRoom = ClinicProfile::query()->value('operating_rooms');
        $defaultRoom = is_array($defaultRoom) ? ($defaultRoom[0] ?? null) : null;

        $payload = [
            'surgery_package_id' => $packageId,
            'scheduled_date'     => $date,
            'scheduled_time'     => $data['surgery_time'] ?? null,
            'operation_room'     => $data['operation_room'] ?? $defaultRoom,
            'status'             => 'SCHEDULED',
        ];

        // Perbarui jadwal yang sudah terhubung & belum mulai; selain itu buat baru.
        $existing = $examination->surgery_schedule_id
            ? SurgerySchedule::where('id', $examination->surgery_schedule_id)
                ->where('status', 'SCHEDULED')
                ->first()
            : null;

        if ($existing) {
            $existing->update($payload);
            return $existing->id;
        }

        return SurgerySchedule::create($payload)->id;
    }

    /**
     * Preview ringkas jadwal bedah pada satu tanggal (Tab 4 → Jadwalkan Bedah).
     * Hanya jadwal aktif (status SCHEDULED). Mengembalikan total + daftar jam terisi
     * (untuk menandai slot bentrok di dropdown jam dokter).
     */
    public function getBedahSlot(string $tanggal): array
    {
        $rows = SurgerySchedule::with('surgeryPackage:id,name')
            ->whereDate('scheduled_date', $tanggal)
            ->where('status', 'SCHEDULED')
            ->orderBy('scheduled_time')
            ->get(['id', 'scheduled_time', 'operation_room', 'surgery_package_id']);

        return [
            'tanggal' => $tanggal,
            'total'   => $rows->count(),
            'slots'   => $rows->map(fn ($s) => [
                'time'         => $s->scheduled_time ? substr($s->scheduled_time, 0, 5) : null,
                'room'         => $s->operation_room,
                'package_name' => $s->surgeryPackage?->name,
            ])->values()->all(),
        ];
    }

    // =========================================================================
    // FOLLOW-UP STANDALONE ENDPOINTS
    // =========================================================================

    public function storeFollowUp(string $visitId, array $data): Visit
    {
        $this->authorizeVisitOwnership($visitId);
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
        $this->authorizeVisitOwnership($visitId);
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
        $this->authorizeVisitOwnership($visitId);

        $examination = DoctorExamination::where('visit_id', $visitId)->firstOrFail();

        if ($examination->is_finalized) {
            throw new \Exception('Pemeriksaan sudah dikunci.', 422);
        }

        if (! $examination->diagnosis_utama || ! $examination->planning) {
            throw new \Exception('Diagnosis utama dan planning wajib diisi sebelum mengunci.', 422);
        }

        // Tanda tangan digital = identitas akun dokter yang sedang login (otoritatif
        // di server, tidak bergantung input klien). Sekaligus pastikan doctor_id terikat
        // ke penandatangan walau record sempat dibuat oleh tab lain.
        $user      = auth('api')->user();
        $employee  = $user?->employee;
        $signer    = $employee?->name ?? $user?->name ?? 'Dokter';
        if ($employee?->sip) {
            $signer .= " (SIP: {$employee->sip})";
        }

        // Hanya kunci pemeriksaan. Routing & pembuatan baris antrean stasiun
        // berikutnya adalah tanggung jawab tunggal QueueService::advanceFromStation
        // (dipanggil via selesaiAntrian). Jangan buat baris antrean di sini agar
        // pasien tidak ter-enqueue ganda. Lihat [[queue-advance-station-pattern]].
        $examination->update([
            'is_finalized'        => true,
            'finalized_at'        => now(),
            'digital_signature'   => $signer,
            'signature_timestamp' => now(),
            'doctor_id'           => $examination->doctor_id ?? $employee?->id,
        ]);

        $this->log(auth('api')->id(), 'FINALIZE_KUNJUNGAN', DoctorExamination::class, $examination->id, "Planning: {$examination->planning}");

        return $examination->fresh(['doctor', 'surgeryPackage']);
    }

    // =========================================================================
    // ORDER PENUNJANG
    // =========================================================================

    public function getOrderPenunjang(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return DiagnosticOrder::with(['orderedBy', 'results'])
            ->where('visit_id', $visitId)
            ->get();
    }

    public function storeOrderPenunjang(array $data): DiagnosticOrder
    {
        $this->authorizeVisitOwnership($data['visit_id']);

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
        $this->authorizeVisitOwnership($order->visit_id);

        if ($order->status !== 'REQUESTED') {
            throw new \Exception('Order tidak bisa dibatalkan — sudah diproses.', 422);
        }

        $order->update(['status' => 'CANCELLED']);
        $this->log(auth('api')->id(), 'CANCEL_ORDER_PENUNJANG', DiagnosticOrder::class, $id);
    }

    public function getHasilPenunjang(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return DiagnosticOrder::with('results')
            ->where('visit_id', $visitId)
            ->whereIn('status', ['COMPLETED', 'IN_PROGRESS'])
            ->get();
    }

    public function getIolRekomendasi(string $visitId): Collection
    {
        $this->authorizeVisitOwnership($visitId);

        return IolRecommendation::with('approvedBy')
            ->where('visit_id', $visitId)
            ->get();
    }

    /**
     * Preview tagihan penunjang yang sudah COMPLETED untuk satu kunjungan.
     *
     * Dipakai Tab 3 dokter agar melihat penunjang + harga (sesuai penjamin)
     * SEBELUM kirim ke kasir. Penunjang = procedure kategori "Penunjang":
     * diagnostic_orders.test_type menyimpan KODE procedure, harga di-resolve
     * via procedure_tariffs (getPrice 'procedure') — mirror persis dengan
     * KasirService::buildPenunjangLines supaya preview == invoice. Order
     * "Lainnya" (kode tak terdaftar di procedures) dilewati.
     *
     * @return array<int, array{id:string, code:string, name:string, category:?string, eye_side:?string, price:float}>
     */
    public function getPenunjangBilling(string $visitId): array
    {
        $visit  = $this->authorizeVisitOwnership($visitId);
        $orders = DiagnosticOrder::where('visit_id', $visitId)
            ->where('status', 'COMPLETED')
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $codes   = $orders->pluck('test_type')->unique()->filter()->values()->all();
        $procMap = Procedure::whereIn('code', $codes)
            ->get(['id', 'code', 'name', 'category'])
            ->keyBy('code');

        $rows = [];
        foreach ($orders as $order) {
            $proc = $procMap->get($order->test_type);
            if (! $proc) {
                continue; // kode tak terdaftar di procedures (mis. "Lainnya") — tidak ditarifkan
            }
            $price = $this->kasirService->getPrice(
                'procedure',
                $proc->id,
                $visit->guarantor_type,
                $visit->insurer_id,
            );
            $rows[] = [
                'id'       => $order->id,
                'code'     => $proc->code,
                'name'     => $proc->name,
                'category' => $proc->category,
                'eye_side' => $order->eye_side,
                'price'    => (float) $price,
            ];
        }

        return $rows;
    }

    // =========================================================================
    // MEDICAL RESUME
    // =========================================================================

    public function getResumeMedis(string $visitId): ?MedicalResume
    {
        $this->authorizeVisitOwnership($visitId);

        return MedicalResume::where('visit_id', $visitId)->first();
    }

    /**
     * Auto-generate resume from all available visit data.
     */
    public function generateMedicalResume(string $visitId): MedicalResume
    {
        $this->authorizeVisitOwnership($visitId);

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
        $this->authorizeVisitOwnership($resume->visit_id);

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
        $this->authorizeVisitOwnership($resume->visit_id);

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
        $this->authorizeVisitOwnership($data['visit_id']);

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
