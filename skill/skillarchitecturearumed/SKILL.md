---
name: skillarchitecturearumed
description: "Use this skill for the complete Arumed Apps architecture reference: full folder tree (backend Laravel 13.8 + frontend Vue 3.5), all ~240 API endpoints across 15 modules (auth, antrian-cross-cutting, admisi, perawat, refraksi, dokter, penunjang, bedah, farmasi, kasir, klaim BPJS, rekam-medis, dashboard, master, integrasi), 67 database migrations grouped per domain, 8 Pinia stores, axios interceptor pattern, BPJS integration points (VClaim/Antrean/iCare/INA-CBGs), Satu Sehat sync, and centralized Service Flow (Section 11: QueueService orchestrates A -> TRIASE+REFRAKSIONIS -> D -> P?/B? -> K -> F -> SELESAI with same-day-bedah rule and sentinel transitions). Use when answering questions about overall system architecture, endpoint discovery, queue station transitions, gate-validation logic (TR finalize gate, BEDAH same-day rule, KASIR resep check), or when you need authoritative cross-module context that skillarumeddb (DB-only) and skillarumedctx (project context summary) don't cover. Complementary to those two skills."
---
# Arumed Apps — ARCHITECTURE

> Dokumen arsitektur tingkat-atas untuk **Arumed Apps**, sistem informasi
> klinik mata (Klinik Mata Arunika). Tujuan: jadi pintu masuk pertama
> developer baru — tahu di mana setiap modul tinggal, kontrak API antar
> layer, dan bagaimana frontend Vue berbicara dengan backend Laravel.

---

## 1. Overview

Arumed Apps adalah HIS (Hospital Information System) khusus oftalmologi,
**100% paperless**, dirancang untuk memenuhi PMK No. 24/2022 sekaligus
terintegrasi dengan BPJS (VClaim, Antrean, iCare, INA-CBGs) dan Satu Sehat
(Kemenkes). Sistem terdiri dari **dua deployable** yang hidup di satu repo:

| Aspek         | Backend                            | Frontend                                 |
|---------------|------------------------------------|------------------------------------------|
| Stack         | Laravel 13.8 + PHP 8.3             | Vue 3.5 + Vite 5                         |
| Auth          | JWT (`tymon/jwt-auth` 2.3)         | Bearer token di `localStorage`           |
| Default DB    | SQLite (dev) / PostgreSQL (prod)   | —                                        |
| State mgmt    | Service classes (DI ke controller) | Pinia (composition API style)            |
| Real-time     | Laravel Reverb (event broadcast)   | `pusher-js` (Reverb protocol)            |
| Folder        | `backend/`                         | `arumed-frontend/`                       |
| Dev port      | `:8000` (`php artisan serve`)      | `:5173` (`npm run dev`, proxy `/api`)    |
| Base path API | `/api/v1/*`                        | `import.meta.env.VITE_API_URL ?? '/api/v1'` |

Audience dokumen: developer baru, reviewer, integrator (BPJS/Satu Sehat).

---

## 2. Folder Structure

Struktur disusun per-deployable. Komentar inline menjelaskan kenapa folder
itu penting — bukan sekadar nama folder.

```
arumed-apps/
├── backend/                          # Laravel 13.8 — REST API + integrasi eksternal
│   ├── app/
│   │   ├── Events/                   # AdmisiQueueUpdated, TriaseQueueUpdated (broadcast Reverb)
│   │   ├── Http/Controllers/         # 14 controller per-modul (thin: validate + delegate ke Service)
│   │   ├── Models/                   # 57 Eloquent model (dikelompokkan per domain di Section 4.3)
│   │   ├── Services/                 # 18 service — semua business logic & integrasi pihak ketiga
│   │   └── Providers/AppServiceProvider.php
│   ├── bootstrap/app.php             # Bootstrap gaya Laravel 11+ (no Kernel.php) — CORS + apiPrefix=api
│   ├── config/
│   │   ├── cors.php                  # Izinkan origin dev frontend :5173
│   │   └── database.php
│   ├── database/
│   │   ├── migrations/               # 67 migration (urutan kronologis, lihat Section 7)
│   │   └── seeders/                  # 12 seeder (Role, User, ICD-9/10, ClinicProfile, dll)
│   ├── routes/
│   │   └── api.php                   # Semua endpoint — grup di bawah prefix /v1 (final: /api/v1/*)
│   └── composer.json
└── arumed-frontend/                  # Vue 3 SPA — UI semua modul HIS
    ├── src/
    │   ├── main.js                   # Entry: install Pinia + Router, mount #app
    │   ├── App.vue                   # Root: dengar event arumed:session-expired
    │   ├── router/index.js           # Definisi route + beforeEach (auth guard)
    │   ├── layouts/AppLayout.vue     # Shell: sidebar + topbar + <RouterView/>
    │   ├── views/                    # 17 view (1 view = 1 modul; lihat Section 6.1)
    │   ├── components/layout/        # AppSidebar, AppTopbar, BrandMark (tidak ada UI lib eksternal)
    │   ├── services/api.js           # Axios instance + helper per-domain (authApi, queueApi, dll)
    │   ├── stores/                   # 8 Pinia store (auth, visit, queue, admisi, perawat, dll)
    │   └── assets/styles/            # tokens.css (design token) + base.css (reset + animasi)
    ├── vite.config.js                # Alias @/ → src/; proxy /api → :8000
    ├── package.json
    └── index.html
```

Direktori lain di root (`Docs/`, `assets/`, `skill/`, `hello.js`) bersifat
auxiliary dan tidak masuk runtime aplikasi.

---

## 3. Tech Stack & Dependencies

Bagian ini mengunci versi runtime yang **harus** dipasang sebelum menjalankan
project — gunakan saat onboarding mesin baru atau diagnose error
version-mismatch.

### 3.1 Backend — `backend/composer.json`

```json
"require": {
  "php": "^8.3",
  "laravel/framework": "^13.8",
  "laravel/tinker": "^3.0",
  "tymon/jwt-auth": "^2.3"
},
"require-dev": {
  "fakerphp/faker": "^1.23",
  "laravel/pail": "^1.2.5",
  "laravel/pao": "^1.0.6",
  "laravel/pint": "^1.27",
  "mockery/mockery": "^1.6",
  "nunomaduro/collision": "^8.6",
  "phpunit/phpunit": "^12.5.12"
}
```

Catatan:
- `tymon/jwt-auth` adalah satu-satunya provider auth — `auth:api` guard
  bergantung pada package ini. Wajib jalankan `php artisan jwt:secret`
  sebelum login.
- `laravel/pail` menyediakan `php artisan pail` (log tail real-time, dipakai
  oleh script `composer dev`).

### 3.2 Frontend — `arumed-frontend/package.json`

```json
"dependencies": {
  "vue": "^3.5.0",
  "vue-router": "^4.4.0",
  "pinia": "^2.2.0",
  "axios": "^1.7.0",
  "pusher-js": "^8.5.0"
},
"devDependencies": {
  "vite": "^5.4.0",
  "@vitejs/plugin-vue": "^5.1.0"
}
```

Tidak ada UI library eksternal (Vuetify/Quasar/shadcn) — semua komponen
custom dengan CSS scoped + design token di `src/assets/styles/tokens.css`.

---

## 4. Backend Components

Layer pemisahan: **Routes → Controller (validate + DI service) → Service
(business logic) → Model (Eloquent)**. Service jadi titik sentuh integrasi
eksternal (BPJS, Satu Sehat) supaya controller tetap tipis.

### 4.1 Controllers (`backend/app/Http/Controllers/`)

Semua 15 controller ekstends `App\Http\Controllers\Controller` (base class)
dan menerima service via constructor DI (lihat pola di `AuthController.php`).

| Controller                  | Routes Prefix      | Purpose                                                                  |
|-----------------------------|--------------------|--------------------------------------------------------------------------|
| `AuthController`            | `/auth/*`          | Login, logout, refresh JWT, profil `/me`, ganti password                  |
| `QueueController`           | `/antrian/*`       | Generic queue ops cross-station — snapshot Antrean TV, advance, panggil   |
| `AdmisiController`          | `/admisi/*`        | Pendaftaran pasien, kunjungan, antrian admisi, jadwal dokter, BPJS-SEP   |
| `PerawatController`         | `/perawat/*`       | Antrian triase + asesmen keperawatan (NurseAssessment), vital history    |
| `RefraksiController`        | `/refraksi/*`      | Antrian refraksi, pemeriksaan refraksi, resep kacamata, rekomendasi IOL  |
| `DokterController`          | `/dokter/*`        | RME dokter (tab2/tab3/tab4), tindakan, resep, order penunjang, TTD       |
| `PenunjangController`       | `/penunjang/*`     | Order + hasil pemeriksaan penunjang (lab, imaging, biometri)             |
| `BedahController`           | `/bedah/*`         | Jadwal operasi, request BHP/IOL, laporan operasi, post-op                |
| `FarmasiController`         | `/farmasi/*`       | Dispensing resep, surgery-request fulfillment, stok obat/BHP/IOL         |
| `KasirController`           | `/kasir/*`         | Invoice + item billing, COB, watermark, laporan harian                   |
| `KlaimController`           | `/klaim/*`         | Workflow klaim BPJS (DRAFT → REVIEW → VERIFIED → SUBMITTED), INA-CBGs    |
| `RekamMedisController`      | `/rekam-medis/*`   | Riwayat pasien, dokumen (versioning), verifikasi QR                      |
| `DashboardController`       | `/dashboard/*`     | KPI, follow-up tracking, alert stok/BPJS/Satu Sehat                      |
| `MasterDataController`      | `/master/*`        | Profil klinik, role, pegawai, ICD-9/10, tarif (4 tipe), template dokumen |
| `IntegrasiController`       | `/integrasi/*`     | Status integrasi + log VClaim/Antrean/iCare/INA-CBGs/Satu Sehat          |

### 4.2 Services (`backend/app/Services/`)

Pola: controller hanya `validate()` lalu memanggil service method. Service
boleh saling memanggil (mis. `KlaimService` memanggil `InaCbgsService` untuk
grouping sebelum submit). **`QueueService` adalah orchestrator pusat**
untuk semua transisi antrian — per-station service (Admisi/Perawat/Refraksi/
Dokter/Penunjang/Bedah/Kasir/Farmasi) hanya thin wrapper yang delegate ke
`QueueService::advanceFromStation()`.

| Service                  | Dipanggil dari                 | Tanggung jawab                                              |
|--------------------------|--------------------------------|-------------------------------------------------------------|
| `AuthService`            | `AuthController`               | Login/logout JWT, refresh token, hash password              |
| `QueueService`           | `QueueController` + 8 station service | Generate queue number, panggil/mulai/lewati/batal, `advanceFromStation()` dengan matrix transisi Section 11.3, broadcast event Reverb |
| `AdmisiService`          | `AdmisiController`             | Buat Visit, daftar pasien; `selesaiAdmisi` → delegate `QueueService` |
| `PerawatService`         | `PerawatController`            | CRUD `NurseAssessment`, finalize (lock); `selesaiAntrian` → `QueueService` |
| `RefraksiService`        | `RefraksiController`           | `RefractionRecord` + `RefractionPrescription` + `IolRecommendation`; `selesaiAntrian` → `QueueService` |
| `DokterService`          | `DokterController`             | `DoctorExamination` (tab2/4), `MedicalResume`; `selesaiAntrian` → `QueueService` |
| `PenunjangService`       | `PenunjangController`          | `DiagnosticOrder` + `DiagnosticResult`; `selesaiAntrian` → `QueueService` (balik ke DOKTER) |
| `BedahService`           | `BedahController`              | `SurgerySchedule`, `SurgeryRecord`, `SurgeryIolUsage`; `selesaiAntrian` → `QueueService` |
| `FarmasiService`         | `FarmasiController`            | Dispensing `Prescription`, fulfillment `SurgeryRequest`; `selesaiAntrian` → `QueueService` (end of flow) |
| `KasirService`           | `KasirController`              | Generate `BillingInvoice`, hitung COB, bayar/cancel; `selesaiAntrian` → `QueueService` |
| `KlaimService`           | `KlaimController`              | Workflow status klaim + audit log                           |
| `RekamMedisService`      | `RekamMedisController`         | `PatientDocument` + `MedicalRecordVersion` (immutable)      |
| `DashboardService`       | `DashboardController`          | Agregasi statistik dari beberapa tabel                      |
| `MasterDataService`      | `MasterDataController`         | CRUD master + import/export CSV tarif                       |
| `IntegrasiService`       | `IntegrasiController`          | Aggregator status integrasi + retry handler                 |
| `BpjsVClaimService`      | `KlaimService`, `AdmisiService`| Wrapper HTTP ke endpoint BPJS VClaim, log di `bpjs_vclaim_logs` |
| `BpjsAntreanService`     | `AdmisiService`                | Validasi booking + sync antrean BPJS                        |
| `InaCbgsService`         | `KlaimService`                 | Grouping INA-CBGs, log di `inacbgs_grouping_logs`           |
| `SatusehatService`       | `RekamMedisService`, `IntegrasiService` | Sync resource ke Satu Sehat, retry          |

### 4.3 Models (`backend/app/Models/`)

Total 57 model Eloquent. Dikelompokkan per **domain klinik**, bukan
alfabetis, supaya mudah mencari saat membaca alur pasien:

- **Core / RBAC** — `User`, `Role`, `Employee`, `Patient`
- **Visit & Antrian** — `Visit`, `Queue`, `VisitCob`, `VisitService`
- **Clinical** — `NurseAssessment`, `RefractionRecord`, `RefractionPrescription`,
  `IolRecommendation`, `DoctorExamination`, `MedicalRecord`,
  `MedicalRecordVersion`, `MedicalResume`
- **Surgery** — `SurgerySchedule`, `SurgeryRecord`, `SurgeryRequest`,
  `SurgeryRequestBhp`, `SurgeryRequestIol`, `SurgeryIolUsage`
- **Pharmacy** — `Prescription`, `PrescriptionItem`
- **Diagnostic** — `DiagnosticOrder`, `DiagnosticResult`
- **Billing** — `BillingInvoice`, `BillingItem`, `BpjsClaim`, `ClaimAuditLog`
- **Master Data** — `Procedure`, `Medication`, `BhpItem`, `IolItem`, `Insurer`,
  `SurgeryPackage`, `Icd10Code`, `Icd9Code`, `ClinicProfile`, `DoctorSchedule`,
  `DocumentType`, `DocumentTemplate`, `DocumentNumberConfig`,
  `StationDocumentMapping`
- **Tariffs** — `ProcedureTariff`, `MedicationTariff`, `BhpTariff`, `IolTariff`
- **Documents & Notification** — `PatientDocument`, `DocumentVerification`,
  `Notification`
- **Integration & Logs** — `IntegrationConfig`, `BpjsReferralIn`,
  `BpjsReferralOut`, `BpjsControlLetter`, `BpjsVClaimLog`,
  `InacbgsGroupingLog`, `SatusehatSyncLog`, `SatusehatResourceLog`,
  `SystemLog`

### 4.4 Events (`backend/app/Events/`)

Dipakai untuk push real-time ke frontend via Reverb (protokol Pusher).
Frontend dengar via `pusher-js` di `admisiStore.js` & `perawatStore.js`.

- `AdmisiQueueUpdated` — broadcast saat antrian admisi berubah (call/selesai)
- `TriaseQueueUpdated` — broadcast saat antrian perawat/triase berubah

### 4.5 Middleware & Auth — `backend/bootstrap/app.php`

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:        __DIR__.'/../routes/web.php',
        api:        __DIR__.'/../routes/api.php',
        commands:   __DIR__.'/../routes/console.php',
        health:     '/up',
        apiPrefix:  'api',           // semua route api.php otomatis dapat prefix /api
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(fn (Exceptions $e) => null)
    ->create();
```

Penjelasan:
- **CORS** global (untuk dev frontend di port 5173). Konfigurasi
  origin/methods ada di `backend/config/cors.php`.
- **`auth:api` guard** dipakai via `Route::middleware('auth:api')->group(...)`
  di `routes/api.php` — di-back oleh `tymon/jwt-auth`.
- Final base URL endpoint: `/api` (dari `apiPrefix`) + `/v1` (group di
  api.php) → **`/api/v1/...`**.
- Health probe: `GET /up` (Laravel built-in, return 200 jika app siap).

---

## 5. API Routes / Endpoints

Semua route terdaftar di `backend/routes/api.php` di bawah `Route::prefix('v1')`.
Convention path memakai bahasa Indonesia (`/kunjungan`, `/antrian`,
`/pemeriksaan`) — match dengan istilah klinik. Total ±230 endpoint.

**Convention status code**:
- `200` sukses, `201` (jarang dipakai — kebanyakan response 200 dengan envelope)
- `401` JWT invalid/expired
- `403` lolos auth tapi tidak boleh aksi
- `422` validation error (format Laravel default)
- `500` exception unhandled

### 5.1 Auth (`/auth`)

Publik hanya `/login`; sisanya wajib Bearer token.

| Method | Path                  | Action                        | Purpose                                   |
|--------|-----------------------|-------------------------------|-------------------------------------------|
| POST   | `/auth/login`         | `AuthController@login`        | Public — tukar username/password ⇄ JWT    |
| POST   | `/auth/logout`        | `AuthController@logout`       | Invalidate token aktif                    |
| POST   | `/auth/refresh`       | `AuthController@refresh`      | Rotate JWT sebelum expired                |
| GET    | `/auth/me`            | `AuthController@me`           | Profil user current (role + employee)     |
| PUT    | `/auth/password`      | `AuthController@changePassword` | Ganti password (current + new + confirm) |

### 5.1b Antrian — cross-cutting (`/antrian`)

Endpoint generic untuk operasi antrian lintas-station. Dipakai oleh
`AntreanTVView.vue` (snapshot semua station), Dashboard, dan tooling admin.
Per-station selesai/panggil tetap available di prefix masing-masing (mis.
`/dokter/antrian/{id}/selesai`); endpoint `/antrian/*` adalah generic
fallback dan TIDAK menerapkan gate validation per-station — gate ada di
`QueueService::resolveNextStation` (lihat Section 11.4).

| Method | Path                              | Action                          | Purpose                                       |
|--------|-----------------------------------|---------------------------------|-----------------------------------------------|
| GET    | `/antrian`                        | `QueueController@index`         | Snapshot semua station hari ini (Antrean TV) |
| GET    | `/antrian/station/{station}`      | `QueueController@byStation`     | Antrian per station (A/TRIASE/.../FARMASI)   |
| GET    | `/antrian/{id}`                   | `QueueController@show`          | Detail satu baris antrian                     |
| PUT    | `/antrian/{id}/panggil`           | `QueueController@panggil`       | WAITING → CALLED + broadcast Reverb           |
| PUT    | `/antrian/{id}/mulai`             | `QueueController@mulai`         | CALLED → IN_PROGRESS                          |
| PUT    | `/antrian/{id}/lewati`            | `QueueController@lewati`        | Pindah ke akhir antrean station yang sama     |
| PUT    | `/antrian/{id}/selesai`           | `QueueController@selesai`       | COMPLETED + advance ke station berikutnya     |
| PUT    | `/antrian/{id}/batal`             | `QueueController@batal`         | CANCELLED (no advance, log alasan)            |

### 5.2 Admisi (`/admisi`)

| Method | Path                                       | Action                                | Purpose                                  |
|--------|--------------------------------------------|---------------------------------------|------------------------------------------|
| GET    | `/admisi/dashboard`                        | `AdmisiController@dashboard`          | KPI admisi + status BPJS expired         |
| GET    | `/admisi/kunjungan`                        | `AdmisiController@indexKunjungan`     | List Visit + filter + paginate           |
| GET    | `/admisi/kunjungan/{id}`                   | `AdmisiController@showKunjungan`      | Detail Visit                             |
| PUT    | `/admisi/kunjungan/{id}/cancel`            | `AdmisiController@cancelKunjungan`    | Batalkan Visit                           |
| GET    | `/admisi/pasien`                           | `AdmisiController@cariPasien`         | Cari pasien existing (keyword)           |
| POST   | `/admisi/pasien`                           | `AdmisiController@storePasien`        | Daftar pasien baru                       |
| GET    | `/admisi/pasien/{id}`                      | `AdmisiController@showPasien`         | Detail pasien                            |
| PUT    | `/admisi/pasien/{id}`                      | `AdmisiController@updatePasien`       | Update biodata pasien                    |
| POST   | `/admisi/daftar`                           | `AdmisiController@daftarKunjungan`    | Buat Visit baru (pasien existing/baru)   |
| GET    | `/admisi/antrian`                          | `AdmisiController@indexAntrian`       | Antrian admisi hari ini                  |
| POST   | `/admisi/antrian`                          | `AdmisiController@createAntrian`      | Tambah ke antrian (manual)               |
| PUT    | `/admisi/antrian/{id}/panggil`             | `AdmisiController@panggilAntrian`     | Panggil antrian → broadcast Reverb       |
| PUT    | `/admisi/antrian/{id}/selesai`             | `AdmisiController@selesaiAntrian`     | Mark selesai → advance ke stasiun next   |
| GET    | `/admisi/jadwal-dokter`                    | `AdmisiController@indexJadwalDokter`  | List jadwal dokter                       |
| POST   | `/admisi/jadwal-dokter`                    | `AdmisiController@storeJadwalDokter`  | Buat jadwal dokter                       |
| PUT    | `/admisi/jadwal-dokter/{id}`               | `AdmisiController@updateJadwalDokter` | Edit jadwal                              |
| DELETE | `/admisi/jadwal-dokter/{id}`               | `AdmisiController@destroyJadwalDokter`| Hapus jadwal                             |
| POST   | `/admisi/bpjs/cek-peserta`                 | `AdmisiController@bpjsCekPeserta`     | Cek status peserta BPJS (VClaim)         |
| POST   | `/admisi/bpjs/generate-sep`                | `AdmisiController@bpjsGenerateSep`    | Generate Surat Eligibilitas Pasien (SEP) |
| POST   | `/admisi/bpjs/cancel-sep`                  | `AdmisiController@bpjsCancelSep`      | Batalkan SEP                             |
| POST   | `/admisi/bpjs/cek-rujukan`                 | `AdmisiController@bpjsCekRujukan`     | Validasi rujukan BPJS                    |
| POST   | `/admisi/bpjs/cek-surat-kontrol`           | `AdmisiController@bpjsCekSuratKontrol`| Validasi surat kontrol                   |
| POST   | `/admisi/bpjs/validasi-booking`            | `AdmisiController@bpjsValidasiBooking`| Validasi booking antrean BPJS            |

### 5.3 Perawat / Triase (`/perawat`)

| Method | Path                                                  | Action                                  | Purpose                                |
|--------|-------------------------------------------------------|-----------------------------------------|----------------------------------------|
| GET    | `/perawat/antrian`                                    | `PerawatController@indexAntrian`        | Antrian triase aktif                   |
| PUT    | `/perawat/antrian/{id}/panggil`                       | `PerawatController@panggilAntrian`      | Panggil pasien                         |
| PUT    | `/perawat/antrian/{id}/mulai`                         | `PerawatController@mulaiAntrian`        | CALLED → IN_PROGRESS                   |
| PUT    | `/perawat/antrian/{id}/selesai`                       | `PerawatController@selesaiAntrian`      | Selesai TR — gate: asesmen wajib finalize |
| PUT    | `/perawat/antrian/{id}/lewati`                        | `PerawatController@lewatiAntrian`       | Skip pasien                            |
| GET    | `/perawat/kunjungan/{visitId}`                        | `PerawatController@showKunjungan`       | Detail kunjungan + status asesmen      |
| GET    | `/perawat/kunjungan/{visitId}/status-parallel`        | `PerawatController@statusParallel`      | Cek parallel-flow (refraksi+triase)    |
| GET    | `/perawat/asesmen/{visitId}`                          | `PerawatController@showAsesmen`         | Tarik NurseAssessment existing         |
| POST   | `/perawat/asesmen`                                    | `PerawatController@storeAsesmen`        | Buat asesmen baru                      |
| PUT    | `/perawat/asesmen/{id}`                               | `PerawatController@updateAsesmen`       | Update asesmen (draft)                 |
| POST   | `/perawat/asesmen/{id}/finalize`                      | `PerawatController@finalizeAsesmen`     | Lock asesmen — irreversible            |
| GET    | `/perawat/pasien/{patientId}/vital-history`           | `PerawatController@vitalHistory`        | Tren TTV pasien                        |
| GET    | `/perawat/pasien/{patientId}/rekam-medis`             | `PerawatController@rekamMedisPasien`    | Vital history + dokumen pasien         |
| GET    | `/perawat/dokumen/{documentId}`                       | `PerawatController@showDokumen`         | Lihat dokumen (read-only utk perawat)  |

### 5.4 Refraksionis (`/refraksi`)

| Method | Path                                                 | Action                                   | Purpose                              |
|--------|------------------------------------------------------|------------------------------------------|--------------------------------------|
| GET    | `/refraksi/antrian`                                  | `RefraksiController@indexAntrian`        | Antrian refraksi                     |
| PUT    | `/refraksi/antrian/{id}/panggil`                     | `RefraksiController@panggilAntrian`      | Panggil pasien                       |
| PUT    | `/refraksi/antrian/{id}/mulai`                       | `RefraksiController@mulaiAntrian`        | CALLED → IN_PROGRESS                 |
| PUT    | `/refraksi/antrian/{id}/selesai`                     | `RefraksiController@selesaiAntrian`      | Selesai — gate: record refraksi wajib finalize |
| GET    | `/refraksi/pemeriksaan/{visitId}`                    | `RefraksiController@showPemeriksaan`     | RefractionRecord existing            |
| POST   | `/refraksi/pemeriksaan`                              | `RefraksiController@storePemeriksaan`    | Buat record refraksi                 |
| PUT    | `/refraksi/pemeriksaan/{id}`                         | `RefraksiController@updatePemeriksaan`   | Update draft                         |
| POST   | `/refraksi/pemeriksaan/{id}/finalize`                | `RefraksiController@finalizePemeriksaan` | Lock record refraksi                 |
| GET    | `/refraksi/resep-kacamata/{refractionId}`            | `RefraksiController@showResepKacamata`   | Tarik resep kacamata                 |
| POST   | `/refraksi/resep-kacamata`                           | `RefraksiController@storeResepKacamata`  | Tulis resep kacamata                 |
| PUT    | `/refraksi/resep-kacamata/{id}`                      | `RefraksiController@updateResepKacamata` | Edit resep                           |
| GET    | `/refraksi/iol-rekomendasi/{visitId}`                | `RefraksiController@showIolRekomendasi`  | Rekomendasi IOL dari biometri        |
| POST   | `/refraksi/iol-rekomendasi`                          | `RefraksiController@storeIolRekomendasi` | Input rekomendasi IOL                |
| PUT    | `/refraksi/iol-rekomendasi/{id}`                     | `RefraksiController@updateIolRekomendasi`| Edit rekomendasi IOL                 |
| GET    | `/refraksi/kunjungan/{visitId}`                      | `RefraksiController@showKunjungan`       | Detail kunjungan + rekam refraksi    |
| GET    | `/refraksi/kunjungan/{visitId}/status-parallel`      | `RefraksiController@statusParallel`      | Cek status triase                    |
| GET    | `/refraksi/pasien/{patientId}/riwayat`               | `RefraksiController@riwayatRefraksi`     | Riwayat refraksi pasien              |

### 5.5 Dokter (`/dokter`)

Workflow EMR dokter dibagi jadi 4 tab (anamnese, segmen, tindakan, SOAP).
Tab2 = anamnese + segmen anterior/posterior + slitlamp. Tab3 = tindakan +
resep + order penunjang. Tab4 = SOAP + ICD + planning.

| Method | Path                                                 | Action                                   | Purpose                              |
|--------|------------------------------------------------------|------------------------------------------|--------------------------------------|
| GET    | `/dokter/antrian`                                    | `DokterController@indexAntrian`          | Antrian pasien di poli dokter        |
| PUT    | `/dokter/antrian/{id}/panggil`                       | `DokterController@panggilAntrian`        | Panggil pasien                       |
| PUT    | `/dokter/antrian/{id}/selesai`                       | `DokterController@selesaiAntrian`        | Selesai pemeriksaan dokter           |
| GET    | `/dokter/kunjungan/{visitId}`                        | `DokterController@showKunjungan`         | Overview kunjungan (semua tab)       |
| GET    | `/dokter/kunjungan/{visitId}/tab2`                   | `DokterController@showTab2`              | Anamnese + segmen + slitlamp         |
| POST   | `/dokter/kunjungan/{visitId}/tab2`                   | `DokterController@storeTab2`             | Simpan tab2                          |
| PUT    | `/dokter/kunjungan/{visitId}/tab2`                   | `DokterController@updateTab2`            | Update tab2                          |
| GET    | `/dokter/kunjungan/{visitId}/tab4`                   | `DokterController@showTab4`              | SOAP + ICD + planning                |
| POST   | `/dokter/kunjungan/{visitId}/tab4`                   | `DokterController@storeTab4`             | Simpan tab4                          |
| PUT    | `/dokter/kunjungan/{visitId}/tab4`                   | `DokterController@updateTab4`            | Update tab4                          |
| POST   | `/dokter/kunjungan/{visitId}/finalize`               | `DokterController@finalizeKunjungan`     | Lock pemeriksaan dokter              |
| POST   | `/dokter/kunjungan/{visitId}/follow-up`              | `DokterController@storeFollowUp`         | Set kontrol ulang                    |
| PUT    | `/dokter/kunjungan/{visitId}/follow-up`              | `DokterController@updateFollowUp`        | Update kontrol ulang                 |
| DELETE | `/dokter/kunjungan/{visitId}/follow-up`              | `DokterController@deleteFollowUp`        | Hapus follow-up                      |
| GET    | `/dokter/kunjungan/{visitId}/tindakan`               | `DokterController@indexTindakan`         | List VisitService (tindakan)         |
| POST   | `/dokter/kunjungan/{visitId}/tindakan`               | `DokterController@storeTindakan`         | Tambah tindakan                      |
| DELETE | `/dokter/tindakan/{id}`                              | `DokterController@deleteTindakan`        | Hapus tindakan                       |
| GET    | `/dokter/kunjungan/{visitId}/resep`                  | `DokterController@indexResep`            | List resep obat                      |
| POST   | `/dokter/kunjungan/{visitId}/resep`                  | `DokterController@storeResep`            | Tulis resep                          |
| GET    | `/dokter/kunjungan/{visitId}/order-penunjang`        | `DokterController@indexOrderPenunjang`   | List order penunjang                 |
| POST   | `/dokter/order-penunjang`                            | `DokterController@storeOrderPenunjang`   | Order pemeriksaan penunjang          |
| DELETE | `/dokter/order-penunjang/{id}`                       | `DokterController@cancelOrderPenunjang`  | Cancel order                         |
| GET    | `/dokter/kunjungan/{visitId}/hasil-penunjang`        | `DokterController@indexHasilPenunjang`   | Lihat hasil penunjang (read-only)    |
| GET    | `/dokter/kunjungan/{visitId}/iol-rekomendasi`        | `DokterController@showIolRekomendasi`    | Read rekomendasi IOL                 |
| GET    | `/dokter/kunjungan/{visitId}/resume-medis`           | `DokterController@showResumeMedis`       | Lihat resume medis                   |
| POST   | `/dokter/kunjungan/{visitId}/resume-medis`           | `DokterController@generateResumeMedis`   | Generate resume otomatis             |
| PUT    | `/dokter/resume-medis/{id}`                          | `DokterController@updateResumeMedis`     | Edit resume                          |
| POST   | `/dokter/resume-medis/{id}/finalize`                 | `DokterController@finalizeResumeMedis`   | Lock resume                          |
| POST   | `/dokter/rujukan-keluar`                             | `DokterController@storeRujukanKeluar`    | Buat rujukan keluar BPJS             |
| GET    | `/dokter/jadwal-bedah`                               | `DokterController@indexJadwalBedah`      | List jadwal operasi                  |
| POST   | `/dokter/jadwal-bedah`                               | `DokterController@storeJadwalBedah`      | Buat jadwal operasi (jika BEDAH)     |
| GET    | `/dokter/notifikasi`                                 | `DokterController@indexNotifikasi`       | Inbox TTD dokter                     |
| PUT    | `/dokter/notifikasi/{id}/baca`                       | `DokterController@bacaNotifikasi`        | Mark read                            |
| POST   | `/dokter/dokumen/{id}/tanda-tangan`                  | `DokterController@tandaTanganDokumen`    | TTD dokumen dengan PIN               |
| POST   | `/dokter/dokumen/{id}/tolak`                         | `DokterController@tolakDokumen`          | Tolak permintaan TTD                 |

### 5.6 Penunjang (`/penunjang`)

| Method | Path                                          | Action                                 | Purpose                            |
|--------|-----------------------------------------------|----------------------------------------|------------------------------------|
| GET    | `/penunjang/antrian`                          | `PenunjangController@indexAntrian`     | Antrian penunjang                  |
| PUT    | `/penunjang/antrian/{id}/panggil`             | `PenunjangController@panggilAntrian`   | Panggil pasien                     |
| PUT    | `/penunjang/antrian/{id}/selesai`             | `PenunjangController@selesaiAntrian`   | Selesai                            |
| GET    | `/penunjang/order`                            | `PenunjangController@indexOrder`       | List order penunjang aktif         |
| POST   | `/penunjang/order`                            | `PenunjangController@storeOrder`       | Buat order baru                    |
| GET    | `/penunjang/order/{id}`                       | `PenunjangController@showOrder`        | Detail order                       |
| PUT    | `/penunjang/order/{id}/proses`                | `PenunjangController@prosesOrder`      | Mulai pemeriksaan                  |
| PUT    | `/penunjang/order/{id}/cancel`                | `PenunjangController@cancelOrder`      | Cancel order                       |
| GET    | `/penunjang/hasil/{orderId}`                  | `PenunjangController@showHasil`        | Tarik hasil                        |
| POST   | `/penunjang/hasil`                            | `PenunjangController@storeHasil`       | Input hasil                        |
| PUT    | `/penunjang/hasil/{id}`                       | `PenunjangController@updateHasil`      | Update hasil                       |
| POST   | `/penunjang/hasil/{id}/selesai`               | `PenunjangController@selesaiHasil`     | Finalize hasil                     |
| GET    | `/penunjang/iol-rekomendasi/{visitId}`        | `PenunjangController@showIolRekomendasi` | Tarik rekomendasi IOL (biometri) |
| POST   | `/penunjang/iol-rekomendasi`                  | `PenunjangController@storeIolRekomendasi`| Input rekomendasi IOL            |
| PUT    | `/penunjang/iol-rekomendasi/{id}`             | `PenunjangController@updateIolRekomendasi`| Edit rekomendasi IOL            |

### 5.7 Bedah (`/bedah`)

| Method | Path                                          | Action                              | Purpose                              |
|--------|-----------------------------------------------|-------------------------------------|--------------------------------------|
| GET    | `/bedah/antrian`                              | `BedahController@indexAntrian`      | Antrian operasi hari ini             |
| PUT    | `/bedah/antrian/{id}/panggil`                 | `BedahController@panggilAntrian`    | Panggil pasien ke ruang ops          |
| PUT    | `/bedah/antrian/{id}/selesai`                 | `BedahController@selesaiAntrian`    | Selesai bedah → advance ke KASIR     |
| GET    | `/bedah/jadwal`                               | `BedahController@indexJadwal`       | List jadwal operasi                  |
| GET    | `/bedah/jadwal/{id}`                          | `BedahController@showJadwal`        | Detail jadwal                        |
| POST   | `/bedah/jadwal`                               | `BedahController@storeJadwal`       | Buat jadwal                          |
| PUT    | `/bedah/jadwal/{id}`                          | `BedahController@updateJadwal`      | Edit jadwal                          |
| DELETE | `/bedah/jadwal/{id}`                          | `BedahController@deleteJadwal`      | Hapus jadwal                         |
| PUT    | `/bedah/jadwal/{id}/mulai`                    | `BedahController@mulaiOperasi`      | Mulai operasi                        |
| PUT    | `/bedah/jadwal/{id}/selesai`                  | `BedahController@selesaiOperasi`    | Selesai operasi                      |
| GET    | `/bedah/request`                              | `BedahController@indexRequest`      | List surgery request (BHP+IOL)       |
| GET    | `/bedah/request/{id}`                         | `BedahController@showRequest`       | Detail request                       |
| POST   | `/bedah/request`                              | `BedahController@storeRequest`      | Buat request BHP/IOL                 |
| PUT    | `/bedah/request/{id}`                         | `BedahController@updateRequest`     | Edit request                         |
| PUT    | `/bedah/request/{id}/kirim`                   | `BedahController@kirimRequest`      | Kirim request ke farmasi             |
| PUT    | `/bedah/request/{id}/terima`                  | `BedahController@terimaRequest`     | Terima BHP/IOL dari farmasi          |
| GET    | `/bedah/record/{scheduleId}`                  | `BedahController@showRecord`        | Tarik laporan operasi                |
| POST   | `/bedah/record`                               | `BedahController@storeRecord`       | Buat laporan operasi                 |
| PUT    | `/bedah/record/{id}`                          | `BedahController@updateRecord`      | Edit laporan operasi                 |
| PUT    | `/bedah/record/{id}/post-op`                  | `BedahController@storePostOp`       | Catat post-op                        |
| POST   | `/bedah/record/{id}/finalize`                 | `BedahController@finalizeRecord`    | Lock laporan operasi                 |
| POST   | `/bedah/iol-usage`                            | `BedahController@storeIolUsage`     | Catat pemakaian IOL                  |
| PUT    | `/bedah/iol-usage/{id}`                       | `BedahController@updateIolUsage`    | Edit pemakaian IOL                   |

### 5.8 Farmasi (`/farmasi`)

| Method | Path                                              | Action                                   | Purpose                          |
|--------|---------------------------------------------------|------------------------------------------|----------------------------------|
| GET    | `/farmasi/antrian`                                | `FarmasiController@indexAntrian`         | Antrian farmasi                  |
| PUT    | `/farmasi/antrian/{id}/panggil`                   | `FarmasiController@panggilAntrian`       | Panggil pasien                   |
| PUT    | `/farmasi/antrian/{id}/selesai`                   | `FarmasiController@selesaiAntrian`       | Selesai                          |
| GET    | `/farmasi/resep`                                  | `FarmasiController@indexResep`           | List resep masuk                 |
| GET    | `/farmasi/resep/{id}`                             | `FarmasiController@showResep`            | Detail resep                     |
| PUT    | `/farmasi/resep/{id}/dispensing`                  | `FarmasiController@startDispensing`      | Mulai dispensing                 |
| PUT    | `/farmasi/resep/{id}/selesai`                     | `FarmasiController@selesaiDispensing`    | Selesai dispensing               |
| PUT    | `/farmasi/resep/{id}/cancel`                      | `FarmasiController@cancelResep`          | Cancel resep                     |
| POST   | `/farmasi/resep/{resepId}/item`                   | `FarmasiController@storeItemDispensing`  | Catat item yang diberikan        |
| PUT    | `/farmasi/resep-item/{id}`                        | `FarmasiController@updateItemDispensing` | Edit item                        |
| DELETE | `/farmasi/resep-item/{id}`                        | `FarmasiController@deleteItemDispensing` | Hapus item                       |
| GET    | `/farmasi/surgery-request`                        | `FarmasiController@indexSurgeryRequest`  | List request dari bedah          |
| GET    | `/farmasi/surgery-request/{id}`                   | `FarmasiController@showSurgeryRequest`   | Detail request                   |
| PUT    | `/farmasi/surgery-request/{id}/siapkan`           | `FarmasiController@siapkanSurgeryRequest`| Siapkan BHP/IOL                  |
| POST   | `/farmasi/surgery-request/{id}/kirim`             | `FarmasiController@kirimSurgeryRequest`  | Kirim ke ruang ops               |
| POST   | `/farmasi/surgery-request/{id}/assign-iol`        | `FarmasiController@assignIol`            | Assign IOL spesifik              |
| GET    | `/farmasi/stok/obat`                              | `FarmasiController@indexStokObat`        | List stok obat                   |
| GET    | `/farmasi/stok/obat/{id}`                         | `FarmasiController@showStokObat`         | Detail stok                      |
| PUT    | `/farmasi/stok/obat/{id}`                         | `FarmasiController@updateStokObat`       | Update stok                      |
| GET    | `/farmasi/stok/bhp`                               | `FarmasiController@indexStokBhp`         | List stok BHP                    |
| PUT    | `/farmasi/stok/bhp/{id}`                          | `FarmasiController@updateStokBhp`        | Update stok BHP                  |
| GET    | `/farmasi/stok/iol`                               | `FarmasiController@indexStokIol`         | List stok IOL                    |
| PUT    | `/farmasi/stok/iol/{id}`                          | `FarmasiController@updateStokIol`        | Update stok IOL                  |
| GET    | `/farmasi/stok/alert`                             | `FarmasiController@stokAlert`            | Item di bawah safety stock       |

### 5.9 Kasir (`/kasir`)

| Method | Path                                          | Action                              | Purpose                              |
|--------|-----------------------------------------------|-------------------------------------|--------------------------------------|
| GET    | `/kasir/antrian`                              | `KasirController@indexAntrian`      | Antrian kasir                        |
| PUT    | `/kasir/antrian/{id}/panggil`                 | `KasirController@panggilAntrian`    | Panggil pasien                       |
| PUT    | `/kasir/antrian/{id}/selesai`                 | `KasirController@selesaiAntrian`    | Selesai                              |
| GET    | `/kasir/invoice`                              | `KasirController@indexInvoice`      | List invoice                         |
| GET    | `/kasir/invoice/{visitId}`                    | `KasirController@showInvoice`       | Invoice by visit                     |
| POST   | `/kasir/invoice/{visitId}/generate`           | `KasirController@generateInvoice`   | Auto-generate dari tindakan/resep    |
| PUT    | `/kasir/invoice/{id}`                         | `KasirController@updateInvoice`     | Edit invoice                         |
| POST   | `/kasir/invoice/{id}/finalize`                | `KasirController@finalizeInvoice`   | Lock invoice                         |
| POST   | `/kasir/invoice/{id}/bayar`                   | `KasirController@bayarInvoice`      | Catat pembayaran                     |
| POST   | `/kasir/invoice/{id}/cancel`                  | `KasirController@cancelInvoice`     | Batalkan invoice                     |
| GET    | `/kasir/invoice/{id}/cetak`                   | `KasirController@cetakInvoice`      | Cetak (PDF)                          |
| POST   | `/kasir/invoice/{invoiceId}/item`             | `KasirController@storeItemInvoice`  | Tambah item invoice manual           |
| PUT    | `/kasir/invoice-item/{id}`                    | `KasirController@updateItemInvoice` | Edit item                            |
| DELETE | `/kasir/invoice-item/{id}`                    | `KasirController@deleteItemInvoice` | Hapus item                           |
| GET    | `/kasir/cob/{visitId}`                        | `KasirController@showCob`           | Coordination of Benefit              |
| PUT    | `/kasir/cob/{visitId}`                        | `KasirController@updateCob`         | Update COB                           |
| PUT    | `/kasir/watermark`                            | `KasirController@updateWatermark`   | Watermark global (`clinic_profiles`) |
| GET    | `/kasir/laporan`                              | `KasirController@laporanHarian`     | Laporan harian                       |
| GET    | `/kasir/laporan/rekap`                        | `KasirController@laporanRekap`      | Laporan rekap                        |

### 5.10 Klaim BPJS (`/klaim`)

Status flow: `DRAFT → REVIEW → VERIFIED → SUBMITTED → SELESAI/DITOLAK`.
Submit irreversible — wajib konfirmasi modal di frontend (lihat `KlaimView`).

| Method | Path                                  | Action                          | Purpose                                |
|--------|---------------------------------------|---------------------------------|----------------------------------------|
| GET    | `/klaim/`                             | `KlaimController@index`         | List klaim + filter status             |
| GET    | `/klaim/{id}`                         | `KlaimController@show`          | Detail klaim                           |
| POST   | `/klaim/{id}/grouping`                | `KlaimController@runGrouping`   | Jalankan grouping INA-CBGs             |
| GET    | `/klaim/grouping-log/{klaimId}`       | `KlaimController@groupingLog`   | Log hasil grouping                     |
| PUT    | `/klaim/{id}/review`                  | `KlaimController@setReview`     | Pindah ke REVIEW                       |
| PUT    | `/klaim/{id}/verifikasi`              | `KlaimController@setVerifikasi` | Pindah ke VERIFIED (gate checklist)    |
| PUT    | `/klaim/{id}/reject`                  | `KlaimController@setReject`     | Tolak (catat alasan)                   |
| POST   | `/klaim/{id}/submit`                  | `KlaimController@submitKlaim`   | Submit ke VClaim — irreversible        |
| POST   | `/klaim/{id}/lupis`                   | `KlaimController@generateLupis` | Format data utilisasi (LUPIS)          |
| GET    | `/klaim/icare/monitoring`             | `KlaimController@icareMonitoring`| Monitoring iCare                      |
| GET    | `/klaim/{id}/audit-log`               | `KlaimController@auditLog`      | Audit log append-only                  |
| GET    | `/klaim/vclaim-log`                   | `KlaimController@vclaimpLog`    | Log call VClaim                        |

### 5.11 Rekam Medis (`/rekam-medis`)

| Method | Path                                              | Action                                  | Purpose                          |
|--------|---------------------------------------------------|-----------------------------------------|----------------------------------|
| GET    | `/rekam-medis/pasien/{patientId}`                 | `RekamMedisController@riwayatPasien`    | Riwayat pasien                   |
| GET    | `/rekam-medis/pasien/{patientId}/kunjungan`       | `RekamMedisController@indexKunjungan`   | List Visit pasien                |
| GET    | `/rekam-medis/dokumen`                            | `RekamMedisController@indexDokumen`     | List PatientDocument             |
| GET    | `/rekam-medis/dokumen/{id}`                       | `RekamMedisController@showDokumen`      | Detail dokumen                   |
| POST   | `/rekam-medis/dokumen`                            | `RekamMedisController@storeDokumen`     | Buat dokumen baru                |
| PUT    | `/rekam-medis/dokumen/{id}`                       | `RekamMedisController@updateDokumen`    | Edit dokumen (draft)             |
| POST   | `/rekam-medis/dokumen/{id}/submit`                | `RekamMedisController@submitDokumen`    | Submit → trigger notif TTD       |
| POST   | `/rekam-medis/dokumen/{id}/void`                  | `RekamMedisController@voidDokumen`      | Void dokumen                     |
| GET    | `/rekam-medis/dokumen/{id}/cetak`                 | `RekamMedisController@cetakDokumen`     | Cetak (PDF + QR verifikasi)      |
| POST   | `/rekam-medis/dokumen/{id}/resend-notif`          | `RekamMedisController@resendNotifDokumen`| Re-send notif TTD               |
| GET    | `/rekam-medis/verifikasi/{token}`                 | `RekamMedisController@verifikasiDokumen`| Verifikasi QR code               |
| GET    | `/rekam-medis/medical-record/{visitId}`           | `RekamMedisController@showMedicalRecord`| Generic form                     |
| POST   | `/rekam-medis/medical-record`                     | `RekamMedisController@storeMedicalRecord`| Buat record                     |
| PUT    | `/rekam-medis/medical-record/{id}`                | `RekamMedisController@updateMedicalRecord`| Update (auto-versioning)       |
| GET    | `/rekam-medis/medical-record/{id}/versions`       | `RekamMedisController@versionsMedicalRecord`| List versi                   |
| GET    | `/rekam-medis/notifikasi`                         | `RekamMedisController@indexNotifikasi`  | Notifikasi inbox                 |
| PUT    | `/rekam-medis/notifikasi/{id}/baca`               | `RekamMedisController@bacaNotifikasi`   | Mark read                        |

### 5.12 Dashboard (`/dashboard`)

| Method | Path                                  | Action                                | Purpose                            |
|--------|---------------------------------------|---------------------------------------|------------------------------------|
| GET    | `/dashboard/statistik`                | `DashboardController@statistik`       | Statistik utama                    |
| GET    | `/dashboard/kunjungan-hari-ini`       | `DashboardController@kunjunganHariIni`| Kunjungan hari ini                 |
| GET    | `/dashboard/antrian-aktif`            | `DashboardController@antrianAktif`    | Antrian aktif (semua stasiun)      |
| GET    | `/dashboard/pendapatan`               | `DashboardController@pendapatan`      | Pendapatan periode                 |
| GET    | `/dashboard/kunjungan-chart`          | `DashboardController@getVisitChart`   | Data chart kunjungan               |
| GET    | `/dashboard/diagnosis-stats`          | `DashboardController@getDiagnosisStats`| Statistik diagnosa                |
| GET    | `/dashboard/follow-up/hari-ini`       | `DashboardController@followUpHariIni` | Follow-up hari ini                 |
| GET    | `/dashboard/follow-up/minggu-ini`     | `DashboardController@followUpMingguIni`| Follow-up minggu ini              |
| GET    | `/dashboard/follow-up/statistik`      | `DashboardController@followUpStatistik`| Rollup bulanan                    |
| GET    | `/dashboard/stok-alert`               | `DashboardController@stokAlert`       | Alert stok                         |
| GET    | `/dashboard/bpjs-expired`             | `DashboardController@bpjsExpiredAlert`| BPJS expired                       |
| GET    | `/dashboard/satusehat-status`         | `DashboardController@satusehatStatus` | Status sync Satu Sehat             |
| GET    | `/dashboard/laporan/kunjungan`        | `DashboardController@laporanKunjungan`| Laporan kunjungan                  |
| GET    | `/dashboard/laporan/pendapatan`       | `DashboardController@laporanPendapatan`| Laporan pendapatan                |
| GET    | `/dashboard/laporan/klaim`            | `DashboardController@laporanKlaim`    | Laporan klaim                      |

### 5.13 Master Data (`/master`)

Modul CRUD murni — semua entitas master ada di sini. Pola identik untuk
setiap resource: GET (list) + POST + PUT/{id} + DELETE/{id}. Tarif (4 tipe)
juga support import/export CSV.

| Method | Path                                          | Action                                  | Purpose                          |
|--------|-----------------------------------------------|-----------------------------------------|----------------------------------|
| GET    | `/master/profil-klinik`                       | `MasterDataController@showProfilKlinik` | Profil klinik                    |
| PUT    | `/master/profil-klinik`                       | `MasterDataController@updateProfilKlinik`| Update profil                   |
| GET    | `/master/nomor-dokumen`                       | `MasterDataController@indexNomorDokumen`| Konfig nomor dokumen             |
| PUT    | `/master/nomor-dokumen/{id}`                  | `MasterDataController@updateNomorDokumen`| Update                          |
| GET    | `/master/roles`                               | `MasterDataController@indexRoles`       | List role                        |
| POST   | `/master/roles`                               | `MasterDataController@storeRole`        | Buat role                        |
| PUT    | `/master/roles/{id}`                          | `MasterDataController@updateRole`       | Edit role                        |
| DELETE | `/master/roles/{id}`                          | `MasterDataController@deleteRole`       | Hapus role                       |
| GET    | `/master/pegawai`                             | `MasterDataController@indexPegawai`     | List pegawai                     |
| GET    | `/master/pegawai/{id}`                        | `MasterDataController@showPegawai`      | Detail pegawai                   |
| POST   | `/master/pegawai`                             | `MasterDataController@storePegawai`     | Buat pegawai (+user)             |
| PUT    | `/master/pegawai/{id}`                        | `MasterDataController@updatePegawai`    | Edit pegawai                     |
| DELETE | `/master/pegawai/{id}`                        | `MasterDataController@deletePegawai`    | Hapus pegawai                    |
| PUT    | `/master/pegawai/{id}/reset-password`         | `MasterDataController@resetPasswordPegawai`| Reset password               |
| GET    | `/master/penjamin`                            | `MasterDataController@indexPenjamin`    | List penjamin (insurer)          |
| POST/PUT/DELETE | `/master/penjamin[/{id}]`            | `MasterDataController@*Penjamin`        | CRUD penjamin                    |
| CRUD   | `/master/tindakan[/{id}]`                     | `MasterDataController@*Tindakan`        | CRUD procedure                   |
| CRUD   | `/master/icd10[/{id}]`                        | `MasterDataController@*Icd10`           | CRUD ICD-10                      |
| CRUD   | `/master/icd9[/{id}]`                         | `MasterDataController@*Icd9`            | CRUD ICD-9                       |
| CRUD   | `/master/obat[/{id}]`                         | `MasterDataController@*Obat`            | CRUD medication                  |
| CRUD   | `/master/bhp[/{id}]`                          | `MasterDataController@*Bhp`             | CRUD BHP                         |
| CRUD   | `/master/iol[/{id}]`                          | `MasterDataController@*Iol`             | CRUD IOL                         |
| CRUD   | `/master/paket-bedah[/{id}]`                  | `MasterDataController@*PaketBedah`      | CRUD surgery package             |
| CRUD   | `/master/tarif/tindakan[/{id}]`               | `MasterDataController@*TarifTindakan`   | CRUD procedure tariff            |
| GET    | `/master/tarif/tindakan/export-csv`           | `MasterDataController@exportTarifCsv`   | Export CSV (type=tindakan)       |
| POST   | `/master/tarif/tindakan/import-csv`           | `MasterDataController@importTarifCsv`   | Import CSV                       |
| CRUD   | `/master/tarif/obat[/{id}]`                   | `MasterDataController@*TarifObat`       | CRUD medication tariff           |
| GET/POST | `/master/tarif/obat/{export-csv,import-csv}`| `MasterDataController@*TarifCsv`        | CSV obat                         |
| CRUD   | `/master/tarif/bhp[/{id}]`                    | `MasterDataController@*TarifBhp`        | CRUD BHP tariff                  |
| GET/POST | `/master/tarif/bhp/{export-csv,import-csv}` | `MasterDataController@*TarifCsv`        | CSV BHP                          |
| CRUD   | `/master/tarif/iol[/{id}]`                    | `MasterDataController@*TarifIol`        | CRUD IOL tariff                  |
| GET/POST | `/master/tarif/iol/{export-csv,import-csv}` | `MasterDataController@*TarifCsv`        | CSV IOL                          |
| CRUD   | `/master/jenis-dokumen[/{id}]`                | `MasterDataController@*JenisDokumen`    | CRUD document types              |
| GET/CRUD| `/master/template-dokumen[/{id}]`            | `MasterDataController@*TemplateDokumen` | CRUD template dokumen            |
| GET/PUT| `/master/stasiun-dokumen[/{id}]`              | `MasterDataController@*StasiunDokumen`  | Mapping stasiun → dokumen        |

### 5.14 Integrasi (`/integrasi`)

| Method | Path                                          | Action                                | Purpose                          |
|--------|-----------------------------------------------|---------------------------------------|----------------------------------|
| GET    | `/integrasi/status`                           | `IntegrasiController@statusSemua`     | Status semua sistem integrasi    |
| POST   | `/integrasi/test/{system}`                    | `IntegrasiController@testKoneksi`     | Test koneksi 1 sistem            |
| GET    | `/integrasi/config`                           | `IntegrasiController@indexConfig`     | List konfig                      |
| PUT    | `/integrasi/config/{id}`                      | `IntegrasiController@updateConfig`    | Update konfig                    |
| GET    | `/integrasi/bpjs/vclaim-log`                  | `IntegrasiController@vclaimpLog`      | Log VClaim                       |
| GET    | `/integrasi/bpjs/vclaim-log/{id}`             | `IntegrasiController@showVclaimpLog`  | Detail log                       |
| GET    | `/integrasi/bpjs/antrean-log`                 | `IntegrasiController@antreanLog`      | Log Antrean                      |
| GET    | `/integrasi/bpjs/icare-log`                   | `IntegrasiController@icareLog`        | Log iCare                        |
| GET    | `/integrasi/bpjs/inacbgs-log`                 | `IntegrasiController@inacbgsLog`      | Log INA-CBGs                     |
| GET    | `/integrasi/bpjs/rujukan-masuk`               | `IntegrasiController@indexRujukanMasuk`| List rujukan masuk              |
| GET    | `/integrasi/bpjs/rujukan-masuk/{id}`          | `IntegrasiController@showRujukanMasuk`| Detail rujukan masuk             |
| GET    | `/integrasi/bpjs/rujukan-keluar`              | `IntegrasiController@indexRujukanKeluar`| List rujukan keluar            |
| GET    | `/integrasi/bpjs/rujukan-keluar/{id}`         | `IntegrasiController@showRujukanKeluar`| Detail rujukan keluar           |
| GET    | `/integrasi/bpjs/surat-kontrol`               | `IntegrasiController@indexSuratKontrol`| List surat kontrol              |
| GET    | `/integrasi/bpjs/surat-kontrol/{id}`          | `IntegrasiController@showSuratKontrol`| Detail surat kontrol             |
| POST   | `/integrasi/bpjs/surat-kontrol/{id}/submit`   | `IntegrasiController@submitSuratKontrol`| Submit surat kontrol           |
| GET    | `/integrasi/satusehat/sync-log`               | `IntegrasiController@satusehatSyncLog`| Log sync Satu Sehat              |
| GET    | `/integrasi/satusehat/sync-log/{id}`          | `IntegrasiController@showSatusehatSyncLog`| Detail log                   |
| GET    | `/integrasi/satusehat/resource-log`           | `IntegrasiController@satusehatResourceLog`| Log resource                 |
| POST   | `/integrasi/satusehat/sync-manual`            | `IntegrasiController@satusehatSyncManual`| Trigger manual sync           |
| POST   | `/integrasi/satusehat/retry/{logId}`          | `IntegrasiController@satusehatRetry`  | Retry sync 1 resource            |

---

## 6. Frontend Components

Frontend adalah SPA Vue 3 + Vite. Pola: 1 view = 1 modul HIS, 1 store Pinia
per-domain. **Tidak ada UI library eksternal** — semua komponen custom
dengan CSS scoped + design token.

### 6.1 Views (`arumed-frontend/src/views/`)

| File                     | Purpose                                                            |
|--------------------------|--------------------------------------------------------------------|
| `LoginView.vue`          | Form login JWT (username + password)                               |
| `DashboardView.vue`      | KPI card + chart kunjungan + follow-up + alert stok                |
| `AdmisiView.vue`         | Pendaftaran pasien, panel antrian, jadwal dokter                   |
| `DokterView.vue`         | RME dokter (tab2/3/4) + inbox TTD                                  |
| `PerawatView.vue`        | Antrian triase + form NurseAssessment + vital signs                |
| `RefraksionisView.vue`   | Input pemeriksaan refraksi + resep kacamata                        |
| `RekamMedisView.vue`     | Search rekam medis + dokumen archive                               |
| `PenunjangView.vue`      | Antrian penunjang + input hasil                                    |
| `FarmasiView.vue`        | Dispensing resep + stok                                            |
| `BedahView.vue`          | Antrian bedah + jadwal operasi + paket                             |
| `KasirView.vue`          | Generate invoice + bayar                                           |
| `KlaimView.vue`          | Workflow klaim BPJS (panel kiri 310px + kanan 3 tab inner)         |
| `MasterDataView.vue`     | Admin: procedure, tarif, insurer, ICD                              |
| `DataPenggunaView.vue`   | Kepegawaian & RBAC                                                 |
| `AntreanTVView.vue`      | **Public** — display antrean (fullscreen, layout blank, no auth)   |
| `AnjunganView.vue`       | **Public** — kiosk self-service registration                       |
| `StubView.vue`           | Placeholder untuk route Pengaturan (belum dibangun)                |

### 6.2 Layout & Komponen Bersama

- `layouts/AppLayout.vue` — shell utama: sidebar kiri + topbar atas +
  `<RouterView/>`. Dipakai oleh semua route terautentikasi.
- `components/layout/AppSidebar.vue` — nav vertikal collapsible, badge
  jumlah antrian per modul, tombol logout.
- `components/layout/AppTopbar.vue` — profil user + dropdown notifikasi.
- `components/layout/BrandMark.vue` — logo SVG klinik (size prop).

### 6.3 Vue Router (`src/router/index.js`)

| Path             | Name           | Component         | Meta                          | Auth |
|------------------|----------------|-------------------|-------------------------------|------|
| `/login`         | `login`        | `LoginView`       | `layout: 'blank'`             | NO   |
| `/antrean-tv`    | `antrean-tv`   | `AntreanTVView`   | `title: 'Antrean TV'`         | NO   |
| `/anjungan`      | `anjungan`     | `AnjunganView`    | `title: 'Anjungan Mandiri'`   | NO   |
| `/admisi`        | `admisi`       | `AdmisiView`      | `title: 'Admisi & Pendaftaran'`| YES |
| `/dokter`        | `dokter`       | `DokterView`      | `title: 'RME Dokter'`         | YES  |
| `/dashboard`     | `dashboard`    | `DashboardView`   | `title: 'Dashboard'`          | YES  |
| `/perawat`       | `perawat`      | `PerawatView`     | `title: 'Triase / Perawat'`   | YES  |
| `/refraksionis`  | `refraksionis` | `RefraksionisView`| `title: 'Refraksionis'`       | YES  |
| `/rekam-medis`   | `rekam-medis`  | `RekamMedisView`  | `title: 'Rekam Medis'`        | YES  |
| `/penunjang`     | `penunjang`    | `PenunjangView`   | `title: 'Pemeriksaan Penunjang'`| YES|
| `/bedah`         | `bedah`        | `BedahView`       | `title: 'Bedah'`              | YES  |
| `/farmasi`       | `farmasi`      | `FarmasiView`     | `title: 'Farmasi'`            | YES  |
| `/kasir`         | `kasir`        | `KasirView`       | `title: 'Kasir & Billing'`    | YES  |
| `/bpjs`          | `bpjs`         | `KlaimView`       | `title: 'BPJS & Klaim'`       | YES  |
| `/master-data`   | `master-data`  | `MasterDataView`  | `title: 'Master Data'`        | YES  |
| `/DataPengguna`  | `DataPengguna` | `DataPenggunaView`| `title: 'Kepegawaian & RBAC'` | YES  |
| `/pengaturan`    | `pengaturan`   | `StubView`        | `title: 'Pengaturan'`         | YES  |

**Auth guard** (`router.beforeEach`): jika `!useAuthStore().isAuthenticated`
dan route bukan public (`['login', 'antrean-tv', 'anjungan']`) → redirect ke
`/login`.

### 6.4 Pinia Stores (`src/stores/`)

| Store               | Tanggung jawab                                                                                                |
|---------------------|----------------------------------------------------------------------------------------------------------------|
| `authStore`         | Token JWT + user profile, persist ke `localStorage`, listener `arumed:session-expired` untuk auto-logout       |
| `visitStore`        | List Visit + filter + pagination + daftar/cancel + cari pasien                                                 |
| `queueStore`        | Generic queue per-station, polling 30s (fallback jika WS mati)                                                  |
| `admisiStore`       | Dashboard KPI admisi, antrian, jadwal dokter; WebSocket Reverb dengan fallback polling                          |
| `perawatStore`      | Antrian triase, asesmen (draft + finalize), vital history, dokumen; WebSocket Reverb                            |
| `notificationStore` | Inbox TTD dokter — sign dokumen dengan PIN, tolak dengan alasan, polling 60s                                    |
| `followUpStore`     | Kontrol ulang per hari/minggu + rollup bulanan, format helper                                                   |
| `auth.js`           | Re-export shim ke `authStore` (legacy import path)                                                              |
| `queue.js`          | Legacy store, kemungkinan tidak terpakai                                                                        |

### 6.5 HTTP Layer (`src/services/api.js`)

```js
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  timeout: 15000,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

// Request: inject Bearer token
api.interceptors.request.use((cfg) => {
  const token = localStorage.getItem('auth_token')
  if (token) cfg.headers.Authorization = `Bearer ${token}`
  return cfg
})

// Response: dispatch custom event berdasarkan status
api.interceptors.response.use(null, (err) => {
  const s = err.response?.status
  if (s === 401)  window.dispatchEvent(new CustomEvent('arumed:session-expired'))
  if (s === 403)  window.dispatchEvent(new CustomEvent('arumed:forbidden', { detail: err }))
  if (s >= 500)   window.dispatchEvent(new CustomEvent('arumed:server-error', { detail: err }))
  return Promise.reject(err)
})
```

Helper grouping per-domain:
`authApi`, `queueApi`, `visitApi`, `notifApi`, `followUpApi`, `perawatApi`,
`dashboardApi`, `masterApi`, `admisiApi` — masing-masing object dengan method
yang memetakan ke endpoint backend (lihat Section 5).

### 6.6 Build — `vite.config.js`

```js
export default defineConfig({
  plugins: [vue()],
  resolve: { alias: { '@': './src' } },     // import @/router, @/stores, dll
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',     // Laravel dev server
        changeOrigin: true,
        secure: false,
      },
    },
  },
})
```

### 6.7 Entry Point — `src/main.js`

```js
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/styles/tokens.css'   // design tokens (warna, spacing)
import './assets/styles/base.css'     // reset + scrollbar + animasi

createApp(App).use(createPinia()).use(router).mount('#app')
```

---

## 7. Database Tables

Backend memakai 67 migration. Default connection bisa **SQLite** (otomatis
dibuat oleh `composer setup`) atau **PostgreSQL** untuk production (per PRD
& memory project). Lihat `backend/config/database.php` untuk override via
`.env`.

Tabel dikelompokkan per domain — sejajar dengan grouping model di Section 4.3.

### 7.1 Pondasi Laravel
| Tabel    | Migration                                  | Purpose                         |
|----------|--------------------------------------------|---------------------------------|
| `users`  | `0001_01_01_000000_create_users_table`     | Akun login (dimodif kemudian)   |
| `cache`  | `0001_01_01_000001_create_cache_table`     | Cache driver `database`         |
| `jobs`   | `0001_01_01_000002_create_jobs_table`      | Queue driver `database`         |

### 7.2 Core / RBAC (2026_05_01)
| Tabel              | Migration                                       | Purpose                |
|--------------------|-------------------------------------------------|------------------------|
| `roles`            | `2026_05_01_000001_create_roles_table`          | Role definisi          |
| `employees`        | `2026_05_01_000002_create_employees_table`      | Master pegawai         |
| `users`            | `2026_05_01_000003_create_users_table`          | Re-create users (relasi `employee_id`, `role_id`) |
| `clinic_profiles`  | `2026_05_01_000004_create_clinic_profiles_table`| Profil klinik singleton |

### 7.3 Master Data (2026_05_02)
| Tabel               | Migration                                            | Purpose            |
|---------------------|------------------------------------------------------|--------------------|
| `insurers`          | `2026_05_02_000001_create_insurers_table`            | Penjamin (BPJS, dll)|
| `procedures`        | `2026_05_02_000002_create_procedures_table`          | Tindakan/prosedur   |
| `icd10_codes`       | `2026_05_02_000003_create_icd10_codes_table`         | ICD-10              |
| `icd9_codes`        | `2026_05_02_000004_create_icd9_codes_table`          | ICD-9-CM            |
| `bhp_items`         | `2026_05_02_000005_create_bhp_items_table`           | Bahan habis pakai   |
| `iol_items`         | `2026_05_02_000006_create_iol_items_table`           | Intraocular lens    |
| `medications`       | `2026_05_02_000007_create_medications_table`         | Obat                |
| `surgery_packages`  | `2026_05_02_000008_create_surgery_packages_table`    | Paket bedah         |

### 7.4 Patient & Visit (2026_05_03)
| Tabel        | Migration                                      | Purpose                              |
|--------------|------------------------------------------------|--------------------------------------|
| `patients`   | `2026_05_03_000001_create_patients_table`      | Master pasien                        |
| `visits`     | `2026_05_03_000002_create_visits_table`        | Kunjungan (1 visit = 1 kedatangan)   |
| `queues`     | `2026_05_03_000003_create_queues_table`        | Antrian per-stasiun                  |
| `visit_cob`  | `2026_05_03_000004_create_visit_cob_table`     | Coordination of Benefit              |

### 7.5 Tariffs (2026_05_04)
| Tabel                  | Migration                                            | Purpose                |
|------------------------|------------------------------------------------------|------------------------|
| `procedure_tariffs`    | `2026_05_04_000001_create_procedure_tariffs_table`   | Tarif tindakan         |
| `medication_tariffs`   | `2026_05_04_000002_create_medication_tariffs_table`  | Tarif obat             |
| `bhp_tariffs`          | `2026_05_04_000003_create_bhp_tariffs_table`         | Tarif BHP              |
| `iol_tariffs`          | `2026_05_04_000004_create_iol_tariffs_table`         | Tarif IOL              |

### 7.6 Clinical (2026_05_05)
| Tabel                       | Migration                                              | Purpose              |
|-----------------------------|--------------------------------------------------------|----------------------|
| `surgery_schedules`         | `2026_05_05_000001_create_surgery_schedules_table`     | Jadwal operasi       |
| `nurse_assessments`         | `2026_05_05_000002_create_nurse_assessments_table`     | Asesmen perawat      |
| `refraction_records`        | `2026_05_05_000003_create_refraction_records_table`    | Rekam refraksi       |
| `refraction_prescriptions`  | `2026_05_05_000004_create_refraction_prescriptions_table`| Resep kacamata     |
| `iol_recommendations`       | `2026_05_05_000005_create_iol_recommendations_table`   | Rekomendasi IOL      |
| `doctor_examinations`       | `2026_05_05_000006_create_doctor_examinations_table`   | EMR dokter           |
| `visit_services`            | `2026_05_05_000007_create_visit_services_table`        | Tindakan per-visit   |
| `medical_resumes`           | `2026_05_05_000008_create_medical_resumes_table`       | Resume medis         |

### 7.7 Surgery (2026_05_06)
| Tabel                    | Migration                                             | Purpose                  |
|--------------------------|-------------------------------------------------------|--------------------------|
| `surgery_records`        | `2026_05_06_000001_create_surgery_records_table`      | Laporan operasi          |
| `surgery_requests`       | `2026_05_06_000002_create_surgery_requests_table`     | Request BHP/IOL ke farmasi|
| `surgery_request_bhp`    | `2026_05_06_000003_create_surgery_request_bhp_table`  | Detail BHP per request   |
| `surgery_request_iol`    | `2026_05_06_000004_create_surgery_request_iol_table`  | Detail IOL per request   |
| `surgery_iol_usage`      | `2026_05_06_000005_create_surgery_iol_usage_table`    | Pemakaian IOL aktual     |

### 7.8 Diagnostic & Pharmacy (2026_05_07)
| Tabel                  | Migration                                              | Purpose                 |
|------------------------|--------------------------------------------------------|-------------------------|
| `diagnostic_orders`    | `2026_05_07_000001_create_diagnostic_orders_table`     | Order penunjang         |
| `diagnostic_results`   | `2026_05_07_000002_create_diagnostic_results_table`    | Hasil penunjang         |
| `prescriptions`        | `2026_05_07_000003_create_prescriptions_table`         | Resep obat              |
| `prescription_items`   | `2026_05_07_000004_create_prescription_items_table`    | Item resep              |

### 7.9 Billing & Klaim (2026_05_08)
| Tabel                | Migration                                            | Purpose                  |
|----------------------|------------------------------------------------------|--------------------------|
| `billing_invoices`   | `2026_05_08_000001_create_billing_invoices_table`    | Invoice                  |
| `billing_items`      | `2026_05_08_000002_create_billing_items_table`       | Item invoice             |
| `bpjs_claims`        | `2026_05_08_000003_create_bpjs_claims_table`         | Klaim BPJS               |
| `claim_audit_logs`   | `2026_05_08_000004_create_claim_audit_logs_table`    | Audit log append-only    |

### 7.10 Misc (2026_05_09)
| Tabel        | Migration                                       | Purpose              |
|--------------|-------------------------------------------------|----------------------|
| `tariffs`    | `2026_05_09_000001_create_tariffs_table`        | (Generic tariff?)    |
| `system_logs`| `2026_05_09_000002_create_system_logs_table`    | Log sistem           |

### 7.11 Documents & Notification (2026_05_10)
| Tabel                          | Migration                                                  | Purpose                  |
|--------------------------------|------------------------------------------------------------|--------------------------|
| `document_number_configs`      | `2026_05_10_000001_create_document_number_configs_table`   | Konfig nomor dokumen     |
| `document_types`               | `2026_05_10_000002_create_document_types_table`            | Jenis dokumen            |
| `document_templates`           | `2026_05_10_000003_create_document_templates_table`        | Template HTML/JSON       |
| `station_document_mappings`    | `2026_05_10_000004_create_station_document_mappings_table` | Mapping stasiun→dokumen  |
| `patient_documents`            | `2026_05_10_000005_create_patient_documents_table`         | Dokumen pasien (instance)|
| `document_verifications`       | `2026_05_10_000006_create_document_verifications_table`    | Token QR verifikasi      |
| `notifications`                | `2026_05_10_000007_create_notifications_table`             | Notif inbox (TTD, dll)   |
| `medical_records`              | `2026_05_10_000008_create_medical_records_table`           | Generic form versioning  |
| `medical_records_versions`     | `2026_05_10_000009_create_medical_records_versions_table`  | Versi (immutable)        |

### 7.12 Integration (2026_05_11)
| Tabel                       | Migration                                                  | Purpose                |
|-----------------------------|------------------------------------------------------------|------------------------|
| `integration_configs`       | `2026_05_11_000001_create_integration_configs_table`       | Konfig integrasi       |
| `bpjs_referrals_in`         | `2026_05_11_000002_create_bpjs_referrals_in_table`         | Rujukan masuk          |
| `bpjs_referrals_out`        | `2026_05_11_000003_create_bpjs_referrals_out_table`        | Rujukan keluar         |
| `bpjs_control_letters`      | `2026_05_11_000004_create_bpjs_control_letters_table`      | Surat kontrol          |
| `bpjs_vclaim_logs`          | `2026_05_11_000005_create_bpjs_vclaim_logs_table`          | Log call VClaim        |
| `bpjs_antrean_logs`         | `2026_05_11_000006_create_bpjs_antrean_logs_table`         | Log Antrean BPJS       |
| `bpjs_icare_logs`           | `2026_05_11_000007_create_bpjs_icare_logs_table`           | Log iCare              |
| `inacbgs_grouping_logs`     | `2026_05_11_000008_create_inacbgs_grouping_logs_table`     | Log grouping INA-CBGs  |
| `satusehat_sync_logs`       | `2026_05_11_000009_create_satusehat_sync_logs_table`       | Log sync Satu Sehat    |
| `satusehat_resource_logs`   | `2026_05_11_000010_create_satusehat_resource_logs_table`   | Log resource Satu Sehat|

### 7.13 Migration Tambahan (2026_05_19)
| Migration                                                  | Perubahan                                              |
|------------------------------------------------------------|--------------------------------------------------------|
| `2026_05_19_000001_add_username_to_users_table`            | Tambah kolom `username` (login pakai username, bukan email)|
| `2026_05_19_000002_add_pain_rps_to_nurse_assessments_table`| Tambah kolom `pain_rps` (Riwayat Penyakit Sekarang nyeri)|
| `2026_05_19_000003_create_doctor_schedules_table`          | Tabel jadwal dokter (sebelumnya belum ada)             |

**Seeder default** (`backend/database/seeders/`):
`DatabaseSeeder` (entry point) → `RoleSeeder`, `EmployeeSeeder`,
`UserSeeder`, `ClinicProfileSeeder`, `ICD10Seeder`, `ICD9Seeder`,
`DocumentTypeSeeder`, `StationMappingSeeder`, `IntegrationConfigSeeder`,
`DoctorScheduleSeeder`, `PatientVisitSeeder`.

---

## 8. API Request/Response Format

Semua endpoint REST mengembalikan **envelope JSON standar** — pola ini
ditegaskan di komentar header `routes/api.php` dan implementasi
`AuthController`.

### 8.1 Envelope Standar

```json
{
  "success": true,         // boolean — true jika operasi sukses
  "data":    { ... },      // object | array | null — payload utama
  "message": "Login berhasil",  // string — pesan utk user (BIPA)
  "errors":  null          // object | null — detail field error (umumnya 422)
}
```

### 8.2 Sample — Login

```http
POST /api/v1/auth/login
Content-Type: application/json
Accept: application/json

{
  "username": "admin",
  "password": "secret123"
}
```

Response 200 OK:

```json
{
  "success": true,
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "bearer",
    "expires_in": 3600,
    "user": {
      "id": 1,
      "username": "admin",
      "name": "Administrator",
      "role": { "id": 1, "name": "admin" },
      "employee": { "id": 1, "name": "Dr. ..." }
    }
  },
  "message": "Login berhasil",
  "errors": null
}
```

Response 401 Unauthorized (kredensial salah):

```json
{ "success": false, "data": null, "message": "Kredensial salah", "errors": null }
```

> Frontend: simpan `data.access_token` di `localStorage` sebagai
> `auth_token`. Setiap request setelahnya wajib header
> `Authorization: Bearer <token>`.

### 8.3 Sample — Authenticated Request

```http
GET /api/v1/admisi/dashboard
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
Accept: application/json
```

### 8.4 Sample — Validation Error (422)

Mengikuti format default Laravel (BUKAN envelope kustom — exception
`ValidationException` di-handle Laravel sebelum sempat dibungkus controller):

```json
{
  "message": "The username field is required.",
  "errors": {
    "username": ["The username field is required."]
  }
}
```

> Catatan: dua format berdampingan ini wajar di Laravel — envelope kustom
> hanya saat controller mengembalikan response, sedangkan validation error
> tetap pakai format framework. Frontend perlu handle keduanya.

### 8.5 Sample — Error Server (5xx)

Response interceptor di frontend akan **dispatch event**
`arumed:server-error` — store/komponen bisa subscribe untuk tampilkan toast
global, sambil error tetap di-reject dari promise call asalnya.

---

## 9. Integration Points

Catatan operasional untuk integrasi pihak ketiga — wajib dibaca sebelum
menyentuh modul BPJS/Satu Sehat.

| Integrasi              | Service Class           | Tabel Log                | Endpoint Utama                                  |
|------------------------|-------------------------|--------------------------|-------------------------------------------------|
| **BPJS VClaim**        | `BpjsVClaimService`     | `bpjs_vclaim_logs`       | `/admisi/bpjs/*`, `/klaim/{id}/submit`          |
| **BPJS Antrean**       | `BpjsAntreanService`    | `bpjs_antrean_logs`      | `/admisi/bpjs/validasi-booking`                 |
| **BPJS iCare**         | (dalam `IntegrasiService`) | `bpjs_icare_logs`     | `/klaim/icare/monitoring`                       |
| **BPJS INA-CBGs**      | `InaCbgsService`        | `inacbgs_grouping_logs`  | `/klaim/{id}/grouping`                          |
| **Satu Sehat (Kemkes)**| `SatusehatService`      | `satusehat_sync_logs`, `satusehat_resource_logs` | `/integrasi/satusehat/*` |
| **Laravel Reverb (WS)**| `App\Events\*`          | —                        | broadcast otomatis saat queue update            |

**Catatan operasional**:
- Semua HTTP call ke pihak ketiga harus melalui Service class — jangan
  langsung dari controller (memudahkan retry & logging).
- Setiap call wajib menulis log ke tabel `*_logs` terkait (request, response,
  status code, timestamp). Tabel log adalah satu-satunya sumber audit ke
  vendor saat dispute.
- **Reverb (WebSocket)** opsional di dev — frontend punya **fallback polling
  30s** di `queueStore`, `admisiStore`, `perawatStore` jika WS gagal connect.
- **Klaim BPJS** sekali `submit` tidak bisa dibatalkan — frontend wajib
  modal konfirmasi (lihat `KlaimView` pattern).

---

## 10. Development Setup

### 10.1 Quick Start

```bash
# === Backend ===
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret              # WAJIB — set JWT_SECRET
php artisan migrate --seed          # buat schema + seed master data
php artisan serve                   # http://localhost:8000

# === Frontend (terminal lain) ===
cd arumed-frontend
npm install
npm run dev                         # http://localhost:5173
```

### 10.2 One-Shot Dev (Concurrently)

`composer dev` di `backend/composer.json` menjalankan 4 proses paralel
dengan `npx concurrently`:

| Nama   | Command                                                  | Untuk                    |
|--------|----------------------------------------------------------|--------------------------|
| server | `php artisan serve`                                      | API server               |
| queue  | `php artisan queue:listen --tries=1 --timeout=0`         | Job worker               |
| logs   | `php artisan pail --timeout=0`                           | Tail log real-time       |
| vite   | `npm run dev`                                            | Frontend HMR             |

### 10.3 Catatan Operasional

- **Database default**: SQLite (auto-created saat `composer setup`).
  Production: ganti `DB_CONNECTION=pgsql` + isi `DB_HOST`/`DB_DATABASE`/dll
  di `.env` lalu re-migrate.
- **CORS**: dev frontend di port 5173 sudah whitelisted via `config/cors.php`
  + global middleware `HandleCors`. Production: update `allowed_origins`.
- **WebSocket Reverb**: belum dikonfigurasi di `.env.example`. Jika dipakai,
  set `BROADCAST_DRIVER=reverb` + `REVERB_*` keys, lalu jalankan
  `php artisan reverb:start`.
- **JWT TTL**: default `JWT_TTL=60` (menit) — refresh via
  `POST /api/v1/auth/refresh` sebelum expired.
- **Health check**: `GET /up` (200 OK jika app booted) — dipakai monitoring.

---

## 11. Alur Layanan (Service Flow / Patient Journey)

Section ini mendeskripsikan **alur antrean pasien end-to-end** — dari masuk
klinik sampai pulang. Setiap tahap layanan direpresentasikan oleh satu atau
lebih baris `queues` (lihat Section 7 schema), dan **semua transisi antar
station di-orkestrasi oleh `QueueService::advanceFromStation()`**. Per-
station service (Admisi/Perawat/Refraksi/Dokter/Penunjang/Bedah/Kasir/
Farmasi) hanya thin wrapper yang delegate ke `QueueService`, plus melakukan
gate validation (mis. Triase wajib finalize asesmen sebelum advance). Setiap
transisi men-trigger event Reverb sehingga `AntreanTVView.vue` update real-
time.

### 11.1 Stasiun Antrean (Queue Stations)

Konstanta `Queue::STATIONS` di model (`backend/app/Models/Queue.php`) +
field `station` di tabel `queues`. **Tahap Triase + Refraksionis (TR
conceptual) di-implementasi sebagai DUA baris `queues` paralel**
(`station=TRIASE` dan `station=REFRAKSIONIS`) yang share `visit_id` —
perawat & refraksionis kerja independen, dan transisi ke DOKTER hanya
terjadi setelah **kedua** sub-task finalize.

| Kode    | Station        | Prefix | View                  | Catatan                          |
|---------|----------------|--------|-----------------------|----------------------------------|
| `A`     | ADMISI         | `A`    | `AdmisiView.vue`      |                                  |
| `T` + `R` | TRIASE + REFRAKSIONIS | `T` / `R` | `PerawatView.vue` + `RefraksionisView.vue` | 2 baris paralel, share `visit_id`, gate-ke-D=AND |
| `D`     | DOKTER         | `D`    | `DokterView.vue`      |                                  |
| `P`     | PENUNJANG      | `P`    | `PenunjangView.vue`   | Opsional (jika ada DiagnosticOrder open) |
| `B`     | BEDAH          | `B`    | `BedahView.vue`       | Opsional (planning=BEDAH **dan** jadwal HARI INI) |
| `K`     | KASIR          | `K`    | `KasirView.vue`       |                                  |
| `F`     | FARMASI        | `F`    | `FarmasiView.vue`     | Opsional (skip jika tidak ada Prescription open) |

> **Status `queues.status`** lifecycle: `WAITING → CALLED → IN_PROGRESS → COMPLETED`
> (atau `CANCELLED`). Skip pakai action `lewati` yang reset `queue_sequence`
> ke akhir tanpa ubah status. Konstanta: `Queue::STATUS_*`,
> `Queue::ACTIVE_STATUSES`, helper `Queue::prefixFor($station)`.

### 11.2 Diagram Alur

```
                       ┌─────────────────────────┐
                       │  KIOSK / ANJUNGAN       │
                       │  (AnjunganView.vue)     │
                       └────────────┬────────────┘
                                    │
              ┌─────────────────────┼────────────────────────┐
              │                                              │
   Pasien BPJS + (Kode Booking / Nomor Rujukan):     Pasien BARU / UMUM /
   • masuk langsung ke TRIASE + REFRAKSIONIS         ASURANSI / LAINNYA
   • jika BPJS Bridging AKTIF → POST ke antrean
     Dokter di WSBPJS (sinkronisasi BPJS Antrean)
              │                                              │
              │ skip admisi                                  ▼
              │                                  ┌─────────────────────┐
              │                                  │  ANTREAN ADMISI (A) │
              │                                  │  AdmisiView.vue     │
              │                                  └──────────┬──────────┘
              │                                             │ selesai daftar
              │                                             ▼
              └────────────────────────►┌──────────────────────────────┐
                                        │  TRIASE (T)  ║ REFRAKSIONIS  │
                                        │  PerawatView ║ RefraksionisView (R)
                                        │  2 baris queues paralel,     │
                                        │  share visit_id              │
                                        └──────────────┬───────────────┘
                                                       │ GATE:
                                                       │ NurseAssessment.is_finalized
                                                       │ AND RefractionRecord.is_finalized
                                                       │ (partner selesai = NO_OP)
                                                       ▼
        ┌────────────────────────────────────────────────────────────┐
        │                  ANTREAN DOKTER (D)                        │
        │                  DokterView.vue                            │
        └──────────────────────────────┬─────────────────────────────┘
                                       │ DOKTER selesai → resolveNextStation:
                          ┌────────────┼─────────────────┐
                          │            │                 │
                          ▼            ▼                 ▼
              ┌─────────────────┐ ┌──────────────┐ ┌─────────────┐
              │ PENUNJANG (P)   │ │  BEDAH (B)   │ │ KASIR (K)   │
              │ ada Diagnostic- │ │ planning=    │ │ default     │
              │ Order open      │ │ BEDAH +      │ │             │
              └─────────┬───────┘ │ schedule     │ └──────┬──────┘
                        │         │ HARI INI     │        │
                        │ kembali │              │        │ bayar selesai
                        └─→ D     └──────┬───────┘        ▼
                                         │       ┌──────────────────┐
                                         └──────►│ KASIR (post-op)  │
                                                 └────────┬─────────┘
                                                          │ KASIR selesai:
                                                          │  ada Prescription open?
                                                          │   YES → FARMASI
                                                          │   NO  → SELESAI
                                                          ▼
                                                ┌──────────────────┐
                                                │  FARMASI (F)     │
                                                └────────┬─────────┘
                                                         │ dispensing selesai
                                                         ▼
                                                  ┌──────────────┐
                                                  │   SELESAI    │
                                                  │  (pulang)    │
                                                  └──────────────┘
```

> **Catatan BEDAH terjadwal hari lain:** kalau `surgery_schedules.scheduled_date > today`,
> Dokter selesai → **KASIR** (bukan BEDAH). Pasien pulang via Kasir & Farmasi.
> Saat hari operasi tiba, pasien daftar ulang dari ADMISI → TRIASE+REFRAKSIONIS →
> DOKTER → BEDAH (gate today match) → KASIR → FARMASI.

### 11.3 Aturan Transisi (per Tahap)

Otoritas sumber: `QueueService::resolveNextStation(Visit, fromStation)` di
`backend/app/Services/QueueService.php`. Tabel di bawah adalah dokumentasi
human-readable dari method tersebut.

| #  | Dari Station   | Kondisi                                                                                | Station Berikutnya            | Endpoint utama                         |
|----|----------------|----------------------------------------------------------------------------------------|-------------------------------|----------------------------------------|
| 1a | Kiosk          | Pasien `BARU` / `UMUM` / `ASURANSI` / `LAINNYA`                                        | **ADMISI**                    | `POST /admisi/daftar`                  |
| 1b | Kiosk          | Pasien `BPJS` + `kode_booking` atau `no_rujukan` valid                                 | **TRIASE + REFRAKSIONIS** (skip ADMISI) | `POST /admisi/bpjs/validasi-booking` |
| 2  | **ADMISI**     | Selesai verifikasi + (BPJS) SEP                                                        | **TRIASE + REFRAKSIONIS** (2 baris paralel) | `PUT /admisi/antrian/{id}/selesai` |
| 3a | **TRIASE**     | `NurseAssessment.is_finalized` AND `RefractionRecord.is_finalized`                     | **DOKTER**                    | `PUT /perawat/antrian/{id}/selesai`    |
| 3b | **REFRAKSIONIS** | Gate sama; jika DOKTER queue sudah dibuat partner → tutup queue saja (`NO_OP`)        | **DOKTER** atau no-op         | `PUT /refraksi/antrian/{id}/selesai`   |
| 3c | TRIASE/REFRAKSIONIS | Gate BELUM passed                                                                 | **422 error** (queue tidak ditutup) | (gate validation di QueueService)  |
| 4a | **DOKTER**     | Ada `DiagnosticOrder` status `REQUESTED`/`IN_PROGRESS`                                 | **PENUNJANG**                 | `PUT /dokter/antrian/{id}/selesai`     |
| 4b | **DOKTER**     | `DoctorExamination.planning='BEDAH'` AND `SurgerySchedule.scheduled_date=today`        | **BEDAH**                     | `PUT /dokter/antrian/{id}/selesai`     |
| 4c | **DOKTER**     | Default (termasuk BEDAH dengan jadwal hari lain)                                       | **KASIR**                     | `PUT /dokter/antrian/{id}/selesai`     |
| 5  | **PENUNJANG**  | Hasil sudah dirilis                                                                    | **DOKTER** (kembali untuk pembacaan) | `PUT /penunjang/antrian/{id}/selesai` |
| 6  | **BEDAH**      | Operasi selesai                                                                        | **KASIR** (billing pasca-op)  | `PUT /bedah/antrian/{id}/selesai`      |
| 7a | **KASIR**      | Ada `Prescription` status `DRAFT`/`SUBMITTED`/`DISPENSING`                             | **FARMASI**                   | `PUT /kasir/antrian/{id}/selesai`      |
| 7b | **KASIR**      | Tidak ada resep open                                                                   | **SELESAI** (`current_station=SELESAI`) | sama                              |
| 8  | **FARMASI**    | Dispensing selesai                                                                     | **SELESAI** (pasien pulang)   | `PUT /farmasi/antrian/{id}/selesai`    |

**Sentinel di `resolveNextStation` return value:**

| Return                     | Arti                                                                            |
|----------------------------|---------------------------------------------------------------------------------|
| `string` (mis. `'KASIR'`)  | Enqueue baris baru di station itu + set `visit.current_station`                 |
| `array<string>`            | Multi-enqueue paralel (mis. `[TRIASE, REFRAKSIONIS]` dari ADMISI)               |
| `QueueService::END_OF_FLOW`| Pasien pulang, set `visit.current_station='SELESAI'`, no enqueue                |
| `QueueService::NO_OP`      | Tutup queue saja (partner TR sudah trigger next), `current_station` tetap       |
| `null`                     | Gate belum passed → throw 422, queue **tidak** ditutup                          |

### 11.4 Implementasi di Backend

**Komponen kunci:**

- **`backend/app/Services/QueueService.php`** — orchestrator pusat. Method utama:
  - `generateQueueNumber($station)` — thread-safe per station per hari,
    format `{PREFIX}-{NNN}` (mis. `A-001`, `T-012`).
  - `enqueue($visitId, $station)` — buat baris `queues` baru dengan status
    `WAITING`.
  - `panggil($queueId)` — `WAITING → CALLED`, set `called_at`, broadcast.
  - `mulai($queueId)` — `CALLED → IN_PROGRESS`, set `started_at`.
  - `lewati($queueId)` — pindah ke akhir antrean station yang sama (reset
    `queue_sequence` ke `MAX+1`, status kembali `WAITING`).
  - `batal($queueId, $reason)` — `CANCELLED`, no advance, log alasan.
  - `advanceFromStation($queueId, $expectedStation)` — transaction:
    resolve next → close current → enqueue next (atau `END_OF_FLOW` /
    `NO_OP`) → broadcast.
  - `resolveNextStation($visit, $fromStation)` — matrix transisi (lihat
    Section 11.3).
  - `getAllActive()` — snapshot semua station untuk `AntreanTVView`.

- **`backend/app/Models/Queue.php`** — constants:
  - `STATION_ADMISI` / `STATION_TRIASE` / `STATION_REFRAKSIONIS` /
    `STATION_DOKTER` / `STATION_PENUNJANG` / `STATION_BEDAH` /
    `STATION_KASIR` / `STATION_FARMASI` + `STATIONS` array, `PREFIX_MAP`.
  - `STATUS_WAITING` / `STATUS_CALLED` / `STATUS_IN_PROGRESS` /
    `STATUS_COMPLETED` / `STATUS_CANCELLED` + `ACTIVE_STATUSES` array.
  - Scopes: `today()`, `byStation($s)`, `active()`, `waiting()`.
  - Helper: `Queue::prefixFor($station)`.

- **`backend/app/Http/Controllers/QueueController.php`** — endpoint generic
  `/v1/antrian/*` (lihat Section 5.1b). Tidak menerapkan gate per-station
  (gate ada di QueueService); cocok untuk admin tooling & TV display.

- **Per-station service (`AdmisiService`, `PerawatService`, `RefraksiService`,
  `DokterService`, `PenunjangService`, `BedahService`, `FarmasiService`,
  `KasirService`)** — `selesaiAntrian` (atau `selesaiAdmisi`) hanya thin
  wrapper yang:
  1. Pre-validate gate spesifik domain (mis. `PerawatService::selesaiAntrian`
     cek `NurseAssessment::is_finalized=true` sebelum advance).
  2. Delegate ke `$this->queueService->advanceFromStation($queueId, $station)`.

**Catatan operasional:**

- **Transisi TR → D**: `nextAfterTriaseOrRefraksi()` cek `is_finalized` di
  kedua tabel `nurse_assessments` & `refraction_records`. Yang **pertama**
  selesai (gate passed) trigger enqueue DOKTER. Yang **kedua** selesai
  setelahnya return `NO_OP` (cek `DOKTER` queue sudah ada hari ini) →
  tutup queue partner tanpa double-enqueue.
- **Transisi D → B (BEDAH)**: hanya kalau `DoctorExamination.planning='BEDAH'`
  **DAN** `SurgerySchedule.scheduled_date = today()` **DAN**
  `SurgerySchedule.status IN ('SCHEDULED', 'IN_PROGRESS')`. Kalau jadwal di
  masa depan, pasien tetap D → K → F → SELESAI hari ini; saat tanggal
  operasi dia daftar ulang dari ADMISI.
- **Transisi K → F vs K → SELESAI**: `nextAfterKasir()` cek apakah ada
  `Prescription` status `DRAFT`/`SUBMITTED`/`DISPENSING` untuk visit ini.
- **Reverb broadcast**: `QueueService::broadcastQueueUpdate()` dispatch
  `AdmisiQueueUpdated` untuk station=ADMISI, `TriaseQueueUpdated` untuk
  TRIASE/REFRAKSIONIS. Station lain belum punya event class — fallback
  polling 30s di frontend.
- **BPJS — kiosk dengan kode booking / no rujukan** (tahap 1):
  `AdmisiService` validasi booking/rujukan via VClaim, lalu langsung
  enqueue TRIASE + REFRAKSIONIS (skip ADMISI). Jika
  `integration_configs.bpjs_enabled=true`, service juga POST entry ke
  antrean Dokter di WSBPJS via `BpjsAntreanService` untuk sinkronisasi
  antrean BPJS.
- **Cancel di tengah alur**: `PUT /admisi/kunjungan/{id}/cancel` (soft
  delete visit) atau `PUT /antrian/{id}/batal` per baris queue. Cancel
  visit men-cascade ke semua queue (soft delete).

### 11.5 Tampilan Real-Time di `AntreanTVView.vue`

`AntreanTVView.vue` adalah **public display** (route `/antrean-tv`, no auth,
layout blank — lihat Section 6.3) yang dipasang di TV ruang tunggu.

- Snapshot semua station satu request: `GET /api/v1/antrian` (lihat Section
  5.1b) → response berisi `prefix`, `total`, `waiting`, `called`, `next`,
  `rows` per station.
- Subscribe ke channel Reverb untuk station yang sudah punya event class
  (`A`, `T/R`) via `pusher-js`. Station lain (D/P/B/K/F) belum broadcast —
  rely pada polling.
- Fallback: polling `GET /api/v1/antrian` tiap 30 detik (sama dengan
  `queueStore.startPolling`).
- Tampilkan **nomor antrean yang sedang dipanggil** per station (`called`),
  plus nomor next-in-line (`next`).
- Saat ada nomor baru dipanggil, trigger animasi blink (`@keyframes blink`
  di `assets/styles/base.css`) + opsional Text-to-Speech / suara panggilan.

### 11.6 Catatan Operasional Alur

- **Antrean TR (paralel)**: 2 baris `queues` per visit dengan
  `station=TRIASE` dan `station=REFRAKSIONIS`, share `visit_id`. Perawat
  (`PerawatView`) buka antrean TRIASE; refraksionis (`RefraksionisView`)
  buka antrean REFRAKSIONIS. Siapa yang selesai duluan trigger enqueue
  DOKTER (jika gate AND passed); yang selesai berikutnya cuma menutup
  queue partner (`NO_OP`).
- **BPJS bridging off**: jika konfigurasi `bpjs_enabled = false`, alur
  WS RS tetap sama (kiosk → TR via validasi booking jika tersedia, atau
  via Admisi manual), hanya **tidak ada POST ke antrean Dokter di WSBPJS**
  — sinkronisasi antrean BPJS dilakukan offline / manual.
- **Cancel di tengah alur**: gunakan
  `PUT /admisi/kunjungan/{id}/cancel` — semua antrean turunan di-mark
  `DIBATALKAN` dan tidak muncul lagi di TV.
- **Lewati (skip)**: hanya di tahap TR
  (`PUT /perawat/antrian/{id}/lewati`) — pasien tidak hilang, hanya turun
  ke urutan paling bawah. Tidak ada `lewati` di Kasir/Farmasi (wajib
  selesai berurutan).

---

> **Versi dokumen**: 2026-05-20 (revisi 2: tambah `QueueService`,
> `QueueController`, endpoint `/v1/antrian/*`, gate validation TR finalize-
> AND, sentinel `END_OF_FLOW`/`NO_OP`, BEDAH same-day rule).
> **Cara update**: setiap perubahan endpoint di `routes/api.php`, tambah model
> di `app/Models/`, atau view/store baru di frontend, sinkronkan ke
> Section terkait. Jaga supaya tabel endpoint dan tree folder tetap
> mencerminkan kondisi `git HEAD`.
