<?php

namespace App\Services;

use App\Models\Prescription;
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
        // Papan digerakkan SCHEDULE (bukan queue): SEMUA jadwal laser HARI INI tampil,
        // walau queue station BEDAH belum terbentuk (mis. dokter simpan planning tapi
        // belum "Kirim ke Kasir"). Queue station BEDAH hanya pelengkap (tombol Panggil
        // + nomor antrean). Ini mencegah pasien laser same-day "hilang" dari papan dan
        // hanya muncul di tab Terjadwal.
        $schedules = SurgerySchedule::with([
            'surgeryPackage',
            'leadSurgeon',
            'surgeryRecord',
            'visit.patient',
            'visit.insurer',
            'visit.doctorExamination.doctor',
            'visit.refractionRecord',
            'visit.queues' => fn ($q) => $q->where('station', 'BEDAH')
                ->orderByDesc('created_at'),
        ])
            ->where('location_type', self::LOCATION)
            ->whereDate('scheduled_date', today())
            ->orderBy('scheduled_time')
            ->orderBy('created_at')
            ->get();

        $activeStatuses = [Queue::STATUS_WAITING, Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS];

        return $schedules->map(function (SurgerySchedule $s) use ($activeStatuses) {
            $visit   = $s->visit
                ?? Visit::with(['patient', 'insurer', 'doctorExamination.doctor', 'refractionRecord', 'queues'])
                    ->whereHas('doctorExamination', fn ($q) => $q->where('surgery_schedule_id', $s->id))
                    ->first();
            $patient = $visit?->patient;
            $dob     = $patient?->date_of_birth;

            $package  = $s->surgeryPackage ?? $visit?->doctorExamination?->surgeryPackage ?? null;
            $exam     = $visit?->doctorExamination;
            $operator = $s->leadSurgeon?->name ?? $exam?->doctor?->name ?? null;
            $record   = $s->surgeryRecord;
            $refr     = $visit?->refractionRecord;

            // Queue station BEDAH terkait (opsional): pilih yang masih aktif, else terbaru.
            $queues = $visit?->queues?->where('station', 'BEDAH') ?? collect();
            $queue  = $queues->whereIn('status', $activeStatuses)->sortByDesc('created_at')->first()
                ?? $queues->sortByDesc('created_at')->first();

            return [
                // ID papan = schedule.id (stabil; mulai/selesai memakai ini). Queue terpisah.
                'id'             => $s->id,
                'queue_id'       => $queue?->id,                  // null bila queue belum ada (Panggil di-disable)
                'queue_number'   => $queue?->queue_number,
                'status'         => $queue?->status,              // status antrean (WAITING/CALLED/…) atau null
                'called_at'      => $queue?->called_at?->toIso8601String(),
                'started_at'     => $queue?->started_at?->toIso8601String(),
                'completed_at'   => $queue?->completed_at?->toIso8601String(),

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

                'schedule' => [
                    'id'             => $s->id,
                    'location_type'  => $s->location_type,
                    'scheduled_date' => $s->scheduled_date?->toDateString(),
                    'scheduled_time' => $s->scheduled_time,
                    'room'           => $s->operation_room,
                    'status'         => $s->status,
                    'package'        => $package ? ['id' => $package->id, 'name' => $package->name] : null,
                ],

                // Laporan laser tersimpan (hydrate form FE): operation_report + kolom record.
                'record' => $record ? [
                    'id'                  => $record->id,
                    'time_in'             => $record->time_in?->toIso8601String(),
                    'time_out'            => $record->time_out?->toIso8601String(),
                    'finalized'           => (bool) $record->finalized_at,
                    'laporan'             => $record->operation_report,
                    'complication'        => $record->complication_detail,
                    'followup_date'       => $record->followup_date?->toDateString(),
                    'post_op_disposition' => $record->post_op_disposition,
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
        $schedule = SurgerySchedule::with(['surgeryRecord', 'surgeryPackage.items'])->findOrFail($scheduleId);
        $this->assertTindakanSchedule($schedule);

        if ($schedule->status !== 'IN_PROGRESS') {
            throw new \Exception('Tindakan belum dimulai.', 422);
        }

        $record = $schedule->surgeryRecord;
        if (! $record) {
            throw new \Exception('Catatan tindakan tidak ditemukan. Mulai tindakan terlebih dahulu.', 422);
        }

        // Wajibkan paket: tanpa paket, tak ada tindakan yang tertagih ke Kasir
        // (recordPackageProcedures no-op) → kebocoran tagihan. Paksa dokter melampirkan
        // paket di planning sebelum tindakan bisa diselesaikan.
        $visitForPkg = $this->resolveVisitForSchedule($schedule);
        $package = $schedule->surgeryPackage ?? $visitForPkg?->doctorExamination?->surgeryPackage;
        if (! $package) {
            throw new \Exception('Tindakan tidak dapat diselesaikan: belum ada paket tindakan. Minta dokter melampirkan paket bedah/tindakan di planning terlebih dahulu.', 422);
        }

        $disposition = $data['post_op_disposition'] ?? 'PULANG';
        $hasComplication = ! empty($data['complication']);

        $result = DB::transaction(function () use ($schedule, $record, $data, $disposition, $hasComplication) {
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

            // Catat tindakan laser ke visit_services agar tertagih di Kasir
            // (KasirService::buildTindakanLines → procedure_tariffs per penjamin).
            // Sumber tindakan = PAKET yang dipilih dokter di planning (DokterView),
            // bukan dipilih ulang manual di stasiun ini.
            if ($visit) {
                $this->recordPackageProcedures($visit, $schedule);
            }

            // Routing pasca-tindakan (pola sama BedahService::finalizeRecord):
            //   - RAWAT_INAP utk pasien RAJAL/PREOP (belum RANAP) → papan "Menunggu Kamar".
            //   - PULANG (RAJAL→KASIR) / pasien SUDAH RANAP (LANJUT_RANAP/HCU→kembali kamar)
            //     ditangani advanceFromStation via resolveNextStation sesuai jenis_pelayanan.
            if ($visit) {
                $active = ['WAITING', 'CALLED', 'IN_PROGRESS'];
                $bedahQueue = Queue::where('visit_id', $visit->id)
                    ->where('station', 'BEDAH')
                    ->whereIn('status', $active)
                    ->latest('created_at')
                    ->first();

                $isRanapPatient = ($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP';
                $toMenungguRanap = $disposition === 'RAWAT_INAP' && ! $isRanapPatient;

                if ($toMenungguRanap) {
                    // Tutup SEMUA antrean aktif (BEDAH/DOKTER/dll) → papan Menunggu Kamar.
                    Queue::where('visit_id', $visit->id)->whereIn('status', $active)
                        ->update(['status' => Queue::STATUS_COMPLETED, 'completed_at' => now()]);
                    $visit->update(['current_station' => 'MENUNGGU_RANAP']);
                } elseif ($bedahQueue) {
                    // Alur normal: pasien sudah di antrean BEDAH → advance ke KASIR.
                    $this->queueService->advanceFromStation($bedahQueue->id, Queue::STATION_BEDAH);
                } else {
                    // Papan schedule-driven: tindakan bisa diselesaikan untuk pasien terjadwal
                    // yang BELUM pernah masuk antrean BEDAH (mis. dokter belum "Kirim ke Kasir").
                    // Pastikan tetap diteruskan ke KASIR agar tindakan tertagih: tutup antrean
                    // aktif non-kasir, lalu enqueue KASIR bila belum ada.
                    $hasActiveKasir = Queue::where('visit_id', $visit->id)
                        ->where('station', Queue::STATION_KASIR)
                        ->whereIn('status', $active)
                        ->exists();
                    Queue::where('visit_id', $visit->id)
                        ->whereIn('status', $active)
                        ->where('station', '!=', Queue::STATION_KASIR)
                        ->update(['status' => Queue::STATUS_COMPLETED, 'completed_at' => now()]);
                    if (! $hasActiveKasir) {
                        $this->queueService->enqueue($visit->id, Queue::STATION_KASIR);
                    }
                    $visit->update(['current_station' => Queue::STATION_KASIR]);
                }
            }

            $this->log('COMPLETE_TINDAKAN', SurgeryRecord::class, $record->id, "Selesai tindakan laser — disposisi {$disposition}");

            return $record->fresh(['surgerySchedule']);
        });

        // Manfaat "konsultasi kontrol gratis pasca-bedah" (Opsi B): bila paket tindakan
        // mengaktifkannya, terbitkan hak per operasi. Non-blok (gagal hanya di-log).
        app(\App\Services\PackageFollowupService::class)->issueForOperation($schedule->id);

        return $result;
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
     * Resep obat pulang pasca-laser → Prescription SUBMITTED (reuse BedahService).
     * Otomatis muncul di Farmasi SETELAH Kasir (QueueService::nextAfterKasir cek resep).
     */
    public function storeResep(string $scheduleId, array $items, array $opts = []): ?Prescription
    {
        $schedule = SurgerySchedule::findOrFail($scheduleId);
        $this->assertTindakanSchedule($schedule);

        $visit = $this->resolveVisitForSchedule($schedule);
        if (! $visit) {
            throw new \Exception('Kunjungan untuk jadwal ini tidak ditemukan.', 422);
        }

        return $this->bedahService->storePostOpPrescription($visit->id, $items, $opts);
    }

    /** Daftar obat untuk picker resep (passthrough BedahService → DokterService). */
    public function getDaftarObat(?string $search): array
    {
        return $this->bedahService->getDaftarObat($search);
    }

    /** Resolve visit dari jadwal: prefer visits.surgery_schedule_id, fallback examination. */
    private function resolveVisitForSchedule(SurgerySchedule $schedule): ?Visit
    {
        return Visit::where('surgery_schedule_id', $schedule->id)->first()
            ?? Visit::whereHas('doctorExamination',
                fn ($q) => $q->where('surgery_schedule_id', $schedule->id))->first();
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

    /**
     * Auto-rekam tindakan (PROCEDURE) dari paket yang dipilih dokter di planning ke
     * visit_services → tertagih di Kasir (buildTindakanLines) + baris diskon paket.
     * Laser tanpa paket → tak ada tindakan auto (operator atur via paket di planning).
     */
    private function recordPackageProcedures(Visit $visit, SurgerySchedule $schedule): void
    {
        $package = $schedule->surgeryPackage ?? $visit->doctorExamination?->surgeryPackage;
        if (! $package) {
            return;
        }

        $performedBy = auth('api')->user()?->employee_id;

        foreach ($package->items->where('item_type', 'PROCEDURE') as $item) {
            $procedureId = $item->item_id;
            if (! $procedureId) {
                continue;
            }

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
                'quantity'        => max(1, (int) $item->quantity),
                'price'           => $price,
                'notes'           => 'Ruang Tindakan (laser) — dari paket ' . $package->name,
            ]);
        }
    }

    // =========================================================================
    // JADWAL TINDAKAN TERJADWAL (per minggu) — hanya RUANG_TINDAKAN
    // =========================================================================

    /**
     * Daftar pasien terjadwal Ruang Tindakan. Tanpa rentang → mendatang (> hari ini;
     * hari ini ada di papan "Antrean Hari Ini"); dengan date_from/date_to (weekpicker)
     * → semua jadwal laser dalam minggu itu (boleh termasuk hari ini, krn dipilih eksplisit).
     */
    public function getScheduledTindakan(array $filters = []): array
    {
        $query = SurgerySchedule::with([
            'surgeryPackage',
            'leadSurgeon',
            'surgeryRecord',
            'visit.patient',
            'visit.insurer',
            'visit.doctorExamination.doctor',
        ])->where('location_type', self::LOCATION);

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            if (! empty($filters['date_from'])) {
                $query->whereDate('scheduled_date', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->whereDate('scheduled_date', '<=', $filters['date_to']);
            }
        } else {
            // Default "mendatang" = STRICTLY setelah hari ini. Jadwal HARI INI adalah
            // ranah papan "Antrean Hari Ini" (getPatientQueue) — jangan didobel di sini.
            $query->whereDate('scheduled_date', '>', today());
        }

        return $query->orderBy('scheduled_date')->orderBy('scheduled_time')->get()
            ->map(function (SurgerySchedule $s) {
                $visit   = $s->visit;
                $patient = $visit?->patient;
                $exam    = $visit?->doctorExamination;
                $dob     = $patient?->date_of_birth;

                return [
                    'schedule_id'    => $s->id,
                    'scheduled_date' => $s->scheduled_date?->toDateString(),
                    'scheduled_time' => $s->scheduled_time,
                    'room'           => $s->operation_room,
                    'status'         => $s->status,
                    'package'        => $s->surgeryPackage?->name,
                    'operator'       => $s->leadSurgeon?->name ?? $exam?->doctor?->name,
                    'diagnosa'       => $exam?->diagnosis_utama,
                    'patient'        => $patient ? [
                        'id'     => $patient->id,
                        'no_rm'  => $patient->no_rm,
                        'name'   => $patient->name,
                        'gender' => $patient->gender,
                        'age'    => $dob ? $dob->age : null,
                    ] : null,
                    'guarantor'      => $visit?->insurer?->name ?? $visit?->guarantor_type,
                ];
            })->all();
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
