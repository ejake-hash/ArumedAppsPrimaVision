# Plan Integrasi E-Klaim INA-CBG ↔ SIMRS Arumed

> Dokumen eksekusi untuk Claude Code. Tujuan: menghubungkan backend Arumed
> (Laravel 13.8) ke aplikasi **E-Klaim INA-CBG build 5.10.7** lewat Web Service
> terenkripsi (`ws.php`), untuk method **kirim & grouping klaim, finalisasi,
> dan baca/cek/edit**.
>
> Cara pakai dokumen ini di Claude Code: kerjakan fase berurutan dari atas.
> **Fase 0 adalah gerbang — jangan lanjut ke Fase 2+ sebelum Fase 0 hijau.**

---

## 0. Ringkasan Lingkungan (terverifikasi langsung dari sistem)

| Item | Nilai | Status |
|------|-------|--------|
| Faskes | RSK MATA PRIMA VISION | Confirmed (UI E-Klaim) |
| Kode RS (E-Klaim) | `1275928` | Confirmed |
| Kelas / Instansi | Kelas C, Swasta | Confirmed |
| `kode_tarif` INA-CBG | **`CS`** | Confirmed (Setup Institusi) |
| Regional | Regional 2 (KMK 3 2023) | Confirmed |
| Build E-Klaim | `5.10.7.202603311031` | Confirmed |
| Stack E-Klaim | Apache/PHP 5.6 (Win32) di `192.168.100.19` | Confirmed (curl header) |
| Endpoint WS SIMRS | `http://192.168.100.19/E-Klaim/ws.php` | Confirmed (port 80 tembus dari server SIMRS) |
| Encryption Key | 64 karakter hex (32 byte = AES-256) | Confirmed format |
| `EKLAIM_KEY_ENCODING` | `hex` | Confirmed |
| Server SIMRS | Ubuntu `vision-PowerEdge-T40`, `/var/www`, Laravel PHP 8.3 | Confirmed |
| Konektivitas | ping 0% loss + HTTP 200 dari server SIMRS | Confirmed |

### Yang masih perlu dibuktikan (1 hal)
- **Urutan byte payload** terenkripsi: `IV.HMAC.CIPHERTEXT` (dugaan utama, High
  confidence) vs `IV.CIPHERTEXT.HMAC`. → Dibuktikan otomatis di **Fase 0**.

### Pemisahan kredensial — JANGAN tertukar
- **`EKLAIM_KEY`** = *Encryption Key* dari menu **Setup → Integrasi SIMRS** di
  E-Klaim. Ini yang dipakai integrasi ini.
- **Consumer ID/Secret** (menu Integrasi BPJS, host `new-api.bpjs-kesehatan.go.id`)
  = untuk VClaim, **bukan** untuk WS E-Klaim.
- **`inacbg.kemkes.go.id/dc/ws.php`** (Integrasi Data Center Kemenkes) = kirim
  data ke pusat, **bukan** integrasi SIMRS lokal.

---

## 1. Prasyarat

1. Di aplikasi E-Klaim, login group admin → **Setup → Integrasi SIMRS** →
   klik **Generate Key** bila "Encryption Key" masih `–`. Catat key (64 hex).
2. Server SIMRS bisa `ping 192.168.100.19` dan `curl http://192.168.100.19/E-Klaim/`
   (sudah terbukti).
3. Pastikan IP `192.168.100.19` **statik / reservasi DHCP** agar tidak berubah.
4. Backend Arumed bisa diakses oleh Claude Code (repo `arumed-apps/backend`).

---

## 2. Arsitektur Integrasi

Mengikuti pola Arumed: **Controller (tipis) → Service (logic + integrasi) →
Model (Eloquent)**. Semua HTTP ke pihak ketiga lewat Service, semua call dicatat
ke tabel log.

```
KlaimController ──> KlaimService ──> InaCbgsService ──> EklaimCrypto
                                          │                  │
                                          │ HTTP POST        │ AES-256-CBC + HMAC
                                          ▼                  ▼
                              http://192.168.100.19/E-Klaim/ws.php
                                          │
                                          ▼
                              inacbgs_grouping_logs  (audit)
```

Alur fungsional klaim:
`new_claim → set_claim_data → grouper(1) → grouper(2)/claim_final`,
dengan `get_claim_data`/`get_claim_status` untuk sinkron balik dan
`reedit_claim`/`delete_claim` untuk koreksi.

---

## 3. Fase Eksekusi

### FASE 0 — Buktikan enkripsi (GERBANG, wajib lebih dulu)

Tujuan: pastikan skema enkripsi cocok dengan `ws.php` server ini sebelum
menulis kode integrasi penuh.

1. Salin `eklaim_ws_test.php` (file terlampir) ke server SIMRS, mis. `/var/www/`.
2. Edit `$KEY_HEX` → tempel Encryption Key utuh (64 hex).
3. Jalankan:
   ```bash
   php /var/www/eklaim_ws_test.php
   ```
4. Skrip mencoba kedua urutan byte otomatis. Catat hasil:
   - `BERHASIL DEKRIPSI dengan layout 'iv_hmac_ct'` → default `EklaimCrypto`
     sudah benar. Lanjut.
   - `... 'iv_ct_hmac'` → di Fase 1, **tukar urutan byte** di `EklaimCrypto.php`
     (lihat catatan di file).
   - **Kedua gagal** → body mungkin harus dibungkus form, atau method/param
     beda. Periksa Manual WS build 5.10 (lihat Sumber) sebelum lanjut.

> **STOP bila Fase 0 belum hijau.** Semua method akan gagal dengan gejala sama
> ("gagal dekripsi") kalau enkripsi salah.

### FASE 1 — Config & Crypto

1. Buat `backend/config/eklaim.php` (isi = file `config_eklaim.php` terlampir).
2. Buat `backend/app/Services/Eklaim/EklaimCrypto.php` (file terlampir).
   - Jika Fase 0 menang dengan `iv_ct_hmac`, ubah di `EklaimCrypto`:
     - `encrypt()`: `base64_encode($iv . $ciphertext . $hmac);`
     - `decrypt()`: `$hmac = substr($raw, -32); $ciphertext = substr($raw, 16, -32);`
3. Tambah ke `backend/.env`:
   ```
   EKLAIM_WS_URL=http://192.168.100.19/E-Klaim/ws.php
   EKLAIM_KEY=<64_hex_dari_e-klaim>
   EKLAIM_KEY_ENCODING=hex
   EKLAIM_KODE_TARIF=CS
   EKLAIM_TIMEOUT=30
   EKLAIM_VERIFY_SSL=false
   ```
4. `php artisan config:clear`
5. Smoke test crypto (tanpa jaringan):
   ```bash
   php artisan tinker
   >>> $c = new App\Services\Eklaim\EklaimCrypto(config('eklaim.key'), 'hex');
   >>> $c->decrypt($c->encrypt('{"x":1}'));   // harus kembalikan {"x":1}
   ```

### FASE 2 — InaCbgsService

1. Timpa/buat `backend/app/Services/InaCbgsService.php` (file terlampir).
2. Cocokkan nama kolom di method log (`startLog`/`finishLog`) dengan migration
   `2026_05_11_000008_create_inacbgs_grouping_logs_table.php`. Kolom dipakai:
   `bpjs_claim_id, method, request, response, status, response_code, message`.
   Jika nama beda, sesuaikan **kode** (atau buat migration tambahan).
3. Cek model `BpjsClaim` punya field yang dipakai: `nomor_kartu, nomor_sep,
   nomor_rm, nama_pasien, tgl_lahir, gender, coder_nik`. Tambah accessor/kolom
   bila belum ada.

### FASE 3 — Controller & Routes

1. Merge `KlaimController_wiring.php` ke
   `backend/app/Http/Controllers/KlaimController.php` (DI `InaCbgsService`).
2. Daftarkan route di `backend/routes/api.php` (dalam group `v1` + `auth:api`):
   ```php
   Route::prefix('klaim')->group(function () {
       Route::post('{id}/grouping',        [KlaimController::class, 'runGrouping']);
       Route::post('{id}/eklaim/new',      [KlaimController::class, 'eklaimNewClaim']);
       Route::post('{id}/eklaim/set-data', [KlaimController::class, 'eklaimSetData']);
       Route::post('{id}/final',           [KlaimController::class, 'submitKlaim']);
       Route::get ('{id}/eklaim/status',   [KlaimController::class, 'eklaimStatus']);
       Route::post('{id}/eklaim/reedit',   [KlaimController::class, 'eklaimReedit']);
   });
   ```
   (Endpoint `POST /klaim/{id}/grouping` & `GET /klaim/grouping-log/{id}` sudah
   ada di arsitektur — sinkronkan, jangan duplikat.)

### FASE 4 — Builder payload `set_claim_data`

Bagian paling spesifik-domain. Letakkan di `KlaimService::buildEklaimPayload(BpjsClaim $claim): array`.

Sumber data dari relasi Visit Arumed:
- Tanggal masuk/pulang ← `Visit`
- Diagnosa (ICD-10) ← `DoctorExamination` tab4 / `MedicalResume`
- Prosedur (ICD-9-CM) ← `VisitService` / tindakan
- Tarif RS ← `BillingInvoice` + `BillingItem`
- SEP/peserta ← `BpjsClaim`

> **PERLU VERIFIKASI** terhadap Manual WS 5.10: nama field (`nomor_sep` vs
> `nomor_rm`), format tanggal (`Y-m-d`), kode gender, separator diagnosa/prosedur
> (`;`), dan apakah `tarif` vs `kode_tarif`. Bangun builder ini SETELAH punya
> contoh struktur `data` dari Manual WS.

### FASE 5 — Frontend (opsional, KlaimView.vue)

Tambah tombol di `arumed-frontend/src/views/KlaimView.vue` yang memanggil
endpoint Fase 3 via `klaimApi`. Pertahankan **modal konfirmasi** untuk
`final` (irreversible).

### FASE 6 — Uji end-to-end (data dummy/ujicoba)

Urut: `eklaimNewClaim` → `eklaimSetData` → `runGrouping` → `eklaimStatus`.
Pastikan tiap call tercatat di `inacbgs_grouping_logs`. Verifikasi hasil
grouping (kode INA-CBG + tarif) muncul.

---

## 4. Daftar File

| File terlampir | Lokasi tujuan di repo |
|----------------|------------------------|
| `eklaim_ws_test.php` | `backend/` atau `/var/www/` (skrip Fase 0, hapus setelah selesai) |
| `config_eklaim.php` | `backend/config/eklaim.php` |
| `EklaimCrypto.php` | `backend/app/Services/Eklaim/EklaimCrypto.php` |
| `InaCbgsService.php` | `backend/app/Services/InaCbgsService.php` |
| `KlaimController_wiring.php` | merge ke `backend/app/Http/Controllers/KlaimController.php` |
| `CHECKLIST_INTEGRASI_EKLAIM.md` | dokumentasi internal |

---

## 5. Checklist Verifikasi (centang sebelum produksi)

- [ ] Fase 0 hijau — respons `ws.php` berhasil di-decrypt jadi JSON.
- [ ] Urutan byte di `EklaimCrypto` sesuai hasil Fase 0.
- [ ] `EKLAIM_KEY` di `.env`, **tidak** di-commit ke git.
- [ ] IP `192.168.100.19` statik / reservasi DHCP.
- [ ] Nama kolom `inacbgs_grouping_logs` cocok dengan kode service.
- [ ] Field `BpjsClaim` lengkap (SEP, RM, kartu, dll).
- [ ] Nama method WS (`claim_final` vs `final_claim`) dikonfirmasi ke Manual WS 5.10.
- [ ] Builder payload `set_claim_data` dikonfirmasi ke Manual WS 5.10.
- [ ] `kode_tarif=CS` benar (sudah Confirmed dari UI).
- [ ] Modal konfirmasi `final` aktif di frontend.
- [ ] Setiap call tercatat di `inacbgs_grouping_logs`.

---

## 6. Risiko & Tradeoff

- **Gerbang tunggal = crypto.** Salah urutan byte/key → semua gagal serupa.
  Selesaikan Fase 0 dulu.
- **`claim_final` praktis irreversible** (hanya bisa dibuka via `reedit_claim`).
  Pertahankan konfirmasi UI.
- **Key = kredensial.** Regenerate Key di E-Klaim akan mematikan key lama →
  `.env` harus diupdate. Generate sekali.
- **Method/param berbeda antar build.** Dokumen ini akurat untuk *bentuk umum*;
  detail field 5.10 tetap perlu dicek ke Manual WS resmi (Sumber).
- **PHP 5.6 di sisi E-Klaim** tidak berpengaruh ke Arumed (PHP 8.3); hanya
  relevan jika ada kuirk TLS/cipher lama — kita pakai HTTP LAN biasa.

---

## 7. Sumber

- Integrasi E-Klaim (lokasi config WS, `ws.php`, `kode_tarif`) — SIMGos V2 docs:
  https://docs.simgos2.simpel.web.id/docs/integrasi/kemenkes/e-klaim/
- Halaman Download resmi INA-CBG (Manual Web Service per build) — Kemenkes:
  https://inacbg.kemkes.go.id/index.php?XP_view=1&page=download
- Web Service E-Klaim INA-CBG (skema enkripsi AES-256-CBC + HMAC-SHA256):
  https://www.slideshare.net/patenpisan/web-service-eklaim-inacbg
- Web Service E-Klaim INA-CBG untuk Build 5.8.8 (referensi method, perlu
  dibandingkan dgn 5.10): https://id.scribd.com/document/762116316/Web-Service-E-Klaim-INA-CBG-Untuk-Build-5-8-8

> Sumber resmi (Manual WS dari inacbg.kemkes.go.id) gated untuk faskes terdaftar.
> Detail bertanda "PERLU VERIFIKASI" di dokumen ini hanya bisa difinalkan dari
> Manual WS build 5.10.x milik Anda atau dari hasil Fase 0.

---
*Faskes 1275928 — RSK Mata Prima Vision. Disusun 2026-06-01.*
