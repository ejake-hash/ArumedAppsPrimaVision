---
name: feature-inventori-harga
description: Sub-menu Penentuan Harga Obat & Alkes dengan metode HPP & HJA + PPN global di modul inventori-farmasi
metadata: 
  node_type: memory
  type: project
  originSessionId: 2a384ffa-6bbd-4437-b411-b7d8b003f6dd
---

Sub-menu **Penentuan Harga Obat & Alkes** di [[feature-inventori-farmasi]] dengan metode HPP & HJA.

**Why:** Master Obat/BHP/IOL hanya jadi data acuan (tanpa harga/stok). Harga jual ditentukan terpusat di modul ini, agar fleksibel diubah tanpa edit master. Stok per-batch ditangani modul Penerimaan/GRN (`inventory_stocks`, sudah ada — lihat [[feature-pembelian-penerimaan]]) + opname di sidebar Data Stock ([[feature-unit-request-retur]]).

**How to apply:** Saat resep/billing butuh harga jual obat/bhp/iol, ambil dari tabel `inventory_prices.hja` (bukan dari kolom legacy `price` di tabel master). Untuk obat/bhp/iol yang belum di-set harga, sistem harus handle status "belum diset" (jangan default ke 0).

## Skema
- Tabel `inventory_prices` (1 row per item, unique `item_type` + `item_id`): hpp, margin_percent, ppn_enabled, hja (stored snapshot), notes, effective_date, updated_by. `item_type` enum: MEDICATION/BHP/IOL.
- Tabel singleton `inventory_price_settings`: ppn_rate (default 11). Migration seed 1 row otomatis.

## Formula
`HJA = HPP × (1 + margin%) × (ppn_enabled ? 1 + ppn_rate% : 1)`
Saat ppn_rate diubah, service auto-recompute HJA semua row.

## Endpoint
- `GET /inventori-farmasi/harga/settings` — get ppn_rate
- `PUT /inventori-farmasi/harga/settings` — set ppn_rate (trigger recompute semua)
- `GET /inventori-farmasi/harga/{type}` — list item (LEFT JOIN, item belum di-price tetap muncul)
- `PUT /inventori-farmasi/harga/{type}/{itemId}` — upsert harga
- `DELETE /inventori-farmasi/harga/{type}/{itemId}` — hapus harga (item balik ke "belum diset")
- **CSV (2026-05-29, daftar SEBELUM route generic `/{type}`):** `GET /{type}/template-csv`, `GET /{type}/export-csv`, `POST /{type}/import-csv`.

Permission: `inventori_farmasi.read` / `inventori_farmasi.write`.

## CSV Import/Export (per tab/type)
Kolom: `kode, nama, hpp, margin_persen, ppn, hja`. **RULE: item dengan `kode` yang tidak ada di master DILEWATI + dicatat di errors[]** (harga hanya untuk item master). Lookup key: MEDICATION/BHP via `code`, **IOL via `serial_number`** (IOL tidak punya code/name). `nama` & `hja` di-ignore saat import (hja auto-compute pakai ppn_rate global). `ppn` parse 1/ya/true. Export hanya item yg sudah ber-harga (price_id != null). Audit pakai `SystemLog` (kolom: action/model/model_id/description) — BUKAN ActivityLog (tidak ada). Service: `InventoryPriceService::templateCsv/exportCsv/importCsv`. Frontend: toolbar CSV per-tab di PenentuanHargaView (`inventoriHargaApi.templateCsv/exportCsv/importCsv`, blob download + result panel inserted/updated/skipped/errors).

## Frontend
View: `arumed-frontend/src/views/inventori-farmasi/PenentuanHargaView.vue`. Route: `/inventori-farmasi/harga`. UX: 3 tab (Obat/BHP/IOL), inline-edit HPP/Margin/PPN per baris dengan preview HJA real-time, save per baris. Card header untuk edit PPN global.
