<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\IolRecommendation;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
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
            'visit.surgerySchedule.leadSurgeon',
            'visit.doctorExamination.surgeryPackage',
            'visit.doctorExamination.surgerySchedule.leadSurgeon',
            'visit.doctorExamination.doctor',
            // B7: data pra-op (visus + IOP) utk modal konfirmasi "Mulai Operasi".
            // HasOne murah; sumber tunggal RefractionRecord (refraksionis).
            'visit.refractionRecord',
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

            // Konteks klinis utk prefill Laporan: diagnosis (kode ICD-10 dari dokter)
            // + DPJP (operator utama dari jadwal, fallback dokter pemeriksa).
            $exam = $visit?->doctorExamination;
            $dpjp = $schedule?->leadSurgeon?->name ?? $exam?->doctor?->name ?? null;

            // B7: data pra-op (visus + IOP per mata) utk modal konfirmasi Mulai
            // Operasi. Sumber: RefractionRecord; null bila pasien tak melalui
            // refraksi (mis. IGD) — FE menyembunyikan field yang kosong.
            $refr = $visit?->refractionRecord;

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
                    'diagnosa'       => $exam?->diagnosis_utama,
                    'dpjp'           => $dpjp,
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

                // B7: pra-op utk konfirmasi Mulai Operasi (visus akhir + IOP per mata).
                'preop' => $refr ? [
                    'visus_od'   => $refr->visus_akhir_od ?? $refr->visus_awal_od ?? null,
                    'visus_os'   => $refr->visus_akhir_os ?? $refr->visus_awal_os ?? null,
                    'pinhole_od' => $refr->pinhole_od ?? null,
                    'pinhole_os' => $refr->pinhole_os ?? null,
                    'iop_od'     => $refr->iop_od ?? null,
                    'iop_os'     => $refr->iop_os ?? null,
                    'iop_method' => $refr->iop_method ?? null,
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
        // Guard supply (BHP+IOL diterima) boleh dicek di luar transaksi — read-only,
        // tidak rentan race krk RECEIVED bersifat satu-arah dan tak diubah di sini.
        $pendingSupply = SurgeryRequest::where('surgery_schedule_id', $scheduleId)
            ->whereNotIn('status', ['RECEIVED', 'CANCELLED'])
            ->exists();

        if ($pendingSupply) {
            throw new \Exception('BHP dan IOL belum diterima dari Farmasi. Konfirmasi terlebih dahulu.', 422);
        }

        return DB::transaction(function () use ($scheduleId) {
            // Kunci baris jadwal SELAMA transaksi untuk cegah double-start serentak:
            // tanpa lock, dua request bisa lolos cek status SCHEDULED bersamaan lalu
            // sama-sama INSERT SurgeryRecord → unique violation surgery_schedule_id
            // (23505 → 500). Re-cek status DI DALAM lock = sumber kebenaran tunggal.
            $schedule = SurgerySchedule::lockForUpdate()->findOrFail($scheduleId);

            if ($schedule->status !== 'SCHEDULED') {
                throw new \Exception('Jadwal tidak dalam status SCHEDULED.', 422);
            }

            $schedule->update(['status' => 'IN_PROGRESS']);

            // Resolve visit_id: kunci utama visits.surgery_schedule_id; fallback ke
            // surgery_requests (operasi tanpa supply-request tetap dapat visit yg benar).
            $visitId = Visit::where('surgery_schedule_id', $schedule->id)->value('id')
                ?? $schedule->surgeryRequests()->value('visit_id');

            // Guard B2: visit_id WAJIB (kolom NOT NULL). Bila jadwal tak terhubung ke
            // kunjungan manapun, lempar 422 SEBELUM create — cegah INSERT null →
            // pelanggaran NOT NULL (500) + record orphan yg memacetkan antrean.
            if (! $visitId) {
                throw new \Exception('Jadwal operasi belum terhubung dengan kunjungan pasien — tidak dapat memulai operasi.', 422);
            }

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
     *
     * TIDAK meneruskan pasien ke Farmasi/Kasir. Advance antrean dipindah ke
     * finalizeRecord() supaya pasien baru jalan setelah laporan operasi dikunci.
     * Di sini schedule cukup ditandai DONE (gerbang agar finalize boleh jalan).
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
            // Disposisi pasca-op: PULANG (default → KASIR) atau RAWAT_INAP
            // (→ papan Menunggu Kamar). Validasi nilai yang sah.
            $disposition = $data['post_op_disposition'] ?? 'PULANG';
            if (! in_array($disposition, ['PULANG', 'RAWAT_INAP'], true)) {
                throw new \Exception('post_op_disposition harus PULANG atau RAWAT_INAP.', 422);
            }

            $record->update([
                'time_out'             => now(),
                'operation_notes'      => $data['operation_notes'] ?? null,
                'has_complication'     => $data['has_complication'] ?? false,
                'complication_detail'  => ($data['has_complication'] ?? false) ? ($data['complication_detail'] ?? null) : null,
                'post_op_instructions' => $data['post_op_instructions'] ?? null,
                'followup_date'        => $data['followup_date'] ?? null,
                'post_op_disposition'  => $disposition,
            ]);

            $schedule->update(['status' => 'DONE']);

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

    /**
     * Kunci laporan operasi + teruskan pasien ke Farmasi/Kasir.
     *
     * Advance antrean SENGAJA di sini (bukan di completeOperation/Time Out): pasien
     * baru diteruskan setelah laporan dikunci. Delegasi ke QueueService sebagai
     * sumber tunggal routing + broadcast TV (FARMASI bila ada resep aktif, else KASIR).
     */
    public function finalizeRecord(string $id): SurgeryRecord
    {
        $record   = SurgeryRecord::with('surgerySchedule')->findOrFail($id);
        $schedule = $record->surgerySchedule;

        if ($record->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci.', 422);
        }

        if ($schedule->status !== 'DONE') {
            throw new \Exception('Operasi belum selesai — tidak bisa finalize laporan.', 422);
        }

        if (! $record->time_in || ! $record->time_out) {
            throw new \Exception('Time In dan Time Out wajib diisi sebelum finalize.', 422);
        }

        return DB::transaction(function () use ($record) {
            $record->update(['finalized_at' => now()]);

            $visitId = $record->visit_id;

            // B6: visit_id null seharusnya tak terjadi (dicegah di hulu oleh guard
            // startOperation/B2). Bila tetap null karena data lama: JANGAN diam dan
            // JANGAN gagal — finalized_at sudah terkunci di atas; cukup catat warning.
            if (! $visitId) {
                \Illuminate\Support\Facades\Log::warning('Finalize bedah: record tanpa visit_id — finalize tetap dikunci, routing antrean dilewati.', [
                    'surgery_record_id' => $record->id,
                ]);
            } else {
                $visit = Visit::find($visitId);

                $bedahQueue = Queue::where('visit_id', $visitId)
                    ->where('station', Queue::STATION_BEDAH)
                    ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                    ->latest('created_at')
                    ->first();

                // Disposisi RAWAT_INAP untuk pasien rawat JALAN/PREOP (belum RANAP):
                // arahkan ke papan "Menunggu Kamar". Petugas ranap admit bed via
                // RanapService::admit (yang men-set jenis_pelayanan=RANAP + enqueue
                // baris RANAP). Pasien yang SUDAH RANAP (bedah = sub-aktivitas)
                // ditangani normal oleh resolveNextRanap → returnToLiveRanap.
                $toRanap = $record->post_op_disposition === 'RAWAT_INAP'
                    && $visit
                    && ($visit->jenis_pelayanan ?? 'RAJAL') !== 'RANAP';

                if ($toRanap) {
                    // Tutup baris bedah bila masih ada (TANPA enqueue otomatis), lalu
                    // SELALU set current_station ke MENUNGGU_RANAP — tidak bergantung
                    // pada keberadaan bedahQueue (cegah pasien hilang dari papan ranap).
                    if ($bedahQueue) {
                        $bedahQueue->update([
                            'status'       => Queue::STATUS_COMPLETED,
                            'completed_at' => now(),
                        ]);
                    }
                    if ($visit) {
                        $visit->update(['current_station' => 'MENUNGGU_RANAP']);
                    }
                } elseif ($bedahQueue) {
                    // PULANG atau RANAP-existing: teruskan normal (→ FARMASI/KASIR).
                    // Jika bedahQueue null, pasien sudah ter-advance proses lain — biarkan.
                    $this->queueService->advanceFromStation($bedahQueue->id, Queue::STATION_BEDAH);
                }
            }

            $this->log(auth('api')->id(), 'FINALIZE_RECORD', SurgeryRecord::class, $record->id, 'Laporan operasi dikunci + pasien diteruskan');

            return $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);
        });
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
                'category'    => $bhp->category,
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
     * KONTRAK (diubah): payload `items` kini DAFTAR LENGKAP item yang terpakai —
     * bersifat OTORITATIF. items = [{ bhp_item_id, used_qty }]. Setiap baris BHP
     * pada request ini yang bhp_item_id-nya TIDAK ada di payload akan di-set
     * used_qty = 0 (dianggap tidak jadi dipakai), supaya item RECEIVED yang
     * seeded used_qty=quantity (terimaRequest) tidak diam-diam over-billing saat
     * FE mengirim daftar parsial.
     */
    public function adjustBhpUsage(string $requestId, array $items): SurgeryRequest
    {
        $request = SurgeryRequest::findOrFail($requestId);

        if (! in_array($request->status, ['SENT', 'RECEIVED'], true)) {
            throw new \Exception('Adjust BHP hanya berlaku saat status SENT atau RECEIVED.', 422);
        }

        return DB::transaction(function () use ($request, $requestId, $items) {
            // Kumpulkan bhp_item_id yang disebut + set used_qty masing-masing.
            $mentionedIds = [];
            foreach ($items as $row) {
                if (empty($row['bhp_item_id'])) continue;
                $mentionedIds[] = $row['bhp_item_id'];
                SurgeryRequestBhp::where('surgery_request_id', $requestId)
                    ->where('bhp_item_id', $row['bhp_item_id'])
                    ->update(['used_qty' => max(0, (int) ($row['used_qty'] ?? 0))]);
            }

            // Otoritatif: item yang TIDAK disebut payload → tidak jadi dipakai → 0.
            SurgeryRequestBhp::where('surgery_request_id', $requestId)
                ->whereNotIn('bhp_item_id', $mentionedIds)
                ->update(['used_qty' => 0]);

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
     * Record IOL yang ditanam saat operasi (1 lensa per mata) → simpan ke
     * surgery_iol_usage + DECREMENT stok `inventory_stocks` (LOC_BEDAH).
     *
     * Kebijakan validasi (keputusan user): expired / non-master / stok kurang →
     * TIDAK menolak (bukan 422), tetap disimpan, tapi dikembalikan `warnings[]`
     * agar FE menampilkan peringatan. Operasi tak boleh terhalang data master.
     *
     * Idempoten per (record, eye_side): bila mata itu sudah punya usage, stok IOL
     * lama dikembalikan (consume di-revert) sebelum stok IOL baru dipotong.
     *
     * @return array{usage: SurgeryIolUsage, warnings: array<string>}
     */
    public function recordIolUsage(string $surgeryRecordId, array $data): array
    {
        $record = SurgeryRecord::findOrFail($surgeryRecordId);
        if ($record->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci — tidak bisa mengubah IOL.', 422);
        }

        return DB::transaction(function () use ($data, $surgeryRecordId) {
            $warnings = [];

            $iol = ! empty($data['iol_item_id']) ? IolItem::find($data['iol_item_id']) : null;

            // Peringatan (tidak memblokir).
            if (! $iol) {
                $warnings[] = 'IOL tidak terdaftar di master — dicatat sebagai data manual.';
            }
            $expiry = $data['expiry_date'] ?? ($iol?->expiry_date?->toDateString());
            if ($expiry && $expiry < now()->toDateString()) {
                $warnings[] = "Lensa kedaluwarsa ({$expiry}) — pastikan benar sebelum lanjut.";
            }

            // Usage lama utk mata ini (untuk revert stok bila IOL diganti).
            $prev = SurgeryIolUsage::where('surgery_record_id', $surgeryRecordId)
                ->where('eye_side', $data['eye_side'])
                ->first();

            $iolChanged = ! $prev || $prev->iol_item_id !== ($iol?->id);

            // ── STOK (ATOMIK & SIMETRIS) ──────────────────────────────────────
            // Urutan: consume IOL BARU dulu. Kalau berhasil → baru revert IOL LAMA.
            // Bila consume gagal (stok kurang): JANGAN revert lama (cegah stok-bocor),
            // operasi tetap lanjut (warning). stock_consumed melacak apakah benar
            // dipotong → dipakai deleteIolUsage agar pengembalian stok simetris.
            $stockConsumed = $prev && ! $iolChanged ? (bool) $prev->stock_consumed : false;

            if ($iol && $iolChanged) {
                $consumed = $this->consumeIolStock($iol->id, $expiry);
                if ($consumed) {
                    $stockConsumed = true;
                    // Kembalikan stok IOL LAMA hanya setelah consume baru sukses.
                    if ($prev && $prev->iol_item_id && $prev->stock_consumed) {
                        $this->restoreIolStock($prev->iol_item_id, $prev->expiry_date);
                    }
                } else {
                    $stockConsumed = false;
                    $warnings[] = 'Stok IOL tidak mencukupi — usage tetap dicatat, stok TIDAK terpotong otomatis. Lakukan penyesuaian stok manual bila perlu.';
                }
            }

            $usage = SurgeryIolUsage::updateOrCreate(
                [
                    'surgery_record_id' => $surgeryRecordId,
                    'eye_side'          => $data['eye_side'],
                ],
                [
                    'iol_item_id'    => $iol?->id,
                    'brand'          => $data['brand'] ?? $iol?->brand,
                    'model'          => $data['model'] ?? $iol?->model,
                    'power'          => $data['power'] ?? $iol?->power,
                    'lot_number'     => $data['lot_number'] ?? null,
                    'serial_number'  => $data['serial_number'] ?? null,
                    'gtin'           => $data['gtin'] ?? $iol?->gtin,
                    'gs1_barcode'    => $data['gs1_barcode'] ?? null,
                    'expiry_date'    => $expiry,
                    'stock_consumed' => $stockConsumed,
                ]
            );

            $this->log(
                auth('api')->id(),
                'RECORD_IOL_USAGE',
                SurgeryIolUsage::class,
                $usage->id,
                "IOL {$data['eye_side']} dipakai — record {$surgeryRecordId}" . ($warnings ? ' [warn]' : '')
            );

            return ['usage' => $usage->load('iolItem'), 'warnings' => $warnings];
        });
    }

    /**
     * Potong 1 unit stok IOL dari `inventory_stocks`. IOL per-tipe (bukan depo
     * serialized) → coba LOC_BEDAH dulu (bila ada distribusi unit), fallback ke
     * LOC_INVENTORI (gudang). Return true bila berhasil dipotong, false bila stok
     * tak cukup di kedua lokasi (operasi tetap lanjut, lihat recordIolUsage).
     */
    private function consumeIolStock(string $iolItemId, $expiry = null): bool
    {
        $svc = app(\App\Services\InventoryStockService::class);
        foreach ([\App\Models\InventoryStock::LOC_BEDAH, \App\Models\InventoryStock::LOC_INVENTORI] as $loc) {
            try {
                $svc->consume(\App\Models\InventoryStock::TYPE_IOL, $iolItemId, 1, $loc);

                return true;
            } catch (\Throwable $e) {
                // lokasi ini kurang → coba lokasi berikut.
            }
        }

        return false;
    }

    /** Kembalikan 1 unit stok IOL ke gudang INVENTORI (lokasi netral). */
    private function restoreIolStock(string $iolItemId, $expiry = null): void
    {
        app(\App\Services\InventoryStockService::class)->upsertStock(
            \App\Models\InventoryStock::TYPE_IOL,
            $iolItemId,
            \App\Models\InventoryStock::LOC_INVENTORI,
            null,
            1,
            $expiry
        );
    }

    public function updateIolUsage(string $id, array $data): SurgeryIolUsage
    {
        $usage = SurgeryIolUsage::with('surgeryRecord')->findOrFail($id);
        if ($usage->surgeryRecord?->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci.', 422);
        }

        $usage->update(array_filter([
            'eye_side'      => $data['eye_side'] ?? null,
            'brand'         => $data['brand'] ?? null,
            'model'         => $data['model'] ?? null,
            'power'         => $data['power'] ?? null,
            'lot_number'    => $data['lot_number'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_IOL_USAGE', SurgeryIolUsage::class, $id);

        return $usage->fresh('iolItem');
    }

    /** Daftar IOL terpasang untuk satu surgery record. */
    public function listIolUsage(string $surgeryRecordId): \Illuminate\Support\Collection
    {
        return SurgeryIolUsage::with('iolItem')
            ->where('surgery_record_id', $surgeryRecordId)
            ->orderBy('eye_side')
            ->get();
    }

    /**
     * Hapus catatan IOL terpasang → kembalikan stok HANYA bila saat record stok
     * benar-benar dipotong (stock_consumed). Mencegah stok-bocor (kembalikan stok
     * yang tak pernah terpotong karena dulu stok kurang).
     */
    public function deleteIolUsage(string $id): void
    {
        $usage = SurgeryIolUsage::with('surgeryRecord')->findOrFail($id);
        if ($usage->surgeryRecord?->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci.', 422);
        }

        DB::transaction(function () use ($usage) {
            if ($usage->iol_item_id && $usage->stock_consumed) {
                $this->restoreIolStock($usage->iol_item_id, $usage->expiry_date);
            }
            $usage->delete();
            $this->log(auth('api')->id(), 'DELETE_IOL_USAGE', SurgeryIolUsage::class, $usage->id);
        });
    }

    // =========================================================================
    // RESEP PASCA-BEDAH (obat pulang dari Bedah → Farmasi)
    // =========================================================================

    /**
     * Buat resep obat pasca-bedah → Farmasi. Pola sama dengan obat pulang RANAP
     * (RanapService::createObatPulang): Prescription status SUBMITTED akan otomatis
     * muncul di antrean Farmasi via QueueService::nextAfterKasir saat pasien selesai
     * KASIR — TIDAK perlu enqueue manual.
     *
     * CATATAN is_bedah: SENGAJA dibiarkan default FALSE (tidak di-set true). Obat
     * pulang/pasca-bedah adalah obat BAWA PULANG yang TIDAK tercakup harga paket
     * bedah, jadi WAJIB ditagih. KasirService::buildObatLines (KasirService.php:293)
     * SKIP item is_bedah=true (dianggap sudah masuk paket) — bila di-set true, obat
     * pulang tidak akan pernah masuk invoice RAJAL/Bedah (pasien pulang tanpa bayar).
     * Penanda is_bedah hanya untuk obat yang DIKONSUMSI saat operasi (bundled paket).
     *
     * @param array<int,array{medication_id?:string,quantity?:int,dose?:string,
     *              frequency?:string,route?:string,duration_days?:int,notes?:string}> $items
     * @param array{notes?:string,pharmacy_note?:string} $opts
     */
    public function storePostOpPrescription(string $visitId, array $items, array $opts = []): ?Prescription
    {
        $user = auth('api')->user();

        // Resep WAJIB punya peresep (prescriptions.prescribed_by_id NOT NULL).
        if (! $user->employee_id) {
            throw new \Exception('Akun tidak punya data pegawai — tidak bisa membuat resep.', 422);
        }

        if (empty($items)) {
            return null;
        }

        return DB::transaction(function () use ($visitId, $items, $opts, $user) {
            $prescription = Prescription::create([
                'visit_id'         => $visitId,
                'prescribed_by_id' => $user->employee_id,
                'status'           => 'SUBMITTED',
                'notes'            => $opts['notes'] ?? 'Obat pasca-bedah',
                'pharmacy_note'    => $opts['pharmacy_note'] ?? null,
            ]);

            foreach ($items as $it) {
                if (empty($it['medication_id'])) {
                    continue;
                }
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'medication_id'   => $it['medication_id'],
                    'quantity'        => $it['quantity'] ?? 1,
                    'dose'            => $it['dose'] ?? null,
                    'frequency'       => $it['frequency'] ?? null,
                    'route'           => $it['route'] ?? null,
                    'duration_days'   => $it['duration_days'] ?? null,
                    'notes'           => $it['notes'] ?? null,
                    // is_bedah TIDAK di-set (default FALSE): obat bawa pulang WAJIB
                    // ditagih (buildObatLines skip is_bedah=true). Lihat docblock.
                ]);
            }

            $this->log(
                $user->id,
                'STORE_POSTOP_RESEP',
                Prescription::class,
                $prescription->id,
                "Resep pasca-bedah untuk kunjungan {$visitId}"
            );

            return $prescription->load('items.medication');
        });
    }

    /**
     * Passthrough daftar obat (untuk picker resep pasca-bedah). Sumber tunggal
     * DokterService::getDaftarObat — return [{id, code, name, form_sediaan,
     * golongan, unit, hja, farmasi_qty}].
     */
    public function getDaftarObat(?string $search): array
    {
        return app(\App\Services\DokterService::class)->getDaftarObat($search);
    }

    /**
     * Passthrough daftar IOL (untuk picker IOL terpakai). Sumber tunggal
     * MasterDataService::indexIol — filters: search, iol_type, material, active,
     * is_used, available_only, per_page.
     */
    public function getIolItems(array $filters): mixed
    {
        return app(\App\Services\MasterDataService::class)->indexIol($filters);
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
