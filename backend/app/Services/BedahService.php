<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\IolRecommendation;
use App\Models\Queue;
use App\Models\SurgeryIolUsage;
use App\Models\SurgeryRecord;
use App\Models\SurgeryRequest;
use App\Models\SurgeryRequestBhp;
use App\Models\SurgeryRequestIol;
use App\Models\SurgerySchedule;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BedahService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
    ) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function getPatientQueue(): array
    {
        $queues = Queue::with([
            'visit.patient',
            'visit.insurer',
            'visit.surgerySchedule.surgeryPackage',
            'visit.doctorExamination.surgeryPackage',
        ])
            ->where('station', 'BEDAH')
            ->whereDate('created_at', today())
            ->whereHas('visit')   // exclude baris dgn visit soft-deleted (zombie row) — sama spt AdmisiView
            ->orderBy('queue_sequence')
            ->get();

        return $queues->map(function (Queue $q) {
            $visit   = $q->visit;
            $patient = $visit?->patient;
            $dob     = $patient?->date_of_birth;

            // Schedule: prefer dari visit.surgery_schedule_id (preop flow),
            // fallback ke doctor_examination.surgery_schedule (skenario C).
            $schedule = $visit?->surgerySchedule ?? $visit?->doctorExamination?->surgerySchedule ?? null;
            $package  = $schedule?->surgeryPackage ?? $visit?->doctorExamination?->surgeryPackage ?? null;

            return [
                'id'             => $q->id,
                'queue_number'   => $q->queue_number,
                'queue_sequence' => $q->queue_sequence,
                'status'         => $q->status,
                'called_at'      => $q->called_at?->toIso8601String(),
                'started_at'     => $q->started_at?->toIso8601String(),
                'completed_at'   => $q->completed_at?->toIso8601String(),

                'visit' => $visit ? [
                    'id'             => $visit->id,
                    'classification' => $visit->classification,
                    'visit_type'     => $visit->visit_type,
                    'guarantor_type' => $visit->guarantor_type,
                    'insurer_name'   => $visit->insurer?->name,
                ] : null,

                'patient' => $patient ? [
                    'id'     => $patient->id,
                    'no_rm'  => $patient->no_rm,
                    'name'   => $patient->name,
                    'gender' => $patient->gender,
                    'age'    => $dob ? $dob->age : null,
                ] : null,

                'surgery_schedule' => $schedule ? [
                    'id'             => $schedule->id,
                    'scheduled_date' => $schedule->scheduled_date?->toDateString(),
                    'scheduled_time' => $schedule->scheduled_time,
                    'operation_room' => $schedule->operation_room,
                    'status'         => $schedule->status,
                    'package'        => $package ? [
                        'id'   => $package->id,
                        'name' => $package->name,
                    ] : null,
                ] : null,
            ];
        })->all();
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_BEDAH)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Bedah → KASIR untuk billing pasca-operasi.
     * Section 11.3 catatan opsional Bedah.
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_BEDAH)->findOrFail($queueId);
        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_BEDAH);
    }

    // =========================================================================
    // JADWAL OPERASI
    // =========================================================================

    public function getScheduledSurgeries(array $filters = []): Collection
    {
        $query = SurgerySchedule::with([
            'surgeryPackage.items',
            'leadSurgeon',
            'anesthesiologist',
            'surgeryRecord',
            'surgeryRequests',
            'visit.patient',
            'visit.doctorExamination',
            'visit.iolRecommendations',
        ]);

        // Rentang tanggal (weekpicker): tampilkan jadwal SCHEDULED dalam minggu terpilih.
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            if (! empty($filters['date_from'])) {
                $query->whereDate('scheduled_date', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->whereDate('scheduled_date', '<=', $filters['date_to']);
            }
            $query->where('status', 'SCHEDULED');
        }
        // Mode upcoming: jadwal SETELAH hari ini (pasien terjadwal mendatang).
        // Jadwal hari ini sengaja dikecualikan — pasien itu sudah masuk antrean Bedah.
        elseif (! empty($filters['upcoming'])) {
            $query->whereDate('scheduled_date', '>', today())
                  ->where('status', 'SCHEDULED');
        } elseif (! empty($filters['tanggal'])) {
            $query->whereDate('scheduled_date', $filters['tanggal']);
        } else {
            $query->whereDate('scheduled_date', today());
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('scheduled_date')->orderBy('scheduled_time')->get();
    }

    public function getScheduleById(string $id): SurgerySchedule
    {
        return SurgerySchedule::with([
            'surgeryPackage',
            'leadSurgeon',
            'anesthesiologist',
            'surgeryRecord.iolUsages.iolItem',
            'surgeryRequests.bhpItems.bhpItem',
            'surgeryRequests.iolItems.iolItem',
        ])->findOrFail($id);
    }

    public function storeSchedule(array $data): SurgerySchedule
    {
        $schedule = SurgerySchedule::create([
            'surgery_package_id'   => $data['surgery_package_id'],
            'lead_surgeon_id'      => $data['lead_surgeon_id'],
            'anesthesiologist_id'  => $data['anesthesiologist_id'] ?? null,
            'scheduled_date'       => $data['scheduled_date'],
            'scheduled_time'       => $data['scheduled_time'],
            'operation_room'       => $data['operation_room'] ?? null,
            'status'               => 'SCHEDULED',
            'notes'                => $data['notes'] ?? null,
        ]);

        $this->log(auth('api')->id(), 'STORE_JADWAL', SurgerySchedule::class, $schedule->id, "Jadwal operasi {$data['scheduled_date']}");

        return $schedule->load(['surgeryPackage', 'leadSurgeon']);
    }

    public function updateSchedule(string $id, array $data): SurgerySchedule
    {
        $schedule = SurgerySchedule::findOrFail($id);

        if (in_array($schedule->status, ['IN_PROGRESS', 'DONE'])) {
            throw new \Exception('Jadwal tidak bisa diubah — operasi sudah berjalan atau selesai.', 422);
        }

        $schedule->update(array_intersect_key($data, array_flip($schedule->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_JADWAL', SurgerySchedule::class, $id);

        return $schedule->fresh(['surgeryPackage', 'leadSurgeon']);
    }

    public function deleteSchedule(string $id): void
    {
        $schedule = SurgerySchedule::findOrFail($id);

        if (in_array($schedule->status, ['IN_PROGRESS', 'DONE'])) {
            throw new \Exception('Jadwal tidak bisa dihapus — operasi sudah berjalan atau selesai.', 422);
        }

        $schedule->delete();
        $this->log(auth('api')->id(), 'DELETE_JADWAL', SurgerySchedule::class, $id);
    }

    /**
     * Time In — mulai operasi.
     * Guard: supply request harus sudah RECEIVED sebelum operasi dimulai.
     */
    public function startOperation(string $scheduleId): SurgeryRecord
    {
        $schedule = SurgerySchedule::with(['surgeryRequests', 'surgeryRecord'])->findOrFail($scheduleId);

        if ($schedule->status !== 'SCHEDULED') {
            throw new \Exception('Jadwal tidak dalam status SCHEDULED.', 422);
        }

        // Cek BHP+IOL supply sudah diterima
        $pendingSupply = $schedule->surgeryRequests()
            ->whereNotIn('status', ['RECEIVED', 'CANCELLED'])
            ->exists();

        if ($pendingSupply) {
            throw new \Exception('BHP dan IOL belum diterima dari Farmasi. Konfirmasi terlebih dahulu.', 422);
        }

        return DB::transaction(function () use ($schedule) {
            $schedule->update(['status' => 'IN_PROGRESS']);

            // Get visit_id from surgery_requests
            $visitId = $schedule->surgeryRequests()->value('visit_id');

            $record = SurgeryRecord::create([
                'surgery_schedule_id' => $schedule->id,
                'visit_id'            => $visitId,
                'time_in'             => now(),
                'has_complication'    => false,
            ]);

            // Update antrian BEDAH → IN_PROGRESS
            if ($visitId) {
                Queue::where('visit_id', $visitId)
                    ->where('station', 'BEDAH')
                    ->whereIn('status', ['WAITING', 'CALLED'])
                    ->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);

                Visit::where('id', $visitId)->update(['current_station' => 'BEDAH']);
            }

            $this->log(auth('api')->id(), 'START_OPERATION', SurgeryRecord::class, $record->id, "Time In: " . now()->toTimeString());

            return $record->load('surgerySchedule');
        });
    }

    /**
     * Time Out — selesai operasi + laporan.
     * Routes visit ke FARMASI.
     */
    public function completeOperation(string $scheduleId, array $data): SurgeryRecord
    {
        $schedule = SurgerySchedule::with('surgeryRecord')->findOrFail($scheduleId);

        if ($schedule->status !== 'IN_PROGRESS') {
            throw new \Exception('Operasi belum dimulai.', 422);
        }

        $record = $schedule->surgeryRecord;

        if (! $record) {
            throw new \Exception('Laporan operasi tidak ditemukan. Mulai operasi terlebih dahulu.', 422);
        }

        return DB::transaction(function () use ($schedule, $record, $data) {
            $record->update([
                'time_out'             => now(),
                'operation_notes'      => $data['operation_notes'] ?? null,
                'has_complication'     => $data['has_complication'] ?? false,
                'complication_detail'  => ($data['has_complication'] ?? false) ? ($data['complication_detail'] ?? null) : null,
                'post_op_instructions' => $data['post_op_instructions'] ?? null,
                'followup_date'        => $data['followup_date'] ?? null,
            ]);

            $schedule->update(['status' => 'DONE']);

            $visitId = $record->visit_id;

            if ($visitId) {
                // Selesaikan antrian BEDAH
                Queue::where('visit_id', $visitId)
                    ->where('station', 'BEDAH')
                    ->where('status', 'IN_PROGRESS')
                    ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

                // Buat antrian FARMASI
                $lastSeq  = Queue::where('station', 'FARMASI')->whereDate('created_at', today())->max('queue_sequence') ?? 0;
                $sequence = $lastSeq + 1;

                Queue::create([
                    'visit_id'       => $visitId,
                    'station'        => 'FARMASI',
                    'queue_prefix'   => 'F',
                    'queue_sequence' => $sequence,
                    'queue_number'   => 'F-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
                    'status'         => 'WAITING',
                ]);

                Visit::where('id', $visitId)->update(['current_station' => 'FARMASI']);
            }

            $this->log(auth('api')->id(), 'COMPLETE_OPERATION', SurgeryRecord::class, $record->id, "Time Out: " . now()->toTimeString());

            return $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);
        });
    }

    // =========================================================================
    // SURGERY RECORD
    // =========================================================================

    public function getRecord(string $scheduleId): ?SurgeryRecord
    {
        return SurgeryRecord::with(['surgerySchedule', 'iolUsages.iolItem'])
            ->where('surgery_schedule_id', $scheduleId)
            ->first();
    }

    public function recordSurgery(array $data): SurgeryRecord
    {
        SurgerySchedule::findOrFail($data['surgery_schedule_id']);

        if (SurgeryRecord::where('surgery_schedule_id', $data['surgery_schedule_id'])->exists()) {
            throw new \Exception('Laporan operasi sudah ada. Gunakan update.', 422);
        }

        $record = SurgeryRecord::create([
            'surgery_schedule_id'  => $data['surgery_schedule_id'],
            'visit_id'             => $data['visit_id'] ?? null,
            'time_in'              => $data['time_in'] ?? now(),
            'time_out'             => $data['time_out'] ?? null,
            'operation_notes'      => $data['operation_notes'] ?? null,
            'has_complication'     => $data['has_complication'] ?? false,
            'complication_detail'  => ($data['has_complication'] ?? false) ? ($data['complication_detail'] ?? null) : null,
            'post_op_instructions' => $data['post_op_instructions'] ?? null,
            'followup_date'        => $data['followup_date'] ?? null,
        ]);

        $this->log(auth('api')->id(), 'STORE_RECORD', SurgeryRecord::class, $record->id);

        return $record->load('surgerySchedule');
    }

    public function updateRecord(string $id, array $data): SurgeryRecord
    {
        $record = SurgeryRecord::findOrFail($id);

        $record->update(array_filter([
            'time_out'             => $data['time_out'] ?? null,
            'operation_notes'      => $data['operation_notes'] ?? null,
            'has_complication'     => $data['has_complication'] ?? null,
            'complication_detail'  => isset($data['has_complication']) && $data['has_complication']
                                        ? ($data['complication_detail'] ?? null)
                                        : null,
            'post_op_instructions' => $data['post_op_instructions'] ?? null,
            'followup_date'        => $data['followup_date'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_RECORD', SurgeryRecord::class, $id);

        return $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);
    }

    /**
     * Update instruksi post-op specifically.
     */
    public function storePostOp(string $recordId, array $data): SurgeryRecord
    {
        $record = SurgeryRecord::findOrFail($recordId);

        $record->update([
            'post_op_instructions' => $data['post_op_instructions'],
            'followup_date'        => $data['followup_date'] ?? null,
        ]);

        $this->log(auth('api')->id(), 'STORE_POST_OP', SurgeryRecord::class, $recordId);

        return $record->fresh();
    }

    public function finalizeRecord(string $id): SurgeryRecord
    {
        $record   = SurgeryRecord::with('surgerySchedule')->findOrFail($id);
        $schedule = $record->surgerySchedule;

        if ($schedule->status !== 'DONE') {
            throw new \Exception('Operasi belum selesai — tidak bisa finalize laporan.', 422);
        }

        if (! $record->time_in || ! $record->time_out) {
            throw new \Exception('Time In dan Time Out wajib diisi sebelum finalize.', 422);
        }

        // No separate is_finalized field on surgery_records — DONE on schedule marks it
        $this->log(auth('api')->id(), 'FINALIZE_RECORD', SurgeryRecord::class, $id, 'Laporan operasi dikunci');

        return $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);
    }

    // =========================================================================
    // SUPPLY REQUEST (BHP + IOL)
    // =========================================================================

    public function getRequests(array $filters = []): Collection
    {
        $query = SurgeryRequest::with([
            'visit.patient',
            'surgerySchedule.surgeryPackage',
            'requestedBy',
            'bhpItems.bhpItem',
            'iolItems.iolItem',
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['tanggal'])) {
            $query->whereDate('created_at', $filters['tanggal']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function getRequestById(string $id): SurgeryRequest
    {
        return SurgeryRequest::with([
            'visit.patient',
            'surgerySchedule.surgeryPackage',
            'requestedBy',
            'bhpItems.bhpItem',
            'iolItems.iolItem',
        ])->findOrFail($id);
    }

    /**
     * Create supply request from Bedah → Farmasi.
     * BHP items: auto dari paket + manual additions.
     * IOL items: dari IOL recommendation (eye_side + power).
     */
    public function createSupplyRequest(string $visitId, array $data): SurgeryRequest
    {
        $user = auth('api')->user();

        return DB::transaction(function () use ($visitId, $data, $user) {
            $request = SurgeryRequest::create([
                'visit_id'            => $visitId,
                'surgery_schedule_id' => $data['surgery_schedule_id'] ?? null,
                'requested_by_id'     => $user->employee_id,
                'status'              => 'REQUESTED',
                'notes'               => $data['notes'] ?? null,
            ]);

            // BHP items
            foreach ($data['bhp_items'] ?? [] as $item) {
                SurgeryRequestBhp::create([
                    'surgery_request_id' => $request->id,
                    'bhp_item_id'        => $item['bhp_item_id'],
                    'quantity'           => $item['quantity'] ?? 1,
                    'notes'              => $item['notes'] ?? null,
                ]);
            }

            // IOL items (dari IOL recommendation — iol_item_id akan di-assign oleh Farmasi)
            foreach ($data['iol_items'] ?? [] as $item) {
                SurgeryRequestIol::create([
                    'surgery_request_id'  => $request->id,
                    'eye_side'            => $item['eye_side'] ?? 'OD',
                    'requested_iol_type'  => $item['requested_iol_type'] ?? 'MONOFOCAL',
                    'requested_power'     => $item['requested_power'] ?? null,
                    'iol_item_id'         => $item['iol_item_id'] ?? null,
                    'notes'               => $item['notes'] ?? null,
                ]);
            }

            $this->log(
                $user->id,
                'CREATE_SUPPLY_REQUEST',
                SurgeryRequest::class,
                $request->id,
                "Request BHP+IOL untuk kunjungan {$visitId}"
            );

            return $request->load(['bhpItems.bhpItem', 'iolItems', 'requestedBy']);
        });
    }

    public function updateRequest(string $id, array $data): SurgeryRequest
    {
        $request = SurgeryRequest::findOrFail($id);

        if ($request->status !== 'REQUESTED') {
            throw new \Exception('Request tidak bisa diubah setelah dikirim ke Farmasi.', 422);
        }

        $request->update(['notes' => $data['notes'] ?? $request->notes]);

        // Re-sync BHP items jika dikirim
        if (isset($data['bhp_items'])) {
            $request->bhpItems()->delete();
            foreach ($data['bhp_items'] as $item) {
                SurgeryRequestBhp::create([
                    'surgery_request_id' => $request->id,
                    'bhp_item_id'        => $item['bhp_item_id'],
                    'quantity'           => $item['quantity'] ?? 1,
                    'notes'              => $item['notes'] ?? null,
                ]);
            }
        }

        // Re-sync IOL items jika dikirim
        if (isset($data['iol_items'])) {
            $request->iolItems()->delete();
            foreach ($data['iol_items'] as $item) {
                SurgeryRequestIol::create([
                    'surgery_request_id' => $request->id,
                    'eye_side'           => $item['eye_side'],
                    'requested_iol_type' => $item['requested_iol_type'] ?? 'MONOFOCAL',
                    'requested_power'    => $item['requested_power'],
                    'notes'              => $item['notes'] ?? null,
                ]);
            }
        }

        $this->log(auth('api')->id(), 'UPDATE_SUPPLY_REQUEST', SurgeryRequest::class, $id);

        return $request->fresh(['bhpItems.bhpItem', 'iolItems', 'requestedBy']);
    }

    /**
     * Kirim request ke Farmasi (REQUESTED → SENT).
     */
    public function kirimRequest(string $id): SurgeryRequest
    {
        $request = SurgeryRequest::findOrFail($id);

        if ($request->status !== 'REQUESTED') {
            throw new \Exception('Request sudah dikirim atau bukan dalam status REQUESTED.', 422);
        }

        if ($request->bhpItems()->count() === 0 && $request->iolItems()->count() === 0) {
            throw new \Exception('Tambahkan minimal 1 item BHP atau IOL sebelum mengirim ke Farmasi.', 422);
        }

        $request->update([
            'status'  => 'SENT',
            'sent_at' => now(),
        ]);

        $this->log(auth('api')->id(), 'KIRIM_REQUEST', SurgeryRequest::class, $id, 'Request dikirim ke Farmasi');

        return $request->fresh(['bhpItems.bhpItem', 'iolItems']);
    }

    /**
     * Susun preview request BHP/IOL dari komposisi paket bedah pada satu jadwal.
     * Dipakai BedahTerjadwalView untuk 1-klik "Request BHP/IOL" — BHP diisi penuh
     * dari paket; IOL diisi dari IolRecommendation visit (eye_side+power+type),
     * sisanya baris kosong (nullable) untuk dilengkapi user/Farmasi.
     */
    public function buildRequestPreviewFromSchedule(string $scheduleId): array
    {
        $schedule = SurgerySchedule::with(['surgeryPackage.items', 'visit.patient'])
            ->findOrFail($scheduleId);

        $visit = $schedule->visit;
        if (! $visit) {
            throw new \Exception('Jadwal operasi ini belum terhubung dengan kunjungan pasien.', 422);
        }

        $package = $schedule->surgeryPackage;
        $items   = $package?->items ?? collect();

        // --- BHP: langsung dari komposisi paket ---
        $bhpItems = [];
        foreach ($items->where('item_type', 'BHP') as $it) {
            $bhp = BhpItem::find($it->item_id);
            if (! $bhp) continue;
            $bhpItems[] = [
                'bhp_item_id' => $bhp->id,
                'name'        => $bhp->name,
                'code'        => $bhp->code,
                'unit'        => $bhp->unit,
                'quantity'    => (int) ($it->quantity ?? 1),
            ];
        }

        // --- IOL: pasangkan item paket dengan IolRecommendation visit ---
        $recs = IolRecommendation::where('visit_id', $visit->id)
            ->orderByDesc('is_approved')
            ->orderByDesc('created_at')
            ->get()
            ->values();

        $iolItems = [];
        $recIdx = 0;
        foreach ($items->where('item_type', 'IOL') as $it) {
            $rec  = $recs->get($recIdx);
            $recIdx++;
            $iol  = IolItem::find($it->item_id);
            $iolItems[] = [
                'iol_item_id'        => $iol?->id,
                'quantity'           => (int) ($it->quantity ?? 1),
                'eye_side'           => $rec?->eye_side ?? 'OD',
                'requested_power'    => $rec ? (float) $rec->recommended_power : null,
                'requested_iol_type' => $rec?->iol_type ?? $iol?->iol_type ?? null,
                'from_recommendation'=> (bool) $rec,
                'master_label'       => $iol ? trim(($iol->brand ?? '') . ' ' . ($iol->model ?? '')) : null,
            ];
        }

        return [
            'visit_id'    => $visit->id,
            'schedule_id' => $schedule->id,
            'patient'     => $visit->patient?->name,
            'package'     => $package ? ['id' => $package->id, 'code' => $package->code, 'name' => $package->name] : null,
            'bhp_items'   => $bhpItems,
            'iol_items'   => $iolItems,
        ];
    }

    /**
     * 1-klik: buat request dari paket (BHP + IOL yang sudah dilengkapi user) lalu
     * langsung kirim ke Bedah (REQUESTED → SENT) dalam satu transaksi.
     */
    public function sendRequestFromSchedule(string $scheduleId, array $data): SurgeryRequest
    {
        $schedule = SurgerySchedule::with(['surgeryPackage', 'visit.patient'])->findOrFail($scheduleId);
        $visit = $schedule->visit;
        if (! $visit) {
            throw new \Exception('Jadwal operasi ini belum terhubung dengan kunjungan pasien.', 422);
        }

        $bhpItems = $data['bhp_items'] ?? [];
        $iolItems = $data['iol_items'] ?? [];
        if (empty($bhpItems) && empty($iolItems)) {
            throw new \Exception('Paket tidak memiliki item BHP/IOL untuk dikirim.', 422);
        }

        $pkgCode = $schedule->surgeryPackage?->code ?? '-';
        $notes   = "Auto dari paket {$pkgCode} — {$visit->patient?->name}";

        return DB::transaction(function () use ($visit, $scheduleId, $bhpItems, $iolItems, $notes) {
            $request = $this->createSupplyRequest($visit->id, [
                'surgery_schedule_id' => $scheduleId,
                'notes'               => $notes,
                'bhp_items'           => $bhpItems,
                'iol_items'           => $iolItems,
            ]);

            return $this->kirimRequest($request->id);
        });
    }

    /**
     * Konfirmasi terima BHP+IOL dari Farmasi (SENT → RECEIVED).
     */
    public function terimaRequest(string $id): SurgeryRequest
    {
        $request = SurgeryRequest::findOrFail($id);

        if ($request->status !== 'SENT') {
            throw new \Exception('Hanya request dengan status SENT yang bisa dikonfirmasi.', 422);
        }

        return DB::transaction(function () use ($request, $id) {
            $request->update([
                'status'      => 'RECEIVED',
                'received_at' => now(),
            ]);

            // Seed used_qty = quantity supaya billing langsung bisa hitung;
            // bedah masih bisa adjust ± lewat adjustBhpUsage sebelum kasir consolidate.
            SurgeryRequestBhp::where('surgery_request_id', $id)
                ->whereNull('used_qty')
                ->update(['used_qty' => DB::raw('quantity')]);

            $this->log(
                auth('api')->id(),
                'TERIMA_REQUEST',
                SurgeryRequest::class,
                $id,
                'BHP+IOL diterima dari Farmasi — siap operasi'
            );

            return $request->fresh(['bhpItems.bhpItem', 'iolItems.iolItem']);
        });
    }

    /**
     * Bedah set/adjust qty BHP yang actual terpakai. Boleh > atau < quantity
     * yang diminta (used_qty bisa nol kalau ternyata tidak jadi dipakai).
     *
     * Payload: items = [{ bhp_item_id, used_qty }]. Item yg tidak disebut
     * dibiarkan apa adanya.
     */
    public function adjustBhpUsage(string $requestId, array $items): SurgeryRequest
    {
        $request = SurgeryRequest::findOrFail($requestId);

        if (! in_array($request->status, ['SENT', 'RECEIVED'], true)) {
            throw new \Exception('Adjust BHP hanya berlaku saat status SENT atau RECEIVED.', 422);
        }

        return DB::transaction(function () use ($request, $requestId, $items) {
            foreach ($items as $row) {
                if (empty($row['bhp_item_id'])) continue;
                SurgeryRequestBhp::where('surgery_request_id', $requestId)
                    ->where('bhp_item_id', $row['bhp_item_id'])
                    ->update(['used_qty' => max(0, (int) ($row['used_qty'] ?? 0))]);
            }

            $this->log(
                auth('api')->id(),
                'ADJUST_BHP_USAGE',
                SurgeryRequest::class,
                $requestId,
                'Adjust used_qty: ' . count($items) . ' baris'
            );

            return $request->fresh(['bhpItems.bhpItem']);
        });
    }

    // =========================================================================
    // IOL USAGE (saat operasi)
    // =========================================================================

    /**
     * Record IOL yang dipakai saat operasi → mark iol_item.is_used = TRUE.
     */
    public function recordIolUsage(string $surgeryRecordId, array $data): SurgeryIolUsage
    {
        $record = SurgeryRecord::findOrFail($surgeryRecordId);

        return DB::transaction(function () use ($record, $data, $surgeryRecordId) {
            $usage = SurgeryIolUsage::create([
                'surgery_record_id' => $surgeryRecordId,
                'iol_item_id'       => $data['iol_item_id'],
                'eye_side'          => $data['eye_side'],
                'brand'             => $data['brand'] ?? null,
                'model'             => $data['model'] ?? null,
                'power'             => $data['power'] ?? null,
                'lot_number'        => $data['lot_number'] ?? null,
                'serial_number'     => $data['serial_number'] ?? null,
            ]);

            // Mark IOL item as used
            if (! empty($data['iol_item_id'])) {
                IolItem::where('id', $data['iol_item_id'])->update(['is_used' => true]);
            }

            $this->log(
                auth('api')->id(),
                'RECORD_IOL_USAGE',
                SurgeryIolUsage::class,
                $usage->id,
                "IOL {$data['eye_side']} dipakai — surgery record {$surgeryRecordId}"
            );

            return $usage->load('iolItem');
        });
    }

    public function updateIolUsage(string $id, array $data): SurgeryIolUsage
    {
        $usage = SurgeryIolUsage::findOrFail($id);

        $usage->update(array_filter([
            'brand'         => $data['brand'] ?? null,
            'model'         => $data['model'] ?? null,
            'power'         => $data['power'] ?? null,
            'lot_number'    => $data['lot_number'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_IOL_USAGE', SurgeryIolUsage::class, $id);

        return $usage->fresh('iolItem');
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
