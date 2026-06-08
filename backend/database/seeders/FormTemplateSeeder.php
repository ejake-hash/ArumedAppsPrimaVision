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
        // Katalog Form Registry sedang dibangun ulang dari form RESMI (PDF) satu per
        // satu. Hanya RM 1.7/RMRJ/22 (Resume Medis Rawat Jalan) yang dipertahankan
        // sebagai percontohan. Template prototipe lain DIMATIKAN (atas permintaan,
        // 7 Jun 2026) — tambahkan kembali per form saat dibuat dari PDF resminya.
        $this->seedResumeMedis();
        // Lembar Klaim — Resume Medis versi klaim (diagnosa/ICD dari koding koder,
        // di-TTD dokter; Resume Medis asli dokter tetap utuh). Dipakai BPJS klaim.
        $this->seedResumeKlaim();
        // RM 2.0/CKB/22 — Checklist Kesiapan Bedah (slice pertama batch bedah).
        $this->seedChecklistKesiapanBedah();
        // RM 10.1/LOVR/22 — Laporan Operasi Vitreo Retina (kondisional: surgery_type=VITREORETINA).
        $this->seedLaporanOperasiVitreoRetina();
        // RM 2.3/COK/22 — Catatan Operasi katarak (kondisional: surgery_type=KATARAK).
        $this->seedCatatanOperasiKatarak();
        // RM 2.2/LP/22 — Laporan Pembedahan generik (universal semua operasi).
        $this->seedLaporanPembedahan();

        // — DINONAKTIFKAN (prototipe lama; aktifkan ulang saat dibangun dari PDF) —
        // $this->seedSuratBerobat();
        // $this->seedSuratKontrol();
        // $this->seedSuratRujukan();
        // $this->seedSuratSakit();
        // $this->seedGeneralConsent();
        // $this->seedMorseFallScale();
        // $this->seedDemoKopTest();
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

    /**
     * RM 1.7/RMRJ/22 — Resume Medis Rawat Jalan (form resmi RS Mata Prima Vision).
     *
     * Pola PERCONTOHAN untuk seluruh form RM (lihat Docs/PLAN-KATALOG-FORMULIR-RM.md):
     *   - kind = HYBRID: dokter buka di DokterView → field AUTO ter-prefill dari
     *     data klinis (anti kerja-dua-kali) TAPI tetap EDITABLE; lalu TTD PIN.
     *   - Dua kelas field:
     *       (a) display_only = auto, hanya tampil di cetak (kop, identitas, meta).
     *           Di-resolve renderer dari binding db/clinic; tidak diisi manual.
     *       (b) editable = binding 'static' + `prefill` (via db/aggregate/clinic).
     *           Prefill jadi default; saat submit tersimpan ke static_payload
     *           dokumen (TIDAK menulis balik ke sumber klinis — diagnosa klaim
     *           BPJS ditangani terpisah di modul Klaim, lihat catatan plan).
     *   - Diagnosa = ICD-10 (aggregate), Tindakan = ICD-9 (tindakan_codes).
     *   - TTD dokter via PIN → stempel "Ditandatangani elektronik" + QR verifikasi.
     */
    private function seedResumeMedis(): void
    {
        $docType = $this->requireDocType('RM-6.1');
        if (!$docType) return;

        // (a) Field AUTO display-only — resolve di output, tidak muncul di input.
        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'display_only' => true, 'binding' => $binding,
        ];
        // (b) Field EDITABLE — binding 'static' (tersimpan ke dokumen) + prefill.
        $editable = fn (string $key, string $label, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];

        $fields = [
            // ── Kop klinik ───────────────────────────────────────────────────
            $auto('klinik_logo',   'Logo Klinik',  'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 64],
            $auto('klinik_nama',   'Nama Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik','text',      ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.phone']),
            // ── Identitas pasien ─────────────────────────────────────────────
            $auto('nama_pasien',   'Nama Pasien',  'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir','date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',          'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',       'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',          'text', ['kind' => 'db', 'source' => 'patient.nik']),
            // ── Meta kunjungan ───────────────────────────────────────────────
            $auto('tanggal_berobat','Tanggal Berobat','date', ['kind' => 'db', 'source' => 'visit.visit_date']),
            $auto('dokter_nama',   'Dokter yang Merawat', 'text', ['kind' => 'db', 'source' => 'visit.doctorExamination.doctor.name']),
            $auto('ruang_poli',    'Ruang Poli',   'text', ['kind' => 'db', 'source' => 'visit.doctorSchedule.poliklinik']),
            $auto('penanggung',    'Penanggung Pembayaran', 'text', ['kind' => 'db', 'source' => 'visit.guarantor_type']),

            // ── Isi resume — AUTO-prefill + EDITABLE ─────────────────────────
            $editable('anamnese',         'Anamnese',          ['via' => 'db', 'source' => 'doctorExamination.anamnese']),
            $editable('pemeriksaan_fisik','Pemeriksaan Fisik', ['via' => 'db', 'source' => 'doctorExamination.soap_objective']),
            $editable('alergi',           'Alergi Obat',       ['via' => 'db', 'source' => 'nurseAssessment.allergy_detail']),
            $editable('penunjang',        'Hasil Penunjang Medis (Lab/Radiologi/dll)', ['via' => 'aggregate', 'source' => 'diagnosticResults.summary', 'format' => 'summary_per_jenis']),
            $editable('diagnosa',         'Diagnosa (ICD-10)', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            $editable('tindakan',         'Tindakan (ICD-9)',  ['via' => 'db', 'source' => 'doctorExamination.tindakan_codes']),
            $editable('terapi',           'Terapi',            ['via' => 'aggregate', 'source' => 'prescriptions', 'format' => 'items_pretty']),
            // Riwayat: manual (tidak ada sumber otomatis).
            ['key' => 'riwayat', 'label' => 'Riwayat/Rawat Inap/Operasi/Tindakan', 'type' => 'longtext', 'binding' => ['kind' => 'static']],
            // Instruksi/Anjuran: kalimat siap-pakai dari rencana tatalaksana (kontrol +
            // tanggal / operasi-paket + tanggal / rawat inap / rujuk), bukan enum mentah.
            $editable('instruksi',        'Instruksi/Anjuran dan Edukasi Lanjutan', ['via' => 'aggregate', 'source' => 'planning_instruction']),
            // Kontrol: tanggal prefill dari rencana follow-up; lokasi default RS (editable).
            $editable('kontrol_tgl',      'Kontrol Tanggal',   ['via' => 'db', 'source' => 'visit.follow_up_date'], 'date'),
            $editable('kontrol_lokasi',   'Kontrol Di',        ['via' => 'static', 'value' => 'RS Mata Prima Vision'], 'text'),

            // ── Tanda tangan dokter (PIN → stempel elektronik + QR) ──────────
            ['key' => 'ttd_dokter', 'label' => 'Tanda Tangan Dokter', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => true, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  <!-- KOP + IDENTITAS -->
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:60%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:16px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9.5px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9.5px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:40%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 1.7/RMRJ/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10.5px;">
          <tr><td style="padding:2px 5px; width:74px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:14px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:6px 0 0;">RESUME MEDIS RAWAT JALAN</div>

  <!-- META -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:22%;">Tanggal Berobat</td>
      <td style="border:1px solid #333; padding:3px 6px; width:28%;">{{tanggal_berobat}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:22%;">Dokter yang Merawat</td>
      <td style="border:1px solid #333; padding:3px 6px; width:28%;">{{dokter_nama}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Ruang Poli</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{ruang_poli}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Penanggung Pembayaran</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{penanggung}}</td>
    </tr>
  </table>

  <!-- ISI RESUME -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Anamnese</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{anamnese}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pemeriksaan Fisik</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pemeriksaan_fisik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Alergi Obat</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alergi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Hasil Penunjang Medis<br><span style="font-weight:400; font-size:9.5px;">Laboratorium/Radiologi/dll</span></td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{penunjang}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Diagnosa</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Tindakan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{tindakan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Terapi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{terapi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Riwayat/Rawat Inap/<br>Operasi/Tindakan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{riwayat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Instruksi/Anjuran dan<br>Edukasi Lanjutan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{instruksi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Kontrol</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">Tanggal: <strong>{{kontrol_tgl}}</strong> &nbsp;&nbsp; Di: {{kontrol_lokasi}}</td></tr>
  </table>

  <!-- TTD -->
  <table style="width:100%; margin-top:16px; font-size:11px;"><tr>
    <td style="width:58%;"></td>
    <td style="width:42%; text-align:center;">
      <div>Dokter yang Memeriksa,</div>
      <div style="min-height:84px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;"><strong>{{dokter_nama}}</strong></div>
      <div style="font-size:9px; color:#666;">Nama Jelas dan Tandatangan</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('RESUME_MEDIS', [
            'name'                  => 'Resume Medis Rawat Jalan',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'dokter', 'section' => 'resume_output', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * Lembar Klaim (RESUME_KLAIM) — Resume Medis versi KLAIM untuk dokumen
     * pendukung BPJS. Identik layout dengan Resume Medis Rawat Jalan TAPI:
     *   - Diagnosa (ICD-10) & Tindakan (ICD-9) di-bind ke KODING KLAIM
     *     (bpjs_claims, disetel koder), BUKAN doctorExamination → dokumen selalu
     *     konsisten dengan angka grouping INA-CBG.
     *   - Narasi klinis (anamnese/pemeriksaan/penunjang/terapi) tetap dari Resume
     *     Medis dokter (read-only) sebagai dasar pendukung.
     *   - Seluruh field AUTO display-only (di-generate KlaimService, bukan diisi di
     *     stasiun) + TTD dokter wajib → masuk antrian TtdDokumenView otomatis.
     *   - TANPA station_assignments (tak dapat dipilih manual di form picker
     *     stasiun; hanya dibuat via "Buat Lembar Klaim" di Klaim).
     * Resume Medis dokter ASLI tidak disentuh.
     */
    private function seedResumeKlaim(): void
    {
        // Reuse DocumentType RM-6.1 (Resume Medis) — sifat dokumen sama.
        $docType = $this->requireDocType('RM-6.1');
        if (!$docType) return;

        // Field AUTO display-only — resolve di output (render/finalize), tidak ada input.
        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'display_only' => true, 'binding' => $binding,
        ];

        $fields = [
            // ── Kop klinik ───────────────────────────────────────────────────
            $auto('klinik_logo',   'Logo Klinik',  'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 64],
            $auto('klinik_nama',   'Nama Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik','text',      ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.phone']),
            // ── Identitas pasien ─────────────────────────────────────────────
            $auto('nama_pasien',   'Nama Pasien',  'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir','date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',          'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',       'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',          'text', ['kind' => 'db', 'source' => 'patient.nik']),
            $auto('no_bpjs',       'No. BPJS',     'text', ['kind' => 'db', 'source' => 'patient.bpjs_number']),
            // ── Meta kunjungan + SEP ─────────────────────────────────────────
            $auto('tanggal_berobat','Tanggal Berobat','date', ['kind' => 'db', 'source' => 'visit.visit_date']),
            $auto('dokter_nama',   'Dokter yang Merawat', 'text', ['kind' => 'db', 'source' => 'visit.doctorExamination.doctor.name']),
            $auto('ruang_poli',    'Ruang Poli',   'text', ['kind' => 'db', 'source' => 'visit.doctorSchedule.poliklinik']),
            $auto('no_sep',        'No. SEP',      'text', ['kind' => 'db', 'source' => 'visit.no_sep']),

            // ── Isi resume — narasi klinis dari RM dokter (read-only) ─────────
            $auto('anamnese',         'Anamnese',          'longtext', ['kind' => 'db', 'source' => 'doctorExamination.anamnese']),
            $auto('pemeriksaan_fisik','Pemeriksaan Fisik', 'longtext', ['kind' => 'db', 'source' => 'doctorExamination.soap_objective']),
            $auto('alergi',           'Alergi Obat',       'longtext', ['kind' => 'db', 'source' => 'nurseAssessment.allergy_detail']),
            $auto('penunjang',        'Hasil Penunjang',   'longtext', ['kind' => 'aggregate', 'source' => 'diagnosticResults.summary', 'format' => 'summary_per_jenis']),
            // ── Diagnosa & Tindakan — dari KODING KLAIM (koder) ──────────────
            $auto('diagnosa',         'Diagnosa (ICD-10)', 'longtext', ['kind' => 'aggregate', 'source' => 'claim.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            $auto('tindakan',         'Tindakan (ICD-9)',  'longtext', ['kind' => 'aggregate', 'source' => 'claim.icd9_procedures', 'format' => 'icd_with_desc_join_newline']),
            $auto('terapi',           'Terapi',            'longtext', ['kind' => 'aggregate', 'source' => 'prescriptions', 'format' => 'items_pretty']),
            $auto('instruksi',        'Instruksi/Anjuran', 'longtext', ['kind' => 'aggregate', 'source' => 'planning_instruction']),

            // ── Tanda tangan dokter (PIN → stempel elektronik + QR) ──────────
            ['key' => 'ttd_dokter', 'label' => 'Tanda Tangan Dokter', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => true, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  <!-- KOP + IDENTITAS -->
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:60%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:16px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9.5px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9.5px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:40%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 1.7/RMRJ/22 · Lampiran Klaim</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10.5px;">
          <tr><td style="padding:2px 5px; width:74px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">No. BPJS</td><td style="padding:2px 5px;">: {{no_bpjs}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:14px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:6px 0 0;">RESUME MEDIS — LAMPIRAN KLAIM BPJS</div>

  <!-- META -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:22%;">Tanggal Berobat</td>
      <td style="border:1px solid #333; padding:3px 6px; width:28%;">{{tanggal_berobat}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:22%;">Dokter yang Merawat</td>
      <td style="border:1px solid #333; padding:3px 6px; width:28%;">{{dokter_nama}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Ruang Poli</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{ruang_poli}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">No. SEP</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{no_sep}}</td>
    </tr>
  </table>

  <!-- ISI RESUME -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Anamnese</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{anamnese}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pemeriksaan Fisik</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pemeriksaan_fisik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Alergi Obat</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alergi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Hasil Penunjang Medis</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{penunjang}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600; background:#eef5fb;">Diagnosa (Koding Klaim)</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top; background:#eef5fb;">{{diagnosa}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600; background:#eef5fb;">Tindakan (Koding Klaim)</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top; background:#eef5fb;">{{tindakan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Terapi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{terapi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Instruksi/Anjuran</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{instruksi}}</td></tr>
  </table>

  <!-- TTD -->
  <table style="width:100%; margin-top:16px; font-size:11px;"><tr>
    <td style="width:58%; vertical-align:bottom; font-size:9px; color:#888;">Diagnosa &amp; tindakan pada lembar ini mengikuti koding klaim BPJS dan telah disetujui dokter penanggung jawab.</td>
    <td style="width:42%; text-align:center;">
      <div>Dokter yang Memeriksa,</div>
      <div style="min-height:84px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;"><strong>{{dokter_nama}}</strong></div>
      <div style="font-size:9px; color:#666;">Nama Jelas dan Tandatangan</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('RESUME_KLAIM', [
            'name'                  => 'Resume Medis (Lampiran Klaim BPJS)',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_OUTPUT,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            // TANPA station_assignments — hanya dibuat via KlaimService::generateClaimResume.
            'station_assignments'   => null,
        ]);
    }

    /**
     * RM 2.0/CKB/22 — Checklist Kesiapan Bedah (form resmi RS Mata Prima Vision).
     *
     * Slice pertama batch bedah (lihat Docs/KATALOG-FORMULIR-RM.md). Diisi PERAWAT
     * kamar bedah sebagai STEP pra-op di BedahView. kind=HYBRID:
     *   - identitas + kop = AUTO display-only (resolve saat cetak).
     *   - konteks operasi = manual / prefill-editable (diagnosa & tgl dari data klinis).
     *   - 4 multi_checkbox per-seksi (Listrik/Alat/Linen/AKHP) — label seksi = label field.
     *   - 2 TTD perawat (signer_type 'nurse', PIN). FASE TRANSISI: required=false
     *     (belum ada tablet → TTD opsional; finalize boleh tanpa TTD). Saat tablet
     *     siap, balikkan required=true. SKP/PAB tetap menuntut TTD pada akhirnya.
     * Keluaran 100% static_payload (tidak menulis balik ke tabel klinis).
     */
    private function seedChecklistKesiapanBedah(): void
    {
        $docType = $this->requireDocType('RM-2.0');
        if (!$docType) return;

        // (a) AUTO display-only — resolve di output, tidak muncul di input.
        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'display_only' => true, 'binding' => $binding,
        ];
        // (b) EDITABLE prefill — binding static + prefill default dari data klinis.
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        // (c) MANUAL — binding static tanpa prefill (diisi perawat).
        $manual = fn (string $key, string $label, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'],
        ];

        $fields = [
            // ── Kop klinik (auto) ────────────────────────────────────────────
            $auto('klinik_logo',   'Logo Klinik',  'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 64],
            $auto('klinik_nama',   'Nama Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik','text',      ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.phone']),
            // ── Identitas pasien (auto) ──────────────────────────────────────
            $auto('nama_pasien',   'Nama Pasien',  'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir','date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',          'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',       'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',          'text', ['kind' => 'db', 'source' => 'patient.nik']),

            // ── Konteks operasi (manual / prefill-editable) ──────────────────
            $manual('ruang',           'Ruang'),
            $manual('kamar',           'Kamar'),
            $editable('diagnosa',      'Diagnosa', ['via' => 'db', 'source' => 'doctorExamination.diagnosis_utama']),
            $manual('tindakan',        'Tindakan'),
            $manual('tehnik_anastesi', 'Tehnik Anestesi'),
            $editable('tgl_tindakan',  'Tgl. Tindakan', ['via' => 'db', 'source' => 'visit.visit_date'], 'date'),

            // ── Checklist (multi_checkbox; centang item yang sudah siap) ──────
            ['key' => 'cek_listrik', 'label' => 'Listrik', 'type' => 'multi_checkbox', 'binding' => ['kind' => 'static'],
             'options' => [
                 'Mesin Phaco terhubung dengan sumber listrik, indikator (+)',
                 'Mesin anestesi terhubung dengan sumber listrik, indikator (+)',
                 'Light source & monitor mata terhubung dengan sumber listrik, indikator (+)',
                 'Extension kabel terhubung dengan sumber listrik, indikator (+)',
                 'Meja operasi terhubung dengan sumber listrik, indikator (+)',
                 'Mikroskop terhubung dengan sumber listrik, indikator (+)',
                 'Lampu kamar operasi menyala',
                 'AC berfungsi dengan baik',
                 'Gas medis terhubung dengan mesin, indikator (+)',
             ]],
            ['key' => 'cek_alat', 'label' => 'Alat', 'type' => 'multi_checkbox', 'binding' => ['kind' => 'static'],
             'options' => [
                 'Casette, selang, diatermi & konektor mesin Phaco sudah tersedia',
                 'Patient plate sudah tersedia',
                 'Instrument steril sesuai kebutuhan sudah tersedia',
                 'Handle mikroskop steril',
                 'Kom kidney steril sudah tersedia',
             ]],
            ['key' => 'cek_linen', 'label' => 'Linen Steril', 'type' => 'multi_checkbox', 'binding' => ['kind' => 'static'],
             'options' => [
                 'Jas steril',
                 'Duk steril',
                 'Linen meja instrumen',
                 'Kasa',
             ]],
            ['key' => 'cek_akhp', 'label' => 'AKHP', 'type' => 'multi_checkbox', 'binding' => ['kind' => 'static'],
             'options' => [
                 'Tersedia AKHP sesuai kebutuhan',
             ]],

            // ── Pemeriksaan / TTD (FASE TRANSISI: opsional, belum ada tablet) ─
            ['key' => 'ttd_perawat_ok', 'label' => 'Tanda Tangan Perawat Kamar Bedah', 'type' => 'signature_canvas',
             'signer_type' => 'nurse', 'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_kepala_ruangan', 'label' => 'Tanda Tangan Kepala Ruangan', 'type' => 'signature_canvas',
             'signer_type' => 'nurse', 'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  <!-- KOP + IDENTITAS -->
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:60%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:16px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9.5px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9.5px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:40%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 2.0/CKB/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10.5px;">
          <tr><td style="padding:2px 5px; width:74px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:14px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:6px 0 0;">CHECKLIST KESIAPAN BEDAH</div>

  <!-- KONTEKS -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:18%;">Ruang</td>
      <td style="border:1px solid #333; padding:3px 6px; width:32%;">{{ruang}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:18%;">Kamar</td>
      <td style="border:1px solid #333; padding:3px 6px; width:32%;">{{kamar}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Diagnosa</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{diagnosa}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Tindakan</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{tindakan}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Tehnik Anestesi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{tehnik_anastesi}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Tgl. Tindakan</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{tgl_tindakan}}</td>
    </tr>
  </table>

  <!-- CHECKLIST -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:24%; vertical-align:top; font-weight:600;">Listrik</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{cek_listrik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Alat</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{cek_alat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Linen Steril</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{cek_linen}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">AKHP</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{cek_akhp}}</td></tr>
  </table>

  <!-- PEMERIKSAAN / TTD -->
  <table style="width:100%; margin-top:16px; font-size:11px;"><tr>
    <td style="width:50%; text-align:center; vertical-align:top;">
      <div>Perawat Kamar Bedah,</div>
      <div style="min-height:74px; display:flex; align-items:center; justify-content:center;">{{ttd_perawat_ok}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;">Nama Jelas dan Tandatangan</div>
    </td>
    <td style="width:50%; text-align:center; vertical-align:top;">
      <div>Kepala Ruangan,</div>
      <div style="min-height:74px; display:flex; align-items:center; justify-content:center;">{{ttd_kepala_ruangan}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;">Nama Jelas dan Tandatangan</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('CHECKLIST_KESIAPAN_BEDAH', [
            'name'                  => 'Checklist Kesiapan Bedah',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'checklist_kesiapan', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * RM 10.1/LOVR/22 — Laporan Operasi Vitreo Retina. Form resmi RS, hampir
     * seluruhnya checkbox terstruktur khas bedah retina → dimodelkan sebagai
     * rangkaian multi_checkbox + text. HYBRID, 100% static_payload (nol migrasi
     * data form). Muncul KONDISIONAL di BedahView saat paket surgery_type =
     * VITREORETINA. TTD DPJP Bedah opsional (fase transisi belum ada tablet).
     */
    private function seedLaporanOperasiVitreoRetina(): void
    {
        $docType = $this->requireDocType('RM-10.1');
        if (!$docType) return;

        // Helper field-builder (selaras seedChecklistKesiapanBedah).
        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'display_only' => true, 'binding' => $binding,
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $manual = fn (string $key, string $label, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'],
        ];
        $mcheck = fn (string $key, string $label, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox',
            'binding' => ['kind' => 'static'], 'options' => $options,
        ];

        $fields = [
            // ── Kop klinik (auto) ────────────────────────────────────────────
            $auto('klinik_logo',   'Logo Klinik',   'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 60],
            $auto('klinik_nama',   'Nama Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik', 'text', ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.phone']),
            // ── Identitas pasien (auto) ──────────────────────────────────────
            $auto('nama_pasien',   'Nama Pasien',   'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',           'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',        'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',           'text', ['kind' => 'db', 'source' => 'patient.nik']),

            // ── Meta operasi ─────────────────────────────────────────────────
            $editable('tgl_operasi', 'Tanggal Operasi', ['via' => 'db', 'source' => 'visit.visit_date'], 'date'),
            $mcheck('area_operasi', 'Area Operasi', ['OD (Mata Kanan)', 'OS (Mata Kiri)']),
            $editable('dpjp_bedah', 'DPJP Bedah', ['via' => 'db', 'source' => 'visit.doctorExamination.doctor.name']),
            // Identitas operasi auto-prefill dari BedahView (surgery_records) — tak diketik ulang.
            $editable('asisten', 'Asisten', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'asisten']),
            $manual('perawat_instrumen', 'Perawat Instrumen'),
            $editable('jam_mulai', 'Jam Mulai Operasi', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'time_in'], 'time'),
            $editable('jam_selesai', 'Jam Selesai Operasi', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'time_out'], 'time'),
            $editable('diagnosa_pre', 'Diagnosis Pre Operasi', ['via' => 'db', 'source' => 'doctorExamination.diagnosis_utama']),
            $editable('diagnosa_post', 'Diagnosis Post Operasi', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'diagnosis_post']),
            $editable('jenis_tindakan', 'Jenis Tindakan Pembedahan', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'procedure']),
            $mcheck('jenis_anestesi', 'Jenis Anestesi', ['Lokal', 'Anestesi Umum', 'Sedasi', 'Anestesi Blok']),
            $editable('dpjp_anestesi', 'DPJP Anestesi', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'anesthesiologist']),

            // ── Teknik bedah (checkbox terstruktur khas vitreoretina) ────────
            $mcheck('peritomi', 'Peritomi', ['360º', 'Sebagian', 'Tak dilakukan']),
            $mcheck('kendala_otot', 'Kendala Otot', ['4 rektus', 'Rektus superior saja', 'Tak dilakukan']),
            $mcheck('bakel_sklera', 'Bakel Sklera', ['Sirkuler 5 mm', 'Sirkuler 4 mm', 'Sirkuler 2,5 mm', 'Sirkuler 2 mm', 'Sponge', 'Tak dilakukan', 'Ikatan sleeve di NI/NS/TS/TI', 'Ikatan dengan benang di NI/NS/TS/TI']),
            $manual('bakel_tyre', 'Bakel — Tyre/type'),
            $mcheck('jahitan_bakel', 'Jahitan Bakel', ['5,0', '4,0', '6,0', 'Nylon', 'Prolene', 'Vycril']),
            $mcheck('jahitan_skleretomi', 'Jahitan Skleretomi', ['3 lubang', '4 lubang/pindah']),
            $mcheck('kanula', 'Kanula', ['3 mm', '3,5 mm', '4 mm', 'Tak tembus, pindah', 'Ujung kanula tak terlihat (blind)']),
            $mcheck('teknik_operasi', 'Teknik Operasi', [
                'Pneumatic retinopexy', 'Pneumatic dysplacement', 'TPA', 'FGE',
                'Kriopeksi 360º/PCR/cyclocryo', 'ILM peeling', 'SICE', 'Core Vitrectomy',
                'Pewarna membran/vitreus', 'Membrane Peeling', 'Endblock/delaminasi', 'Bersihkan vitreous base',
                'Lensectomy', 'Ekstirpasi IOL', 'Ekstirpasi benda asing', 'AC/Fiksasi skelera',
                'Reposisi IOL', 'Ekstirpasi lensa', 'Tidak dipasang IOL', 'Iridektomi perifer',
                'Evakuasi silicone oil', 'FAKO', 'Injeksi Intravitreal',
            ]),
            $manual('injeksi_lokasi', 'Injeksi Intravitreal — Lokasi'),
            $mcheck('drainase_subretina', 'Drainase Cairan Subretina', ['Dari lubang retina baru', 'Dari robekan yang ada', 'Drainase external']),
            $mcheck('laser', 'Laser', ['Dilakukan', 'EL', 'LIO', 'Tidak dilakukan']),
            $manual('laser_jumlah', 'Laser — Jumlah'),
            $manual('laser_power', 'Laser — Power'),
            $manual('laser_time', 'Laser — Time Exposure'),
            $mcheck('tamponade', 'Tamponade/Intravitreal', ['Cairan', 'Udara steril', 'C3F8 14%', 'SF6 20%', 'F6H8', 'Perfluorocarbon', 'Silicon oil 1000/1300/5000']),
            $manual('tamponade_antibiotik', 'Tamponade — Antibiotik'),
            $manual('tamponade_anti_vegf', 'Tamponade — Anti VEGF'),
            $manual('tamponade_lain', 'Tamponade — Lain-lain'),
            $mcheck('lain_lain_intra', 'Lain-lain', ['Corneal debridement', 'Lensa kontak']),
            $mcheck('akhir_operasi', 'Akhir Operasi', ['Retina melekat sempurna', 'Retina melekat tidak sempurna', 'Sisa cairan sub retina']),
            $mcheck('pemeriksaan_pa', 'Pemeriksaan PA', ['Ya', 'Tidak']),
            $manual('pa_specimen', 'Jenis Specimen PA'),

            // ── Halaman 2 ────────────────────────────────────────────────────
            $mcheck('komplikasi', 'Komplikasi & Penanganan', ['Ya', 'Tidak']),
            $manual('komplikasi_detail', 'Komplikasi — Penjelasan', 'longtext'),
            $manual('gambar_skema', 'Gambar Skema Operasi (deskripsi)', 'longtext'),
            $mcheck('perdarahan', 'Perdarahan', ['Ya', 'Tidak']),
            $manual('perdarahan_cc', 'Jumlah Perdarahan (cc)', 'number'),
            $mcheck('transfusi', 'Transfusi', ['Ya', 'Tidak']),
            $manual('transfusi_cc', 'Jumlah Transfusi (cc)', 'number'),
            $mcheck('tatalaksana_pasca', 'Tatalaksana Pasca Bedah', ['Tidur telungkup 3hr / 10hr / 1bl', 'Tidur biasa', 'Lepas lensa kontak setelah 2 hari']),
            $manual('inst_kontrol', 'Instruksi: Kontrol nadi/tensi/napas/suhu'),
            $manual('inst_puasa', 'Instruksi: Puasa'),
            $manual('inst_drain', 'Instruksi: Drain'),
            $manual('inst_infus', 'Instruksi: Infus'),
            $manual('inst_obat', 'Instruksi: Obat-obatan', 'longtext'),
            $manual('inst_ganti_balut', 'Instruksi: Ganti Balut'),
            $manual('inst_lain', 'Instruksi: Lain-lain'),

            // Stiker Implant — AUTO-isi dari surgery_iol_usage (hasil scan UDI:
            // brand/power + serial/lot/gtin/expiry), menggantikan stiker fisik.
            // Editable: operator dapat tambah implan non-IOL (mis. bakel sklera) atau
            // koreksi. Kosong bila operasi tanpa IOL (vitrektomi murni).
            $editable('stiker_implant', 'Stiker Implant (IOL/implan terpasang)', ['via' => 'aggregate', 'source' => 'surgery_iol_usage'], 'longtext'),

            // TTD DPJP Bedah (OPSIONAL — fase transisi belum ada tablet).
            ['key' => 'ttd_dpjp_bedah', 'label' => 'Tanda Tangan DPJP Bedah', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:10.5px; padding:16px;">
  <!-- KOP + IDENTITAS -->
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:15px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 10.1/LOVR/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
          <tr><td style="padding:2px 5px; width:66px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:13px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:4px 0;">LAPORAN OPERASI VITREO RETINA</div>

  <!-- META -->
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:16%;">Tanggal Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px; width:34%;">{{tgl_operasi}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:16%;">Area Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px; width:34%; white-space:pre-line;">{{area_operasi}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">DPJP Bedah</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{dpjp_bedah}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Asisten</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{asisten}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Jam Mulai</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{jam_mulai}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Jam Selesai</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{jam_selesai}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Perawat Instrumen</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{perawat_instrumen}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">DPJP Anestesi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{dpjp_anestesi}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Diagnosis Pre</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{diagnosa_pre}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Diagnosis Post</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{diagnosa_post}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Jenis Tindakan</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{jenis_tindakan}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Jenis Anestesi</td>
      <td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{jenis_anestesi}}</td>
    </tr>
  </table>

  <!-- TEKNIK -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:10px;">
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600; width:23%;">Peritomi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{peritomi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Kendala Otot</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{kendala_otot}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Bakel Sklera</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{bakel_sklera}}<div style="margin-top:2px; color:#333;">Tyre/type: {{bakel_tyre}}</div></td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Jahitan Bakel</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{jahitan_bakel}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Jahitan Skleretomi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{jahitan_skleretomi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Kanula</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{kanula}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Teknik Operasi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{teknik_operasi}}<div style="margin-top:2px; color:#333;">Injeksi Intravitreal — Lokasi: {{injeksi_lokasi}}</div></td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Drainase Subretina</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{drainase_subretina}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Laser</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{laser}}<div style="margin-top:2px; color:#333;">Jumlah: {{laser_jumlah}} &nbsp;·&nbsp; Power: {{laser_power}} &nbsp;·&nbsp; Time Exposure: {{laser_time}}</div></td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Tamponade / Intravitreal</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{tamponade}}<div style="margin-top:2px; color:#333;">Antibiotik: {{tamponade_antibiotik}} &nbsp;·&nbsp; Anti VEGF: {{tamponade_anti_vegf}} &nbsp;·&nbsp; Lain: {{tamponade_lain}}</div></td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Lain-lain</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{lain_lain_intra}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Akhir Operasi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{akhir_operasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Pemeriksaan PA</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{pemeriksaan_pa}}<div style="margin-top:2px; color:#333;">Jenis specimen: {{pa_specimen}}</div></td></tr>
  </table>

  <!-- HALAMAN 2 -->
  <div style="page-break-before:always; height:6px;"></div>
  <div style="text-align:center; font-weight:700; font-size:12px; border-bottom:1.5px solid #0E3A66; padding:3px 0; margin:4px 0;">LAPORAN OPERASI VITREO RETINA (lanjutan)</div>
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600; width:23%;">Komplikasi & Penanganan</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{komplikasi}}<div style="margin-top:2px;">{{komplikasi_detail}}</div></td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Gambar Skema Operasi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; min-height:60px; white-space:pre-line;">{{gambar_skema}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Perdarahan</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{perdarahan}} &nbsp;&nbsp; Jumlah: {{perdarahan_cc}} cc</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Transfusi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{transfusi}} &nbsp;&nbsp; Jumlah: {{transfusi_cc}} cc</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Tatalaksana Pasca Bedah</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{tatalaksana_pasca}}</td></tr>
  </table>
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:10px;">
    <tr><td style="border:1px solid #333; padding:3px 6px; width:50%;">1. Kontrol nadi/tensi/napas/suhu: {{inst_kontrol}}</td><td style="border:1px solid #333; padding:3px 6px;">5. Obat-obatan: {{inst_obat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">2. Puasa: {{inst_puasa}}</td><td style="border:1px solid #333; padding:3px 6px;">6. Ganti Balut: {{inst_ganti_balut}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">3. Drain: {{inst_drain}}</td><td style="border:1px solid #333; padding:3px 6px;">7. Lain-lain: {{inst_lain}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">4. Infus: {{inst_infus}}</td><td style="border:1px solid #333; padding:3px 6px;"></td></tr>
  </table>

  <!-- STIKER IMPLANT + TTD -->
  <table style="width:100%; margin-top:12px; font-size:10px;"><tr>
    <td style="width:45%; vertical-align:top;">
      <div style="font-weight:600; margin-bottom:3px;">Stiker Implant (data IOL/implan)</div>
      <div style="border:1px solid #333; min-height:80px; padding:5px; white-space:pre-line;">{{stiker_implant}}</div>
    </td>
    <td style="width:10%;"></td>
    <td style="width:45%; text-align:center; vertical-align:top;">
      <div>DPJP Bedah,</div>
      <div style="min-height:74px; display:flex; align-items:center; justify-content:center;">{{ttd_dpjp_bedah}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;">Nama Jelas dan Tandatangan</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('LAPORAN_OPERASI_VITREO_RETINA', [
            'name'                  => 'Laporan Operasi Vitreo Retina',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_vitreoretina', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * RM 2.3/COK/22 — Catatan Operasi (KHAS KATARAK/FAKO). Form resmi RS, checkbox
     * terstruktur (capsulotomy/IOL placement/komplikasi PCR-ECCE). HYBRID, 100%
     * static_payload (NOL migrasi). KONDISIONAL di BedahView saat surgery_type=KATARAK
     * (fallback IOL_RE). IOL terpasang AUTO dari surgery_iol_usage (scan UDI). TTD DPJP
     * opsional. INPUT terstruktur — jadi sumber narasi RM 2.2 (Laporan Pembedahan).
     */
    private function seedCatatanOperasiKatarak(): void
    {
        $docType = $this->requireDocType('RM-2.3-COK');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'display_only' => true, 'binding' => $binding,
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $manual = fn (string $key, string $label, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'],
        ];
        $mcheck = fn (string $key, string $label, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox',
            'binding' => ['kind' => 'static'], 'options' => $options,
        ];

        $fields = [
            // ── Kop + identitas (auto) ───────────────────────────────────────
            $auto('klinik_logo',   'Logo Klinik',   'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 60],
            $auto('klinik_nama',   'Nama Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik', 'text', ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.phone']),
            $auto('nama_pasien',   'Nama Pasien',   'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',           'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',        'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',           'text', ['kind' => 'db', 'source' => 'patient.nik']),

            // ── Meta operasi ─────────────────────────────────────────────────
            $editable('dokter_bedah', 'Dokter Bedah', ['via' => 'db', 'source' => 'visit.doctorExamination.doctor.name']),
            $manual('perawat_scrub', 'Perawat Scrub'),
            $editable('tanggal', 'Tanggal', ['via' => 'db', 'source' => 'visit.visit_date'], 'date'),
            // Identitas operasi auto-prefill dari BedahView (surgery_records).
            $editable('operasi_mulai', 'Operasi Mulai', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'time_in'], 'time'),
            $editable('operasi_selesai', 'Operasi Selesai', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'time_out'], 'time'),
            $editable('dokter_anestesi', 'Dokter Anestesi', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'anesthesiologist']),
            $editable('diagnosa_pra', 'Diagnosis Pra Bedah', ['via' => 'db', 'source' => 'doctorExamination.diagnosis_utama']),
            $editable('diagnosa_pasca', 'Diagnosis Pasca Bedah', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'diagnosis_post']),
            $manual('tindakan_operasi', 'Tindakan Operasi'),

            // ── Checkbox terstruktur khas katarak ────────────────────────────
            $mcheck('anesthesi', 'Anesthesi', ['Topikal', 'Intracamelar', 'Retrobulbar/Peribulbar', 'NU / bius umum', 'Subconjunctival', 'Xylocain', 'Lidocain']),
            $mcheck('insisi', 'Insisi', ['Kornea', 'Limbus', 'Sclera']),
            $mcheck('wound', 'Wound (Tunnel)', ['Main port', 'Two side port', 'One Side port', 'keratome 2.75 mm', 'Crescen knife']),
            $mcheck('capsulotomi', 'Capsulotomi Anterior', ['CCC', "X'mas tree", 'Linear', 'Can Opener', 'Tryphan blue']),
            $mcheck('teknik_tambahan', 'Teknik Tambahan', ['CTR', 'Kapsulotomi posterior', 'Vitrektomi anterior']),
            $mcheck('cairan_irigasi', 'Cairan Irigasi', ['RL', 'B.S.S']),
            $mcheck('lensa_iol', 'Lensa Intra Okular', ['Dalam kantung kapsul', 'Diluar kantung capsul', 'Bilik mata depan', 'Afakia', 'Sulcus siliaris', 'Fiksasi Scleral']),
            $mcheck('viskoelastik', 'Cairan Viskoelastik', ['HPMC', 'Viscoat', 'Hyaluronic acid']),
            $mcheck('benang', 'Benang', ['Tanpa jahitan', 'Ethylon 10-0', 'Vicryl 8-0']),
            $mcheck('komplikasi', 'Komplikasi', ['Tidak ada', 'PCR', 'Prolaps vitreous', 'Drop Nucleus', 'Perdarahan', 'Corneal burn', 'Convert to ECCE', 'Convert to ICCE']),
            $mcheck('perawatan_pasca', 'Perawatan Pasca Operasi', ['Pulang Berobat jalan', 'Opname']),
            $mcheck('instruksi_pasca', 'Instruksi Paska Operasi', ['Perban di buka 2 jam paska operasi', 'Obat mulai di pakai setelah perban di buka', 'Perban dibuka dan ditutup kembali setelah ditetes obat', 'Pantangan sesuai dengan instruksi post operasi']),

            // IOL terpasang AUTO dari surgery_iol_usage (scan UDI) + catatan.
            $editable('iol_terpasang', 'IOL Terpasang (scan UDI)', ['via' => 'aggregate', 'source' => 'surgery_iol_usage'], 'longtext'),
            $manual('catatan_tambahan', 'Catatan Tambahan', 'longtext'),

            // TTD Operator (DPJP Bedah) — opsional (fase transisi).
            ['key' => 'ttd_operator', 'label' => 'Operator (DPJP Bedah)', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:11px; padding:16px;">
  <!-- KOP + IDENTITAS -->
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:15px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 2.3/COK/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
          <tr><td style="padding:2px 5px; width:66px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:13px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:4px 0;">CATATAN OPERASI</div>

  <!-- META -->
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:16%;">Dokter Bedah</td>
      <td style="border:1px solid #333; padding:3px 6px; width:34%;">{{dokter_bedah}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:16%;">Perawat Scrub</td>
      <td style="border:1px solid #333; padding:3px 6px; width:34%;">{{perawat_scrub}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Tanggal</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{tanggal}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Operasi Mulai</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{operasi_mulai}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Dokter Anestesi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{dokter_anestesi}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Operasi Selesai</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{operasi_selesai}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Diagnosis Pra Bedah</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{diagnosa_pra}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Diagnosis Pasca Bedah</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{diagnosa_pasca}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Tindakan Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px;" colspan="3">{{tindakan_operasi}}</td>
    </tr>
  </table>

  <!-- CHECKBOX KATARAK -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:10px;">
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600; width:23%;">Anesthesi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{anesthesi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Insisi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{insisi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Wound (Tunnel)</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{wound}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Capsulotomi Anterior</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{capsulotomi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Teknik Tambahan</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{teknik_tambahan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Cairan Irigasi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{cairan_irigasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Lensa Intra Okular</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{lensa_iol}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Cairan Viskoelastik</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{viskoelastik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Benang</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{benang}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Komplikasi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{komplikasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Perawatan Pasca Operasi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{perawatan_pasca}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Instruksi Paska Operasi</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{instruksi_pasca}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">IOL Terpasang</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{iol_terpasang}}</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Catatan Tambahan</td><td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line; min-height:36px;">{{catatan_tambahan}}</td></tr>
  </table>

  <!-- TTD OPERATOR -->
  <table style="width:100%; margin-top:14px; font-size:11px;"><tr>
    <td style="width:58%;"></td>
    <td style="width:42%; text-align:center; vertical-align:top;">
      <div>Operator,</div>
      <div style="min-height:74px; display:flex; align-items:center; justify-content:center;">{{ttd_operator}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;">(dr. ................................................)</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('CATATAN_OPERASI_KATARAK', [
            'name'                  => 'Catatan Operasi (Katarak)',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'catatan_operasi', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * RM 2.2/LP/22 — Laporan Pembedahan. Laporan operasi GENERIK nasional (Permenkes),
     * berlaku SEMUA jenis operasi (universal). HYBRID, static_payload. Menggantikan
     * RM-5.3/RM_BEDAH_LAPORAN yg dimatikan. "Auto-generate": field teknik_temuan
     * prefill dari operation_report (surgery_operation_summary), AMHP dari IOL scan
     * (surgery_iol_usage). TTD Operator Bedah opsional (fase transisi).
     */
    private function seedLaporanPembedahan(): void
    {
        $docType = $this->requireDocType('RM-2.2-LP');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'display_only' => true, 'binding' => $binding,
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $manual = fn (string $key, string $label, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'],
        ];
        $mcheck = fn (string $key, string $label, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox',
            'binding' => ['kind' => 'static'], 'options' => $options,
        ];

        $fields = [
            // ── Kop + identitas (auto) ───────────────────────────────────────
            $auto('klinik_logo',   'Logo Klinik',   'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 60],
            $auto('klinik_nama',   'Nama Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik', 'text', ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.phone']),
            $auto('nama_pasien',   'Nama Pasien',   'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',           'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',        'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',           'text', ['kind' => 'db', 'source' => 'patient.nik']),

            // ── Meta operasi ─────────────────────────────────────────────────
            $manual('ruang_operasi', 'Ruang Operasi'),
            $manual('kamar', 'Kamar'),
            $mcheck('akut_terencana', 'Akut / Terencana', ['Akut', 'Terencana']),
            $editable('tanggal', 'Tanggal', ['via' => 'db', 'source' => 'visit.visit_date'], 'date'),
            $editable('pembedahan', 'Pembedahan (Operator)', ['via' => 'db', 'source' => 'visit.doctorExamination.doctor.name']),
            // Identitas operasi auto-prefill dari BedahView (surgery_records).
            $editable('ahli_anestesi', 'Ahli Anestesi', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'anesthesiologist']),
            $editable('asisten1', 'Asisten I', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'asisten1']),
            $editable('asisten2', 'Asisten II', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'asisten2']),
            $manual('perawat_instrument', 'Perawat Instrument'),
            $mcheck('jenis_anestesi', 'Jenis Anestesi', ['Umum', 'BSP', 'Spiral', 'CSP', 'Epidural', 'Lokal']),
            $editable('diagnosa_pra', 'Diagnosa Pra-Bedah', ['via' => 'db', 'source' => 'doctorExamination.diagnosis_utama']),
            $manual('indikasi_operasi', 'Indikasi Operasi'),
            $editable('diagnosa_pasca', 'Diagnosa Pasca-Bedah', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'diagnosis_post']),
            $editable('jenis_operasi', 'Jenis Operasi', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'procedure']),
            $manual('desinfeksi_kulit', 'Desinfeksi Kulit dengan'),
            $manual('posisi_penderita', 'Posisi Penderita'),
            $editable('jam_mulai', 'Jam Operasi Dimulai', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'time_in'], 'time'),
            $editable('jam_selesai', 'Jam Operasi Selesai', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'time_out'], 'time'),
            $editable('lama_operasi', 'Lama Operasi Berlangsung', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'duration']),
            $manual('bahan_lab', 'Jenis Bahan ke Laboratorium'),
            $manual('macam_sayatan', 'Macam Sayatan'),

            // Teknik & temuan — AUTO-generate dari operation_report.
            $editable('teknik_temuan', 'Teknik Operasi dan Temuan Intra/Operasi', ['via' => 'aggregate', 'source' => 'surgery_operation_summary'], 'longtext'),

            // AMHP Khusus — IOL auto dari scan UDI.
            $mcheck('amhp_khusus', 'Penggunaan AMHP Khusus', ['Ya', 'Tidak']),
            $editable('amhp_jenis_jumlah', 'Jenis dan Jumlah AMHP Khusus', ['via' => 'aggregate', 'source' => 'surgery_iol_usage'], 'longtext'),

            // Komplikasi + perdarahan + instruksi.
            $mcheck('komplikasi', 'Komplikasi Intra-operasi', ['Ya', 'Tidak']),
            $manual('komplikasi_detail', 'Penjabaran Komplikasi', 'longtext'),
            $manual('perdarahan_cc', 'Perdarahan (cc)', 'number'),
            $manual('instruksi_anestesi', 'Instruksi Anestesi', 'longtext'),
            $manual('inst_kontrol', 'Instruksi: Kontrol nadi/tensi/pernapasan/suhu'),
            $manual('inst_puasa', 'Instruksi: Puasa'),
            $manual('inst_drain', 'Instruksi: Drain'),
            $manual('inst_infus', 'Instruksi: Infus'),
            $manual('inst_obat', 'Instruksi: Obat-obatan', 'longtext'),
            $manual('inst_ganti_balut', 'Instruksi: Ganti Balut'),
            $manual('inst_lain', 'Instruksi: Lain-lain'),

            // TTD Operator Bedah (opsional).
            $manual('tempat_tanggal', 'Tempat, Tanggal'),
            ['key' => 'ttd_operator', 'label' => 'Operator Bedah', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:10.5px; padding:16px;">
  <!-- KOP + IDENTITAS -->
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:15px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 2.2/LP/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
          <tr><td style="padding:2px 5px; width:66px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:13px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:4px 0;">LAPORAN PEMBEDAHAN</div>

  <!-- META -->
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:18%;">Ruang Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px; width:32%;">{{ruang_operasi}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:18%;">Kamar</td>
      <td style="border:1px solid #333; padding:3px 6px; width:32%;">{{kamar}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Akut / Terencana</td>
      <td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{akut_terencana}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Tanggal</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{tanggal}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Pembedahan</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{pembedahan}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Ahli Anestesi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{ahli_anestesi}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Asisten I</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{asisten1}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Asisten II</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{asisten2}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Perawat Instrument</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{perawat_instrument}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Jenis Anestesi</td>
      <td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{jenis_anestesi}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Diagnosa Pra-Bedah</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{diagnosa_pra}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Indikasi Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{indikasi_operasi}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Diagnosa Pasca-Bedah</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{diagnosa_pasca}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Jenis Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{jenis_operasi}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Desinfeksi Kulit</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{desinfeksi_kulit}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Posisi Penderita</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{posisi_penderita}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Jam Dimulai</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{jam_mulai}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Jam Selesai</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{jam_selesai}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Lama Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{lama_operasi}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Bahan ke Lab</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{bahan_lab}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Macam Sayatan</td>
      <td style="border:1px solid #333; padding:3px 6px;" colspan="3">{{macam_sayatan}}</td>
    </tr>
  </table>

  <!-- TEKNIK & TEMUAN -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:10px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; font-weight:600;">Teknik Operasi dan Temuan Intra/Operasi</td></tr>
    <tr><td style="border:1px solid #333; padding:6px; white-space:pre-line; min-height:70px; vertical-align:top;">{{teknik_temuan}}</td></tr>
  </table>

  <!-- HALAMAN 2 -->
  <div style="page-break-before:always; height:6px;"></div>
  <div style="text-align:center; font-weight:700; font-size:12px; border-bottom:1.5px solid #0E3A66; padding:3px 0; margin:4px 0;">LAPORAN PEMBEDAHAN (lanjutan)</div>
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
    <tr>
      <td style="border:1px solid #333; padding:4px 6px; width:23%; vertical-align:top; font-weight:600;">Penggunaan AMHP Khusus</td>
      <td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{amhp_khusus}}<div style="margin-top:2px;">Jenis &amp; Jumlah: {{amhp_jenis_jumlah}}</div></td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Komplikasi Intra-operasi</td>
      <td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{komplikasi}}<div style="margin-top:2px;">{{komplikasi_detail}}</div><div>Perdarahan: {{perdarahan_cc}} cc</div></td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:4px 6px; vertical-align:top; font-weight:600;">Instruksi Anestesi</td>
      <td style="border:1px solid #333; padding:4px 6px; vertical-align:top; white-space:pre-line;">{{instruksi_anestesi}}</td>
    </tr>
  </table>
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:10px;">
    <tr><td style="border:1px solid #333; padding:3px 6px; width:50%;">1. Kontrol nadi/tensi/napas/suhu: {{inst_kontrol}}</td><td style="border:1px solid #333; padding:3px 6px;">5. Obat-obatan: {{inst_obat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">2. Puasa: {{inst_puasa}}</td><td style="border:1px solid #333; padding:3px 6px;">6. Ganti Balut: {{inst_ganti_balut}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">3. Drain: {{inst_drain}}</td><td style="border:1px solid #333; padding:3px 6px;">7. Lain-lain: {{inst_lain}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">4. Infus: {{inst_infus}}</td><td style="border:1px solid #333; padding:3px 6px;"></td></tr>
  </table>

  <!-- TTD -->
  <table style="width:100%; margin-top:14px; font-size:11px;"><tr>
    <td style="width:58%;"></td>
    <td style="width:42%; text-align:center; vertical-align:top;">
      <div>{{tempat_tanggal}}</div>
      <div>Operator Bedah,</div>
      <div style="min-height:74px; display:flex; align-items:center; justify-content:center;">{{ttd_operator}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;">Tanda Tangan dan Nama Jelas</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('LAPORAN_PEMBEDAHAN', [
            'name'                  => 'Laporan Pembedahan',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_pembedahan', 'mode' => 'HYBRID'],
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
