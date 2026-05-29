<?php

namespace App\Services;

use App\Events\AdmisiQueueUpdated;
use App\Events\AntreanTvUpdated;
use App\Events\TriaseQueueUpdated;
use App\Models\DiagnosticOrder;
use App\Models\Prescription;
use App\Models\Queue;
use App\Models\SurgerySchedule;
use App\Models\SystemLog;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sentral logic antrian — referensi: ARCHITECTURE.md Section 11 (Service Flow).
 *
 * Alur:  A → (TRIASE & REFRAKSIONIS paralel) → D → (P? / B?) → K → F → SELESAI
 *
 * Setiap "selesaiAntrian" memanggil advanceFromStation() yang:
 *   1. Tandai baris queues sekarang -> COMPLETED
 *   2. Tentukan station berikutnya (lihat resolveNextStation)
 *   3. Enqueue baris baru di station tujuan (atau set visit.current_station=SELESAI)
 *   4. Broadcast event Reverb yang relevan
 *
 * resolveNextStation kontrak:
 *   string         → enqueue baris baru di station itu
 *   array<string>  → enqueue multi paralel
 *   END_OF_FLOW    → pasien pulang (current_station=SELESAI)
 *   NO_OP          → tutup queue saja, no enqueue, current_station tetap (mis. partner TR sudah trigger next)
 *   null           → gate belum passed, antrian tidak boleh ditutup (throw)
 */
class QueueService
{
    public const END_OF_FLOW = '__END__';
    public const NO_OP       = '__NOOP__';

    public function __construct(private readonly Request $request) {}

    // =========================================================================
    // GENERATE NUMBER (thread-safe per station per hari)
    // =========================================================================

    /**
     * Generate nomor antrian.
     *
     * Untuk station DOKTER, prefix bisa dynamic berdasarkan nomor ruangan:
     *   room="1" → prefix="D1", queue_number="D1-001"
     *   room="2" → prefix="D2", queue_number="D2-001"
     *   room=null → prefix="D",  queue_number="D-001"  (fallback)
     *
     * Sequence dihitung PER PREFIX per hari, sehingga D1 dan D2
     * punya counter terpisah (D1-001 dan D2-001 bisa exist bersamaan).
     */
    public function generateQueueNumber(string $station, ?string $room = null): array
    {
        if ($station === Queue::STATION_DOKTER && $room !== null && $room !== '') {
            $prefix = 'D' . $room;

            $lastSeq = Queue::where('station', Queue::STATION_DOKTER)
                ->where('queue_prefix', $prefix)
                ->whereDate('created_at', today())
                ->max('queue_sequence') ?? 0;

            $sequence = $lastSeq + 1;
        } else {
            $prefix = Queue::prefixFor($station);

            // Stasiun yang share prefix (mis. TRIASE + REFRAKSIONIS = "TR")
            // perlu sequence yang juga shared supaya nomor tidak collision.
            $stationsForSeq = Queue::SHARED_PREFIX_GROUPS[$prefix] ?? [$station];

            $lastSeq = Queue::whereIn('station', $stationsForSeq)
                ->whereDate('created_at', today())
                ->max('queue_sequence') ?? 0;

            $sequence = $lastSeq + 1;
        }

        return [
            'queue_prefix'   => $prefix,
            'queue_sequence' => $sequence,
            'queue_number'   => $prefix . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
        ];
    }

    // =========================================================================
    // CORE ACTIONS
    // =========================================================================

    /**
     * Buat antrian baru di station tertentu untuk sebuah visit.
     * Broadcast `added` ke channel `antrean-tv` agar TV langsung lihat row baru.
     *
     * Untuk station DOKTER, room diambil otomatis dari visit.doctorSchedule.room
     * sehingga queue_number menjadi "D{room}-xxx".
     *
     * @param  array|null  $sharedNumber  Optional: pakai nomor pre-generated
     *                                    (untuk pair TRIASE+REFRAKSIONIS yg share TR-NNN)
     */
    public function enqueue(string $visitId, string $station, ?array $sharedNumber = null): Queue
    {
        $this->assertStation($station);

        if ($sharedNumber) {
            $data = $sharedNumber;
        } elseif ($station === Queue::STATION_DOKTER) {
            // Ambil ruangan dokter yang di-assign saat admisi
            $visit = \App\Models\Visit::with('doctorSchedule')->find($visitId);
            $room  = $visit?->doctorSchedule?->room;
            $data  = $this->generateQueueNumber($station, $room);
        } else {
            $data = $this->generateQueueNumber($station);
        }

        $queue = Queue::create([
            'visit_id'       => $visitId,
            'station'        => $station,
            'queue_prefix'   => $data['queue_prefix'],
            'queue_sequence' => $data['queue_sequence'],
            'queue_number'   => $data['queue_number'],
            'status'         => Queue::STATUS_WAITING,
        ]);

        $this->broadcastQueueUpdate($queue->fresh(['visit.patient']), 'added');

        return $queue;
    }

    /**
     * Data tiket Dokter (D-NNN) untuk sebuah visit — dipakai stasiun TR untuk
     * mencetak tiket lanjutan setelah gate Triase+Refraksionis lolos & antrian
     * DOKTER otomatis dibuat (lihat checkReadyForDoctor di Perawat/RefraksiService).
     * Return null kalau antrian DOKTER belum ada (partner TR belum finalize).
     */
    public function getDoctorTicket(string $visitId): ?array
    {
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->where('visit_id', $visitId)
            ->whereDate('created_at', today())
            ->latest('created_at')
            ->first();

        if (! $queue) {
            return null;
        }

        $sched = Visit::with('doctorSchedule.employee')->find($visitId)?->doctorSchedule;

        return [
            'queue_number' => $queue->queue_number,
            'poliklinik'   => $sched?->poliklinik,
            'room'         => $sched?->room,
            'doctor_name'  => $sched?->employee?->name,
        ];
    }

    /**
     * Panggil antrian:
     *   - WAITING     → CALLED (panggilan awal)
     *   - CALLED      → CALLED (panggil ulang, pasien belum hadir/menjawab)
     *   - IN_PROGRESS → IN_PROGRESS (panggil ulang saat pasien sedang diperiksa,
     *                   mis. perlu kembali dari ruang ganti). Status tetap, hanya
     *                   `called_at` di-refresh + broadcast ulang ke speaker/TV.
     * COMPLETED/CANCELLED ditolak.
     */
    public function panggil(string $queueId): Queue
    {
        $queue = Queue::findOrFail($queueId);

        // SELESAI_PENUNJANG (& DI_PENUNJANG) tetap bisa dipanggil — dokter melanjutkan pemeriksaan.
        $callable = array_merge(Queue::ACTIVE_STATUSES, [Queue::STATUS_AT_PENUNJANG, Queue::STATUS_PENUNJANG_DONE]);
        if (! in_array($queue->status, $callable, true)) {
            throw new \Exception('Antrian sudah selesai atau dibatalkan — tidak bisa dipanggil.', 422);
        }

        $queue->update([
            'status'    => $queue->status === Queue::STATUS_IN_PROGRESS
                ? Queue::STATUS_IN_PROGRESS   // pertahankan IN_PROGRESS saat re-call
                : Queue::STATUS_CALLED,
            'called_at' => now(),
        ]);

        $this->broadcastQueueUpdate($queue->fresh(['visit.patient']));

        return $queue->fresh(['visit.patient']);
    }

    /**
     * Mulai layanan: CALLED -> IN_PROGRESS.
     */
    public function mulai(string $queueId): Queue
    {
        $queue = Queue::findOrFail($queueId);

        if ($queue->status !== Queue::STATUS_CALLED) {
            throw new \Exception('Panggil pasien terlebih dahulu.', 422);
        }

        $queue->update([
            'status'     => Queue::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        $this->broadcastQueueUpdate($queue->fresh(['visit.patient']));

        return $queue->fresh(['visit.patient']);
    }

    /**
     * Lewati / skip pasien: pindahkan ke akhir antrean station yang sama.
     */
    public function lewati(string $queueId): Queue
    {
        $queue = Queue::whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED])
            ->findOrFail($queueId);

        $maxSeq = Queue::byStation($queue->station)
            ->whereDate('created_at', today())
            ->max('queue_sequence') ?? 0;

        $queue->update([
            'queue_sequence' => $maxSeq + 1,
            'status'         => Queue::STATUS_WAITING,
            'called_at'      => null,
        ]);

        $this->broadcastQueueUpdate($queue->fresh(['visit.patient']));

        return $queue->fresh(['visit.patient']);
    }

    /**
     * Batal: status -> CANCELLED. Tidak buat antrian baru.
     */
    public function batal(string $queueId, ?string $reason = null): Queue
    {
        $queue = Queue::findOrFail($queueId);

        $queue->update([
            'status'       => Queue::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);

        $this->log(auth('api')->id(), 'CANCEL_ANTRIAN', Queue::class, $queueId, $reason);
        $this->broadcastQueueUpdate($queue->fresh(['visit.patient']));

        return $queue->fresh(['visit.patient']);
    }

    // =========================================================================
    // ADVANCE (selesaiAntrian per station) — Section 11.3
    // =========================================================================

    /**
     * Tandai antrian COMPLETED + buat antrian baru di station berikutnya
     * berdasarkan aturan transisi Section 11.3.
     *
     * @return array{queue: Queue, visit: Visit, next_station: ?string, next_queue: ?Queue}
     */
    public function advanceFromStation(string $queueId, string $expectedStation): array
    {
        return DB::transaction(function () use ($queueId, $expectedStation) {
            $queue = Queue::with('visit')->findOrFail($queueId);

            if ($queue->station !== $expectedStation) {
                throw new \Exception(
                    "Station antrian tidak cocok: expected={$expectedStation}, actual={$queue->station}.",
                    422
                );
            }

            if ($queue->status === Queue::STATUS_COMPLETED) {
                throw new \Exception('Antrian sudah ditutup.', 422);
            }

            // 1. Resolve next station SEBELUM close — bisa throw kalau gate belum passed
            $visit       = $queue->visit;
            $nextStation = $this->resolveNextStation($visit, $expectedStation);

            if ($nextStation === null) {
                throw new \Exception(
                    "Antrian {$expectedStation} belum bisa diselesaikan — sub-task belum lengkap.",
                    422
                );
            }

            // 2. Close current queue
            $queue->update([
                'status'       => Queue::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            // 3. Enqueue next / END_OF_FLOW
            $nextQueue       = null;
            $nextStationName = null;

            if ($nextStation === self::END_OF_FLOW) {
                $visit->update(['current_station' => 'SELESAI']);
                $nextStationName = 'SELESAI';
            } elseif ($nextStation === self::NO_OP) {
                $nextStationName = 'NO_OP';
            } elseif (is_array($nextStation)) {
                // Cek apakah semua station di array share prefix yang sama
                // (mis. [TRIASE, REFRAKSIONIS] → keduanya prefix "TR"). Kalau iya,
                // generate SATU nomor antrean dan terapkan ke semua baris.
                $prefixes = array_unique(array_map(fn ($s) => Queue::prefixFor($s), $nextStation));
                $shared   = count($prefixes) === 1 ? $this->generateQueueNumber($nextStation[0]) : null;

                foreach ($nextStation as $st) {
                    $this->enqueue($visit->id, $st, $shared);
                }

                $visit->update(['current_station' => $nextStation[0]]);
                $nextStationName = implode('+', $nextStation);
            } else {
                $nextQueue = $this->enqueue($visit->id, $nextStation);
                $visit->update(['current_station' => $nextStation]);
                $nextStationName = $nextStation;
            }

            $this->log(
                auth('api')->id(),
                'ADVANCE_QUEUE',
                Visit::class,
                $visit->id,
                "Selesai {$expectedStation} → {$nextStationName}"
            );

            $this->broadcastQueueUpdate($queue->fresh(['visit.patient']));

            return [
                'queue'        => $queue->fresh(['visit.patient']),
                'visit'        => $visit->fresh(['patient', 'queues']),
                'next_station' => $nextStationName,
                'next_queue'   => $nextQueue,
            ];
        });
    }

    /**
     * Tentukan station berikutnya berdasarkan kondisi visit + aturan Section 11.3.
     *
     * Return: string | array | null
     *   - string  : single next station
     *   - array   : multi parallel (mis. [TRIASE, REFRAKSIONIS])
     *   - null    : pasien pulang (SELESAI)
     */
    public function resolveNextStation(Visit $visit, string $fromStation): string|array|null
    {
        return match ($fromStation) {
            Queue::STATION_ADMISI       => [Queue::STATION_TRIASE, Queue::STATION_REFRAKSIONIS],
            Queue::STATION_TRIASE       => $this->nextAfterTriaseOrRefraksi($visit),
            Queue::STATION_REFRAKSIONIS => $this->nextAfterTriaseOrRefraksi($visit),
            Queue::STATION_DOKTER       => $this->nextAfterDokter($visit),
            Queue::STATION_PENUNJANG    => $this->nextAfterPenunjang($visit), // balik ke dokter (anti-duplikat)
            Queue::STATION_BEDAH        => Queue::STATION_KASIR,
            Queue::STATION_KASIR        => $this->nextAfterKasir($visit),
            Queue::STATION_FARMASI      => self::END_OF_FLOW, // pasien pulang
            default                     => null,
        };
    }

    /**
     * TRIASE atau REFRAKSIONIS selesai → DOKTER hanya jika keduanya sudah finalize.
     * Jika belum, tetap di station yg sama (return null = jangan enqueue baru).
     *
     * Khusus visit PREOP_BEDAH: gate paralel sama (TR+REF wajib selesai), tapi
     * transisi ke BEDAH dilakukan eksplisit lewat PerawatService::kirimKeBedah()
     * (tombol manual). Auto-advance dimatikan untuk mencegah pasien tidak sengaja
     * didorong ke bedah hanya karena finalize asesmen.
     */
    private function nextAfterTriaseOrRefraksi(Visit $visit): ?string
    {
        $visit->loadMissing(['nurseAssessment', 'refractionRecord']);

        $triaseDone   = (bool) $visit->nurseAssessment?->is_finalized;
        $refraksiDone = (bool) $visit->refractionRecord?->is_finalized;

        if (! $triaseDone || ! $refraksiDone) {
            // Gate belum passed — antrian tidak boleh ditutup
            return null;
        }

        // PREOP_BEDAH: jangan auto-advance — tunggu tombol "Kirim ke Bedah".
        // Sub-task lain (mis. refraksi finalize duluan) tetap NO_OP saat partner
        // sudah selesai supaya antrean asal boleh ditutup.
        if ($visit->visit_type === 'PREOP_BEDAH') {
            return self::NO_OP;
        }

        // Partner (sub-task lain) sudah trigger transisi ke DOKTER → tutup saja, no-op
        $alreadyQueued = Queue::byStation(Queue::STATION_DOKTER)
            ->where('visit_id', $visit->id)
            ->today()
            ->exists();

        return $alreadyQueued ? self::NO_OP : Queue::STATION_DOKTER;
    }

    /**
     * DOKTER selesai → urutan prioritas:
     *   1. Ada DiagnosticOrder REQUESTED/IN_PROGRESS → PENUNJANG
     *   2. Planning BEDAH dengan jadwal operasi HARI INI → BEDAH
     *      (planning BEDAH dengan jadwal hari lain → tetap lanjut ke KASIR;
     *       saat hari operasi tiba pasien daftar ulang dari ADMISI)
     *   3. Else → KASIR
     */
    private function nextAfterDokter(Visit $visit): string
    {
        // 1. Order penunjang yang masih open?
        $hasOpenOrder = DiagnosticOrder::where('visit_id', $visit->id)
            ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
            ->exists();

        if ($hasOpenOrder) {
            return Queue::STATION_PENUNJANG;
        }

        // 2. Surgery schedule HARI INI?
        $visit->loadMissing('doctorExamination');
        $exam = $visit->doctorExamination;

        if ($exam && $exam->planning === 'BEDAH' && $exam->surgery_schedule_id) {
            $isToday = SurgerySchedule::where('id', $exam->surgery_schedule_id)
                ->whereDate('scheduled_date', today())
                ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
                ->exists();

            if ($isToday) {
                return Queue::STATION_BEDAH;
            }
            // Jadwal hari lain → pasien pulang via KASIR & FARMASI, kembali di hari operasi.
        }

        // 3. Default
        return Queue::STATION_KASIR;
    }

    /**
     * PENUNJANG selesai → balik ke DOKTER. Tapi bila baris DOKTER pasien masih hidup
     * (di-pause saat dikirim ke penunjang), jangan buat baris baru — PenunjangService
     * ::requeueToDokter yang menaikkannya kembali. NO_OP mencegah duplikat.
     */
    private function nextAfterPenunjang(Visit $visit): string
    {
        $hasLiveDokter = Queue::byStation(Queue::STATION_DOKTER)
            ->where('visit_id', $visit->id)
            ->today()
            ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
            ->exists();

        return $hasLiveDokter ? self::NO_OP : Queue::STATION_DOKTER;
    }

    /**
     * KASIR selesai → FARMASI jika ada resep, else SELESAI.
     */
    private function nextAfterKasir(Visit $visit): string
    {
        $hasPrescription = Prescription::where('visit_id', $visit->id)
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'DISPENSING'])
            ->exists();

        return $hasPrescription ? Queue::STATION_FARMASI : self::END_OF_FLOW;
    }

    // =========================================================================
    // QUERY HELPERS
    // =========================================================================

    public function getByStation(string $station): Collection
    {
        $this->assertStation($station);

        return Queue::with(['visit.patient'])
            ->byStation($station)
            ->today()
            ->orderBy('queue_sequence')
            ->get();
    }

    /**
     * Snapshot semua antrean aktif per station — untuk Antrean TV / Dashboard.
     */
    public function getAllActive(): array
    {
        $result = [];

        foreach (Queue::STATIONS as $station) {
            $rows = Queue::with(['visit.patient'])
                ->byStation($station)
                ->today()
                ->orderBy('queue_sequence')
                ->get();

            $called = $rows->firstWhere('status', Queue::STATUS_CALLED);
            $next   = $rows->firstWhere('status', Queue::STATUS_WAITING);

            $result[$station] = [
                'prefix'   => Queue::prefixFor($station),
                'total'    => $rows->count(),
                'waiting'  => $rows->where('status', Queue::STATUS_WAITING)->count(),
                'called'   => $called ? $this->formatLite($called) : null,
                'next'     => $next ? $this->formatLite($next) : null,
                'rows'     => $rows->map(fn ($r) => $this->formatLite($r))->values(),
            ];
        }

        return $result;
    }

    public function getStatus(string $queueId): Queue
    {
        return Queue::with(['visit.patient'])->findOrFail($queueId);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function assertStation(string $station): void
    {
        if (! in_array($station, Queue::STATIONS, true)) {
            throw new \Exception("Station tidak dikenal: {$station}.", 422);
        }
    }

    private function broadcastQueueUpdate(Queue $queue, string $action = 'updated'): void
    {
        $payload = [
            'id'             => $queue->id,
            'visit_id'       => $queue->visit_id,
            'station'        => $queue->station,
            'queue_number'   => $queue->queue_number,
            'queue_sequence' => $queue->queue_sequence,
            'status'         => $queue->status,
            'called_at'      => $queue->called_at?->toIso8601String(),
            'patient'        => $queue->visit?->patient ? [
                'no_rm' => $queue->visit->patient->no_rm,
                'name'  => $queue->visit->patient->name,
            ] : null,
        ];

        // Per-station channel — dipakai view modul masing-masing (AdmisiView, PerawatView, RefraksionisView)
        match ($queue->station) {
            Queue::STATION_ADMISI => broadcast(new AdmisiQueueUpdated($payload, $action))->toOthers(),
            Queue::STATION_TRIASE, Queue::STATION_REFRAKSIONIS
                => broadcast(new TriaseQueueUpdated($payload, $action))->toOthers(),
            default => null, // station lain (D/P/B/K/F) belum punya event station-specific
        };

        // Generic channel untuk Antrean TV — fire untuk SEMUA station termasuk
        // DOKTER, PENUNJANG, BEDAH, KASIR, FARMASI agar TV update real-time.
        broadcast(new AntreanTvUpdated($payload, $action))->toOthers();
    }

    private function formatLite(Queue $q): array
    {
        return [
            'id'             => $q->id,
            'queue_number'   => $q->queue_number,
            'queue_sequence' => $q->queue_sequence,
            'status'         => $q->status,
            'visit_id'       => $q->visit_id,
            'patient_name'   => $q->visit?->patient?->name,
            'no_rm'          => $q->visit?->patient?->no_rm,
            'called_at'      => $q->called_at?->toIso8601String(),
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
