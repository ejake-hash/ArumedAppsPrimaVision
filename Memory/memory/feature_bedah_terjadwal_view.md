---
name: bedah-terjadwal-view
description: "BedahTerjadwalView.vue (/bedah/terjadwal) — Tab1 Pasien Terjadwal (tabel+weekpicker+kolom IOL biometri), Tab2 Request (tabel 3-sumber + aksi Terima/Retur + sub-tab Riwayat). Request dari paket → unit-request Inventori Farmasi."
metadata:
  node_type: memory
  type: project
  originSessionId: 697d5b2d-9424-4ec9-a321-53f3779c03d8
---

[BedahTerjadwalView.vue](arumed-frontend/src/views/BedahTerjadwalView.vue) — route `/bedah/terjadwal`, 2 tab. Beda dari [[bedah-view]] (BedahView = antrean hari ini + jadwal per-tanggal read-only). Di-wire 2026-05-29 dari 100% mock → data nyata; iterasi banyak (lihat bawah).

**Sumber data — rantai lewat visit, BUKAN langsung di surgery_schedule:** `surgery_schedules` TIDAK punya kolom patient/visit. Pasien tersambung lewat `visits.surgery_schedule_id` (di-set saat [[dokterview-module]] Tab 4 planning=BEDAH, lihat [[feature_preop_bedah]]). Diagnosa dari `doctor_examinations.diagnosis_utama`. IOL dari `iol_recommendations` per-visit (hasil Biometri PenunjangView, lihat bawah).

## Backend (`BedahService`/`BedahController`/`Visit`/`SurgerySchedule`)
- `SurgerySchedule::visit()` HasOne (reverse `Visit::surgerySchedule`). `Visit::iolRecommendations()` HasMany.
- `getScheduledSurgeries($filters)` eager-load `surgeryPackage.items`, `visit.patient`, `visit.doctorExamination`, `visit.iolRecommendations`, `leadSurgeon`. Filter: **`date_from`/`date_to`** (weekpicker, status SCHEDULED) > **`upcoming`** (`scheduled_date > today` + SCHEDULED; sengaja KECUALIKAN hari ini krn auto masuk antrean Bedah) > `tanggal` > default today. Controller `indexJadwal` teruskan `tanggal,status,upcoming,date_from,date_to`.
- 1-klik request dari paket: `GET /bedah/jadwal/{id}/auto-request/preview` (`previewAutoRequest`→`buildRequestPreviewFromSchedule`: BHP penuh dari `surgery_package.items`; IOL pasangkan item paket dgn IolRecommendation visit, +quantity). `POST /bedah/jadwal/{id}/auto-request` (`sendRequestFromSchedule`) MASIH ADA tapi **FE pakai preview saja** lalu kirim via unit-request (lihat Tab1). Grup `bedah` cuma `auth:api`, tanpa permission middleware.
- IOL recommendation dihasilkan `PenunjangService::generateIolRecommendation` saat finalize Biometri (per mata OD/OS: `recommended_power`,`iol_type`,`brand`; ada gate `is_approved`).

## Tab 1 — Pasien Terjadwal
**Tabel HTML** (`.jt`, bukan grid div) kolom: **No** · Tanggal · Nama Pasien · Diagnosa · Jenis Operasi · **IOL (Biometri)** · Paket · Aksi. `mapSchedule` flatten relasi snake_case + bangun `iol={od,os}` dari `visit.iol_recommendations` (power "+21.5 D", type; kosong→"—"). **Weekpicker**: `weekStart` null=mode upcoming; input date snap ke Senin (`mondayOf`), tombol ‹/› geser minggu, label rentang, "Semua mendatang" reset → `loadJadwal` kirim `date_from`/`date_to` atau `upcoming:1`. Tombol "Request BHP/IOL" `v-if="p.visitId"` (tanpa visit → teks "Tanpa kunjungan").
**Aksi tombol → kirim unit-request ke Inventori Farmasi** (BUKAN surgery-request): `openRequestFromPasien` panggil `autoRequestPreview` → modal konfirmasi read-only (BHP+IOL nama×qty dari paket) → `submitRequest` buat `unitRequestApi.create`+`submit` station=BEDAH, items `{item_type,item_id,qty_requested}` (BHP semua; IOL **hanya yg punya `iol_item_id`** master, sisanya skip+catatan). Tombol "Kirim ke Inventori Farmasi".

## Tab 2 — Request (tabel + aksi + sub-tab)
**Tabel** kolom No/Tanggal/Tujuan/Item/Keterangan/Status/Aksi. Gabung **3 sumber**: `bedahApi.listRequests` (kind=bedah), `unitRequestApi.list` station=BEDAH (kind=gudang), `unitReturnApi.list` station=BEDAH (kind=retur). Mapper kasih `rawId`+`kind`+`keterangan`(=reason retur)+`rawItems`. **Sub-tab Berjalan|Riwayat**: FINAL_STATUSES=[CLOSED,RECEIVED,REJECTED]→Riwayat. **Aksi per-baris**: Terima (gudang DELIVERED→`unitRequestApi.close`; bedah SENT→`bedahApi.terimaRequest`), Retur (gudang CLOSED→buka `ReturBhpIolModal` dgn **prop `prefill`={unit_request_id,items}** → retur tertaut+reason wajib). Header 2 tombol global **Minta**/**Retur** (`RequestBhpIolModal`/`ReturBhpIolModal`, lihat bawah).

## Komponen modal stok (`components/bedah/`)
`RequestBhpIolModal.vue` & `ReturBhpIolModal.vue` — mirror `components/farmasi/Request|ReturObatModal.vue` tapi item BHP+IOL (selector tipe + master dropdown). Props `:bhp`/`:iol` dari `bhpOptions`/`iolOptions`; Retur tambah prop `prefill`. station=**BEDAH**, via [[feature_unit_request_retur]]. Lifecycle unit: create+submit(DRAFT→SUBMITTED); approve/deliver/receive di sisi admin RequestUnitView.

## Gotchas penting
- **`loadMasters` & semua list master pakai `unwrapList()`** — `masterApi.bhp/iol.list` return LengthAwarePaginator (`data.data.data`). Dulu salah ambil objek paginator → prop modal "Expected Array got Object" → SEMUA tombol view mati (bukan stale tab).
- **RBAC**: endpoint `/inventori-farmasi/unit-request|unit-return` butuh `request_unit.read|write`. Role `dokter` (operator Bedah, tak ada role bedah khusus) ditambah `request_unit=>['R','W']` di RolePermissionSeeder + re-seed (`db:seed --class=RolePermissionSeeder`, sync() idempotent).
- **`createSupplyRequest` diperluas**: persist `iol_item_id` + default eye_side 'OD' + power nullable.
- **Deliver unit-request butuh `inventory_stock`** (FEFO) — aksi admin RequestUnitView, BUKAN Bedah. Tombol "Terima" baru aktif setelah admin gudang approve+deliver.
- **Dev DB**: `iol_items` & paket seed (Laser PRP) KOSONG → uji IOL butuh buat IolItem+SurgeryPackageItem+IolRecommendation temp.

## Seed
`BedahTerjadwalSeeder` (manual, BELUM di DatabaseSeeder). Idempotent: patient NIK 1271065208600099 (Tuti Handayani) → visit PREOP_BEDAH → doctor_examination (H25.9,BEDAH) → surgery_schedule H+3 SCHEDULED, reuse paket aktif pertama.

## Bug fix terkait
[BedahView.vue](arumed-frontend/src/views/BedahView.vue) TDZ — `watch(leftMode,...)` sebelum `const leftMode=ref()` → "Cannot access 'leftMode' before initialization" → halaman blank. Fix: pindah watcher ke setelah deklarasi.
