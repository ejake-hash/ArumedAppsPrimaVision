<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\BillingItem;
use App\Models\ClinicProfile;
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
    ) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function getPatientQueue(): Collection
    {
        return Queue::with(['visit.patient', 'visit.billingInvoice'])
            ->where('station', 'KASIR')
            ->whereDate('created_at', today())
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
            'doctorExamination.surgerySchedule.surgeryRecord.iolUsages.iolItem',
            'visitCob.penjamin1',
            'visitCob.penjamin2',
        ])->findOrFail($visitId);

        if (BillingInvoice::where('visit_id', $visitId)->whereNotIn('status', ['CANCELLED'])->exists()) {
            throw new \Exception('Invoice sudah ada untuk kunjungan ini.', 422);
        }

        return DB::transaction(function () use ($visit) {
            $lines    = [];
            $subtotal = 0;

            // 1. Registrasi / Pendaftaran
            $regPrice  = 50000; // default; bisa dari tariff config nanti
            $lines[]   = ['item_type' => 'REGISTRASI', 'reference_id' => $visit->id, 'description' => 'Biaya Pendaftaran', 'quantity' => 1, 'unit_price' => $regPrice, 'total_price' => $regPrice];
            $subtotal += $regPrice;

            // 2. Tindakan (visit_services → procedure_tariffs)
            foreach ($visit->visitServices as $vs) {
                $price   = $this->getPrice('procedure', $vs->procedure_id, $visit->guarantor_type, $visit->insurer_id);
                $total   = $price * $vs->quantity;
                $lines[] = [
                    'item_type'    => 'TINDAKAN',
                    'reference_id' => $vs->id,
                    'description'  => $vs->procedure?->name ?? 'Tindakan',
                    'quantity'     => $vs->quantity,
                    'unit_price'   => $price,
                    'total_price'  => $total,
                ];
                $subtotal += $total;
            }

            // 3. Obat (prescription items → medication_tariffs)
            foreach ($visit->prescriptions as $prescription) {
                if ($prescription->status !== 'DISPENSED') {
                    continue;
                }

                foreach ($prescription->items as $item) {
                    $price   = $this->getPrice('medication', $item->medication_id, $visit->guarantor_type, $visit->insurer_id);
                    $total   = $price * $item->quantity;
                    $lines[] = [
                        'item_type'    => 'OBAT',
                        'reference_id' => $item->id,
                        'description'  => $item->medication?->name ?? 'Obat',
                        'quantity'     => $item->quantity,
                        'unit_price'   => $price,
                        'total_price'  => $total,
                        'notes'        => $item->dosage,
                    ];
                    $subtotal += $total;
                }
            }

            // 4. IOL (dari surgery_iol_usage → iol_tariffs)
            $record = $visit->doctorExamination?->surgerySchedule?->surgeryRecord;
            if ($record) {
                foreach ($record->iolUsages as $iolUsage) {
                    $price   = $this->getPrice('iol', $iolUsage->iol_item_id, $visit->guarantor_type, $visit->insurer_id);
                    $lines[] = [
                        'item_type'    => 'IOL',
                        'reference_id' => $iolUsage->id,
                        'description'  => "IOL {$iolUsage->brand} {$iolUsage->model} P{$iolUsage->power} ({$iolUsage->eye_side})",
                        'quantity'     => 1,
                        'unit_price'   => $price,
                        'total_price'  => $price,
                    ];
                    $subtotal += $price;
                }
            }

            // 5. COB calculation
            $cob      = $visit->visitCob;
            $discount = $this->calculateCOBDiscount($subtotal, $cob);
            $total    = max(0, $subtotal - $discount);

            // 6. Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber();

            $invoice = BillingInvoice::create([
                'visit_id'       => $visit->id,
                'invoice_number' => $invoiceNumber,
                'subtotal'       => $subtotal,
                'discount'       => $discount,
                'tax'            => 0,
                'total'          => $total,
                'status'         => 'DRAFT',
            ]);

            // 7. Persist billing items
            foreach ($lines as $line) {
                BillingItem::create(array_merge($line, ['billing_invoice_id' => $invoice->id]));
            }

            $this->log(auth('api')->id(), 'CONSOLIDATE_BILLING', BillingInvoice::class, $invoice->id, "Invoice {$invoiceNumber} — total {$total}");

            return $invoice->load(['items', 'visit.patient']);
        });
    }

    /**
     * Tariff lookup with 3-level fallback:
     *   1. Specific insurer_id + classification
     *   2. NULL insurer_id + classification
     *   3. NULL insurer_id + classification = UMUM
     */
    public function getPrice(string $itemType, ?string $itemId, string $guarantorType, ?string $insurerId): float
    {
        if (! $itemId) {
            return 0;
        }

        [$table, $fkColumn] = match ($itemType) {
            'procedure' => ['procedure_tariffs', 'procedure_id'],
            'medication' => ['medication_tariffs', 'medication_id'],
            'bhp'        => ['bhp_tariffs', 'bhp_item_id'],
            'iol'        => ['iol_tariffs', 'iol_item_id'],
            default      => throw new \Exception("Item type tidak dikenal: {$itemType}", 422),
        };

        $baseQuery = DB::table($table)
            ->where($fkColumn, $itemId)
            ->where('is_active', true);

        // Level 1: specific insurer + classification
        if ($insurerId) {
            $tariff = (clone $baseQuery)
                ->where('insurer_id', $insurerId)
                ->where('classification', $guarantorType)
                ->value('price');

            if ($tariff !== null) {
                return (float) $tariff;
            }
        }

        // Level 2: no insurer, match classification
        $tariff = (clone $baseQuery)
            ->whereNull('insurer_id')
            ->where('classification', $guarantorType)
            ->value('price');

        if ($tariff !== null) {
            return (float) $tariff;
        }

        // Level 3: fallback to UMUM
        $tariff = (clone $baseQuery)
            ->whereNull('insurer_id')
            ->where('classification', 'UMUM')
            ->value('price');

        return (float) ($tariff ?? 0);
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

        $invoice->update(array_filter([
            'discount' => $data['discount'] ?? null,
            'tax'      => $data['tax'] ?? null,
            'notes'    => $data['notes'] ?? null,
        ], fn ($v) => ! is_null($v)));

        // Recalculate total
        $invoice->update(['total' => max(0, $invoice->subtotal - $invoice->discount + $invoice->tax)]);

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

        return DB::transaction(function () use ($invoice, $data, $paidAmount, $user) {
            $totalPaid   = $invoice->paid_amount + $paidAmount;
            $isFullyPaid = $totalPaid >= $invoice->total;

            $invoice->update([
                'paid_amount'    => $totalPaid,
                'payment_method' => $data['payment_method'],
                'status'         => $isFullyPaid ? 'PAID' : 'PARTIALLY_PAID',
                'paid_at'        => $isFullyPaid ? now() : $invoice->paid_at,
                'cashier_id'     => $user->employee_id,
                'notes'          => $data['notes'] ?? $invoice->notes,
            ]);

            if ($isFullyPaid) {
                // Selesaikan kunjungan
                $invoice->visit->update(['current_station' => 'SELESAI']);

                Queue::where('visit_id', $invoice->visit_id)
                    ->where('station', 'KASIR')
                    ->whereIn('status', ['WAITING', 'CALLED', 'IN_PROGRESS'])
                    ->update(['status' => 'COMPLETED', 'completed_at' => now()]);
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

        $totalPrice = ($data['unit_price'] ?? 0) * ($data['quantity'] ?? 1);

        $item = BillingItem::create([
            'billing_invoice_id' => $invoiceId,
            'item_type'          => $data['item_type'],
            'reference_id'       => $data['reference_id'] ?? null,
            'description'        => $data['description'],
            'quantity'           => $data['quantity'] ?? 1,
            'unit_price'         => $data['unit_price'] ?? 0,
            'total_price'        => $totalPrice,
            'notes'              => $data['notes'] ?? null,
        ]);

        $this->recalculateInvoice($invoice);

        return $item;
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

        $item->update([
            'description' => $data['description'] ?? $item->description,
            'quantity'    => $qty,
            'unit_price'  => $unitPrice,
            'total_price' => $totalPrice,
            'notes'       => $data['notes'] ?? $item->notes,
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
            'items',
            'cashier',
        ])->findOrFail($invoiceId);

        $clinic = ClinicProfile::first();
        $total  = (float) $invoice->total;
        $paid   = (float) $invoice->paid_amount;

        return [
            'clinic' => [
                'name'           => $clinic?->clinic_name,
                'address'        => $clinic?->address,
                'phone'          => $clinic?->phone,
                'email'          => $clinic?->email,
                'director_name'  => $clinic?->director_name,
                'director_sip'   => $clinic?->director_sip,
                'logo_path'      => $clinic?->logo_path,
                'logo_url'       => $this->resolveAssetUrl($clinic?->logo_path),
                'stamp_path'     => $clinic?->stamp_path,
                'stamp_url'      => $this->resolveAssetUrl($clinic?->stamp_path),
                'watermark_type' => $clinic?->watermark_enabled ? $clinic?->watermark_type : null,
            ],
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
            'items'     => $invoice->items->toArray(),
            'summary'   => [
                'subtotal'    => $invoice->subtotal,
                'discount'    => $invoice->discount,
                'tax'         => $invoice->tax,
                'total'       => $invoice->total,
                'paid_amount' => $invoice->paid_amount,
                'change'      => max(0, $paid - $total),
                'sisa'        => max(0, $total - $paid),
            ],
            'cashier' => $invoice->cashier?->name,
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
        $subtotal = $invoice->items()->sum('total_price');
        $total    = max(0, $subtotal - $invoice->discount + $invoice->tax);

        $invoice->update(['subtotal' => $subtotal, 'total' => $total]);
    }

    private function generateInvoiceNumber(): string
    {
        $clinic  = ClinicProfile::first();
        $code    = $clinic?->clinic_code ?? 'KMA';
        $year    = now()->format('Y');
        $month   = now()->format('m');

        $lastSeq = BillingInvoice::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count();

        $seq = str_pad($lastSeq + 1, 3, '0', STR_PAD_LEFT);

        return "INV-{$code}/{$year}/{$month}/{$seq}";
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
