<?php

namespace App\Services;

use App\Models\DiagnosticOrder;
use App\Models\DiagnosticResult;
use App\Models\IolRecommendation;
use App\Models\Notification;
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
        return Queue::with(['visit.patient', 'visit.diagnosticOrders'])
            ->where('station', 'PENUNJANG')
            ->whereDate('created_at', today())
            ->orderBy('queue_sequence')
            ->get();
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_PENUNJANG)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Penunjang → kembali ke DOKTER untuk pembacaan hasil
     * (Section 11.3 catatan opsional Penunjang).
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_PENUNJANG)->findOrFail($queueId);
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
            'visit_id'      => $visitId,
            'ordered_by_id' => $user->employee_id,
            'test_type'     => $testType,
            'eye_side'      => $data['eye_side'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'status'        => 'REQUESTED',
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

            // Notify doctor: hasil penunjang siap
            $this->notifyDoctor($order->visit_id, $order->test_type, $result->id);

            // Jika semua order untuk kunjungan ini selesai → re-queue DOKTER
            $pendingOrders = DiagnosticOrder::where('visit_id', $order->visit_id)
                ->whereIn('status', ['REQUESTED', 'IN_PROGRESS'])
                ->count();

            if ($pendingOrders === 0) {
                $this->requeueToDokter($order->visit_id);
            }
        });

        // Auto-generate IOL recommendation for Biometri
        if ($order->test_type === 'Biometri') {
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

        if ($result->order->test_type !== 'Biometri') {
            throw new \Exception('IOL recommendation hanya bisa di-generate dari hasil Biometri.', 422);
        }

        $data    = $result->expertise_data ?? [];
        $visitId = $result->order->visit_id;

        DB::transaction(function () use ($data, $visitId, $biometriResultId) {
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
                        'notes'                => isset($eyeData['axial_length'])
                            ? "AL: {$eyeData['axial_length']} mm, K1: {$eyeData['k1']} D, K2: {$eyeData['k2']} D"
                            : null,
                        'is_approved'          => false,
                    ]
                );
            }
        });

        $this->log(null, 'GENERATE_IOL_REKOMENDASI', DiagnosticResult::class, $biometriResultId, "Rekomendasi IOL dari biometri untuk kunjungan {$visitId}");
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
     */
    private function notifyDoctor(string $visitId, string $testType, string $resultId): void
    {
        // Find the doctor user for this visit
        $doctorUser = User::whereHas(
            'employee',
            fn ($q) => $q->where('profession', 'like', '%Dokter%')
        )->first();

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
