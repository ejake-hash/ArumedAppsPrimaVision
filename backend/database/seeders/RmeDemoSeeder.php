<?php

namespace Database\Seeders;

use App\Models\DiagnosticOrder;
use App\Models\DiagnosticResult;
use App\Models\DiagnosticTestType;
use App\Models\DoctorExamination;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\NurseAssessment;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\RefractionPrescription;
use App\Models\RefractionRecord;
use App\Models\SurgeryIolUsage;
use App\Models\SurgeryPackage;
use App\Models\SurgeryRecord;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RmeDemoSeeder — SATU pasien demo dengan riwayat LENGKAP mengisi SEMUA menu
 * Rekam Medis Elektronik (RekamMedisView): Ringkasan, Kunjungan, Refraksi,
 * Penunjang, Obat, Bedah, Diagnosis, Dokumen.
 *
 * Skenario klinis: Tn. Bambang Sutrisno, katarak senilis nuklear OD progresif
 * yang berakhir dengan Phacoemulsifikasi + IOL, lalu kontrol pasca-bedah.
 * 4 kunjungan lintas waktu (180 / 120 / 60 / 14 hari lalu):
 *   - V1 (180h) : pemeriksaan awal, diagnosis katarak, resep tetes mata.
 *   - V2 (120h) : biometri + OCT (penunjang), persiapan bedah.
 *   - V3  (60h) : OPERASI Phaco + IOL OD (bedah + IOL usage + USG pra-op).
 *   - V4  (14h) : kontrol pasca-bedah, visus membaik, resep obat.
 * Tiap kunjungan finalized dengan SOAP, ICD-10/9, refraksi, vitals, dan
 * sebagian dilengkapi penunjang/obat/dokumen → semua tab RME terisi.
 *
 * Sumber data master (dokter, ICD, penunjang, obat, jenis dokumen) ditarik
 * dari DB; jika master kosong, bagian terkait dilewati dengan aman.
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate via NIK + visit_date).
 *
 * Jalankan: php artisan db:seed --class=RmeDemoSeeder
 */
class RmeDemoSeeder extends Seeder
{
    private const NIK   = '3275019999000001'; // NIK tetap → idempoten
    private const NO_RM = 'RME-DEMO-01';

    public function run(): void
    {
        $doctor = Employee::where('profession', 'like', '%okter%')
            ->where('is_active', true)
            ->first();

        if (! $doctor) {
            $this->command?->warn('RmeDemoSeeder: tidak ada Employee dokter aktif. Seeder dibatalkan.');
            return;
        }

        DB::transaction(function () use ($doctor) {
            $patient = Patient::firstOrCreate(
                ['nik' => self::NIK],
                [
                    'no_rm'         => self::NO_RM,
                    'name'          => 'Bambang Sutrisno (Demo RME)',
                    'gender'        => 'L',
                    'date_of_birth' => '1958-07-21',
                    'phone'         => '0813-9000-0001',
                    'address'       => 'Jl. Mata Sehat No. 21, Medan',
                    'province'      => 'Sumatera Utara',
                    'blood_type'    => 'B',
                    'allergy_notes' => 'Penisilin (ruam kulit)',
                    'is_active'     => true,
                ]
            );

            // V1 — 180 hari lalu: pemeriksaan awal + diagnosis + resep tetes mata.
            $v1 = $this->makeVisit($patient, 180, 'Baru', $doctor, [
                's'  => 'Penglihatan mata kanan buram perlahan sejak ±4 bulan, silau bila terkena cahaya. Tidak nyeri, tidak merah.',
                'o'  => 'VOD 6/24 ph 6/15, VOS 6/9. Lensa OD keruh nuklear (NO2). Segmen anterior tenang. TIO normal palpasi.',
                'a'  => 'Katarak senilis nuklear OD (H25.1).',
                'p'  => 'Edukasi perjalanan penyakit & rencana operasi. Air mata buatan 4×1 tetes OD. Kontrol 2 bulan untuk biometri.',
                'dx' => 'H25.1', 'dx2' => [], 'icd9' => ['95.02'],
                'planning' => 'PULANG_BEROBAT_JALAN',
            ]);
            $this->makeNurse($v1, 180, '6/24', '6/9');
            $this->makeRefraction($v1, 180, '6/15', '6/9', 16, 15, [
                'rx_od_sph' => -1.50, 'rx_od_cyl' => -0.75, 'rx_od_axis' => 90,
                'rx_os_sph' => -0.50, 'rx_os_cyl' => null, 'rx_os_axis' => null,
                'glasses_type' => 'Bifokal', 'lens_material' => 'Polikarbonat', 'coating' => 'Anti-radiasi',
            ]);
            $this->makePrescription($v1, 180, $doctor, [
                ['Cendo Xitrol Tetes Mata', 1, '1 tetes', '4× sehari OD selama 2 minggu'],
                ['Cenfresh Tetes Mata', 2, '1 tetes', '4-6× sehari OD bila kering'],
            ]);

            // V2 — 120 hari lalu: penunjang (biometri + OCT) untuk persiapan bedah.
            $v2 = $this->makeVisit($patient, 120, 'Kontrol', $doctor, [
                's'  => 'Penglihatan OD semakin menurun, mengganggu aktivitas membaca dan menyetir.',
                'o'  => 'VOD 6/30 ph 6/18, VOS 6/9. Lensa OD keruh NO3. Funduskopi OD sulit dinilai (media keruh).',
                'a'  => 'Katarak senilis nuklear OD progresif (H25.1), siap operasi.',
                'p'  => 'Biometri & OCT makula dikerjakan. Rencana Phacoemulsifikasi + IOL OD. Jadwalkan operasi.',
                'dx' => 'H25.1', 'dx2' => [], 'icd9' => ['95.02'],
                'planning' => 'PULANG_BEROBAT_JALAN',
            ]);
            $this->makeNurse($v2, 120, '6/30', '6/9');
            $this->makeRefraction($v2, 120, '6/18', '6/9', 17, 15, null);
            $this->makePenunjang($v2, 120, $doctor, 'BIOM', 'od', [
                'AL (Axial Length)' => '23.42 mm',
                'ACD'               => '3.10 mm',
                'K1 / K2'           => '43.50 / 44.25 D',
                'Target IOL'        => '+21.0 D (SRK/T)',
                'kesimpulan'        => 'Biometri layak untuk implantasi IOL +21.0 D, target emetropia.',
            ]);
            $this->makePenunjang($v2, 120, $doctor, 'OCT', 'od', [
                'CMT (Central Macular Thickness)' => '248 µm',
                'RNFL'                            => 'Dalam batas normal',
                'kesan'                           => 'Makula OD normal, tidak ada edema. Aman untuk operasi katarak.',
            ]);

            // V3 — 60 hari lalu: OPERASI Phaco + IOL OD (bedah + IOL + USG pra-op).
            $v3 = $this->makeVisit($patient, 60, 'Pre-Op', $doctor, [
                's'  => 'Pasien datang untuk operasi katarak OD yang telah dijadwalkan. Puasa & persiapan pra-bedah selesai.',
                'o'  => 'VOD 6/30, VOS 6/9. Segmen anterior OD tenang, pupil dapat dilebarkan adekuat. TIO 16 mmHg.',
                'a'  => 'Katarak senilis nuklear OD (H25.1) — dilakukan Phaco + IOL.',
                'p'  => 'Phacoemulsifikasi + implantasi IOL OD. Antibiotik & steroid topikal pasca-op. Kontrol H+1, H+7.',
                'dx' => 'H25.1', 'dx2' => [], 'icd9' => ['13.41'], // Phacoemulsification and aspiration of cataract
                'planning' => 'PULANG_BEROBAT_JALAN',
            ]);
            $this->makeNurse($v3, 60, '6/30', '6/9');
            $this->makePenunjang($v3, 60, $doctor, 'USG', 'od', [
                'Vitreus'   => 'Jernih, tidak ada perdarahan',
                'Retina'    => 'Melekat, tidak ada ablasi',
                'kesimpulan' => 'USG B-Scan OD: segmen posterior dalam batas normal pra-operasi.',
            ]);
            $this->makeSurgery($v3, 60, $doctor);
            $this->makePrescription($v3, 60, $doctor, [
                ['Cendo Xitrol Tetes Mata', 1, '1 tetes', '6× sehari OD tapering 4 minggu'],
                ['Ketorolac 30mg/ml', 1, '1 tetes', '3× sehari OD selama 2 minggu'],
            ]);

            // V4 — 14 hari lalu: kontrol pasca-bedah, visus membaik.
            $v4 = $this->makeVisit($patient, 14, 'Post-Op', $doctor, [
                's'  => 'Kontrol pasca operasi katarak OD. Penglihatan dirasakan jauh lebih terang, tidak nyeri.',
                'o'  => 'VOD 6/9 ph 6/7,5, VOS 6/9. IOL OD posisi sentral, kornea jernih, COA dalam. TIO 14 mmHg.',
                'a'  => 'Pasca Phaco + IOL OD, hasil baik (Z96.1). Pseudofakia OD.',
                'p'  => 'Lanjutkan tetes mata tapering. Resep kacamata baca menyusul stabil. Kontrol 1 bulan.',
                'dx' => 'H25.1', 'dx2' => [], 'icd9' => ['95.02'],
                'planning' => 'PULANG_BEROBAT_JALAN',
            ]);
            $this->makeNurse($v4, 14, '6/9', '6/9');
            $this->makeRefraction($v4, 14, '6/7.5', '6/9', 14, 15, [
                'rx_od_sph' => 0.00, 'rx_od_cyl' => -0.50, 'rx_od_axis' => 85, 'rx_od_add' => 2.00,
                'rx_os_sph' => -0.50, 'rx_os_cyl' => null, 'rx_os_axis' => null, 'rx_os_add' => 2.00,
                'glasses_type' => 'Progresif', 'lens_material' => 'CR-39', 'coating' => 'Anti-reflektif',
            ]);

            // Dokumen RME (FINAL) — tersebar di beberapa kunjungan.
            $this->makeDocument($patient, $v1, 'RM-1.1', 180); // General Consent
            $this->makeDocument($patient, $v2, 'RM-3.2', 120); // Hasil Penunjang
            $this->makeDocument($patient, $v3, 'RM-2.3', 60);  // Pemeriksaan Dokter Mata
        });

        $this->command?->info('RmeDemoSeeder selesai — pasien "Bambang Sutrisno (Demo RME)" (NIK '.self::NIK.') dengan riwayat lengkap 4 kunjungan: kunjungan/SOAP, refraksi, penunjang, obat, bedah, diagnosis, dokumen.');
    }

    /** Kunjungan finalized + DoctorExamination SOAP. */
    private function makeVisit(Patient $patient, int $daysAgo, string $class, Employee $doctor, array $soap): Visit
    {
        $date = Carbon::today()->subDays($daysAgo);

        $visit = Visit::firstOrNew([
            'patient_id' => $patient->id,
            'visit_date' => $date->toDateString(),
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'classification'  => $class,
                'visit_type'      => 'REGULAR',
                'current_station' => 'SELESAI',
                'guarantor_type'  => 'UMUM',
                'follow_up_date'  => $class === 'Post-Op' ? $date->copy()->addMonth()->toDateString() : null,
                'follow_up_reason' => $class === 'Post-Op' ? 'Kontrol pasca bedah katarak OD' : null,
                'created_at'      => $date->copy()->setTime(9, 0),
                'updated_at'      => $date->copy()->setTime(11, 30),
            ]);
            $visit->save();
        }

        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'           => $doctor->id,
                'anamnese'            => $soap['s'],
                'soap_subjective'     => $soap['s'],
                'soap_objective'      => $soap['o'],
                'soap_assessment'     => $soap['a'],
                'soap_plan'           => $soap['p'],
                'diagnosis_utama'     => $soap['dx'],
                'diagnosis_sekunder'  => $soap['dx2'],
                'tindakan_codes'      => $soap['icd9'],
                'planning'            => $soap['planning'],
                'is_finalized'        => true,
                'finalized_at'        => $date->copy()->setTime(11, 0),
                'digital_signature'   => $doctor->name.($doctor->sip ? " (SIP: {$doctor->sip})" : ''),
                'signature_timestamp' => $date->copy()->setTime(11, 0),
                'created_at'          => $date->copy()->setTime(9, 30),
                'updated_at'          => $date->copy()->setTime(11, 0),
            ]
        );

        return $visit;
    }

    /** Asesmen perawat (vitals) finalized. */
    private function makeNurse(Visit $visit, int $daysAgo, string $visusOd, string $visusOs): void
    {
        $date = Carbon::today()->subDays($daysAgo);
        NurseAssessment::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'assessed_by_id'  => null,
                'td_sistol'       => 130,
                'td_diastol'      => 80,
                'nadi'            => 76,
                'suhu'            => 36.5,
                'respirasi'       => 18,
                'spo2'            => 98,
                'kgd'             => 118,
                'has_allergy'     => true,
                'allergy_detail'  => 'Penisilin (ruam kulit)',
                'chief_complaint' => "Visus dasar OD {$visusOd}, OS {$visusOs}.",
                'rps'             => 'Keluhan progresif tanpa nyeri/mata merah.',
                'pain_scale'      => 0,
                'is_finalized'    => true,
                'finalized_at'    => $date->copy()->setTime(9, 15),
            ]
        );
    }

    /** Rekam refraksi + (opsional) resep kacamata. */
    private function makeRefraction(Visit $visit, int $daysAgo, string $visusOd, string $visusOs, $iopOd, $iopOs, ?array $rx): void
    {
        $date = Carbon::today()->subDays($daysAgo);
        $rec = RefractionRecord::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'examined_by_id'   => null,
                'examination_date' => $date->copy()->setTime(9, 40),
                'visus_awal_od'    => $visusOd,
                'visus_awal_os'    => $visusOs,
                'pinhole_od'       => $visusOd,
                'pinhole_os'       => $visusOs,
                'visus_akhir_od'   => $visusOd,
                'visus_akhir_os'   => $visusOs,
                'autoref_od_sph'   => $rx['rx_od_sph'] ?? null,
                'autoref_od_cyl'   => $rx['rx_od_cyl'] ?? null,
                'autoref_od_axis'  => $rx['rx_od_axis'] ?? null,
                'iop_od'           => $iopOd,
                'iop_os'           => $iopOs,
                'iop_method'       => 'Non-contact tonometry',
                'pd_distance'      => 63,
                'clinical_notes'   => 'Pemeriksaan refraksi rutin pra-konsultasi dokter.',
                'is_finalized'     => true,
                'finalized_at'     => $date->copy()->setTime(9, 50),
            ]
        );

        if ($rx && ! $rec->prescription) {
            RefractionPrescription::create([
                'refraction_record_id' => $rec->id,
                'visit_id'             => $visit->id,
                'rx_od_sph'            => $rx['rx_od_sph'] ?? null,
                'rx_od_cyl'            => $rx['rx_od_cyl'] ?? null,
                'rx_od_axis'           => $rx['rx_od_axis'] ?? null,
                'rx_od_add'            => $rx['rx_od_add'] ?? null,
                'rx_os_sph'            => $rx['rx_os_sph'] ?? null,
                'rx_os_cyl'            => $rx['rx_os_cyl'] ?? null,
                'rx_os_axis'           => $rx['rx_os_axis'] ?? null,
                'rx_os_add'            => $rx['rx_os_add'] ?? null,
                'glasses_type'         => $rx['glasses_type'] ?? null,
                'lens_material'        => $rx['lens_material'] ?? null,
                'coating'              => $rx['coating'] ?? null,
            ]);
        }
    }

    /** Order penunjang + hasil (expertise_data) — di-review (REVIEWED). */
    private function makePenunjang(Visit $visit, int $daysAgo, Employee $doctor, string $code, string $eye, array $expertise): void
    {
        $type = DiagnosticTestType::where('code', $code)->first();
        if (! $type) {
            return; // master penunjang tidak ada → lewati aman
        }
        $date = Carbon::today()->subDays($daysAgo);

        $order = DiagnosticOrder::firstOrCreate(
            ['visit_id' => $visit->id, 'test_type' => $code],
            [
                'ordered_by_id' => $doctor->id,
                'eye_side'      => $eye,
                'notes'         => 'Order penunjang untuk evaluasi katarak/persiapan bedah.',
                'status'        => 'COMPLETED',
            ]
        );

        DiagnosticResult::firstOrCreate(
            ['diagnostic_order_id' => $order->id],
            [
                'performed_by_id' => null,
                'expertise_data'  => $expertise,
                'notes'           => 'Hasil pemeriksaan penunjang telah diverifikasi.',
                'result_status'   => 'REVIEWED',
                'uploaded_at'     => $date->copy()->setTime(10, 0),
                'reviewed_by_id'  => $doctor->id,
                'reviewed_at'     => $date->copy()->setTime(10, 30),
            ]
        );
    }

    /** Resep + item obat (sumber: master Medication by-name). */
    private function makePrescription(Visit $visit, int $daysAgo, Employee $doctor, array $items): void
    {
        $date = Carbon::today()->subDays($daysAgo);

        $presc = Prescription::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'prescribed_by_id' => $doctor->id,
                'status'           => 'DISPENSED',
                'dispensed_at'     => $date->copy()->setTime(12, 0),
                'notes'            => 'Resep diserahkan ke pasien.',
            ]
        );

        // Jika sudah ada item (idempoten), jangan dobel.
        if ($presc->items()->exists()) {
            return;
        }

        foreach ($items as [$name, $qty, $dose, $rule]) {
            $med = Medication::where('name', $name)->first();
            if (! $med) {
                continue; // obat master tidak ada → lewati
            }
            PrescriptionItem::create([
                'prescription_id' => $presc->id,
                'medication_id'   => $med->id,
                'quantity'        => $qty,
                'dosage'          => $dose,
                'instructions'    => $rule,
                'dose'            => $dose,
                'frequency'       => $rule,
                'route'           => 'Topikal (mata)',
                'duration_days'   => 14,
            ]);
        }
    }

    /** Catatan operasi Phaco + IOL OD + pemakaian IOL (butuh SurgerySchedule). */
    private function makeSurgery(Visit $visit, int $daysAgo, Employee $doctor): void
    {
        $date = Carbon::today()->subDays($daysAgo);

        // surgery_schedules.surgery_package_id NOT NULL → butuh paket bedah.
        $package = SurgeryPackage::firstOrCreate(
            ['code' => 'PKG-PHACO-IOL'],
            [
                'name'               => 'Phacoemulsifikasi + IOL (Demo)',
                'category'           => 'Katarak',
                'description'        => 'Paket operasi katarak Phaco dengan implantasi IOL monofokal.',
                'estimated_duration' => 45,
                'price'              => 0,
                'is_active'          => true,
            ]
        );

        // surgery_records.surgery_schedule_id NOT NULL → buat jadwal (status DONE)
        // dan tautkan ke visit (visits.surgery_schedule_id) seperti alur Bedah.
        $schedule = SurgerySchedule::firstOrCreate(
            ['id' => $visit->surgery_schedule_id ?: Str::uuid()->toString()],
            [
                'surgery_package_id' => $package->id,
                'lead_surgeon_id' => $doctor->id,
                'scheduled_date'  => $date->toDateString(),
                'scheduled_time'  => '10:00',
                'operation_room'  => 'OK 1',
                'status'          => 'DONE',
                'notes'           => 'Phacoemulsifikasi + IOL OD (demo RME).',
            ]
        );
        if ($visit->surgery_schedule_id !== $schedule->id) {
            $visit->forceFill(['surgery_schedule_id' => $schedule->id])->save();
        }

        $rec = SurgeryRecord::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'surgery_schedule_id'  => $schedule->id,
                'time_in'              => $date->copy()->setTime(10, 0),
                'time_out'             => $date->copy()->setTime(10, 45),
                'operation_notes'      => 'Phacoemulsifikasi OD: insisi clear cornea 2.2mm, CCC, hidrodiseksi, '
                    .'fakoemulsifikasi nukleus, irigasi-aspirasi korteks, implantasi IOL di kantong kapsul. '
                    .'IOL posisi sentral, stabil. Hidrasi luka, tanpa jahitan.',
                'has_complication'     => false,
                'complication_detail'  => null,
                'post_op_instructions' => 'Pelindung mata 24 jam. Hindari mengucek & terkena air. Tetes mata sesuai resep. '
                    .'Segera kontrol bila nyeri hebat, merah, atau penglihatan menurun.',
                'followup_date'        => $date->copy()->addDay()->toDateString(),
                'finalized_at'         => $date->copy()->setTime(11, 0),
            ]
        );

        if (! $rec->iolUsages()->exists()) {
            // surgery_iol_usage.iol_item_id NOT NULL → siapkan item IOL master.
            $iol = IolItem::firstOrCreate(
                ['serial_number' => 'SN-DEMO-0042-OD'],
                [
                    'brand'         => 'Alcon',
                    'model'         => 'AcrySof IQ SN60WF',
                    'iol_type'      => 'MONOFOCAL',
                    'material'      => 'Acrylic',
                    'power'         => 21.00,
                    'lot_number'    => 'LOT-AC-2026-0042',
                    'stock'         => 0,
                    'is_used'       => true,
                    'is_active'     => true,
                ]
            );

            SurgeryIolUsage::create([
                'surgery_record_id' => $rec->id,
                'iol_item_id'       => $iol->id,
                'eye_side'          => 'od',
                'brand'             => 'Alcon',
                'model'             => 'AcrySof IQ SN60WF',
                'power'             => 21.00,
                'lot_number'        => 'LOT-AC-2026-0042',
                'serial_number'     => 'SN-DEMO-0042-OD',
            ]);
        }
    }

    /** Dokumen RME FINAL merujuk DocumentType yang tampil di RME. */
    private function makeDocument(Patient $patient, Visit $visit, string $typeCode, int $daysAgo): void
    {
        $type = DocumentType::where('code', $typeCode)->first();
        if (! $type) {
            return; // jenis dokumen tidak ada → lewati
        }
        $date = Carbon::today()->subDays($daysAgo);

        PatientDocument::firstOrCreate(
            ['patient_id' => $patient->id, 'visit_id' => $visit->id, 'document_type_id' => $type->id],
            [
                'document_number'    => 'DOC-'.strtoupper($typeCode).'-'.$date->format('ymd'),
                'status'             => 'FINAL',
                'created_by_station' => 'DOKTER',
                'printed_count'      => 1,
                'finalized_at'       => $date->copy()->setTime(11, 15),
                'template_code'      => $typeCode,
                'template_version'   => 1,
            ]
        );
    }
}
