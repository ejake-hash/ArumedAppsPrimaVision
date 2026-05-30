---
name: feature-master-data-stage1
description: "Master Data Stage 1+2 — selesai 2026-05-26, lalu di-rev (2026-05-26 sesi malam): Tarif Tindakan pindah ke Tarif & Paket Bedah, Obat/BHP/IOL pindah ke modul Inventori Farmasi standalone. Master Data sekarang hanya berisi Profil Klinik, ICD-10, ICD-9, Wilayah. Lihat [[feature-tarif-paket-bedah]] dan [[feature-inventori-farmasi]]."
metadata:
  node_type: memory
  type: project
  originSessionId: ec6fc0c4-c948-49e3-a047-f7cd65331c6c
---

Halaman **Master Data** (`/master-data/*`) selesai dibangun pada **2026-05-26** (Stage 1: 8 resource), lalu **direstrukturisasi** di sesi 2026-05-26 malam:

- **Tarif Tindakan dipindah** ke sub-modul Tarif & Paket Bedah → `/tarif-paket/tarif-tindakan` (file komponennya tetap di `views/master-data/TarifTindakanView.vue` — hanya router yang mengarah ke `/tarif-paket/*`). Lihat [[feature-tarif-paket-bedah]].
- **Obat/BHP/IOL dipindah** ke modul standalone Inventori Farmasi `/inventori-farmasi/{obat,bhp,iol}` (file view fisik tetap di `views/master-data/`, router yang reroute). Lihat [[feature-inventori-farmasi]].
- **Master Data sekarang hanya berisi**: Profil Klinik, ICD-10, ICD-9, Wilayah Indonesia. Section "Tarif" dan "Inventori" sudah dihapus dari [MasterDataLayout.vue](arumed-frontend/src/views/master-data/MasterDataLayout.vue) sidebar.
- Default redirect `/master-data` → `master-profil-klinik` (bukan `tarif-tindakan` lagi).

**How to apply:** Sebelum bikin resource master baru, cek [[project-arumed]] untuk pola umum, lalu **pakai infrastruktur yang sudah ada** — jangan bikin pola CRUD baru, ekstend yang ada. Pola eksekusi yang dipakai berulang dan terbukti: **A→F berurutan** (Migration → Model → Controller validasi → Service filter → CSV schema → RBAC route+seeder).

## Stage 1 (terbukti, masih dipakai)

**Backend** ([MasterDataController.php](backend/app/Http/Controllers/MasterDataController.php) + [MasterDataService.php](backend/app/Services/MasterDataService.php)):
- 3 method generic CSV: `csvTemplate(type)`, `exportResourceCsv(type)`, `importResourceCsv(type, csv)` — pakai `resourceSchema()` registry (private method) untuk map kolom & cast per type.
- Whitelist type via konstanta `MasterDataController::CSV_TYPES = ['tindakan', 'obat', 'bhp', 'iol', 'icd10', 'icd9']`.
- **Special-case** `tindakan` di `csvTemplate`/`exportResourceCsv`/`importResourceCsv` — pakai header friendly **tanpa kode** (lihat bagian "Rev Mei 2026" di bawah).
- 15+ route CSV (template/export/import) — **WAJIB didaftar SEBELUM `/{id}`** supaya tidak ditangkap parameter.

**Frontend** ([arumed-frontend/src/views/master-data/](arumed-frontend/src/views/master-data/)):
- Layout shell `MasterDataLayout.vue` — vertical tabs nav (2 section: Administrasi/Klasifikasi setelah rev).
- Komponen reusable di `src/components/master-data/`: `MasterTable`, `MasterFormModal`, `CsvActionBar`, `WilayahPicker`.
- **5 view tipis** (ObatView, BhpView, IolView, Icd10View, Icd9View) pakai generic `_CrudResourceView.vue` — Obat/BHP/IOL sekarang dirouter ke `/inventori-farmasi/*`.

## Ekspansi awal: 5 resource diperluas + RBAC per-modul

Per resource: **migration tambah kolom → update model `$fillable` + casts → controller validasi tambah field + fix `sometimes` di update → service filter baru + search wider → CSV schema diperluas → modul RBAC sendiri + middleware per-verb route**.

| Resource | Field baru | Filter baru | Modul RBAC |
|---|---|---|---|
| **Tindakan** (`procedures`) | `base_price`, `keterangan` (dulu category enum 3 nilai, **direvisi**) | `category` | `pengaturan.read` (parent) |
| **Obat** (`medications`) | `form_sediaan`, `golongan` (BPOM), `composition`, `manufacturer` | `form_sediaan`, `golongan`, `active`, `low_stock` | `master_obat.*` + sekarang `inventori_farmasi.*` |
| **BHP** (`bhp_items`) | `category`, `manufacturer`, `expiry_date`, `batch_number` | `category`, `active`, `low_stock` | `master_bhp.*` + `inventori_farmasi.*` |
| **IOL** (`iol_items`) | `manufacturer`, `cylinder`+`axis` (required_if TORIC), `expiry_date` + **partial unique** `serial_number WHERE NOT NULL AND deleted_at IS NULL` | `material`, `active`, `is_used`, `available_only`. Range `power` `-20..+40`. | `master_iol.*` + `inventori_farmasi.*` |
| **ICD-10** (`icd10_codes`) | `chapter`, `chapter_label`, `category`, `indonesian_description` | `category`, `eye_related`, `favorite` | `master_icd.*` (shared 10+9) |
| **ICD-9** (`icd9_codes`) | `category`, `indonesian_description` (no chapter) | `category`, `eye_related`, `favorite` | `master_icd.*` (shared) |

**Policy `code` immutable di update** untuk ICD-10/9: validasi update sengaja tidak menerima `code` (alasan: FK protection di RME/billing). Sama policy diterapkan ke `procedures.code` setelah revisi (lihat di bawah).

## Rev sesi 2026-05-26 malam — Procedures schema baru

**Procedures (Tarif Tindakan) di-rev besar**:

1. **`procedures.code` tidak lagi UNIQUE** — migration [drop_unique_from_procedures_code](backend/database/migrations/2026_05_26_000021_drop_unique_from_procedures_code.php) drop constraint, ganti dengan index biasa. Code sekarang **auto-generated** format `{PREFIX}-{NNN}` (mis. `TND-001`, `KSL-002`).
2. **Tabel baru `procedure_categories`** (migration [create_procedure_categories_table](backend/database/migrations/2026_05_26_000020_create_procedure_categories_table.php)) — kolom `name` (unique), `code_prefix` (unique, mis. TND/KSL), `description`, `is_active`. Model [ProcedureCategory.php](backend/app/Models/ProcedureCategory.php). Kategori = sumber prefix kode.
3. **Service `MasterDataService::generateProcedureCode($categoryName)`** — lookup prefix dari `procedure_categories`, find max suffix `MAX(NNN)` existing untuk prefix itu, return `{PREFIX}-{NNN+1}` 3-digit padded. Dipanggil otomatis di `storeTindakan()` kalau `data['code']` kosong.
4. **`storeTindakan`** sekarang accept `code: nullable` (auto-gen kalau kosong). **`updateTindakan`** strip `code` dari data (immutable).
5. **CRUD master kategori** via [MasterDataController](backend/app/Http/Controllers/MasterDataController.php): `indexProcedureCategories / storeProcedureCategory / updateProcedureCategory / deleteProcedureCategory` di route `/master/tindakan/kategori`. Guard:
   - `code_prefix` immutable kalau sudah dipakai procedure.
   - Delete blocked kalau ada procedure pakai prefix.
6. **`kategoriListTindakan`** dulu return distinct strings dari `procedures.category`, sekarang return array of `{id, name, code_prefix}` dari `procedure_categories`.

## Rev sesi 2026-05-26 malam — CSV Tindakan friendly (tanpa kode)

**Template/Export/Import Master Tarif Tindakan** di-special-case (lihat `MasterDataService::csvTemplate`, `exportResourceCsv`, `importResourceCsv` cabang `if ($type === 'tindakan')`):

- **Header**: `no, nama, kategori, harga, keterangan, status` (6 kolom, **TANPA kode**)
- **Method baru**: `tindakanCsvHeaderOnly()`, `exportTindakanCsv()`, `importTindakanCsv()` (private, terisolasi dari generic resourceSchema).
- **Import logic**:
  - Header wajib: `nama, kategori, harga` (case-insensitive). `no` di-ignore.
  - Lookup by **(nama, kategori) case-insensitive** ke `procedures`.
  - Existing → UPDATE harga/keterangan/status (code tidak berubah).
  - Tidak ada → CREATE + autogen code dari kategori (kategori wajib terdaftar di `procedure_categories`, kalau tidak → error per baris).
- **Export**: status di-export sebagai string "aktif"/"nonaktif" (lebih ramah admin).
- **Frontend** ([TarifTindakanView.vue](arumed-frontend/src/views/master-data/TarifTindakanView.vue)): tabel UI tetap menampilkan kolom Kode (sebagai identifier visual), tapi CSV tidak include kode. Form modal Tambah: kode disembunyikan, kategori = dropdown master dengan preview "Kode akan: TND-NNN". Form Edit: kode tampil read-only.
- **Modal "Kelola Kategori"** inline di TarifTindakanView (tombol di header) — CRUD master kategori dengan field code_prefix uppercase auto.

## Yang belum

- **Wilayah → DB**: kalau nanti `clinic_profiles` ditambah kolom `province_id`/`regency_id`/`district_id`, ubah `ProfilKlinikView` dari "append ke alamat" jadi save terpisah.
- **Resource sisa belum jadi modul terpisah**: Penjamin (Insurer) — sudah dipindah ke `/tarif-paket/metode-bayar` (lihat [[feature-tarif-paket-bedah]]). Jenis Dokumen, Template Dokumen, Stasiun Document Mapping, Konfigurasi Nomor Dokumen masih di MasterDataController belum di-UI-kan.
- **Audit Log tab** di DataPengguna masih placeholder.

## Pakai untuk extend resource baru

Pola A→F (proven 5×): **Migration → Model fillable+casts+konstanta enum → Controller validasi store/update (pakai `sometimes` di update untuk field core, jangan campur `nullable` dan `required` jadi inkonsisten) → Service indexXxx filter + search wider → CSV `resourceSchema()` + tambah ke `CSV_TYPES` di Controller → RBAC: tambah modul ke [PermissionSeeder.php](backend/database/seeders/PermissionSeeder.php) + pasang middleware per-verb route**.

Untuk frontend: kalau sudah ada `_CrudResourceView`, tinggal extend config (columns/fields/defaults) + opsional slot `#filters` untuk filter UI custom + slot `#cell-<key>` untuk render cell color-coded.
