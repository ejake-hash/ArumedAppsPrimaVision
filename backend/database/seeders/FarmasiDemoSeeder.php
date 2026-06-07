<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\InventoryStock;
use App\Models\Insurer;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Queue;
use App\Models\Visit;
use App\Services\InventoryStockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FarmasiDemoSeeder — pasien demo siap dispensing di stasiun FARMASI,
 * 1 per penjamin (UMUM, BPJS, ASURANSI).
 *
 * Tiap pasien:
 *   - 1 kunjungan HARI INI dengan current_station = FARMASI (sudah lewat dokter).
 *   - 1 Prescription status DRAFT + beberapa PrescriptionItem dgn Medication nyata
 *     (campur TETES_MATA / SALEP_MATA = obat luar + TABLET = oral, supaya etiket
 *     biru/putih ke-exercise).
 *   - 1 baris Queue station FARMASI (WAITING) → tampil di antrean Farmasi.
 *
 * Selain itu, seeder MEMASTIKAN stok unit FARMASI cukup (via InventoryStockService::opname
 * ke lokasi FARMASI) untuk tiap obat yang diresepkan — supaya alur Serahkan
 * (selesaiDispensing → consume) tidak ditolak 422 "stok tidak mencukupi".
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate via NIK + visit_date/station;
 * resep & queue di-skip kalau sudah ada; opname set-total bukan increment).
 *
 * Jalankan: php artisan db:seed --class=FarmasiDemoSeeder
 */
class FarmasiDemoSeeder extends Seeder
{
    /** Satu pasien per penjamin. */
    private array $profiles = [
        [
            'key'       => 'umum',
            'name'      => 'Hendra Gunawan',
            'gender'    => 'L',
            'dob'       => '1979-04-11',
            'guarantor' => 'UMUM',
            'bpjs'      => null,
            'address'   => 'Jl. Iskandar Muda No. 21, Kel. Babura',
        ],
        [
            'key'       => 'bpjs',
            'name'      => 'Nurhayati',
            'gender'    => 'P',
            'dob'       => '1965-12-03',
            'guarantor' => 'BPJS',
            'bpjs'      => '0002233445566',
            'address'   => 'Jl. Setia Budi No. 9, Kel. Tanjung Sari',
        ],
        [
            'key'       => 'asuransi',
            'name'      => 'Dewi Lestari',
            'gender'    => 'P',
            'dob'       => '1991-07-29',
            'guarantor' => 'ASURANSI',
            'bpjs'      => null,
            'address'   => 'Jl. Ringroad No. 100, Kel. Asam Kumbang',
        ],
    ];

    public function run(): void
    {
        $asuransiInsurer = $this->ensureAsuransiInsurer();
        $umumInsurer     = Insurer::where('is_system', true)->where('type', 'UMUM')->first();
        $bpjsInsurer     = Insurer::where('is_system', true)->where('type', 'BPJS')->first();

        // Dokter peresep — ambil employee dokter pertama (fallback employee mana saja).
        $doctor = Employee::query()
            ->when(true, fn ($q) => $q->orderBy('name'))
            ->first();
        if (! $doctor) {
            $this->command?->warn('FarmasiDemoSeeder: belum ada Employee — jalankan EmployeeSeeder dulu. Dibatalkan.');
            return;
        }

        // Obat untuk resep: utamakan obat mata (luar) + 1 oral supaya etiket beragam.
        $medications = $this->pickMedications();
        if ($medications->isEmpty()) {
            $this->command?->warn('FarmasiDemoSeeder: belum ada master Medication aktif — import obat dulu. Dibatalkan.');
            return;
        }

        $stockService = app(InventoryStockService::class);
        $created = 0;

        DB::transaction(function () use (
            $asuransiInsurer, $umumInsurer, $bpjsInsurer, $doctor, $medications, $stockService, &$created
        ) {
            $patIndex = 0;
            foreach ($this->profiles as $prof) {
                $patIndex++;
                $suffix = str_pad((string) $patIndex, 2, '0', STR_PAD_LEFT);
                $nik    = substr('3275' . $suffix . '88002200', 0, 16);
                $bpjs   = $prof['bpjs'] ? substr($prof['bpjs'] . $suffix, 0, 13) : null;

                $patient = Patient::firstOrCreate(
                    ['nik' => $nik],
                    [
                        'no_rm'         => 'FR' . $suffix . '0001',
                        'name'          => $prof['name'] . ' (Demo ' . strtoupper($prof['key']) . ')',
                        'gender'        => $prof['gender'],
                        'date_of_birth' => $prof['dob'],
                        'phone'         => '0812-' . $suffix . '-8800',
                        'address'       => $prof['address'] ?? null,
                        'province'      => 'Sumatera Utara',
                        'bpjs_number'   => $bpjs,
                        'is_active'     => true,
                    ]
                );

                $insurerId = match ($prof['guarantor']) {
                    'ASURANSI' => $asuransiInsurer?->id,
                    'BPJS'     => $bpjsInsurer?->id,
                    default    => $umumInsurer?->id,
                };

                // Kunjungan hari ini di stasiun FARMASI (sudah lewat triase/refraksi/dokter).
                $visit = Visit::firstOrNew([
                    'patient_id'      => $patient->id,
                    'visit_date'      => today()->toDateString(),
                    'current_station' => 'FARMASI',
                ]);
                if (! $visit->exists) {
                    $visit->fill([
                        'insurer_id'            => $insurerId,
                        'classification'        => 'Kontrol',
                        'visit_type'            => 'REGULAR',
                        'guarantor_type'        => $prof['guarantor'],
                        'ready_for_doctor'      => true,
                        'triase_completed_at'   => now()->subHours(4),
                        'refraksi_completed_at' => now()->subHours(3),
                    ]);
                    $visit->save();
                }

                // Resep + item (idempoten: skip kalau visit sudah punya resep).
                $this->seedPrescription($visit, $doctor, $medications, $stockService);

                // Antrean FARMASI.
                $this->enqueueFarmasi($visit);

                $created++;
            }
        });

        $this->command?->info("FarmasiDemoSeeder selesai — {$created} pasien (UMUM/BPJS/ASURANSI) di antrean Farmasi + resep DRAFT + stok unit FARMASI tercukupi.");
    }

    /**
     * Pilih hingga 3 obat: prioritaskan sediaan mata/luar (etiket BIRU) + 1 oral
     * (etiket PUTIH) supaya demo etiket beragam.
     *
     * CATATAN: master `form_sediaan` di DB ini TIDAK pakai enum model
     * (TETES_MATA/SALEP_MATA) — kebanyakan kosong, sisanya SALEP/BOTOL/AMPUL/dst.
     * Jadi deteksi obat luar/mata via NAMA (mirror heuristik etiket di FarmasiView).
     */
    private function pickMedications(): \Illuminate\Support\Collection
    {
        $base = fn () => Medication::query()->where('is_active', true);

        // Obat luar/mata berdasarkan nama (tetes mata, salep, eye drop, dll).
        $mata = $base()
            ->where(function ($q) {
                foreach (['%tetes mata%', '%eye drop%', '%salep mata%', '%zalf%', '%tetes%'] as $kw) {
                    $q->orWhere('name', 'ilike', $kw);
                }
            })
            ->orderBy('name')->limit(2)->get();

        // Oral — tablet/kaplet/kapsul (etiket PUTIH).
        $oral = $base()
            ->whereIn('form_sediaan', ['TABLET', 'KAPLET', 'KAPSUL', 'SIRUP'])
            ->orderBy('name')->limit(1)->get();

        $picked = $mata->concat($oral)->unique('id');

        // Fallback: kalau tidak ketemu apa pun, ambil obat apa saja.
        if ($picked->isEmpty()) {
            $picked = $base()->orderBy('name')->limit(3)->get();
        }

        return $picked->values();
    }

    /** Buat resep DRAFT + item; pastikan stok unit FARMASI cukup untuk tiap item. */
    private function seedPrescription(
        Visit $visit,
        Employee $doctor,
        \Illuminate\Support\Collection $medications,
        InventoryStockService $stockService
    ): void {
        $presc = Prescription::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'prescribed_by_id' => $doctor->id,
                'status'           => 'DRAFT',
                'notes'            => 'Pakai sesuai aturan. Kontrol bila keluhan bertambah.',
            ]
        );

        if ($presc->items()->exists()) {
            // Resep sudah ada — tetap pastikan stok cukup untuk item yang ada.
            $presc->load('items');
            foreach ($presc->items as $item) {
                $this->ensureFarmasiStock($stockService, $item->medication_id, (float) $item->quantity);
            }
            return;
        }

        foreach ($medications as $med) {
            $isMata = $this->isObatLuar($med->name);
            $qty    = $isMata ? 1 : 10;   // tetes/salep per botol/tube, oral per-butir

            PrescriptionItem::create([
                'prescription_id' => $presc->id,
                'medication_id'   => $med->id,
                'quantity'        => $qty,
                'dosage'          => $isMata ? '1 tetes' : '1 tablet',
                'instructions'    => $isMata ? '4x/hari ODS' : '3x/hari sesudah makan',
                'dose'            => $isMata ? '1 tetes' : '1 tablet',
                'frequency'       => $isMata ? '4x/hari' : '3x/hari',
                'route'           => $isMata ? 'Tetes mata' : 'Oral',
                'duration_days'   => $isMata ? 14 : 5,
                'notes'           => $isMata ? 'ODS' : 'Sesudah makan',
            ]);

            // Pastikan stok unit FARMASI minimal cukup (set-total via opname, idempoten).
            $this->ensureFarmasiStock($stockService, $med->id, $qty);
        }
    }

    /** Deteksi obat luar/mata dari nama (mirror heuristik etiket FarmasiView). */
    private function isObatLuar(string $name): bool
    {
        $hay = strtolower($name);
        foreach (['tetes', 'eye drop', 'salep', 'zalf', 'ointment', 'krim', 'cream', 'gel', 'mata'] as $kw) {
            if (str_contains($hay, $kw)) return true;
        }
        return false;
    }

    /**
     * Pastikan on-hand obat di lokasi FARMASI >= $need. Kalau kurang, opname
     * set-total ke nilai aman (need + buffer). opname idempoten (set, bukan tambah).
     */
    private function ensureFarmasiStock(InventoryStockService $stockService, string $medicationId, float $need): void
    {
        $onHand = $stockService->onHand('MEDICATION', $medicationId, InventoryStock::LOC_FARMASI);
        if ($onHand >= $need) {
            return;
        }
        $stockService->opname([
            'item_type' => 'MEDICATION',
            'item_id'   => $medicationId,
            'location'  => InventoryStock::LOC_FARMASI,
            'new_qty'   => $need + 20,   // buffer demo
            'reason'    => 'Stok awal demo Farmasi (FarmasiDemoSeeder)',
        ]);
    }

    /**
     * Pastikan ada 1 insurer bertipe ASURANSI (non-sistem) agar pasien demo
     * ASURANSI punya penjamin nyata. Pada DB fresh hanya ada UMUM/BPJS/SOSIAL
     * (sistem). firstOrCreate idempoten via code; selaras dgn KasirDemoSeeder.
     */
    private function ensureAsuransiInsurer(): Insurer
    {
        $existing = Insurer::where('type', 'ASURANSI')->where('is_active', true)->first();
        if ($existing) {
            return $existing;
        }

        return Insurer::firstOrCreate(
            ['code' => 'ASR-DEMO'],
            [
                'name'      => 'Asuransi Sehat Sentosa (Demo)',
                'type'      => 'ASURANSI',
                'is_active' => true,
                'is_system' => false,
                'is_tpa'    => false,
            ]
        );
    }

    /** Enqueue ke antrean FARMASI hari ini (idempoten via visit+station). */
    private function enqueueFarmasi(Visit $visit): void
    {
        if (Queue::where('visit_id', $visit->id)->where('station', 'FARMASI')->exists()) {
            return;
        }
        $prefix = Queue::prefixFor('FARMASI'); // 'F'
        $seq = (int) (Queue::where('station', 'FARMASI')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
        Queue::create([
            'visit_id'       => $visit->id,
            'station'        => 'FARMASI',
            'queue_prefix'   => $prefix,
            'queue_sequence' => $seq,
            'queue_number'   => $prefix . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'status'         => 'WAITING',
        ]);
    }
}
