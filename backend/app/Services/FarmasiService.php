<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\InventoryStock;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Queue;
use App\Models\SurgeryRequest;
use App\Models\SurgeryRequestBhp;
use App\Models\SurgeryRequestIol;
use App\Models\SystemLog;
use App\Models\Visit;
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
            ->whereHas('visit')   // exclude zombie row (visit soft-deleted)
            ->orderBy('queue_sequence')
            ->get();
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_FARMASI)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Lewati pasien Farmasi yang tidak hadir → tukar urutan dengan pasien
     * berikutnya (turun 1). Pola sama dengan stasiun lain (Kasir/Perawat/dst).
     */
    public function lewatiAntrian(string $queueId): Queue
    {
        Queue::byStation(Queue::STATION_FARMASI)->findOrFail($queueId);
        return $this->queueService->lewati($queueId);
    }

    /**
     * Preview harga obat tambahan (di luar resep) untuk satu visit, sesuai
     * penjamin pasien — SUMBER HARGA YANG SAMA dengan yang ditagih kasir
     * (KasirService::getPrice → medication_tariffs per-insurer), BUKAN HJA POS.
     *
     * Untuk visit RANAP/IGD obat ditagih lewat inpatient_charges (bukan resep),
     * jadi tandai billed_via='RANAP' agar UI bisa memberi catatan yang benar.
     *
     * @return array{unit_price: float, billed_via: string, guarantor_type: ?string}
     */
    public function previewHargaObat(string $medicationId, ?string $visitId): array
    {
        $kasir = app(KasirService::class);

        $guarantor = 'UMUM';
        $insurerId = null;
        $billedVia = 'INVOICE';

        if ($visitId) {
            $visit = Visit::find($visitId);
            if ($visit) {
                $guarantor = $visit->guarantor_type ?: 'UMUM';
                $insurerId = $visit->insurer_id;
                // RANAP/IGD: obat masuk inpatient_charges, bukan invoice resep.
                if (in_array($visit->visit_type, ['RAWAT_INAP', 'IGD'], true)) {
                    $billedVia = $visit->visit_type === 'IGD' ? 'IGD' : 'RANAP';
                }
            }
        }

        $price = $kasir->getPrice('medication', $medicationId, $guarantor, $insurerId);

        return [
            'unit_price'     => (float) $price,
            'billed_via'     => $billedVia,
            'guarantor_type' => $guarantor,
        ];
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

        // BPJS Antrol (Sisi A): daftarkan antrean farmasi + task 6 (mulai buat obat).
        // Non-blocking — skip diam-diam bila bukan BPJS / ANTREAN nonaktif.
        $visit = $prescription->visit;
        $this->queueService->reportAntreanFarmasiAdd($visit);
        $this->queueService->reportTask($visit, 6);

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
        // (per-batch FEFO) di lokasi UNIT FARMASI (strict). BUKAN kolom legacy
        // `medications.stock`. Kalau stok unit kurang → minta transfer dari gudang.
        foreach ($prescription->items as $item) {
            if (! $item->medication) continue;
            $onHand = $this->stockService->onHand('MEDICATION', $item->medication_id, InventoryStock::LOC_FARMASI);
            if ($onHand < $item->quantity) {
                throw new \Exception(
                    "Stok unit FARMASI untuk {$item->medication->name} tidak mencukupi. Tersedia: {$onHand}, dibutuhkan: {$item->quantity}. Minta transfer dari gudang dulu.",
                    422
                );
            }
        }

        $user = auth('api')->user();

        DB::transaction(function () use ($prescription, $user) {
            // Deduct dari inventory_stocks lokasi FARMASI (FEFO, per-batch).
            // consume() throw 422 bila stok berubah & jadi tak cukup (race).
            foreach ($prescription->items as $item) {
                if ($item->medication) {
                    $this->stockService->consume('MEDICATION', $item->medication_id, (float) $item->quantity, InventoryStock::LOC_FARMASI);
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

        // BPJS Antrol task 7 = akhir obat selesai dibuat (Docs/Antrol.md:346).
        // Non-blocking — guard monoton memastikan urut 6 → 7.
        $this->queueService->reportTask($prescription->visit, 7);

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

        $userId     = auth('api')->id();
        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($prescriptionId, $items, $employeeId, $userId) {
            $created = [];
            $adaTambahan = false;
            foreach ($items as $item) {
                $source = ($item['source'] ?? 'RESEP') === 'TAMBAHAN' ? 'TAMBAHAN' : 'RESEP';

                // Item tambahan apotek: hanya obat BEBAS/BEBAS_TERBATAS + catat petugas.
                if ($source === 'TAMBAHAN') {
                    $this->assertObatBolehTambahan($item['medication_id']);
                    $adaTambahan = true;
                }

                $created[] = PrescriptionItem::create([
                    'prescription_id' => $prescriptionId,
                    'medication_id'   => $item['medication_id'],
                    'source'          => $source,
                    'added_by_id'     => $source === 'TAMBAHAN' ? $employeeId : null,
                    'quantity'        => $item['quantity'],
                    'dosage'          => $item['dosage'] ?? null,
                    'instructions'    => $item['instructions'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            if ($adaTambahan) {
                $this->log($userId, 'ADD_ITEM_TAMBAHAN', Prescription::class, $prescriptionId,
                    'Tambah obat di luar resep dokter (TAMBAHAN apotek)');
            }

            // Eloquent collection (bukan base collection) supaya ->load() valid.
            return PrescriptionItem::with('medication')
                ->whereIn('id', collect($created)->pluck('id'))
                ->get();
        });
    }

    /**
     * Penjualan obat tambahan (OTC) untuk pasien antrean Farmasi yang BELUM punya
     * resep. Buat Prescription baru atas nama petugas farmasi (prescribed_by_id),
     * status DISPENSING (skip verifikasi), semua item dipaksa source=TAMBAHAN.
     */
    public function createOtcPrescription(string $visitId, array $items): Prescription
    {
        $visit = Visit::findOrFail($visitId);

        // Hanya untuk pasien yang ada di antrean FARMASI hari ini.
        $diFarmasi = Queue::where('visit_id', $visitId)
            ->where('station', Queue::STATION_FARMASI)
            ->whereDate('created_at', today())
            ->exists();
        if (! $diFarmasi) {
            throw new \Exception('Penjualan obat tambahan hanya untuk pasien yang ada di antrean Farmasi.', 422);
        }

        $userId     = auth('api')->id();
        $employeeId = auth('api')->user()?->employee_id;

        return DB::transaction(function () use ($visit, $items, $employeeId, $userId) {
            $prescription = Prescription::create([
                'visit_id'         => $visit->id,
                'prescribed_by_id' => $employeeId,
                'status'           => 'DISPENSING',
                'notes'            => 'Pembelian obat tambahan (OTC) di apotek',
            ]);

            foreach ($items as $item) {
                $this->assertObatBolehTambahan($item['medication_id']);
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'medication_id'   => $item['medication_id'],
                    'source'          => 'TAMBAHAN',
                    'added_by_id'     => $employeeId,
                    'quantity'        => $item['quantity'],
                    'dosage'          => $item['dosage'] ?? null,
                    'instructions'    => $item['instructions'] ?? null,
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            $this->log($userId, 'CREATE_OTC_PRESCRIPTION', Prescription::class, $prescription->id,
                'Penjualan obat tambahan (OTC) — ' . count($items) . ' item');

            return $prescription->load('items.medication');
        });
    }

    /**
     * Pastikan obat boleh dijual sebagai item TAMBAHAN tanpa resep dokter.
     *
     * Hanya golongan setara BEBAS / BEBAS_TERBATAS / SUPLEMEN / JAMU yang boleh.
     * Obat KERAS/NARKOTIKA/PSIKOTROPIKA dan obat TANPA label golongan (NULL) DITOLAK
     * (konservatif — petugas wajib melengkapi golongan di master dulu).
     *
     * NB: master `golongan` di DB tidak seragam (mis. "OBAT KERAS", "OBAT BEBAS",
     * "SUPLEMEN", NULL), jadi normalisasi via kata kunci, bukan match enum persis.
     */
    private function assertObatBolehTambahan(string $medicationId): void
    {
        $med = Medication::findOrFail($medicationId);
        $g = strtoupper(trim((string) $med->golongan));

        $terlarang = $g === ''
            || str_contains($g, 'KERAS')
            || str_contains($g, 'NARKOTIKA')
            || str_contains($g, 'PSIKOTROPIKA');

        $boleh = ! $terlarang && (
            str_contains($g, 'BEBAS')
            || str_contains($g, 'SUPLEMEN')
            || str_contains($g, 'JAMU')
        );

        if (! $boleh) {
            $label = $g === '' ? 'tanpa golongan' : "golongan {$med->golongan}";
            throw new \Exception(
                "Obat {$med->name} ({$label}) tidak boleh ditambahkan tanpa resep dokter. " .
                "Hanya obat bebas/bebas terbatas/suplemen/jamu yang boleh dijual sebagai tambahan apotek. " .
                "Lengkapi golongan obat di master bila perlu.",
                422
            );
        }
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
            // Deduct BHP dari inventory_stocks lokasi UNIT BEDAH (FEFO, per-batch,
            // strict) — bukan kolom legacy bhp_items.stock. Kalau stok unit BEDAH
            // kurang → minta transfer dari gudang dulu.
            foreach ($surgeryRequest->bhpItems as $item) {
                if ($item->bhpItem) {
                    $onHand = $this->stockService->onHand('BHP', $item->bhp_item_id, InventoryStock::LOC_BEDAH);
                    if ($onHand < $item->quantity) {
                        throw new \Exception(
                            "Stok unit BEDAH untuk BHP {$item->bhpItem->name} tidak mencukupi. Tersedia: {$onHand}. Minta transfer dari gudang dulu.",
                            422
                        );
                    }
                    $this->stockService->consume('BHP', $item->bhp_item_id, (float) $item->quantity, InventoryStock::LOC_BEDAH);
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
        $query = $this->withFarmasiOnHand(Medication::query(), 'MEDICATION');

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('medications.name', 'ilike', "%{$keyword}%")
                ->orWhere('medications.code', 'ilike', "%{$keyword}%")
                ->orWhere('medications.generic_name', 'ilike', "%{$keyword}%")
            );
        }

        if (! empty($filters['formularium'])) {
            $query->where('medications.formularium', $filters['formularium']);
        }

        if (! empty($filters['alert'])) {
            $query->whereRaw('COALESCE(farmasi_stock.qty, 0) <= medications.min_stock');
        }

        $page = $query->orderBy('medications.name')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->each(fn ($m) => $m->stock = (float) $m->farmasi_qty);

        // Lampirkan HJA (Harga Jual Apotek) dari modul Penentuan Harga — dipakai
        // POS penjualan obat bebas untuk preview harga/total di UI.
        $ids = $page->getCollection()->pluck('id')->all();
        if (! empty($ids)) {
            $hjaMap = \App\Models\InventoryPrice::where('item_type', 'MEDICATION')
                ->whereIn('item_id', $ids)
                ->pluck('hja', 'item_id');
            $page->getCollection()->each(fn ($m) => $m->hja = isset($hjaMap[$m->id]) ? (float) $hjaMap[$m->id] : null);
        }

        return $page;
    }

    public function updateStokObat(string $id, array $data): Medication
    {
        $medication = Medication::findOrFail($id);

        // Atribut master (min_stock/price) tetap di tabel medications.
        $medication->update(array_filter([
            'min_stock' => $data['min_stock'] ?? null,
            'price'     => $data['price'] ?? null,
        ], fn ($v) => ! is_null($v)));

        // Stok adalah per-batch di inventory_stocks lokasi FARMASI (sumber kebenaran
        // yang dikonsumsi dispensing). Opname set-total ke lokasi FARMASI.
        if (array_key_exists('stock', $data) && $data['stock'] !== null) {
            $this->stockService->opname([
                'item_type' => 'MEDICATION',
                'item_id'   => $id,
                'location'  => InventoryStock::LOC_FARMASI,
                'new_qty'   => (float) $data['stock'],
                'reason'    => 'Koreksi stok manual (Farmasi)',
            ]);
        }

        $onHand = $this->stockService->onHand('MEDICATION', $id, InventoryStock::LOC_FARMASI);
        $medication->stock = $onHand;

        return $medication;
    }

    // =========================================================================
    // STOK — BHP
    // =========================================================================

    public function getStokBhp(array $filters = []): LengthAwarePaginator
    {
        $query = $this->withFarmasiOnHand(BhpItem::query(), 'BHP');

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('bhp_items.name', 'ilike', "%{$keyword}%")
                ->orWhere('bhp_items.code', 'ilike', "%{$keyword}%")
            );
        }

        if (! empty($filters['alert'])) {
            $query->whereRaw('COALESCE(farmasi_stock.qty, 0) <= bhp_items.min_stock');
        }

        $page = $query->orderBy('bhp_items.name')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->each(fn ($b) => $b->stock = (float) $b->farmasi_qty);

        return $page;
    }

    public function updateStokBhp(string $id, array $data): BhpItem
    {
        $bhp = BhpItem::findOrFail($id);

        $bhp->update(array_filter([
            'min_stock' => $data['min_stock'] ?? null,
            'price'     => $data['price'] ?? null,
        ], fn ($v) => ! is_null($v)));

        if (array_key_exists('stock', $data) && $data['stock'] !== null) {
            $this->stockService->opname([
                'item_type' => 'BHP',
                'item_id'   => $id,
                'location'  => InventoryStock::LOC_FARMASI,
                'new_qty'   => (float) $data['stock'],
                'reason'    => 'Koreksi stok manual (Farmasi)',
            ]);
        }

        $bhp->stock = $this->stockService->onHand('BHP', $id, InventoryStock::LOC_FARMASI);

        return $bhp;
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
        $obatAlert = $this->withFarmasiOnHand(Medication::query(), 'MEDICATION')
            ->where('medications.is_active', true)
            ->whereRaw('COALESCE(farmasi_stock.qty, 0) <= medications.min_stock')
            ->get(['medications.id', 'medications.code', 'medications.name', 'medications.min_stock', 'medications.unit'])
            ->each(fn ($m) => $m->stock = (float) $m->farmasi_qty);

        $bhpAlert = $this->withFarmasiOnHand(BhpItem::query(), 'BHP')
            ->where('bhp_items.is_active', true)
            ->whereRaw('COALESCE(farmasi_stock.qty, 0) <= bhp_items.min_stock')
            ->get(['bhp_items.id', 'bhp_items.code', 'bhp_items.name', 'bhp_items.min_stock', 'bhp_items.unit'])
            ->each(fn ($b) => $b->stock = (float) $b->farmasi_qty);

        return [
            'obat'  => $obatAlert,
            'bhp'   => $bhpAlert,
            'total' => $obatAlert->count() + $bhpAlert->count(),
        ];
    }

    /**
     * Join sub-query SUM(qty_on_hand) inventory_stocks lokasi FARMASI ke query
     * master (medications / bhp_items). Menyediakan kolom alias `farmasi_qty`
     * (stok riil unit Farmasi yang dikonsumsi dispensing) + tabel `farmasi_stock`
     * untuk dipakai di whereRaw alert. Kolom legacy `stock` di master TIDAK lagi
     * otoritatif — selalu di-overlay dengan `farmasi_qty` oleh caller.
     */
    private function withFarmasiOnHand($query, string $itemType)
    {
        $table = $query->getModel()->getTable();

        // inventory_stocks TIDAK pakai SoftDeletes — JANGAN tambah whereNull('deleted_at')
        // (kolomnya tak ada → 500, bug kelas #4/#5 di audit pra-go-live).
        $sub = DB::table('inventory_stocks')
            ->select('item_id', DB::raw('SUM(qty_on_hand) as qty'))
            ->where('item_type', $itemType)
            ->where('location', InventoryStock::LOC_FARMASI)
            ->groupBy('item_id');

        return $query
            ->leftJoinSub($sub, 'farmasi_stock', "farmasi_stock.item_id", '=', "{$table}.id")
            ->select("{$table}.*", DB::raw('COALESCE(farmasi_stock.qty, 0) as farmasi_qty'));
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
