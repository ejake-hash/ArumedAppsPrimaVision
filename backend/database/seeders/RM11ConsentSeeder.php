<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * RM 1.1 — Persetujuan Umum (General Consent) RS Khusus Mata Prima Vision Medan.
 *
 * Dibuat ulang dari dokumen asli "RM 1.1 PERSETUJUAN UMUM PASIEN KELUARGA.docx"
 * (Docs/EMR TEMPLATE/). Teks naratif dipertahankan VERBATIM (5 bagian:
 * Perawatan & Pengobatan, Pelepasan Informasi, Hak & Tanggung Jawab Pasien,
 * Informasi Rawat Inap, Biaya Perawatan) — tidak diringkas.
 *
 * Template kind=OUTPUT: identitas pasien auto-bind dari data pasien, isian
 * tambahan (orang yang diberi wewenang, tanggal/jam, dua tanda tangan) diisi
 * saat dokumen disiapkan/ditandatangani.
 *
 * Jalankan manual:  php artisan db:seed --class=RM11ConsentSeeder
 */
class RM11ConsentSeeder extends Seeder
{
    public function run(): void
    {
        $docType = $this->seedDocType();
        $this->seedTemplate($docType);
    }

    private function seedDocType(): DocumentType
    {
        return DocumentType::updateOrCreate(
            ['code' => 'RM-1.1'],
            [
                'name'                => 'Formulir Persetujuan Umum (General Consent)',
                'fill_frequency'      => 'ONCE_LIFETIME',
                'generate_type'       => 'MANUAL',
                'category'            => 'ADMINISTRASI',
                'required_signatures' => [
                    ['role' => 'PASIEN',  'sign_type' => 'digital', 'is_required' => true],
                    ['role' => 'PETUGAS', 'sign_type' => 'digital', 'is_required' => true],
                ],
                'show_in_rme'         => true,
                'sort_order'          => 1,
                'is_active'           => true,
            ]
        );
    }

    private function seedTemplate(DocumentType $docType): void
    {
        $fields = [
            // ── Identitas pasien (auto-bind dari data pasien) ──
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

            // ── Keluarga yang berwenang menerima informasi (static, isi saat penyiapan) ──
            ['key' => 'keluarga_nama_1', 'label' => 'Nama Keluarga (1)',     'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'keluarga_telp_1', 'label' => 'No. Telp Keluarga (1)', 'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'keluarga_nama_2', 'label' => 'Nama Keluarga (2)',     'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'keluarga_telp_2', 'label' => 'No. Telp Keluarga (2)', 'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'keluarga_nama_3', 'label' => 'Nama Keluarga (3)',     'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'keluarga_telp_3', 'label' => 'No. Telp Keluarga (3)', 'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'tempat_tanggal', 'label' => 'Medan, Tgl/Jam', 'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],

            // ── Nama penanda tangan (static) ──
            ['key' => 'nama_pemberi_informasi', 'label' => 'Nama Pemberi Informasi (Petugas RS)', 'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'nama_penerima_informasi', 'label' => 'Nama Penerima Informasi (Pasien/Keluarga)', 'type' => 'text',
             'binding' => ['kind' => 'static', 'value' => null]],

            // ── Tanda tangan (signature_canvas → embed SVG saat finalize) ──
            // Penerima (pasien/wali) WAJIB; pemberi info (petugas) opsional —
            // boleh menyusul. signer_type 'staff' = enum valid DocumentSignature.
            ['key' => 'ttd_penerima', 'label' => 'Tanda Tangan Penerima Informasi (Pasien/Keluarga)', 'type' => 'signature_canvas',
             'signer_type' => 'patient', 'required' => true,
             'binding' => ['kind' => 'static', 'value' => null]],
            ['key' => 'ttd_pemberi',  'label' => 'Tanda Tangan Pemberi Informasi (Petugas)', 'type' => 'signature_canvas',
             'signer_type' => 'staff', 'required' => false,
             'binding' => ['kind' => 'static', 'value' => null]],

            // ── Kop klinik (auto-resolve) ──
            ['key' => 'clinic_logo', 'label' => 'Logo Klinik', 'type' => 'image_url', 'max_height_px' => 70,
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.logo_path']],
            ['key' => 'clinic_name', 'label' => 'Nama Klinik', 'type' => 'text',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.clinic_name']],
            ['key' => 'clinic_addr', 'label' => 'Alamat Klinik', 'type' => 'longtext',
             'binding' => ['kind' => 'clinic', 'source' => 'clinic.address']],
        ];

        $layoutHtml = $this->layoutHtml();

        DocumentTemplate::updateOrCreate(
            ['code' => 'RM_1_1_GENERAL_CONSENT'],
            [
                'name'                => 'Persetujuan Umum (General Consent)',
                'document_type_id'    => $docType->id,
                'kind'                => DocumentTemplate::KIND_OUTPUT,
                'complexity_kind'     => DocumentTemplate::COMPLEXITY_SIMPLE_BINDING,
                'layout_html'         => $layoutHtml,
                'field_schema'        => ['layout_mode' => 'single_page', 'fields' => $fields],
                'station_assignments' => [
                    ['station' => 'admisi', 'section' => 'identitas', 'mode' => 'OUTPUT'],
                ],
                'page_size'           => 'A4',
                'orientation'         => 'portrait',
                'version'             => 1,
                'is_active'           => true,
                'code_locked_at'      => now(),
            ]
        );

        $this->command?->info('Template RM_1_1_GENERAL_CONSENT siap (DocumentType RM-1.1).');
    }

    /**
     * Layout A4 — teks consent VERBATIM dari dokumen asli RM 1.1/PU(GJ)/22.
     */
    private function layoutHtml(): string
    {
        return <<<'HTML'
<div style="font-family: 'Times New Roman', serif; font-size: 12px; line-height: 1.45; color: #000; padding: 8px;">

  <!-- Kop surat + kode form + identitas -->
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
  <div style="text-align:right; font-size:10px;">RM 1.1/PU(GJ)/22</div>

  <h2 style="text-align:center; margin:6px 0 2px; font-size:16px;">PERSETUJUAN UMUM</h2>
  <h3 style="text-align:center; margin:0 0 10px; font-size:13px; font-weight:normal;">(GENERAL CONSENT)</h3>

  <p style="font-weight:bold; text-transform:uppercase; margin:8px 0;">
    Pasien/Keluarga dan atau Wali Hukum harus membaca, memahami dan mengisi informasi berikut:
  </p>

  <!-- 1. PERSETUJUAN UNTUK PERAWATAN DAN PENGOBATAN -->
  <p style="font-weight:bold; margin:10px 0 4px;">1. PERSETUJUAN UNTUK PERAWATAN DAN PENGOBATAN</p>
  <p style="text-align:justify; margin:4px 0;">
    Saya menyetujui untuk mendapatkan perawatan di Rumah Sakit Khusus Prima Vision Medan sebagai
    pasien rawat jalan/rawat inap tergantung kepada kebutuhan medis. Pengobatan dapat meliputi
    pemeriksaan visus, tonometri dan prosedur rutin seperti cairan infuse atau suntikan dan evaluasi
    (wawancara dan pemeriksaan fisik).
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Persetujuan yang saya berikan tidak termasuk persetujuan untuk prosedur/tindakan invasif
    (misalnya operasi) atau tindakan yang mempunyai resiko tinggi.
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Jika saya memutuskan untuk menghentikan perawatan medis untuk diri saya, saya memahami dan
    menyadari bahwa Rumah Sakit Khusus Mata Prima Vision ataupun dokter tidak bertanggung jawab atau
    hasil yang merugikan saya.
  </p>

  <!-- 2. PERSETUJUAN PERLEPASAN INFORMASI -->
  <p style="font-weight:bold; margin:10px 0 4px;">2. PERSETUJUAN PERLEPASAN INFORMASI</p>
  <p style="text-align:justify; margin:4px 0;">
    Saya memahami informasi yang ada di dalam Saya, termasuk Diagnosis, hasil laboratorium dan hasil
    tes diagnostik yang digunakan untuk perawatan medis, Rumah Sakit Khusus Mata Prima Vision Medan
    akan menjamin kerahasiaannya.
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Saya memberi wewenang kepada Rumah Sakit Khusus Mata Prima Vision Medan untuk memberikan informasi
    tentang diagnosis, hasil pelayanan dan pengobatan bila diperlukan untuk memproses klaim
    asuransi/perusahaan atau lembaga pemerintah.
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Saya memberi wewenang kepada Rumah Sakit Khusus Mata Prima Vision Medan untuk memberikan informasi
    tentang diagnosis, hasil pelayanan dan pengobatan saya kepada anggota keluarga saya dan kepada:
  </p>
  <table style="width:100%; border-collapse:collapse; margin:4px 0 4px 24px; font-size:12px;">
    <tr>
      <td style="width:60%; padding:2px 8px 2px 0;">Nama: <span style="display:inline-block; min-width:55%; border-bottom:1px solid #000;">&nbsp;{{keluarga_nama_1}}</span></td>
      <td style="width:40%; padding:2px 0;">No. Telp: <span style="display:inline-block; min-width:55%; border-bottom:1px solid #000;">&nbsp;{{keluarga_telp_1}}</span></td>
    </tr>
    <tr>
      <td style="padding:2px 8px 2px 0;">Nama: <span style="display:inline-block; min-width:55%; border-bottom:1px solid #000;">&nbsp;{{keluarga_nama_2}}</span></td>
      <td style="padding:2px 0;">No. Telp: <span style="display:inline-block; min-width:55%; border-bottom:1px solid #000;">&nbsp;{{keluarga_telp_2}}</span></td>
    </tr>
    <tr>
      <td style="padding:2px 8px 2px 0;">Nama: <span style="display:inline-block; min-width:55%; border-bottom:1px solid #000;">&nbsp;{{keluarga_nama_3}}</span></td>
      <td style="padding:2px 0;">No. Telp: <span style="display:inline-block; min-width:55%; border-bottom:1px solid #000;">&nbsp;{{keluarga_telp_3}}</span></td>
    </tr>
  </table>

  <!-- 3. HAK DAN TANGGUNG JAWAB PASIEN -->
  <p style="font-weight:bold; margin:10px 0 4px;">3. HAK DAN TANGGUNG JAWAB PASIEN</p>
  <p style="text-align:justify; margin:4px 0;">
    Saya memiliki hak untuk mengambil bagian dalam keputusan mengenai penyakit saya dalam hal
    perawatan medis dan rencana pengobatan.
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Saya telah mendapat informasi tentang Hak dan Tanggung Jawab Pasien Rumah Sakit Khusus Mata Prima
    Vision Medan melalui leaflet dan banner yang disediakan petugas.
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Saya memahami bahwa Rumah Sakit Khusus Mata Prima Vision Medan tidak bertanggung jawab atas
    kehilangan barang-barang pribadi dan barang yang dibawa ke Rumah Sakit Khusus Mata Prima Vision
    Medan.
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Saya memahami bahwa Rumah Sakit Khusus Mata Prima Vision Medan tidak memperbolehkan
    pendokumentasian semua tindakan yang dilakukan di Rumah Sakit Khusus Mata Prima Vision, baik
    berbentuk Foto / Video / Audio (sesuai UU Praktik Kedokteran No.29/2004 pasal 48 dan 51, UU
    Telekomunikasi No.36/1999)
  </p>

  <!-- 4. INFORMASI RAWAT INAP -->
  <p style="font-weight:bold; margin:10px 0 4px;">4. INFORMASI RAWAT INAP</p>
  <p style="text-align:justify; margin:4px 0;">
    Saya telah menerima informasi tentang peraturan yang telah diberlakukan oleh Rumah Sakit Khusus
    Mata Prima Vision Medan dan saya beserta keluarga bersedia untuk mematuhinya, termasuk mematuhi
    berkunjung pasien sesuai dengan aturan di Rumah Sakit Khusus Mata Prima Vision Medan.
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Anggota keluarga saya yang menunggu saya bersedia untuk selalu memakai tanda pengenal khusus yang
    diberikan Rumah Sakit Khusus Mata Prima Vision Medan, dan demi keamanan seluruh pasien setiap
    keluarga dan siapapun yang akan mengunjungi saya diluar jam berkunjung bersedia untuk
    diminta/diperiksa identitasnya dan memakai identitas yang diberikan Rumah Sakit Khusus Mata Prima
    Vision Medan.
  </p>
  <p style="text-align:justify; margin:4px 0;">
    Saya memahami bahwa saya dapat memilih turun kelas perawatan apabila kamar perawatan yang menjadi
    hak saya sesuai fasilitas kartu BPJS saya tidak akan tersedia. Dan saya telah memahami apa yang
    menjadi kewajiban dan hak saya memilih hal tersebut. Dan saya bersedia mengikuti peraturan Rumah
    Sakit Khusus Mata Prima Vision Medan yang berlaku saat ini.
  </p>

  <!-- 5. BIAYA PERAWATAN -->
  <p style="font-weight:bold; margin:10px 0 4px;">5. BIAYA PERAWATAN</p>
  <ul style="text-align:justify; margin:4px 0; padding-left:22px;">
    <li style="margin:4px 0;">
      Saya menyatakan setuju sebagai pasien/penanggung jawab dengan status umum untuk membayar total
      biaya perawatan yang diberikan sesuai rincian biaya dan ketentuan Rumah Sakit Khusus Mata Prima
      Vision Medan.
    </li>
    <li style="margin:4px 0;">
      Saya menyatakan setuju sebagai pasien/penanggung jawab pasien dengan biaya ditanggung penjamin
      untuk segera melengkapi berkas persyaratan administrasi paling lambat 3x24 jam.
    </li>
  </ul>
  <p style="text-align:justify; margin:8px 0;">
    Saya telah membaca isi dari pernyataan ini/telah dibacakan isi dari pernyataan ini, dan saya telah
    memahami isi dari pernyataan ini. Dan semua pernyataan saya telah dijawab dengan jelas.
  </p>

  <!-- Tanda tangan -->
  <p style="margin:14px 0 4px;">Medan, {{tempat_tanggal}}</p>
  <table style="width:100%; border-collapse:collapse; margin-top:6px; text-align:center; font-size:12px;">
    <tr>
      <td style="width:50%; vertical-align:top;">
        Pemberi Informasi dari<br>RS Khusus Mata Prima Vision
        <div style="height:70px; display:flex; align-items:flex-end; justify-content:center;">{{ttd_pemberi}}</div>
        <div style="border-top:1px solid #000; display:inline-block; padding-top:2px; min-width:60%;">
          {{nama_pemberi_informasi}}
        </div>
        <div style="font-size:10px;">Nama dan Tandatangan</div>
      </td>
      <td style="width:50%; vertical-align:top;">
        Penerima Informasi<br>(Pasien/Keluarga Pasien)
        <div style="height:70px; display:flex; align-items:flex-end; justify-content:center;">{{ttd_penerima}}</div>
        <div style="border-top:1px solid #000; display:inline-block; padding-top:2px; min-width:60%;">
          {{nama_penerima_informasi}}
        </div>
        <div style="font-size:10px;">Nama dan Tandatangan</div>
      </td>
    </tr>
  </table>

</div>
HTML;
    }
}
