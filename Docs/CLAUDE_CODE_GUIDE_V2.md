# Panduan Claude Code — Arumed Apps
## Panduan Lengkap dari Nol sampai Selesai
### Versi 2.0 — Updated dengan Fitur Follow-up (Kontrol Ulang)

---

## 🧭 BACA INI DULU (UNTUK PEMULA)

### Apa Itu Claude Code?
Claude Code adalah asisten AI yang bisa menulis code untuk Anda di terminal.
Bayangkan seperti seorang programmer yang Anda kasih instruksi, lalu dia tulis codenya.

### Apa Itu Migration?
Migration = "Cetak biru" database. Seperti arsitek yang gambar denah rumah sebelum membangun.
Dengan migration, kita kasih tahu ke sistem: "Buat tabel ini, dengan kolom-kolom ini".

### Apa Itu Model?
Model = "Perantara" antara Laravel dan database.
Ketika code mau ambil atau simpan data pasien, dia pakai Model Patient.

### Apa Itu Seeder?
Seeder = "Data awal" yang diisi otomatis.
Contoh: Data role (Dokter, Perawat, Kasir) langsung terisi tanpa harus input manual.

### Apa Itu Controller?
Controller = "Otak" yang mengatur alur kerja.
Ketika ada request dari UI (klik tombol daftar pasien), Controller yang memproses.

---

## 📁 STRUKTUR PROJECT

```
/arumed-apps/
├── docs/
│   ├── skills/
│   │   ├── skillarumeddb.md        ← Blueprint database (WAJIB upload ke Claude Code)
│   │   └── skillarumedctx.md       ← Context project (WAJIB upload ke Claude Code)
│   ├── CLAUDE_CODE_GUIDE.md        ← File ini
│   ├── PROGRESS.md                 ← Progress tracker
│   └── DATABASE_SCHEMA_REVISED.md  ← Referensi schema lengkap
├── backend/                        ← Laravel (kode server)
└── frontend/                       ← Vue 3 (kode tampilan)
```

---

## ✅ CHECKLIST SEBELUM MULAI

```
[ ] PostgreSQL sudah terinstall & bisa dibuka
[ ] Database "arumed_apps" sudah dibuat di PostgreSQL
[ ] Laravel project sudah ada di folder /backend
[ ] File .env sudah diisi: DB_CONNECTION=pgsql, DB_DATABASE=arumed_apps
[ ] php artisan migrate berhasil dijalankan (tidak ada error)
[ ] File skillarumeddb.md ADA di /docs/skills/ (versi terbaru, sudah include follow-up)
[ ] File skillarumedctx.md ADA di /docs/skills/ (versi terbaru, sudah include follow-up)
[ ] npm install sudah dijalankan di folder /frontend
```

> **⚠️ PENTING:** Pastikan skillarumeddb.md dan skillarumedctx.md sudah diupdate
> dengan fitur Follow-up (Kontrol Ulang) sebelum mulai migration apapun.

---

## 📋 7 ATURAN WAJIB CLAUDE CODE

### Rule 1 — Upload Skill Setiap Sesi Baru
```
Setiap buka Claude Code (sesi baru):
→ Upload kedua skill files: skillarumeddb.md + skillarumedctx.md
→ Tulis di prompt: "Baca skillarumeddb + skillarumedctx"
→ Baru minta task

Kenapa? Claude Code tidak ingat percakapan sebelumnya.
Seperti karyawan baru yang perlu briefing setiap hari.
```

### Rule 2 — 1 Prompt = 1 Task
```
❌ SALAH: "Buatkan semua migrations, models, dan controllers"
✅ BENAR:  "Buatkan migrations untuk Batch 1: roles, employees, users"

Kenapa? Jika terlalu banyak sekaligus:
- Output bisa terpotong dan tidak lengkap
- Error susah dilacak
- Token habis di tengah jalan
```

### Rule 3 — Batch Size yang Tepat
```
Migrations  → 4-5 tabel per prompt
Models      → 3-4 model per prompt
Controllers → 1 controller + 1 service per prompt
Services    → 1 file per prompt (karena besar)
```

### Rule 4 — Verifikasi Setiap Batch
```
Setelah setiap batch selesai, JANGAN lanjut dulu:
1. Copy hasil ke project
2. Jalankan: php artisan migrate
3. Pastikan tidak ada error merah
4. Update PROGRESS.md
5. BARU minta batch berikutnya
```

### Rule 5 — Paste Error, Jangan Fix Sendiri
```
Kalau ada error setelah migrate:
→ Copy error message
→ Paste ke Claude Code
→ Tulis: "Error ini muncul setelah migrate, tolong fix"
→ Jangan coba-coba edit file sendiri
```

### Rule 6 — Sebut Konteks di Setiap Prompt
```
Claude Code tidak ingat progress sebelumnya.
Selalu sebut:
"Konteks: Batch 1-3 sudah selesai, sekarang lanjut Batch 4"

Contoh lengkap:
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1 (foundation) sudah selesai
Task: Generate migration untuk Batch 2 (master data)
```

### Rule 7 — Simpan Progress
```
Setelah setiap batch, update file /docs/PROGRESS.md
Tandai [ ] → [x] untuk yang sudah selesai
Tambah catatan jika ada yang perlu diingat
```

---

## 📊 PROGRESS TRACKER

Salin ini ke file `/docs/PROGRESS.md`:

```markdown
# Arumed Apps — Build Progress

## Terakhir Update: [isi tanggal]
## Posisi Sekarang: [isi sedang di mana]

---

## Phase 1: Database Migrations (Target: 65 tabel + 3 kolom follow-up)

### Core Migrations:
- [ ] Batch 1:  clinic_profiles, roles, employees, users
- [ ] Batch 2:  insurers, procedures, icd10, icd9, bhp_items, iol_items, medications, surgery_packages
- [ ] Batch 3:  patients, visits (+ 3 kolom follow-up), queues, visit_cob
- [ ] Batch 4:  procedure_tariffs, medication_tariffs, bhp_tariffs, iol_tariffs
- [ ] Batch 5a: surgery_schedules, nurse_assessments, refraction_records, refraction_prescriptions, iol_recommendations
- [ ] Batch 5b: doctor_examinations, visit_services, medical_resumes
- [ ] Batch 6:  surgery_records, surgery_requests, surgery_request_bhp, surgery_request_iol, surgery_iol_usage
- [ ] Batch 7:  diagnostic_orders, diagnostic_results, prescriptions, prescription_items
- [ ] Batch 8:  billing_invoices, billing_items, bpjs_claims, claim_audit_logs
- [ ] Batch 9:  tariffs, system_logs
- [ ] Batch 10a: document_number_configs, document_types, document_templates, station_document_mappings, patient_documents
- [ ] Batch 10b: document_verifications, notifications, medical_records, medical_records_versions
- [ ] Batch 11a: integration_configs, bpjs_referrals_in, bpjs_referrals_out, bpjs_control_letters
- [ ] Batch 11b: bpjs_vclaim_logs, bpjs_antrean_logs, bpjs_icare_logs, inacbgs_grouping_logs, satusehat_sync_logs, satusehat_resource_logs

## Phase 2: Models
- [ ] Batch 1: Role, Employee, User, ClinicProfile
- [ ] Batch 2: Insurer, Procedure, Icd10Code, Icd9Code, BhpItem, IolItem, Medication, SurgeryPackage
- [ ] Batch 3: Patient, Visit (+ follow-up), Queue, VisitCob
- [ ] Batch 4: NurseAssessment, RefractionRecord, RefractionPrescription, IolRecommendation, DoctorExamination, VisitService, MedicalResume
- [ ] Batch 5: SurgerySchedule, SurgeryRecord, SurgeryRequest, SurgeryRequestBhp, SurgeryRequestIol, SurgeryIolUsage
- [ ] Batch 6: Prescription, PrescriptionItem, BillingInvoice, BillingItem, BpjsClaim, ClaimAuditLog
- [ ] Batch 7: DocumentType, DocumentTemplate, PatientDocument, DocumentVerification, Notification, BpjsReferralIn, BpjsReferralOut, BpjsControlLetter, IntegrationConfig, SatusehatSyncLog

## Phase 3: Seeders
- [ ] RoleSeeder
- [ ] EmployeeSeeder + UserSeeder
- [ ] ClinicProfileSeeder
- [ ] IntegrationConfigSeeder
- [ ] ICD10Seeder + ICD9Seeder
- [ ] DocumentTypeSeeder
- [ ] StationMappingSeeder

## Phase 4: Backend API
- [ ] Routes setup (api.php)
- [ ] AuthController + AuthService
- [ ] AdmisiController + AdmisiService
- [ ] PerawatController + PerawatService
- [ ] RefraksiController + RefraksiService
- [ ] DokterController + DokterService (INCLUDE: follow-up logic)
- [ ] PenunjangController + PenunjangService
- [ ] BedahController + BedahService
- [ ] FarmasiController + FarmasiService
- [ ] KasirController + KasirService
- [ ] KlaimController + KlaimService
- [ ] RekamMedisController + RekamMedisService
- [ ] DashboardController (INCLUDE: follow-up widgets)
- [ ] MasterDataController + MasterDataService
- [ ] IntegrasiController

## Phase 5: Frontend
- [ ] api.js + stores setup
- [ ] Revisi 15 views existing
- [ ] DokterView.vue (INCLUDE: follow-up input di Tab 4)
- [ ] DashboardView.vue (INCLUDE: follow-up widgets)
- [ ] Views baru: MasterDataView, InboxTTD, IntegrasiView

## Phase 6: Testing
- [ ] Test per modul (Postman)
- [ ] Test end-to-end flow pasien
- [ ] Test follow-up flow (pasien dengan kontrol ulang)
- [ ] Test BPJS flow (mock)
```

---

## 🗄️ PHASE 1: DATABASE MIGRATIONS

> **Apa yang kita lakukan di fase ini:**
> Membuat semua "laci" (tabel) di database. Ibarat membangun lemari arsip
> lengkap dengan semua lacinya, sebelum mulai mengisi dokumen.

### Template Prompt Standar (Pakai Setiap Batch):
```
Upload: skillarumeddb.md + skillarumedctx.md

Baca kedua skill files.

Generate Laravel migrations untuk: [LIST TABEL DI SINI]

Rules:
- UUID primary: $table->uuid('id')->primary()
- Timestamps: $table->timestamps()
- SoftDelete: $table->softDeletes() jika ada deleted_at di skill
- Foreign keys: sesuai skill, dengan constrained()
- Indexes: sesuai skill
- Enum/status values: sesuai skill
- File timestamp: gunakan format 2026_05_xx_HHMMSS

Output: file migration siap pakai, lengkap tidak terpotong
```

---

### BATCH 1 — Foundation (Pondasi)
> **Penjelasan:** Ini tabel paling dasar. Seperti pondasi rumah, harus dibuat paling pertama.
> Berisi data klinik, role pengguna, data karyawan, dan akun login.

**Prompt:**
```
Upload: skillarumeddb.md + skillarumedctx.md

Baca kedua skill files.

Generate migrations untuk:
1. clinic_profiles  ← data klinik (nama, alamat, kode)
2. roles            ← Dokter, Perawat, Kasir, dll
3. employees        ← data karyawan
4. users            ← akun login (FK ke employees dan roles)

Urutan wajib: clinic_profiles → roles → employees → users
UUID primary key di semua tabel.
File timestamp format: 2026_05_18_HHMMSS
Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
# Yang diharapkan: "4 migrations ran successfully"
```

**Kalau ada error:**
```
→ Copy pesan error merah
→ Paste ke Claude Code
→ Tulis: "Error ini muncul saat php artisan migrate, tolong fix"
```

---

### BATCH 2 — Master Data
> **Penjelasan:** Data "kamus" yang dipakai sistem. Seperti buku referensi:
> daftar jenis tindakan, obat-obatan, ICD-10 (kode diagnosa), dll.
> Tidak ada FK ke pasien, jadi bisa dibuat kapan saja.

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1 (clinic_profiles, roles, employees, users) sudah selesai

Generate migrations untuk:
1. insurers          ← data asuransi / penjamin
2. procedures        ← master tindakan medis
3. icd10_codes       ← kode diagnosa ICD-10
4. icd9_codes        ← kode tindakan ICD-9
5. bhp_items         ← bahan habis pakai (viscoelastic, benang, dll)
6. iol_items         ← master IOL (lensa tanam)
7. medications       ← master obat
8. surgery_packages  ← paket operasi (Phaco, Pterygium, dll)

Catatan: surgery_packages punya field includes (JSONB)
Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 3 — Pasien & Kunjungan ⭐ (KRITIS - Ada Follow-up)
> **Penjelasan:** Ini inti dari sistem. Tabel pasien, kunjungan, dan antrian.
> **PENTING:** Di sini kita tambahkan 3 kolom baru untuk fitur Follow-up (Kontrol Ulang).

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-2 sudah selesai

Generate migrations untuk:
1. patients     ← data pasien (NIK, no_rm, nama, dll)
2. visits       ← data kunjungan (PENTING: ada field parallel tracking + FOLLOW-UP)
3. queues       ← antrian per stasiun
4. visit_cob    ← data COB (dua penjamin)

KRITIS untuk tabel visits:
a) Field parallel tracking triase+refraksi:
   - triase_completed_at TIMESTAMP NULL
   - refraksi_completed_at TIMESTAMP NULL
   - ready_for_doctor BOOLEAN DEFAULT FALSE

b) Field BPJS:
   - no_sep, bpjs_booking_code, bpjs_antrean_number

c) Field Satu Sehat:
   - satusehat_encounter_id, satusehat_sync_status

d) Field FOLLOW-UP (BARU):
   - planning_follow_up BOOLEAN DEFAULT FALSE
     (TRUE jika dokter jadwalkan kontrol ulang)
   - follow_up_date DATE NULL
     (tanggal rencana kontrol, constraint: >= CURRENT_DATE jika tidak NULL)
   - follow_up_reason TEXT NULL
     (alasan kontrol, diisi dokter saat planning)
   - Tambahkan INDEX pada follow_up_date untuk performa query dashboard

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
# Check: tabel visits punya kolom planning_follow_up, follow_up_date, follow_up_reason
```

---

### BATCH 4 — Tarif
> **Penjelasan:** Tabel harga tindakan, obat, IOL, dan BHP.
> Tiap item bisa punya harga berbeda tergantung penjamin (BPJS, Umum, Asuransi).

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-3 sudah selesai

Generate migrations untuk:
1. procedure_tariffs    ← harga tindakan per penjamin
2. medication_tariffs   ← harga obat per penjamin
3. bhp_tariffs          ← harga BHP per penjamin
4. iol_tariffs          ← harga IOL per penjamin

Catatan:
- Semua punya UNIQUE constraint: (item_id + insurer_id + classification)
- insurer_id NULLABLE (NULL = berlaku untuk semua penjamin)
- classification: UMUM/BPJS/ASURANSI/PERUSAHAAN/SOSIAL

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 5a — Klinis (Bagian 1)
> **Penjelasan:** Tabel untuk data pemeriksaan klinis.
> Jadwal operasi, catatan perawat, pemeriksaan refraksi (kacamata).

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-4 sudah selesai

Generate migrations untuk:
1. surgery_schedules          ← jadwal operasi
2. nurse_assessments          ← TTV (tekanan darah, nadi, dll)
3. refraction_records         ← BESAR: banyak field OD/OS untuk pemeriksaan mata
4. refraction_prescriptions   ← resep kacamata
5. iol_recommendations        ← rekomendasi IOL dari biometri

Catatan refraction_records:
- Ada field per mata OD (kanan) dan OS (kiri): autoref, keratometri (K1/K2),
  visus (awal/akhir), pinhole, add_power, refraksi_subjektif, old_glasses
- iop_od, iop_os, iop_method (tekanan bola mata)
- perception_type: DEKAT/JAUH
- pd_distance, clinical_notes (SHARED, bukan per mata)
- digital_signature

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 5b — Klinis (Bagian 2)
> **Penjelasan:** Tabel pemeriksaan dokter (4 tab), layanan yang diberikan, dan resume medis.

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 5a (surgery_schedules, nurse_assessments, refraction_records,
         refraction_prescriptions, iol_recommendations) sudah selesai

Generate migrations untuk:
1. doctor_examinations  ← BESAR: data pemeriksaan dokter 4 tab
2. visit_services       ← tindakan/layanan per kunjungan
3. medical_resumes      ← resume medis (S/O/A/P)

Catatan doctor_examinations:
- Tab 2: anamnese, segmen anterior OD/OS (kornea, coa, iris, pupil, lensa),
         segmen posterior OD/OS (papil, macula, retina, vitreous), slitlamp_notes
- Tab 4: soap_subjective/objective/assessment/plan, diagnosis_utama (ICD-10),
         diagnosis_sekunder (JSONB), tindakan_codes (JSONB)
- Planning: PULANG_BEROBAT_JALAN / BEDAH / RUJUK / PULANG_SEHAT
  (CATATAN: Follow-up bukan planning terpisah, tapi optional field di tabel visits)
- FK ke surgery_packages dan surgery_schedules (nullable)

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 6 — Bedah
> **Penjelasan:** Tabel untuk proses operasi: laporan operasi, permintaan bahan,
> dan penggunaan IOL (lensa tanam).

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-5 sudah selesai

Generate migrations untuk:
1. surgery_records       ← laporan operasi (time in/out, komplikasi)
2. surgery_requests      ← permintaan BHP+IOL ke farmasi
3. surgery_request_bhp   ← detail BHP yang diminta
4. surgery_request_iol   ← detail IOL yang diminta
5. surgery_iol_usage     ← IOL yang benar-benar dipakai (brand, power, lot, serial)

Catatan:
- surgery_request_iol: FK ke iol_items (nullable, diisi farmasi)
- surgery_iol_usage: FK ke iol_items dan surgery_records
- Status surgery_requests: REQUESTED/SENT/RECEIVED

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 7 — Penunjang & Farmasi
> **Penjelasan:** Tabel untuk pemeriksaan penunjang (OCT, USG, biometri)
> dan resep obat.

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-6 sudah selesai

Generate migrations untuk:
1. diagnostic_orders    ← order pemeriksaan (OCT, USG, Biometri)
2. diagnostic_results   ← hasil pemeriksaan (expertise_data JSONB)
3. prescriptions        ← header resep obat
4. prescription_items   ← detail item resep (obat, qty, dosis)

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 8 — Billing & Klaim
> **Penjelasan:** Tabel tagihan dan klaim BPJS.

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-7 sudah selesai

Generate migrations untuk:
1. billing_invoices  ← invoice (ada: penjamin1/2/patient_amount untuk COB)
2. billing_items     ← detail item tagihan
3. bpjs_claims       ← BESAR: data klaim BPJS (SEP, INA-CBGs, LUPIS, status)
4. claim_audit_logs  ← audit trail klaim

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 9 — Sistem
> **Penjelasan:** Tabel pendukung sistem.

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-8 sudah selesai

Generate migrations untuk:
1. tariffs      ← tabel tarif legacy
2. system_logs  ← log aktivitas sistem

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 10a — Rekam Medis (Bagian 1)
> **Penjelasan:** Tabel dokumen rekam medis (RME) dan konfigurasinya.

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-9 sudah selesai

Generate migrations untuk:
1. document_number_configs      ← konfigurasi nomor dokumen
2. document_types               ← jenis dokumen (parent_id self-reference)
3. document_templates           ← template dokumen
4. station_document_mappings    ← dokumen per stasiun
5. patient_documents            ← KRITIS: ada signatures JSONB, pending_signature_roles

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 10b — Rekam Medis (Bagian 2)
**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 10a sudah selesai

Generate migrations untuk:
1. document_verifications    ← verifikasi QR dokumen
2. notifications             ← notifikasi inbox TTD
3. medical_records           ← form RME generic
4. medical_records_versions  ← versi/audit trail RME

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 11a — Integrasi (Bagian 1)
> **Penjelasan:** Tabel untuk koneksi ke sistem luar: BPJS, Satu Sehat.

**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 1-10 sudah selesai

Generate migrations untuk:
1. integration_configs      ← konfigurasi API (BPJS, Satu Sehat)
2. bpjs_referrals_in        ← surat rujukan masuk
3. bpjs_referrals_out       ← surat rujukan keluar
4. bpjs_control_letters     ← surat kontrol BPJS

Catatan bpjs_control_letters:
- Link ke visits.follow_up_date untuk tanggal_rencana_kontrol
- Auto-create saat pasien BPJS mendapat planning follow-up

Output lengkap tidak terpotong.
```

**Verifikasi:**
```bash
php artisan migrate
```

---

### BATCH 11b — Integrasi (Bagian 2)
**Prompt:**
```
Skill: skillarumeddb + skillarumedctx
Konteks: Batch 11a sudah selesai

Generate migrations untuk:
1. bpjs_vclaim_logs
2. bpjs_antrean_logs
3. bpjs_icare_logs
4. inacbgs_grouping_logs
5. satusehat_sync_logs
6. satusehat_resource_logs

Output lengkap tidak terpotong.
```

**Verifikasi FINAL Phase 1:**
```bash
php artisan migrate
php artisan migrate:status
# Yang diharapkan: 65+ migrations, semua status "Ran"
```

---

## 🏗️ PHASE 2: MODELS

> **Apa yang kita lakukan di fase ini:**
> Membuat "perantara" antara code dan database.
> Ibarat membuat kartu identitas untuk setiap tabel agar bisa dikenali oleh Laravel.

### Template Prompt Standar Models:
```
Skill: skillarumeddb + skillarumedctx
Konteks: Semua migrations sudah selesai (65+ migrations ran)

Generate Laravel Model untuk: [NAMA MODEL]

Rules:
- Namespace: App\Models
- use SoftDeletes jika ada deleted_at
- $fillable: semua kolom kecuali id, timestamps, deleted_at
- $casts: boolean, json/array, decimal sesuai tipe kolom
- Relationships sesuai skill (hasMany, belongsTo, hasOne)
- Scopes: scopeToday(), scopeActive() jika relevan

Output lengkap tidak terpotong.
```

### BATCH Models 1 — Foundation
```
Generate Models: Role, Employee, User, ClinicProfile
```

### BATCH Models 2 — Master Data
```
Generate Models: Insurer, Procedure, Icd10Code, Icd9Code,
BhpItem, IolItem, Medication, SurgeryPackage
```

### BATCH Models 3 — Kunjungan ⭐ (INCLUDE Follow-up)
```
Skill: skillarumeddb + skillarumedctx
Konteks: Models Batch 1-2 sudah selesai

Generate Models:
1. Patient  (hasMany visits, hasMany patientDocuments)
2. Visit    (belongsTo patient + FOLLOW-UP fields + scopeFollowUpToday() + scopeFollowUpThisWeek())
3. Queue    (belongsTo visit, belongsTo employee)
4. VisitCob (belongsTo visit)

PENTING untuk Model Visit:
- $casts include: 'planning_follow_up' => 'boolean'
- $fillable include: planning_follow_up, follow_up_date, follow_up_reason
- Tambah scope: scopeHasFollowUp() → WHERE planning_follow_up = TRUE
- Tambah scope: scopeFollowUpToday() → WHERE follow_up_date = TODAY AND planning_follow_up = TRUE
- Tambah scope: scopeFollowUpThisWeek() → WHERE follow_up_date BETWEEN TODAY AND TODAY+7

Output lengkap tidak terpotong.
```

### BATCH Models 4 — Klinis
```
Generate Models:
NurseAssessment, RefractionRecord, RefractionPrescription,
IolRecommendation, DoctorExamination, VisitService, MedicalResume
```

### BATCH Models 5 — Bedah
```
Generate Models:
SurgerySchedule, SurgeryRecord, SurgeryRequest,
SurgeryRequestBhp, SurgeryRequestIol, SurgeryIolUsage
```

### BATCH Models 6 — Farmasi & Billing
```
Generate Models:
Prescription, PrescriptionItem, BillingInvoice, BillingItem,
BpjsClaim, ClaimAuditLog
```

### BATCH Models 7 — RME & Integrasi
```
Generate Models:
DocumentType, DocumentTemplate, PatientDocument, DocumentVerification,
Notification, BpjsReferralIn, BpjsReferralOut, BpjsControlLetter,
IntegrationConfig, SatusehatSyncLog
```

---

## 🌱 PHASE 3: SEEDERS

> **Apa yang kita lakukan di fase ini:**
> Mengisi data awal. Seperti menyiapkan inventaris sebelum toko dibuka:
> isi role, user demo, ICD-10, dan konfigurasi sistem.

### Prompt Seeder (Jalankan Per Batch):

**Batch Seeder 1:**
```
Skill: skillarumeddb + skillarumedctx

Generate Seeders:
1. RoleSeeder → Superadmin, Dokter, Perawat, Refraksionis,
                Penunjang, Farmasi, Kasir, Verifikator, Admisi
2. EmployeeSeeder → 1 dokter dummy, 1 perawat, 1 refraksionis, 1 admin
3. UserSeeder → 1 user per role (password: password123)
4. ClinicProfileSeeder → nama: Klinik Mata Arunika, kode: KMA
```

**Batch Seeder 2:**
```
Skill: skillarumeddb + skillarumedctx

Generate Seeders:
5. IntegrationConfigSeeder → 6 record: VCLAIM/ANTREAN/ICARE/LUPIS/INACBGS/SATUSEHAT
                              Semua is_enabled = FALSE
6. ICD10Seeder → ICD-10 mata (H00-H59) yang sering dipakai
7. ICD9Seeder  → ICD-9 CM mata yang sering dipakai
8. DocumentTypeSeeder → 24 jenis dokumen RME (RM-1.1 sampai RM-8.2)
9. StationMappingSeeder → mapping stasiun ke dokumen
```

**Jalankan Seeder:**
```bash
php artisan db:seed
```

---

## 🔧 PHASE 4: BACKEND API

> **Apa yang kita lakukan di fase ini:**
> Membuat "tombol-tombol" yang bisa dipanggil dari aplikasi.
> Ketika dokter klik "Simpan Pemeriksaan", ada endpoint API yang menerima data itu.

### Setup Routes Dulu:
```
Skill: skillarumeddb + skillarumedctx

Generate routes/api.php untuk semua modul:
- Auth, Admisi, Perawat, Refraksi, Dokter, Penunjang
- Bedah, Farmasi, Kasir, Klaim, RekamMedis
- Dashboard (INCLUDE: follow-up endpoints), MasterData, Integrasi

Rules:
- Semua dalam middleware auth:api
- Prefix: /api/v1
- Resource naming: kebab-case
- Response: JSON sesuai API pattern di skill

Output lengkap tidak terpotong.
```

---

### AUTH
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. AuthController (login, logout, refresh, me)
2. AuthService (JWT authentication, log ke system_logs saat login)
```

### ADMISI
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. AdmisiController
   - dashboard() → stat cards + BPJS status
   - index() → list kunjungan hari ini (filter, search, paginate)
   - store() → daftar pasien baru (UMUM/BPJS)
   - show() → detail kunjungan
   - complete() → selesai admisi → buat queue
   - searchPatient() → search by NIK/no_kartu/no_rm/nama

2. AdmisiService
   - getDashboard()
   - registerPatient(data)
   - generateNoRM()
   - generateQueueNumber(station)
   - selesaiAdmisi(visitId, stationTujuan)
```

### PERAWAT
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. PerawatController
   - index() → list pasien di triase (queue T)
   - show() → detail pasien
   - storeAssessment() → simpan TTV + alergi
   - finalizeAssessment() → kunci data perawat
   - getVitalHistory() → riwayat TTV pasien

2. PerawatService
   - getPatientQueue()
   - storeAssessment(visitId, data)
   - finalizeAssessment(assessmentId)
   - checkReadyForDoctor(visitId)
```

### REFRAKSI
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. RefraksiController
   - index() → list pasien di refraksi (queue R)
   - show() → detail pasien
   - storeRefraction() → simpan data refraksi OD/OS
   - storePrescription() → simpan resep kacamata
   - finalizeRefraction() → kunci data refraksi
   - getPreviousRefraction() → riwayat refraksi pasien

2. RefraksiService
   - storeRefractionRecord(visitId, data)
   - storeRefractionPrescription(recordId, data)
   - finalizeRefraction(recordId)
```

### DOKTER ⭐ (INCLUDE Follow-up Logic)
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. DokterController
   - index() → list pasien di antrian dokter (queue D)
   - show() → detail pasien (Tab 1: data perawat+refraksi readonly)
   - storeExamination() → simpan Tab 2 (anamnese + segmen anterior/posterior)
   - storeServices() → simpan Tab 3 (tindakan + resep obat)
   - storePlanning() → KRITIS: simpan Tab 4 (SOAP + ICD + planning)
   - getInboxTTD() → list dokumen menunggu TTD dokter
   - signDocument() → TTD dokumen (input PIN)
   - rejectDocument() → tolak dokumen

2. DokterService
   - getPatientData(visitId)
   - storeExamination(visitId, data)
   - storePlanning(visitId, data) ← INCLUDE FOLLOW-UP LOGIC:
     * Jika data.follow_up_date diisi:
       - Set visits.planning_follow_up = TRUE
       - Set visits.follow_up_date = data.follow_up_date
       - Set visits.follow_up_reason = data.follow_up_reason
       - Auto-populate medical_resume.resume_p dengan info follow-up
       - Jika visit.guarantor_type = 'BPJS':
           Create bpjs_control_letters (status: DRAFT)
           Isi: tanggal_rencana_kontrol = follow_up_date
       - Create medical_records Surat Kontrol (type: FOLLOW_UP_LETTER)
     * Jika data.follow_up_date kosong:
       - planning_follow_up = FALSE (normal pulang berobat jalan)
       - Tidak ada dokumen follow-up yang dibuat
   - generateMedicalResume(visitId)
   - signDocument(documentId, pin, doctorId)
```

### PENUNJANG
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. PenunjangController
   - index() → list order penunjang
   - store() → buat order baru (OCT, USG, Biometri, Topografi)
   - storeResult() → simpan hasil + expertise
   - finalizeResult() → kunci hasil
   - getIolRecommendation() → rekomendasi IOL dari biometri

2. PenunjangService
   - createOrder(visitId, type, data)
   - storeResult(orderId, data)
   - generateIolRecommendation(biometriId)
```

### BEDAH
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. BedahController
   - index() → list jadwal operasi
   - startOperation() → time in
   - completeOperation() → time out + laporan operasi
   - storePostOp() → instruksi post-op
   - getSurgeryRequests() → list permintaan BHP+IOL
   - confirmSuppliesReceived() → konfirmasi terima BHP+IOL

2. BedahService
   - getScheduledSurgeries()
   - createSupplyRequest(visitId, data)
   - recordSurgery(scheduleId, data)
   - recordIolUsage(surgeryRecordId, iolData)
```

### FARMASI
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. FarmasiController
   - getPrescriptions() → list resep pending
   - dispensePrescription() → dispense obat
   - getSurgeryRequests() → list request BHP+IOL dari bedah
   - prepareSurgerySupplies() → siapkan + assign IOL
   - sendToSurgery() → kirim ke bedah
   - getMasterObat() / getMasterBhp() / getMasterIol() → CRUD master

2. FarmasiService
   - dispensePrescription(prescriptionId)
   - updateStock(itemId, qty, type)
   - prepareSurgeryRequest(requestId)
   - assignIolToRequest(requestIolId, iolItemId)
```

### KASIR
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. KasirController
   - index() → list pasien siap bayar
   - calculateBill() → hitung total (dengan tarif + COB)
   - processPayment() → proses pembayaran
   - printReceipt() → generate PDF kwitansi

2. KasirService
   - consolidateBilling(visitId)
   - getPrice(itemType, itemId, classification, insurerId)
   - calculateCOB(totalAmount, cob)
   - processPayment(invoiceId, data)
   - generateReceipt(invoiceId)
```

### DASHBOARD ⭐ (INCLUDE Follow-up Widgets)
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. DashboardController
   - getSummary() → stats hari ini (pasien, operasi, pendapatan, klaim)
   - getVisitChart() → grafik kunjungan (7 hari)
   - getDiagnosisStats() → top 10 diagnosis
   - getRevenueStats() → pendapatan per penjamin
   - getFollowUpToday() → BARU: list pasien kontrol hari ini
   - getFollowUpThisWeek() → BARU: pasien kontrol minggu ini
   - getFollowUpAnalytics() → BARU: statistik follow-up per bulan

2. DashboardService
   - getDailySummary()
   - getWeeklyVisits()
   - getTopDiagnoses()
   - getRevenueSummary()
   - getFollowUpToday() ← Query: visits WHERE follow_up_date = TODAY AND planning_follow_up = TRUE
   - getFollowUpThisWeek() ← Query: visits WHERE follow_up_date BETWEEN TODAY AND TODAY+7
   - getFollowUpAnalytics() ← GROUP BY month, guarantor_type WHERE planning_follow_up = TRUE
```

### KLAIM BPJS
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. KlaimController
   - index() → list kunjungan siap klaim
   - runGrouper() → INA-CBGs grouping
   - verify() → verifikasi data klaim
   - submit() → submit ke VClaim (placeholder)

2. KlaimService
   - prepareClaimData(visitId)
   - runInaCbgsGrouping(claimId)
   - submitClaim(claimId)
```

### REKAM MEDIS
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. RekamMedisController
   - searchPatient() → cari pasien by nama/RM/NIK
   - getPatientHistory() → timeline kunjungan
   - getDocuments() → dokumen per kunjungan
   - printDocument() → generate PDF
   - createDocument() → buat dokumen dari stasiun
   - submitForSignature() → submit ke WAITING_SIGNATURE
   - verifyDocument() → verifikasi QR code

2. RekamMedisService
   - searchPatient(query)
   - getVisitHistory(patientId)
   - generatePdf(documentId)
   - generateDocumentNumber()
   - generateQrCode(documentId)
```

### MASTER DATA
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. MasterDataController → CRUD untuk semua master:
   procedures, tariffs per jenis, insurers,
   icd10, icd9, surgery_packages,
   document_types, document_templates,
   station_mappings, document_number_configs,
   clinic_profiles

Rules:
- Import/Export CSV untuk tarif
- Soft delete semua master
```

### INTEGRASI
```
Skill: skillarumeddb + skillarumedctx

Generate:
1. IntegrasiController
   - getStatus() → status semua integrasi
   - testConnection(system) → test koneksi
   - getSatusehatLogs() / getBpjsLogs() → log
   - retrySatusehat() → retry manual

2. SatuSehatService (placeholder)
3. BpjsVClaimService (placeholder)
4. BpjsAntreanService (placeholder)
5. InaCbgsService (placeholder)
```

---

## 🎨 PHASE 5: FRONTEND

> **Apa yang kita lakukan di fase ini:**
> Menyambungkan tampilan (Vue) ke backend (Laravel API).
> Ibarat memasang kabel listrik dari panel listrik ke setiap colokan di rumah.

### Setup API Client:
```
Skill: skillarumeddb + skillarumedctx

Generate frontend/src/services/api.js:
- Axios instance dengan base URL
- Auth token interceptor (kirim JWT di setiap request)
- Response interceptor (handle 401 = logout, 500 = error message)

Generate frontend/src/stores/:
- authStore.js
- visitStore.js
- queueStore.js
- notificationStore.js (inbox TTD)
- followUpStore.js ← BARU: untuk follow-up dashboard widgets
```

### Revisi Views (2-3 per batch):
```
Skill: skillarumeddb + skillarumedctx

Revisi [NAMA VIEW].vue:
- Tambah field baru sesuai schema
- Connect ke API (ganti mock data)
- Handle loading + error states
- Realtime updates via WebSocket (untuk queue boards)

Jangan ubah: styling, layout utama, warna biru klinik
```

### View Prioritas Follow-up:
```
Skill: skillarumeddb + skillarumedctx

Revisi DokterView.vue → Tab 4 Planning section:
- Ketika doctor pilih planning PULANG_BEROBAT_JALAN:
  Tampilkan optional date picker "Jadwalkan Kontrol Ulang"
- Jika date picker diisi:
  Tampilkan field "Alasan Kontrol" (text input)
  Tampilkan preview: "Pasien dijadwalkan kontrol pada {tanggal}"
- Jika date picker kosong:
  Pasien pulang berobat jalan biasa (tidak ada tambahan UI)

Revisi DashboardView.vue:
- Tambah widget card: "Kontrol Ulang Hari Ini" (GET /api/v1/dashboard/follow-up/today)
- Tambah widget card: "Kontrol Ulang Minggu Ini" (GET /api/v1/dashboard/follow-up/week)
- Tambah tab Analytics: "Statistik Follow-up" (GET /api/v1/dashboard/follow-up/analytics)
```

### Views Baru:
```
1. MasterDataView.vue → Tab: Tindakan, Tarif, IOL, BHP, Penjamin, ICD, RME
                        CRUD + Import/Export CSV

2. InboxTTDView.vue   → List dokumen menunggu TTD
                        Review + Sign (PIN) + Reject

3. IntegrasiView.vue  → Status semua sistem
                        Log Satu Sehat + Retry
```

---

## 🧪 PHASE 6: TESTING

> **Apa yang kita lakukan di fase ini:**
> Mencoba semua fitur untuk memastikan tidak ada yang error sebelum dipakai user.

### Test Backend (Postman):
```
1. Auth: login → dapat token JWT
2. Admisi: register pasien, generate queue
3. Perawat: simpan TTV, finalize
4. Refraksi: simpan refraksi, finalize
5. Check: ready_for_doctor otomatis jadi TRUE
6. Dokter: muncul di list, simpan examination
7. Follow-up: simpan planning dengan follow_up_date
              → cek visits.planning_follow_up = TRUE
              → cek bpjs_control_letters terbuat (jika BPJS)
8. Dashboard: cek endpoint follow-up/today, follow-up/week
```

### Test E2E Flow:
```
Skenario 1: Pasien UMUM Non-Bedah (Tanpa Follow-up)
→ Register → Triase → Refraksi → Dokter (PBJ biasa) → Farmasi → Kasir

Skenario 2: Pasien UMUM Non-Bedah (Dengan Follow-up)
→ Register → Triase → Refraksi → Dokter (PBJ + isi tanggal kontrol)
→ Cek: visits.planning_follow_up = TRUE
→ Farmasi → Kasir
→ Cek: muncul di dashboard "Kontrol Hari Ini" saat tanggal tiba

Skenario 3: Pasien BPJS Bedah
→ Register + SEP → Triase → Refraksi → Dokter (BEDAH)
→ Biometri → IOL Request → Bedah → Farmasi → Kasir → Klaim

Skenario 4: Parallel Station
→ Register → Refraksi dulu → Triase → Auto masuk Dokter
```

---

## ⚡ TIPS HEMAT TOKEN

### DO ✅
```
1. Upload skill di awal sesi, pakai terus
2. Batch 4-5 migrations per prompt
3. Copy-paste error langsung ke Claude
4. Selalu sebut konteks: "Batch X sudah selesai"
5. Tambahkan: "Output lengkap tidak terpotong"
6. 1 Controller + 1 Service per prompt
7. Update PROGRESS.md setiap batch selesai
```

### DON'T ❌
```
1. Jangan minta semua sekaligus
2. Jangan minta "generate semua models"
3. Jangan lanjut tanpa verifikasi batch sebelumnya
4. Jangan edit hasil Claude sebelum test
5. Jangan skip php artisan migrate setelah copy file
```

### Template Prompt Standar (Hemat Token):
```
[WAJIB DI SETIAP PROMPT]
Skill: skillarumeddb + skillarumedctx
Konteks: [sebutkan batch apa yang sudah selesai]
Task: [1 task spesifik saja]
Output: [apa yang diharapkan, lengkap tidak terpotong]
```

---

## 🛠️ PERINTAH LARAVEL BERGUNA

```bash
# Migrations
php artisan migrate                     ← Run semua pending
php artisan migrate:status              ← Lihat status semua migration
php artisan migrate:rollback            ← Undo 1 batch terakhir
php artisan migrate:fresh --seed        ← Reset total + isi seeder
php artisan migrate:rollback --step=1   ← Undo hanya 1 migration

# Generate file kosong (jika butuh)
php artisan make:model NamaModel        ← Buat model kosong
php artisan make:controller NamaCtrl --api
php artisan make:seeder NamaSeeder
php artisan make:migration nama_file

# Testing
php artisan tinker                      ← Console interaktif
php artisan route:list                  ← Lihat semua routes

# Cache (jalankan jika ada bug aneh)
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Background jobs
php artisan queue:work                  ← Untuk PDF generation
php artisan schedule:run                ← Untuk Satu Sehat sync
```

---

## 📅 ESTIMASI WAKTU

| Phase | Deskripsi | Estimasi |
|-------|-----------|----------|
| 1 | 65+ Migrations (14 batch) | 2-3 hari |
| 2 | Models (7 batch) | 1-2 hari |
| 3 | Seeders | 1 hari |
| 4 | Backend API (14 modul) | 1-1.5 minggu |
| 5 | Frontend | 1-1.5 minggu |
| 6 | Testing | 3-5 hari |
| **Total** | | **~4-5 minggu** |

---

## ❓ PANDUAN KALAU ADA ERROR

### Error: "Class not found"
```bash
composer dump-autoload
```

### Error: "Table already exists"
```bash
php artisan migrate:rollback --step=1
# Cek migration file, perbaiki, lalu
php artisan migrate
```

### Error: "Foreign key constraint failed"
```
→ Cek urutan migration (tabel FK harus sudah ada dulu)
→ Paste error ke Claude Code, minta fix urutan
```

### Error setelah copy file dari Claude Code:
```
→ Paste SELURUH pesan error ke Claude Code
→ Sebutkan: "Error ini muncul setelah php artisan migrate"
→ Jangan edit sendiri
```

---

## 📌 RINGKASAN FITUR FOLLOW-UP (KONTROL ULANG)

> Panduan singkat untuk tim yang perlu tahu tentang fitur ini.

**Apa itu?**
Dokter bisa opsional menjadwalkan pasien untuk kembali (follow-up) saat memilih
planning "Pulang Berobat Jalan" di Tab 4.

**Bagaimana cara kerjanya?**
```
Dokter pilih PULANG_BEROBAT_JALAN
  ↓
Isi tanggal kontrol ulang? (OPSIONAL)
  ↓ YA                          ↓ TIDAK
planning_follow_up = TRUE      planning_follow_up = FALSE
follow_up_date = [tanggal]     Selesai, pasien pulang biasa
follow_up_reason = [alasan]
  ↓
Auto-generate:
- Medical resume section P
- Surat Kontrol (jika BPJS)
  ↓
Dashboard admin:
- "Kontrol hari ini"
- "Kontrol minggu ini"
- "Statistik per bulan"
```

**Apa yang TIDAK dilakukan sistem?**
- Tidak auto-generate antrian saat tanggal kontrol tiba
- Pasien datang sendiri, ikut alur normal (Admisi → Triase → Dokter)

**Tabel yang terpengaruh:**
- `visits`: +3 kolom (planning_follow_up, follow_up_date, follow_up_reason)
- `bpjs_control_letters`: auto-create saat pasien BPJS ada follow-up
- `medical_records`: auto-create Surat Kontrol
- `medical_resumes`: auto-populate Plan section
