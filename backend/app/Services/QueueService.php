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

        // Generate-nomor + create DALAM SATU TRANSAKSI dengan lock baris station hari
        // ini, supaya dua enqueue bersamaan tidak baca MAX(queue_sequence) yang sama →
        // nomor antrean kembar (tak ada unique constraint yang menangkapnya).
        $queue = DB::transaction(function () use ($visitId, $station, $sharedNumber) {
            // Kunci baris station+hari ini (range lock praktis) sebelum baca MAX.
            // sharedNumber (pasangan TR yg sudah pre-generated) tak perlu di-lock ulang.
            if (! $sharedNumber) {
                Queue::whereIn('station', Queue::SHARED_PREFIX_GROUPS[Queue::prefixFor($station)] ?? [$station])
                    ->whereDate('created_at', today())
                    ->lockForUpdate()
                    ->get(['id']);
            }

            if ($sharedNumber) {
                $data = $sharedNumber;
            } elseif ($station === Queue::STATION_DOKTER) {
                $visit = \App\Models\Visit::with('doctorSchedule')->find($visitId);
                $room  = $visit?->doctorSchedule?->room;
                $data  = $this->generateQueueNumber($station, $room);
            } else {
                $data = $this->generateQueueNumber($station);
            }

            return Queue::create([
                'visit_id'       => $visitId,
                'station'        => $station,
                'queue_prefix'   => $data['queue_prefix'],
                'queue_sequence' => $data['queue_sequence'],
                'queue_number'   => $data['queue_number'],
                'status'         => Queue::STATUS_WAITING,
            ]);
        });

        // Broadcast SETELAH commit supaya TV tak menerima ghost row bila transaksi rollback.
        DB::afterCommit(fn () => $this->broadcastQueueUpdate($queue->fresh(['visit.patient']), 'added'));

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
        // Tanpa filter tanggal: tiket DOKTER untuk pasien lintas-hari (antrian dibuat
        // kemarin) tetap bisa dicetak. Ambil baris DOKTER terbaru milik visit ini.
        $queue = Queue::byStation(Queue::STATION_DOKTER)
            ->where('visit_id', $visitId)
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

        $newStatus = $queue->status === Queue::STATUS_IN_PROGRESS
            ? Queue::STATUS_IN_PROGRESS   // pertahankan IN_PROGRESS saat re-call
            : Queue::STATUS_CALLED;

        // Mutual-exclusion stasiun paralel (Triase ↔ Refraksionis): pasien fisik hanya
        // bisa di SATU stasiun pada satu waktu. Cegah panggil-ganda — tolak bila stasiun
        // pasangan sedang memegang pasien (CALLED/IN_PROGRESS). Kunci baris visit (TOCTOU,
        // pola sama dgn checkReadyForDoctor) agar dua panggil nyaris bersamaan tak sama-sama
        // lolos. Re-call stasiun sendiri tak terpengaruh (cek hanya stasiun pasangan).
        $sibling = $this->parallelSibling($queue->station);
        if ($sibling !== null) {
            DB::transaction(function () use ($queue, $sibling, $newStatus) {
                Visit::where('id', $queue->visit_id)->lockForUpdate()->first();
                // Tanpa filter tanggal: pasien lintas-hari (boardVisible) bisa dipanggil
                // di stasiun pasangan yang baris-nya dibuat kemarin — guard harus tetap
                // mendeteksinya. Status CALLED/IN_PROGRESS sudah cukup membatasi.
                $held = Queue::where('visit_id', $queue->visit_id)
                    ->where('station', $sibling)
                    ->whereIn('status', [Queue::STATUS_CALLED, Queue::STATUS_IN_PROGRESS])
                    ->exists();
                if ($held) {
                    throw new \Exception(
                        'Pasien sedang ditangani di ' . $this->stationLabel($sibling)
                        . ' — selesaikan atau lewati di sana dulu.',
                        422
                    );
                }
                $queue->update(['status' => $newStatus, 'called_at' => now()]);
            });
        } else {
            $queue->update(['status' => $newStatus, 'called_at' => now()]);
        }

        $this->broadcastQueueUpdate($queue->fresh(['visit.patient']));

        // BPJS Antrol task 4 = mulai layan poli (pasien DIPANGGIL ke poli). Guard
        // monoton di reportTask memastikan hanya panggilan pertama yang terkirim
        // (re-call pasca-penunjang tak kirim ulang). Non-blocking.
        if ($queue->station === Queue::STATION_DOKTER) {
            $this->reportTask($queue->visit, 4);
        }

        return $queue->fresh(['visit.patient']);
    }

    /**
     * Stasiun paralel pasangan: 1 pasien fisik hanya boleh di salah satunya pada satu
     * waktu (Triase ↔ Refraksionis). null = stasiun bukan bagian pasangan paralel.
     */
    private function parallelSibling(string $station): ?string
    {
        return match ($station) {
            Queue::STATION_TRIASE       => Queue::STATION_REFRAKSIONIS,
            Queue::STATION_REFRAKSIONIS => Queue::STATION_TRIASE,
            default                     => null,
        };
    }

    private function stationLabel(string $station): string
    {
        return match ($station) {
            Queue::STATION_TRIASE       => 'Triase',
            Queue::STATION_REFRAKSIONIS => 'Refraksionis',
            default                     => $station,
        };
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
     * Lewati / skip pasien: turunkan SATU posisi — tukar urutan dengan pasien
     * berikutnya yang masih aktif (WAITING/CALLED) di station yang sama. Bila
     * pasien ini sudah paling bawah (tak ada yang aktif di bawahnya), tetap di
     * tempat (no-op posisi). Status selalu di-reset ke WAITING (kalau tadinya
     * CALLED, panggilannya dibatalkan).
     */
    public function lewati(string $queueId): Queue
    {
        return DB::transaction(function () use ($queueId) {
            $queue = Queue::whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED])
                ->lockForUpdate()
                ->findOrFail($queueId);

            // Pasien berikutnya = baris aktif dengan queue_sequence terkecil yang
            // masih lebih besar dari baris ini (kandidat layanan setelah ini).
            $next = Queue::byStation($queue->station)
                ->whereDate('created_at', today())
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED])
                ->where('id', '!=', $queue->id)
                ->where('queue_sequence', '>', $queue->queue_sequence)
                ->orderBy('queue_sequence')
                ->lockForUpdate()
                ->first();

            if ($next) {
                // Tukar urutan: pasien ini turun 1, pasien berikutnya naik 1.
                $thisSeq = $queue->queue_sequence;
                $nextSeq = $next->queue_sequence;
                $next->update(['queue_sequence' => $thisSeq]);
                $queue->update([
                    'queue_sequence' => $nextSeq,
                    'status'         => Queue::STATUS_WAITING,
                    'called_at'      => null,
                ]);
                $this->broadcastQueueUpdate($next->fresh(['visit.patient']));
            } else {
                // Sudah paling bawah — cuma reset status (batalkan panggilan bila CALLED).
                $queue->update([
                    'status'    => Queue::STATUS_WAITING,
                    'called_at' => null,
                ]);
            }

            $this->broadcastQueueUpdate($queue->fresh(['visit.patient']));

            return $queue->fresh(['visit.patient']);
        });
    }

    /**
     * Dahulukan: pindahkan baris ke PALING ATAS band aktif (WAITING/CALLED) station
     * hari ini — kebalikan lewati(). queue_sequence = min(aktif) - 1 (pola sama
     * dengan PenunjangService::requeueToDokter). Status di-reset ke WAITING (batalkan
     * panggilan bila CALLED) agar dipanggil ulang dari atas. No-op aman bila sudah
     * paling atas. Broadcast TV + channel station.
     */
    public function dahulukan(string $queueId): Queue
    {
        return DB::transaction(function () use ($queueId) {
            $queue = Queue::whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED])
                ->lockForUpdate()
                ->findOrFail($queueId);

            $minSeq = Queue::byStation($queue->station)
                ->whereDate('created_at', today())
                ->whereIn('status', [Queue::STATUS_WAITING, Queue::STATUS_CALLED])
                ->min('queue_sequence');

            // Sudah paling atas → cukup reset status (batalkan panggilan bila CALLED).
            $newSeq = ($minSeq === null || $queue->queue_sequence <= $minSeq)
                ? $queue->queue_sequence
                : $minSeq - 1;

            $queue->update([
                'queue_sequence' => $newSeq,
                'status'         => Queue::STATUS_WAITING,
                'called_at'      => null,
            ]);

            $this->broadcastQueueUpdate($queue->fresh(['visit.patient']));

            return $queue->fresh(['visit.patient']);
        });
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
     * $reportBpjs: bila false, lewati pelaporan updatewaktu otomatis di sini —
     * dipakai jalur kiosk (daftarkanWalkIn) yang melapor 1→2→3 berurutan sendiri
     * agar urutan taskid tidak terbalik (lihat reportAdmisiKioskTasks).
     *
     * @return array{queue: Queue, visit: Visit, next_station: ?string, next_queue: ?Queue}
     */
    public function advanceFromStation(string $queueId, string $expectedStation, bool $reportBpjs = true): array
    {
        $result = DB::transaction(function () use ($queueId, $expectedStation) {
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

        // Lapor waktu antrean ke BPJS SETELAH commit (post-transaction) — non-blocking:
        // kegagalan/timeout/credential-kosong tidak boleh membatalkan transisi lokal.
        // Dilewati bila caller akan melapor sendiri dgn urutan khusus (jalur kiosk).
        if ($reportBpjs) {
            $this->reportAntreanWaktu($result['visit'] ?? null, $expectedStation, $result['next_station'] ?? null);
        }

        return $result;
    }

    /**
     * Kirim updatewaktu ke BPJS Antrean berdasarkan PERPINDAHAN station.
     *
     * taskid RESMI BPJS Antrol (Docs/Antrol.md:339-347 — tiap task = SATU titik
     * "akhir X / mulai Y"):
     *   1 mulai tunggu admisi · 2 akhir tunggu admisi / mulai layan admisi
     *   3 akhir layan admisi / mulai tunggu poli
     *   4 akhir tunggu poli / mulai layan poli (pasien DIPANGGIL ke poli)
     *   5 akhir layan poli / mulai tunggu farmasi
     *   6 akhir tunggu farmasi / mulai layan farmasi (buat obat)
     *   7 akhir obat selesai dibuat · 99 batal
     *
     * PENTING: pemetaan ini dulu OFF-BY-ONE (3 utk mulai poli, dst). Diperbaiki agar
     * sesuai spec — BPJS memvalidasi makna & urutan task; salah-geser = laporan keliru.
     *
     * Yang dilapor dari transisi station di sini HANYA:
     *   - selesai ADMISI            → task 3 (akhir layan admisi / mulai tunggu poli)
     *   - selesai DOKTER → KASIR/FARMASI → task 5 (akhir layan poli / mulai tunggu farmasi)
     *
     * Task 4 (mulai layan poli) dilapor saat DOKTER MEMANGGIL pasien (lihat panggil()).
     * Task 6 & 7 (mulai/selesai buat obat) dilapor dari FarmasiService (dispensing).
     *
     * Selalu non-blocking — reportTask tak pernah melempar.
     */
    private function reportAntreanWaktu(?Visit $visit, string $fromStation, ?string $nextStation = null): void
    {
        // task saat MENINGGALKAN (akhir layan) station sumber.
        $finishMap = ['ADMISI' => 3, 'DOKTER' => 5];

        if (isset($finishMap[$fromStation])) {
            $this->reportTask($visit, $finishMap[$fromStation]);
        }
    }

    /**
     * Lapor antrean/add ke BPJS untuk kunjungan BPJS yang baru didaftarkan di loket.
     * Payload disusun AntrolBuilderService (null bila poli belum dipetakan / dokter
     * tanpa kode DPJP → skip diam-diam). Non-blocking, panggil SETELAH commit.
     */
    public function reportAntreanAdd(?Visit $visit): void
    {
        try {
            if (! $visit || $visit->guarantor_type !== 'BPJS') {
                return;
            }

            $antrean = app(\App\Services\BpjsAntreanService::class);
            if (! $antrean->isEnabled()) {
                return;
            }

            $payload = app(\App\Services\AntrolBuilderService::class)->buildAddPayload($visit);
            if ($payload === null) {
                return; // data belum cukup (mapping poli / kode DPJP) — bukan fatal
            }

            $antrean->addAntrean($payload, $visit->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BPJS antrean/add gagal: ' . $e->getMessage());
        }
    }

    /**
     * Lapor antrean/add + taskid 1→2→3 untuk pasien BPJS jalur KIOSK → loket admisi.
     *
     * Semantik resmi (Docs/Antrol.md:340-342):
     *   - taskid 1 = mulai waktu tunggu admisi (pasien ambil tiket kiosk ke loket)
     *   - taskid 2 = akhir tunggu admisi / mulai layan admisi (mulai didaftarkan)
     *   - taskid 3 = akhir layan admisi / mulai tunggu poli (selesai daftar)
     *
     * Karena ketiganya terjadi pada satu aksi petugas (daftarkanWalkIn), dilapor
     * sekaligus DI SINI dengan urutan benar — itulah kenapa advanceFromStation
     * dipanggil dgn reportBpjs=false (kalau tidak, task 3-nya keduluan & guard
     * monoton menolak 1 & 2). Urutan WAJIB add → 1 → 2 → 3: updatewaktu menargetkan
     * kodebooking yang sudah didaftarkan via antrean/add.
     *
     * Dipanggil SETELAH commit. Non-blocking total: skip diam-diam bila bukan BPJS /
     * ANTREAN nonaktif, dan reportTask sendiri tak pernah melempar.
     */
    public function reportAdmisiKioskTasks(?Visit $visit): void
    {
        if (! $visit || $visit->guarantor_type !== 'BPJS') {
            return;
        }

        // Pastikan antrean terdaftar di BPJS dulu (idempoten bila sudah pernah).
        $this->reportAntreanAdd($visit);

        // 1 = mulai tunggu admisi, 2 = mulai layan admisi, 3 = selesai layan admisi.
        $this->reportTask($visit, 1);
        $this->reportTask($visit, 2);
        $this->reportTask($visit, 3);
    }

    /**
     * Lapor antrean/farmasi/add ke BPJS (Sisi A) — daftarkan antrean farmasi RS.
     * Wajib bagi RS yang mengimplementasi antrean farmasi (Docs/Antrol.md:365).
     * Dipanggil saat petugas MULAI menyiapkan obat (startDispensing). Idempoten
     * di sisi BPJS ditangani BPJS; lokal cukup dipanggil sekali per resep.
     *
     * Non-blocking: skip diam-diam bila bukan BPJS / ANTREAN nonaktif / tanpa booking.
     */
    public function reportAntreanFarmasiAdd(?Visit $visit): void
    {
        try {
            if (! $visit || $visit->guarantor_type !== 'BPJS' || empty($visit->bpjs_booking_code)) {
                return;
            }

            $antrean = app(\App\Services\BpjsAntreanService::class);
            if (! $antrean->isEnabled()) {
                return;
            }

            $fq = Queue::byStation(Queue::STATION_FARMASI)
                ->where('visit_id', $visit->id)
                ->orderByDesc('created_at')
                ->first();
            if (! $fq) {
                return; // belum ada antrean farmasi fisik
            }

            $antrean->addAntreanFarmasi([
                'kodebooking'  => $visit->bpjs_booking_code,
                'jenisresep'   => app(\App\Services\AntrolMobileService::class)->resolveJenisResep($visit->id),
                'nomorantrean' => (string) $fq->queue_number,
                'keterangan'   => '',
            ], $visit->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BPJS antrean/farmasi/add gagal: ' . $e->getMessage());
        }
    }

    /**
     * Kirim satu taskid updatewaktu ke BPJS Antrean untuk kunjungan BPJS
     * yang punya kode booking. Dipakai oleh alur station (reportAntreanWaktu)
     * maupun Antrol Mobile JKN (B5 batal=99, B6 check-in=3).
     *
     * Guard monoton via visits.bpjs_last_taskid: BPJS menolak taskid yang sama
     * atau mundur. Pada alur mata yang bolak-balik DOKTER↔PENUNJANG, task 3/4
     * bisa terpicu berulang — kita skip kalan taskid <= yang terakhir terkirim
     * (kecuali 99 batal yang selalu boleh).
     *
     * Selalu non-blocking: kegagalan/credential-kosong tidak melempar.
     */
    public function reportTask(?Visit $visit, int $taskId): void
    {
        try {
            if (! $visit || $visit->guarantor_type !== 'BPJS' || empty($visit->bpjs_booking_code)) {
                return;
            }

            $antrean = app(\App\Services\BpjsAntreanService::class);
            if (! $antrean->isEnabled()) {
                return;
            }

            // Guard monoton: jangan kirim taskid yang sama/mundur (99 batal dikecualikan).
            $last = (int) ($visit->bpjs_last_taskid ?? 0);
            if ($taskId !== 99 && $taskId <= $last) {
                return;
            }

            $result = $antrean->updateWaktuAntrean([
                'kodebooking' => $visit->bpjs_booking_code,
                'taskid'      => $taskId,
                'waktu'       => (int) (microtime(true) * 1000),
            ], $visit->id);

            // Catat taskid terakhir HANYA bila BPJS menerima — agar retry mungkin.
            if (($result['is_success'] ?? false) && $taskId !== 99) {
                $visit->forceFill(['bpjs_last_taskid' => $taskId])->saveQuietly();
            }
        } catch (\Throwable $e) {
            // Diam-diam — sudah tercatat di bpjs_antrean_logs lewat service; jangan ganggu flow.
            \Illuminate\Support\Facades\Log::warning('BPJS updatewaktu gagal: ' . $e->getMessage());
        }
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
        // Cabang per jenis pelayanan SEBELUM match station. Alur rawat jalan (RAJAL)
        // dipindah PERSIS ke resolveNextRajal() agar zero-regression; RANAP punya
        // alur long-lived sendiri. IGD diaktifkan di fase akhir.
        return match ($visit->jenis_pelayanan ?? 'RAJAL') {
            'RANAP' => $this->resolveNextRanap($visit, $fromStation),
            // 'IGD' => $this->resolveNextIgd($visit, $fromStation), // diaktifkan di fase IGD (akhir)
            default => $this->resolveNextRajal($visit, $fromStation),
        };
    }

    /**
     * Alur RAWAT JALAN (RAJAL) — body existing dipindah persis dari resolveNextStation.
     * JANGAN ubah tanpa uji regresi A→TR→D→K→F→SELESAI.
     */
    private function resolveNextRajal(Visit $visit, string $fromStation): string|array|null
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
     * Alur RAWAT INAP (RANAP) — station RANAP long-lived (1 baris bertahan
     * berhari-hari). Visite/tindakan/obat = sub-aktivitas (menulis inpatient_charges),
     * BUKAN advanceFromStation. Transisi nyata hanya saat discharge.
     *
     *   RANAP    → KASIR        (HANYA jika discharge_at sudah terisi; else gate null)
     *   PENUNJANG→ RANAP/NO_OP  (pasien inap order penunjang → balik ke baris RANAP yg masih hidup)
     *   KASIR    → nextAfterKasir (reuse: FARMASI jika ada resep, else SELESAI)
     *   FARMASI  → SELESAI
     */
    private function resolveNextRanap(Visit $visit, string $fromStation): string|array|null
    {
        return match ($fromStation) {
            Queue::STATION_RANAP     => $visit->discharge_at
                ? Queue::STATION_KASIR
                : null, // belum discharge → tidak boleh tutup baris RANAP
            // PENUNJANG & BEDAH = sub-aktivitas pasien inap → balik ke baris RANAP
            // yang masih hidup (bed ditahan). Biaya masuk inpatient_charges, 1 invoice
            // saat discharge. BUKAN BEDAH→KASIR seperti rawat jalan.
            Queue::STATION_PENUNJANG => $this->returnToLiveRanap($visit),
            Queue::STATION_BEDAH     => $this->returnToLiveRanap($visit),
            Queue::STATION_KASIR     => $this->nextAfterKasir($visit),
            Queue::STATION_FARMASI   => self::END_OF_FLOW,
            default                  => null,
        };
    }

    /**
     * Sub-aktivitas RANAP (penunjang/bedah) selesai → balik ke baris RANAP yang
     * masih hidup (long-lived, bed ditahan). Jika baris RANAP masih ada → NO_OP
     * (jangan buat baris baru). Pasca-bedah, transfer ke HCU/ICU dilakukan terpisah
     * lewat RanapService::transferBed (keputusan petugas).
     */
    private function returnToLiveRanap(Visit $visit): string
    {
        $hasLiveRanap = Queue::byStation(Queue::STATION_RANAP)
            ->where('visit_id', $visit->id)
            ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
            ->exists();

        return $hasLiveRanap ? self::NO_OP : Queue::STATION_RANAP;
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

        // Partner (sub-task lain) sudah trigger transisi ke DOKTER → tutup saja, no-op.
        // Cek baris DOKTER yang masih AKTIF (bukan filter tanggal) supaya pasien
        // lintas-hari tak dibuatkan baris DOKTER ganda.
        $alreadyQueued = Queue::byStation(Queue::STATION_DOKTER)
            ->where('visit_id', $visit->id)
            ->active()
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

        // 2b. Fase 8 — Dokter putuskan RAWAT INAP (observasi/pemeriksaan tanpa operasi):
        //     tutup queue dokter TANPA enqueue otomatis. Pasien masuk papan "Menunggu
        //     Kamar"; petugas ranap memilih bed via RanapService::admit (yang baru
        //     meng-enqueue baris RANAP long-lived). inpatient_reason=OBSERVASI di-set
        //     DokterService::applyInpatientReason.
        if ($exam && $exam->planning === 'RAWAT_INAP') {
            $visit->update(['current_station' => 'MENUNGGU_RANAP']);
            return self::NO_OP;
        }

        // 2c. Planning BEDAH dgn jadwal HARI INI → BEDAH. BEDAH yang butuh inap (pre-op
        //     H-1, requires_inpatient) jadwalnya pasti hari lain (pasien datang H-1) →
        //     jatuh ke default KASIR (pulang dulu), lalu masuk via Admisi pre-op di
        //     hari H-1 (Fase 8B). Tidak perlu cabang khusus di sini.
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
        // Tanpa filter tanggal: baris DOKTER yang di-pause (DI_PENUNJANG) bisa berasal
        // dari hari sebelumnya untuk visit lintas-hari — harus tetap terdeteksi agar
        // tak membuat baris DOKTER ganda saat penunjang selesai.
        $hasLiveDokter = Queue::byStation(Queue::STATION_DOKTER)
            ->where('visit_id', $visit->id)
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
        if (! in_array($station, Queue::ALL_STATIONS, true)) {
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

        // Generic channel untuk Antrean TV — HANYA station papan TV (Queue::STATIONS).
        // RANAP & IGD long-lived sengaja DI-EXCLUDE: channel antrean-tv publik tak
        // berautentikasi, jadi nama+no_rm pasien rawat inap/IGD JANGAN dibroadcast ke
        // layar lobi. (Read path getAllActive/getByStation sudah pakai STATIONS.)
        if (in_array($queue->station, Queue::STATIONS, true)) {
            broadcast(new AntreanTvUpdated($payload, $action))->toOthers();
        }
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
