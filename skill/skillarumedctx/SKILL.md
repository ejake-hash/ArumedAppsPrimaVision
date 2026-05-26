---
name: skillarumedctx
description: "Use this skill for Arumed Apps project context including tech stack, patient flow, module list, API patterns, coding conventions, and integration details. Always use alongside skillarumeddb for complete context. Triggers: any code generation, API design, controller/service creation, or architectural decisions for Arumed Apps."
---

# Arumed Apps — Project Context

## Overview
Sistem manajemen klinik mata (FKRTL Klinik Utama Mata) 100% paperless.
Nama klinik: Klinik Mata Arunika (kode: KMA)
Server: Ubuntu + Nginx
Domain: apps.primavisionhospital.com

---

## Tech Stack

| Layer | Teknologi |
|-------|-----------|
| Backend | Laravel 13.8 + PHP 8.3 |
| Database | PostgreSQL (prod) / SQLite (dev) |
| Frontend | Vue 3.5 + Vite 5 + Pinia 2.2 + Vue Router 4 |
| HTTP Client | axios 1.7 (interceptor: 401 → arumed:session-expired event) |
| Realtime | Laravel Reverb (WebSocket) + pusher-js 8.5 (frontend listener) |
| PDF Engine | Puppeteer (Chrome Headless) |
| Queue | Laravel Queue (jobs) |
| Scheduler | Laravel Scheduler (cron) |
| Auth | JWT (tymon/jwt-auth 2.3) — `auth:api` guard |
| RBAC | Role + Employee via tabel `roles`/`employees`/`users` (custom, bukan Spatie) |
| Server | Ubuntu + Nginx |
| Dev port | Backend `:8000` (artisan serve), Frontend `:5173` (vite, proxy /api → :8000) |
| API base | `/api/v1/*` (prefix dari bootstrap/app.php + group v1 di routes/api.php) |

---

## Folder Structure

```
/arumed-apps
├── /backend                       (Laravel 13.8)
│   ├── app/Http/Controllers/      (14 controller, thin: validate + delegate)
│   ├── app/Services/              (18 service, semua business logic & integrasi)
│   ├── app/Models/                (57 Eloquent model)
│   ├── app/Events/                (AdmisiQueueUpdated, TriaseQueueUpdated → Reverb)
│   ├── bootstrap/app.php          (Laravel 11+ style, apiPrefix=api, HandleCors)
│   ├── database/migrations/       (67 migration)
│   ├── database/seeders/          (12 seeder)
│   └── routes/api.php             (~230 endpoint, prefix /v1 → final /api/v1/*)
└── /arumed-frontend               (Vue 3.5 + Vite 5)
    ├── src/views/                 (17 view; 1 view per modul + AntreanTVView + AnjunganView)
    ├── src/layouts/AppLayout.vue  (shell: sidebar + topbar untuk route terautentikasi)
    ├── src/components/layout/     (AppSidebar, AppTopbar, BrandMark)
    ├── src/stores/                (8 Pinia store)
    ├── src/services/api.js        (axios instance + helper per-domain)
    ├── src/router/index.js        (route + beforeEach auth guard)
    └── src/assets/styles/         (tokens.css + base.css; no UI lib eksternal)
```

**Catatan:** Lihat `ARCHITECTURE.md` di root repo untuk tree lengkap, daftar
endpoint per modul, dan struktur Pinia stores detail.

---

## Alur Stasiun (Service Flow)

**Catatan kunci:** Triase & Refraksionis sudah **DI-MERGE** menjadi satu
antrean `TR` (1 baris `queues`, 2 worker paralel). Urutan stasiun final:
**ADMISI → TR → DOKTER → KASIR → FARMASI → PULANG**.

```
KIOSK / ANJUNGAN (AnjunganView.vue)
  │
  ├── Pasien BPJS + (kode booking ATAU no rujukan)
  │     → SKIP Admisi → langsung ANTREAN TR di WS RS
  │     → JIKA BPJS bridging AKTIF: paralel POST entry antrean DOKTER
  │       di WSBPJS (sinkronisasi BPJS Antrean — pakai BpjsAntreanService)
  │
  └── Pasien BARU / UMUM / ASURANSI / LAINNYA
        → ANTREAN ADMISI (A)
              ↓ selesai daftar
        ANTREAN TR (gabungan Triase + Refraksionis)
          ├── Perawat (PerawatView) — finalize NurseAssessment
          └── Refraksionis (RefraksionisView) — finalize RefractionRecord
              ↓ KEDUANYA finalize (status-parallel OK)
        ANTREAN DOKTER (D)
              ↓ dokter finalize
        ANTREAN KASIR (K)  ← KASIR DULUAN, BUKAN FARMASI
              ↓ bayar / finalize invoice
        ANTREAN FARMASI (F)
              ↓ dispensing selesai
        PULANG

Sisipan opsional di tengah alur:
  • PENUNJANG (P) — jika dokter order; setelah hasil rilis kembali ke D
  • BEDAH (B)    — jika planning=BEDAH; buat jadwal operasi, pasien pulang
                   dulu, kembali sesuai tanggal operasi
```

### Logic Auto Queue ke Dokter (dari TR → D)
```php
// Trigger saat nurse_assessments ATAU refraction_records di-finalize.
// Cek apakah KEDUA-DUANYA sudah selesai untuk visit yg sama:

$triaseSelesai   = NurseAssessment::where('visit_id', $id)
                     ->where('is_finalized', true)->exists();
$refraksiSelesai = RefractionRecord::where('visit_id', $id)
                     ->where('is_finalized', true)->exists();

if ($triaseSelesai && $refraksiSelesai) {
    $visit->update([
        'ready_for_doctor'       => true,
        'triase_completed_at'    => $visit->triase_completed_at ?? now(),
        'refraksi_completed_at'  => $visit->refraksi_completed_at ?? now(),
    ]);
    // Mark antrean TR selesai, lalu enqueue ke DOKTER
    Queue::where('visit_id', $id)->where('queue_prefix', 'TR')
         ->update(['status' => 'COMPLETED']);
    app(QueueService::class)->createQueue($visit->id, 'DOKTER');
    // Broadcast event Reverb (frontend & AntreanTVView listen)
    event(new TriaseQueueUpdated($visit));
}
```

### Queue Prefix per Stasiun (FINAL)
```
A  = ADMISI
TR = TRIASE + REFRAKSIONIS (merged — satu antrean, dua worker)
D  = DOKTER
P  = PENUNJANG (opsional)
B  = BEDAH    (opsional)
K  = KASIR
F  = FARMASI
```

### AntreanTVView (Display TV Real-Time)
- Route public `/antrean-tv` (no auth, layout blank) — dipasang di TV
  ruang tunggu.
- Subscribe ke channel Reverb untuk station `A`, `TR`, `D`, `K`, `F` via
  `pusher-js`. Fallback polling 30s jika WS gagal.
- Tampilkan nomor antrean yang sedang `DIPANGGIL` + next-in-line per
  station. Animasi blink + TTS saat panggilan baru.

### Klasifikasi Kunjungan
```
Baru / Pre-Op / Post-Op / Kontrol
```

### Planning Dokter
```
PULANG_BEROBAT_JALAN / BEDAH / RUJUK
```

---

## Alur Pasien per Tipe

### UMUM (Non-Bedah)
```
Admisi (guarantor_type=UMUM)
→ TR (Triase+Refraksionis, paralel) → Dokter
→ Planning: PULANG_BEROBAT_JALAN
→ Kasir (tarif UMUM) → Farmasi → Pulang
```

### UMUM (Bedah)
```
Admisi → TR → Dokter
→ Planning: BEDAH
→ Penunjang Biometri → IOL recommendation
→ Bedah (request BHP+IOL ke Farmasi)
→ Kasir (tarif UMUM) → Farmasi → Pulang
```

### BPJS — pakai Kode Booking / No Rujukan (skip Admisi saja)
```
Anjungan input kode booking JKN ATAU no rujukan
→ POST /admisi/bpjs/validasi-booking (validasi via VClaim)
→ Langsung ANTREAN TR di WS RS — skip Admisi (TR tetap dilewati)
→ JIKA BPJS bridging AKTIF (integration_configs.bpjs_enabled=true):
   paralel POST entry antrean DOKTER di WSBPJS (BpjsAntreanService)
   — entry lokal (WS RS) & entry BPJS (WSBPJS) hidup paralel
→ TR (perawat + refraksionis) → Dokter
→ ICD-10 + ICD-9 CM (untuk klaim)
→ Kasir (tarif BPJS) → Farmasi (FORNAS) → Pulang
→ Klaim: INA-CBGs grouping → LUPIS → VClaim submit
```

### BPJS — Manual (tanpa kode booking / no rujukan)
```
Admisi (guarantor_type=BPJS)
→ Generate SEP via VClaim (POST /admisi/bpjs/generate-sep)
→ Validasi rujukan (bpjs_referrals_in)
→ TR → Dokter
→ ICD-10 + ICD-9 CM
→ Kasir (tarif BPJS) → Farmasi (FORNAS) → Pulang
→ Klaim: INA-CBGs grouping → LUPIS → VClaim submit
```

### BPJS (Bedah)
```
Sama seperti BPJS Non-Bedah +
→ Bedah: BHP+IOL request ke Farmasi
→ IOL usage dicatat (surgery_iol_usage)
→ Klaim: include BHP+IOL di billing
```

### RUJUK
```
Dokter planning=RUJUK
→ POST VClaim → dapat nomor rujukan keluar
→ Generate Surat Rujukan (patient_documents)
→ Kasir → Farmasi → Pulang
```

### KONTROL BPJS
```
Admisi input no_surat_kontrol
→ GET VClaim → validasi SC
→ Alur normal (tanpa rujukan baru)
```

---

## API Response Pattern (Wajib Konsisten)

```php
// Success
return response()->json([
    'success' => true,
    'data'    => $data,
    'message' => 'Berhasil',
    'errors'  => null,
], 200);

// Error Validasi
return response()->json([
    'success' => false,
    'data'    => null,
    'message' => 'Validasi gagal',
    'errors'  => $validator->errors(),
], 422);

// Not Found
return response()->json([
    'success' => false,
    'data'    => null,
    'message' => 'Data tidak ditemukan',
    'errors'  => null,
], 404);

// Server Error
return response()->json([
    'success' => false,
    'data'    => null,
    'message' => 'Terjadi kesalahan sistem',
    'errors'  => null,
], 500);
```

---

## Coding Conventions (Laravel)

### Controller
```php
// Tipis — hanya validasi + call service
public function store(Request $request)
{
    $validated = $request->validate([...]);
    $data = $this->service->create($validated);
    return response()->json(['success' => true, 'data' => $data]);
}
```

### Service
```php
// Semua business logic di sini
// Bukan di Controller, bukan di Model
class AdmisiService
{
    public function registerPatient(array $data): array { ... }
    public function generateQueueNumber(string $station): array { ... }
}
```

### Model
```php
// Hanya: fillable, casts, relationships, scopes
class Visit extends Model
{
    use SoftDeletes;
    protected $fillable = [...];
    protected $casts = ['is_completed' => 'boolean'];
    
    public function patient(): BelongsTo { ... }
    public function queues(): HasMany { ... }
    
    // Scope
    public function scopeToday($query) {
        return $query->whereDate('visit_date', today());
    }
}
```

### Route Pattern
```php
// routes/api.php
Route::prefix('admisi')->group(function () {
    Route::get('/dashboard', [AdmisiController::class, 'dashboard']);
    Route::get('/kunjungan', [AdmisiController::class, 'index']);
    Route::post('/daftar', [AdmisiController::class, 'store']);
    Route::get('/kunjungan/{id}', [AdmisiController::class, 'show']);
    Route::put('/kunjungan/{id}/selesai', [AdmisiController::class, 'complete']);
});
```

---

## Tarif Logic

```php
// Priority lookup tarif:
// 1. Cari tarif spesifik (insurer_id + classification)
// 2. Fallback ke tarif classification (tanpa insurer)
// 3. Fallback ke tarif UMUM

// Tarif mengikuti visits.guarantor_type yang di-set saat ADMISI
// Tidak berubah sampai kasir (kecuali kasir update COB)
```

---

## Nomor Format

```
No RM:       YYYYMM + 4 digit
             Contoh: 202605 0001
             Tidak pernah reset

No Dokumen:  RME/{kode_dok}/{CLINIC}/{SEQ}
             Contoh: RME/1.1/KMA/0000001
             SEQ tidak reset (global)

No Invoice:  INV-{CLINIC}/{YYYY}/{MM}/{SEQ}
             Contoh: INV-KMA/2026/05/001
             SEQ reset per bulan
```

---

## Modul & Controller List

| Modul | Controller | Service |
|-------|-----------|---------|
| Admisi | AdmisiController | AdmisiService |
| Triase/Perawat | PerawatController | PerawatService |
| Refraksionis | RefraksiController | RefraksiService |
| Dokter | DokterController | DokterService |
| Penunjang | PenunjangController | PenunjangService |
| Bedah | BedahController | BedahService |
| Farmasi | FarmasiController | FarmasiService |
| Kasir | KasirController | KasirService |
| Klaim BPJS | KlaimController | KlaimService |
| Rekam Medis | RekamMedisController | RekamMedisService |
| Dashboard | DashboardController | DashboardService |
| Master Data | MasterDataController | MasterDataService |
| Integrasi | IntegrasiController | IntegrasiService |

---

## External Integrations

### BPJS (5 sistem)
```
VClaim   → SEP, klaim, rujukan, surat kontrol
Antrean  → Validasi kode booking JKN Mobile
iCare    → Monitoring klaim & utilisasi
LUPIS    → Format data utilisasi klaim
INA-CBGs → Grouper diagnosis → tarif klaim
```

### Satu Sehat (FHIR R4)
```
5 resource wajib (sync batch 23:59):
Encounter / Condition / MedicationRequest /
MedicationDispense / ImagingStudy

Auto retry: 01:00 jika gagal
Manual retry: via menu Integrasi
```

### Status Integrasi
```
Semua disabled default (is_enabled=FALSE)
Enable via integration_configs saat credentials tersedia
Service files sudah ada sebagai placeholder
```

---

## BPJS Flow Detail

### Generate SEP
```
1. Input no_kartu_bpjs atau NIK
2. GET peserta dari VClaim → validasi
3. GET rujukan → validasi (kunjungan pertama)
4. POST generate SEP → dapat no_sep
5. Update visits.no_sep
6. Log di bpjs_vclaim_logs
```

### Rujukan
```
MASUK:  GET VClaim → validasi → simpan bpjs_referrals_in
KELUAR: POST VClaim → dapat nomor → simpan bpjs_referrals_out
KONTROL: GET VClaim → validasi SC → simpan bpjs_control_letters
```

### Klaim
```
INA-CBGs grouping → lupis_data → VClaim submit
Status: DRAFT→REVIEW→VERIFIED→SUBMITTED
Track di: bpjs_claims + claim_audit_logs
```

---

## RME & Dokumen

### Status Dokumen
```
DRAFT → WAITING_SIGNATURE → FINAL → (VOID oleh admin)
                         ↘ REJECTED (oleh dokter)
```

### Tanda Tangan
```
Staff/Dokter : PIN
Pasien/Keluarga: DRAW (tablet)
```

### Print
```
Engine: Puppeteer
Format: A4 Portrait
Watermark: ORIGINAL/COPY/DRAFT (kasir set global)
QR Code: di setiap dokumen FINAL → link verifikasi
```

### Nomor Dokumen
```
Generate otomatis saat status → FINAL
Format dari document_number_configs
Tidak bisa diubah setelah FINAL
```

---

## Notification System

```
Inbox TTD Dokter:
→ Muncul saat patient_documents.status = WAITING_SIGNATURE
→ pending_signature_roles contains "DOCTOR"
→ Dokter bisa: TTD (PIN) / Reject + alasan / Edit field
→ Resend: trigger notif ulang ke dokter

Notif Expired:
→ BPJS rujukan expired → notif admisi
→ Surat Kontrol expired → notif admisi
→ Cek via Laravel Scheduler (daily)
```

---

## IOL Flow

```
Penunjang Biometri:
→ Input IOL recommendation (power, type, eye_side)
→ Kirim ke dokter (iol_recommendations)

Dokter Planning Bedah:
→ Lihat IOL recommendation
→ Approve → planning=BEDAH

Bedah Request ke Farmasi:
→ surgery_requests (BHP auto dari paket + IOL)
→ Status: REQUESTED→SENT→RECEIVED

Farmasi Siapkan:
→ Assign iol_item_id ke surgery_request_iol
→ Kurangi stok BHP
→ IOL is_used=TRUE saat operasi

Laporan Operasi:
→ surgery_iol_usage (brand, power, lot, serial)
```

---

## Seeder Data Awal

```
Wajib ada sebelum testing:
1. RoleSeeder          → roles: Superadmin, Dokter, dll
2. EmployeeSeeder      → 1 dokter, 1 perawat, dll (dummy)
3. UserSeeder          → 1 user per role (dummy)
4. ClinicProfileSeeder → data klinik KMA
5. IntegrationSeeder   → 6 record (semua disabled)
6. ICD10Seeder         → ICD-10 mata (H00-H59)
7. ICD9Seeder          → ICD-9 CM mata
8. TariffSeeder        → tarif dasar UMUM
9. DocumentTypeSeeder  → 24 jenis dokumen RME
10. StationMappingSeeder → mapping stasiun → dokumen
```

---

## Migration Batch Order

```
Batch 1 (Foundation):
clinic_profiles, roles, employees, users

Batch 2 (Master Data):
insurers, procedures, icd10_codes, icd9_codes,
bhp_items, iol_items, medications, surgery_packages

Batch 3 (Pasien & Kunjungan):
patients, visits, queues, visit_cob

Batch 4 (Tarif):
procedure_tariffs, medication_tariffs,
bhp_tariffs, iol_tariffs

Batch 5 (Klinis):
surgery_schedules, nurse_assessments,
refraction_records, refraction_prescriptions,
iol_recommendations, doctor_examinations,
visit_services, medical_resumes

Batch 6 (Bedah):
surgery_records, surgery_requests,
surgery_request_bhp, surgery_request_iol,
surgery_iol_usage

Batch 7 (Penunjang & Farmasi):
diagnostic_orders, diagnostic_results,
prescriptions, prescription_items

Batch 8 (Billing & Klaim):
billing_invoices, billing_items,
bpjs_claims, claim_audit_logs

Batch 9 (Sistem):
tariffs, system_logs

Batch 10 (Rekam Medis):
document_number_configs, document_types,
document_templates, station_document_mappings,
patient_documents, document_verifications,
notifications, medical_records,
medical_records_versions

Batch 11 (Integrasi):
integration_configs, bpjs_referrals_in,
bpjs_referrals_out, bpjs_control_letters,
bpjs_vclaim_logs, bpjs_antrean_logs,
bpjs_icare_logs, inacbgs_grouping_logs,
satusehat_sync_logs, satusehat_resource_logs
```

---

## Prompt Template untuk Claude Code

### Generate Migration
```
Baca skill: skillarumeddb + skillarumedctx

Generate Laravel migration untuk:
[nama tabel]

Rules:
- UUID: $table->uuid('id')->primary()
- Timestamps: $table->timestamps()
- SoftDelete: $table->softDeletes() jika ada deleted_at di skill
- Foreign keys sesuai skill
- Indexes sesuai skill
- Enum values sesuai skill
- Filename: YYYY_MM_DD_HHMMSS_create_{table}_table.php
```

### Generate Model
```
Baca skill: skillarumeddb + skillarumedctx

Generate Laravel Model untuk:
[nama tabel]

Rules:
- Namespace: App\Models
- use SoftDeletes jika ada deleted_at
- $fillable: semua kolom kecuali id, timestamps
- $casts: boolean, json, decimal sesuai tipe
- Relationships: sesuai diagram di skill
- Scopes: today(), active(), completed() jika relevan
```

### Generate Controller + Service
```
Baca skill: skillarumeddb + skillarumedctx

Generate Controller + Service untuk modul:
[nama modul]

Rules:
- Controller: tipis, hanya validasi + call service
- Service: semua business logic
- Response: ikuti API Response Pattern di skill
- Endpoint: sesuai alur pasien di skill (A → TR → D → K → F)
- Tarif: ikuti tarif logic di skill
- Setiap selesaiAntrian: update queues.status='COMPLETED' + buat baris
  queues baru untuk station next + broadcast event Reverb
```

---

## Referensi Repo

- **`ARCHITECTURE.md`** (root repo) — sumber kebenaran arsitektur:
  tree folder, 14 tabel endpoint per modul (~230 route), daftar 8 Pinia
  store, alur layanan lengkap (Section 11), tampilan AntreanTVView.
- **`backend/routes/api.php`** — sumber kebenaran endpoint (jika ada
  perbedaan dengan tabel di skill, ikut api.php).
- **`backend/bootstrap/app.php`** — middleware (HandleCors) + apiPrefix.
- **`arumed-frontend/src/services/api.js`** — axios instance & helper.
- **`arumed-frontend/src/stores/`** — 8 Pinia store, WebSocket logic.
