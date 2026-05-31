# OBSERVASI Fase 2 — Eksplorasi Data Prima Vision

**Tanggal:** 2026-05-31
**Metode:** Parsing langsung 9 CSV gzip (`Docs/migrasi data/csv/*.csv.gz`) via PowerShell + Microsoft.VisualBasic CSV parser (quote-aware). **Tanpa restore Postgres** — CSV cukup.

> ⚠️ Beberapa temuan **mengoreksi asumsi di `MIGRASI_PRIMAVISION_ARUMED_v2.md`**. Lihat bagian "Koreksi Dokumen v2" di bawah.

---

## Ringkasan jumlah baris (semua file utuh)

| Tabel | Data rows | Kolom |
|---|---|---|
| pasien | 55.442 | 47 |
| biodata | 154 | 54 |
| registrasi | 53.146 | 145 |
| pemeriksaan_ro | 50.819 | 90 |
| pemeriksaan_dokter | 51.303 | 49 |
| pemeriksaan_dokter_icdten | 3.817 | 14 |
| carabayar | 48 | 7 |
| asuransi | 241 | 10 |
| penanggung_jawab | 53.178 | 24 |

---

## 1. `delete_soft` — KOREKSI BESAR

- **`pasien`, `registrasi`, `pemeriksaan_ro`: SEMUA baris `delete_soft = 1`.** Tidak ada satupun `0`.
- **`biodata` SAJA yang punya `0`:** 42 nonaktif (`0`) + 112 aktif (`1`).

**Kesimpulan:**
- CSV pasien/registrasi/ro tampaknya **sudah difilter hanya data aktif** saat export. Konvensi: `delete_soft = 1` = **AKTIF** (sesuai `status='active'`), `0` = nonaktif. **Ini KEBALIKAN dari asumsi dokumen v2** ("`delete_soft=0`→terhapus, set deleted_at=created_at").
- **Aksi:** Untuk pasien/registrasi/ro → **semua `deleted_at = NULL`**, tidak ada konversi soft-delete. Untuk `biodata`/users → yang `delete_soft=0` di-nonaktifkan (`deleted_at` / flag inactive).

---

## 2. NIK (`pasien`)

| Metrik | Jumlah | % |
|---|---|---|
| `no_ktp` valid 16-digit | 37.238 | 67,2% |
| fallback `no_identitas` 16-digit | 3.883 | 7,0% |
| **TANPA NIK valid** | **14.321** | **25,8%** |

- `no_ktp` kosong/`'-'`: 14.187 ; `no_identitas` kosong/`'-'`: 9.473.
- **Keputusan terbuka:** 25,8% pasien (14.321) tanpa NIK valid. Identitas utama tetap `no_rm`, jadi **tetap dimigrasi dengan `nik=NULL`** (rekomendasi). NIK bukan syarat.

---

## 3. Format `rekam_medis` — KOREKSI

Panjang RM **tidak seragam**:

| Panjang | Jumlah |
|---|---|
| 6 digit | 35.703 (64%) |
| 9 digit | 19.738 (36%) |
| 8 digit | 1 |

- Dokumen v2 mengira semua 9-digit (`{tahun}{bulan}{angka_nol}{nomor}`). Faktanya mayoritas **6-digit**.
- **Aman:** RM Arumed baru = 10 digit → 6/8/9 ≠ 10, **tidak konflik**. Pertahankan apa adanya (`no_rm` = string).
- Parsing komponen tahun/bulan dari RM **tidak perlu** (kita pakai string mentah).

---

## 4. Placeholder & data kosong (`pasien`)

| Field | Placeholder `'-'` / kosong |
|---|---|
| nama = '-' | 2 |
| alamat = '-' | 8 |
| no_handphone = '-' | 1.070 |
| **pekerjaan = '-'** | **55.422 (≈100% kosong)** |
| **golongan_darah = '-'** | **55.438 (≈100% kosong)** |
| tanggal_lahir placeholder (`1000-01-01`) | 0 |
| tanggal_lahir `1990-09-09` | 4 |

- **`pekerjaan` & `golongan_darah` praktis kosong total.** 2 dari 7 kolom baru tidak terisi data migrasi — tetap ditambah untuk input baru, tapi seeder tidak mengisi apa-apa.
- **dob hampir selalu terisi valid** (placeholder ~nihil). Bagus.
- gender: 11 baris `''`/`'-'` → NULL; sisanya `Laki-Laki` / `Perempuan` (kapitalisasi: `Laki-Laki`, BUKAN `LAKI-LAKI`). **Cek enum gender Arumed aktual** sebelum normalisasi.

---

## 5. Alamat — sumber PUNYA kode wilayah sebagian

- `provinsi_id` terisi (non-0): **35,6%** (19.732 dari 55.442). Ada juga `kab_kota_id/kecamatan_id/kelurahan_id` + versi `nama_*`.
- Artinya: **bukan "tanpa kode wilayah"**. ~36% data bisa langsung pakai kode; sisanya hanya string nama.
- Strategi "legacy string" dokumen tetap valid sebagai fallback, tapi pertimbangkan **migrasi `*_id` juga** untuk 36% yang punya (kalau skema kode cocok Kemendagri).

---

## 6. `pemeriksaan_ro` — autoref PALING KOTOR

Distribusi `ocular_dextra_autoref`:

| Kategori | Jumlah | % |
|---|---|---|
| kosong / `0.0` / `-` | 7.463 | 14,7% |
| mulai `S` (parsable) | 31.885 | 62,7% |
| mulai angka | 8 | 0,0% |
| **lain (tak terduga)** | **11.463** | **22,6%** |

**Variasi yang HARUS ditangani parser:**
- Spasi tak konsisten: `S+0.75`, `S +0.75`, `S+ 0.75`, `S + 0.75`
- **Desimal koma Indonesia**: `S+ 0,50 C- 1,75 X 85`
- **Notasi ×100 tanpa titik**: `S-275 C-125 X 150` (= S-2.75 C-1.25)
- Axis nempel: `X100` vs `X 100`
- **Silinder tanpa S**: `C -3.00 X 90`
- **Literal sampah**: `error`, `ERROR`, `Error` → NULL

➡️ **Strategi:** regex toleran + **WAJIB simpan raw string** ke kolom cadangan (`autoref_od_raw` / `raw_data` JSONB). ~22% gagal-parse jangan dibuang.

### `kacamata_lama_addisi` vs `add` — TERJAWAB
- `addisi` (lama) terisi **2.225** ; `add` (baru) terisi **80**. **Field aktif = `addisi`.**
- Nilai jelas **ADDITION power** (`+1.00`…`+4.00`), **BUKAN axis**. → map ke `old_glasses_add_*`, bukan axis.
- Sampah perlu cleaning: `0)`, `+.300`, `+2..25`, notasi ×100 (`175`, `+225`, `+300`).

### `visus` — simpan string apa adanya
- `6/7.5`, `6/7,5`, `6/9.5`, `6//7.5`, `6/7.5F`, backtick, plus teks bebas (`px tidak mau visus`, `px langsung ke dokter`). **Jangan paksa numerik** — varchar as-is.

---

## 7. `registrasi`

- `delete_soft` = 1 semua (lihat §1).
- **Orphan:** 6 baris `pasien_uuid` tak ada di `pasien` (0,01%) → skip aman.
- `jenis`: Rawat Jalan 52.102 · Rawat Inap 951 · One Day Care 89 · IGD 4. → map ke `classification`/`visit_type`.
- `carabayar_nama` dominan: **BPJS Kesehatan 40.662 (76%)** · Umum 10.372 · sisanya asuransi/perusahaan (PLN, Mandiri inHealth, ADMEDIKA, Prodia, dst). → cocokkan **by-name** ke `insurers` Arumed (BPJS & Umum kemungkinan built-in, skip duplikat).

---

## 8. `pemeriksaan_dokter_icdten`

- 3.817 baris, orphan ke registrasi: **1** (abaikan).
- **Kode ICD-10 mengandung SPASI**: `H 25.1`, `Z 96.1`, `H 35.0`. → **normalisasi hapus spasi** (`H25.1`) saat map ke `diagnosis_utama`/`diagnosis_sekunder`, lalu validasi terhadap master ICD-10 Arumed.

---

## 9. `biodata` → employees + users

- `delete_soft`: 42 nonaktif (`0`) + 112 aktif (`1`).
- `sebagai_pengguna` (peran) **sangat tidak rapi**: `Dokter Spesialis Mata`, `dokter mata`, `Dokter Umum`, `RO`, `Perawat`, `Kasir`, `Farmasi`, dll (≈38 variasi). → **mapping manual ke role RBAC Arumed**, tidak bisa otomatis.
- `sima` (SIP?) & `simc` (STR?) terisi 44/154 ; `ttd` asli cuma 9. → relevan untuk profil nakes (NIP/SIP/STR).

---

## Koreksi Dokumen v2 (`MIGRASI_PRIMAVISION_ARUMED_v2.md`)

1. **§"Soft delete"** — SALAH untuk dataset ini. pasien/registrasi/ro semua `delete_soft=1` (aktif). Tidak ada konversi `deleted_at=created_at`. Hanya `biodata` punya `0`.
2. **§"Format RM"** — mayoritas RM **6-digit**, bukan 9. Tetap aman (≠10 digit Arumed) tapi narasi parsing komponen tidak berlaku.
3. **§"Strategi alamat"** — sumber **punya `*_id` kode wilayah 36%**, bukan nol. Pertimbangkan migrasi kode juga.
4. **dob placeholder** `1000-01-01` ternyata **0 baris** (yang muncul `1990-09-09`, 4 baris). Default schema ro = `1990-09-09`.
5. **autoref "lain" 22,6%** termasuk literal `error` & silinder-tanpa-S → pertegas kebutuhan kolom **raw**.

---

## Pertanyaan terbuka yang kini TERJAWAB

- ✅ Format autoref aktual → 62,7% `S...` parsable, 22,6% kotor, butuh raw fallback.
- ✅ `kacamata_lama_addisi` = **addition** (bukan axis).
- ✅ Persentase NIK valid → 74,2% (67,2% KTP + 7% fallback); 25,8% tanpa NIK.
- ✅ Pasien orphan → praktis nol (6 registrasi, 1 icdten) → skip.

## Masih perlu keputusan user / verifikasi codebase

- [ ] Enum `gender` Arumed aktual (`Laki-Laki`/`Perempuan` vs `L`/`P` vs `LAKI-LAKI`).
- [ ] Migrasi pasien tanpa NIK (14.321) → tetap migrasi `nik=NULL`? (rekomendasi: ya)
- [ ] Migrasi kode wilayah `*_id` (36%) atau cukup string nama legacy?
- [ ] Mapping `sebagai_pengguna` → role RBAC (manual, butuh tabel pemetaan).
- [ ] Skema kolom raw untuk autoref/refraksi yang gagal parse (kolom `*_raw` atau `raw_data` JSONB di `refraction_records`).
