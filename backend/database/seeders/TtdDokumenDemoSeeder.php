<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use App\Models\Visit;
use App\Services\FormRegistry\SignatureService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TtdDokumenDemoSeeder — data demo untuk halaman Antrian TTD Dokter
 * (TtdDokumenView / GET /rekam-medis/ttd-queue).
 *
 * Tujuan: menghadirkan dokumen pasien di SEMUA status sepanjang siklus TTD,
 * supaya halaman TTD Dokumen bisa diuji tampilannya tanpa harus menjalankan
 * alur dokter manual:
 *
 *   1. DRAFT              → tampil di antrian (status "Draf")
 *   2. RENDERED           → tampil di antrian (status "Siap TTD")
 *   3. PENDING_SIGNATURE  → tampil di antrian (status "Menunggu TTD")
 *   4. PENDING_SIGNATURE  → SUDAH di-TTD dokter ini → TIDAK tampil (kasus "sudah ditandatangani")
 *   5. FINALIZED          → sudah final + terkunci → TIDAK tampil (koreksi via addendum)
 *
 * Catatan kunci alur (lihat SignatureService::ttdQueueForDoctor):
 *   - Dokumen hanya masuk antrian bila template_code menunjuk ke DocumentTemplate
 *     yang punya field signature_canvas dengan signer_type='doctor'. Template
 *     OUTPUT bawaan (SURAT_BEROBAT dst) TIDAK punya itu, jadi seeder ini membuat
 *     template khusus SURAT_BEROBAT_TTD (clone + 1 field TTD dokter).
 *   - Filter "belum di-TTD oleh dokter ini" memakai signer_user_id = users.id
 *     (bukan employees.id). Resolusi: Employee->user.
 *
 * IDEMPOTEN: aman dijalankan berulang (firstOrCreate per dokumen via no_dokumen).
 *
 * Jalankan: php artisan db:seed --class=TtdDokumenDemoSeeder
 */
class TtdDokumenDemoSeeder extends Seeder
{
    private const TEMPLATE_CODE = 'SURAT_BEROBAT_TTD';
    private const NIK   = '3275019999000050'; // pasien demo TTD — idempoten
    private const NO_RM = 'TTD-DEMO-01';

    public function run(): void
    {
        // DINONAKTIFKAN (7 Jun 2026): seeder ini membuat template demo
        // 'SURAT_BEROBAT_TTD' + dokumen demo TTD. Katalog Form Registry sedang
        // dibersihkan menjadi hanya RESUME_MEDIS. Hapus baris return ini untuk
        // mengaktifkan kembali data demo antrean TTD.
        $this->command?->warn('TtdDokumenDemoSeeder dinonaktifkan (pembersihan Form Registry).');
        return;

        $doctor = Employee::where('profession', 'like', '%okter%')
            ->where('is_active', true)
            ->first();

        if (! $doctor) {
            $this->command?->warn('TtdDokumenDemoSeeder: tidak ada Employee dokter aktif. Seeder dibatalkan.');
            return;
        }

        $doctorUser = $doctor->user; // users.employee_id → users.id dipakai sbg signer_user_id
        if (! $doctorUser) {
            $this->command?->warn("TtdDokumenDemoSeeder: dokter '{$doctor->name}' belum punya akun User. "
                .'Kasus "sudah ditandatangani" akan dilewati, sisanya tetap dibuat.');
        }

        // Dokumen dibuat di dalam transaksi; TTD dokter di-capture SETELAH commit.
        // Alasan: SignatureService::capture() membuka transaksi sendiri + menulis
        // audit ke system_logs. Di Postgres, error di jalur itu bisa "meracuni"
        // transaksi induk (statement berikutnya gagal "transaction is aborted").
        // Memisahkan capture menjaga pembuatan dokumen tetap atomik & andal pada
        // run pertama di DB bersih.
        $signedDocId = DB::transaction(function () use ($doctor) {
            $template = $this->ensureTemplate();
            if (! $template) {
                return null;
            }

            $patient = Patient::firstOrCreate(
                ['nik' => self::NIK],
                [
                    'no_rm'         => self::NO_RM,
                    'name'          => 'Siti Aminah (Demo TTD)',
                    'gender'        => 'P',
                    'date_of_birth' => '1969-03-12',
                    'phone'         => '0813-9000-0050',
                    'address'       => 'Jl. Tanda Tangan No. 7, Medan',
                    'province'      => 'Sumatera Utara',
                    'is_active'     => true,
                ]
            );

            // Satu kunjungan demo (status SELESAI) — semua dokumen menempel di sini.
            $visit = $this->ensureVisit($patient, $doctor);

            // 1) DRAFT — baru disubmit, belum di-render.
            $this->ensureDocument($patient, $visit, $template, [
                'status'      => 'DRAFT',
                'number'      => 'TTD-DEMO-DRAFT',
                'days_ago'    => 0,
                'finalize'    => false,
            ]);

            // 2) RENDERED — sudah di-render, menunggu TTD.
            $this->ensureDocument($patient, $visit, $template, [
                'status'      => 'RENDERED',
                'number'      => 'TTD-DEMO-RENDERED',
                'days_ago'    => 1,
                'finalize'    => false,
            ]);

            // 3) PENDING_SIGNATURE — sudah ada TTD lain (mis. pasien), menunggu dokter.
            $this->ensureDocument($patient, $visit, $template, [
                'status'      => 'PENDING_SIGNATURE',
                'number'      => 'TTD-DEMO-PENDING',
                'days_ago'    => 2,
                'finalize'    => false,
            ]);

            // 4) PENDING_SIGNATURE — akan di-TTD dokter ini setelah commit (lihat di bawah)
            //    → setelah ditandatangani, dokumen ini TIDAK muncul lagi di antrian.
            $signed = $this->ensureDocument($patient, $visit, $template, [
                'status'      => 'PENDING_SIGNATURE',
                'number'      => 'TTD-DEMO-SIGNED',
                'days_ago'    => 3,
                'finalize'    => false,
            ]);

            // 5) FINALIZED — sudah final & terkunci (koreksi hanya via addendum) → tidak muncul.
            $this->ensureDocument($patient, $visit, $template, [
                'status'      => 'FINALIZED',
                'number'      => 'TTD-DEMO-FINAL',
                'days_ago'    => 5,
                'finalize'    => true,
            ]);

            return $signed->id;
        });

        // Capture TTD dokter di luar transaksi pembuatan dokumen.
        if ($signedDocId && $doctorUser) {
            $signed = PatientDocument::find($signedDocId);
            if ($signed) {
                $this->captureDoctorSignatureOnce($signed, $doctorUser);
            }
        }

        $this->command?->info('TtdDokumenDemoSeeder selesai — pasien "Siti Aminah (Demo TTD)" '
            .'dengan 5 dokumen: DRAFT, RENDERED, PENDING_SIGNATURE, PENDING(sudah TTD), FINALIZED. '
            .'Tiga yang pertama akan muncul di Antrian TTD Dokter.');
    }

    /**
     * Template OUTPUT clone "Surat Berobat" + 1 field signature_canvas signer_type='doctor'.
     * WAJIB ada agar dokumen lolos filter ttdQueueForDoctor (schemaRequiresDoctorSignature).
     */
    private function ensureTemplate(): ?DocumentTemplate
    {
        $docType = DocumentType::where('code', 'RM-1.2')->first();
        if (! $docType) {
            $this->command?->warn("DocumentType 'RM-1.2' tidak ada — jalankan DocumentTypeSeeder dulu. Seeder dibatalkan.");
            return null;
        }

        $fields = [
            ['key' => 'nama_pasien',       'label' => 'Nama Pasien',         'type' => 'text', 'required' => true,
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'no_rm',             'label' => 'No. Rekam Medis',     'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'tgl_lahir',         'label' => 'Tanggal Lahir',       'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'patient.date_of_birth']],
            ['key' => 'jenis_kelamin',     'label' => 'Jenis Kelamin',       'type' => 'enum_gender',
             'binding' => ['kind' => 'db', 'source' => 'patient.gender']],
            ['key' => 'tanggal_kunjungan', 'label' => 'Tanggal Kunjungan',   'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.visit_date']],
            ['key' => 'diagnosa',          'label' => 'Diagnosa Utama',      'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.diagnosis_utama']],
            ['key' => 'dokter_nama',       'label' => 'Dokter yang Merawat', 'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'visit.doctorExamination.doctor.name']],
            ['key' => 'klinik_nama',       'label' => 'Nama Klinik',         'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'klinik_alamat',     'label' => 'Alamat Klinik',       'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],

            // Field kunci: TTD dokter. Tanpa ini dokumen tidak muncul di antrian TTD dokter.
            ['key' => 'ttd_dokter', 'label' => 'Tanda Tangan Dokter', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; padding: 24px;">
  <header style="text-align: center; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 16px;">
    <h2 style="margin: 0;">{{klinik_nama}}</h2>
    <p style="margin: 4px 0; font-size: 12px;">{{klinik_alamat}}</p>
  </header>

  <h3 style="text-align: center; text-decoration: underline; margin: 16px 0;">SURAT KETERANGAN BEROBAT</h3>

  <p>Yang bertanda tangan di bawah ini menerangkan bahwa:</p>

  <table style="margin-left: 24px; font-size: 14px;">
    <tr><td style="width: 160px;">Nama</td><td>: <strong>{{nama_pasien}}</strong></td></tr>
    <tr><td>No. Rekam Medis</td><td>: {{no_rm}}</td></tr>
    <tr><td>Tanggal Lahir</td><td>: {{tgl_lahir}}</td></tr>
    <tr><td>Jenis Kelamin</td><td>: {{jenis_kelamin}}</td></tr>
  </table>

  <p style="margin-top: 16px;">
    Telah berobat di {{klinik_nama}} pada tanggal <strong>{{tanggal_kunjungan}}</strong>
    dengan diagnosa <strong>{{diagnosa}}</strong>.
  </p>

  <p>Surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.</p>

  <div style="margin-top: 48px; display: flex; justify-content: flex-end;">
    <div style="text-align: center;">
      <p style="margin: 0;">Dokter Pemeriksa,</p>
      <div style="height: 70px; display: flex; align-items: flex-end; justify-content: center;">{{ttd_dokter}}</div>
      <p style="margin: 0;"><strong>{{dokter_nama}}</strong></p>
    </div>
  </div>
</div>
HTML;

        return DocumentTemplate::updateOrCreate(
            ['code' => self::TEMPLATE_CODE],
            [
                'name'                => 'DEMO — Surat Berobat (dengan TTD Dokter)',
                'document_type_id'    => $docType->id,
                'kind'                => DocumentTemplate::KIND_OUTPUT,
                'complexity_kind'     => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
                'layout_html'         => $layoutHtml,
                'field_schema'        => ['layout_mode' => 'single_page', 'fields' => $fields],
                'station_assignments' => [
                    ['station' => 'dokter', 'section' => 'surat', 'mode' => 'OUTPUT'],
                ],
                'page_size'           => 'A4',
                'orientation'         => 'portrait',
                'version'             => 1,
                'is_active'           => true,
                'code_locked_at'      => now(),
            ]
        );
    }

    private function ensureVisit(Patient $patient, Employee $doctor): Visit
    {
        $date = Carbon::today()->subDays(5);

        $visit = Visit::firstOrNew([
            'patient_id' => $patient->id,
            'visit_date' => $date->toDateString(),
        ]);
        if (! $visit->exists) {
            $visit->fill([
                'classification'  => 'Baru',
                'visit_type'      => 'REGULAR',
                'current_station' => 'SELESAI',
                'guarantor_type'  => 'UMUM',
                'created_at'      => $date->copy()->setTime(9, 0),
                'updated_at'      => $date->copy()->setTime(11, 0),
            ]);
            $visit->save();
        }

        // DoctorExamination ringan supaya binding diagnosa & nama dokter terisi saat preview.
        \App\Models\DoctorExamination::firstOrCreate(
            ['visit_id' => $visit->id],
            [
                'doctor_id'        => $doctor->id,
                'anamnese'         => 'Mata kanan terasa berair, demo data TTD.',
                'soap_subjective'  => 'Mata kanan berair sejak 3 hari.',
                'soap_objective'   => 'VOD 6/9, VOS 6/6. Konjungtiva hiperemis ringan OD.',
                'soap_assessment'  => 'Konjungtivitis OD.',
                'soap_plan'        => 'Tetes mata antibiotik 4×1 OD. Kontrol bila memburuk.',
                'diagnosis_utama'  => 'H10.9',
                'is_finalized'     => true,
                'finalized_at'     => $date->copy()->setTime(10, 30),
            ]
        );

        return $visit;
    }

    /**
     * @param array{status:string, number:string, days_ago:int, finalize:bool} $opt
     */
    private function ensureDocument(Patient $patient, Visit $visit, DocumentTemplate $template, array $opt): PatientDocument
    {
        $date = Carbon::today()->subDays($opt['days_ago']);

        $doc = PatientDocument::firstOrNew(['document_number' => $opt['number']]);
        if (! $doc->exists) {
            $doc->fill([
                'patient_id'         => $patient->id,
                'visit_id'           => $visit->id,
                'document_type_id'   => $template->document_type_id,
                'status'             => $opt['status'],
                'created_by_station' => 'DOKTER',
                'template_code'      => $template->code,
                'template_version'   => $template->version,
                'printed_count'      => 0,
                'created_at'         => $date->copy()->setTime(9, 0),
                'updated_at'         => $date->copy()->setTime(9, 0),
            ]);
            if ($opt['finalize']) {
                $doc->finalized_at = $date->copy()->setTime(9, 30);
            }
            $doc->save();
        }

        return $doc;
    }

    /**
     * Capture TTD dokter pada dokumen (kasus "sudah ditandatangani").
     * Idempoten: lewati bila dokter ini sudah TTD dokumen tsb.
     */
    private function captureDoctorSignatureOnce(PatientDocument $doc, User $doctorUser): void
    {
        $already = $doc->documentSignatures()
            ->where('signer_type', 'doctor')
            ->where('signer_user_id', $doctorUser->id)
            ->exists();
        if ($already) {
            return;
        }

        app(SignatureService::class)->capture([
            'patient_document_id' => $doc->id,
            'signer_type'         => 'doctor',
            'signer_user_id'      => $doctorUser->id,
            // SVG minimal valid — cukup untuk demo (hash & embed jalan).
            'signature_svg'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 80">'
                .'<path d="M10 60 C 40 10, 70 70, 100 40 S 160 10, 190 50" '
                .'fill="none" stroke="#1763d4" stroke-width="2"/></svg>',
            'biometric_metadata'  => ['stroke_count' => 1, 'total_duration_ms' => 1200, 'total_points' => 42, 'demo' => true],
            'audit_log'           => ['source' => 'TtdDokumenDemoSeeder'],
            'captured_device_info'=> ['agent' => 'seeder'],
        ]);
    }
}
