---
name: billing-items-expansion
description: "Ekspansi item billing 2026-05-29 — BHP loop fix di consolidateBilling, 4 kategori BHP tertutup (CSSD/INSTRUMENT_SET/MEDICAL_SUPPLIES/MEDICAL_BHP), modul Medical Equipment baru dengan flat usage fee per insurer"
metadata: 
  node_type: memory
  type: project
  originSessionId: 598250db-3b86-4c6a-acc5-18a79c992f0a
---

Ekspansi rincian biaya pasien per 2026-05-29 — sebelumnya `billing_items` hanya REGISTRASI/TINDAKAN/OBAT/IOL aktif; BHP sudah ada schema lengkap (tabel + tarif + request flow) tapi **loop billing-nya hilang** di `KasirService::consolidateBilling()`, jadi BHP yang dipakai operasi tidak pernah masuk tagihan.

**Why:** klinik perlu rincian biaya operasi lengkap — Obat, BHP, IOL, Set Instrumen, dan Alat Medis (microscope/Phaco) — sesuai realita penagihan. Pasien BPJS sudah include INA-CBGs (jadi alat-alat ini tidak ditagih), tapi Umum/Asuransi/Perusahaan tetap perlu detail per item.

**How to apply:** kalau ada pertanyaan "kenapa BHP nggak masuk tagihan" / "bagaimana tagih microscope" / "kategori INSTRUMENT_SET buat apa" — rujuk ke fitur ini. Kalau perlu tambah jenis item billing baru, pertimbangkan dulu apakah cukup reuse kategori BHP atau perlu entity terpisah seperti Medical Equipment.

## 3 Fase yang diimplementasikan

### Fase 1 — BHP billing loop + 4 kategori tertutup
- **Migration baru**: `2026_05_29_000010_add_used_qty_to_surgery_request_bhp_table.php` — kolom `used_qty` (int, nullable) di `surgery_request_bhp`, default null. Backfill UPDATE: row dgn `surgery_requests.status='RECEIVED'` di-set `used_qty = quantity`.
- **Semantic**: `quantity` = qty yg diminta bedah ke farmasi; `used_qty` = qty actual yg terpakai (bisa < atau > quantity karena bedah adjust di lapangan).
- **BhpItem konstanta** ([`backend/app/Models/BhpItem.php`](backend/app/Models/BhpItem.php)): `CATEGORY_MEDICAL_BHP` / `CSSD` / `INSTRUMENT_SET` / `MEDICAL_SUPPLIES` + array `CATEGORIES`. Validator `MasterDataController::storeBhp/updateBhp` jadi `in:` enum tertutup (tetap nullable utk backward-compat data lama).
- **KasirService loop baru** ([`backend/app/Services/KasirService.php`](backend/app/Services/KasirService.php) line ~178-208): iterasi `visit.surgeryRequests` filter `status='RECEIVED'`, lalu `bhpItems` filter `used_qty > 0`. Reuse `getPrice('bhp', ...)` yang sudah ada. Description format: `"NamaBHP [KATEGORI]"`. item_type tetap `'BHP'` (pembedaan kategori via description + lookup ke bhp_items).
- **BedahService**: `terimaRequest()` di-wrap transaction + seed `used_qty = quantity` saat status berubah RECEIVED. Method baru `adjustBhpUsage($requestId, $items)` — bedah edit qty actual via endpoint `POST /bedah/request/{id}/adjust-bhp` (permission default bedah, status SENT/RECEIVED).
- **Frontend BhpView**: dropdown kategori tertutup (4 opsi) bukan free-text, filter di toolbar jadi `<select>`, pill warna per kategori (CSSD kuning, INSTRUMENT_SET ungu, MEDICAL_SUPPLIES biru, MEDICAL_BHP hijau). Default value `MEDICAL_BHP`.
- **Frontend BedahView**: section baru "**Pemakaian BHP (dari Farmasi)**" muncul kalau `selP.visitId` punya `surgery_requests` RECEIVED. Auto-load via watcher `selP`. Tabel: item, kategori (pill), qty diminta, qty terpakai (editable input). Tombol Simpan per request → call `bedahApi.adjustBhpUsage`.
- **Frontend KasirView**: pill warna baru utk `kat-bhp` / `kat-iol` / `kat-obat` / `kat-medical_equipment` / `kat-registrasi` / `kat-lainnya`. `itemTypes` array di-update.

### Fase 2 — Set Instrumen via kategori BHP (NO-OP, sudah ter-handle Fase 1)
- **Keputusan**: tidak buat entity terpisah (tidak ada `surgical_instrument_sets` table, no item_type baru, no separate billing loop). Set Bedah Katarak Standard / dll **didaftar sebagai BhpItem dengan `category='INSTRUMENT_SET'`** — tarif lewat `bhp_tariffs`, request via flow `surgery_request_bhp` yang sama, billing via loop BHP yang sama.
- **Reason**: scope klinik kecil, internal tracking per-instrumen di luar sistem (mis. paper checklist CSSD). Compliance-friendly: tagihan pasien tetap flat per-set, tidak unbundling.
- **Decision skip komposisi**: user pilih "Skip komposisi sekarang, hanya tag category INSTRUMENT_SET cukup". Kalau klinik tumbuh & butuh CSSD tracking detail, tambah tabel `bhp_item_compositions` belakangan.

### Fase 3 — Medical Equipment (microscope, Phaco, biometri) flat usage fee
- **3 migration baru**:
  - `2026_05_29_000020_create_medical_equipments_table.php` — master aset (code/name/category/brand/model/serial_number/location/status/calibration_due_at/purchase_date). Bukan consumable, no per-batch stock.
  - `2026_05_29_000021_create_medical_equipment_tariffs_table.php` — tarif flat per pemakaian per `(medical_equipment_id, insurer_id, classification)`. Mirror pola `bhp_tariffs`.
  - `2026_05_29_000022_create_medical_equipment_usages_table.php` — log pemakaian per `visit_id` (+ optional `surgery_schedule_id` utk bedah, `used_by_id` employee).
- **3 model baru** ([`MedicalEquipment.php`](backend/app/Models/MedicalEquipment.php), `MedicalEquipmentTariff.php`, `MedicalEquipmentUsage.php`). Konstanta kategori: `MICROSCOPE` / `PHACO_MACHINE` / `BIOMETRY` / `AUTOREFRACTOR` / `LAINNYA`. Status: `ACTIVE` / `MAINTENANCE` / `RETIRED`.
- **Visit relation**: `equipmentUsages(): HasMany`.
- **KasirService `getPrice()` dispatch baru**: `'equipment' => ['medical_equipment_tariffs', 'medical_equipment_id']`. Reuse seluruh resolver TPA-aware (parent/child) + fallback UMUM yang sudah ada.
- **KasirService loop billing #6 baru** (setelah IOL, sebelum COB): iterasi `visit.equipmentUsages` → 1 line per pemakaian. **Guard `if ($price <= 0) continue;`** — pasien BPJS yang tarif disetel Rp 0 atau `is_active=false` otomatis skip (sesuai INA-CBGs). item_type `'MEDICAL_EQUIPMENT'`, description: `"Pemakaian {nama} ({brand})"`.
- **Service + Controller** baru ([`MedicalEquipmentService.php`](backend/app/Services/MedicalEquipmentService.php), [`MedicalEquipmentController.php`](backend/app/Http/Controllers/MedicalEquipmentController.php)): CRUD master (auto-gen `MEQ-NNN`), upsertTariff per `(insurer_id, classification)`, recordUsage, usagesByVisit, deleteUsage.
- **Routes** ([`backend/routes/api.php`](backend/routes/api.php)): `/inventori-farmasi/alat-medis/*` (CRUD + `/{id}/tarif/*` permission `inventori_farmasi.read|write|delete`), `/alat-medis/visit/{visitId}/usages` + `/alat-medis/usage` + `/alat-medis/usage/{id}` (untuk BedahView, tanpa specific permission — pakai auth umum).
- **Frontend**:
  - [`api.js`](arumed-frontend/src/services/api.js): `masterApi.alatMedis` (utk CrudResourceView) + `alatMedisApi` standalone (tarif + usage).
  - [`masterDataStore.js`](arumed-frontend/src/stores/masterDataStore.js): REGISTRY `alatMedis`.
  - Router: `/inventori-farmasi/alat-medis` → [`AlatMedisView.vue`](arumed-frontend/src/views/inventori-farmasi/AlatMedisView.vue).
  - [`InventoriFarmasiLayout.vue`](arumed-frontend/src/views/inventori-farmasi/InventoriFarmasiLayout.vue): tab nav "Alat Medis" di section Master Item + icon `machine` SVG.
  - [`AlatMedisView.vue`](arumed-frontend/src/views/inventori-farmasi/AlatMedisView.vue): CRUD pakai `CrudResourceView` + tombol "Atur Tarif" → modal sub-resource (list tariffs per insurer + form add/edit, checkbox aktif).
  - [`BedahView.vue`](arumed-frontend/src/views/BedahView.vue): section "Pemakaian Alat Medis" di tab Intraoperatif. Dropdown alat aktif (exclude yg sudah dicatat), tombol Catat + tabel log dengan kolom Alat/Kategori/Lokasi/Waktu + hapus per row. Auto-load di watcher `selP` + initial mount.

## Pricing per insurer — pattern untuk BPJS skip

```php
// Klinik set di UI Tarif:
// UMUM      → Rp 500.000 (aktif)
// BPJS      → Rp 0       (aktif)  ATAU is_active=false
// ASURANSI  → Rp 600.000 (aktif)
```

Untuk pasien BPJS, `getPrice()` return 0 → loop skip. Untuk Umum/Asuransi, line item muncul dgn harga sesuai. Pattern ini berlaku universal di sistem — bukan equipment-only.

## Forward-only

Fase 1 dan 3 sengaja forward-only — visit lama yang sudah closed billing tidak otomatis di-recompute. Kalau perlu backfill, jalankan once-off command (belum dibuat) atau re-consolidate manual per visit lewat KasirController.

## Yang penting diperhatikan

- **Kategori BHP free-text lama masih valid** — validator nullable, jadi data legacy dgn category sembarang tidak ditolak. UI cuma offer 4 enum baru untuk write.
- **item_type `BHP` tetap satu** — pembedaan CSSD/SET/SUPPLIES via `bhp_items.category` (lookup). Tidak ada `item_type='SURGERY_INSTRUMENT_SET'` separate.
- **`itemTypes` di KasirView.vue line 174**: array sumber utk dropdown edit-item manual di kasir. Update kalau tambah jenis baru.
- **Kompatibilitas KasirController validator** (`storeItemInvoice`) — `item_type` di `in:` rule line 174: `REGISTRASI,TINDAKAN,OBAT,IOL,BHP,MEDICAL_EQUIPMENT,LAINNYA`. Tambah jenis baru di sini juga.

## Reference
- [[kasir-getprice-resolve]] — tariff resolver TPA-aware yg di-reuse semua item_type.
- [[feature_inventori_farmasi]] — sub-menu Alat Medis ditambahkan di tab nav.
- [[feature_unit_request_retur]] — flow `surgery_request` yang dipakai utk BHP (termasuk kategori INSTRUMENT_SET).
- [[feature_bedah_view]] — section "Pemakaian BHP" & "Pemakaian Alat Medis" di tab Intraoperatif.
- [[feature_kasir_view]] — pill warna baru per item_type.
