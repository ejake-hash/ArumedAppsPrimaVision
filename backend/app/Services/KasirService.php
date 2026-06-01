<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\BillingInvoice;
use App\Models\BillingItem;
use App\Models\ClinicProfile;
use App\Models\Insurer;
use App\Models\InpatientCharge;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\Prescription;
use App\Models\Queue;
use App\Models\SurgeryIolUsage;
use App\Models\SurgeryRequest;
use App\Models\SystemLog;
use App\Models\Visit;
use App\Models\VisitCob;
use App\Models\VisitService;
use App\Services\AsuransiService;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KasirService
{
    public function __construct(
        private readonly Request $request,
        private readonly QueueService $queueService,
        private readonly AsuransiService $asuransiService,
    ) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function getPatientQueue(): Collection
    {
        return Queue::with(['visit.patient', 'visit.billingInvoice'])
            ->where('station', 'KASIR')
            ->whereDate('created_at', today())
            ->whereHas('visit')   // exclude zombie row (visit soft-deleted)
            ->orderBy('queue_sequence')
            ->get();
    }

    public function panggilAntrian(string $queueId): Queue
    {
        $queue = Queue::byStation(Queue::STATION_KASIR)->findOrFail($queueId);
        return $this->queueService->panggil($queue->id);
    }

    /**
     * Selesai antrian Kasir → FARMASI (jika ada resep) atau SELESAI.
     * Section 11.3 step 5.
     */
    public function selesaiAntrian(string $queueId): array
    {
        $queue = Queue::byStation(Queue::STATION_KASIR)->findOrFail($queueId);
        return $this->queueService->advanceFromStation($queue->id, Queue::STATION_KASIR);
    }

    /** Geser antrean Kasir ke akhir (delegasi ke QueueService::lewati). */
    public function lewatiAntrian(string $queueId): Queue
    {
        Queue::byStation(Queue::STATION_KASIR)->findOrFail($queueId);
        return $this->queueService->lewati($queueId);
    }

    // =========================================================================
    // INVOICE
    // =========================================================================

    public function getInvoiceList(array $filters = []): LengthAwarePaginator
    {
        $query = BillingInvoice::with(['visit.patient', 'cashier'])
            ->whereDate('created_at', $filters['tanggal'] ?? today());

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('invoice_number', 'ilike', "%{$keyword}%")
                ->orWhereHas('visit.patient', fn ($p) => $p
                    ->where('name', 'ilike', "%{$keyword}%")
                    ->orWhere('no_rm', 'ilike', "%{$keyword}%")
                )
            );
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 20);
    }

    public function getInvoiceByVisit(string $visitId): ?BillingInvoice
    {
        return BillingInvoice::with(['visit.patient', 'items', 'cashier'])
            ->where('visit_id', $visitId)
            ->first();
    }

    /**
     * Daftar tarif tindakan (procedure) yang harganya sudah di-resolve per
     * penjamin visit ybs — dipakai kasir saat "Edit Tagihan" untuk menambah
     * item dengan harga yang BENAR sesuai metode bayar (bukan ketik manual).
     * Mirror dari DokterService::getTarifTindakan tapi tanpa gate ownership
     * dokter (kasir bukan pemilik visit).
     *
     * @return array<array{id:string,code:?string,name:string,category:?string,price:float}>
     */
    public function getTarifTindakan(string $visitId): array
    {
        $visit = Visit::findOrFail($visitId);

        return \App\Models\Procedure::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category'])
            ->map(fn ($p) => [
                'id'       => $p->id,
                'code'     => $p->code,
                'name'     => $p->name,
                'category' => $p->category,
                'price'    => $this->getPrice('procedure', $p->id, $visit->guarantor_type, $visit->insurer_id),
            ])
            ->all();
    }

    // =========================================================================
    // CONSOLIDATE BILLING (generate invoice dari semua sumber)
    // =========================================================================

    /**
     * Build invoice from all visit sources: tindakan, obat, IOL (bedah), registrasi.
     * Applies tariff lookup with fallback logic.
     * Applies COB if configured.
     */
    public function consolidateBilling(string $visitId): BillingInvoice
    {
        $visit = Visit::with([
            'patient',
            'visitServices.procedure',
            'prescriptions.items.medication',
            'diagnosticOrders',
            'doctorExamination.surgerySchedule.surgeryRecord.iolUsages.iolItem',
            'doctorExamination.surgeryPackage.items',
            'surgeryRequests.bhpItems.bhpItem',
            'equipmentUsages.equipment',
            'visitCob.penjamin1',
            'visitCob.penjamin2',
        ])->findOrFail($visitId);

        if (BillingInvoice::where('visit_id', $visitId)->whereNotIn('status', ['CANCELLED'])->exists()) {
            throw new \Exception('Invoice sudah ada untuk kunjungan ini.', 422);
        }

        return DB::transaction(function () use ($visit) {
            // Builder pipeline — tiap source berdiri sendiri, return array baris BillingItem.
            // Tambah source baru = tambah satu method + tambah ke array_merge.
            $lines = array_merge(
                $this->buildRegistrasiLines($visit),
                $this->buildTindakanLines($visit),
                $this->buildObatLines($visit),
                $this->buildPenunjangLines($visit),
                $this->buildBhpLines($visit),
                $this->buildPaketRoomBhpLines($visit),
                $this->buildIolLines($visit),
                $this->buildEquipmentLines($visit),
                $this->buildInpatientChargeLines($visit),
            );

            $subtotal = array_sum(array_map(fn ($l) => (float) ($l['total_price'] ?? 0), $lines));

            // COB → global discount
            $discount = $this->calculateCOBDiscount($subtotal, $visit->visitCob);
            $total    = max(0, $subtotal - $discount);

            $invoiceNumber = $this->generateInvoiceNumber($visit);

            $invoice = BillingInvoice::create([
                'visit_id'       => $visit->id,
                'invoice_number' => $invoiceNumber,
                'subtotal'       => $subtotal,
                'discount'       => $discount,
                'tax'            => 0,
                'total'          => $total,
                'status'         => 'DRAFT',
            ]);

            foreach ($lines as $line) {
                BillingItem::create(array_merge($line, ['billing_invoice_id' => $invoice->id]));
            }

            // RANAP: tandai inpatient_charges yang baru ditagih agar tak dobel saat
            // (mis.) invoice di-cancel lalu konsolidasi ulang.
            if (($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP') {
                InpatientCharge::where('visit_id', $visit->id)
                    ->where('is_billed', false)
                    ->update(['is_billed' => true]);
            }

            $this->log(auth('api')->id(), 'CONSOLIDATE_BILLING', BillingInvoice::class, $invoice->id, "Invoice {$invoiceNumber} — total {$total}");

            return $invoice->load(['items', 'visit.patient']);
        });
    }

    // =========================================================================
    // BUILDERS — satu method per sumber, dipanggil dari consolidateBilling.
    // Tiap builder return array<array> siap di-create sebagai BillingItem.
    // =========================================================================

    private function buildRegistrasiLines(Visit $visit): array
    {
        // Default flat Rp 50.000 — bisa diganti ke tariff lookup kalau master Registrasi dibuat.
        $price = 50000;
        return [[
            'item_type'    => 'REGISTRASI',
            'category'     => 'Registrasi',
            'reference_id' => $visit->id,
            'description'  => 'Biaya Pendaftaran',
            'quantity'     => 1,
            'unit_price'   => $price,
            'total_price'  => $price,
            'net_price'    => $price,
        ]];
    }

    private function buildTindakanLines(Visit $visit): array
    {
        $lines = [];
        foreach ($visit->visitServices as $vs) {
            $price = $this->getPrice('procedure', $vs->procedure_id, $visit->guarantor_type, $visit->insurer_id);
            $total = $price * $vs->quantity;
            $lines[] = [
                'item_type'    => 'TINDAKAN',
                'category'     => $vs->procedure?->category ?: 'Tindakan',
                'reference_id' => $vs->id,
                'description'  => $vs->procedure?->name ?? 'Tindakan',
                'quantity'     => $vs->quantity,
                'unit_price'   => $price,
                'total_price'  => $total,
                'net_price'    => $total,
            ];
        }
        return $lines;
    }

    private function buildObatLines(Visit $visit): array
    {
        // RANAP: obat (termasuk obat pulang) ditagih lewat inpatient_charges type OBAT
        // (buildInpatientChargeLines) — resep RANAP hanya untuk antrean Farmasi & potong
        // stok, BUKAN sumber tagihan. Skip di sini agar tidak dobel-tagih.
        if (($visit->jenis_pelayanan ?? 'RAJAL') === 'RANAP') {
            return [];
        }

        // Alur RAJAL/Bedah: DOKTER → KASIR → FARMASI. Kasir konsolidasi billing
        // SEBELUM farmasi men-dispense, jadi resep masih DRAFT/SUBMITTED (belum
        // DISPENSED). Tagih semua resep yang BUKAN CANCELLED — kalau hanya menagih
        // DISPENSED, obat pulang RAJAL/Bedah tak pernah masuk invoice (pasien pulang
        // tanpa dibayar). DRAFT pun ditagih: resep dokter dibuat status DRAFT dan
        // tetap DRAFT sampai farmasi memprosesnya (tak ada langkah submit terpisah
        // untuk RAJAL — lihat DokterService::storePrescription & FarmasiService::startDispensing).
        $lines = [];
        foreach ($visit->prescriptions as $prescription) {
            if ($prescription->status === 'CANCELLED') {
                continue;
            }
            foreach ($prescription->items as $item) {
                // Obat operasi yang sudah tercakup paket bedah (is_bedah) ditagih lewat
                // builder paket — jangan dobel-tagih di sini.
                if ($item->is_bedah) {
                    continue;
                }
                $price = $this->getPrice('medication', $item->medication_id, $visit->guarantor_type, $visit->insurer_id);
                $total = $price * $item->quantity;
                $lines[] = [
                    'item_type'    => 'OBAT',
                    'category'     => 'Obat',
                    'reference_id' => $item->id,
                    'description'  => $item->medication?->name ?? 'Obat',
                    'quantity'     => $item->quantity,
                    'unit_price'   => $price,
                    'total_price'  => $total,
                    'net_price'    => $total,
                    'notes'        => $item->dosage,
                ];
            }
        }
        return $lines;
    }

    /**
     * Penunjang: visit.diagnosticOrders status COMPLETED → tarif via procedure_tariffs.
     * Penunjang = procedure kategori "Penunjang": `diagnostic_orders.test_type` menyimpan
     * KODE procedure (mis. "PNJ-001") → lookup ke procedures.code untuk dapat id, lalu
     * getPrice('procedure', ...). Label tetap item_type PENUNJANG.
     */
    private function buildPenunjangLines(Visit $visit): array
    {
        $lines = [];
        $orders = $visit->diagnosticOrders->where('status', 'COMPLETED');
        if ($orders->isEmpty()) {
            return $lines;
        }

        $codes = $orders->pluck('test_type')->unique()->filter()->values()->all();
        // Penunjang = procedure kategori "Penunjang": test_type menyimpan KODE procedure.
        // Map kode → procedure (id, name, category) supaya hemat query.
        $procMap = \App\Models\Procedure::whereIn('code', $codes)
            ->get(['id', 'code', 'name', 'category'])
            ->keyBy('code');

        foreach ($orders as $order) {
            $proc = $procMap->get($order->test_type);
            if (! $proc) {
                continue; // kode tidak terdaftar di procedures — skip (mis. order "Lainnya")
            }
            $price = $this->getPrice('procedure', $proc->id, $visit->guarantor_type, $visit->insurer_id);
            $label = $proc->name ?? $order->test_type;
            $desc  = $order->eye_side ? "{$label} ({$order->eye_side})" : $label;
            $lines[] = [
                'item_type'    => 'PENUNJANG',
                'category'     => $proc->category ?: 'Penunjang',
                'reference_id' => $order->id,
                'description'  => $desc,
                'quantity'     => 1,
                'unit_price'   => $price,
                'total_price'  => $price,
                'net_price'    => $price,
            ];
        }
        return $lines;
    }

    private function buildBhpLines(Visit $visit): array
    {
        $lines = [];
        // Hanya request berstatus RECEIVED dan baris dengan used_qty > 0.
        foreach ($visit->surgeryRequests as $surgeryReq) {
            if ($surgeryReq->status !== 'RECEIVED') {
                continue;
            }
            foreach ($surgeryReq->bhpItems as $bhp) {
                $usedQty = (int) ($bhp->used_qty ?? 0);
                if ($usedQty <= 0) {
                    continue;
                }
                $price = $this->getPrice('bhp', $bhp->bhp_item_id, $visit->guarantor_type, $visit->insurer_id);
                $total = $price * $usedQty;
                $label = $bhp->bhpItem?->name ?? 'BHP';
                $cat   = $bhp->bhpItem?->category;
                $lines[] = [
                    'item_type'    => 'BHP',
                    'category'     => $cat ?: 'BHP',
                    'reference_id' => $bhp->id,
                    'description'  => $cat ? "{$label} [{$cat}]" : $label,
                    'quantity'     => $usedQty,
                    'unit_price'   => $price,
                    'total_price'  => $total,
                    'net_price'    => $total,
                ];
            }
        }
        return $lines;
    }

    /**
     * BHP "kamar bedah" dari komposisi PAKET — kategori CSSD & INSTRUMENT_SET.
     *
     * Item ini ada fisik di kamar bedah (TIDAK diminta ke gudang lewat unit-request),
     * tapi tetap ditagih sesuai paket. Sumber: surgery_package.items kategori
     * CSSD/INSTRUMENT_SET. Anti dobel-tagih: lewati BHP yang sudah masuk via
     * buildBhpLines (jalur used_qty surgery_requests).
     */
    private function buildPaketRoomBhpLines(Visit $visit): array
    {
        $package = $visit->doctorExamination?->surgeryPackage;
        if (! $package) {
            return [];
        }

        // BHP yang sudah ditagih lewat pemakaian operasi (used_qty) — jangan dobel.
        $alreadyBilled = [];
        foreach ($visit->surgeryRequests as $req) {
            if ($req->status !== 'RECEIVED') continue;
            foreach ($req->bhpItems as $bhp) {
                if ((int) ($bhp->used_qty ?? 0) > 0) {
                    $alreadyBilled[$bhp->bhp_item_id] = true;
                }
            }
        }

        $roomCategories = [BhpItem::CATEGORY_CSSD, BhpItem::CATEGORY_INSTRUMENT_SET];

        $lines = [];
        foreach ($package->items as $pi) {
            if ($pi->item_type !== 'BHP') continue;
            if (isset($alreadyBilled[$pi->item_id])) continue;

            $bhp = BhpItem::find($pi->item_id);
            if (! $bhp || ! in_array($bhp->category, $roomCategories, true)) {
                continue;
            }

            $qty   = (int) ($pi->quantity ?? 1);
            $price = $this->getPrice('bhp', $bhp->id, $visit->guarantor_type, $visit->insurer_id);
            $total = $price * $qty;
            $cat   = $bhp->category;
            $lines[] = [
                'item_type'    => 'BHP',
                'category'     => $cat,
                'reference_id' => $pi->id,
                'description'  => "{$bhp->name} [{$cat}]",
                'quantity'     => $qty,
                'unit_price'   => $price,
                'total_price'  => $total,
                'net_price'    => $total,
            ];
        }
        return $lines;
    }

    private function buildIolLines(Visit $visit): array
    {
        $lines = [];
        $record = $visit->doctorExamination?->surgerySchedule?->surgeryRecord;
        if (! $record) {
            return $lines;
        }
        foreach ($record->iolUsages as $iolUsage) {
            $price = $this->getPrice('iol', $iolUsage->iol_item_id, $visit->guarantor_type, $visit->insurer_id);
            $lines[] = [
                'item_type'    => 'IOL',
                'category'     => 'IOL',
                'reference_id' => $iolUsage->id,
                'description'  => "IOL {$iolUsage->brand} {$iolUsage->model} P{$iolUsage->power} ({$iolUsage->eye_side})",
                'quantity'     => 1,
                'unit_price'   => $price,
                'total_price'  => $price,
                'net_price'    => $price,
            ];
        }
        return $lines;
    }

    private function buildEquipmentLines(Visit $visit): array
    {
        $lines = [];
        // Flat fee per pemakaian. Tarif Rp 0 → skip (mis. BPJS yg sudah include di INA-CBGs).
        foreach ($visit->equipmentUsages as $usage) {
            $price = $this->getPrice('equipment', $usage->medical_equipment_id, $visit->guarantor_type, $visit->insurer_id);
            if ($price <= 0) {
                continue;
            }
            $eq    = $usage->equipment;
            $label = $eq ? trim(($eq->name ?? '') . ($eq->brand ? " ({$eq->brand})" : '')) : 'Alat Medis';
            $cat   = $eq?->category;
            $lines[] = [
                'item_type'    => 'MEDICAL_EQUIPMENT',
                'category'     => $cat ?: 'Alat Kesehatan',
                'reference_id' => $usage->id,
                'description'  => "Pemakaian {$label}",
                'quantity'     => 1,
                'unit_price'   => $price,
                'total_price'  => $price,
                'net_price'    => $price,
            ];
        }
        return $lines;
    }

    /**
     * RANAP — biaya inap dari inpatient_charges yang belum ditagih (is_billed=false):
     * kamar/LOS + visite + tindakan/obat/BHP/penunjang/lainnya yang dicatat manual
     * via modal RANAP. Return [] untuk visit non-RANAP (zero-diff alur rawat jalan).
     *
     * Sumber kebenaran harga = inpatient_charges (sudah di-resolve getPrice saat dicatat),
     * jadi builder ini TIDAK lookup tarif ulang. Baris yang dipakai ditandai is_billed=true
     * di dalam transaksi consolidate (lihat consolidateBilling) agar tak dobel-tagih.
     */
    private function buildInpatientChargeLines(Visit $visit): array
    {
        if (($visit->jenis_pelayanan ?? 'RAJAL') !== 'RANAP') {
            return [];
        }

        $charges = InpatientCharge::where('visit_id', $visit->id)
            ->where('is_billed', false)
            ->orderBy('charge_date')
            ->get();

        // Map charge_type RANAP → item_type/category invoice (label tetap informatif).
        $map = [
            InpatientCharge::TYPE_ROOM      => ['ROOM',      'Kamar Rawat Inap'],
            InpatientCharge::TYPE_VISITE    => ['VISITE',    'Visite Dokter'],
            InpatientCharge::TYPE_TINDAKAN  => ['TINDAKAN',  'Tindakan'],
            InpatientCharge::TYPE_OBAT      => ['OBAT',      'Obat'],
            InpatientCharge::TYPE_BHP       => ['BHP',       'BHP'],
            InpatientCharge::TYPE_PENUNJANG => ['PENUNJANG', 'Penunjang'],
            InpatientCharge::TYPE_LAINNYA   => ['LAINNYA',   'Lainnya'],
        ];

        $lines = [];
        foreach ($charges as $c) {
            [$itemType, $category] = $map[$c->charge_type] ?? ['LAINNYA', 'Lainnya'];
            $total = (float) $c->total_price;
            $lines[] = [
                'item_type'    => $itemType,
                'category'     => $category,
                'reference_id' => $c->id,
                'description'  => $c->description,
                'quantity'     => (float) $c->quantity,
                'unit_price'   => (float) $c->unit_price,
                'total_price'  => $total,
                'net_price'    => $total,
            ];
        }
        return $lines;
    }

    /**
     * Tariff lookup by insurer (post drop_classification).
     *
     * Resolve order:
     *   1. Pakai visit.insurer_id (resolve TPA: child → parent via tariffInsurerId()).
     *   2. Bila NULL → resolve insurer sistem dari guarantor_type (UMUM/BPJS/SOSIAL).
     *   3. Fallback: insurer sistem UMUM.
     */
    public function getPrice(string $itemType, ?string $itemId, string $guarantorType, ?string $insurerId): float
    {
        if (! $itemId) {
            return 0;
        }

        [$table, $fkColumn] = match ($itemType) {
            'procedure'       => ['procedure_tariffs',            'procedure_id'],
            'medication'      => ['medication_tariffs',           'medication_id'],
            'bhp'             => ['bhp_tariffs',                  'bhp_item_id'],
            'iol'             => ['iol_tariffs',                  'iol_item_id'],
            'equipment'       => ['medical_equipment_tariffs',    'medical_equipment_id'],
            // RANAP: tarif kamar di-key oleh KELAS HAK (room_class), bukan UUID.
            // Resolusi insurer + fallback UMUM sama dengan tipe lain.
            'room'            => ['room_tariffs',                 'room_class'],
            default           => throw new \Exception("Item type tidak dikenal: {$itemType}", 422),
        };

        $resolvedInsurerId = $this->resolveTariffInsurerId($insurerId, $guarantorType);
        if (! $resolvedInsurerId) {
            return 0;
        }

        $baseQuery = DB::table($table)
            ->where($fkColumn, $itemId)
            ->where('is_active', true);

        // Level 1: insurer terpilih (sudah di-resolve TPA parent).
        $tariff = (clone $baseQuery)->where('insurer_id', $resolvedInsurerId)->value('price');
        if ($tariff !== null) {
            return (float) $tariff;
        }

        // Level 2: fallback ke insurer sistem UMUM.
        $umumId = $this->systemInsurerId('UMUM');
        if ($umumId && $umumId !== $resolvedInsurerId) {
            $tariff = (clone $baseQuery)->where('insurer_id', $umumId)->value('price');
            if ($tariff !== null) {
                return (float) $tariff;
            }
        }

        return 0;
    }

    /**
     * Resolve insurer_id untuk lookup tarif. Mengembalikan parent_id bila child TPA,
     * atau insurer sistem (UMUM/BPJS/SOSIAL) bila visit belum di-link ke insurer eksplisit.
     */
    private function resolveTariffInsurerId(?string $insurerId, string $guarantorType): ?string
    {
        if ($insurerId) {
            $insurer = Insurer::find($insurerId);
            if ($insurer) {
                return $insurer->tariffInsurerId();
            }
        }

        return $this->systemInsurerId(in_array($guarantorType, ['UMUM', 'BPJS', 'SOSIAL'], true) ? $guarantorType : 'UMUM');
    }

    /** Cache id insurer sistem (UMUM/BPJS/SOSIAL) untuk hindari query berulang. */
    private array $systemInsurerCache = [];
    private function systemInsurerId(string $type): ?string
    {
        if (! array_key_exists($type, $this->systemInsurerCache)) {
            $this->systemInsurerCache[$type] = Insurer::where('is_system', true)->where('type', $type)->value('id');
        }
        return $this->systemInsurerCache[$type];
    }

    /**
     * COB: Penjamin 1 bayar dulu, Penjamin 2 cover selisih.
     * Returns discount amount = apa yang ditanggung penjamin (tidak dibayar pasien).
     */
    public function calculateCOBDiscount(float $subtotal, ?VisitCob $cob): float
    {
        if (! $cob || ! $cob->is_active) {
            return 0;
        }

        // Jika ada penjamin 2 → asumsikan penjamin 1 cover sebagian, sisanya ke penjamin 2
        // Untuk klinik kecil: implementasi sederhana — semua ditanggung penjamin (diskon = subtotal)
        // Bisa dikembangkan dengan plafon per penjamin jika dibutuhkan

        return 0; // Diisi saat COB logic lebih detail diimplementasikan
    }

    public function calculateCOB(float $totalAmount, ?VisitCob $cob): array
    {
        if (! $cob || ! $cob->is_active) {
            return [
                'penjamin1_amount' => 0,
                'penjamin2_amount' => 0,
                'patient_amount'   => $totalAmount,
            ];
        }

        // Placeholder — implementasi plafon per penjamin saat policy COB tersedia
        return [
            'penjamin1_type'   => $cob->penjamin1_type,
            'penjamin1_amount' => 0,
            'penjamin2_type'   => $cob->penjamin2_type,
            'penjamin2_amount' => 0,
            'patient_amount'   => $totalAmount,
        ];
    }

    // =========================================================================
    // INVOICE CRUD
    // =========================================================================

    public function updateInvoice(string $id, array $data): BillingInvoice
    {
        $invoice = BillingInvoice::findOrFail($id);

        if (in_array($invoice->status, ['PAID', 'CANCELLED'])) {
            throw new \Exception('Invoice sudah lunas atau dibatalkan, tidak bisa diubah.', 422);
        }

        $patch = array_filter([
            'discount'         => $data['discount']         ?? null,
            'discount_percent' => $data['discount_percent'] ?? null,
            'tax'              => $data['tax']              ?? null,
            'notes'            => $data['notes']            ?? null,
        ], fn ($v) => ! is_null($v));

        // Bila user kirim discount_percent → hitung discount nominal dari subtotal-after-item-discount
        if (isset($patch['discount_percent']) && ! isset($patch['discount'])) {
            $itemDiscount   = (float) $invoice->items()->sum('discount_amount');
            $subtotalAfter  = max(0, (float) $invoice->subtotal - $itemDiscount);
            $patch['discount'] = round($subtotalAfter * ((float) $patch['discount_percent']) / 100, 2);
        }

        $invoice->update($patch);

        $this->recalculateInvoice($invoice);

        $this->log(auth('api')->id(), 'UPDATE_INVOICE', BillingInvoice::class, $id);

        return $invoice->fresh(['items']);
    }

    public function finalizeInvoice(string $id): BillingInvoice
    {
        $invoice = BillingInvoice::findOrFail($id);

        if ($invoice->status !== 'DRAFT') {
            throw new \Exception('Hanya invoice DRAFT yang bisa di-finalize.', 422);
        }

        $invoice->update(['status' => 'FINALIZED']);

        $this->log(auth('api')->id(), 'FINALIZE_INVOICE', BillingInvoice::class, $id);

        return $invoice->fresh(['items', 'visit.patient']);
    }

    public function cancelInvoice(string $id): BillingInvoice
    {
        $invoice = BillingInvoice::findOrFail($id);

        if ($invoice->status === 'PAID') {
            throw new \Exception('Invoice yang sudah dibayar tidak bisa dibatalkan.', 422);
        }

        $invoice->update(['status' => 'CANCELLED']);

        $this->log(auth('api')->id(), 'CANCEL_INVOICE', BillingInvoice::class, $id);

        return $invoice->fresh();
    }

    // =========================================================================
    // PAYMENT
    // =========================================================================

    /**
     * Process payment → mark invoice PAID/PARTIALLY_PAID.
     * Mark visit SELESAI and complete KASIR queue.
     */
    public function processPayment(string $invoiceId, array $data): BillingInvoice
    {
        $invoice = BillingInvoice::with('visit')->findOrFail($invoiceId);

        if (! in_array($invoice->status, ['FINALIZED', 'PARTIALLY_PAID'])) {
            throw new \Exception('Invoice harus dalam status FINALIZED atau PARTIALLY_PAID untuk diproses.', 422);
        }

        $paidAmount = (float) $data['paid_amount'];

        if ($paidAmount <= 0) {
            throw new \Exception('Nominal bayar harus lebih dari 0.', 422);
        }

        $user = auth('api')->user();

        $fresh = DB::transaction(function () use ($invoice, $data, $paidAmount, $user) {
            $totalPaid   = $invoice->paid_amount + $paidAmount;
            // Tagihan dianggap lunas bila pembayaran pasien + porsi ditanggung asuransi
            // (covered_amount) sudah menutup total. Untuk pasien umum covered = 0.
            $isFullyPaid = ($totalPaid + (float) $invoice->covered_amount) >= $invoice->total;

            $invoice->update([
                'paid_amount'    => $totalPaid,
                'payment_method' => $data['payment_method'],
                'status'         => $isFullyPaid ? 'PAID' : 'PARTIALLY_PAID',
                'paid_at'        => $isFullyPaid ? now() : $invoice->paid_at,
                'cashier_id'     => $user->employee_id,
                'notes'          => $data['notes'] ?? $invoice->notes,
            ]);

            if ($isFullyPaid) {
                // Delegate ke QueueService::advanceFromStation supaya routing FARMASI vs SELESAI
                // di-handle satu tempat (nextAfterKasir cek prescription DRAFT/SUBMITTED/DISPENSING)
                // + TV broadcast jalan benar.
                $kasirQueue = Queue::where('visit_id', $invoice->visit_id)
                    ->where('station', 'KASIR')
                    ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                    ->first();
                if ($kasirQueue) {
                    $this->queueService->advanceFromStation($kasirQueue->id, Queue::STATION_KASIR);
                } else {
                    // Tidak ada queue KASIR aktif (pasien dibayar dari non-queue flow) — set manual.
                    $invoice->visit->update(['current_station' => 'SELESAI']);
                }

                // Auto-draft klaim TPA non-BPJS jika visit guarantor ASURANSI/PERUSAHAAN
                // dan sudah VERIFIED. BPJS punya alurnya sendiri (KlaimService), tidak disentuh.
                $this->maybeCreateInsuranceClaimDraft($invoice);
            }

            $this->log(
                $user->id,
                'PROCESS_PAYMENT',
                BillingInvoice::class,
                $invoice->id,
                "Bayar {$paidAmount} via {$data['payment_method']} — status: " . ($isFullyPaid ? 'PAID' : 'PARTIALLY_PAID')
            );

            return $invoice->fresh(['items', 'visit.patient', 'cashier']);
        });

        // LPK BPJS post-commit (non-blocking): kirim Lembar Pengajuan Klaim ke VClaim
        // saat kunjungan BPJS lunas & punya SEP. Gagal/credential-kosong tidak ganggu.
        if ($fresh->status === 'PAID') {
            $this->maybeSubmitLpkBpjs($fresh->visit_id);
        }

        return $fresh;
    }

    /**
     * Kirim LPK ke VClaim untuk kunjungan BPJS yang sudah punya no_sep.
     * Membentuk t_lpk dari diagnosa & tindakan dokter. Non-blocking total.
     */
    private function maybeSubmitLpkBpjs(?string $visitId): void
    {
        try {
            if (! $visitId) {
                return;
            }
            $visit = \App\Models\Visit::with(['doctorExamination', 'doctorSchedule.employee'])->find($visitId);
            if (! $visit || $visit->guarantor_type !== 'BPJS' || empty($visit->no_sep)) {
                return;
            }

            $vclaim = app(\App\Services\BpjsVClaimService::class);
            if (! $vclaim->isEnabled()) {
                return;
            }

            $exam     = $visit->doctorExamination;
            $kodePoli = \App\Models\BpjsPoliMapping::bpjsCodeFor($visit->doctorSchedule?->poli_code);
            $kodeDpjp = $visit->doctorSchedule?->employee?->bpjs_dpjp_code;

            // Diagnosa: utama (level 1) + sekunder (level 2).
            $diagnosa = [];
            if ($exam?->diagnosis_utama) {
                $diagnosa[] = ['kode' => $exam->diagnosis_utama, 'level' => '1'];
            }
            foreach ((array) ($exam?->diagnosis_sekunder ?? []) as $kode) {
                if ($kode) $diagnosa[] = ['kode' => $kode, 'level' => '2'];
            }

            // Procedure: ICD-9 dari tindakan_codes.
            $procedure = array_values(array_filter(array_map(
                fn ($k) => $k ? ['kode' => $k] : null,
                (array) ($exam?->tindakan_codes ?? [])
            )));

            // Diagnosa wajib minimal 1 — kalau dokter belum isi, skip (tidak kirim LPK kosong).
            if (empty($diagnosa)) {
                return;
            }

            $today = now('Asia/Jakarta')->toDateString();
            $vclaim->insertLpk([
                'noSep'      => $visit->no_sep,
                'tglMasuk'   => $today,
                'tglKeluar'  => $today,
                'jaminan'    => '1',
                'poli'       => ['poli' => $kodePoli ?? ''],
                'perawatan'  => ['ruangRawat' => '', 'kelasRawat' => '', 'spesialistik' => '', 'caraKeluar' => '1', 'kondisiPulang' => '1'],
                'diagnosa'   => $diagnosa,
                'procedure'  => $procedure,
                'rencanaTL'  => ['tindakLanjut' => '1', 'dirujukKe' => ['kodePPK' => ''], 'kontrolKembali' => ['tglKontrol' => '', 'poli' => '']],
                'DPJP'       => $kodeDpjp ?? '',
                'user'       => auth('api')->user()?->name ?? 'arumed',
            ], $visit->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BPJS LPK gagal: ' . $e->getMessage());
        }
    }

    /**
     * Konfirmasi tagihan yang DITANGGUNG PENUH asuransi/TPA — pasien tidak membayar.
     * Kasir hanya menekan "Konfirmasi". Invoice ditandai PAID dengan payment_method
     * INSURANCE, paid_amount tetap 0, covered_amount = total. Pendapatan asuransi
     * tetap terpisah dari pendapatan tunai pada laporan.
     */
    public function confirmInsuranceCoverage(string $invoiceId, array $data = []): BillingInvoice
    {
        $invoice = BillingInvoice::with('visit')->findOrFail($invoiceId);

        if (! in_array($invoice->status, ['FINALIZED', 'PARTIALLY_PAID'])) {
            throw new \Exception('Invoice harus dalam status FINALIZED atau PARTIALLY_PAID untuk dikonfirmasi.', 422);
        }

        // Sisa yang harus ditanggung pasien setelah cover & pembayaran sebelumnya.
        $patientDue = (float) $invoice->total - (float) $invoice->covered_amount - (float) $invoice->paid_amount;
        if ($patientDue > 0.009) {
            throw new \Exception(
                'Masih ada sisa Rp ' . number_format($patientDue, 0, ',', '.') . ' yang harus dibayar pasien. Gunakan proses pembayaran biasa.',
                422
            );
        }

        $user = auth('api')->user();

        return DB::transaction(function () use ($invoice, $data, $user) {
            // covered_amount minimal harus menutup total (full cover). Naikkan bila perlu.
            $covered = max((float) $invoice->covered_amount, (float) $invoice->total - (float) $invoice->paid_amount);

            $invoice->update([
                'covered_amount' => $covered,
                'covered_by'     => $user->id,
                'covered_at'     => $invoice->covered_at ?? now(),
                'payment_method' => 'INSURANCE',
                'status'         => 'PAID',
                'paid_at'        => now(),
                'cashier_id'     => $user->employee_id,
                'notes'          => $data['notes'] ?? $invoice->notes,
            ]);

            $kasirQueue = Queue::where('visit_id', $invoice->visit_id)
                ->where('station', 'KASIR')
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->first();
            if ($kasirQueue) {
                $this->queueService->advanceFromStation($kasirQueue->id, Queue::STATION_KASIR);
            } else {
                $invoice->visit->update(['current_station' => 'SELESAI']);
            }

            $this->maybeCreateInsuranceClaimDraft($invoice);

            $this->log(
                $user->id,
                'CONFIRM_INSURANCE_COVERAGE',
                BillingInvoice::class,
                $invoice->id,
                "Ditanggung asuransi Rp {$covered} — status: PAID (INSURANCE)"
            );

            return $invoice->fresh(['items', 'visit.patient', 'cashier']);
        });
    }

    /**
     * Konfirmasi kunjungan BPJS — pasien TIDAK membayar di kasir. Tagihan
     * diselesaikan via klaim INA-CBG (alur KlaimService terpisah), bukan
     * pembayaran tunai. Kasir hanya menekan "Konfirmasi". Invoice ditandai
     * PAID dengan payment_method BPJS, paid_amount = 0 (tidak menambah kas).
     *
     * BEDA dgn confirmInsuranceCoverage: TIDAK set covered_amount dan TIDAK
     * membuat draft klaim TPA (BPJS punya jalur klaim sendiri).
     */
    public function confirmBpjsCoverage(string $invoiceId, array $data = []): BillingInvoice
    {
        $invoice = BillingInvoice::with('visit')->findOrFail($invoiceId);

        if ($invoice->status === 'PAID' || $invoice->status === 'CANCELLED') {
            throw new \Exception('Invoice sudah lunas atau dibatalkan.', 422);
        }

        $guarantor = strtoupper((string) ($invoice->visit?->guarantor_type ?? ''));
        if ($guarantor !== 'BPJS') {
            throw new \Exception('Konfirmasi BPJS hanya untuk kunjungan dengan penjamin BPJS.', 422);
        }

        $user = auth('api')->user();

        return DB::transaction(function () use ($invoice, $data, $user) {
            // Finalize dulu kalau masih DRAFT (kasir konfirmasi langsung tanpa step terpisah).
            if ($invoice->status === 'DRAFT') {
                $invoice->update(['status' => 'FINALIZED']);
            }

            $invoice->update([
                'payment_method' => 'BPJS',
                'status'         => 'PAID',
                'paid_amount'    => 0,
                'paid_at'        => now(),
                'cashier_id'     => $user->employee_id,
                'notes'          => $data['notes'] ?? $invoice->notes,
            ]);

            $kasirQueue = Queue::where('visit_id', $invoice->visit_id)
                ->where('station', 'KASIR')
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->first();
            if ($kasirQueue) {
                $this->queueService->advanceFromStation($kasirQueue->id, Queue::STATION_KASIR);
            } else {
                $invoice->visit->update(['current_station' => 'SELESAI']);
            }

            $this->log(
                $user->id,
                'CONFIRM_BPJS_COVERAGE',
                BillingInvoice::class,
                $invoice->id,
                "Kunjungan BPJS dikonfirmasi — status: PAID (BPJS), ditagih via klaim INA-CBG"
            );

            return $invoice->fresh(['items', 'visit.patient', 'cashier']);
        });
    }

    // =========================================================================
    // BILLING ITEMS
    // =========================================================================

    public function storeItemInvoice(string $invoiceId, array $data): BillingItem
    {
        $invoice = BillingInvoice::findOrFail($invoiceId);

        if (in_array($invoice->status, ['PAID', 'CANCELLED'])) {
            throw new \Exception('Invoice sudah final, tidak bisa tambah item.', 422);
        }

        $qty       = $data['quantity']   ?? 1;
        $unitPrice = $data['unit_price'] ?? 0;
        $totalPrice = $unitPrice * $qty;
        [$discAmt, $discPc] = $this->computeItemDiscount($totalPrice, $data['discount_amount'] ?? null, $data['discount_percent'] ?? null);
        $netPrice  = max(0, $totalPrice - $discAmt);

        $item = BillingItem::create([
            'billing_invoice_id' => $invoiceId,
            'item_type'          => $data['item_type'],
            'category'           => $data['category'] ?? null,
            'reference_id'       => $data['reference_id'] ?? null,
            'description'        => $data['description'],
            'quantity'           => $qty,
            'unit_price'         => $unitPrice,
            'total_price'        => $totalPrice,
            'discount_amount'    => $discAmt,
            'discount_percent'   => $discPc,
            'net_price'          => $netPrice,
            'notes'              => $data['notes'] ?? null,
        ]);

        $this->recalculateInvoice($invoice);

        return $item;
    }

    /**
     * Hitung pasangan (discount_amount, discount_percent) untuk satu baris.
     * Bila amount diisi → percent dihitung. Bila percent diisi → amount dihitung.
     * Bila keduanya kosong → 0/0.
     */
    private function computeItemDiscount(float $totalPrice, $amount, $percent): array
    {
        if (! is_null($amount)) {
            $amt = max(0, min((float) $amount, $totalPrice));
            $pc  = $totalPrice > 0 ? round($amt / $totalPrice * 100, 2) : 0;
            return [$amt, $pc];
        }
        if (! is_null($percent)) {
            $pc  = max(0, min((float) $percent, 100));
            $amt = round($totalPrice * $pc / 100, 2);
            return [$amt, $pc];
        }
        return [0.0, 0.0];
    }

    public function updateItemInvoice(string $id, array $data): BillingItem
    {
        $item    = BillingItem::with('billingInvoice')->findOrFail($id);
        $invoice = $item->billingInvoice;

        if (in_array($invoice->status, ['PAID', 'CANCELLED'])) {
            throw new \Exception('Invoice sudah final, tidak bisa ubah item.', 422);
        }

        $qty        = $data['quantity'] ?? $item->quantity;
        $unitPrice  = $data['unit_price'] ?? $item->unit_price;
        $totalPrice = $unitPrice * $qty;

        // Diskon: jika field dikirim → recompute. Kalau kedua field tidak ada di payload, pertahankan existing.
        $hasDisc = array_key_exists('discount_amount', $data) || array_key_exists('discount_percent', $data);
        if ($hasDisc) {
            [$discAmt, $discPc] = $this->computeItemDiscount(
                $totalPrice,
                $data['discount_amount']  ?? null,
                $data['discount_percent'] ?? null,
            );
        } else {
            // qty/unit_price berubah → jaga konsistensi: pakai persen lama.
            $pc      = (float) $item->discount_percent;
            $discAmt = round($totalPrice * $pc / 100, 2);
            $discPc  = $pc;
        }
        $netPrice = max(0, $totalPrice - $discAmt);

        $item->update([
            'description'      => $data['description'] ?? $item->description,
            'category'         => array_key_exists('category', $data) ? $data['category'] : $item->category,
            'quantity'         => $qty,
            'unit_price'       => $unitPrice,
            'total_price'      => $totalPrice,
            'discount_amount'  => $discAmt,
            'discount_percent' => $discPc,
            'net_price'        => $netPrice,
            'notes'            => $data['notes'] ?? $item->notes,
        ]);

        $this->recalculateInvoice($invoice);

        return $item->fresh();
    }

    public function deleteItemInvoice(string $id): void
    {
        $item    = BillingItem::with('billingInvoice')->findOrFail($id);
        $invoice = $item->billingInvoice;

        if (in_array($invoice->status, ['PAID', 'CANCELLED'])) {
            throw new \Exception('Invoice sudah final, tidak bisa hapus item.', 422);
        }

        $item->delete();

        $this->recalculateInvoice($invoice);
    }

    // =========================================================================
    // COB
    // =========================================================================

    public function getCob(string $visitId): ?VisitCob
    {
        return VisitCob::with(['penjamin1', 'penjamin2'])
            ->where('visit_id', $visitId)
            ->active()
            ->first();
    }

    public function updateCob(string $visitId, array $data): VisitCob
    {
        $cob = VisitCob::updateOrCreate(
            ['visit_id' => $visitId],
            [
                'penjamin1_type'       => $data['penjamin1_type'],
                'penjamin1_insurer_id' => $data['penjamin1_insurer_id'] ?? null,
                'penjamin2_type'       => $data['penjamin2_type'] ?? null,
                'penjamin2_insurer_id' => $data['penjamin2_insurer_id'] ?? null,
                'is_active'            => true,
                'notes'                => $data['notes'] ?? null,
            ]
        );

        $this->log(auth('api')->id(), 'UPDATE_COB', VisitCob::class, $cob->id, "COB updated untuk kunjungan {$visitId}");

        return $cob->fresh(['penjamin1', 'penjamin2']);
    }

    // =========================================================================
    // WATERMARK
    // =========================================================================

    public function updateWatermark(array $data): void
    {
        ClinicProfile::query()->update([
            'watermark_enabled' => $data['watermark_enabled'],
            'watermark_type'    => $data['watermark_type'] ?? 'ORIGINAL',
        ]);

        $this->log(auth('api')->id(), 'UPDATE_WATERMARK', ClinicProfile::class, null, "Watermark: {$data['watermark_type']}");
    }

    /** Setting cetak kwitansi/rincian kasir saat ini (default ditimpa nilai tersimpan). */
    public function getReceiptPrintSettings(): array
    {
        $clinic = ClinicProfile::first();
        return $clinic ? $clinic->receiptPrintSettings() : ClinicProfile::RECEIPT_PRINT_DEFAULTS;
    }

    /** Simpan toggle elemen cetak (hanya key yang dikenal). */
    public function updateReceiptPrintSettings(array $data): array
    {
        $clinic = ClinicProfile::first();
        if (! $clinic) {
            throw new \Exception('Profil klinik belum dibuat.', 422);
        }

        // Merge: pertahankan default, timpa key yang dikirim (cast bool).
        $merged = $clinic->receiptPrintSettings();
        foreach (array_keys(ClinicProfile::RECEIPT_PRINT_DEFAULTS) as $key) {
            if (array_key_exists($key, $data)) {
                $merged[$key] = (bool) $data[$key];
            }
        }

        $clinic->update(['receipt_print_settings' => $merged]);
        $this->log(auth('api')->id(), 'UPDATE_RECEIPT_PRINT_SETTINGS', ClinicProfile::class, $clinic->id);

        return $merged;
    }

    // =========================================================================
    // RECEIPT GENERATION
    // =========================================================================

    /**
     * Generate receipt data for PDF rendering (via Puppeteer on frontend).
     * Returns structured data + clinic profile for PDF template.
     *
     * Boleh dicetak pada status apa pun (termasuk DRAFT / belum lunas) — dokumen
     * yang belum PAID ditandai "PRO FORMA / BELUM LUNAS" di sisi frontend.
     */
    public function generateReceipt(string $invoiceId): array
    {
        $invoice = BillingInvoice::with([
            'visit.patient',
            'visit.insurer',
            'visit.room',
            'visit.bed',
            'items',
            'cashier',
        ])->findOrFail($invoiceId);

        $clinic  = ClinicProfile::first();
        $total   = (float) $invoice->total;
        $paid    = (float) $invoice->paid_amount;
        $covered = (float) $invoice->covered_amount;

        // Toggle elemen cetak (logo/stempel/e-sign/footer/watermark) — admin atur via UI.
        $print = $clinic ? $clinic->receiptPrintSettings() : ClinicProfile::RECEIPT_PRINT_DEFAULTS;

        return [
            'clinic' => [
                'name'           => $clinic?->clinic_name,
                'address'        => $clinic?->address,
                'phone'          => $clinic?->phone,
                'email'          => $clinic?->email,
                'director_name'  => $clinic?->director_name,
                'director_sip'   => $clinic?->director_sip,
                'logo_path'      => $print['show_logo'] ? $clinic?->logo_path : null,
                'logo_url'       => $print['show_logo'] ? $this->resolveAssetUrl($clinic?->logo_path) : null,
                'stamp_path'     => $print['show_stamp'] ? $clinic?->stamp_path : null,
                'stamp_url'      => $print['show_stamp'] ? $this->resolveAssetUrl($clinic?->stamp_path) : null,
                'watermark_type' => ($print['show_watermark'] && $clinic?->watermark_enabled) ? $clinic?->watermark_type : null,
            ],
            'print_settings' => $print,
            'invoice' => [
                'number'         => $invoice->invoice_number,
                'date'           => $invoice->created_at?->format('d/m/Y'),
                'status'         => $invoice->status,
                'is_paid'        => $invoice->status === 'PAID',
                'payment_method' => $invoice->payment_method,
                'paid_at'        => $invoice->paid_at?->format('d/m/Y H:i'),
            ],
            'patient' => [
                'no_rm'          => $invoice->visit->patient?->no_rm,
                'name'           => $invoice->visit->patient?->name,
                'nik'            => $invoice->visit->patient?->nik,
                'guarantor_type' => $invoice->visit->guarantor_type,
                'insurer'        => $invoice->visit->insurer?->name,
            ],
            // Blok inap (null bila bukan RANAP) — kamar/bed/kelas/tgl/LOS untuk kwitansi RI.
            'inpatient'  => $this->receiptInpatientBlock($invoice->visit),
            'items'      => $invoice->items->toArray(),
            'categories' => \App\Models\BillingCategory::where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'sort_order'])->toArray(),
            'summary'   => [
                'subtotal'         => $invoice->subtotal,
                'item_discount'    => (float) $invoice->items->sum('discount_amount'),
                'discount'         => $invoice->discount,
                'discount_percent' => $invoice->discount_percent,
                'tax'              => $invoice->tax,
                'total'            => $invoice->total,
                'paid_amount'      => $invoice->paid_amount,
                'covered_amount'   => $covered,
                'change'           => max(0, ($paid + $covered) - $total),
                'sisa'             => max(0, $total - $covered - $paid),
            ],
            // Nama penanda tangan: kasir tercatat di invoice; bila tidak ada
            // (mis. invoice lama / user tanpa employee link), fallback ke user
            // yang sedang login (kasir on-duty yang mencetak).
            'cashier' => $invoice->cashier?->name
                ?? auth('api')->user()?->employee?->name
                ?? auth('api')->user()?->name,
        ];
    }

    /**
     * Blok data inap untuk kwitansi RANAP. Null bila visit bukan RANAP (kwitansi
     * rawat jalan tidak menampilkan blok ini). LOS dihitung konsisten dengan
     * generateRoomCharges: max(1, malam admission_at..discharge_at) per hari kalender.
     */
    private function receiptInpatientBlock(?Visit $visit): ?array
    {
        if (! $visit || ($visit->jenis_pelayanan ?? 'RAJAL') !== 'RANAP') {
            return null;
        }

        $los = null;
        if ($visit->admission_at) {
            $end = $visit->discharge_at ?? now();
            $los = max(1, \Illuminate\Support\Carbon::parse($visit->admission_at)
                ->startOfDay()
                ->diffInDays(\Illuminate\Support\Carbon::parse($end)->startOfDay()));
        }

        return [
            'room'           => $visit->room?->name ?? $visit->room?->code,
            'bed'            => $visit->bed?->label ?? $visit->bed?->code,
            'kelas_rawat_hak' => $visit->kelas_rawat_hak,
            'kelas_rawat'    => $visit->kelas_rawat,
            'admission_at'   => $visit->admission_at?->format('d/m/Y H:i'),
            'discharge_at'   => $visit->discharge_at?->format('d/m/Y H:i'),
            'discharge_type' => $visit->discharge_type,
            'los'            => $los,
        ];
    }

    /**
     * Ubah path logo/stempel (relatif storage) menjadi URL absolut yang bisa
     * dimuat di jendela cetak. Data URI / URL penuh dikembalikan apa adanya.
     */
    private function resolveAssetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http') || str_starts_with($path, 'data:')) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }

    // =========================================================================
    // LAPORAN
    // =========================================================================

    public function getLaporanHarian(array $filters = []): array
    {
        $tanggal = $filters['tanggal'] ?? today()->toDateString();

        $invoices = BillingInvoice::whereDate('created_at', $tanggal)
            ->whereIn('status', ['PAID', 'PARTIALLY_PAID'])
            ->get();

        $totalPendapatan  = $invoices->sum('paid_amount');
        $perMetodeBayar   = $invoices->groupBy('payment_method')
            ->map(fn ($g) => ['count' => $g->count(), 'total' => $g->sum('paid_amount')]);

        return [
            'tanggal'          => $tanggal,
            'total_invoice'    => BillingInvoice::whereDate('created_at', $tanggal)->count(),
            'total_lunas'      => $invoices->where('status', 'PAID')->count(),
            'total_sebagian'   => $invoices->where('status', 'PARTIALLY_PAID')->count(),
            'total_pendapatan' => $totalPendapatan,
            'per_metode_bayar' => $perMetodeBayar,
        ];
    }

    public function getLaporanRekap(array $filters = []): array
    {
        $from = $filters['from'] ?? today()->startOfMonth()->toDateString();
        $to   = $filters['to']   ?? today()->toDateString();

        $invoices = BillingInvoice::whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereIn('status', ['PAID', 'PARTIALLY_PAID'])
            ->get();

        return [
            'periode'          => ['from' => $from, 'to' => $to],
            'total_invoice'    => $invoices->count(),
            'total_pendapatan' => $invoices->sum('paid_amount'),
            'per_metode_bayar' => $invoices->groupBy('payment_method')
                ->map(fn ($g) => ['count' => $g->count(), 'total' => $g->sum('paid_amount')]),
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function recalculateInvoice(BillingInvoice $invoice): void
    {
        $invoice->refresh();
        // subtotal = gross (sum total_price) — net dihitung dari sum(net_price) yang sudah memperhitungkan diskon per-item.
        $subtotal     = (float) $invoice->items()->sum('total_price');
        $itemNet      = (float) $invoice->items()->sum('net_price');
        $globalDisc   = (float) $invoice->discount;
        $globalDiscPc = (float) ($invoice->discount_percent ?? 0);

        // Bila discount_percent terisi, hitung ulang nominal global dari net item.
        if ($globalDiscPc > 0) {
            $globalDisc = round($itemNet * $globalDiscPc / 100, 2);
            $invoice->update(['discount' => $globalDisc]);
        }

        $total = max(0, $itemNet - $globalDisc + (float) $invoice->tax);

        $invoice->update(['subtotal' => $subtotal, 'total' => $total]);
    }

    /**
     * Nomor invoice per jenis pelayanan dengan COUNTER TERPISAH per tipe:
     *   - RAJAL → "INV-{code}/{Y}/{m}/{seq}"     (TIDAK diubah — backward compatible)
     *   - RANAP → "INV-RI/{code}/{Y}/{m}/{seq}"
     *   - IGD   → "INV-IGD/{code}/{Y}/{m}/{seq}"
     * Seq dihitung dari jumlah invoice bulan berjalan yang prefix-nya sama (bukan
     * count global), supaya tiap tipe punya nomor mulai 001 sendiri.
     */
    private function generateInvoiceNumber(?Visit $visit = null): string
    {
        $clinic  = ClinicProfile::first();
        $code    = $clinic?->clinic_code ?? 'KMA';
        $year    = now()->format('Y');
        $month   = now()->format('m');

        $jenis   = $visit?->jenis_pelayanan ?? 'RAJAL';
        $prefix  = match ($jenis) {
            'RANAP' => "INV-RI/{$code}/",
            'IGD'   => "INV-IGD/{$code}/",
            default => "INV-{$code}/",
        };

        // Counter per-tipe: hitung invoice bulan berjalan dgn prefix yang sama.
        // RAJAL prefix "INV-{code}/" juga cocok ke "INV-RI/..."? TIDAK — RI/IGD pakai
        // segmen tipe di depan code, jadi prefix RAJAL tak akan match RI/IGD.
        $lastSeq = BillingInvoice::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->where('invoice_number', 'like', $prefix . '%')
            ->count();

        $seq = str_pad($lastSeq + 1, 3, '0', STR_PAD_LEFT);

        return "{$prefix}{$year}/{$month}/{$seq}";
    }

    // =========================================================================
    // ASURANSI/TPA — warning verifikasi + auto-draft klaim
    // BPJS tidak disentuh (KlaimService punya alur sendiri).
    // =========================================================================

    /**
     * Info verifikasi asuransi untuk UI kasir.
     * - `show`: flag tampil banner alert (true jika PENDING/ISSUE, false jika NONE/VERIFIED)
     * - `verification`: data eligibility (plafon, copay %/Rp, exclusion) — selalu di-return
     *   kalau visit pakai ASURANSI/PERUSAHAAN supaya kasir bisa lihat referensi.
     *
     * Bukan blocker keras — kasir tetap bisa proses pembayaran.
     */
    public function getInsuranceWarning(string $visitId): array
    {
        $visit = Visit::with('latestInsuranceVerification:id,visit_id,status,policy_number,member_name,member_card_number,plafon_amount,copayment_percent,copayment_amount,covered_amount,coverage_notes,exclusion_flags,issue_notes,verified_at')
            ->find($visitId);
        if (! $visit) {
            return ['show' => false];
        }

        if (! in_array($visit->guarantor_type, ['ASURANSI', 'PERUSAHAAN'], true)) {
            return ['show' => false];
        }

        $status = $visit->insurance_verification_status ?? 'NONE';
        $verif  = $visit->latestInsuranceVerification;

        $verifData = $verif ? [
            'status'             => $verif->status,
            'policy_number'      => $verif->policy_number,
            'member_name'        => $verif->member_name,
            'member_card_number' => $verif->member_card_number,
            'plafon_amount'      => $verif->plafon_amount,
            'copayment_percent'  => $verif->copayment_percent,
            'copayment_amount'   => $verif->copayment_amount,
            'covered_amount'     => $verif->covered_amount,
            'coverage_notes'     => $verif->coverage_notes,
            'exclusion_flags'    => $verif->exclusion_flags,
            'issue_notes'        => $verif->issue_notes,
            'verified_at'        => $verif->verified_at,
        ] : null;

        $show    = in_array($status, ['PENDING', 'ISSUE'], true);
        $message = null;
        if ($status === 'PENDING') {
            $message = 'Verifikasi asuransi belum selesai. Pastikan billing sudah cek portal TPA sebelum memproses pembayaran.';
        } elseif ($status === 'ISSUE') {
            $message = 'Ada masalah verifikasi asuransi. Konfirmasi supervisor dulu sebelum memproses pembayaran.';
        }

        return [
            'show'         => $show,
            'status'       => $status,
            'message'      => $message,
            'verification' => $verifData,
        ];
    }

    /**
     * Setelah invoice PAID dan visit pakai ASURANSI/PERUSAHAAN dengan verifikasi
     * VERIFIED, otomatis buat draft klaim. Billing tidak perlu manual klik "Buat
     * Klaim Baru" — checklist dokumen sudah di-prepopulate dari master TPA.
     *
     * Tidak melempar exception kalau gagal — kegagalan auto-draft tidak boleh
     * membatalkan transaksi pembayaran. Billing bisa selalu buat draft manual.
     */
    private function maybeCreateInsuranceClaimDraft(BillingInvoice $invoice): void
    {
        try {
            $visit = $invoice->visit;
            if (! $visit) return;

            if (! in_array($visit->guarantor_type, ['ASURANSI', 'PERUSAHAAN'], true)) return;
            if ($visit->insurance_verification_status !== 'VERIFIED') return;
            if (! $visit->insurer_id) return;

            // Hindari duplikat — kalau sudah ada klaim untuk invoice ini, skip.
            $exists = \App\Models\InsuranceClaim::where('billing_invoice_id', $invoice->id)->exists();
            if ($exists) return;

            // Klaim ke TPA = porsi yang ditanggung asuransi (covered_amount). Jika admin
            // belum menentukan cover, fallback ke seluruh nilai invoice (billing sesuaikan
            // saat submit). Sisa = tanggungan pasien.
            $claimAmount = (float) $invoice->covered_amount > 0
                ? (float) $invoice->covered_amount
                : (float) $invoice->total;
            $patientResp = max(0, (float) $invoice->total - $claimAmount);

            $this->asuransiService->createDraftKlaim([
                'visit_id'               => $visit->id,
                'insurer_id'             => $visit->insurer_id,
                'billing_invoice_id'     => $invoice->id,
                'claim_amount'           => $claimAmount,
                'patient_responsibility' => $patientResp,
                'source'                 => 'auto_after_payment',
            ]);
        } catch (\Throwable $e) {
            // Log tapi jangan lempar — payment sudah committed.
            $this->log(
                auth('api')->id(),
                'AUTO_DRAFT_CLAIM_FAILED',
                BillingInvoice::class,
                $invoice->id,
                $e->getMessage()
            );
        }
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
