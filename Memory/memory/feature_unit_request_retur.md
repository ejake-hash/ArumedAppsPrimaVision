---
name: feature-unit-request-retur
description: "Alur Request/Retur barang unit→gudang inventori farmasi. Tab Status (APPROVED/DELIVERED) terpisah dari Histori, batch/expiry auto-FEFO tanpa input admin, generator nomor pakai MAX+1 raw + retry 23505, default landing /inventori-farmasi → request-unit, kartu Data Stock di sidebar kanan."
metadata: 
  node_type: memory
  type: project
  originSessionId: 78c6333f-1bc2-4e17-8da8-773eeb228d86
---

Modul **Request & Retur Unit** menghubungkan unit klinik (Admisi/Triase/Bedah/Farmasi/dll) ke gudang inventori farmasi. Sisi unit ada di [RequestObatModal.vue](arumed-frontend/src/components/farmasi/RequestObatModal.vue) + [ReturObatModal.vue](arumed-frontend/src/components/farmasi/ReturObatModal.vue) (saat ini wired ke FarmasiView). Sisi admin gudang ada di [RequestUnitView.vue](arumed-frontend/src/views/inventori-farmasi/RequestUnitView.vue). Lihat [[feature-inventori-farmasi]] untuk konteks modul induk, dan [[feature-request-unit-notif]] untuk notifikasi bell + polling fallback di sisi Farmasi.

## State machine

Request: `DRAFT → SUBMITTED → APPROVED → DELIVERED → CLOSED` (atau `REJECTED`). Retur: `DRAFT → SUBMITTED → RECEIVED` (atau `REJECTED`).

**Label UI "CLOSED" = "Diterima"** (bukan "Selesai" / "Ditutup") — by user request. Saat unit klik **Terima** di tab Status, frontend panggil `close()` → status pindah ke CLOSED → row muncul di Histori dengan label "Diterima".

**PENTING (revisi 2026-05-29 [[feature-stok-per-lokasi]]):** stok unit BUKAN lagi ditambah saat `close()`. Sejak stok per-lokasi, mutasi stok terjadi saat **`deliver()`** = `InventoryStockService::transfer(INVENTORI → requesting_station)`. `close()` kini murni transisi DELIVERED→CLOSED. Method lama `consumeStock()`/`addUnitStock()` (yang dulu nulis kolom legacy `stock`) SUDAH DIHAPUS. Retur: `removeUnitStock` kini `consume()` FEFO di lokasi unit (strict), bukan decrement kolom legacy.

## UX sisi unit (RequestObatModal)

3 tab: **Buat Permintaan / Status / Histori**.

- **Tab Status** = filter `APPROVED + DELIVERED` (siap diterima). Punya badge angka (`.ro-tabcount`) untuk jumlah item siap proses.
- `APPROVED` = info-only ("menunggu kirim"). `DELIVERED` = tombol **Terima**.
- Tab Histori = read-only untuk semua status (tombol Terima dihilangkan dari sini — pindah ke tab Status).
- Tabel histori punya kolom **Barang** (item_name × qty + unit) setelah No. Permintaan/Retur — backend `index()` di kedua service eager-load `items` + transform paginator via `toArray()` supaya frontend tidak perlu N+1 show().

**Why:** User minta pemisahan eksplisit antara "yang perlu aksi sekarang" vs "riwayat lengkap" supaya tidak salah klik & status mudah dipantau.

## Default landing & kartu Data Stock

- Default `/inventori-farmasi` redirect ke **`/inventori-farmasi/request-unit`** (sebelumnya `/obat`). Section "Operasional" juga dipindah ke atas di `allTabs` [InventoriFarmasiLayout.vue](arumed-frontend/src/views/inventori-farmasi/InventoriFarmasiLayout.vue).
- **Rev 2026-05-28**: Data Stock dipindah KELUAR dari card konten RequestUnitView ke kolom ke-3 di `if-grid` (`220px nav | 1fr content | 340px stock`). Layout aktif via `showStockSidebar = route.path.startsWith('/inventori-farmasi/request-unit')`. Wrap di-expand jadi max 1760px via `:has(.if-grid.has-stock)`. Komponen baru [InventoriStockSidebar.vue](arumed-frontend/src/components/inventori-farmasi/InventoriStockSidebar.vue) (self-contained: fetch + state + UI). Collapse: hilang di ≤1200px, full-stack di ≤900px.
- Kartu **Data Stock**: 3 tab (Obat/BHP/IOL), search debounced 300ms, list scrollable max-height `calc(100vh - 90px)`, warna highlight expiry per batch (merah expired, oranye ≤30 hari, kuning ≤90 hari).
- Endpoint baru `GET /inventori-farmasi/stock/{type}` (type=MEDICATION/BHP/IOL) → `UnitRequestController::stock`. Sum `qty_on_hand` per master item dari `inventory_stocks`, plus daftar batch FEFO-sorted. API client: `inventoriStockApi.list(type, { search })`.
- **`showStockSidebar` sekarang `route.path.startsWith('/inventori-farmasi')`** (semua sub-route, bukan cuma request-unit) — sidebar tampil di seluruh modul.
- **Paginasi: SENGAJA TIDAK ADA (keputusan sadar 2026-05-30).** `refreshStock` ambil SEMUA baris (`inventoriStockApi.list` tanpa `page`/`per_page`), render penuh `v-for` (1 blok/item + 1 blok/batch). Aman untuk skala klinik oftalmologi (puluhan–ratusan SKU): layout tak rusak karena `overflow-y:auto`+`max-height:calc(100vh-90px)`, dan search server-side+debounce 300ms tetap cepat. Yang dikorbankan HANYA kehalusan render kalau data tembus ~500+ tanpa filter (jank/memori). JANGAN anggap ini bug — kalau nanti perlu, tambah infinite-scroll/“Muat lebih banyak” (perlu ubah backend `UnitRequestController::stock` agar paginasi) atau virtual scroll (tinggi baris bervariasi karena jumlah batch beda); search & opname tak terdampak.

## Stock Opname & CSV Stok (verified 2026-05-29, MEMORY SEBELUMNYA SALAH bilang sidebar read-only)

Sidebar Data Stock BUKAN read-only — punya **opname** (gate `inventori_farmasi.write`, IOL di-skip karena serialized):
- Tombol ✎ per item → modal opname: tabel batch (Sistem vs Fisik editable) + tambah batch baru + footer delta (+/−). [InventoriStockSidebar.vue](arumed-frontend/src/components/inventori-farmasi/InventoriStockSidebar.vue).
- Service [InventoryStockService::opname](backend/app/Services/InventoryStockService.php): 2 mode — `batches[]` (override qty per `stock_id`, atau batch baru) ATAU `new_qty` (set-total, dipakai sidebar & CSV). `applyDelta`: delta+ → batch `OPNAME-{Ymd}`, delta− → **FEFO deduct** (abort 422 kalau stok existing kurang). Endpoint `POST /inventori-farmasi/stock/opname`. Audit `OPNAME_STOCK` di SystemLog (before/after/touched/reason).
- **CSV stok** (Obat & BHP saja; IOL skip) — toolbar ⇅ collapsible di sidebar. Kolom `code,name,qty`; import = opname via `new_qty` (lookup tier: code → LOWER(name), error kalau nama ambigu >1 match). `InventoryStockService::csvTemplate/exportCsv/importCsv`, endpoint `inventori-farmasi/stock/{type}/{template,export,import}-csv`. Result: `{applied, skipped, errors[]}`.

## Sisi ADMIN RequestUnitView — 4 tab (verified 2026-05-29, lebih dari catatan lama)

[RequestUnitView.vue](arumed-frontend/src/views/inventori-farmasi/RequestUnitView.vue) sisi admin gudang punya **4 tab**: Permintaan (default filter SUBMITTED) · **Pending Pengiriman** (filter APPROVED, belum DELIVERED) · Retur (SUBMITTED) · **History** (2 sub-tab: Request [DELIVERED/CLOSED/REJECTED] + Retur [RECEIVED/REJECTED]). Semua tabel ada kolom No. Aksi: approve/reject/deliver(modal qty)/close untuk request; receive/reject untuk retur. Listener `arumed:inventori-inbox-open` dari bell layout auto-switch ke tab Permintaan/Retur. `removeUnitStock` saat receive retur clamp ≥0.

## Batch & expiry: FEFO otomatis, jangan input manual

User minta **modal Kirim dan Detail Permintaan TIDAK menampilkan input batch/expiry**. Backend wajib pilih FEFO otomatis dari `inventory_stocks`.

- Frontend payload `deliver()` hanya kirim `{ id, qty_delivered }` — tanpa `batch_no`/`expiry_date`.
- Backend `UnitRequestService::consumeStock()` **return array** `['batch_no', 'expiry_date']` berisi batch pertama yang dikonsumsi FEFO. `deliver()` menyimpan info ini ke `unit_request_items` supaya audit trail tetap ada walau admin tidak input manual.
- Validasi `items.*.batch_no` & `items.*.expiry_date` di controller dibiarkan `nullable` (back-compat — kalau ada caller lain yang masih kirim).

**How to apply:** Untuk fitur baru yang konsumsi `inventory_stocks`, contoh signature `consumeStock` yang return info batch ada di [UnitRequestService.php](backend/app/Services/UnitRequestService.php). Jangan tampilkan input batch di UI deliver — biarkan FEFO yang tentukan.

## Generator nomor REQ/RET: MAX+1 raw + retry 23505

Bug 2026-05-28: generator string-sort `orderByDesc('request_number')` + `withTrashed()` bisa **menabrak unique constraint** `request_number` karena soft-deleted/format jump tidak terdeteksi. Solusi sekarang:

```php
$next = (int) DB::table('unit_requests')
    ->where('request_number', 'like', $prefix . '%')
    ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(request_number FROM '\d+$') AS INTEGER)), 0) + 1 AS n")
    ->value('n');
return $prefix . sprintf('%04d', $next + $bump);
```

Dan `create()` dibungkus retry: kalau `QueryException::getCode() === '23505'`, increment `$attempts` (param `$bump` di `generateNumber`) dan ulang transaksi sampai 8x. Pattern ini ada di [UnitRequestService.php](backend/app/Services/UnitRequestService.php) & [UnitReturnService.php](backend/app/Services/UnitReturnService.php).

**Why:** Race antar dua user submit bersamaan di bulan yang sama bisa generate nomor identik. `withTrashed()` + string-sort tidak menyelesaikan karena PostgreSQL bisa skip row tertentu. Raw MAX dengan regex extract digit + retry adalah pola yang andal.

**How to apply:** Untuk generator nomor sekuensial bulanan baru (PO, GRN, dll), tiru pattern ini — jangan pakai string-order + withTrashed.
