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
        // Fase 1 — laporan operasi subspesialis (section terpadu laporan_operasi).
        $this->seedLaporanTrabekulektomi();  // RM 8.10/LOT/22 — GLAUKOMA
        $this->seedLaporanPterygium();       // RM 9.0/LOP/22  — PTERYGIUM
        $this->seedLaporanInjeksiAntiVegf(); // RM 8.8/LIAV/22 — INJEKSI
        // Fase 2 — anestesi (INPUT; section laporan_operasi, soft requires_anesthesia).
        $this->seedPenilaianPraAnestesi();   // RM 4.4/PPA/22 — penilaian pra-anestesi
        $this->seedPersetujuanAnestesi();    // RM 4.3/PTA/22 — consent anestesi
        // Fase 3 — safety & peri-operatif (section checklist_kesiapan, modal Pra-Bedah).
        $this->seedWhoSafetyChecklist();     // RM 4.9/CLKPO/22 — WHO SSC (reuse data, K6)
        $this->seedSiteMarking();            // RM 1.9/SM(PO)/22 — penandaan operasi (SKP 4)
        $this->seedProsesPeriOperatif();     // RM 1.10/PPPO/22 — proses peri-operatif (perawat)
        // RM 3.5/LB/22 — Resume Medis Bedah (ringkasan pulang pasien BEDAH rawat jalan /
        // day-surgery). Menggantikan RM 1.7 untuk pasien bedah; draft dibuat otomatis saat
        // finalisasi laporan operasi (BedahService::finalizeRecord). Section laporan_operasi.
        $this->seedResumeMedisBedah();

        // ── RANAP (Rawat Inap) — Phase 1, 3 form nakes-only (TTD pasien ditunda
        // sampai PSrE). Pola HYBRID auto-fill seperti Resume RJ; field ber-`group`
        // untuk UX accordion FormRMRenderer. Lihat plan resilient-singing-pearl.
        $this->seedResumeMedisRanap();      // RM 3.5/RI — Resume Medis Rawat Inap (DPJP, auto saat discharge)
        $this->seedPengkajianAwalMedis();   // RM 7.7/PAM — Pengkajian Awal Medis (DPJP ≤24 jam)
        $this->seedAsesmenAwalKeperawatan();// RM 7.8/AAKRI — Asesmen Awal Keperawatan (perawat, Norton+MST)
        // RANAP Phase 2 (Tier 2 — keselamatan/kepatuhan akreditasi; perawat/farmasi).
        $this->seedPencegahanJatuh();       // RM 2.9/JTH — Pencegahan Pasien Jatuh (SKP 6)
        $this->seedEdukasiTerintegrasi();   // RM 2.4/EDU — Edukasi Terintegrasi (MKE)
        $this->seedRekonsiliasiObat();      // RM 2.7/REK — Rekonsiliasi Obat (PKPO/SKP 3)
        // RANAP Phase 3 (Tier 3 — ARK akses & kontinuitas; dokter/perawat).
        $this->seedSuratPengantarDirawat(); // RM 2.5/SPD — Surat Pengantar Untuk Dirawat Inap
        $this->seedTransferPasien();        // RM 2.6/TRF — Transfer Pasien antar ruang/unit

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
            // Anamnese = anamnesa dokter + segmen mata anterior/posterior (soap_objective).
            $editable('anamnese',         'Anamnese',          ['via' => 'aggregate', 'source' => 'anamnese_full']),
            // Pemeriksaan Fisik = TTV triase + O Refraksionis (RO, soap_o). Segmen dokter pindah ke Anamnese.
            $editable('pemeriksaan_fisik','Pemeriksaan Fisik', ['via' => 'aggregate', 'source' => 'physical_exam']),
            // Alergi: detail alergi triase → catatan alergi master pasien → "Tidak Ada".
            $editable('alergi',           'Alergi Obat',       ['via' => 'aggregate', 'source' => 'allergy']),
            // Hasil Penunjang = HANYA kode ICD-9 penunjang (kode+nama) dari Tab 2 dokter.
            $editable('penunjang',        'Hasil Penunjang Medis (Lab/Radiologi/dll)', ['via' => 'aggregate', 'source' => 'penunjang_rmrj', 'format' => 'icd_with_desc_join_newline']),
            // Diagnosa = kode+nama ICD-10 + teks diagnosa bebas (diagnosis_text).
            $editable('diagnosa',         'Diagnosa (ICD-10)', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            // Tindakan = kode+nama ICD-9 + "Visus, Tonometri, Autorefkeratometri, Slit Lamp" (auto-tulis).
            $editable('tindakan',         'Tindakan (ICD-9)',  ['via' => 'aggregate', 'source' => 'tindakan_rmrj', 'format' => 'icd_with_desc_join_newline']),
            $editable('terapi',           'Terapi',            ['via' => 'aggregate', 'source' => 'prescriptions', 'format' => 'items_pretty']),
            // Riwayat = Riwayat Penyakit Sekarang (RPS) dari triase perawat.
            $editable('riwayat',          'Riwayat/Rawat Inap/Operasi/Tindakan', ['via' => 'db', 'source' => 'nurseAssessment.rps']),
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
     * RM 3.5/LB/22 — Resume Medis BEDAH (ringkasan pulang pasien bedah rawat jalan /
     * day-surgery). MENGGANTIKAN RM 1.7 (RESUME_MEDIS) untuk pasien bedah: rajal-bedah
     * tidak lagi dapat resume rawat jalan generik, tetapi resume khusus bedah yang
     * memuat identitas operasi (operator/jenis/mata/anestesi/durasi), tindakan ICD-9,
     * IOL/implan terpasang (scan UDI), dan terapi pulang. Semua aggregate sudah ada
     * (surgery_identity, surgery_iol_usage, doctorExamination.icd9_procedures, dst.).
     *
     * Station bedah:laporan_operasi → muncul di tab "Laporan" BedahView (FormDocsBrowser).
     * Draft dibuat otomatis saat finalisasi laporan operasi (BedahService::finalizeRecord)
     * untuk visit RAJAL. Untuk pasien rawat inap-bedah dipakai RESUME_MEDIS_RANAP
     * (dokumen pulang ranap yang diwajibkan regulasi; detail operasi di Laporan Operasi).
     */
    private function seedResumeMedisBedah(): void
    {
        $docType = $this->requireDocType('RM-3.5-LB');
        if (!$docType) return;

        // Field AUTO display-only (resolve di output, tak muncul di input).
        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'display_only' => true, 'binding' => $binding,
        ];
        // Field EDITABLE — binding 'static' (tersimpan ke dokumen) + prefill.
        $editable = fn (string $key, string $label, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        // Field MANUAL — editable tanpa prefill (diisi operator; tak ada sumber otomatis).
        $manual = fn (string $key, string $label, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'],
        ];

        // Struktur MENGIKUTI form resmi "RM 3.5 RESUME MEDIS" (FORMULIR BEDAH):
        // identitas → meta perawatan/operasi → diagnosa → pemeriksaan & penunjang →
        // tindakan/operasi → pengobatan → kondisi pulang → instruksi → terapi pulang.
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

            // ── Meta perawatan/operasi (header form RM 3.5) ──────────────────
            // Day-surgery: Tgl Masuk ≈ Tgl Keluar = tanggal operasi (editable bila beda).
            $auto('tgl_masuk',     'Tanggal Masuk', 'text', ['kind' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'operation_date']),
            $editable('tgl_keluar','Tanggal Keluar / Tanggal Meninggal', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'operation_date'], 'text'),
            $manual('ruang_rawat', 'Ruang Rawat Terakhir', 'text'),
            $auto('penanggung',    'Penanggung Pembayaran', 'text', ['kind' => 'db', 'source' => 'visit.guarantor_type']),
            $auto('dpjp_nama',     'Dokter Penanggung Jawab (DPJP)', 'text', ['kind' => 'db', 'source' => 'visit.doctorExamination.doctor.name']),
            // Rawat Tim Dokter = operator + asisten dari laporan operasi BedahView.
            $editable('tim_dokter','Rawat Tim Dokter', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'team'], 'text'),

            // ── Diagnosa ─────────────────────────────────────────────────────
            $manual('alasan_dirawat',   'Alasan Dirawat'),
            $editable('diagnosa_masuk', 'Diagnosa Masuk', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            $editable('diagnosa_keluar','Diagnosa Keluar (Diagnosa Utama + Sekunder, ICD-10)', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            $manual('penyebab_kematian','Penyebab Kematian (Secara Klinis)'),

            // ── Pemeriksaan & Penunjang ──────────────────────────────────────
            // Pemeriksaan Fisik penting bedah: TD+KGD triase & Visus+IOP refraksi
            // dari data TERAKHIR pasien (ringkas, bukan TTV/RO lengkap).
            $editable('pemeriksaan_fisik','Pemeriksaan Fisik yang Penting', ['via' => 'aggregate', 'source' => 'physical_exam_bedah']),
            $manual('laboratorium',    'Laboratorium yang Penting'),
            $manual('radiologi',       'Radiologi'),
            $editable('penunjang_lain','Penunjang Lain', ['via' => 'aggregate', 'source' => 'diagnosticResults.summary', 'format' => 'summary_per_jenis']),

            // ── Tindakan / Operasi & Pengobatan ──────────────────────────────
            // Tindakan/Operasi = ICD-9 prosedur + IOL/implan terpasang (scan UDI).
            $editable('tindakan_operasi','Tindakan / Operasi (ICD-9)', ['via' => 'aggregate', 'source' => 'surgery_tindakan', 'format' => 'icd_with_desc_join_newline']),
            $manual('pengobatan_dirawat','Pengobatan Selama Dirawat'),

            // ── Kondisi Pulang & Tindak Lanjut ───────────────────────────────
            $editable('kondisi_pulang','Kondisi Pulang', ['via' => 'static', 'value' => 'Sembuh / Membaik — boleh pulang (berobat jalan)'], 'text'),
            // Instruksi pasca-op DIISI MANUAL — planning_instruction berisi rencana
            // PRA-operasi ("Rencana operasi ... tanggal ...") yang tak relevan utk
            // resume pulang. Operator menulis edukasi/instruksi pasca-bedah sendiri.
            $manual('instruksi',       'Instruksi dan Edukasi Lanjutan (follow up)'),
            $editable('kontrol_tgl',   'Kontrol Tanggal', ['via' => 'db', 'source' => 'visit.follow_up_date'], 'date'),
            $manual('diet',            'Diet', 'text'),
            $manual('latihan',         'Latihan', 'text'),
            $manual('tanda_bahaya',    'Segera Kembali ke RS / IGD bila Terjadi'),

            // ── Terapi Pulang ────────────────────────────────────────────────
            // Sumber = resep PASCA-BEDAH (BedahView), BUKAN resep dokter Tab 3.
            $editable('terapi_pulang', 'Terapi Pulang (Obat)', ['via' => 'aggregate', 'source' => 'prescriptions_post_op', 'format' => 'items_pretty']),

            // ── Tanda tangan dokter/operator (PIN → stempel elektronik + QR) ──
            ['key' => 'ttd_dokter', 'label' => 'Tanda Tangan Dokter/Operator', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => true, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  <!-- KOP + IDENTITAS -->
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:16px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9.5px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9.5px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 3.5/LB/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10.5px;">
          <tr><td style="padding:2px 5px; width:74px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:14px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:6px 0 0;">RESUME MEDIS BEDAH</div>

  <!-- META PERAWATAN / OPERASI -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:22%;">Tanggal Masuk</td>
      <td style="border:1px solid #333; padding:3px 6px; width:28%;">{{tgl_masuk}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:22%;">Tanggal Keluar / Meninggal</td>
      <td style="border:1px solid #333; padding:3px 6px; width:28%;">{{tgl_keluar}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Ruang Rawat Terakhir</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{ruang_rawat}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Penanggung Pembayaran</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{penanggung}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Dokter Penanggung Jawab (DPJP)</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{dpjp_nama}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Rawat Tim Dokter</td>
      <td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{tim_dokter}}</td>
    </tr>
  </table>

  <!-- ISI RESUME -->
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Alasan Dirawat</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alasan_dirawat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Diagnosa Masuk</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_masuk}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Diagnosa Keluar<br><span style="font-weight:400; font-size:9.5px;">(Diagnosa Utama)</span></td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_keluar}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Penyebab Kematian<br><span style="font-weight:400; font-size:9.5px;">(Secara Klinis)</span></td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{penyebab_kematian}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pemeriksaan Fisik yang Penting</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pemeriksaan_fisik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Laboratorium yang Penting</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{laboratorium}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Radiologi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{radiologi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Penunjang Lain</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{penunjang_lain}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Tindakan / Operasi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{tindakan_operasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pengobatan Selama Dirawat</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pengobatan_dirawat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Kondisi Pulang</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{kondisi_pulang}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Instruksi &amp; Edukasi<br>Lanjutan (follow up)</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{instruksi}}<br>Kontrol Tanggal: <strong>{{kontrol_tgl}}</strong><br>Diet: {{diet}}<br>Latihan: {{latihan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Segera kembali ke RS / IGD<br>bila terjadi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{tanda_bahaya}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Terapi Pulang</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{terapi_pulang}}</td></tr>
  </table>

  <!-- TTD -->
  <table style="width:100%; margin-top:16px; font-size:11px;"><tr>
    <td style="width:58%;"></td>
    <td style="width:42%; text-align:center;">
      <div>Yang Membuat (Dokter/Operator),</div>
      <div style="min-height:84px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;"><strong>{{dpjp_nama}}</strong></div>
      <div style="font-size:9px; color:#666;">Nama Jelas dan Tandatangan</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('RESUME_MEDIS_BEDAH', [
            'name'                  => 'Resume Medis Bedah',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID'],
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
            // Section terpadu `laporan_operasi` (picker search BedahView). `conditions.
            // report_type` → di-badge "Disarankan" saat operator konfirmasi jenis VR;
            // soft (tetap muncul & dapat dipilih untuk kasus gabungan).
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID',
                 'conditions' => ['report_type' => ['VITREORETINA']]],
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
            // Section terpadu `laporan_operasi`; disarankan saat jenis = KATARAK.
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID',
                 'conditions' => ['report_type' => ['KATARAK']]],
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
            // Section terpadu `laporan_operasi`. Laporan GENERIK: tanpa `conditions`
            // → SELALU 'recommended' (fallback semua jenis operasi).
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * Kerangka bersama laporan operasi subspesialis (Fase 1) — RM 8.10/9.0/8.8.
     * Identitas/tim/jam/diagnosis/anestesi PREFILL dari operation_report (binding
     * surgery_identity) → "isi sekali di BedahView → proyeksi" (tak diketik ulang).
     * SOP = boilerplate display-only (static layout). $opts: rm_number, title,
     * sop_html, ttd_mode ('perawat_operator'|'dpjp'), extra_fields[].
     *
     * @return array{fields: array, layout: string}
     */
    private function buildSurgeryReportTemplate(array $opts): array
    {
        $rmNumber = $opts['rm_number'];
        $title    = $opts['title'];
        $sopHtml  = $opts['sop_html'];
        $ttdMode  = $opts['ttd_mode'] ?? 'perawat_operator';
        $extra    = $opts['extra_fields'] ?? [];

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'display_only' => true, 'binding' => $binding,
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $sid = fn (string $fmt) => ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => $fmt];

        $fields = [
            $auto('klinik_logo',   'Logo Klinik',   'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 60],
            $auto('klinik_nama',   'Nama Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik', 'text', ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.phone']),
            $auto('nama_pasien',   'Nama Pasien',   'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',           'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',        'text', ['kind' => 'db', 'source' => 'patient.no_rm']),

            // Meta operasi — prefill dari BedahView (surgery_identity), tetap editable.
            $editable('tgl_operasi', 'Tgl. Operasi', ['via' => 'db', 'source' => 'visit.visit_date'], 'date'),
            $editable('mata', 'Mata (OD/OS)', $sid('operative_eye')),
            $editable('operator', 'Operator', $sid('operator')),
            $editable('jam_operasi', 'Jam Operasi', $sid('time_in'), 'time'),
            $editable('lama_operasi', 'Lama Operasi', $sid('duration')),
            $editable('diagnosis', 'Diagnosis', $sid('diagnosis_post'), 'longtext'),
            $editable('asisten', 'Asisten', $sid('asisten')),
            $editable('jenis_operasi', 'Jenis Operasi', $sid('procedure')),
            $editable('anesthesia', 'Anesthesia', $sid('anesthesia_type')),
            $editable('anesthesiologist', 'Anesthesiologist', $sid('anesthesiologist')),
        ];
        foreach ($extra as $f) {
            $fields[] = $f;
        }

        if ($ttdMode === 'dpjp') {
            $fields[] = ['key' => 'ttd_dpjp', 'label' => 'DPJP / Dokter', 'type' => 'signature_canvas',
                'signer_type' => 'doctor', 'required' => false, 'binding' => ['kind' => 'static']];
            $ttdHtml = <<<'HTML'
<table style="width:100%; margin-top:14px; font-size:11px;"><tr>
  <td style="width:58%;"></td>
  <td style="width:42%; text-align:center; vertical-align:top;">
    <div>Tanda Tangan DPJP / Dokter</div>
    <div style="min-height:74px; display:flex; align-items:center; justify-content:center;">{{ttd_dpjp}}</div>
    <div style="border-top:1px solid #333; padding-top:3px; margin:0 24px;">Nama Jelas</div>
  </td>
</tr></table>
HTML;
        } else {
            $fields[] = ['key' => 'ttd_perawat', 'label' => 'Perawat', 'type' => 'signature_canvas',
                'signer_type' => 'nurse', 'required' => false, 'binding' => ['kind' => 'static']];
            $fields[] = ['key' => 'ttd_operator', 'label' => 'Operator', 'type' => 'signature_canvas',
                'signer_type' => 'doctor', 'required' => false, 'binding' => ['kind' => 'static']];
            $ttdHtml = <<<'HTML'
<table style="width:100%; margin-top:14px; font-size:11px; text-align:center;"><tr>
  <td style="width:50%; vertical-align:top;">
    <div>Perawat</div>
    <div style="min-height:74px; display:flex; align-items:center; justify-content:center;">{{ttd_perawat}}</div>
    <div style="border-top:1px solid #333; padding-top:3px; margin:0 24px;">Nama Jelas</div>
  </td>
  <td style="width:50%; vertical-align:top;">
    <div>Operator</div>
    <div style="min-height:74px; display:flex; align-items:center; justify-content:center;">{{ttd_operator}}</div>
    <div style="border-top:1px solid #333; padding-top:3px; margin:0 24px;">Nama Jelas</div>
  </td>
</tr></table>
HTML;
        }

        $layout = <<<HTML
<div style="font-family: Arial, sans-serif; color:#111; font-size:10.5px; padding:16px;">
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
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">$rmNumber</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
          <tr><td style="padding:2px 5px; width:66px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:13px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:4px 0;">$title</div>

  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:20%;">Tgl. Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px; width:30%;">{{tgl_operasi}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:20%;">Mata</td>
      <td style="border:1px solid #333; padding:3px 6px; width:30%;">{{mata}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Operator</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{operator}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Jam Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{jam_operasi}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Lama Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{lama_operasi}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Jenis Operasi</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{jenis_operasi}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Diagnosis</td>
      <td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;" colspan="3">{{diagnosis}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Asisten</td>
      <td style="border:1px solid #333; padding:3px 6px;" colspan="3">{{asisten}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Anesthesia</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{anesthesia}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Anesthesiologist</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{anesthesiologist}}</td>
    </tr>
  </table>

  <div style="border:1px solid #333; border-top:none; padding:6px 10px; font-size:10px;">
    $sopHtml
  </div>

  $ttdHtml
</div>
HTML;

        return ['fields' => $fields, 'layout' => $layout];
    }

    /**
     * RM 8.10/LOT/22 — Laporan Operasi Trabekulektomi (GLAUKOMA). HYBRID; identitas
     * & tim prefill dari BedahView. SOP boilerplate display-only. TTD perawat+operator.
     */
    private function seedLaporanTrabekulektomi(): void
    {
        $docType = $this->requireDocType('RM-8.10-LOT');
        if (!$docType) return;

        $sop = <<<'HTML'
<div style="font-weight:600; margin-bottom:3px;">Teknik Operasi:</div>
<ol style="margin:0; padding-left:18px; line-height:1.5;">
  <li>Pasien dalam posisi supine di tempat tidur</li>
  <li>Teknik aseptik &amp; antiseptik</li>
  <li>Pasang drape dan spekulum</li>
  <li>Dilakukan anestesi subkonjungtiva</li>
  <li>Peritomi konjungtiva superior, kemudian dibuat flap sklera</li>
  <li>Buat insisi berbentuk jendela antara sklera dan kornea</li>
  <li>Dilakukan iridektomi, flap sklera dijahit, lalu dilakukan penjahitan konjungtiva</li>
  <li>Injeksi antibiotik (Gentamycin), Dexamethason, dan salep</li>
  <li>Operasi selesai</li>
</ol>
HTML;

        $tpl = $this->buildSurgeryReportTemplate([
            'rm_number' => 'RM 8.10/LOT/22',
            'title'     => 'LAPORAN OPERASI TRABEKULEKTOMI',
            'sop_html'  => $sop,
            'ttd_mode'  => 'perawat_operator',
        ]);

        $this->upsert('LAPORAN_TRABEKULEKTOMI', [
            'name'                  => 'Laporan Operasi Trabekulektomi',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $tpl['layout'],
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $tpl['fields']],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID',
                 'conditions' => ['report_type' => ['GLAUKOMA']]],
            ],
        ]);
    }

    /**
     * RM 9.0/LOP/22 — Laporan Operasi Pterygium (PTERYGIUM). HYBRID; prefill BedahView.
     */
    private function seedLaporanPterygium(): void
    {
        $docType = $this->requireDocType('RM-9.0-LOP');
        if (!$docType) return;

        $sop = <<<'HTML'
<div style="font-weight:600; margin-bottom:3px;">Teknik Operasi:</div>
<ol style="margin:0; padding-left:18px; line-height:1.5;">
  <li>Pasien dalam posisi supine di tempat tidur dan anestesi parabulbar</li>
  <li>Teknik aseptik &amp; antiseptik</li>
  <li>Tutup duk lubang steril</li>
  <li>Pasang blefarostat</li>
  <li>Injeksi Lidocain pada caput dan corpus pterygium</li>
  <li>Pisahkan dari epitel kornea hingga bersih</li>
  <li>Atasi perdarahan</li>
  <li>Gunting corpus pterygium</li>
  <li>Buat graft dari konjungtiva bagian superior</li>
  <li>Geser ke medial, jahit tepinya</li>
  <li>Salep antibiotik</li>
  <li>Operasi selesai</li>
</ol>
HTML;

        $tpl = $this->buildSurgeryReportTemplate([
            'rm_number' => 'RM 9.0/LOP/22',
            'title'     => 'LAPORAN OPERASI PTERYGIUM',
            'sop_html'  => $sop,
            'ttd_mode'  => 'perawat_operator',
        ]);

        $this->upsert('LAPORAN_PTERYGIUM', [
            'name'                  => 'Laporan Operasi Pterygium',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $tpl['layout'],
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $tpl['fields']],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID',
                 'conditions' => ['report_type' => ['PTERYGIUM']]],
            ],
        ]);
    }

    /**
     * RM 8.8/LIAV/22 — Laporan Injeksi Anti VEGF (INJEKSI). HYBRID. Field isian khusus
     * (obat/volume/kuadran/jarak limbus) prefill dari operation_report.injection_detail
     * (diisi sekali di tab Intraoperatif BedahView). TTD DPJP/Dokter.
     */
    private function seedLaporanInjeksiAntiVegf(): void
    {
        $docType = $this->requireDocType('RM-8.8-LIAV');
        if (!$docType) return;

        // Field isian khusus injeksi (editable, prefill dari surgery_identity binding).
        $inj = fn (string $key, string $label, string $fmt) => [
            'key' => $key, 'label' => $label, 'type' => 'text',
            'binding' => ['kind' => 'static'],
            'prefill' => ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => $fmt],
        ];
        $extra = [
            $inj('injection_drug', 'Obat Anti-VEGF', 'injection_drug'),
            $inj('injection_volume', 'Volume (ml)', 'injection_volume'),
            $inj('injection_quadrant', 'Kuadran', 'injection_quadrant'),
            $inj('injection_distance', 'Jarak dari Limbus (mm)', 'injection_distance'),
        ];

        $sop = <<<'HTML'
<div style="font-weight:600; margin-bottom:3px;">Teknik Tindakan:</div>
<ol style="margin:0; padding-left:18px; line-height:1.5;">
  <li>Pasien berbaring dalam anestesi {{anesthesia}}</li>
  <li>Tindakan aseptik &amp; antiseptik menggunakan povidone iodine</li>
  <li>Dipasangkan eye drape</li>
  <li>Dipasangkan blefarostat</li>
  <li>Pengukuran menggunakan caliper/trocar dengan jarak {{injection_distance}} mm dari limbus di kuadran {{injection_quadrant}}</li>
  <li>Dilakukan injeksi {{injection_drug}} intravitreal sebanyak {{injection_volume}} ml</li>
  <li>Diteteskan antibiotik</li>
  <li>Mata ditutup kassa &amp; dop</li>
  <li>Tindakan selesai</li>
</ol>
HTML;

        $tpl = $this->buildSurgeryReportTemplate([
            'rm_number'    => 'RM 8.8/LIAV/22',
            'title'        => 'LAPORAN INJEKSI ANTI VEGF',
            'sop_html'     => $sop,
            'ttd_mode'     => 'dpjp',
            'extra_fields' => $extra,
        ]);

        $this->upsert('LAPORAN_INJEKSI_ANTIVEGF', [
            'name'                  => 'Laporan Injeksi Anti VEGF',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $tpl['layout'],
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $tpl['fields']],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID',
                 'conditions' => ['report_type' => ['INJEKSI']]],
            ],
        ]);
    }

    /**
     * RM 4.4/PPA/22 — Penilaian Pra Anestesi & Sedasi (diisi pasien + dokter anestesi).
     * INPUT (HYBRID: identitas auto). Section laporan_operasi, soft requires_anesthesia.
     */
    private function seedPenilaianPraAnestesi(): void
    {
        $docType = $this->requireDocType('RM-4.4-PPA');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding,
        ];
        $manual = fn (string $key, string $label, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'],
        ];
        $mcheck = fn (string $key, string $label, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox', 'binding' => ['kind' => 'static'], 'options' => $options,
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];

        $fields = [
            $auto('klinik_logo',   'Logo Klinik',   'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 56],
            $auto('klinik_nama',   'Nama Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('nama_pasien',   'Nama Pasien',   'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',           'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',        'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',           'text', ['kind' => 'db', 'source' => 'patient.nik']),

            // ── Diisi pasien/keluarga ──
            $manual('pekerjaan', 'Pekerjaan'),
            $mcheck('kebiasaan', 'Kebiasaan', ['Merokok', 'Alkohol', 'Kopi/Teh/Cola', 'Olahraga rutin']),
            $manual('kebiasaan_detail', 'Kebiasaan — detail/jumlah', 'longtext'),
            $mcheck('pengobatan', 'Pengobatan & Alergi', ['Aspirin/Plavix rutin', 'Obat anti sakit rutin', 'Alergi obat', 'Alergi makanan', 'Alergi lain']),
            $manual('pengobatan_detail', 'Pengobatan/Alergi — detail (dosis & lama)', 'longtext'),
            $mcheck('riwayat_keluarga', 'Riwayat Keluarga', ['Perdarahan tidak normal', 'Diabetes', 'Masalah pembiusan', 'Asma', 'Gangguan irama jantung']),
            $mcheck('riwayat_penyakit', 'Riwayat Penyakit Pasien', ['Perdarahan tidak normal', 'Mengorok', 'Nyeri dada', 'Hepatitis', 'Sakit maag', 'Hipertensi', 'Anemia', 'Diabetes', 'Serangan jantung', 'Pingsan', 'Asma']),
            $manual('riwayat_lain', 'Transfusi / HIV / Alat bantu / Riwayat operasi', 'longtext'),

            // ── Diisi dokter anestesi ──
            $mcheck('kajian_sistem', 'Kajian Sistem (Dokter)', ['Hilangnya gigi', 'Obesitas', 'Masalah mobilisasi leher', 'Sakit dada', 'Leher pendek', 'Stroke', 'Denyut jantung tidak normal', 'Kejang', 'Sesak napas', 'Sedang hamil']),
            $manual('vital_gcs', 'GCS'),
            $manual('vital_td', 'Tekanan Darah'),
            $manual('vital_nadi', 'Nadi'),
            $manual('vital_suhu', 'Suhu'),
            $manual('vital_rr', 'RR'),
            $manual('vital_tb', 'Tinggi (cm)'),
            $manual('vital_bb', 'Berat (kg)'),
            $manual('vital_bmi', 'BMI'),
            $manual('vital_vas', 'VAS'),
            $mcheck('airway', 'Airway', ['Buka mulut > 2 jari', 'Gigi palsu', 'Jarak thyromental > 3 jari', 'Gerakan leher maksimal']),
            $mcheck('mallampati', 'Mallampati', ['I', 'II', 'III', 'IV']),
            $manual('keadaan_umum', 'Keadaan Umum (kepala/sklera/konjungtiva/leher/jantung/paru/abdomen/ekstremitas)', 'longtext'),
            $manual('lab', 'Laboratorium (Hb/Ht/Plt · SGOT/SGPT · PPT/APTT · Glukosa · EKG · Rontgen)', 'longtext'),
            $manual('diagnosis', 'Diagnosis (ICD-X)'),
            $mcheck('asa', 'ASA Classification', ['ASA 1', 'ASA 2', 'ASA 3', 'ASA 4']),
            $mcheck('teknik', 'Teknik Anestesi & Sedasi', ['Sedasi', 'GA', 'Spinal', 'Epidural', 'Kaudal', 'Blok Perifer']),
            $mcheck('monitoring', 'Monitoring', ['EKG', 'SpO2', 'NIBP', 'Temp', 'Lain-lain']),
            $mcheck('perawatan_pasca', 'Perawatan Pasca Anestesi', ['Rawat inap', 'Rawat jalan', 'ICU', 'HDU']),
            $manual('puasa', 'Puasa mulai (jam / tanggal)'),
            $manual('rencana_operasi', 'Rencana Operasi (jam / tanggal)'),
            $manual('catatan', 'Catatan', 'longtext'),
            $editable('dpjp_anestesi', 'Dokter Anestesi', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'anesthesiologist']),

            ['key' => 'ttd_pasien',  'label' => 'Pasien/Keluarga', 'type' => 'signature_canvas', 'signer_type' => 'patient', 'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_perawat', 'label' => 'Perawat',         'type' => 'signature_canvas', 'signer_type' => 'nurse',   'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_dokter',  'label' => 'Dokter Anestesi',  'type' => 'signature_canvas', 'signer_type' => 'doctor',  'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:10px; padding:16px;">
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:middle; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle; font-size:14px; font-weight:700; color:#0E3A66;">{{klinik_nama}}</td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:9px; color:#666;">RM 4.4/PPA/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
          <tr><td style="padding:2px 5px; width:60px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:12px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:3px 0; margin:3px 0;">PENILAIAN PRA-ANESTESI DAN SEDASI</div>

  <div style="font-weight:700; background:#eef3f9; padding:2px 6px; margin-top:4px;">Diisi oleh Pasien / Keluarga</div>
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
    <tr><td style="border:1px solid #333; padding:3px 6px; width:24%;">Pekerjaan</td><td style="border:1px solid #333; padding:3px 6px;" colspan="3">{{pekerjaan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Kebiasaan</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{kebiasaan}}</td><td style="border:1px solid #333; padding:3px 6px; vertical-align:top; width:18%;">Detail</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{kebiasaan_detail}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Pengobatan &amp; Alergi</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{pengobatan}}</td><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Detail</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{pengobatan_detail}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Riwayat Keluarga</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;" colspan="3">{{riwayat_keluarga}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Riwayat Penyakit</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;" colspan="3">{{riwayat_penyakit}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Lain-lain</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;" colspan="3">{{riwayat_lain}}</td></tr>
  </table>

  <div style="page-break-before:always; height:4px;"></div>
  <div style="font-weight:700; background:#eef3f9; padding:2px 6px; margin-top:4px;">Diisi oleh Dokter Anestesi</div>
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
    <tr><td style="border:1px solid #333; padding:3px 6px; width:24%; vertical-align:top;">Kajian Sistem</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;" colspan="3">{{kajian_sistem}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;" colspan="4">GCS: {{vital_gcs}} &nbsp;·&nbsp; TD: {{vital_td}} &nbsp;·&nbsp; Nadi: {{vital_nadi}} &nbsp;·&nbsp; Suhu: {{vital_suhu}} &nbsp;·&nbsp; RR: {{vital_rr}} &nbsp;·&nbsp; TB: {{vital_tb}} &nbsp;·&nbsp; BB: {{vital_bb}} &nbsp;·&nbsp; BMI: {{vital_bmi}} &nbsp;·&nbsp; VAS: {{vital_vas}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Airway</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{airway}}</td><td style="border:1px solid #333; padding:3px 6px; vertical-align:top; width:18%;">Mallampati</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{mallampati}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Keadaan Umum</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;" colspan="3">{{keadaan_umum}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Laboratorium</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;" colspan="3">{{lab}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Diagnosis (ICD-X)</td><td style="border:1px solid #333; padding:3px 6px;">{{diagnosis}}</td><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">ASA</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{asa}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Teknik Anestesi</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{teknik}}</td><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Monitoring</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{monitoring}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Perawatan Pasca</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{perawatan_pasca}}</td><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Persiapan</td><td style="border:1px solid #333; padding:3px 6px;">Puasa: {{puasa}}<div>Rencana Operasi: {{rencana_operasi}}</div></td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Catatan</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;" colspan="3">{{catatan}}</td></tr>
  </table>

  <table style="width:100%; margin-top:12px; font-size:10px; text-align:center;"><tr>
    <td style="width:33%; vertical-align:top;"><div>Pasien/Keluarga</div><div style="min-height:64px; display:flex; align-items:center; justify-content:center;">{{ttd_pasien}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 16px;">Nama Jelas</div></td>
    <td style="width:33%; vertical-align:top;"><div>Perawat</div><div style="min-height:64px; display:flex; align-items:center; justify-content:center;">{{ttd_perawat}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 16px;">Nama Jelas</div></td>
    <td style="width:34%; vertical-align:top;"><div>Dokter Anestesi</div><div style="min-height:64px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 16px;">{{dpjp_anestesi}}</div></td>
  </tr></table>
</div>
HTML;

        $this->upsert('PENILAIAN_PRA_ANESTESI', [
            'name'                  => 'Penilaian Pra Anestesi dan Sedasi',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID',
                 'conditions' => ['requires_anesthesia' => true]],
            ],
        ]);
    }

    /**
     * RM 4.3/PTA/22 — Persetujuan Tindakan Anestesi (consent). HYBRID. Info tindakan/
     * risiko/komplikasi = boilerplate display-only; TTD dokter + pasien + saksi.
     */
    private function seedPersetujuanAnestesi(): void
    {
        $docType = $this->requireDocType('RM-4.3-PTA');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding,
        ];
        $manual = fn (string $key, string $label, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'],
        ];
        $mcheck = fn (string $key, string $label, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox', 'binding' => ['kind' => 'static'], 'options' => $options,
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];

        $fields = [
            $auto('klinik_logo',   'Logo Klinik',   'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 56],
            $auto('klinik_nama',   'Nama Klinik',   'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('nama_pasien',   'Nama Pasien',   'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',           'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',        'text', ['kind' => 'db', 'source' => 'patient.no_rm']),

            $editable('dokter_pelaksana', 'Dokter Pelaksana Tindakan', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'anesthesiologist']),
            $manual('pemberi_informasi', 'Pemberi Informasi'),
            $manual('penerima_informasi', 'Penerima Informasi / Pemberi Persetujuan'),
            $manual('diagnosis', 'Diagnosis (WD & DD)'),
            $mcheck('asa', 'Status Fisik ASA', ['ASA 1', 'ASA 2', 'ASA 3', 'ASA 4']),
            $mcheck('tindakan_umum', 'Anestesi Umum', ['Intubasi', 'LMA', 'FM', 'TIVA']),
            $mcheck('tindakan_regional', 'Anestesi Regional', ['Spinal', 'Epidural', 'Blok Perifer']),
            $mcheck('risiko', 'Risiko', ['Shock', 'Henti Jantung', 'Meninggal dunia di meja operasi']),
            $manual('prognosis', 'Prognosis'),
            $manual('alternatif', 'Alternatif Tindakan'),
            $manual('lain_lain', 'Lain-lain', 'longtext'),
            // Pernyataan persetujuan.
            $manual('nama_penanggung', 'Nama Yang Menyatakan'),
            $manual('hubungan', 'Hubungan dengan Pasien'),
            $manual('alamat', 'Alamat'),
            $manual('tempat_tanggal', 'Tempat, Tanggal, Pukul'),

            ['key' => 'ttd_dokter', 'label' => 'Dokter', 'type' => 'signature_canvas', 'signer_type' => 'doctor',  'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_pasien', 'label' => 'Yang Menyatakan', 'type' => 'signature_canvas', 'signer_type' => 'patient', 'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_saksi',  'label' => 'Saksi',  'type' => 'signature_canvas', 'signer_type' => 'witness', 'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:10px; padding:16px;">
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:middle; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle; font-size:14px; font-weight:700; color:#0E3A66;">{{klinik_nama}}</td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:9px; color:#666;">RM 4.3/PTA/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
          <tr><td style="padding:2px 5px; width:60px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:12px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:3px 0; margin:3px 0;">PERSETUJUAN TINDAKAN ANESTESI</div>

  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
    <tr><td style="border:1px solid #333; padding:3px 6px; width:30%;">Dokter Pelaksana</td><td style="border:1px solid #333; padding:3px 6px;">{{dokter_pelaksana}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Pemberi Informasi</td><td style="border:1px solid #333; padding:3px 6px;">{{pemberi_informasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Penerima Informasi / Pemberi Persetujuan</td><td style="border:1px solid #333; padding:3px 6px;">{{penerima_informasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Diagnosis (WD &amp; DD)</td><td style="border:1px solid #333; padding:3px 6px;">{{diagnosis}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Status Fisik ASA</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{asa}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Tindakan — Anestesi Umum</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{tindakan_umum}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Tindakan — Anestesi Regional</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{tindakan_regional}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Indikasi &amp; Tujuan</td><td style="border:1px solid #333; padding:3px 6px;">Memfasilitasi operasi, menghilangkan rasa sakit saat operasi.</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Risiko</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{risiko}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Prognosis</td><td style="border:1px solid #333; padding:3px 6px;">{{prognosis}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Alternatif Tindakan</td><td style="border:1px solid #333; padding:3px 6px;">{{alternatif}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Lain-lain</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{lain_lain}}</td></tr>
  </table>

  <div style="border:1px solid #333; border-top:none; padding:5px 8px; font-size:8.5px; color:#333; line-height:1.4;">
    <b>Komplikasi yang mungkin terjadi</b> — <u>Anestesi Umum:</u> gangguan sistem pernafasan (kejang/penyempitan jalan nafas, hipoksia, aspirasi), jantung &amp; pembuluh darah (hipo/hipertensi, aritmia s.d. henti jantung), saraf (kejang, bangun lambat, trauma saraf tepi), trauma laringoskopi-intubasi (gigi patah, luka, perdarahan), perubahan suhu tubuh, efek obat &amp; alergi (s.d. syok anafilaktik), cedera akibat posisi. <u>Anestesi Regional (Spinal/Epidural):</u> penurunan tekanan darah, anestesi spinal total, reaksi toksik/alergi, nyeri kepala/punggung, infeksi, retensi urin, cedera saraf, perdarahan.
  </div>

  <div style="margin-top:8px; font-size:9px; line-height:1.45; text-align:justify;">
    Yang bertanda tangan di bawah ini, saya, nama <b>{{nama_penanggung}}</b> ({{hubungan}}), alamat {{alamat}}, dengan ini menyatakan <b>PERSETUJUAN</b> untuk dilakukannya tindakan <b>ANESTESI</b> terhadap pasien <b>{{nama_pasien}}</b> (No. RM {{no_rm}}). Saya telah dijelaskan dan memahami jenis tindakan pembiusan beserta manfaat, risiko dan komplikasi yang mungkin timbul, serta menyadari bahwa keberhasilan tindakan kedokteran bukanlah keniscayaan.
  </div>

  <table style="width:100%; margin-top:10px; font-size:9.5px; text-align:center;">
    <tr><td colspan="3" style="text-align:right; padding-right:8px;">{{tempat_tanggal}}</td></tr>
    <tr>
      <td style="width:34%; vertical-align:top;"><div>Dokter</div><div style="min-height:62px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 14px;">Nama Jelas</div></td>
      <td style="width:33%; vertical-align:top;"><div>Yang Menyatakan</div><div style="min-height:62px; display:flex; align-items:center; justify-content:center;">{{ttd_pasien}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 14px;">Nama Jelas</div></td>
      <td style="width:33%; vertical-align:top;"><div>Saksi</div><div style="min-height:62px; display:flex; align-items:center; justify-content:center;">{{ttd_saksi}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 14px;">Nama Jelas</div></td>
    </tr>
  </table>
</div>
HTML;

        $this->upsert('PERSETUJUAN_ANESTESI', [
            'name'                  => 'Persetujuan Tindakan Anestesi',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'bedah', 'section' => 'laporan_operasi', 'mode' => 'HYBRID',
                 'conditions' => ['requires_anesthesia' => true]],
            ],
        ]);
    }

    /**
     * RM 4.9/CLKPO/22 — Checklist Keselamatan Pasien Operasi (WHO SSC 3 fase).
     * REUSE data sign-in/time-out/sign-out BedahView (K6) via binding
     * surgery_safety_checklist — TIDAK diinput ulang. HYBRID, section checklist_kesiapan.
     */
    private function seedWhoSafetyChecklist(): void
    {
        $docType = $this->requireDocType('RM-4.9-SSC');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding,
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $ssc = fn (string $fmt) => ['via' => 'aggregate', 'source' => 'surgery_safety_checklist', 'format' => $fmt];

        $fields = [
            $auto('klinik_logo', 'Logo Klinik', 'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 56],
            $auto('klinik_nama', 'Nama Klinik', 'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('nama_pasien', 'Nama Pasien', 'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',   'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P', 'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm', 'No. RM', 'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            // 3 fase WHO — prefill dari safety_checklist (editable bila perlu koreksi).
            $editable('sign_in',  'Sign In (sebelum induksi anestesi)', $ssc('sign_in')),
            $editable('time_out', 'Time Out (sebelum insisi)', $ssc('time_out')),
            $editable('sign_out', 'Sign Out (sebelum pasien keluar OK)', $ssc('sign_out')),
            ['key' => 'ttd_dokter',  'label' => 'Dokter', 'type' => 'signature_canvas', 'signer_type' => 'doctor',  'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_perawat', 'label' => 'Perawat', 'type' => 'signature_canvas', 'signer_type' => 'nurse',  'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:10px; padding:16px;">
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:middle; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle; font-size:14px; font-weight:700; color:#0E3A66;">{{klinik_nama}}</td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:9px; color:#666;">RM 4.9/CLKPO/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
          <tr><td style="padding:2px 5px; width:60px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:12px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:3px 0; margin:3px 0;">CHECKLIST KESELAMATAN PASIEN OPERASI</div>

  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
    <tr>
      <td style="border:1px solid #333; padding:4px 6px; width:33.3%; vertical-align:top; background:#eef3f9; font-weight:700;">SIGN IN<div style="font-weight:400; font-size:8.5px;">Sebelum induksi anestesi</div></td>
      <td style="border:1px solid #333; padding:4px 6px; width:33.3%; vertical-align:top; background:#eef3f9; font-weight:700;">TIME OUT<div style="font-weight:400; font-size:8.5px;">Sebelum insisi</div></td>
      <td style="border:1px solid #333; padding:4px 6px; width:33.4%; vertical-align:top; background:#eef3f9; font-weight:700;">SIGN OUT<div style="font-weight:400; font-size:8.5px;">Sebelum pasien keluar OK</div></td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:5px 6px; vertical-align:top; white-space:pre-line;">{{sign_in}}</td>
      <td style="border:1px solid #333; padding:5px 6px; vertical-align:top; white-space:pre-line;">{{time_out}}</td>
      <td style="border:1px solid #333; padding:5px 6px; vertical-align:top; white-space:pre-line;">{{sign_out}}</td>
    </tr>
  </table>

  <table style="width:100%; margin-top:12px; font-size:10px; text-align:center;"><tr>
    <td style="width:50%; vertical-align:top;"><div>Dokter</div><div style="min-height:64px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 24px;">Nama &amp; Tanda Tangan</div></td>
    <td style="width:50%; vertical-align:top;"><div>Perawat</div><div style="min-height:64px; display:flex; align-items:center; justify-content:center;">{{ttd_perawat}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 24px;">Nama &amp; Tanda Tangan</div></td>
  </tr></table>
</div>
HTML;

        $this->upsert('WHO_SAFETY_CHECKLIST', [
            'name'                  => 'Checklist Keselamatan Pasien Operasi',
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
     * RM 1.9/SM(PO)/22 — Site Marking (Penandaan Operasi, SKP 4). Mata prefill dari
     * operative_eye (operation_report); editable agar bisa diisi pra-op. TTD pasien+dokter+perawat.
     */
    private function seedSiteMarking(): void
    {
        $docType = $this->requireDocType('RM-1.9-SM');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding,
        ];
        $manual = fn (string $key, string $label, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'],
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];

        $fields = [
            $auto('klinik_logo', 'Logo Klinik', 'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 56],
            $auto('klinik_nama', 'Nama Klinik', 'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('nama_pasien', 'Nama Pasien', 'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',   'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('no_rm', 'No. RM', 'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik', 'NIK', 'text', ['kind' => 'db', 'source' => 'patient.nik']),
            // Mata: OD/OS (prefill dari operation_report.operative_eye; editable pra-op).
            $editable('mata', 'Mata yang Dioperasi (OD/OS)', ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => 'operative_eye']),
            $manual('tanggal', 'Tanggal', 'date'),
            $manual('jam', 'Jam', 'time'),
            ['key' => 'ttd_pasien',  'label' => 'Pasien/Keluarga', 'type' => 'signature_canvas', 'signer_type' => 'patient', 'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_dokter',  'label' => 'Dokter yang Merawat', 'type' => 'signature_canvas', 'signer_type' => 'doctor', 'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_perawat', 'label' => 'Perawat Penanggung Jawab', 'type' => 'signature_canvas', 'signer_type' => 'nurse', 'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:10.5px; padding:16px;">
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:middle; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle; font-size:14px; font-weight:700; color:#0E3A66;">{{klinik_nama}}</td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:9px; color:#666;">RM 1.9/SM(PO)/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
          <tr><td style="padding:2px 5px; width:60px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:13px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:4px 0;">SITE MARKING (PENANDAAN OPERASI)</div>

  <p style="margin:6px 0; font-size:10px;">Beri tanda (&#10003;) pada lokasi yang akan dioperasi menggunakan alat penanda/marker.</p>

  <div style="text-align:center; margin:14px 0; font-size:14px; font-weight:700; letter-spacing:1px;">
    Mata yang dioperasi: <span style="border:2px solid #0E3A66; padding:4px 16px; border-radius:6px;">{{mata}}</span>
  </div>

  <div style="margin-top:10px; font-size:10px;">Tanggal: {{tanggal}} &nbsp;&nbsp; Jam: {{jam}}</div>

  <table style="width:100%; margin-top:18px; font-size:10px; text-align:center;"><tr>
    <td style="width:33.3%; vertical-align:top;"><div>Pasien/Keluarga</div><div style="min-height:66px; display:flex; align-items:center; justify-content:center;">{{ttd_pasien}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 14px;">Nama &amp; Tanda Tangan</div></td>
    <td style="width:33.3%; vertical-align:top;"><div>Dokter yang Merawat</div><div style="min-height:66px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 14px;">Nama &amp; Tanda Tangan</div></td>
    <td style="width:33.4%; vertical-align:top;"><div>Perawat Penanggung Jawab</div><div style="min-height:66px; display:flex; align-items:center; justify-content:center;">{{ttd_perawat}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 14px;">Nama &amp; Tanda Tangan</div></td>
  </tr></table>
</div>
HTML;

        $this->upsert('SITE_MARKING', [
            'name'                  => 'Site Marking (Penandaan Operasi)',
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
     * RM 1.10/PPPO/22 — Proses Perawatan Peri-operatif. INPUT (perawat ruangan + kamar
     * bedah). Identitas/diagnosis/operator prefill; checklist persiapan via mcheck. HYBRID.
     */
    private function seedProsesPeriOperatif(): void
    {
        $docType = $this->requireDocType('RM-1.10-PPO');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding,
        ];
        $manual = fn (string $key, string $label, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'],
        ];
        $mcheck = fn (string $key, string $label, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox', 'binding' => ['kind' => 'static'], 'options' => $options,
        ];
        $editable = fn (string $key, string $label, array $prefill, string $type = 'text') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $sid = fn (string $fmt) => ['via' => 'aggregate', 'source' => 'surgery_identity', 'format' => $fmt];

        $fields = [
            $auto('klinik_logo', 'Logo Klinik', 'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 56],
            $auto('klinik_nama', 'Nama Klinik', 'text', ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('nama_pasien', 'Nama Pasien', 'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',   'Tanggal Lahir', 'date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P', 'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm', 'No. RM', 'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik', 'NIK', 'text', ['kind' => 'db', 'source' => 'patient.nik']),

            // ── A: Catatan perawatan sebelum operasi (perawat ruangan) ──
            $manual('tanggal', 'Tanggal', 'date'),
            $manual('jam', 'Jam', 'time'),
            $manual('ruangan', 'Ruangan'),
            $manual('jenis_pasien', 'Jenis Pasien (Umum/Asuransi/BPJS)'),
            $editable('diagnosis', 'Diagnosis', $sid('diagnosis_post')),
            $editable('tindakan_operasi', 'Tindakan Operasi', $sid('procedure')),
            $editable('dokter_operator', 'Dokter Operator', $sid('operator')),
            $editable('dokter_anestesi', 'Dokter Anestesi', $sid('anesthesiologist')),
            $manual('vs_temp', 'Suhu'),
            $manual('vs_nadi', 'Nadi'),
            $manual('vs_rr', 'Pernafasan'),
            $manual('vs_td', 'Tekanan Darah'),
            $manual('vs_tb', 'Tinggi'),
            $manual('vs_bb', 'Berat (kg)'),
            $mcheck('riwayat_penyakit', 'Riwayat Penyakit', ['Hipertensi', 'Diabetes', 'Hepatitis', 'Lain-lain']),
            $mcheck('alergi', 'Alergi', ['Tidak', 'Tidak Tahu', 'Ya']),
            $manual('kgd', 'Hasil KGD (+ waktu pengambilan)'),

            // ── B: Checklist persiapan (perawat ruangan & kamar operasi) ──
            $mcheck('persiapan', 'Checklist Persiapan (centang yang SUDAH dilakukan)', [
                'Pemeriksaan identitas pasien', 'Pemeriksaan gelang nama',
                'Formulir persetujuan operasi (TTD)', 'Pemberian premedikasi',
                'Puasa makan & minum terakhir', 'Lepas alat prothesa (gigi palsu/lensa kontak)',
                'Lepas penjepit rambut/cat kuku/perhiasan', 'Status pasien terlampir',
                'X-ray/Scan terlampir', 'Persiapan pencukuran bulu mata',
                'Pemeriksaan darah (PMI/Lab RS)', 'Site marker terpasang',
            ]),
            $manual('catatan', 'Keterangan / Catatan', 'longtext'),

            ['key' => 'ttd_perawat_ruangan', 'label' => 'Perawat Ruangan', 'type' => 'signature_canvas', 'signer_type' => 'nurse', 'required' => false, 'binding' => ['kind' => 'static']],
            ['key' => 'ttd_perawat_bedah',   'label' => 'Perawat Kamar Bedah', 'type' => 'signature_canvas', 'signer_type' => 'nurse', 'required' => false, 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:10px; padding:16px;">
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:middle; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle; font-size:14px; font-weight:700; color:#0E3A66;">{{klinik_nama}}</td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:9px; color:#666;">RM 1.10/PPPO/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
          <tr><td style="padding:2px 5px; width:60px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:12px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:3px 0; margin:3px 0;">PROSES PERAWATAN PERI-OPERATIF</div>

  <div style="font-weight:700; background:#eef3f9; padding:2px 6px; margin-top:4px;">A. Catatan Sebelum Operasi (Perawat Ruangan)</div>
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
    <tr><td style="border:1px solid #333; padding:3px 6px; width:18%;">Tanggal / Jam</td><td style="border:1px solid #333; padding:3px 6px; width:32%;">{{tanggal}} &nbsp; {{jam}}</td><td style="border:1px solid #333; padding:3px 6px; width:18%;">Ruangan</td><td style="border:1px solid #333; padding:3px 6px;">{{ruangan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Jenis Pasien</td><td style="border:1px solid #333; padding:3px 6px;">{{jenis_pasien}}</td><td style="border:1px solid #333; padding:3px 6px;">Diagnosis</td><td style="border:1px solid #333; padding:3px 6px;">{{diagnosis}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Tindakan Operasi</td><td style="border:1px solid #333; padding:3px 6px;">{{tindakan_operasi}}</td><td style="border:1px solid #333; padding:3px 6px;">Dokter Operator</td><td style="border:1px solid #333; padding:3px 6px;">{{dokter_operator}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Dokter Anestesi</td><td style="border:1px solid #333; padding:3px 6px;">{{dokter_anestesi}}</td><td style="border:1px solid #333; padding:3px 6px;">Hasil KGD</td><td style="border:1px solid #333; padding:3px 6px;">{{kgd}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Vital Signs</td><td style="border:1px solid #333; padding:3px 6px;" colspan="3">Suhu: {{vs_temp}} &nbsp;·&nbsp; Nadi: {{vs_nadi}} &nbsp;·&nbsp; RR: {{vs_rr}} &nbsp;·&nbsp; TD: {{vs_td}} &nbsp;·&nbsp; TB: {{vs_tb}} &nbsp;·&nbsp; BB: {{vs_bb}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Riwayat Penyakit</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{riwayat_penyakit}}</td><td style="border:1px solid #333; padding:3px 6px; vertical-align:top;">Alergi</td><td style="border:1px solid #333; padding:3px 6px; white-space:pre-line;">{{alergi}}</td></tr>
  </table>

  <div style="font-weight:700; background:#eef3f9; padding:2px 6px; margin-top:6px;">B. Checklist Persiapan (Perawat Ruangan &amp; Kamar Operasi)</div>
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:9.5px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; white-space:pre-line;">{{persiapan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:3px 6px;">Keterangan: {{catatan}}</td></tr>
  </table>

  <table style="width:100%; margin-top:12px; font-size:10px; text-align:center;"><tr>
    <td style="width:50%; vertical-align:top;"><div>Perawat Ruangan</div><div style="min-height:64px; display:flex; align-items:center; justify-content:center;">{{ttd_perawat_ruangan}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 24px;">Nama &amp; Tanda Tangan</div></td>
    <td style="width:50%; vertical-align:top;"><div>Perawat Kamar Bedah</div><div style="min-height:64px; display:flex; align-items:center; justify-content:center;">{{ttd_perawat_bedah}}</div><div style="border-top:1px solid #333; padding-top:2px; margin:0 24px;">Nama &amp; Tanda Tangan</div></td>
  </tr></table>
</div>
HTML;

        $this->upsert('PROSES_PERI_OPERATIF', [
            'name'                  => 'Proses Perawatan Peri-Operatif',
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

    // ═════════════════════════════════════════════════════════════════════════
    // RANAP — Phase 1 (3 form nakes-only/PIN). Pola HYBRID auto-fill seperti
    // Resume RJ. Field editable diberi atribut `group` untuk UX accordion
    // (FormRMRenderer mengelompokkan field per `group`; field tanpa group →
    // fallback datar = backward-compatible). TTD `required:false` (fase transisi).
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * RM 3.5/RI — Resume Medis Rawat Inap (discharge summary). DPJP. Auto-terbuka
     * saat discharge di RawatInapView (mirip Resume RJ). Prefill identitas perawatan
     * (tgl masuk/keluar/lama rawat/kelas/DPJP) dari kolom RANAP Visit + diagnosa
     * (ICD-10) / tindakan (ICD-9) / terapi pulang (resep) dari data yang sudah ada.
     */
    private function seedResumeMedisRanap(): void
    {
        $docType = $this->requireDocType('RM-3.5-RI');
        if (!$docType) return;

        // Helper closures — sejajar seedResumeMedis, + parameter `group` (accordion).
        $auto = fn (string $key, string $label, string $type, array $binding, array $extra = []) => array_merge(
            ['key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding],
            $extra
        );
        $ed = fn (string $key, string $label, string $group, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $manual = fn (string $key, string $label, string $group, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'],
        ];

        $fields = [
            // ── Kop klinik + identitas (display_only, di kop/meta output) ─────
            $auto('klinik_logo',   'Logo Klinik',  'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 64],
            $auto('klinik_nama',   'Nama Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik','text',      ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.phone']),
            $auto('nama_pasien',   'Nama Pasien',  'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir','date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',          'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',       'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',          'text', ['kind' => 'db', 'source' => 'patient.nik']),
            // Identitas perawatan (display_only) — dari aggregate ranap_identity.
            $auto('tgl_masuk',     'Tanggal Masuk',  'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'admission_date']),
            $auto('tgl_keluar',    'Tanggal Keluar', 'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'discharge_date']),
            $auto('lama_rawat',    'Lama Rawat',     'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'los']),
            $auto('ruang_rawat',   'Ruang Rawat',    'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'room_bed']),
            $auto('kelas_rawat',   'Kelas',          'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'kelas']),
            $auto('dpjp_nama',     'DPJP',           'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'dpjp']),
            $auto('penanggung',    'Penanggung Pembayaran', 'text', ['kind' => 'db', 'source' => 'visit.guarantor_type']),

            // ── Diagnosa & Alasan Dirawat ────────────────────────────────────
            $manual('alasan_dirawat',  'Alasan Dirawat',                  'Diagnosa'),
            $ed('diagnosa_masuk',      'Diagnosa Masuk',                  'Diagnosa', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            $ed('diagnosa_keluar',     'Diagnosa Keluar (Diagnosa Utama, ICD-10)', 'Diagnosa', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),

            // ── Pemeriksaan & Penunjang ──────────────────────────────────────
            $ed('pemeriksaan_fisik',   'Pemeriksaan Fisik yang Penting',  'Pemeriksaan & Penunjang', ['via' => 'aggregate', 'source' => 'physical_exam']),
            $manual('laboratorium',    'Laboratorium yang Penting',       'Pemeriksaan & Penunjang'),
            $manual('radiologi',       'Radiologi',                       'Pemeriksaan & Penunjang'),
            $ed('penunjang_lain',      'Penunjang Lain',                  'Pemeriksaan & Penunjang', ['via' => 'aggregate', 'source' => 'diagnosticResults.summary', 'format' => 'summary_per_jenis']),

            // ── Tindakan & Pengobatan selama dirawat ─────────────────────────
            $ed('tindakan_operasi',    'Tindakan / Operasi (ICD-9)',      'Tindakan & Pengobatan', ['via' => 'aggregate', 'source' => 'doctorExamination.icd9_procedures', 'format' => 'icd_with_desc_join_newline']),
            $manual('pengobatan_dirawat','Pengobatan Selama Dirawat',     'Tindakan & Pengobatan'),

            // ── Kondisi Pulang & Tindak Lanjut ───────────────────────────────
            $ed('kondisi_pulang',      'Kondisi Pulang',                  'Kondisi Pulang', ['via' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'discharge_type'], 'text'),
            $ed('instruksi',           'Instruksi & Edukasi Lanjutan (follow up)', 'Kondisi Pulang', ['via' => 'aggregate', 'source' => 'planning_instruction']),
            $ed('kontrol_tgl',         'Kontrol Tanggal',                 'Kondisi Pulang', ['via' => 'db', 'source' => 'visit.follow_up_date'], 'date'),
            $manual('diet',            'Diet',                            'Kondisi Pulang', 'text'),
            $manual('latihan',         'Latihan',                         'Kondisi Pulang', 'text'),
            $manual('tanda_bahaya',    'Segera Kembali ke RS / IGD bila Terjadi', 'Kondisi Pulang'),

            // ── Terapi Pulang ────────────────────────────────────────────────
            // Dikosongkan (keputusan user): DPJP mengisi terapi pulang manual, tidak
            // auto-isi dari resep. Tanpa `prefill`, renderer juga tak me-refill saat TTD.
            $manual('terapi_pulang',   'Terapi Pulang (Obat)',            'Terapi Pulang'),

            // ── TTD DPJP (PIN → stempel + QR; opsional fase transisi) ────────
            ['key' => 'ttd_dokter', 'label' => 'Tanda Tangan DPJP', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => false, 'group' => 'Pengesahan', 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:16px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9.5px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9.5px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 3.5/RI/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10.5px;">
          <tr><td style="padding:2px 5px; width:74px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:14px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:6px 0 0;">RESUME MEDIS RAWAT INAP</div>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr>
      <td style="border:1px solid #333; padding:3px 6px; width:22%;">Tanggal Masuk</td>
      <td style="border:1px solid #333; padding:3px 6px; width:28%;">{{tgl_masuk}}</td>
      <td style="border:1px solid #333; padding:3px 6px; width:22%;">Tanggal Keluar</td>
      <td style="border:1px solid #333; padding:3px 6px; width:28%;">{{tgl_keluar}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">Ruang Rawat / Kelas</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{ruang_rawat}} &nbsp; ({{kelas_rawat}})</td>
      <td style="border:1px solid #333; padding:3px 6px;">Lama Rawat</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{lama_rawat}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:3px 6px;">DPJP</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{dpjp_nama}}</td>
      <td style="border:1px solid #333; padding:3px 6px;">Penanggung</td>
      <td style="border:1px solid #333; padding:3px 6px;">{{penanggung}}</td>
    </tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Alasan Dirawat</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alasan_dirawat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Diagnosa Masuk</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_masuk}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Diagnosa Keluar<br><span style="font-weight:400; font-size:9.5px;">(Diagnosa Utama)</span></td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_keluar}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pemeriksaan Fisik yang Penting</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pemeriksaan_fisik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Laboratorium yang Penting</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{laboratorium}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Radiologi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{radiologi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Penunjang Lain</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{penunjang_lain}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Tindakan / Operasi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{tindakan_operasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pengobatan Selama Dirawat</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pengobatan_dirawat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Kondisi Pulang</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{kondisi_pulang}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Instruksi &amp; Edukasi<br>Lanjutan (follow up)</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{instruksi}}<br>Kontrol Tanggal: <strong>{{kontrol_tgl}}</strong><br>Diet: {{diet}}<br>Latihan: {{latihan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Segera kembali ke RS / IGD<br>bila terjadi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{tanda_bahaya}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Terapi Pulang</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{terapi_pulang}}</td></tr>
  </table>

  <table style="width:100%; margin-top:16px; font-size:11px;"><tr>
    <td style="width:58%;"></td>
    <td style="width:42%; text-align:center;">
      <div>Dokter Penanggung Jawab (DPJP),</div>
      <div style="min-height:84px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;"><strong>{{dpjp_nama}}</strong></div>
      <div style="font-size:9px; color:#666;">Nama Jelas dan Tandatangan</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('RESUME_MEDIS_RANAP', [
            'name'                  => 'Resume Medis Rawat Inap',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'ranap', 'section' => 'ringkasan_pulang', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * RM 7.7/PAM — Pengkajian Awal Medis (Mata). DPJP, ≤24 jam sejak masuk. Prefill
     * anamnesa / nyeri / TTV dari triase perawat + diagnosa & rencana dari pemeriksaan
     * dokter bila sudah ada. HYBRID; field ber-group untuk accordion.
     */
    private function seedPengkajianAwalMedis(): void
    {
        $docType = $this->requireDocType('RM-7.7-PAM');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding, array $extra = []) => array_merge(
            ['key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding],
            $extra
        );
        $ed = fn (string $key, string $label, string $group, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $manual = fn (string $key, string $label, string $group, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'],
        ];

        $fields = [
            // Kop + identitas
            $auto('klinik_logo',   'Logo Klinik',  'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 64],
            $auto('klinik_nama',   'Nama Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik','text',      ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.phone']),
            $auto('nama_pasien',   'Nama Pasien',  'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir','date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',          'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',       'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',          'text', ['kind' => 'db', 'source' => 'patient.nik']),
            $auto('tgl_masuk',     'Tanggal Masuk','text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'admission_date']),
            $auto('dpjp_nama',     'DPJP',         'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'dpjp']),

            // ── Anamnesa ─────────────────────────────────────────────────────
            $ed('keluhan_utama',  'Keluhan Utama',                 'Anamnesa', ['via' => 'db', 'source' => 'nurseAssessment.chief_complaint']),
            $ed('rps',            'Riwayat Penyakit Sekarang',     'Anamnesa', ['via' => 'db', 'source' => 'nurseAssessment.rps']),
            $manual('rpd',        'Riwayat Penyakit Dahulu',       'Anamnesa'),
            $manual('riwayat_obat','Riwayat Pengobatan',           'Anamnesa'),
            $manual('riwayat_keluarga','Riwayat Penyakit Keluarga','Anamnesa'),
            $manual('riwayat_operasi','Riwayat Operasi / Transfusi','Anamnesa'),
            $ed('alergi',         'Alergi',                        'Anamnesa', ['via' => 'aggregate', 'source' => 'allergy']),

            // ── Penilaian Nyeri (VAS) ────────────────────────────────────────
            $ed('skala_nyeri',    'Skala Nyeri (0–10)',            'Penilaian Nyeri', ['via' => 'db', 'source' => 'nurseAssessment.pain_scale'], 'number'),
            $manual('nyeri_lokasi','Lokasi / Karakteristik Nyeri', 'Penilaian Nyeri'),

            // ── Tanda Vital & Status Generalis ───────────────────────────────
            $manual('keadaan_umum','Keadaan Umum / Kesadaran / GCS','Tanda Vital'),
            $ed('nadi',           'Nadi (x/mnt)',                  'Tanda Vital', ['via' => 'db', 'source' => 'nurseAssessment.nadi'], 'number'),
            $ed('rr',             'Respirasi (x/mnt)',             'Tanda Vital', ['via' => 'db', 'source' => 'nurseAssessment.respirasi'], 'number'),
            $ed('suhu',           'Suhu (°C)',                     'Tanda Vital', ['via' => 'db', 'source' => 'nurseAssessment.suhu'], 'number'),
            $ed('spo2',           'Saturasi O₂ (%)',               'Tanda Vital', ['via' => 'db', 'source' => 'nurseAssessment.spo2'], 'number'),
            $manual('tekanan_darah','Tekanan Darah (mmHg)',        'Tanda Vital', 'text'),
            $ed('berat_badan',    'Berat Badan (kg)',              'Tanda Vital', ['via' => 'db', 'source' => 'nurseAssessment.berat_badan'], 'number'),

            // ── Pemeriksaan Fisik & Status Lokalis Mata ──────────────────────
            $manual('pemeriksaan_umum','Pemeriksaan Fisik Umum (Kepala/Leher/Thorax)', 'Pemeriksaan Mata'),
            $ed('status_mata',    'Status Lokalis Mata (OD / OS)',  'Pemeriksaan Mata', ['via' => 'db', 'source' => 'doctorExamination.soap_objective']),
            $manual('penunjang',  'Pemeriksaan Penunjang (Lab/EKG/X-Ray)', 'Pemeriksaan Mata'),

            // ── Diagnosa & Rencana ───────────────────────────────────────────
            $ed('diagnosa_kerja', 'Diagnosa Kerja (ICD-10)',       'Diagnosa & Rencana', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            $manual('diagnosa_diferensial','Diagnosa Diferensial', 'Diagnosa & Rencana'),
            // Dikosongkan (keputusan user): Terapi & Rencana Kerja diisi manual DPJP,
            // tidak auto-isi dari resep / planning. Tanpa `prefill` tak ada refill saat TTD.
            $manual('terapi',         'Terapi',                     'Diagnosa & Rencana'),
            $manual('rencana_kerja',  'Rencana Kerja',              'Diagnosa & Rencana'),

            // ── TTD DPJP ─────────────────────────────────────────────────────
            ['key' => 'ttd_dokter', 'label' => 'Tanda Tangan DPJP', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => false, 'group' => 'Pengesahan', 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:16px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9.5px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9.5px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 7.7/PAM/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10.5px;">
          <tr><td style="padding:2px 5px; width:74px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:14px; border-top:2px solid #0E3A66; border-bottom:1px solid #0E3A66; padding:4px 0 2px; margin:6px 0 0;">PENGKAJIAN AWAL MEDIS MATA</div>
  <div style="text-align:center; font-size:9px; color:#666; border-bottom:2px solid #0E3A66; padding-bottom:3px;">(Diisi oleh Dokter dalam waktu 24 jam sejak pasien masuk) — Masuk: {{tgl_masuk}} · DPJP: {{dpjp_nama}}</div>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px; margin-top:0;">
    <tr><td colspan="2" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">ANAMNESA</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Keluhan Utama</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{keluhan_utama}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Riwayat Penyakit Sekarang</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{rps}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Riwayat Penyakit Dahulu</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{rpd}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Riwayat Pengobatan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{riwayat_obat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Riwayat Penyakit Keluarga</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{riwayat_keluarga}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Riwayat Operasi / Transfusi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{riwayat_operasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Alergi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alergi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Penilaian Nyeri</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">Skala: <strong>{{skala_nyeri}}</strong> / 10 &nbsp; — &nbsp; {{nyeri_lokasi}}</td></tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td colspan="4" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">TANDA VITAL</td></tr>
    <tr>
      <td style="border:1px solid #333; padding:4px 6px; width:25%;">Tekanan Darah: <strong>{{tekanan_darah}}</strong></td>
      <td style="border:1px solid #333; padding:4px 6px; width:25%;">Nadi: <strong>{{nadi}}</strong> x/mnt</td>
      <td style="border:1px solid #333; padding:4px 6px; width:25%;">RR: <strong>{{rr}}</strong> x/mnt</td>
      <td style="border:1px solid #333; padding:4px 6px; width:25%;">Suhu: <strong>{{suhu}}</strong> °C</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:4px 6px;">SpO₂: <strong>{{spo2}}</strong> %</td>
      <td style="border:1px solid #333; padding:4px 6px;">BB: <strong>{{berat_badan}}</strong> kg</td>
      <td colspan="2" style="border:1px solid #333; padding:4px 6px; white-space:pre-line;">{{keadaan_umum}}</td>
    </tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td colspan="2" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">PEMERIKSAAN FISIK</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Pemeriksaan Fisik Umum</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pemeriksaan_umum}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Status Lokalis Mata (OD/OS)</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{status_mata}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pemeriksaan Penunjang</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{penunjang}}</td></tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td colspan="2" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">DIAGNOSA &amp; RENCANA</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Diagnosa Kerja</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_kerja}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Diagnosa Diferensial</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_diferensial}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Terapi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{terapi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Rencana Kerja</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{rencana_kerja}}</td></tr>
  </table>

  <table style="width:100%; margin-top:16px; font-size:11px;"><tr>
    <td style="width:58%;"></td>
    <td style="width:42%; text-align:center;">
      <div>Dokter Pemeriksa (DPJP),</div>
      <div style="min-height:84px; display:flex; align-items:center; justify-content:center;">{{ttd_dokter}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;"><strong>{{dpjp_nama}}</strong></div>
      <div style="font-size:9px; color:#666;">Nama Jelas dan Tandatangan</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('PENGKAJIAN_AWAL_MEDIS', [
            'name'                  => 'Pengkajian Awal Medis Rawat Inap',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'ranap', 'section' => 'pengkajian_awal', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * RM 7.8/AAKRI — Asesmen Awal Keperawatan Rawat Inap. Perawat, ≤24 jam. SCORED
     * form (Skala Norton dekubitus 5 item + skrining gizi MST 2 item via
     * ScoringEngine) + discharge planning. HYBRID (dokumen ber-kop + skor + TTD
     * perawat). Field ber-group untuk accordion; skor live di header grup (FE).
     */
    private function seedAsesmenAwalKeperawatan(): void
    {
        $docType = $this->requireDocType('RM-7.8-AAKRI');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding, array $extra = []) => array_merge(
            ['key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding],
            $extra
        );
        $ed = fn (string $key, string $label, string $group, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $manual = fn (string $key, string $label, string $group, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'],
        ];
        // scored_radio helper (Norton/MST) — opsi {label, score}.
        $scored = fn (string $key, string $label, string $group, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'scored_radio', 'group' => $group,
            'required' => false, 'options' => $options, 'binding' => ['kind' => 'static', 'value' => null],
        ];

        $fields = [
            // Kop + identitas
            $auto('klinik_logo',   'Logo Klinik',  'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 64],
            $auto('klinik_nama',   'Nama Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik','text',      ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.phone']),
            $auto('nama_pasien',   'Nama Pasien',  'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir','date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',          'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',       'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',          'text', ['kind' => 'db', 'source' => 'patient.nik']),
            $auto('tgl_masuk',     'Tanggal Masuk','text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'admission_date']),

            // ── Alergi & Pemeriksaan Fisik ───────────────────────────────────
            $ed('alergi',          'Alergi / Reaksi',               'Alergi & Fisik', ['via' => 'aggregate', 'source' => 'allergy']),
            $ed('pemeriksaan_fisik','Pemeriksaan Fisik (Kesadaran/Pernafasan/Kulit/dll)', 'Alergi & Fisik', ['via' => 'aggregate', 'source' => 'physical_exam']),
            $manual('keadaan_umum','Keadaan Umum',                  'Alergi & Fisik', 'text'),

            // ── Skrining Risiko Cedera/Jatuh ─────────────────────────────────
            $manual('risiko_jatuh','Risiko Cedera/Jatuh & Tindak Lanjut (gelang/segitiga)', 'Risiko Jatuh'),

            // ── Skala Norton (dekubitus) — 5 scored_radio + computed ─────────
            $scored('norton_fisik',    'Keluhan Fisik',   'Skala Norton', [
                ['label' => 'Baik',         'score' => 4],
                ['label' => 'Sedang',       'score' => 3],
                ['label' => 'Buruk',        'score' => 2],
                ['label' => 'Sangat buruk', 'score' => 1],
            ]),
            $scored('norton_mental',   'Status Mental',   'Skala Norton', [
                ['label' => 'Sadar',   'score' => 4],
                ['label' => 'Apatis',  'score' => 3],
                ['label' => 'Bingung', 'score' => 2],
                ['label' => 'Stupor',  'score' => 1],
            ]),
            $scored('norton_aktifitas','Aktifitas',       'Skala Norton', [
                ['label' => 'Jalan sendiri',   'score' => 4],
                ['label' => 'Dengan bantuan',  'score' => 3],
                ['label' => 'Kursi roda',      'score' => 2],
                ['label' => 'Di tempat tidur', 'score' => 1],
            ]),
            $scored('norton_mobilitas','Mobilitas',       'Skala Norton', [
                ['label' => 'Bebas bergerak',  'score' => 4],
                ['label' => 'Gerak terbatas',  'score' => 3],
                ['label' => 'Sangat terbatas', 'score' => 2],
                ['label' => 'Tidak bergerak',  'score' => 1],
            ]),
            $scored('norton_inkontinensia','Inkontinensia','Skala Norton', [
                ['label' => 'Kontinen',            'score' => 4],
                ['label' => 'Kadang inkontinen',   'score' => 3],
                ['label' => 'Selalu inkontinen',   'score' => 2],
                ['label' => 'Inkontinen urin & alvi','score' => 1],
            ]),
            ['key' => 'norton_total', 'label' => 'Total Skor Norton', 'type' => 'computed_sum', 'group' => 'Skala Norton',
             'sum_of' => ['norton_fisik', 'norton_mental', 'norton_aktifitas', 'norton_mobilitas', 'norton_inkontinensia'],
             'binding' => ['kind' => 'computed']],
            ['key' => 'norton_interpretasi', 'label' => 'Interpretasi Risiko Dekubitus', 'type' => 'computed_threshold', 'group' => 'Skala Norton',
             'based_on' => 'norton_total',
             'thresholds' => [
                 ['max' => 11, 'label' => 'Risiko Tinggi (<12)'],
                 ['max' => 15, 'label' => 'Risiko Sedang (12–15)'],
                 ['max' => 20, 'label' => 'Tidak Ada Risiko (16–20)'],
             ],
             'binding' => ['kind' => 'computed']],

            // ── Skala Nyeri ──────────────────────────────────────────────────
            $ed('skala_nyeri',    'Skala Nyeri (0–10)',             'Skala Nyeri', ['via' => 'db', 'source' => 'nurseAssessment.pain_scale'], 'number'),
            $manual('nyeri_detail','Lokasi / Onset / Karakteristik Nyeri', 'Skala Nyeri'),

            // ── Skrining Gizi (MST) — 2 scored_radio + computed ──────────────
            $scored('mst_bb', 'Penurunan BB tidak diinginkan (3 bulan terakhir)', 'Skrining Gizi', [
                ['label' => 'Tidak ada penurunan BB', 'score' => 0],
                ['label' => 'Penurunan 1–5 kg',       'score' => 1],
                ['label' => 'Penurunan 6–10 kg',      'score' => 2],
                ['label' => 'Penurunan 11–15 kg',     'score' => 3],
                ['label' => 'Penurunan >15 kg',       'score' => 4],
            ]),
            $scored('mst_nafsu', 'Asupan makan berkurang karena tidak nafsu makan', 'Skrining Gizi', [
                ['label' => 'Tidak', 'score' => 0],
                ['label' => 'Ya',    'score' => 1],
            ]),
            ['key' => 'mst_total', 'label' => 'Total Skor MST', 'type' => 'computed_sum', 'group' => 'Skrining Gizi',
             'sum_of' => ['mst_bb', 'mst_nafsu'], 'binding' => ['kind' => 'computed']],
            ['key' => 'mst_interpretasi', 'label' => 'Interpretasi Risiko Gizi', 'type' => 'computed_threshold', 'group' => 'Skrining Gizi',
             'based_on' => 'mst_total',
             'thresholds' => [
                 ['max' => 1,    'label' => 'Risiko Rendah (tidak perlu rujukan gizi)'],
                 ['max' => 9999, 'label' => 'Berisiko Malnutrisi — pengkajian lanjutan oleh ahli gizi (skor ≥2)'],
             ],
             'binding' => ['kind' => 'computed']],

            // ── Status Fungsional ────────────────────────────────────────────
            $manual('status_fungsional','Status Fungsional / Tingkat Ketergantungan (Mandiri / Bantuan minimal / Total)', 'Status Fungsional'),

            // ── Discharge Planning ───────────────────────────────────────────
            $manual('estimasi_pulang','Estimasi Tanggal Pemulangan',  'Discharge Planning', 'date'),
            $manual('kebutuhan_pulang','Masalah & Kebutuhan saat Pulang (mobilitas/hygiene/obat/diet)', 'Discharge Planning'),
            $manual('alat_bantu',  'Alat Medis / Alat Bantu / Perawatan Lanjutan di Rumah', 'Discharge Planning'),

            // ── Diagnosa & Rencana Keperawatan ───────────────────────────────
            $manual('diagnosa_kep','Diagnosa Keperawatan',           'Rencana Keperawatan'),
            $manual('tujuan_kep',  'Tujuan',                         'Rencana Keperawatan'),
            $manual('intervensi_kep','Intervensi',                   'Rencana Keperawatan'),

            // ── TTD Perawat ──────────────────────────────────────────────────
            ['key' => 'ttd_perawat', 'label' => 'Tanda Tangan Perawat', 'type' => 'signature_canvas',
             'signer_type' => 'nurse', 'required' => false, 'group' => 'Pengesahan', 'binding' => ['kind' => 'static']],
        ];

        $layoutHtml = <<<'HTML'
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  <table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
    <tr>
      <td style="vertical-align:top; width:58%;">
        <table style="border-collapse:collapse;"><tr>
          <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
          <td style="vertical-align:middle;">
            <div style="font-size:16px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
            <div style="font-size:9.5px; color:#444;">{{klinik_alamat}}</div>
            <div style="font-size:9.5px; color:#444;">Telp: {{klinik_telp}}</div>
          </td>
        </tr></table>
      </td>
      <td style="vertical-align:top; width:42%;">
        <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">RM 7.8/AAKRI/22</div>
        <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10.5px;">
          <tr><td style="padding:2px 5px; width:74px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
          <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
          <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
          <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="text-align:center; font-weight:700; font-size:14px; border-top:2px solid #0E3A66; border-bottom:1px solid #0E3A66; padding:4px 0 2px; margin:6px 0 0;">ASESMEN AWAL KEPERAWATAN RAWAT INAP</div>
  <div style="text-align:center; font-size:9px; color:#666; border-bottom:2px solid #0E3A66; padding-bottom:3px;">(Dilengkapi perawat dalam 24 jam pertama pasien masuk ruang rawat inap) — Masuk: {{tgl_masuk}}</div>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Alergi / Reaksi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alergi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Keadaan Umum</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{keadaan_umum}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pemeriksaan Fisik</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pemeriksaan_fisik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Skrining Risiko Cedera/Jatuh</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{risiko_jatuh}}</td></tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td colspan="2" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">PENILAIAN RISIKO DEKUBITUS (SKALA NORTON)</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; width:50%;">Keluhan Fisik: <strong>{{norton_fisik}}</strong></td><td style="border:1px solid #333; padding:4px 6px;">Status Mental: <strong>{{norton_mental}}</strong></td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px;">Aktifitas: <strong>{{norton_aktifitas}}</strong></td><td style="border:1px solid #333; padding:4px 6px;">Mobilitas: <strong>{{norton_mobilitas}}</strong></td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px;">Inkontinensia: <strong>{{norton_inkontinensia}}</strong></td><td style="border:1px solid #333; padding:4px 6px; background:#fff7ec;">Total Skor: <strong>{{norton_total}}</strong> — {{norton_interpretasi}}</td></tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Skala Nyeri</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">Skala: <strong>{{skala_nyeri}}</strong> / 10 &nbsp; {{nyeri_detail}}</td></tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td colspan="2" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">SKRINING GIZI (MST)</td></tr>
    <tr><td style="border:1px solid #333; padding:4px 6px; width:50%;">Penurunan BB: <strong>{{mst_bb}}</strong></td><td style="border:1px solid #333; padding:4px 6px;">Nafsu makan turun: <strong>{{mst_nafsu}}</strong></td></tr>
    <tr><td colspan="2" style="border:1px solid #333; padding:4px 6px; background:#fff7ec;">Total Skor MST: <strong>{{mst_total}}</strong> — {{mst_interpretasi}}</td></tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Status Fungsional</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{status_fungsional}}</td></tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td colspan="2" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">RENCANA PEMULANGAN PASIEN (DISCHARGE PLANNING)</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Estimasi Tanggal Pulang</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{estimasi_pulang}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Kebutuhan saat Pulang</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{kebutuhan_pulang}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Alat Medis / Perawatan Lanjutan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alat_bantu}}</td></tr>
  </table>

  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td colspan="2" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">RENCANA KEPERAWATAN</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Diagnosa Keperawatan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_kep}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Tujuan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{tujuan_kep}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Intervensi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{intervensi_kep}}</td></tr>
  </table>

  <table style="width:100%; margin-top:16px; font-size:11px;"><tr>
    <td style="width:58%;"></td>
    <td style="width:42%; text-align:center;">
      <div>Perawat Asesor,</div>
      <div style="min-height:84px; display:flex; align-items:center; justify-content:center;">{{ttd_perawat}}</div>
      <div style="border-top:1px solid #333; padding-top:3px;">Nama Jelas dan Tandatangan</div>
    </td>
  </tr></table>
</div>
HTML;

        $this->upsert('ASESMEN_AWAL_KEPERAWATAN_RI', [
            'name'                  => 'Asesmen Awal Keperawatan Rawat Inap',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SCORED_FORM,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'ranap', 'section' => 'asuhan_keperawatan', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // RANAP — Phase 2 (Tier 2 keselamatan/kepatuhan). Pola sama Phase 1: HYBRID,
    // field ber-`group`, TTD nakes `required:false`. INPUT mostly multi_checkbox
    // (static_payload) + sedikit prefill. Helper kop ringkas dibagi via closure.
    // ═════════════════════════════════════════════════════════════════════════

    /** Field kop+identitas standar RANAP (display_only) — dipakai 3 form Phase 2. */
    private function ranapKopFields(): array
    {
        $auto = fn (string $key, string $label, string $type, array $binding, array $extra = []) => array_merge(
            ['key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding],
            $extra
        );

        return [
            $auto('klinik_logo',   'Logo Klinik',  'image_url', ['kind' => 'clinic', 'source' => 'clinic.logo_path']) + ['max_height_px' => 64],
            $auto('klinik_nama',   'Nama Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.clinic_name']),
            $auto('klinik_alamat', 'Alamat Klinik','text',      ['kind' => 'clinic', 'source' => 'clinic.address']),
            $auto('klinik_telp',   'Telp Klinik',  'text',      ['kind' => 'clinic', 'source' => 'clinic.phone']),
            $auto('nama_pasien',   'Nama Pasien',  'text', ['kind' => 'db', 'source' => 'patient.name']),
            $auto('tgl_lahir',     'Tanggal Lahir','date', ['kind' => 'db', 'source' => 'patient.date_of_birth']),
            $auto('jenis_kelamin', 'L/P',          'text', ['kind' => 'db', 'source' => 'patient.gender']),
            $auto('no_rm',         'No. RM',       'text', ['kind' => 'db', 'source' => 'patient.no_rm']),
            $auto('nik',           'NIK',          'text', ['kind' => 'db', 'source' => 'patient.nik']),
        ];
    }

    /** Heredoc kop+identitas RANAP (dipakai 3 layout Phase 2). $kode = label form pojok. */
    private function ranapKopHtml(string $kode, string $judul): string
    {
        return <<<HTML
<table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
  <tr>
    <td style="vertical-align:top; width:58%;">
      <table style="border-collapse:collapse;"><tr>
        <td style="vertical-align:middle; padding-right:10px;">{{klinik_logo}}</td>
        <td style="vertical-align:middle;">
          <div style="font-size:16px; font-weight:700; color:#0E3A66; letter-spacing:.5px;">{{klinik_nama}}</div>
          <div style="font-size:9.5px; color:#444;">{{klinik_alamat}}</div>
          <div style="font-size:9.5px; color:#444;">Telp: {{klinik_telp}}</div>
        </td>
      </tr></table>
    </td>
    <td style="vertical-align:top; width:42%;">
      <div style="text-align:right; font-size:10px; color:#666; margin-bottom:2px;">{$kode}</div>
      <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:10.5px;">
        <tr><td style="padding:2px 5px; width:74px;">Nama</td><td style="padding:2px 5px;">: {{nama_pasien}}</td></tr>
        <tr><td style="padding:2px 5px;">Tgl. Lahir</td><td style="padding:2px 5px;">: {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</td></tr>
        <tr><td style="padding:2px 5px;">No. RM</td><td style="padding:2px 5px;">: {{no_rm}}</td></tr>
        <tr><td style="padding:2px 5px;">NIK</td><td style="padding:2px 5px;">: {{nik}}</td></tr>
      </table>
    </td>
  </tr>
</table>
<div style="text-align:center; font-weight:700; font-size:14px; border-top:2px solid #0E3A66; border-bottom:2px solid #0E3A66; padding:4px 0; margin:6px 0 8px;">{$judul}</div>
HTML;
    }

    /** Blok TTD nakes RANAP (perawat/apoteker) — heredoc. */
    private function ranapTtdHtml(string $peran, string $placeholder): string
    {
        return <<<HTML
<table style="width:100%; margin-top:16px; font-size:11px;"><tr>
  <td style="width:58%;"></td>
  <td style="width:42%; text-align:center;">
    <div>{$peran},</div>
    <div style="min-height:80px; display:flex; align-items:center; justify-content:center;">{{{$placeholder}}}</div>
    <div style="border-top:1px solid #333; padding-top:3px;">Nama Jelas dan Tandatangan</div>
  </td>
</tr></table>
HTML;
    }

    /**
     * RM 2.9/JTH — Pelaksanaan Pencegahan Pasien Jatuh (SKP 6). Perawat. Tingkat
     * risiko + intervensi standar/risiko-tinggi (multi_checkbox). TTD perawat.
     */
    private function seedPencegahanJatuh(): void
    {
        $docType = $this->requireDocType('RM-2.9-JTH');
        if (!$docType) return;

        $mcheck = fn (string $key, string $label, string $group, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox', 'group' => $group,
            'options' => $options, 'binding' => ['kind' => 'static'],
        ];
        $manual = fn (string $key, string $label, string $group, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group, 'binding' => ['kind' => 'static'],
        ];

        $fields = array_merge($this->ranapKopFields(), [
            ['key' => 'tingkat_risiko', 'label' => 'Tingkat Risiko Jatuh', 'type' => 'radio_with_detail',
             'group' => 'Tingkat Risiko', 'options' => ['Rendah', 'Sedang', 'Tinggi'], 'binding' => ['kind' => 'static']],
            $mcheck('intervensi_standar', 'Intervensi Standar (semua pasien)', 'Intervensi Standar', [
                'Orientasi ruangan & penggunaan bel',
                'Posisi tempat tidur rendah & terkunci',
                'Pagar pengaman terpasang',
                'Pencahayaan cukup',
                'Alas kaki anti-licin',
                'Barang kebutuhan dalam jangkauan',
            ]),
            $mcheck('intervensi_tinggi', 'Intervensi Risiko Tinggi', 'Intervensi Risiko Tinggi', [
                'Gelang risiko jatuh (kuning) terpasang',
                'Tanda/segitiga risiko jatuh di tempat tidur',
                'Pasien tidak ditinggalkan sendiri',
                'Pendampingan saat mobilisasi/ke kamar mandi',
                'Edukasi keluarga tentang pencegahan jatuh',
                'Evaluasi ulang risiko tiap shift',
            ]),
            $manual('catatan_jatuh', 'Catatan / Kejadian', 'Catatan'),
            ['key' => 'ttd_perawat', 'label' => 'Tanda Tangan Perawat', 'type' => 'signature_canvas',
             'signer_type' => 'nurse', 'required' => false, 'group' => 'Pengesahan', 'binding' => ['kind' => 'static']],
        ]);

        $kop = $this->ranapKopHtml('RM 2.9/JTH/22', 'PELAKSANAAN PENCEGAHAN PASIEN JATUH');
        $ttd = $this->ranapTtdHtml('Perawat', 'ttd_perawat');
        $layoutHtml = <<<HTML
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  {$kop}
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Tingkat Risiko Jatuh</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{tingkat_risiko}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Intervensi Standar</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{intervensi_standar}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Intervensi Risiko Tinggi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{intervensi_tinggi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Catatan / Kejadian</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{catatan_jatuh}}</td></tr>
  </table>
  {$ttd}
</div>
HTML;

        $this->upsert('PENCEGAHAN_JATUH_RI', [
            'name'                  => 'Pelaksanaan Pencegahan Pasien Jatuh',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'ranap', 'section' => 'keselamatan', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * RM 2.4/EDU — Edukasi Terintegrasi (MKE). Perawat/edukator. Hambatan + topik +
     * metode (multi_checkbox) + evaluasi pemahaman. TTD pasien DITUNDA (PSrE) →
     * hanya TTD nakes.
     */
    private function seedEdukasiTerintegrasi(): void
    {
        $docType = $this->requireDocType('RM-2.4-EDU');
        if (!$docType) return;

        $mcheck = fn (string $key, string $label, string $group, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox', 'group' => $group,
            'options' => $options, 'binding' => ['kind' => 'static'],
        ];
        $manual = fn (string $key, string $label, string $group, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group, 'binding' => ['kind' => 'static'],
        ];

        $fields = array_merge($this->ranapKopFields(), [
            $mcheck('hambatan', 'Hambatan Belajar', 'Hambatan', [
                'Tidak ada hambatan', 'Bahasa', 'Pendengaran', 'Penglihatan',
                'Kognitif / daya ingat', 'Emosi / motivasi', 'Hambatan fisik',
            ]),
            $mcheck('topik', 'Topik Edukasi', 'Topik', [
                'Penyakit & rencana perawatan', 'Penggunaan obat', 'Diet & nutrisi',
                'Perawatan luka', 'Penggunaan alat medis', 'Rehabilitasi / mobilisasi',
                'Manajemen nyeri', 'Pencegahan infeksi', 'Hak & kewajiban pasien',
            ]),
            $mcheck('metode', 'Metode Edukasi', 'Metode', [
                'Lisan / diskusi', 'Demonstrasi', 'Leaflet / brosur', 'Audiovisual',
            ]),
            ['key' => 'pemahaman', 'label' => 'Evaluasi Pemahaman', 'type' => 'radio_with_detail',
             'group' => 'Evaluasi', 'options' => ['Mengerti', 'Perlu pengulangan', 'Perlu demonstrasi ulang'], 'binding' => ['kind' => 'static']],
            $manual('sasaran_edukasi', 'Sasaran (Pasien / Keluarga, nama)', 'Evaluasi', 'text'),
            $manual('catatan_edukasi', 'Catatan Tambahan', 'Evaluasi'),
            ['key' => 'ttd_edukator', 'label' => 'Tanda Tangan Edukator', 'type' => 'signature_canvas',
             'signer_type' => 'nurse', 'required' => false, 'group' => 'Pengesahan', 'binding' => ['kind' => 'static']],
        ]);

        $kop = $this->ranapKopHtml('RM 2.4/EDU/22', 'FORMULIR EDUKASI TERINTEGRASI');
        $ttd = $this->ranapTtdHtml('Edukator / Perawat', 'ttd_edukator');
        $layoutHtml = <<<HTML
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  {$kop}
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Sasaran Edukasi</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{sasaran_edukasi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Hambatan Belajar</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{hambatan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Topik Edukasi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{topik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Metode</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{metode}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Evaluasi Pemahaman</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{pemahaman}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Catatan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{catatan_edukasi}}</td></tr>
  </table>
  {$ttd}
</div>
HTML;

        $this->upsert('EDUKASI_TERINTEGRASI_RI', [
            'name'                  => 'Edukasi Terintegrasi Rawat Inap',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'ranap', 'section' => 'edukasi', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * RM 2.7/REK — Rekonsiliasi Obat (PKPO/SKP 3). Farmasi/perawat. Daftar obat
     * yang dibawa pasien + sumber data + keputusan tindak lanjut. Prefill terapi
     * dari resep berjalan (referensi). TTD apoteker/perawat.
     */
    private function seedRekonsiliasiObat(): void
    {
        $docType = $this->requireDocType('RM-2.7-REK');
        if (!$docType) return;

        $mcheck = fn (string $key, string $label, string $group, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox', 'group' => $group,
            'options' => $options, 'binding' => ['kind' => 'static'],
        ];
        $manual = fn (string $key, string $label, string $group, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group, 'binding' => ['kind' => 'static'],
        ];
        $ed = fn (string $key, string $label, string $group, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];

        $fields = array_merge($this->ranapKopFields(), [
            $mcheck('sumber_data', 'Sumber Data Riwayat Obat', 'Sumber', [
                'Pasien', 'Keluarga', 'Obat yang dibawa', 'Resep terdahulu', 'Rekam medis', 'Tidak ada data',
            ]),
            $manual('obat_dibawa', 'Daftar Obat yang Sedang/Pernah Digunakan (nama · dosis · frekuensi · sejak kapan)', 'Riwayat Obat'),
            $manual('alergi_obat', 'Riwayat Alergi Obat', 'Riwayat Obat', 'text'),
            $ed('terapi_berjalan', 'Terapi Saat Ini (referensi resep berjalan)', 'Tindak Lanjut', ['via' => 'aggregate', 'source' => 'prescriptions', 'format' => 'items_pretty']),
            $manual('tindak_lanjut', 'Keputusan Rekonsiliasi (Lanjut / Stop / Ganti / Tunda — per obat)', 'Tindak Lanjut'),
            ['key' => 'ada_diskrepansi', 'label' => 'Ditemukan Diskrepansi?', 'type' => 'radio_with_detail',
             'group' => 'Tindak Lanjut', 'options' => ['Tidak', 'Ya'], 'binding' => ['kind' => 'static']],
            $manual('catatan_rekonsiliasi', 'Catatan / Konfirmasi ke DPJP', 'Tindak Lanjut'),
            ['key' => 'ttd_petugas', 'label' => 'Tanda Tangan Apoteker / Perawat', 'type' => 'signature_canvas',
             'signer_type' => 'nurse', 'required' => false, 'group' => 'Pengesahan', 'binding' => ['kind' => 'static']],
        ]);

        $kop = $this->ranapKopHtml('RM 2.7/REK/22', 'FORMULIR REKONSILIASI OBAT');
        $ttd = $this->ranapTtdHtml('Apoteker / Perawat', 'ttd_petugas');
        $layoutHtml = <<<HTML
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  {$kop}
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Sumber Data</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{sumber_data}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Riwayat Obat Digunakan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{obat_dibawa}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Riwayat Alergi Obat</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{alergi_obat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Terapi Saat Ini</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{terapi_berjalan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Diskrepansi</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{ada_diskrepansi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Keputusan Rekonsiliasi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{tindak_lanjut}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Catatan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{catatan_rekonsiliasi}}</td></tr>
  </table>
  {$ttd}
</div>
HTML;

        $this->upsert('REKONSILIASI_OBAT_RI', [
            'name'                  => 'Rekonsiliasi Obat',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'ranap', 'section' => 'obat', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // RANAP — Phase 3 (Tier 3 ARK: akses & kontinuitas). HYBRID, field ber-group,
    // TTD nakes `required:false`. TTD pasien/keluarga DITUNDA (PSrE).
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * RM 2.5/SPD — Surat Pengantar Untuk Dirawat Inap. Dokter IGD/poli. Surat
     * pendek: alasan dirawat + saran terapi + rencana tindakan. Prefill diagnosa
     * (ICD-10), terapi (resep), rencana (planning_instruction). TTD dokter.
     */
    private function seedSuratPengantarDirawat(): void
    {
        $docType = $this->requireDocType('RM-2.5-SPD');
        if (!$docType) return;

        $ed = fn (string $key, string $label, string $group, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $manual = fn (string $key, string $label, string $group, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group, 'binding' => ['kind' => 'static'],
        ];

        $fields = array_merge($this->ranapKopFields(), [
            ['key' => 'asal_ruangan', 'label' => 'Asal Ruangan', 'type' => 'radio_with_detail',
             'group' => 'Pengantar', 'options' => ['IGD', 'Poliklinik'], 'binding' => ['kind' => 'static']],
            $manual('rencana_perawatan', 'Rencana Perawatan Di (ruang/kelas)', 'Pengantar', 'text'),
            $ed('alasan_dirawat', 'Karena Menderita (alasan dirawat / diagnosa)', 'Klinis', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            // Dikosongkan (keputusan user): Saran Terapi & Rencana Tindakan diisi manual
            // dokter, tidak auto-isi dari resep / planning. Tanpa `prefill` tak ada refill saat TTD.
            $manual('saran_terapi', 'Saran Terapi', 'Klinis'),
            $manual('rencana_tindakan', 'Rencana Tindakan', 'Klinis'),
            ['key' => 'ttd_dokter', 'label' => 'Tanda Tangan Dokter', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => false, 'group' => 'Pengesahan', 'binding' => ['kind' => 'static']],
        ]);

        $kop = $this->ranapKopHtml('RM 2.5/SPD/22', 'SURAT PENGANTAR UNTUK DIRAWAT INAP');
        $ttd = $this->ranapTtdHtml('Dokter yang Memeriksa', 'ttd_dokter');
        $layoutHtml = <<<HTML
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  {$kop}
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:32%; vertical-align:top; font-weight:600;">Asal Ruangan</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{asal_ruangan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Rencana Perawatan Di</td><td style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{rencana_perawatan}}</td></tr>
  </table>
  <p style="margin:8px 0 4px;">Bersama ini kami kirimkan pasien tersebut di atas untuk dirawat inap:</p>
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:32%; vertical-align:top; font-weight:600;">Karena Menderita</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alasan_dirawat}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Saran Terapi</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{saran_terapi}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Rencana Tindakan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{rencana_tindakan}}</td></tr>
  </table>
  <p style="margin:8px 0;">Mohon ditindaklanjuti untuk rencana tindakan/terapi.</p>
  {$ttd}
</div>
HTML;

        $this->upsert('SURAT_PENGANTAR_DIRAWAT', [
            'name'                  => 'Surat Pengantar Untuk Dirawat Inap',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'ranap', 'section' => 'pengantar_dirawat', 'mode' => 'HYBRID'],
            ],
        ]);
    }

    /**
     * RM 2.6/TRF — Formulir Transfer Pasien (antar ruang/unit). DPJP/perawat.
     * Prefill diagnosa/DPJP/TTV; alasan & metode transfer (multi_checkbox); kondisi
     * pasien saat pindah. TTD perawat pengirim (TTD pasien/keluarga & penerima
     * ditunda — PSrE). Selaras transferBed() RanapService.
     */
    private function seedTransferPasien(): void
    {
        $docType = $this->requireDocType('RM-2.6-TRF');
        if (!$docType) return;

        $auto = fn (string $key, string $label, string $type, array $binding) => [
            'key' => $key, 'label' => $label, 'type' => $type, 'display_only' => true, 'binding' => $binding,
        ];
        $ed = fn (string $key, string $label, string $group, array $prefill, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group,
            'binding' => ['kind' => 'static'], 'prefill' => $prefill,
        ];
        $manual = fn (string $key, string $label, string $group, string $type = 'longtext') => [
            'key' => $key, 'label' => $label, 'type' => $type, 'group' => $group, 'binding' => ['kind' => 'static'],
        ];
        $mcheck = fn (string $key, string $label, string $group, array $options) => [
            'key' => $key, 'label' => $label, 'type' => 'multi_checkbox', 'group' => $group,
            'options' => $options, 'binding' => ['kind' => 'static'],
        ];

        $fields = array_merge($this->ranapKopFields(), [
            $auto('dpjp_nama', 'DPJP', 'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'dpjp']),
            $auto('ruang_asal_auto', 'Ruang Saat Ini', 'text', ['kind' => 'aggregate', 'source' => 'ranap_identity', 'format' => 'room_bed']),

            // ── Transfer ─────────────────────────────────────────────────────
            $manual('ruangan_selanjutnya', 'Ruangan Selanjutnya (tujuan)', 'Transfer', 'text'),
            $mcheck('alasan_transfer', 'Alasan Perpindahan', 'Transfer', [
                'Kondisi pasien memburuk', 'Kondisi pasien stabil', 'Tidak ada perubahan',
                'Fasilitas kurang memadai', 'Membutuhkan peralatan lebih baik',
                'Membutuhkan tenaga lebih ahli', 'Jumlah tenaga kurang',
            ]),
            $manual('alasan_lain', 'Alasan Lain', 'Transfer', 'text'),
            $mcheck('metode_transfer', 'Metode Perpindahan', 'Transfer', [
                'Kursi roda', 'Tempat tidur', 'Brankar / stretcher',
            ]),

            // ── Klinis ───────────────────────────────────────────────────────
            $ed('diagnosa_utama', 'Diagnosa Utama (ICD-10)', 'Klinis', ['via' => 'aggregate', 'source' => 'doctorExamination.icd10_diagnoses', 'format' => 'icd_with_desc_join_newline']),
            $manual('diagnosa_sekunder', 'Diagnosa Sekunder', 'Klinis'),
            $ed('perhatian_khusus', 'Perlu Perhatian (Alergi / MRSA / dll)', 'Klinis', ['via' => 'aggregate', 'source' => 'allergy']),
            $manual('pemeriksaan_fisik', 'Pemeriksaan Fisik (Status Generalis/Lokalis signifikan)', 'Klinis'),
            $ed('terapi_berjalan', 'Terapi / Intervensi Berjalan', 'Klinis', ['via' => 'aggregate', 'source' => 'prescriptions', 'format' => 'items_pretty']),

            // ── Kondisi Saat Pindah ──────────────────────────────────────────
            $manual('keadaan_umum', 'Keadaan Umum / Kesadaran', 'Kondisi Pindah', 'text'),
            $manual('status_nyeri', 'Status Nyeri', 'Kondisi Pindah', 'text'),
            $ed('nadi', 'Nadi (x/mnt)', 'Kondisi Pindah', ['via' => 'db', 'source' => 'nurseAssessment.nadi'], 'number'),
            $ed('suhu', 'Suhu (°C)', 'Kondisi Pindah', ['via' => 'db', 'source' => 'nurseAssessment.suhu'], 'number'),
            $ed('rr', 'Pernafasan (x/mnt)', 'Kondisi Pindah', ['via' => 'db', 'source' => 'nurseAssessment.respirasi'], 'number'),
            $manual('tekanan_darah', 'Tekanan Darah (mmHg)', 'Kondisi Pindah', 'text'),
            $mcheck('peralatan_menyertai', 'Peralatan yang Menyertai', 'Kondisi Pindah', [
                'Tidak ada', 'Portable O₂', 'Alat penghisap', 'Bagging', 'NGT',
                'Ventilator', 'Kateter urin', 'Pompa infus',
            ]),
            $manual('pendamping', 'Pendamping / Petugas Pengantar', 'Kondisi Pindah', 'text'),

            ['key' => 'ttd_perawat', 'label' => 'Tanda Tangan Perawat Pengirim', 'type' => 'signature_canvas',
             'signer_type' => 'nurse', 'required' => false, 'group' => 'Pengesahan', 'binding' => ['kind' => 'static']],
        ]);

        $kop = $this->ranapKopHtml('RM 2.6/TRF/22', 'FORMULIR TRANSFER PASIEN');
        $ttd = $this->ranapTtdHtml('Perawat Pengirim', 'ttd_perawat');
        $layoutHtml = <<<HTML
<div style="font-family: Arial, sans-serif; color:#111; font-size:12px; padding:18px;">
  {$kop}
  <table style="width:100%; border:1px solid #333; border-collapse:collapse; font-size:11px;">
    <tr>
      <td style="border:1px solid #333; padding:4px 6px; width:25%; font-weight:600;">DPJP</td><td style="border:1px solid #333; padding:4px 6px;">{{dpjp_nama}}</td>
      <td style="border:1px solid #333; padding:4px 6px; width:25%; font-weight:600;">Ruang Asal</td><td style="border:1px solid #333; padding:4px 6px;">{{ruang_asal_auto}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:4px 6px; font-weight:600;">Ruangan Selanjutnya</td><td colspan="3" style="border:1px solid #333; padding:4px 6px;">{{ruangan_selanjutnya}}</td>
    </tr>
  </table>
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td style="border:1px solid #333; padding:5px 6px; width:30%; vertical-align:top; font-weight:600;">Diagnosa Utama</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_utama}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Diagnosa Sekunder</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{diagnosa_sekunder}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Perlu Perhatian</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{perhatian_khusus}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Alasan Perpindahan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{alasan_transfer}}<br>{{alasan_lain}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Metode Perpindahan</td><td style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{metode_transfer}}</td></tr>
  </table>
  <table style="width:100%; border:1px solid #333; border-top:none; border-collapse:collapse; font-size:11px;">
    <tr><td colspan="4" style="border:1px solid #333; padding:3px 6px; background:#eef3f8; font-weight:700;">KEADAAN PASIEN SAAT PINDAH</td></tr>
    <tr>
      <td style="border:1px solid #333; padding:4px 6px; width:50%;">Keadaan Umum / Kesadaran: <strong>{{keadaan_umum}}</strong></td>
      <td style="border:1px solid #333; padding:4px 6px;" colspan="3">Status Nyeri: <strong>{{status_nyeri}}</strong></td>
    </tr>
    <tr>
      <td style="border:1px solid #333; padding:4px 6px;">TD: <strong>{{tekanan_darah}}</strong> mmHg</td>
      <td style="border:1px solid #333; padding:4px 6px;">Nadi: <strong>{{nadi}}</strong> x/mnt</td>
      <td style="border:1px solid #333; padding:4px 6px;">Suhu: <strong>{{suhu}}</strong> °C</td>
      <td style="border:1px solid #333; padding:4px 6px;">RR: <strong>{{rr}}</strong> x/mnt</td>
    </tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pemeriksaan Fisik</td><td colspan="3" style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{pemeriksaan_fisik}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Terapi / Intervensi</td><td colspan="3" style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{terapi_berjalan}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Peralatan Menyertai</td><td colspan="3" style="border:1px solid #333; padding:5px 6px; white-space:pre-line; vertical-align:top;">{{peralatan_menyertai}}</td></tr>
    <tr><td style="border:1px solid #333; padding:5px 6px; vertical-align:top; font-weight:600;">Pendamping</td><td colspan="3" style="border:1px solid #333; padding:5px 6px; vertical-align:top;">{{pendamping}}</td></tr>
  </table>
  {$ttd}
</div>
HTML;

        $this->upsert('TRANSFER_PASIEN_RI', [
            'name'                  => 'Formulir Transfer Pasien',
            'document_type_id'      => $docType->id,
            'kind'                  => DocumentTemplate::KIND_HYBRID,
            'complexity_kind'       => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
            'layout_html'           => $layoutHtml,
            'field_schema'          => ['layout_mode' => 'single_page', 'fields' => $fields],
            'station_assignments'   => [
                ['station' => 'ranap', 'section' => 'transfer', 'mode' => 'HYBRID'],
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
