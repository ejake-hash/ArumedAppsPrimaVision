---
name: feature-stok-per-lokasi
description: "inventory_stocks dapat dimensi LOKASI (INVENTORI/BEDAH/FARMASI). Kirim request unit = TRANSFER gudang→unit, dispensing/bedah konsumsi STRICT stok lokasi unit. Memperbaiki bug stok 'hilang' saat barang diterima unit."
metadata: 
  node_type: memory
  type: project
  originSessionId: 9d9c6e85-b40e-4b10-974d-0f39effcb618
---

**Stok per-lokasi** ditambahkan 2026-05-29 di branch `fix/pre-golive-500-bugs`. Memperbaiki bug yang dilaporkan user: saat barang dikirim gudang→unit lewat Request Unit, stok seolah HILANG. Akar masalah: `inventory_stocks` tak punya lokasi; deliver mengurangi inventory_stocks (gudang) tapi `close()` lama menambah ke kolom LEGACY `medications.stock`/`bhp_items.stock` (`increment('stock')`) yang TIDAK lagi dibaca dispensing (BUG#7 fix konsumsi inventory_stocks). Lihat [[project-pre-golive-bug-audit]], [[feature-unit-request-retur]], [[feature-inventori-farmasi]].

## Keputusan desain (dikonfirmasi user)
- **3 lokasi** (kolom string `inventory_stocks.location`): `INVENTORI` (gudang induk, default), `BEDAH`, `FARMASI`. Konstanta `InventoryStock::LOC_*` + `InventoryStock::LOCATIONS`. TANPA tabel master lokasi.
- **Data existing → `INVENTORI`** (migration `2026_06_01_000001_add_location_to_inventory_stocks`: kolom default INVENTORI, unique lama `(item_type,item_id,batch_no)` di-DROP, ganti `(location,item_type,item_id,batch_no)` — batch sama boleh di >1 lokasi).
- **Konsumsi STRICT**: unit HANYA pakai stok lokasinya sendiri. Kurang → abort 422 "Stok unit {LOKASI} tidak mencukupi … minta transfer dari gudang dulu". TIDAK ada fallback ke gudang.

## Aliran stok (sumber kebenaran tunggal = inventory_stocks per lokasi)
- **GRN (penerimaan supplier)** → masuk `INVENTORI` ([GoodsReceiptService] applyStock/reverseStock pakai location INVENTORI).
- **Request Unit `deliver()`** → `InventoryStockService::transfer($type,$id,$qty,'INVENTORI',$requesting_station)`: FEFO-deduct gudang + upsert ke lokasi unit (jaga batch_no+expiry per batch). Gudang turun & unit naik dalam 1 transaksi. Guard: `requesting_station` wajib ∈ LOCATIONS, else 422. IOL serialized → skip transfer.
- **`close()`** sekarang MURNI transisi DELIVERED→CLOSED (stok sudah pindah saat deliver). Method lama `consumeStock()`/`addUnitStock()` di UnitRequestService DIHAPUS.
- **Dispensing obat** ([FarmasiService::selesaiDispensing]) konsumsi lokasi `FARMASI`. **BHP bedah** ([FarmasiService::kirimSurgeryRequest]) konsumsi lokasi `BEDAH`. Keduanya `onHand`+`consume` dengan arg lokasi.
- **Baca (display) lokasi FARMASI**: e-resep dokter ([DokterService::getDaftarObat]) menampilkan kolom stok = SUM on-hand lokasi FARMASI via `leftJoinSub` (bukan `medications.stock` legacy). Cuma info, dokter tetap bisa resep walau 0. Lihat [[dokterview-module]].
- **Retur unit** ([UnitReturnService::receive]) → `removeUnitStock` = `consume()` FEFO di `returning_station` (strict), `returnStock` upsert balik ke `INVENTORI`. UnitReturnService kini inject InventoryStockService.

## InventoryStockService — API
Semua method punya param `$location` DEFAULT `INVENTORI` (caller lama aman): `onHand/consume/opname/applyDelta/exportCsv/importCsv`. Opname terima `$data['location']`. Method BARU `transfer(type,id,qty,from,to)` (return batch yg dipindah). UnitRequestService & UnitReturnService inject service ini via constructor.

## Frontend
- `GET /inventori-farmasi/stock/{type}?location=` (default INVENTORI). Endpoint stock di [UnitRequestController::stock] + CSV/opname di [InventoryStockController] terima `location` (tolak tak dikenal → INVENTORI).
- [InventoriStockSidebar.vue] tambah segmented selector lokasi (Gudang/Farmasi/Bedah) di header → semua aksi (list/opname/export/import CSV) ikut lokasi terpilih. `inventoriStockApi.list/exportCsv/importCsv/opname` thread `location`.

## Verifikasi (2026-05-29, semua hijau)
Migrate OK (14 baris existing → INVENTORI). E2E (rollback): transfer 10 INVENTORI 5500→5490 / FARMASI 0→10; consume 4 FARMASI→6, INVENTORI unchanged; consume berlebih → 422 strict. `composer smoke` 35/35. Frontend build hijau. **STATUS: kode selesai & terverifikasi tapi BELUM di-commit** (semua perubahan masih di working tree branch `fix/pre-golive-500-bugs`).

## Konsekuensi operasional (penting buat onboarding klinik)
Karena STRICT: petugas Farmasi/Bedah WAJIB request+terima stok ke unitnya dulu sebelum bisa dispensing/pakai BHP. Kalau lupa transfer → 422 (bukan gagal diam-diam, pesan jelas). Stok awal semua di INVENTORI, jadi setelah deploy unit mulai dari 0 sampai ada deliver pertama.

## Leftover audit DITUTUP 2026-05-30 (FarmasiView Manajemen Stok/Opname → per-lokasi FARMASI)
Sisa yang ditandai di [[project-pre-golive-bug-audit]] (#7 SISA) SELESAI: endpoint manual stok Farmasi tak lagi sentuh kolom legacy.
- `FarmasiService::getStokObat/getStokBhp/getStokAlert` kini overlay stok dari `inventory_stocks` lokasi FARMASI via helper baru `withFarmasiOnHand($query,$itemType)` (leftJoinSub SUM(qty_on_hand) → alias `farmasi_qty`, `$m->stock` di-overwrite). Filter `alert` pakai `whereRaw('COALESCE(farmasi_stock.qty,0) <= min_stock')`. **GOTCHA: inventory_stocks TIDAK SoftDeletes — JANGAN `whereNull('deleted_at')` (kolom tak ada → 500, kelas bug #4/#5).**
- `updateStokObat/updateStokBhp` kini: min_stock/price tetap ke master, tapi `stock` → `stockService->opname(location:FARMASI, new_qty:set-total)`. Kolom legacy `stock` TIDAK ditulis lagi. FarmasiView tab Manajemen Stok + Opname otomatis benar (baca `s.stock` = FARMASI on-hand) + ditambah banner `.loc-note` "stok unit Farmasi, bukan gudang".
- `FarmasiService::updateStock()` (generic set/inc/dec legacy) DIHAPUS (dead code, tak ada caller).
- Verified: E2E tinker (GET report FARMASI on-hand bukan legacy; SET opname update inventory & legacy untouched), `composer smoke` 35/35, frontend build hijau.

## Out of scope / catatan
- Kolom legacy `medications.stock`/`bhp_items.stock` tetap ADA tapi **tak ada lagi yang menulisnya** dari alur stok (dispensing/transfer/opname semua via inventory_stocks). Seeder master masih set `'stock'=>0` saat create (legacy, abaikan).
- Controller `updateStokObat` masih validasi `expiry_date`/`batch_number` tapi service ABAIKAN (per-batch ditangani FEFO/inventori sidebar). Tidak crash, cuma no-op.
- Belum ada UI transfer manual antar lokasi (transfer hanya via deliver Request Unit). Belum multi-gudang.
