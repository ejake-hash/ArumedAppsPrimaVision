# PLAN — Bridging BPJS VClaim & Antrean ke Arumed Apps

> Disusun 2026-05-29. Sumber: `Docs/BRIDGING VCLAIM.md` (spec resmi BPJS) + audit repo aktual.
> Konteks: Arumed = **Klinik Mata** (FKRTL rawat jalan + bedah katarak). Jadi **fokus endpoint Rawat Jalan (RJ)**. Endpoint Rawat Inap (RANAP), INA-CBGs grouper, LPK ranap = **out-of-scope / low-priority** (klinik tidak ranap).

---

## 0. Ringkasan Eksekutif

**Strategi tetap "zero-code activation"** (sesuai keputusan di doc): bangun fondasi teknis + UI sekarang dengan credential kosong. Begitu `cons_id` / `secretKey` / `user_key` turun dari Trustmark, admin tinggal **paste di UI Bridging → Test → Aktifkan**.

Yang **sudah ada** (audit aktual, bukan klaim doc):
- Backend: `IntegrationConfig` model + tabel, `BpjsVClaimService` + `BpjsAntreanService` (semua `placeholder()`), `IntegrasiService` (config CRUD + test + log readers), `IntegrasiController` (21 endpoint `/integrasi/*` aktif), 7 tabel log/data BPJS, kolom BPJS di `visits` (`no_sep`, `bpjs_booking_code`, `bpjs_antrean_number`, `bpjs_referral_in_id`, `bpjs_control_letter_id`).

Yang **BELUM ada** (harus dibangun):
1. **Helper auth nyata** — HMAC-SHA256 signature + decrypt (AES-256-CBC + LZ-String) → kelas baru `App\Services\Bpjs\BpjsClient`.
2. **Library LZ-String** — `composer require nullpunkt/lz-string-php`.
3. **Implementasi HTTP call nyata** di tiap method VClaim & Antrean (sekarang placeholder).
4. **Method Antrean lengkap** (add antrean, updatewaktu, batal, sisa antrean, dashboard wajib).
5. **Cast `encrypted:array`** untuk kolom `credentials` (sekarang `array` polos, tidak terenkripsi).
6. **Seeder** baris `integration_configs` untuk VCLAIM & ANTREAN.
7. **SELURUH Frontend** — `IntegrasiView.vue`, route, `integrasiStore.js`, item sidebar. **(belum ada satupun)**.
8. **Wiring ke flow existing** — Admisi (cari peserta/rujukan + terbitkan SEP), Anjungan (validasi booking JKN), Dokter (rencana kontrol/SPRI), Kasir (LPK saat selesai).

---

## 1. Arsitektur Backend yang Diusulkan

```
App\Services\Bpjs\
 ├── BpjsClient.php         ← BARU. Auth (HMAC) + HTTP + decrypt. DRY untuk VClaim & Antrean.
 ├── BpjsVClaimService.php  ← isi method placeholder → call nyata via BpjsClient.
 └── BpjsAntreanService.php ← idem + tambah method Antrean wajib.
```

**`BpjsClient`** menampung:
- `signature($timestamp)` → `base64(hmac_sha256(consId&timestamp, secretKey))`.
- `headers($timestamp)` → `X-cons-id`, `X-timestamp`, `X-signature`, `user_key`, `Content-Type`.
- `request($method, $path, $body, $encrypted=true)` → kirim, lalu kalau `$encrypted` → `decrypt(consId.consPwd.timestamp)` + `LZString::decompressFromEncodedURIComponent`.
- Flag `$encrypted`: **true** untuk VClaim (response terenkripsi), **false** untuk mayoritas Antrean (JSON polos), set per-call.
- `503` jelas kalau config belum aktif (tidak crash, tidak blok flow non-BPJS).

**Keputusan dipakai dari doc §3:** `BpjsVClaimService` & `BpjsAntreanService` inject `BpjsClient`; fokus mereka cuma bentuk path + parse hasil. Hindari duplikasi signature.

---

## 2. PETA ENDPOINT → LOKASI DI ARUMED

Legenda kolom **"Taruh di mana"**:
- **BE** = backend service method (+ controller endpoint kalau di-expose ke UI).
- **UI** = view/komponen frontend tempat user memicunya.
- **Prioritas**: 🔴 wajib MVP klinik mata RJ · 🟡 berguna · ⚪ out-of-scope (ranap/grouper).

### 2.1 PESERTA (cek eligibility)

| Endpoint BPJS | Fungsi | BE | UI | Prio |
|---|---|---|---|---|
| `GET /Peserta/nokartu/{no}/tglSEP/{tgl}` | Cek peserta by No.Kartu | `BpjsVClaimService::checkPeserta($no,'nokartu',$tgl)` | **AdmisiView** — tombol "Cek BPJS" di form pendaftaran (samping input No.Kartu). Tampilkan nama/hakKelas/statusPeserta/cob. | 🔴 |
| `GET /Peserta/nik/{nik}/tglSEP/{tgl}` | Cek peserta by NIK | sama, param `'nik'` | sama (toggle NIK / No.Kartu) | 🔴 |

**Wiring**: `AdmisiController` tambah endpoint `POST /admisi/bpjs/cek-peserta` → panggil service. Hasil dipakai prefill `visits.insurer_id` (BPJS) + simpan `hakKelas` / status ke draft kunjungan. Endpoint baru, **tidak ganggu flow UMUM**.

### 2.2 RUJUKAN (wajib untuk SEP RJ)

| Endpoint BPJS | Fungsi | BE | UI | Prio |
|---|---|---|---|---|
| `GET /Rujukan/RS/{noRujukan}` | Cari rujukan by nomor | `checkRujukan($no)` (sudah ada, isi nyata) | **AdmisiView** — input No.Rujukan → tombol "Cek Rujukan" | 🔴 |
| `GET /Rujukan/RS/Peserta/{noKartu}` | Rujukan terakhir by kartu (1) | `getRujukanByKartu($no)` | AdmisiView — auto saat cek peserta | 🟡 |
| `GET /Rujukan/RS/List/Peserta/{noKartu}` | Semua rujukan by kartu | `listRujukanByKartu($no)` | AdmisiView — modal pilih rujukan | 🟡 |
| `POST /Rujukan/2.0/insert` · `PUT .../Update` · `DELETE .../delete` | Buat/ubah/hapus **rujukan keluar** (rujuk ke RS lain) | `insertRujukanKeluar/updateRujukanKeluar/deleteRujukanKeluar` | **DokterView** (saat dokter memutuskan rujuk keluar) + tab "Rujukan Keluar" di **IntegrasiView** | 🟡 |
| `GET /Rujukan/Keluar/List/...` · `GET /Rujukan/Keluar/{no}` | Daftar/detail rujukan keluar | `listRujukanKeluar/showRujukanKeluar` | **IntegrasiView → tab Rujukan Keluar** (sudah ada endpoint reader `/integrasi/bpjs/rujukan-keluar`, sambungkan ke VClaim) | 🟡 |
| `GET /Rujukan/ListSpesialistik/...` · `GET /Rujukan/ListSarana/...` · `GET /Rujukan/JumlahSEP/...` | Referensi saat insert rujukan | helper di service, dipanggil saat form rujukan keluar | dropdown di form rujukan keluar (DokterView) | ⚪ |

**Catatan**: rujukan **masuk** (`bpjs_referrals_in`) di-resolve via `checkRujukan` saat Admisi; rujukan **keluar** (`bpjs_referrals_out`) dibuat dokter. Tabel + reader endpoint sudah ada.

### 2.3 SEP — Surat Eligibilitas Peserta (jantung bridging RJ)

| Endpoint BPJS | Fungsi | BE | UI | Prio |
|---|---|---|---|---|
| `POST /SEP/2.0/insert` | Terbitkan SEP | `generateSep($data)` (sudah ada, isi nyata + mapping field) | **AdmisiView** — tombol "Terbitkan SEP" setelah peserta+rujukan valid. Simpan `visits.no_sep`. | 🔴 |
| `PUT /SEP/2.0/update` | Update SEP | `updateSep($data)` | AdmisiView / IntegrasiView detail SEP | 🟡 |
| `DELETE /SEP/2.0/delete` | Batalkan SEP | `cancelSep($noSep,$alasan)` (sudah ada, isi nyata) | AdmisiView (batal hari sama) + IntegrasiView | 🔴 |
| `PUT /SEP/2.0/updtglplg` | Update tgl pulang | `updateTglPulang(...)` | ⚪ (ranap) — skip | ⚪ |
| `GET /Sep/updtglplg/list/...` | List update tgl pulang | — | ⚪ | ⚪ |
| `GET /sep/cbg/{noSep}` | SEP untuk INA-CBGs 4.1 (XML) | `getSepForInacbg` | ⚪ (grouper aplikasi terpisah) | ⚪ |
| `GET /SEP/Internal/{noSep}` · `DELETE /SEP/Internal/delete` | SEP rujukan internal antar poli | ⚪ (RS besar, multi-poli) | ⚪ | ⚪ |
| `GET /SEP/FingerPrint/...` (4 endpoint) | Validasi fingerprint peserta | `getFingerprint / listFingerprint / randomQuestion / postRandomAnswer` | **AnjunganView** atau AdmisiView (badge "sudah finger") — opsional, butuh device | ⚪ |

**Mapping field `SEP/2.0/insert`** (dari data Arumed): `noKartu`←peserta, `tglSep`←hari ini, `ppkPelayanan`←kode faskes (config), `jnsPelayanan="2"` (RJ), `klsRawatHak`←hasil cek peserta, `noMR`←`patients.no_rm`, `rujukan`←hasil cek rujukan, `diagAwal`←ICD-10 awal, `poli.tujuan`←mapping poli klinik→kode poli BPJS, `katarak`←flag (RELEVAN klinik mata!), `tujuanKunj`, `dpjpLayan`←kode DPJP. **Butuh master mapping**: poli Arumed → kode poli BPJS, dokter Arumed → kode DPJP BPJS.

### 2.4 RENCANA KONTROL & SPRI

| Endpoint BPJS | Fungsi | BE | UI | Prio |
|---|---|---|---|---|
| `POST /RencanaKontrol/v2/Insert` | Buat surat kontrol (jnsKontrol 2) | `insertRencanaKontrol($data)` (samakan dgn `postSuratKontrol` yang sudah ada) | **DokterView** — saat dokter rencanakan kontrol ulang (sudah ada flag `planning_follow_up`/`follow_up_date` di visits!) | 🔴 |
| `PUT /RencanaKontrol/v2/Update` · `DELETE /RencanaKontrol/Delete` | Ubah/hapus | `updateRencanaKontrol/deleteRencanaKontrol` | DokterView + IntegrasiView | 🟡 |
| `POST /RencanaKontrol/InsertSPRI` · `PUT .../UpdateSPRI` | SPRI (kontrol tanpa SEP asal) | `insertSpri/updateSpri` | ⚪ (SPRI utamanya ranap) | ⚪ |
| `GET /RencanaKontrol/nosep/{no}` · `.../noSuratKontrol/{no}` | Cari SEP/surat kontrol | `cariSepKontrol/cariSuratKontrol` | AdmisiView (pasien datang dengan surat kontrol → prefill) | 🔴 |
| `GET /RencanaKontrol/ListRencanaKontrol/...` (by kartu / by tgl) | Daftar rencana kontrol | `listRencanaKontrol*` | **IntegrasiView → tab Surat Kontrol** (reader `/integrasi/bpjs/surat-kontrol` sudah ada) | 🟡 |
| `GET /RencanaKontrol/ListSpesialistik/...` · `.../JadwalPraktekDokter/...` | Kuota poli + jadwal dokter saat buat kontrol | helper dipanggil form kontrol | dropdown di form rencana kontrol (DokterView) | 🟡 |

**Wiring kunci**: visits SUDAH punya `planning_follow_up` + `follow_up_date` + `bpjs_control_letter_id`. Saat dokter set kontrol untuk pasien BPJS → otomatis draft `bpjs_control_letters` (DRAFT) → submit ke VClaim (endpoint submit sudah ada di `IntegrasiService::submitSuratKontrol`, tinggal isi `postSuratKontrol` nyata + ganti ke `RencanaKontrol/v2/Insert`).

### 2.5 LPK — Lembar Pengajuan Klaim

| Endpoint BPJS | Fungsi | BE | UI | Prio |
|---|---|---|---|---|
| `POST /LPK/insert` · `PUT /LPK/update` · `DELETE /LPK/delete` | Kirim data pelayanan utk klaim (terkait SEP) | `insertLpk/updateLpk/deleteLpk` | **KasirView** — saat kunjungan BPJS selesai/dibayar, kirim LPK (diagnosa+procedure+DPJP+rencanaTL) | 🟡 |
| `GET /LPK/TglMasuk/{tgl}/JnsPelayanan/2` | Daftar LPK | `listLpk($tgl)` | IntegrasiView → tab Klaim | 🟡 |

**Wiring**: LPK butuh data yang sudah lengkap di Arumed (diagnosa ICD-10 dari DokterView, procedure ICD-9, DPJP). Pemicu: di **KasirView** saat `processPayment` untuk visit BPJS, atau tombol manual "Kirim LPK" di IntegrasiView. **`rencanaTL.kontrolKembali`** nyambung ke rencana kontrol (§2.4).

### 2.6 MONITORING

| Endpoint BPJS | Fungsi | BE | UI | Prio |
|---|---|---|---|---|
| `GET /Monitoring/Kunjungan/Tanggal/{tgl}/JnsPelayanan/2` | Data kunjungan per tgl | `monitoringKunjungan($tgl)` | **IntegrasiView → tab Monitoring** | 🟡 |
| `GET /Monitoring/Klaim/Tanggal/.../Status/{s}` | Data klaim + status verifikasi | `monitoringKlaim(...)` | IntegrasiView → tab Monitoring/Klaim (rekonsiliasi vs data lokal) | 🟡 |
| `GET /monitoring/HistoriPelayanan/NoKartu/...` | Histori pelayanan peserta | `historiPelayanan($no,$from,$to)` | AdmisiView (riwayat pasien) / RekamMedisView | 🟡 |

### 2.7 REFERENSI (master pendukung)

| Endpoint BPJS | Fungsi | BE | UI | Prio |
|---|---|---|---|---|
| `GET /referensi/diagnosa/{q}` | Cari ICD-10 | `refDiagnosa($q)` | autocomplete diagnosa (DokterView, form SEP/LPK) | 🔴 |
| `GET /referensi/poli/{q}` | Cari poli | `refPoli($q)` | **master mapping poli** + dropdown SEP | 🔴 |
| `GET /referensi/faskes/{q}/{jns}` | Cari faskes | `refFaskes($q,$jns)` | dropdown ppkDirujuk (rujukan keluar) | 🟡 |
| `GET /referensi/dokter/...` · `.../dokter/pelayanan/...` | Cari DPJP | `refDokter / refDpjp` | **master mapping dokter→kode DPJP** | 🔴 |
| `GET /referensi/propinsi · /kabupaten · /kecamatan` | Wilayah (KLL) | `refPropinsi/refKabupaten/refKecamatan` | form KLL di SEP (jarang di klinik mata) | ⚪ |
| `GET /referensi/procedure/{q}` | Cari ICD-9-CM | `refProcedure($q)` | autocomplete tindakan (LPK) | 🟡 |
| `GET /referensi/diagnosaprb · /obatprb/{q}` | PRB (penyakit kronis) | `refDiagnosaPrb/refObatPrb` | ⚪ (klinik mata jarang PRB) | ⚪ |
| `GET /referensi/kelasrawat · /spesialistik · /ruangrawat · /carakeluar · /pascapulang` | Referensi LPK | `refKelasRawat` dst | dropdown LPK (KasirView/IntegrasiView) | 🟡 |

**Catatan**: referensi di-cache (config TTL) supaya tidak hit BPJS tiap kali. Bisa disimpan ke tabel referensi lokal atau cache Laravel.

### 2.8 ANTREAN ONLINE (service `antreanrs_dev`, signature sama, response JSON polos)

> Doc belum mencantumkan detail endpoint Antrean, tapi §2 doc menyebut "Method Antrean yang lengkap (add/updatewaktu/batal/dashboard wajib)" sebagai pekerjaan. Endpoint standar BPJS Antrean RS:

| Endpoint BPJS Antrean | Fungsi | BE | UI | Prio |
|---|---|---|---|---|
| `POST /antrean/add` | Daftarkan antrean (sinkron antrean Arumed → BPJS) | `addAntrean($data)` | **AdmisiView** (auto saat daftar pasien BPJS) + **AnjunganView** (kiosk walk-in) | 🔴 |
| `POST /antrean/updatewaktu` | Update waktu tiap tahap (mulai/selesai layan) | `updateWaktuAntrean($data)` | **QueueService** — hook di transisi stasiun (panggil saat pasien dilayani dokter / selesai) | 🔴 |
| `POST /antrean/batal` | Batal antrean | `batalAntrean($data)` | AdmisiView (batal kunjungan BPJS) | 🟡 |
| `GET /antrean/getlistwaktu/...` · `/dashboard/...` | Dashboard wajib lapor (per tgl/bulan) | `dashboardAntrean(...)` | IntegrasiView → tab Antrean (status lapor) | 🟡 |
| `POST /jadwaldokter/...` | Sinkron jadwal dokter ke BPJS (wajib utk JKN Mobile) | `syncJadwalDokter($data)` | **JadwalDokterView** — tombol "Kirim ke BPJS" per minggu | 🟡 |
| validasi booking dari JKN Mobile | `validateBookingCode` (sudah ada placeholder) | **AnjunganView/AdmisiView** — input kode booking | 🔴 |

**Wiring kunci Antrean = "wajib lapor"**: BPJS menilai keaktifan faskes dari kelengkapan `updatewaktu` per tahapan. Maka **QueueService** (sumber tunggal transisi stasiun — lihat memory `queue-advance-station-pattern`) harus emit event ke `BpjsAntreanService::updateWaktuAntrean` di titik: ambil antrean, mulai layan (dokter), selesai layan, selesai farmasi. Lakukan **async/queued + non-blocking** (gagal lapor BPJS TIDAK boleh blok flow lokal; cukup log ke `bpjs_antrean_logs`).

---

## 3. FRONTEND — `IntegrasiView.vue` (BARU, belum ada)

**Lokasi menu**: section **"Sistem"** di `AppSidebar.vue` (setelah Pengaturan), `v-if="auth.can('integrasi.read')"`, route `/integrasi`. Ikon plug/link.

**Permission baru**: tambah modul `integrasi` (R/W) ke RBAC matrix (lihat memory `feature-rbac-user-management`). Default hanya superadmin + admin.

**Struktur tab** (pola tab existing seperti AsuransiView 3-tab):

1. **Tab Status & Konfigurasi** (inti zero-code):
   - Kartu per sistem (VCLAIM, ANTREAN, dst) dari `GET /integrasi/status`.
   - Form edit credential (`cons_id`, `secretKey`, `user_key VClaim`, `user_key Antrean`, `kode faskes`, `base_url`) → `PUT /integrasi/config/{id}`.
   - Tombol **Test Koneksi** (`POST /integrasi/test/{system}`) + badge `last_test_status`.
   - Toggle **Aktifkan/Nonaktifkan** (`is_enabled`).
   - ⚠️ Field `secretKey`/`user_key` **password-masked**; tampil "tersimpan" tanpa echo nilai.

2. **Tab Rujukan** (Masuk + Keluar) — reader `/integrasi/bpjs/rujukan-masuk|keluar`.
3. **Tab SEP / Klaim** — daftar SEP + LPK, status, aksi batal/submit.
4. **Tab Surat Kontrol** — reader `/integrasi/bpjs/surat-kontrol` + submit.
5. **Tab Monitoring** — kunjungan + klaim per tanggal (langsung dari VClaim).
6. **Tab Antrean** — status lapor + dashboard wajib.
7. **Tab Log** — `vclaim-log` / `antrean-log` / `icare-log` (audit penuh, sudah ada reader). Filter action/tanggal/sukses.

**Master mapping baru** (di Master Data atau IntegrasiView → sub-tab Mapping):
- **Poli Arumed → Kode Poli BPJS** (untuk SEP `poli.tujuan`).
- **Dokter Arumed → Kode DPJP BPJS** (untuk `dpjpLayan` / `DPJP`).
- **Kode Faskes klinik** (PPK) → di config.

**Store**: `src/stores/integrasiStore.js` — actions: `fetchStatus`, `updateConfig`, `testKoneksi`, `fetchLog(type)`, `fetchRujukan*`, `fetchSuratKontrol`, `submitSuratKontrol`, dll. Pakai `api.js` + pola envelope existing.

**Styling**: ikuti memory `feedback-styling-visibility` (`#1763d4` + text `#000`) dan pola tab/kartu existing.

---

## 4. URUTAN PENGERJAAN (bertahap, sesuai memory `feedback-bertahap-konfirmasi`)

> Tiap poin = 1 batch, tunggu konfirmasi sebelum lanjut.

**Poin 1 — Fondasi auth (BE, tanpa UI)**
- `composer require nullpunkt/lz-string-php` (kunci versi).
- Buat `App\Services\Bpjs\BpjsClient` (signature + headers + request + decrypt).
- Cast `credentials` → `encrypted:array` di `IntegrationConfig`.
- Seeder baris `integration_configs` VCLAIM + ANTREAN (credential kosong, `is_enabled=false`, base_url dev).
- Unit test signature pakai contoh doc (`message:aaa key:bbb → 20BKS3...`).

**Poin 2 — UI Bridging (zero-code activation)**  ← *paling penting buat user, bisa didahulukan*
- `IntegrasiView.vue` + Tab Status/Konfigurasi + Tab Log.
- `integrasiStore.js` + route `/integrasi` + item sidebar + permission `integrasi.*`.
- Hasil: admin sudah bisa paste credential & Test, walau call belum semua live.

**Poin 3 — VClaim Peserta + Rujukan + SEP (RJ) live**
- Isi `checkPeserta`, `checkRujukan`, `generateSep`, `cancelSep` nyata via BpjsClient.
- Endpoint `POST /admisi/bpjs/{cek-peserta,cek-rujukan,terbitkan-sep,batal-sep}`.
- Wiring tombol di **AdmisiView** (tidak ganggu UMUM).
- Master mapping Poli + DPJP.

**Poin 4 — Antrean Online (wajib lapor)**
- Method `addAntrean`, `updateWaktuAntrean`, `batalAntrean`, `validateBookingCode`, `dashboardAntrean`.
- Hook **QueueService** (async/non-blocking) di transisi stasiun.
- Wiring validasi booking di **AnjunganView** (sekarang BPJS disabled — lihat memory `feature-anjungan-kiosk`).

**Poin 5 — Rencana Kontrol + Surat Kontrol**
- `insertRencanaKontrol` (v2) nyata; sambung ke `planning_follow_up`/`follow_up_date` visits.
- Wiring **DokterView** + Tab Surat Kontrol IntegrasiView.

**Poin 6 — LPK + Monitoring + Referensi**
- LPK insert/update/list; Monitoring; Referensi (cache).
- Wiring **KasirView** (kirim LPK saat selesai BPJS) + Tab Monitoring/Klaim.

**Poin 7 (opsional/nanti)** — Fingerprint, Rujukan internal, SPRI, PRB, INA-CBGs grouper. ⚪ skip dulu (bukan kebutuhan klinik mata RJ).

---

## 5. PRINSIP & GUARDRAIL (dari doc + konvensi Arumed)

- **Non-blocking**: semua call BPJS yang gagal/timeout/credential-kosong → lempar `503` jelas, log, **JANGAN blok flow UMUM/non-BPJS**. Antrean updatewaktu = queued async.
- **Audit penuh**: tiap call → `bpjs_vclaim_logs` / `bpjs_antrean_logs` (request, response, http_status, is_success). Reader sudah ada.
- **Timezone**: server WIB; `X-timestamp` = epoch UTC (`time()` PHP sudah UTC). Pastikan NTP sinkron (drift > beberapa menit → signature ditolak). Lihat memory `feedback-timezone-wib`.
- **Tidak sentuh BPJS Klaim existing** (`KlaimController` / modul Asuransi TPA) — itu non-BPJS, terpisah.
- **Credential aman**: encrypted cast + masked di UI.
- **Skill wajib**: pakai `skillarchitecturearumed` + `skillarumeddb` + `skillarumedctx` saat eksekusi koding (pola envelope, RBAC, service flow).

---

## 6. CATATAN DATA YANG SUDAH SIAP DIPAKAI

- `visits.no_sep`, `bpjs_booking_code`, `bpjs_antrean_number`, `bpjs_referral_in_id`, `bpjs_control_letter_id` → kolom sudah ada, tinggal diisi.
- `visits.planning_follow_up` + `follow_up_date` + `follow_up_reason` → siap untuk Rencana Kontrol.
- `visits.guarantor_type='BPJS'` + `insurer_id` → penanda kunjungan BPJS.
- Tabel `bpjs_referrals_in/out`, `bpjs_control_letters`, `bpjs_claims` + log → siap.
- ICD-10/ICD-9 master lokal sudah ada (Master Data) → bisa dipakai sebelum/along referensi BPJS.

---

## 7. YANG PERLU DIKONFIRMASI KE USER SEBELUM KODING

1. **Prioritas urutan**: dahulukan **Poin 2 (UI dulu)** atau **Poin 1 (fondasi auth dulu)**? (UI dulu lebih cepat terlihat & sesuai tujuan zero-code).
2. **Antrean Online**: apakah klinik sudah terdaftar di **Aplicares/JKN Mobile** (butuh sinkron jadwal dokter + add antrean), atau cukup VClaim SEP dulu?
3. **Kode poli & DPJP BPJS**: apakah sudah ada daftarnya, atau perlu ambil via endpoint referensi lalu user-mapping manual di UI?
4. **Scope ranap**: konfirmasi klinik **murni rawat jalan + bedah** (tidak ranap) → boleh skip semua endpoint RANAP/INA-CBGs/SPRI? (asumsi plan ini: ya).
```
