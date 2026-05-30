---
name: feature-penunjang-tarif-wiring
description: "Penunjang = procedures kategori 'Penunjang' (master tunggal di Tarif Tindakan, ada harga). diagnostic_test_types jadi MIRROR via ProcedureObserver (dipakai order/hasil/biometri). Tarif penunjang IKUT procedure_tariffs (bukan tabel sendiri). diagnostic_orders.test_type = KODE procedure. Biometri = procedure kode BIOM."
metadata:
  node_type: memory
  type: project
  originSessionId: fd57371e-4e17-4873-bf91-818b31a5e3f2
---

Penunjang ↔ tarif ↔ tindakan dokter. **Arsitektur FINAL 2026-05-30** (mengganti total wiring `diagnostic_test_types` 2026-05-29 yang dibalik). Lihat [[kasir-getprice-resolve]], [[feature-penunjang-view]], [[feature-kasir-view]], [[feature-tarif-paket-bedah]].

## Arsitektur final: penunjang = procedures kategori "Penunjang"
- **Master tunggal** = `procedures` kategori **"Penunjang"** (kode PNJ-NNN · nama · kategori · `base_price`), dikelola di **Tarif Tindakan** persis seperti tindakan lain. Satu-satunya tempat set harga.
- **Tarif per-penjamin penunjang IKUT procedure** (`procedure_tariffs`), override di tab Tarif Tindakan di Metode Bayar yang SUDAH ADA. TIDAK ada tabel/menu tarif penunjang terpisah.
- **`diagnostic_test_types` = MIRROR** dari procedures (dipertahankan karena alur order/hasil/biometri pakai `diagnostic_orders`/`diagnostic_results`). Sinkron otomatis via observer.
- **`diagnostic_orders.test_type` = KODE procedure** (mis. PNJ-001/BIOM). Dokter order kirim kode (chip dari mirror `diagnosticTestType.list`, kirim `o.code || o.name`; custom "Lainnya" `code:null` → tak ditarifkan).
- **Tagihan**: penunjang tetap label item_type **PENUNJANG**, tapi harga resolve via **procedure** — `KasirService::buildPenunjangLines` & `DokterService::getPenunjangBilling` map `test_type`(code) → `Procedure::whereIn('code')` → `getPrice('procedure', $proc->id, ...)`. **NB: bukan `getPrice('diagnostic_test')` lagi** — arm itu DIHAPUS.

## Sinkronisasi (KUNCI)
- **`App\Observers\ProcedureObserver`** (didaftar di `AppServiceProvider::boot()` via `Procedure::observe`): `saved` → kalau category==='Penunjang' `DiagnosticTestType::updateOrCreate(['code'=>$p->code], name/category/is_active, tanpa harga)`; kalau bukan Penunjang → soft-delete + is_active=false cermin. `deleted`/`restored` ikut. Terbukti jalan via `$p->touch()` (re-mirror) + test create/recat.
- **Backfill** migration `2026_05_30_100010`: promote tiap `diagnostic_test_types` lama → procedures (kategori Penunjang, base_price 0, kode sama) + arah balik (PNJ-001 → mirror) + seed BIOM (procedure + mirror). Idempotent. Kategori "Penunjang" (prefix PNJ) ada di `procedure_categories`.

## Biometri = procedure kode BIOM
Konstanta `DiagnosticTestType::BIOMETRI_CODE = 'BIOM'` dipakai cek biometri (form OD/OS + auto-IOL). Cek `=== 'Biometri'` lama sudah diganti BIOM di: `PenunjangService` auto-IOL ×2, `PenunjangController::validateExpertiseData` (arm match konstanta) + `storeOrder` validasi → `exists:diagnostic_test_types,code`, [PenunjangView.vue](arumed-frontend/src/views/PenunjangView.vue) 3 cek pakai const lokal `BIOMETRI_CODE='BIOM'`. BIOM ada sbg procedure (kategori Penunjang) + mirror.

## Tab "Jenis Penunjang" (modul Penunjang) = READ-ONLY tanpa harga
[JenisPenunjangView.vue](arumed-frontend/src/views/master-data/JenisPenunjangView.vue): `config.readOnly:true`, kolom code/name/category/is_active (TANPA harga). Sumber = `diagnosticTestType` (mirror). Tambahan reusable: flag `config.readOnly` di [_CrudResourceView.vue](arumed-frontend/src/views/master-data/_CrudResourceView.vue) (sembunyikan Tambah + CSV bar) + prop `hideActions` di [MasterTable.vue](arumed-frontend/src/components/master-data/MasterTable.vue) (sembunyikan kolom Aksi walau slot ada).

## Tab 3 dokter — preview penunjang read-only
Endpoint `GET /dokter/kunjungan/{visitId}/penunjang-billing` (`DokterService::getPenunjangBilling`, resolve via procedure → preview == invoice). FE [DokterView.vue](arumed-frontend/src/views/DokterView.vue): `penunjangBilling` ref + `loadPenunjangBilling` (watch visitId) + card read-only `.pj-bill-*` antara Tindakan & E-Resep (Total Penunjang + Estimasi). **JANGAN push ke `tindakanList`** → dobel-tagih sbg TINDAKAN.

## Sejarah bug (tetap relevan)
Dokter DULU simpan **nama** ke `test_type` tapi billing lookup by **code** → penunjang tak tertagih. Fix: kirim kode. Sekarang kode = kode procedure (via mirror).

## How to apply / gotcha
- Tambah penunjang baru → tambah procedure kategori "Penunjang" di Tarif Tindakan; observer auto-bikin mirror; tarif per-penjamin di tab Tarif Tindakan Metode Bayar.
- **DIBONGKAR & jangan dipakai lagi**: tabel `diagnostic_test_type_tariffs` (di-drop), model `DiagnosticTestTypeTariff` (dihapus), arm `'penunjang'` dispatcher tarif, arm `'diagnostic_test'` getPrice, kolom `diagnostic_test_types.base_price` (di-drop), tab "Tarif Penunjang" Metode Bayar. Migration drop: `2026_05_30_100000`.
- Order lama (pre-fix) simpan nama → tak match procedure code → tak tertagih (forward-only).
- Verified round-trip (tinker): add procedure Penunjang via Tarif Tindakan (auto PNJ-002, base 275rb) → mirror otomatis → tarif insurer 320rb → `getPrice('procedure')` resolve 320rb (override) / 0 UMUM fallback.
- BPJS: pola Rp 0 / is_active=false skip otomatis [[kasir-getprice-resolve]].
