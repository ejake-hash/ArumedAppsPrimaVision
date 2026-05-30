---
name: feature-pembelian-penerimaan
description: "Modul Pembelian (PO) & Penerimaan Barang (GRN) di Inventori Farmasi. 3 sub-menu baru (Supplier/Pembelian/Penerimaan), 6 tabel baru, stok per-batch dengan FEFO-ready, status PO auto dari GRN."
metadata: 
  node_type: memory
  type: project
  originSessionId: f77f4723-ed5b-4d85-aede-03c1da85341f
---

Modul **Pembelian & Penerimaan** di `/inventori-farmasi/{supplier,pembelian,penerimaan}` — dibuat 2026-05-26. Sub-menu di dalam [[feature-inventori-farmasi]]. Tidak terhubung ke HPP/HJA — sesuai pilihan user, HPP tetap manual di [[feature-inventori-harga]].

**Why:** Klinik perlu workflow pembelian formal (PO → terima barang) untuk audit, dan tracking stok per batch+expiry untuk obat/BHP medis. Stok IOL juga ikut karena IOL punya serial number per item.

**How to apply:** Saat butuh ambil stok untuk dispensing/billing, query `inventory_stocks` (per item+batch). Untuk update HPP berbasis weighted-average di masa depan, hook ke `GoodsReceiptService::createItemAndApplyStock()`.

## Tabel (6 baru)

| Tabel | Isi |
|---|---|
| `suppliers` | code (auto `SUP-NNN`), name, contact, phone, email, npwp, address, is_active, soft_delete |
| `purchase_orders` | po_number (auto `PO-YYYYMM-NNNN`), supplier_id, po_date, expected_date, status enum, total_amount, soft_delete |
| `purchase_order_items` | po_id, item_type (MEDICATION/BHP/IOL), item_id, qty_ordered, qty_received (running), unit_price, subtotal |
| `goods_receipts` | grn_number (auto `GRN-YYYYMM-NNNN`), po_id (nullable — direct receipt), supplier_id, receipt_date, invoice_number, soft_delete |
| `goods_receipt_items` | grn_id, po_item_id (nullable), item_type, item_id, qty_received, **batch_no, expiry_date**, unit_price, subtotal |
| `inventory_stocks` | item_type, item_id, batch_no, expiry_date, qty_on_hand, last_received_at. **Unique** `(item_type, item_id, batch_no)` — satu row per batch. |

PO status enum: `DRAFT, SENT, PARTIAL, RECEIVED, CANCELED`. DRAFT/SENT manual; PARTIAL/RECEIVED auto dari GRN; CANCELED via endpoint.

## RBAC

3 modul permission baru di [PermissionSeeder](backend/database/seeders/PermissionSeeder.php): `supplier`, `pembelian`, `penerimaan` (R/W/D each). Role `farmasi` dapat full di ketiganya. Total seeder sekarang **23 modul × 3 = 69 permission**.

## Pola arsitektur kunci

- **PO Service** ([PurchaseOrderService](backend/app/Services/PurchaseOrderService.php)): syncItems pakai delete-all-then-recreate (sederhana, aman karena items dilock kalau sudah ada GRN). Update items ditolak kalau `goodsReceipts()->exists()`. `generatePoNumber()` per bulan (`po_date->format('Ym')` sebagai prefix).
- **GRN Service** ([GoodsReceiptService](backend/app/Services/GoodsReceiptService.php)): satu-satu poin entry stok. `createItemAndApplyStock()` upsert `inventory_stocks` per `(item_type, item_id, batch_no)`, increment qty_on_hand. **Delete GRN reverse stok** — kalau qty habis row stock dihapus. Status PO auto-update via `updatePoStatus()` (PARTIAL kalau ada qty_received > 0, RECEIVED kalau semua qty_ordered terpenuhi, kembali ke SENT kalau semua GRN-nya dihapus).
- **Validasi over-receive**: `validateAgainstPo()` di create GRN — qty per baris ≤ `qty_ordered - qty_received` PO item. Error 422 dengan pesan baris spesifik.
- **Direct receipt** (no PO): pass `po_id = null` saat create GRN. Item tidak link ke PO item.

## Frontend

3 view standalone, **tidak pakai `_CrudResourceView`** (terlalu kompleks untuk dynamic line items):

- [SupplierView.vue](arumed-frontend/src/views/inventori-farmasi/SupplierView.vue) — pakai `_CrudResourceView` generic (form sederhana).
- [PembelianView.vue](arumed-frontend/src/views/inventori-farmasi/PembelianView.vue) — list + modal kompleks (header + dynamic line items + nested item picker dengan tab Obat/BHP/IOL live search).
- [PenerimaanView.vue](arumed-frontend/src/views/inventori-farmasi/PenerimaanView.vue) — list + **2 mode modal**: "Dari PO" (auto-fill items dengan qty_remaining + checkbox per baris) atau "Direct" (manual item picker). Setiap baris wajib `expiry_date > today`.

Layout sidebar dipisah 3 section: **Master Item** (Obat/BHP/IOL), **Harga** (Penentuan Harga), **Pengadaan** (Supplier/Pembelian/Penerimaan). Per-tab di-filter via `auth.can(perm)`.

## Endpoint summary

| Path | Method | Permission |
|---|---|---|
| `/inventori-farmasi/supplier` | GET/POST/PUT/DELETE | `supplier.{R/W/D}` |
| `/inventori-farmasi/pembelian` | GET/POST + `{id}` GET/PUT/DELETE + `{id}/cancel` POST | `pembelian.{R/W/D}` |
| `/inventori-farmasi/penerimaan` | GET/POST + `{id}` GET/DELETE + `/from-po/{poId}` GET | `penerimaan.{R/W/D}` |

**Note `/from-po/{poId}` HARUS didaftar SEBELUM `/{id}`** di routes/api.php (Laravel route order matters).

## Cetak PO + tanda tangan (2026-05-29)

PO Inventori Farmasi = **dokumen permohonan** (petugas farmasi = pemohon; yang menyetujui = Direktur dengan **TTD basah**). Cetak A4 sudah ada di [PembelianView.vue](arumed-frontend/src/views/inventori-farmasi/PembelianView.vue) — Teleport `#po-print-root` + `window.print()`, kop klinik + logo (load-guard sebelum print) + supplier + tabel item + total.

**Blok tanda tangan 2 kolom** (`.pp-sign`):
- **Kiri "Pemohon"** — e-sign RINGAN (tanpa canvas/library/tabel): badge dashed hijau "✓ Ditandatangani secara elektronik" + meta `formatDateTime(created_at) · po_number` + nama `auth.employeeName` + label "Petugas Farmasi". Datanya sudah ada semua (created_by terisi `auth('api')->id()` saat create, show() return `created_at`).
- **Kanan "Menyetujui, Direktur"** — ruang TTD basah KOSONG (`.pp-sign-space` 64px, disamakan tinggi dgn blok e-sign biar garis nama sejajar) + `clinic.director_name`+`director_sip` dari Profil Klinik.

User pilih e-sign teks (BUKAN TTD digital canvas) karena pemohon cuma butuh "kesan ditandatangani" yang ringan. Gotcha: kalau printPurchaseOrder dipanggil dari modal tanpa `created_at`, tanggal tampil '—' (formatDateTime null-safe, tidak error).

## Yang belum

- **HPP weighted-average otomatis** dari penerimaan — by-design tidak dibuat (user pilih HPP manual). Hook tinggal tambah di `GoodsReceiptService::createItemAndApplyStock()` kalau berubah pikiran.
- **FEFO dispensing**: stok per batch sudah ready, tapi modul Farmasi belum baca dari `inventory_stocks` saat dispensing. Saat dispensing perlu pick batch dengan `expiry_date` terdekat (FEFO = First Expired First Out).
- **Alert near-expiry**: query `inventory_stocks WHERE expiry_date < NOW() + 90 days` untuk dashboard warning.
- **Retur PO/GRN**: tidak ada flow retur — kalau salah terima harus delete GRN lalu buat ulang.
- **CSV import/export**: belum ada untuk PO/GRN (volume rendah, jarang dibutuhkan).
