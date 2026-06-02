<?php

namespace Database\Seeders;

use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\RefractionPrescription;
use App\Models\RefractionRecord;
use App\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * RefraksionisDemoSeeder — pasien demo untuk stasiun REFRAKSIONIS (RefraksionisView).
 *
 * Membuat beberapa pasien yang HARI INI duduk di antrean REFRAKSIONIS sehingga
 * SELURUH tampilan/filter/skenario RefraksionisView ada datanya:
 *
 *   - Filter status antrean : WAITING (Menunggu) + CALLED (Dipanggil) +
 *     IN_PROGRESS (Dilayani) + COMPLETED (Selesai).
 *   - Filter penjamin       : BPJS + Umum & Asuransi (chip "Umum & Asuransi"
 *     menggabung guarantor_type UMUM + ASURANSI).
 *   - State RefractionRecord :
 *       (a) BELUM ADA          → pasien baru, form kosong (uji create/store).
 *       (b) DRAFT sebagian     → autoref + visus terisi, belum semua tab.
 *       (c) DRAFT lengkap      → semua tab terisi (autoref, keratometri, visus,
 *                                refraksi subjektif, kacamata lama, IOP, ADD,
 *                                PD, catatan) + resep kacamata draft.
 *       (d) FINALIZED (dikunci)→ record is_finalized=true + resep kacamata,
 *                                queue COMPLETED (uji read-only + cetak).
 *
 * Catatan teknis (disesuaikan dengan RefraksiService::getPatientQueue):
 *   - getPatientQueue() memfilter Queue station=REFRAKSIONIS + whereDate(created_at, today)
 *     + whereHas('visit'), eager-load 'visit.patient' & 'visit.refractionRecord'.
 *     Maka queue dibuat HARI INI dan visit valid.
 *   - REFRAKSIONIS berbagi prefix "TR" dengan TRIASE (SHARED_PREFIX_GROUPS),
 *     jadi queue_sequence dihitung lintas kedua station agar nomor tak collision.
 *   - Field decimal disimpan sebagai string mengikuti payload frontend
 *     (input text → decimal:2 cast di model).
 *
 * IDEMPOTEN: pasien via NIK; visit via (patient, visit_date, station=REFRAKSIONIS);
 * refraction_record via visit_id; prescription via refraction_record_id;
 * queue via (visit, station) dengan sinkron status pada run berulang.
 *
 * Jalankan: php artisan db:seed --class=RefraksionisDemoSeeder
 */
class RefraksionisDemoSeeder extends Seeder
{
    public function run(): void
    {
        $umum     = Insurer::where('type', 'UMUM')->value('id');
        $bpjs     = Insurer::where('type', 'BPJS')->value('id');
        $asuransi = Insurer::where('type', 'ASURANSI')->where('is_active', true)->value('id');

        $profiles = [
            // (a) Pasien BARU, BPJS — belum ada refraction_record (form kosong).
            [
                'nik' => '3275077701000001', 'rm' => 'RM-REF-001', 'name' => 'Hendra Wijaya',
                'gender' => 'L', 'dob' => '1990-03-18', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '0002233445566', 'class' => 'Baru', 'queue_status' => 'WAITING',
                'record' => null,
            ],

            // (b) Umum — DRAFT sebagian (autoref + visus saja), queue CALLED.
            [
                'nik' => '3275077701000002', 'rm' => 'RM-REF-002', 'name' => 'Lestari Ningsih',
                'gender' => 'P', 'dob' => '1978-11-02', 'guarantor' => 'UMUM', 'insurer' => $umum,
                'bpjs' => null, 'class' => 'Kontrol', 'queue_status' => 'CALLED',
                'record' => [
                    'finalized'       => false,
                    'perception_type' => 'JAUH',
                    'data' => [
                        'autoref_od_sph' => '-1.25', 'autoref_od_cyl' => '-0.50', 'autoref_od_axis' => 90,
                        'autoref_os_sph' => '-1.00', 'autoref_os_cyl' => '-0.75', 'autoref_os_axis' => 85,
                        'visus_awal_od'  => '6/12', 'visus_awal_os' => '6/9',
                        'pinhole_od'     => '6/7.5', 'pinhole_os' => '6/6',
                    ],
                    'prescription' => null,
                ],
            ],

            // (c) Asuransi — DRAFT LENGKAP (semua tab) + resep kacamata draft, IN_PROGRESS.
            [
                'nik' => '3275077701000003', 'rm' => 'RM-REF-003', 'name' => 'Maya Anggraini',
                'gender' => 'P', 'dob' => '1985-07-25', 'guarantor' => 'ASURANSI', 'insurer' => $asuransi,
                'bpjs' => null, 'class' => 'Baru', 'queue_status' => 'IN_PROGRESS',
                'record' => [
                    'finalized'       => false,
                    'perception_type' => 'JAUH',
                    'data' => [
                        // Autoref
                        'autoref_od_sph' => '-2.50', 'autoref_od_cyl' => '-1.00', 'autoref_od_axis' => 180,
                        'autoref_os_sph' => '-2.25', 'autoref_os_cyl' => '-0.75', 'autoref_os_axis' => 175,
                        // Keratometri
                        'keratometri1_od' => '43.50', 'keratometri2_od' => '44.25', 'keratometri_axis_od' => 90,
                        'keratometri1_os' => '43.25', 'keratometri2_os' => '44.00', 'keratometri_axis_os' => 88,
                        // Visus
                        'visus_awal_od' => '6/18', 'visus_akhir_od' => '6/6', 'pinhole_od' => '6/7.5', 'add_power_od' => '1.00',
                        'visus_awal_os' => '6/15', 'visus_akhir_os' => '6/6', 'pinhole_os' => '6/6',  'add_power_os' => '1.00',
                        // Refraksi subjektif
                        'refraksi_subjektif_od_sph' => '-2.25', 'refraksi_subjektif_od_cyl' => '-0.75', 'refraksi_subjektif_od_axis' => 180,
                        'refraksi_subjektif_os_sph' => '-2.00', 'refraksi_subjektif_os_cyl' => '-0.50', 'refraksi_subjektif_os_axis' => 175,
                        // Kacamata lama
                        'old_glasses_od_sph' => '-2.00', 'old_glasses_od_cyl' => '-0.50', 'old_glasses_od_axis' => 175, 'old_glasses_add_od' => '0.75',
                        'old_glasses_os_sph' => '-1.75', 'old_glasses_os_cyl' => '-0.50', 'old_glasses_os_axis' => 170, 'old_glasses_add_os' => '0.75',
                        // IOP
                        'iop_od' => '15.00', 'iop_os' => '16.00', 'iop_method' => 'NCT',
                        // Shared
                        'pd_distance'    => '63.00',
                        'clinical_notes' => 'Miopia astigmat ringan ODS. Pasien minta lensa progresif.',
                    ],
                    'prescription' => [
                        'rx_od_sph' => '-2.25', 'rx_od_cyl' => '-0.75', 'rx_od_axis' => 180, 'rx_od_add' => '1.00',
                        'rx_os_sph' => '-2.00', 'rx_os_cyl' => '-0.50', 'rx_os_axis' => 175, 'rx_os_add' => '1.00',
                        'glasses_type'  => 'Progresif',
                        'lens_material' => 'High Index 1.67',
                        'coating'       => 'Blue Light + Anti-Reflektif',
                        'notes'         => 'Lensa progresif untuk presbiopia awal.',
                    ],
                ],
            ],

            // (d) BPJS — FINALIZED (dikunci) + resep kacamata, queue COMPLETED.
            [
                'nik' => '3275077701000004', 'rm' => 'RM-REF-004', 'name' => 'Suparman',
                'gender' => 'L', 'dob' => '1960-05-09', 'guarantor' => 'BPJS', 'insurer' => $bpjs,
                'bpjs' => '0007788990011', 'class' => 'Kontrol', 'queue_status' => 'COMPLETED',
                'record' => [
                    'finalized'       => true,
                    'perception_type' => 'DEKAT',
                    'data' => [
                        'autoref_od_sph' => '+1.50', 'autoref_od_cyl' => '-0.50', 'autoref_od_axis' => 90,
                        'autoref_os_sph' => '+1.75', 'autoref_os_cyl' => '-0.50', 'autoref_os_axis' => 85,
                        'keratometri1_od' => '44.00', 'keratometri2_od' => '44.50', 'keratometri_axis_od' => 92,
                        'keratometri1_os' => '44.25', 'keratometri2_os' => '44.75', 'keratometri_axis_os' => 90,
                        'visus_awal_od' => '6/9',  'visus_akhir_od' => '6/6', 'pinhole_od' => '6/6', 'add_power_od' => '2.50',
                        'visus_awal_os' => '6/12', 'visus_akhir_os' => '6/6', 'pinhole_os' => '6/6', 'add_power_os' => '2.50',
                        'refraksi_subjektif_od_sph' => '+1.50', 'refraksi_subjektif_od_cyl' => '-0.50', 'refraksi_subjektif_od_axis' => 90,
                        'refraksi_subjektif_os_sph' => '+1.75', 'refraksi_subjektif_os_cyl' => '-0.50', 'refraksi_subjektif_os_axis' => 85,
                        'old_glasses_od_sph' => '+1.25', 'old_glasses_od_cyl' => '-0.50', 'old_glasses_od_axis' => 90, 'old_glasses_add_od' => '2.25',
                        'old_glasses_os_sph' => '+1.50', 'old_glasses_os_cyl' => '-0.50', 'old_glasses_os_axis' => 85, 'old_glasses_add_os' => '2.25',
                        'iop_od' => '17.00', 'iop_os' => '18.00', 'iop_method' => 'Goldmann',
                        'pd_distance'    => '64.00',
                        'clinical_notes' => 'Presbiopia + hipermetropia ringan ODS. Resep kacamata baca.',
                    ],
                    'prescription' => [
                        'rx_od_sph' => '+1.50', 'rx_od_cyl' => '-0.50', 'rx_od_axis' => 90, 'rx_od_add' => '2.50',
                        'rx_os_sph' => '+1.75', 'rx_os_cyl' => '-0.50', 'rx_os_axis' => 85, 'rx_os_add' => '2.50',
                        'glasses_type'  => 'Bifokal',
                        'lens_material' => 'CR-39',
                        'coating'       => 'Anti-Reflektif',
                        'notes'         => 'Kacamata baca / bifokal untuk aktivitas dekat.',
                    ],
                ],
            ],
        ];

        DB::transaction(function () use ($profiles) {
            foreach ($profiles as $prof) {
                $patient = Patient::firstOrCreate(
                    ['nik' => $prof['nik']],
                    [
                        'no_rm'         => $prof['rm'],
                        'identity_type' => 'KTP',
                        'name'          => $prof['name'],
                        'gender'        => $prof['gender'],
                        'date_of_birth' => $prof['dob'],
                        'bpjs_number'   => $prof['bpjs'],
                        'address'       => 'Medan',
                        'is_active'     => true,
                    ],
                );

                $visit = Visit::firstOrNew([
                    'patient_id'      => $patient->id,
                    'visit_date'      => today()->toDateString(),
                    'current_station' => 'REFRAKSIONIS',
                ]);
                if (! $visit->exists) {
                    $visit->fill([
                        'insurer_id'     => $prof['insurer'],
                        'classification' => $prof['class'],
                        'guarantor_type' => $prof['guarantor'],
                        'visit_type'     => 'REGULAR',
                    ]);
                    $visit->save();
                }

                if ($prof['record']) {
                    $this->seedRecord($visit, $prof['record']);
                }

                $this->enqueueRefraksi($visit, $prof['queue_status']);
            }
        });

        $this->command?->info('RefraksionisDemoSeeder: ' . count($profiles) . ' pasien di antrean REFRAKSIONIS hari ini (baru/draft/lengkap/finalized).');
    }

    /** Buat/refresh refraction_record + (opsional) resep kacamata sesuai skenario. */
    private function seedRecord(Visit $visit, array $r): void
    {
        $attrs = array_merge($r['data'], [
            'examination_date' => today(),
            'perception_type'  => $r['perception_type'],
            'is_finalized'     => $r['finalized'],
            'finalized_at'     => $r['finalized'] ? now()->subMinutes(10) : null,
        ]);

        $record = RefractionRecord::firstOrNew(['visit_id' => $visit->id]);
        if (! $record->exists) {
            $record->fill($attrs)->save();
        }

        if ($r['finalized']) {
            // Tandai visit refraksi selesai (mirror finalizeRefraction), tanpa
            // memicu enqueue DOKTER otomatis — seeder ini fokus stasiun refraksi.
            $visit->update(['refraksi_completed_at' => $visit->refraksi_completed_at ?? now()->subMinutes(10)]);
        }

        if ($r['prescription']) {
            $presc = RefractionPrescription::firstOrNew(['refraction_record_id' => $record->id]);
            if (! $presc->exists) {
                $presc->fill(array_merge($r['prescription'], ['visit_id' => $visit->id]))->save();
            }
        }
    }

    /**
     * Enqueue ke antrean REFRAKSIONIS hari ini (idempoten via visit+station).
     * Sequence dihitung lintas grup prefix "TR" (TRIASE+REFRAKSIONIS) agar nomor
     * konsisten dengan QueueService::generateQueueNumber.
     */
    private function enqueueRefraksi(Visit $visit, string $status): void
    {
        $existing = Queue::where('visit_id', $visit->id)->where('station', 'REFRAKSIONIS')->first();
        if ($existing) {
            if ($existing->status !== $status) {
                $existing->update($this->statusTimestamps($status));
            }
            return;
        }

        $sharedStations = Queue::SHARED_PREFIX_GROUPS['TR'] ?? ['REFRAKSIONIS'];
        $seq = (int) (Queue::whereIn('station', $sharedStations)->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;

        Queue::create(array_merge([
            'visit_id'       => $visit->id,
            'station'        => 'REFRAKSIONIS',
            'queue_prefix'   => 'TR',
            'queue_sequence' => $seq,
            'queue_number'   => 'TR-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
        ], $this->statusTimestamps($status)));
    }

    /** Timestamp called/started/completed sesuai status agar tampilan konsisten. */
    private function statusTimestamps(string $status): array
    {
        return [
            'status'       => $status,
            'called_at'    => in_array($status, ['CALLED', 'IN_PROGRESS', 'COMPLETED'], true) ? now()->subMinutes(30) : null,
            'started_at'   => in_array($status, ['IN_PROGRESS', 'COMPLETED'], true) ? now()->subMinutes(25) : null,
            'completed_at' => $status === 'COMPLETED' ? now()->subMinutes(5) : null,
        ];
    }
}
