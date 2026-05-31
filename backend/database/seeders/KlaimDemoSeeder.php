<?php

namespace Database\Seeders;

use App\Models\BillingInvoice;
use App\Models\BpjsClaim;
use App\Models\ClaimAuditLog;
use App\Models\DocumentType;
use App\Models\DoctorExamination;
use App\Models\Employee;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * KlaimDemoSeeder — data demo Klaim BPJS untuk KlaimView (/bpjs).
 *
 * Membuat 5 pasien + kunjungan BPJS lengkap (SEP, diagnosis dokter, billing)
 * lalu BpjsClaim di berbagai status alur kerja agar setiap kondisi KlaimView
 * tampil: DRAFT, REVIEW, VERIFIED, SUBMITTED (bpjs PENDING), DITOLAK.
 *
 * Field klaim mengikuti skema `bpjs_claims` (no_sep unik, diagnosis_utama kode
 * ICD-10, procedure_codes ICD-9, inacbgs_kode/tarif, lupis_data, bpjs_status).
 * Tarif & kode hanya untuk demo — INA-CBGs nyata via grouper backend.
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate via NIK + no_sep).
 *
 * Jalankan: php artisan db:seed --class=KlaimDemoSeeder
 */
class KlaimDemoSeeder extends Seeder
{
    /** 5 profil klaim — diagnosis hanya kode ICD-10/9 yang ada di master. */
    private array $profiles = [
        [
            'nik' => '3275081111000001', 'rm' => 'BPJS-CLM-01',
            'name' => 'Siti Rahayu (Demo Klaim)', 'gender' => 'P', 'dob' => '1969-03-12',
            'sep' => '0038R0011111111', 'kartu' => '0001112223334',
            'dx_utama' => 'H25.1', 'dx_sekunder' => ['H40.1'], 'icd9' => ['13.41'],
            'cbg_kode' => 'N-1-13-I', 'cbg_tarif' => 3850000,
            'jasa' => 1500000, 'tindakan' => 2000000, 'obat' => 350000,
            'status' => 'DRAFT', 'bpjs_status' => null, 'days_ago' => 3,
            'docs' => ['RM-2.3'], // berkas belum lengkap (baru resume dokter)
        ],
        [
            'nik' => '3275082222000002', 'rm' => 'BPJS-CLM-02',
            'name' => 'Budi Santoso (Demo Klaim)', 'gender' => 'L', 'dob' => '1957-07-21',
            'sep' => '0038R0012222222', 'kartu' => '0002223334445',
            'dx_utama' => 'H40.1', 'dx_sekunder' => [], 'icd9' => ['13.41'],
            'cbg_kode' => 'N-1-12-I', 'cbg_tarif' => 4200000,
            'jasa' => 1800000, 'tindakan' => 2200000, 'obat' => 200000,
            'status' => 'REVIEW', 'bpjs_status' => null, 'days_ago' => 4,
            'docs' => ['RM-1.2', 'RM-2.3', 'RM-3.2'],
        ],
        [
            'nik' => '3275083333000003', 'rm' => 'BPJS-CLM-03',
            'name' => 'Dewi Lestari (Demo Klaim)', 'gender' => 'P', 'dob' => '1982-11-02',
            'sep' => '0038R0013333333', 'kartu' => '0003334445556',
            'dx_utama' => 'H25.1', 'dx_sekunder' => [], 'icd9' => ['13.41'],
            'cbg_kode' => 'N-1-13-II', 'cbg_tarif' => 2750000,
            'jasa' => 1200000, 'tindakan' => 1400000, 'obat' => 150000,
            'status' => 'VERIFIED', 'bpjs_status' => null, 'days_ago' => 5,
            'docs' => ['RM-1.2', 'RM-2.2', 'RM-2.3', 'RM-3.2'],
        ],
        [
            'nik' => '3275084444000004', 'rm' => 'BPJS-CLM-04',
            'name' => 'Ahmad Fauzi (Demo Klaim)', 'gender' => 'L', 'dob' => '1978-01-30',
            'sep' => '0038R0014444444', 'kartu' => '0004445556667',
            'dx_utama' => 'H40.1', 'dx_sekunder' => ['H25.1'], 'icd9' => ['13.41'],
            'cbg_kode' => 'N-1-14-I', 'cbg_tarif' => 7500000,
            'jasa' => 3000000, 'tindakan' => 4000000, 'obat' => 500000,
            'status' => 'SUBMITTED', 'bpjs_status' => 'PENDING', 'days_ago' => 8,
            'docs' => ['RM-1.2', 'RM-2.2', 'RM-2.3', 'RM-3.2'],
        ],
        [
            'nik' => '3275085555000005', 'rm' => 'BPJS-CLM-05',
            'name' => 'Ratna Sari (Demo Klaim)', 'gender' => 'P', 'dob' => '1972-06-18',
            'sep' => '0038R0015555555', 'kartu' => '0005556667778',
            'dx_utama' => 'H25.1', 'dx_sekunder' => [], 'icd9' => ['13.41'],
            'cbg_kode' => 'N-1-13-I', 'cbg_tarif' => 2100000,
            'jasa' => 1000000, 'tindakan' => 1100000, 'obat' => 0,
            'status' => 'DITOLAK_BPJS', 'bpjs_status' => 'DITOLAK', 'days_ago' => 10,
            'reject_reason' => 'Kode tindakan tidak sesuai dengan diagnosis utama. Perbaiki ICD-9 CM.',
            'docs' => ['RM-1.2', 'RM-2.3', 'RM-3.2'],
        ],
        [
            'nik' => '3275086666000006', 'rm' => 'BPJS-CLM-06',
            'name' => 'Joko Widodo (Demo Klaim RI)', 'gender' => 'L', 'dob' => '1961-06-21',
            'sep' => '0038R0016666666', 'kartu' => '0006667778889',
            'dx_utama' => 'H25.1', 'dx_sekunder' => ['H40.1'], 'icd9' => ['13.41'],
            'cbg_kode' => 'N-1-13-I', 'cbg_tarif' => 5200000,
            'jasa' => 2200000, 'tindakan' => 2500000, 'obat' => 500000,
            'status' => 'REVIEW', 'bpjs_status' => null, 'days_ago' => 6,
            'jenis_pelayanan' => 'RANAP',
            'docs' => ['RM-1.2', 'RM-2.3', 'RM-3.2'],
        ],
    ];

    public function run(): void
    {
        $doctor  = Employee::where('profession', 'like', '%okter%')->where('is_active', true)->first();
        $insurer = Insurer::where('type', 'BPJS')->first();

        if (! $doctor) {
            $this->command?->warn('KlaimDemoSeeder: tidak ada Employee dokter aktif. Dibatalkan.');
            return;
        }

        DB::transaction(function () use ($doctor, $insurer) {
            foreach ($this->profiles as $prof) {
                $date = Carbon::today()->subDays($prof['days_ago']);

                $patient = Patient::firstOrCreate(
                    ['nik' => $prof['nik']],
                    [
                        'no_rm'         => $prof['rm'],
                        'name'          => $prof['name'],
                        'gender'        => $prof['gender'],
                        'date_of_birth' => $prof['dob'],
                        'phone'         => '0813-7000-' . substr($prof['nik'], -4),
                        'address'       => 'Jl. BPJS Sehat, Medan',
                        'province'      => 'Sumatera Utara',
                        'bpjs_number'   => $prof['kartu'],
                        'is_active'     => true,
                    ]
                );

                // Kunjungan BPJS dengan SEP (idempoten via patient + visit_date).
                $visit = Visit::firstOrNew([
                    'patient_id' => $patient->id,
                    'visit_date' => $date->toDateString(),
                ]);
                if (! $visit->exists) {
                    $visit->fill([
                        'insurer_id'      => $insurer?->id,
                        'no_sep'          => $prof['sep'],
                        'classification'  => 'Baru',
                        'visit_type'      => 'REGULAR',
                        'jenis_pelayanan' => $prof['jenis_pelayanan'] ?? 'RAJAL',
                        'current_station' => 'SELESAI',
                        'guarantor_type'  => 'BPJS',
                        'created_at'      => $date->copy()->setTime(9, 0),
                        'updated_at'      => $date->copy()->setTime(13, 0),
                    ]);
                    $visit->save();
                }

                // Pemeriksaan dokter (diagnosis sumber klaim).
                DoctorExamination::firstOrCreate(
                    ['visit_id' => $visit->id],
                    [
                        'doctor_id'          => $doctor->id,
                        'soap_assessment'    => 'Diagnosis kerja: ' . $prof['dx_utama'],
                        'diagnosis_utama'    => $prof['dx_utama'],
                        'diagnosis_sekunder' => $prof['dx_sekunder'],
                        'tindakan_codes'     => $prof['icd9'],
                        'planning'           => 'PULANG_BEROBAT_JALAN',
                        'is_finalized'       => true,
                        'finalized_at'       => $date->copy()->setTime(11, 0),
                    ]
                );

                // Billing invoice (sumber total biaya untuk LUPIS).
                $total = $prof['jasa'] + $prof['tindakan'] + $prof['obat'];
                BillingInvoice::firstOrCreate(
                    ['visit_id' => $visit->id],
                    [
                        'invoice_number' => 'INV-' . $prof['rm'] . '-' . $date->format('ymd'),
                        'subtotal'       => $total,
                        'discount'       => 0,
                        'tax'            => 0,
                        'total'          => $total,
                        'status'         => 'PAID',
                        'payment_method' => 'BPJS',
                        'paid_amount'    => $total,
                        'paid_at'        => $date->copy()->setTime(13, 0),
                        'notes'          => 'Demo billing klaim BPJS.',
                    ]
                );

                // Dokumen pendukung klaim (RM yang FINAL) — kelengkapan berkas.
                $this->seedDocuments($visit, $patient, $prof, $date);

                $this->seedClaim($visit, $patient, $doctor, $prof, $date);
            }
        });

        $this->command?->info('KlaimDemoSeeder selesai — ' . count($this->profiles) . ' klaim BPJS demo (DRAFT/REVIEW/VERIFIED/SUBMITTED/DITOLAK_BPJS + 1 RANAP) + dokumen pendukung + audit trail.');
    }

    /** Buat BpjsClaim sesuai status profil + audit log + lupis_data bila perlu. */
    private function seedClaim(Visit $visit, Patient $patient, Employee $doctor, array $prof, Carbon $date): void
    {
        $status   = $prof['status'];
        // DITOLAK_BPJS = sudah lewat siklus penuh (grouping+LUPIS+submit) lalu ditolak BPJS.
        $grouped  = in_array($status, ['REVIEW', 'VERIFIED', 'SUBMITTED', 'DITOLAK_BPJS'], true);
        $hasLupis = in_array($status, ['VERIFIED', 'SUBMITTED', 'DITOLAK_BPJS'], true);
        $isBpjsRejected = $status === 'DITOLAK_BPJS';

        $claim = BpjsClaim::firstOrNew(['no_sep' => $prof['sep']]);
        if ($claim->exists) {
            return; // idempoten — sudah ada
        }

        $claim->fill([
            'visit_id'           => $visit->id,
            'patient_nik'        => $patient->nik,
            'diagnosis_utama'    => $prof['dx_utama'],
            'diagnosis_sekunder' => $prof['dx_sekunder'],
            'procedure_codes'    => $prof['icd9'],
            'inacbgs_kode'       => $grouped ? $prof['cbg_kode'] : null,
            'inacbgs_tarif'      => $grouped ? $prof['cbg_tarif'] : null,
            'lupis_data'         => $hasLupis ? $this->buildLupis($patient, $prof) : null,
            'status'             => $status,
            'bpjs_status'        => $prof['bpjs_status'],
            'bpjs_response'      => in_array($status, ['SUBMITTED', 'DITOLAK_BPJS'], true)
                ? ['noSep' => $prof['sep'], 'status' => $isBpjsRejected ? 'rejected' : 'submitted', 'timestamp' => $date->copy()->setTime(13, 5)->toIso8601String()]
                : null,
            'submitted_at'       => in_array($status, ['SUBMITTED', 'DITOLAK_BPJS'], true) ? $date->copy()->setTime(13, 5) : null,
            'rejection_reason'   => $isBpjsRejected ? ($prof['reject_reason'] ?? null) : null,
            'rejected_at'        => $isBpjsRejected ? $date->copy()->addDays(2)->setTime(14, 0) : null,
            'resubmission_count' => 0,
            'created_at'         => $date->copy()->setTime(13, 0),
            'updated_at'         => $date->copy()->setTime(13, 30),
        ]);
        $claim->save();

        $this->seedAuditTrail($claim->id, $doctor->id, $status, $date, $prof['reject_reason'] ?? null);
    }

    /** Dokumen pendukung klaim = PatientDocument FINAL pada visit klaim. */
    private function seedDocuments(Visit $visit, Patient $patient, array $prof, Carbon $date): void
    {
        foreach (($prof['docs'] ?? []) as $i => $typeCode) {
            $type = DocumentType::where('code', $typeCode)->first();
            if (! $type) {
                continue;
            }
            PatientDocument::firstOrCreate(
                ['patient_id' => $patient->id, 'visit_id' => $visit->id, 'document_type_id' => $type->id],
                [
                    'document_number'    => 'DOC-' . $prof['rm'] . '-' . strtoupper($typeCode),
                    'status'             => 'FINAL',
                    'created_by_station' => 'DOKTER',
                    'printed_count'      => 1,
                    'finalized_at'       => $date->copy()->setTime(12, 0)->addMinutes($i * 5),
                    'template_code'      => $typeCode,
                    'template_version'   => 1,
                ]
            );
        }
    }

    /** Struktur LUPIS ringkas (rawat jalan). */
    private function buildLupis(Patient $patient, array $prof): array
    {
        return [
            'noSep'            => $prof['sep'],
            'nik'             => $patient->nik,
            'nama'            => $patient->name,
            'tglLahir'        => $prof['dob'],
            'jnsPelayanan'    => '2', // rawat jalan
            'diagnosaUtama'   => $prof['dx_utama'],
            'diagnosaSekunder' => $prof['dx_sekunder'],
            'procedureCodes'  => $prof['icd9'],
            'cbgCode'         => $prof['cbg_kode'],
            'cbgTarif'        => $prof['cbg_tarif'],
            'totalBiaya'      => $prof['jasa'] + $prof['tindakan'] + $prof['obat'],
        ];
    }

    /** Audit trail sesuai tahap status yang sudah dilalui. */
    private function seedAuditTrail(string $claimId, ?string $empId, string $status, Carbon $date, ?string $rejectReason): void
    {
        // Urutan tahap kumulatif sesuai alur kerja.
        $steps = [
            ['PREPARE',   null,        'DRAFT',     'Data klaim disiapkan dari kunjungan', 0],
            ['REVIEW',    'DRAFT',     'REVIEW',    null, 60],
            ['GROUPING',  'REVIEW',    'REVIEW',    'CBG di-grouping INA-CBGs', 70],
            ['LUPIS_GENERATED', 'REVIEW', 'REVIEW', 'Data LUPIS digenerate', 80],
            ['VERIFIKASI', 'REVIEW',   'VERIFIED',  'Semua berkas lengkap dan terverifikasi', 90],
            ['SUBMIT',    'VERIFIED',  'SUBMITTED', 'Disubmit ke VClaim', 95],
        ];

        // Tentukan sampai tahap mana berdasarkan status akhir.
        $reachIndex = [
            'DRAFT'        => 0,
            'REVIEW'       => 1,   // PREPARE + REVIEW (grouping/lupis belum)
            'VERIFIED'     => 4,
            'SUBMITTED'    => 5,
            'DITOLAK_BPJS' => 5,   // lewat siklus penuh (submit) lalu ditolak BPJS
        ][$status] ?? 0;

        for ($i = 0; $i <= $reachIndex; $i++) {
            [$action, $old, $new, $note, $minute] = $steps[$i];
            ClaimAuditLog::create([
                'bpjs_claim_id'   => $claimId,
                'performed_by_id' => $empId,
                'action'          => $action,
                'old_status'      => $old,
                'new_status'      => $new,
                'notes'           => $note,
                'created_at'      => $date->copy()->setTime(13, 0)->addMinutes($minute),
                'updated_at'      => $date->copy()->setTime(13, 0)->addMinutes($minute),
            ]);
        }

        // Entri penolakan BPJS (setelah submit).
        if ($status === 'DITOLAK_BPJS') {
            ClaimAuditLog::create([
                'bpjs_claim_id'   => $claimId,
                'performed_by_id' => $empId,
                'action'          => 'REJECT_BPJS',
                'old_status'      => 'SUBMITTED',
                'new_status'      => 'DITOLAK_BPJS',
                'notes'           => $rejectReason ?? 'Klaim ditolak BPJS.',
                'created_at'      => $date->copy()->addDays(2)->setTime(14, 0),
                'updated_at'      => $date->copy()->addDays(2)->setTime(14, 0),
            ]);
        }
    }
}
