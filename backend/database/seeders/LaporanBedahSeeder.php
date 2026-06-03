<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * RM-5.3 — Laporan Operasi (PAB STARKES 2024 / WHO Surgical Safety Checklist).
 *
 * Menerbitkan DocumentTemplate OUTPUT untuk laporan operasi bedah agar laporan
 * yang difinalisasi di modul Bedah (BedahService::finalizeRecord) menjadi
 * PatientDocument resmi: masuk antrean TTD Dokumen (PIN → stempel digital + QR)
 * dan tampil di modul Rekam Medis.
 *
 * Dua penandatangan terpisah:
 *   - {{ttd_doctor}}          → DPJP / operator (signer_type 'doctor')   — WAJIB
 *   - {{ttd_doctor_anestesi}} → dokter anestesi (signer_type 'doctor_anestesi')
 *                               — WAJIB hanya bila operasi pakai anestesi (GA /
 *                               ada anestesiolog). Operasi topikal/lokal tanpa
 *                               anestesiolog → slot ini di-set required=false
 *                               per-dokumen lewat signatures.field_schema_override.
 *
 * Isi laporan (identitas + diagnosis + tim + teknik/temuan + komplikasi + EBL +
 * implan IOL + checklist WHO + Aldrete + instruksi pasca-op) di-inject sebagai
 * static_payload oleh BedahService::buildLaporanBedahPayload(). Identitas pasien
 * & kop klinik auto-bind (db/clinic).
 *
 * Idempoten. Jalankan manual:  php artisan db:seed --class=LaporanBedahSeeder
 */
class LaporanBedahSeeder extends Seeder
{
    public function run(): void
    {
        $docType = $this->seedDocType();
        $this->seedTemplate($docType);
    }

    /**
     * DocumentType RM-5.3 sudah ada di DocumentTypeSeeder (1 TTD DOKTER).
     * updateOrCreate menambah slot ANESTESI (is_required=false → kondisional)
     * tanpa menggandakan baris.
     */
    private function seedDocType(): DocumentType
    {
        return DocumentType::updateOrCreate(
            ['code' => 'RM-5.3'],
            [
                'name'                => 'Laporan Operasi',
                'fill_frequency'      => 'PER_EPISODE',
                'generate_type'       => 'MANUAL',
                'category'            => 'BEDAH',
                'required_signatures' => [
                    ['role' => 'DPJP',     'sign_type' => 'digital', 'is_required' => true],
                    ['role' => 'ANESTESI', 'sign_type' => 'digital', 'is_required' => false],
                ],
                'show_in_rme'         => true,
                'sort_order'          => 13,
                'is_active'           => true,
            ]
        );
    }

    private function seedTemplate(DocumentType $docType): void
    {
        // Identitas & kop = auto-bind. Seluruh isi laporan = static (diisi payload).
        $staticKeys = [
            'tgl_operasi', 'time_in', 'time_out', 'durasi', 'ruang_ok',
            'diagnosis_pre', 'diagnosis_post', 'prosedur', 'jenis_anestesi',
            'operator', 'asisten', 'anesthesiologist', 'scrub_nurse', 'circ_nurse',
            'teknik', 'temuan', 'komplikasi', 'ebl', 'vitrektomi',
            'implan_iol', 'sign_in', 'time_out_checklist', 'sign_out',
            'aldrete', 'instruksi_pasca', 'disposisi',
        ];

        $fields = [
            // ── Identitas pasien (auto-bind) ──
            ['key' => 'nama_pasien',  'label' => 'Nama Pasien',     'type' => 'text', 'required' => true,
             'binding' => ['kind' => 'db', 'source' => 'patient.name']],
            ['key' => 'tgl_lahir',    'label' => 'Tanggal Lahir',   'type' => 'date',
             'binding' => ['kind' => 'db', 'source' => 'patient.date_of_birth']],
            ['key' => 'jenis_kelamin','label' => 'Jenis Kelamin',   'type' => 'enum_gender',
             'binding' => ['kind' => 'db', 'source' => 'patient.gender']],
            ['key' => 'no_rm',        'label' => 'No. Rekam Medis', 'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.no_rm']],
            ['key' => 'nik',          'label' => 'NIK',             'type' => 'text',
             'binding' => ['kind' => 'db', 'source' => 'patient.nik']],

            // ── Kop klinik (auto-resolve) ──
            ['key' => 'clinic_logo', 'label' => 'Logo Klinik', 'type' => 'image_url', 'max_height_px' => 70,
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.logo_path']],
            ['key' => 'clinic_name', 'label' => 'Nama Klinik', 'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'clinic_addr', 'label' => 'Alamat Klinik', 'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],

            // ── Tanda tangan (signature_canvas → embed stempel PIN saat finalize) ──
            ['key' => 'ttd_doctor', 'label' => 'Tanda Tangan DPJP / Operator', 'type' => 'signature_canvas',
             'signer_type' => 'doctor', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'ttd_doctor_anestesi', 'label' => 'Tanda Tangan Dokter Anestesi', 'type' => 'signature_canvas',
             'signer_type' => 'doctor_anestesi', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],
        ];

        // Isi laporan = static fields (diisi via static_payload BedahService).
        foreach ($staticKeys as $k) {
            $fields[] = ['key' => $k, 'label' => $k, 'type' => 'longtext',
                'binding' => ['kind' => 'static', 'value' => null]];
        }

        DocumentTemplate::updateOrCreate(
            ['code' => 'RM_BEDAH_LAPORAN'],
            [
                'name'                => 'Laporan Operasi',
                'document_type_id'    => $docType->id,
                'kind'                => DocumentTemplate::KIND_OUTPUT,
                'complexity_kind'     => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
                'layout_html'         => $this->layoutHtml(),
                'field_schema'        => ['layout_mode' => 'single_page', 'fields' => $fields],
                'station_assignments' => [
                    ['station' => 'bedah', 'section' => 'laporan', 'mode' => 'OUTPUT'],
                ],
                'page_size'           => 'A4',
                'orientation'         => 'portrait',
                'version'             => 1,
                'is_active'           => true,
                'code_locked_at'      => now(),
            ]
        );

        $this->command?->info('Template RM_BEDAH_LAPORAN siap (DocumentType RM-5.3).');
    }

    /** Layout A4 Laporan Operasi — placeholder {{key}} diisi static_payload + binding. */
    private function layoutHtml(): string
    {
        return <<<'HTML'
<div style="font-family: 'Times New Roman', serif; font-size: 12px; line-height: 1.45; color: #000; padding: 8px;">

  <!-- Kop + kode form + identitas -->
  <table style="width:100%; border-collapse:collapse; margin-bottom:8px;">
    <tr>
      <td style="width:62%; vertical-align:top; padding:0 8px 0 0;">
        <div style="display:flex; align-items:center; gap:10px;">
          <div>{{clinic_logo}}</div>
          <div>
            <div style="font-weight:bold; font-size:14px;">{{clinic_name}}</div>
            <div style="font-size:10px;">{{clinic_addr}}</div>
          </div>
        </div>
      </td>
      <td style="width:38%; vertical-align:top; border:1px solid #000; padding:6px; font-size:11px;">
        <div>Nama&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{nama_pasien}}</div>
        <div>Tgl. Lahir : {{tgl_lahir}} &nbsp; {{jenis_kelamin}}</div>
        <div>No. RM&nbsp;&nbsp;&nbsp;: {{no_rm}}</div>
        <div>NIK&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{nik}}</div>
      </td>
    </tr>
  </table>
  <div style="text-align:right; font-size:10px;">RM 5.3/LAP-OP/22</div>

  <h2 style="text-align:center; margin:6px 0 10px; font-size:16px;">LAPORAN OPERASI</h2>

  <!-- Identitas operasi -->
  <table style="width:100%; border-collapse:collapse; font-size:11.5px;">
    <tr>
      <td style="border:1px solid #000; padding:4px; width:18%; font-weight:bold;">Tanggal Operasi</td>
      <td style="border:1px solid #000; padding:4px; width:32%;">{{tgl_operasi}}</td>
      <td style="border:1px solid #000; padding:4px; width:18%; font-weight:bold;">Ruang OK</td>
      <td style="border:1px solid #000; padding:4px; width:32%;">{{ruang_ok}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #000; padding:4px; font-weight:bold;">Time In</td>
      <td style="border:1px solid #000; padding:4px;">{{time_in}}</td>
      <td style="border:1px solid #000; padding:4px; font-weight:bold;">Time Out</td>
      <td style="border:1px solid #000; padding:4px;">{{time_out}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #000; padding:4px; font-weight:bold;">Durasi</td>
      <td style="border:1px solid #000; padding:4px;">{{durasi}}</td>
      <td style="border:1px solid #000; padding:4px; font-weight:bold;">Jenis Anestesi</td>
      <td style="border:1px solid #000; padding:4px;">{{jenis_anestesi}}</td>
    </tr>
  </table>

  <!-- Diagnosis -->
  <table style="width:100%; border-collapse:collapse; font-size:11.5px; margin-top:6px;">
    <tr>
      <td style="border:1px solid #000; padding:4px; width:18%; font-weight:bold;">Diagnosis Pra-Bedah</td>
      <td style="border:1px solid #000; padding:4px;">{{diagnosis_pre}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #000; padding:4px; font-weight:bold;">Diagnosis Pasca-Bedah</td>
      <td style="border:1px solid #000; padding:4px;">{{diagnosis_post}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #000; padding:4px; font-weight:bold;">Nama Prosedur</td>
      <td style="border:1px solid #000; padding:4px;">{{prosedur}}</td>
    </tr>
  </table>

  <!-- Tim bedah -->
  <p style="font-weight:bold; margin:10px 0 4px;">Tim Bedah</p>
  <table style="width:100%; border-collapse:collapse; font-size:11.5px;">
    <tr>
      <td style="border:1px solid #000; padding:4px; width:18%; font-weight:bold;">Operator (DPJP)</td>
      <td style="border:1px solid #000; padding:4px; width:32%;">{{operator}}</td>
      <td style="border:1px solid #000; padding:4px; width:18%; font-weight:bold;">Anestesiologis</td>
      <td style="border:1px solid #000; padding:4px; width:32%;">{{anesthesiologist}}</td>
    </tr>
    <tr>
      <td style="border:1px solid #000; padding:4px; font-weight:bold;">Asisten</td>
      <td style="border:1px solid #000; padding:4px;">{{asisten}}</td>
      <td style="border:1px solid #000; padding:4px; font-weight:bold;">Scrub / Circulating</td>
      <td style="border:1px solid #000; padding:4px;">{{scrub_nurse}} / {{circ_nurse}}</td>
    </tr>
  </table>

  <!-- Laporan tindakan -->
  <p style="font-weight:bold; margin:10px 0 4px;">Laporan Tindakan</p>
  <table style="width:100%; border-collapse:collapse; font-size:11.5px;">
    <tr><td style="border:1px solid #000; padding:4px; width:22%; font-weight:bold;">Teknik Operasi</td><td style="border:1px solid #000; padding:4px;">{{teknik}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Temuan Intraoperatif</td><td style="border:1px solid #000; padding:4px;">{{temuan}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Komplikasi</td><td style="border:1px solid #000; padding:4px;">{{komplikasi}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Estimasi Perdarahan (EBL)</td><td style="border:1px solid #000; padding:4px;">{{ebl}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Detail Vitrektomi</td><td style="border:1px solid #000; padding:4px;">{{vitrektomi}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Implan IOL Terpasang</td><td style="border:1px solid #000; padding:4px;">{{implan_iol}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Disposisi Pasca-Operasi</td><td style="border:1px solid #000; padding:4px;">{{disposisi}}</td></tr>
  </table>

  <!-- WHO Surgical Safety Checklist -->
  <p style="font-weight:bold; margin:10px 0 4px;">WHO Surgical Safety Checklist</p>
  <table style="width:100%; border-collapse:collapse; font-size:11.5px;">
    <tr><td style="border:1px solid #000; padding:4px; width:22%; font-weight:bold;">Sign In</td><td style="border:1px solid #000; padding:4px;">{{sign_in}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Time Out</td><td style="border:1px solid #000; padding:4px;">{{time_out_checklist}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Sign Out</td><td style="border:1px solid #000; padding:4px;">{{sign_out}}</td></tr>
  </table>

  <!-- Pemulihan & instruksi -->
  <table style="width:100%; border-collapse:collapse; font-size:11.5px; margin-top:6px;">
    <tr><td style="border:1px solid #000; padding:4px; width:22%; font-weight:bold;">Skor Pemulihan (Aldrete)</td><td style="border:1px solid #000; padding:4px;">{{aldrete}}</td></tr>
    <tr><td style="border:1px solid #000; padding:4px; font-weight:bold;">Instruksi Pasca-Operasi</td><td style="border:1px solid #000; padding:4px;">{{instruksi_pasca}}</td></tr>
  </table>

  <!-- Tanda tangan: DPJP + Anestesi -->
  <table style="width:100%; border-collapse:collapse; margin-top:16px; text-align:center; font-size:12px;">
    <tr>
      <td style="width:50%; vertical-align:top;">
        Dokter Operator / DPJP
        <div style="height:80px; display:flex; align-items:flex-end; justify-content:center;">{{ttd_doctor}}</div>
        <div style="border-top:1px solid #000; display:inline-block; padding-top:2px; min-width:60%;">{{operator}}</div>
        <div style="font-size:10px;">Nama dan Tandatangan</div>
      </td>
      <td style="width:50%; vertical-align:top;">
        Dokter Anestesi
        <div style="height:80px; display:flex; align-items:flex-end; justify-content:center;">{{ttd_doctor_anestesi}}</div>
        <div style="border-top:1px solid #000; display:inline-block; padding-top:2px; min-width:60%;">{{anesthesiologist}}</div>
        <div style="font-size:10px;">Nama dan Tandatangan</div>
      </td>
    </tr>
  </table>

</div>
HTML;
    }
}
