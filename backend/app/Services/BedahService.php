<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\DoctorExamination;
use App\Models\DocumentTemplate;
use App\Models\Icd10Code;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\BillingInvoice;
use App\Models\IolItem;
use App\Models\PatientDocument;
use App\Models\IolRecommendation;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionTemplate;
use App\Models\PrescriptionTemplateItem;
use App\Models\Queue;
use App\Models\SurgeryAnesthesiaReport;
use App\Models\SurgeryAnesthesiaVital;
use App\Models\SurgeryIolUsage;
use App\Models\SurgeryRecord;
use App\Models\SurgeryRequest;
use App\Models\SurgeryRequestBhp;
use App\Models\SurgeryRequestIol;
use App\Models\SurgerySchedule;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Models\VisitSurgeryPackage;
use App\Models\VisitSurgeryPackageItem;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BedahService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
    ) {}

    /** Cache resolusi kode ICD-10 → nama (per request) agar antrean tak N+1. */
    private array $icd10NameCache = [];

    /** Resolusi kode ICD-10 → nama (Indonesia, fallback Inggris). Null bila tak ada. */
    private function icd10Name(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        if (! array_key_exists($code, $this->icd10NameCache)) {
            $row = Icd10Code::where('code', $code)->first();
            $this->icd10NameCache[$code] = $row?->indonesian_description ?: $row?->description;
        }
        return $this->icd10NameCache[$code];
    }

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
            // Status tutup-kasir utk pemisahan tab "Selesai" (hari ini) vs "Masih Aktif"
            // (kunjungan belum tutup kasir) di FE — selaras klausa filter di bawah.
            'visit.billingInvoice',
        ])
            ->where('station', 'BEDAH')
            // Antrean HARI INI, ATAU operasi yang BELUM selesai dari hari sebelumnya.
            // Operasi yang menembus tengah malam (status IN_PROGRESS = sedang berjalan /
            // sudah Time Out tapi laporan belum dikunci, CALLED = pasien sudah dipanggil
            // masuk OK) TIDAK boleh hilang dari papan saat tanggal berganti — kunjungan
            // masih aktif & laporan belum dikunci. WAITING sengaja TIDAK dibawa-serta
            // agar antrean yang batal/terlupa tak menumpuk tiap hari.
            ->where(function ($q) {
                $q->whereDate('created_at', today())
                  ->orWhere(fn ($a) => $a
                      ->whereIn('status', [Queue::STATUS_IN_PROGRESS, Queue::STATUS_CALLED])
                      ->where('created_at', '>=', today()->subDays(7)))   // batasi ≤7 hari agar operasi terbengkalai lama tak menumpuk
                  // +Pasien "belum tutup kasir" (status apa pun, incl COMPLETED) yang
                  // visit-nya belum SELESAI & billing belum dikunci → bisa tambah/ubah
                  // paket bedah & obat pasca-bedah selama belum bayar (tab "Masih Aktif").
                  // TANPA batas umur: selaras KasirService (boardVisibleOpenBilling null) —
                  // kunjungan yang belum tutup tagihan kasir tak boleh lenyap dari papan
                  // hanya karena lewat 7 hari (mis. klaim BPJS tertunda). Bound-nya adalah
                  // status billing (PAID/PARTIALLY_PAID/CANCELLED) + current_station=SELESAI,
                  // bukan tanggal.
                  ->orWhere(fn ($b) => $b
                      ->whereHas('visit', fn ($v) => $v
                          ->where('current_station', '!=', 'SELESAI')
                          ->where(fn ($iv) => $iv
                              ->whereDoesntHave('billingInvoice')
                              ->orWhereHas('billingInvoice', fn ($bi) => $bi
                                  ->whereNotIn('status', ['PAID', 'PARTIALLY_PAID', 'CANCELLED'])))));
            })
            ->whereHas('visit')   // exclude baris dgn visit soft-deleted (zombie row) — sama spt AdmisiView
            // Papan BEDAH = hanya operasi (RUANG_BEDAH). Pasien tindakan laser
            // (location_type=RUANG_TINDAKAN) ditangani stasiun Ruang Tindakan terpisah.
            // Jadwal lama tanpa location_type (null) dianggap RUANG_BEDAH (backward-compat).
            ->where(function ($q) {
                $q->whereDoesntHave('visit.surgerySchedule')
                  ->orWhereHas('visit.surgerySchedule', fn ($s) =>
                      $s->where('location_type', '!=', SurgerySchedule::LOCATION_RUANG_TINDAKAN)
                        ->orWhereNull('location_type'));
            })
            // GUARD: hanya pasien yang BENAR-BENAR punya jadwal bedah boleh tampil di
            // papan. Operasi tak bisa dimulai tanpa surgery_schedule (startOperation
            // butuh schedule untuk transisi SCHEDULED→IN_PROGRESS + buat SurgeryRecord),
            // jadi baris YATIM — jadwal dihapus/dibatalkan, atau planning=BEDAH tanpa
            // paket+tanggal (DokterService::resolveSurgerySchedule kembalikan null) —
            // tak boleh muncul. Sumber jadwal: visit (preop flow) ATAU doctor_examination
            // (jalur dokter), selaras resolusi $schedule di mapper di bawah.
            ->where(function ($q) {
                $q->whereHas('visit.surgerySchedule')
                  ->orWhereHas('visit.doctorExamination.surgerySchedule');
            })
            ->orderBy('queue_sequence')
            ->get();

        return $queues->map(fn (Queue $q) => $this->mapBedahQueueRow($q))->all();
    }

    /** Eager-load relasi baris antrean/riwayat Bedah (dipakai getPatientQueue & getSurgeryHistory). */
    private function bedahQueueEagerLoads(): array
    {
        return [
            'visit.patient',
            'visit.insurer',
            'visit.surgerySchedule.surgeryPackage',
            'visit.surgerySchedule.leadSurgeon',
            'visit.doctorExamination.surgeryPackage',
            'visit.doctorExamination.surgerySchedule.leadSurgeon',
            'visit.doctorExamination.doctor',
            'visit.refractionRecord',
            'visit.billingInvoice',
        ];
    }

    /** Map satu baris Queue BEDAH ke shape antrean/riwayat yg dipakai FE (transformQueueItem). */
    private function mapBedahQueueRow(Queue $q): array
    {
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
                    'id'              => $visit->id,
                    'visit_date'      => $visit->visit_date?->toDateString(),
                    'classification'  => $visit->classification,
                    'visit_type'      => $visit->visit_type,
                    'guarantor_type'  => $visit->guarantor_type,
                    'insurer_name'    => $visit->insurer?->name,
                    // jenis_pelayanan dipakai FE utk dropdown disposisi adaptif:
                    // RANAP → pasien dari kamar (kembali ke RANAP/HCU), else PULANG/RAWAT_INAP.
                    'jenis_pelayanan' => $visit->jenis_pelayanan ?? 'RAJAL',
                    'diagnosa'        => $exam?->diagnosis_utama,
                    'diagnosa_nama'   => $this->icd10Name($exam?->diagnosis_utama),
                    'dpjp'            => $dpjp,
                    // Kunjungan sudah ditutup di kasir? current_station=SELESAI (di-set saat
                    // invoice PAID) ATAU invoice sudah PAID/PARTIALLY_PAID. Dipakai FE utk
                    // tab "Masih Aktif" = lintas-hari & kasir BELUM selesai (bukan proksi tanggal).
                    'kasir_selesai'   => $visit->current_station === 'SELESAI'
                        || in_array($visit->billingInvoice?->status, ['PAID', 'PARTIALLY_PAID'], true),
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
                    // Tanggal planning bedah dibuat — fallback "tanggal bedah" di FE
                    // saat scheduled_date kosong (kartu antrean + klasifikasi hari-ini).
                    'created_date'   => $schedule->created_at?->toDateString(),
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
    }

    /**
     * Riwayat pasien Bedah pada TANGGAL tertentu (datepicker) — untuk mengerjakan/
     * melengkapi Laporan Operasi di kemudian hari. Semua baris Queue BEDAH yang TANGGAL
     * BEDAH-nya = $date (scheduled_date jadwal, jalur visit atau doctor_examination),
     * apa pun status (incl COMPLETED). Hanya operasi (RUANG_BEDAH; laser dikecualikan).
     * Shape identik antrean → FE pakai transformQueueItem + pickPt yang sama.
     */
    public function getSurgeryHistory(string $date): array
    {
        $queues = Queue::with($this->bedahQueueEagerLoads())
            ->where('station', 'BEDAH')
            ->whereHas('visit')
            ->where(function ($q) {
                $q->whereDoesntHave('visit.surgerySchedule')
                  ->orWhereHas('visit.surgerySchedule', fn ($s) =>
                      $s->where('location_type', '!=', SurgerySchedule::LOCATION_RUANG_TINDAKAN)
                        ->orWhereNull('location_type'));
            })
            // Tanggal bedah = scheduled_date jadwal (visit preop ATAU doctor_examination).
            ->where(function ($q) use ($date) {
                $q->whereHas('visit.surgerySchedule', fn ($s) => $s->whereDate('scheduled_date', $date))
                  ->orWhereHas('visit.doctorExamination.surgerySchedule', fn ($s) => $s->whereDate('scheduled_date', $date));
            })
            ->orderBy('queue_sequence')
            ->get();

        return $queues->map(fn (Queue $q) => $this->mapBedahQueueRow($q))->all();
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

        // Jadwal dibatalkan → pasien yang terlanjur ter-route ke BEDAH jangan nyangkut.
        if ($schedule->status === 'CANCELLED') {
            $this->releaseBedahForSchedule($id);
        }

        return $schedule->fresh(['surgeryPackage', 'leadSurgeon']);
    }

    public function deleteSchedule(string $id): void
    {
        $schedule = SurgerySchedule::findOrFail($id);

        if (in_array($schedule->status, ['IN_PROGRESS', 'DONE'])) {
            throw new \Exception('Jadwal tidak bisa dihapus — operasi sudah berjalan atau selesai.', 422);
        }

        // Tangkap visit tertaut SEBELUM hapus (FK akan menggantung setelah delete).
        $visitIds = $this->scheduleLinkedVisitIds($id);

        $schedule->delete();
        // Lepas tautan menggantung agar tak ada referensi ke jadwal yang sudah tiada.
        Visit::where('surgery_schedule_id', $id)->update(['surgery_schedule_id' => null]);
        DoctorExamination::where('surgery_schedule_id', $id)->update(['surgery_schedule_id' => null]);

        $this->log(auth('api')->id(), 'DELETE_JADWAL', SurgerySchedule::class, $id);

        // Pasien yang terlanjur ter-route ke BEDAH karena jadwal ini → teruskan ke
        // alur normal (BEDAH→KASIR / kembali RANAP) supaya tak nyangkut tanpa jadwal.
        foreach ($visitIds as $vid) {
            $visit = Visit::find($vid);
            if ($visit) {
                $this->queueService->releaseUnscheduledBedah($visit);
            }
        }
    }

    /** Visit yang menunjuk sebuah jadwal bedah (lewat visit ATAU doctor_examination). */
    private function scheduleLinkedVisitIds(string $scheduleId)
    {
        return Visit::where('surgery_schedule_id', $scheduleId)->pluck('id')
            ->merge(DoctorExamination::where('surgery_schedule_id', $scheduleId)->pluck('visit_id'))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Lepas pasien yang ter-route ke BEDAH karena jadwal ini, saat jadwal di-CANCELLED
     * (schedule row masih ada). Delegasi ke QueueService::releaseUnscheduledBedah yang
     * mengecek validitas jadwal (CANCELLED → dianggap tak valid) lalu teruskan alur normal.
     */
    private function releaseBedahForSchedule(string $scheduleId): void
    {
        foreach ($this->scheduleLinkedVisitIds($scheduleId) as $vid) {
            $visit = Visit::find($vid);
            if ($visit) {
                $this->queueService->releaseUnscheduledBedah($visit);
            }
        }
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
        $schedule = SurgerySchedule::with('surgeryRecord', 'surgeryPackage')->findOrFail($scheduleId);

        if ($schedule->status !== 'IN_PROGRESS') {
            throw new \Exception('Operasi belum dimulai.', 422);
        }

        $record = $schedule->surgeryRecord;

        if (! $record) {
            throw new \Exception('Laporan operasi tidak ditemukan. Mulai operasi terlebih dahulu.', 422);
        }

        // Gating lunak: Time Out (gerbang WHO sebelum insisi) wajib terisi sebelum
        // operasi diselesaikan — KECUALI tim mencatat alasan bypass darurat.
        $checklist  = $record->safety_checklist ?? [];
        $timeOutOk  = ! empty($checklist['time_out']);
        $timeOutByp = ! empty($checklist['bypass']['time_out']);
        if (! $timeOutOk && ! $timeOutByp) {
            throw new \Exception('Time Out (checklist keselamatan) belum diisi. Lengkapi atau lewati dengan alasan darurat terlebih dahulu.', 422);
        }

        $result = DB::transaction(function () use ($schedule, $record, $data) {
            // Disposisi pasca-op:
            //   PULANG       → KASIR (rawat jalan/pre-op selesai, pulang)
            //   RAWAT_INAP   → papan "Menunggu Kamar" (pasien BARU butuh inap)
            //   LANJUT_RANAP → kembali ke kamar (pasien yang SUDAH RANAP, bedah = sub-aktivitas)
            //   HCU          → kembali ke RANAP + tanda butuh pindah HCU (transfer bed oleh petugas RANAP)
            $disposition = $data['post_op_disposition'] ?? 'PULANG';
            if (! in_array($disposition, self::POST_OP_DISPOSITIONS, true)) {
                throw new \Exception('post_op_disposition tidak valid.', 422);
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

            $fresh = $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);

            // Peringatan non-blok: operasi IOL (Phaco/SICS) tapi IOL belum dicatat →
            // billing & traceability bisa bocor. Tidak memblok penyelesaian operasi.
            $warnings = [];
            if ($this->needsIol($schedule) && $fresh->iolUsages->isEmpty()) {
                $warnings[] = 'IOL belum dicatat untuk operasi ini — pastikan lensa terpasang dicatat sebelum mengunci laporan.';
            }
            $fresh->setAttribute('warnings', $warnings);

            return $fresh;
        });

        // Manfaat "konsultasi kontrol gratis pasca-bedah" (Opsi B): terbitkan hak PER
        // OPERASI untuk paket pasien yang mengaktifkannya. Non-blok (gagal hanya di-log).
        app(\App\Services\PackageFollowupService::class)->issueForOperation($schedule->id);

        return $result;
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
     * "Selesai Operasi" — teruskan pasien ke Farmasi/Kasir TANPA mengunci laporan.
     *
     * Pemisahan SELESAI vs KUNCI (keputusan 7 Jun 2026): aksi ini hanya meroute
     * pasien; laporan operasi tetap DRAFT & editable. Penguncian final terjadi saat
     * dokter meninjau & menandatangani dokumen RM di TtdDokumenView (finalize
     * PatientDocument = immutable). Karena itu `finalized_at` TIDAK di-set di sini
     * (finalized_at = sinyal kunci universal yang memblok edit laporan/IOL/recovery).
     *
     * Advance antrean SENGAJA di sini (bukan di Time Out): pasien baru diteruskan
     * setelah operator menekan "Selesai Operasi". Delegasi ke QueueService sbg sumber
     * tunggal routing + broadcast TV (FARMASI bila ada resep aktif, else KASIR).
     * Idempoten: tanpa baris bedah aktif, pasien sudah diteruskan → routing dilewati.
     */
    public function finalizeRecord(string $id): SurgeryRecord
    {
        $record   = SurgeryRecord::with('surgerySchedule')->findOrFail($id);
        $schedule = $record->surgerySchedule;

        // Legacy: record lama yang TER-KUNCI (finalized_at di-set sebelum pemisahan).
        if ($record->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci.', 422);
        }

        if ($schedule->status !== 'DONE') {
            throw new \Exception('Operasi belum selesai — lakukan Time Out dulu.', 422);
        }

        if (! $record->time_in || ! $record->time_out) {
            throw new \Exception('Time In dan Time Out wajib diisi dulu.', 422);
        }

        return DB::transaction(function () use ($record) {
            // CATATAN: finalized_at SENGAJA tidak di-set — laporan tetap editable
            // sampai dikunci via TTD dokumen RM (TtdDokumenView).
            $visitId = $record->visit_id;

            // B6: visit_id null seharusnya tak terjadi (dicegah di hulu oleh guard
            // startOperation/B2). Bila tetap null karena data lama: JANGAN gagal —
            // cukup catat warning + lewati routing.
            if (! $visitId) {
                \Illuminate\Support\Facades\Log::warning('Selesai operasi: record tanpa visit_id — routing antrean dilewati.', [
                    'surgery_record_id' => $record->id,
                ]);
            } else {
                $visit = Visit::find($visitId);

                $bedahQueue = Queue::where('visit_id', $visitId)
                    ->where('station', Queue::STATION_BEDAH)
                    ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                    ->latest('created_at')
                    ->first();

                // IDEMPOTEN: routing hanya saat masih ada baris bedah AKTIF. Karena
                // finalized_at tak lagi mengunci, "Selesai Operasi" bisa terpanggil
                // ulang — tanpa baris aktif berarti pasien sudah diteruskan → lewati
                // (cegah double-route / reset MENUNGGU_RANAP setelah pasien di-admit).
                if ($bedahQueue) {
                    $disposition = $record->post_op_disposition ?? 'PULANG';
                    $isRanapPatient = $visit && ($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP';

                    // Disposisi HCU (pasien RANAP butuh perawatan intensif pasca-bedah):
                    // tetap dikembalikan ke alur RANAP (bed sekarang ditahan), TAPI catat
                    // tanda agar petugas RANAP memindahkan ke bed HCU via transferBed.
                    if ($disposition === 'HCU' && $isRanapPatient) {
                        \Illuminate\Support\Facades\Log::info('Pasca-bedah: pasien RANAP ditandai butuh HCU — petugas RANAP perlu transfer bed.', [
                            'visit_id'          => $visitId,
                            'surgery_record_id' => $record->id,
                        ]);
                    }

                    // Disposisi RAWAT_INAP untuk pasien rawat JALAN/PREOP (belum RANAP):
                    // arahkan ke papan "Menunggu Kamar". Petugas ranap admit bed via
                    // RanapService::admit. Pasien yang SUDAH RANAP (LANJUT_RANAP/HCU)
                    // ditangani normal oleh resolveNextRanap → returnToLiveRanap.
                    $toMenungguRanap = $disposition === 'RAWAT_INAP' && $visit && ! $isRanapPatient;

                    if ($toMenungguRanap) {
                        $bedahQueue->update([
                            'status'       => Queue::STATUS_COMPLETED,
                            'completed_at' => now(),
                        ]);
                        $visit->update(['current_station' => 'MENUNGGU_RANAP']);
                    } else {
                        // PULANG (RAJAL→KASIR/Farmasi) atau pasien SUDAH RANAP (kembali
                        // ke kamar). advanceFromStation memilih tujuan via resolveNextStation.
                        $this->queueService->advanceFromStation($bedahQueue->id, Queue::STATION_BEDAH);
                    }
                }
            }

            // Terbitkan laporan operasi sebagai PatientDocument resmi (masuk antrean
            // TTD Dokumen utk DPJP + dokter anestesi, & tampil di Rekam Medis).
            // Dibungkus try/catch + Log: gagal publish TIDAK boleh mem-batalkan
            // finalisasi operasi (pola toleran sama spt visit_id null di atas).
            try {
                $this->publishLaporanBedahDocument($record);
            } catch (\Throwable $e) {
                Log::warning('Finalize bedah: gagal terbitkan dokumen laporan operasi — finalisasi tetap dikunci.', [
                    'surgery_record_id' => $record->id,
                    'error'             => $e->getMessage(),
                ]);
            }

            // Resume Medis BEDAH (RM 3.5/LB) — ringkasan pulang pasien bedah. Untuk
            // pasien yang PULANG langsung dari bedah (RAJAL day-surgery & IGD) buat DRAFT
            // otomatis → masuk antrean TTD dokter (menggantikan RM 1.7 yang sengaja TIDAK
            // dibuat utk pasien bedah, lihat DokterService::finalize). HANYA pasien RANAP
            // yang dikecualikan: mereka pakai Resume Medis Rawat Inap (RESUME_MEDIS_RANAP)
            // via alur RawatInap. Idempoten + non-blocking (pola sama di atas).
            if ($visitId) {
                try {
                    $visitForResume = Visit::find($visitId);
                    if ($visitForResume && ($visitForResume->jenis_pelayanan ?? 'RAJAL') !== 'RANAP') {
                        app(\App\Services\FormRegistry\FormRegistryService::class)
                            ->ensureDraftForVisit($visitId, 'RESUME_MEDIS_BEDAH');
                    }
                } catch (\Throwable $e) {
                    Log::warning('Finalize bedah: gagal buat draft Resume Medis Bedah — finalisasi tetap dikunci.', [
                        'visit_id' => $visitId,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $this->log(auth('api')->id(), 'FINALIZE_RECORD', SurgeryRecord::class, $record->id, 'Selesai operasi — pasien diteruskan (laporan belum dikunci; kunci via TTD dokumen)');

            return $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);
        });
    }

    // =========================================================================
    // PERIOPERATIF — WHO Safety Checklist + Laporan Operasi + Skor Pemulihan
    // (PAB STARKES 2024 / WHO Surgical Safety Checklist). Disimpan di kolom JSONB
    // surgery_records, pola sama surgery_anesthesia_reports.form_data.
    // =========================================================================

    private const CHECKLIST_PHASES = ['sign_in', 'time_out', 'sign_out'];

    /**
     * Disposisi pasca-operasi yang sah:
     *   PULANG       — rawat jalan/pre-op selesai → KASIR
     *   RAWAT_INAP   — pasien BARU butuh inap → papan "Menunggu Kamar"
     *   LANJUT_RANAP — pasien yang SUDAH RANAP, bedah=sub-aktivitas → kembali ke kamar
     *   HCU          — kembali ke RANAP + tanda butuh pindah HCU (transfer bed di modul RANAP)
     */
    public const POST_OP_DISPOSITIONS = ['PULANG', 'RAWAT_INAP', 'LANJUT_RANAP', 'HCU'];

    /**
     * Simpan satu fase WHO Safety Checklist (sign_in / time_out / sign_out).
     *
     * MERGE (bukan replace) ke kolom safety_checklist supaya fase lain tak hilang.
     * Gating lunak: bila $bypassReason diisi, fase dianggap "dilewati darurat" dan
     * dicatat di safety_checklist.bypass[$phase] + SystemLog (audit).
     */
    public function saveSafetyChecklist(string $recordId, string $phase, array $data, ?string $bypassReason = null): SurgeryRecord
    {
        if (! in_array($phase, self::CHECKLIST_PHASES, true)) {
            throw new \Exception('Fase checklist tidak valid (sign_in/time_out/sign_out).', 422);
        }

        $record = SurgeryRecord::findOrFail($recordId);
        if ($record->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci — checklist tidak bisa diubah.', 422);
        }

        $checklist = $record->safety_checklist ?? [];
        $checklist[$phase] = array_merge($data, [
            'at' => now()->toIso8601String(),
            'by' => auth('api')->id(),
        ]);

        if ($bypassReason !== null && $bypassReason !== '') {
            $checklist['bypass'] = $checklist['bypass'] ?? [];
            $checklist['bypass'][$phase] = [
                'reason' => $bypassReason,
                'by'     => auth('api')->id(),
                'at'     => now()->toIso8601String(),
            ];
        }

        $record->update(['safety_checklist' => $checklist]);

        $this->log(
            auth('api')->id(),
            'SAVE_SAFETY_CHECKLIST',
            SurgeryRecord::class,
            $recordId,
            "Fase {$phase}" . ($bypassReason ? " (DILEWATI: {$bypassReason})" : '')
        );

        return $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);
    }

    /**
     * Simpan Laporan Operasi terstruktur (isi minimal PAB) ke JSONB.
     *
     * Implan (IOL) selalu di-sinkron dari iolUsages → menjamin no. registrasi
     * lensa masuk laporan. Ringkasan diturunkan ke kolom lama
     * (operation_notes/has_complication/complication_detail) agar pembaca lama
     * (Kasir/RME) tetap jalan.
     */
    public function saveOperationReport(string $recordId, array $data): SurgeryRecord
    {
        $record = SurgeryRecord::with('iolUsages.iolItem')->findOrFail($recordId);
        if ($record->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci.', 422);
        }

        // Implan auto dari IOL terpasang (sumber kebenaran tunggal).
        $implants = $record->iolUsages->map(fn ($u) => [
            'eye_side'      => $u->eye_side,
            'brand'         => $u->brand,
            'model'         => $u->model,
            'power'         => $u->power,
            'lot_number'    => $u->lot_number,
            'serial_number' => $u->serial_number,
            'gtin'          => $u->gtin,
        ])->values()->all();

        // MERGE (bukan replace) ke operation_report yang sudah ada — supaya saat satu
        // pasien dikerjakan beberapa akun bergiliran (mis. operator isi laporan, lalu
        // akun lain melengkapi), key yang TIDAK dikirim payload ini tidak hilang.
        // FE tetap mengirim payload penuh hasil hidrasi; merge = pertahanan berlapis.
        $existing = is_array($record->operation_report) ? $record->operation_report : [];
        $report = array_merge($existing, $data);
        $report['implants']  = $implants;
        $report['signed_by'] = auth('api')->id();
        $report['signed_at'] = now()->toIso8601String();

        $complication = $data['complication'] ?? [];
        $hasComplication = (bool) ($complication['ada'] ?? false);

        // Ringkasan backward-compatible ke kolom lama.
        $notesParts = [];
        if (! empty($data['technique']))  $notesParts[] = "[Teknik Operasi]\n" . $data['technique'];
        if (! empty($data['findings']))   $notesParts[] = "[Temuan Intraoperatif]\n" . $data['findings'];
        if (! empty($data['notes']))      $notesParts[] = "[Catatan]\n" . $data['notes'];

        $updates = [
            'operation_report'    => $report,
            'operation_notes'     => $notesParts ? implode("\n\n", $notesParts) : ($record->operation_notes),
            'has_complication'    => $hasComplication,
            'complication_detail' => $hasComplication
                ? trim(($complication['type'] ?? '') . ' ' . ($complication['management'] ?? ''))
                : null,
        ];

        // BUG-FIX: disposisi pasca-op yang diubah di form Laporan Operasi WAJIB
        // diterapkan ke KOLOM (bukan cuma masuk JSONB) — finalizeRecord meroute
        // pasien berdasarkan kolom post_op_disposition. Tanpa ini, perubahan
        // disposisi di tab Laporan hilang diam-diam (salah rute KASIR vs RANAP).
        if (isset($data['post_op_disposition'])
            && in_array($data['post_op_disposition'], self::POST_OP_DISPOSITIONS, true)) {
            $updates['post_op_disposition'] = $data['post_op_disposition'];
        }

        $record->update($updates);

        $this->log(auth('api')->id(), 'SAVE_OPERATION_REPORT', SurgeryRecord::class, $recordId);

        return $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);
    }

    /**
     * Simpan Skor Pemulihan (Aldrete) + nyeri + vital (PACU).
     * Total Aldrete dihitung di SERVER (jangan percaya FE); ambang ≥9 = layak transfer.
     */
    public function saveRecoveryAssessment(string $recordId, array $data): SurgeryRecord
    {
        $record = SurgeryRecord::findOrFail($recordId);
        if ($record->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci.', 422);
        }

        $aldrete = $data['aldrete'] ?? [];
        $keys = ['activity', 'respiration', 'circulation', 'consciousness', 'spo2'];
        $total = 0;
        foreach ($keys as $k) {
            $v = (int) ($aldrete[$k] ?? 0);
            $total += max(0, min(2, $v)); // clamp 0..2
        }

        $assessment = $data;
        $assessment['aldrete']        = $aldrete;
        $assessment['aldrete_total']  = $total;
        $assessment['layak_transfer'] = $total >= 9;
        $assessment['at']             = now()->toIso8601String();
        $assessment['by']             = auth('api')->id();

        $record->update(['recovery_assessment' => $assessment]);

        $this->log(auth('api')->id(), 'SAVE_RECOVERY_ASSESSMENT', SurgeryRecord::class, $recordId, "Aldrete {$total}/10");

        return $record->fresh(['surgerySchedule', 'iolUsages.iolItem']);
    }

    // =========================================================================
    // DOKUMEN LAPORAN OPERASI (RM-5.3) → PatientDocument multi-TTD
    // =========================================================================

    /**
     * Apakah operasi melibatkan anestesi termonitor → TTD anestesi wajib.
     * Selaras FE BedahView hasAnesthesia: jenis TIVA / Umum (GA) — diputuskan di awal
     * (Pra-Bedah); lokal/topikal/sub-tenon TIDAK, walau slot anestesiolog terisi.
     * Fallback anesthesiologist_id hanya untuk record legacy tanpa anesthesia_type.
     */
    private function operationHasAnesthesia(SurgeryRecord $record): bool
    {
        $report = $record->operation_report ?? [];
        $type   = strtolower((string) ($report['anesthesia_type'] ?? ''));

        if ($type !== '') {
            return in_array($type, ['umum', 'tiva'], true);
        }

        return ! empty($record->surgerySchedule?->anesthesiologist_id);
    }

    /**
     * Terbitkan laporan operasi yang difinalisasi sebagai PatientDocument (RM-5.3).
     * Idempoten per (visit, document_type). Status PENDING_SIGNATURE → masuk antrean
     * TTD Dokumen (DPJP via 'doctor', anestesi via 'doctor_anestesi') & Rekam Medis.
     * Pola: DokterService::publishResumeDocument.
     */
    private function publishLaporanBedahDocument(SurgeryRecord $record): void
    {
        $visit = Visit::with('patient')->find($record->visit_id);
        if (! $visit) {
            return; // tanpa visit, dokumen tak bisa di-render — finalisasi tetap jalan.
        }

        // RM_BEDAH_LAPORAN (RM-5.3) DIMATIKAN saat pembersihan Form Registry → rebuild
        // sebagai RM 2.2/LP/22 (LAPORAN_PEMBEDAHAN) via FormDocsBrowser di tab Laporan
        // BedahView. Tanpa template ini, auto-publish HANYA membuat dokumen menggantung
        // (template_code tak ter-render di TtdView/RME). Guard: lewati auto-publish.
        if (! DocumentTemplate::where('code', 'RM_BEDAH_LAPORAN')->exists()) {
            return;
        }

        $hasAnesthesia = $this->operationHasAnesthesia($record);

        $docType = DocumentType::firstOrCreate(
            ['code' => 'RM-5.3'],
            [
                'name'                => 'Laporan Operasi',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [
                    ['role' => 'DPJP',     'sign_type' => 'digital', 'is_required' => true],
                    ['role' => 'ANESTESI', 'sign_type' => 'digital', 'is_required' => false],
                ],
                'show_in_rme'         => true,
                'is_active'           => true,
            ]
        );

        $payload    = $this->buildLaporanBedahPayload($record, $visit);
        $pending    = ['DPJP'];
        $signatures = ['static_payload' => $payload];

        if ($hasAnesthesia) {
            $pending[] = 'ANESTESI';
        } else {
            // Operasi tanpa anestesi → slot TTD anestesi TIDAK wajib. Override
            // field_schema per-dokumen supaya finalize cukup TTD DPJP.
            $tpl = DocumentTemplate::where('code', 'RM_BEDAH_LAPORAN')->first();
            $schema = $tpl?->field_schema ?? [];
            // Loop by index — `($schema['fields'] ?? [])` di foreach-by-ref menulis ke
            // SALINAN temporer (gotcha operator ??), bukan ke $schema['fields'].
            foreach (($schema['fields'] ?? []) as $i => $f) {
                if (($f['key'] ?? null) === 'ttd_doctor_anestesi') {
                    $schema['fields'][$i]['required'] = false;
                }
            }
            $signatures['field_schema_override'] = $schema;
        }

        PatientDocument::updateOrCreate(
            ['visit_id' => $visit->id, 'document_type_id' => $docType->id],
            [
                'patient_id'              => $visit->patient_id,
                'status'                  => 'PENDING_SIGNATURE',
                'created_by_station'      => 'BEDAH',
                'template_code'           => 'RM_BEDAH_LAPORAN',
                'rendered_html'           => null,
                'pending_signature_roles' => $pending,
                'signatures'              => $signatures,
                'finalized_at'            => null,
                'printed_count'           => 0,
            ]
        );
    }

    /**
     * Peta key→string isi LENGKAP laporan operasi untuk static_payload template.
     * Sumber: operation_report / safety_checklist / recovery_assessment (JSONB) +
     * iolUsages + kolom record. Identitas pasien & kop klinik auto-bind di template.
     */
    private function buildLaporanBedahPayload(SurgeryRecord $record, Visit $visit): array
    {
        $report   = $record->operation_report ?? [];
        $checklist = $record->safety_checklist ?? [];
        $recovery = $record->recovery_assessment ?? [];
        $schedule = $record->surgerySchedule;

        $fmtTime = fn ($t) => $t ? $t->format('H:i') : '—';
        $durasi  = '—';
        if ($record->time_in && $record->time_out) {
            // Carbon 3 diffInMinutes() bisa kembalikan float → cast int (cegah deprecation).
            $mins = (int) $record->time_in->diffInMinutes($record->time_out);
            $durasi = intdiv($mins, 60) . 'j ' . ($mins % 60) . 'm';
        }

        // Implan IOL terpasang (sumber kebenaran = iolUsages).
        $iolLines = [];
        foreach ($record->iolUsages as $u) {
            $parts = array_filter([
                $u->eye_side,
                trim(($u->brand ?? '') . ' ' . ($u->model ?? '')),
                $u->power !== null ? $u->power . ' D' : null,
                $u->lot_number ? 'Lot ' . $u->lot_number : null,
                $u->serial_number ? 'SN ' . $u->serial_number : null,
            ]);
            $iolLines[] = implode(' · ', $parts);
        }

        // Komplikasi naratif.
        $compl = $report['complication'] ?? [];
        $komplikasi = ($record->has_complication || ! empty($compl['ada']))
            ? trim(($compl['type'] ?? '') . ' ' . ($compl['management'] ?? '')) ?: ($record->complication_detail ?? 'Ada')
            : 'Tidak ada komplikasi';

        // Vitrektomi (bila ada).
        $vit = $report['vitrectomy_details'] ?? null;
        $vitrektomi = '—';
        if (is_array($vit) && ! empty($vit)) {
            $vitrektomi = 'Tamponade: ' . ($vit['tamponade'] ?? '—')
                . (! empty($vit['endolaser']) ? ' · Endolaser' : '')
                . (! empty($vit['membrane_peeling']) ? ' · Membrane peeling' : '');
        }

        // Aldrete + nyeri.
        $aldreteStr = isset($recovery['aldrete_total'])
            ? $recovery['aldrete_total'] . '/10' . (($recovery['layak_transfer'] ?? false) ? ' (layak transfer)' : '')
                . (isset($recovery['pain_score']) ? ' · Nyeri ' . $recovery['pain_score'] . '/10' : '')
            : '—';

        // Checklist WHO ringkas (label TRUE saja).
        $who = function (array $phase): string {
            $on = [];
            foreach ($phase as $k => $v) {
                if ($v === true) $on[] = str_replace('_', ' ', $k);
                elseif (is_string($v) && $v !== '') $on[] = str_replace('_', ' ', $k) . ': ' . $v;
            }
            return $on ? implode(', ', $on) : '—';
        };

        $disposisiMap = [
            'PULANG' => 'Pulang (ke Kasir)', 'RAWAT_INAP' => 'Rawat Inap',
            'LANJUT_RANAP' => 'Lanjut Rawat Inap (kembali ke kamar)', 'HCU' => 'Pindah HCU',
        ];

        $asisten = $report['asisten'] ?? [];

        return [
            'tgl_operasi'        => optional($schedule?->scheduled_date)->format('d-m-Y') ?? optional($record->time_in)->format('d-m-Y') ?? '—',
            'ruang_ok'           => $schedule?->operation_room ?? '—',
            'time_in'            => $fmtTime($record->time_in),
            'time_out'           => $fmtTime($record->time_out),
            'durasi'             => $durasi,
            'jenis_anestesi'     => $report['anesthesia_type'] ?? '—',
            'diagnosis_pre'      => $report['diagnosis_pre'] ?? '—',
            'diagnosis_post'     => $report['diagnosis_post'] ?? '—',
            'prosedur'           => $report['procedure_name'] ?? '—',
            'operator'           => $report['operator'] ?? '—',
            'anesthesiologist'   => $report['anesthesiologist'] ?? '—',
            'asisten'            => is_array($asisten) ? (implode(', ', array_filter($asisten)) ?: '—') : (string) $asisten,
            'scrub_nurse'        => $report['scrub_nurse'] ?? '—',
            'circ_nurse'         => $report['circ_nurse'] ?? '—',
            'teknik'             => $report['technique'] ?? '—',
            'temuan'             => $report['findings'] ?? '—',
            'komplikasi'         => $komplikasi,
            'ebl'                => $report['estimated_blood_loss'] ?? '—',
            'vitrektomi'         => $vitrektomi,
            'implan_iol'         => $iolLines ? implode('; ', $iolLines) : 'Tidak ada implan',
            'sign_in'            => $who($checklist['sign_in'] ?? []),
            'time_out_checklist' => $who($checklist['time_out'] ?? []),
            'sign_out'           => $who($checklist['sign_out'] ?? []),
            'aldrete'            => $aldreteStr,
            'instruksi_pasca'    => $record->post_op_instructions ?: '—',
            'disposisi'          => $disposisiMap[$record->post_op_disposition] ?? ($record->post_op_disposition ?? '—'),
        ];
    }

    // =========================================================================
    // ANESTESI — Laporan Anestesi (RM 5.2) + Monitoring vital durante
    // Tabel: surgery_anesthesia_reports (form_data JSONB, 1/operasi) +
    // surgery_anesthesia_vitals (baris vital per-waktu). RBAC anestesi.read/write.
    // =========================================================================

    /** Daftar dokter anestesi (doctor_type=ANESTESI) untuk dropdown DPJP Anestesi. */
    public function getAnesthesiologists(): array
    {
        return Employee::where('doctor_type', Employee::DT_ANESTESI)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'profession', 'sip', 'str'])
            ->toArray();
    }

    /** Laporan anestesi untuk satu surgery_record (null bila belum ada). */
    public function getAnesthesiaReport(string $recordId): ?SurgeryAnesthesiaReport
    {
        return SurgeryAnesthesiaReport::where('surgery_record_id', $recordId)->first();
    }

    /**
     * Simpan/perbarui laporan anestesi (form_data JSONB). Idempoten via
     * updateOrCreate per surgery_record_id (unique). asa_class & teknik_anestesi
     * diekstrak dari form_data untuk query/cetak cepat.
     */
    public function saveAnesthesiaReport(string $recordId, array $data): SurgeryAnesthesiaReport
    {
        $record = SurgeryRecord::findOrFail($recordId);
        if ($record->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci — laporan anestesi tidak bisa diubah.', 422);
        }

        $formData = $data['form_data'] ?? [];

        $report = SurgeryAnesthesiaReport::updateOrCreate(
            ['surgery_record_id' => $recordId],
            [
                'visit_id'        => $record->visit_id,
                'asa_class'       => $formData['asa_class'] ?? null,
                'teknik_anestesi' => $formData['teknik_anestesi'] ?? null,
                'form_data'       => $formData,
                'recorded_by_id'  => $this->resolveEmployeeId(),
            ]
        );

        $this->log(auth('api')->id(), 'SAVE_ANESTHESIA_REPORT', SurgeryAnesthesiaReport::class, $report->id);

        return $report;
    }

    /** Daftar pembacaan vital anestesi (durante) untuk satu record, urut waktu. */
    public function listAnesthesiaVitals(string $recordId): Collection
    {
        return SurgeryAnesthesiaVital::where('surgery_record_id', $recordId)
            ->orderBy('recorded_at')
            ->get();
    }

    /** Catat satu pembacaan vital anestesi. */
    public function recordAnesthesiaVital(array $data): SurgeryAnesthesiaVital
    {
        $record = SurgeryRecord::findOrFail($data['surgery_record_id']);
        if ($record->finalized_at) {
            throw new \Exception('Laporan operasi sudah dikunci — vital tidak bisa ditambah.', 422);
        }

        $vital = SurgeryAnesthesiaVital::create([
            'surgery_record_id' => $record->id,
            'recorded_at'       => $data['recorded_at'] ?? now(),
            'td_sistol'         => $data['td_sistol'] ?? null,
            'td_diastol'        => $data['td_diastol'] ?? null,
            'nadi'              => $data['nadi'] ?? null,
            'spo2'              => $data['spo2'] ?? null,
            'rr'                => $data['rr'] ?? null,
            'etco2'             => $data['etco2'] ?? null,
            'suhu'              => $data['suhu'] ?? null,
            'obat_kejadian'     => $data['obat_kejadian'] ?? null,
            'recorded_by_id'    => $this->resolveEmployeeId(),
        ]);

        return $vital;
    }

    /**
     * Perbarui satu pembacaan vital anestesi. FE selalu mengirim payload lengkap
     * (semua kolom nullable), jadi set langsung — `?? null` membersihkan field
     * yang dikosongkan petugas.
     */
    public function updateAnesthesiaVital(string $id, array $data): SurgeryAnesthesiaVital
    {
        $vital = SurgeryAnesthesiaVital::findOrFail($id);

        $vital->update([
            'recorded_at'   => $data['recorded_at'] ?? $vital->recorded_at,
            'td_sistol'     => $data['td_sistol'] ?? null,
            'td_diastol'    => $data['td_diastol'] ?? null,
            'nadi'          => $data['nadi'] ?? null,
            'spo2'          => $data['spo2'] ?? null,
            'rr'            => $data['rr'] ?? null,
            'etco2'         => $data['etco2'] ?? null,
            'suhu'          => $data['suhu'] ?? null,
            'obat_kejadian' => $data['obat_kejadian'] ?? null,
        ]);

        return $vital->fresh();
    }

    /** Hapus satu pembacaan vital anestesi. */
    public function deleteAnesthesiaVital(string $id): void
    {
        SurgeryAnesthesiaVital::findOrFail($id)->delete();
    }

    /** Resolve employee_id dari user login (untuk recorded_by). */
    private function resolveEmployeeId(): ?string
    {
        return auth('api')->user()?->employee_id ?? auth('api')->user()?->employee?->id;
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

            // Utamakan lensa yang DOKTER putuskan (rec->iol_item_id, dari layar
            // keputusan IOL berbasis biometri). Bila belum ada keputusan, jatuh
            // ke lensa bawaan komposisi paket.
            $decided = $rec?->iol_item_id ? IolItem::find($rec->iol_item_id) : null;
            $iol     = $decided ?? IolItem::find($it->item_id);

            $iolItems[] = [
                'iol_item_id'        => $iol?->id,
                'quantity'           => (int) ($it->quantity ?? 1),
                'eye_side'           => $rec?->eye_side ?? 'OD',
                'requested_power'    => $rec ? (float) $rec->recommended_power : null,
                'requested_iol_type' => $rec?->iol_type ?? $iol?->iol_type ?? null,
                'from_recommendation'=> (bool) $rec,
                'from_decision'      => (bool) ($decided && $rec?->is_final),
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
     * CATATAN is_bedah (penanda komponen paket): default FALSE → obat ditagih sebagai
     * obat pulang. Bila TRUE → obat dianggap komponen paket bedah: ditagih dari SNAPSHOT
     * paket (KasirService::buildPaketObatLines) dan di-SKIP di buildObatLines agar tak
     * dobel (selain itu buildObatLines juga punya safety-net dedup by medication_id vs
     * komposisi paket). Item dari "paket obat pasca-bedah" boleh ditandai `bundled`=true;
     * is_bedah HANYA di-set true bila bundled DAN pasien punya paket bedah aktif
     * (VisitSurgeryPackage). Pengaman: pasien tanpa paket → obat tetap ditagih walau
     * bundled; obat manual (bundled=false) → selalu ditagih (kecuali medication_id-nya
     * kebetulan ada di komposisi paket → terdedup oleh safety-net).
     *
     * @param array<int,array{medication_id?:string,quantity?:int,dose?:string,
     *              frequency?:string,route?:string,duration_days?:int,notes?:string,
     *              bundled?:bool}> $items
     * @param array{notes?:string,pharmacy_note?:string} $opts
     */
    public function storePostOpPrescription(string $visitId, array $items, array $opts = []): ?Prescription
    {
        $user = auth('api')->user();

        // Resep WAJIB punya peresep (prescriptions.prescribed_by_id NOT NULL).
        if (! $user->employee_id) {
            throw new \Exception('Akun tidak punya data pegawai — tidak bisa membuat resep.', 422);
        }

        // Revisi "Buka Kembali": tolak bila pembayaran sudah dikonfirmasi atau obat
        // pasca-bedah sudah diserahkan Farmasi (DISPENSING/DISPENSED).
        $this->assertPostOpEditable($visitId);

        // Penyerapan ke paket hanya logis bila pasien memang punya paket bedah aktif.
        $hasPaket = VisitSurgeryPackage::where('visit_id', $visitId)->exists();

        $prescription = DB::transaction(function () use ($visitId, $items, $opts, $user, $hasPaket) {
            // REPLACE resep pasca-bedah lama yang masih bisa direvisi (DRAFT/SUBMITTED) +
            // itemnya. Delete+recreate → verified_at baru null = wajib verifikasi ulang
            // Farmasi. Resep DOKTER (is_post_op=false) pada visit ini TIDAK disentuh.
            // Item TAMBAHAN Farmasi diselamatkan dulu (aturan legacy dosage/instructions
            // + audit) — pola sama dgn DokterService::storePrescription.
            $tambahanCarry = [];
            foreach ($this->postOpEditableQuery($visitId)->get() as $old) {
                foreach ($old->items()->where('source', 'TAMBAHAN')->get() as $t) {
                    $tambahanCarry[] = $t->only([
                        'medication_id', 'original_medication_id', 'quantity',
                        'dosage', 'instructions', 'notes',
                        'dose', 'frequency', 'route', 'duration_days', 'pos_kwitansi',
                        'source', 'change_reason', 'changed_by_id', 'changed_at', 'added_by_id',
                    ]);
                }
                PrescriptionItem::where('prescription_id', $old->id)->delete();
                $old->delete();
            }

            // Buang item tanpa medication_id; kosong → resep dikosongkan (return null).
            $valid = array_values(array_filter($items, fn ($it) => ! empty($it['medication_id'])));
            if (empty($valid) && empty($tambahanCarry)) {
                $this->log($user->id, 'STORE_POSTOP_RESEP', Prescription::class, $visitId, 'Resep pasca-bedah dikosongkan');
                return null;
            }

            $prescription = Prescription::create([
                'visit_id'         => $visitId,
                'prescribed_by_id' => $user->employee_id,
                'status'           => 'SUBMITTED',
                'is_post_op'       => true,
                'notes'            => $opts['notes'] ?? 'Obat pasca-bedah',
                'pharmacy_note'    => $opts['pharmacy_note'] ?? null,
            ]);

            foreach ($valid as $it) {
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'medication_id'   => $it['medication_id'],
                    'quantity'        => $it['quantity'] ?? 1,
                    'dose'            => $it['dose'] ?? null,
                    'frequency'       => $it['frequency'] ?? null,
                    'route'           => $it['route'] ?? null,
                    'duration_days'   => $it['duration_days'] ?? null,
                    'notes'           => $it['notes'] ?? null,
                    // Pos kwitansi pilihan operator (null = ikut master tarif obat).
                    'pos_kwitansi'    => $it['pos_kwitansi'] ?? null,
                    // Terserap ke harga paket HANYA bila obat paket (bundled) & pasien
                    // berpaket. Else ditagih sbg obat pulang (lihat docblock).
                    'is_bedah'        => ($it['bundled'] ?? false) && $hasPaket,
                ]);
            }

            // Salin ulang item TAMBAHAN Farmasi (kemasan tidak dibawa — verifikasi ulang).
            foreach ($tambahanCarry as $t) {
                PrescriptionItem::create(['prescription_id' => $prescription->id] + $t);
            }

            $this->log(
                $user->id,
                'STORE_POSTOP_RESEP',
                Prescription::class,
                $prescription->id,
                "Resep pasca-bedah (replace) untuk kunjungan {$visitId}"
            );

            return $prescription->load('items.medication');
        });

        // Revisi pasca-kirim: bila tagihan (belum bayar) sudah ada, bangun ulang agar
        // kwitansi mencerminkan obat terbaru. Obat hasil reset (verified_at null) tak
        // ikut tertagih sampai Farmasi verifikasi ulang (gate buildObatLines).
        $this->reconsolidatePostOpRevision($visitId);

        return $prescription;
    }

    /**
     * Resep pasca-bedah yang MASIH bisa direvisi (DRAFT/SUBMITTED) untuk satu visit.
     * Cocokkan is_post_op=true ATAU resep lama bertanda catatan default 'Obat
     * pasca-bedah' (transisi data pra-kolom is_post_op). TIDAK menyentuh resep dokter.
     */
    private function postOpEditableQuery(string $visitId)
    {
        return Prescription::where('visit_id', $visitId)
            ->where(fn ($q) => $q->where('is_post_op', true)->orWhere('notes', 'Obat pasca-bedah'))
            ->whereIn('status', ['DRAFT', 'SUBMITTED']);
    }

    /**
     * Tolak revisi resep pasca-bedah bila pembayaran sudah dikonfirmasi (invoice
     * PAID/PARTIALLY_PAID) atau obat sudah diserahkan Farmasi (DISPENSING/DISPENSED).
     */
    private function assertPostOpEditable(string $visitId): void
    {
        $paid = BillingInvoice::where('visit_id', $visitId)
            ->whereIn('status', ['PAID', 'PARTIALLY_PAID'])->exists();
        if ($paid) {
            throw new \Exception('Pembayaran sudah dikonfirmasi di kasir — resep pasca-bedah tidak bisa diubah.', 422);
        }

        $dispensed = Prescription::where('visit_id', $visitId)
            ->where(fn ($q) => $q->where('is_post_op', true)->orWhere('notes', 'Obat pasca-bedah'))
            ->whereIn('status', ['DISPENSING', 'DISPENSED'])->exists();
        if ($dispensed) {
            throw new \Exception('Obat pasca-bedah sudah diserahkan Farmasi, tidak bisa diubah.', 422);
        }
    }

    /**
     * Setelah revisi resep pasca-bedah: bila tagihan aktif (belum bayar) sudah ada,
     * bangun ulang agar kwitansi mengikuti obat terbaru. FINALIZED dikembalikan ke
     * DRAFT supaya kasir konfirmasi ulang. Tak melempar — revisi tak boleh gagal
     * gara-gara rebuild kwitansi.
     */
    private function reconsolidatePostOpRevision(string $visitId): void
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
            \Illuminate\Support\Facades\Log::warning('reconsolidate pasca-bedah gagal: ' . $e->getMessage(), ['visit_id' => $visitId]);
        }
    }

    /**
     * Muat resep pasca-bedah aktif + status tagihan untuk BedahView (hidrasi saat
     * buka pasien & gating "Buka Kembali"). items kosong bila belum ada resep.
     *
     * @return array{prescription_id:?string,sent:bool,dispensing:bool,
     *               items:array<int,array<string,mixed>>,has_invoice:bool,billing_paid:bool}
     */
    public function getPostOpPrescription(string $visitId): array
    {
        $rx = Prescription::with('items.medication')
            ->where('visit_id', $visitId)
            ->where(fn ($q) => $q->where('is_post_op', true)->orWhere('notes', 'Obat pasca-bedah'))
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
                'pos_kwitansi'  => $it->pos_kwitansi,
                'from_paket'    => (bool) $it->is_bedah,
            ];
        }

        return [
            'prescription_id' => $rx?->id,
            'sent'            => (bool) $rx,
            'dispensing'      => $rx ? in_array($rx->status, ['DISPENSING', 'DISPENSED'], true) : false,
            'items'           => $items,
            'has_invoice'     => (bool) $invoice,
            'billing_paid'    => $invoice ? in_array($invoice->status, ['PAID', 'PARTIALLY_PAID'], true) : false,
        ];
    }

    /**
     * Passthrough daftar obat (untuk picker resep pasca-bedah). Sumber tunggal
     * DokterService::getDaftarObat — return [{id, code, name, form_sediaan,
     * golongan, unit, hja, farmasi_qty}].
     */
    public function getDaftarObat(?string $search): array
    {
        // Picker resep pasca-bedah HANYA menampilkan obat yang terdaftar di inventori
        // unit Farmasi (farmasiOnly=true) — resep harus bisa dilayani Farmasi, bukan
        // seluruh katalog master. Termasuk obat stok 0 (asal punya baris stok farmasi).
        return app(\App\Services\DokterService::class)->getDaftarObat($search, true);
    }

    // =========================================================================
    // PAKET OBAT PASCA-BEDAH (template resep rutin) — master CRUD
    // =========================================================================

    /** Daftar template aktif + item (untuk picker & pengelolaan). */
    public function listPrescriptionTemplates(?string $search = null): Collection
    {
        return PrescriptionTemplate::with(['items' => fn ($q) => $q->with('medication:id,name,form_sediaan,unit')])
            ->where('is_active', true)
            ->when($search, fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->orderBy('name')
            ->get();
    }

    /** Buat paket obat baru + item. */
    public function storePrescriptionTemplate(array $data): PrescriptionTemplate
    {
        return DB::transaction(function () use ($data) {
            $tpl = PrescriptionTemplate::create([
                'name'        => $data['name'],
                'category'    => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active'   => $data['is_active'] ?? true,
            ]);
            $this->syncTemplateItems($tpl, $data['items'] ?? []);

            return $tpl->load('items.medication:id,name,form_sediaan,unit');
        });
    }

    /** Perbarui paket obat + sinkron item (replace). */
    public function updatePrescriptionTemplate(string $id, array $data): PrescriptionTemplate
    {
        return DB::transaction(function () use ($id, $data) {
            $tpl = PrescriptionTemplate::findOrFail($id);
            $tpl->update(array_filter([
                'name'        => $data['name'] ?? null,
                'category'    => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active'   => $data['is_active'] ?? null,
            ], fn ($v) => $v !== null));

            if (array_key_exists('items', $data)) {
                $tpl->items()->delete();
                $this->syncTemplateItems($tpl, $data['items']);
            }

            return $tpl->load('items.medication:id,name,form_sediaan,unit');
        });
    }

    /** Soft-delete paket obat (item ikut cascade saat hard-delete; di sini cukup template). */
    public function deletePrescriptionTemplate(string $id): void
    {
        $tpl = PrescriptionTemplate::findOrFail($id);
        $tpl->items()->delete();
        $tpl->delete();
    }

    /** Buat ulang baris item paket dari payload. */
    private function syncTemplateItems(PrescriptionTemplate $tpl, array $items): void
    {
        $order = 0;
        foreach ($items as $it) {
            if (empty($it['medication_id'])) {
                continue;
            }
            PrescriptionTemplateItem::create([
                'prescription_template_id' => $tpl->id,
                'medication_id'            => $it['medication_id'],
                'quantity'                 => $it['quantity'] ?? 1,
                'dose'                     => $it['dose'] ?? null,
                'frequency'                => $it['frequency'] ?? null,
                'route'                    => $it['route'] ?? null,
                'duration_days'            => $it['duration_days'] ?? null,
                'sort_order'               => $order++,
            ]);
        }
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

    /**
     * Deteksi operasi yang butuh IOL (Phaco/SICS/katarak) dari nama paket bedah.
     * Pola regex sama dengan deteksi isPhaco di FE (BedahView.vue).
     */
    private function needsIol(SurgerySchedule $schedule): bool
    {
        $name = (string) ($schedule->surgeryPackage->name ?? '');
        return (bool) preg_match('/phaco|katarak|cataract|\biol\b|sics|lensa intraokular/i', $name);
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

    // =========================================================================
    // KOMPONEN PAKET PASIEN (snapshot) — edit BHP & Tindakan saat operasi
    // =========================================================================

    /** Snapshot paket pasien + items (enrich nama). Null bila pasien tak punya paket. */
    /**
     * Daftar SEMUA snapshot paket pasien untuk satu visit (multi-paket, mis. Phaco +
     * TIVA). Mengembalikan array payload — FE merender satu kartu per paket.
     */
    public function getVisitPackages(string $visitId): array
    {
        return VisitSurgeryPackage::with('items')
            ->where('visit_id', $visitId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (VisitSurgeryPackage $snap) => $this->buildVisitPackagePayload($snap))
            ->all();
    }

    /** Payload satu snapshot paket pasien (header + komponen). */
    private function buildVisitPackagePayload(VisitSurgeryPackage $snap): array
    {
        $items = $snap->items->map(function (VisitSurgeryPackageItem $it) {
            $resolved = $it->resolveItem();
            return [
                'id'         => $it->id,
                'item_type'  => $it->item_type,
                'item_id'    => $it->item_id,
                'item_name'  => $resolved?->name ?? $resolved?->brand ?? '-',
                'item_code'  => $resolved?->code ?? null,
                // Kategori Buku Tarif untuk kolom JENIS (PROCEDURE→kategori prosedur,
                // BHP→BAHAN HABIS PAKAI/CSSD, IOL→IOL, OBAT→pos kwitansi).
                'category'    => $this->visitPackageItemCategory($it, $resolved),
                'pos_kwitansi'=> $it->pos_kwitansi,
                'quantity'   => $it->quantity,
                'unit_price' => (float) $it->unit_price,
                'subtotal'   => $it->subtotal(),
                'editable'   => in_array($it->item_type, VisitSurgeryPackageItem::EDITABLE_TYPES, true),
                'notes'      => $it->notes,
            ];
        })->values()->all();

        return [
            'id'                        => $snap->id,
            'source_surgery_package_id' => $snap->source_surgery_package_id,
            'package_name'              => $snap->package_name,
            'package_type'              => $snap->package_type,
            'sell_price'                => (float) $snap->sell_price,
            'total_base_price'          => (float) $snap->total_base_price,
            'discount_amount'           => $snap->discountAmount(),
            'label'                     => $snap->label,
            'items'                     => $items,
        ];
    }

    /**
     * Kategori "Buku Tarif" untuk satu komponen snapshot (kolom JENIS + pos obat).
     * Selaras KasirService (bhpBillingCategory / MedicationTariff::posLabel).
     */
    private function visitPackageItemCategory(VisitSurgeryPackageItem $it, $resolved): string
    {
        return match ($it->item_type) {
            VisitSurgeryPackageItem::TYPE_PROCEDURE  => $resolved?->category ?: 'Tindakan',
            VisitSurgeryPackageItem::TYPE_BHP        => BhpItem::billingCategoryLabel($resolved?->category),
            VisitSurgeryPackageItem::TYPE_IOL        => 'IOL',
            VisitSurgeryPackageItem::TYPE_MEDICATION => \App\Models\MedicationTariff::posLabel($it->pos_kwitansi ?: $this->medicationUmumPos($it->item_id)),
            default                                  => $it->item_type,
        };
    }

    /** Pos kwitansi default obat dari baris tarif UMUM (acuan saat pos komponen NULL). */
    private function medicationUmumPos(string $medicationId): ?string
    {
        $umumId = DB::table('insurers')->where('type', 'UMUM')->whereNull('deleted_at')->value('id');
        if (! $umumId) {
            return null;
        }
        return DB::table('medication_tariffs')
            ->where('medication_id', $medicationId)
            ->where('insurer_id', $umumId)
            ->whereNull('deleted_at')
            ->value('pos_kwitansi');
    }

    /** Guard: tolak edit komponen bila operasi sudah difinalisasi. */
    private function assertVisitPackageEditable(VisitSurgeryPackage $snap): void
    {
        $record = ($snap->surgerySchedule ?? $snap->visit?->surgerySchedule)?->surgeryRecord;
        if ($record && $record->finalized_at) {
            throw new \Exception('Operasi sudah difinalisasi — komponen paket tak bisa diubah.', 422);
        }
    }

    /**
     * Tambah komponen ke snapshot paket pasien (hanya PROCEDURE/BHP).
     * Multi-paket: paket sasaran ditentukan $data['visit_surgery_package_id'] bila
     * dikirim; fallback ke snapshot pertama visit (kompat alur 1 paket).
     */
    public function addVisitPackageItem(string $visitId, array $data): array
    {
        $snap = $this->resolveTargetSnapshot($visitId, $data['visit_surgery_package_id'] ?? null);
        $this->assertVisitPackageEditable($snap);

        $type = $data['item_type'];
        if (! in_array($type, VisitSurgeryPackageItem::EDITABLE_TYPES, true)) {
            throw new \Exception('Hanya komponen Tindakan / BHP / Obat yang dapat ditambah.', 422);
        }

        return DB::transaction(function () use ($snap, $data, $type) {
            $visit     = $snap->visit;
            $priceType = match ($type) {
                VisitSurgeryPackageItem::TYPE_PROCEDURE  => 'procedure',
                VisitSurgeryPackageItem::TYPE_MEDICATION => 'medication',
                default                                  => 'bhp',
            };
            $unitPrice = $data['unit_price']
                ?? app(KasirService::class)->getPrice($priceType, $data['item_id'], $visit->guarantor_type, $visit->insurer_id);

            // Pos kwitansi hanya relevan untuk OBAT (Obat Tindakan/Injeksi/Pulang).
            $pos = $type === VisitSurgeryPackageItem::TYPE_MEDICATION ? ($data['pos_kwitansi'] ?? null) : null;

            VisitSurgeryPackageItem::updateOrCreate(
                ['visit_surgery_package_id' => $snap->id, 'item_type' => $type, 'item_id' => $data['item_id']],
                ['quantity' => (int) ($data['quantity'] ?? 1), 'unit_price' => $unitPrice, 'pos_kwitansi' => $pos, 'notes' => $data['notes'] ?? null]
            );
            $snap->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'ADD_VISIT_PACKAGE_ITEM', VisitSurgeryPackage::class, $snap->id, "visit:{$snap->visit_id}");

            return $this->getVisitPackages($snap->visit_id);
        });
    }

    /**
     * Resolve snapshot paket sasaran. Bila $snapshotId diberikan → snapshot itu
     * (wajib milik visit). Bila null → snapshot pertama visit (kompat 1 paket).
     */
    private function resolveTargetSnapshot(string $visitId, ?string $snapshotId): VisitSurgeryPackage
    {
        $q = VisitSurgeryPackage::with('visit.surgerySchedule.surgeryRecord', 'surgerySchedule.surgeryRecord')
            ->where('visit_id', $visitId);
        if ($snapshotId) {
            $q->where('id', $snapshotId);
        } else {
            $q->orderBy('created_at');
        }
        return $q->firstOrFail();
    }

    /** Ubah qty/harga/notes komponen snapshot. */
    public function updateVisitPackageItem(string $itemId, array $data): array
    {
        $item = VisitSurgeryPackageItem::with('visitPackage.visit.surgerySchedule.surgeryRecord', 'visitPackage.surgerySchedule.surgeryRecord')
            ->findOrFail($itemId);
        $snap = $item->visitPackage;
        $this->assertVisitPackageEditable($snap);

        return DB::transaction(function () use ($item, $snap, $data) {
            // pos_kwitansi: hanya untuk OBAT; dikirim → terapkan apa adanya (termasuk null
            // untuk reset ke default master). array_key_exists agar null sah membatalkan pos.
            $patch = array_filter([
                'quantity'   => isset($data['quantity'])   ? (int) $data['quantity']   : null,
                'unit_price' => isset($data['unit_price']) ? (float) $data['unit_price'] : null,
                'notes'      => $data['notes'] ?? null,
            ], fn ($v) => $v !== null);
            if (array_key_exists('pos_kwitansi', $data) && $item->item_type === VisitSurgeryPackageItem::TYPE_MEDICATION) {
                $patch['pos_kwitansi'] = $data['pos_kwitansi'] ?: null;
            }
            $item->update($patch);
            $snap->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'UPDATE_VISIT_PACKAGE_ITEM', VisitSurgeryPackageItem::class, $item->id);

            return $this->getVisitPackages($snap->visit_id);
        });
    }

    /** Hapus komponen snapshot (hanya PROCEDURE/BHP). */
    public function removeVisitPackageItem(string $itemId): array
    {
        $item = VisitSurgeryPackageItem::with('visitPackage.visit.surgerySchedule.surgeryRecord', 'visitPackage.surgerySchedule.surgeryRecord')
            ->findOrFail($itemId);
        $snap = $item->visitPackage;
        $this->assertVisitPackageEditable($snap);

        if (! in_array($item->item_type, VisitSurgeryPackageItem::EDITABLE_TYPES, true)) {
            throw new \Exception('Komponen ini tidak dapat dihapus dari modul Bedah.', 422);
        }

        $visitId = $snap->visit_id;
        return DB::transaction(function () use ($item, $snap, $visitId) {
            $item->delete();
            $snap->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'REMOVE_VISIT_PACKAGE_ITEM', VisitSurgeryPackageItem::class, $item->id);

            return $this->getVisitPackages($visitId);
        });
    }

    /**
     * Tambah PAKET ke visit (multi-paket, mis. paket anestesi TIVA di samping paket
     * tindakan Phaco). Membuat snapshot baru via DokterService::syncVisitPackageSnapshot
     * (copy komponen + resolve harga Buku Tarif). Anti-dobel: unique
     * vsp_visit_source_unique → paket master yang sama tak ter-snapshot 2×.
     */
    public function addVisitPackage(string $visitId, string $packageId, ?string $tariffId = null): array
    {
        $visit = Visit::with('surgerySchedule.surgeryRecord')->findOrFail($visitId);

        if ($visit->surgerySchedule?->surgeryRecord?->finalized_at) {
            throw new \Exception('Operasi sudah difinalisasi — paket tak bisa ditambah.', 422);
        }

        // Sudah ter-snapshot (termasuk trashed yang akan di-restore sync) → tolak ramah.
        $exists = VisitSurgeryPackage::where('visit_id', $visitId)
            ->where('source_surgery_package_id', $packageId)
            ->exists();
        if ($exists) {
            throw new \Exception('Paket ini sudah ditambahkan ke pasien.', 422);
        }

        return DB::transaction(function () use ($visit, $packageId, $tariffId) {
            // clearType=null: jangan hapus paket lain saat menambah paket baru.
            // $tariffId = varian harga terpilih (1 penjamin bisa >1 varian).
            app(DokterService::class)->syncVisitPackageSnapshot($visit, $packageId, $visit->surgery_schedule_id, null, $tariffId);
            $this->log(auth('api')->id(), 'ADD_VISIT_PACKAGE', VisitSurgeryPackage::class, $visit->id, "visit:{$visit->id} pkg:{$packageId}");

            return $this->getVisitPackages($visit->id);
        });
    }

    /** Hapus satu PAKET dari visit (soft-delete snapshot + komponennya). */
    public function removeVisitPackage(string $snapshotId): array
    {
        $snap = VisitSurgeryPackage::with('visit.surgerySchedule.surgeryRecord', 'surgerySchedule.surgeryRecord')
            ->findOrFail($snapshotId);
        $this->assertVisitPackageEditable($snap);

        $visitId = $snap->visit_id;
        return DB::transaction(function () use ($snap, $visitId) {
            $snap->items()->delete();
            $snap->delete();
            $this->log(auth('api')->id(), 'REMOVE_VISIT_PACKAGE', VisitSurgeryPackage::class, $snap->id, "visit:{$visitId}");

            return $this->getVisitPackages($visitId);
        });
    }
}
