<?php

namespace App\Services;

use App\Models\ClinicProfile;
use App\Models\DoctorSchedule;
use App\Models\Employee;
use App\Models\IgdTriageRecord;
use App\Models\InpatientCharge;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Queue;
use App\Models\SurgeryPackage;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use App\Models\VisitSurgeryPackage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orkestrasi IGD (Instalasi Gawat Darurat). Thin-wrapper di atas QueueService —
 * routing antrean SELALU lewat QueueService (sumber tunggal, lihat memory
 * queue-advance-station-pattern).
 *
 * Model station IGD (keputusan user): 1 STASIUN GABUNG long-lived. 1 baris
 * queues station=IGD status=IN_PROGRESS bertahan = "kartu pasien di papan IGD".
 * Triase + tindakan + obat = sub-aktivitas (menulis inpatient_charges), BUKAN
 * advanceFromStation. Transisi nyata HANYA saat disposisi (igd_disposition di-set):
 *   - PULANG / RUJUK → advance IGD→KASIR (1 invoice INV-IGD)
 *   - RANAP          → set current_station='MENUNGGU_RANAP' (petugas ranap admit bed)
 *   - MENINGGAL      → tutup baris IGD (SELESAI), tanpa kasir
 *
 * Papan IGD diurut by `priority` (triase berlevel, makin kecil makin gawat),
 * BUKAN FIFO. IGD di-EXCLUDE dari Antrean TV (papan internal sendiri, seperti RANAP).
 */
class IgdService
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly KasirService $kasir,
        private readonly RanapService $ranap,
        private readonly AdmisiService $admisi,
    ) {}

    // Triase: warna → priority (makin kecil makin gawat).
    public const TRIAGE_PRIORITY = [
        'MERAH'  => 1, // resusitasi / immediate
        'KUNING' => 2, // urgent
        'HIJAU'  => 3, // non-urgent
        'HITAM'  => 4, // expectant / DOA
    ];

    // =========================================================================
    // QUERY (papan IGD, detail pasien, running bill)
    // =========================================================================

    /**
     * Papan IGD — pasien IGD aktif (current_station=IGD, belum disposisi).
     * Urut by priority (gawat dulu), lalu waktu kedatangan.
     */
    public function board(): array
    {
        // Baris queues IGD yang masih hidup (kartu pasien). Long-lived → TIDAK
        // pakai scope today() (pasien bisa bertahan lewat tengah malam).
        $rows = Queue::with(['visit.patient', 'visit.igdTriageRecord'])
            ->byStation(Queue::STATION_IGD)
            ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
            ->get();

        // Urut by efektif-prioritas lalu urutan kedatangan (queue_sequence).
        // priority: 1=MERAH .. 4=HITAM (makin kecil makin gawat). 0 = belum ditriase
        // → dianggap 2.5 (perlu dinilai segera, di antara KUNING & HIJAU).
        $sorted = $rows->sortBy(function (Queue $q) {
            $p = (int) $q->priority;
            $effective = $p === 0 ? 2.5 : $p;
            return sprintf('%04.1f-%012d', $effective, $q->queue_sequence);
        })->values();

        return $sorted->map(fn (Queue $q) => $this->formatBoardRow($q))->all();
    }

    private function formatBoardRow(Queue $q): array
    {
        $v = $q->visit;
        $t = $v?->igdTriageRecord;

        return [
            'queue_id'        => $q->id,
            'visit_id'        => $q->visit_id,
            'queue_number'    => $q->queue_number,
            'status'          => $q->status,
            'priority'        => (int) $q->priority,
            'name'            => $v?->patient?->name,
            'no_rm'           => $v?->patient?->no_rm,
            'gender'          => $v?->patient?->gender,
            'guarantor_type'  => $v?->guarantor_type,
            'igd_arrival_at'  => $v?->igd_arrival_at,
            'triase_level'    => $v?->triase_level,
            'triase_color'    => $v?->triase_color,
            'chief_complaint' => $t?->chief_complaint,
            'triaged_at'      => $t?->triaged_at,
        ];
    }

    /** Detail pasien IGD + triase + running bill (charges IGD memakai inpatient_charges). */
    public function detail(string $visitId): array
    {
        $visit = Visit::with([
            'patient', 'igdTriageRecord.triagedBy:id,name', 'inpatientCharges',
        ])->findOrFail($visitId);

        $charges = $visit->inpatientCharges;

        return [
            'visit'   => $visit,
            'triase'  => $visit->igdTriageRecord,
            'charges' => $charges,
            'running_bill' => [
                'total'  => (float) $charges->sum('total_price'),
                'billed' => (float) $charges->where('is_billed', true)->sum('total_price'),
            ],
        ];
    }

    // =========================================================================
    // PENDAFTARAN (walk-in gawat darurat) — tanpa jadwal dokter
    // =========================================================================

    /**
     * Daftarkan pasien IGD (walk-in darurat). Visit dibuat langsung dengan
     * jenis_pelayanan=IGD + current_station=IGD + enqueue baris IGD long-lived.
     * Tidak melalui ADMISI/TRIASE rawat jalan.
     *
     * @param  array  $data  patient_id, guarantor_type, insurer_id?, chief_complaint?,
     *                       triage_color?, triage_level? (boleh triase awal sekaligus),
     *                       arrival_at?
     */
    public function register(array $data): Visit
    {
        return DB::transaction(function () use ($data) {
            $patient = Patient::findOrFail($data['patient_id']);
            $arrival = ! empty($data['arrival_at']) ? Carbon::parse($data['arrival_at']) : now();

            $color = $data['triage_color'] ?? null;
            $priority = $color ? (self::TRIAGE_PRIORITY[$color] ?? 0) : 0;

            $visit = Visit::create([
                'patient_id'       => $patient->id,
                'insurer_id'       => $data['insurer_id'] ?? null,
                'registered_by_id' => auth('api')->user()?->employee_id,
                'no_registrasi'    => $this->generateNoRegistrasi(),
                'visit_date'       => today(),
                'classification'   => $data['classification'] ?? 'IGD',
                'jenis_pelayanan'  => 'IGD',
                'current_station'  => Queue::STATION_IGD,
                'guarantor_type'   => $data['guarantor_type'],
                'igd_arrival_at'   => $arrival,
                'triase_level'     => $data['triage_level'] ?? null,
                'triase_color'     => $color,
                'satusehat_sync_status' => 'PENDING',
            ]);

            // Catatan triase awal (boleh kosong dulu, dilengkapi via triase()).
            IgdTriageRecord::create([
                'visit_id'        => $visit->id,
                'triage_level'    => $data['triage_level'] ?? null,
                'triage_color'    => $color,
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'triaged_by_id'   => $color ? auth('api')->user()?->employee_id : null,
                'triaged_at'      => $color ? $arrival : null,
            ]);

            // Enqueue baris IGD long-lived (langsung IN_PROGRESS = kartu di papan),
            // set priority dari triase awal.
            $q = $this->queue->enqueue($visit->id, Queue::STATION_IGD);
            $q->update([
                'status'     => Queue::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'priority'   => $priority,
            ]);

            return $visit->fresh(['patient', 'igdTriageRecord']);
        });
    }

    /**
     * Daftar pasien BARU (belum ada di sistem) langsung dari IGD. Untuk gawat
     * darurat pasien sering belum terdaftar — buat rekam medis dulu (reuse
     * AdmisiService::storePasien, no_rm tergenerate), lalu register IGD.
     * Identitas boleh "TANPA_IDENTITAS" (NIK opsional) untuk pasien tak dikenal.
     *
     * @param  array  $data  field pasien (name/gender/date_of_birth wajib, nik dst opsional)
     *                       + field IGD (guarantor_type/chief_complaint/triage_color).
     */
    public function registerNewPatient(array $data): Visit
    {
        return DB::transaction(function () use ($data) {
            // 1. Buat pasien (reuse logika admisi: generate no_rm, simpan foto, log).
            $patient = $this->admisi->storePasien($data);

            // 2. Daftar IGD untuk pasien baru tsb.
            return $this->register(array_merge($data, ['patient_id' => $patient->id]));
        });
    }

    // =========================================================================
    // TRIASE BERLEVEL (set priority + warna + vital)
    // =========================================================================

    /**
     * Triase / re-triase pasien IGD. Set warna+level → priority baris queue
     * (papan otomatis re-sort). Vital signs + GCS opsional. Idempoten via
     * updateOrCreate igd_triage_records.
     *
     * @param  array  $data  triage_color (wajib), triage_level?, chief_complaint?,
     *                       td_sistol/td_diastol/nadi/suhu/respirasi/spo2?,
     *                       gcs_e/gcs_v/gcs_m?
     */
    public function triase(Visit $visit, array $data): IgdTriageRecord
    {
        return DB::transaction(function () use ($visit, $data) {
            $color = $data['triage_color'];
            $priority = self::TRIAGE_PRIORITY[$color] ?? 0;

            // String kosong dari form (v-model.number kosong → '') harus jadi null,
            // kalau tidak kolom decimal/integer gagal cast ("Unable to cast to decimal").
            $num = fn ($k) => (($data[$k] ?? null) === '' || ($data[$k] ?? null) === null) ? null : $data[$k];

            $record = IgdTriageRecord::updateOrCreate(
                ['visit_id' => $visit->id],
                [
                    'triage_level'    => ($data['triage_level'] ?? '') === '' ? null : $data['triage_level'],
                    'triage_color'    => $color,
                    'chief_complaint' => ($data['chief_complaint'] ?? '') === '' ? null : $data['chief_complaint'],
                    'td_sistol'       => $num('td_sistol'),
                    'td_diastol'      => $num('td_diastol'),
                    'nadi'            => $num('nadi'),
                    'suhu'            => $num('suhu'),
                    'respirasi'       => $num('respirasi'),
                    'spo2'            => $num('spo2'),
                    'gcs_e'           => $num('gcs_e'),
                    'gcs_v'           => $num('gcs_v'),
                    'gcs_m'           => $num('gcs_m'),
                    'triaged_by_id'   => auth('api')->user()?->employee_id,
                    'triaged_at'      => now(),
                ]
            );

            // Snapshot ke visits (display/SatuSehat) + re-sort papan via priority queue.
            $visit->update([
                'triase_level' => $data['triage_level'] ?? null,
                'triase_color' => $color,
            ]);

            Queue::byStation(Queue::STATION_IGD)
                ->where('visit_id', $visit->id)
                ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
                ->update(['priority' => $priority]);

            return $record->fresh('triagedBy');
        });
    }

    // =========================================================================
    // TINDAKAN / OBAT / CHARGE (reuse inpatient_charges — sama pola RANAP)
    // =========================================================================

    /** Daftar tindakan (procedures) + harga ter-resolve untuk penjamin pasien. */
    public function tarifTindakan(Visit $visit): array
    {
        return Procedure::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category'])
            ->map(fn ($p) => [
                'id'       => $p->id,
                'code'     => $p->code,
                'name'     => $p->name,
                'category' => $p->category,
                'price'    => $this->kasir->getPrice('procedure', $p->id, $visit->guarantor_type, $visit->insurer_id),
            ])
            ->all();
    }

    /** Daftar obat + harga ter-resolve untuk penjamin pasien. */
    public function daftarObat(Visit $visit, ?string $search = null): array
    {
        // Tampilkan SEMUA obat (selaras sumber Farmasi yg tak filter is_active); nonaktif ditandai.
        return Medication::query()
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%");
            }))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'code', 'name', 'unit', 'is_active'])
            ->map(fn ($m) => [
                'id'        => $m->id,
                'code'      => $m->code,
                'name'      => $m->name,
                'unit'      => $m->unit ?? null,
                'price'     => $this->kasir->getPrice('medication', $m->id, $visit->guarantor_type, $visit->insurer_id),
                'is_active' => (bool) $m->is_active,
            ])
            ->all();
    }

    /** Catat biaya berjalan IGD (tindakan/obat/lainnya) → inpatient_charges. */
    public function addCharge(Visit $visit, array $data): InpatientCharge
    {
        $qty   = (float) ($data['quantity'] ?? 1);
        $price = (float) ($data['unit_price'] ?? 0);

        return InpatientCharge::create([
            'visit_id'       => $visit->id,
            'charge_date'    => $data['charge_date'] ?? today(),
            'charge_type'    => $data['charge_type'] ?? InpatientCharge::TYPE_LAINNYA,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id'   => $data['reference_id'] ?? null,
            'description'    => $data['description'],
            'quantity'       => $qty,
            'unit_price'     => $price,
            'total_price'    => $qty * $price,
            'is_billed'      => false,
            'created_by_id'  => auth('api')->user()?->employee_id,
        ]);
    }

    /** Catat TINDAKAN IGD — harga resolve OTOMATIS via getPrice. */
    public function addTindakan(Visit $visit, string $procedureId, float $qty = 1): InpatientCharge
    {
        $proc  = Procedure::findOrFail($procedureId);
        $price = $this->kasir->getPrice('procedure', $procedureId, $visit->guarantor_type, $visit->insurer_id);

        return $this->addCharge($visit, [
            'charge_type'    => InpatientCharge::TYPE_TINDAKAN,
            'reference_type' => 'procedure',
            'reference_id'   => $proc->id,
            'description'    => $proc->name,
            'quantity'       => $qty,
            'unit_price'     => $price,
        ]);
    }

    /** Catat OBAT IGD — harga resolve OTOMATIS via getPrice. */
    public function addObat(Visit $visit, string $medicationId, float $qty = 1): InpatientCharge
    {
        $med   = Medication::findOrFail($medicationId);
        $price = $this->kasir->getPrice('medication', $medicationId, $visit->guarantor_type, $visit->insurer_id);

        return $this->addCharge($visit, [
            'charge_type'    => InpatientCharge::TYPE_OBAT,
            'reference_type' => 'medication',
            'reference_id'   => $med->id,
            'description'    => $med->name . ($med->unit ? " ({$med->unit})" : ''),
            'quantity'       => $qty,
            'unit_price'     => $price,
        ]);
    }

    /** Hapus charge IGD yang belum di-billing (koreksi input). */
    public function deleteCharge(Visit $visit, string $chargeId): void
    {
        $charge = InpatientCharge::where('visit_id', $visit->id)->findOrFail($chargeId);
        if ($charge->is_billed) {
            throw new \Exception('Biaya sudah masuk invoice — tidak bisa dihapus.', 422);
        }
        $charge->delete();
    }

    // =========================================================================
    // DISPOSISI (keputusan akhir IGD)
    // =========================================================================

    /**
     * Disposisi pasien IGD (keputusan dokter). 6 cabang (selaras regulasi gawat
     * darurat SNARS/STARKES ARK — kontinuitas pelayanan, tiap transfer terdokumentasi):
     *   PULANG / RUJUK → advance IGD→KASIR (1 invoice INV-IGD).
     *   RANAP          → set current_station='MENUNGGU_RANAP' (lihat papan RANAP;
     *                    petugas ranap admit bed → jenis_pelayanan jadi RANAP, SEP
     *                    inap baru diurus admin manual). Baris IGD ditutup COMPLETED.
     *   BEDAH          → jadwalkan operasi CITO (hari ini) + rutekan ke papan BEDAH.
     *                    jenis_pelayanan TETAP IGD → biaya IGD (inpatient_charges) +
     *                    paket bedah dikonsolidasi jadi SATU invoice di akhir (carry-forward).
     *   RAJAL          → encounter rawat jalan TERPISAH (IGD≠rajal): biaya IGD ditutup
     *                    ke KASIR, lalu kunjungan ANAK rawat jalan ke poli tujuan
     *                    (hari ini→DOKTER, lain hari→ADMISI). Pola rujukan internal.
     *   MENINGGAL      → tutup baris IGD (SELESAI), tanpa kasir.
     *
     * @param  string  $disposition  PULANG|RANAP|RUJUK|MENINGGAL|BEDAH|RAJAL
     * @param  array   $extra        field tambahan per-disposisi (BEDAH: surgery_package_id,
     *                               lead_surgeon_id, anesthesiologist_id, location_type,
     *                               scheduled_time, operation_room, surgery_package_tariff_id;
     *                               RAJAL: target_doctor_schedule_id, scheduled_date).
     */
    public function disposisi(Visit $visit, string $disposition, ?string $notes = null, array $extra = []): array
    {
        $igdQueue = Queue::byStation(Queue::STATION_IGD)
            ->where('visit_id', $visit->id)
            ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
            ->latest('created_at')
            ->firstOrFail();

        // Helper: set disposisi di visit + triage record (jejak) dalam 1 transaksi.
        $setDisposisi = function () use ($visit, $disposition, $notes) {
            $visit->update([
                'igd_disposition'   => $disposition,
                'discharge_summary' => $notes ?? $visit->discharge_summary,
            ]);
            $visit->igdTriageRecord?->update(['disposition' => $disposition]);
        };

        // Routing per jenis disposisi — disposisi di-set ATOMIK bersama aksi routing
        // (rollback bila gagal, tak ada state sesaat inkonsisten).
        if (in_array($disposition, ['PULANG', 'RUJUK'], true)) {
            // → KASIR lewat QueueService. Set disposisi DI DALAM transaksi yang sama
            // dgn advance: bila enqueue KASIR gagal, igd_disposition ikut rollback.
            $result = DB::transaction(function () use ($setDisposisi, $igdQueue) {
                $setDisposisi();
                return $this->queue->advanceFromStation($igdQueue->id, Queue::STATION_IGD);
            });

            // Lapor tgl pulang ke BPJS (non-blocking, di luar transaksi).
            $this->maybeUpdateTglPulangBpjs($visit->fresh());

            return $result;
        }

        if ($disposition === 'RANAP') {
            // Tutup baris IGD + arahkan ke papan "Menunggu Kamar". jenis_pelayanan
            // tetap IGD sampai petugas ranap admit (RanapService::admit ubah ke RANAP).
            return DB::transaction(function () use ($setDisposisi, $visit, $igdQueue) {
                $setDisposisi();
                $igdQueue->update([
                    'status'       => Queue::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
                $visit->update(['current_station' => 'MENUNGGU_RANAP']);

                return ['routed_to' => 'MENUNGGU_RANAP', 'next_station' => 'MENUNGGU_RANAP'];
            });
        }

        if ($disposition === 'BEDAH') {
            // Operasi CITO gawat darurat → jadwalkan operasi HARI INI + pindahkan kartu
            // pasien ke papan BEDAH. jenis_pelayanan TETAP 'IGD' → biaya IGD
            // (inpatient_charges) + paket bedah dikonsolidasi jadi SATU invoice di akhir
            // episode (carry-forward, konsisten alur RANAP→Bedah). Papan BEDAH me-resolve
            // pasien via visit.surgery_schedule_id (pola PREOP_BEDAH).
            return DB::transaction(function () use ($setDisposisi, $visit, $igdQueue, $extra, $notes) {
                $setDisposisi();

                $locationType = ($extra['location_type'] ?? null) === SurgerySchedule::LOCATION_RUANG_TINDAKAN
                    ? SurgerySchedule::LOCATION_RUANG_TINDAKAN
                    : SurgerySchedule::LOCATION_RUANG_BEDAH;
                $packageId = $extra['surgery_package_id'] ?? null;

                // Ruang OK default dari Profil Klinik (ambil pertama bila ada).
                $defaultRoom = ClinicProfile::query()->value('operating_rooms');
                $defaultRoom = is_array($defaultRoom) ? ($defaultRoom[0] ?? null) : null;

                $schedule = SurgerySchedule::create([
                    'surgery_package_id'  => $packageId,
                    'location_type'       => $locationType,
                    'lead_surgeon_id'     => $extra['lead_surgeon_id'] ?? null,
                    'anesthesiologist_id' => $extra['anesthesiologist_id'] ?? null,
                    'scheduled_date'      => today(),
                    'scheduled_time'      => $extra['scheduled_time'] ?? now('Asia/Jakarta')->format('H:i'),
                    'operation_room'      => $extra['operation_room'] ?? $defaultRoom,
                    'status'              => 'SCHEDULED',
                    'requires_inpatient'  => false,   // cito hari ini, bukan pre-op H-1
                    'notes'               => 'Operasi cito dari IGD' . ($notes ? ' — ' . $notes : ''),
                ]);

                // Propagasi ke visit → papan BEDAH me-resolve via surgery_schedule_id.
                $visit->update(['surgery_schedule_id' => $schedule->id]);

                // Snapshot paket bedah (dasar diskon + sumber tagih paket di kwitansi).
                // Hanya bila paket dipilih (RUANG_TINDAKAN/laser bisa tanpa paket — ditagih
                // via procedure). Reuse generik DokterService::syncVisitPackageSnapshot.
                if ($packageId) {
                    app(DokterService::class)->syncVisitPackageSnapshot(
                        $visit,
                        $packageId,
                        $schedule->id,
                        VisitSurgeryPackage::TYPE_BEDAH,
                        $extra['surgery_package_tariff_id'] ?? null
                    );
                }

                // Tutup baris IGD + enqueue BEDAH (kartu pasien pindah ke papan bedah;
                // RUANG_TINDAKAN otomatis tampil di papan Ruang Tindakan via filter lokasi).
                $igdQueue->update([
                    'status'       => Queue::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
                $this->queue->enqueue($visit->id, Queue::STATION_BEDAH);
                $visit->update(['current_station' => Queue::STATION_BEDAH]);

                return [
                    'routed_to'           => 'BEDAH',
                    'next_station'        => Queue::STATION_BEDAH,
                    'surgery_schedule_id' => $schedule->id,
                ];
            });
        }

        if ($disposition === 'RAJAL') {
            // Rujuk ke poliklinik = encounter rawat jalan TERPISAH (kaidah SNARS/BPJS:
            // IGD-darurat ≠ rajal-rujukan, SEP berbeda). Encounter IGD diselesaikan ke
            // KASIR (biaya gawat darurat), lalu dibuat kunjungan ANAK rawat jalan ke poli
            // tujuan. Jejak rujukan internal (parent_visit_id + alasan) tersimpan utk RME.
            $targetScheduleId = $extra['target_doctor_schedule_id'] ?? null;
            if (! $targetScheduleId) {
                throw new \Exception('Poli/dokter tujuan wajib dipilih untuk rujukan rawat jalan.', 422);
            }
            $target = DoctorSchedule::with('employee')->findOrFail($targetScheduleId);

            // Tanggal kunjungan poli: default kemunculan berikutnya hari praktik dokter
            // tujuan (≥ hari ini). Bila dipilih eksplisit → validasi jatuh di hari praktik.
            $today = now('Asia/Jakarta')->startOfDay();
            $scheduledDate = $extra['scheduled_date'] ?? null;
            if ($scheduledDate) {
                $date = Carbon::parse($scheduledDate, 'Asia/Jakarta')->startOfDay();
                if ($date->lt($today)) {
                    throw new \Exception('Tanggal rujukan tidak boleh di masa lalu.', 422);
                }
                if ((int) $date->isoWeekday() !== (int) $target->day_of_week) {
                    throw new \Exception('Tanggal rujukan harus jatuh pada hari praktik dokter tujuan.', 422);
                }
            } else {
                $delta = ($target->day_of_week - $today->isoWeekday() + 7) % 7;
                $date  = $today->copy()->addDays($delta);
            }
            $isToday = $date->isSameDay($today);

            $result = DB::transaction(function () use ($setDisposisi, $visit, $igdQueue, $target, $date, $isToday, $notes) {
                $setDisposisi();

                // 1) Selesaikan encounter IGD → KASIR (invoice biaya gawat darurat).
                $advance = $this->queue->advanceFromStation($igdQueue->id, Queue::STATION_IGD);

                // 2) Kunjungan ANAK rawat jalan ke poli tujuan (pola rujukan internal).
                $child = Visit::create([
                    'parent_visit_id'               => $visit->id,
                    'patient_id'                    => $visit->patient_id,
                    'insurer_id'                    => $visit->insurer_id,
                    'registered_by_id'              => auth('api')->user()?->employee_id,
                    'doctor_schedule_id'            => $target->id,
                    'internal_referral_reason'      => $notes,
                    'no_registrasi'                 => $this->generateNoRegistrasi(),
                    'visit_date'                    => $date->toDateString(),
                    'classification'                => 'Rujukan IGD',
                    'visit_type'                    => 'REGULAR',
                    // Hari ini → langsung antrean DOKTER. Hari lain → penanda di ADMISI
                    // (petugas memunculkan ke antrean saat pasien datang di hari-H).
                    'current_station'               => $isToday ? Queue::STATION_DOKTER : Queue::STATION_ADMISI,
                    'guarantor_type'                => $visit->guarantor_type,
                    'satusehat_sync_status'         => 'PENDING',
                    'insurance_verification_status' => 'NONE',
                ]);
                if ($isToday) {
                    $this->queue->enqueue($child->id, Queue::STATION_DOKTER);
                }

                return [
                    'routed_to'      => 'KASIR',
                    'next_station'   => $advance['next_station'] ?? Queue::STATION_KASIR,
                    'child_visit_id' => $child->id,
                    'child_enqueued' => $isToday,
                ];
            });

            // Lapor tgl pulang IGD ke BPJS (non-blocking, di luar transaksi).
            $this->maybeUpdateTglPulangBpjs($visit->fresh());

            return $result;
        }

        // MENINGGAL → tutup baris IGD, pasien selesai (tanpa kasir).
        return DB::transaction(function () use ($setDisposisi, $visit, $igdQueue) {
            $setDisposisi();
            $igdQueue->update([
                'status'       => Queue::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
            $visit->update(['current_station' => 'SELESAI']);

            return ['routed_to' => 'SELESAI', 'next_station' => 'SELESAI'];
        });
    }

    /**
     * Opsi modal disposisi BEDAH (operasi cito): paket bedah (harga per-penjamin
     * pasien), operator (spesialis mata), anestesiologis, & lokasi. Operator boleh
     * dikosongkan di sini (ditetapkan saat operasi di papan Bedah) — selaras alur
     * penjadwalan dokter yang tak men-set lead_surgeon di awal.
     */
    public function bedahOptions(Visit $visit): array
    {
        $gt  = $visit->guarantor_type ?? 'UMUM';
        $ins = $visit->insurer_id;

        $packages = SurgeryPackage::where('is_active', true)
            ->where(fn ($q) => $q
                ->where('package_type', VisitSurgeryPackage::TYPE_BEDAH)
                ->orWhereNull('package_type'))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'package_type'])
            ->map(function (SurgeryPackage $p) use ($gt, $ins) {
                $t = $this->kasir->resolvePackageTariff($p->id, $gt, $ins);
                return [
                    'id'         => $p->id,
                    'name'       => $t?->display_name ?: $p->name,
                    'code'       => $p->code,
                    'sell_price' => $t ? (float) $t->sell_price : null,
                ];
            })->all();

        $operators = Employee::where('doctor_type', Employee::DT_SPESIALIS_MATA)
            ->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'profession'])->all();

        $anesthesiologists = Employee::where('doctor_type', Employee::DT_ANESTESI)
            ->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'profession'])->all();

        return [
            'packages'          => $packages,
            'operators'         => $operators,
            'anesthesiologists' => $anesthesiologists,
            'locations'         => [
                ['code' => SurgerySchedule::LOCATION_RUANG_BEDAH,    'label' => 'Ruang Bedah (Operasi)'],
                ['code' => SurgerySchedule::LOCATION_RUANG_TINDAKAN, 'label' => 'Ruang Tindakan (Laser)'],
            ],
        ];
    }

    /**
     * Daftar tujuan rujukan rawat jalan (disposisi RAJAL): jadwal dokter/poli aktif
     * minggu ini. Tiap baris menandai apakah dokter tujuan praktik HARI INI (langsung
     * antre DOKTER) atau praktik berikutnya (penanda di ADMISI). Pola sama
     * DokterService::getRujukInternalTargets tanpa pengecualian dokter (IGD tak punya
     * doctor_schedule_id).
     */
    public function rajalTargets(): array
    {
        $weekStart = DoctorSchedule::currentWeekStart();
        $todayDow  = (int) now('Asia/Jakarta')->isoWeekday();

        return DoctorSchedule::with('employee')
            ->forWeek($weekStart)
            ->where('is_active', true)
            ->orderBy('poliklinik')->orderBy('day_of_week')->orderBy('start_time')
            ->get()
            ->map(fn (DoctorSchedule $s) => [
                'schedule_id' => $s->id,
                'doctor_name' => $s->employee?->name,
                'poliklinik'  => $s->poliklinik,
                'poli_code'   => $s->poli_code,
                'day_of_week' => $s->day_of_week,
                'day_label'   => $this->dayLabel($s->day_of_week),
                'start_time'  => substr((string) $s->start_time, 0, 5),
                'end_time'    => substr((string) $s->end_time, 0, 5),
                'is_today'    => $s->day_of_week === $todayDow,
                'next_date'   => $this->nextDateForDow((int) $s->day_of_week),
            ])->values()->all();
    }

    /** Tanggal kemunculan berikutnya (≥ hari ini, WIB) dari sebuah hari praktik (ISO 1..7). */
    private function nextDateForDow(int $dow): string
    {
        $today = now('Asia/Jakarta')->startOfDay();
        $delta = ($dow - $today->isoWeekday() + 7) % 7;
        return $today->copy()->addDays($delta)->toDateString();
    }

    private function dayLabel(int $dow): string
    {
        return [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'][$dow] ?? '-';
    }

    // =========================================================================
    // CPPT (Catatan Perkembangan Pasien Terintegrasi) — delegasi ke mesin RANAP.
    // Pasien IGD yang diobservasi WAJIB CPPT (SNARS/STARKES). Tabel sama
    // (nurse_cppt_entries, visit-scoped, multi-PPA, verifikasi DPJP), jadi cukup
    // delegasi — TIDAK duplikasi logika. Riwayat lintas-episode tampil di RME.
    // =========================================================================

    /** Daftar CPPT pasien IGD (terbaru dulu). */
    public function cpptEntries(Visit $visit): array
    {
        return $this->ranap->cpptEntries($visit);
    }

    /** Tambah CPPT IGD (SOAP + TTV, peran PPA derive otomatis dari profesi). */
    public function addCppt(Visit $visit, array $data): \App\Models\NurseCpptEntry
    {
        return $this->ranap->addCppt($visit, $data);
    }

    /** Soft-edit CPPT IGD. */
    public function updateCppt(string $entryId, array $data): \App\Models\NurseCpptEntry
    {
        return $this->ranap->updateCppt($entryId, $data);
    }

    /** Verifikasi DPJP atas entri CPPT IGD. */
    public function verifyCppt(string $entryId): \App\Models\NurseCpptEntry
    {
        return $this->ranap->verifyCppt($entryId);
    }

    // =========================================================================
    // SEP IGD (BPJS) — terbit TERPISAH setelah dokter isi diagnosa awal.
    // Gawat darurat = pengecualian rujukan berjenjang (Permenkes 47/2018):
    // asalRujukan='2', tanpa rujukan FKTP, poli IGD, diagAwal WAJIB.
    // Delegasi ke AdmisiService::bpjsGenerateSep yang sudah IGD-aware.
    // =========================================================================

    /**
     * Info pra-SEP untuk panel IGD: status SEP, no kartu BPJS, diagnosa awal
     * (dari CPPT/triase) sebagai default usulan ICD-10.
     */
    public function sepInfo(Visit $visit): array
    {
        return [
            'visit_id'       => $visit->id,
            'guarantor_type' => $visit->guarantor_type,
            'no_sep'         => $visit->no_sep,
            'bpjs_number'    => $visit->patient?->bpjs_number,
            'has_sep'        => ! empty($visit->no_sep),
            'is_bpjs'        => $visit->guarantor_type === 'BPJS',
        ];
    }

    /**
     * Terbitkan SEP Gawat Darurat (IGD). WAJIB: penjamin BPJS + diagnosa awal
     * (ICD-10). SEP tidak dibuat saat register — baru di sini, setelah dokter
     * menilai gawat darurat & menentukan diagnosa.
     *
     * @param  array  $data  diag_awal (wajib), bpjs_number?, kode_dpjp?, no_rujukan?
     */
    public function generateSep(Visit $visit, array $data): array
    {
        if (($visit->guarantor_type ?? null) !== 'BPJS') {
            throw new \Exception('SEP hanya untuk pasien penjamin BPJS.', 422);
        }
        if (empty($data['diag_awal'])) {
            throw new \Exception('Diagnosa awal (ICD-10) wajib untuk menerbitkan SEP IGD.', 422);
        }

        // DPJP layan default = dokter jaga IGD yang menerbitkan SEP (user login),
        // bila tak dikirim eksplisit. Beberapa faskes VClaim mensyaratkan dpjpLayan.
        if (empty($data['kode_dpjp'])) {
            $dpjp = auth('api')->user()?->employee?->bpjs_dpjp_code;
            if ($dpjp) {
                $data['kode_dpjp'] = $dpjp;
            }
        }

        // Resolve KELAS HAK peserta dari VClaim (cek peserta) — supaya SEP IGD pakai
        // kelas hak yang benar, bukan default '3'. Non-blocking: gagal cek → biarkan
        // bpjsGenerateSep fallback (visit.kelas_rawat_hak / '3').
        if (empty($visit->kelas_rawat_hak) && empty($data['kls_rawat'])) {
            $kls = $this->resolveHakKelas($visit, $data['bpjs_number'] ?? null);
            if ($kls) {
                $data['kls_rawat'] = $kls;
            }
        }

        // Delegasi ke AdmisiService::bpjsGenerateSep (cabang IGD aktif via
        // visit.jenis_pelayanan='IGD'). Menyimpan visits.no_sep bila sukses.
        return $this->admisi->bpjsGenerateSep(array_merge($data, [
            'visit_id' => $visit->id,
        ]));
    }

    /**
     * Ambil kode kelas hak peserta BPJS via cek peserta VClaim (untuk SEP IGD).
     * Return '1'|'2'|'3' atau null bila gagal (caller fallback ke default).
     */
    private function resolveHakKelas(Visit $visit, ?string $bpjsNumber): ?string
    {
        $noKartu = $bpjsNumber ?: $visit->patient?->bpjs_number;
        if (empty($noKartu)) {
            return null;
        }

        try {
            $res  = app(\App\Services\BpjsVClaimService::class)->checkPeserta($noKartu, 'nokartu', '', $visit->id);
            $kode = $res['response']['peserta']['hakKelas']['kode'] ?? null;
            return $kode ? (string) $kode : null;
        } catch (\Throwable $e) {
            Log::warning('IGD resolveHakKelas gagal: ' . $e->getMessage(), ['visit_id' => $visit->id]);
            return null;
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Lapor tgl pulang ke BPJS untuk SEP IGD (non-blocking). Pola sama
     * RanapService::maybeUpdateTglPulangBpjs — hanya dipanggil bila BPJS + ada SEP.
     * Untuk PULANG/RUJUK belum tentu discharge_at terisi (IGD tak pakai discharge_at
     * yang sama dgn RANAP) → pakai now() sebagai tgl pulang IGD.
     */
    private function maybeUpdateTglPulangBpjs(Visit $visit): void
    {
        try {
            if (($visit->guarantor_type ?? null) !== 'BPJS' || empty($visit->no_sep)) {
                return;
            }

            $vclaim = app(\App\Services\BpjsVClaimService::class);
            if (! $vclaim->isEnabled()) {
                return;
            }

            $vclaim->updateTglPulang([
                'noSep'      => $visit->no_sep,
                'tglPulang'  => \Illuminate\Support\Carbon::parse($visit->discharge_at ?? now())
                    ->setTimezone('Asia/Jakarta')->toDateString(),
                'noLPManual' => '',
                'user'       => auth('api')->user()?->name ?? 'arumed',
            ], $visit->id);
        } catch (\Throwable $e) {
            Log::warning('IGD updateTglPulang BPJS gagal: ' . $e->getMessage(), ['visit_id' => $visit->id]);
        }
    }

    /**
     * No registrasi harian — format SAMA AdmisiService (REG-{Ymd}-{seq3}) supaya
     * counter harian konsisten lintas pintu masuk (admisi & IGD berbagi urutan).
     */
    private function generateNoRegistrasi(): string
    {
        $prefix = 'REG-' . today()->format('Ymd') . '-';

        $last = Visit::withTrashed()
            ->where('no_registrasi', 'like', $prefix . '%')
            ->orderByDesc('no_registrasi')
            ->value('no_registrasi');

        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
