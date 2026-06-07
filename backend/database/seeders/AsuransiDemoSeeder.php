<?php

namespace Database\Seeders;

use App\Models\BillingInvoice;
use App\Models\BillingItem;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimLog;
use App\Models\InsuranceVerification;
use App\Models\Insurer;
use App\Models\InsurerDocumentRequirement;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AsuransiDemoSeeder — data demo modul Asuransi/TPA Non-BPJS (AsuransiView, /asuransi).
 *
 * Mengisi keempat tab + kartu ringkasan dashboard:
 *   1. Verifikasi Pending — 3 kunjungan HARI INI status verifikasi PENDING
 *      (2 punya row verifikasi awal utk prefill polis, 1 belum).
 *   2. Sedang Dilayani    — 3 kunjungan HARI INI VERIFIED/ISSUE (current_station != SELESAI)
 *      + invoice: full cover / partial cover / ISSUE tanpa cover (patient_due muncul).
 *   3. Klaim Management   — 6 InsuranceClaim lintas status (DRAFT/SUBMITTED/APPROVED/
 *      REJECTED/APPEALED + 1 SUBMITTED lama) + audit log (timeline).
 *   4. Aging Report       — klaim DRAFT/SUBMITTED/APPEALED; 1 SUBMITTED overdue
 *      (umur 25 hari > sla_days ASR 14).
 *
 * Master pendukung: 2 insurer TPA (type ASURANSI, sla_days) + InsurerDocumentRequirement
 * (supaya documents_checklist klaim & checklist modal submit terisi).
 *
 * CATATAN: performed_by/submitted_by = User (BUKAN Employee). Modul ini berbeda dari
 * KlaimView/bpjs_claims (lihat KlaimDemoSeeder) — di sini tabel insurance_claims (TPA).
 *
 * IDEMPOTEN: firstOrCreate via NIK / no_registrasi / visit_id. Aman dijalankan berulang.
 * Jalankan: php artisan db:seed --class=AsuransiDemoSeeder
 */
class AsuransiDemoSeeder extends Seeder
{
    /** Dokumen wajib per TPA (untuk documents_checklist). [nama, wajib, urutan]. */
    private array $docNames = [
        ['Resume Medis', true, 1],
        ['Kwitansi Asli', true, 2],
        ['Salinan Kartu Peserta', true, 3],
        ['Hasil Pemeriksaan Penunjang', false, 4],
    ];

    public function run(): void
    {
        // performed_by/submitted_by butuh User; modul digate kasir.* → pakai akun kasir.
        $actor = User::where('username', 'kasir')->first()
            ?? User::where('username', 'verifikator')->first()
            ?? User::query()->first();

        DB::transaction(function () use ($actor) {
            $asr = $this->ensureInsurer('ASR-DEMO', 'Asuransi Sehat Sentosa (Demo)', 14, false);
            $adm = $this->ensureInsurer('ADM-DEMO', 'Admedika TPA (Demo)', 21, true);
            foreach ([$asr, $adm] as $ins) {
                $this->seedDocRequirements($ins);
            }

            $this->seedPending($asr, $adm);
            $this->seedInService($asr, $adm);
            $this->seedClaims($asr, $adm, $actor);
        });

        $this->command?->info('AsuransiDemoSeeder selesai — 2 TPA + dokumen wajib, 3 verifikasi pending, 3 sedang dilayani, 6 klaim (1 overdue) + audit log.');
    }

    // ── MASTER ──────────────────────────────────────────────────────────────────
    private function ensureInsurer(string $code, string $name, int $sla, bool $tpa): Insurer
    {
        $ins = Insurer::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => 'ASURANSI', 'is_active' => true, 'is_system' => false, 'is_tpa' => $tpa, 'sla_days' => $sla]
        );
        // ASR-DEMO mungkin sudah dibuat KasirDemoSeeder/FarmasiDemoSeeder tanpa sla_days → lengkapi.
        if ($ins->sla_days === null) {
            $ins->update(['sla_days' => $sla]);
        }
        return $ins;
    }

    private function seedDocRequirements(Insurer $ins): void
    {
        foreach ($this->docNames as [$name, $req, $order]) {
            InsurerDocumentRequirement::firstOrCreate(
                ['insurer_id' => $ins->id, 'document_name' => $name],
                ['is_required' => $req, 'sort_order' => $order]
            );
        }
    }

    /** documents_checklist {nama=>bool}; complete=semua true, else 2 wajib pertama saja. */
    private function checklist(bool $complete): array
    {
        $out = [];
        foreach ($this->docNames as $i => [$name]) {
            $out[$name] = $complete ? true : ($i < 2);
        }
        return $out;
    }

    // ── TAB 1: VERIFIKASI PENDING ─────────────────────────────────────────────────
    private function seedPending(Insurer $asr, Insurer $adm): void
    {
        $defs = [
            ['nik' => '3273010000000101', 'rm' => 'ASR-PND-01', 'name' => 'Aulia Rahman',    'g' => 'L', 'dob' => '1988-02-14', 'ins' => $asr, 'wait' => 6,  'verif' => true],
            ['nik' => '3273010000000102', 'rm' => 'ASR-PND-02', 'name' => 'Fitri Handayani', 'g' => 'P', 'dob' => '1993-09-30', 'ins' => $adm, 'wait' => 18, 'verif' => true],
            ['nik' => '3273010000000103', 'rm' => 'ASR-PND-03', 'name' => 'Gunawan Pratama', 'g' => 'L', 'dob' => '1979-05-22', 'ins' => $asr, 'wait' => 3,  'verif' => false],
        ];
        foreach ($defs as $d) {
            $p = $this->makePatient($d);
            $createdAt = now()->subMinutes($d['wait']);
            $visit = $this->makeVisit($p, $d['ins'], 'PENDING', 'KASIR', Carbon::today(), $createdAt);
            if ($d['verif']) {
                InsuranceVerification::firstOrCreate(
                    ['visit_id' => $visit->id, 'insurer_id' => $d['ins']->id],
                    [
                        'status'             => InsuranceVerification::STATUS_PENDING,
                        'policy_number'      => 'POL-' . substr($d['nik'], -6),
                        'member_name'        => $d['name'],
                        'member_card_number' => 'CARD-' . substr($d['nik'], -7),
                        'created_at'         => $createdAt,
                        'updated_at'         => $createdAt,
                    ]
                );
            }
        }
    }

    // ── TAB 2: SEDANG DILAYANI ────────────────────────────────────────────────────
    private function seedInService(Insurer $asr, Insurer $adm): void
    {
        $defs = [
            // Full cover → patient_due 0 (kasir cukup konfirmasi).
            ['nik' => '3273020000000201', 'rm' => 'ASR-SVC-01', 'name' => 'Hesti Wulandari', 'g' => 'P', 'dob' => '1985-04-08', 'ins' => $asr,
             'vstatus' => 'VERIFIED', 'verif' => InsuranceVerification::STATUS_VERIFIED, 'total' => 750000, 'covered' => 750000, 'issue' => null],
            // Partial cover → pasien tanggung selisih.
            ['nik' => '3273020000000202', 'rm' => 'ASR-SVC-02', 'name' => 'Irfan Maulana',   'g' => 'L', 'dob' => '1990-12-19', 'ins' => $adm,
             'vstatus' => 'VERIFIED', 'verif' => InsuranceVerification::STATUS_VERIFIED, 'total' => 1200000, 'covered' => 900000, 'issue' => null],
            // ISSUE (NEEDS_CLARIFICATION) → visit status ISSUE, belum ada cover.
            ['nik' => '3273020000000203', 'rm' => 'ASR-SVC-03', 'name' => 'Joko Prasetyo',   'g' => 'L', 'dob' => '1972-07-03', 'ins' => $asr,
             'vstatus' => 'ISSUE', 'verif' => InsuranceVerification::STATUS_NEEDS_CLARIFICATION, 'total' => 600000, 'covered' => 0,
             'issue' => 'Sisa plafon Rp 400rb < estimasi tagihan Rp 600rb — pasien tanggung selisih.'],
        ];
        foreach ($defs as $d) {
            $p = $this->makePatient($d);
            $visit = $this->makeVisit($p, $d['ins'], $d['vstatus'], 'KASIR', Carbon::today(), now()->subHours(2));
            $this->makeInvoice($visit, $d['total'], $d['covered'], 'DRAFT');

            $isPartial = $d['covered'] > 0 && $d['covered'] < $d['total'];
            InsuranceVerification::firstOrCreate(
                ['visit_id' => $visit->id, 'insurer_id' => $d['ins']->id],
                [
                    'status'             => $d['verif'],
                    'policy_number'      => 'POL-' . substr($d['nik'], -6),
                    'member_name'        => $d['name'],
                    'member_card_number' => 'CARD-' . substr($d['nik'], -7),
                    'plafon_amount'      => $d['verif'] === InsuranceVerification::STATUS_NEEDS_CLARIFICATION ? 400000 : 5000000,
                    'copayment_percent'  => $isPartial ? 25 : 0,
                    'covered_amount'     => $d['covered'] > 0 ? $d['covered'] : null,
                    'coverage_notes'     => $d['covered'] >= $d['total']
                        ? 'Ditanggung penuh sesuai polis (demo).'
                        : ($isPartial ? 'Cover sebagian, sisa co-payment pasien (demo).' : null),
                    'issue_notes'        => $d['issue'],
                    'verified_at'        => now()->subHours(2),
                ]
            );
        }
    }

    // ── TAB 3 + 4: KLAIM MANAGEMENT + AGING ────────────────────────────────────────
    private function seedClaims(Insurer $asr, Insurer $adm, ?User $actor): void
    {
        $defs = [
            // DRAFT — outstanding, belum submit (aging: umur dari created_at).
            ['nik' => '3273030000000301', 'rm' => 'ASR-CLM-01', 'name' => 'Kartika Sari',      'g' => 'P', 'dob' => '1983-01-11', 'ins' => $asr,
             'status' => 'DRAFT', 'amount' => 850000, 'created_days' => 2, 'complete' => false],
            // SUBMITTED bulan ini → tidak overdue.
            ['nik' => '3273030000000302', 'rm' => 'ASR-CLM-02', 'name' => 'Lukman Hakim',      'g' => 'L', 'dob' => '1968-06-27', 'ins' => $adm,
             'status' => 'SUBMITTED', 'amount' => 1500000, 'created_days' => 4, 'submitted_days' => 2, 'complete' => true],
            // SUBMITTED lama → OVERDUE (umur 25 > sla ASR 14).
            ['nik' => '3273030000000303', 'rm' => 'ASR-CLM-03', 'name' => 'Maya Anggraini',    'g' => 'P', 'dob' => '1991-03-05', 'ins' => $asr,
             'status' => 'SUBMITTED', 'amount' => 2100000, 'created_days' => 27, 'submitted_days' => 25, 'complete' => true],
            // APPROVED bulan ini (ada selisih → patient_responsibility 200rb).
            ['nik' => '3273030000000304', 'rm' => 'ASR-CLM-04', 'name' => 'Nanda Pratama',     'g' => 'L', 'dob' => '1987-10-02', 'ins' => $adm,
             'status' => 'APPROVED', 'amount' => 1800000, 'approved' => 1600000, 'created_days' => 12, 'submitted_days' => 10, 'approved_days' => 1, 'complete' => true],
            // REJECTED bulan ini.
            ['nik' => '3273030000000305', 'rm' => 'ASR-CLM-05', 'name' => 'Oki Setiawan',      'g' => 'L', 'dob' => '1975-08-16', 'ins' => $asr,
             'status' => 'REJECTED', 'amount' => 950000, 'created_days' => 9, 'submitted_days' => 8, 'rejected_days' => 2, 'complete' => false,
             'rej_code' => 'DOC-INCOMPLETE', 'rej_reason' => 'Resume medis tidak mencantumkan diagnosis utama. Lengkapi & resubmit.'],
            // APPEALED (dibanding, sudah resubmit 1×).
            ['nik' => '3273030000000306', 'rm' => 'ASR-CLM-06', 'name' => 'Putri Ayuningtyas', 'g' => 'P', 'dob' => '1995-11-23', 'ins' => $adm,
             'status' => 'APPEALED', 'amount' => 3200000, 'created_days' => 20, 'submitted_days' => 18, 'resub' => 1, 'complete' => true,
             'appeal' => 'Banding dgn bukti tambahan: surat keterangan medis & hasil OCT. Mohon ditinjau ulang.'],
        ];

        foreach ($defs as $d) {
            $p         = $this->makePatient($d);
            $createdAt = now()->subDays($d['created_days'])->setTime(10, 0);
            $visit     = $this->makeVisit($p, $d['ins'], 'VERIFIED', 'SELESAI', Carbon::today()->subDays($d['created_days']), $createdAt);
            $invoice   = $this->makeInvoice($visit, $d['amount'], $d['amount'], 'PAID', $createdAt);

            $verif = InsuranceVerification::firstOrCreate(
                ['visit_id' => $visit->id, 'insurer_id' => $d['ins']->id],
                [
                    'status'             => InsuranceVerification::STATUS_VERIFIED,
                    'policy_number'      => 'POL-' . substr($d['nik'], -6),
                    'member_name'        => $d['name'],
                    'member_card_number' => 'CARD-' . substr($d['nik'], -7),
                    'covered_amount'     => $d['amount'],
                    'verified_at'        => $createdAt,
                ]
            );

            if (InsuranceClaim::where('visit_id', $visit->id)->exists()) {
                continue; // idempoten — klaim sudah ada
            }

            $submittedAt = isset($d['submitted_days']) ? now()->subDays($d['submitted_days'])->setTime(11, 0) : null;
            $approvedAt  = isset($d['approved_days'])  ? now()->subDays($d['approved_days'])->setTime(14, 0)  : null;
            $rejectedAt  = isset($d['rejected_days'])  ? now()->subDays($d['rejected_days'])->setTime(14, 0)  : null;
            $ref         = $submittedAt ? 'TPA-' . $d['rm'] : null;

            $claim = InsuranceClaim::create([
                'visit_id'                  => $visit->id,
                'insurer_id'                => $d['ins']->id,
                'billing_invoice_id'        => $invoice->id,
                'insurance_verification_id' => $verif->id,
                'submitted_by'              => $submittedAt ? $actor?->id : null,
                'status'                    => $d['status'],
                'claim_amount'              => $d['amount'],
                'approved_amount'           => $d['approved'] ?? null,
                'patient_responsibility'    => isset($d['approved']) ? max(0, $d['amount'] - $d['approved']) : 0,
                'submission_ref'            => $ref,
                'submitted_at'              => $submittedAt,
                'approved_at'               => $approvedAt,
                'rejected_at'               => $rejectedAt,
                'documents_checklist'       => $this->checklist($d['complete']),
                'rejection_code'            => $d['rej_code']   ?? null,
                'rejection_reason'          => $d['rej_reason'] ?? null,
                'resubmission_count'        => $d['resub']      ?? 0,
                'appeal_notes'              => $d['appeal']     ?? null,
                'notes'                     => 'Klaim demo TPA (AsuransiDemoSeeder).',
                'created_at'                => $createdAt,
                'updated_at'                => $approvedAt ?? $rejectedAt ?? $submittedAt ?? $createdAt,
            ]);

            $this->seedLogs($claim, $actor, $d, $createdAt, $submittedAt, $approvedAt, $rejectedAt, $ref);
        }
    }

    /** Audit trail kumulatif sesuai status akhir klaim (CREATED → SUBMITTED → …). */
    private function seedLogs(
        InsuranceClaim $claim, ?User $actor, array $d,
        Carbon $createdAt, ?Carbon $submittedAt, ?Carbon $approvedAt, ?Carbon $rejectedAt, ?string $ref
    ): void {
        $log = function (string $action, ?string $from, ?string $to, Carbon $at, array $meta = [], ?string $notes = null) use ($claim, $actor) {
            InsuranceClaimLog::create([
                'insurance_claim_id' => $claim->id,
                'performed_by'       => $actor?->id,
                'action'             => $action,
                'from_status'        => $from,
                'to_status'          => $to,
                'notes'              => $notes,
                'metadata'           => $meta ?: null,
                'performed_at'       => $at,
            ]);
        };

        $log(InsuranceClaimLog::ACTION_CREATED, null, 'DRAFT', $createdAt, ['source' => 'demo']);
        if ($submittedAt) {
            $log(InsuranceClaimLog::ACTION_SUBMITTED, 'DRAFT', 'SUBMITTED', $submittedAt, ['submission_ref' => $ref]);
        }
        if ($approvedAt) {
            $log(InsuranceClaimLog::ACTION_APPROVED, 'SUBMITTED', 'APPROVED', $approvedAt, ['approved_amount' => $d['approved'] ?? null]);
        }
        if ($rejectedAt) {
            $log(InsuranceClaimLog::ACTION_REJECTED, 'SUBMITTED', 'REJECTED', $rejectedAt, ['rejection_code' => $d['rej_code'] ?? null], $d['rej_reason'] ?? null);
        }
        if (($d['status'] ?? null) === 'APPEALED') {
            $appealAt = $submittedAt ? $submittedAt->copy()->addDays(3) : $createdAt;
            $log(InsuranceClaimLog::ACTION_APPEALED, 'SUBMITTED', 'APPEALED', $appealAt, [], $d['appeal'] ?? null);
        }
    }

    // ── HELPERS ─────────────────────────────────────────────────────────────────
    private function makePatient(array $d): Patient
    {
        return Patient::firstOrCreate(
            ['nik' => $d['nik']],
            [
                'no_rm'         => $d['rm'],
                'name'          => $d['name'] . ' (Demo Asuransi)',
                'gender'        => $d['g'],
                'date_of_birth' => $d['dob'],
                'phone'         => '0814-' . substr($d['nik'], -4) . '-00',
                'address'       => 'Jl. Asuransi Sehat No. ' . substr($d['nik'], -2) . ', Medan',
                'province'      => 'Sumatera Utara',
                'is_active'     => true,
            ]
        );
    }

    private function makeVisit(Patient $p, Insurer $ins, string $verifStatus, string $station, Carbon $date, Carbon $createdAt): Visit
    {
        return Visit::firstOrCreate(
            ['no_registrasi' => 'REG-' . $p->nik],
            [
                'patient_id'                    => $p->id,
                'visit_date'                    => $date->toDateString(),
                'jenis_pelayanan'               => 'RAJAL',
                'classification'                => 'Kontrol',
                'visit_type'                    => 'REGULAR',
                'guarantor_type'                => 'ASURANSI',
                'insurer_id'                    => $ins->id,
                'current_station'               => $station,
                'insurance_verification_status' => $verifStatus,
                'insurance_verified_at'         => $verifStatus === 'PENDING' ? null : $createdAt,
                'created_at'                    => $createdAt,
                'updated_at'                    => $createdAt,
            ]
        );
    }

    private function makeInvoice(Visit $visit, float $total, float $covered, string $status, ?Carbon $at = null): BillingInvoice
    {
        $at = $at ?? now();
        $invoice = BillingInvoice::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                // Ekor UUID (bagian acak) — BUKAN prefix: UUIDv7 berbasis-waktu, prefix
                // sama utk visit yang dibuat di detik yang sama → invoice_number bentrok.
                'invoice_number' => 'INV-ASR/' . $at->format('Y/m') . '/' . substr($visit->id, -12),
                'status'         => $status,
                'subtotal'       => $total,
                'discount'       => 0,
                'tax'            => 0,
                'total'          => $total,
                'covered_amount' => $covered, // kolom NOT NULL → pakai 0, bukan null
                'paid_amount'    => $status === 'PAID' ? max(0, $total - $covered) : 0,
                'paid_at'        => $status === 'PAID' ? $at : null,
                'created_at'     => $at,
                'updated_at'     => $at,
            ]
        );

        // Item rincian (untuk panel "Rincian Tagihan Pasien" di modal verifikasi).
        if (! $invoice->items()->exists()) {
            $items = [
                ['desc' => 'Konsultasi Spesialis Mata (Demo)',  'price' => 200000.0],
                ['desc' => 'Tindakan / Pemeriksaan Mata (Demo)', 'price' => max(0.0, $total - 200000.0)],
            ];
            foreach ($items as $it) {
                if ($it['price'] <= 0) {
                    continue;
                }
                BillingItem::create([
                    'billing_invoice_id' => $invoice->id,
                    'item_type'          => 'TINDAKAN',
                    'category'           => 'Tindakan',
                    'description'        => $it['desc'],
                    'quantity'           => 1,
                    'unit_price'         => $it['price'],
                    'total_price'        => $it['price'],
                    'discount_amount'    => 0,
                    'discount_percent'   => 0,
                    'net_price'          => $it['price'],
                ]);
            }
        }

        return $invoice;
    }
}
