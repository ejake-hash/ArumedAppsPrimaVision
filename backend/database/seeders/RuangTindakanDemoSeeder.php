<?php

namespace Database\Seeders;

use App\Models\DoctorExamination;
use App\Models\Employee;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureTariff;
use App\Models\Queue;
use App\Models\RefractionRecord;
use App\Models\SurgeryPackage;
use App\Models\SurgeryPackageItem;
use App\Models\SurgeryRecord;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * RuangTindakanDemoSeeder — data demo untuk stasiun "Ruang Tindakan" (Laser YAG / PRP).
 *
 * Mengisi PAPAN ANTREAN Ruang Tindakan hari ini (RuangTindakanView /ruang-tindakan/antrian):
 * pasien dengan SurgerySchedule.location_type = RUANG_TINDAKAN (BUKAN operasi bedah),
 * di-enqueue station BEDAH hari ini (sama infrastruktur Bedah, dibedakan location_type).
 *
 * 3 skenario laser oftalmologi:
 *   1) Laser YAG Kapsulotomi — katarak sekunder (PCO, H26.4), UMUM, MENUNGGU.
 *   2) Laser Iridotomi (YAG PI) — glaukoma sudut tertutup (H40.2), BPJS, BERLANGSUNG
 *      (sudah "Mulai Tindakan" → SurgeryRecord time_in, schedule IN_PROGRESS).
 *   3) Laser Retina / PRP — retinopati diabetik (H36.0), UMUM, MENUNGGU.
 *
 * Billing laser mengikuti PAKET yang dipilih dokter di planning (auto-rekam tindakan
 * paket ke visit_services saat "Selesai"). Seeder membuat 3 Procedure laser +
 * ProcedureTariff (UMUM/BPJS) DAN 3 SurgeryPackage laser (1 PROCEDURE per paket,
 * package_type=BEDAH category "Laser") lalu menempelkannya ke jadwal tiap pasien,
 * agar billing end-to-end bisa diuji. Tiap pasien dilengkapi RefractionRecord
 * pra-tindakan (visus + IOP) → panel "preop".
 *
 * IDEMPOTEN: pasien via NIK; procedure via code; tarif via (procedure, insurer);
 * schedule via (location_type, date, time, room); visit via (schedule, patient);
 * queue via (visit, station).
 *
 * Jalankan: php artisan db:seed --class=RuangTindakanDemoSeeder
 */
class RuangTindakanDemoSeeder extends Seeder
{
    public function run(): void
    {
        $surgeon = Employee::query()->value('id');
        $umum    = Insurer::where('type', 'UMUM')->value('id');
        $bpjs    = Insurer::where('type', 'BPJS')->value('id');

        if (! $surgeon) {
            $this->command?->warn('RuangTindakanDemoSeeder: tidak ada Employee (operator). Jalankan EmployeeSeeder dulu. Lewati.');
            return;
        }

        // ── Master Procedure laser (+ tarif UMUM/BPJS) ──────────────────────────
        $procedures = [
            ['code' => 'TND-YAG-CAP', 'name' => 'Laser YAG Kapsulotomi',        'icd9' => '13.64', 'umum' => 850000,  'bpjs' => 0],
            ['code' => 'TND-YAG-PI',  'name' => 'Laser Iridotomi (YAG PI)',     'icd9' => '12.12', 'umum' => 950000,  'bpjs' => 0],
            ['code' => 'TND-PRP',     'name' => 'Laser Retina / Panretinal (PRP)', 'icd9' => '14.34', 'umum' => 1200000, 'bpjs' => 0],
        ];
        $pkgIds = [];
        foreach ($procedures as $pr) {
            $proc = Procedure::firstOrCreate(
                ['code' => $pr['code']],
                [
                    'name'        => $pr['name'],
                    'category'    => 'Tindakan Laser',
                    'base_price'  => $pr['umum'],
                    'icd9_code'   => $pr['icd9'],
                    'description' => 'Demo Ruang Tindakan (laser).',
                    'is_active'   => true,
                ],
            );

            foreach ([[$umum, $pr['umum']], [$bpjs, $pr['bpjs']]] as [$insurerId, $price]) {
                if (! $insurerId) {
                    continue;
                }
                ProcedureTariff::firstOrCreate(
                    ['procedure_id' => $proc->id, 'insurer_id' => $insurerId],
                    ['price' => $price, 'is_active' => true],
                );
            }

            // Paket laser (1 procedure) — sumber tindakan tertagih saat "Selesai".
            $pkg = SurgeryPackage::firstOrCreate(
                ['code' => 'PKG-' . $pr['code']],
                [
                    'name'             => 'Paket ' . $pr['name'],
                    'package_type'     => 'BEDAH',
                    'category'         => 'Laser',
                    'description'      => 'Demo Ruang Tindakan (laser).',
                    'price'            => $pr['umum'],
                    'total_base_price' => $pr['umum'],
                    'is_active'        => true,
                ],
            );
            SurgeryPackageItem::firstOrCreate(
                ['surgery_package_id' => $pkg->id, 'item_type' => 'PROCEDURE', 'item_id' => $proc->id],
                ['quantity' => 1, 'default_price' => $pr['umum']],
            );
            $pkgIds[$pr['code']] = $pkg->id;
        }

        // ── Pasien laser hari ini ───────────────────────────────────────────────
        $pasien = [
            [
                'nik' => '3275099002000001', 'rm' => 'RM-RT-001', 'name' => 'Darmawan Saputra',
                'gender' => 'L', 'dob' => '1962-04-18', 'guarantor' => 'UMUM', 'insurer' => $umum, 'bpjs' => null,
                'time' => '08:30:00', 'room' => 'Ruang Laser 1', 'queue_status' => 'WAITING',
                'diag' => 'H26.4', 'proc' => 'TND-YAG-CAP',
                'anamnese' => 'Katarak sekunder (PCO) OD pasca Phaco. Visus turun perlahan. Rencana Laser YAG kapsulotomi.',
                'refr' => ['visus_awal_od' => '6/24', 'visus_akhir_od' => '6/12', 'iop_od' => 15, 'iop_os' => 14],
            ],
            [
                'nik' => '3275099002000002', 'rm' => 'RM-RT-002', 'name' => 'Sumarni Dewi',
                'gender' => 'P', 'dob' => '1957-10-02', 'guarantor' => 'BPJS', 'insurer' => $bpjs, 'bpjs' => '0009876543210',
                'time' => '09:15:00', 'room' => 'Ruang Laser 1', 'queue_status' => 'IN_PROGRESS',
                'diag' => 'H40.2', 'proc' => 'TND-YAG-PI',
                'anamnese' => 'Glaukoma sudut tertutup primer OS, bilik mata depan dangkal. Rencana Laser iridotomi profilaksis.',
                'refr' => ['visus_awal_os' => '6/9', 'visus_akhir_os' => '6/9', 'iop_od' => 18, 'iop_os' => 26],
            ],
            [
                'nik' => '3275099002000003', 'rm' => 'RM-RT-003', 'name' => 'Bambang Hartono',
                'gender' => 'L', 'dob' => '1969-06-25', 'guarantor' => 'UMUM', 'insurer' => $umum, 'bpjs' => null,
                'time' => '10:30:00', 'room' => 'Ruang Laser 2', 'queue_status' => 'WAITING',
                'diag' => 'H36.0', 'proc' => 'TND-PRP',
                'anamnese' => 'Retinopati diabetik proliferatif OD. Neovaskularisasi (+). Rencana Laser PRP sesi 1.',
                'refr' => ['visus_awal_od' => '6/18', 'visus_akhir_od' => '6/12', 'iop_od' => 16, 'iop_os' => 15],
            ],
        ];

        DB::transaction(function () use ($pasien, $surgeon, $pkgIds) {
            foreach ($pasien as $p) {
                $visit  = $this->makeLaserVisit($p, $surgeon, $pkgIds[$p['proc']]);
                $queue  = $this->enqueue($visit, $p['queue_status']);

                // Skenario "BERLANGSUNG": sudah Mulai Tindakan → SurgeryRecord time_in
                // + schedule IN_PROGRESS (agar form "Selesai Tindakan" siap dibuka).
                // Selain itu: pastikan SCHEDULED & bersih dari record liar (demo deterministik).
                if ($p['queue_status'] === 'IN_PROGRESS') {
                    $this->startRecord($visit);
                } else {
                    $this->resetSchedule($visit);
                }
            }
        });

        $this->command?->info('RuangTindakanDemoSeeder: 3 procedure + 3 paket laser, ' . count($pasien) . ' pasien antrean Ruang Tindakan hari ini (1 berlangsung).');
    }

    /** Patient + SurgerySchedule(RUANG_TINDAKAN, dgn paket laser) + Visit + DoctorExamination + Refraksi. */
    private function makeLaserVisit(array $p, ?string $surgeon, string $packageId): Visit
    {
        $patient = Patient::firstOrCreate(
            ['nik' => $p['nik']],
            [
                'no_rm'         => $p['rm'],
                'identity_type' => 'KTP',
                'name'          => $p['name'],
                'gender'        => $p['gender'],
                'date_of_birth' => $p['dob'],
                'bpjs_number'   => $p['bpjs'],
                'address'       => 'Medan',
                'is_active'     => true,
            ],
        );

        // Laser dgn paket (billing auto-rekam tindakan paket). Idempoten via lokasi+tanggal+jam+ruang.
        $schedule = SurgerySchedule::firstOrCreate(
            [
                'location_type'  => SurgerySchedule::LOCATION_RUANG_TINDAKAN,
                'scheduled_date' => today()->toDateString(),
                'scheduled_time' => $p['time'],
                'operation_room' => $p['room'],
            ],
            [
                'surgery_package_id' => $packageId,
                'lead_surgeon_id'    => $surgeon,
                'status'             => 'SCHEDULED',
                'requires_inpatient' => false,
                'notes'              => 'Demo RuangTindakanDemoSeeder (laser).',
            ],
        );
        // Perbaiki baris demo lama yang dulu dibuat tanpa paket (idempoten).
        if ($schedule->surgery_package_id !== $packageId) {
            $schedule->update(['surgery_package_id' => $packageId]);
        }

        $visit = Visit::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id, 'patient_id' => $patient->id],
            [
                'insurer_id'      => $p['insurer'],
                'visit_date'      => today()->toDateString(),
                'classification'  => 'Tindakan',
                'visit_type'      => 'PREOP_BEDAH',
                'current_station' => 'BEDAH',
                'guarantor_type'  => $p['guarantor'],
                'jenis_pelayanan' => 'RAJAL',
            ],
        );

        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'           => $surgeon,
                'anamnese'            => $p['anamnese'],
                'diagnosis_utama'     => $p['diag'],
                'planning'            => 'BEDAH',
                'surgery_package_id'  => $packageId,
                'surgery_schedule_id' => $schedule->id,
                'is_finalized'        => true,
                'finalized_at'        => now(),
            ],
        );

        // Pra-tindakan: visus + IOP (panel "preop" di RuangTindakanView).
        RefractionRecord::firstOrCreate(
            ['visit_id' => $visit->id],
            array_merge($p['refr'], [
                'iop_method'   => 'NCT',
                'is_finalized' => true,
                'finalized_at' => now(),
            ]),
        );

        return $visit;
    }

    /** Enqueue ke antrean station BEDAH hari ini (papan Ruang Tindakan memfilter location_type). */
    private function enqueue(Visit $visit, string $status): Queue
    {
        $existing = Queue::where('visit_id', $visit->id)->where('station', 'BEDAH')->first();
        if ($existing) {
            if ($existing->status !== $status) {
                $existing->update($this->statusTimestamps($status));
            }
            return $existing;
        }

        $seq = (int) (Queue::where('station', 'BEDAH')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;

        return Queue::create(array_merge([
            'visit_id'       => $visit->id,
            'station'        => 'BEDAH',
            'queue_prefix'   => 'B',
            'queue_sequence' => $seq,
            'queue_number'   => 'B-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
        ], $this->statusTimestamps($status)));
    }

    /** Tandai jadwal "sedang berlangsung": SurgeryRecord (time_in) + schedule IN_PROGRESS. */
    private function startRecord(Visit $visit): void
    {
        $schedule = $visit->surgerySchedule;
        if (! $schedule) {
            return;
        }

        SurgeryRecord::firstOrCreate(
            ['surgery_schedule_id' => $schedule->id],
            [
                'visit_id' => $visit->id,
                'time_in'  => now()->subMinutes(10),
            ],
        );
        $schedule->update(['status' => 'IN_PROGRESS']);
    }

    /** Pasien "Menunggu": pastikan jadwal SCHEDULED & buang record yg belum selesai (demo bersih). */
    private function resetSchedule(Visit $visit): void
    {
        $schedule = $visit->surgerySchedule;
        if (! $schedule) {
            return;
        }

        SurgeryRecord::where('surgery_schedule_id', $schedule->id)
            ->whereNull('time_out')
            ->delete();

        if ($schedule->status !== 'SCHEDULED') {
            $schedule->update(['status' => 'SCHEDULED']);
        }
    }

    private function statusTimestamps(string $status): array
    {
        return [
            'status'       => $status,
            'called_at'    => in_array($status, ['CALLED', 'IN_PROGRESS', 'COMPLETED'], true) ? now()->subMinutes(20) : null,
            'started_at'   => in_array($status, ['IN_PROGRESS', 'COMPLETED'], true) ? now()->subMinutes(10) : null,
            'completed_at' => $status === 'COMPLETED' ? now()->subMinutes(2) : null,
        ];
    }
}
