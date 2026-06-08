<?php

namespace App\Services;

use App\Jobs\SendReceiptEmail;
use App\Models\BhpItem;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceCoverage;
use App\Models\BillingItem;
use App\Models\ClinicProfile;
use App\Models\Insurer;
use App\Models\InpatientCharge;
use App\Models\InsuranceVerification;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\MedicationTariff;
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

    /**
     * Pencarian buku tarif LINTAS KATEGORI (tindakan / obat / BHP / IOL / alkes)
     * untuk "Edit Tagihan" kasir — supaya item non-tindakan pun ditambah dengan
     * harga master per-penjamin (bukan ketik manual). Hasil dibatasi & berbasis
     * query teks agar tidak menarik ribuan obat sekaligus.
     *
     * @param  string  $type  Filter sumber: ALL|TINDAKAN|OBAT|BHP|IOL|MEDICAL_EQUIPMENT
     * @return array<array{source:string,item_type:string,id:string,code:?string,name:string,category:?string,price:float}>
     */
    public function searchTarifBuku(string $visitId, string $q, string $type = 'ALL', int $limit = 40): array
    {
        $visit = Visit::findOrFail($visitId);
        $q     = trim($q);
        if ($q === '') {
            return [];
        }

        $g  = $visit->guarantor_type;
        $ix = $visit->insurer_id;
        $perType = max(5, (int) ceil($limit / ($type === 'ALL' ? 5 : 1)));
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $out  = [];

        $wants = fn (string $t) => $type === 'ALL' || $type === $t;

        // ── TINDAKAN (procedure) ──────────────────────────────────────────────
        if ($wants('TINDAKAN')) {
            foreach (\App\Models\Procedure::where('is_active', true)
                ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like))
                ->orderBy('name')->limit($perType)->get(['id', 'code', 'name', 'category']) as $p) {
                $out[] = [
                    'source' => 'procedure', 'item_type' => 'TINDAKAN',
                    'id' => $p->id, 'code' => $p->code, 'name' => $p->name,
                    'category' => $p->category ?: 'Tindakan',
                    'price' => $this->getPrice('procedure', $p->id, $g, $ix),
                ];
            }
        }

        // ── OBAT (medication) ─────────────────────────────────────────────────
        if ($wants('OBAT')) {
            foreach (Medication::where('is_active', true)
                ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('generic_name', 'ilike', $like))
                ->orderBy('name')->limit($perType)->get(['id', 'code', 'name', 'unit']) as $m) {
                $out[] = [
                    'source' => 'medication', 'item_type' => 'OBAT',
                    'id' => $m->id, 'code' => $m->code,
                    'name' => $m->name . ($m->unit ? " ({$m->unit})" : ''),
                    'category' => 'Obat',
                    'price' => $this->getPrice('medication', $m->id, $g, $ix),
                ];
            }
        }

        // ── BHP ───────────────────────────────────────────────────────────────
        if ($wants('BHP')) {
            foreach (BhpItem::where('is_active', true)
                ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like))
                ->orderBy('name')->limit($perType)->get(['id', 'code', 'name', 'category']) as $b) {
                $out[] = [
                    'source' => 'bhp', 'item_type' => 'BHP',
                    'id' => $b->id, 'code' => $b->code, 'name' => $b->name,
                    'category' => $b->category ?: 'BHP',
                    'price' => $this->getPrice('bhp', $b->id, $g, $ix),
                ];
            }
        }

        // ── IOL (tak punya kolom name/code → komposisi brand + model + power) ──
        if ($wants('IOL')) {
            foreach (IolItem::where('is_active', true)
                ->where(fn ($w) => $w->where('brand', 'ilike', $like)->orWhere('model', 'ilike', $like)->orWhere('iol_type', 'ilike', $like))
                ->orderBy('brand')->limit($perType)->get(['id', 'brand', 'model', 'iol_type', 'power']) as $i) {
                $name = trim(implode(' ', array_filter([$i->brand, $i->model, $i->power ? "+{$i->power}D" : null])));
                $out[] = [
                    'source' => 'iol', 'item_type' => 'IOL',
                    'id' => $i->id, 'code' => null,
                    'name' => $name !== '' ? $name : 'IOL',
                    'category' => $i->iol_type ?: 'IOL',
                    'price' => $this->getPrice('iol', $i->id, $g, $ix),
                ];
            }
        }

        // ── ALAT MEDIS (equipment) ─────────────────────────────────────────────
        if ($wants('MEDICAL_EQUIPMENT')) {
            foreach (\App\Models\MedicalEquipment::where('is_active', true)
                ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('brand', 'ilike', $like))
                ->orderBy('name')->limit($perType)->get(['id', 'code', 'name', 'category']) as $e) {
                $out[] = [
                    'source' => 'equipment', 'item_type' => 'MEDICAL_EQUIPMENT',
                    'id' => $e->id, 'code' => $e->code, 'name' => $e->name,
                    'category' => $e->category ?: 'Alat Medis',
                    'price' => $this->getPrice('equipment', $e->id, $g, $ix),
                ];
            }
        }

        return array_slice($out, 0, $limit);
    }

    // =========================================================================
    // CONSOLIDATE BILLING (generate invoice dari semua sumber)
    // =========================================================================

    /**
     * Build invoice from all visit sources: tindakan, obat, BHP, IOL (bedah), paket.
     * Applies tariff lookup with fallback logic.
     * Applies COB if configured.
     */
    public function consolidateBilling(string $visitId): BillingInvoice
    {
        $visit = Visit::with([
            'patient',
            'visitServices.procedure',
            'prescriptions.items.medication',
            'doctorExamination.surgerySchedule.surgeryRecord.iolUsages.iolItem',
            // FIX B1: pasien pre-op Admisi & RANAP→Bedah (Fase 8C) set visits.surgery_schedule_id
            // LANGSUNG tanpa doctorExamination → eager-load jalur visit langsung agar IOL tak hilang & tanpa N+1.
            'surgerySchedule.surgeryRecord.iolUsages.iolItem',
            'doctorExamination.surgeryPackage.items',
            'surgeryRequests.bhpItems.bhpItem',
            'equipmentUsages.equipment',
            'surgeryPackageSnapshots.items',
            'visitCob.penjamin1',
            'visitCob.penjamin2',
        ])->findOrFail($visitId);

        if (BillingInvoice::where('visit_id', $visitId)->whereNotIn('status', ['CANCELLED'])->exists()) {
            throw new \Exception('Invoice sudah ada untuk kunjungan ini.', 422);
        }

        return DB::transaction(function () use ($visit) {
            // Insurer yang dipakai untuk MEMBANGUN baris tagihan. Untuk COB, tagihan
            // pasien & penjamin-2 berhitung di harga penjamin-2 (keputusan bisnis:
            // "hitung ulang di harga penjamin-2"); penjamin-1 (mis. BPJS INA-CBG)
            // menanggung lump via coverage. Non-COB → harga penjamin utama (zero-diff).
            [$billInsurerId, $billGuarantor] = $this->billingInsurerFor($visit);

            $lines = $this->buildLines($visit, $billInsurerId, $billGuarantor);

            $subtotal = array_sum(array_map(fn ($l) => (float) ($l['total_price'] ?? 0), $lines));

            // COB → global discount (placeholder, 0 — split COB lewat coverages, bukan discount)
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

            // COB: rekam porsi tanggungan per penjamin (seq 1 & 2). Idempoten +
            // soft-delete aware → aman saat konsolidasi ulang.
            if ($visit->visitCob && $visit->visitCob->is_active) {
                $this->persistCoverages($invoice, $visit);
            }

            // RANAP/IGD: tandai inpatient_charges yang baru ditagih agar tak dobel saat
            // (mis.) invoice di-cancel lalu konsolidasi ulang.
            if ($this->usesInpatientCharges($visit)) {
                InpatientCharge::where('visit_id', $visit->id)
                    ->where('is_billed', false)
                    ->update(['is_billed' => true]);
            }

            $this->log(auth('api')->id(), 'CONSOLIDATE_BILLING', BillingInvoice::class, $invoice->id, "Invoice {$invoiceNumber} — total {$total}");

            return $invoice->load(['items', 'visit.patient']);
        });
    }

    /**
     * Pipeline builder — gabung semua sumber jadi baris BillingItem.
     * $insurerId/$guarantorType opsional: bila null pakai penjamin utama visit
     * (perilaku lama). Override dipakai untuk membangun tagihan COB di harga
     * penjamin-2 dan untuk recomputeTotalForInsurer (preview cob-basis).
     */
    private function buildLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        $insurerId    ??= $visit->insurer_id;
        $guarantorType ??= $visit->guarantor_type;

        return array_merge(
            // Tindakan + Penunjang kini SATU sumber: visit_services. Dokter menambahkan
            // pemeriksaan penunjang sebagai tindakan lewat Tab 3 DokterView ("Tambah
            // Tindakan") → tertarif via Buku Tarif (procedure_tariffs) di buildTindakanLines.
            // Order penunjang (diagnostic_orders) kini MURNI operasional (kirim ke stasiun
            // penunjang, hasil, rekomendasi IOL) dan TIDAK lagi jadi sumber tagihan —
            // menghindari dobel tagih dengan baris tindakan Tab 3.
            $this->buildTindakanLines($visit, $insurerId, $guarantorType),
            $this->buildObatLines($visit, $insurerId, $guarantorType),
            $this->buildBhpLines($visit, $insurerId, $guarantorType),
            $this->buildPaketRoomBhpLines($visit, $insurerId, $guarantorType),
            $this->buildIolLines($visit, $insurerId, $guarantorType),
            $this->buildEquipmentLines($visit, $insurerId, $guarantorType),
            $this->buildInpatientChargeLines($visit),
            $this->buildPaketDiscountLines($visit, $insurerId, $guarantorType),
            $this->buildFollowupConsultLines($visit, $insurerId, $guarantorType),
        );
    }

    /**
     * Total tagihan bila seluruh item dihitung di harga insurer tertentu.
     * Dipakai untuk basis selisih COB penjamin-2 ("hitung ulang di harga penjamin-2")
     * dan preview cob-basis sebelum invoice dibuat.
     */
    public function recomputeTotalForInsurer(Visit $visit, ?string $insurerId, string $guarantorType): float
    {
        $lines = $this->buildLines($visit, $insurerId, $guarantorType);
        return array_sum(array_map(fn ($l) => (float) ($l['total_price'] ?? 0), $lines));
    }

    /**
     * Insurer + guarantor untuk MEMBANGUN baris tagihan.
     * COB aktif → penjamin-2 (tagihan pasien & penjamin-2 di harga penjamin-2).
     * Non-COB → penjamin utama visit.
     */
    private function billingInsurerFor(Visit $visit): array
    {
        $cob = $visit->visitCob;
        if ($cob && $cob->is_active && $cob->penjamin2_insurer_id) {
            return [$cob->penjamin2_insurer_id, $cob->penjamin2_type ?? $visit->guarantor_type];
        }
        return [$visit->insurer_id, $visit->guarantor_type];
    }

    // =========================================================================
    // BUILDERS — satu method per sumber, dipanggil dari consolidateBilling.
    // Tiap builder return array<array> siap di-create sebagai BillingItem.
    // =========================================================================

    /**
     * Apakah visit menagih biaya lewat tabel inpatient_charges (bukan builder
     * tindakan/obat/penunjang biasa)? Berlaku untuk RANAP DAN IGD — keduanya
     * mencatat tindakan/obat/charge ke inpatient_charges (lihat IgdService::addCharge
     * & RanapService). Sumber tunggal agar guard tidak hardcode 'RANAP' di banyak
     * tempat dan biaya IGD tidak hilang dari invoice.
     */
    private function usesInpatientCharges(Visit $visit): bool
    {
        return in_array($visit->jenis_pelayanan ?? 'RAJAL', ['RANAP', 'IGD'], true);
    }

    // CATATAN: buildRegistrasiLines() DIHAPUS (bug lama). Dulu menyuntikkan flat
    // Rp 50.000 "Biaya Pendaftaran" ke SETIAP invoice — padahal registrasi tidak ada
    // di Buku Tarif / Tarif Tindakan. Bila suatu saat ada biaya pendaftaran resmi,
    // daftarkan sebagai item di Buku Tarif lalu tagih lewat builder bertarif (atau
    // tambah manual lewat Edit Tagihan — item_type REGISTRASI masih diizinkan).

    private function buildTindakanLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        $insurerId    ??= $visit->insurer_id;
        $guarantorType ??= $visit->guarantor_type;
        $lines = [];
        foreach ($visit->visitServices as $vs) {
            $price = $this->getPrice('procedure', $vs->procedure_id, $guarantorType, $insurerId);
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

    private function buildObatLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        $insurerId    ??= $visit->insurer_id;
        $guarantorType ??= $visit->guarantor_type;
        // RANAP/IGD: obat (termasuk obat pulang) ditagih lewat inpatient_charges type OBAT
        // (buildInpatientChargeLines) — resep di sini hanya untuk antrean Farmasi & potong
        // stok, BUKAN sumber tagihan. Skip agar tidak dobel-tagih.
        if ($this->usesInpatientCharges($visit)) {
            return [];
        }

        // Alur RAJAL/Bedah: DOKTER → KASIR → FARMASI. Kasir konsolidasi billing
        // SEBELUM farmasi men-dispense, jadi resep masih DRAFT/SUBMITTED (belum
        // DISPENSED). Tagih semua resep yang BUKAN CANCELLED — kalau hanya menagih
        // DISPENSED, obat pulang RAJAL/Bedah tak pernah masuk invoice (pasien pulang
        // tanpa dibayar). DRAFT pun ditagih: resep dokter dibuat status DRAFT dan
        // tetap DRAFT sampai farmasi memprosesnya (tak ada langkah submit terpisah
        // untuk RAJAL — lihat DokterService::storePrescription & FarmasiService::startDispensing).
        // Pos kwitansi obat (Obat Pulang/Tindakan/Injeksi) = klasifikasi di TARIF obat,
        // dibaca dari baris UMUM (harga tunggal acuan → 1 obat = 1 pos lintas penjamin).
        // Pre-load map medication_id → pos dalam 1 query (hindari N+1). Item tanpa tarif
        // UMUM → default OBAT_PULANG (lihat MedicationTariff::posLabel).
        // Obat "terserap paket" (is_bedah=true) HANYA disurfacekan bila pasien memang
        // punya paket — biar ada baris diskon penyeimbang. Tanpa paket → tetap di-skip
        // (perilaku lama: obat is_bedah tak ditagih). Bila berpaket, posMap perlu
        // mencakup obat is_bedah juga (untuk label pos kwitansi).
        $hasPaket = $visit->surgeryPackageSnapshots->isNotEmpty();

        $medIds = $visit->prescriptions
            ->where('status', '!=', 'CANCELLED')
            ->flatMap(fn ($p) => ($hasPaket ? $p->items : $p->items->where('is_bedah', false))->pluck('medication_id'))
            ->filter()->unique()->values()->all();

        $posMap = [];
        $umumId = $this->systemInsurerId('UMUM');
        if ($umumId && $medIds) {
            $posMap = DB::table('medication_tariffs')
                ->whereIn('medication_id', $medIds)
                ->where('insurer_id', $umumId)
                ->whereNull('deleted_at')
                ->pluck('pos_kwitansi', 'medication_id')
                ->all();
        }

        $lines = [];
        $bundledTotal = 0.0;   // Σ obat terserap paket (is_bedah) → dinetralkan via diskon.
        foreach ($visit->prescriptions as $prescription) {
            if ($prescription->status === 'CANCELLED') {
                continue;
            }
            foreach ($prescription->items as $item) {
                // Obat terserap paket (is_bedah) pada pasien TANPA paket → skip (tak ditagih,
                // perilaku lama). Pada pasien BERPAKET → tetap dimunculkan sebagai baris
                // (transparansi) lalu dinetralkan oleh baris diskon paket di bawah.
                $isBundled = $item->is_bedah && $hasPaket;
                if ($item->is_bedah && ! $hasPaket) {
                    continue;
                }
                $price = $this->getPrice('medication', $item->medication_id, $guarantorType, $insurerId);
                $total = $price * $item->quantity;
                $lines[] = [
                    'item_type'    => 'OBAT',
                    'category'     => MedicationTariff::posLabel($posMap[$item->medication_id] ?? null),
                    'reference_id' => $item->id,
                    'description'  => ($item->medication?->name ?? 'Obat') . ($isBundled ? ' (termasuk paket)' : ''),
                    'quantity'     => $item->quantity,
                    'unit_price'   => $price,
                    'total_price'  => $total,
                    'net_price'    => $total,
                    'notes'        => $item->dosage,
                ];
                if ($isBundled) {
                    $bundledTotal += $total;
                }
            }
        }

        // Obat terserap paket muncul di kwitansi tapi TIDAK menambah total: satu baris
        // diskon paket (negatif) menyeimbangkan jumlahnya. Harga paket diasumsikan sudah
        // mencakup obat ini. DISKON_PAKET tak diperlakukan khusus di COB → aman.
        if ($bundledTotal > 0) {
            $lines[] = [
                'item_type'    => 'DISKON_PAKET',
                'category'     => 'Diskon',
                'reference_id' => null,
                'description'  => 'Obat termasuk paket bedah',
                'quantity'     => 1,
                'unit_price'   => -$bundledTotal,
                'total_price'  => -$bundledTotal,
                'net_price'    => -$bundledTotal,
                'notes'        => null,
            ];
        }
        return $lines;
    }

    // CATATAN: buildPenunjangLines() DIHAPUS. Penunjang tidak lagi ditagih dari
    // diagnostic_orders COMPLETED. Sejak alur terbaru, dokter menambahkan pemeriksaan
    // penunjang sebagai TINDAKAN lewat Tab 3 DokterView ("Tambah Tindakan") → masuk
    // visit_services dan tertarif via Buku Tarif di buildTindakanLines(). diagnostic_orders
    // kini murni operasional (antrean stasiun penunjang, hasil, rekomendasi IOL).

    private function buildBhpLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        $insurerId    ??= $visit->insurer_id;
        $guarantorType ??= $visit->guarantor_type;
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
                $price = $this->getPrice('bhp', $bhp->bhp_item_id, $guarantorType, $insurerId);
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
     * tapi tetap ditagih sesuai paket. Anti dobel-tagih: lewati BHP yang sudah
     * masuk via buildBhpLines (jalur used_qty surgery_requests).
     *
     * Sumber: SNAPSHOT paket pasien bila ada (komponen yang sudah disesuaikan
     * operator Bedah) → menjaga himpunan "komponen tertagih" = "basis diskon"
     * (lihat buildPaketDiscountLines). Fallback ke package master bila snapshot
     * belum ada (pasien lama / alur tanpa snapshot).
     */
    private function buildPaketRoomBhpLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        $insurerId    ??= $visit->insurer_id;
        $guarantorType ??= $visit->guarantor_type;
        // Multi-paket: kumpulkan komponen BHP dari SEMUA snapshot paket pasien.
        // Fallback ke package master bila belum ada snapshot (pasien lama).
        $snaps = $visit->surgeryPackageSnapshots;
        if ($snaps->isNotEmpty()) {
            $items = $snaps->flatMap(fn ($s) => $s->items->where('item_type', 'BHP'));
        } else {
            $items = $visit->doctorExamination?->surgeryPackage?->items?->where('item_type', 'BHP');
        }
        if (! $items || $items->isEmpty()) {
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
        // Catatan: TIDAK men-dedup lintas paket — tiap paket menagih komponennya
        // sendiri agar tetap konsisten dgn basis diskon per-paket (buildPaketDiscountLines
        // menghitung basis per snapshot). Bila operator tak mau dobel, hapus komponen di
        // salah satu paket lewat panel Komponen Paket modul Bedah.
        foreach ($items as $pi) {
            if (isset($alreadyBilled[$pi->item_id])) continue;

            $bhp = BhpItem::find($pi->item_id);
            if (! $bhp || ! in_array($bhp->category, $roomCategories, true)) {
                continue;
            }

            $qty = (int) ($pi->quantity ?? 1);
            // Harga SELALU live (getPrice) — baik dari snapshot maupun master — agar
            // tagihan room-BHP = basis diskon (buildPaketDiscountLines juga pakai getPrice).
            $price = $this->getPrice('bhp', $bhp->id, $guarantorType, $insurerId);
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

    private function buildIolLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        $insurerId    ??= $visit->insurer_id;
        $guarantorType ??= $visit->guarantor_type;
        $lines = [];
        // FIX B1 (KRITIS): prefer surgery_schedule_id pada visit (pasien pre-op Admisi &
        // RANAP→Bedah Fase 8C yang set visits.surgery_schedule_id LANGSUNG tanpa doctorExamination),
        // fallback ke jadwal via doctorExamination (alur poli/rawat-jalan klasik).
        $record = ($visit->surgerySchedule ?? $visit->doctorExamination?->surgerySchedule)?->surgeryRecord;
        if (! $record) {
            return $lines;
        }
        foreach ($record->iolUsages as $iolUsage) {
            $price = $this->getPrice('iol', $iolUsage->iol_item_id, $guarantorType, $insurerId);
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

    private function buildEquipmentLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        $insurerId    ??= $visit->insurer_id;
        $guarantorType ??= $visit->guarantor_type;
        $lines = [];
        // Flat fee per pemakaian. Tarif Rp 0 → skip (mis. BPJS yg sudah include di INA-CBGs).
        foreach ($visit->equipmentUsages as $usage) {
            $price = $this->getPrice('equipment', $usage->medical_equipment_id, $guarantorType, $insurerId);
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
        if (! $this->usesInpatientCharges($visit)) {
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
     * Diskon paket pasien — SATU baris negatif bila pasien punya snapshot paket
     * (bedah/pemeriksaan) yang harga jualnya < total komponen.
     *
     * Basis diskon dihitung LIVE dari snapshot.items via getPrice (bukan angka
     * beku → bebas bug "snapshot basi"). Komponen aktual tetap ditagih dari sumber
     * masing-masing (visitServices / used_qty / iolUsages); baris ini hanya
     * mengurangi total sebesar (Σ komponen dalam paket − sell_price), sehingga
     * pasien bayar = sell_price + komponen di luar paket. Anti dobel-tagih: tidak
     * menambah/menghapus baris komponen, hanya 1 baris diskon.
     */
    private function buildPaketDiscountLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        $insurerId    ??= $visit->insurer_id;
        $guarantorType ??= $visit->guarantor_type;

        // Bila membangun di insurer override (COB penjamin-2), harga jual paket pun
        // harus di-resolve ulang di penjamin tsb (sell_price snapshot di-set utk penjamin
        // utama saat planning). Non-override → pakai sell_price snapshot (zero-diff).
        $overrideInsurer = $insurerId !== $visit->insurer_id;

        // Sisa qty obat diresepkan (non-cancelled, non-bedah) — untuk "absorpsi" obat ke
        // paket PEMERIKSAAN. Greedy: dikurangi tiap kali terserap (cegah serap-ganda antar paket).
        $rxRemaining = [];
        foreach ($visit->prescriptions as $rx) {
            if ($rx->status === 'CANCELLED') {
                continue;
            }
            foreach ($rx->items as $rxIt) {
                if ($rxIt->is_bedah) {
                    continue;
                }
                $rxRemaining[$rxIt->medication_id] = ($rxRemaining[$rxIt->medication_id] ?? 0) + (int) $rxIt->quantity;
            }
        }

        // Multi-paket: 1 baris diskon PER paket (mis. Phaco + TIVA terpisah).
        $lines = [];
        foreach ($visit->surgeryPackageSnapshots as $snap) {
            $isPemeriksaan = ($snap->package_type === \App\Models\VisitSurgeryPackage::TYPE_PEMERIKSAAN);
            // Basis = Σ harga Buku Tarif komponen snapshot (yang sudah disesuaikan operator).
            $basis = 0.0;
            foreach ($snap->items as $it) {
                // OBAT (paket PEMERIKSAAN): hanya terserap sebatas yang BENAR-BENAR diresepkan
                // pasien. Obat ditagih penuh di buildObatLines; diskon menyerap selisihnya.
                if ($it->item_type === 'MEDICATION') {
                    if (! $isPemeriksaan) {
                        continue;
                    }
                    $avail = $rxRemaining[$it->item_id] ?? 0;
                    if ($avail <= 0) {
                        continue;
                    }
                    $matched = min((int) $it->quantity, $avail);
                    $rxRemaining[$it->item_id] = $avail - $matched;
                    $basis += $this->getPrice('medication', $it->item_id, $guarantorType, $insurerId) * $matched;
                    continue;
                }
                $type = match ($it->item_type) {
                    'PROCEDURE' => 'procedure',
                    'BHP'       => 'bhp',
                    'IOL'       => 'iol',
                    default     => null,
                };
                if (! $type) {
                    continue;
                }
                $price = $this->getPrice($type, $it->item_id, $guarantorType, $insurerId);
                $basis += $price * (int) $it->quantity;
            }

            // COB penjamin-2: resolve ulang harga DAN nama tampil di insurer override.
            $overrideTariff = ($overrideInsurer && $snap->source_surgery_package_id)
                ? $this->resolvePackageTariff($snap->source_surgery_package_id, $guarantorType, $insurerId)
                : null;
            $sell = $overrideTariff ? (float) $overrideTariff->sell_price : (float) $snap->sell_price;
            $discount = round($basis - $sell, 2);
            if ($discount <= 0) {
                continue; // paket ini tidak lebih murah → tak ada diskon
            }

            $lines[] = [
                'item_type'    => 'DISKON_PAKET',
                'category'     => 'Diskon',
                'reference_id' => $snap->id,
                'description'  => ($overrideTariff?->display_name) ?: $snap->effectiveLabel(),
                'quantity'     => 1,
                'unit_price'   => -$discount,
                'total_price'  => -$discount,
                'net_price'    => -$discount,
            ];
        }
        return $lines;
    }

    /**
     * Diskon "konsultasi kontrol gratis pasca-bedah" (Opsi B) — baris negatif yang
     * menetralkan tagihan konsultasi saat pasien KONTROL, bila ia punya hak aktif
     * (package_followup_entitlements) dari operasi sebelumnya.
     *
     * Aturan (keputusan user):
     *   - HANYA penjamin UMUM (tunai). BPJS/asuransi: konsultasi tercakup penjamin.
     *   - Hak ditebus di visit BERBEDA dari visit asal operasi (tak di visit operasi itu sendiri).
     *   - 1 hak = 1 unit konsultasi gratis; dicocokkan ke tindakan (visit_services) by procedure.
     *
     * Baris ini hanya "diusulkan"; kasir bisa MENGHAPUSnya (override) lewat Edit Tagihan.
     * Hak baru benar-benar terpakai saat invoice LUNAS (PackageFollowupService::redeemPaidInvoice),
     * jadi override sebelum bayar = hak tidak hangus. reference_id = id hak (untuk tebus).
     */
    private function buildFollowupConsultLines(Visit $visit, ?string $insurerId = null, ?string $guarantorType = null): array
    {
        // Manfaat hanya untuk pasien UMUM.
        if (($visit->guarantor_type ?? null) !== 'UMUM' || ! $visit->patient_id) {
            return [];
        }

        $ents = \App\Models\PackageFollowupEntitlement::redeemableForPatient($visit->patient_id)
            ->whereNull('redeemed_visit_id')
            ->where(fn ($q) => $q->whereNull('source_visit_id')->orWhere('source_visit_id', '!=', $visit->id))
            ->get();
        if ($ents->isEmpty()) {
            return [];
        }

        // Anti dobel-pakai: hak yang sudah "diklaim" baris diskon di invoice LAIN (belum
        // dibatalkan, beda visit) disingkirkan — cegah satu hak menggratiskan dua visit
        // kontrol yang invoice-nya sama-sama masih terbuka. (Konsumsi final tetap saat bayar.)
        $claimed = DB::table('billing_items')
            ->join('billing_invoices', 'billing_invoices.id', '=', 'billing_items.billing_invoice_id')
            ->where('billing_items.item_type', 'DISKON_KONTROL')
            ->whereIn('billing_items.reference_id', $ents->pluck('id')->all())
            ->whereNull('billing_items.deleted_at')
            ->whereNull('billing_invoices.deleted_at')
            ->where('billing_invoices.status', '!=', 'CANCELLED')
            ->where('billing_invoices.visit_id', '!=', $visit->id)
            ->pluck('billing_items.reference_id')->unique()->all();
        if ($claimed) {
            $ents = $ents->reject(fn ($e) => in_array($e->id, $claimed, true))->values();
            if ($ents->isEmpty()) {
                return [];
            }
        }

        // Antrean hak per prosedur konsultasi (greedy: 1 hak menggratiskan 1 unit).
        $queue = [];
        foreach ($ents as $e) {
            $queue[$e->procedure_id][] = $e;
        }

        $guarantorType ??= $visit->guarantor_type;
        $insurerId     ??= $visit->insurer_id;

        $lines = [];
        foreach ($visit->visitServices as $vs) {
            $pid = $vs->procedure_id;
            if (empty($queue[$pid])) {
                continue;
            }
            $qty = max(1, (int) $vs->quantity);
            for ($n = 0; $n < $qty; $n++) {
                $ent = array_shift($queue[$pid]);
                if (! $ent) {
                    break;
                }
                $price = $this->getPrice('procedure', $pid, $guarantorType, $insurerId);
                if ($price <= 0) {
                    continue;
                }
                $lines[] = [
                    'item_type'    => 'DISKON_KONTROL',
                    'category'     => 'Diskon',
                    'reference_id' => $ent->id,
                    'description'  => 'Konsultasi termasuk paket (kontrol pasca-bedah)',
                    'quantity'     => 1,
                    'unit_price'   => -$price,
                    'total_price'  => -$price,
                    'net_price'    => -$price,
                ];
            }
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
     * Resolve harga jual paket (surgery_package_tariffs.sell_price) untuk penjamin
     * pasien. Urutan: insurer terpilih (resolve TPA parent) → baris insurer_id NULL
     * ("SEMUA") → 0. Dipakai DokterService saat membuat snapshot paket pasien.
     */
    public function resolvePackageSellPrice(string $packageId, string $guarantorType, ?string $insurerId): float
    {
        return (float) ($this->resolvePackageTariff($packageId, $guarantorType, $insurerId)?->sell_price ?? 0.0);
    }

    /**
     * Resolve BARIS tarif paket (sell_price + display_name) untuk penjamin pasien.
     * Urutan: insurer terpilih (resolve TPA parent) → baris insurer_id NULL ("SEMUA")
     * → null. display_name = nama tampil khusus per-penjamin (mis. promo UMUM); null =
     * pakai nama paket master. Dipakai DokterService (snapshot label+harga) & kwitansi.
     */
    public function resolvePackageTariff(string $packageId, string $guarantorType, ?string $insurerId): ?object
    {
        $resolvedInsurerId = $this->resolveTariffInsurerId($insurerId, $guarantorType);

        $base = DB::table('surgery_package_tariffs')
            ->where('surgery_package_id', $packageId)
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if ($resolvedInsurerId) {
            $row = (clone $base)->where('insurer_id', $resolvedInsurerId)
                ->first(['sell_price', 'display_name', 'discount_percent']);
            if ($row) {
                return $row;
            }
        }

        // Fallback: tarif "SEMUA" (insurer_id NULL).
        return (clone $base)->whereNull('insurer_id')
            ->first(['sell_price', 'display_name', 'discount_percent']);
    }

    /**
     * COB diskon global — TIDAK dipakai untuk split COB (split lewat coverages,
     * bukan discount). Dipertahankan return 0 agar consolidateBilling non-COB
     * tak berubah perilaku. Lihat persistCoverages/calculateCOB/coverages.
     */
    public function calculateCOBDiscount(float $subtotal, ?VisitCob $cob): float
    {
        return 0;
    }

    /**
     * Hitung split COB sebuah invoice: berapa ditanggung penjamin-1, penjamin-2,
     * dan sisa pasien. Sumber nominal = covered_amount tiap baris coverage (diisi
     * dari VERIFIKASI per penjamin — keputusan bisnis "manual per penjamin").
     * Tagihan dibangun di harga penjamin-2, jadi invoice.total = total@penjamin-2.
     */
    public function calculateCOB(BillingInvoice $invoice): array
    {
        $total = (float) $invoice->total;
        $coverages = $invoice->relationLoaded('coverages')
            ? $invoice->coverages
            : $invoice->coverages()->get();

        if ($coverages->isEmpty()) {
            return [
                'is_cob'        => false,
                'total'         => $total,
                'covered_total' => 0.0,
                'patient_amount' => $total,
                'penjamin'      => [],
            ];
        }

        $coveredTotal = 0.0;
        $rows = [];
        foreach ($coverages as $cov) {
            $coveredTotal += (float) $cov->covered_amount;
            $rows[] = [
                'sequence'       => (int) $cov->sequence,
                'insurer_id'     => $cov->insurer_id,
                'guarantor_type' => $cov->guarantor_type,
                'covered_amount' => (float) $cov->covered_amount,
                'basis_amount'   => $cov->basis_amount !== null ? (float) $cov->basis_amount : null,
            ];
        }
        $coveredTotal = min($coveredTotal, $total);

        return [
            'is_cob'         => true,
            'total'          => $total,
            'covered_total'  => $coveredTotal,
            'patient_amount' => max(0.0, $total - $coveredTotal),
            'penjamin'       => $rows,
        ];
    }

    /**
     * Buat/segarkan baris coverage per penjamin untuk invoice COB (seq 1 & 2).
     * covered_amount diambil dari verifikasi asuransi masing-masing penjamin
     * (0 bila verifikasi belum ada — akan diperbarui saat verifikasi diinput).
     * Soft-delete aware: konsolidasi ulang me-restore baris, bukan menggandakan.
     */
    public function persistCoverages(BillingInvoice $invoice, Visit $visit): void
    {
        $cob = $visit->visitCob;
        if (! $cob || ! $cob->is_active) {
            return;
        }

        $total = (float) $invoice->total;

        $defs = [
            1 => ['insurer_id' => $cob->penjamin1_insurer_id, 'guarantor_type' => $cob->penjamin1_type, 'basis' => null],
            // Penjamin-2 = basis tagihan (invoice dibangun di harganya).
            2 => ['insurer_id' => $cob->penjamin2_insurer_id, 'guarantor_type' => $cob->penjamin2_type, 'basis' => $total],
        ];

        foreach ($defs as $seq => $def) {
            if (! $def['insurer_id']) {
                continue;
            }
            $verif   = $this->getVerifikasiForInsurer($visit->id, $def['insurer_id']);
            $covered = $verif && $verif->covered_amount !== null ? (float) $verif->covered_amount : 0.0;

            // Soft-delete aware upsert keyed (billing_invoice_id, sequence).
            $existing = BillingInvoiceCoverage::withTrashed()
                ->where('billing_invoice_id', $invoice->id)
                ->where('sequence', $seq)
                ->first();

            $values = [
                'insurer_id'      => $def['insurer_id'],
                'guarantor_type'  => $def['guarantor_type'],
                'covered_amount'  => $covered,
                'basis_amount'    => $def['basis'],
                'verification_id' => $verif?->id,
            ];

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update($values);
            } else {
                BillingInvoiceCoverage::create($values + [
                    'billing_invoice_id' => $invoice->id,
                    'sequence'           => $seq,
                ]);
            }
        }

        $this->recalcInvoiceCovered($invoice);
    }

    /**
     * Set billing_invoices.covered_amount = Σ coverages (clamp ≤ total) — agregat
     * yang dipakai jalur pembayaran/kwitansi existing (backward-compat).
     */
    public function recalcInvoiceCovered(BillingInvoice $invoice): void
    {
        $sum = (float) BillingInvoiceCoverage::where('billing_invoice_id', $invoice->id)->sum('covered_amount');
        $covered = min($sum, (float) $invoice->total);
        $invoice->update(['covered_amount' => $covered]);
    }

    /** Verifikasi asuransi terbaru untuk satu (visit, insurer). Null bila belum ada. */
    public function getVerifikasiForInsurer(string $visitId, ?string $insurerId): ?InsuranceVerification
    {
        if (! $insurerId) {
            return null;
        }
        return InsuranceVerification::where('visit_id', $visitId)
            ->where('insurer_id', $insurerId)
            ->latest()
            ->first();
    }

    /**
     * Basis COB untuk panduan verifikator/kasir (boleh sebelum invoice dibuat):
     * total@harga-penjamin-2, cover penjamin-1 (mis. BPJS INA-CBG dari verifikasi),
     * selisih, saran cover penjamin-2 (clamp plafon), dan estimasi sisa pasien.
     */
    public function getCobBasis(string $visitId): array
    {
        $visit = Visit::with([
            'visitServices.procedure',
            'prescriptions.items.medication',
            'surgerySchedule.surgeryRecord.iolUsages.iolItem',
            'doctorExamination.surgerySchedule.surgeryRecord.iolUsages.iolItem',
            'doctorExamination.surgeryPackage.items',
            'surgeryRequests.bhpItems.bhpItem',
            'equipmentUsages.equipment',
            'surgeryPackageSnapshots.items',
            'visitCob',
        ])->findOrFail($visitId);

        $cob = $visit->visitCob;
        if (! $cob || ! $cob->is_active) {
            return ['is_cob' => false];
        }

        $totalP2 = $this->recomputeTotalForInsurer(
            $visit,
            $cob->penjamin2_insurer_id,
            $cob->penjamin2_type ?? $visit->guarantor_type
        );

        $verif1 = $this->getVerifikasiForInsurer($visitId, $cob->penjamin1_insurer_id);
        $verif2 = $this->getVerifikasiForInsurer($visitId, $cob->penjamin2_insurer_id);

        $p1Covered = $verif1 && $verif1->covered_amount !== null ? (float) $verif1->covered_amount : 0.0;
        $plafon2   = $verif2 && $verif2->plafon_amount !== null ? (float) $verif2->plafon_amount : null;
        $p2Covered = $verif2 && $verif2->covered_amount !== null ? (float) $verif2->covered_amount : null;

        $selisih      = max(0.0, $totalP2 - $p1Covered);
        $suggestedP2  = $plafon2 !== null ? min($plafon2, $selisih) : $selisih;
        $effectiveP2  = $p2Covered ?? $suggestedP2;

        return [
            'is_cob'           => true,
            'total_penjamin2'  => $totalP2,
            'selisih'          => $selisih,
            'penjamin1'        => [
                'type'       => $cob->penjamin1_type,
                'insurer_id' => $cob->penjamin1_insurer_id,
                'covered'    => $p1Covered,
                'verified'   => (bool) $verif1,
            ],
            'penjamin2'        => [
                'type'              => $cob->penjamin2_type,
                'insurer_id'        => $cob->penjamin2_insurer_id,
                'plafon'            => $plafon2,
                'covered'           => $p2Covered,
                'suggested_covered' => $suggestedP2,
                'verified'          => (bool) $verif2,
            ],
            'patient_estimate' => max(0.0, $totalP2 - $p1Covered - $effectiveP2),
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
        $rawPaid = (float) $data['paid_amount'];
        if ($rawPaid <= 0) {
            throw new \Exception('Nominal bayar harus lebih dari 0.', 422);
        }

        $user = auth('api')->user();

        // SEMUA pembacaan status/sisa + update DALAM transaksi dengan lockForUpdate,
        // supaya dua pembayaran bersamaan (double-click / 2 tab) tidak sama-sama baca
        // paid_amount lama → over-collect / dobel mark PAID. Load di luar transaksi
        // (tanpa lock) tidak aman: status & sisa harus dibaca dari baris yang terkunci.
        $fresh = DB::transaction(function () use ($invoiceId, $data, $rawPaid, $user) {
            $invoice = BillingInvoice::with('visit')->lockForUpdate()->findOrFail($invoiceId);

            if (! in_array($invoice->status, ['FINALIZED', 'PARTIALLY_PAID'])) {
                throw new \Exception('Invoice harus dalam status FINALIZED atau PARTIALLY_PAID untuk diproses.', 422);
            }

            // Sisa yang masih harus DIBAYAR pasien = total − ditanggung asuransi − sudah dibayar.
            // Clamp pembayaran ke sisa ini supaya paid_amount tidak pernah melebihi tagihan
            // (overpay = uang fisik kembalian, bukan pendapatan).
            $sisaDue = max(0.0, (float) $invoice->total - (float) $invoice->covered_amount - (float) $invoice->paid_amount);
            if ($sisaDue <= 0.009) {
                throw new \Exception('Tagihan sudah lunas — tidak ada sisa yang harus dibayar.', 422);
            }
            $paidAmount = min($rawPaid, $sisaDue);

            $totalPaid   = $invoice->paid_amount + $paidAmount;
            // Tagihan dianggap lunas bila pembayaran pasien + porsi ditanggung asuransi
            // (covered_amount) sudah menutup total. Untuk pasien umum covered = 0.
            $isFullyPaid = ($totalPaid + (float) $invoice->covered_amount) >= $invoice->total;

            // cash_received = uang tunai fisik diterima (utk hitung kembalian di kwitansi).
            // Akumulasi bila bayar bertahap tunai. Hanya untuk metode CASH.
            $cashReceived = $invoice->cash_received;
            if (($data['payment_method'] ?? null) === 'CASH' && isset($data['cash_received'])) {
                $cashReceived = (float) $invoice->cash_received + (float) $data['cash_received'];
            }

            $invoice->update([
                'paid_amount'    => $totalPaid,
                'cash_received'  => $cashReceived,
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

                // Konsultasi kontrol gratis pasca-bedah (Opsi B): tandai hak terpakai untuk
                // tiap baris DISKON_KONTROL yang masih ada (kasir tak meng-override). Idempoten.
                app(\App\Services\PackageFollowupService::class)->redeemPaidInvoice($invoice);

                // Auto-draft klaim TPA non-BPJS jika visit guarantor ASURANSI/PERUSAHAAN
                // dan sudah VERIFIED.
                $this->maybeCreateInsuranceClaimDraft($invoice);

                // Auto-draft klaim BPJS: kunjungan BPJS lunas yang sudah punya SEP +
                // diagnosis dokter otomatis muncul sebagai klaim DRAFT di panel Klaim.
                $this->maybeCreateBpjsClaimDraft($invoice);
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
            // COB BPJS+asuransi: penjamin-1 BPJS juga perlu draft klaim INA-CBG (self-guarded).
            $this->maybeCreateBpjsClaimDraft($invoice);

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

        // COB: BPJS hanya menanggung INA-CBG; sisa (selisih) ditanggung penjamin-2/pasien.
        // Jangan tandai lunas sepihak — pakai konfirmasi coverage + pembayaran biasa.
        if ($invoice->visit?->visitCob?->is_active) {
            throw new \Exception('Kunjungan COB: konfirmasi coverage penjamin-2 & sisa pasien lewat pembayaran biasa, bukan konfirmasi BPJS.', 422);
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

    /**
     * Selesaikan tagihan yang sisa bayar pasiennya Rp 0 — mis. diskon/penghapusan
     * 100% oleh RS atau dokter (pasien UMUM, BUKAN asuransi/BPJS). Tidak ada uang
     * masuk: invoice ditandai PAID dengan paid_amount = 0 dan payment_method WAIVED.
     *
     * BEDA dgn confirmInsuranceCoverage: TIDAK menyentuh covered_amount — ini bukan
     * tanggungan asuransi, melainkan diskon yang SUDAH tercatat sehingga total = 0.
     * processPayment menolak nominal 0 (min:0.01), jadi kasus ini perlu jalur sendiri.
     */
    public function settleZeroInvoice(string $invoiceId, array $data = []): BillingInvoice
    {
        $user = auth('api')->user();

        return DB::transaction(function () use ($invoiceId, $data, $user) {
            $invoice = BillingInvoice::with('visit')->lockForUpdate()->findOrFail($invoiceId);

            if (in_array($invoice->status, ['PAID', 'CANCELLED'])) {
                throw new \Exception('Invoice sudah lunas atau dibatalkan.', 422);
            }

            // Finalize dulu bila masih DRAFT (kasir konfirmasi langsung tanpa step terpisah).
            if ($invoice->status === 'DRAFT') {
                $invoice->update(['status' => 'FINALIZED']);
            }
            if (! in_array($invoice->status, ['FINALIZED', 'PARTIALLY_PAID'])) {
                throw new \Exception('Invoice harus dalam status FINALIZED atau PARTIALLY_PAID untuk diselesaikan.', 422);
            }

            // Wajib benar-benar nol: kalau masih ada sisa, pakai pembayaran biasa.
            $sisaDue = (float) $invoice->total - (float) $invoice->covered_amount - (float) $invoice->paid_amount;
            if ($sisaDue > 0.009) {
                throw new \Exception(
                    'Masih ada sisa Rp ' . number_format($sisaDue, 0, ',', '.') . ' yang harus dibayar — gunakan proses pembayaran biasa.',
                    422
                );
            }

            $invoice->update([
                'payment_method' => 'WAIVED',
                'status'         => 'PAID',
                'paid_at'        => now(),
                'cashier_id'     => $user->employee_id,
                'notes'          => $data['notes'] ?? $invoice->notes,
            ]);

            // Routing FARMASI vs SELESAI + TV broadcast (sama seperti pembayaran lunas).
            $kasirQueue = Queue::where('visit_id', $invoice->visit_id)
                ->where('station', 'KASIR')
                ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                ->first();
            if ($kasirQueue) {
                $this->queueService->advanceFromStation($kasirQueue->id, Queue::STATION_KASIR);
            } else {
                $invoice->visit->update(['current_station' => 'SELESAI']);
            }

            // Hak konsultasi kontrol gratis pasca-bedah (idempoten; aman utk invoice nol).
            app(\App\Services\PackageFollowupService::class)->redeemPaidInvoice($invoice);

            $this->log(
                $user->id,
                'SETTLE_ZERO_INVOICE',
                BillingInvoice::class,
                $invoice->id,
                'Tagihan Rp 0 (diskon/penghapusan 100%) — status: PAID (WAIVED)'
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
        // Kembalian = uang tunai fisik − total. paid_amount di-clamp ke total (logika
        // partial-pay), jadi tak bisa dipakai hitung kembalian. cash_received menyimpan
        // uang fisik; bila ada, hitung kembalian darinya. Fallback ke perilaku lama.
        $cashReceived = $invoice->cash_received !== null ? (float) $invoice->cash_received : null;
        $change = $cashReceived !== null
            ? max(0, ($cashReceived + $covered) - $total)
            : max(0, ($paid + $covered) - $total);

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
                // Kop kanonik (sumber tunggal) — identik dgn pratinjau Profil.
                'letterhead_html' => $clinic ? $clinic->renderLetterheadHtml((bool) $print['show_logo']) : '',
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
                'cash_received'    => $cashReceived,
                'covered_amount'   => $covered,
                'change'           => $change,
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
     * Kirim kwitansi PDF ke email pasien (alternatif cetak fisik). Data kwitansi
     * dirakit di sini (konteks request → nama kasir & auth benar), lalu email
     * di-QUEUE; PDF dirender di worker (lihat ReceiptMail). Email juga disimpan
     * ke record pasien agar bisa dipakai ulang kunjungan berikutnya.
     *
     * Parity BPJS: pasien BPJS ditagih via klaim INA-CBG, bukan ke pasien —
     * kwitansi tidak dikirim (sama dgn aturan cetak kwitansi history).
     *
     * Status pengiriman dicatat di invoice (QUEUED → SENT/FAILED via
     * App\Jobs\SendReceiptEmail) agar kasir tahu hasilnya, bukan asal "dikirim".
     *
     * @return array{status:string,email:string,at:?string}
     */
    public function emailReceipt(string $invoiceId, string $email): array
    {
        $invoice = BillingInvoice::with('visit.patient')->findOrFail($invoiceId);

        // Visit pakai SoftDeletes → relasi bisa null bila kunjungan sudah dibatalkan.
        // Tanpa guard, akses $invoice->visit-> di bawah (dan generateReceipt) memicu
        // "read property on null" → 500. Beri error 422 yang jelas.
        if (! $invoice->visit) {
            throw new \Exception('Kunjungan untuk invoice ini tidak ditemukan / sudah dibatalkan.', 422);
        }

        if (strtoupper($invoice->visit->guarantor_type ?? '') === 'BPJS') {
            throw new \Exception('Pasien BPJS ditagih via klaim BPJS — kwitansi tidak dikirim ke pasien.', 422);
        }

        // Rakit data di request (auth ada → nama kasir benar), lalu di-queue.
        $data = $this->generateReceipt($invoiceId);

        // Simpan email ke pasien bila baru/berubah (dipakai ulang & prefill berikutnya).
        $patient = $invoice->visit->patient;
        if ($patient && $patient->email !== $email) {
            $patient->update(['email' => $email]);
        }

        // Tandai ANTRE dulu; job akan update SENT/FAILED (sync → langsung SENT).
        $invoice->update([
            'receipt_email'        => $email,
            'receipt_email_status' => 'QUEUED',
            'receipt_email_at'     => now(),
            'receipt_email_error'  => null,
        ]);

        SendReceiptEmail::dispatch($invoiceId, $email, $data, $data['invoice']['number'] ?? null);

        // Re-read: pada queue sync, job sudah jalan → status mungkin sudah SENT.
        $invoice->refresh();

        return [
            'status' => $invoice->receipt_email_status,
            'email'  => $invoice->receipt_email,
            'at'     => $invoice->receipt_email_at?->toIso8601String(),
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

        // Cover asuransi tak boleh melebihi total baru. Saat item ditambah/dihapus
        // atau diskon berubah, covered_amount lama bisa jadi > total (→ keliru dianggap
        // "full cover" oleh kasir) atau menyisakan sisa negatif. Clamp ke total terkini.
        // Hanya turunkan (clamp), tidak menaikkan otomatis — penetapan cover tetap manual.
        $covered = (float) $invoice->covered_amount;
        if ($covered > $total) {
            $invoice->update(['covered_amount' => $total]);
        }
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

        // Counter per-tipe: invoice bulan berjalan dgn prefix yang sama.
        // RAJAL prefix "INV-{code}/" juga cocok ke "INV-RI/..."? TIDAK — RI/IGD pakai
        // segmen tipe di depan code, jadi prefix RAJAL tak akan match RI/IGD.
        //
        // Dipanggil di dalam DB::transaction (consolidateBilling). lockForUpdate +
        // baca sequence MAKS (bukan count) mencegah: (a) dua kasir bersamaan dapat
        // nomor sama → 23505 pada invoice_number unik; (b) reuse nomor saat ada
        // invoice yang terhapus (count turun). Pola sama QueueService::enqueue.
        $rows = BillingInvoice::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->where('invoice_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->pluck('invoice_number');

        $maxSeq = $rows
            ->map(fn ($num) => (int) substr((string) $num, strrpos((string) $num, '/') + 1))
            ->max() ?? 0;

        $seq = str_pad($maxSeq + 1, 3, '0', STR_PAD_LEFT);

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

            $cob = $visit->visitCob;

            // === COB: klaim asuransi/TPA untuk PENJAMIN-2 (menanggung selisih) ===
            if ($cob && $cob->is_active) {
                $insurerId = $cob->penjamin2_insurer_id;
                if (! $insurerId) return;

                // Dedup per (invoice, insurer) — penjamin-2 boleh berdampingan dgn klaim BPJS.
                $exists = \App\Models\InsuranceClaim::where('billing_invoice_id', $invoice->id)
                    ->where('insurer_id', $insurerId)->exists();
                if ($exists) return;

                // Cover penjamin-2 dari baris coverage seq 2 (diisi verifikasi penjamin-2).
                $cov = $invoice->coverages()->where('sequence', 2)->first();
                $claimAmount = $cov ? (float) $cov->covered_amount : 0.0;
                if ($claimAmount <= 0) return; // belum ada cover → jangan buat klaim kosong

                $patientResp = max(0, (float) $invoice->total - (float) $invoice->covered_amount);

                $this->asuransiService->createDraftKlaim([
                    'visit_id'               => $visit->id,
                    'insurer_id'             => $insurerId,
                    'billing_invoice_id'     => $invoice->id,
                    'claim_amount'           => $claimAmount,
                    'patient_responsibility' => $patientResp,
                    'source'                 => 'auto_cob_penjamin2',
                ]);
                return;
            }

            // === Non-COB (perilaku lama): klaim ke penjamin utama ===
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

    /**
     * Auto-draft klaim BPJS saat kunjungan lunas. Kunjungan BPJS yang sudah
     * punya SEP otomatis muncul sebagai klaim DRAFT di panel Klaim
     * (tabel bpjs_claims) — tanpa petugas menarik manual.
     *
     * Diagnosis SENGAJA tidak dijadikan syarat: bila dokter belum/lupa mengisi
     * diagnosis, draft TETAP dibuat (diagnosis kosong) agar tidak ada kunjungan
     * yang hilang senyap. Petugas klaim melengkapi/mengganti diagnosis dari menu
     * Klaim (Edit Koding → KlaimService::updateClaimCoding) sebelum grouping.
     *
     * Idempoten (updateOrCreate by visit_id) & non-blocking: kegagalan tidak
     * mengganggu pembayaran yang sudah committed.
     */
    private function maybeCreateBpjsClaimDraft(BillingInvoice $invoice): void
    {
        try {
            $visit = $invoice->visit;
            if (! $visit) return;

            // BPJS primer ATAU penjamin-1 COB = BPJS (COB BPJS + asuransi).
            $cob = $visit->visitCob;
            $isBpjs = $visit->guarantor_type === 'BPJS'
                || ($cob && $cob->is_active && $cob->penjamin1_type === 'BPJS');
            if (! $isBpjs) return;
            if (empty($visit->no_sep)) return; // tanpa SEP, belum bisa diklaim

            // Diagnosis dokter (bila ada) dipakai untuk pra-isi; bila kosong,
            // draft tetap dibuat dengan diagnosis kosong untuk dilengkapi petugas.
            $exam = $visit->doctorExamination()->first();

            // Hindari menimpa klaim yang sudah diproses (mis. sudah grouping/dikirim).
            $existing = \App\Models\BpjsClaim::where('visit_id', $visit->id)->first();
            if ($existing && ! in_array($existing->status, ['DRAFT'], true)) {
                return;
            }

            // Untuk draft existing yang sudah di-edit petugas, jangan timpa kembali
            // diagnosa dengan nilai dari rekam medis (hormati koreksi koder).
            $payload = [
                'no_sep'      => $visit->no_sep,
                'patient_nik' => $visit->patient?->nik,
                'status'      => $existing?->status ?? 'DRAFT',
            ];
            if (! $existing) {
                $payload['diagnosis_utama']    = $exam?->diagnosis_utama;
                $payload['diagnosis_sekunder'] = $exam?->diagnosis_sekunder ?? [];
                $payload['procedure_codes']    = $exam?->tindakan_codes ?? [];
            }

            \App\Models\BpjsClaim::updateOrCreate(['visit_id' => $visit->id], $payload);

            $tanpaDx = empty($exam?->diagnosis_utama) ? ' (TANPA diagnosis — perlu dilengkapi)' : '';
            $this->log(
                auth('api')->id(),
                'AUTO_DRAFT_BPJS_CLAIM',
                BillingInvoice::class,
                $invoice->id,
                "Klaim BPJS draft dibuat otomatis dari kunjungan {$visit->id} (SEP {$visit->no_sep}){$tanpaDx}"
            );
        } catch (\Throwable $e) {
            $this->log(
                auth('api')->id(),
                'AUTO_DRAFT_BPJS_CLAIM_FAILED',
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
