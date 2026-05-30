---
name: feature-tarif-paket-bedah
description: Sub-modul standalone Tarif & Paket Bedah (/tarif-paket/*) — 3 section: Tarif Tindakan (master procedures + autogen kode TND-001 per kategori), Metode Bayar (penjamin + TPA parent/child inheritance + tarif tindakan per-insurer), Paket Bedah (komposisi + auto-diskon). Direstrukturisasi besar di sesi 2026-05-26 malam.
metadata:
  node_type: memory
  type: project
  originSessionId: f7fa0de3-9019-4de1-8fc4-e54c7f1a38b8
---

Sub-modul **Tarif & Paket Bedah** (`/tarif-paket/*`) — modul **standalone terpisah dari Master Data**. Struktur final (post-rev 2026-05-26 malam):

**3 section di [TarifPaketLayout.vue](arumed-frontend/src/views/tarif-paket/TarifPaketLayout.vue) sidebar**:
1. **Tarif Tindakan** → `/tarif-paket/tarif-tindakan` (reuse view [TarifTindakanView.vue](arumed-frontend/src/views/master-data/TarifTindakanView.vue) di folder master-data)
2. **Metode Bayar** → `/tarif-paket/metode-bayar` (list insurers) → klik baris → `/tarif-paket/metode-bayar/{id}` (detail + tarif tindakan per-insurer)
3. **Paket Bedah** → `/tarif-paket/paket-bedah` + `/tarif-paket/paket-bedah/{id}` (detail)

**Container `.tpl-wrap` max-width 1600px** (dari 1400px) — lebih lega untuk hero card + tabel besar.

**Why:** Tarif tindakan butuh master acuan utama (Tarif Tindakan) **plus** override harga per penjamin (Metode Bayar) **plus** komposisi paket bedah dengan auto-diskon. TPA pattern penting di Indonesia (Allianz/BNI Life via Admedika — child mewarisi tarif parent).

**How to apply:** Untuk fitur yang butuh tarif/insurer, panggil endpoint `/api/v1/tarif-paket/*`. **Jangan pakai `/master/tarif/*` lama** (sudah deprecated, route lama dihapus). Master kategori procedures via `/master/tindakan/kategori` (CRUD via modal di TarifTindakanView).

---

## Section 1 — Tarif Tindakan

**Master `procedures`** dengan code auto-generated per kategori. Lihat [[feature-master-data-stage1]] section "Rev sesi 2026-05-26 malam" untuk detail schema.

- Kode format: `{PREFIX}-{NNN}` (TND-001, KSL-001, dst). PREFIX dari tabel `procedure_categories.code_prefix`.
- Field modal: Nama, Kategori (dropdown master), Harga, Status, Keterangan. **Kode hidden saat create** (autogen di backend), **read-only saat edit**.
- **Modal "Kelola Kategori"** inline di toolbar — CRUD master kategori. Code prefix uppercase auto + immutable kalau sudah dipakai procedure.
- CSV header: `no, nama, kategori, harga, keterangan, status` (TANPA kode). Import: lookup by (nama, kategori) case-insensitive, upsert.

---

## Section 2 — Metode Bayar (insurers + TPA + tarif tindakan per-insurer)

**Data model `insurers`** ([Insurer.php](backend/app/Models/Insurer.php)):

- Kolom enum `type`: **`UMUM, BPJS, ASURANSI, PERUSAHAAN, SOSIAL`** (5 nilai, sebelum rev 3 nilai). UMUM/BPJS/SOSIAL adalah **insurer sistem** (`is_system=true`, ter-seed via [InsurerSystemSeeder](backend/database/seeders/InsurerSystemSeeder.php), immutable nama/tipe, tidak boleh dihapus).
- Kolom baru: `parent_id` (nullable FK ke `insurers.id`) untuk pola TPA + `is_system` (boolean) untuk row sistem. Migration [add_parent_id_and_system_to_insurers_table](backend/database/migrations/2026_05_26_000010_add_parent_id_and_system_to_insurers_table.php).
- Helper di model: `parent()` BelongsTo self, `children()` HasMany self, `isChildTpa()`, `tariffInsurerId()` = `parent_id ?? id` (lookup tarif via parent kalau child).

**TPA pattern — inheritance murni**:
- Insurer "Admedika" (parent_id=null) punya tarif sendiri.
- Insurer "Allianz via Admedika" (parent_id=Admedika.id) **TIDAK punya tarif sendiri** — semua read tarif resolve ke parent via `tariffInsurerId()`.
- Detail page child: **banner kuning** "Tarif bersumber dari {parent.name}", tabel read-only (tombol Tambah/Edit/Hapus/Import disabled, hanya Export aktif untuk download tarif parent).

**Tarif tables — `classification` di-drop**:
- 4 tabel: `procedure_tariffs`, `medication_tariffs`, `bhp_tariffs`, `iol_tariffs` semua **drop kolom `classification`** (migration [drop_classification_from_tariffs](backend/database/migrations/2026_05_26_000011_drop_classification_from_tariffs.php)).
- Unique constraint lama `(item_id, insurer_id, classification)` → baru `(item_id, insurer_id)`.
- Karena UMUM/BPJS/SOSIAL sekarang jadi insurer sistem (baris di `insurers`), classification field redundant.

**Tarif hanya untuk Tindakan**:
- Tab Obat/BHP/IOL **dihapus dari MetodeBayarDetailView**. Harga obat/BHP/IOL ambil langsung dari master masing-masing (medications.price, dst).
- Backend tarif untuk obat/bhp/iol masih ada (back-compat) tapi tidak diakses dari UI.

**Detail page structure** ([MetodeBayarDetailView.vue](arumed-frontend/src/views/tarif-paket/MetodeBayarDetailView.vue)):
- **Hero card kecil minimalis** di atas (padding 1rem 1.2rem, avatar 44px, title 20px, no gradient): avatar gradient hijau (inisial nama), nama insurer, badge SISTEM, tags (kode, type pill, status dot, parent pill, children pill), contact row (telp/email/alamat icons).
- **Banner child TPA** (kalau parent_id != null) — info read-only mode.
- **Kartu "Tarif Tindakan"** (besar, fokus halaman) — padding 1.8rem 2rem, border-radius 16px, shadow, heading 26px dengan border-bottom. Berisi MetodeBayarTarifTab type=tindakan.

**MetodeBayarTarifTab** ([MetodeBayarTarifTab.vue](arumed-frontend/src/views/tarif-paket/MetodeBayarTarifTab.vue)) — reusable komponen per-type:
- Kolom: **No, Kode, Nama Item, Kategori, Harga Master, Harga Jual, Status, Aksi** (8 kolom).
- "Harga Override" → **Harga Jual** (rename).
- TYPE_META map kategori per type: tindakan→category, obat→golongan, bhp→category, iol→iol_type.
- Modal Tambah: dropdown item dari masterApi → **watch** perubahan → auto-fill `price` dari `selected[masterPriceKey]` atau fallback API `GET /tarif-paket/master-price/{type}/{itemId}`. Admin bisa override.
- Toolbar CSV: Template, Import, Export — child TPA: Template/Import disabled, Export aktif (download tarif parent).

**Endpoints baru ([api.php](backend/routes/api.php))**:
- `GET /tarif-paket/metode-bayar/{id}` → detail insurer + parent + children + counts per item type
- `GET /tarif-paket/metode-bayar/{id}/tarif/{type}/template-csv`
- `GET /tarif-paket/metode-bayar/{id}/tarif/{type}/export-csv`
- `POST /tarif-paket/metode-bayar/{id}/tarif/{type}/import-csv` (reject 422 kalau insurer child TPA)
- `GET /tarif-paket/master-price/{type}/{itemId}` — helper harga master live

**CSV Metode Bayar** — header: `no, nama, kategori, harga_master, harga_jual` (5 kolom, **TANPA kode**). Import: lookup item by (nama, kategori) case-insensitive ke master, upsert tarif by (item_id, insurer_id). Existing → UPDATE harga_jual, baru → CREATE.

**Guard penjamin** (di `MasterDataService::storePenjamin/updatePenjamin/deletePenjamin`):
- Insurer sistem: block edit `name`/`type`/`code` (hanya allow address/phone/email/is_active).
- Insurer sistem: block delete (422).
- Insurer dengan children > 0: block delete (422 "Hapus child dulu").
- `parent_id` tidak boleh = id sendiri.
- `is_system` tidak bisa di-set via API (hanya seeder).

---

## Section 3 — Paket Bedah

**Tetap sama dengan implementasi awal** ([feature backup di bawah]):

- Migrations: `add_total_base_price_to_surgery_packages_table`, `create_surgery_package_items_table`, `create_surgery_package_tariffs_table`.
- Models: [SurgeryPackageItem.php](backend/app/Models/SurgeryPackageItem.php) (TYPES PROCEDURE/MEDICATION/BHP/IOL, polymorphic `resolveItem()`), [SurgeryPackageTariff.php](backend/app/Models/SurgeryPackageTariff.php) (helper `discountAmount()`/`discountPercent()`), [SurgeryPackage.php](backend/app/Models/SurgeryPackage.php) (`recalcTotalBasePrice()`).
- Service [TarifPaketService.php](backend/app/Services/TarifPaketService.php) — sekarang juga delegate `showMetodeBayar()`, `templateTarifCsv()`, `exportTarifCsvForInsurer()`, `importTarifCsvForInsurer()`, `getMasterPriceFor()` ke MasterDataService.
- **`surgery_package_tariffs` masih punya kolom `classification`** — beda scope dengan per-insurer tariff (di-drop). Tidak diubah.

**Auto-diskon paket**: `discount_amount = max(0, total_base_price − sell_price)`, `discount_percent = round(amount / base × 100, 2)`. Backend hitung, frontend tinggal render dari `listTariffs()`.

**CSV Import/Export Paket Bedah** (2026-05-29):
- Format **long** — header: `nama_paket, kategori, deskripsi, aktif, item_tipe, item_nama, qty, catatan` (1 baris = 1 item, paket multi-item → multi-baris berulang nama_paket).
- **`item_tipe` tetap 4 nilai**: `PROCEDURE / MEDICATION / BHP / IOL` (sama dengan `SurgeryPackageItem::TYPES`). Tidak ada perubahan dimensi.
- **Template CSV = header only** (tanpa contoh baris). User pilih 2026-05-29 — versi awal dengan 2 baris contoh dibuang.
- Tarif jual per penjamin **TIDAK** masuk CSV — tetap diatur manual di panel kanan detail page. Trade-off: lebih sederhana, hindari N×M baris (insurer × klasifikasi).
- Tombol di **PaketBedahListView** header: Template / Export CSV / Import CSV (bukan di detail page).
- Endpoint: `GET /tarif-paket/paket-bedah/template-csv`, `GET .../export-csv`, `POST .../import-csv` (route didaftar SEBELUM `/paket-bedah/{id}` agar tidak tertangkap).
- Konflik nama paket di import: **replace komposisi** — header paket diupdate (kategori/deskripsi/aktif), items lama dihapus & insert dari CSV. Tarif jual TIDAK disentuh (aman dari overwrite).
- Item lookup case-insensitive by `name` (PROCEDURE/MEDICATION/BHP) atau `"brand model powerD"` (IOL, split via regex `^(.*?)\s+([\d.]+)d\s*$`). Ambigu (≥2 match) → null → error baris.
- `default_price` auto dari master saat import (sama seperti `addItem` interaktif). Tidak ada kolom harga di CSV.
- Modal hasil import: 4 stat card (paket baru/update, item insert, item gagal lookup) + list error (max 50 ditampilkan).
- **Fix download bug 2026-05-29**: gejala "toast sukses tapi file tidak ke-download". Root cause: `URL.revokeObjectURL` dipanggil terlalu cepat (sinkron setelah `a.click()`) — beberapa browser belum sempat trigger save dialog. Fix di `triggerDownload()` [tarifPaketStore.js]: `setTimeout(() => { remove + revoke }, 100)` + Blob defensive cast (in case axios kasih string mentah, bukan Blob). Pola yang sama berlaku untuk semua endpoint CSV `responseType: 'blob'`.

---

## RBAC

- Modul `tarif_paket` (3 permission). Semua route `/tarif-paket/*` dilindungi `permission:tarif_paket.{read|write|delete}` per HTTP verb.
- Superadmin bypass otomatis.

## Endpoint deprecated (dihapus dari route)

- `GET/POST /tarif-paket/tarif/{type}/export-csv` & `import-csv` lama (global, tidak per-insurer) — diganti dengan endpoint per-insurer di section 2.
- Route lama `/tarif-paket/tarif/:type(tindakan|obat|bhp|iol)` dengan view `TarifPenjaminView.vue` — view file masih ada di repo tapi tidak ada route. Kandidat hapus.

## Decision points yang dipakai

- **Klasifikasi pasien (UMUM/BPJS/SOSIAL)** dulu enum di kolom `classification` di tarif tables. Sekarang: dijadikan **insurer sistem** (baris di `insurers` dengan `is_system=true`) supaya struktur tariff seragam (tarif = item × insurer, no extra dimension).
- **TPA inheritance murni** (child tidak punya tarif sendiri) — dipilih daripada "inheritance + override per-child" karena lebih simple. Override per-child bisa ditambah belakangan kalau perlu.
- **Code procedures** non-unique global, tapi unique per prefix (TND-001 unique karena kombinasi auto-counter). Format `{PREFIX}-{NNN}` aman untuk lookup CSV dan FK protection.
- **CSV tanpa kode**: untuk mengurangi friction admin (tidak perlu tahu kode internal saat upload bulk). Trade-off: nama duplikat dalam kategori sama = ambigu. Mitigation: lookup case-insensitive (nama, kategori) sebagai composite key — kalau ada konflik, baris pertama yang dipakai (silent overwrite via updateOrCreate).
