---
name: feature-form-registry
description: "Form Registry — modul template form RM. Fase 1-6 done 2026-05-28. v2 gap-fix 2026-05-30: SEMUA 13 gap selesai (lihat section v2). Migration gzip applied. smalot/pdfparser installed. PMK24 tests 4/6 pass + 2 skip (sqlite tidak ada di dev)."
metadata: 
  node_type: memory
  type: project
  originSessionId: 9ad6abe5-42a0-4b95-9ccb-286ece93e3ac
---

Form Registry adalah modul untuk template form RM. Design doc: `Docs/design/form-registry-design.md` (v1.0 final 2026-05-26).

Status (per 2026-05-28):
- **Fase 1 (foundation)** — done. Migration + skeleton service + endpoint + 1 pilot template.
- **Fase 2-backend (parser + aggregate + onboarding)** — done. Parser .docx (sync), tiered binding suggester, aggregate resolver, 5 form OUTPUT siap pakai.
- **Fase 2-frontend (mapper wizard + TipTap + runtime renderer + DokterView integrasi)** — done.
- **Fase 3 (INPUT mode + submit router + GENERAL_CONSENT)** — done.
- **Fase 4 (signature flow + status transitions + addendum + TtdDokumenView)** — done.
- **Fase 5 (ScoringEngine + scored_radio + CUSTOM_COMPONENT + SVG embed)** — done.
- **Fase 6 (audit log lengkap + SOP docs)** — done. Gzip storage + archive tier ditunda (skala belum butuh).
- **Modul Form Registry SELESAI** untuk v1 (semua 6 fase design doc Section 14 terlaksana).
- **v2 gap-fix DONE 2026-05-30** — semua 13 gap eksekusi (lihat section "v2 gap-fix" di bawah). Migration `2026_05_30_000007_add_rendered_html_gz_to_patient_documents` applied. `smalot/pdfparser` v2.12 installed.

**Why:** klinik regulated, form RM fisik (RM 1.x, 2.x, dst) belum punya skema seragam. Tujuan: admin upload .docx → parse → setup binding → form aktif tanpa deploy. Wajib snapshot-at-signing per PMK 24/2022.

**How to apply:**

## Fase 1 (foundation)

- **PK semua tabel = uuid** (bukan bigint seperti design doc — adjustment biar konsisten dengan tabel existing).
- **`document_templates`** sudah ada `version` & `is_active` di migration original — di extend tambah `code`, `kind`, `complexity_kind`, `custom_component_name`, `source_file_path`, `layout_html`, `field_schema` (jsonb), `station_assignments` (jsonb), `code_locked_at`, `deprecated_at`.
- **`patient_documents`** sudah ada `status` + `finalized_at`; status pakai nilai BARU `RENDERED/PENDING_SIGNATURE/FINALIZED` (paralel dengan nilai lama `WAITING_SIGNATURE/FINAL/REJECTED/VOID` yang tetap dipakai modul lain). Extend kolom: `template_code`, `template_version`, `rendered_html`, `final_integrity_hash`.
- **Endpoint master** (paralel dengan `/master/template-dokumen/*` lama): `/master/form-template/*` (CRUD + activate/deactivate), `/master/field-registry`, `/master/station-sections`.
- **Endpoint runtime**: `/rekam-medis/forms?station=X&section=Y&visit_id=Z` (list), `/rekam-medis/form/{code}/render?visit_id=Z` (render OUTPUT dry-run), `POST /rekam-medis/document/{id}/finalize` (snapshot ke patient_documents), `GET /rekam-medis/document/{id}/render` (ambil snapshot).
- **BindingResolver path**: resource pertama relatif ke Visit. `patient.*` → `$visit->patient->*`, `visit.*` → `$visit->*`, `doctorExamination.*` → `$visit->doctorExamination->*`, `nurseAssessment.*`, `medicalResume.*`, `clinic.*` → `ClinicProfile::first()->*`. Path nested (`visit.doctorExamination.doctor.name`) di-traverse via getAttribute.
- **FieldRegistry whitelist** pakai field name DB actual (mis. `patient.name` BUKAN `patient.nama`, `patient.date_of_birth` BUKAN `tanggal_lahir`). Label tetap Bahasa Indonesia.
- **Code lock**: `code_locked_at` di-set saat `activate()` pertama kali; one-way ratchet — controller refuse rename setelah locked.
- **`PatientDocument::$fillable`** WAJIB include `template_code`/`template_version`/`rendered_html`/`final_integrity_hash` — kalau lupa, mass-assign akan strip diam-diam (sempat ngebug saat test Fase 1).
- **Finalize idempotent**: status FINALIZED + finalized_at not null → return existing tanpa overwrite (snapshot immutable per design D7/D14).
- **document_signatures**: append-only, tidak ada updated_at. SignatureService akan throw ImmutableRecordException di Fase 4.

## Fase 2-backend (parser + aggregate + onboarding)

- **Dep baru**: `phpoffice/phpword` 1.1. Ada DEPRECATED warning saat tinker dengan PHP 8.3 — tidak fatal, abaikan.
- **`AggregateResolver`** — file terpisah, di-inject ke `BindingResolver` via constructor default `= new AggregateResolver()`. Format implemented:
  - `prescriptions` → `items_pretty` (newline) atau `items_table_html` (HTML table)
  - `doctorExamination.icd10_diagnoses` → `icd_with_desc_join_newline` (lookup `icd10_codes` table) atau `icd_only_join_comma`
  - Stub null: `visitServices`, `diagnosticResults.summary` (Fase 3+)
- **`BindingSuggester`** — tiered string similarity (PHP `similar_text` percent). Tier: high ≥ 90%, medium 60-89%, low < 60%. Compare normalized label & path. Return top-3 suggestions per field.
- **`FormParserService`** — sync (TIDAK pakai queue di Fase 2 atas request user). Cache hasil 1 jam dengan prefix `form_parser_result:`, parse_id format `fp_<ulid>`. Heuristik: tabel 2-kolom → field per row (label : value). Tipe field heuristik: signature_canvas, date, time, enum_gender, number, longtext, text. Tabel >2 kolom → render statis + warning.
- **`FormParserService::parse()`** simpan file ke disk `local` (private), `storage/app/form-template-uploads/`.
- **Endpoint Fase 2**:
  - `POST /master/form-template/upload` (multipart file, max 5MB, mimes:docx) → 202 + parse_id + draft
  - `GET /master/form-template/parse-result/{parseId}` → 200 atau 404 (cache expired)
  - **PENTING**: route `parse-result/{parseId}` WAJIB didaftar SEBELUM `form-template/{id}` di api.php agar tidak terjebak.
- **Validation 422 vs 302**: Laravel default redirect 302 ke `/login` web saat validation gagal — tambah header `Accept: application/json` di request agar dapat 422 JSON. Frontend axios sudah set ini default.
- **Onboarding 5 form OUTPUT** (semua active + code_locked saat seed, jalankan `php artisan db:seed --class=FormTemplateSeeder`):
  - `SURAT_BEROBAT` (RM-1.2) — dokter/surat
  - `RESUME_MEDIS` (RM-6.1) — dokter/resume_output, pakai aggregate `icd10_diagnoses` + `prescriptions(items_table_html)`
  - `SURAT_KONTROL` (RM-6.2) — dokter/surat
  - `SURAT_RUJUKAN` (RM-6.3) — dokter/surat, pakai aggregate `icd10_diagnoses`
  - `SURAT_SAKIT` (RM-6.4) — dokter/surat, ada field static `durasi_istirahat` + `mulai_istirahat` (perlu input manual di Fase 3)

## Fase 2-frontend (mapper UI + runtime di station)

- **Deps baru**: `@tiptap/vue-3` v3.23, `@tiptap/starter-kit`, plus extension `extension-table` + table-row/cell/header. **TipTap v3** — semua extension pakai NAMED export (`import { Table } from '@tiptap/extension-table'`), BUKAN default. Bundle wizard ~430KB (lazy-loaded, gzip 137KB).
- **RBAC**: modul baru `form_template` (R/W/D) di PermissionSeeder. RolePermissionSeeder grant `form_template.read+write` ke role `dokter`, `read` ke role `manajemen`. Superadmin auto-bypass.
- **Menu lokasi**: submenu "Template Form RM" di sidebar Master Data (section "Form Rekam Medis"). Parent route `/master-data/*` permission diperluas jadi `['pengaturan.read', 'form_template.read']` (OR) supaya dokter bisa lewat parent guard tanpa expose item lain di sidebar (item per-section punya `v-if="auth.can(...)"`).
- **Router child**:
  - `master-data/form-template` → list (FormTemplateView)
  - `master-data/form-template/new` → wizard fresh
  - `master-data/form-template/:id` → wizard load existing (start di Step 2)
- **Wizard 3-step** di-keep state lokal komponen (BUKAN store) — supaya navigate-away tidak leak draft. Persist baru di "Simpan" / "Simpan & Aktifkan".
- **Komponen kunci**:
  - `views/master-data/form-template/FormTemplateView.vue` — list + toggle active
  - `views/master-data/form-template/FormTemplateWizard.vue` — shell 3-step
  - `views/master-data/form-template/{Upload,Mapper,Assignment}Step.vue`
  - `components/master/form-template/LayoutEditor.vue` — TipTap wrapper + insert placeholder dropdown
  - `components/master/form-template/BindingPickerModal.vue` — 4 tab (db/aggregate/clinic/static) + saran auto-suggest
  - `components/forms/FormSection.vue` + `components/forms/FormRMRenderer.vue` — runtime per (station, section, visit)
  - `stores/formTemplateStore.js` — Pinia composition; cache `fieldRegistry`/`stationSections`/`documentTypes` 1x per session
- **Integrasi DokterView** (`views/DokterView.vue`): tambah 3 `<FormSection>` (Resume Medis / Surat-Surat / Consent) tepat sebelum penutup `.rme-card`, di-guard `v-if="store.selectedVisitId"`. Auto-hide kalau station tidak punya template aktif (`hideIfEmpty=true` default).
- **Endpoint baru di backend Fase 2-frontend support**: `GET /master/document-types` — list parent kategori untuk dropdown wizard. Permission `form_template.read`.
- **Vue parser gotcha**: `{{ '{{key}}' }}` di template BIKIN compile error ("Unterminated string constant"). Pakai HTML entity `&#123;&#123;key&#125;&#125;` untuk literal placeholder text.
- **TipTap content sync**: `LayoutEditor` punya watch dengan guard `if (editor.getHTML() !== val)` untuk hindari loop infinite saat external update.

## Fase 3 (INPUT mode + submit router)

- **`SubmitRouter`** file baru di `Services/FormRegistry/`. Inspect field_schema → kelompokkan per resource (patient/visit/nurseAssessment/doctorExamination/medicalResume) → delegate ke adapter. Adapter Fase 3:
  - `patient.*` → `Patient::update()` (langsung mass-assign — kolom `name/nik/date_of_birth/dst` semua aman karena di whitelist FieldRegistry)
  - `visit.*` → hanya whitelist `follow_up_date/follow_up_reason/planning_follow_up`. Field lain di-reject dengan warning (flow status tidak boleh diubah via Form Registry).
  - `nurseAssessment.*` → `firstOrNew` (create-or-update), skip kalau sudah `is_finalized`. Set `assessed_by_id` saat first create.
  - `doctorExamination.*` → **SKIP** di Fase 3 (warning). Pakai endpoint `/dokter/*` langsung; resource ini kompleks dengan SOAP + diagnosa ICD + finalize flow.
  - `medicalResume.*` → SKIP (Fase 3 stub).
- **Static payload** → field dengan `binding.kind = 'static'` di-collect ke `static_payload` dictionary, disimpan ke `patient_documents.signatures.static_payload` (kolom jsonb). BUKAN signature object real — itu Fase 4. Reuse kolom karena adapter pattern, akan dipisah saat document_signatures table dipakai penuh.
- **Path nested ditolak untuk WRITE**: `visit.doctorExamination.doctor.name` (depth 3) di-skip dengan warning. Hanya `resource.column_langsung` (depth 2) yang boleh.
- **Field `clinic`/`aggregate`/`computed` read-only** — di-skip dengan warning, bukan error (boleh mix di INPUT form karena render-only di OUTPUT preview).
- **`signature_canvas` / `signature_placeholder` type SKIP** di Fase 3 dengan warning. Akan di-handle di Fase 4.
- **Endpoint baru**: `POST /rekam-medis/form/{code}/submit` (body `{visit_id, data}`) → `{document_id, status: 'DRAFT', sync: {synced, skipped, warnings, static_payload}}`. Tidak gated permission (sama dengan endpoint runtime lain) — server cuma `auth:api`.
- **Auto-create patient_document DRAFT**: setiap submit → buat row baru `patient_documents`. Finalize TERPISAH via `POST /document/{id}/finalize`. Pattern manual-finalize: admin/dokter bisa update DRAFT (re-submit untuk overwrite) sebelum lock. **TODO**: idempotent update — saat submit ulang untuk visit yang sama, sekarang BIKIN row baru. Sebaiknya: cek existing DRAFT untuk (visit, template_code) → update kalau ada. Belum diimplementasi di Fase 3.
- **OUTPUT-only template menolak submit**: `kind === 'OUTPUT'` di template → throw exception "Template adalah OUTPUT-only" → 422.
- **Frontend**:
  - `components/forms/FormFieldRenderer.vue` — render 1 field by type. Support: text, longtext, date, time, number, enum_gender, multi_checkbox (checkbox tunggal, multi-option list Fase 5), radio_with_detail, structured_list, signature (placeholder visual). Server-side error → tampil inline per field via prop `error`.
  - `components/forms/FormRMRenderer.vue` — extend dengan auto-detect mode (`template.kind`). INPUT → render form via FormFieldRenderer, tombol "Simpan sebagai Draft" → submit. Setelah submit, tab switch ke OUTPUT (preview HTML dengan data yang baru ter-sync) + tombol "Finalisasi & Lock". HYBRID → tab Isi Data + Preview/Cetak.
  - `formData` di komponen lokal (bukan store) — kalau modal ditutup, draft hilang.
- **Integrasi AdmisiView** (`views/AdmisiView.vue`): `<FormSection station="admisi" section="identitas" />` di dalam modal "Detail Kunjungan" (`visitDetailRow.visitId` sebagai konteks). Auto-hide kalau tidak ada form ter-assign.
- **GENERAL_CONSENT pilot** (`RM-1.1`):
  - Mix field: `patient.{name,nik,no_rm,date_of_birth,gender,address,phone}` (db) + `clinic.*` (read-only, render-only) + `visit.visit_date` (db, read-only di adapter karena tidak whitelist) + 6 static (3 setuju + 3 saksi).
  - Station assignments: `admisi/identitas` + `dokter/consent`.
  - Field `visit.visit_date` akan kena warning saat submit ("read-only via Form Registry") — by design.

## Fase 4 (signature flow + status transitions + addendum)

- **Model baru**: `App\Models\DocumentSignature` + `App\Models\DocumentAddendum`. **PENTING**: Addendum model wajib explicit `$table = 'document_addenda'` — Laravel default pluralize `DocumentAddendum` → `document_addendums` yang SALAH. Migration pakai Latin plural `document_addenda`.
- **`DocumentSignature` append-only**: `$timestamps = false` (cuma `created_at`, tanpa `updated_at`). `booted()` throw exception di `updating`/`deleting` event. Test PASS — model guard catch tampering attempt.
- **`SignatureService::capture()`**:
  - Validate `signer_type` ∈ SIGNER_TYPES (patient/guardian/witness/doctor/nurse/staff).
  - Validate identity: `doctor/nurse/staff` butuh `signer_user_id`. `patient` butuh `signer_patient_id`. `witness/guardian` butuh `signer_external_identity.nama`.
  - Reject kalau dokumen sudah FINALIZED/FINAL/VOID/REJECTED.
  - `captured_at` SELALU dari server (`now()`).
  - `integrity_hash = SHA-256(svg + captured_at.format('Y-m-d H:i:s') + patient_document_id + identity_key)`. **TIDAK pakai microseconds (.u)** — bedrock cross-DB (SQLite text vs Postgres timestamp). Verify pakai format yang sama.
  - Auto-advance status: DRAFT/RENDERED → PENDING_SIGNATURE saat first sign.
- **`FormRegistryService::finalize()` validate required signers**: scan `field_schema.fields[]` untuk `type='signature_canvas' AND required=true`. Kumpulkan `signer_type` yang required. Cek `document_signatures` table — kalau ada required signer yang belum ter-capture, throw 422 "Belum bisa finalize — signature wajib belum lengkap: X, Y". Test PASS.
- **`FormRegistryService::markRendered()`** soft transition DRAFT → RENDERED (idempoten — kalau bukan DRAFT, return as-is).
- **`FormRegistryService::createAddendum()`** validate `status ∈ FINALIZED/FINAL` → buat row `document_addenda` dengan `created_by = auth user`. Addendum finalisasi via signature terpisah (signature_id FK).
- **Endpoint baru Fase 4** (semua tidak gated, auth:api saja):
  - `POST /document/{id}/mark-rendered` → idempoten transition
  - `POST /document/{id}/sign` → capture (validate full di service)
  - `GET /document/{id}/signatures` → list semua signature
  - `GET /signature/{signatureId}/verify` → re-hash dan cocokkan
  - `GET /signature/{signatureId}/audit` → admin lihat metadata lengkap
  - `GET /ttd-queue` → grouped by patient untuk dokter yang login
  - `POST /document/{id}/addendum` → buat addendum
- **`ttdQueueForDoctor`**: filter dokumen yang punya field `signature_canvas` dengan `signer_type='doctor'` DAN dokter ini belum TTD. Group by `patient_id`. Status filter: `PENDING_SIGNATURE/RENDERED/DRAFT` (semua yang belum finalize).
- **GENERAL_CONSENT seeder extended** dengan 2 signature_canvas field: `ttd_pasien` (signer_type=patient, required) + `ttd_saksi` (signer_type=witness, required).
- **Frontend**:
  - Dep baru: `signature_pad` (~7KB gzip).
  - `components/forms/signature/SignatureCanvas.vue` — wrap signature_pad. Track stroke_count, total_duration_ms, total_points untuk biometric_metadata. High-DPI canvas via `devicePixelRatio` scale.
  - `components/forms/signature/SignatureCaptureModal.vue` — full-screen capture modal. Required checkbox "Saya sudah membaca isi dokumen…" (PMK 24/2022 Section 9.2 — read confirmation). External identity form (nama+NIK+hubungan) untuk witness/guardian.
  - `FormFieldRenderer.vue` — type `signature_canvas` jadi tombol "Buka Canvas Tanda Tangan" → emit `capture-signature` event dengan payload SVG+PNG+biometric.
  - `FormRMRenderer.vue` — section "Tanda Tangan" muncul setelah submit DRAFT (butuh document_id). Tombol "Finalisasi & Lock" disabled kalau ada required signer yang belum TTD.
  - `views/TtdDokumenView.vue` — halaman standalone `/ttd-dokumen` (route + sidebar entry under "Pemeriksaan Dokter"). Permission `rme_dokter.read`. Grouped by patient. Klik baris → modal preview + tombol "Tanda Tangani" → SignatureCaptureModal.
- **Patient signature dari renderer Fase 4 belum lookup patient_id otomatis**: `onCaptureSignature` di FormRMRenderer tidak punya context patient. Backend tetap accept dengan `signer_external_identity` fallback. **TODO Fase 5**: pass `patient_id` prop ke FormRMRenderer & FormSection (dari Visit context di parent station view).
- **Status `signatures` dipake dual-purpose**: kolom `signatures` (jsonb) di `patient_documents` masih nampung `static_payload` Fase 3. Saat capture signature → row baru di `document_signatures` table, BUKAN di kolom jsonb. Dua-duanya koeksistensi.

## Fase 5 (ScoringEngine + CUSTOM_COMPONENT + SVG embed)

- **`ScoringEngine`** file baru di `Services/FormRegistry/`. Stateless. 3 primitif:
  - `computed_sum` → sum dari field di `sum_of: ['key1', 'key2']`
  - `computed_threshold` → label dari `thresholds: [{max, label}]` sorted asc, pick first yang `value <= max`
  - `computed_duration` → menit antara 2 field time (Carbon-parsed)
- **Multi-pass compute** (max 3 iterasi): threshold bisa depend on sum → iterasi sampai stable.
- **`DocumentRenderer::render()`** sekarang menerima `$documentPayload` (jawaban user dari `patient_documents.signatures.static_payload`). Step:
  1. Compute scored fields via ScoringEngine
  2. Per field: computed_* → pakai hasil compute; static dengan payload override → pakai payload; selain itu → BindingResolver.
  3. SKIP `signature_canvas/_placeholder` — biarkan placeholder `{{ttd_*}}` utuh.
  4. Substitute `keepUnknown=true` supaya placeholder signature tidak ke-replace empty.
- **`DocumentRenderer::embedSignatures()`** sekarang implemented. Map `{fieldKey: signer_type}` → cari signature record by signer_type → render `<div><svg style="width:100%">...</svg></div>` inline. Cleanup placeholder yang signature-nya belum ada → empty string.
- **`extractSignatureFieldMap($schema)`** helper baru di DocumentRenderer untuk extract mapping.
- **`FormRegistryService::finalize()`** sekarang pipeline lengkap:
  1. Validate required signers
  2. Load static_payload dari doc.signatures
  3. `render($template, $visitId, $payload)` — substitusi data + computed
  4. `embedSignatures($html, $fieldMap, $sigByType)` — inject SVG
  5. Hash final
- **`FormRegistryService::submit()`** sekarang compute scored fields server-side + return ke response.sync.computed (sanity check vs frontend live compute).
- **`SubmitRouter`** treat `scored_radio` + `computed_*` sebagai static_payload (bukan sync ke tabel klinis).
- **Frontend `useScoring.js`** composable — mirror ScoringEngine PHP. Pakai computed ref multi-pass (sama 3 iterasi). Inject hasil ke formData via `watch` di FormRMRenderer.
- **`FormFieldRenderer`** tambah 4 type baru:
  - `scored_radio` — radio dengan opsi+skor (klik update modelValue ke score number)
  - `computed_sum` — display read-only badge biru
  - `computed_threshold` — display read-only badge oranye
  - `computed_duration` — display read-only badge hijau
- **`FormRMRenderer`** tambah CUSTOM_COMPONENT support: `resolveCustomComponent(name)` → lazy import → render via `<component :is="customComponent" v-model="formData">`.
- **`components/forms/custom/customComponents.js`** static map registry. Tambah custom component → edit 1 file + drop file `.vue` di folder.
- **`MorseFallScale.vue`** contoh custom component dengan UI dense (6 row × button option + footer gauge interpretasi color-coded).
- **`MapperStep` UI editor SCORED_FORM**: per field type, tampilkan editor khusus:
  - scored_radio → options[] (label + score) + tombol +Add
  - computed_sum → multi-select chips dari `scoredFieldKeys`
  - computed_threshold → `based_on` dropdown + thresholds[] (max + label) + tombol +Add
  - computed_duration → from/to dropdown
  - signature_canvas → signer_type dropdown + required checkbox
- **Pilot MORSE_FALL_SCALE** seeder: 6 scored_radio + computed_sum (total_score) + computed_threshold (interpretasi 3 level Rendah/Sedang/Tinggi) + signature_canvas nurse. Station assignment: perawat/asesmen_input. document_type RM-2.1.

### Bug fix critical Fase 5

- **Issue**: SVG signature tidak embed ke rendered_html.
- **Root cause**: `render()` substitute SEMUA placeholder dulu, termasuk `{{ttd_*}}` jadi empty string (karena binding=static, payload tidak punya key itu). `embedSignatures()` dipanggil setelah, tapi placeholder sudah hilang.
- **Fix**: render() SKIP signature_canvas/_placeholder dari substitution + pakai `keepUnknown=true`. embedSignatures() yang handle replace.

## Fase 6 (audit log + SOP docs)

- **`FormRegistryAudit`** file baru di `Services/FormRegistry/`. Wrapper static class untuk `SystemLog` (table `system_logs` existing — reuse pattern PerawatService::log + AdmisiService::log). Action namespace `FORM_*`:
  - `FORM_TEMPLATE_CREATED/UPDATED/ACTIVATED/DEACTIVATED` (di MasterDataController CRUD methods)
  - `FORM_DOC_SUBMITTED/RENDERED/FINALIZED` (di FormRegistryService)
  - `FORM_SIG_CAPTURED` (di SignatureService::capture)
  - `FORM_ADDENDUM_CREATED` (di FormRegistryService::createAddendum)
- **Defensive try/catch** di `record()` — audit log NEVER bikin operasi utama gagal. `report($e)` ke Sentry/error channel kalau exception.
- **Context dict** di-append ke description sebagai `ctx={...json}` — supaya audit lookup via LIKE `%"patient_document_id":"X"%` jalan.
- **`FormRegistryAudit::queryForDocument($docId)`** helper return Query: filter `action LIKE 'FORM_%'` + (direct model_id match OR description contains patient_document_id). Pakai di endpoint `/document/{id}/audit-log`.
- **Endpoint Fase 6**: `GET /rekam-medis/document/{id}/audit-log` → limit 200 entry, eager-load `user:id,name,email`, sorted DESC.
- **Auth context**: `auth('api')->id()` di static method. Request facade pakai helper `request()` (null-safe untuk CLI/seeder context).
- **SOP docs** di `Docs/form-registry-sop.md` (NEW). Step-by-step admin onboarding form baru: upload .docx → mapper UI → activate. Plus runtime flow dokter/perawat, addendum, troubleshooting, audit log query.
- **Gzip rendered_html storage**: SKIPPED (ditunda sampai 10K+ dokumen finalized). Migration siap kalau perlu — tambah `rendered_html_gz LONGBLOB nullable`, update finalize() compress via gzcompress(), update snapshot() decompress.

### Roadmap di luar v1 (dicatat di Fase 6 tapi tidak diimplementasi)

- Gzip storage rendered_html
- Archive tier untuk dokumen >2 tahun
- UI addendum (frontend form modal)
- Patient_id auto-context dari renderer ke SignatureCaptureModal
- Validasi server-side payload (NIK format, gender enum) di SubmitRouter
- Multi-page wizard untuk template panjang
- PDF parsing (saat library PHP mature)
- Auto-discover custom components via `import.meta.glob`

## Gotcha & catatan

- Saat tambah field di FormRegistry whitelist, label di sini juga jadi haystack BindingSuggester. Pastikan label cocok dengan istilah real klinik supaya match high-confidence.
- `FormParserService::slugifyKey` pakai underscore (`_`), bukan dash, supaya cocok dengan regex placeholder `[a-zA-Z_][a-zA-Z0-9_]*`.
- Tabel ICD-10 = `icd10_codes` (BUKAN `icd10_descriptions`). Model: `App\Models\Icd10Code`.
- Permission middleware backend Fase 2: `/master/form-template/*` + `/master/field-registry|station-sections|document-types` semua `permission:form_template.{read|write}`. Endpoint runtime `/rekam-medis/forms` + `/rekam-medis/form/{code}/render|submit` + `/document/{id}/finalize|render` TIDAK di-gate (cukup auth:api) supaya nurse/dokter yang sudah granted modul stasiun mereka tetap bisa pakai form tanpa perlu permission tambahan.
- Submit INPUT bisa mengubah data klinis pasien (via patient.* adapter). **Audit log nominal di adapter belum ada** — sebaiknya log per-field-changed saat Fase 6 hardening, atau sekarang masuk ke `audit_logs` table existing.
- **Dev DB = PostgreSQL** (bukan SQLite seperti dugaan awal Fase 1). Migration & seeder jalan keduanya, tapi behavior subtle bisa beda — mis. jsonb di pg vs text di sqlite. Pernyataan Fase 1 perlu update.
- **`DocumentAddendum::$table` wajib eksplisit** = 'document_addenda'. Tanpa itu Laravel cari table `document_addendums` (default plural-s) → table not found.
- **Signature hash format**: pakai `Y-m-d H:i:s` (tanpa microseconds). Konsisten antara capture() dan verify(). Trade-off: collision dalam detik yang sama mustahil di praktek (signer_identity_key tambahan unik per signer).
- **`signature_pad` v5+ pakai event listener** (`addEventListener('beginStroke', ...)`), bukan callback property `onBegin`. Sintaks lama tidak jalan.
- **Audit log Form Registry**: query via `WHERE action LIKE 'FORM_%'` di `system_logs` table. Description format: `"<base text> | ctx={json}"` — context JSON ber-key familiar (template_code, patient_document_id, signer_type, dst). Endpoint dedicated `/document/{id}/audit-log` untuk per-document timeline.
- **SystemLog model**: tidak punya `updated_at` semantic untuk audit (kolom ada di table tapi sama dengan created_at). Append-only by convention.

## v2 gap-fix (2026-05-30) — semua 13 selesai

Eksekusi 13 outstanding gap dari Fase 6 backlog. Per-gap detail:

1. **SubmitRouter `doctorExamination`+`medicalResume`** — `syncDoctorExamination()` + `syncMedicalResume()` di SubmitRouter. firstOrNew(visit_id) + cek `is_finalized` (+ `is_editable` untuk resume) + set creator (`doctor_id = user.employee_id`) saat first create. INPUT form yang reference SOAP/anamnese/resume_s/o/a/p sekarang ter-sync.

2. **UI Addendum** — backend `listByStationSection` inject `existing_document: {id, status, finalized_at}` per template. FormRMRenderer: badge status di card list + tombol "Buat Addendum" (visible saat FINALIZED) → modal dengan form `alasan` + `isi_koreksi` (z-index 1100). Pakai `formTemplateApi.createAddendum(docId, payload)`.

3. **Server-side validation payload** — SubmitRouter `rulesFor($resource)` + `validatePatch()`. Sometimes-style: hanya field yang ada di patch divalidasi. Rules: NIK max:50, gender in:L,P, td_sistol between:40,300, suhu between:30,45, dst. Re-map error key dari `data.<column>` ke `data.<fieldKey>` setelah catch ValidationException (kolom `columnToFieldKey` dibangun saat grouping). Frontend strip prefix `data.` → fieldErrors[fieldKey]. 422 per-field, bukan 500 DB error.

4. **AggregateResolver `visitServices`+`diagnosticResults.summary`** — `resolveVisitServices` format `list_simple` (default: "1. Operasi Katarak Phaco x1") / `list_with_tarif` ("+ Rp 5.000.000"). `resolveDiagnosticResults` format `summary_per_jenis`: group by `test_type`, flatten `expertise_data` 1-level scalar + notes ("Biometri: notes_x | AL: 23.4 / USG B: notes_y").

5. **patient_id auto-context** — prop `patientId` di FormSection.vue + FormRMRenderer.vue. `onCaptureSignature` set `body.signer_patient_id = props.patientId` saat `signer_type='patient'`. AdmisiView pass `:patient-id="visitDetailRow.patientId"`, DokterView pass `:patient-id="store.selectedPatientId"`. Patient TTD tidak butuh isi nama+NIK manual lagi.

6. **Frontend UI audit log** — `formTemplateApi.auditLog(docId)` → `/rekam-medis/document/{id}/audit-log`. Tab "Audit Log" di FormRMRenderer modal (visible saat ada `existingDocId` atau `submittedDocId`), lazy-load on switch dengan `auditLoadedFor` guard. Label action Indonesia (FORM_DOC_SUBMITTED → "Form disubmit (Draft)" dst).

7. **Cleanup `binding.kind=computed`** — hapus dari BindingResolver match (dead code). SubmitRouter keep defensive skip (kind=computed → warning) untuk backward-compat template legacy.

8. **Gzip storage** — migration `2026_05_30_000007_add_rendered_html_gz_to_patient_documents` (binary nullable). PatientDocument fillable ditambah. `finalize()` simpan `gzcompress($html, 6)` ke `rendered_html_gz`, null-kan `rendered_html`. `getSnapshot()` decompress + fallback ke `rendered_html` untuk dokumen pre-gzip (forward-only). Hash dihitung dari HTML asli (reproducible).

9. **Compliance test PMK 24/2022** — split jadi 2:
   - `tests/Unit/FormRegistry/PMK24HashTest.php` — pure-logic: hash determinism + per-component sensitivity + FORM_ namespace + Y-m-d H:i:s (no microseconds). Jalan tanpa DB.
   - `tests/Feature/FormRegistry/PMK24ComplianceTest.php` — DocumentSignature append-only (update/delete throw). Auto-skip kalau pdo_sqlite tidak ada (via `setUpBeforeClass()`).
   - Result: 6 tests, 4 passed, 2 skipped (sqlite missing). 17 assertion.

10. **multi_checkbox options[] editor** — FormFieldRenderer support `field.options[]` (string atau `{value, label}`); modelValue = array; fallback single-boolean kalau options kosong (legacy/consent). `onMultiCheckChange` toggle value di array. MapperStep editor "Pilihan Checkbox" (value + label rows).

11. **PDF parsing** — `MasterDataController` accept `mimes:docx,pdf`. FormParserService dispatch by extension → `parsePdf()`: per-baris non-kosong, deteksi pola "Label: value" atau "Label:" → field text/static, sisanya `<p>` layout. `extractPdfText()` coba `Smalot\PdfParser\Parser` kalau ada (installed v2.12), fallback regex `(...)Tj`. Warning explicit "Best-effort, review manual".

12. **Multi-page wizard UI** — `pageCount` computed (max dari `field.page` + `field_schema.page_count`). `addPage()` / `removeLastPage()` (last + confirm + auto-migrate field). Per-field `<select>` "Hal N" visible saat pageCount>1. Backend tidak butuh perubahan — flat `fields[]` dengan attribute `page` per field; renderer/ScoringEngine iterasi flat tanpa peduli `page`.

13. **Hash microseconds** — by design + dokumentasi. SignatureService comment sudah jelas (line 60-63). Test PMK24HashTest verifikasi format `Y-m-d H:i:s` cross-DB. Docs/form-registry-sop.md v2 changelog appended.

### File yang dimodifikasi v2

Backend: SubmitRouter, FormRegistryService, BindingResolver, AggregateResolver, FormParserService, PatientDocument, MasterDataController, migration baru, tests/{TestCase,CreatesApplication}, 2 test file
Frontend: FormRMRenderer, FormSection, FormFieldRenderer, api.js, MapperStep, AdmisiView, DokterView

### Wajib post-deploy

- ✅ `php artisan migrate` (sudah dijalankan 2026-05-30)
- ✅ `composer require smalot/pdfparser` (sudah, v2.12.5)
- ✅ `php artisan test --filter PMK24` (6 tests, 4 passed + 2 skipped pdo_sqlite)

### Catatan gotcha v2

- **pdo_sqlite tidak ada di dev env** (cuma pdo_pgsql). Test DB-level di-skip otomatis. Untuk full test, install ext atau pakai postgres connection di phpunit.xml.
- **api.js change** — user/linter modifikasi file ini selama session (formTemplateApi.auditLog tetap utuh).
- **Field `page` per field** — saved flat di `field_schema.fields[]`, backward-compat dengan iterasi flattenFields. Field tanpa `page` = page 1.
- **PdfParser opsional** — kalau composer require di-skip, fallback regex `(...)Tj` aktif (akurasi rendah). PDF scan/image tetap butuh OCR.
- **Validation re-map** — kalau ada lebih dari satu field FE yang share kolom DB sama (mis. 2 field key beda → patient.name), error cuma kena field yang terakhir di-iterate. Edge case rare.

## UI polish 2026-05-30 (post-v2)

User complain "form RM jelek + posisi tidak natural" di DokterView. Polish UI-only (logic intact):

- **[FormSection.vue](arumed-frontend/src/components/forms/FormSection.vue)** — section card dengan `SECTION_META` mapping (`resume_output`=clipboard·biru, `surat`=mail·oranye, `consent`=shield·hijau, `identitas`=user·ungu). Subtitle auto-derive, override via prop `subtitle`. Empty state + spinner loading. Container radius 12px + shadow tipis.
- **[FormRMRenderer.vue:373-398](arumed-frontend/src/components/forms/FormRMRenderer.vue#L373)** — trigger `.frr-card` jadi 3-zone document chip: leading icon tinted per mode (OUTPUT=file-text/biru #1763d4, INPUT=edit-3/oranye #b46f00, HYBRID=layers/ungu #5d3fc9), main column (nama + meta inline `code · v · mode`), trailing (status badge UPPERCASE + chevron). Hover: border biru + bg #f3f8ff + chevron translateX(2px). Modal tab underline `#1763d4`, primary button `#1763d4` + `color:#fff !important` ([[feedback-styling-visibility]]).
- **[DokterView.vue](arumed-frontend/src/views/DokterView.vue)** — 3 `<FormSection>` dipindah dari sibling `.rmc` ke **dalam Tab SOAP** (setelah section Finalisasi). Wrapper `.rme-form-registry-stack` + banner `.rmd-form-banner` (gradient biru→ungu, icon putih). Lihat trap di [[dokterview-module]] section "Form Registry placement — flexbox trap".
- AdmisiView tidak terdampak — prop `subtitle` optional, fallback otomatis dari SECTION_META.

Links: [[project-arumed]] (modul list — Form Registry v2 SELESAI semua gap), [[dokterview-module]] (flexbox placement trap).
