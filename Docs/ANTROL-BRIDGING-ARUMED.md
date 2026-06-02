# Antrol (WS Antrean Online BPJS) — Arumed Prima Vision

> Dokumentasi alur kerja & fitur bridging Antrean Online BPJS di RS Mata Prima Vision (Medan).
> Sumber spec: `Docs/Antrol.md` (spec resmi BPJS). Dibangun 2026-05-31, **belum di-commit**.
> Status integrasi: **non-blocking & fail-safe** — semua tetap jalan walau bridging belum aktif/tanpa credential.

---

## 1. Apa itu Antrol & dua arah komunikasinya

"Antrol" = Web Service Antrean Online BPJS — agar RS bisa **tampil & menerima booking di aplikasi Mobile JKN**. BPJS mewajibkan DUA arah:

- **Sisi A — RS → BPJS (HFIS):** RS memanggil server BPJS untuk kirim referensi, **lapor waktu antrean (taskid)**, sinkron jadwal dokter, dashboard wajib lapor. Header `x-cons-id/x-timestamp/x-signature/user_key` (HMAC). Penilaian keaktifan faskes BPJS bergantung pada kelengkapan laporan ini.
- **Sisi B — Mobile JKN → RS:** RS **menyediakan** web service yang dipanggil server BPJS (pasien ambil antrean lewat Mobile JKN). Auth `x-token` + `x-username` (RS menerbitkan token via endpoint `/antrol/token`). Respon pakai envelope BPJS `{response, metaData:{code,message}}` (200 sukses, 201 gagal, 202 pasien baru).

Keduanya berbagi infrastruktur auth `BpjsClient` yang sama dengan VClaim (sudah LIVE).

---

## 2. Fitur yang tersedia

### Sisi A — RS melapor ke BPJS (otomatis, menempel di alur antrean lokal)
| Fitur | Endpoint BPJS | Pemicu di Arumed |
|---|---|---|
| Referensi poli/dokter/jadwal/poli-fp | `/ref/*` | manual / saat pemetaan |
| **Daftar antrean** | `/antrean/add` | otomatis saat registrasi pasien BPJS |
| **Lapor waktu (taskid 1–7, 99)** | `/antrean/updatewaktu` | otomatis tiap transisi stasiun antrean |
| Batal antrean | `/antrean/batal` + task 99 | saat antrean dibatalkan |
| Antrean farmasi | `/antrean/farmasi/add` | otomatis saat pasien masuk FARMASI |
| Sinkron jadwal dokter | `/jadwaldokter/updatejadwaldokter` | tombol "Sinkron Jadwal" (per minggu) |
| Dashboard wajib lapor | `/dashboard/waktutunggu/*` | tab Antrean (per tanggal/bulan) |

### Sisi B — RS melayani Mobile JKN (11 endpoint, prefix `/api/v1/antrol`)
| # | Endpoint RS | Fungsi |
|---|---|---|
| B1 | `GET /antrol/token` | Terbitkan token (x-username + x-password) |
| B2 | `POST /antrol/status` | Status antrean per poli (total/sisa/panggil/kuota) |
| B3 | `POST /antrol/ambil` | **Ambil antrean** → reservasi + kodebooking (code 202 bila pasien baru) |
| B4 | `POST /antrol/sisa` | Sisa antrean hari-H + waktutunggu |
| B5 | `POST /antrol/batal` | Batal antrean (by kodebooking) |
| B6 | `POST /antrol/checkin` | Check-in → **buat Visit + antrean fisik** |
| B7 | `POST /antrol/pasien-baru` | Buat rekam medis pasien baru → balas norm |
| B8 | `POST /antrol/jadwal-operasi` | Jadwal operasi RS (rentang tanggal) |
| B9 | `POST /antrol/jadwal-operasi-pasien` | Jadwal operasi per pasien (by nopeserta) |
| B10 | `POST /antrol/farmasi/ambil` | Ambil antrean farmasi |
| B11 | `POST /antrol/farmasi/status` | Status antrean farmasi |

### Master pendukung (UI di tab Bridging → Antrean)
- **Kuota** (`antrean_kuota`): plafon JKN/non-JKN per poli (opsional per dokter & tanggal). Sisa kuota dihitung runtime (kuota − antrean terpakai), tidak disimpan.
- **SPM** (`antrean_spm`): menit/pasien per poli/dokter. Dasar `estimasidilayani` & `waktutunggu` (formula BPJS `SPM × (sisa−1)`). Default 15 menit.

---

## 3. Alur kerja end-to-end

### 3.1 Pasien booking lewat Mobile JKN (Sisi B)
```
Mobile JKN                         RS (Arumed)
   │  GET /antrol/token  ─────────▶  verifikasi user/pass → terbitkan token (B1)
   │  POST /antrol/status ────────▶  cek sisa antrean & kuota poli (B2)
   │  POST /antrol/ambil ─────────▶  RESERVASI di antrean_bookings + generate kodebooking (B3)
   │                                 ├─ pasien dikenal (NIK→kartu) → code 200 + norm
   │                                 └─ pasien baru → code 202
   │  POST /antrol/pasien-baru ───▶  (jika 202) buat RM, tautkan booking, balas norm (B7)
   │           …hari-H…
   │  POST /antrol/checkin ───────▶  buat Visit + antrean TRIASE+REFRAKSIONIS, lapor task 3 (B6)
```
**Kunci desain:** "Ambil Antrean" **hanya reservasi**, BUKAN Visit. Visit & antrean fisik baru lahir saat **check-in** → no-show tidak mengotori antrean/kuota hari itu.

### 3.2 Pelaporan TaskID otomatis (Sisi A) — menempel di alur antrean RS Mata
Alur antrean RS: `ADMISI → [TRIASE + REFRAKSIONIS paralel] → DOKTER → (PENUNJANG↺) → KASIR → FARMASI → SELESAI`

Makna taskid mengikuti spec resmi (Antrol.md:340-347) — tiap task = SATU titik "akhir X / mulai Y":

| Event lokal | TaskID | Makna spec | Catatan |
|---|---|---|---|
| Ambil tiket kiosk → loket admisi | 1 | mulai tunggu admisi | hanya jalur kiosk |
| ADMISI mulai daftar (dipanggil) | 2 | mulai layan admisi | hanya jalur kiosk |
| Selesai daftar admisi | **3** | akhir layan admisi / mulai tunggu poli | jalur loket & check-in Mobile JKN mulai dari sini |
| DOKTER dipanggil | 4 | mulai layan poli | `panggil()` station DOKTER; **sekali saja** (re-call pasca-penunjang tak kirim ulang) |
| Selesai DOKTER → KASIR/FARMASI | 5 | akhir layan poli / mulai tunggu farmasi | sisa antrean BPJS berkurang di task 5 |
| FARMASI mulai dispensing | 6 | mulai layan farmasi (buat obat) | `startDispensing` + `antrean/farmasi/add` |
| FARMASI selesai (serah obat) | 7 | akhir obat selesai dibuat | `selesaiDispensing` |
| Antrean dibatalkan | 99 | tidak hadir/batal | |

> ⚠️ **Koreksi 2026-06-02:** pemetaan taskid sebelumnya OFF-BY-ONE (task 3 dipakai utk "mulai poli", dst). Sudah diperbaiki agar sesuai spec — BPJS memvalidasi makna & urutan task. Jalur kiosk kini melapor 1→2→3 berurutan via `QueueService::reportAdmisiKioskTasks` (advance ADMISI dipanggil `reportBpjs=false` agar urutan tak terbalik). Task 4 di `panggil()` DOKTER; task 6/7 + `antrean/farmasi/add` di `FarmasiService`.

**Guard monoton:** kolom `visits.bpjs_last_taskid` menyimpan taskid terakhir; hanya kirim bila taskid baru > terakhir. Mencegah duplikat & waktu mundur (kasus bolak-balik DOKTER↔PENUNJANG khas pasien mata). Task 99 dikecualikan (selalu boleh).

### 3.3 Registrasi pasien BPJS (Sisi A)
Saat petugas daftarkan pasien BPJS (loket/kiosk):
1. `AntrolBuilderService::ensureKodebooking` → generate `kodebooking` lokal (format `{kodepoliBPJS|RS}{YYMMDD}{6hex}`).
2. Jika bridging aktif: `buildAddPayload` susun 23 field → `antrean/add` ke BPJS.
3. Lapor task 3 (loket). Semua **non-blocking** (pasca-commit).

---

## 4. Arsitektur (file utama)

```
backend/app/Services/
 ├── Bpjs/BpjsClient.php          ← auth HMAC + HTTP + decrypt (REUSE, dipakai VClaim & Antrean)
 ├── BpjsAntreanService.php       ← Sisi A: ref/add/updatewaktu/batal/farmasi-add/jadwal/dashboard
 ├── AntrolBuilderService.php     ← generator kodebooking + susun payload antrean/add (lokal)
 ├── AntreanKuotaService.php      ← resolve kuota & sisa + SPM + estimasi (lokal, tanpa BPJS)
 ├── AntrolTokenService.php       ← token stateless HMAC untuk Sisi B (issue/validate)
 ├── AntrolMobileService.php      ← logika 11 endpoint Sisi B
 └── QueueService.php             ← reportTask() = sumber tunggal lapor taskid + guard monoton

backend/app/Http/Controllers/
 ├── IntegrasiController.php      ← Sisi A + CRUD kuota/SPM (di bawah auth:api, /integrasi/*)
 └── AntrolMobileController.php   ← Sisi B (PUBLIC /antrol/*, envelope BPJS + audit log)

backend/app/Http/Middleware/
 └── VerifyAntrolToken.php        ← validasi x-token + x-username (alias 'antrol.token')

backend/app/Models/   AntreanKuota, AntreanSpm, AntreanBooking, BpjsPoliMapping(+localCodeFor)
backend/database/migrations/  antrean_kuota, antrean_spm, antrean_bookings, visits.bpjs_last_taskid

frontend/
 ├── views/bridging/BridgingAntreanView.vue      ← tab Dashboard/Booking/Kuota/SPM
 ├── views/bridging/BridgingKonfigurasiView.vue  ← credential + kredensial Mobile JKN (Sisi B)
 └── components/jadwal-dokter/BpjsMappingModal.vue ← mapping poli/DPJP + sinkron jadwal (sudah ada)
```

**Routing:** Sisi A di `/api/v1/integrasi/*` (auth:api). Sisi B PUBLIK di `/api/v1/antrol/*` (di luar auth:api, diproteksi `antrol.token`). URL yang didaftarkan ke BPJS: `https://{host}/api/v1/antrol/...`.

---

## 5. Prinsip non-blocking (kenapa aman tanpa credential)

Semua call ke BPJS bersifat **lapisan pelapor** di atas alur lokal — 3 lapis pertahanan:
1. Skip cepat bila bukan BPJS / belum punya kodebooking.
2. Cek `BpjsAntreanService::isEnabled()` → bila mati, `return` diam-diam (kodebooking & `bpjs_last_taskid` tetap dicatat lokal untuk resync nanti).
3. Dibungkus `try/catch`, dipanggil **pasca-commit** → kegagalan hanya `Log::warning`, transisi/registrasi lokal tak terganggu.

Sisi B juga **fail-closed**: tanpa `mobilejkn_username`/`password`, semua endpoint menolak (201). Membuka grup publik `/antrol` tidak menambah permukaan serangan saat belum dikonfigurasi.

Audit semua call (masuk & keluar) → tabel `bpjs_antrean_logs`.

---

## 6. Cara mengaktifkan (zero-code activation)

1. **Isi credential** di Bridging → Konfigurasi & Status, sistem **ANTREAN**:
   - Sisi A: `cons_id`, `secret_key` (consumer secret), `user_key`, `kode_faskes`, `base_url`, `service_name`.
   - Sisi B: `mobilejkn_username`, `mobilejkn_password` (token_secret opsional, default APP_KEY).
2. **Test Koneksi** ANTREAN → klik **Aktifkan**.
3. **Pemetaan** (prasyarat payload BPJS riil): di Jadwal Dokter → "Pemetaan BPJS":
   - **Pemetaan Poli**: `poli_code` lokal → kode poli/subspesialis BPJS.
   - **Kode DPJP**: set `bpjs_dpjp_code` per dokter.
   - **Sinkron Jadwal**: kirim jadwal minggu aktif ke BPJS.
4. Daftarkan URL `https://{host}/api/v1/antrol/*` + username/password ke BPJS untuk Mobile JKN.

---

## 7. Catatan operasional & TODO

- **`poli_code` jadwal lama NULL** — kolom `doctor_schedules.poli_code` ditambah setelah seeding awal, jadi jadwal lama kosong. Form jadwal dokter sudah punya input `poli_code`; admin perlu buka & isi tiap jadwal eksisting agar muncul di Pemetaan Poli & payload BPJS terisi. Tanpa itu, payload ter-skip aman (tak crash).
- **Jenis resep racikan** — model resep belum punya penanda racikan eksplisit. `AntrolMobileService::resolveJenisResep()` sementara selalu "Non racikan" (1 titik, `#TODO RACIKAN`). Saat menu racikan dibuat, ubah method ini saja → A8/B10/B11 ikut otomatis.
- **Uji live sandbox BPJS** — menunggu credential turun.

### Bugfix 2026-06-02 (Antrol)
- **Taskid OFF-BY-ONE diperbaiki** — task 3-7 dulu salah-geser vs spec; kini sesuai (lihat tabel §3.2). Jalur kiosk lapor 1→2→3 berurutan; task 4 di panggil DOKTER; task 6/7 di dispensing farmasi.
- **Kuota over-booking** — `AntreanKuotaService::terpakai()` dulu hanya hitung `Visit`; kini juga menghitung reservasi `AntreanBooking` aktif (DIBOOK belum check-in, anti dobel via `visit_id IS NULL`). Tanpa ini `sisakuota` ke BPJS selalu salah & reservasi online bisa lewati plafon.
- **Double-booking race (B3)** — `lockForUpdate` pada SELECT kosong tak mengunci apa-apa; ditambah **partial unique index** `antrean_bookings_active_unique` (nik+dokter+tanggal saat status aktif) + tangkap `UniqueConstraintViolationException` → balas ramah.
- **`antrean/farmasi/add` (A8) tak pernah dipanggil** — kini dilapor di `FarmasiService::startDispensing` (`QueueService::reportAntreanFarmasiAdd`).
- **Verifikasi:** `storage/app/e2e/test_antrol_fixes.php` 4/0 + `test_admisi_flows.php` 43/0 (no-regress walk-in). (IGD obat di `test_obat_pulang_billing.php` FAIL = pre-existing, soal billing inpatient_charges, di luar Antrol.)
- **Belum di-commit.**
