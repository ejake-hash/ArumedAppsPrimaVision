<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Medication;
use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\Procedure;
use App\Models\Insurer;
use App\Models\Employee;
use App\Models\InventoryStock;
use App\Models\SurgeryPackage;
use App\Models\SurgeryPackageItem;
use App\Models\SurgeryPackageTariff;
use App\Models\SurgerySchedule;
use App\Models\Patient;
use App\Models\Visit;
use App\Models\Queue;
use App\Models\DoctorExamination;
use App\Models\ProcedureTariff;
use App\Models\BhpTariff;
use App\Models\IolTariff;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\BillingInvoice;
use App\Models\BillingItem;
use App\Models\DocumentTemplate;
use App\Models\PatientDocument;

/**
 * BedahDemoSeeder — data demo lengkap untuk skenario BEDAH (Phaco/katarak):
 *   1. Master: Medication + BHP + IOL (oftalmologi)
 *   2. Stok awal di inventory_stocks (per-batch) → dispensing/surgery-request jalan
 *   3. Tarif per-penjamin (UMUM + BPJS): procedure / bhp / iol
 *   4. Paket Bedah Phaco composite (items + tariffs per-penjamin)
 *   5. Satu pasien duduk di antrean BEDAH HARI INI → tampil di BedahView
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate / cek existence).
 *
 * Jalankan: php artisan db:seed --class=BedahDemoSeeder
 */
class BedahDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $insurers = $this->resolveInsurers();

            $meds = $this->seedMedications();
            $bhps = $this->seedBhp();
            $iols = $this->seedIol();
            $proc = $this->seedProcedure();

            $this->seedStock($meds, $bhps, $iols);
            $this->seedTariffs($insurers, $proc, $bhps, $iols);
            $package = $this->seedPackage($proc, $meds, $bhps, $iols, $insurers);
            $this->seedBedahPatientToday($package);

            // Pasien di stasiun hilir (idempoten via NIK + station+today).
            $this->seedFarmasiPatientToday($meds);
            $this->seedKasirPatientToday($proc, $meds);
            // DINONAKTIFKAN (7 Jun 2026): seedTtdDoctorQueue() membuat template demo
            // 'DEMO_TTD_DOKTER' — dikecualikan saat pembersihan Form Registry.
            // $this->seedTtdDoctorQueue();
        });

        $this->command?->info('BedahDemoSeeder selesai — master + stok + tarif + paket + pasien BEDAH/FARMASI/KASIR/TTD hari ini.');
    }

    /** UMUM + BPJS insurer (sistem) untuk tarif. */
    private function resolveInsurers(): array
    {
        return [
            'UMUM' => Insurer::where('name', 'UMUM')->first(),
            'BPJS' => Insurer::where('name', 'BPJS')->first(),
        ];
    }

    private function seedMedications(): array
    {
        $defs = [
            ['code' => 'MED-901', 'name' => 'Cendo Tobroson Tetes Mata', 'unit' => 'botol', 'price' => 45000, 'golongan' => 'Antibiotik'],
            ['code' => 'MED-902', 'name' => 'Cendo Xitrol Tetes Mata',   'unit' => 'botol', 'price' => 52000, 'golongan' => 'Steroid'],
            ['code' => 'MED-903', 'name' => 'Asam Mefenamat 500mg',       'unit' => 'tablet','price' => 1500,  'golongan' => 'Analgetik'],
        ];
        $out = [];
        foreach ($defs as $d) {
            $out[] = Medication::firstOrCreate(
                ['code' => $d['code']],
                [
                    'name'        => $d['name'],
                    'formularium' => 'NON_FORNAS',
                    'unit'        => $d['unit'],
                    'stock'       => 0,        // legacy — stok riil di inventory_stocks
                    'min_stock'   => 0,
                    'price'       => $d['price'],
                    'golongan'    => $d['golongan'],
                    'is_active'   => true,
                ]
            );
        }
        return $out;
    }

    private function seedBhp(): array
    {
        $defs = [
            ['code' => 'BHP-901', 'name' => 'Viscoelastic (OVD)',        'unit' => 'pcs', 'price' => 350000, 'category' => 'MEDICAL_BHP'],
            ['code' => 'BHP-902', 'name' => 'Phaco Tip & Sleeve Set',    'unit' => 'set', 'price' => 750000, 'category' => 'INSTRUMENT_SET'],
            ['code' => 'BHP-903', 'name' => 'Surgical Blade 15°',        'unit' => 'pcs', 'price' => 25000,  'category' => 'MEDICAL_BHP'],
        ];
        $out = [];
        foreach ($defs as $d) {
            $out[] = BhpItem::firstOrCreate(
                ['code' => $d['code']],
                [
                    'name'      => $d['name'],
                    'unit'      => $d['unit'],
                    'stock'     => 0,          // legacy — stok riil di inventory_stocks
                    'min_stock' => 0,
                    'price'     => $d['price'],
                    'category'  => $d['category'],
                    'is_active' => true,
                ]
            );
        }
        return $out;
    }

    private function seedIol(): array
    {
        $defs = [
            ['brand' => 'Alcon', 'model' => 'AcrySof IQ', 'iol_type' => 'MONOFOCAL', 'power' => 21.0, 'price' => 2500000],
            ['brand' => 'Alcon', 'model' => 'AcrySof IQ', 'iol_type' => 'MONOFOCAL', 'power' => 22.5, 'price' => 2500000],
            ['brand' => 'Zeiss', 'model' => 'CT Lucia',   'iol_type' => 'MONOFOCAL', 'power' => 20.0, 'price' => 3200000],
        ];
        $out = [];
        foreach ($defs as $d) {
            $out[] = IolItem::firstOrCreate(
                ['brand' => $d['brand'], 'model' => $d['model'], 'power' => $d['power']],
                [
                    'iol_type'  => $d['iol_type'],
                    'material'  => 'Acrylic Hydrophobic',
                    'stock'     => 0,
                    'is_used'   => false,
                    'price'     => $d['price'],
                    'is_active' => true,
                ]
            );
        }
        return $out;
    }

    /** Procedure Phaco (tindakan bedah utama). */
    private function seedProcedure(): Procedure
    {
        return Procedure::firstOrCreate(
            ['name' => 'Phacoemulsifikasi + IOL'],
            [
                'code'       => 'TND-PHACO',
                'category'   => 'Bedah',
                'icd9_code'  => '13.41',
                'base_price' => 3000000,
                'is_active'  => true,
            ]
        );
    }

    /** Stok awal per-batch di inventory_stocks (sumber stok riil pasca-redesign). */
    private function seedStock(array $meds, array $bhps, array $iols): void
    {
        $batch = 'SEED-' . now()->format('Ymd');
        $exp   = today()->addYears(2)->toDateString();

        foreach ($meds as $m) $this->upsertStock('MEDICATION', $m->id, 100, $batch, $exp);
        foreach ($bhps as $b) $this->upsertStock('BHP', $b->id, 50, $batch, $exp);
        foreach ($iols as $i) $this->upsertStock('IOL', $i->id, 10, $batch, $exp);
    }

    private function upsertStock(string $type, string $itemId, float $qty, string $batch, string $exp): void
    {
        $stock = InventoryStock::firstOrNew([
            'item_type' => $type,
            'item_id'   => $itemId,
            'batch_no'  => $batch,
        ]);
        // Hanya set qty saat baris baru — jangan timpa stok berjalan kalau sudah ada.
        if (! $stock->exists) {
            $stock->expiry_date      = $exp;
            $stock->qty_on_hand      = $qty;
            $stock->last_received_at = now();
            $stock->save();
        }
    }

    /** Tarif per-penjamin (UMUM + BPJS) untuk procedure / bhp / iol. */
    private function seedTariffs(array $insurers, Procedure $proc, array $bhps, array $iols): void
    {
        foreach ($insurers as $ins) {
            if (! $ins) continue;

            ProcedureTariff::firstOrCreate(
                ['procedure_id' => $proc->id, 'insurer_id' => $ins->id],
                ['price' => $proc->base_price, 'is_active' => true]
            );
            foreach ($bhps as $b) {
                BhpTariff::firstOrCreate(
                    ['bhp_item_id' => $b->id, 'insurer_id' => $ins->id],
                    ['price' => $b->price, 'is_active' => true]
                );
            }
            foreach ($iols as $i) {
                IolTariff::firstOrCreate(
                    ['iol_item_id' => $i->id, 'insurer_id' => $ins->id],
                    ['price' => $i->price, 'is_active' => true]
                );
            }
        }
    }

    /** Paket Bedah Phaco composite — items + tariff per-penjamin. */
    private function seedPackage(Procedure $proc, array $meds, array $bhps, array $iols, array $insurers): SurgeryPackage
    {
        $package = SurgeryPackage::firstOrCreate(
            ['name' => 'Paket Phaco + IOL Monofokal'],
            [
                'code'               => 'PKG-PHACO',
                'category'           => 'Bedah Katarak',
                'description'        => 'Paket bedah katarak Phaco dengan IOL monofokal (demo seed).',
                'estimated_duration' => 45,
                'price'              => 8500000,
                'total_base_price'   => 0,
                'is_active'          => true,
            ]
        );

        // Composite items (idempoten: hanya isi kalau belum ada item).
        if ($package->items()->count() === 0) {
            $items = [
                ['type' => 'PROCEDURE',  'id' => $proc->id,     'qty' => 1, 'price' => $proc->base_price],
                ['type' => 'IOL',        'id' => $iols[0]->id,  'qty' => 1, 'price' => $iols[0]->price],
                ['type' => 'BHP',        'id' => $bhps[0]->id,  'qty' => 1, 'price' => $bhps[0]->price],
                ['type' => 'BHP',        'id' => $bhps[1]->id,  'qty' => 1, 'price' => $bhps[1]->price],
                ['type' => 'MEDICATION', 'id' => $meds[0]->id,  'qty' => 2, 'price' => $meds[0]->price],
            ];
            $base = 0;
            foreach ($items as $it) {
                SurgeryPackageItem::create([
                    'surgery_package_id' => $package->id,
                    'item_type'          => $it['type'],
                    'item_id'            => $it['id'],
                    'quantity'           => $it['qty'],
                    'default_price'      => $it['price'],
                ]);
                $base += $it['price'] * $it['qty'];
            }
            $package->update(['total_base_price' => $base]);
        }

        // Tarif jual per-penjamin (UMUM + BPJS). insurer-only — kolom `classification`
        // sudah di-drop dari surgery_package_tariffs (unique: surgery_package_id, insurer_id).
        foreach ($insurers as $key => $ins) {
            SurgeryPackageTariff::firstOrCreate(
                ['surgery_package_id' => $package->id, 'insurer_id' => $ins?->id],
                ['sell_price' => $key === 'BPJS' ? 7800000 : $package->price, 'is_active' => true]
            );
        }

        return $package;
    }

    /** Satu pasien di antrean BEDAH HARI INI → langsung tampil di BedahView. */
    private function seedBedahPatientToday(SurgeryPackage $package): void
    {
        $surgeon = Employee::whereHas('user.role', fn ($q) => $q->where('name', 'dokter'))->first();

        $patient = Patient::firstOrCreate(
            ['nik' => '1271065208600088'],
            [
                'no_rm'         => now()->format('Ym') . '8088',
                'name'          => 'Siti Aminah (Demo Bedah)',
                'gender'        => 'P',
                'date_of_birth' => '1958-03-20',
                'phone'         => '0812-8000-0088',
                'province'      => 'Sumatera Utara',
                'is_active'     => true,
            ]
        );

        // Jadwal HARI INI (status SCHEDULED → routing/visibilitas BEDAH).
        $schedule = SurgerySchedule::firstOrCreate(
            [
                'surgery_package_id' => $package->id,
                'scheduled_date'     => today()->toDateString(),
                'scheduled_time'     => '09:00:00',
            ],
            [
                'lead_surgeon_id' => $surgeon?->id,
                'operation_room'  => 'OK 1',
                'status'          => 'SCHEDULED',
                'notes'           => 'Demo: pasien bedah hari ini (BedahDemoSeeder).',
            ]
        );

        $visit = Visit::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id, 'patient_id' => $patient->id],
            [
                'visit_date'      => today(),
                'classification'  => 'Pre-Op',
                'visit_type'      => 'PREOP_BEDAH',
                'current_station' => 'BEDAH',
                'guarantor_type'  => 'UMUM',
            ]
        );

        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'           => $surgeon?->id,
                'anamnese'            => 'Katarak matur OD. Rencana Phaco + IOL.',
                'diagnosis_utama'     => 'H25.9',
                'planning'            => 'BEDAH',
                'surgery_package_id'  => $package->id,
                'surgery_schedule_id' => $schedule->id,
                'is_finalized'        => true,
                'finalized_at'        => now(),
            ]
        );

        // Antrean BEDAH hari ini (idempoten via visit+station).
        $this->enqueue($visit, 'BEDAH', 'B');
    }

    // =====================================================================
    // FARMASI — pasien dengan resep aktif di antrean FARMASI hari ini.
    // =====================================================================
    private function seedFarmasiPatientToday(array $meds): void
    {
        $doctor  = Employee::whereHas('user.role', fn ($q) => $q->where('name', 'dokter'))->first();
        $patient = Patient::firstOrCreate(
            ['nik' => '1271065208600077'],
            [
                'no_rm'         => now()->format('Ym') . '7077',
                'name'          => 'Budi Santoso (Demo Farmasi)',
                'gender'        => 'L',
                'date_of_birth' => '1975-07-07',
                'phone'         => '0812-7000-0077',
                'is_active'     => true,
            ]
        );

        $visit = Visit::firstOrCreate(
            ['patient_id' => $patient->id, 'visit_date' => today(), 'current_station' => 'FARMASI'],
            ['classification' => 'Baru', 'visit_type' => 'REGULAR', 'guarantor_type' => 'UMUM']
        );

        // Resep aktif (DRAFT) + item — pakai obat hasil seed.
        if (! Prescription::where('visit_id', $visit->id)->exists()) {
            $presc = Prescription::create([
                'visit_id'         => $visit->id,
                'prescribed_by_id' => $doctor?->id,
                'status'           => 'DRAFT',
                'notes'            => 'Resep demo (BedahDemoSeeder).',
            ]);
            PrescriptionItem::create([
                'prescription_id' => $presc->id,
                'medication_id'   => $meds[0]->id,
                'quantity'        => 1,
                'dosage'          => '1 tetes',
                'instructions'    => '4x sehari, topikal (mata), selama 7 hari',
            ]);
            PrescriptionItem::create([
                'prescription_id' => $presc->id,
                'medication_id'   => $meds[2]->id,
                'quantity'        => 10,
                'dosage'          => '500mg',
                'instructions'    => '3x sehari, oral, selama 3 hari',
            ]);
        }

        $this->enqueue($visit, 'FARMASI', 'F');
    }

    // =====================================================================
    // KASIR — pasien dengan invoice di antrean KASIR hari ini.
    // =====================================================================
    private function seedKasirPatientToday(Procedure $proc, array $meds): void
    {
        $patient = Patient::firstOrCreate(
            ['nik' => '1271065208600066'],
            [
                'no_rm'         => now()->format('Ym') . '6066',
                'name'          => 'Dewi Lestari (Demo Kasir)',
                'gender'        => 'P',
                'date_of_birth' => '1988-06-06',
                'phone'         => '0812-6000-0066',
                'is_active'     => true,
            ]
        );

        $visit = Visit::firstOrCreate(
            ['patient_id' => $patient->id, 'visit_date' => today(), 'current_station' => 'KASIR'],
            ['classification' => 'Baru', 'visit_type' => 'REGULAR', 'guarantor_type' => 'UMUM']
        );

        // Invoice DRAFT + 2 item (tindakan + obat).
        if (! BillingInvoice::where('visit_id', $visit->id)->exists()) {
            $items = [
                ['type' => 'PROCEDURE',  'ref' => $proc->id,    'desc' => $proc->name,  'qty' => 1, 'price' => (float) $proc->base_price],
                ['type' => 'MEDICATION', 'ref' => $meds[1]->id, 'desc' => $meds[1]->name,'qty' => 1, 'price' => (float) $meds[1]->price],
            ];
            $subtotal = array_sum(array_map(fn ($i) => $i['price'] * $i['qty'], $items));

            $invoice = BillingInvoice::create([
                'visit_id'       => $visit->id,
                'invoice_number' => 'INV-DEMO/' . now()->format('Y/m') . '/' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
                'status'         => 'DRAFT',
                'subtotal'       => $subtotal,
                'discount'       => 0,
                'tax'            => 0,
                'total'          => $subtotal,
            ]);
            foreach ($items as $it) {
                BillingItem::create([
                    'billing_invoice_id' => $invoice->id,
                    'item_type'          => $it['type'],
                    'reference_id'       => $it['ref'],
                    'description'        => $it['desc'],
                    'quantity'           => $it['qty'],
                    'unit_price'         => $it['price'],
                    'total_price'        => $it['price'] * $it['qty'],
                    'net_price'          => $it['price'] * $it['qty'],
                ]);
            }
        }

        $this->enqueue($visit, 'KASIR', 'K');
    }

    // =====================================================================
    // TTD DOKUMEN — template ber-tanda-tangan dokter + dokumen PENDING_SIGNATURE.
    // =====================================================================
    private function seedTtdDoctorQueue(): void
    {
        // Template demo dengan field signature_canvas signer_type=doctor
        // (syarat masuk antrean TTD dokter — lihat SignatureService::ttdQueueForDoctor).
        $docTypeId = \App\Models\DocumentType::query()->value('id');
        $schema = [
            'fields' => [
                ['key' => 'nama_pasien', 'type' => 'text', 'label' => 'Nama Pasien',
                 'binding' => ['kind' => 'db', 'source' => 'patient.name'], 'required' => true],
                ['key' => 'isi', 'type' => 'longtext', 'label' => 'Keterangan'],
                ['key' => 'ttd_dokter', 'type' => 'signature_canvas', 'label' => 'Tanda Tangan Dokter',
                 'signer_type' => 'doctor', 'required' => true],
            ],
        ];
        $template = DocumentTemplate::firstOrCreate(
            ['code' => 'DEMO_TTD_DOKTER'],
            [
                'document_type_id' => $docTypeId,
                'name'             => 'Surat Demo TTD Dokter',
                'body_html'        => '<h3>Surat Demo</h3><p>Pasien: {{nama_pasien}}</p><p>{{isi}}</p><div>{{ttd_dokter}}</div>',
                'page_size'        => 'A4',
                'orientation'      => 'portrait',
                'version'          => 1,
                'kind'             => 'OUTPUT',
                'field_schema'     => $schema,
                'is_active'        => true,
            ]
        );

        // Pasien + visit + dokumen PENDING_SIGNATURE (belum diteken dokter).
        $patient = Patient::firstOrCreate(
            ['nik' => '1271065208600055'],
            [
                'no_rm'         => now()->format('Ym') . '5055',
                'name'          => 'Andi Wijaya (Demo TTD)',
                'gender'        => 'L',
                'date_of_birth' => '1970-05-05',
                'phone'         => '0812-5000-0055',
                'is_active'     => true,
            ]
        );
        $visit = Visit::firstOrCreate(
            ['patient_id' => $patient->id, 'visit_date' => today(), 'current_station' => 'DOKTER'],
            ['classification' => 'Baru', 'visit_type' => 'REGULAR', 'guarantor_type' => 'UMUM']
        );

        PatientDocument::firstOrCreate(
            ['visit_id' => $visit->id, 'template_code' => 'DEMO_TTD_DOKTER'],
            [
                'patient_id'         => $patient->id,
                'document_type_id'   => $template->document_type_id,
                'status'             => 'PENDING_SIGNATURE',
                'created_by_station' => 'DOKTER',
                'template_version'   => 1,
                'signatures'         => ['static_payload' => ['isi' => 'Dokumen demo menunggu tanda tangan dokter.']],
            ]
        );
    }

    /** Enqueue visit ke station tertentu hari ini (idempoten via visit+station). */
    private function enqueue(Visit $visit, string $station, string $prefix): void
    {
        if (Queue::where('visit_id', $visit->id)->where('station', $station)->exists()) {
            return;
        }
        $seq = (int) (Queue::where('station', $station)->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
        Queue::create([
            'visit_id'       => $visit->id,
            'station'        => $station,
            'queue_prefix'   => $prefix,
            'queue_sequence' => $seq,
            'queue_number'   => $prefix . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'status'         => 'WAITING',
        ]);
    }
}
