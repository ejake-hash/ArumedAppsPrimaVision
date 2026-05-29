<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Onboarding Form Registry — 5 template OUTPUT prioritas (Fase 1 + Fase 2).
 *
 * Mapping parent document_type (sudah ada di DocumentTypeSeeder):
 *   SURAT_BEROBAT  → RM-1.2 (Surat Keterangan Rawat Jalan)  ← pilot Fase 1
 *   RESUME_MEDIS   → RM-6.1 (Resume Medis Rawat Jalan)
 *   SURAT_KONTROL  → RM-6.2 (Surat Kontrol Ulang)
 *   SURAT_RUJUKAN  → RM-6.3 (Surat Rujukan)
 *   SURAT_SAKIT    → RM-6.4 (Surat Keterangan Sakit)
 *
 * Semua active + code_locked_at di-set saat seeding (anggap sudah curated).
 * Jalankan manual: php artisan db:seed --class=FormTemplateSeeder
 */
class FormTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSuratBerobat();
        $this->seedResumeMedis();
        $this->seedSuratKontrol();
        $this->seedSuratRujukan();
        $this->seedSuratSakit();
        $this->seedGeneralConsent();
        $this->seedMorseFallScale();
        $this->seedDemoKopTest();
    }

    /**
     * DEMO_KOP_TEST — template OUTPUT minimal untuk test rendering logo klinik.
     * Sekedar dummy "Kartu Identitas Pasien" dengan kop surat lengkap (logo +
     * nama + alamat + telp). Tujuan: lihat preview cepat apakah {{clinic_logo}}
     * ter-render <img> di output.
     */
    private function seedDemoKopTest(): void
    {
        $docType = $this->requireDocType('RM-1.2');  // reuse Surat Rawat Jalan
        if (!$docType) return;

        $fields = [
            ['key' => 'clinic_logo',   'label' => 'Logo Klinik',    'type' => 'image_url',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.logo_path'],
             'max_height_px' => 100],
            ['key' => 'clinic_name',   'label' => 'Nama Klinik',    'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'clinic_addr',   'label' => 'Alamat Klinik',  'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],
            ['key' => 'clinic_phone',  'label' => 'Telp Klinik',    'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.phone']],
            ['key' => 'clinic_email',  'label' => 'Email Klinik',   'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.email']],
            ['key' => 'nama_pasien',   'label' => 'Nama Pasien',    'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'no_rm',         'label' => 'No. Rekam Medis','type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'tgl_lahir',     'label' => 'Tanggal Lahir',  'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'patient.date_of_birth']],
            ['key' => 'tanggal_cetak', 'label' => 'Tanggal Cetak',  'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.visit_date']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; padding: 24px;">
  <header style="display: flex; align-items: center; gap: 16px; border-bottom: 2px solid #1763d4; padding-bottom: 12px; margin-bottom: 20px;">
    <div style="flex: 0 0 auto;">
      {{clinic_logo}}
    </div>
    <div style="flex: 1; text-align: left;">
      <h2 style="margin: 0 0 4px; color: #1763d4; font-size: 20px;">{{clinic_name}}</h2>
      <p style="margin: 2px 0; font-size: 12px; color: #444;">{{clinic_addr}}</p>
      <p style="margin: 2px 0; font-size: 12px; color: #444;">
        Telp: {{clinic_phone}} &nbsp;|&nbsp; Email: {{clinic_email}}
      </p>
    </div>
  </header>

  <h3 style="text-align: center; text-decoration: underline; margin: 24px 0;">
    KARTU IDENTITAS PASIEN (DEMO KOP)
  </h3>

  <p style="margin-bottom: 16px;">Data pasien yang terdaftar di sistem rekam medis:</p>

  <table style="width: 100%; max-width: 500px; margin: 0 auto; font-size: 14px; line-height: 1.8;">
    <tr>
      <td style="width: 180px;"><strong>Nama Pasien</strong></td>
      <td>: {{nama_pasien}}</td>
    </tr>
    <tr>
      <td><strong>No. Rekam Medis</strong></td>
      <td>: {{no_rm}}</td>
    </tr>
    <tr>
      <td><strong>Tanggal Lahir</strong></td>
      <td>: {{tgl_lahir}}</td>
    </tr>
    <tr>
      <td><strong>Tanggal Cetak</strong></td>
      <td>: {{tanggal_cetak}}</td>
    </tr>
  </table>

  <footer style="margin-top: 48px; padding-top: 12px; border-top: 1px dashed #999; font-size: 11px; color: #777; text-align: center;">
    Template DEMO_KOP_TEST &mdash; verifikasi kop surat (logo + identitas klinik)
  </footer>
</div>
HTML;

        $this->upsert('DEMO_KOP_TEST', [
            'name'                  => 'DEMO — Test Kop Surat (Logo + Identitas Klinik)',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_OUTPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'dokter', 'section' => 'surat', 'mode' => 'OUTPUT'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pilot Fase 1
    // ─────────────────────────────────────────────────────────────────────────

    private function seedSuratBerobat(): void
    {
        $docType = $this->requireDocType('RM-1.2');
        if (!$docType) return;

        $fields = [
            ['key' => 'nama_pasien',       'label' => 'Nama Pasien',         'type' => 'text',        'required' => true,
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
            ['key' => 'direktur_nama',     'label' => 'Direktur Klinik',     'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.director_name']],
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
      <br><br><br>
      <p style="margin: 0;"><strong>{{dokter_nama}}</strong></p>
    </div>
  </div>
</div>
HTML;

        $this->upsert('SURAT_BEROBAT', [
            'name'                  => 'Surat Keterangan Berobat',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_OUTPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'dokter', 'section' => 'surat', 'mode' => 'OUTPUT'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fase 2 — onboarding 4 form tambahan
    // ─────────────────────────────────────────────────────────────────────────

    private function seedResumeMedis(): void
    {
        $docType = $this->requireDocType('RM-6.1');
        if (!$docType) return;

        $fields = [
            ['key' => 'nama_pasien',   'label' => 'Nama Pasien',     'type' => 'text', 'required' => true,
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'no_rm',         'label' => 'No. Rekam Medis', 'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'tgl_lahir',     'label' => 'Tanggal Lahir',   'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'patient.date_of_birth']],
            ['key' => 'jenis_kelamin', 'label' => 'Jenis Kelamin',   'type' => 'enum_gender',
             'binding' => ['kind' => 'db', 'source' => 'patient.gender']],
            ['key' => 'tanggal_kunjungan', 'label' => 'Tanggal Kunjungan', 'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.visit_date']],
            ['key' => 'anamnese',      'label' => 'Anamnesa',        'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.anamnese']],
            ['key' => 'soap_o',        'label' => 'Objective',       'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.soap_objective']],
            ['key' => 'soap_a',        'label' => 'Assessment',      'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.soap_assessment']],
            ['key' => 'soap_p',        'label' => 'Plan',            'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.soap_plan']],
            ['key' => 'diagnosa_list', 'label' => 'Diagnosa (ICD-10)', 'type' => 'longtext',
             'binding' => ['kind' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']],
            ['key' => 'resep_table',   'label' => 'Daftar Resep',    'type' => 'longtext',
             'binding' => ['kind' => 'aggregate', 'source' => 'prescriptions', 'format' => 'items_table_html']],
            ['key' => 'dokter_nama',   'label' => 'Dokter',          'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'visit.doctorExamination.doctor.name']],
            ['key' => 'klinik_nama',   'label' => 'Nama Klinik',     'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'klinik_alamat', 'label' => 'Alamat Klinik',   'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; padding: 24px;">
  <header style="text-align: center; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 16px;">
    <h2 style="margin: 0;">{{klinik_nama}}</h2>
    <p style="margin: 4px 0; font-size: 12px;">{{klinik_alamat}}</p>
  </header>

  <h3 style="text-align: center; text-decoration: underline; margin: 16px 0;">RESUME MEDIS RAWAT JALAN</h3>

  <table style="font-size: 14px; margin-bottom: 12px;">
    <tr><td style="width: 160px;">Nama</td><td>: <strong>{{nama_pasien}}</strong></td></tr>
    <tr><td>No. Rekam Medis</td><td>: {{no_rm}}</td></tr>
    <tr><td>Tanggal Lahir</td><td>: {{tgl_lahir}} ({{jenis_kelamin}})</td></tr>
    <tr><td>Tanggal Kunjungan</td><td>: {{tanggal_kunjungan}}</td></tr>
  </table>

  <h4 style="margin-bottom: 4px;">Anamnesa</h4>
  <p style="white-space: pre-line;">{{anamnese}}</p>

  <h4 style="margin-bottom: 4px;">Pemeriksaan (Objective)</h4>
  <p style="white-space: pre-line;">{{soap_o}}</p>

  <h4 style="margin-bottom: 4px;">Diagnosis</h4>
  <p style="white-space: pre-line;">{{diagnosa_list}}</p>

  <h4 style="margin-bottom: 4px;">Assessment</h4>
  <p style="white-space: pre-line;">{{soap_a}}</p>

  <h4 style="margin-bottom: 4px;">Planning</h4>
  <p style="white-space: pre-line;">{{soap_p}}</p>

  <h4 style="margin-bottom: 4px;">Terapi</h4>
  {{resep_table}}

  <div style="margin-top: 48px; display: flex; justify-content: flex-end;">
    <div style="text-align: center;">
      <p style="margin: 0;">Dokter,</p>
      <br><br><br>
      <p style="margin: 0;"><strong>{{dokter_nama}}</strong></p>
    </div>
  </div>
</div>
HTML;

        $this->upsert('RESUME_MEDIS', [
            'name'                  => 'Resume Medis Rawat Jalan',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_OUTPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'dokter', 'section' => 'resume_output', 'mode' => 'OUTPUT'],
            ],
        ]);
    }

    private function seedSuratKontrol(): void
    {
        $docType = $this->requireDocType('RM-6.2');
        if (!$docType) return;

        $fields = [
            ['key' => 'nama_pasien',   'label' => 'Nama Pasien',     'type' => 'text', 'required' => true,
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'no_rm',         'label' => 'No. Rekam Medis', 'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'tgl_lahir',     'label' => 'Tanggal Lahir',   'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'patient.date_of_birth']],
            ['key' => 'tanggal_kunjungan', 'label' => 'Tanggal Berobat', 'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.visit_date']],
            ['key' => 'tgl_kontrol',   'label' => 'Tanggal Kontrol', 'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.follow_up_date']],
            ['key' => 'alasan_kontrol','label' => 'Alasan Kontrol',  'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'visit.follow_up_reason']],
            ['key' => 'diagnosa',      'label' => 'Diagnosa',        'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.diagnosis_utama']],
            ['key' => 'dokter_nama',   'label' => 'Dokter',          'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'visit.doctorExamination.doctor.name']],
            ['key' => 'klinik_nama',   'label' => 'Nama Klinik',     'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'klinik_alamat', 'label' => 'Alamat Klinik',   'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; padding: 24px;">
  <header style="text-align: center; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 16px;">
    <h2 style="margin: 0;">{{klinik_nama}}</h2>
    <p style="margin: 4px 0; font-size: 12px;">{{klinik_alamat}}</p>
  </header>

  <h3 style="text-align: center; text-decoration: underline; margin: 16px 0;">SURAT KONTROL ULANG</h3>

  <p>Pasien dengan identitas berikut dijadwalkan untuk kontrol ulang:</p>

  <table style="margin-left: 24px; font-size: 14px;">
    <tr><td style="width: 180px;">Nama</td><td>: <strong>{{nama_pasien}}</strong></td></tr>
    <tr><td>No. Rekam Medis</td><td>: {{no_rm}}</td></tr>
    <tr><td>Tanggal Lahir</td><td>: {{tgl_lahir}}</td></tr>
    <tr><td>Tanggal Berobat</td><td>: {{tanggal_kunjungan}}</td></tr>
    <tr><td>Diagnosa</td><td>: {{diagnosa}}</td></tr>
    <tr><td>Tanggal Kontrol</td><td>: <strong>{{tgl_kontrol}}</strong></td></tr>
  </table>

  <p style="margin-top: 16px;"><strong>Alasan Kontrol:</strong></p>
  <p style="white-space: pre-line;">{{alasan_kontrol}}</p>

  <div style="margin-top: 48px; display: flex; justify-content: flex-end;">
    <div style="text-align: center;">
      <p style="margin: 0;">Dokter,</p>
      <br><br><br>
      <p style="margin: 0;"><strong>{{dokter_nama}}</strong></p>
    </div>
  </div>
</div>
HTML;

        $this->upsert('SURAT_KONTROL', [
            'name'                  => 'Surat Kontrol Ulang',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_OUTPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'dokter', 'section' => 'surat', 'mode' => 'OUTPUT'],
            ],
        ]);
    }

    private function seedSuratRujukan(): void
    {
        $docType = $this->requireDocType('RM-6.3');
        if (!$docType) return;

        $fields = [
            ['key' => 'nama_pasien',   'label' => 'Nama Pasien',     'type' => 'text', 'required' => true,
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'no_rm',         'label' => 'No. Rekam Medis', 'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'nik',           'label' => 'NIK',             'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.nik']],
            ['key' => 'tgl_lahir',     'label' => 'Tanggal Lahir',   'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'patient.date_of_birth']],
            ['key' => 'jenis_kelamin', 'label' => 'Jenis Kelamin',   'type' => 'enum_gender',
             'binding' => ['kind' => 'db', 'source' => 'patient.gender']],
            ['key' => 'alamat',        'label' => 'Alamat',          'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'patient.address']],
            ['key' => 'tanggal_kunjungan', 'label' => 'Tanggal',     'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.visit_date']],
            ['key' => 'anamnese',      'label' => 'Anamnesa',        'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.anamnese']],
            ['key' => 'pemeriksaan',   'label' => 'Pemeriksaan',     'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.soap_objective']],
            ['key' => 'diagnosa_list', 'label' => 'Diagnosa',        'type' => 'longtext',
             'binding' => ['kind' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']],
            ['key' => 'terapi',        'label' => 'Terapi yang sudah diberikan', 'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.soap_plan']],
            ['key' => 'tujuan_rujukan','label' => 'Tujuan Rujukan',  'type' => 'longtext',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'dokter_nama',   'label' => 'Dokter Perujuk',  'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'visit.doctorExamination.doctor.name']],
            ['key' => 'klinik_nama',   'label' => 'Nama Klinik',     'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'klinik_alamat', 'label' => 'Alamat Klinik',   'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; padding: 24px;">
  <header style="text-align: center; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 16px;">
    <h2 style="margin: 0;">{{klinik_nama}}</h2>
    <p style="margin: 4px 0; font-size: 12px;">{{klinik_alamat}}</p>
  </header>

  <h3 style="text-align: center; text-decoration: underline; margin: 16px 0;">SURAT RUJUKAN</h3>

  <p>Mohon untuk dapat menerima pasien dengan identitas dan kondisi sebagai berikut:</p>

  <table style="font-size: 14px; margin-bottom: 12px;">
    <tr><td style="width: 180px;">Nama</td><td>: <strong>{{nama_pasien}}</strong></td></tr>
    <tr><td>No. Rekam Medis / NIK</td><td>: {{no_rm}} / {{nik}}</td></tr>
    <tr><td>Tanggal Lahir / JK</td><td>: {{tgl_lahir}} / {{jenis_kelamin}}</td></tr>
    <tr><td>Alamat</td><td>: {{alamat}}</td></tr>
    <tr><td>Tanggal Pemeriksaan</td><td>: {{tanggal_kunjungan}}</td></tr>
  </table>

  <h4 style="margin-bottom: 4px;">Anamnesa</h4>
  <p style="white-space: pre-line;">{{anamnese}}</p>

  <h4 style="margin-bottom: 4px;">Pemeriksaan</h4>
  <p style="white-space: pre-line;">{{pemeriksaan}}</p>

  <h4 style="margin-bottom: 4px;">Diagnosis</h4>
  <p style="white-space: pre-line;">{{diagnosa_list}}</p>

  <h4 style="margin-bottom: 4px;">Terapi yang sudah diberikan</h4>
  <p style="white-space: pre-line;">{{terapi}}</p>

  <h4 style="margin-bottom: 4px;">Tujuan Rujukan</h4>
  <p style="white-space: pre-line;">{{tujuan_rujukan}}</p>

  <div style="margin-top: 48px; display: flex; justify-content: flex-end;">
    <div style="text-align: center;">
      <p style="margin: 0;">Dokter Perujuk,</p>
      <br><br><br>
      <p style="margin: 0;"><strong>{{dokter_nama}}</strong></p>
    </div>
  </div>
</div>
HTML;

        $this->upsert('SURAT_RUJUKAN', [
            'name'                  => 'Surat Rujukan',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_OUTPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'dokter', 'section' => 'surat', 'mode' => 'OUTPUT'],
            ],
        ]);
    }

    private function seedSuratSakit(): void
    {
        $docType = $this->requireDocType('RM-6.4');
        if (!$docType) return;

        $fields = [
            ['key' => 'nama_pasien',   'label' => 'Nama Pasien',     'type' => 'text', 'required' => true,
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'no_rm',         'label' => 'No. Rekam Medis', 'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'tgl_lahir',     'label' => 'Tanggal Lahir',   'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'patient.date_of_birth']],
            ['key' => 'jenis_kelamin', 'label' => 'Jenis Kelamin',   'type' => 'enum_gender',
             'binding' => ['kind' => 'db', 'source' => 'patient.gender']],
            ['key' => 'alamat',        'label' => 'Alamat',          'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'patient.address']],
            ['key' => 'tanggal_kunjungan', 'label' => 'Tanggal Pemeriksaan', 'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.visit_date']],
            ['key' => 'diagnosa',      'label' => 'Diagnosa',        'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'doctorExamination.diagnosis_utama']],
            ['key' => 'durasi_istirahat', 'label' => 'Lama Istirahat (hari)', 'type' => 'number',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'mulai_istirahat',  'label' => 'Mulai Istirahat',     'type' => 'date',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'dokter_nama',   'label' => 'Dokter',          'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'visit.doctorExamination.doctor.name']],
            ['key' => 'klinik_nama',   'label' => 'Nama Klinik',     'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'klinik_alamat', 'label' => 'Alamat Klinik',   'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; padding: 24px;">
  <header style="text-align: center; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 16px;">
    <h2 style="margin: 0;">{{klinik_nama}}</h2>
    <p style="margin: 4px 0; font-size: 12px;">{{klinik_alamat}}</p>
  </header>

  <h3 style="text-align: center; text-decoration: underline; margin: 16px 0;">SURAT KETERANGAN SAKIT</h3>

  <p>Yang bertanda tangan di bawah ini menerangkan bahwa:</p>

  <table style="margin-left: 24px; font-size: 14px;">
    <tr><td style="width: 180px;">Nama</td><td>: <strong>{{nama_pasien}}</strong></td></tr>
    <tr><td>No. Rekam Medis</td><td>: {{no_rm}}</td></tr>
    <tr><td>Tanggal Lahir / JK</td><td>: {{tgl_lahir}} / {{jenis_kelamin}}</td></tr>
    <tr><td>Alamat</td><td>: {{alamat}}</td></tr>
  </table>

  <p style="margin-top: 16px;">
    Setelah dilakukan pemeriksaan pada tanggal <strong>{{tanggal_kunjungan}}</strong>,
    pasien tersebut <strong>perlu beristirahat</strong> selama <strong>{{durasi_istirahat}}</strong> hari
    terhitung sejak <strong>{{mulai_istirahat}}</strong> karena sakit dengan diagnosa
    <strong>{{diagnosa}}</strong>.
  </p>

  <p>Surat keterangan ini dibuat dengan sebenarnya dan dapat dipergunakan sebagaimana mestinya.</p>

  <div style="margin-top: 48px; display: flex; justify-content: flex-end;">
    <div style="text-align: center;">
      <p style="margin: 0;">Dokter Pemeriksa,</p>
      <br><br><br>
      <p style="margin: 0;"><strong>{{dokter_nama}}</strong></p>
    </div>
  </div>
</div>
HTML;

        $this->upsert('SURAT_SAKIT', [
            'name'                  => 'Surat Keterangan Sakit',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_OUTPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'dokter', 'section' => 'surat', 'mode' => 'OUTPUT'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fase 3 — onboarding 1 form INPUT (General Consent)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedGeneralConsent(): void
    {
        $docType = $this->requireDocType('RM-1.1');
        if (!$docType) return;

        // Mix: field DB (patient/visit) + static (saksi + persetujuan).
        // signature_canvas sengaja BELUM dipakai — Fase 4.
        $fields = [
            // Identitas pasien (sebagian besar auto-fill via existing data, tapi
            // tetap editable di form untuk koreksi typo saat onboarding).
            ['key' => 'nama_pasien',     'label' => 'Nama Pasien',         'type' => 'text',        'required' => true,
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'nik',             'label' => 'NIK',                  'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.nik']],
            ['key' => 'no_rm',           'label' => 'No. Rekam Medis',     'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'tgl_lahir',       'label' => 'Tanggal Lahir',       'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'patient.date_of_birth']],
            ['key' => 'jenis_kelamin',   'label' => 'Jenis Kelamin',       'type' => 'enum_gender',
             'binding' => ['kind' => 'db', 'source' => 'patient.gender']],
            ['key' => 'alamat',          'label' => 'Alamat',               'type' => 'longtext',
             'binding' => ['kind' => 'db', 'source' => 'patient.address']],
            ['key' => 'no_telp',         'label' => 'No. Telepon',          'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.phone']],

            // Persetujuan (static — disimpan ke patient_documents.signatures.static_payload)
            ['key' => 'menyetujui_pemeriksaan',  'label' => 'Saya menyetujui pemeriksaan',           'type' => 'multi_checkbox', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'menyetujui_pengobatan',   'label' => 'Saya menyetujui pengobatan',            'type' => 'multi_checkbox', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'menyetujui_administrasi', 'label' => 'Saya menyetujui pengurusan administrasi','type' => 'multi_checkbox', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],

            // Saksi (static)
            ['key' => 'saksi_nama',      'label' => 'Nama Saksi',           'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'saksi_nik',       'label' => 'NIK Saksi',            'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'saksi_hubungan',  'label' => 'Hubungan dengan Pasien','type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],

            // Tanda tangan (Fase 4) — capture via SignatureService append-only.
            // signer_type wajib ada (server validate); required = true → wajib
            // sebelum finalize.
            ['key' => 'ttd_pasien',  'label' => 'Tanda Tangan Pasien', 'type' => 'signature_canvas',
             'signer_type' => 'patient', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'ttd_saksi',   'label' => 'Tanda Tangan Saksi',  'type' => 'signature_canvas',
             'signer_type' => 'witness', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],

            // Klinik (auto-resolve saat render)
            ['key' => 'klinik_nama',     'label' => 'Nama Klinik',          'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'klinik_alamat',   'label' => 'Alamat Klinik',        'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],
            ['key' => 'tanggal_kunjungan', 'label' => 'Tanggal Kunjungan',  'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.visit_date']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; padding: 24px;">
  <header style="text-align: center; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 16px;">
    <h2 style="margin: 0;">{{klinik_nama}}</h2>
    <p style="margin: 4px 0; font-size: 12px;">{{klinik_alamat}}</p>
  </header>

  <h3 style="text-align: center; text-decoration: underline; margin: 16px 0;">PERSETUJUAN UMUM (GENERAL CONSENT)</h3>

  <p>Saya yang bertanda tangan di bawah ini:</p>

  <table style="margin-left: 24px; font-size: 14px;">
    <tr><td style="width: 180px;">Nama</td><td>: <strong>{{nama_pasien}}</strong></td></tr>
    <tr><td>NIK</td><td>: {{nik}}</td></tr>
    <tr><td>No. Rekam Medis</td><td>: {{no_rm}}</td></tr>
    <tr><td>Tanggal Lahir / JK</td><td>: {{tgl_lahir}} / {{jenis_kelamin}}</td></tr>
    <tr><td>Alamat</td><td>: {{alamat}}</td></tr>
    <tr><td>No. Telepon</td><td>: {{no_telp}}</td></tr>
  </table>

  <p style="margin-top: 16px;">Pada tanggal <strong>{{tanggal_kunjungan}}</strong>, dengan ini menyatakan:</p>

  <ol style="font-size: 14px; line-height: 1.6;">
    <li>{{menyetujui_pemeriksaan}} Menyetujui pemeriksaan kesehatan oleh tenaga medis di klinik ini.</li>
    <li>{{menyetujui_pengobatan}} Menyetujui pengobatan / tindakan medis yang dianggap perlu sesuai diagnosis.</li>
    <li>{{menyetujui_administrasi}} Menyetujui pengurusan administrasi dan penggunaan data rekam medis untuk klaim asuransi.</li>
  </ol>

  <p>Demikian pernyataan ini saya buat dengan kesadaran penuh, tanpa tekanan dari pihak manapun.</p>

  <div style="margin-top: 36px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <div style="text-align: center;">
      <p style="margin: 0;"><strong>Saksi</strong></p>
      <p style="margin: 4px 0; font-size: 13px;">Nama: {{saksi_nama}}</p>
      <p style="margin: 4px 0; font-size: 13px;">NIK: {{saksi_nik}}</p>
      <p style="margin: 4px 0; font-size: 13px;">Hubungan: {{saksi_hubungan}}</p>
      <div style="height: 70px; display: flex; align-items: flex-end; justify-content: center;">{{ttd_saksi}}</div>
      <p style="margin: 0; border-top: 1px solid #333; padding-top: 4px;">Tanda tangan</p>
    </div>
    <div style="text-align: center;">
      <p style="margin: 0;"><strong>Pasien / Wali</strong></p>
      <p style="margin: 4px 0; font-size: 13px;">{{nama_pasien}}</p>
      <div style="height: 70px; display: flex; align-items: flex-end; justify-content: center;">{{ttd_pasien}}</div>
      <p style="margin: 0; border-top: 1px solid #333; padding-top: 4px;">Tanda tangan</p>
    </div>
  </div>
</div>
HTML;

        $this->upsert('GENERAL_CONSENT', [
            'name'                  => 'Persetujuan Umum (General Consent)',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_INPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'admisi', 'section' => 'identitas',  'mode' => 'INPUT'],
                ['station' => 'dokter', 'section' => 'consent',    'mode' => 'INPUT'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fase 5 — Morse Fall Scale (SCORED_FORM, risiko jatuh dewasa)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedMorseFallScale(): void
    {
        $docType = $this->requireDocType('RM-2.1');  // Asesmen Keperawatan
        if (!$docType) return;

        $fields = [
            // Identitas (auto-fill)
            ['key' => 'nama_pasien', 'label' => 'Nama Pasien', 'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'no_rm',       'label' => 'No. RM',      'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'tanggal',     'label' => 'Tanggal',     'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'visit.visit_date']],

            // 6 pertanyaan scored — Morse Fall Scale standard
            ['key' => 'riwayat_jatuh', 'label' => 'Riwayat jatuh dalam 3 bulan terakhir', 'type' => 'scored_radio', 'required' => true,
             'options' => [
                 ['label' => 'Tidak', 'score' => 0],
                 ['label' => 'Ya',    'score' => 25],
             ],
             'binding' => ['kind' => 'static', 'value' => null]],

            ['key' => 'diagnosis_sekunder', 'label' => 'Diagnosis sekunder (≥ 2 diagnosa medis)', 'type' => 'scored_radio', 'required' => true,
             'options' => [
                 ['label' => 'Tidak', 'score' => 0],
                 ['label' => 'Ya',    'score' => 15],
             ],
             'binding' => ['kind' => 'static', 'value' => null]],

            ['key' => 'alat_bantu', 'label' => 'Alat bantu jalan', 'type' => 'scored_radio', 'required' => true,
             'options' => [
                 ['label' => 'Tidak ada / bedrest / kursi roda dibantu', 'score' => 0],
                 ['label' => 'Kruk / tongkat / walker',                  'score' => 15],
                 ['label' => 'Berpegangan pada furnitur',                'score' => 30],
             ],
             'binding' => ['kind' => 'static', 'value' => null]],

            ['key' => 'iv_terapi', 'label' => 'Terpasang infus / heparin lock', 'type' => 'scored_radio', 'required' => true,
             'options' => [
                 ['label' => 'Tidak', 'score' => 0],
                 ['label' => 'Ya',    'score' => 20],
             ],
             'binding' => ['kind' => 'static', 'value' => null]],

            ['key' => 'cara_jalan', 'label' => 'Cara berjalan', 'type' => 'scored_radio', 'required' => true,
             'options' => [
                 ['label' => 'Normal / bedrest / tidak bergerak', 'score' => 0],
                 ['label' => 'Lemah / lemah lutut',              'score' => 10],
                 ['label' => 'Terganggu / tidak seimbang',       'score' => 20],
             ],
             'binding' => ['kind' => 'static', 'value' => null]],

            ['key' => 'status_mental', 'label' => 'Status mental', 'type' => 'scored_radio', 'required' => true,
             'options' => [
                 ['label' => 'Sadar akan kemampuan diri',         'score' => 0],
                 ['label' => 'Tidak sadar (over-/under-estimate)', 'score' => 15],
             ],
             'binding' => ['kind' => 'static', 'value' => null]],

            // Computed total
            ['key' => 'total_score', 'label' => 'Total Skor', 'type' => 'computed_sum',
             'sum_of' => ['riwayat_jatuh', 'diagnosis_sekunder', 'alat_bantu', 'iv_terapi', 'cara_jalan', 'status_mental'],
             'binding' => ['kind' => 'computed']],

            // Computed interpretation
            ['key' => 'interpretasi', 'label' => 'Interpretasi Risiko', 'type' => 'computed_threshold',
             'based_on' => 'total_score',
             'thresholds' => [
                 ['max' => 24,   'label' => 'Risiko Rendah (tidak ada / minimal intervensi)'],
                 ['max' => 44,   'label' => 'Risiko Sedang (standard fall prevention)'],
                 ['max' => 9999, 'label' => 'Risiko Tinggi (high-risk fall protocol)'],
             ],
             'binding' => ['kind' => 'computed']],

            // Perawat (auto-resolve via visit) + signature
            ['key' => 'perawat_nama', 'label' => 'Perawat Asesor', 'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'nurseAssessment.assessment_notes']],   // placeholder; nama lewat user yang submit

            ['key' => 'ttd_perawat', 'label' => 'Tanda Tangan Perawat', 'type' => 'signature_canvas',
             'signer_type' => 'nurse', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],

            // Klinik
            ['key' => 'klinik_nama', 'label' => 'Nama Klinik', 'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; padding: 24px;">
  <header style="text-align: center; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 16px;">
    <h2 style="margin: 0;">{{klinik_nama}}</h2>
  </header>

  <h3 style="text-align: center; text-decoration: underline; margin: 16px 0;">ASESMEN RISIKO JATUH — MORSE FALL SCALE</h3>

  <table style="font-size: 14px; margin-bottom: 12px;">
    <tr><td style="width: 160px;">Nama</td><td>: <strong>{{nama_pasien}}</strong></td></tr>
    <tr><td>No. Rekam Medis</td><td>: {{no_rm}}</td></tr>
    <tr><td>Tanggal Asesmen</td><td>: {{tanggal}}</td></tr>
  </table>

  <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;">
    <thead><tr style="background:#f0f0f0;">
      <th style="border:1px solid #999;padding:6px;text-align:left;">Kriteria</th>
      <th style="border:1px solid #999;padding:6px;width:120px;">Jawaban</th>
    </tr></thead>
    <tbody>
      <tr><td style="border:1px solid #999;padding:6px;">Riwayat jatuh (3 bulan)</td><td style="border:1px solid #999;padding:6px;">{{riwayat_jatuh}}</td></tr>
      <tr><td style="border:1px solid #999;padding:6px;">Diagnosis sekunder</td><td style="border:1px solid #999;padding:6px;">{{diagnosis_sekunder}}</td></tr>
      <tr><td style="border:1px solid #999;padding:6px;">Alat bantu jalan</td><td style="border:1px solid #999;padding:6px;">{{alat_bantu}}</td></tr>
      <tr><td style="border:1px solid #999;padding:6px;">Terpasang infus</td><td style="border:1px solid #999;padding:6px;">{{iv_terapi}}</td></tr>
      <tr><td style="border:1px solid #999;padding:6px;">Cara berjalan</td><td style="border:1px solid #999;padding:6px;">{{cara_jalan}}</td></tr>
      <tr><td style="border:1px solid #999;padding:6px;">Status mental</td><td style="border:1px solid #999;padding:6px;">{{status_mental}}</td></tr>
    </tbody>
    <tfoot>
      <tr style="background:#fff7ec;">
        <td style="border:1px solid #999;padding:8px;font-weight:700;">Total Skor</td>
        <td style="border:1px solid #999;padding:8px;font-weight:700;text-align:center;">{{total_score}}</td>
      </tr>
      <tr>
        <td style="border:1px solid #999;padding:8px;font-weight:700;">Interpretasi</td>
        <td style="border:1px solid #999;padding:8px;">{{interpretasi}}</td>
      </tr>
    </tfoot>
  </table>

  <div style="margin-top: 36px; display: flex; justify-content: flex-end;">
    <div style="text-align: center;">
      <p style="margin: 0;">Perawat Asesor,</p>
      <div style="height:60px;display:flex;align-items:center;justify-content:center;">{{ttd_perawat}}</div>
      <p style="margin: 0; border-top: 1px solid #333; padding-top: 4px;">Tanda tangan</p>
    </div>
  </div>
</div>
HTML;

        $this->upsert('MORSE_FALL_SCALE', [
            'name'                  => 'Asesmen Risiko Jatuh — Morse Fall Scale',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_INPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SCORED_FORM,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'perawat', 'section' => 'asesmen_input', 'mode' => 'INPUT'],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function requireDocType(string $code): ?DocumentType
    {
        $dt = DocumentType::query()->where('code', $code)->first();
        if ($dt === null) {
            $this->command?->warn("DocumentType '{$code}' tidak ditemukan — jalankan DocumentTypeSeeder dulu.");
            return null;
        }
        return $dt;
    }

    private function upsert(string $code, array $attrs): void
    {
        $tpl = DocumentTemplate::query()->updateOrCreate(
            ['code' => $code],
            array_merge($attrs, [
                'page_size'      => 'A4',
                'orientation'    => 'portrait',
                'version'        => 1,
                'is_active'      => true,
                'code_locked_at' => now(),
            ])
        );
        $this->command?->info("Template {$code} siap (id={$tpl->id}).");
    }
}
