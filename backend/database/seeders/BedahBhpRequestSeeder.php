<?php

namespace Database\Seeders;

use App\Models\BhpItem;
use App\Models\BhpTariff;
use App\Models\DoctorExamination;
use App\Models\Employee;
use App\Models\Insurer;
use App\Models\InventoryStock;
use App\Models\Patient;
use App\Models\SurgeryPackage;
use App\Models\SurgeryPackageItem;
use App\Models\SurgeryPackageTariff;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * BedahBhpRequestSeeder — data uji untuk alur REQUEST BHP di Bedah Terjadwal.
 *
 * Membuat paket bedah dengan BANYAK item BHP (14) + rantai pasien terjadwal,
 * sehingga tombol "Request BHP/IOL" di /bedah/terjadwal menampilkan & mengirim
 * banyak BHP. Stok BHP di-seed di lokasi GUDANG (INVENTORI) supaya admin gudang
 * bisa approve+deliver (transfer INVENTORI → BEDAH, lihat stok per-lokasi).
 *
 * Rantai yang dibaca BedahTerjadwalView via GET /bedah/jadwal?upcoming=1:
 *   patient → visit (PREOP_BEDAH, surgery_schedule_id)
 *           → doctor_examination (planning=BEDAH, diagnosis_utama)
 *           → surgery_schedule (SCHEDULED, H+2) → surgery_package (14 item BHP)
 *
 * IDEMPOTEN. Jalankan: php artisan db:seed --class=BedahBhpRequestSeeder
 */
class BedahBhpRequestSeeder extends Seeder
{
    /** Master BHP untuk bedah katarak/phaco — kode BHP-95x agar tak bentrok demo (BHP-90x). */
    private const BHP_DEFS = [
        ['code' => 'BHP-950', 'name' => 'Viscoelastic / OVD (Hydroxypropyl)', 'unit' => 'pcs', 'price' => 350000, 'category' => 'MEDICAL_SUPPLIES'],
        ['code' => 'BHP-951', 'name' => 'Phaco Tip 2.8mm',                    'unit' => 'pcs', 'price' => 650000, 'category' => 'INSTRUMENT_SET'],
        ['code' => 'BHP-952', 'name' => 'Phaco Sleeve Silikon',               'unit' => 'pcs', 'price' => 180000, 'category' => 'INSTRUMENT_SET'],
        ['code' => 'BHP-953', 'name' => 'Surgical Blade 2.75mm Keratom',      'unit' => 'pcs', 'price' => 95000,  'category' => 'MEDICAL_BHP'],
        ['code' => 'BHP-954', 'name' => 'Sideport Blade 15°',                 'unit' => 'pcs', 'price' => 75000,  'category' => 'MEDICAL_BHP'],
        ['code' => 'BHP-955', 'name' => 'Capsulorhexis Forceps (CSSD)',       'unit' => 'set', 'price' => 0,      'category' => 'CSSD'],
        ['code' => 'BHP-956', 'name' => 'Irrigation/Aspiration Cannula',      'unit' => 'pcs', 'price' => 120000, 'category' => 'MEDICAL_SUPPLIES'],
        ['code' => 'BHP-957', 'name' => 'Balanced Salt Solution (BSS) 500ml', 'unit' => 'botol','price' => 85000, 'category' => 'MEDICAL_SUPPLIES'],
        ['code' => 'BHP-958', 'name' => 'IOL Injector Cartridge',             'unit' => 'pcs', 'price' => 220000, 'category' => 'MEDICAL_SUPPLIES'],
        ['code' => 'BHP-959', 'name' => 'Trypan Blue 0.06% (Pewarna Kapsul)',  'unit' => 'vial','price' => 145000, 'category' => 'MEDICAL_BHP'],
        ['code' => 'BHP-960', 'name' => 'Nylon Suture 10-0',                  'unit' => 'pcs', 'price' => 65000,  'category' => 'MEDICAL_BHP'],
        ['code' => 'BHP-961', 'name' => 'Eye Drape Steril (Lubang)',          'unit' => 'pcs', 'price' => 40000,  'category' => 'MEDICAL_SUPPLIES'],
        ['code' => 'BHP-962', 'name' => 'Sponge Spear / Weck-Cel',            'unit' => 'pak', 'price' => 30000,  'category' => 'MEDICAL_BHP'],
        ['code' => 'BHP-963', 'name' => 'Set Instrumen Katarak (CSSD)',       'unit' => 'set', 'price' => 0,      'category' => 'CSSD'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $insurers = [
                'UMUM' => Insurer::where('name', 'UMUM')->first(),
                'BPJS' => Insurer::where('name', 'BPJS')->first(),
            ];

            $bhps = $this->seedBhp();
            $this->seedStock($bhps);
            $this->seedTariffs($insurers, $bhps);
            $package = $this->seedPackage($bhps, $insurers);
            $this->seedScheduledPatient($package);
        });

        $this->command?->info('BedahBhpRequestSeeder selesai — paket "Paket Phaco Lengkap (BHP)" dengan 14 BHP + pasien terjadwal. Buka /bedah/terjadwal → Request BHP/IOL.');
    }

    /** @return BhpItem[] */
    private function seedBhp(): array
    {
        $out = [];
        foreach (self::BHP_DEFS as $d) {
            $out[] = BhpItem::firstOrCreate(
                ['code' => $d['code']],
                [
                    'name'      => $d['name'],
                    'unit'      => $d['unit'],
                    'stock'     => 0,           // legacy — stok riil di inventory_stocks
                    'min_stock' => 0,
                    'price'     => $d['price'],
                    'category'  => $d['category'],
                    'is_active' => true,
                ]
            );
        }
        return $out;
    }

    /** Stok awal per-batch di GUDANG (INVENTORI). 80 unit tiap BHP — cukup untuk uji deliver berulang. */
    private function seedStock(array $bhps): void
    {
        $batch = 'SEED-BHP-' . now()->format('Ymd');
        $exp   = today()->addYears(2)->toDateString();

        foreach ($bhps as $b) {
            $stock = InventoryStock::firstOrNew([
                'item_type' => InventoryStock::TYPE_BHP,
                'location'  => InventoryStock::LOC_INVENTORI,
                'item_id'   => $b->id,
                'batch_no'  => $batch,
            ]);
            if (! $stock->exists) {
                $stock->expiry_date      = $exp;
                $stock->qty_on_hand      = 80;
                $stock->last_received_at = now();
                $stock->save();
            }
        }
    }

    /** Tarif BHP per-penjamin (UMUM + BPJS) supaya muncul benar di billing kasir. */
    private function seedTariffs(array $insurers, array $bhps): void
    {
        foreach ($insurers as $ins) {
            if (! $ins) continue;
            foreach ($bhps as $b) {
                BhpTariff::firstOrCreate(
                    ['bhp_item_id' => $b->id, 'insurer_id' => $ins->id],
                    ['price' => $b->price, 'is_active' => true]
                );
            }
        }
    }

    /** Paket bedah berisi SEMUA BHP di atas sebagai surgery_package_items (item_type=BHP). */
    private function seedPackage(array $bhps, array $insurers): SurgeryPackage
    {
        $package = SurgeryPackage::firstOrCreate(
            ['name' => 'Paket Phaco Lengkap (BHP)'],
            [
                'code'               => 'PKG-PHACO-BHP',
                'category'           => 'Bedah Katarak',
                'description'        => 'Paket uji request BHP — komposisi BHP lengkap untuk fakoemulsifikasi (seed).',
                'estimated_duration' => 60,
                'price'              => 9500000,
                'total_base_price'   => 0,
                'is_active'          => true,
            ]
        );

        // Isi item BHP hanya bila paket belum punya item (idempoten).
        if ($package->items()->count() === 0) {
            foreach ($bhps as $i => $b) {
                // Sebagian item qty 2 (mata + cadangan) untuk variasi, sisanya 1.
                $qty = in_array($i, [0, 3, 7], true) ? 2 : 1;
                SurgeryPackageItem::create([
                    'surgery_package_id' => $package->id,
                    'item_type'          => 'BHP',
                    'item_id'            => $b->id,
                    'quantity'           => $qty,
                    'default_price'      => $b->price,
                ]);
            }
            $package->recalcTotalBasePrice();
        }

        foreach ($insurers as $key => $ins) {
            SurgeryPackageTariff::firstOrCreate(
                ['surgery_package_id' => $package->id, 'insurer_id' => $ins?->id, 'classification' => 'Pre-Op'],
                ['sell_price' => $key === 'BPJS' ? 8800000 : $package->price, 'is_active' => true]
            );
        }

        return $package;
    }

    /** Rantai pasien terjadwal MENDATANG (H+2) yang menunjuk ke paket di atas. */
    private function seedScheduledPatient(SurgeryPackage $package): void
    {
        $surgeon = Employee::query()
            ->where('profession', 'like', '%dokter%')
            ->orWhere('profession', 'like', '%Sp.M%')
            ->first()
            ?? Employee::query()->first();

        $patient = Patient::firstOrCreate(
            ['nik' => '1271065208600033'],
            [
                'no_rm'         => now()->format('Ym') . '7033',
                'name'          => 'Bambang Sutrisno (Demo BHP)',
                'gender'        => 'L',
                'date_of_birth' => '1955-06-15',
                'phone'         => '0812-7000-0033',
                'province'      => 'Sumatera Utara',
                'bpjs_number'   => '0007033000033',
                'is_active'     => true,
            ]
        );

        $schedule = SurgerySchedule::firstOrCreate(
            [
                'surgery_package_id' => $package->id,
                'scheduled_date'     => today()->addDays(2)->toDateString(),
                'scheduled_time'     => '10:00:00',
            ],
            [
                'lead_surgeon_id' => $surgeon?->id,
                'operation_room'  => 'OK 1',
                'status'          => 'SCHEDULED',
                'notes'           => 'Jadwal uji request BHP (auto-seed BedahBhpRequestSeeder).',
            ]
        );

        $visit = Visit::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id],
            [
                'patient_id'            => $patient->id,
                'visit_date'            => today(),
                'classification'        => 'Pre-Op',
                'visit_type'            => 'PREOP_BEDAH',
                'current_station'       => 'DOKTER',
                'guarantor_type'        => 'BPJS',
                'satusehat_sync_status' => 'PENDING',
            ]
        );

        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'           => $surgeon?->id,
                'anamnese'            => 'Katarak matur OD, rencana fakoemulsifikasi + IOL.',
                'diagnosis_utama'     => 'H25.9',
                'planning'            => 'BEDAH',
                'surgery_package_id'  => $package->id,
                'surgery_schedule_id' => $schedule->id,
                'is_finalized'        => true,
                'finalized_at'        => now(),
            ]
        );
    }
}
