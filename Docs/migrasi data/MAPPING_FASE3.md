# MAPPING Fase 3 — Prima Vision → Arumed (schema AKTUAL)

**Tanggal:** 2026-05-31
**Sumber kebenaran:** migrasi Laravel di `backend/database/migrations/` (BUKAN tebakan dokumen v2).
**Prasyarat terpenuhi:** ✅ Migrasi `2026_06_06_000005_add_legacy_fields_to_patients_table` sudah ada. Model `Patient` sudah punya semua kolom legacy di `$fillable`.

> Catatan penting nama kolom Arumed berbeda dari dokumen v2:
> `name` (bukan nama), `date_of_birth` (bukan dob), `blood_type` (bukan golongan_darah),
> `identity_type`+`nik` varchar(50) (bukan jenis_identitas/no_identitas terpisah).
> Golongan darah TIDAK pakai kolom baru — pakai `blood_type` existing.

---

## Fakta schema target (terverifikasi)

| Hal | Nilai aktual Arumed |
|---|---|
| PK semua tabel | `uuid('id')` via `HasUuids` (string, non-incrementing) |
| `patients.gender` | `varchar(10)` nullable — **enum efektif `L` / `P`** (validasi controller `in:L,P`) |
| `patients.nik` | `varchar(50)` **nullable** + `identity_type` default `KTP` |
| `patients.blood_type` | `varchar(5)` — dipakai untuk golongan darah |
| `patients` kolom legacy | `legacy_uuid`, `tempat_lahir`, `pekerjaan`, `nama_kab_kota`, `nama_kecamatan`, `nama_kelurahan` |
| `insurers` sistem (sudah di-seed) | `UMUM`, `BPJS`, `SOSIAL` (type sama). Ada `is_system`, `parent_id` (TPA), `code` unique |
| `insurers.type` | `UMUM/BPJS/SOSIAL/ASURANSI/PERUSAHAAN` |
| `visits` FK | `patient_id`→patients, `insurer_id`→insurers, `registered_by_id`→employees |
| `visits` wajib | `visit_date`, `classification`, `current_station` (def ADMISI), `guarantor_type` |
| `refraction_records` | `visit_id` **UNIQUE** (1 visit = 1 record), sph/cyl `decimal(5,2)`, axis `integer`, visus `varchar(20)` |
| `doctor_examinations` | `visit_id` **UNIQUE**, `diagnosis_utama` varchar(10), `diagnosis_sekunder`/`tindakan_codes` jsonb |
| `employees` | `name, nip, profession, sip, str, phone, email, address, is_active` |
| `users` | `employee_id`→employees, `role_id`→roles, `email` unique, `password`, `pin` |

⚠️ **KENDALA `refraction_records.visit_id` UNIQUE + `doctor_examinations.visit_id` UNIQUE:**
1 visit hanya boleh 1 refraksi & 1 pemeriksaan dokter. Perlu cek di Fase 4 apakah ada `registrasi_uuid` duplikat di `pemeriksaan_ro`/`pemeriksaan_dokter` sumber. Jika ada → ambil yang terbaru / terlengkap.

---

## 3.1 `pasien` → `patients`

| Prima Vision | Arumed | Transform |
|---|---|---|
| `uuid` | `legacy_uuid` | direct |
| (generate) | `id` | UUID baru (HasUuids) |
| `rekam_medis` | `no_rm` | direct (6/9 digit, pertahankan apa adanya). **UNIQUE** — cek duplikat di Fase 4 |
| `nama` | `name` | `NULLIF(nama,'-')`; jika NULL → fallback `'-'`? (name NOT NULL) → pakai `'TANPA NAMA'` |
| `no_ktp`/`no_identitas` (16 dgt) | `nik` | `^[0-9]{16}$` → ktp dulu, fallback identitas, else NULL (74,2% terisi) |
| — | `identity_type` | `KTP` jika nik dari 16-digit, else dari `jenis_identitas` (Paspor/SIM/KIA) atau `KTP` default |
| `tanggal_lahir` | `date_of_birth` | `NULLIF(...,'1000-01-01')` & `'1990-09-09'` → NULL (praktis semua valid) |
| `tempat_lahir` | `tempat_lahir` | `NULLIF('-')` |
| `jenis_kelamin` | `gender` | **`Laki-Laki`→`L`, `Perempuan`→`P`, else NULL** (11 baris kosong) |
| `alamat` | `address` | `NULLIF('-')` |
| `nama_provinsi` | `province` | `NULLIF('-')` |
| `nama_kab_kota` | `nama_kab_kota` | `NULLIF('-')` |
| `nama_kecamatan` | `nama_kecamatan` | `NULLIF('-')` |
| `nama_kelurahan` | `nama_kelurahan` | `NULLIF('-')` |
| `no_handphone` | `phone` | `NULLIF('-')` (1.070 kosong) |
| `pekerjaan` | `pekerjaan` | `NULLIF('-')` (≈100% kosong → hampir semua NULL) |
| `golongan_darah` | `blood_type` | `NULLIF('-')` + normalize A/B/AB/O±  (≈100% kosong) |
| — | `is_active` | `true` (semua delete_soft=1) |
| `created_at` | `created_at` | direct |
| — | `deleted_at` | **NULL untuk semua** (tidak ada delete_soft=0) |

**Catatan:** kolom wilayah `*_id` (provinsi_id dst, terisi 36%) **TIDAK** dimigrasi ke kolom kode Arumed pada fase ini (keputusan: cukup string legacy). Bisa ditambah belakangan jika perlu laporan by-kode.

---

## 3.2 `biodata` → `employees` + `users`

**employees:**

| Prima Vision | Arumed `employees` | Transform |
|---|---|---|
| `uuid` | (legacy ref — perlu kolom? **employees TIDAK punya legacy_uuid**) | ⚠️ butuh strategi lookup (lihat catatan) |
| `nama_pengguna` | `name` | direct |
| `sebagai_pengguna` | `profession` | direct (string apa adanya) atau map |
| `sima` | `sip` | `NULLIF('-')` (SIM-A = SIP, 44 terisi) — **konfirmasi** sima=SIP |
| `simc` | `str` | `NULLIF('-')` (SIM-C = STR? 44 terisi) — **konfirmasi** simc=STR |
| `no_handphone` | `phone` | `NULLIF('-')` |
| `email_pengguna` | `email` | `NULLIF('-')`; **UNIQUE** — dedup |
| `alamat` | `address` | `NULLIF('-')` |
| `delete_soft` | `is_active` | `1`→true, `0`→false (42 nonaktif) |

**users (akun login):**

| Prima Vision | Arumed `users` | Transform |
|---|---|---|
| → employee | `employee_id` | FK ke employees hasil insert |
| `sebagai_pengguna` | `role_id` | **MAPPING MANUAL** ke `roles` (≈38 variasi tidak rapi) |
| `nama_pengguna` | `name` | direct |
| `email_pengguna`/`username_pengguna` | `email` | UNIQUE; jika kosong → generate `user{n}@primavision.local` |
| (generate) | `password` | bcrypt default + paksa ganti, ATAU skip migrasi user (buat manual) |

⚠️ **KEPUTUSAN BESAR:** `employees` & `users` **TIDAK punya `legacy_uuid`**. Untuk FK `visits.registered_by_id` & `refraction.examined_by_id` & `doctor_examinations.doctor_id` yang sumbernya `pengguna_uuid`, perlu salah satu:
- **(a)** Tambah migrasi `legacy_uuid` ke `employees` (paling bersih, konsisten dgn patients), ATAU
- **(b)** Bangun map `pengguna_uuid → employee.id` in-memory saat seeder (dari `biodata.pengguna_uuid`).
Rekomendasi: **(a)** tambah `employees.legacy_uuid` (1 migrasi kecil), simetris dengan patients.

---

## 3.3 `registrasi` → `visits` (header saja, dari 145 kolom ambil ~8)

| Prima Vision | Arumed `visits` | Transform |
|---|---|---|
| (generate) | `id` | UUID baru |
| `uuid` | (legacy ref) | ⚠️ `visits` juga **belum punya legacy_uuid** — butuh untuk FK refraksi/dokter (lihat 3.4/3.5). Tambah `visits.legacy_uuid` |
| `pasien_uuid` | `patient_id` | lookup `patients.legacy_uuid` (6 orphan → skip) |
| `tanggal` | `visit_date` | cast date (NOT NULL) |
| `jenis` | `classification` | map: `Rawat Jalan`→`Baru`? — **perlu keputusan** (lihat catatan classification) |
| `carabayar_nama` / `nama_asuransi` | `guarantor_type` | `BPJS Kesehatan`→`BPJS`, `Umum`→`UMUM`, asuransi→`ASURANSI`, perusahaan→`PERUSAHAAN` |
| `asuransi_uuid`/`carabayar` | `insurer_id` | lookup insurer by-name (UMUM/BPJS skip ke sistem; sisanya by name) |
| `pengguna_uuid` | `registered_by_id` | lookup employee.legacy_uuid (nullable) |
| — | `current_station` | `SELESAI` (data historis, kunjungan sudah lewat) |
| `created_at` | `created_at` | direct |

**Catatan `classification`:** Arumed `classification` semantik = `Baru/Pre-Op/Post-Op/Kontrol` (status klinis kunjungan), **bukan** rawat jalan/inap. Sedangkan sumber `jenis` = Rawat Jalan/Inap/ODC/IGD (jalur layanan). Mapping tidak 1:1. **Keputusan:** isi `classification='Baru'` default untuk semua data historis, ATAU tambah kolom legacy. Rawat Inap/ODC (951+89) mungkin perlu penanganan khusus tapi di luar scope migrasi awal.

---

## 3.4 `pemeriksaan_ro` → `refraction_records` (KOMPLEKS — string parsing)

FK: `visit_id` ← lookup `visits.legacy_uuid` dari `registrasi_uuid`. **UNIQUE** → 1 record per visit (cek duplikat).

| Prima Vision | Arumed | Parsing |
|---|---|---|
| `registrasi_uuid` | `visit_id` | lookup |
| `pengguna_uuid` | `examined_by_id` | lookup employee |
| `tanggal`+`waktu` | `examination_date` | concat timestamp |
| `ocular_dextra_autoref` | `autoref_od_sph/cyl/axis` | **regex toleran** (lihat di bawah) |
| `ocular_sinistra_autoref` | `autoref_os_*` | sama |
| `ocular_*_keratometri_k1/k2` | `keratometri1_*/keratometri2_*` | cast decimal, NULLIF placeholder |
| `ocular_*_visus` | `visus_awal_*` | **string as-is** (varchar20), truncate >20 |
| `ocular_*_bcva2`/`bcva1` | `visus_akhir_*` | bcva2 jika ada else bcva1, string as-is |
| `ocular_*_pinhole` | `pinhole_*` | string as-is |
| `ocular_*_add` | `add_power_*` | cast decimal |
| `ocular_*_tonometri` | `iop_*` | cast decimal |
| `ocular_dextra_pd` | `pd_distance` | pilih OD, cast decimal |
| `ocular_*_kacamata_lama_sph/cyl` | `old_glasses_*_sph/cyl` | parse decimal (notasi ×100) |
| `ocular_*_kacamata_lama_addisi` | `old_glasses_add_*` | **addition power** (terjawab Fase 2), cast decimal |
| `ocular_*_kacamata_lama_addisi` jadi axis? | `old_glasses_*_axis` | **TIDAK** — addisi=addition, axis = NULL (sumber tak punya axis terpisah) |

**Regex autoref** (target ≥63% sukses; sisanya raw):
```
normalize: ganti ',' → '.', hapus spasi ganda, uppercase
pattern  : S\s*([+-]?\s*\d+[.,]?\d*)\s*(?:C\s*([+-]?\s*\d+[.,]?\d*)\s*X\s*(\d+))?
fallback notasi ×100: jika |angka| > 30 → bagi 100 (mis 275 → 2.75)
literal 'error'/'ERROR' → NULL
'C ... X ...' tanpa S → sph=NULL, cyl & axis terisi
```
⚠️ **decimal(5,2)** → nilai harus muat -99.99..99.99. Setelah ÷100, sph/cyl wajar. Axis 0-180 (integer).

⚠️ **REKOMENDASI: tambah kolom raw** (`autoref_od_raw`, `autoref_os_raw` varchar / atau `raw_data` jsonb) di `refraction_records` untuk simpan string asli yang gagal/ambigu (22,6% "lain"). **Belum ada di schema** — perlu 1 migrasi.

---

## 3.5 `pemeriksaan_dokter` → `doctor_examinations`

FK: `visit_id` ← `registrasi_uuid`. **UNIQUE**.

| Prima Vision | Arumed | Transform |
|---|---|---|
| `registrasi_uuid` | `visit_id` | lookup |
| `pengguna_uuid` | `doctor_id` | lookup employee |
| `anamnese` | `anamnese` | direct |
| `pemeriksaan_diagnosa_kode` | `diagnosis_utama` | **hapus spasi** `H 25.1`→`H25.1`, truncate 10, validasi master ICD-10 |
| ICD sekunder (dari icdten) | `diagnosis_sekunder` (jsonb) | aggregate array `kode_icdten` per `pemeriksaan_dokter_uuid`, hapus spasi |
| `pemeriksaan_tindakan_kode` | `tindakan_codes` (jsonb) | wrap array, normalisasi |
| `pilihan_plan` | `planning` | map enum `PULANG_BEROBAT_JALAN/BEDAH/RUJUK` — **cek nilai aktual Fase 4** |
| ocular_dextra/sinistra_* (palpebra/cornea/lensa/dst) | `sa_*`/`sp_*` | map field segmen anterior/posterior (nama beda) — **opsional**, banyak yg '-' |

**Aggregate ICD sekunder:** group `pemeriksaan_dokter_icdten` by `pemeriksaan_dokter_uuid` → array kode (hapus spasi). Hanya 3.817 baris untuk 51.303 pemeriksaan → mayoritas tanpa sekunder.

---

## 3.6 `carabayar` + `asuransi` → `insurers`

- **SKIP** baris yang = sistem: `Umum`→sudah ada UMUM, `BPJS Kesehatan`→sudah ada BPJS. Match by name (case-insensitive).
- `carabayar` (48) → insurer `type` tentukan: yang jelas perusahaan (PLN, PT...) → `PERUSAHAAN`, asuransi → `ASURANSI`.
- `asuransi` (241) → `type='ASURANSI'`, `parent_id` bisa pakai `carabayar_uuid` (grup TPA) jika mau hierarki.
- `code` di sumber = `'-'` → NULL (jangan isi, `code` UNIQUE).
- Dedup: nama sama muncul di carabayar & asuransi → 1 insurer.

⚠️ Total insurer non-sistem ≈ 48+241 = 289 dikurangi duplikat & sistem. Perlu dedup by-name di Fase 4.

---

## Ringkasan migrasi tambahan yang DIBUTUHKAN sebelum seeder (Fase 5)

| # | Migrasi | Alasan | Status |
|---|---|---|---|
| 1 | 6 kolom legacy `patients` | FK + demografi | ✅ **SUDAH ADA** (`2026_06_06_000005`) |
| 2 | `employees.legacy_uuid` | FK lookup dari `pengguna_uuid` | ❌ belum |
| 3 | `visits.legacy_uuid` | FK lookup dari `registrasi_uuid` (refraksi/dokter) | ❌ belum |
| 4 | kolom raw autoref di `refraction_records` (`autoref_od_raw`,`autoref_os_raw` / `raw_data` jsonb) | simpan 22,6% autoref gagal-parse | ❌ belum (REKOMENDASI) |

(Opsional) `visits.classification` legacy / tabel pemetaan role untuk `sebagai_pengguna`.

---

## Keputusan terbuka yang TERJAWAB di Fase 3

- ✅ gender enum Arumed = **`L`/`P`** (bukan Laki-Laki/LAKI-LAKI).
- ✅ NIK nullable + identity_type sudah ada → pasien tanpa NIK **didukung schema**, migrasi `nik=NULL` aman.
- ✅ Golongan darah → `blood_type` existing (tak perlu kolom baru).
- ✅ insurers sistem UMUM/BPJS/SOSIAL sudah di-seed → skip saat migrasi.
- ✅ 6 kolom legacy patients + fillable model **sudah siap**.

## KEPUTUSAN FINAL USER (2026-05-31) — terkunci

- [x] **`legacy_uuid` ke `employees` DAN `visits`** → buat 2 migrasi (nullable, indexed). Simetris dgn patients.
- [x] **Autoref raw = `raw_data` jsonb** di `refraction_records`. Simpan string asli OD/OS (+ field ambigu lain) per baris. Bisa **re-parse dari DB tanpa sentuh sumber** → migrasi ulang aman.
- [x] **Migrasi `employees` SAJA**, akun `users` (login) dibuat MANUAL via UI RBAC belakangan. → tidak perlu mapping ~38 peran sekarang, tidak perlu password massal.
- [x] **`classification = 'Baru'` untuk SEMUA** visit historis. Tanpa penanganan khusus Rawat Inap/ODC (data lama = riwayat/RME, bukan workflow aktif).

### 4 migrasi tambахan final sebelum seeder — SEMUA SELESAI (2026-05-31)
1. ✅ 6 kolom legacy `patients` — `2026_06_06_000005` (Ran)
2. ✅ `legacy_uuid` di 7 tabel (insurers, employees, medications, visits, refraction_records, doctor_examinations, prescriptions) — `2026_06_06_000006` (Ran). Melebihi kebutuhan, bagus.
3. ✅ (tercakup di #2)
4. ✅ `refraction_records.raw_data` jsonb — `2026_06_06_000007` (Ran, dibuat sesi ini)

**Model $fillable disesuaikan sesi ini:** `legacy_uuid` ditambah ke Visit, Employee, Insurer, DoctorExamination, RefractionRecord; `raw_data`(+cast array) ke RefractionRecord. (Patient sudah punya sebelumnya.) Medications/Prescriptions belum (di luar scope migrasi awal).

### Temuan validasi Fase 4 (cek duplikat)
- ✅ `pasien.no_rm`: **55.442 unik, 0 duplikat** → `no_rm UNIQUE` aman.
- 🔴 `pemeriksaan_ro.registrasi_uuid`: **500 reg duplikat** (max 4×, 521 baris ekstra) → langgar `visit_id UNIQUE`. **Seeder ambil 1/reg** (created_at terbaru). Buang ~521 (~1%).
- 🔴 `pemeriksaan_dokter.registrasi_uuid`: **114 reg duplikat** (115 baris ekstra) → sama, ambil 1/reg.
- `pilihan_plan` → `planning` map: `Pulang Berobat Jalan`(33.579)→`PULANG_BEROBAT_JALAN`; `''`(13.416)→NULL; `Operasi`(3.395)+`Operasi pada jadwal...`(41)→`BEDAH`; `Rawat Inap`(871)→NULL (tak ada padanan enum).

## FASE 5 — HASIL FULL RUN (2026-05-31) ✅

Command: `php -d memory_limit=1024M artisan migrasi:primavision` (CSV gzip streaming, idempotent via legacy_uuid).

| Tabel | Masuk | Dilewati (orphan) |
|---|---|---|
| insurers | 230 (227 migrasi + 3 sistem) | 62 (sistem/duplikat/kosong) |
| employees | 159 (153 migrasi + 6 login) | 1 (nama kosong) |
| patients | 55.442 | 0 |
| visits | 53.140 | 6 (pasien orphan) |
| refraksi | 50.264 | 34 (visit orphan) |
| dokter | 51.159 | 28 (visit orphan) |

**FK orphan = 0** di semua relasi. with_nik=40.786, gender L/P/null=23960/31472/11 (cocok CSV). raw_data terisi 100% refraksi. autoref_od_sph terisi 29.203.

**5 bug ditemukan & difix saat uji sample 200:**
1. phone/string overflow → `truncate()` per panjang kolom.
2. `nik UNIQUE`: no_identitas non-16digit 95% sampah + 335 NIK 16-digit duplikat + placeholder `xxx00000000` → `nik=NULL` first-wins.
3. `decimal(5,2)` out-of-range → `num()` clamp ±999.99 (return NULL, bukan potong).
4. **`pemeriksaan_diagnosa_kode`/`_tindakan_kode` = UUID master, BUKAN ICD** → `diagnosis_utama` ambil ICD pertama dari `icdten` (sisanya sekunder); nama teks → `soap_assessment`/`soap_plan`; `tindakan_codes=NULL`.
5. **GOTCHA: `Patient.php` & `RefractionRecord.php` sempat ter-revert** (legacy_uuid hilang dari $fillable). Cek `grep -c legacy_uuid app/Models/*.php` sebelum run.

**Catatan ops:** WAJIB `php -d memory_limit=1024M` (default CLI 256M habis saat exception handler bangun context auth). ~1.300 baris/5 detik.

## Masih perlu verifikasi (non-blocking, bisa saat Fase 4/coding)

- [ ] Konfirmasi `sima`=SIP, `simc`=STR di biodata (saat migrasi employees).
- [ ] Cek duplikat `registrasi_uuid` di pemeriksaan_ro/dokter (visit_id UNIQUE) — Fase 4.
- [ ] Cek duplikat `no_rm` di pasien (no_rm UNIQUE) — Fase 4.
- [ ] Nilai aktual `pilihan_plan` sumber → map ke `planning` enum — Fase 4.
