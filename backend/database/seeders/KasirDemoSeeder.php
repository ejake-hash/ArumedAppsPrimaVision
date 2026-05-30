<?php

namespace Database\Seeders;

use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Queue;
use App\Models\Visit;
use App\Models\VisitService;
use App\Services\KasirService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * KasirDemoSeeder — pasien demo untuk stasiun KASIR, 1 per penjamin
 * (UMUM, BPJS, ASURANSI).
 *
 * Tiap pasien:
 *   - 1 kunjungan HARI INI dengan current_station = KASIR.
 *   - Beberapa VisitService (tindakan) supaya tagihan punya item nyata.
 *   - 1 baris Queue station KASIR (WAITING) → tampil di antrean Kasir.
 *   - 1 BillingInvoice DRAFT digenerate via KasirService::consolidateBilling
 *     (registrasi + tindakan, tarif resolve per-penjamin via getPrice).
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate via NIK + visit_date/station;
 * invoice & queue di-skip kalau sudah ada).
 *
 * Jalankan: php artisan db:seed --class=KasirDemoSeeder
 */
class KasirDemoSeeder extends Seeder
{
    /** Satu pasien per penjamin. */
    private array $profiles = [
        [
            'key'       => 'umum',
            'name'      => 'Rahmat Wijaya',
            'gender'    => 'L',
            'dob'       => '1975-06-14',
            'guarantor' => 'UMUM',
            'bpjs'      => null,
            'address'   => 'Jl. Sisingamangaraja No. 45, Kel. Sukaraja',
        ],
        [
            'key'       => 'bpjs',
            'name'      => 'Siti Aminah',
            'gender'    => 'P',
            'dob'       => '1962-09-22',
            'guarantor' => 'BPJS',
            'bpjs'      => '0001122334455',
            'address'   => 'Jl. Gatot Subroto No. 12, Kel. Helvetia',
        ],
        [
            'key'       => 'asuransi',
            'name'      => 'Bambang Santoso',
            'gender'    => 'L',
            'dob'       => '1988-03-07',
            'guarantor' => 'ASURANSI',
            'bpjs'      => null,
            'address'   => 'Jl. Dr. Mansyur No. 88, Kel. Padang Bulan',
        ],
    ];

    public function run(): void
    {
        $asuransiInsurer = Insurer::where('type', 'ASURANSI')->where('is_active', true)->first();
        if (! $asuransiInsurer) {
            $this->command?->warn('KasirDemoSeeder: belum ada insurer bertipe ASURANSI — pasien asuransi tetap dibuat tanpa insurer_id.');
        }

        // Sistem insurer (UMUM/BPJS) supaya getPrice tidak rely on fallback.
        $umumInsurer = Insurer::where('is_system', true)->where('type', 'UMUM')->first();
        $bpjsInsurer = Insurer::where('is_system', true)->where('type', 'BPJS')->first();

        // Sampai 2 tindakan untuk dilampirkan ke tiap kunjungan (kalau master ada).
        $procedures = Procedure::query()->where('is_active', true)->orderBy('name')->limit(2)->get();
        if ($procedures->isEmpty()) {
            $this->command?->warn('KasirDemoSeeder: belum ada master Procedure aktif — tagihan hanya berisi biaya registrasi.');
        }

        $created = 0;

        DB::transaction(function () use ($asuransiInsurer, $umumInsurer, $bpjsInsurer, $procedures, &$created) {
            $patIndex = 0;
            foreach ($this->profiles as $prof) {
                $patIndex++;
                $suffix = str_pad((string) $patIndex, 2, '0', STR_PAD_LEFT);
                $nik    = substr('3271' . $suffix . '99001100', 0, 16);
                $bpjs   = $prof['bpjs'] ? substr($prof['bpjs'] . $suffix, 0, 13) : null;

                $patient = Patient::firstOrCreate(
                    ['nik' => $nik],
                    [
                        'no_rm'         => 'KS' . $suffix . '0001',
                        'name'          => $prof['name'] . ' (Demo ' . strtoupper($prof['key']) . ')',
                        'gender'        => $prof['gender'],
                        'date_of_birth' => $prof['dob'],
                        'phone'         => '0813-' . $suffix . '-9900',
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

                // Kunjungan hari ini di stasiun KASIR.
                $visit = Visit::firstOrNew([
                    'patient_id'      => $patient->id,
                    'visit_date'      => today()->toDateString(),
                    'current_station' => 'KASIR',
                ]);
                $isNew = ! $visit->exists;
                if ($isNew) {
                    $visit->fill([
                        'insurer_id'            => $insurerId,
                        'classification'        => 'Kontrol',
                        'visit_type'            => 'REGULAR',
                        'guarantor_type'        => $prof['guarantor'],
                        'ready_for_doctor'      => true,
                        'triase_completed_at'   => now()->subHours(3),
                        'refraksi_completed_at' => now()->subHours(2),
                    ]);
                    $visit->save();
                }

                // Tindakan (VisitService) — supaya invoice punya item selain registrasi.
                foreach ($procedures as $proc) {
                    VisitService::firstOrCreate(
                        ['visit_id' => $visit->id, 'procedure_id' => $proc->id],
                        ['quantity' => 1, 'notes' => 'Demo tindakan kasir']
                    );
                }

                // Antrean KASIR.
                $this->enqueueKasir($visit);

                // Invoice DRAFT via konsolidasi billing (registrasi + tindakan).
                $this->generateInvoice($visit->id);

                $created++;
            }
        });

        $this->command?->info("KasirDemoSeeder selesai — {$created} pasien (UMUM/BPJS/ASURANSI) di antrean Kasir + invoice DRAFT.");
    }

    /** Enqueue ke antrean KASIR hari ini (idempoten via visit+station). */
    private function enqueueKasir(Visit $visit): void
    {
        if (Queue::where('visit_id', $visit->id)->where('station', 'KASIR')->exists()) {
            return;
        }
        $prefix = Queue::prefixFor('KASIR'); // 'K'
        $seq = (int) (Queue::where('station', 'KASIR')->whereDate('created_at', today())->max('queue_sequence') ?? 0) + 1;
        Queue::create([
            'visit_id'       => $visit->id,
            'station'        => 'KASIR',
            'queue_prefix'   => $prefix,
            'queue_sequence' => $seq,
            'queue_number'   => $prefix . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'status'         => 'WAITING',
        ]);
    }

    /** Generate invoice DRAFT lewat konsolidasi billing (skip kalau sudah ada). */
    private function generateInvoice(string $visitId): void
    {
        $exists = \App\Models\BillingInvoice::where('visit_id', $visitId)
            ->whereNotIn('status', ['CANCELLED'])
            ->exists();
        if ($exists) {
            return;
        }
        app(KasirService::class)->consolidateBilling($visitId);
    }
}
