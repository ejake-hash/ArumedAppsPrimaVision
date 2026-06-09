<?php

namespace Database\Seeders;

use App\Models\BillingInvoice;
use App\Models\Employee;
use App\Models\InpatientCharge;
use App\Models\InsuranceVerification;
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
 * KasirDemoSeeder — pasien demo untuk stasiun KASIR, 1 per SKENARIO penjamin.
 * Tiap profil sengaja menargetkan SATU alur kasir yang berbeda supaya semua
 * cabang UI (KasirView.vue) bisa diuji manual tanpa setup tambahan:
 *
 *   1. UMUM            → bayar tunai biasa (metode pembayaran + kembalian).
 *   2. BPJS            → panel "Ditanggung BPJS" → tombol Konfirmasi (paid 0).
 *   3. ASURANSI penuh  → verifikasi VERIFIED + covered_amount = total invoice →
 *                        panel "Ditanggung Penuh Asuransi" → tombol Konfirmasi.
 *   4. ASURANSI copay  → verifikasi VERIFIED dgn copay% + plafon → eligibility
 *                        panel tampil estimasi pasien bayar, kasir bayar manual.
 *
 * Tiap pasien:
 *   - 1 kunjungan HARI INI dengan current_station = KASIR (jenis RAJAL).
 *   - 2 VisitService (tindakan master) + 1 item manual ber-harga → tagihan nyata.
 *   - 1 baris Queue station KASIR (WAITING) → tampil di antrean Kasir.
 *   - 1 BillingInvoice DRAFT via KasirService::consolidateBilling
 *     (registrasi + tindakan, tarif resolve per-penjamin via getPrice).
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate via NIK + visit_date/station;
 * invoice, queue, verifikasi, item manual di-skip kalau sudah ada).
 *
 * Jalankan: php artisan db:seed --class=KasirDemoSeeder
 */
class KasirDemoSeeder extends Seeder
{
    /** Satu pasien per skenario penjamin. */
    private array $profiles = [
        [
            'key'       => 'umum',
            'name'      => 'Rahmat Wijaya',
            'gender'    => 'L',
            'dob'       => '1975-06-14',
            'guarantor' => 'UMUM',
            'bpjs'      => null,
            'address'   => 'Jl. Sisingamangaraja No. 45, Kel. Sukaraja',
            'scenario'  => 'cash',
        ],
        [
            'key'       => 'bpjs',
            'name'      => 'Siti Aminah',
            'gender'    => 'P',
            'dob'       => '1962-09-22',
            'guarantor' => 'BPJS',
            'bpjs'      => '0001122334455',
            'address'   => 'Jl. Gatot Subroto No. 12, Kel. Helvetia',
            'scenario'  => 'bpjs',
        ],
        [
            'key'       => 'asuransi-full',
            'name'      => 'Bambang Santoso',
            'gender'    => 'L',
            'dob'       => '1988-03-07',
            'guarantor' => 'ASURANSI',
            'bpjs'      => null,
            'address'   => 'Jl. Dr. Mansyur No. 88, Kel. Padang Bulan',
            'scenario'  => 'asuransi_full',   // ditanggung penuh
        ],
        [
            'key'       => 'asuransi-copay',
            'name'      => 'Dewi Lestari',
            'gender'    => 'P',
            'dob'       => '1991-11-19',
            'guarantor' => 'ASURANSI',
            'bpjs'      => null,
            'address'   => 'Jl. Iskandar Muda No. 21, Kel. Babura',
            'scenario'  => 'asuransi_copay',  // copay 20% + plafon → pasien bayar sisa
        ],
    ];

    public function run(): void
    {
        $asuransiInsurer = $this->ensureAsuransiInsurer();

        // Sistem insurer (UMUM/BPJS) supaya getPrice tidak rely on fallback.
        $umumInsurer = Insurer::where('is_system', true)->where('type', 'UMUM')->first();
        $bpjsInsurer = Insurer::where('is_system', true)->where('type', 'BPJS')->first();

        // Dokter DPJP (tampil di kwitansi) + kasir (cashier_id utk kwitansi PAID).
        $dpjpDoctor = Employee::where('name', 'ilike', '%dr.%')->orderBy('name')->first()
            ?? Employee::orderBy('name')->first();
        $kasir = Employee::orderBy('name')->first();

        // Sampai 2 tindakan untuk dilampirkan ke tiap kunjungan (kalau master ada).
        $procedures = Procedure::query()->where('is_active', true)->orderBy('name')->limit(2)->get();
        if ($procedures->isEmpty()) {
            $this->command?->warn('KasirDemoSeeder: belum ada master Procedure aktif — tagihan hanya berisi biaya registrasi + item manual.');
        }

        $created = 0;

        DB::transaction(function () use ($asuransiInsurer, $umumInsurer, $bpjsInsurer, $procedures, $dpjpDoctor, &$created) {
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

                // Kunjungan hari ini di stasiun KASIR (RAJAL).
                $visit = Visit::firstOrNew([
                    'patient_id'      => $patient->id,
                    'visit_date'      => today()->toDateString(),
                    'current_station' => 'KASIR',
                ]);
                $isNew = ! $visit->exists;
                if ($isNew) {
                    $visit->fill([
                        'insurer_id'            => $insurerId,
                        'jenis_pelayanan'       => 'RAJAL',
                        'classification'        => 'Kontrol',
                        'visit_type'            => 'REGULAR',
                        'guarantor_type'        => $prof['guarantor'],
                        'ready_for_doctor'      => true,
                        'triase_completed_at'   => now()->subHours(3),
                        'refraksi_completed_at' => now()->subHours(2),
                    ]);
                    $visit->save();
                }

                // DPJP utk kwitansi (idempoten — juga backfill kunjungan demo lama).
                if ($dpjpDoctor && $visit->dpjp_employee_id !== $dpjpDoctor->id) {
                    $visit->update(['dpjp_employee_id' => $dpjpDoctor->id]);
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
                $invoice = $this->generateInvoice($visit->id);

                // Pastikan ada item ber-harga walau master tarif tindakan kosong
                // (getPrice → 0). Tambah 1 item TINDAKAN manual Rp 250.000 supaya
                // total invoice realistis untuk demo full-cover / copay.
                if ($invoice) {
                    $this->ensurePricedItem($invoice);
                    $invoice->refresh();
                }

                // Skenario asuransi: tulis verifikasi + (full cover) covered_amount.
                if ($invoice && in_array($prof['scenario'], ['asuransi_full', 'asuransi_copay'], true) && $asuransiInsurer) {
                    $this->applyAsuransiScenario($visit, $invoice, $asuransiInsurer, $prof['scenario']);
                }

                $created++;
            }
        });

        // Riwayat pembayaran (PAID) — 1 Rawat Jalan + 1 Rawat Inap — supaya tab History
        // & pemisahan RAJAL/RANAP + kwitansi (DPJP, judul per jenis layanan) bisa diuji.
        $hist = $this->seedPaidHistory($umumInsurer, $dpjpDoctor, $kasir, $procedures);

        $this->command?->info("KasirDemoSeeder selesai — {$created} pasien antrean Kasir (invoice DRAFT) + {$hist} transaksi PAID (history RAJAL & RANAP).");
    }

    /**
     * Buat transaksi PAID untuk demo tab "History": 1 Rawat Jalan + 1 Rawat Inap.
     * RANAP memakai inpatient_charges (kamar/visite/tindakan/obat) → kwitansi RANAP
     * lengkap (blok inap + DPJP). Idempoten via NIK + cek invoice/charge existing.
     *
     * @return int jumlah invoice PAID yang dipastikan ada
     */
    private function seedPaidHistory(?Insurer $umumInsurer, ?Employee $dpjp, ?Employee $kasir, $procedures): int
    {
        $paid = 0;

        // ── 1) RAWAT JALAN — PAID ────────────────────────────────────────────────
        $patRj = Patient::firstOrCreate(
            ['nik' => '3271059900110001'],
            [
                'no_rm' => 'KS050001', 'name' => 'Joko Prasetyo (Demo HIST-RAJAL)',
                'gender' => 'L', 'date_of_birth' => '1980-01-10', 'phone' => '0813-05-9900',
                'address' => 'Jl. Demo Rawat Jalan No. 5', 'province' => 'Sumatera Utara', 'is_active' => true,
            ]
        );
        $visitRj = Visit::firstOrNew([
            'patient_id'      => $patRj->id,
            'visit_date'      => today()->toDateString(),
            'current_station' => 'SELESAI',
        ]);
        if (! $visitRj->exists) {
            $visitRj->fill([
                'insurer_id' => $umumInsurer?->id, 'jenis_pelayanan' => 'RAJAL',
                'classification' => 'Kontrol', 'visit_type' => 'REGULAR', 'guarantor_type' => 'UMUM',
                'dpjp_employee_id' => $dpjp?->id, 'ready_for_doctor' => true,
            ]);
            $visitRj->save();
        }
        foreach ($procedures as $proc) {
            VisitService::firstOrCreate(
                ['visit_id' => $visitRj->id, 'procedure_id' => $proc->id],
                ['quantity' => 1, 'notes' => 'Demo history rawat jalan']
            );
        }
        $invRj = $this->generateInvoice($visitRj->id);
        if ($invRj) {
            $this->ensurePricedItem($invRj);
            $invRj->refresh();
            if ($this->markPaid($invRj, 'CASH', $kasir)) {
                $paid++;
            }
        }

        // ── 2) RAWAT INAP — PAID (kwitansi RANAP) ────────────────────────────────
        $patRi = Patient::firstOrCreate(
            ['nik' => '3271069900110001'],
            [
                'no_rm' => 'KS060001', 'name' => 'Sri Wahyuni (Demo HIST-RANAP)',
                'gender' => 'P', 'date_of_birth' => '1970-05-05', 'phone' => '0813-06-9900',
                'address' => 'Jl. Demo Rawat Inap No. 6', 'province' => 'Sumatera Utara', 'is_active' => true,
            ]
        );
        $visitRi = Visit::firstOrNew([
            'patient_id'      => $patRi->id,
            'visit_date'      => today()->toDateString(),
            'current_station' => 'SELESAI',
        ]);
        if (! $visitRi->exists) {
            $visitRi->fill([
                'insurer_id' => $umumInsurer?->id, 'jenis_pelayanan' => 'RANAP',
                'classification' => 'Rawat Inap', 'visit_type' => 'REGULAR', 'guarantor_type' => 'UMUM',
                'dpjp_employee_id' => $dpjp?->id,
                'kelas_rawat_hak' => '2', 'kelas_rawat' => '2',
                'admission_at' => now()->subDays(2), 'discharge_at' => now()->subHours(3),
                'discharge_type' => 'PULANG_SEHAT',
            ]);
            $visitRi->save();
        }
        $this->ensureInpatientCharges($visitRi, $kasir);
        $invRi = $this->generateInvoice($visitRi->id);
        if ($invRi) {
            $invRi->refresh();
            if ($this->markPaid($invRi, 'CASH', $kasir)) {
                $paid++;
            }
        }

        return $paid;
    }

    /**
     * Pastikan visit RANAP punya inpatient_charges (sumber tagihan rawat inap).
     * Idempoten: skip bila sudah ada charge. created_by_id = kasir demo.
     */
    private function ensureInpatientCharges(Visit $visit, ?Employee $kasir): void
    {
        if (InpatientCharge::where('visit_id', $visit->id)->exists()) {
            return;
        }
        $rows = [
            [InpatientCharge::TYPE_ROOM,     'Kamar Kelas 2 (2 malam)',      2, 350000],
            [InpatientCharge::TYPE_VISITE,   'Visite Dokter Spesialis',      2, 150000],
            [InpatientCharge::TYPE_TINDAKAN, 'Perawatan Luka Operasi',       1, 200000],
            [InpatientCharge::TYPE_OBAT,     'Obat Rawat Inap (paket)',      5,  20000],
        ];
        foreach ($rows as [$type, $desc, $qty, $price]) {
            InpatientCharge::create([
                'visit_id'      => $visit->id,
                'charge_date'   => today()->toDateString(),
                'charge_type'   => $type,
                'description'   => $desc,
                'quantity'      => $qty,
                'unit_price'    => $price,
                'total_price'   => $qty * $price,
                'is_billed'     => false,
                'created_by_id' => $kasir?->id,
            ]);
        }
    }

    /**
     * Tandai invoice PAID langsung (seeder tanpa auth → tak bisa KasirService::processPayment
     * yang butuh auth user). Set paid_amount = total − covered, cashier, metode, paid_at.
     * Idempoten: skip bila sudah PAID. Return true bila menjadi PAID.
     */
    private function markPaid(BillingInvoice $invoice, string $method, ?Employee $kasir): bool
    {
        if ($invoice->status === 'PAID') {
            return false;
        }
        $due = max(0.0, (float) $invoice->total - (float) $invoice->covered_amount);
        $invoice->update([
            'status'         => 'PAID',
            'paid_amount'    => $due,
            'cash_received'  => $method === 'CASH' ? $due : null,
            'payment_method' => $method,
            'paid_at'        => now()->subHour(),
            'cashier_id'     => $kasir?->id,
        ]);

        return true;
    }

    /**
     * Pastikan ada 1 insurer bertipe ASURANSI (non-sistem) untuk skenario
     * full-cover & copay. Pada DB fresh hanya ada UMUM/BPJS/SOSIAL (sistem),
     * sehingga panel "Ditanggung Penuh Asuransi" / eligibility copay di KasirView
     * tak bisa didemokan. firstOrCreate idempoten via code.
     */
    private function ensureAsuransiInsurer(): Insurer
    {
        $existing = Insurer::where('type', 'ASURANSI')->where('is_active', true)->first();
        if ($existing) {
            return $existing;
        }

        // Baris ASR-DEMO bisa SOFT-DELETED dari run sebelumnya. Unique `code` mencakup
        // baris trashed, jadi firstOrCreate (yang abai trashed) akan INSERT → 23505.
        // Pulihkan & aktifkan kembali baris lama bila ada.
        $demo = Insurer::withTrashed()->where('code', 'ASR-DEMO')->first();
        if ($demo) {
            if ($demo->trashed()) {
                $demo->restore();
            }
            $demo->update(['is_active' => true, 'type' => 'ASURANSI']);

            return $demo;
        }

        return Insurer::create([
            'code'      => 'ASR-DEMO',
            'name'      => 'Asuransi Sehat Sentosa (Demo)',
            'type'      => 'ASURANSI',
            'is_active' => true,
            'is_system' => false,
            'is_tpa'    => false,
        ]);
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

    /** Generate invoice DRAFT lewat konsolidasi billing (return existing kalau sudah ada). */
    private function generateInvoice(string $visitId): ?BillingInvoice
    {
        $existing = BillingInvoice::where('visit_id', $visitId)
            ->whereNotIn('status', ['CANCELLED'])
            ->first();
        if ($existing) {
            return $existing;
        }

        return app(KasirService::class)->consolidateBilling($visitId);
    }

    /**
     * Pastikan invoice punya minimal satu item TINDAKAN ber-harga > 0. Saat master
     * tarif tindakan belum diisi, getPrice() me-return 0 → demo full-cover/copay
     * jadi tak bermakna (total = hanya registrasi 50rb). Tambah 1 item manual.
     * Idempoten: skip kalau sudah ada item demo ini.
     */
    private function ensurePricedItem(BillingInvoice $invoice): void
    {
        if (in_array($invoice->status, ['PAID', 'CANCELLED'], true)) {
            return;
        }
        $marker = 'Konsultasi Spesialis Mata (Demo)';
        if ($invoice->items()->where('description', $marker)->exists()) {
            return;
        }

        app(KasirService::class)->storeItemInvoice($invoice->id, [
            'item_type'   => 'TINDAKAN',
            'category'    => 'Tindakan',
            'description' => $marker,
            'quantity'    => 1,
            'unit_price'  => 250000,
        ]);
    }

    /**
     * Tulis InsuranceVerification VERIFIED + set status cache di visit + (untuk
     * full cover) covered_amount = total invoice agar FE memunculkan panel
     * "Ditanggung Penuh Asuransi". Untuk copay: plafon + copay% saja (pasien bayar
     * sisa), covered_amount dibiarkan 0 → bukan full cover.
     */
    private function applyAsuransiScenario(Visit $visit, BillingInvoice $invoice, Insurer $insurer, string $scenario): void
    {
        $isFull = $scenario === 'asuransi_full';

        InsuranceVerification::firstOrCreate(
            ['visit_id' => $visit->id, 'insurer_id' => $insurer->id],
            [
                'status'             => InsuranceVerification::STATUS_VERIFIED,
                'policy_number'      => 'POL-' . strtoupper(substr($scenario, 0, 4)) . '-' . substr($visit->id, 0, 6),
                'member_name'        => $visit->patient?->name,
                'member_card_number' => 'CARD-' . substr($visit->id, 0, 8),
                'plafon_amount'      => $isFull ? null : 1000000,        // copay: plafon 1jt; full: unlimited
                'copayment_percent'  => $isFull ? 0 : 20,               // copay: pasien 20%
                'copayment_amount'   => 0,
                'covered_amount'     => $isFull ? (float) $invoice->total : null,
                'coverage_notes'     => $isFull
                    ? 'Ditanggung penuh sesuai polis (demo).'
                    : 'Co-payment 20% ditanggung pasien, sisanya klaim TPA (demo).',
                'exclusion_flags'    => $isFull ? null : ['KACAMATA'],
                'verified_at'        => now()->subHour(),
            ]
        );

        // Cache status verifikasi di visit (dipakai getInsuranceWarning & gating FE).
        $visit->update([
            'insurance_verification_status' => 'VERIFIED',
            'insurance_verified_at'         => now()->subHour(),
        ]);

        // Full cover: tandai invoice ditanggung penuh asuransi → FE tampilkan
        // tombol "Konfirmasi Lunas (Ditanggung Asuransi)" (isFullCover = true).
        if ($isFull && ! in_array($invoice->status, ['PAID', 'CANCELLED'], true)) {
            $invoice->update(['covered_amount' => (float) $invoice->total]);
        }
    }
}
