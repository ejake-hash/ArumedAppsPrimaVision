<?php

namespace App\Services;

use App\Models\Procedure;
use App\Models\Queue;
use App\Models\SurgeryRecord;
use App\Models\SurgerySchedule;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Models\VisitService;
use Illuminate\Support\Facades\DB;

/**
 * Stasiun "Ruang Tindakan" (Laser YAG / Laser Retina-PRP).
 *
 * REUSE infrastruktur Bedah (surgery_schedules + surgery_records + queue station BEDAH)
 * dengan filter surgery_schedules.location_type = RUANG_TINDAKAN. Yang berbeda dari Bedah:
 *   - papan hanya pasien laser (location_type),
 *   - "Selesai Tindakan" TANPA gating WHO checklist (laser bukan operasi besar),
 *   - laporan tindakan disimpan di surgery_records.operation_report (JSONB) skema laser,
 *   - tindakan laser dicatat ke visit_services agar tertagih via KasirService::buildTindakanLines.
 *
 * Lifecycle "mulai" & "panggil" mendelegasi ke BedahService (logika identik, menulis ke
 * tabel yang sama) → tidak menduplikasi guard double-start / lock.
 */
class RuangTindakanService
{
    public function __construct(
        private readonly BedahService $bedahService,
        private readonly QueueService $queueService,
        private readonly KasirService $kasirService,
    ) {}

    private const LOCATION = SurgerySchedule::LOCATION_RUANG_TINDAKAN;

    // =========================================================================
    // PAPAN ANTRIAN (hari ini, hanya pasien Ruang Tindakan)
    // =========================================================================

    public function getPatientQueue(): array
    {
        $queues = Queue::with([
            'visit.patient',
            'visit.insurer',
            'visit.surgerySchedule.surgeryPackage',
            'visit.surgerySchedule.leadSurgeon',
            'visit.surgerySchedule.surgeryRecord',
            'visit.doctorExamination.surgerySchedule.surgeryPackage',
            'visit.doctorExamination.surgerySchedule.leadSurgeon',
            'visit.doctorExamination.surgerySchedule.surgeryRecord',
            'visit.doctorExamination.doctor',
            'visit.refractionRecord',
        ])
            ->where('station', 'BEDAH')
            ->whereDate('created_at', today())
            ->whereHas('visit')
            // Hanya pasien dengan jadwal RUANG_TINDAKAN (via visit.surgery_schedule_id
            // ATAU via doctor_examination.surgery_schedule — sama spt BedahService).
            ->where(function ($q) {
                $q->whereHas('visit.surgerySchedule', fn ($s) => $s->where('location_type', self::LOCATION))
                  ->orWhereHas('visit.doctorExamination.surgerySchedule', fn ($s) => $s->where('location_type', self::LOCATION));
            })
            ->orderBy('queue_sequence')
            ->get();

        return $queues->map(function (Queue $q) {
            $visit   = $q->visit;
            $patient = $visit?->patient;
            $dob     = $patient?->date_of_birth;

            $schedule = $this->resolveSchedule($visit);
            $package  = $schedule?->surgeryPackage ?? $visit?->doctorExamination?->surgeryPackage ?? null;
            $exam     = $visit?->doctorExamination;
            $operator = $schedule?->leadSurgeon?->name ?? $exam?->doctor?->name ?? null;
            $record   = $schedule?->surgeryRecord;
            $refr     = $visit?->refractionRecord;

            return [
                'id'             => $q->id,
                'queue_number'   => $q->queue_number,
                'queue_sequence' => $q->queue_sequence,
                'status'         => $q->status,
                'called_at'      => $q->called_at?->toIso8601String(),
                'started_at'     => $q->started_at?->toIso8601String(),
                'completed_at'   => $q->completed_at?->toIso8601String(),

                'visit' => $visit ? [
                    'id'              => $visit->id,
                    'classification'  => $visit->classification,
                    'guarantor_type'  => $visit->guarantor_type,
                    'insurer_name'    => $visit->insurer?->name,
                    'jenis_pelayanan' => $visit->jenis_pelayanan ?? 'RAJAL',
                    'diagnosa'        => $exam?->diagnosis_utama,
                    'operator'        => $operator,
                ] : null,

                'patient' => $patient ? [
                    'id'     => $patient->id,
                    'no_rm'  => $patient->no_rm,
                    'name'   => $patient->name,
                    'gender' => $patient->gender,
                    'age'    => $dob ? $dob->age : null,
                ] : null,

                'schedule' => $schedule ? [
                    'id'             => $schedule->id,
                    'location_type'  => $schedule->location_type,
                    'scheduled_date' => $schedule->scheduled_date?->toDateString(),
                    'scheduled_time' => $schedule->scheduled_time,
                    'room'           => $schedule->operation_room,
                    'status'         => $schedule->status,
                    'package'        => $package ? ['id' => $package->id, 'name' => $package->name] : null,
                ] : null,

                // Laporan laser yang sudah tersimpan (untuk hydrate form di FE).
                'record' => $record ? [
                    'id'         => $record->id,
                    'time_in'    => $record->time_in?->toIso8601String(),
                    'time_out'   => $record->time_out?->toIso8601String(),
                    'finalized'  => (bool) $record->finalized_at,
                    'laporan'    => $record->operation_report,
                ] : null,

                // Pra-tindakan (visus/IOP) bila pasien lewat refraksi.
                'preop' => $refr ? [
                    'visus_od' => $refr->visus_akhir_od ?? $refr->visus_awal_od ?? null,
                    'visus_os' => $refr->visus_akhir_os ?? $refr->visus_awal_os ?? null,
                    'iop_od'   => $refr->iop_od ?? null,
                    'iop_os'   => $refr->iop_os ?? null,
                ] : null,
            ];
        })->all();
    }

    // =========================================================================
    // LIFECYCLE
    // =========================================================================

    /** Panggil pasien (delegasi ke logika antrean Bedah — station sama). */
    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_BEDAH)->findOrFail($queueId);
        $this->assertTindakanQueue($queue);

        return $this->queueService->panggil($queue->id);
    }

    /** Mulai tindakan (Time In) — reuse BedahService::startOperation (membuat SurgeryRecord). */
    public function mulaiTindakan(string $scheduleId): SurgeryRecord
    {
        $this->assertTindakanSchedule($scheduleId);

        return $this->bedahService->startOperation($scheduleId);
    }

    /**
     * Selesai tindakan (Time Out) + simpan laporan laser, tanpa gating WHO checklist.
     * Catat procedure laser ke visit_services (billing) lalu advance antrean → KASIR.
     *
     * @param array{
     *   laporan?:array, procedure_ids?:array<int,string>, post_op_disposition?:string,
     *   followup_date?:?string, complication?:?string, notes?:?string
     * } $data
     */
    public function selesaiTindakan(string $scheduleId, array $data): SurgeryRecord
    {
        $schedule = SurgerySchedule::with('surgeryRecord')->findOrFail($scheduleId);
        $this->assertTindakanSchedule($schedule);

        if ($schedule->status !== 'IN_PROGRESS') {
            throw new \Exception('Tindakan belum dimulai.', 422);
        }

        $record = $schedule->surgeryRecord;
        if (! $record) {
            throw new \Exception('Catatan tindakan tidak ditemukan. Mulai tindakan terlebih dahulu.', 422);
        }

        $disposition = $data['post_op_disposition'] ?? 'PULANG';
        $hasComplication = ! empty($data['complication']);

        return DB::transaction(function () use ($schedule, $record, $data, $disposition, $hasComplication) {
            $record->update([
                'time_out'             => now(),
                'operation_notes'      => $data['notes'] ?? null,
                'operation_report'     => $data['laporan'] ?? $record->operation_report,
                'has_complication'     => $hasComplication,
                'complication_detail'  => $hasComplication ? $data['complication'] : null,
                'followup_date'        => $data['followup_date'] ?? null,
                'post_op_disposition'  => $disposition,
                // Tindakan laser ringkas → laporan langsung dikunci saat selesai.
                'finalized_at'         => now(),
            ]);

            $schedule->update(['status' => 'DONE']);

            // Resolve visit: prefer visits.surgery_schedule_id (alur planning dokter),
            // fallback ke doctor_examination.surgery_schedule (konsisten dgn getPatientQueue
            // yang memfilter via kedua jalur) — cegah tindakan tak tertagih.
            $visit = Visit::where('surgery_schedule_id', $schedule->id)->first()
                ?? Visit::whereHas('doctorExamination',
                    fn ($q) => $q->where('surgery_schedule_id', $schedule->id))->first();

            // Catat tindakan laser sebagai visit_services agar tertagih di Kasir
            // (KasirService::buildTindakanLines → procedure_tariffs per penjamin).
            if ($visit && ! empty($data['procedure_ids'])) {
                $this->recordVisitServices($visit, $data['procedure_ids']);
            }

            // Routing pasca-tindakan (pola sama BedahService::finalizeRecord):
            //   - RAWAT_INAP utk pasien RAJAL/PREOP (belum RANAP) → papan "Menunggu Kamar".
            //   - PULANG (RAJAL→KASIR) / pasien SUDAH RANAP (LANJUT_RANAP/HCU→kembali kamar)
            //     ditangani advanceFromStation via resolveNextStation sesuai jenis_pelayanan.
            if ($visit) {
                $queue = Queue::where('visit_id', $visit->id)
                    ->where('station', 'BEDAH')
                    ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                    ->latest('created_at')
                    ->first();

                $isRanapPatient = ($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP';
                $toMenungguRanap = $disposition === 'RAWAT_INAP' && ! $isRanapPatient;

                if ($toMenungguRanap) {
                    if ($queue) {
                        $queue->update(['status' => Queue::STATUS_COMPLETED, 'completed_at' => now()]);
                    }
                    $visit->update(['current_station' => 'MENUNGGU_RANAP']);
                } elseif ($queue) {
                    $this->queueService->advanceFromStation($queue->id, Queue::STATION_BEDAH);
                }
            }

            $this->log('COMPLETE_TINDAKAN', SurgeryRecord::class, $record->id, "Selesai tindakan laser — disposisi {$disposition}");

            return $record->fresh(['surgerySchedule']);
        });
    }

    /** Simpan/perbarui laporan laser tanpa menyelesaikan (autosave saat tindakan berjalan). */
    public function saveLaporan(string $recordId, array $laporan): SurgeryRecord
    {
        $record = SurgeryRecord::with('surgerySchedule')->findOrFail($recordId);
        $this->assertTindakanSchedule($record->surgerySchedule);

        if ($record->finalized_at) {
            throw new \Exception('Laporan sudah dikunci.', 422);
        }

        $record->update(['operation_report' => $laporan]);
        $this->log('SAVE_LAPORAN_TINDAKAN', SurgeryRecord::class, $record->id, 'Simpan laporan laser');

        return $record->fresh();
    }

    public function getRecord(string $scheduleId): ?SurgeryRecord
    {
        return SurgeryRecord::where('surgery_schedule_id', $scheduleId)->first();
    }

    /**
     * Master procedure (tindakan berbayar) untuk dropdown billing Ruang Tindakan.
     * Default mengutamakan kategori laser/tindakan di urutan atas, TAPI tetap
     * menampilkan procedure lain agar operator bisa memilih tindakan apa pun
     * (membatasi ketat berisiko menyembunyikan procedure yang kategorinya berbeda).
     * Saat $search diisi, cari di semua procedure aktif by nama/kode.
     */
    public function getProcedureOptions(?string $search): array
    {
        return Procedure::query()
            ->where('is_active', true)
            ->when($search, fn ($q) => $q->where(fn ($w) =>
                $w->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%")))
            // Tanpa kata kunci: dahulukan kategori laser/tindakan (relevan utk stasiun ini).
            ->orderByRaw("CASE WHEN category ILIKE '%laser%' OR category ILIKE '%tindakan%' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'code', 'name', 'category'])
            ->map(fn ($p) => [
                'id'       => $p->id,
                'code'     => $p->code,
                'name'     => $p->name,
                'category' => $p->category,
            ])
            ->all();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Jadwal RUANG_TINDAKAN: prefer visit.surgerySchedule, fallback ke examination. */
    private function resolveSchedule(?Visit $visit): ?SurgerySchedule
    {
        return $visit?->surgerySchedule ?? $visit?->doctorExamination?->surgerySchedule ?? null;
    }

    private function recordVisitServices(Visit $visit, array $procedureIds): void
    {
        $performedBy = auth('api')->user()?->employee_id;

        foreach (array_unique($procedureIds) as $procedureId) {
            // Anti-dobel: jangan catat procedure sama dua kali untuk visit ini.
            $exists = VisitService::where('visit_id', $visit->id)
                ->where('procedure_id', $procedureId)
                ->exists();
            if ($exists) {
                continue;
            }

            // Resolve harga per penjamin saat catat (kolom price NOT NULL). KasirService
            // tetap me-resolve ulang saat invoice, jadi nilai ini = snapshot konsisten.
            $price = $this->kasirService->getPrice(
                'procedure', $procedureId, $visit->guarantor_type, $visit->insurer_id
            );

            VisitService::create([
                'visit_id'        => $visit->id,
                'procedure_id'    => $procedureId,
                'performed_by_id' => $performedBy,
                'quantity'        => 1,
                'price'           => $price,
                'notes'           => 'Ruang Tindakan (laser)',
            ]);
        }
    }

    /** Pastikan queue ini benar-benar milik pasien RUANG_TINDAKAN. */
    private function assertTindakanQueue(Queue $queue): void
    {
        $visit = $queue->visit()->with(['surgerySchedule', 'doctorExamination.surgerySchedule'])->first();
        $this->assertTindakanSchedule($this->resolveSchedule($visit));
    }

    /** Validasi jadwal bertipe RUANG_TINDAKAN (tolak jadwal operasi Bedah). */
    private function assertTindakanSchedule(SurgerySchedule|string|null $schedule): void
    {
        if (is_string($schedule)) {
            $schedule = SurgerySchedule::find($schedule);
        }

        if (! $schedule || $schedule->location_type !== self::LOCATION) {
            throw new \Exception('Jadwal ini bukan tindakan Ruang Tindakan.', 422);
        }
    }

    private function log(string $action, string $model, string $modelId, string $desc): void
    {
        SystemLog::create([
            'user_id'     => auth('api')->id(),
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $desc,
        ]);
    }
}
