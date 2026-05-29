# Form Registry — SOP Onboarding Form Baru

> **Versi**: 1.0
> **Tanggal**: 2026-05-28 (Fase 6)
> **Audience**: admin klinik / dokter curator yang punya permission `form_template.read+write`
> **Prasyarat**: login sebagai role `dokter` / `manajemen` / `superadmin`

Dokumen ini adalah panduan langkah-demi-langkah untuk menambahkan form rekam medis baru ke sistem via Form Registry.

---

## Ringkasan Alur

```
[.docx file]
   │
   │ 1. Upload
   ▼
[Parser] ──► [Draft JSON] ──► [Step 2: Mapper]
                                  │
                                  │ 3. Edit binding + layout
                                  ▼
                              [Step 3: Assignment]
                                  │
                                  │ 4. Pilih station + activate
                                  ▼
                              [Template ACTIVE]
                                  │
                                  │ 5. Station Vue auto-render via FormSection
                                  ▼
                              [Pasien isi / dokter cetak]
```

---

## Step 1 — Upload .docx

1. Buka sidebar **Master Data → Template Form RM**.
2. Klik tombol **+ Buat Baru**.
3. Drag-drop file `.docx` ke area upload (atau klik **Pilih File**).
4. Format yang didukung: **`.docx` Word 2007+** saja. Max 5MB.
5. **Tidak didukung di v1**: PDF, `.doc` (Word 97-2003), Google Docs format.
6. Klik **Upload & Parse**. Parsing biasanya selesai dalam 5 detik (sync, tidak butuh worker).

### Apa yang parser lakukan?

- Ekstrak tabel 2-kolom → tiap row jadi 1 field (label kiri, placeholder kanan).
- Klasifikasi tipe field berdasarkan kata kunci di label:
  - "Tanggal", "Tgl" → `date`
  - "Jam", "Waktu" → `time`
  - "L/P", "Jenis Kelamin" → `enum_gender`
  - "Jumlah", "Nadi", "Suhu", "Tinggi" → `number`
  - "Tandatangan", "TTD" → `signature_canvas`
  - Cell value >50 char → `longtext`
  - Default → `text`
- Suggest binding ke field DB (tier high/medium/low berdasarkan kemiripan label dengan whitelist FieldRegistry).
- Preserve paragraf statis di `layout_html`.

### Yang TIDAK ter-parse:

- Tabel nested (table-in-table) — fallback ke layout statis, harus edit manual.
- Tabel >2 kolom — render statis + warning.
- Multi-page Section — semua disatukan jadi 1 layout.
- Scored radio (tabel 3-kolom pertanyaan/opsi/skor) — admin harus convert ke `scored_radio` manual di Step 2.
- Image (logo, foto) — di-skip.

---

## Step 2 — Identitas + Binding + Layout

### Identitas (panel kiri atas)

| Field | Isi |
|---|---|
| **Code** | Auto-suggest dari nama file, contoh `SURAT_BEROBAT`. Format wajib: huruf besar, angka, underscore (`^[A-Z0-9_]+$`). **TIDAK BISA DIUBAH** setelah template di-activate (one-way ratchet). |
| **Nama** | Display name, mis. "Surat Keterangan Berobat". |
| **Jenis Dokumen** | Pilih parent kategori (`RM-1.1` Identitas, `RM-6.1` Resume Medis, dst). |
| **Kind** | `OUTPUT` (cetak saja), `INPUT` (entry data), `HYBRID` (dua-duanya). |
| **Complexity** | `SIMPLE_BINDING` (default, form label-value), `SCORED_FORM` (kuesioner skor), `CUSTOM_COMPONENT` (Vue component khusus). |

### Field rows (panel kiri bawah)

Tiap baris adalah satu field di form. Per row:

- **Status badge**: `✓` (binding sudah set), `★` (auto-suggest high confidence ≥90%), `!` (medium 60-89%), `?` (none / static).
- **Key**: identifier internal, format `[a-z0-9_]+`. Dipakai di `{{key}}` placeholder.
- **Type**: dropdown 14 pilihan (`text`, `longtext`, `date`, `time`, `number`, `enum_gender`, `multi_checkbox`, `radio_with_detail`, `structured_list`, `signature_canvas`, `signature_placeholder`, `scored_radio`, `computed_sum`, `computed_threshold`, `computed_duration`).
- **Label**: display label di form (Bahasa Indonesia OK).
- **Binding picker** (klik chip kuning): pilih dari 4 tab:
  - **DB** — kolom dari tabel klinis (whitelist FieldRegistry; mis. `patient.name`, `visit.visit_date`).
  - **Aggregate** — `prescriptions`, `doctorExamination.icd10_diagnoses`, dll (multi-row resolve).
  - **Klinik** — data klinik dari `clinic_profiles` (mis. `clinic.clinic_name`).
  - **Static** — null saat render (user/staff isi manual di INPUT mode).

### Editor khusus per type (Step 2 expand otomatis):

- **`signature_canvas`**:
  - `signer_type`: `patient` / `guardian` / `witness` / `doctor` / `nurse` / `staff`
  - `required`: checkbox — kalau dicentang, dokumen tidak bisa di-finalize sebelum signature ini ter-capture.

- **`scored_radio`**:
  - Tombol `+ Tambah Pilihan` untuk add option (label + skor).
  - Skor numerik per pilihan.

- **`computed_sum`**:
  - Multi-select chips dari `scoredFieldKeys` (key field bertipe scored_radio).

- **`computed_threshold`**:
  - `based_on`: pilih field numerik (number / computed_sum).
  - Threshold rows: `≤ max → label`. Sorted ascending. Threshold terakhir biasanya `max=9999` untuk "tinggi".

- **`computed_duration`**:
  - `from` + `to`: dua field time. Hasil dalam menit.

### Layout editor (panel kanan)

TipTap v3 rich text editor. Toolbar:

- **B** / **I**: bold / italic
- **H2** / **H3** / **¶**: heading / paragraph
- **• List** / **1. List**: bullet / ordered list
- **+ Tabel**: insert tabel 3×2
- **{{ Insert Field**: dropdown untuk insert placeholder `{{key}}`. Klik field name → ter-sisip di posisi cursor.

**Tips**:
- Untuk template surat formal, gunakan H2 untuk header klinik, H3 untuk title surat.
- Tabel untuk identitas (Nama | : | placeholder) layout standar SOP.
- Static text bisa di-edit bebas — termasuk paragraf hukum (general consent), keterangan footer, dll.

---

## Step 3 — Station Assignment + Activate

### Station cards

Pilih station mana yang menampilkan form ini. Multi-select. Per station:

- **Section**: dropdown dari SectionRegistry (mis. `dokter` → `surat`, `resume_output`, `consent`, `asesmen_input`).
- **Mode**: `OUTPUT` (cetak), `INPUT` (entry), `HYBRID`. Mode disini bisa beda dengan template kind — admin bisa expose subset.

### Activate

- Klik **Simpan sebagai Draft** untuk save tanpa expose ke station (status `is_active=false`).
- Klik **Simpan & Aktifkan** untuk save + expose. **Code akan ter-lock permanen** (`code_locked_at = now()`).

### Setelah activate

- Template muncul di list `/master-data/form-template` dengan badge "Aktif".
- Station Vue otomatis menampilkan kartu form via `<FormSection station=X section=Y :visit-id=Z />`.
- Dokter/perawat yang granted permission `rme_dokter.read` bisa klik kartu untuk buka modal render/input.

---

## Runtime Flow (untuk dokter/perawat)

### Mode OUTPUT (cetak)

1. Buka station Vue (mis. DokterView untuk visit aktif).
2. Klik kartu form di section "Surat-Surat" / "Resume & Surat" / dst.
3. Modal preview muncul dengan HTML ter-render (data pasien auto-fill dari Visit context).
4. Klik **Cetak** untuk window.print() jendela baru (A4, margin 1.5cm).

### Mode INPUT (entry data)

1. Buka station Vue, klik kartu form INPUT.
2. Modal muncul dengan form fields.
3. Isi field static + scored. Computed fields auto-update real-time.
4. Klik **Simpan sebagai Draft** → backend create `patient_document` status `DRAFT`.
5. Section TTD muncul (kalau ada field `signature_canvas` di schema).
6. Klik **Buka Canvas Tanda Tangan** per signer → capture SVG + biometric (stroke count, duration). Untuk patient/witness/guardian: ada form identitas saksi.
7. Setelah semua required signer TTD, tombol **Finalisasi & Lock** enabled.
8. Klik finalize → backend snapshot `rendered_html` (immutable) + status `FINALIZED` + integrity hash.

### Antrian TTD Dokter

- Sidebar **Tanda Tangan Dokumen** (route `/ttd-dokumen`).
- Grouped by patient. Filter: dokumen yang punya `signer_type=doctor required=true` dan dokter login belum TTD.
- Klik baris → modal preview + tombol "Tanda Tangani".

---

## Koreksi Dokumen FINALIZED — Addendum

Dokumen yang sudah FINALIZED **tidak bisa diedit**. Koreksi via addendum:

1. Buka dokumen FINALIZED via dashboard atau snapshot endpoint.
2. POST `/api/v1/rekam-medis/document/{id}/addendum` dengan body `{alasan, isi_koreksi}`.
3. Addendum ter-create, ter-link ke parent dokumen.
4. Saat cetak dokumen, addendum otomatis terlampir di halaman terpisah (TODO frontend).
5. UI buat addendum: belum diimplementasi (backend ready). Hubungi developer.

---

## Audit Log

Semua operasi Form Registry ter-record di `system_logs` table (Fase 6).

### Event types

| Event | Trigger | Model | Context yang di-log |
|---|---|---|---|
| `FORM_TEMPLATE_CREATED` | POST `/master/form-template` | DocumentTemplate | kind, complexity_kind |
| `FORM_TEMPLATE_UPDATED` | PUT `/master/form-template/{id}` | DocumentTemplate | fields_changed, new_version |
| `FORM_TEMPLATE_ACTIVATED` | POST `/master/form-template/{id}/activate` | DocumentTemplate | code_locked_at |
| `FORM_TEMPLATE_DEACTIVATED` | POST `/master/form-template/{id}/deactivate` | DocumentTemplate | — |
| `FORM_DOC_SUBMITTED` | POST `/form/{code}/submit` | PatientDocument | template_code, visit_id, patient_id, fields_synced, has_computed |
| `FORM_DOC_RENDERED` | POST `/document/{id}/mark-rendered` | PatientDocument | template_code |
| `FORM_DOC_FINALIZED` | POST `/document/{id}/finalize` | PatientDocument | template_version, signature_ids, integrity_hash, rendered_html_size |
| `FORM_SIG_CAPTURED` | POST `/document/{id}/sign` | DocumentSignature | signature_id, patient_document_id, signer_type, integrity_hash, biometric_summary |
| `FORM_ADDENDUM_CREATED` | POST `/document/{id}/addendum` | DocumentAddendum | patient_document_id, alasan |

### Query audit log per dokumen

```http
GET /api/v1/rekam-medis/document/{id}/audit-log
Authorization: Bearer <token>
```

Return 200 entry terakhir (sorted DESC by created_at) dengan eager-load user (id+name+email).

### Query manual via tinker

```php
SystemLog::where('action', 'like', 'FORM_%')
  ->where('model_id', $documentId)
  ->orderByDesc('created_at')
  ->get();
```

---

## Troubleshooting

### Parser gagal "Unable to load file"

- Pastikan file `.docx` valid (buka di Word/LibreOffice dulu).
- Format `.doc` lama tidak didukung — Save As → `.docx`.

### Code lock — tidak bisa rename

By design. Code = primary key bisnis (dipakai di route `/form/{code}/render`). Rename → buat template baru, deprecate yang lama.

### Render OUTPUT placeholder muncul kosong

- Cek binding di Step 2: `binding.kind = 'static'` artinya null saat render.
- Untuk auto-fill dari Visit, pakai `binding.kind = 'db'` + path FieldRegistry valid.
- Field tidak ter-resolve → cek `field_schema` JSON di tabel `document_templates` (via tinker atau admin DB).

### Finalize 422 "signature wajib belum lengkap"

- Cek `field_schema.fields[].required = true` untuk `signature_canvas`.
- Capture semua required signature dulu via `POST /document/{id}/sign`.

### Audit log kosong walaupun ada submit

- `SystemLog` di-skip kalau ada exception (defensive). Cek `storage/logs/laravel.log`.
- Pastikan `auth('api')->id()` not null (user terautentikasi).

---

## Roadmap berikutnya (di luar v1)

- **Gzip storage** `rendered_html_gz` (saat clinic 10K+ dokumen).
- **Archive tier** untuk dokumen >2 tahun.
- **Field validation rules** lebih granular (regex, min/max, conditional required).
- **UI addendum** (modal form di FormRMRenderer).
- **Multi-page wizard** untuk template panjang.
- **Auto-discover custom components** via `import.meta.glob`.
- **PDF parsing** (saat PDF library mature di PHP ekosistem).

---

## Update v2 (2026-05-30) — 13 gap fix log

Ringkasan implementasi follow-up dari Fase 6 backlog:

| Gap | Status | Catatan |
|-----|--------|---------|
| #1 SubmitRouter `doctorExamination`+`medicalResume` | ✅ done | adapter `firstOrNew`+cek `is_finalized`/`is_editable`+set creator |
| #2 UI Addendum | ✅ done | tombol+modal di FormRMRenderer (visible saat status FINALIZED) |
| #3 Server-side validation payload | ✅ done | `Validator` per resource, 422 per-field; re-map error key `column → fieldKey` |
| #4 AggregateResolver `visitServices`+`diagnosticResults.summary` | ✅ done | format `list_simple/list_with_tarif` + `summary_per_jenis` |
| #5 patient_id auto-context | ✅ done | prop `patientId` di FormSection → FormRMRenderer → `signer_patient_id` saat patient TTD |
| #6 Frontend UI audit log | ✅ done | tab "Audit Log" di modal dengan timeline FORM_* action label Indonesia |
| #7 Cleanup dead `binding.kind=computed` | ✅ done | hapus dari BindingResolver match; SubmitRouter keep defensive skip |
| #8 Gzip `rendered_html_gz` | ✅ done | migration baru, finalize() compress, getSnapshot() decompress + fallback |
| #9 PMK 24/2022 compliance test | ✅ done | `tests/Feature/FormRegistry/PMK24ComplianceTest.php` (6 assertion) |
| #10 multi_checkbox `options[]` editor | ✅ done | FormFieldRenderer support array value; MapperStep editor pilihan checkbox |
| #11 PDF parsing | ✅ done | accept `mimes:docx,pdf`; PdfParser dipakai kalau tersedia, fallback regex extractor |
| #12 Multi-page wizard UI | ✅ done | per-field `page` selector; `addPage`/`removeLastPage` di MapperStep |
| #13 Hash microseconds | ✅ by design | trade-off dokumentasi: `Y-m-d H:i:s` untuk cross-DB; test verifikasi determinism + sensitivity per-component |

### Catatan untuk Gap #11 (PDF)

PdfParser (`Smalot/PdfParser`) opsional — install via `composer require smalot/pdfparser` untuk hasil terbaik. Tanpa install, fallback regex `(...)Tj` jalan tapi akurasi rendah (hanya plain-text PDF). PDF scan/image tetap butuh OCR/convert manual.

### Catatan untuk Gap #8 (gzip)

Jalankan migration `2026_05_30_000007_add_rendered_html_gz_to_patient_documents` sebelum finalize() dipakai produksi. Forward-only — dokumen pre-gzip tetap readable via fallback ke kolom `rendered_html`.

### Catatan untuk Gap #9 (test)

Jalankan: `php artisan test --filter PMK24Compliance` (in-memory SQLite, RefreshDatabase).
