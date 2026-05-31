# Panduan Migrasi Prima Vision → Arumed (v2)

**Strategi:** Restore dump ke Postgres lokal → eksplorasi & cocokkan data → export CSV sample untuk review → migrasi akhir lewat seeder Laravel Arumed.

**Status:** Fase ekstraksi sumber **SELESAI**. Backup & CSV ada di `C:\Users\PrimaVision\Desktop\primavision_backup\`. Fase eksplorasi & seterusnya belum dimulai.

**Tanggal versi ini:** 2026-05-31 (v2 — keputusan final pasien)

---

## Daftar Isi

1. [Ringkasan Konteks](#1-ringkasan-konteks)
2. [Keputusan Final](#2-keputusan-final)
3. [Kolom yang Arumed Wajib Tambah Sebelum Migrasi](#3-kolom-yang-arumed-wajib-tambah-sebelum-migrasi)
4. [Tool yang Dibutuhkan](#4-tool-yang-dibutuhkan)
5. [Fase 1 — Setup Postgres Lokal & Restore Dump](#fase-1--setup-postgres-lokal--restore-dump)
6. [Fase 2 — Eksplorasi & Verifikasi Data](#fase-2--eksplorasi--verifikasi-data)
7. [Fase 3 — Mapping Detail per Tabel](#fase-3--mapping-detail-per-tabel)
8. [Fase 4 — Sample Transformation ke CSV](#fase-4--sample-transformation-ke-csv)
9. [Fase 5 — Migrasi Production via Seeder](#fase-5--migrasi-production-via-seeder)
10. [Checklist Keputusan yang Belum Final](#checklist-keputusan-yang-belum-final)
11. [Troubleshooting](#troubleshooting)
12. [Lampiran: Daftar File Backup](#lampiran-daftar-file-backup)

---

## 1. Ringkasan Konteks

### Sumber
- **Server:** Dell PowerEdge T40, Ubuntu 22.04, IP `192.168.100.20`
- **DB:** PostgreSQL, nama database `runningprima`, ukuran 2.4 GB
- **Aplikasi:** Prima Vision (SIMRS klinik mata, **masih aktif**)
- **Akses:** SSH `vision@192.168.100.20`

### Target
- **Aplikasi:** Arumed (Laravel 11)
- **DB:** PostgreSQL (DB baru, terpisah)
- **Status:** Development — belum production

### Tabel yang dimigrasi

| # | Sumber Prima Vision | Target Arumed | Baris |
|---|---|---|---|
| 1 | `pasien` | `patients` | 55,442 |
| 2 | `biodata` | `employees` + `users` | 154 |
| 3 | `registrasi` | `visits` | 53,146 |
| 4 | `penanggung_jawab` | (TBD) | 53,178 |
| 5 | `pemeriksaan_ro` | `refraction_records` | 50,819 |
| 6 | `pemeriksaan_dokter` | `doctor_examinations` | 51,302 |
| 7 | `pemeriksaan_dokter_icdten` | `doctor_examinations.diagnosis_sekunder` (JSONB) | 3,817 |
| 8 | `carabayar` | `insurers` | 48 |
| 9 | `asuransi` | `insurers` | 241 |

**Tidak dimigrasi (fase awal):**
- Workflow detail (jam, antrian, status)
- Billing/tagihan/kwitansi/klaim BPJS
- Resep & farmasi (Arumed punya e-resep sendiri, modul terpisah)
- Bedah & dokumen operasi
- `layanan_pasien` (528k baris)
- `cppt` (134k baris)
- `log_pengguna` (64k baris, 953 MB)

**Total estimasi:** ~210,000 baris dari ~1+ juta total.

---

## 2. Keputusan Final

### Field per pasien

| Field Prima Vision | Status | Catatan |
|---|---|---|
| `uuid` | → `legacy_uuid` | Untuk traceability |
| `rekam_medis` | → `no_rm` | Format Prima Vision dipertahankan apa adanya |
| `nama` | → `nama` | `'-'` → NULL |
| `no_ktp` (16 digit) | → `nik` | Prioritas pertama |
| `no_identitas` (16 digit, fallback) | → `nik` | Fallback jika `no_ktp` kosong |
| `jenis_identitas` + `no_identitas` (non-KTP) | → `jenis_identitas` + `no_identitas` Arumed | Untuk paspor/SIM |
| `tanggal_lahir` | → `dob` | Placeholder (`1000-01-01`) → NULL |
| `tempat_lahir` | → `tempat_lahir` | **Kolom baru di Arumed** |
| `jenis_kelamin` | → `gender` | Normalize ke enum Arumed |
| `alamat` | → `address` | `'-'` → NULL |
| `nama_provinsi` | → `province` | Direct |
| `nama_kab_kota` | → `nama_kab_kota` | **Kolom baru di Arumed (legacy string)** |
| `nama_kecamatan` | → `nama_kecamatan` | **Kolom baru di Arumed (legacy string)** |
| `nama_kelurahan` | → `nama_kelurahan` | **Kolom baru di Arumed (legacy string)** |
| `no_handphone` | → `phone` | `'-'` → NULL |
| `pekerjaan` | → `pekerjaan` | **Kolom baru di Arumed** |
| `golongan_darah` | → `golongan_darah` | **Kolom baru di Arumed** (data persistent, bukan per visit) |
| `created_at` | → `created_at` | Direct |
| `delete_soft = 0` | → `deleted_at = created_at` | Konversi soft delete |
| `agama`, `suku`, `status_pernikahan`, `pendidikan_terakhir`, `nama_ayah`, `nama_ibu`, `email`, `kodepos`, `rt_rw`, `kelompok_umur_*`, `alias`, `sebutan`, `is_printer_card`, `pasien_old_id`, `tahun`, `bulan`, `angka_nol`, `nomor` | DROP | Tidak dimigrasi |

### Strategi alamat

- **Data lama (Prima Vision):** Simpan sebagai **string nama** (legacy)
- **Data baru (input via Arumed):** Pakai **kode wilayah Kemendagri**
- Arumed butuh **dua set kolom**: ID (untuk data baru) + nama string (untuk legacy display)

**Konsekuensi yang harus diterima:**
- Pasien lama tidak punya `kab_kota_id` resmi → laporan demografi by ID akan miss data lama
- Saat pasien lama edit alamat di Arumed, perlu logika UI: konversi nama → ID resmi
- Laporan ke dinas kesehatan dengan kode wilayah hanya untuk data pasien baru

### Strategi NIK & identitas

- **`nik` Arumed:** HANYA 16 digit numerik (sesuai format KTP)
- **Pasien dengan paspor/SIM/identitas lain (bukan 16 digit):** masuk ke `jenis_identitas` + `no_identitas` Arumed
- **Logika:**
  ```sql
  -- Untuk kolom nik
  CASE
    WHEN no_ktp ~ '^[0-9]{16}$' THEN no_ktp
    WHEN no_identitas ~ '^[0-9]{16}$' THEN no_identitas
    ELSE NULL
  END AS nik,
  
  -- Untuk kolom jenis_identitas + no_identitas (kalau bukan KTP)
  CASE 
    WHEN no_ktp ~ '^[0-9]{16}$' THEN NULL
    ELSE NULLIF(jenis_identitas, '-')
  END AS jenis_identitas,
  CASE 
    WHEN no_ktp ~ '^[0-9]{16}$' THEN NULL
    ELSE NULLIF(no_identitas, '-')
  END AS no_identitas
  ```

### Format RM

- **Prima Vision:** `260503861` = `{tahun 2 digit}{bulan 2 digit}{angka_nol 1 digit}{nomor 4 digit}` (9 digit total)
- **Arumed:** `2026050003` = `{tahun 4 digit}{bulan 2 digit}{sequence 4 digit}` (10 digit total)
- **Strategi:** Pasien lama tetap format Prima Vision apa adanya, pasien baru tetap format Arumed. Beda panjang → tidak konflik.

### Soft delete
- `delete_soft = 0` (terhapus) → tetap dimigrasi, set `deleted_at = created_at`
- `delete_soft = 1` (aktif) → `deleted_at = NULL`

---

## 3. Kolom yang Arumed Wajib Tambah Sebelum Migrasi

**Tabel `patients`:**

| # | Kolom | Tipe | Nullable | Catatan |
|---|---|---|---|---|
| 1 | `legacy_uuid` | varchar(50) | YES | Indexed untuk lookup cepat |
| 2 | `tempat_lahir` | varchar(100) | YES | |
| 3 | `pekerjaan` | varchar(50) | YES | |
| 4 | `golongan_darah` | varchar(5) | YES | A/B/AB/O dengan +/- |
| 5 | `nama_kab_kota` | varchar(100) | YES | Legacy string |
| 6 | `nama_kecamatan` | varchar(100) | YES | Legacy string |
| 7 | `nama_kelurahan` | varchar(100) | YES | Legacy string |

**Total: 7 kolom baru.**

### Migration Laravel yang harus dibuat

```php
// database/migrations/2026_06_xx_add_legacy_fields_to_patients_table.php
public function up(): void
{
    Schema::table('patients', function (Blueprint $table) {
        $table->string('legacy_uuid', 50)->nullable()->index();
        $table->string('tempat_lahir', 100)->nullable();
        $table->string('pekerjaan', 50)->nullable();
        $table->string('golongan_darah', 5)->nullable();
        $table->string('nama_kab_kota', 100)->nullable();
        $table->string('nama_kecamatan', 100)->nullable();
        $table->string('nama_kelurahan', 100)->nullable();
    });
}

public function down(): void
{
    Schema::table('patients', function (Blueprint $table) {
        $table->dropColumn([
            'legacy_uuid',
            'tempat_lahir',
            'pekerjaan',
            'golongan_darah',
            'nama_kab_kota',
            'nama_kecamatan',
            'nama_kelurahan',
        ]);
    });
}
```

### Yang juga harus disesuaikan di Arumed (selain migration)

- [ ] Update Model `Patient` — tambah kolom ke `$fillable`
- [ ] Update Request validation rules untuk create/update patient
- [ ] Update UI form patient (tambah field tempat_lahir, pekerjaan, golongan_darah)
- [ ] Update API resource/transformer
- [ ] Update PDF templates yang menampilkan data pasien
- [ ] Update form RME yang pakai data pasien

[Medium confidence] Daftar di atas asumsi dari pattern Laravel umum. Validasi langsung di codebase Arumed.

---

## 4. Tool yang Dibutuhkan

| Tool | Untuk apa | Status |
|---|---|---|
| PostgreSQL di laptop | Restore dump + eksplorasi | Cek `psql --version` |
| DBeaver (opsional, disarankan) | GUI untuk query Postgres | https://dbeaver.io/ — Community gratis |
| VS Code | Edit SQL & file mapping | Sudah ada |
| Excel | Review CSV sample | Sudah ada |
| 7-Zip | Extract `.csv.gz` | https://www.7-zip.org/ |

---

## Fase 1 — Setup Postgres Lokal & Restore Dump

**Tujuan:** Restore `runningprima_20260531_1036.dump` ke DB lokal `primavision_local` di laptop.

**Estimasi waktu:** 15-30 menit.

### 1.1 Verifikasi Postgres terinstall

```cmd
psql --version
```

Harapan: `psql (PostgreSQL) 15.x` atau lebih baru.

Kalau belum terinstall, download dari https://www.postgresql.org/download/windows/. Saat install, catat password user `postgres`. Centang "Add to PATH" di akhir installer.

### 1.2 Cek Postgres running

```cmd
pg_isready
```

Harapan: `localhost:5432 - accepting connections`.

### 1.3 Buat DB lokal kosong

```cmd
psql -U postgres -c "CREATE DATABASE primavision_local;"
```

Verifikasi:
```cmd
psql -U postgres -c "\l" | findstr primavision
```

### 1.4 Restore dump

```cmd
cd C:\Users\PrimaVision\Desktop\primavision_backup
pg_restore -U postgres -d primavision_local --no-owner --no-privileges --verbose runningprima_20260531_1036.dump
```

Estimasi: 5-15 menit. Banyak output normal. Beberapa warning (constraint, sequence) dapat diabaikan.

### 1.5 Verifikasi restore sukses

```cmd
psql -U postgres -d primavision_local -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';"
```

Harapan: ~170 tabel.

```cmd
psql -U postgres -d primavision_local -c "SELECT 'pasien' AS tbl, count(*) FROM pasien UNION ALL SELECT 'pemeriksaan_ro', count(*) FROM pemeriksaan_ro UNION ALL SELECT 'pemeriksaan_dokter', count(*) FROM pemeriksaan_dokter;"
```

Harapan: pasien=55,442, pemeriksaan_ro=50,819, pemeriksaan_dokter=51,302.

### 1.6 Connect via DBeaver (opsional)

1. Install DBeaver Community Edition
2. New Connection → PostgreSQL
3. Host: `localhost`, Port: `5432`, Database: `primavision_local`, User: `postgres`
4. Test Connection → OK → Finish

---

## Fase 2 — Eksplorasi & Verifikasi Data

**Tujuan:** Verifikasi pattern data sebelum bikin parsing logic.

**Estimasi waktu:** 3-6 jam fokus.

### 2.1 Distribusi & validitas NIK

```sql
SELECT 
  count(*) AS total,
  count(*) FILTER (WHERE no_ktp ~ '^[0-9]{16}$') AS no_ktp_valid_16,
  count(*) FILTER (WHERE no_identitas ~ '^[0-9]{16}$') AS no_identitas_valid_16,
  count(*) FILTER (WHERE 
    no_ktp ~ '^[0-9]{16}$' OR no_identitas ~ '^[0-9]{16}$'
  ) AS punya_nik_valid
FROM pasien;

-- Distribusi jenis_identitas
SELECT jenis_identitas, count(*)
FROM pasien
GROUP BY jenis_identitas
ORDER BY count DESC;
```

Yang dicari: berapa persen pasien yang punya NIK valid. Berapa banyak yang harus pakai jenis_identitas non-KTP.

### 2.2 Format autoref (CRUCIAL)

```sql
-- Distribusi format
SELECT 
  CASE 
    WHEN ocular_dextra_autoref ~ '^S' THEN 'mulai_dengan_S'
    WHEN ocular_dextra_autoref ~ '^[+-]?[0-9]' THEN 'mulai_dengan_angka'
    WHEN ocular_dextra_autoref IN ('-', '', '0.0') THEN 'kosong'
    ELSE 'lain'
  END AS format_type,
  count(*) AS jumlah
FROM pemeriksaan_ro
GROUP BY format_type;

-- Sample 30 nilai non-kosong
SELECT DISTINCT ocular_dextra_autoref
FROM pemeriksaan_ro
WHERE ocular_dextra_autoref NOT IN ('-', '', '0.0')
LIMIT 30;
```

### 2.3 Field `kacamata_lama_addisi` vs `add`

```sql
SELECT 
  count(*) FILTER (WHERE ocular_dextra_kacamata_lama_addisi NOT IN ('-', '', NULL)) AS addisi_terisi,
  count(*) FILTER (WHERE ocular_dextra_kacamata_lama_add NOT IN ('-', '', NULL)) AS add_terisi,
  count(*) FILTER (WHERE 
    ocular_dextra_kacamata_lama_addisi NOT IN ('-', '', NULL) 
    AND ocular_dextra_kacamata_lama_add NOT IN ('-', '', NULL)
  ) AS keduanya_terisi
FROM pemeriksaan_ro;

-- Sample distinct values
SELECT DISTINCT ocular_dextra_kacamata_lama_addisi
FROM pemeriksaan_ro
WHERE ocular_dextra_kacamata_lama_addisi NOT IN ('-', '', NULL)
LIMIT 20;
```

Yang dicari: `addisi` itu axis (0-180) atau addition (0.50-3.00)?

### 2.4 Distribusi enum

```sql
SELECT jenis_kelamin, count(*) FROM pasien GROUP BY jenis_kelamin ORDER BY count DESC;
SELECT pekerjaan, count(*) FROM pasien GROUP BY pekerjaan ORDER BY count DESC LIMIT 30;
SELECT golongan_darah, count(*) FROM pasien GROUP BY golongan_darah ORDER BY count DESC;
```

### 2.5 Placeholder & data dirty

```sql
SELECT 
  count(*) AS total,
  count(*) FILTER (WHERE nama = '-') AS nama_placeholder,
  count(*) FILTER (WHERE tempat_lahir = '-') AS tempat_lahir_placeholder,
  count(*) FILTER (WHERE alamat = '-') AS alamat_placeholder,
  count(*) FILTER (WHERE tanggal_lahir = '1000-01-01') AS dob_placeholder,
  count(*) FILTER (WHERE no_handphone = '-') AS phone_placeholder,
  count(*) FILTER (WHERE pekerjaan = '-') AS pekerjaan_placeholder,
  count(*) FILTER (WHERE golongan_darah = '-') AS goldar_placeholder
FROM pasien;
```

### 2.6 Relasi & orphan

```sql
-- Registrasi tanpa pasien valid
SELECT count(*) FROM registrasi r
LEFT JOIN pasien p ON p.uuid = r.pasien_uuid
WHERE p.id IS NULL;

-- Pemeriksaan_ro tanpa registrasi valid
SELECT count(*) FROM pemeriksaan_ro pr
LEFT JOIN registrasi r ON r.uuid = pr.registrasi_uuid
WHERE r.id IS NULL;

-- Pasien tanpa registrasi
SELECT count(*) FROM pasien p
LEFT JOIN registrasi r ON r.pasien_uuid = p.uuid
WHERE r.id IS NULL;
```

### 2.7 Format rekam_medis

```sql
SELECT length(rekam_medis) AS panjang, count(*)
FROM pasien
GROUP BY length(rekam_medis)
ORDER BY panjang;

SELECT rekam_medis, tahun, bulan, angka_nol, nomor
FROM pasien
ORDER BY id DESC LIMIT 10;
```

### 2.8 Catat observasi

Bikin `OBSERVASI.md` di folder backup. Format:

```markdown
## Observasi Fase 2

### NIK
- Pasien punya NIK valid 16 digit: X dari 55,442 (Y%)
- Yang fallback ke jenis_identitas non-KTP: Z

### Autoref
- Sample format: ...
- Pattern dominan: ...
- Persentase kosong: ...

### Kacamata lama "addisi"
- Distribusi nilai: ...
- Kesimpulan: addisi = axis / addition

### Placeholder
- nama placeholder '-': X
- dob placeholder '1000-01-01': Y
- ...

### Orphan
- registrasi tanpa pasien: X
- pemeriksaan_ro tanpa registrasi: Y
```

---

## Fase 3 — Mapping Detail per Tabel

**Prasyarat:** Fase 2 selesai + schema Arumed (file migration Laravel) sudah didapat.

### 3.1 `pasien` → `patients`

| Prima Vision | Arumed | Transform |
|---|---|---|
| `uuid` | `legacy_uuid` | Direct |
| `rekam_medis` | `no_rm` | Direct (format Prima Vision dipertahankan) |
| `nama` | `nama` | `NULLIF(nama, '-')` |
| `no_ktp` / `no_identitas` (16 digit) | `nik` | Lihat logika di Section 2 |
| `jenis_identitas` (non-KTP) | `jenis_identitas` | `NULLIF` |
| `no_identitas` (non-KTP) | `no_identitas` | `NULLIF` |
| `tanggal_lahir` | `dob` | `NULLIF(tanggal_lahir, '1000-01-01'::date)` |
| `tempat_lahir` | `tempat_lahir` | `NULLIF(tempat_lahir, '-')` |
| `jenis_kelamin` | `gender` | Normalize ke enum (cek Arumed) |
| `alamat` | `address` | `NULLIF` |
| `nama_provinsi` | `province` | `NULLIF` |
| `nama_kab_kota` | `nama_kab_kota` | `NULLIF` |
| `nama_kecamatan` | `nama_kecamatan` | `NULLIF` |
| `nama_kelurahan` | `nama_kelurahan` | `NULLIF` |
| `no_handphone` | `phone` | `NULLIF` + normalize format |
| `pekerjaan` | `pekerjaan` | `NULLIF` |
| `golongan_darah` | `golongan_darah` | `NULLIF` + normalize (A+/A-/B+ dst) |
| `created_at` | `created_at` | Direct |
| `delete_soft = 0` | `deleted_at = created_at` | Konversi |

### 3.2 `biodata` → `employees` + `users`

**TBD** — butuh schema `employees` & `users` Arumed. Tabel `biodata` Prima Vision gabung data pribadi + bank + BPJS ketenagakerjaan + signature → di Arumed kemungkinan dipisah.

### 3.3 `registrasi` → `visits`

Hanya field header:

| Prima Vision | Arumed `visits` | Transform |
|---|---|---|
| `uuid` | `legacy_uuid` | Direct |
| `pasien_uuid` | `patient_id` | Lookup ke `patients.legacy_uuid` |
| `tanggal` | `visit_date` (TBD) | Direct |
| `jenis` | `classification` | Map ke enum Arumed |
| `carabayar_nama` | `guarantor_type` | Map: UMUM/BPJS/ASURANSI |
| `nama_asuransi` | `insurer_id` | Lookup ke `insurers.legacy_uuid` |
| `pengguna_uuid` (dokter) | `doctor_id` | Lookup ke `employees.legacy_uuid` |
| `created_at` | `created_at` | Direct |

**Tidak dimigrasi:** kolom `*_jam_*`, `posisi_antrian_*`, `status_antrian_*`, billing/klaim/kamar inap/bedah.

### 3.4 `pemeriksaan_ro` → `refraction_records` (KOMPLEKS)

String parsing yang dibutuhkan:

| Prima Vision (string) | Arumed (numerik) | Parsing |
|---|---|---|
| `ocular_dextra_autoref` `"S-1.00 C-0.50 X90"` | `autoref_od_sph`, `autoref_od_cyl`, `autoref_od_axis` | Regex `S([+-]?\d+\.?\d*)\s+C([+-]?\d+\.?\d*)\s+X(\d+)` |
| `ocular_sinistra_autoref` | `autoref_os_*` | Sama |
| `ocular_*_keratometri_k1`, `k2` | `keratometri1_*`, `keratometri2_*` | Cast ke numeric |
| `ocular_*_visus` | `visus_awal_*` | Direct |
| `ocular_*_bcva1` / `bcva2` | `visus_akhir_*` | Pilih bcva2 jika ada, fallback bcva1 |
| `ocular_*_pinhole` | `pinhole_*` | Direct |
| `ocular_*_add` | `add_power_*` | Cast numeric |
| `ocular_*_tonometri` | `iop_*` | Cast numeric |
| `ocular_dextra_pd` | `pd_distance` (shared) | Pilih salah satu mata |
| `ocular_*_kacamata_lama_sph/cyl/addisi` | `old_glasses_*_sph/cyl/axis` | Parsing + normalize |
| `ocular_*_kacamata_lama_add` | `old_glasses_add_*` | Cast numeric |
| `registrasi_uuid` | `visit_id` | Lookup |
| `pengguna_uuid` | `examiner_id` | Lookup |
| `tanggal` + `waktu` | `examination_date` | Concat: `tanggal || ' ' || waktu` |

**Tantangan:**
- Parsing autoref harus toleran (catatan: confirm format di Fase 2)
- Decimal separator (`.` atau `,`)
- Negative sign Unicode vs ASCII

### 3.5 `pemeriksaan_dokter` → `doctor_examinations`

Hanya SOAP + ICD:

| Prima Vision | Arumed | Transform |
|---|---|---|
| `anamnese` | `anamnese` / `soap_subjective` | Direct |
| `pemeriksaan_diagnosa_kode` | `diagnosis_utama` | Direct |
| ICD-10 sekunder dari `pemeriksaan_dokter_icdten` | `diagnosis_sekunder` (JSONB) | Aggregate array |
| `pemeriksaan_tindakan_kode` | `tindakan_codes` (JSONB) | Wrap array |
| `pilihan_plan` | `planning` | Map enum |
| `registrasi_uuid` | `visit_id` | Lookup |
| `pengguna_uuid` | `doctor_id` | Lookup |

Aggregate ICD-10 sekunder:

```sql
SELECT 
  pd.uuid,
  pd.pemeriksaan_diagnosa_kode AS diagnosis_utama,
  json_agg(icdten.kode_icdten ORDER BY icdten.created_at) FILTER (WHERE icdten.kode_icdten IS NOT NULL) AS diagnosis_sekunder
FROM pemeriksaan_dokter pd
LEFT JOIN pemeriksaan_dokter_icdten icdten 
  ON icdten.pemeriksaan_dokter_uuid = pd.uuid
GROUP BY pd.uuid, pd.pemeriksaan_diagnosa_kode;
```

### 3.6 `carabayar` + `asuransi` → `insurers`

```sql
SELECT uuid AS legacy_uuid, nama AS insurer_name, 'GUARANTOR' AS insurer_type
FROM carabayar
UNION ALL
SELECT uuid AS legacy_uuid, nama AS insurer_name, 'ASURANSI' AS insurer_type
FROM asuransi;
```

Cek apakah Arumed sudah pre-load `insurers` (UMUM & BPJS built-in). Kalau iya, skip dua row itu.

---

## Fase 4 — Sample Transformation ke CSV

**Tujuan:** Test transformasi di 100 baris, review di Excel.

### 4.1 Contoh SQL transformasi pasien

```sql
-- File: transform_pasien_sample.sql
COPY (
  SELECT
    uuid AS legacy_uuid,
    rekam_medis AS no_rm,
    NULLIF(nama, '-') AS nama,
    -- NIK
    CASE
      WHEN no_ktp ~ '^[0-9]{16}$' THEN no_ktp
      WHEN no_identitas ~ '^[0-9]{16}$' THEN no_identitas
      ELSE NULL
    END AS nik,
    -- Jenis identitas (non-KTP)
    CASE 
      WHEN no_ktp ~ '^[0-9]{16}$' THEN NULL
      WHEN no_identitas ~ '^[0-9]{16}$' THEN NULL
      ELSE NULLIF(jenis_identitas, '-')
    END AS jenis_identitas,
    CASE 
      WHEN no_ktp ~ '^[0-9]{16}$' THEN NULL
      WHEN no_identitas ~ '^[0-9]{16}$' THEN NULL
      ELSE NULLIF(no_identitas, '-')
    END AS no_identitas_lain,
    NULLIF(tempat_lahir, '-') AS tempat_lahir,
    NULLIF(tanggal_lahir, '1000-01-01'::date) AS dob,
    CASE
      WHEN UPPER(TRIM(jenis_kelamin)) IN ('LAKI-LAKI', 'LAKI LAKI', 'L', 'LAKI', 'PRIA') THEN 'LAKI-LAKI'
      WHEN UPPER(TRIM(jenis_kelamin)) IN ('PEREMPUAN', 'P', 'WANITA') THEN 'PEREMPUAN'
      ELSE NULL
    END AS gender,
    NULLIF(alamat, '-') AS address,
    NULLIF(nama_provinsi, '-') AS province,
    NULLIF(nama_kab_kota, '-') AS nama_kab_kota,
    NULLIF(nama_kecamatan, '-') AS nama_kecamatan,
    NULLIF(nama_kelurahan, '-') AS nama_kelurahan,
    NULLIF(no_handphone, '-') AS phone,
    NULLIF(pekerjaan, '-') AS pekerjaan,
    NULLIF(golongan_darah, '-') AS golongan_darah,
    created_at,
    CASE WHEN delete_soft = 0 THEN created_at ELSE NULL END AS deleted_at
  FROM pasien
  ORDER BY id ASC
  LIMIT 100
) TO 'C:/Users/PrimaVision/Desktop/sample_patients.csv' WITH CSV HEADER;
```

Jalankan:
```cmd
psql -U postgres -d primavision_local -f transform_pasien_sample.sql
```

### 4.2 Review di Excel

Checklist saat buka CSV:
- [ ] `no_rm` text (leading zero terjaga)
- [ ] `nik` 16 digit untuk KTP
- [ ] `jenis_identitas` + `no_identitas` terisi untuk non-KTP
- [ ] `dob` NULL untuk placeholder `1000-01-01`
- [ ] `gender` konsisten ENUM
- [ ] Placeholder `'-'` → NULL semua
- [ ] `pekerjaan`, `golongan_darah` terisi sesuai data sumber

### 4.3 Urutan sample untuk tiap tabel

1. `pasien` (paling penting, paling kompleks NULL handling)
2. `carabayar` + `asuransi` (simple)
3. `biodata` (mid)
4. `registrasi` (mid, banyak FK lookup)
5. `pemeriksaan_dokter` + `icdten` (mid, ICD aggregation)
6. `pemeriksaan_ro` (sulit, string parsing)
7. `penanggung_jawab` (final)

### 4.4 Validasi statistik

```sql
SELECT 
  count(*) AS total,
  count(nik) AS punya_nik,
  count(*) FILTER (WHERE nik IS NULL AND jenis_identitas IS NOT NULL) AS pakai_jenis_lain,
  count(*) FILTER (WHERE nik IS NULL AND jenis_identitas IS NULL) AS tanpa_identitas
FROM (
  -- Subquery dari transformasi di atas
) AS transformed;
```

**Threshold:** tentukan target. Misal: parsing autoref gagal maksimal 5%, sisanya simpan raw.

---

## Fase 5 — Migrasi Production via Seeder

**Prasyarat:** Fase 4 sukses, schema Arumed stabil, 7 kolom baru di patients sudah ditambah.

### 5.1 Laravel command

```bash
php artisan make:command MigrateFromPrimaVision
```

Command ini:
1. Connect ke DB `primavision_local` (second DB)
2. Stream per chunk 1000 baris
3. Transform per baris (SQL → PHP atau pakai raw query)
4. Insert ke DB Arumed
5. Track progress + log error

### 5.2 Second DB config

`config/database.php`:

```php
'connections' => [
    'pgsql' => [...], // Arumed default
    'primavision' => [
        'driver' => 'pgsql',
        'host' => 'localhost',
        'port' => '5432',
        'database' => 'primavision_local',
        'username' => 'postgres',
        'password' => env('PRIMAVISION_DB_PASSWORD'),
    ],
],
```

### 5.3 Urutan migrasi (FK dependency)

1. `insurers` (master, no FK)
2. `employees` + `users` dari `biodata`
3. `patients` dari `pasien`
4. `visits` dari `registrasi`
5. `refraction_records` dari `pemeriksaan_ro`
6. `doctor_examinations` dari `pemeriksaan_dokter` + `icdten`

### 5.4 Validasi setelah migrasi

```sql
-- Count match
SELECT 'patients' AS tbl, count(*) FROM patients
UNION ALL SELECT 'visits', count(*) FROM visits
UNION ALL SELECT 'refraction_records', count(*) FROM refraction_records
UNION ALL SELECT 'doctor_examinations', count(*) FROM doctor_examinations;

-- Orphan check
SELECT count(*) FROM visits WHERE patient_id NOT IN (SELECT id FROM patients);
SELECT count(*) FROM refraction_records WHERE visit_id NOT IN (SELECT id FROM visits);
```

### 5.5 Cut-off plan

Prima Vision masih aktif → butuh strategi cut-off.

**Opsi A (paling aman):** Freeze Prima Vision di hari X, restore dump baru, migrasi, go-live Arumed di hari X+1. Downtime 1 hari.

**Opsi B (zero downtime):** Sync delta. **Risiko tinggi**, hindari kecuali sudah berpengalaman.

[High confidence] **Untuk klinik mata, Opsi A jauh lebih aman.** Downtime Sabtu/Minggu cukup.

---

## 10. Checklist Keputusan yang Belum Final

### Schema Arumed (perlu file migration Laravel)
- [ ] File `xxxx_create_patients_table.php` — confirm nama kolom existing
- [ ] File `xxxx_create_visits_table.php`
- [ ] File `xxxx_create_refraction_records_table.php`
- [ ] File `xxxx_create_doctor_examinations_table.php`
- [ ] File `xxxx_create_employees_table.php` + `users`
- [ ] File `xxxx_create_insurers_table.php`

### Implementasi Arumed (sebelum migrasi production)
- [ ] Migration tambah 7 kolom di `patients` (legacy_uuid, tempat_lahir, pekerjaan, golongan_darah, nama_kab_kota, nama_kecamatan, nama_kelurahan)
- [ ] Update Model Patient (`$fillable`)
- [ ] Update Request validation
- [ ] Update UI form patient
- [ ] Update API resource
- [ ] Update PDF templates

### Setelah Fase 2 (eksplorasi)
- [ ] Format autoref aktual (confirm regex pattern)
- [ ] `kacamata_lama_addisi` = axis atau addition
- [ ] Persentase NIK valid vs fallback
- [ ] Pasien orphan: migrasi atau skip?

### Cut-off & go-live
- [ ] Tanggal target go-live Arumed
- [ ] Tanggal freeze Prima Vision
- [ ] Strategi rollback
- [ ] Komunikasi ke operator klinik
- [ ] Backup final sebelum go-live

---

## 11. Troubleshooting

### `pg_restore: error: could not connect to database`
- Cek `pg_isready`
- Pastikan DB tujuan sudah dibuat

### `pg_restore: error: role "postgres-old" does not exist`
- Pakai flag `--no-owner` (sudah ada di guide)
- Atau buat role: `CREATE ROLE "postgres-old";`

### CSV di Excel: leading zero hilang
- Data → From Text/CSV → set kolom sebagai "Text"
- Jangan double-click CSV

### CSV rusak (koma/newline di data)
- Pakai DBeaver untuk lihat data
- Atau export dengan delimiter berbeda: `\COPY ... WITH CSV HEADER DELIMITER '|'`

---

## 12. Lampiran: Daftar File Backup

Folder `C:\Users\PrimaVision\Desktop\primavision_backup\`:

| File | Ukuran | Fungsi |
|---|---|---|
| `runningprima_20260531_1036.dump` | 263 MB | **Dump utama** |
| `primavision_20260531_1027.dump` | 74 MB | Dump DB lama (referensi) |
| `runningprima_schema.sql` | 567 KB | DDL Schema |
| `runningprima_tables.txt` | 17 KB | Daftar tabel |
| `top50_tables_fresh.txt` | 2.8 KB | Top tabel |
| `struct_*.txt` | bervariasi | Struktur tabel kunci |
| `csv/*.csv.gz` | 32 MB total | Data ekstrak |
| `MIGRASI_PRIMAVISION_ARUMED.md` | — | Panduan ini |
| `OBSERVASI.md` | — | (akan dibuat di Fase 2) |
| `MIGRASI_NOTES.md` | — | Catatan keputusan |

---

## Catatan Penutup

**Realitas timeline:**

| Fase | Estimasi |
|---|---|
| 1. Setup + restore | 30 menit |
| 2. Eksplorasi & verifikasi | 3-6 jam |
| 3. Mapping detail | 1-2 hari |
| 4. Sample transformation | 1-2 hari |
| 5. Production + validasi | 3-7 hari |
| 6. Cut-off, go-live, monitoring | 1-3 hari |

**Total realistis:** 2-3 minggu kerja, bukan satu hari.

**Risiko terbesar:**
1. Bekerja sendiri tanpa code review = single point of failure
2. Schema Arumed berubah saat development → mapping invalid
3. Prima Vision masih aktif → data baru terus masuk
4. Parsing autoref/refraksi gagal sebagian → loss klinis history

**Mitigasi:**
1. Minta developer Arumed (kalau bukan kamu) review sample seeder sebelum production
2. Freeze schema Arumed (patients/visits/refraction/dokter) sebelum mulai Fase 3
3. Backup ulang dump Prima Vision dekat tanggal cut-off
4. Tetapkan threshold parsing failure (misal 5%); sisanya simpan raw_data JSONB untuk recovery

**Yang paling penting:**
- Backup `runningprima_20260531_1036.dump` di laptop = jaring pengaman emas
- Simpan minimal 2 copy (laptop + cloud/external drive)
- Jangan pernah hapus sampai Arumed stabil production minimal 6 bulan
- **DB Prima Vision di server JANGAN DISENTUH** selama proses migrasi — semua kerja transformasi di Postgres lokal
