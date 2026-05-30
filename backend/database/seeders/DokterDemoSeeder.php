<?php

namespace Database\Seeders;

use App\Models\DoctorExamination;
use App\Models\DoctorSchedule;
use App\Models\Insurer;
use App\Models\NurseAssessment;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\RefractionRecord;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DokterDemoSeeder — pasien demo untuk stasiun DOKTER (RME), 3 jenis penjamin
 * (UMUM, BPJS, ASURANSI) lengkap dengan RIWAYAT SOAP kunjungan sebelumnya.
 *
 * Untuk SETIAP dokter yang punya jadwal HARI INI:
 *   - 3 pasien WAITING di antrean DOKTER hari ini (1 UMUM, 1 BPJS, 1 ASURANSI),
 *     visit.doctor_schedule_id = jadwal dokter ybs (syarat tampil di getPatientQueue),
 *     + NurseAssessment (vitals) + RefractionPrescription (visus) sudah final.
 *   - Tiap pasien punya 2 KUNJUNGAN LAMA (finalized) dengan SOAP terisi penuh
 *     (S/O/A/P + ICD-10 + ICD-9) → tampil di Rekam Medis RME (riwayat).
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate via NIK + visit_date/station).
 *
 * Jalankan: php artisan db:seed --class=DokterDemoSeeder
 */
class DokterDemoSeeder extends Seeder
{
    /** Definisi 3 pasien per dokter (suffix NIK & RM dibuat unik per-dokter). */
    private array $profiles = [
        [
            'key'       => 'umum',
            'name'      => 'Sumarni Hadi',
            'gender'    => 'P',
            'dob'       => '1969-02-11',
            'guarantor' => 'UMUM',
            'bpjs'      => null,
            'class'     => 'Kontrol',
            'allergy'   => null,
            'chief'     => 'Penglihatan mata kanan buram perlahan sejak 3 bulan.',
        ],
        [
            'key'       => 'bpjs',
            'name'      => 'Joko Prasetyo',
            'gender'    => 'L',
            'dob'       => '1957-10-03',
            'guarantor' => 'BPJS',
            'bpjs'      => '00099887',   // dilengkapi suffix unik per pasien saat create
            'class'     => 'Kontrol',
            'allergy'   => null,
            'chief'     => 'Kontrol pasca operasi katarak mata kiri, mata terasa berair.',
        ],
        [
            'key'       => 'asuransi',
            'name'      => 'Maria Gunawan',
            'gender'    => 'P',
            'dob'       => '1982-12-19',
            'guarantor' => 'ASURANSI',
            'bpjs'      => null,
            'class'     => 'Baru',
            'allergy'   => 'Sulfa',
            'chief'     => 'Mata sering pegal dan kabur saat membaca dekat.',
        ],
    ];

    public function run(): void
    {
        $dow = (int) now()->isoWeekday(); // 1=Senin .. 7=Minggu

        // Dokter yang punya jadwal HARI INI → pasien dilampirkan ke jadwal tsb.
        $schedulesToday = DoctorSchedule::where('day_of_week', $dow)
            ->where('is_active', true)
            ->with('employee')
            ->get()
            ->unique('employee_id')   // satu jadwal mewakili satu dokter
            ->values();

        if ($schedulesToday->isEmpty()) {
            $this->command?->warn("DokterDemoSeeder: tidak ada jadwal dokter untuk hari ini (dow={$dow}). Tidak ada pasien yang di-seed.");
            return;
        }

        $asuransiInsurer = Insurer::where('type', 'ASURANSI')->where('is_active', true)->first();
        if (! $asuransiInsurer) {
            $this->command?->warn('DokterDemoSeeder: belum ada insurer bertipe ASURANSI — pasien asuransi tetap dibuat tanpa insurer_id.');
        }

        DB::transaction(function () use ($schedulesToday, $asuransiInsurer) {
            $docIndex = 0;
            foreach ($schedulesToday as $sched) {
                $docIndex++;
                $employee = $sched->employee;
                if (! $employee) {
                    continue;
                }

                $patIndex = 0;
                foreach ($this->profiles as $prof) {
                    $patIndex++;
                    // NIK & RM unik per (dokter, pasien) supaya tidak bentrok antar dokter.
                    $suffix  = str_pad((string) $docIndex, 2, '0', STR_PAD_LEFT)
                             . str_pad((string) $patIndex, 2, '0', STR_PAD_LEFT);
                    $empHash = str_pad((string) crc32($employee->id), 8, '0', STR_PAD_LEFT);
                    $nik     = substr('3299' . $suffix . $empHash, 0, 16);
                    // BPJS number wajib unik → tempelkan suffix + hash dokter.
                    $bpjs    = $prof['bpjs'] ? substr($prof['bpjs'] . $suffix . $empHash, 0, 13) : null;

                    $patient = Patient::firstOrCreate(
                        ['nik' => $nik],
                        [
                            'no_rm'         => 'DK' . $suffix . substr($empHash, 0, 4),
                            'name'          => $prof['name'] . ' (Demo ' . strtoupper($prof['key']) . ')',
                            'gender'        => $prof['gender'],
                            'date_of_birth' => $prof['dob'],
                            'phone'         => '0812-' . $suffix . '-' . str_pad((string) $patIndex, 4, '0', STR_PAD_LEFT),
                            'province'      => 'Sumatera Utara',
                            'bpjs_number'   => $bpjs,
                            'allergy_notes' => $prof['allergy'],
                            'is_active'     => true,
                        ]
                    );

                    $insurerId = $prof['guarantor'] === 'ASURANSI' ? $asuransiInsurer?->id : null;

                    // 1) Riwayat SOAP: 3 kunjungan lama (finalized).
                    $this->seedPastVisitsWithSoap($patient, $employee, $prof, $insurerId);

                    // 2) Kunjungan HARI INI di antrean DOKTER.
                    $this->seedTodayQueueVisit($patient, $employee, $sched, $prof, $insurerId);
                }
            }
        });

        $this->command?->info(
            'DokterDemoSeeder selesai — ' . $schedulesToday->count() . ' dokter on-duty × 3 pasien (UMUM/BPJS/ASURANSI) + riwayat SOAP.'
        );
    }

    /** Tiga kunjungan lama dengan SOAP lengkap & sudah difinalisasi (untuk riwayat RME). */
    private function seedPastVisitsWithSoap(Patient $patient, $employee, array $prof, ?string $insurerId): void
    {
        $soapByVisit = [
            [
                'days_ago'   => 90,
                'dx'         => 'H25.1',                 // Senile nuclear cataract
                'dx_label'   => 'Katarak senilis nuklear',
                'icd9'       => ['16.21'],               // Slit-lamp examination
                's'          => $prof['chief'],
                'o'          => 'VOD 6/18 ph 6/9, VOS 6/12. TIO normal per palpasi. Lensa keruh nuklear OD. Segmen anterior tenang.',
                'a'          => 'Katarak senilis nuklear OD (H25.1).',
                'p'          => 'Edukasi rencana operasi katarak Phaco + IOL. Tetes air mata buatan. Kontrol 1 bulan.',
                'planning'   => 'PULANG_BEROBAT_JALAN',
            ],
            [
                'days_ago'   => 60,
                'dx'         => 'H25.1',
                'dx_label'   => 'Katarak senilis nuklear',
                'icd9'       => ['16.21', '95.02'],      // + ophthalmoscopy
                's'          => 'Penglihatan mata kanan dirasakan semakin menurun. Tidak ada nyeri, tidak merah.',
                'o'          => 'VOD 6/24 ph 6/12, VOS 6/9. Lensa OD keruh derajat NO3. Fundus sulit dinilai karena media keruh.',
                'a'          => 'Katarak senilis nuklear OD progresif (H25.1).',
                'p'          => 'Direncanakan Phacoemulsifikasi + IOL OD. Pemeriksaan biometri dijadwalkan. Kontrol pra-bedah.',
                'planning'   => 'PULANG_BEROBAT_JALAN',
            ],
            [
                'days_ago'   => 30,
                'dx'         => 'H25.1',
                'dx_label'   => 'Katarak senilis nuklear',
                'icd9'       => ['16.21'],
                's'          => 'Kontrol pra-bedah. Tidak ada keluhan baru, pasien sudah memahami rencana operasi.',
                'o'          => 'VOD 6/24, VOS 6/9. Biometri: AL 23.1 mm, target IOL +21.0 D. Segmen anterior tenang.',
                'a'          => 'Katarak senilis nuklear OD, siap Phaco + IOL (H25.1).',
                'p'          => 'Phacoemulsifikasi + IOL OD dijadwalkan. Antibiotik profilaksis topikal. Informed consent.',
                'planning'   => 'PULANG_BEROBAT_JALAN',
            ],
        ];

        foreach ($soapByVisit as $past) {
            $visitDate = Carbon::today()->subDays($past['days_ago']);

            // Idempoten by (patient, visit_date) — kunjungan lama unik per tanggal.
            $visit = Visit::firstOrNew([
                'patient_id' => $patient->id,
                'visit_date' => $visitDate->toDateString(),
            ]);
            if (! $visit->exists) {
                $visit->fill([
                    'insurer_id'      => $insurerId,
                    'classification'  => 'Kontrol',
                    'visit_type'      => 'REGULAR',
                    'current_station' => 'SELESAI',
                    'guarantor_type'  => $prof['guarantor'],
                    'created_at'      => $visitDate->copy()->setTime(9, 0),
                    'updated_at'      => $visitDate->copy()->setTime(11, 0),
                ]);
                $visit->save();
            }

            DoctorExamination::firstOrCreate(
                ['visit_id' => $visit->id],
                [
                    'doctor_id'           => $employee->id,
                    'anamnese'            => $past['s'],
                    'soap_subjective'     => $past['s'],
                    'soap_objective'      => $past['o'],
                    'soap_assessment'     => $past['a'],
                    'soap_plan'           => $past['p'],
                    'diagnosis_utama'     => $past['dx'],
                    'diagnosis_sekunder'  => [],
                    'tindakan_codes'      => $past['icd9'],
                    'planning'            => $past['planning'],
                    'is_finalized'        => true,
                    'finalized_at'        => $visitDate->copy()->setTime(11, 0),
                    'digital_signature'   => $employee->name . ($employee->sip ? " (SIP: {$employee->sip})" : ''),
                    'signature_timestamp' => $visitDate->copy()->setTime(11, 0),
                    'created_at'          => $visitDate->copy()->setTime(9, 30),
                    'updated_at'          => $visitDate->copy()->setTime(11, 0),
                ]
            );
        }
    }

    /** Kunjungan hari ini: WAITING di antrean DOKTER, vitals + visus sudah final. */
    private function seedTodayQueueVisit(Patient $patient, $employee, DoctorSchedule $sched, array $prof, ?string $insurerId): void
    {
        $visit = Visit::firstOrNew([
            'patient_id'      => $patient->id,
            'visit_date'      => today()->toDateString(),
            'current_station' => 'DOKTER',
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'insurer_id'         => $insurerId,
                'doctor_schedule_id' => $sched->id,   // syarat tampil di getPatientQueue
                'classification'     => $prof['class'],
                'visit_type'         => 'REGULAR',
                'guarantor_type'     => $prof['guarantor'],
                'ready_for_doctor'   => true,
                'triase_completed_at'   => now()->subHours(2),
                'refraksi_completed_at' => now()->subHours(1),
            ]);
            $visit->save();
        }

        // Vitals (Triase Perawat) — supaya Tab 1 RME terisi.
        NurseAssessment::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'assessed_by_id'  => null,
                'td_sistol'       => $prof['guarantor'] === 'BPJS' ? 140 : 120,
                'td_diastol'      => $prof['guarantor'] === 'BPJS' ? 90 : 80,
                'nadi'            => 78,
                'suhu'            => 36.6,
                'respirasi'       => 18,
                'spo2'            => 98,
                'kgd'             => $prof['guarantor'] === 'BPJS' ? 165 : 110,
                'has_allergy'     => ! empty($prof['allergy']),
                'allergy_detail'  => $prof['allergy'],
                'chief_complaint' => $prof['chief'],
                'rps'             => 'Keluhan dirasakan progresif, tidak disertai nyeri hebat maupun mata merah.',
                'pain_scale'      => 1,
                'is_finalized'    => true,
                'finalized_at'    => now()->subHours(2),
            ]
        );

        // Refraksi (visus dasar) — supaya Tab Pemeriksaan Mata punya data.
        if (Schema::hasTable('refraction_records')) {
            RefractionRecord::firstOrCreate(
                ['visit_id' => $visit->id],
                [
                    'examined_by_id'   => null,
                    'examination_date' => today(),
                    'visus_awal_od'    => '6/18',
                    'visus_awal_os'    => '6/9',
                    'pinhole_od'       => '6/9',
                    'pinhole_os'       => '6/6',
                    'visus_akhir_od'   => '6/9',
                    'visus_akhir_os'   => '6/6',
                    'iop_od'           => 15,
                    'iop_os'           => 14,
                    'iop_method'       => 'Non-contact',
                    'clinical_notes'   => 'Visus dasar untuk konsultasi dokter.',
                    'is_finalized'     => true,
                ]
            );
        }

        $this->enqueueDokter($visit);
    }

    /** Enqueue ke antrean DOKTER hari ini (idempoten via visit+station). */
    private function enqueueDokter(Visit $visit): void
    {
        if (Queue::where('visit_id', $visit->id)->where('station', 'DOKTER')->exists()) {
            return;
        }
        $prefix = 'D';
        $seq = (int) (Queue::where('station', 'DOKTER')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
        Queue::create([
            'visit_id'       => $visit->id,
            'station'        => 'DOKTER',
            'queue_prefix'   => $prefix,
            'queue_sequence' => $seq,
            'queue_number'   => $prefix . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'status'         => 'WAITING',
        ]);
    }
}
