<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\InventoryPrice;
use App\Models\InventoryStock;
use App\Models\Medication;
use App\Models\PharmacySale;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Visit;
use App\Services\FarmasiService;
use App\Services\InventoryStockService;
use App\Services\PharmacySaleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FarmasiPosDemoSeeder — data demo untuk tab FarmasiView yang BELUM tercakup
 * FarmasiDemoSeeder (yang hanya menyiapkan antrean Dispensing):
 *
 *   1. POS "Penjualan Obat Bebas" — menyiapkan beberapa obat OTC siap-jual
 *      (golongan bebas + HJA terisi di Penentuan Harga + stok unit FARMASI),
 *      lalu membuat beberapa transaksi pharmacy_sales HARI INI (termasuk 1 yang
 *      dibatalkan) lewat PharmacySaleService — supaya stok & consumed_batches
 *      konsisten dengan alur sebenarnya.
 *
 *   2. Obat TAMBAHAN apotek pada resep — menambahkan 1 item source=TAMBAHAN ke
 *      salah satu resep demo (FarmasiDemoSeeder) + menyiapkan 1 kunjungan di
 *      antrean FARMASI TANPA resep dokter (untuk menguji empty-state
 *      "Buat Penjualan OTC").
 *
 * GOTCHA (lihat memory project-golongan-obat-otc-pos-gap): master `golongan` di
 * DB ini kebanyakan NULL / "OBAT KERAS" → POS & OTC SELALU KOSONG. Seeder ini
 * MEMAKSA beberapa obat (yang namanya jelas obat bebas) ber-golongan "OBAT BEBAS"
 * /"SUPLEMEN" supaya fitur bisa didemokan. Ini data demo, bukan koreksi master.
 *
 * IDEMPOTEN: obat OTC dipilih konsisten, HJA/stok di-set (bukan increment), sale
 * demo di-skip kalau transaksi hari ini sudah ada. Item TAMBAHAN di-skip kalau
 * sudah ada.
 *
 * Prasyarat: FarmasiDemoSeeder sudah dijalankan (untuk poin 2 idealnya), master
 * Medication terisi. Manual-only (tidak dipanggil DatabaseSeeder).
 *
 * Jalankan: php artisan db:seed --class=FarmasiPosDemoSeeder
 */
class FarmasiPosDemoSeeder extends Seeder
{
    /**
     * Kandidat obat bebas (by nama). Dipakai untuk memilih obat nyata yang akan
     * "dijadikan" OTC (set golongan + HJA). Kata kunci konservatif obat bebas.
     */
    private array $otcNameHints = [
        'paracetamol', 'parasetamol', 'vitamin', 'cetirizine', 'cetirizin',
        'antasida', 'antasid', 'oralit', 'tolak angin', 'air mata', 'cendo lyteers',
        'natrium', 'cmc', 'artificial tears', 'lubricant', 'sanbe tears', 'insto',
    ];

    public function run(): void
    {
        if (Medication::where('is_active', true)->doesntExist()) {
            $this->command?->warn('FarmasiPosDemoSeeder: belum ada master Medication aktif — import obat dulu. Dibatalkan.');
            return;
        }

        $stockService = app(InventoryStockService::class);

        // 1) Siapkan obat OTC (golongan + HJA + stok), kumpulkan untuk transaksi POS.
        $otcMeds = $this->ensureOtcMedications($stockService);
        if ($otcMeds->isEmpty()) {
            $this->command?->warn('FarmasiPosDemoSeeder: tidak menemukan obat yang bisa dijadikan OTC. POS dilewati.');
        } else {
            $this->seedPosSales($otcMeds);
        }

        // 2) Obat TAMBAHAN pada resep + kunjungan tanpa resep.
        $this->seedTambahanPadaResep($otcMeds, $stockService);
        $this->seedKunjunganTanpaResep($stockService);

        $this->command?->info('FarmasiPosDemoSeeder selesai — obat OTC siap-jual + transaksi POS demo + obat tambahan pada resep + 1 kunjungan tanpa resep.');
    }

    // =========================================================================
    // 1. POS — obat OTC + transaksi penjualan
    // =========================================================================

    /**
     * Pastikan minimal 3 obat layak jual bebas: golongan mengandung "BEBAS"/
     * "SUPLEMEN", HJA > 0 (Penentuan Harga), dan stok unit FARMASI cukup.
     * Mengembalikan koleksi Medication yang sudah disiapkan.
     */
    private function ensureOtcMedications(InventoryStockService $stockService): \Illuminate\Support\Collection
    {
        // Cari obat yang sudah ber-golongan OTC (kalau ada — hormati data nyata).
        $existing = Medication::where('is_active', true)
            ->where(function ($q) {
                foreach (['%BEBAS%', '%SUPLEMEN%', '%JAMU%'] as $kw) {
                    $q->orWhere('golongan', 'ilike', $kw);
                }
            })
            ->where(function ($q) {
                $q->whereNull('golongan')
                  ->orWhere(function ($q2) {
                      $q2->where('golongan', 'not ilike', '%KERAS%')
                         ->where('golongan', 'not ilike', '%NARKOTIKA%')
                         ->where('golongan', 'not ilike', '%PSIKOTROPIKA%');
                  });
            })
            ->orderBy('name')->limit(5)->get();

        // Belum cukup → ambil obat dgn NAMA mirip obat bebas & PAKSA golongannya.
        if ($existing->count() < 3) {
            $candidates = Medication::where('is_active', true)
                ->where(function ($q) {
                    foreach ($this->otcNameHints as $kw) {
                        $q->orWhere('name', 'ilike', "%{$kw}%");
                    }
                })
                ->orderBy('name')->limit(6)->get();

            // Fallback terakhir: obat apa saja yang BUKAN keras/narkotika.
            if ($candidates->isEmpty()) {
                $candidates = Medication::where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('golongan')
                          ->orWhere('golongan', 'not ilike', '%KERAS%');
                    })
                    ->orderBy('name')->limit(4)->get();
            }

            foreach ($candidates as $c) {
                // Set golongan bebas hanya bila belum jelas OTC (jangan timpa data benar).
                $g = strtoupper((string) $c->golongan);
                if ($g === '' || (! str_contains($g, 'BEBAS') && ! str_contains($g, 'SUPLEMEN') && ! str_contains($g, 'JAMU'))) {
                    // Vitamin/suplemen → SUPLEMEN, sisanya OBAT BEBAS.
                    $isSup = str_contains(strtolower($c->name), 'vitamin') || str_contains(strtolower($c->name), 'suplemen');
                    $c->golongan = $isSup ? 'SUPLEMEN' : 'OBAT BEBAS';
                    $c->save();
                }
            }

            $existing = $existing->concat($candidates)->unique('id');
        }

        $picked = $existing->take(4)->values();

        // Set HJA (Penentuan Harga) + stok unit FARMASI untuk tiap obat.
        foreach ($picked as $i => $med) {
            $hja = [12000, 8500, 25000, 15000][$i] ?? 10000;
            InventoryPrice::updateOrCreate(
                ['item_type' => InventoryPrice::TYPE_MEDICATION, 'item_id' => $med->id],
                [
                    'hpp'            => round($hja * 0.7, 2),
                    'margin_percent' => 30,
                    'ppn_enabled'    => false,
                    'hja'            => $hja,
                    'notes'          => 'Harga demo POS (FarmasiPosDemoSeeder)',
                    'effective_date' => today()->toDateString(),
                ]
            );

            // Stok unit FARMASI aman untuk dijual.
            $onHand = $stockService->onHand('MEDICATION', $med->id, InventoryStock::LOC_FARMASI);
            if ($onHand < 50) {
                $stockService->opname([
                    'item_type' => 'MEDICATION',
                    'item_id'   => $med->id,
                    'location'  => InventoryStock::LOC_FARMASI,
                    'new_qty'   => 100,
                    'reason'    => 'Stok awal demo POS (FarmasiPosDemoSeeder)',
                ]);
            }
        }

        return $picked;
    }

    /**
     * Buat beberapa transaksi POS HARI INI lewat PharmacySaleService::checkout,
     * lalu batalkan satu — supaya tab Penjualan + Riwayat (termasuk status batal)
     * terisi. Skip kalau sudah ada transaksi hari ini (idempoten).
     */
    private function seedPosSales(\Illuminate\Support\Collection $otcMeds): void
    {
        if (PharmacySale::whereDate('created_at', today())->exists()) {
            $this->command?->info('FarmasiPosDemoSeeder: sudah ada transaksi POS hari ini — seed penjualan dilewati.');
            return;
        }

        $service = app(PharmacySaleService::class);
        $a = $otcMeds->get(0);
        $b = $otcMeds->get(1) ?? $a;
        $c = $otcMeds->get(2) ?? $a;

        // Skenario transaksi demo (qty kecil supaya stok 100 lebih dari cukup).
        $scenarios = [
            [
                'buyer_name'     => 'Pembeli Umum',
                'payment_method' => 'CASH',
                'items'          => [['medication_id' => $a->id, 'quantity' => 2]],
                'discount'       => 0,
                'paid'           => 50000,
                'cancel'         => false,
            ],
            [
                'buyer_name'     => 'Ibu Sari',
                'payment_method' => 'CASH',
                'items'          => [
                    ['medication_id' => $a->id, 'quantity' => 1],
                    ['medication_id' => $b->id, 'quantity' => 3],
                ],
                'discount'       => 2000,
                'paid'           => 100000,
                'cancel'         => false,
            ],
            [
                'buyer_name'     => 'Bapak Joko (dibatalkan)',
                'payment_method' => 'CARD',
                'items'          => [['medication_id' => $c->id, 'quantity' => 2]],
                'discount'       => 0,
                'paid'           => 100000,
                'cancel'         => true,   // → demo status CANCELLED + restock
            ],
        ];

        $made = 0; $cancelled = 0;
        foreach ($scenarios as $s) {
            try {
                $sale = $service->checkout([
                    'buyer_name'     => $s['buyer_name'],
                    'payment_method' => $s['payment_method'],
                    'paid_amount'    => $s['paid'],
                    'discount'       => $s['discount'],
                    'items'          => $s['items'],
                ]);
                $made++;

                if ($s['cancel']) {
                    $service->cancel($sale->id, 'Pembeli batal — demo');
                    $cancelled++;
                }
            } catch (\Throwable $e) {
                $this->command?->warn('FarmasiPosDemoSeeder: gagal buat transaksi POS demo — ' . $e->getMessage());
            }
        }

        $this->command?->info("FarmasiPosDemoSeeder: {$made} transaksi POS dibuat ({$cancelled} dibatalkan).");
    }

    // =========================================================================
    // 2. Obat TAMBAHAN pada resep + kunjungan tanpa resep
    // =========================================================================

    /**
     * Tambahkan 1 item source=TAMBAHAN ke salah satu resep demo yang ada
     * (prioritas resep dari FarmasiDemoSeeder di antrean FARMASI hari ini).
     * Idempoten: skip kalau resep itu sudah punya item TAMBAHAN.
     */
    private function seedTambahanPadaResep(
        \Illuminate\Support\Collection $otcMeds,
        InventoryStockService $stockService
    ): void {
        if ($otcMeds->isEmpty()) return;

        $presc = Prescription::whereHas('visit', fn ($q) => $q
                ->whereDate('visit_date', today())
                ->where('current_station', 'FARMASI')
            )
            ->whereHas('items', fn ($q) => $q->where('source', 'RESEP'))
            ->orderByDesc('created_at')
            ->first();

        if (! $presc) {
            $this->command?->warn('FarmasiPosDemoSeeder: tidak ada resep demo FARMASI hari ini — jalankan FarmasiDemoSeeder dulu untuk demo obat tambahan. Dilewati.');
            return;
        }

        if ($presc->items()->where('source', 'TAMBAHAN')->exists()) {
            return;   // sudah ada item tambahan
        }

        $med   = $otcMeds->first();
        $qty   = 1;
        $addBy = Employee::orderBy('name')->value('id');

        DB::transaction(function () use ($presc, $med, $qty, $addBy, $stockService) {
            PrescriptionItem::create([
                'prescription_id' => $presc->id,
                'medication_id'   => $med->id,
                'source'          => 'TAMBAHAN',
                'added_by_id'     => $addBy,
                'quantity'        => $qty,
                'dosage'          => '1 sesuai kebutuhan',
                'instructions'    => 'Diminta pasien saat di apotek (obat bebas)',
                'dose'            => '1',
                'frequency'       => 'k/p',
                'route'           => 'Oral',
                'duration_days'   => 3,
                'notes'           => 'TAMBAHAN apotek (demo)',
            ]);

            // Pastikan stok unit FARMASI cukup untuk item tambahan.
            $onHand = $stockService->onHand('MEDICATION', $med->id, InventoryStock::LOC_FARMASI);
            if ($onHand < $qty) {
                $stockService->opname([
                    'item_type' => 'MEDICATION',
                    'item_id'   => $med->id,
                    'location'  => InventoryStock::LOC_FARMASI,
                    'new_qty'   => $qty + 20,
                    'reason'    => 'Stok demo obat tambahan (FarmasiPosDemoSeeder)',
                ]);
            }
        });

        $this->command?->info("FarmasiPosDemoSeeder: 1 item TAMBAHAN ({$med->name}) ditambahkan ke resep {$presc->id}.");
    }

    /**
     * Siapkan 1 kunjungan di antrean FARMASI hari ini TANPA resep dokter, supaya
     * panel dispensing menampilkan empty-state "Buat Penjualan OTC". Reuse pasien
     * UMUM demo FarmasiDemoSeeder bila ada; jika tidak, pakai pasien aktif mana saja.
     */
    private function seedKunjunganTanpaResep(InventoryStockService $stockService): void
    {
        $patient = \App\Models\Patient::where('name', 'ilike', '%(Demo UMUM)%')->first()
            ?? \App\Models\Patient::where('is_active', true)->orderBy('created_at')->first();

        if (! $patient) {
            $this->command?->warn('FarmasiPosDemoSeeder: tidak ada pasien untuk kunjungan tanpa resep. Dilewati.');
            return;
        }

        // Visit khusus "tanpa resep" dipisah dari visit dispensing (classification beda)
        // supaya tidak bentrok dgn FarmasiDemoSeeder (yang current_station=FARMASI juga).
        $visit = Visit::firstOrNew([
            'patient_id'      => $patient->id,
            'visit_date'      => today()->toDateString(),
            'current_station' => 'FARMASI',
            'classification'  => 'Beli Obat',
        ]);
        if (! $visit->exists) {
            $umum = \App\Models\Insurer::where('is_system', true)->where('type', 'UMUM')->first();
            $visit->fill([
                'insurer_id'       => $umum?->id,
                'visit_type'       => 'REGULAR',
                'guarantor_type'   => 'UMUM',
                'ready_for_doctor' => true,
            ]);
            $visit->save();
        }

        // Jangan buat Prescription untuk visit ini (justru itu yang diuji).
        // Enqueue ke FARMASI (idempoten).
        if (! \App\Models\Queue::where('visit_id', $visit->id)->where('station', 'FARMASI')->exists()) {
            $prefix = \App\Models\Queue::prefixFor('FARMASI');
            $seq = (int) (\App\Models\Queue::where('station', 'FARMASI')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
            \App\Models\Queue::create([
                'visit_id'       => $visit->id,
                'station'        => 'FARMASI',
                'queue_prefix'   => $prefix,
                'queue_sequence' => $seq,
                'queue_number'   => $prefix . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                'status'         => 'WAITING',
            ]);
        }

        $this->command?->info('FarmasiPosDemoSeeder: 1 kunjungan FARMASI tanpa resep disiapkan (uji empty-state Buat Penjualan OTC).');
    }
}
