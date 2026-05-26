---
name: skillarumeddb
description: "Use this skill whenever working on Arumed Apps database. Triggers include: generating migrations, models, controllers, services, or any code that involves database tables for the Arumed Apps clinic management system. Also use when asked about table structure, field names, relationships, enum values, or any database-related questions for this project. Contains all 60+ tables (67 migrations), field details, relationships, migration order, enum values, and Laravel code patterns."
---

# Arumed Apps — Database Schema Skill

## Overview

Database schema untuk **Arumed Apps** — sistem manajemen klinik mata (FKRTL Klinik Utama Mata).

- **Database:** PostgreSQL (prod) / SQLite (dev, default Laravel)
- **Backend:** Laravel 13.8 + PHP 8.3 + `tymon/jwt-auth` 2.3
- **Total Tables:** 60+ tabel (dari 67 file migration)
- **Total Models:** 57 Eloquent model
- **Primary Key:** UUID di semua tabel
- **Soft Delete:** `deleted_at` di semua tabel utama
- **Timestamps:** `created_at`, `updated_at` di semua tabel

---

## Aturan Penting

1. **UUID** sebagai primary key (`gen_random_uuid()`)
2. **Soft delete** — jangan `DELETE`, gunakan `deleted_at = NOW()`
3. **Migration order** harus diikuti (foreign key dependency)
4. **JSONB** untuk data flexible (diagnosis sekunder, tindakan, hasil penunjang)
5. **is_finalized** — jika TRUE, record dikunci, tidak bisa diedit
6. **Semua field OD/OS** di tabel mata (kecuali PD dan clinical notes = shared)

---

## Tabel & Fungsinya

### AUTHORIZATION & PEGAWAI

| Tabel | Fungsi |
|-------|--------|
| `roles` | Role user: Superadmin, Dokter, Perawat, Refraksionis, Farmasi, Kasir, dll |
| `employees` | Data pegawai: nama, profesi, SIP, STR |
| `users` | Login credentials (kolom `username` ditambah via migration 2026_05_19_000001), relasi ke employee & role |

### PASIEN & KUNJUNGAN

| Tabel | Fungsi |
|-------|--------|
| `patients` | Data pasien: NIK, no_rm, nama, gender, DOB, phone, address, province |
| `visits` | Kunjungan: classification (Baru/Pre-Op/Post-Op/Kontrol), station saat ini, no_sep |
| `queues` | Antrian per station. **Prefix: A=Admisi, TR=Triase+Refraksionis (merged), D=Dokter, P=Penunjang, B=Bedah, K=Kasir, F=Farmasi**. TR adalah satu antrean dengan dua worker paralel (PerawatView + RefraksionisView) — bukan dua baris terpisah |

### PEMERIKSAAN KLINIS

| Tabel | Fungsi |
|-------|--------|
| `nurse_assessments` | TTV (TD, Nadi, Suhu, Respirasi, SpO2, KGD), BB/TB/BMI, alergi, keluhan. Kolom `pain_rps` (Riwayat Penyakit Sekarang nyeri) ditambah via migration 2026_05_19_000002 |
| `refraction_records` | Autoref, Keratometri (K1/K2), Visus (awal/akhir), Pinhole, ADD, Refraksi Subjektif, Kacamata Lama, IOP, PD, Clinical Notes, Perception (DEKAT/JAUH) |
| `refraction_prescriptions` | Resep kacamata: Rx OD/OS, glasses type, lens material, coating |
| `doctor_examinations` | Tab2: Anamnese + Segmen Anterior/Posterior OD/OS (dropdown) + Slitlamp notes + Button Order Penunjang. Tab4: SOAP, ICD-10, ICD-9, Planning (PBJ/Bedah), Digital Signature |
| `visit_services` | Tindakan/layanan per kunjungan (relasi ke master tarif) |
| `medical_resumes` | Resume Medis auto-generate: S/O/A/P + hasil penunjang. Bisa diedit, dicetak, dikunci |
| `medical_records` | Form RME generic dengan versioning |
| `medical_records_versions` | Audit trail perubahan RME |

### PENUNJANG

| Tabel | Fungsi |
|-------|--------|
| `diagnostic_orders` | Order penunjang dari dokter: OCT, USG, Biometri, Topografi |
| `diagnostic_results` | Hasil penunjang: expertise_data (JSONB), attachment files, status |

### FARMASI

| Tabel | Fungsi |
|-------|--------|
| `medications` | Master obat: kode, nama, formularium (FORNAS/GENERIK/BRANDED), stok, harga |
| `prescriptions` | Resep obat dari dokter |
| `prescription_items` | Item resep: obat, qty, dosis, aturan pakai |

### BEDAH

| Tabel | Fungsi |
|-------|--------|
| `surgery_packages` | Master paket bedah: Phaco, Pterygium, Trabeculectomy, dll |
| `surgery_schedules` | Jadwal operasi: tanggal, jam, ruang OK, tim bedah |
| `surgery_records` | Record operasi: Time In/Out, catatan, komplikasi, post-op, laporan |

### BILLING & KLAIM

| Tabel | Fungsi |
|-------|--------|
| `billing_invoices` | Invoice: subtotal, diskon, pajak, total, metode bayar |
| `billing_items` | Detail item invoice: registrasi, tindakan, obat |
| `bpjs_claims` | Klaim BPJS: SEP, INA-CBGs, LUPIS, status verifikasi, tracking |
| `claim_audit_logs` | Audit trail klaim BPJS |

### MASTER DATA FARMASI

| Tabel | Fungsi |
|-------|--------|
| `medications` | Master obat: kode, nama, formularium (FORNAS/GENERIK/BRANDED), stok, harga |
| `bhp_items` | Master BHP (Bahan Habis Pakai): Viscoelastic, BSS, Benang, dll |
| `iol_items` | Master IOL: brand, model, type, power, lot/serial. Bisa input manual atau scan GS1 DataMatrix |

### MASTER TINDAKAN & TARIF

| Tabel | Fungsi |
|-------|--------|
| `procedures` | Master tindakan lokal: nama, kategori, link ke ICD-9 CM. Terpisah dari ICD |
| `insurers` | Master penjamin: Asuransi, Perusahaan, Sosial (UMUM & BPJS built-in) |
| `procedure_tariffs` | Tarif tindakan per penjamin. Fallback ke UMUM jika tidak ada tarif khusus |
| `medication_tariffs` | Tarif obat per penjamin |
| `bhp_tariffs` | Tarif BHP per penjamin |
| `iol_tariffs` | Tarif IOL per penjamin |

### MASTER ICD

| Tabel | Fungsi |
|-------|--------|
| `icd10_codes` | Master ICD-10 (input manual yang relevan). Flag: is_eye_related, is_favorite |
| `icd9_codes` | Master ICD-9 CM (input manual yang relevan). Flag: is_eye_related, is_favorite |

### COB & BILLING

| Tabel | Fungsi |
|-------|--------|
| `visit_cob` | COB setup per kunjungan. Set di admisi, bisa diubah di kasir. Penjamin 1 bayar dulu, penjamin 2 cover selisih |

### BEDAH & IOL

| Tabel | Fungsi |
|-------|--------|
| `iol_recommendations` | Rekomendasi IOL dari penunjang (biometry) → dikirim ke dokter |
| `surgery_requests` | Request BHP + IOL dari bedah ke farmasi. Status: REQUESTED/SENT/RECEIVED |
| `surgery_request_bhp` | Detail BHP dalam request bedah (auto dari paket, bisa tambah manual) |
| `surgery_request_iol` | Detail IOL dalam request bedah. Farmasi assign iol_item_id setelah disiapkan |
| `surgery_iol_usage` | Pemakaian IOL saat operasi. Update is_used di iol_items |

### KONFIGURASI SISTEM

| Tabel | Fungsi |
|-------|--------|
| `clinic_profiles` | Identitas klinik: nama, kode (KMA), logo, TTD direktur, cap, konfigurasi nomor RM, PDF engine (Puppeteer), watermark global |
| `integration_configs` | Credentials & konfigurasi semua sistem eksternal (VCLAIM/ANTREAN/ICARE/LUPIS/INACBGS/SATUSEHAT). Placeholder siap — isi saat credentials tersedia. Track last_test_status |
| `doctor_schedules` | Jadwal praktik dokter per-hari (ditambah via migration 2026_05_19_000003). Dipakai oleh AdmisiController endpoint `/admisi/jadwal-dokter` CRUD |
| `tariffs` | Generic tariff (dari migration `2026_05_09_000001_create_tariffs_table`) — alternatif lama, tarif aktual dipakai dari `procedure_tariffs` / `medication_tariffs` / `bhp_tariffs` / `iol_tariffs` |
| `system_logs` | Log sistem generic (audit, error, action) |

### INTEGRASI BPJS

| Tabel | Fungsi |
|-------|--------|
| `bpjs_referrals_in` | Rujukan MASUK (GET VClaim). Validasi nomor rujukan dari FKTP. Track sisa kunjungan dari VClaim. Notif jika expired |
| `bpjs_referrals_out` | Rujukan KELUAR (POST VClaim). Dokter rujuk ke RS lain. Dapat nomor rujukan dari VClaim |
| `bpjs_control_letters` | Surat Kontrol. Dokter terbitkan untuk pasien kontrol. POST ke VClaim → dapat nomor SC. Notif jika expired |
| `bpjs_vclaim_logs` | Log semua request VClaim: GENERATE_SEP/CANCEL_SEP/SUBMIT_CLAIM/CHECK_STATUS. Simpan request + response + http_status |
| `bpjs_antrean_logs` | Log validasi kode booking JKN Mobile: VALIDATE_BOOKING/CHECK_QUOTA/CONFIRM |
| `bpjs_icare_logs` | Log monitoring klaim & utilisasi via iCare |
| `inacbgs_grouping_logs` | Log INA-CBGs grouper: input (diagnosis+tindakan) → output (CBG code+tarif+severity). Track versi grouper (update tiap tahun) |

### INTEGRASI SATU SEHAT

| Tabel | Fungsi |
|-------|--------|
| `satusehat_sync_logs` | Log batch sync harian 23:59. Summary total sent/failed per resource. Auto retry 01:00 jika FAILED. Bisa retry manual. Status: SUCCESS/PARTIAL/FAILED/RUNNING |
| `satusehat_resource_logs` | Log detail per visit per resource FHIR R4 (Encounter/Condition/MedicationRequest/MedicationDispense/ImagingStudy). Simpan FHIR payload + response Satu Sehat |

### MASTER REKAM MEDIS (RME)

| Tabel | Fungsi |
|-------|--------|
| `document_number_configs` | Format nomor dokumen customizable per jenis. Token: {PREFIX}/{CODE}/{CLINIC}/{YYYY}/{MM}/{SEQ}. Default RME: `RME/1.1/KMA/0000001`, Invoice: `INV-KMA/2026/05/001` |
| `document_types` | Master jenis dokumen (RM-1.1 dst). Punya `parent_id` untuk sub-type. Admin bisa tambah sendiri |
| `document_templates` | Template field & layout HTML (header/body/footer) untuk generate PDF via Puppeteer |
| `station_document_mappings` | Admin setting: stasiun mana dapat akses dokumen apa |
| `patient_documents` | Dokumen aktual milik pasien. Status: DRAFT→WAITING_SIGNATURE→FINAL/REJECTED. Track TTD, print count |
| `document_verifications` | QR Code verifikasi per dokumen. Token unik → URL verifikasi. Track scan count. is_valid=FALSE jika void |
| `notifications` | Inbox dokter: notifikasi dokumen menunggu TTD. Bisa di-resend. Track is_read, read_at |

---

## Key Relationships

```
patients (1)
  └── (many) visits
      ├── (many) queues
      ├── (1) nurse_assessments
      ├── (1) refraction_records
      │   └── (1) refraction_prescriptions
      ├── (1) doctor_examinations
      │   ├── → surgery_packages
      │   └── → surgery_schedules
      ├── (many) visit_services → tariffs
      ├── (1) medical_resumes
      ├── (many) medical_records
      ├── (many) diagnostic_orders
      │   └── (many) diagnostic_results
      ├── (many) prescriptions
      │   └── (many) prescription_items → medications
      ├── (1) billing_invoices
      │   └── (many) billing_items
      └── (0..1) bpjs_claims
          └── (many) claim_audit_logs

surgery_packages (1)
  └── (many) surgery_schedules
      └── (many) surgery_records
```

---

## Field Kritis Per Tabel

### patients
```sql
no_rm VARCHAR(50) UNIQUE          -- Format: RM-26-0001
nik VARCHAR(16) UNIQUE            -- Primary untuk BPJS lookup
province VARCHAR(100)             -- Provinsi pasien
```

### visits
```sql
no_antreen VARCHAR(20)            -- Format: A-001 (admisi)
no_sep VARCHAR(50) UNIQUE         -- SEP dari BPJS VClaim
classification VARCHAR(50)        -- Baru / Pre-Op / Post-Op / Kontrol
current_station VARCHAR(50)       -- ADMISI/TR/DOKTER/PENUNJANG/BEDAH/KASIR/FARMASI/SELESAI (urutan final: A → TR → D → K → F)
guarantor_type VARCHAR(20)        -- UMUM/BPJS/ASURANSI/PERUSAHAAN/SOSIAL
insurer_id UUID                   -- FK ke insurers (NULL jika UMUM/BPJS)

-- PARALLEL STATION TRACKING
triase_completed_at TIMESTAMP     -- Set otomatis saat nurse_assessments.is_finalized=TRUE
refraksi_completed_at TIMESTAMP   -- Set otomatis saat refraction_records.is_finalized=TRUE
ready_for_doctor BOOLEAN          -- TRUE jika keduanya selesai → auto queue ke DOKTER

-- BPJS
bpjs_booking_code VARCHAR(50)     -- Kode booking dari JKN Mobile
bpjs_antrean_number VARCHAR(20)   -- Nomor antrean BPJS
bpjs_referral_in_id UUID          -- FK ke bpjs_referrals_in
bpjs_control_letter_id UUID       -- FK ke bpjs_control_letters

-- SATU SEHAT
satusehat_encounter_id VARCHAR(100)
satusehat_sync_status VARCHAR(20) -- PENDING/SYNCED/FAILED/SKIPPED
satusehat_synced_at TIMESTAMP
```

### queues
```sql
queue_prefix VARCHAR(2)           -- A / TR / D / P / B / K / F  (TR butuh 2 char)
queue_sequence INT                -- Reset per station per hari
queue_number VARCHAR(20)          -- Gabungan: prefix + sequence (A-001, TR-005, D-003)
status VARCHAR(50)                -- WAITING/CALLED/IN_PROGRESS/COMPLETED/CANCELLED
station VARCHAR(20)               -- ADMISI/TR/DOKTER/PENUNJANG/BEDAH/KASIR/FARMASI

-- IMPORTANT: Antrean TR adalah SATU baris saja per visit.
-- PerawatView dan RefraksionisView sama-sama tarik dari station='TR'
-- berdasarkan visit_id. Transisi ke DOKTER baru terjadi setelah
-- nurse_assessments.is_finalized=TRUE DAN refraction_records.is_finalized=TRUE
-- untuk visit yang sama.
```

### nurse_assessments
```sql
td_sistol INT                     -- mmHg
td_diastol INT                    -- mmHg
nadi INT                          -- x/menit
suhu DECIMAL(4,1)                 -- °C
respirasi INT                     -- x/menit
spo2 DECIMAL(5,2)                 -- %
kgd DECIMAL(6,2)                  -- mg/dL (Kadar Gula Darah)
bmi DECIMAL(5,2)                  -- Auto-hitung dari BB/TB
has_allergy BOOLEAN               -- Trigger input allergy_detail
```

### refraction_records
```sql
perception_type VARCHAR(20)       -- DEKAT / JAUH
examination_date TIMESTAMP        -- Tanggal pemeriksaan

-- Setiap group ada OD & OS version:
autoref_{od/os}_{sph/cyl/axis}    -- Refraksi objektif
keratometri{1/2}_{od/os}          -- K1, K2 (Dioptri)
keratometri_axis_{od/os}          -- Axis
visus_{awal/akhir}_{od/os}        -- Tajam penglihatan
pinhole_{od/os}                   -- Test pinhole
add_power_{od/os}                 -- ADD presbyopia
refraksi_subjektif_{od/os}_{sph/cyl/axis} -- Phoropter
old_glasses_{od/os}_{sph/cyl/axis}         -- Kacamata lama (S C X)
old_glasses_add_{od/os}           -- ADD kacamata lama
iop_{od/os} DECIMAL(5,2)          -- IOP mmHg
iop_method VARCHAR(50)            -- NCT / Goldmann / Schiotz

-- SHARED (bukan OD/OS):
pd_distance DECIMAL(5,2)          -- Pupil Distance mm
clinical_notes TEXT               -- Catatan klinis

-- SIGNATURE:
digital_signature VARCHAR(500)    -- Hash e-sign dokter
signature_timestamp TIMESTAMP
```

### doctor_examinations
```sql
-- TAB 2:
anamnese TEXT                     -- Sebelum pemeriksaan mata

-- Segmen Anterior OD/OS (dropdown: Normal/Tidak Normal/Tidak Dapat Dinilai):
sa_{kornea/coa/iris/pupil/lensa}_{od/os} VARCHAR(50)

-- Segmen Posterior OD/OS (dropdown: Normal/Tidak Normal/Tidak Dapat Dinilai):
sp_{papil/macula/retina/vitreous}_{od/os} VARCHAR(50)

slitlamp_notes TEXT               -- Catatan slitlamp

-- TAB 4:
soap_{subjective/objective/assessment/plan} TEXT
diagnosis_utama VARCHAR(10)       -- ICD-10 utama
diagnosis_sekunder JSONB          -- Array ICD-10 sekunder
tindakan_codes JSONB              -- Array ICD-9 CM
planning VARCHAR(50)              -- PULANG_BEROBAT_JALAN / BEDAH
surgery_package_id UUID           -- Jika BEDAH → pilih paket
surgery_schedule_id UUID          -- Jika BEDAH → jadwal
medical_resume_id UUID            -- Link ke resume medis
```

### medical_resumes
```sql
resume_s TEXT                     -- S: dari anamnese
resume_o TEXT                     -- O: dari refraksionis (auto-populate)
resume_a TEXT                     -- A: dari ICD-10
resume_p TEXT                     -- P: dari ICD-9 + planning
penunjang_results JSONB           -- [{test_type, result, date}] dari diagnostic_results
is_editable BOOLEAN DEFAULT TRUE  -- Bisa diedit sampai finalized
is_finalized BOOLEAN DEFAULT FALSE -- Jika TRUE → dikunci
printed_at TIMESTAMP              -- Kapan terakhir dicetak
```

### bpjs_claims
```sql
no_sep VARCHAR(50) UNIQUE         -- Nomor SEP dari VClaim
patient_nik VARCHAR(16)           -- Lookup via NIK (bukan no_kartu terpisah)
diagnosis_utama VARCHAR(10)       -- ICD-10
diagnosis_sekunder JSONB          -- Array ICD-10
procedure_codes JSONB             -- Array ICD-9 CM
inacbgs_kode VARCHAR(20)          -- Hasil mapping grouper
inacbgs_tarif DECIMAL(12,2)       -- Tarif klaim
lupis_data JSONB                  -- Format LUPIS lengkap
status VARCHAR(50)                -- DRAFT/REVIEW/VERIFIED/SUBMITTED/SELESAI/DITOLAK
bpjs_response JSONB               -- Raw response dari API BPJS
```

### surgery_records
```sql
time_in TIMESTAMP                 -- Mulai operasi
time_out TIMESTAMP                -- Selesai operasi
operation_notes TEXT              -- Laporan operasi
has_complication BOOLEAN          -- Ada komplikasi?
complication_detail TEXT
post_op_instructions TEXT         -- Instruksi post-op
followup_date DATE                -- Jadwal kontrol
-- TIDAK ADA informed_consent (dihapus)
```

### medications
```sql
formularium VARCHAR(100)          -- FORNAS / FORMULARIUM GENERIK / BRANDED
code VARCHAR(50) UNIQUE
stock INT
min_stock INT                     -- Alert stok minimum
expiry_date DATE
batch_number VARCHAR(100)
```

### patient_documents
```sql
patient_id UUID                   -- Dokumen MILIK PASIEN (bukan visit)
visit_id UUID (nullable)          -- NULL = ONCE_LIFETIME (General Consent)
status VARCHAR(50)                -- DRAFT/WAITING_SIGNATURE/FINAL/REJECTED/VOID
created_by_station VARCHAR(50)    -- Stasiun yang buat dokumen
pending_signature_roles JSONB     -- ["DOCTOR"] = dokter belum TTD
signatures JSONB                  -- [{role, name, sign_type, signed_at, status}]
reject_reason TEXT                -- Alasan reject dari dokter
document_number VARCHAR(100)      -- RME/2026/05/001 (generate saat FINAL)
printed_count INT                 -- Track berapa kali dicetak
void_reason TEXT                  -- Alasan void (admin only)
```

### document_types
```sql
code VARCHAR(20)                  -- RM-1.1, RM-2.1, dll
fill_frequency VARCHAR(20)        -- ONCE_LIFETIME/PER_VISIT/PER_EPISODE
parent_id UUID                    -- Sub-type: Laporan Katarak child dari Laporan Operasi
required_signatures JSONB         -- [{role, sign_type, is_required}]
show_in_rme BOOLEAN               -- Tampil di menu rekam medis?
sort_order INT                    -- Urutan tampil di rekam medis
```

### notifications
```sql
recipient_id UUID                 -- Dokter/staff yang terima notif
type VARCHAR(50)                  -- SIGNATURE_REQUEST/REJECTED/FINAL
patient_document_id UUID          -- Dokumen yang dimaksud
is_read BOOLEAN                   -- Sudah dibaca?
resend_count INT                  -- Berapa kali di-resend
```

### station_document_mappings
```sql
station VARCHAR(50)               -- Stasiun: ADMISI/TRIASE/dll
document_type_id UUID             -- Dokumen yang available
is_available BOOLEAN              -- Tampil di stasiun ini?
can_create BOOLEAN                -- Bisa buat baru?
can_submit BOOLEAN                -- Bisa submit ke WAITING_SIGNATURE?
can_print BOOLEAN                 -- Bisa cetak?
```

### clinic_profiles
```sql
clinic_code VARCHAR(20)           -- "KMA" → dipakai di nomor dokumen
logo_path VARCHAR(500)            -- Path logo untuk kop surat PDF
signature_path VARCHAR(500)       -- Path TTD direktur (image)
stamp_path VARCHAR(500)           -- Path cap/stempel klinik
director_name VARCHAR(255)        -- Nama direktur untuk dokumen resmi
director_sip VARCHAR(100)         -- SIP direktur

-- Nomor RM config
rm_format VARCHAR(50)             -- 'YYYYMMSEQ' (default)
rm_seq_length INT DEFAULT 4       -- 4 digit: 0001
rm_last_seq INT DEFAULT 0         -- Auto-increment saat pasien baru

-- PDF & Print config
pdf_engine VARCHAR(50)            -- 'puppeteer' (default)
watermark_enabled BOOLEAN         -- Global ON/OFF (kasir yang ubah)
watermark_type VARCHAR(20)        -- ORIGINAL / COPY / DRAFT
```

### document_verifications
```sql
verification_token VARCHAR(255)   -- Token unik untuk QR Code
verification_url VARCHAR(500)     -- URL scan: /verify/{TOKEN}
document_hash VARCHAR(255)        -- SHA256 dari document_data saat FINAL
is_valid BOOLEAN                  -- FALSE jika dokumen di-void
scan_count INT                    -- Berapa kali QR di-scan
last_scanned_at TIMESTAMP         -- Kapan terakhir di-scan
```

### bpjs_referrals_in
```sql
no_rujukan VARCHAR(50) UNIQUE     -- Nomor rujukan dari FKTP
tgl_expired DATE                  -- Dari VClaim (bukan hardcode)
fktp_kode VARCHAR(20)             -- Kode FKTP pengirim
diagnosa_rujukan VARCHAR(10)      -- ICD-10 dari FKTP
max_kunjungan INT                 -- Dari VClaim
sisa_kunjungan INT                -- Dari VClaim
kunjungan_ke INT                  -- Kunjungan ke berapa ini
is_notified_expired BOOLEAN       -- Sudah notif expired?
status VARCHAR(20)                -- VALID/EXPIRED/USED_UP/INVALID
```

### bpjs_referrals_out
```sql
no_rujukan VARCHAR(50) UNIQUE     -- Nomor rujukan dari VClaim
faskes_tujuan_kode VARCHAR(20)    -- Kode RS tujuan di BPJS
kode_spesialis VARCHAR(10)        -- Kode spesialis tujuan
urgency VARCHAR(20)               -- ELEKTIF/SEGERA/EMERGENCY
tgl_expired DATE                  -- Dari VClaim
status VARCHAR(20)                -- DRAFT/SUBMITTED/SUCCESS/FAILED
```

### bpjs_control_letters
```sql
no_surat_kontrol VARCHAR(50)      -- Nomor SC dari VClaim
tgl_kontrol DATE                  -- Tanggal kontrol berikutnya
tgl_expired DATE                  -- Dari VClaim
is_notified_expired BOOLEAN       -- Sudah notif expired?
status VARCHAR(20)                -- DRAFT/SUBMITTED/SUCCESS/FAILED/EXPIRED/USED
```

### satusehat_sync_logs
```sql
sync_date DATE                    -- Tanggal sync
sync_type VARCHAR(20)             -- AUTO/MANUAL
status VARCHAR(20)                -- SUCCESS/PARTIAL/FAILED/RUNNING
total_sent INT                    -- Total berhasil dikirim
total_failed INT                  -- Total gagal
retry_count INT                   -- Sudah retry berapa kali
next_retry_at TIMESTAMP           -- Jadwal retry berikutnya
```

### inacbgs_grouping_logs
```sql
grouper_version VARCHAR(20)       -- Versi INA-CBGs (update tiap tahun)
input_diagnosis JSONB             -- {utama, sekunder}
input_tindakan JSONB              -- [array ICD-9 CM]
cbg_code VARCHAR(20)              -- Kode CBG hasil grouping
cbg_tarif DECIMAL(12,2)           -- Tarif hasil grouping
severity_level VARCHAR(5)         -- I/II/III
engine_type VARCHAR(10)           -- JAR/API
status VARCHAR(20)                -- SUCCESS/FAILED
```

---

## Migration Order (67 Files)

> Foundation Laravel (`0001_01_01_*`): `users`, `cache`, `jobs` — di-create
> dulu oleh skeleton. Tabel `users` di-recreate oleh migration custom
> nomor 3 di bawah (relasi `employee_id`, `role_id`).

```
-- FOUNDATION
1.  roles
2.  employees
3.  users
4.  clinic_profiles

-- MASTER DATA
5.  insurers
6.  procedures
7.  icd10_codes
8.  icd9_codes
9.  bhp_items
10. iol_items
11. medications
12. surgery_packages

-- PASIEN & KUNJUNGAN
13. patients
14. visits
15. queues
16. visit_cob

-- TARIF
17. procedure_tariffs
18. medication_tariffs
19. bhp_tariffs
20. iol_tariffs

-- KLINIS
21. surgery_schedules
22. nurse_assessments
23. refraction_records
24. refraction_prescriptions
25. iol_recommendations
26. doctor_examinations
27. visit_services
28. medical_resumes

-- BEDAH
29. surgery_records
30. surgery_requests
31. surgery_request_bhp
32. surgery_request_iol
33. surgery_iol_usage

-- PENUNJANG
34. diagnostic_orders
35. diagnostic_results

-- FARMASI & BILLING
36. prescriptions
37. prescription_items
38. billing_invoices
39. billing_items
40. bpjs_claims
41. claim_audit_logs

-- SISTEM
42. tariffs
43. system_logs

-- REKAM MEDIS
44. document_number_configs
45. document_types
46. document_templates
47. station_document_mappings
48. patient_documents
49. document_verifications
50. notifications
51. medical_records
52. medical_records_versions

-- INTEGRASI
53. integration_configs
54. bpjs_referrals_in
55. bpjs_referrals_out
56. bpjs_control_letters
57. bpjs_vclaim_logs
58. bpjs_antrean_logs
59. bpjs_icare_logs
60. inacbgs_grouping_logs
61. satusehat_sync_logs
62. satusehat_resource_logs

-- TAMBAHAN (2026_05_19)
63. add_username_to_users_table          -- ALTER users: kolom username
64. add_pain_rps_to_nurse_assessments    -- ALTER nurse_assessments: kolom pain_rps
65. create_doctor_schedules_table        -- TABEL BARU: jadwal praktik dokter
```


---

## Enum Values (Standar)

```
visits.guarantor_type:     UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL
visits.satusehat_sync_status: PENDING / SYNCED / FAILED / SKIPPED
visits.insurer_id:         NULL jika UMUM/BPJS, isi UUID jika ASURANSI/PERUSAHAAN/SOSIAL

insurers.type:             ASURANSI / PERUSAHAAN / SOSIAL
-- UMUM & BPJS tidak perlu record di insurers (built-in)

procedure_tariffs.classification:  UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL
-- Logika fallback: cari tarif spesifik → fallback ke classification → fallback ke UMUM

visit_cob.penjamin2_type:  BPJS / ASURANSI / PERUSAHAAN
surgery_requests.status:   REQUESTED / SENT / RECEIVED

iol_items.iol_type:        MONOFOCAL / MULTIFOCAL / TORIC / TRIFOCAL / EDOF / PHAKIC
iol_items.material:        Acrylic / Silicone / PMMA
visits.classification:     Baru / Pre-Op / Post-Op / Kontrol
visits.current_station:    ADMISI / TR / DOKTER / PENUNJANG / BEDAH / KASIR / FARMASI / SELESAI
                           -- urutan flow: A → TR → D → (P/B opsional) → K → F → SELESAI
                           -- TR menggantikan TRIASE & REFRAKSIONIS (merged station)

queues.status:             WAITING / CALLED / IN_PROGRESS / COMPLETED / CANCELLED
queues.queue_prefix:       A (Admisi) / TR (Triase+Refraksionis) / D (Dokter) / P (Penunjang) / B (Bedah) / K (Kasir) / F (Farmasi)

refraction_records.perception_type:   DEKAT / JAUH
refraction_records.iop_method:        NCT / Goldmann / Schiotz

doctor_examinations.planning:         PULANG_BEROBAT_JALAN / BEDAH / RUJUK
bpjs_referrals_in.status:   VALID / EXPIRED / USED_UP / INVALID
bpjs_referrals_out.status:  DRAFT / SUBMITTED / SUCCESS / FAILED
bpjs_control_letters.status: DRAFT / SUBMITTED / SUCCESS / FAILED / EXPIRED / USED

rujuk_urgency:                        ELEKTIF / SEGERA / EMERGENCY

integration_configs.system_name:      VCLAIM / ANTREAN / ICARE / LUPIS / INACBGS / SATUSEHAT
integration_configs.last_test_status: SUCCESS / FAILED

bpjs_vclaim_logs.action:    GENERATE_SEP / CANCEL_SEP / SUBMIT_CLAIM / CHECK_STATUS
bpjs_antrean_logs.action:   VALIDATE_BOOKING / CHECK_QUOTA / CONFIRM
bpjs_icare_logs.action:     CHECK_CLAIM / GET_UTILISASI / MONITOR
inacbgs_grouping_logs.engine_type: JAR / API

satusehat_sync_logs.status:       SUCCESS / PARTIAL / FAILED / RUNNING
satusehat_sync_logs.sync_type:    AUTO / MANUAL
satusehat_resource_logs.resource_type: Encounter / Condition / MedicationRequest / MedicationDispense / ImagingStudy
satusehat_resource_logs.status:   SUCCESS / FAILED / SKIPPED
doctor_examinations.segmen (dropdown): Normal / Tidak Normal / Tidak Dapat Dinilai

prescriptions.status:      DRAFT / SUBMITTED / DISPENSING / DISPENSED / CANCELLED
billing_invoices.status:   DRAFT / FINALIZED / PAID / PARTIALLY_PAID / CANCELLED
billing_invoices.payment_method: CASH / CREDIT_CARD / TRANSFER / BPJS

bpjs_claims.status:        DRAFT / REVIEW / VERIFIED / SUBMITTED / SELESAI / DITOLAK
bpjs_claims.bpjs_status:   PENDING / PROSES / SELESAI / DITOLAK

surgery_schedules.status:  SCHEDULED / IN_PROGRESS / DONE / CANCELLED
diagnostic_orders.status:  REQUESTED / IN_PROGRESS / COMPLETED / CANCELLED
diagnostic_results.result_status: PENDING / COMPLETED / REVIEWED / APPROVED

medications.formularium:   FORNAS / FORMULARIUM GENERIK / BRANDED

document_types.fill_frequency:    ONCE_LIFETIME / PER_VISIT / PER_EPISODE
document_types.generate_type:     AUTO / MANUAL / HYBRID
document_types.category:          ADMINISTRASI / KLINIS / PENUNJANG / BEDAH / FARMASI / BILLING

patient_documents.status:         DRAFT / WAITING_SIGNATURE / FINAL / REJECTED / VOID
patient_documents.created_by_station: ADMISI / TR / DOKTER / PENUNJANG / BEDAH / KASIR / FARMASI

notifications.type:               SIGNATURE_REQUEST / SIGNATURE_REJECTED / DOCUMENT_FINAL

document_number_configs.reset_period: DAILY / MONTHLY / YEARLY / NEVER
```

---

## Soft Delete Pattern (Laravel)

```php
// Di setiap Model:
use SoftDeletes;

// Query otomatis exclude deleted:
Patient::all();                    // Hanya aktif
Patient::withTrashed()->all();     // Termasuk deleted
Patient::onlyTrashed()->all();     // Hanya deleted

// Soft delete:
$patient->delete();                // Set deleted_at = NOW()

// Restore:
$patient->restore();               // Set deleted_at = NULL
```

---

## Finalization Pattern

```php
// Setelah finalized, record TIDAK BISA diedit
// Implementasi di Model atau Service:

public function finalize(string $id, string $employeeId): void
{
    $record = Model::findOrFail($id);

    if ($record->is_finalized) {
        throw new \Exception('Record sudah dikunci, tidak bisa diubah.');
    }

    $record->update([
        'is_finalized'    => true,
        'finalized_at'    => now(),
        'finalized_by_id' => $employeeId,
    ]);
}
```

---

## BMI Auto-Calculate

```php
// Di NurseAssessmentService:
public function calculateBMI(float $bb, float $tb): float
{
    // TB dalam cm → konversi ke meter
    $tbMeter = $tb / 100;
    return round($bb / ($tbMeter * $tbMeter), 2);
}
```

---

## Queue Number Format

```php
// Generate queue number per station per hari:
// Format: {prefix}-{sequence 3 digit}
// Contoh: A-001, TR-005, D-003, K-007, F-012

public function generateQueueNumber(string $station): array
{
    $prefix = match($station) {
        'ADMISI'    => 'A',
        'TR'        => 'TR',   // Triase + Refraksionis (merged)
        'DOKTER'    => 'D',
        'PENUNJANG' => 'P',
        'BEDAH'     => 'B',
        'KASIR'     => 'K',
        'FARMASI'   => 'F',
        default     => 'X',
    };

    $lastQueue = Queue::whereDate('created_at', today())
        ->where('station', $station)
        ->max('queue_sequence') ?? 0;

    $sequence = $lastQueue + 1;

    return [
        'queue_prefix'   => $prefix,
        'queue_sequence' => $sequence,
        'queue_number'   => $prefix . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT),
    ];
}
```

### Flow Transisi Antrean (matrix selesaiAntrian → station next)

```
ADMISI    selesai → TR    (1 baris queues baru, prefix=TR)
TR        selesai → D     (hanya jika NurseAssessment & RefractionRecord
                           kedua-duanya is_finalized=true untuk visit yg sama)
DOKTER    selesai → K     (atau ke PENUNJANG dulu jika ada order, kembali ke D
                           setelah hasil rilis, baru ke K)
KASIR     selesai → F     (setelah invoice bayar/finalize)
FARMASI   selesai → SELESAI (pasien pulang, queue.status='COMPLETED')

BPJS — Kiosk dengan Kode Booking / No Rujukan:
KIOSK     → TR (WS RS)    (skip Admisi SAJA, TR tetap dilewati;
                           validasi booking via POST /admisi/bpjs/validasi-booking)
          + paralel POST entry DOKTER di WSBPJS (sinkronisasi BPJS Antrean)
            HANYA jika integration_configs.bpjs_enabled=true (bridging aktif).
            Pakai BpjsAntreanService. Entry lokal & entry BPJS hidup paralel.
```

---

## Medical Resume Auto-Generate

```php
// Di MedicalResumeService:
public function generateResume(string $visitId, string $doctorId): MedicalResume
{
    $visit      = Visit::with(['nurseAssessment', 'refractionRecord', 'doctorExamination', 'diagnosticResults'])->findOrFail($visitId);
    $doctor     = $visit->doctorExamination;
    $refraction = $visit->refractionRecord;

    // S: dari anamnese dokter
    $s = $doctor->anamnese ?? '-';

    // O: dari data refraksionis (auto-populate)
    $o = "Visus OD: {$refraction->visus_akhir_od}, OS: {$refraction->visus_akhir_os}. "
       . "IOP OD: {$refraction->iop_od} mmHg, OS: {$refraction->iop_os} mmHg. "
       . "Autoref OD: S{$refraction->autoref_od_sph} C{$refraction->autoref_od_cyl} X{$refraction->autoref_od_axis}";

    // A: dari ICD-10
    $a = $doctor->diagnosis_utama . ' ' . implode(', ', $doctor->diagnosis_sekunder ?? []);

    // P: dari ICD-9 + planning
    $p = implode(', ', $doctor->tindakan_codes ?? []) . '. Planning: ' . $doctor->planning;

    // Hasil penunjang (dari diagnostic_results yang sudah selesai)
    $penunjang = $visit->diagnosticResults->map(fn($r) => [
        'test_type' => $r->order->test_type,
        'result'    => $r->expertise_data,
        'date'      => $r->uploaded_at,
    ])->toArray();

    return MedicalResume::create([
        'visit_id'          => $visitId,
        'doctor_id'         => $doctorId,
        'resume_s'          => $s,
        'resume_o'          => $o,
        'resume_a'          => $a,
        'resume_p'          => $p,
        'penunjang_results' => $penunjang,
        'is_editable'       => true,
        'generated_at'      => now(),
    ]);
}
```

---

## File Referensi

- **Arsitektur lengkap:** `ARCHITECTURE.md` (root repo) — Section 7
  (Database Tables, 13 sub-section per domain) + Section 11 (Service Flow)
- **Schema SQL Lengkap:** `docs/DATABASE_SCHEMA_REVISED.md`
- **PRD:** `docs/prd.md`
- **API Contract:** `docs/api-contract.md`
- **Migration aktual:** `backend/database/migrations/` (67 file)
- **Model aktual:** `backend/app/Models/` (57 file)
