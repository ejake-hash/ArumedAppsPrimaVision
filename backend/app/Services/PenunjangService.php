<?php

namespace App\Services;

use App\Models\DiagnosticOrder;
use App\Models\DiagnosticTestType;
use App\Models\DiagnosticResult;
use App\Models\IolRecommendation;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PenunjangIngestInbox;
use App\Models\Queue;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\Visit;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PenunjangService
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
        $queues = Queue::with(['visit.patient', 'visit.diagnosticOrders'])
            ->where('station', 'PENUNJANG')
            ->boardVisible()   // hari ini ATAU masih aktif lintas-hari (≤7 hari) — pasien nyangkut tak hilang
            ->whereHas('visit')   // exclude zombie row (visit soft-deleted)
            ->orderBy('queue_sequence')
            ->get();

        // Lampirkan NAMA pemeriksaan (master diagnostic_test_types) ke tiap order agar
        // panel "Daftar Order dari Dokter" menampilkan nama, bukan kode (BIOM/PNJ-015).
        // FE sudah pakai `o.test_name ?? o.test_type`; kode lama tak pernah mengisi test_name.
        $codes = $queues->flatMap(fn ($q) => $q->visit?->diagnosticOrders ?? [])
            ->pluck('test_type')->filter()->unique()->all();
        $names = $codes ? DiagnosticTestType::whereIn('code', $codes)->pluck('name', 'code') : collect();
        foreach ($queues as $q) {
            foreach ($q->visit?->diagnosticOrders ?? [] as $o) {
                $o->test_name = $names[$o->test_type] ?? $o->test_type;
            }
        }

        return $queues;
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_PENUNJANG)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /** Lewati antrean Penunjang: turun 1 posisi (delegasi ke QueueService::lewati). */
    public function lewatiAntrian(string $queueId): Queue
    {
        Queue::byStation(Queue::STATION_PENUNJANG)->findOrFail($queueId);
        return $this->queueService->lewati($queueId);
    }

    /**
     * Selesai antrian Penunjang → kembali ke DOKTER untuk pembacaan hasil
     * (Section 11.3 catatan opsional Penunjang).
     *
     * Tutup semua order REQUESTED/IN_PROGRESS milik visit (operator menyatakan
     * pemeriksaan sudah cukup), lalu naikkan baris DOKTER ke atas dengan status
     * SELESAI_PENUNJANG. advanceFromStation menjadi no-op untuk baris DOKTER
     * (lihat nextAfterPenunjang) karena requeueToDokter sudah menghidupkannya.
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_PENUNJANG)->with('visit')->findOrFail($queueId);

        DB::transaction(function () use ($queue) {
            // Jujur: order yang BENAR-BENAR punya hasil → COMPLETED; order yang
            // tak pernah diisi hasilnya saat operator menutup antrean → CANCELLED.
            // Status ini kini MURNI operasional (hasil tampil di RME/resume + rekomendasi
            // IOL hanya digenerate dari order COMPLETED). Billing TIDAK lagi membaca
            // diagnostic_orders — penunjang ditagih sebagai tindakan Tab 3 (visit_services).
            $orders = DiagnosticOrder::where('visit_id', $queue->visit_id)
                ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
                ->withCount('results')
                ->get();

            foreach ($orders as $order) {
                $order->update([
                    'status' => $order->results_count > 0 ? 'COMPLETED' : 'CANCELLED',
                ]);
            }

            $this->requeueToDokter($queue->visit_id);
        });

        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_PENUNJANG);
    }

    // =========================================================================
    // ORDER PENUNJANG
    // =========================================================================

    public function getOrders(array $filters = []): Collection
    {
        $query = DiagnosticOrder::with(['visit.patient', 'orderedBy', 'results'])
            ->whereDate('created_at', $filters['tanggal'] ?? today());

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['test_type'])) {
            $query->where('test_type', $filters['test_type']);
        }

        return $query->orderBy('created_at')->get();
    }

    public function getOrderById(string $id): DiagnosticOrder
    {
        return DiagnosticOrder::with([
            'visit.patient',
            'visit.refractionRecord',
            'orderedBy',
            'results.performedBy',
        ])->findOrFail($id);
    }

    /**
     * Create diagnostic order — can also be initiated from Penunjang side.
     */
    public function createOrder(string $visitId, string $testType, array $data = []): DiagnosticOrder
    {
        $user  = auth('api')->user();
        $order = DiagnosticOrder::create([
            'visit_id'         => $visitId,
            'ordered_by_id'    => $user->employee_id,
            'test_type'        => $testType,
            // Accession DICOM (kunci pencocokan worklist/ingest alat). Lihat AccessionService.
            'accession_number' => app(AccessionService::class)->next(),
            'eye_side'         => $data['eye_side'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'status'           => 'REQUESTED',
        ]);

        // Create PENUNJANG queue for this visit if not already queued today
        $alreadyQueued = Queue::where('visit_id', $visitId)
            ->where('station', 'PENUNJANG')
            ->whereDate('created_at', today())
            ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
            ->exists();

        if (! $alreadyQueued) {
            $lastSeq  = Queue::where('station', 'PENUNJANG')->whereDate('created_at', today())->max('queue_sequence') ?? 0;
            $sequence = $lastSeq + 1;

            Queue::create([
                'visit_id'       => $visitId,
                'station'        => 'PENUNJANG',
                'queue_prefix'   => 'P',
                'queue_sequence' => $sequence,
                'queue_number'   => 'P-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
                'status'         => 'WAITING',
            ]);
        }

        $this->log($user->id, 'CREATE_ORDER', DiagnosticOrder::class, $order->id, "{$testType} untuk kunjungan {$visitId}");

        return $order->load('orderedBy');
    }

    public function prosesOrder(string $id): DiagnosticOrder
    {
        $order = DiagnosticOrder::findOrFail($id);

        if ($order->status !== 'REQUESTED') {
            throw new \Exception('Order tidak dalam status REQUESTED.', 422);
        }

        $order->update(['status' => 'IN_PROGRESS']);

        $this->log(auth('api')->id(), 'PROSES_ORDER', DiagnosticOrder::class, $id);

        return $order->fresh(['visit.patient', 'orderedBy']);
    }

    public function cancelOrder(string $id): void
    {
        $order = DiagnosticOrder::findOrFail($id);

        if (! in_array($order->status, ['REQUESTED', 'IN_PROGRESS'])) {
            throw new \Exception('Order tidak bisa dibatalkan.', 422);
        }

        $order->update(['status' => 'CANCELLED']);

        $this->log(auth('api')->id(), 'CANCEL_ORDER', DiagnosticOrder::class, $id);
    }

    // =========================================================================
    // HASIL PENUNJANG
    // =========================================================================

    public function getResult(string $orderId): ?DiagnosticResult
    {
        return DiagnosticResult::with(['performedBy', 'reviewedBy'])
            ->where('diagnostic_order_id', $orderId)
            ->first();
    }

    /**
     * Store diagnostic result with expertise_data (JSONB per test type).
     */
    public function storeResult(string $orderId, array $data): DiagnosticResult
    {
        $order = DiagnosticOrder::findOrFail($orderId);

        if ($order->status === 'CANCELLED') {
            throw new \Exception('Tidak bisa input hasil — order sudah dibatalkan.', 422);
        }

        if (DiagnosticResult::where('diagnostic_order_id', $orderId)->exists()) {
            throw new \Exception('Hasil sudah ada. Gunakan update untuk mengubah.', 422);
        }

        $user   = auth('api')->user();
        $result = DiagnosticResult::create([
            'diagnostic_order_id' => $orderId,
            'performed_by_id'     => $user->employee_id,
            'expertise_data'      => $data['expertise_data'],
            'attachment_path'     => $data['attachment_path'] ?? null,
            'notes'               => $data['notes'] ?? null,
            'result_status'       => 'PENDING',
            'uploaded_at'         => now(),
        ]);

        // Update order status
        $order->update(['status' => 'IN_PROGRESS']);

        $this->log(
            $user->id,
            'STORE_HASIL',
            DiagnosticResult::class,
            $result->id,
            "Hasil {$order->test_type} diinput untuk kunjungan {$order->visit_id}"
        );

        return $result->load('performedBy');
    }

    /**
     * Lampirkan file (PDF/gambar) ke hasil sebuah order — dipakai jalur MESIN
     * (ingest bridge/watcher) & penautan dari Inbox. BEDA dari storeResult (jalur
     * manual): TIDAK throw bila hasil sudah ada (pakai firstOrNew), tidak mengisi
     * expertise_data form, dan TIDAK auto-finalize (manusia tetap review + ketik angka).
     *
     * - File pertama → attachment_path; file tambahan (mis. OD lalu OS) → ditumpuk di
     *   expertise_data['attachments'][] tanpa ubah skema.
     * - Idempoten via $externalRef (study/SOP UID): bila ref sudah pernah dilampirkan,
     *   kembalikan hasil apa adanya (tak menambah file dobel saat bridge retry).
     *
     * @param ?string $performedById employee_id penaut (null = mesin)
     */
    public function attachAttachmentToOrder(
        DiagnosticOrder $order,
        string $path,
        ?string $performedById = null,
        ?string $externalRef = null,
        ?array $expertisePatch = null
    ): DiagnosticResult {
        return DB::transaction(function () use ($order, $path, $performedById, $externalRef, $expertisePatch) {
            $result = DiagnosticResult::firstOrNew(['diagnostic_order_id' => $order->id]);

            if (! $result->exists) {
                $result->performed_by_id = $performedById;
                $result->expertise_data  = [];
                $result->result_status   = 'PENDING';
                $result->uploaded_at     = now();
            }

            $exp  = $result->expertise_data ?? [];
            $refs = $exp['ingest_refs'] ?? [];

            // Idempotensi: ref sudah pernah masuk → tak menambah file dobel.
            if ($externalRef && in_array($externalRef, $refs, true)) {
                return $result;
            }

            // Data terstruktur dari parser alat (mis. biometri Quantel) — merge,
            // tanpa menimpa kunci internal attachments/ingest_refs.
            if ($expertisePatch) {
                $exp = array_merge($exp, $expertisePatch);
            }

            if (empty($result->attachment_path)) {
                $result->attachment_path = $path;
            } else {
                $attachments = $exp['attachments'] ?? [];
                if ($path !== $result->attachment_path && ! in_array($path, $attachments, true)) {
                    $attachments[] = $path;
                }
                $exp['attachments'] = $attachments;
            }

            if ($externalRef) {
                $refs[] = $externalRef;
                $exp['ingest_refs'] = $refs;
            }

            $result->expertise_data = $exp;
            $result->save();

            // Naikkan order ke IN_PROGRESS bila masih terbuka (jangan turunkan COMPLETED).
            if (in_array($order->status, ['REQUESTED', 'IN_PROGRESS'], true)) {
                $order->update(['status' => 'IN_PROGRESS']);
            }

            $this->log(
                $performedById ? auth('api')->id() : null,
                'INGEST_ATTACHMENT',
                DiagnosticResult::class,
                $result->id,
                "Lampiran hasil {$order->test_type} ditautkan (kunjungan {$order->visit_id})"
            );

            return $result;
        });
    }

    public function updateResult(string $id, array $data): DiagnosticResult
    {
        $result = DiagnosticResult::findOrFail($id);

        if (in_array($result->result_status, ['APPROVED'])) {
            throw new \Exception('Hasil sudah di-approve, tidak bisa diubah.', 422);
        }

        $result->update(array_filter([
            'expertise_data'  => $data['expertise_data'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
            'notes'           => $data['notes'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_HASIL', DiagnosticResult::class, $id);

        return $result->fresh(['performedBy']);
    }

    /**
     * Finalize result → mark COMPLETED → notify doctor → re-queue to DOKTER if all orders done.
     * If test_type = Biometri → auto-generate IOL recommendation.
     */
    public function finalizeResult(string $id): DiagnosticResult
    {
        $result = DiagnosticResult::with(['order.visit.patient'])->findOrFail($id);
        $order  = $result->order;

        if ($result->result_status === 'APPROVED') {
            throw new \Exception('Hasil sudah dikunci.', 422);
        }

        $user = auth('api')->user();

        DB::transaction(function () use ($result, $order, $user) {
            $result->update([
                'result_status' => 'COMPLETED',
                'reviewed_by_id' => $user->employee_id,
                'reviewed_at'   => now(),
            ]);

            $order->update(['status' => 'COMPLETED']);

            // Notify doctor: hasil penunjang siap (ke dokter pemesan order)
            $this->notifyDoctor($order, $order->test_type, $result->id);

            // Jika semua order untuk kunjungan ini selesai → re-queue DOKTER
            $pendingOrders = DiagnosticOrder::where('visit_id', $order->visit_id)
                ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
                ->count();

            if ($pendingOrders === 0) {
                $this->requeueToDokter($order->visit_id);
            }
        });

        // Auto-generate IOL recommendation for Biometri (test_type = kode master BIOM)
        if ($order->test_type === DiagnosticTestType::BIOMETRI_CODE) {
            $this->generateIolRecommendation($result->id);
        }

        $this->log(
            $user->id,
            'FINALIZE_HASIL',
            DiagnosticResult::class,
            $id,
            "Hasil {$order->test_type} dikunci — kunjungan {$order->visit_id}"
        );

        return $result->fresh(['performedBy', 'reviewedBy', 'order']);
    }

    // =========================================================================
    // IOL RECOMMENDATION (dari Biometri)
    // =========================================================================

    public function getIolRekomendasi(string $visitId): Collection
    {
        return IolRecommendation::with('approvedBy')
            ->where('visit_id', $visitId)
            ->orderBy('eye_side')
            ->get();
    }

    /**
     * Auto-generate IOL recommendations from biometri expertise_data.
     * Reads od/os blocks from expertise_data and creates IolRecommendation per eye.
     */
    public function generateIolRecommendation(string $biometriResultId): void
    {
        $result = DiagnosticResult::with('order')->findOrFail($biometriResultId);

        if ($result->order->test_type !== DiagnosticTestType::BIOMETRI_CODE) {
            throw new \Exception('IOL recommendation hanya bisa di-generate dari hasil Biometri.', 422);
        }

        $data    = $result->expertise_data ?? [];
        $visitId = $result->order->visit_id;

        $created = [];
        DB::transaction(function () use ($data, $visitId, $biometriResultId, &$created) {
            foreach (['od', 'os'] as $eye) {
                $eyeData = $data[$eye] ?? null;

                if (! $eyeData || empty($eyeData['recommended_iol_power'])) {
                    continue;
                }

                IolRecommendation::updateOrCreate(
                    [
                        'visit_id' => $visitId,
                        'eye_side' => strtoupper($eye),
                    ],
                    [
                        'diagnostic_result_id' => $biometriResultId,
                        'recommended_power'    => $eyeData['recommended_iol_power'],
                        'iol_type'             => $eyeData['iol_type'] ?? 'MONOFOCAL',
                        'brand'                => $eyeData['brand'] ?? null,
                        // Biometri kini IOL-only — tak ada lagi rakitan AL/K1/K2.
                        'notes'                => null,
                        'is_approved'          => false,
                    ]
                );

                $created[] = strtoupper($eye) . ' ' . $eyeData['recommended_iol_power'] . 'D '
                    . ($eyeData['iol_type'] ?? 'MONOFOCAL')
                    . ($eyeData['brand'] ? " ({$eyeData['brand']})" : '');
            }
        });

        $this->log(null, 'GENERATE_IOL_REKOMENDASI', DiagnosticResult::class, $biometriResultId, "Rekomendasi IOL dari biometri untuk kunjungan {$visitId}");

        // Push ke Bedah: bila pasien BEDAH TERJADWAL (visit.surgery_schedule_id), kirim
        // notifikasi catatan IOL ke petugas bedah agar bisa di-request ke gudang.
        if (! empty($created)) {
            $this->notifyBedahIol($visitId, $created);
        }
    }

    /**
     * Notifikasi ke stasiun Bedah saat rekomendasi IOL siap UNTUK pasien bedah
     * terjadwal (visit.surgery_schedule_id terisi). Catatan: IolRecommendation sudah
     * otomatis dibaca BedahTerjadwalView (buildRequestPreviewFromSchedule); notifikasi
     * ini sekadar "push" agar petugas bedah tahu ada rekomendasi baru → request gudang.
     * Tak menjangkau dokter pemesan (itu sudah via notifyDoctor terpisah).
     */
    private function notifyBedahIol(string $visitId, array $summary): void
    {
        $visit = Visit::with('patient')->find($visitId);
        if (! $visit || ! $visit->surgery_schedule_id) {
            return; // bukan pasien bedah terjadwal → tak perlu push ke Bedah
        }

        // Penerima: semua user dengan akses tulis Bedah (petugas bedah).
        $bedahUsers = User::whereHas('role.permissions', fn ($q) => $q->where('key', 'bedah.write'))->get();
        if ($bedahUsers->isEmpty()) {
            return;
        }

        $name = $visit->patient?->name ?? 'Pasien';
        $detail = implode('; ', $summary);

        foreach ($bedahUsers as $u) {
            Notification::create([
                'recipient_id'        => $u->id,
                'type'                => 'IOL_RECOMMENDATION',
                'patient_document_id' => null,
                'title'               => 'Rekomendasi IOL Siap (Bedah Terjadwal)',
                'message'             => "{$name}: {$detail}. Cek Bedah Terjadwal untuk request IOL/BHP ke gudang.",
                'is_read'             => false,
                'resend_count'        => 0,
            ]);
        }

        $this->log(null, 'NOTIFY_BEDAH_IOL', Visit::class, $visitId, "Notif IOL ke Bedah ({$bedahUsers->count()} petugas): {$detail}");
    }

    /**
     * Manual IOL recommendation (penunjang input manually, not from auto-generate).
     */
    public function storeIolRekomendasi(array $data): IolRecommendation
    {
        $rekomendasi = IolRecommendation::create([
            'visit_id'             => $data['visit_id'],
            'diagnostic_result_id' => $data['diagnostic_result_id'] ?? null,
            'eye_side'             => $data['eye_side'],
            'recommended_power'    => $data['recommended_power'],
            'iol_type'             => $data['iol_type'] ?? 'MONOFOCAL',
            'brand'                => $data['brand'] ?? null,
            'notes'                => $data['notes'] ?? null,
            'is_approved'          => false,
        ]);

        $this->log(auth('api')->id(), 'STORE_IOL_REKOMENDASI', IolRecommendation::class, $rekomendasi->id);

        return $rekomendasi;
    }

    public function updateIolRekomendasi(string $id, array $data): IolRecommendation
    {
        $rekomendasi = IolRecommendation::findOrFail($id);

        if ($rekomendasi->is_approved) {
            throw new \Exception('Rekomendasi IOL sudah disetujui dokter, tidak bisa diubah.', 422);
        }

        $rekomendasi->update(array_intersect_key($data, array_flip($rekomendasi->getFillable())));

        $this->log(auth('api')->id(), 'UPDATE_IOL_REKOMENDASI', IolRecommendation::class, $id);

        return $rekomendasi->fresh();
    }

    // =========================================================================
    // INBOX HASIL TAK-TERTAUT (ingest yang gagal cocok otomatis → tautkan manual)
    // =========================================================================

    /** Daftar item inbox UNMATCHED (opsional saring per source: OCT|USG_WATCHER). */
    public function getInbox(?string $source = null): Collection
    {
        $items = PenunjangIngestInbox::where('status', 'UNMATCHED')
            ->when($source, fn ($q) => $q->where('source', $source))
            ->orderBy('created_at')
            ->get();

        // Resolusi NAMA pasien — nama file alat berupa GUID acak tak informatif.
        // Dua jalur sesuai cara cocok masing-masing alat:
        //  - OCT (DICOM)        → punya accession_number → telusuri order → visit → pasien
        //    (berlaku walau order sudah COMPLETED/CANCELLED → itu sebab gagal cocok otomatis).
        //  - Quantel/USG watcher → punya claimed_no_rm → cari Patient by No.RM.
        // patient_name=null berarti identitas file tak terpetakan di sistem (sinyal salah
        // No.RM/accession di alat atau pasien belum terdaftar).
        $byRm = Patient::whereIn('no_rm', $items->pluck('claimed_no_rm')->filter()->unique()->all())
            ->pluck('name', 'no_rm');

        $accessions = $items->pluck('accession_number')->filter()->unique()->all();
        $byAcc = $accRm = collect();
        if ($accessions) {
            $orders = DiagnosticOrder::with('visit.patient')
                ->whereIn('accession_number', $accessions)->get();
            $byAcc = $orders->mapWithKeys(fn ($o) => [$o->accession_number => $o->visit?->patient?->name]);
            $accRm = $orders->mapWithKeys(fn ($o) => [$o->accession_number => $o->visit?->patient?->no_rm]);
        }

        foreach ($items as $it) {
            if ($it->accession_number && $byAcc->has($it->accession_number)) {
                $it->patient_name  = $byAcc[$it->accession_number];
                $it->patient_no_rm = $accRm[$it->accession_number] ?? $it->claimed_no_rm;
            } else {
                $it->patient_name  = $it->claimed_no_rm ? ($byRm[$it->claimed_no_rm] ?? null) : null;
                $it->patient_no_rm = $it->claimed_no_rm;
            }
        }

        return $items;
    }

    /** Order penunjang terbuka hari ini sebagai kandidat penautan (picker UI). */
    public function searchAssignableOrders(array $filters = []): Collection
    {
        $query = DiagnosticOrder::with('visit.patient')
            ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
            ->whereNotNull('accession_number')
            // Tanggal eksplisit → tepat hari itu; tanpa tanggal → order terbuka ≤7 hari
            // (selaras worklist & auto-match) supaya order nyangkut lintas-hari tetap bisa ditautkan.
            ->when(
                ! empty($filters['date']),
                fn ($q) => $q->whereDate('created_at', $filters['date']),
                fn ($q) => $q->where('created_at', '>=', today()->subDays(7)),
            );

        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->whereHas('visit.patient', fn ($p) => $p
                ->where('no_rm', 'ilike', "%{$kw}%")
                ->orWhere('name', 'ilike', "%{$kw}%"));
        }

        return $query->orderBy('created_at')->limit(50)->get();
    }

    /** Tautkan item inbox ke sebuah order (reuse attachAttachmentToOrder). */
    public function assignInbox(string $inboxId, string $orderId): PenunjangIngestInbox
    {
        $inbox = PenunjangIngestInbox::findOrFail($inboxId);
        if ($inbox->status !== 'UNMATCHED') {
            throw new \Exception('Item inbox sudah diproses.', 422);
        }

        $order = DiagnosticOrder::findOrFail($orderId);
        if (! in_array($order->status, ['REQUESTED', 'IN_PROGRESS'], true)) {
            throw new \Exception('Order tidak dalam status terbuka.', 422);
        }

        $user = auth('api')->user();

        return DB::transaction(function () use ($inbox, $order, $user) {
            $this->attachAttachmentToOrder($order, $inbox->attachment_path, $user->employee_id, $inbox->external_ref);
            $inbox->update([
                'status'            => 'ASSIGNED',
                'assigned_order_id' => $order->id,
                'assigned_by_id'    => $user->employee_id,
                'assigned_at'       => now(),
            ]);
            $this->log($user->id, 'ASSIGN_INBOX', PenunjangIngestInbox::class, $inbox->id, "Tautkan ke order {$order->id}");

            return $inbox->fresh('assignedOrder');
        });
    }

    /** Buang item inbox (mis. file salah/ganda). */
    public function discardInbox(string $inboxId): void
    {
        $inbox = PenunjangIngestInbox::findOrFail($inboxId);
        if ($inbox->status === 'ASSIGNED') {
            throw new \Exception('Item sudah tertaut — tidak bisa dibuang.', 422);
        }
        $inbox->update(['status' => 'DISCARDED']);
        $this->log(auth('api')->id(), 'DISCARD_INBOX', PenunjangIngestInbox::class, $inboxId);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * When all penunjang orders are done → re-create DOKTER queue so patient returns to doctor.
     */
    private function requeueToDokter(string $visitId): void
    {
        // Pindah ke PALING ATAS antrean DOKTER hari ini (prioritas pembacaan hasil).
        $minSeq = Queue::byStation(Queue::STATION_DOKTER)->whereDate('created_at', today())->min('queue_sequence') ?? 1;
        $topSeq = $minSeq - 1;

        // Reuse baris DOKTER yang masih hidup (di-pause saat dikirim ke penunjang),
        // bukan membuat baris baru → satu pasien tetap satu baris.
        $dokterQueue = Queue::byStation(Queue::STATION_DOKTER)
            ->where('visit_id', $visitId)
            ->whereNotIn('status', [Queue::STATUS_COMPLETED, Queue::STATUS_CANCELLED])
            ->orderByDesc('created_at')
            ->first();

        if ($dokterQueue) {
            $dokterQueue->update([
                'status'         => Queue::STATUS_PENUNJANG_DONE,
                'queue_sequence' => $topSeq,
                'called_at'      => null,
                'started_at'     => null,
            ]);
            $this->log(null, 'REQUEUE_DOKTER', Visit::class, $visitId, 'Semua order penunjang selesai — pasien naik ke atas antrian Dokter (Selesai Penunjang)');
            return;
        }

        // Fallback: tak ada baris DOKTER hidup → buat baru di atas.
        $num = (Queue::byStation(Queue::STATION_DOKTER)->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
        Queue::create([
            'visit_id'       => $visitId,
            'station'        => Queue::STATION_DOKTER,
            'queue_prefix'   => 'D',
            'queue_sequence' => $topSeq,
            'queue_number'   => 'D-' . str_pad($num, 3, '0', STR_PAD_LEFT),
            'status'         => Queue::STATUS_PENUNJANG_DONE,
        ]);

        $this->log(null, 'REQUEUE_DOKTER', Visit::class, $visitId, 'Semua order penunjang selesai — pasien kembali ke antrian Dokter');
    }

    /**
     * Notify doctor (via Notification inbox) that test result is ready.
     *
     * Sasaran = dokter PEMESAN order (order.ordered_by_id → employee → user),
     * BUKAN dokter sembarang. Fallback ke dokter mana pun hanya bila pemesan tak
     * terpetakan ke user (mis. order lama tanpa ordered_by_id).
     */
    private function notifyDoctor(DiagnosticOrder $order, string $testType, string $resultId): void
    {
        $doctorUser = null;

        if ($order->ordered_by_id) {
            $doctorUser = User::where('employee_id', $order->ordered_by_id)->first();
        }

        if (! $doctorUser) {
            $doctorUser = User::whereHas(
                'employee',
                fn ($q) => $q->where('profession', 'like', '%Dokter%')
            )->first();
        }

        if (! $doctorUser) {
            return;
        }

        Notification::create([
            'recipient_id'        => $doctorUser->id,
            'type'                => 'SIGNATURE_REQUEST',
            'patient_document_id' => null,
            'title'               => "Hasil {$testType} Siap",
            'message'             => "Hasil pemeriksaan {$testType} telah diinput dan siap direview.",
            'is_read'             => false,
            'resend_count'        => 0,
        ]);
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
