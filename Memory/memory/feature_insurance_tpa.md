---
name: feature-insurance-tpa
description: "Modul Asuransi/TPA non-BPJS live (Sprint 1-4 done 2026-05-30). Verifikasi eligibility manual + klaim workflow + aging report, terpisah dari BPJS flow. Frontend /asuransi 3 tab."
metadata: 
  node_type: memory
  type: project
  originSessionId: 46cd865d-9adc-4a43-889d-bb1fddf2374d
---

# Modul Asuransi/TPA Non-BPJS — LIVE

**Status:** Sprint 1-4 done 2026-05-30. Spec: `Docs/ARUMED_INSURANCE_TPA_MODULE.md`.

## Why
Project [[project-arumed]] butuh handle penjamin asuransi swasta/TPA & PERUSAHAAN selain BPJS. Tidak menyentuh `bpjs_claims` / `KlaimController` / alur BPJS. Semua integrasi portal TPA manual oleh billing — sistem catat hasil.

## How to apply
Saat kerja modul asuransi non-BPJS / TPA / klaim swasta, ekstend yang sudah ada — jangan bikin ulang. Permission: `kasir.*` (read/write/delete). Modul akses lewat sidebar → `/asuransi`. BPJS tetap pakai `/bpjs` ([[dokterview-module]]/KlaimController).

## Database (6 migration baru, batch 26-27)
- `2026_05_30_000001_add_tpa_fields_to_insurers_table.php` — +6 kolom: portal_url, pic_name, pic_phone, pic_email, claim_submission_notes, sla_days(default 14)
- `2026_05_30_000002_add_insurance_verification_status_to_visits_table.php` — enum NONE/PENDING/VERIFIED/ISSUE + insurance_verified_at
- `2026_05_30_000003_create_insurer_document_requirements_table.php` — checklist dokumen per TPA
- `2026_05_30_000004_create_insurance_verifications_table.php` — hasil eligibility per visit. status: PENDING/VERIFIED/NEEDS_CLARIFICATION/REJECTED. plafon/copay/exclusion_flags jsonb
- `2026_05_30_000005_create_insurance_claims_table.php` — workflow klaim. status: DRAFT/SUBMITTED/APPROVED/REJECTED/APPEALED. documents_checklist jsonb, resubmission_count
- `2026_05_30_000006_create_insurance_claim_logs_table.php` — audit immutable (no softDeletes)

Konvensi: `uuid('id')->primary()` + HasUuids, audit user (`verified_by`/`submitted_by`/`performed_by`) = `uuid nullable` TANPA `->constrained()` (loose, isi via `auth('api')->id()`).

## Backend
- **4 Model baru**: `InsuranceVerification`, `InsuranceClaim` (+const STATUS_*), `InsuranceClaimLog` (+const ACTION_*, no SoftDeletes), `InsurerDocumentRequirement`
- **Insurer & Visit** existing diupdate: +fillable kolom baru + relasi (Insurer::documentRequirements, Visit::insuranceVerifications/latestInsuranceVerification/insuranceClaims)
- **`AsuransiService`** ([app/Services/AsuransiService.php](backend/app/Services/AsuransiService.php)) ~480 baris: verifikasi CRUD + pendingVerifications (wait_minutes), klaim CRUD + submit/status/resubmit (DRAFT only update, REJECTED only resubmit, validate checklist required), aging report + dashboardSummary (6 metric), 4 docRequirement CRUD, helpers `syncVisitStatus`/`addLog`/`validateDocumentChecklist`/`notifySupervisor` (broadcast via Notification model)
- **`AsuransiController`** [app/Http/Controllers/AsuransiController.php](backend/app/Http/Controllers/AsuransiController.php) — 19 endpoint, controller tipis + validate, envelope `ok()`/`error()` lokal
- **19 routes** di [api.php](backend/routes/api.php) prefix `/api/v1/asuransi` middleware `permission:kasir.*` + 1 di kasir `/kasir/insurance-warning/{visitId}`
- **`AdmisiService::registerVisit` + `daftarkanWalkIn`**: ASURANSI/PERUSAHAAN → set `insurance_verification_status='PENDING'` + bikin InsuranceVerification awal status PENDING dengan policy_number/member_name/member_card_number dari payload
- **`AdmisiController`** — +3 field validation policy_number/member_name/member_card_number di register + walkin
- **`KasirService`**: constructor inject AsuransiService. `getInsuranceWarning(visitId)` return {show,status,message} (non-blocker UI). `maybeCreateInsuranceClaimDraft()` auto-call setelah `processPayment` PAID, anti-duplikat via billing_invoice_id, try/catch silent (gagal auto-draft tidak rollback payment)
- **`MasterDataController`** — +6 field validation TPA di storePenjamin/updatePenjamin. Service tidak perlu disentuh (mass-assignment via fillable yang sudah update)

## Frontend (Sprint 4)
- **`asuransiApi`** di [services/api.js](arumed-frontend/src/services/api.js) — 19 method (verifikasi, klaim, laporan, docReq). `kasirApi.insuranceWarning` ditambah.
- **`asuransiStore`** [stores/asuransiStore.js](arumed-frontend/src/stores/asuransiStore.js) Composition API: state pendingList/verifikasi/klaims/aging/summary/docRequirements + getter overdueCount + actions full CRUD
- **`AsuransiView.vue`** [views/AsuransiView.vue](arumed-frontend/src/views/AsuransiView.vue) — 3 tab (Verifikasi Pending / Klaim Management / Aging Report) + 5 modal (Verifikasi form, Submit klaim, Update Status, Resubmit, Log timeline) + 6 stat card. Route `/asuransi` lazy-loaded
- **`AdmisiView.vue`**: form tambah 3 field (insuranceNo/memberName/memberCardNumber) saat guarantor ASURANSI/PERUSAHAAN, payload kirim policy_number/member_name/member_card_number. Info-box PIC TPA (auto-show dari selectedInsurerInfo). Badge `.verif-badge.vb-{pending|verified|issue}` di list antrean (warna kuning/hijau/merah)
- **`KasirView.vue`**: state `insuranceWarning` (fetch saat pickP non-blocking), panel `.insurance-alert.ia-pending/ia-issue` di col-left atas (non-blocker, kasir tetap bisa bayar)

## Notes
- BPJS flow tidak disentuh — `KlaimController`/`KlaimService`/`bpjs_claims`/`bpjs_*` migrations intact
- Tidak ada permission baru di seeder (pakai `kasir.*` existing). Kalau mau modul terpisah, tambah `asuransi` di PermissionSeeder
- Auto-draft klaim setelah PAID hanya jalan kalau visit guarantor ASURANSI/PERUSAHAAN + verifikasi VERIFIED + insurer_id ada
- COB ([[feature-tarif-paket-bedah]]) belum di-test khusus dengan klaim ganda penjamin — `visit_cob` table existing tidak diutak-atik
- Compatible dgn TPA parent/child (insurers `parent_id` di [[kasir-getprice-resolve]])
- Sidebar nav: AppSidebar.vue link "Asuransi & TPA" setelah "Klaim BPJS", visible jika `kasir.read`

## Bugfix & UI rev 2026-05-28
- **Patient kolom**: pakai `no_rm` (bukan `medical_record_number`) di `pendingVerifications` & `indexKlaim`. Konvensi Indonesia di project ini.
- **Visit::latestInsuranceVerification**: PostgreSQL tidak punya `MAX(uuid)` → `latestOfMany()` gagal. Workaround: `hasOne + orderByDesc('created_at')`. Pattern ini berlaku untuk semua relasi latest-of-many di project pgsql.
- **Tab 1 UI**: kolom "No. Antrean" → "No." (index 1,2,3), "MRN" → "No. Rekam Medis". +3 kolom: No. Polis (mono), Nama Peserta, No. Kartu (mono) — diambil dari `latestInsuranceVerification` (policy_number/member_name/member_card_number). Total 10 kolom.
- **Tab 2 UI**: badge 📎 X/Y di samping pill status (`docProgress()` hitung dari `documents_checklist` jsonb). Hijau jika lengkap, kuning jika partial. Tooltip detail.
- **Tab 2 filter**: chip period (`Semua/Hari Ini/7 Hari/Bulan Ini`) auto-set date_from/date_to. Custom datepicker tetap ada di samping.

## Bugfix wait_minutes 2026-05-29
- **Gejala:** kolom "Menunggu" di Tab Verifikasi Pending tampil ~44 menit padahal pasien baru diadmisi beberapa menit lalu. BUKAN bug timezone — semua timestamp konsisten Asia/Jakarta (`APP_TIMEZONE=Asia/Jakarta`, pgsql, `now()` & `created_at` cocok).
- **Akar masalah:** `visits.created_at` terisi saat pasien **ambil tiket antrean di AnjunganView**, bukan saat konfirmasi/daftar di AdmisiView. Jadi wait lama termasuk waktu antre poli umum sebelum sampai meja verifikasi.
- **Fix** di `AsuransiService::pendingVerifications` ([AsuransiService.php](backend/app/Services/AsuransiService.php)): `wait_minutes` sekarang dihitung dari `insurance_verifications.created_at` (saat admisi set PENDING via [[feature-admisi-view]]), fallback `visits.created_at` kalau row verif null. Wajib tambah `created_at` ke kolom eager-load `latestInsuranceVerification` (sebelumnya tidak di-select → null).
- **Pelajaran umum:** untuk metrik "tunggu di stasiun X", pakai timestamp masuk stasiun itu, JANGAN `visits.created_at` (itu waktu ambil antrean Anjungan). Berlaku untuk semua station view sejenis.

## UX Rev: Cost-sharing (plafon/copay) — keputusan & implementasi 2026-05-28
**Keputusan: TIDAK auto-hitung copay/plafon di kasir.** Sistem cuma decision-support tool.

**Why:** aturan TPA tidak seragam (min vs max vs admin), eligibility real-time bisa basi, edge case banyak (COB, exclusion, pre-auth). Auto-hitung salah 1 case → over/under-charge pasien → komplain/rugi. Klinik kecil-menengah lebih percaya kalkulator manual + verifikasi telepon.

**How to apply:** Kalau user minta auto-split invoice atau auto-set nominal bayar, push back dulu. Cukup tampilkan angka eligibility + estimasi referensi di 3 titik: modal verifikasi, panel KasirView, modal status APPROVED. Kasir tetap input nominal manual.

**Implementasi (frontend pure, no backend logic change):**
- **Modal Verifikasi Eligibility** ([AsuransiView.vue](arumed-frontend/src/views/AsuransiView.vue)): dirombak jadi 4 grup dengan instruksi langkah-langkah portal TPA. Tiap field punya field-help inline + format Rp realtime. Computed `copayPreview` (simulasi tagihan Rp 1jt dummy → pasien tanggung X, TPA Y, warning plafon kurang). Note italic "estimasi referensi, kasir tetap hitung manual".
- **Panel Info Eligibility di KasirView** ([KasirView.vue](arumed-frontend/src/views/KasirView.vue)): kartu hijau border emerald — 4 kolom grid (Plafon, Copay %, Copay Rp, Estimasi Pasien Bayar dari total invoice riil) + meta polis/peserta + coverage notes + exclusion chips merah + plafon warn kalau sisa < klaim + hint kuat "TIDAK otomatis". Computed `kasirCopayEstimate` + `eligPlafonWarn`. Backend `KasirService::getInsuranceWarning` di-extend return `verification` object (selalu kalau guarantor ASURANSI/PERUSAHAAN, tidak tergantung `show` flag).
- **Modal Update Status Klaim** APPROVED: ringkasan rekonsiliasi otomatis (Klaim diajukan → TPA setujui → Selisih jadi Patient Responsibility). REJECTED: peringatan klaim akan jadi patient responsibility kalau resubmit gagal. Computed `approvedDiff`.

**Format angka Rupiah/percent (rev terakhir):**
- `formatRp = Math.round(...).toLocaleString('id-ID')` — Rupiah selalu integer (tidak ada sen)
- Prefill modal: backend cast `decimal:2` return string `"100000.00"` → frontend `Math.round(Number(v.plafon_amount))` sebelum masuk field input
- Input `step="1000"` untuk plafon & copay tetap (spinner naik per Rp 1.000), `step="1"` untuk copay % (bulat)
- KasirView panel pakai `Math.round(Number(...))` di display plafon/copay/persen
- Backend schema tetap `decimal(15,2)` & `decimal(5,2)` — fleksibel kalau suatu saat butuh sen, frontend tinggal hilangkan `Math.round`

**Triggers untuk auto-hitung di masa depan (kalau klinik request):** >50 pasien asuransi/hari + 1-2 TPA dominan dengan aturan polis konsisten + anggaran maintenance per TPA. Belum ada saat ini.

## Audit bug 2026-06-01 (AsuransiView + jembatan ke Kasir, belum commit, E2E 22/0)

Diaudit bareng KasirView (detail Kasir di [[feature-kasir-view]] ronde-3). Yang terkait modul Asuransi:
- **🟡 `updateVerifikasi` `array_filter(fn=>$v!==null)`** membuang 0/null/'' → admin **TAK bisa reset `covered_amount` ke null** (batalkan cover yg terlanjur diisi), juga tak bisa kosongkan notes. FIX [AsuransiService.php](backend/app/Services/AsuransiService.php): ganti ke loop `array_key_exists($key,$data)` utk 11 field — hanya field yg dikirim yg di-update, nilai null/0/'' diterapkan apa adanya. Aman karena `$request->validate()` di controller hanya mengembalikan key yg memang dikirim, & FE `submitVerif` selalu spread full `verifForm` (semua key present). E2E: reset covered→null PASS, copay→0 PASS.
- **🟡 Timezone WIB** di `setPeriod` (quick-filter periode) & `exportAgingCsv` (nama file) — `new Date().toISOString().slice(0,10)` konversi ke UTC → di WIB tengah malam–07:00 mundur 1 hari (filter "Hari Ini" salah). FIX [AsuransiView.vue](arumed-frontend/src/views/AsuransiView.vue): helper `localYmd(d)` pakai getFullYear/getMonth/getDate lokal. Lihat [[feedback-timezone-wib]].
- **🟠 Jembatan cover→Kasir (di KasirService, relevan modul ini):** `covered_amount` yg diinput admin via `syncCoveredToInvoice` (sudah `min(covered,total)` saat verif disimpan) BISA jadi basi bila total invoice berubah setelahnya (item ditambah/hapus) → cover > total = keliru "full cover", atau sisa negatif. FIX di `recalculateInvoice`: clamp `covered_amount` ≤ total terkini (hanya turunkan). E2E: hapus item→cover 0, tambah item→cover tetap partial ≤ total.
- **POIN user TERVERIFIKASI — full cover asuransi = KONFIRMASI saja, tak bayar di kasir:** UI `isFullCover` (cover≥total) tampil box "Konfirmasi Lunas (Ditanggung Asuransi)", sembunyikan metode bayar+Campuran. `KasirService::confirmInsuranceCoverage` → PAID, payment_method=INSURANCE, paid_amount=0, covered=total; **guard 422 kalau masih ada sisa pasien (>0.009)** → partial cover TIDAK bisa konfirmasi penuh, harus bayar selisih dulu (E2E P2 full PASS, P2b partial-ditolak-lalu-bayar-selisih PASS). Konsisten dgn [[feature-asuransi-cover-kasir]].
- **Tab "Sedang Dilayani" (`inServiceVerifications`)** dipakai admin set jumlah cover sebelum pasien sampai kasir — kolom Total Tagihan / Ditanggung / Sisa Pasien (`patient_due`). Cover diinput di sini → `syncCoveredToInvoice` tulis ke `billing_invoices.covered_amount` (skip jika invoice PAID/CANCELLED).
