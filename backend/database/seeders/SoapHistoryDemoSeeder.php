<?php

namespace Database\Seeders;

use App\Models\DoctorExamination;
use App\Models\DoctorSchedule;
use App\Models\Employee;
use App\Models\NurseAssessment;
use App\Models\NurseCpptEntry;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\RefractionRecord;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SoapHistoryDemoSeeder — satu pasien demo dengan RIWAYAT 3 KUNJUNGAN SEBELUMNYA
 * lengkap untuk menguji kartu SOAP / CPPT / "Riwayat Kunjungan".
 *
 * Tiap kunjungan lama (3 buah, finalized, station SELESAI) berisi:
 *   - NurseAssessment finalized  → feed kartu "Riwayat Kunjungan" (vital history
 *     lintas-kunjungan: PerawatService::getVitalHistory / RmeAggregator).
 *   - DoctorExamination SOAP (S/O/A/P + ICD-10 + ICD-9, finalized) → riwayat SOAP
 *     di Rekam Medis RME (RmeAggregatorService::kunjungan).
 *   - 1-2 NurseCpptEntry → timeline CPPT per kunjungan tsb.
 *
 * + Satu kunjungan AKTIF hari ini di TRIASE (antrean perawat) supaya kartu bisa
 *   dibuka langsung di UI dan menampilkan 3 kunjungan sebelumnya.
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate / firstOrNew per tanggal).
 *
 * Jalankan: php artisan db:seed --class=SoapHistoryDemoSeeder
 */
class SoapHistoryDemoSeeder extends Seeder
{
    /** 3 kunjungan lama — paling lama dulu (90 → 60 → 30 hari lalu). */
    private array $pastVisits = [
        [
            'days_ago'  => 90,
            'class'     => 'Baru',
            'td_s'      => 130, 'td_d' => 85, 'nadi' => 80, 'suhu' => 36.5, 'spo2' => 98, 'kgd' => 118, 'pain' => 1,
            'chief'     => 'Penglihatan mata kanan kabur perlahan sejak ±4 bulan, silau saat malam.',
            'dx'        => 'H25.1', 'icd9' => ['16.21'],
            's'         => 'Penglihatan mata kanan kabur perlahan, silau saat melihat lampu malam.',
            'o'         => 'VOD 6/18 ph 6/9, VOS 6/9. Lensa OD keruh nuklear. Segmen anterior tenang, TIO normal.',
            'a'         => 'Katarak senilis nuklear OD (H25.1).',
            'p'         => 'Edukasi rencana operasi katarak. Air mata buatan 4x1 OD. Kontrol 1 bulan.',
            'cppt'      => [
                ['offset_min' => 20, 'td_s' => 132, 'td_d' => 86, 'nadi' => 82, 'kgd' => 120, 'notes' => 'TTV ulang stabil pra-konsul. Pasien tenang, tidak ada keluhan akut.'],
            ],
        ],
        [
            'days_ago'  => 60,
            'class'     => 'Kontrol',
            'td_s'      => 138, 'td_d' => 88, 'nadi' => 84, 'suhu' => 36.7, 'spo2' => 97, 'kgd' => 142, 'pain' => 2,
            'chief'     => 'Mata kanan makin buram, mengganggu aktivitas membaca.',
            'dx'        => 'H25.1', 'icd9' => ['16.21', '95.02'],
            's'         => 'Penglihatan mata kanan dirasakan makin menurun dibanding kunjungan lalu.',
            'o'         => 'VOD 6/24 ph 6/12, VOS 6/9. Lensa OD keruh NO3. Fundus sulit dinilai (media keruh).',
            'a'         => 'Katarak senilis nuklear OD progresif (H25.1).',
            'p'         => 'Rencana Phacoemulsifikasi + IOL OD. Biometri dijadwalkan. Kontrol pra-bedah.',
            'cppt'      => [
                ['offset_min' => 25, 'td_s' => 140, 'td_d' => 90, 'nadi' => 86, 'kgd' => 145, 'notes' => 'TD borderline tinggi, disarankan kontrol tensi ke poli umum sebelum operasi.'],
                ['offset_min' => 90, 'td_s' => 134, 'td_d' => 84, 'nadi' => 80, 'kgd' => 138, 'notes' => 'Cek ulang TD setelah istirahat → membaik. Layak lanjut persiapan.'],
            ],
        ],
        [
            'days_ago'  => 30,
            'class'     => 'Pre-Op',
            'td_s'      => 128, 'td_d' => 82, 'nadi' => 78, 'suhu' => 36.4, 'spo2' => 99, 'kgd' => 110, 'pain' => 0,
            'chief'     => 'Persiapan operasi katarak mata kanan.',
            'dx'        => 'H25.1', 'icd9' => ['16.21'],
            's'         => 'Pasien datang untuk persiapan operasi. Tidak ada keluhan baru, puasa sesuai instruksi.',
            'o'         => 'VOD 6/24, VOS 6/9. Biometri: AL 23.2 mm, target IOL +21.0 D. Segmen anterior tenang.',
            'a'         => 'Katarak senilis nuklear OD, siap Phaco + IOL (H25.1).',
            'p'         => 'Phacoemulsifikasi + IOL OD terjadwal. Antibiotik profilaksis topikal. Informed consent.',
            'cppt'      => [
                ['offset_min' => 15, 'td_s' => 126, 'td_d' => 80, 'nadi' => 76, 'kgd' => 108, 'notes' => 'TTV pra-operasi dalam batas normal. Gula darah terkontrol. Siap operasi.'],
            ],
        ],
    ];

    public function run(): void
    {
        $doctor = Employee::whereHas('user.role', fn ($q) => $q->where('name', 'dokter'))->first();

        DB::transaction(function () use ($doctor) {
            $patient = Patient::firstOrCreate(
                ['nik' => '1271065208650199'],
                [
                    'no_rm'         => now()->format('Ym') . '0199',
                    'name'          => 'Kartika Sari (Demo Riwayat SOAP)',
                    'gender'        => 'P',
                    'date_of_birth' => '1965-08-12',
                    'phone'         => '0812-0199-0199',
                    'province'      => 'Sumatera Utara',
                    'allergy_notes' => null,
                    'is_active'     => true,
                ]
            );

            foreach ($this->pastVisits as $pv) {
                $this->seedPastVisit($patient, $doctor, $pv);
            }

            // Kunjungan aktif hari ini → tampil di antrean TRIASE (perawat) & DOKTER.
            $this->seedActiveVisit($patient, $doctor);
        });

        $this->command?->info('SoapHistoryDemoSeeder selesai — pasien "Kartika Sari (Demo Riwayat SOAP)" + 3 kunjungan lama (NurseAssessment + SOAP + CPPT) + 1 kunjungan aktif hari ini.');
    }

    /** Satu kunjungan lama lengkap: visit SELESAI + asesmen + SOAP + CPPT. */
    private function seedPastVisit(Patient $patient, ?Employee $doctor, array $pv): void
    {
        $date = Carbon::today()->subDays($pv['days_ago']);

        $visit = Visit::firstOrNew([
            'patient_id' => $patient->id,
            'visit_date' => $date->toDateString(),
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'classification'  => $pv['class'],
                'visit_type'      => 'REGULAR',
                'current_station' => 'SELESAI',
                'guarantor_type'  => 'UMUM',
                'created_at'      => $date->copy()->setTime(8, 30),
                'updated_at'      => $date->copy()->setTime(11, 30),
            ]);
            $visit->save();
        }

        // Asesmen awal (vital history lintas-kunjungan).
        $assessment = NurseAssessment::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'assessed_by_id'  => null,
                'td_sistol'       => $pv['td_s'],
                'td_diastol'      => $pv['td_d'],
                'nadi'            => $pv['nadi'],
                'suhu'            => $pv['suhu'],
                'respirasi'       => 18,
                'spo2'            => $pv['spo2'],
                'kgd'             => $pv['kgd'],
                'has_allergy'     => false,
                'allergy_detail'  => null,
                'chief_complaint' => $pv['chief'],
                'rps'             => 'Keluhan progresif tanpa nyeri hebat / mata merah.',
                'pain_scale'      => $pv['pain'],
                'is_finalized'    => true,
                'finalized_at'    => $date->copy()->setTime(9, 0),
                'created_at'      => $date->copy()->setTime(8, 45),
                'updated_at'      => $date->copy()->setTime(9, 0),
            ]
        );

        // SOAP dokter (riwayat SOAP di RME).
        DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'           => $doctor?->id,
                'anamnese'            => $pv['s'],
                'soap_subjective'     => $pv['s'],
                'soap_objective'      => $pv['o'],
                'soap_assessment'     => $pv['a'],
                'soap_plan'           => $pv['p'],
                'diagnosis_utama'     => $pv['dx'],
                'diagnosis_sekunder'  => [],
                'tindakan_codes'      => $pv['icd9'],
                'planning'            => 'PULANG_BEROBAT_JALAN',
                'is_finalized'        => true,
                'finalized_at'        => $date->copy()->setTime(11, 0),
                'digital_signature'   => $doctor?->name . ($doctor?->sip ? " (SIP: {$doctor->sip})" : ''),
                'signature_timestamp' => $date->copy()->setTime(11, 0),
                'created_at'          => $date->copy()->setTime(10, 0),
                'updated_at'          => $date->copy()->setTime(11, 0),
            ]
        );

        // CPPT timeline kunjungan tsb (append-only, idempoten via notes+created_at).
        foreach ($pv['cppt'] as $c) {
            $createdAt = $date->copy()->setTime(9, 0)->addMinutes($c['offset_min']);
            $exists = NurseCpptEntry::where('visit_id', $visit->id)
                ->where('created_at', $createdAt)
                ->exists();
            if ($exists) {
                continue;
            }
            NurseCpptEntry::create([
                'visit_id'            => $visit->id,
                'nurse_assessment_id' => $assessment->id,
                'td_sistol'           => $c['td_s'],
                'td_diastol'          => $c['td_d'],
                'nadi'                => $c['nadi'],
                'suhu'                => null,
                'respirasi'           => null,
                'spo2'                => null,
                'kgd'                 => $c['kgd'],
                'pain_scale'          => null,
                'notes'               => $c['notes'],
                'created_by_id'       => null,
                'created_at'          => $createdAt,
                'updated_at'          => $createdAt,
            ]);
        }
    }

    /** Kunjungan aktif hari ini: TRIASE (antrean perawat) + RefractionRecord. */
    private function seedActiveVisit(Patient $patient, ?Employee $doctor): void
    {
        $dow   = (int) now()->isoWeekday();
        $sched = DoctorSchedule::where('day_of_week', $dow)
            ->where('is_active', true)
            ->when($doctor, fn ($q) => $q->where('employee_id', $doctor->id))
            ->first()
            ?? DoctorSchedule::where('day_of_week', $dow)->where('is_active', true)->first();

        $visit = Visit::firstOrNew([
            'patient_id'      => $patient->id,
            'visit_date'      => today()->toDateString(),
            'current_station' => 'TRIASE',
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'doctor_schedule_id' => $sched?->id,
                'classification'     => 'Kontrol',
                'visit_type'         => 'REGULAR',
                'guarantor_type'     => 'UMUM',
            ]);
            $visit->save();
        }

        // Visus dasar supaya tab Pemeriksaan terisi saat naik ke dokter.
        if (Schema::hasTable('refraction_records')) {
            RefractionRecord::firstOrCreate(
                ['visit_id' => $visit->id],
                [
                    'examined_by_id'   => null,
                    'examination_date' => today(),
                    'visus_awal_od'    => '6/24',
                    'visus_awal_os'    => '6/9',
                    'pinhole_od'       => '6/12',
                    'pinhole_os'       => '6/6',
                    'visus_akhir_od'   => '6/12',
                    'visus_akhir_os'   => '6/6',
                    'iop_od'           => 16,
                    'iop_os'           => 14,
                    'iop_method'       => 'Non-contact',
                    'clinical_notes'   => 'Kontrol katarak OD — lanjut konsul dokter.',
                    'is_finalized'     => true,
                ]
            );
        }

        $this->enqueue($visit, 'TRIASE', 'T');
    }

    /** Enqueue visit ke station hari ini (idempoten via visit+station). */
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
