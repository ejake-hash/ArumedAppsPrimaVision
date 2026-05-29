<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Queue;
use App\Models\SurgeryRequest;
use App\Models\SurgeryRequestBhp;
use App\Models\SurgeryRequestIol;
use App\Models\SystemLog;
use App\Services\QueueService;
use App\Services\InventoryStockService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FarmasiService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
        private readonly InventoryStockService $stockService,
    ) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function getPatientQueue(): Collection
    {
        return Queue::with(['visit.patient', 'visit.prescriptions'])
            ->where('station', 'FARMASI')
            ->whereDate('created_at', today())
            ->orderBy('queue_sequence')
            ->get();
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_FARMASI)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Farmasi → pasien PULANG (current_station = SELESAI).
     * Section 11.3 step 6.
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_FARMASI)->findOrFail($queueId);
        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_FARMASI);
    }

    // =========================================================================
    // RESEP OBAT
    // =========================================================================

    public function getPrescriptions(array $filters = []): LengthAwarePaginator
    {
        $query = Prescription::with(['visit.patient', 'prescribedBy', 'items.medication'])
            ->whereDate('created_at', $filters['tanggal'] ?? today());

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->whereHas('visit.patient', fn ($q) => $q
                ->where('name', 'ilike', "%{$keyword}%")
                ->orWhere('no_rm', 'ilike', "%{$keyword}%")
            );
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);
    }

    public function getPrescriptionById(string $id): Prescription
    {
        return Prescription::with([
            'visit.patient',
            'prescribedBy',
            'dispensedBy',
            'items.medication',
        ])->findOrFail($id);
    }

    /**
     * DRAFT → DISPENSING (mulai proses dispensing).
     */
    public function startDispensing(string $prescriptionId): Prescription
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        if (! in_array($prescription->status, ['DRAFT', 'SUBMITTED'])) {
            throw new \Exception('Resep tidak dalam status yang bisa diproses.', 422);
        }

        $prescription->update(['status' => 'DISPENSING']);

        $this->log(auth('api')->id(), 'START_DISPENSING', Prescription::class, $prescriptionId);

        return $prescription->fresh(['items.medication']);
    }

    /**
     * DISPENSING → DISPENSED: kurangi stok obat per item.
     */
    public function selesaiDispensing(string $prescriptionId): Prescription
    {
        $prescription = Prescription::with('items.medication')->findOrFail($prescriptionId);

        if ($prescription->status !== 'DISPENSING') {
            throw new \Exception('Resep harus dalam status DISPENSING sebelum diselesaikan.', 422);
        }

        // Cek stok kecukupan sebelum deduct — sumber stok = `inventory_stocks`
        // (per-batch FEFO), BUKAN kolom legacy `medications.stock` yang sudah
        // tidak otoritatif pasca-redesign inventori.
        foreach ($prescription->items as $item) {
            if (! $item->medication) continue;
            $onHand = $this->stockService->onHand('MEDICATION', $item->medication_id);
            if ($onHand < $item->quantity) {
                throw new \Exception(
                    "Stok {$item->medication->name} tidak mencukupi. Tersedia: {$onHand}, dibutuhkan: {$item->quantity}.",
                    422
                );
            }
        }

        $user = auth('api')->user();

        DB::transaction(function () use ($prescription, $user) {
            // Deduct dari inventory_stocks (FEFO, per-batch). consume() throw 422
            // bila stok berubah & jadi tak cukup (race) — tetap atomik dalam transaksi.
            foreach ($prescription->items as $item) {
                if ($item->medication) {
                    $this->stockService->consume('MEDICATION', $item->medication_id, (float) $item->quantity);
                }
            }

            $prescription->update([
                'status'          => 'DISPENSED',
                'dispensed_by_id' => $user->employee_id,
                'dispensed_at'    => now(),
            ]);
        });

        $this->log(
            $user->id,
            'SELESAI_DISPENSING',
            Prescription::class,
            $prescriptionId,
            "Resep diselesaikan — {$prescription->items->count()} item obat"
        );

        return $prescription->fresh(['items.medication', 'dispensedBy']);
    }

    public function cancelResep(string $prescriptionId): Prescription
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        if (in_array($prescription->status, ['DISPENSED'])) {
            throw new \Exception('Resep yang sudah diselesaikan tidak bisa dibatalkan.', 422);
        }

        $prescription->update(['status' => 'CANCELLED']);

        $this->log(auth('api')->id(), 'CANCEL_RESEP', Prescription::class, $prescriptionId);

        return $prescription->fresh();
    }

    // -------------------------------------------------------------------------
    // Item dispensing CRUD

    public function storeItemDispensing(string $prescriptionId, array $items): Collection
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        if ($prescription->status === 'DISPENSED') {
            throw new \Exception('Resep sudah diselesaikan, tidak bisa tambah item.', 422);
        }

        return DB::transaction(function () use ($prescriptionId, $items) {
            $created = [];
            foreach ($items as $item) {
                $created[] = PrescriptionItem::create([
                    'prescription_id' => $prescriptionId,
                    'medication_id'   => $item['medication_id'],
                    'quantity'        => $item['quantity'],
                    'dosage'          => $item['dosage'] ?? null,
                    'instructions'    => $item['instructions'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            return collect($created)->load('medication');
        });
    }

    public function updateItemDispensing(string $id, array $data): PrescriptionItem
    {
        $item = PrescriptionItem::with('prescription')->findOrFail($id);

        if ($item->prescription->status === 'DISPENSED') {
            throw new \Exception('Resep sudah diselesaikan, tidak bisa ubah item.', 422);
        }

        $item->update(array_filter([
            'quantity'     => $data['quantity'] ?? null,
            'dosage'       => $data['dosage'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'notes'        => $data['notes'] ?? null,
        ], fn ($v) => ! is_null($v)));

        return $item->fresh('medication');
    }

    public function deleteItemDispensing(string $id): void
    {
        $item = PrescriptionItem::with('prescription')->findOrFail($id);

        if ($item->prescription->status === 'DISPENSED') {
            throw new \Exception('Resep sudah diselesaikan, tidak bisa hapus item.', 422);
        }

        $item->delete();
        $this->log(auth('api')->id(), 'DELETE_ITEM_RESEP', PrescriptionItem::class, $id);
    }

    // =========================================================================
    // SURGERY REQUEST — BHP + IOL
    // =========================================================================

    public function getSurgeryRequests(array $filters = []): Collection
    {
        $query = SurgeryRequest::with([
            'visit.patient',
            'surgerySchedule.surgeryPackage',
            'requestedBy',
            'bhpItems.bhpItem',
            'iolItems.iolItem',
        ]);

        $query->where('status', $filters['status'] ?? 'REQUESTED');

        if (! empty($filters['tanggal'])) {
            $query->whereDate('created_at', $filters['tanggal']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function getSurgeryRequestById(string $id): SurgeryRequest
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
     * Tandai bahwa Farmasi sedang menyiapkan item.
     * Tidak mengubah status — hanya log sebagai audit trail.
     */
    public function siapkanSurgeryRequest(string $requestId): SurgeryRequest
    {
        $request = SurgeryRequest::findOrFail($requestId);

        if ($request->status !== 'REQUESTED') {
            throw new \Exception('Hanya request dengan status REQUESTED yang bisa disiapkan.', 422);
        }

        $this->log(
            auth('api')->id(),
            'SIAPKAN_SURGERY_REQUEST',
            SurgeryRequest::class,
            $requestId,
            'Farmasi mulai menyiapkan BHP+IOL'
        );

        return $request->load(['bhpItems.bhpItem', 'iolItems.iolItem']);
    }

    /**
     * Assign IOL item spesifik ke surgery_request_iol.
     * Validasi: iol_item belum dipakai + power/type cocok dengan permintaan.
     */
    public function assignIolToRequest(string $requestIolId, string $iolItemId): SurgeryRequestIol
    {
        $requestIol = SurgeryRequestIol::with('surgeryRequest')->findOrFail($requestIolId);
        $iolItem    = IolItem::findOrFail($iolItemId);

        if ($iolItem->is_used) {
            throw new \Exception("IOL {$iolItem->brand} {$iolItem->model} (P:{$iolItem->power}) sudah digunakan.", 422);
        }

        if (! $iolItem->is_active) {
            throw new \Exception('IOL item tidak aktif.', 422);
        }

        // Validasi power: toleransi ±0.5 D
        if (
            $requestIol->requested_power
            && abs($iolItem->power - $requestIol->requested_power) > 0.5
        ) {
            throw new \Exception(
                "Power IOL tidak cocok. Diminta: {$requestIol->requested_power} D, tersedia: {$iolItem->power} D (toleransi ±0.5 D).",
                422
            );
        }

        $requestIol->update(['iol_item_id' => $iolItemId]);

        $this->log(
            auth('api')->id(),
            'ASSIGN_IOL',
            SurgeryRequestIol::class,
            $requestIolId,
            "IOL {$iolItem->brand} {$iolItem->model} P{$iolItem->power} di-assign ke mata {$requestIol->eye_side}"
        );

        return $requestIol->fresh('iolItem');
    }

    /**
     * Kirim supply ke Bedah (REQUESTED → SENT).
     * Guard: semua IOL item wajib sudah di-assign.
     * Side-effect: deduct BHP stock.
     */
    public function kirimSurgeryRequest(string $requestId): SurgeryRequest
    {
        $surgeryRequest = SurgeryRequest::with([
            'bhpItems.bhpItem',
            'iolItems.iolItem',
        ])->findOrFail($requestId);

        if ($surgeryRequest->status !== 'REQUESTED') {
            throw new \Exception('Request harus dalam status REQUESTED untuk dikirim.', 422);
        }

        // Semua IOL harus sudah di-assign
        $unassignedIol = $surgeryRequest->iolItems->filter(fn ($i) => ! $i->iol_item_id);
        if ($unassignedIol->isNotEmpty()) {
            throw new \Exception(
                "Belum semua IOL di-assign. Mata belum di-assign: "
                . $unassignedIol->pluck('eye_side')->implode(', ') . '.',
                422
            );
        }

        DB::transaction(function () use ($surgeryRequest) {
            // Deduct BHP dari inventory_stocks (FEFO, per-batch) — bukan kolom
            // legacy bhp_items.stock. Sama dengan perbaikan dispensing obat.
            foreach ($surgeryRequest->bhpItems as $item) {
                if ($item->bhpItem) {
                    $onHand = $this->stockService->onHand('BHP', $item->bhp_item_id);
                    if ($onHand < $item->quantity) {
                        throw new \Exception(
                            "Stok BHP {$item->bhpItem->name} tidak mencukupi. Tersedia: {$onHand}.",
                            422
                        );
                    }
                    $this->stockService->consume('BHP', $item->bhp_item_id, (float) $item->quantity);
                }
            }

            $surgeryRequest->update([
                'status'  => 'SENT',
                'sent_at' => now(),
            ]);
        });

        $this->log(
            auth('api')->id(),
            'KIRIM_SURGERY_REQUEST',
            SurgeryRequest::class,
            $requestId,
            'BHP+IOL dikirim ke Bedah'
        );

        return $surgeryRequest->fresh(['bhpItems.bhpItem', 'iolItems.iolItem']);
    }

    // =========================================================================
    // STOK — OBAT
    // =========================================================================

    public function getStokObat(array $filters = []): LengthAwarePaginator
    {
        $query = Medication::query();

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$keyword}%")
                ->orWhere('code', 'ilike', "%{$keyword}%")
                ->orWhere('generic_name', 'ilike', "%{$keyword}%")
            );
        }

        if (! empty($filters['formularium'])) {
            $query->where('formularium', $filters['formularium']);
        }

        if (! empty($filters['alert'])) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        return $query->orderBy('name')->paginate($filters['per_page'] ?? 25);
    }

    public function updateStokObat(string $id, array $data): Medication
    {
        $medication = Medication::findOrFail($id);

        $medication->update(array_filter([
            'stock'        => $data['stock'] ?? null,
            'min_stock'    => $data['min_stock'] ?? null,
            'expiry_date'  => $data['expiry_date'] ?? null,
            'batch_number' => $data['batch_number'] ?? null,
            'price'        => $data['price'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_STOK_OBAT', Medication::class, $id, "Stok diperbarui: {$medication->stock} → {$data['stock']}");

        return $medication->fresh();
    }

    // =========================================================================
    // STOK — BHP
    // =========================================================================

    public function getStokBhp(array $filters = []): LengthAwarePaginator
    {
        $query = BhpItem::query();

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$keyword}%")
                ->orWhere('code', 'ilike', "%{$keyword}%")
            );
        }

        if (! empty($filters['alert'])) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        return $query->orderBy('name')->paginate($filters['per_page'] ?? 25);
    }

    public function updateStokBhp(string $id, array $data): BhpItem
    {
        $bhp = BhpItem::findOrFail($id);

        $bhp->update(array_filter([
            'stock'     => $data['stock'] ?? null,
            'min_stock' => $data['min_stock'] ?? null,
            'price'     => $data['price'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_STOK_BHP', BhpItem::class, $id);

        return $bhp->fresh();
    }

    // =========================================================================
    // STOK — IOL
    // =========================================================================

    public function getStokIol(array $filters = []): LengthAwarePaginator
    {
        $query = IolItem::where('is_active', true);

        if (! empty($filters['available_only'])) {
            $query->where('is_used', false);
        }

        if (! empty($filters['iol_type'])) {
            $query->where('iol_type', $filters['iol_type']);
        }

        if (! empty($filters['brand'])) {
            $query->where('brand', 'ilike', "%{$filters['brand']}%");
        }

        if (! empty($filters['power'])) {
            $query->where('power', $filters['power']);
        }

        return $query->orderBy('brand')->orderBy('power')->paginate($filters['per_page'] ?? 25);
    }

    public function updateStokIol(string $id, array $data): IolItem
    {
        $iol = IolItem::findOrFail($id);

        $iol->update(array_filter([
            'brand'         => $data['brand'] ?? null,
            'model'         => $data['model'] ?? null,
            'iol_type'      => $data['iol_type'] ?? null,
            'material'      => $data['material'] ?? null,
            'power'         => $data['power'] ?? null,
            'lot_number'    => $data['lot_number'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'gs1_barcode'   => $data['gs1_barcode'] ?? null,
            'price'         => $data['price'] ?? null,
            'is_active'     => $data['is_active'] ?? null,
        ], fn ($v) => ! is_null($v)));

        $this->log(auth('api')->id(), 'UPDATE_STOK_IOL', IolItem::class, $id);

        return $iol->fresh();
    }

    // =========================================================================
    // STOK ALERT (semua tipe)
    // =========================================================================

    public function getStokAlert(): array
    {
        $obatAlert = Medication::whereColumn('stock', '<=', 'min_stock')
            ->active()
            ->get(['id', 'code', 'name', 'stock', 'min_stock', 'unit']);

        $bhpAlert = BhpItem::whereColumn('stock', '<=', 'min_stock')
            ->active()
            ->get(['id', 'code', 'name', 'stock', 'min_stock', 'unit']);

        return [
            'obat'  => $obatAlert,
            'bhp'   => $bhpAlert,
            'total' => $obatAlert->count() + $bhpAlert->count(),
        ];
    }

    // =========================================================================
    // GENERIC STOCK UPDATE (digunakan secara internal)
    // =========================================================================

    /**
     * @param  string  $type  'obat' | 'bhp'
     * @param  string  $mode  'set' | 'increment' | 'decrement'
     */
    public function updateStock(string $itemId, int $qty, string $type, string $mode = 'set'): void
    {
        $model = match ($type) {
            'obat' => Medication::class,
            'bhp'  => BhpItem::class,
            default => throw new \Exception("Tipe stok tidak dikenal: {$type}", 422),
        };

        $item = $model::findOrFail($itemId);

        match ($mode) {
            'set'       => $item->update(['stock' => $qty]),
            'increment' => $model::where('id', $itemId)->increment('stock', $qty),
            'decrement' => $model::where('id', $itemId)->decrement('stock', $qty),
            default     => throw new \Exception("Mode update stok tidak valid: {$mode}", 422),
        };

        $this->log(auth('api')->id(), 'UPDATE_STOCK', $model, $itemId, "{$type} {$mode} {$qty}");
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
