# ARUMED — Plan Bridging BPJS Kesehatan (VClaim + Antrean RS)

> Status: **PLANNING**. Credential belum turun. Strategi: bangun **semua fondasi teknis + UI** sekarang dengan credential kosong, sehingga begitu `cons_id`/`secretKey`/`user_key` turun dari Trustmark BPJS, tinggal **paste di UI → Test → Aktifkan**, bridging hidup tanpa sentuh kode.
>
> Trustmark = **VClaim BPJS** → `cons_id` + `secretKey` SATU pasang per faskes (dipakai VClaim & Antrean), `user_key` BEDA per layanan.

---

## 0. Daftar Isi
1. Tujuan & Prinsip
2. Kondisi Repo Saat Ini (audit)
3. Arsitektur Target
4. Skema Autentikasi & Enkripsi BPJS
5. Tahapan Eksekusi (Poin 1–5)
6. Tabel Spec Endpoint — **DIISI USER** (VClaim)
7. Tabel Spec Endpoint — **DIISI USER** (Antrean RS)
8. Format Request/Response yang Saya Butuhkan dari Anda
9. UI Menu Bridging — rancangan
10. Keamanan Credential
11. Checklist Administratif (paralel, tugas Anda)
12. Definisi Selesai (DoD)

---

## 1. Tujuan & Prinsip

- **Zero-code activation**: setelah merge, mengaktifkan BPJS = pekerjaan admin di UI, bukan developer.
- **Aman saat credential kosong**: semua method melempar `503` jelas ("Integrasi belum diaktifkan"), TIDAK crash, TIDAK memblokir flow non-BPJS.
- **Tidak menyentuh flow existing** kecuali titik wiring yang disepakati (Admisi, QueueService, Kasir).
- **Satu sumber auth**: helper HMAC + decrypt dipakai bersama VClaim & Antrean (DRY).
- **Audit penuh**: tiap call tercatat di `bpjs_vclaim_logs` / `bpjs_antrean_logs` (request, response, status, sukses/gagal).

---

## 2. Kondisi Repo Saat Ini (audit 2026-05-29)

### Sudah ada (kerangka)
| Komponen | Lokasi | Catatan |
|---|---|---|
| Model config | `app/Models/IntegrationConfig.php` | `credentials`/`configuration` cast `array`. ⚠️ belum encrypted. |
| Tabel config | `migrations/2026_05_11_000001_create_integration_configs_table.php` | kolom `system_name`, `is_enabled`, `base_url`, `credentials` (jsonb), `configuration`, `last_test_status`, `last_tested_at`, `notes`. |
| Service VClaim | `app/Services/BpjsVClaimService.php` | semua method `placeholder()`. |
| Service Antrean | `app/Services/BpjsAntreanService.php` | semua method `placeholder()`. |
| Orchestrator | `app/Services/IntegrasiService.php` | config CRUD + test + log readers. |
| Controller | `app/Http/Controllers/IntegrasiController.php` | 25+ endpoint `/integrasi/*` aktif. |
| Routes | `routes/api.php` ~baris 888 | prefix `integrasi`. |
| Tabel log | `bpjs_vclaim_logs`, `bpjs_antrean_logs`, `bpjs_icare_logs` | siap. |
| Tabel data | `bpjs_claims`, `bpjs_referrals_in`, `bpjs_referrals_out`, `bpjs_control_letters` | siap. |

### Belum ada (akan dibangun)
- Helper auth nyata (HMAC signature) + decrypt response (AES-256-CBC + LZ-String).
- Library LZ-String PHP (composer).
- Implementasi HTTP call nyata di tiap method.
- Method Antrean yang lengkap (add/updatewaktu/batal/dashboard wajib).
- Seeder baris `integration_configs` untuk VCLAIM & ANTREAN.
- **UI menu Bridging** (frontend) — belum ada `IntegrasiView.vue`, route, store, item sidebar.
- Cast `encrypted:array` untuk credential.

---

## 3. Arsitektur Target

```
                       ┌─────────────────────────────────────┐
                       │  UI Menu "Bridging" (frontend)       │
                       │  - Tab Koneksi: paste cred + Test     │
                       │  - Tab Log VClaim / Antrean           │
                       │  - Tab Rujukan / SEP / Surat Kontrol  │
                       └──────────────┬──────────────────────┘
                                      │ axios /integrasi/*
                       ┌──────────────▼──────────────────────┐
                       │  IntegrasiController + IntegrasiService│
                       └──────────────┬──────────────────────┘
              ┌───────────────────────┼───────────────────────┐
              ▼                       ▼                        ▼
   ┌──────────────────┐   ┌──────────────────┐    ┌────────────────────┐
   │ BpjsVClaimService │   │ BpjsAntreanService│    │  (iCare/INA nanti)  │
   └────────┬─────────┘   └────────┬─────────┘    └────────────────────┘
            │                      │
            └──────────┬───────────┘
                       ▼
            ┌────────────────────────┐     baca credential dari
            │  BpjsClient (BARU)      │◄──── IntegrationConfig (encrypted)
            │  - buildHeaders() HMAC  │
            │  - request()            │
            │  - decryptResponse()    │ AES-256-CBC + LZ-String
            │  - log() ke tabel log   │
            └───────────┬────────────┘
                        ▼
            apijkn(-dev).bpjs-kesehatan.go.id
```

**Keputusan desain:** buat satu kelas baru `App\Services\Bpjs\BpjsClient` yang menampung auth+http+decrypt. `BpjsVClaimService` & `BpjsAntreanService` meng-inject `BpjsClient`, fokus ke pembentukan path + parse hasil. Ini menghindari duplikasi signature di dua service.

---

## 4. Skema Autentikasi & Enkripsi BPJS

### 4.1 Header (semua request VClaim & Antrean)
```
X-cons-id   : {cons_id}
X-timestamp : {unix epoch detik, UTC}
X-signature : base64_encode( hash_hmac('sha256', "{cons_id}&{timestamp}", secretKey, true) )
user_key    : {user_key sesuai layanan}    ← VClaim & Antrean BEDA
Content-Type: application/json; charset=utf-8
Accept      : application/json
```

### 4.2 Dekripsi response VClaim
Field `response` (string) → JSON asli melalui:
```
1. key  = hex2bin( hash('sha256', cons_id . secretKey . timestamp) ) → ambil 32 byte
   (catatan: BPJS pakai SHA-256 string sbg key 32 char, IV = 16 char pertama)
2. plain = openssl_decrypt(base64_decode(response), 'AES-256-CBC', key, OPENSSL_RAW_DATA, iv)
3. json  = LZString::decompressFromEncodedURIComponent(plain)
4. data  = json_decode(json, true)
```
> `timestamp` untuk key = timestamp yang DIKIRIM di request itu. Wajib disimpan per-request.

### 4.3 Catatan Antrean RS
- Header signature SAMA.
- Sebagian besar endpoint Antrean **response JSON polos (tidak terenkripsi)** — tapi ada pengecualian; akan dikonfirmasi per endpoint via tabel §7.
- `BpjsClient::request()` punya flag `$encrypted` (default true VClaim, false Antrean) → tinggal set per call.

### 4.4 Library
- `composer require nullpunkt/lz-string-php` (atau ekuivalen). Akan dikunci versinya saat Poin 1.

### 4.5 Sinkronisasi waktu
Server WIB (Asia/Jakarta) sudah benar. `X-timestamp` dihitung sebagai epoch UTC (`time()` di PHP sudah UTC-based). Pastikan NTP host sinkron — drift > beberapa menit → signature ditolak BPJS.

---

## 5. Tahapan Eksekusi

### Poin 1 — Fondasi auth & enkripsi (backend)
- [ ] `composer require` LZ-String PHP.
- [ ] Buat `app/Services/Bpjs/LZString.php` (bila library tak memadai) atau wrapper.
- [ ] Buat `app/Services/Bpjs/BpjsClient.php`: `buildHeaders()`, `request($method,$path,$body,$encrypted)`, `decryptResponse()`, `log()`.
- [ ] `IntegrationConfig`: ubah cast `credentials`/`configuration` → `encrypted:array`. (Migrasi data lama tidak perlu — masih kosong.)
- [ ] `BpjsVClaimService` & `BpjsAntreanService` inject `BpjsClient`.
- [ ] `testConnection()` nyata (endpoint ringan; ditentukan via tabel).

### Poin 2 — Endpoint VClaim lengkap
Implementasi tiap method sesuai tabel §6: Peserta, Rujukan, SEP, Surat Kontrol, Referensi, Klaim. Tetap aman saat credential kosong.

### Poin 3 — Endpoint Antrean RS lengkap
Implementasi tiap method sesuai tabel §7: add, updatewaktu, batal, sisa-antrean, dashboard wajib (waktu tunggu, dll).

### Poin 4 — Seeder config + test per layanan
- [ ] Seeder pastikan baris `VCLAIM` & `ANTREAN` ada (is_enabled=false, base_url default DEV).
- [ ] Endpoint test sudah dipetakan ke endpoint ringan nyata.

### Poin 5 — UI Menu Bridging (frontend)
- [ ] `views/integrasi/IntegrasiView.vue` (+ sub-view bila perlu).
- [ ] `stores/integrasiStore.js`.
- [ ] Route `/integrasi` + item sidebar (permission baru, lihat §10).
- [ ] Tab Koneksi (paste cred + Test + Aktifkan), Tab Log VClaim, Tab Log Antrean.

### (Nanti) Poin 6 — Wiring ke flow
Admisi (checkPeserta + checkRujukan + SEP), QueueService (Antrean updatewaktu), Anjungan (aktifkan tab BPJS), Kasir/Klaim. **Di luar scope plan awal; dikerjakan setelah test koneksi DEV hijau.**

---

## 6. Tabel Spec Endpoint — VClaim — **DIISI USER**

Isi kolom yang masih `?`. Path relatif terhadap base_url VClaim
(`https://apijkn-dev.bpjs-kesehatan.go.id/vclaim-rest` dev / `.../vclaim-rest` prod — konfirmasi).

| # | Fungsi | HTTP | Path (relatif) | Encrypted resp? | Status |
|---|---|---|---|---|---|
| V1 | Cek peserta by NIK | GET | `/Peserta/nik/{nik}/tglSEP/{tgl}` | ya | draft |
| V2 | Cek peserta by no kartu | GET | `/Peserta/nokartu/{noka}/tglSEP/{tgl}` | ya | draft |
| V3 | Cek rujukan by no rujukan (FKTP) | GET | `/Rujukan/{noRujukan}` | ya | draft |
| V4 | Cek rujukan by no kartu | GET | `/Rujukan/Peserta/{noka}` | ya | draft |
| V5 | Insert SEP | POST | `/SEP/2.0/insert` | ya | draft |
| V6 | Update SEP | PUT | `/SEP/2.0/update` | ya | draft |
| V7 | Hapus SEP | DELETE | `/SEP/2.0/delete` | ya | draft |
| V8 | Cek SEP by no SEP | GET | `/SEP/{noSEP}` | ya | draft |
| V9 | Insert Surat Kontrol | POST | `/RencanaKontrol/insert` | ya | draft |
| V10 | List Surat Kontrol | GET | `/RencanaKontrol/ListRencanaKontrol/...` | ya | draft |
| V11 | Referensi Diagnosa (ICD-10) | GET | `/referensi/diagnosa/{kode}` | ya | draft |
| V12 | Referensi Poli | GET | `/referensi/poli/{kode}` | ya | draft |
| V13 | Referensi Dokter DPJP | GET | `/referensi/dokter/pelayanan/{jnsPelayanan}/tglPelayanan/{tgl}/spesialis/{kdSpesialis}` | ya | draft |
| V14 | Referensi Faskes | GET | `/referensi/faskes/{kode}/{jnsFaskes}` | ya | draft |
| ... | (tambah sesuai kebutuhan) | | | | |

> Path di atas adalah **dugaan berdasarkan dokumentasi umum VClaim 2.0**. TOLONG koreksi sesuai dokumen Trustmark Anda. Tanda "draft" → ganti "fix" setelah Anda verifikasi.

---

## 7. Tabel Spec Endpoint — Antrean RS — **DIISI USER**

Base_url Antrean (`https://apijkn-dev.bpjs-kesehatan.go.id/antreanrs` dev — konfirmasi).

| # | Fungsi | HTTP | Path (relatif) | Encrypted resp? | Status |
|---|---|---|---|---|---|
| A1 | Tambah antrean | POST | `/antrean/add` | tidak | draft |
| A2 | Update status/waktu task | POST | `/antrean/updatewaktu` | tidak | draft |
| A3 | Batal antrean | POST | `/antrean/batal` | tidak | draft |
| A4 | Sisa antrean (per kodebooking) | POST | `/antrean/getlistantrean` | tidak | draft |
| A5 | Ambil antrean (status) | POST | `/antrean/getantreanbelumdilayani` | tidak | draft |
| A6 | Ref poli (mapping ke BPJS) | GET | `/ref/poli` | tidak | draft |
| A7 | Ref dokter | GET | `/ref/dokter` | tidak | draft |
| A8 | Jadwal dokter (kirim ke BPJS) | POST | `/jadwaldokter/...` | tidak | draft |
| A9 | Dashboard — waktu tunggu per tanggal | POST | `/dashboard/waktutunggu/tanggal/...` | tidak | draft |
| A10 | Dashboard — per bulan | POST | `/dashboard/waktutunggu/bulan/...` | tidak | draft |
| ... | (tambah sesuai kebutuhan) | | | | |

> Antrean RS mewajibkan pelaporan tiap perubahan status (taskid 1–7: mulai daftar → selesai dilayani → selesai farmasi). Mapping taskid ke stasiun Arumed akan dibuat di §Poin 6.

---

## 8. Format Request/Response yang Saya Butuhkan dari Anda

Untuk tiap fungsi yang Anda anggap prioritas, kirim dalam format ini (paste apa adanya dari dokumen Trustmark):

```
### [V5] Insert SEP
PATH    : POST /SEP/2.0/insert
REQUEST :
{
  "request": {
    "t_sep": {
      "noKartu": "...",
      "tglSep": "2026-05-29",
      "ppkPelayanan": "{kdppk}",
      "jnsPelayanan": "2",
      "klsRawat": { "klsRawatHak": "...", "klsRawatNaik": "", "pembiayaan": "", "penanggungJawab": "" },
      "noMR": "...",
      "rujukan": { "asalRujukan": "1", "tglRujukan": "...", "noRujukan": "...", "ppkRujukan": "..." },
      "catatan": "...",
      "diagAwal": "...",
      "poli": { "tujuan": "...", "eksekutif": "0" },
      ...
    }
  }
}
RESPONSE (sukses):
{
  "metaData": { "code": "200", "message": "Sukses" },
  "response": { "sep": { "noSep": "...", "tglSep": "..." } }
}
RESPONSE (gagal):
{ "metaData": { "code": "201", "message": "Data tidak ditemukan" }, "response": null }
```

**Yang paling saya butuhkan:**
1. **Base URL pasti** untuk DEV (VClaim & Antrean) — sering beda akhiran (`/vclaim-rest`, `/antreanrs`).
2. Untuk tiap endpoint prioritas: **path final, struktur body request, struktur response sukses & gagal**.
3. **`metaData.code` sukses** (biasanya `"200"` / `"1"`) supaya parser tahu kapan dianggap berhasil.
4. Apakah response **terenkripsi** (VClaim ya, Antrean biasanya tidak — konfirmasi per endpoint kalau ada kekecualian).

> Anda tidak perlu kirim semuanya sekaligus. Kita isi bertahap: mulai dari **test koneksi + cek peserta + cek rujukan + insert SEP** (VClaim), dan **add + updatewaktu** (Antrean). Sisanya menyusul.

---

## 9. UI Menu Bridging — Rancangan

Lokasi: sidebar grup "Pengaturan" / "Integrasi" → route `/integrasi`.

**Tab 1 — Koneksi**
- Kartu per sistem (VCLAIM, ANTREAN; iCare/INA/SatuSehat disabled/coming-soon).
- Field: `Base URL`, `cons_id`, `secretKey` (password, toggle show), `user_key`, `kode faskes (kdppk)`.
- Tombol **Simpan** (PUT `/integrasi/config/{id}`), **Test Koneksi** (POST `/integrasi/test/{system}`), **toggle Aktif** (`is_enabled`).
- Badge status: last_test_status + last_tested_at + indikator "Credential terisi".

**Tab 2 — Log VClaim** — tabel dari `/integrasi/bpjs/vclaim-log` (filter action/sukses/tanggal), klik baris → detail request/response JSON.

**Tab 3 — Log Antrean** — tabel dari `/integrasi/bpjs/antrean-log`.

**Tab 4 — Data BPJS** (read-only awal): Rujukan Masuk/Keluar, Surat Kontrol.

Styling ikut konvensi user: tombol primary `#1763d4`, teks `#000`, hindari `var(--pri)` polos.

---

## 10. Keamanan Credential

- Cast `credentials` → `encrypted:array` (terenkripsi di DB pakai APP_KEY).
- Response config ke frontend: **mask** `secretKey`/`user_key` (kirim `••••1234` + flag `has_credentials`), kirim plain hanya field non-rahasia (base_url, cons_id boleh, kdppk boleh).
- Saat simpan: jika field rahasia dikirim kosong → pertahankan nilai lama (jangan timpa dengan kosong).
- Permission baru: `integrasi.read` / `integrasi.write` (Superadmin bypass). Default hanya superadmin/admin. Tambah ke `RolePermissionSeeder`.
- Audit tiap perubahan config via `SystemLog` (sudah ada di `IntegrasiService::updateConfig`).

---

## 11. Checklist Administratif (paralel — tugas Anda)

- [ ] PKS BPJS aktif + kode faskes (`kdppk`) di tangan.
- [ ] Faskes terdaftar & poli/dokter ter-mapping di **HFIS**.
- [ ] Ajukan credential **DEV** VClaim (cons_id, secretKey, user_key VClaim) via Trustmark.
- [ ] Ajukan credential **DEV** Antrean RS (user_key Antrean — beda dari VClaim).
- [ ] Mapping kode poli & kode dokter internal Arumed ↔ kode BPJS (dibutuhkan SEP & Antrean add).
- [ ] Setelah uji DEV lolos → ajukan credential **PROD**.

---

## 12. Definisi Selesai (DoD) — fase plan ini

1. Buka menu Bridging → isi base URL + cons_id/secretKey/user_key VCLAIM → **Test** → hijau (di DEV).
2. Sama untuk ANTREAN.
3. Aktifkan toggle → `checkPeserta` mengembalikan data peserta nyata dari DEV.
4. Semua call tercatat di log dengan request/response terbaca.
5. Flow non-BPJS existing tidak berubah & tidak error saat BPJS nonaktif.

---

## Log Revisi Plan
- 2026-05-29 — draft awal dibuat (struktur + tabel kosong untuk diisi user).
