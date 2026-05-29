# Form Registry — Design Document
### Arumed Apps · Klinik Mata Arunika

> **Status**: Final Draft v1.0  
> **Tanggal**: 2026-05-26  
> **Scope**: Modul form rekam medis — template, parsing, rendering, signature, audit  
> **Dikerjakan**: Setelah seluruh modul EMR core selesai & stabil  
> **Source of truth**: Dokumen ini. Update tiap ada keputusan baru.

---

## 1. Problem Statement

Arumed Apps sudah punya modul EMR lengkap (Admisi, Perawat, Refraksi, Dokter, Bedah, Farmasi, Kasir). Tapi **form rekam medis sebagai dokumen cetak/arsip belum punya skema yang seragam**. Form fisik (RM 1.1, RM 1.3, RM 1.7, RM 2.2, dll) saat ini dicetak manual di luar sistem.

**Yang ingin dicapai:**
- Admin upload file Word (.docx) → sistem parse struktur → admin setup binding → form aktif
- Semua template form terdaftar di master data, station tinggal panggil by code
- Form bisa mode INPUT (entry data) atau OUTPUT (render & cetak)
- Dokumen yang sudah ditandatangani tersimpan immutable dengan audit trail lengkap

**Yang TIDAK dibangun:**
- Form builder real-time untuk end user
- Generic EAV all-in-one table
- Duplikasi data klinis (form hanya binding ke EMR existing)
- Dukungan PDF parsing di v1

---

## 2. Posisi di Arsitektur Existing

### 2.1 Yang sudah ada dan DIPAKAI (extend, bukan ganti)

| Existing | Lokasi | Peran di Form Registry |
|---|---|---|
| `document_templates` table | migration `2026_05_10_000003` | **Master template** — di-extend dengan kolom baru |
| `document_types` table | migration `2026_05_10_000002` | Kategori form (RM, Surat, Consent) |
| `station_document_mappings` table | migration `2026_05_10_000004` | Digantikan oleh `station_assignments` JSON di template |
| `patient_documents` table | migration `2026_05_10_000005` | **Snapshot dokumen** — tambah kolom signature & rendered_html |
| `document_verifications` table | migration `2026_05_10_000006` | QR verifikasi dokumen — tetap dipakai |
| `notifications` table | migration `2026_05_10_000007` | Notifikasi antrian TTD dokter |
| `MasterDataController` | `Http/Controllers/MasterDataController.php` | Extend untuk endpoint form-registry |
| `RekamMedisController` | `Http/Controllers/RekamMedisController.php` | Extend untuk endpoint runtime render/submit/sign |
| `RekamMedisService` | `Services/RekamMedisService.php` | Extend untuk document rendering |
| `MasterDataView.vue` | `views/MasterDataView.vue` | Tambah tab/section Form Template |

### 2.2 Service baru yang dibuat

```
backend/app/Services/
├── FormRegistry/
│   ├── FormRegistryService.php      # Router utama: render, getSchema, submit, listByStation
│   ├── FormParserService.php        # Parse .docx → JSON draft
│   ├── BindingResolver.php          # Resolve binding field ke data DB
│   ├── ScoringEngine.php            # Evaluasi SCORED_FORM (sum, threshold)
│   ├── FieldRegistry.php            # Whitelist kolom DB yang boleh di-bind
│   └── DocumentRenderer.php        # Render layout_html + substitusi placeholder
```

### 2.3 Komponen Vue baru

```
arumed-frontend/src/
├── views/master/
│   └── FormTemplateView.vue              # Halaman master form template (list + wizard)
├── components/master/form-template/
│   ├── UploadStep.vue                    # Step 1: upload .docx
│   ├── MapperStep.vue                    # Step 2: binding field (rich text editor + field mapper)
│   ├── AssignmentStep.vue                # Step 3: station assignment
│   ├── FieldMapperRow.vue                # 1 row binding per field
│   ├── BindingPickerModal.vue            # Modal pilih binding dari FieldRegistry
│   └── TemplatePreviewPanel.vue          # Preview render
├── components/forms/
│   ├── FormSection.vue                   # Wrapper: load forms dari endpoint per (station, section)
│   ├── FormRMRenderer.vue                # Render 1 form (INPUT atau OUTPUT)
│   ├── FormFieldRenderer.vue             # Render 1 field by type
│   └── signature/
│       ├── SignatureCanvas.vue           # Canvas TTD (wrap signature_pad)
│       ├── SignatureCaptureModal.vue     # Full-screen modal tablet
│       ├── SignaturePreview.vue          # Tampilkan TTD existing (read-only)
│       └── SignatureAuditViewer.vue      # Admin: lihat metadata + log
└── stores/
    └── formTemplateStore.js              # Pinia store untuk form template admin
```

---

## 3. Database Schema

### 3.1 Extend `document_templates` (existing table)

```sql
-- Tambahkan via migration baru: 2026_05_26_000001_extend_document_templates_for_form_registry

ALTER TABLE document_templates ADD COLUMN code VARCHAR(50) UNIQUE;
ALTER TABLE document_templates ADD COLUMN kind ENUM('INPUT','OUTPUT','HYBRID') DEFAULT 'OUTPUT';
ALTER TABLE document_templates ADD COLUMN complexity_kind ENUM('SIMPLE_BINDING','SCORED_FORM','CUSTOM_COMPONENT') DEFAULT 'SIMPLE_BINDING';
ALTER TABLE document_templates ADD COLUMN custom_component_name VARCHAR(100) NULL;
ALTER TABLE document_templates ADD COLUMN source_file_path VARCHAR(255) NULL;
ALTER TABLE document_templates ADD COLUMN layout_html LONGTEXT NULL;
ALTER TABLE document_templates ADD COLUMN field_schema JSON NULL;
ALTER TABLE document_templates ADD COLUMN station_assignments JSON NULL;
ALTER TABLE document_templates ADD COLUMN version INTEGER DEFAULT 1;
ALTER TABLE document_templates ADD COLUMN code_locked_at TIMESTAMP NULL;
ALTER TABLE document_templates ADD COLUMN is_active BOOLEAN DEFAULT false;
ALTER TABLE document_templates ADD COLUMN deprecated_at TIMESTAMP NULL;
```

**Aturan kunci:**
- `code` = admin set manual via UI dengan auto-suggest dari parser. Format `^[A-Z0-9_]+$`, unique.
- `code` mutable selama `code_locked_at IS NULL`. Saat `is_active` di-set `true` pertama kali → `code_locked_at = now()`. **One-way ratchet** — tidak bisa di-unset.
- Rename setelah aktif: **deprecate + reissue** (template baru, code baru). Dokumen historis tetap reference code lama.

### 3.2 Extend `patient_documents` (existing table)

```sql
-- Migration: 2026_05_26_000002_extend_patient_documents_for_snapshots

ALTER TABLE patient_documents ADD COLUMN template_code VARCHAR(50) NULL;
ALTER TABLE patient_documents ADD COLUMN template_version INTEGER NULL;
ALTER TABLE patient_documents ADD COLUMN rendered_html LONGTEXT NULL;
ALTER TABLE patient_documents ADD COLUMN status ENUM('DRAFT','RENDERED','PENDING_SIGNATURE','FINALIZED') DEFAULT 'DRAFT';
ALTER TABLE patient_documents ADD COLUMN finalized_at TIMESTAMP NULL;
ALTER TABLE patient_documents ADD COLUMN final_integrity_hash CHAR(64) NULL;
```

**Aturan immutability:** saat `status = FINALIZED`, kolom `rendered_html` tidak boleh diubah. Enforce di `DocumentRenderer` service + DB trigger kalau perlu.

### 3.3 Tabel baru: `document_signatures`

```sql
-- Migration: 2026_05_26_000003_create_document_signatures_table

CREATE TABLE document_signatures (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    signature_id VARCHAR(50) UNIQUE NOT NULL,       -- "sig_xxxx" format

    patient_document_id BIGINT NOT NULL,
    FOREIGN KEY (patient_document_id) REFERENCES patient_documents(id),

    signer_type ENUM('patient','guardian','witness','doctor','nurse','staff') NOT NULL,
    signer_user_id BIGINT NULL,                     -- kalau signer = user sistem
    FOREIGN KEY (signer_user_id) REFERENCES users(id),
    signer_patient_id BIGINT NULL,                  -- kalau signer = pasien
    FOREIGN KEY (signer_patient_id) REFERENCES patients(id),
    signer_external_identity JSON NULL,             -- nama, NIK, hub. (untuk saksi eksternal)

    signature_svg LONGTEXT NULL,
    signature_png_base64 LONGTEXT NULL,

    captured_at TIMESTAMP(3) NOT NULL,              -- dari server, bukan client clock
    captured_device_info JSON NOT NULL,             -- IP, user-agent, device_id
    captured_by_facilitator_user_id BIGINT NULL,    -- petugas yang pegang tablet
    FOREIGN KEY (captured_by_facilitator_user_id) REFERENCES users(id),

    biometric_metadata JSON NULL,                   -- stroke count, duration ms, dll
    audit_log JSON NOT NULL,                        -- event timeline (modal_opened, dll)
    integrity_hash CHAR(64) NOT NULL,               -- SHA-256 tamper-evident

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    -- TIDAK ADA updated_at — tabel ini append-only
);
```

**Append-only enforcement:** tidak ada `UPDATE` atau `DELETE`. `SignatureService::update()` → throw `ImmutableRecordException`.

### 3.4 Tabel baru: `document_addenda`

```sql
-- Migration: 2026_05_26_000004_create_document_addenda_table
-- Untuk koreksi dokumen yang sudah FINALIZED (bukan edit langsung)

CREATE TABLE document_addenda (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    patient_document_id BIGINT NOT NULL,
    FOREIGN KEY (patient_document_id) REFERENCES patient_documents(id),
    alasan TEXT NOT NULL,
    isi_koreksi TEXT NOT NULL,
    created_by BIGINT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finalized_at TIMESTAMP NULL,
    signature_id BIGINT NULL,
    FOREIGN KEY (signature_id) REFERENCES document_signatures(id)
);
```

---

## 4. `field_schema` — Format JSON

### 4.1 SIMPLE_BINDING (form label-value standar)

```json
{
  "layout_mode": "single_page",
  "fields": [
    {
      "key": "nama_pasien",
      "label": "Nama",
      "type": "text",
      "required": true,
      "binding": { "kind": "db", "source": "patient.nama" }
    },
    {
      "key": "diagnosa",
      "label": "Diagnosa",
      "type": "longtext",
      "binding": {
        "kind": "aggregate",
        "source": "doctorExamination.icd10_diagnoses",
        "format": "icd_with_desc_join_newline"
      }
    },
    {
      "key": "ttd_dokter",
      "label": "Tanda Tangan Dokter",
      "type": "signature_canvas",
      "signer_type": "doctor",
      "required": true,
      "binding": { "kind": "db", "source": "visit.dokter_id" }
    }
  ]
}
```

**Jenis `binding.kind`:**
- `db` — kolom langsung dari tabel existing (via FieldRegistry)
- `aggregate` — query preset (prescriptions, icd10 list, dll)
- `static` — nilai literal atau null (INPUT: user isi; OUTPUT: render kosong)
- `computed` — ekspresi sederhana (`patient.tanggal_lahir → umur`)
- `clinic` — data klinik (`clinic.nama`, `clinic.logo_url`, `clinic.alamat`)

### 4.2 Multi-halaman (form seperti RM 2.2 Laporan Pembedahan)

```json
{
  "layout_mode": "multi_page",
  "pages": [
    {
      "page_number": 1,
      "title": "Identitas & Tim Bedah",
      "fields": [
        { "key": "nama_pasien", "type": "text", "binding": { "kind": "db", "source": "patient.nama" } },
        { "key": "jenis_anestesi", "type": "multi_checkbox",
          "options": ["Umum", "BSP Spiral", "CSP Epidural", "Lokal"],
          "binding": { "kind": "db", "source": "surgery_records.jenis_anestesi" } },
        { "key": "jam_mulai", "type": "time", "binding": { "kind": "db", "source": "surgery_records.jam_mulai" } },
        { "key": "jam_selesai", "type": "time", "binding": { "kind": "db", "source": "surgery_records.jam_selesai" } },
        { "key": "lama_operasi", "type": "computed_duration", "from": "jam_mulai", "to": "jam_selesai" },
        { "key": "teknik_operasi", "type": "block_freetext",
          "binding": { "kind": "db", "source": "surgery_records.teknik_operasi" } }
      ]
    },
    {
      "page_number": 2,
      "title": "Komplikasi & Instruksi Pasca-Bedah",
      "fields": [
        { "key": "komplikasi_intra", "type": "radio_with_detail",
          "options": ["Ya", "Tidak"],
          "detail_key": "komplikasi_penjabaran",
          "binding": { "kind": "db", "source": "surgery_records.komplikasi_intra_operasi" } },
        { "key": "perdarahan_cc", "type": "number",
          "validation": { "min": 0 },
          "binding": { "kind": "db", "source": "surgery_records.perdarahan_cc" } },
        { "key": "post_op_instructions", "type": "structured_list",
          "items": ["Kontrol nadi/tensi", "Puasa", "Drain", "Infus", "Obat-obatan", "Ganti Balut", "Lain-lain"],
          "binding": { "kind": "db", "source": "surgery_records.post_op_instructions" } },
        { "key": "ttd_operator", "type": "signature_canvas",
          "signer_type": "doctor", "required": true }
      ]
    }
  ]
}
```

> **Catatan**: multi-halaman hanya untuk OUTPUT/print. Di CRUD aplikasi, semua field tampil dalam satu form scroll biasa dengan section header — tidak ada stepper.

### 4.3 SCORED_FORM (kuesioner skor)

```json
{
  "layout_mode": "single_page",
  "fields": [
    {
      "key": "riwayat_jatuh",
      "label": "Riwayat jatuh dalam 3 bulan",
      "type": "scored_radio",
      "required": true,
      "options": [
        { "label": "Tidak", "score": 0 },
        { "label": "Ya", "score": 25 }
      ]
    },
    {
      "key": "total_score",
      "type": "computed_sum",
      "sum_of": ["riwayat_jatuh", "diagnosis_sekunder"]
    },
    {
      "key": "interpretasi",
      "type": "computed_threshold",
      "based_on": "total_score",
      "thresholds": [
        { "max": 24, "label": "Risiko Rendah" },
        { "max": 44, "label": "Risiko Sedang" },
        { "max": 9999, "label": "Risiko Tinggi" }
      ]
    }
  ]
}
```

### 4.4 Tipe field lengkap

| Tipe | Deskripsi | Binding |
|---|---|---|
| `text` | Input teks pendek | db |
| `longtext` | Textarea panjang | db |
| `block_freetext` | Block naratif besar (tanpa label kiri) | db |
| `date` | Date picker | db |
| `time` | Time picker | db |
| `number` | Input angka + validasi range | db |
| `enum_gender` | Radio L/P | db |
| `multi_checkbox` | Pilih lebih dari 1 | db (JSON array) |
| `radio_with_detail` | Radio + conditional expand detail | db |
| `structured_list` | List bernomor dengan value per item | db (JSON array) |
| `scored_radio` | Radio dengan skor per opsi | SCORED_FORM only |
| `computed_sum` | Hitung total dari field lain | computed |
| `computed_duration` | Hitung durasi dari 2 field time | computed |
| `computed_threshold` | Interpretasi dari nilai numerik | computed |
| `signature_canvas` | Canvas TTD digital | signature |
| `signature_placeholder` | Area TTD basah (untuk print) | static |

---

## 5. `station_assignments` — Format JSON

```json
[
  {
    "station": "dokter",
    "section": "resume_output",
    "mode": "OUTPUT"
  },
  {
    "station": "perawat",
    "section": "asesmen_input",
    "mode": "INPUT"
  },
  {
    "station": "dokter",
    "section": "asesmen_input",
    "mode": "OUTPUT"
  }
]
```

**Station valid** (sesuai `Queue::STATIONS` di arsitektur):
`admisi`, `perawat`, `refraksionis`, `dokter`, `penunjang`, `bedah`, `kasir`, `farmasi`

**Section valid** (di-define di SectionRegistry backend):
- `admisi`: `identitas`, `dokumen_admisi`
- `perawat`: `asesmen_input`, `dokumen_perawat`
- `dokter`: `asesmen_input` (read), `resume_output`, `surat`, `consent`
- `bedah`: `laporan_bedah`, `consent_operasi`
- `kasir`: `invoice_dokumen`
- `farmasi`: `resep_dokumen`

---

## 6. FieldRegistry — Whitelist

File: `backend/app/Services/FormRegistry/FieldRegistry.php`

Hanya kolom yang terdaftar di sini yang boleh di-bind dari form. Tidak ada DB introspection otomatis — developer tambah manual saat ada kolom baru yang boleh di-expose.

```php
<?php
namespace App\Services\FormRegistry;

class FieldRegistry
{
    public static function columns(): array
    {
        return [
            'patient' => [
                'nama'            => ['label' => 'Nama Pasien',      'type' => 'text'],
                'nik'             => ['label' => 'NIK',              'type' => 'text'],
                'no_rm'           => ['label' => 'No. Rekam Medis',  'type' => 'text'],
                'tanggal_lahir'   => ['label' => 'Tanggal Lahir',    'type' => 'date'],
                'jenis_kelamin'   => ['label' => 'Jenis Kelamin',    'type' => 'enum'],
                'alamat'          => ['label' => 'Alamat',           'type' => 'longtext'],
                'no_telp'         => ['label' => 'No. Telepon',      'type' => 'text'],
                'alergi_obat'     => ['label' => 'Alergi Obat',      'type' => 'longtext'],
            ],
            'visit' => [
                'tanggal_kunjungan' => ['label' => 'Tanggal Berobat',        'type' => 'date'],
                'dokter.nama'       => ['label' => 'Dokter yang Merawat',    'type' => 'text'],
                'poli'              => ['label' => 'Ruang Poli',             'type' => 'text'],
                'jenis_pembayaran'  => ['label' => 'Penanggung Pembayaran',  'type' => 'enum'],
            ],
            'doctorExamination' => [
                'anamnese'          => ['label' => 'Anamnese',           'type' => 'longtext'],
                'pemeriksaan_fisik' => ['label' => 'Pemeriksaan Fisik',  'type' => 'longtext'],
                'soap_assessment'   => ['label' => 'Assessment (SOAP)',  'type' => 'longtext'],
                'soap_planning'     => ['label' => 'Planning (SOAP)',    'type' => 'longtext'],
            ],
            'nurseAssessment' => [
                'keluhan_utama'  => ['label' => 'Keluhan Utama', 'type' => 'longtext'],
                'tekanan_darah'  => ['label' => 'Tekanan Darah', 'type' => 'text'],
                'nadi'           => ['label' => 'Nadi',          'type' => 'integer'],
                'suhu'           => ['label' => 'Suhu Tubuh',    'type' => 'decimal'],
                'pain_rps'       => ['label' => 'Nyeri (RPS)',   'type' => 'text'],
            ],
            'medicalResume' => [
                'follow_up_date'     => ['label' => 'Tanggal Kontrol', 'type' => 'date'],
                'follow_up_location' => ['label' => 'Tempat Kontrol',  'type' => 'text'],
            ],
            'surgery_records' => [
                'teknik_operasi'         => ['label' => 'Teknik Operasi',       'type' => 'longtext'],
                'diagnosa_pra_bedah'     => ['label' => 'Diagnosa Pra-Bedah',   'type' => 'text'],
                'diagnosa_pasca_bedah'   => ['label' => 'Diagnosa Pasca-Bedah', 'type' => 'text'],
                'jenis_anestesi'         => ['label' => 'Jenis Anestesi',       'type' => 'json'],
                'jam_mulai'              => ['label' => 'Jam Mulai Operasi',    'type' => 'time'],
                'jam_selesai'            => ['label' => 'Jam Selesai Operasi',  'type' => 'time'],
                'perdarahan_cc'          => ['label' => 'Perdarahan (cc)',       'type' => 'decimal'],
                'komplikasi_intra_operasi' => ['label' => 'Komplikasi Intra-op','type' => 'boolean'],
                'post_op_instructions'   => ['label' => 'Instruksi Pasca-Op',   'type' => 'json'],
            ],
            'clinic' => [
                'nama'      => ['label' => 'Nama Klinik',   'type' => 'text'],
                'alamat'    => ['label' => 'Alamat Klinik', 'type' => 'text'],
                'logo_url'  => ['label' => 'Logo Klinik',  'type' => 'image_url'],
                'no_telp'   => ['label' => 'Telp Klinik',  'type' => 'text'],
                'no_izin'   => ['label' => 'No. Izin',     'type' => 'text'],
            ],
        ];
    }

    public static function aggregates(): array
    {
        return [
            'doctorExamination.icd10_diagnoses' => [
                'label'   => 'Diagnosa ICD-10',
                'formats' => ['icd_with_desc_join_newline', 'icd_only_join_comma'],
            ],
            'prescriptions' => [
                'label'   => 'Daftar Resep Obat',
                'formats' => ['items_pretty', 'items_table_html'],
            ],
            'visitServices' => [
                'label'   => 'Daftar Tindakan',
                'formats' => ['list_simple', 'list_with_tarif'],
            ],
            'diagnosticResults.summary' => [
                'label'   => 'Ringkasan Hasil Penunjang',
                'formats' => ['summary_per_jenis'],
            ],
        ];
    }
}
```

---

## 7. Service Architecture

### 7.1 FormRegistryService (router utama)

```php
class FormRegistryService
{
    // OUTPUT mode: resolve binding → render HTML → return
    public function render(string $code, int $visitId): string;

    // INPUT mode: return field_schema untuk frontend dynamic form
    public function getSchema(string $code): array;

    // INPUT mode: validate + route ke service domain yang tepat
    public function submit(string $code, int $visitId, array $data): void;

    // List template by (station, section) untuk FormSection.vue
    public function listByStationSection(string $station, string $section, int $visitId): array;

    // Finalize: snapshot rendered_html → status FINALIZED
    public function finalize(int $patientDocumentId, array $signatureIds): void;
}
```

**Penting:** `FormRegistryService` adalah **router, bukan god-object**. Untuk submit INPUT, dia delegate ke service domain yang tepat berdasarkan binding:
- field binding `doctorExamination.*` → `DokterService`
- field binding `nurseAssessment.*` → `PerawatService`
- field binding `surgery_records.*` → `BedahService`

### 7.2 DocumentRenderer (rendering engine)

```php
class DocumentRenderer
{
    // Ambil layout_html → resolve semua {{placeholder}} → return HTML final
    public function render(DocumentTemplate $template, int $visitId): string;

    // Embed SVG signature ke posisi yang ditandai di layout_html
    public function embedSignatures(string $html, array $signatures): string;
}
```

### 7.3 FormParserService (parsing .docx)

```php
class FormParserService
{
    // Async (queue job) — hasil disimpan ke cache/DB, di-poll frontend
    public function parseAsync(string $filePath): string; // return job_id

    // Ambil hasil parsing
    public function getParseResult(string $jobId): array; // return JSON draft
}
```

### 7.4 SignatureService (append-only)

```php
class SignatureService
{
    // Capture TTD — generate server-side: captured_at, hash, device_info
    public function capture(array $data): DocumentSignature;

    // Verify integrity
    public function verify(string $signatureId): bool;

    // List signatures by document
    public function listByDocument(int $patientDocumentId): array;

    // TIDAK ADA update() / delete() — throw ImmutableRecordException
}
```

---

## 8. Endpoint Kontrak

### 8.1 Setup endpoints (extend MasterDataController)

```
POST   /master/form-template/upload          # Upload .docx → async parse job
GET    /master/form-template/parse-result/{jobId}  # Poll hasil parser
GET    /master/form-template                 # List semua template
GET    /master/form-template/{id}            # Detail template
POST   /master/form-template                 # Buat template baru (dari parse result)
PUT    /master/form-template/{id}            # Update (code hanya kalau belum locked)
POST   /master/form-template/{id}/activate   # Set is_active=true + lock code
POST   /master/form-template/{id}/deactivate # Set is_active=false
GET    /master/form-template/{id}/preview?visit_id=X  # Preview render (dry-run)
GET    /master/station-sections              # List section valid per station
GET    /master/field-registry                # List field yang boleh di-bind
```

### 8.2 Runtime endpoints (extend RekamMedisController)

```
GET    /rekam-medis/forms?station=X&section=Y&visit_id=Z  # List form tersedia
GET    /rekam-medis/form/{code}/render?visit_id=Z         # OUTPUT: render HTML
GET    /rekam-medis/form/{code}/schema                    # INPUT: get field schema
POST   /rekam-medis/form/{code}/submit                    # INPUT: submit data
GET    /rekam-medis/document/{id}/render                  # Ambil snapshot (bukan re-render)
POST   /rekam-medis/document/{id}/sign                    # Capture TTD
POST   /rekam-medis/document/{id}/finalize                # Snapshot + lock
POST   /rekam-medis/document/{id}/addendum                # Koreksi post-finalize

GET    /rekam-medis/ttd-queue                             # Antrian TTD dokter (grouped by patient)
GET    /rekam-medis/signature/{id}/verify                 # Verify integrity hash
GET    /rekam-medis/signature/{id}/audit                  # Admin: lihat audit log TTD
```

---

## 9. Alur TTD (Signature Flow)

### 9.1 Status dokumen

```
DRAFT → RENDERED → PENDING_SIGNATURE → FINALIZED
```

- **DRAFT**: data diisi, belum di-render
- **RENDERED**: konten final, menunggu TTD (konten tidak bisa diubah lagi)
- **PENDING_SIGNATURE**: sudah dikirim ke pihak yang harus TTD
- **FINALIZED**: semua TTD selesai, snapshot immutable

### 9.2 Urutan wajib (per regulasi PMK 24/2022)

```
1. Data diisi lengkap (INPUT mode)
2. Dokter/petugas verifikasi kebenaran data
3. Render dokumen → status RENDERED
4. Pihak yang TTD MEMBACA dokumen (checkbox konfirmasi)
5. TTD di canvas (tidak boleh ubah konten setelah ini)
6. Finalize → snapshot → IMMUTABLE
```

### 9.3 Antrian TTD (multi-doctor, grouped by patient)

Halaman: `/rekam-medis/ttd-queue` — hanya tampilkan dokumen milik dokter yang login.

```
Antrian Tanda Tangan — dr. Andi Wijaya

┌─────────────────────────────────────────────────────┐
│ Budi Santoso — No.RM 001234          [3 dokumen]   │
│ Poli Katarak · Kunjungan hari ini                   │
├─────────────────────────────────────────────────────┤
│  ○ Resume Medis          dirender 10 mnt lalu  [TTD]│
│  ○ Informed Consent      dirender 30 mnt lalu  [TTD]│
│  ○ Laporan Bedah         dirender 2 jam lalu   [TTD]│
└─────────────────────────────────────────────────────┘
```

Filter: `WHERE required_signer_user_id = current_user_id AND status = 'PENDING_SIGNATURE'`

### 9.4 Audit metadata TTD

Setiap signature menyimpan:
- `captured_at` — timestamp dari server (bukan client)
- `captured_device_info` — IP, user-agent, device_id
- `captured_by_facilitator_user_id` — petugas yang fasilitasi TTD
- `biometric_metadata` — stroke count, duration ms (fingerprint perilaku)
- `audit_log` — timeline event: modal_opened, signature_started, signature_completed, user_confirmed
- `integrity_hash` — SHA-256 dari `{svg + captured_at + patient_document_id + signer_identity}`

### 9.5 Jenis signer yang didukung v1

| Signer type | Siapa | Identity source |
|---|---|---|
| `doctor` | Dokter yang login | `users` table |
| `nurse` / `staff` | Perawat/petugas yang login | `users` table |
| `patient` | Pasien | `patients` table |
| `guardian` | Wali/keluarga | `signer_external_identity` JSON (nama, NIK, hubungan) |
| `witness` | Saksi | `signer_external_identity` JSON (nama, NIK) |

### 9.6 Koreksi setelah FINALIZED: Addendum

Dokumen yang sudah FINALIZED **tidak bisa diedit**. Koreksi via addendum:
- Buat `document_addenda` baru (isi koreksi + alasan)
- Addendum di-TTD oleh yang berwenang
- Saat cetak dokumen, addendum otomatis terlampir di halaman terpisah

---

## 10. Parser — Detail teknis

### 10.1 Scope v1: .docx only

PDF tidak didukung di v1. Admin diminta convert ke .docx jika form sumber hanya tersedia dalam PDF.

### 10.2 Library

Backend: `phpoffice/phpword` (sudah mature, kompatibel PHP 8.3)

### 10.3 Tahap parsing

| Tahap | Output | Akurasi |
|---|---|---|
| 1. Ekstrak tabel Word via phpword | Array of rows × cells | ~99% |
| 2. Klasifikasi tipe field (heuristik label) | Field type per row | ~70-85% |
| 3. Suggest binding via string similarity ke FieldRegistry | Suggested binding + confidence | ~70% high, ~40% medium |
| 4. Generate `layout_html` draft dengan paragraf statis dipreserve | HTML draft | ~90% (struktur) |

### 10.4 Heuristik tipe field

| Pattern | Tipe |
|---|---|
| Label berakhir `:` + cell kanan kosong inline | `text` |
| Cell kanan lebar kosong | `longtext` |
| Mengandung `Tanggal` / `Tgl.` | `date` |
| Mengandung `L/P`, `Pria/Wanita` | `enum_gender` |
| Cell merged + bold + uppercase | `section_title` (bukan field) |
| Frasa `Tandatangan`, `TTD` | `signature_canvas` |
| Tabel 3 kolom (pertanyaan/opsi/skor) | `scored_radio` |
| Paragraf panjang bukan tabel | preserve sebagai static content di layout_html |

### 10.5 Auto-suggest binding (tiered)

| Confidence | Logika | Behavior UI |
|---|---|---|
| **High** | Similarity ≥ 90% AND ada di whitelist standar | Auto-bind, icon ✓ hijau, admin konfirmasi |
| **Medium** | Similarity 60-89% | Dropdown terbuka + kandidat ter-highlight |
| **Low/None** | Tidak ada match | Empty dropdown + search manual |

### 10.6 Handling static content (form seperti RM 1.1 General Consent)

Form yang mayoritas isinya teks statis (paragraf hukum, informasi persetujuan): parser **preserve teks ke `layout_html`** apa adanya, bukan mencoba extract sebagai field. Admin bisa edit teks via rich text editor di Mapper UI.

---

## 11. UI Setup Admin (Wizard 3-Step)

Lokasi: extend `MasterDataView.vue` — tambah tab "Form Rekam Medis"

### 11.1 Komponen layout editor

`layout_html` diedit via **rich text editor** (TipTap atau Quill). Admin bisa edit semua teks — paragraf statis, heading, bullet, tabel. Untuk insert field dinamis, tersedia tombol `{{field}}` yang membuka `BindingPickerModal`.

**Semua teks di layout_html bisa diedit** — termasuk teks legalitas, nama klinik, paragraf persetujuan. Ini by design supaya klinik bisa menyesuaikan dengan kebutuhan.

### 11.2 Step 1 — Upload & Parse

- Drag-drop area `.docx`, max 5MB
- Klik "Upload & Parse" → `POST /master/form-template/upload` → `202 Accepted + job_id`
- Frontend poll `GET /master/form-template/parse-result/{job_id}` tiap 2 detik
- Saat selesai → auto-navigate ke Step 2

### 11.3 Step 2 — Identitas & Binding

Layout 2-kolom: form identitas + field mapper (kiri) | rich text editor + preview (kanan)

- **Code field**: auto-suggest dari parser, admin set manual. Validasi unique via debounce GET.
- **Complexity radio**: SIMPLE_BINDING / SCORED_FORM / CUSTOM_COMPONENT — mengubah tampilan mapper
- **Field rows**: ikon status ✅ (high) ⚠️ (medium) ❌ (none) — klik edit → `BindingPickerModal`
- **Rich text editor**: edit layout_html langsung, preview update saat binding berubah
- **Auto-save draft**: debounce 1 detik, indicator "Draft saved ✓"

### 11.4 Step 3 — Station Assignment & Aktivasi

- Station cards (admisi, perawat, dokter, dll) dengan checkbox aktifkan
- Saat dicentang: expand pilih section (dropdown dari `/master/station-sections`) + mode
- Preview test: pilih visit dummy, klik "Preview Render" → lihat hasil di modal
- Warning sebelum activate: code tidak bisa diubah setelah aktif
- Modal konfirmasi → `POST /master/form-template/{id}/activate`

---

## 12. Vue Frontend — Integrasi ke Station

### 12.1 Pola C2: Vue craft section, admin assign form

Station Vue punya layout dengan section yang sudah di-define developer. Admin assign form ke section via master UI. Tidak ada hardcode form spesifik di komponen station.

```vue
<!-- DokterView.vue — contoh integrasi -->
<template>
  <div class="dokter-station">
    <!-- Komponen EMR native existing -->
    <DokterTab2Anamnese :visit-id="visitId" />
    <DokterTab3Tindakan :visit-id="visitId" />
    <DokterTab4Soap :visit-id="visitId" />

    <!-- Section form RM dari master — dinamis -->
    <FormSection
      title="Asesmen dari Perawat"
      section="asesmen_input"
      station="dokter"
      :visit-id="visitId"
    />
    <FormSection
      title="Resume & Surat"
      section="resume_output"
      station="dokter"
      :visit-id="visitId"
    />
    <FormSection
      title="Consent & Persetujuan"
      section="consent"
      station="dokter"
      :visit-id="visitId"
    />
  </div>
</template>
```

### 12.2 FormSection.vue

```javascript
// Load forms dari endpoint per (station, section, visit_id)
// GET /rekam-medis/forms?station=dokter&section=resume_output&visit_id=123
// → render list kartu form, tiap kartu buka FormRMRenderer
```

### 12.3 FormRMRenderer.vue

- **OUTPUT mode**: GET render → tampilkan HTML → tombol "TTD" (kalau perlu) → "Cetak"
- **INPUT mode**: GET schema → render dynamic form → submit data → render → TTD → finalize
- **CUSTOM_COMPONENT**: dynamic import dari registry Vue

### 12.4 Pinia store baru

`stores/formTemplateStore.js`:
- `fetchFormsByStation(station, section, visitId)`
- `fetchSchema(code)`
- `renderForm(code, visitId)`
- `submitForm(code, visitId, data)`
- `captureSignature(docId, signerType, svgData, auditLog)`
- `finalizeDocument(docId, signatureIds)`
- `fetchTtdQueue()` — antrian TTD dokter grouped by patient

---

## 13. Versioning & Audit (Snapshot-at-signing)

### 13.1 Aturan

1. **Template**: perubahan `field_schema` atau `layout_html` → naikkan `version` integer. Tidak ada history diff/rollback UI.
2. **Snapshot saat finalize**: `DocumentRenderer` render HTML final → simpan ke `patient_documents.rendered_html` + set `finalized_at`.
3. **Retrieval historis**: `GET /rekam-medis/document/{id}/render` selalu baca dari `rendered_html` snapshot. **Tidak pernah re-render dari template.**
4. **Template version tracking**: `patient_documents.template_version` mencatat versi template saat dokumen di-generate.

### 13.2 Mengapa ini wajib (PMK 24/2022)

Dokumen medis yang sudah ditandatangani = dokumen hukum. Jika template binding berubah setelah dokumen di-finalize, dokumen lama harus tetap identik dengan yang ada di arsip fisik. Re-render dari template terbaru = risiko inkonsistensi audit BPJS & tuntutan hukum.

### 13.3 Integrity hash chain

```
Signature hash = SHA-256(svg + captured_at + patient_document_id + signer_identity)
Document final hash = SHA-256(rendered_html + signature_id_1 + signature_id_2 + ...)
```

Keduanya disimpan. Verifikasi via `GET /rekam-medis/signature/{id}/verify`.

---

## 14. Roadmap Implementasi

### Fase 1 — Foundation (2-3 minggu)

- [ ] Migration 4 file (extend document_templates, extend patient_documents, document_signatures, document_addenda)
- [ ] `FieldRegistry.php` initial (patient, visit, doctorExamination, nurseAssessment, clinic)
- [ ] `FormRegistryService` skeleton (render, getSchema, listByStationSection)
- [ ] `BindingResolver` untuk `db` dan `clinic` binding
- [ ] `DocumentRenderer` basic (placeholder substitusi)
- [ ] Endpoint render OUTPUT + endpoint finalize (snapshot)
- [ ] Pilot end-to-end: 1 form OUTPUT sederhana (surat keterangan berobat)

### Fase 2 — Parser + Mapper UI (3-4 minggu)

- [ ] `FormParserService` (phpoffice/phpword, async queue job)
- [ ] Endpoint upload + parse-result + CRUD template
- [ ] `FormTemplateView.vue` dengan wizard 3-step
- [ ] Rich text editor (TipTap) untuk layout_html
- [ ] Tiered auto-suggest binding (string similarity ke FieldRegistry)
- [ ] Aggregate binding resolver (prescriptions, icd10_diagnoses)
- [ ] `FormSection.vue` + `FormRMRenderer.vue` OUTPUT mode
- [ ] Integrasi ke `DokterView.vue` (section "Resume & Surat")
- [ ] Onboarding 5-10 form RM OUTPUT priority

### Fase 3 — INPUT mode (2-3 minggu)

- [ ] `FormRMRenderer.vue` INPUT mode (dynamic form dari schema)
- [ ] Submit routing ke service domain existing
- [ ] Integrasi ke `PerawatView.vue`, `AdmisiView.vue`
- [ ] Onboarding form INPUT (RM 1.3 Asesmen Perawat, RM 1.1 Identitas)

### Fase 4 — Signature Flow (2-3 minggu)

- [ ] `SignatureService` + endpoint sign/verify/audit
- [ ] `SignatureCanvas.vue`, `SignatureCaptureModal.vue`
- [ ] Multi-party signer (patient, witness, doctor)
- [ ] Antrian TTD halaman `/rekam-medis/ttd-queue` (grouped by patient)
- [ ] Status flow DRAFT → RENDERED → PENDING_SIGNATURE → FINALIZED
- [ ] Addendum flow

### Fase 5 — SCORED_FORM + CUSTOM_COMPONENT (2-3 minggu)

- [ ] `ScoringEngine.php` (computed_sum, computed_threshold)
- [ ] Mapper UI extension untuk SCORED_FORM
- [ ] CUSTOM_COMPONENT registry Vue
- [ ] Component pertama: Morse Fall Scale atau Humpty Dumpty

### Fase 6 — Hardening (1-2 minggu)

- [ ] Audit log lengkap semua operasi template + dokumen
- [ ] Test compliance PMK 24/2022
- [ ] Performance test snapshot storage (gzip rendered_html)
- [ ] Dokumentasi SOP onboarding form baru

---

## 15. Risk Register

| # | Risiko | Likelihood | Impact | Mitigasi |
|---|---|---|---|---|
| R1 | Parser gagal pada form non-tabular atau tabel nested | High | Medium | Detect early, error message jelas, minta admin convert |
| R2 | Admin salah set binding → form OUTPUT tampil data salah | High | High | Preview mandatory sebelum activate, audit log perubahan binding |
| R3 | TTD canvas tidak diterima saat audit/sengketa hukum | Medium | High | Pertahankan TTD basah untuk dokumen high-stakes (bedah, operasi). Lihat keputusan D11 |
| R4 | Storage rendered_html membengkak | Medium | Low | Gzip kolom, archive tier untuk dokumen >2 tahun |
| R5 | Perubahan nama kolom DB merusak FieldRegistry existing | Low | High | Buat test yang scan field_schema active templates vs kolom DB aktual |
| R6 | Auto-suggest confidence "high" salah match | Medium | Medium | Admin tetap harus klik konfirmasi, tidak auto-save |
| R7 | SCORED_FORM dipaksa untuk kasus yang butuh CUSTOM_COMPONENT | High | Medium | Dokumentasi jelas (Section 4.3), code review saat setup |
| R8 | code template di-rename via SQL langsung (bypass lock) | Low | Critical | DB trigger atau check di migration. Documented convention. |
| R9 | Konsultan akreditasi minta versioning lebih ketat | Medium | High | Verifikasi Fase 6. Jika ya, scope ke Fase 7. |
| R10 | Multi-party signing order tidak di-enforce → TTD tidak valid | Medium | Medium | field_schema dukung `required_signers_order`. Finalize check semua signer ada. |

---

## 16. Open Questions (jawab saat implementasi mulai)

1. **Identitas saksi** — saksi consent operasi biasanya keluarga pasien (bukan user sistem). Verifikasi identitas: manual input nama+NIK, atau scan KTP?
2. **TTD dokter** — canvas sama seperti pasien, atau PIN-based (embed signature image pre-uploaded saat onboarding dokter)?
3. **Urutan signing** — sistem enforce urutan (pasien dulu → saksi → dokter), atau bebas?
4. **Konversi PDF output** — render HTML di browser (`window.print + CSS`) atau server-side (`dompdf`)? Trade-off: kualitas vs konsistensi.
5. **SectionRegistry** — definisi section valid per station belum di-list lengkap. Definisikan saat mulai Vue layer implementation.
6. **Mapper UI mobile** — admin setup form via tablet? Atau desktop-only? (Saat ini assumsi desktop-only)

---

## 17. Decision Log

| # | Keputusan | Rationale |
|---|---|---|
| D1 | Form Registry (setup sekali), bukan Form Builder real-time | Klinik regulated, form stabil, setup sekali cukup |
| D2 | Extend `document_templates` existing, bukan tabel paralel | Hindari dua sistem template berdampingan |
| D3 | Code admin-set manual + auto-suggest dari parser, immutable setelah aktif | Code = primary key bisnis, wajib stabil untuk reference Vue dan audit |
| D4 | Hybrid 3 complexity: SIMPLE_BINDING / SCORED_FORM / CUSTOM_COMPONENT | Realistis: tidak semua form bisa generic, tidak semua harus hardcode |
| D5 | FieldRegistry whitelist (bukan DB introspection) | Kolom sensitif tidak bocor ke UI admin |
| D6 | .docx only di v1 | PDF parsing tidak reliable, cost tinggi vs nilai |
| D7 | Snapshot-at-signing (immutable rendered_html) | Wajib untuk audit PMK 24/2022 dan defensibilitas hukum |
| D8 | Pure C layout C2: admin assign (station, section, mode) | Otorisasi di backend, Vue craft section, form tambah tanpa deploy |
| D9 | Rich text editor untuk layout_html | Semua teks bisa diedit admin, fleksibel untuk berbagai klinik |
| D10 | Multi-halaman = layout_mode multi_page di schema, CRUD tetap single scroll | Multi-halaman hanya untuk print output, bukan UX input |
| D11 | TTD canvas (tidak tersertifikasi) + audit metadata + hash + immutable | Cukup untuk operasional klinik, sadar batasan hukum. TTD basah untuk dokumen high-stakes. |
| D12 | Multi-party signer di v1: patient, guardian, witness, doctor, staff | Form consent butuh saksi + dokter. Sudah ada use case di klinik. |
| D13 | Antrian TTD grouped by patient, filter per dokter login | Mencegah tercecer, efisiensi TTD 1 sesi per pasien |
| D14 | Addendum untuk koreksi post-finalize (bukan edit langsung) | Dokumen RM yang sudah TTD = dokumen hukum, tidak boleh diubah |
| D15 | Service domain tetap pegang otoritas tabelnya, FormRegistryService = router | Avoid god-object, business logic tetap di tempat yang tepat |

---

> **End of Document** · Form Registry Design v1.0 · Arumed Apps · 2026-05-26  
> Update dokumen ini setiap ada keputusan baru di Fase 1-6 atau saat open questions terjawab.
> Simpan di: `Docs/design/form-registry-design.md`
