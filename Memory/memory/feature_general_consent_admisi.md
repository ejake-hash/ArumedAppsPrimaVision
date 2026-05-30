---
name: feature_general_consent_admisi
description: "General Consent disisipkan di AdmisiView step 3 (pasien baru, opsional, TTD inline tanpa visit_id) + 2 bug gzip/placeholder yang diperbaiki"
metadata: 
  node_type: memory
  type: project
  originSessionId: 9ecb388d-02f1-4ad7-a9f4-57051acb536d
---

Kasus pertama "sisipkan Form Registry di tombol UI + auto-fill" (2026-05-29). Pasien BARU bisa TTD General Consent di wizard Admisi step 3 SEBELUM cetak antrean — **opsional** (badge amber/hijau, tombol cetak tetap aktif), pasien lama tidak diminta.

**Arsitektur (GC inline tanpa ID):** visit/patient baru lahir saat `submitRegistration`, jadi GC di-render dari DATA FORM (bukan DB). Alur:
- `POST /admisi/consent/preview` → `AdmisiService::previewConsent()` → `DocumentRenderer::renderForPreview($tpl, $formValues, $staticPayload, $sigSvgByType)` (method BARU, tanpa Visit; `db` binding `patient.*`/`visit.*` diambil dari formValues by kolom-terakhir, `clinic.*` via `BindingResolver::resolveClinicPublic()` baru, signature di-embed via `embedSignatures`).
- TTD pakai komponen existing `SignatureCaptureModal.vue` (standalone, emit `{signature_svg, signature_png_base64, external_identity}`, tidak butuh docId) — di-reuse, bukan bikin baru.
- Saat submit, frontend kirim `payload.consent = {template_code, signatures[], static_payload}`. Validasi di `daftarKunjungan`. `AdmisiService::registerVisit` panggil `saveConsentDocument()` (private, **non-fatal** try/catch + Log::warning) DALAM transaksi: buat PatientDocument DRAFT → `SignatureService::capture()` per signer → render+embed → set FINALIZED.

**2 BUG ditemukan saat verifikasi (PENTING, salah satunya laten di kode existing):**
1. **gzip bytea Postgres**: `gzcompress()` → kolom `rendered_html_gz` (tipe `binary`/bytea) → Postgres tolak `SQLSTATE[22021] invalid byte sequence for encoding UTF8 (0x9c)`. **Pola IDENTIK ada di `FormRegistryService::finalize()` (baris ~178) — belum pernah kena di Postgres karena PMK test pakai sqlite (lihat [[feature_form_registry]] "dev DB Postgres no sqlite ext").** Solusi di sini: simpan `rendered_html` PLAIN (longText, aman), `rendered_html_gz=null`. `getSnapshot()` sudah fallback ke plain. **Kalau nanti finalize() dipakai di Postgres, akan meledak — perlu fix serupa (bind binary atau plain).**
2. **layout GC tanpa placeholder TTD**: seeder GENERAL_CONSENT (`FormTemplateSeeder::seedGeneralConsent`) dulu cuma teks "Tanda tangan" tanpa `{{ttd_pasien}}`/`{{ttd_saksi}}` (komentar "Fase 4"). TTD ter-capture tapi tak muncul di HTML. Fix: tambah `{{ttd_pasien}}`+`{{ttd_saksi}}` di layout_html → **WAJIB re-seed** (`php artisan db:seed --class=FormTemplateSeeder --force`).

**Verified:** preview (nama+svg+2 sigfield) & save (FINALIZED, 2 sig, 2 svg embed, 2 queue) OK; build FE hijau. File: AdmisiService/AdmisiController/api.php(`/admisi/consent/preview`)/DocumentRenderer/BindingResolver/AdmisiView.vue(card `.gc-*` + modal + SignatureCaptureModal)/FormTemplateSeeder.

Mekanisme "form per tombol" = `station_assignments` JSON + `FormRegistryService::listByStationSection` + `SectionRegistry` (admisi→[identitas,dokumen_admisi]); GC sudah ter-assign admisi/identitas. UI editor station_assignments di FormTemplateWizard BELUM dibuat (Bagian C plan, ditunda). Lihat [[feature_form_registry]] [[feature_admisi_view]].
