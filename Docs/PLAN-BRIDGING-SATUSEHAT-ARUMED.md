# PLAN — Bridging Satu Sehat (SATUSEHAT) untuk Arumed

> **Tujuan utama (sesuai permintaan user):** mengirim 4 FHIR resource ke Satu Sehat agar
> muncul di **statistik dashboard Satu Sehat**:
> 1. **Jumlah Kunjungan** → `Encounter`
> 2. **Jumlah Diagnosis** → `Condition`
> 3. **Jumlah Peresepan Obat** → `MedicationRequest`
> 4. **Jumlah Obat Dibawa Pulang** → `MedicationDispense`
>
> **Status koneksi (TERUJI 2026-05-29):** kredensial Sandbox VALID. OAuth2 token OK,
> GET Organization OK, resolve Patient by NIK → IHS Number OK. Fondasi koneksi pasti benar.

---

## 0. Keputusan yang sudah disepakati (user)

| Topik | Keputusan |
|---|---|
| Kode obat KFA | **Tambah kolom `kfa_code` di master `medications`** (UI muncul di menu **Inventori Farmasi → master Obat**) + helper cari KFA via API. Lihat catatan di bawah. |
| NIK Dokter (Practitioner) | **Tambah kolom `nik`** di Employee + UI |
| Trigger kirim | **Batch harian (scheduler) + tombol Sync Manual** |
| Environment | **Sandbox dulu** (`api-satusehat-stg.dto.kemkes.go.id`), Production menyusul |

> **Catatan lokasi `kfa_code` (keputusan user 2026-05-30):** kolom di tabel **`medications`**,
> BUKAN `inventory_stocks`. Alasan: KFA = identitas obat (1 obat = 1 KFA), bukan atribut per-batch;
> `inventory_stocks` hanya menyimpan batch/qty fisik (`item_type`+`item_id`+`batch_no`) dan
> `item_id` menunjuk balik ke `medications.id`. Dispense FEFO baca `inventory_stocks`, TAPI master
> obatnya tetap `medications` — `FarmasiService` sudah mengakses `$item->medication`, jadi
> MedicationRequest/Dispense ambil KFA via `$item->medication->kfa_code` (relasi yang sudah ada).
> **UI-nya tetap muncul di menu Inventori Farmasi (master Obat)** sesuai pola existing (view fisik di
> `views/master-data/`, router reroute) — jadi dari sisi user kfa diatur di Inventori.

### Kredensial Sandbox (diisi & diuji lewat UI Konfigurasi Bridging — BUKAN di kode/seeder)
> **Keputusan user 2026-05-30:** kredensial **TIDAK di-hardcode/seed**. Admin paste
> Organization ID / Client ID / Client Secret di **halaman Konfigurasi Bridging** (kartu SATUSEHAT,
> credential write-only seperti VClaim) lalu **Test → Aktifkan** langsung dari UI.
> Disimpan di `IntegrationConfig` SATUSEHAT (`credentials` encrypted). `.env` opsional hanya untuk
> base URL/env (non-secret). Untuk Production: regenerate kredensial baru.
```
# .env (opsional — hanya non-secret / fallback dev)
SATUSEHAT_ENV=sandbox
# ORGANIZATION_ID / CLIENT_ID / CLIENT_SECRET / LOCATION_ID → diisi via UI Konfigurasi
```

---

## 1. Base URL & Endpoint resmi (terverifikasi)

| Env | OAuth2 | FHIR R4 |
|---|---|---|
| Sandbox/Staging | `https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1` | `https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1` |
| Production | `https://api-satusehat.kemkes.go.id/oauth2/v1` | `https://api-satusehat.kemkes.go.id/fhir-r4/v1` |

**Auth:** `POST {oauth}/accesstoken?grant_type=client_credentials`
Body form-urlencoded: `client_id`, `client_secret` → balik `access_token` (cache ~expires_in-60s).
Semua FHIR call: header `Authorization: Bearer <token>`.

**Resolve IHS (WAJIB sebelum kirim klinis):**
`GET {fhir}/Patient?identifier=https://fhir.kemkes.go.id/id/nik|{NIK}` → IHS pasien
`GET {fhir}/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|{NIK}` → IHS dokter

---

## 2. Gap data sumber Arumed (yang harus ditutup)

| Resource butuh | Sumber Arumed | Status |
|---|---|---|
| Patient IHS | `patients.nik` | ✅ ada |
| **Practitioner IHS** | `employees.nik` | ❌ **belum ada → tambah kolom** |
| Encounter (kunjungan) | `visits` (+ patient + doctor + location) | ✅ ada |
| Condition (diagnosa ICD-10) | `doctor_examinations.diagnosis_utama` + `diagnosis_sekunder[]` | ✅ ada |
| MedicationRequest (resep) | `prescriptions` + `prescription_items` | ✅ ada |
| **Obat kode KFA** | `medications.kfa_code` | ❌ **belum ada → tambah kolom** |
| MedicationDispense (obat pulang) | `prescriptions.status=DISPENSED` + `dispensed_at`/`dispensed_by_id` | ✅ ada |
| Encounter ID tersimpan | `visits.satusehat_encounter_id` | ✅ ada |
| IHS pasien tersimpan | (belum) | ❌ **tambah `patients.satusehat_ihs`** (cache, hindari resolve berulang) |
| IHS dokter tersimpan | (belum) | ❌ **tambah `employees.satusehat_ihs`** (cache) |

---

## 3. Arsitektur (reuse kerangka yang SUDAH ADA)

Sudah tersedia & dipakai ulang (tidak dibuat lagi):
- `app/Services/SatusehatService.php` (kerangka batch/per-visit/retry — isi method-nya)
- `satusehat_sync_logs` + `satusehat_resource_logs` (tabel log)
- `visits.satusehat_encounter_id / satusehat_sync_status / satusehat_synced_at`
- Endpoint: `/integrasi/satusehat/sync-log`, `/sync-log/{id}`, `/resource-log`, `/sync-manual`, `/retry/{logId}`
- Row `IntegrationConfig` `SATUSEHAT`

Baru dibuat: `SatusehatClient` (HTTP+OAuth, dipisah dari service biar testable — pola sama seperti `Bpjs/BpjsClient`).

---

## 4. Langkah Implementasi (BERTAHAP — tunggu konfirmasi tiap fase)

### FASE 1 — Auth & Test Connection (fondasi, bisa diuji langsung)
1. `config/services.php`: tambah blok `satusehat` HANYA untuk base URL + env (non-secret).
   **Kredensial (org_id/client_id/client_secret/location_id) TIDAK di `.env`/seeder** — dibaca
   dari `IntegrationConfig` SATUSEHAT (`credentials` encrypted) yang diisi admin lewat UI Konfigurasi.
2. Buat `app/Services/Satusehat/SatusehatClient.php` (pola sama `Bpjs/BpjsClient`):
   - baca credentials dari row `IntegrationConfig` SATUSEHAT; `isEnabled()` = is_enabled && credentials non-empty (belum aktif → RuntimeException 503).
   - `getAccessToken()` — POST accesstoken + `Cache::remember` (TTL = expires_in − 60).
   - `get($path)`, `post($path, $payload)` — inject Bearer, base FHIR per-env.
   - `oauthBase()` / `fhirBase()` — switch sandbox/prod.
3. `SatusehatService::testConnection()` — hit accesstoken, cek `status=approved`, return ringkas.
   Kartu SATUSEHAT di **BridgingKonfigurasiView** sudah ada (credential write-only, tombol Test/Simpan/Aktifkan) — tinggal di-wire.
4. **Uji:** admin paste kredensial Sandbox di UI Konfigurasi → klik **Test** → token + approved.
   **✅ Acceptance:** balik token sandbox, `status: approved` (diuji dari UI, bukan tinker).

### FASE 2 — Migrasi gap data + resolve IHS
5. Migration tambah kolom:
   - `employees.nik` (string, nullable, index) + `employees.satusehat_ihs`
   - `medications.kfa_code` (string, nullable, index)
   - `patients.satusehat_ihs` (string, nullable)
6. Update fillable model (Employee, Medication, Patient).
7. `SatusehatService::resolvePatientIhs(Patient)` & `resolvePractitionerIhs(Employee)`:
   - cek kolom `satusehat_ihs` dulu → kalau kosong, GET by NIK → simpan → return.
8. UI master: field `nik` di Data Pengguna (dokter), field `kfa_code` di **master Obat
   (menu Inventori Farmasi)** — view fisik di `views/master-data/`, akses lewat /inventori-farmasi
   (+ tombol "Cari KFA" opsional — bisa Fase 5).
   **✅ Acceptance:** resolve IHS pasien & dokter dari NIK berhasil, IHS tersimpan.

### FASE 3 — Builder Encounter + Condition (target statistik #1 & #2)
9. `buildEncounterPayload(Visit)` — FHIR Encounter valid:
   - `status: finished`, `class: AMB` (rawat jalan),
   - `subject` → Patient/{IHS}, `participant` → Practitioner/{IHS},
   - `serviceProvider` → Organization/{org_id}, `location` → Location/{location_id},
   - `period.start/end` dari visit.
10. POST Encounter → simpan `id` ke `visits.satusehat_encounter_id`.
11. `buildConditionPayload(Visit)` — satu Condition per diagnosa:
    - `code` ICD-10 (`system: http://hl7.org/fhir/sid/icd-10`), `subject`, `encounter`,
    - utama → `category: encounter-diagnosis`, sekunder → loop `diagnosis_sekunder[]`.
    **✅ Acceptance:** statistik Kunjungan & Diagnosis bertambah di dashboard Satu Sehat (sandbox).

### FASE 4 — Builder MedicationRequest + MedicationDispense (target #3 & #4)
12. `buildMedicationRequestPayload(Visit)` — per `prescription_item`:
    - `medicationCodeableConcept` pakai `kfa_code` (system KFA), fallback display nama,
    - `subject`, `encounter`, `requester` → Practitioner, `dosageInstruction` dari `dosage`/`instructions`.
13. `buildMedicationDispensePayload(Visit)` — untuk prescription `status=DISPENSED`:
    - `medicationCodeableConcept` KFA, `quantity`, `whenHandedOver` = `dispensed_at`,
    - `performer` → Practitioner/petugas, `authorizingPrescription` → MedicationRequest.
14. Skip aman jika `kfa_code` kosong (log SKIPPED, jangan gagалкan seluruh visit).
    **✅ Acceptance:** statistik Peresepan & Obat Pulang bertambah di dashboard.

### FASE 5 — Trigger batch + Log + polish
15. Wire scheduler (`routes/console.php` / Kernel): `batchSync('AUTO')` tiap 23:59 WIB,
    retry 01:00 untuk PARTIAL/FAILED. Hanya visit `current_station=SELESAI` hari itu.
16. Sub-view **BridgingLogView** (sudah ada) tampilkan juga sync-log Satu Sehat +
    drill-down resource-log (payload/response/error), tombol Retry.
17. (Opsional) tombol "Cari KFA" di master Obat → panggil API KFA, isi `kfa_code`.
    **✅ Acceptance:** end-to-end batch jalan, log terbaca, retry berfungsi.

### FASE 6 — Dashboard Monitoring Satu Sehat  ⭐ (permintaan user)
> Modul `/bridging/*` SUDAH ADA (`BridgingLayout` + nav: Konfigurasi/VClaim/Antrean/Log).
> Header layout bahkan SUDAH menyebut "Satu Sehat" tapi belum ada halamannya.
> Dashboard = **tambah 1 tab + 1 sub-view** di modul ini (murah, konsisten).

18. **Endpoint stats baru** `GET /integrasi/satusehat/dashboard` (`SatusehatService::dashboardStats`):
    - **Koneksi**: env (sandbox/prod), `is_enabled`, status test terakhir, nama Organization.
    - **Kartu 4 resource** (hari ini + rentang tanggal `?from&to`), masing-masing:
      `Encounter / Condition / MedicationRequest / MedicationDispense`
      → total terkirim (SUCCESS), gagal (FAILED), dilewati (SKIPPED) — dari `satusehat_resource_logs`.
    - **Ringkas kunjungan**: jumlah visit SELESAI vs sudah SYNCED vs PENDING vs FAILED
      (dari `visits.satusehat_sync_status`) → "berapa kunjungan belum terkirim".
    - **Tren**: sync per hari (N hari terakhir) untuk grafik garis/bar.
    - **Kesiapan data**: # dokter tanpa `nik`, # obat tanpa `kfa_code`, # pasien tanpa IHS
      (indikator kenapa ada yang ke-SKIP).
    - **Riwayat batch**: ringkasan `satusehat_sync_logs` terakhir (status, retry, next_retry).
19. **Sub-view** `BridgingSatusehatView.vue` (tab baru "Satu Sehat" di `BridgingLayout`):
    - Banner status koneksi (hijau approved / merah) + badge env + tombol **Test Koneksi**.
    - **4 stat-card** (Kunjungan/Diagnosis/Peresepan/Obat Pulang) angka besar + SUCCESS/FAILED/SKIPPED.
    - Date-range picker (default hari ini) untuk filter angka.
    - Panel "Kesiapan Data" (peringatan dokter tanpa NIK / obat tanpa KFA, link ke master).
    - Grafik tren sync harian (reuse pola chart yang ada di DashboardView bila ada, else bar CSS).
    - Tombol **Sync Manual** (panggil `/sync-manual`) + indikator running + auto-refresh ringan.
    - Tabel batch terakhir + tombol **Retry** untuk PARTIAL/FAILED.
20. Tambah tab di `BridgingLayout.allTabs` + route `/bridging/satusehat` (RBAC `integrasi.read`/superadmin).
    **✅ Acceptance:** buka `/bridging/satusehat` → lihat status koneksi + 4 angka resource
    yang naik setelah sync, plus peringatan data yang belum siap. Tidak perlu buka portal Satu Sehat.

---

## 5. Catatan teknis & risiko

- **Idempoten:** simpan `satusehat_encounter_id` & resource id; jangan double-POST.
  Gunakan `satusehat_resource_logs` untuk cek resource yang sudah terkirim.
- **Urutan dependensi:** Encounter dulu → baru Condition/Medication (butuh encounter ref).
- **Gagal sebagian:** satu resource gagal ≠ batalkan semua; status visit `FAILED` + retry.
- **Timezone:** `period`/`whenHandedOver` pakai WIB (Asia/Jakarta) → format ISO8601 dengan offset.
- **KFA bertahap:** obat tanpa `kfa_code` → MedicationRequest/Dispense di-SKIP (logged), KFA DI SET DI INVENTORI, DISPENCE AMBIL BARANG DARI INVENTORI
  Encounter+Condition tetap terkirim. Isi KFA mulai dari obat tersering.
- **ImagingStudy/Observation:** TIDAK termasuk scope ini (di luar 4 statistik yang diminta).
- **Location (keputusan user 2026-05-30):** **DIKOSONGKAN dulu** — Encounter tetap terkirim &
  terhitung tanpa field `location` (builder Fase 3 SKIP field bila `location_id` kosong, jangan
  kirim `Location/` kosong). Bisa diisi belakangan tanpa ubah kode.
  - **TODO (after Fase 1 lulus): helper auto-register Location dari Arumed.** Tombol di UI →
    `POST {fhir}/Location` (status active, physicalType room, managingOrganization→Organization/{org_id})
    → terima UUID → simpan otomatis (tak perlu buka portal Kemenkes). Sumber ruang Arumed:
    `clinic_profiles.operating_rooms` (Ruang OK) + `doctor_schedules.poliklinik`/`poli_code` (Poli).
    Multi-Location per ruang ditunda; mulai dari 1 Location global bila nanti diisi.
- **Production:** ganti kredensial (regenerate) lewat UI Konfigurasi, set env production, isi Location ID prod.

---

## 6. Ringkas perubahan file

**Baru:**
- `Docs/PLAN-BRIDGING-SATUSEHAT-ARUMED.md` (ini)
- `backend/app/Services/Satusehat/SatusehatClient.php`
- 1 migration (employees.nik+ihs, medications.kfa_code, patients.satusehat_ihs)
- `arumed-frontend/src/views/bridging/BridgingSatusehatView.vue` (Fase 6 — dashboard monitoring)

**Diubah:**
- `backend/config/services.php` (+blok satusehat: base URL + env saja, TANPA secret — kredensial dari `IntegrationConfig`)
- `backend/app/Services/SatusehatService.php` (isi method + `dashboardStats()`)
- `backend/app/Http/Controllers/IntegrasiController.php` (+handler dashboard) & `routes/api.php` (+`/satusehat/dashboard`)
- Models: Employee, Medication, Patient (fillable)
- `routes/console.php` / Kernel (scheduler)
- `arumed-frontend/src/views/bridging/BridgingLayout.vue` (+tab "Satu Sehat") & router (+route)
- `arumed-frontend/src/views/bridging/BridgingKonfigurasiView.vue` (wire kartu SATUSEHAT — sudah ada UI-nya)
- `arumed-frontend/src/views/bridging/BridgingLogView.vue` (tampilkan log Satu Sehat)
- UI master: Data Pengguna (nik dokter), **master Obat di menu Inventori Farmasi (kfa_code)**

**Tidak disentuh:** alur klinis, BPJS/VClaim, billing, queue.
