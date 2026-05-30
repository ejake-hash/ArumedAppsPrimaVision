---
name: feature-kasir-view
description: KasirView revamp 2026-05-29 — kartu antrean PerawatView-style + Lewati + Kategori dari procedures.category + diskon flex (Rp/% per-item dan global) + print fix via Teleport
metadata: 
  node_type: memory
  type: project
  originSessionId: 83cb5400-a8e1-4343-b99e-029d37e04567
---

KasirView refactor — semua perubahan landed dalam satu pass.

**Kartu antrean (kiri)**: dirombak mengikuti PerawatView (`aside.col-queue` > `.card` > `.card-head` + `.queue-scroll` > `.stats-bar` (Belum Bayar / Lunas / Total) + `.primary-filter` (Belum Bayar | Lunas) + `.ptype-tabs` (Semua | BPJS | Umum/Asuransi) + `.q-search` + `.q-item` dengan `.qi-left` (no antrean + pill status) + `.q-info` (nama, RM, tag penjamin, actions) + `.qi-time`). Button-press tier-3 di `.q-act-btn:active` dengan `transform: scale(.93)` + warna press berbeda untuk call/recall/skip.

**Tombol Lewati**: backend baru — `PUT /kasir/antrian/{id}/lewati` → `KasirService::lewatiAntrian` delegate ke `QueueService::lewati` (sama pola Perawat). Frontend `kasirApi.lewatiAntrian` + `skipPt()` dengan `pendingSkipIds` guard supaya tidak dobel-klik.

**Kategori kolom**: dulu tampilkan `item_type` enum (TINDAKAN/OBAT/IOL/BHP/REGISTRASI). Sekarang tampilkan `category` dari procedures (mis. "Pemeriksaan", "Konsultasi", "Operasi") via column baru `billing_items.category` (migration `2026_05_29_000020`). Fallback ke `item_type` kalau category null.

**Diskon (per-item + global, Rp/%)**:
- DB: `billing_items` dapat `discount_amount`, `discount_percent`, `net_price` (kolom baru). `billing_invoices` dapat `discount_percent`.
- Backend: `KasirService::computeItemDiscount()` hitung pasangan (amount, percent) konsisten — kalau user kirim salah satu, hitung yang lain. `recalculateInvoice()` sekarang sum `net_price` (bukan `total_price`) — sehingga subtotal gross tetap, diskon item ditarik dari net.
- Frontend: 2 kolom extra di tabel saat `editTagihan` (Disc % dan Disc Rp), debounce 400ms ke `kasirApi.updateItem`. Footer: blok "Diskon Global" dengan input % dan Rp side-by-side, debounce ke `kasirApi.updateInvoice`. Display row "Diskon Item" terpisah dari "Diskon Global".

**Cetak rincian/kwitansi yang dulu kosong**: root cause = template `.rincian-print` berada di dalam `<style scoped>` Vue (mendapat `data-v-xxx`). `@media print` dengan rule scoped tidak match elemen di posisi root. Fix:
1. Bungkus `.rincian-print` dengan `<Teleport to="body">` supaya keluar dari pohon scoped.
2. Pisah jadi dua `<style>` block: `<style scoped>` untuk UI normal, plus `<style>` (global) untuk `.rincian-print` + `@media print`.
3. `@media print` sekarang pakai `body > *:not(.rincian-print) { display: none }` — lebih bersih dari menargetkan `.kasir > .grid` saja.

**Why**: user minta poin-poin di atas dalam satu requestes. Lewati endpoint juga sekaligus bikin TV broadcast jalan (`QueueService::lewati` already triggers broadcast).

**How to apply**: kalau perlu nambah/edit item kasir lain, ingat sekarang ada per-item discount fields. Backend `consolidateBilling` udah isi `category` + `net_price` untuk semua source (registrasi/tindakan/obat/IOL/BHP). [[kasir-getprice-resolve]] tetap relevant — getPrice masih sentral resolver tarif.

Related: [[feature-perawat-view]] (sumber pola kartu antrean), [[kasir-getprice-resolve]].

---

## Update lanjutan 2026-05-29 — modular sources + master Kategori + auto-route Farmasi

**Builder pattern**: `consolidateBilling()` di-refactor jadi 7 method `build{Registrasi,Tindakan,Obat,Penunjang,Bhp,Iol,Equipment}Lines(Visit $visit): array`. Body consolidate cuma `array_merge(...)`. Tambah source baru = tambah 1 method + 1 baris di array_merge.

**Penunjang masuk invoice** (arsitektur FINAL 2026-05-30 — procedure-based, lihat [[feature-penunjang-tarif-wiring]]):
- Penunjang = `procedures` kategori "Penunjang". `buildPenunjangLines()`: filter `visit->diagnosticOrders` status `COMPLETED`, resolve `test_type`(KODE procedure) → `Procedure::whereIn('code')` → `getPrice('procedure', $proc->id, ...)`. `item_type='PENUNJANG'`, `category` dari `procedure->category` ("Penunjang") fallback "Penunjang".
- Enum `item_type` di validation `storeItemInvoice` & `updateItemInvoice` ditambah `PENUNJANG`. Frontend `itemTypes` array juga.
- **OBSOLETE (dibongkar 2026-05-30)**: tabel `diagnostic_test_type_tariffs` (di-drop), arm `getPrice('diagnostic_test')` (dihapus). Versi awal 2026-05-29 sempat pakai itu — JANGAN dipakai lagi.

**Master Kategori Tagihan (billing_categories)**:
- Tabel `billing_categories` (id + name unique + sort_order + is_active). Seeder default 10 row (Registrasi 10 → Lainnya 999).
- CRUD via `MasterDataController::*BillingCategory` + service `MasterDataService::*BillingCategory`. Routes `/master/kategori-tagihan` (+`/reorder` PUT bulk).
- UI: `KategoriTagihanView.vue` (pakai `CrudResourceView`), di-link dari `TarifPaketLayout` section "Pengaturan" (icon `tag`). Router: `/tarif-paket/kategori-tagihan`.
- `masterDataStore.REGISTRY.kategoriTagihan` + `masterApi.kategoriTagihan` (list/create/update/remove/reorder).
- KasirView fetch on mount → `billingCategories` ref. Helper `groupItemsByCategory(items, categories)` kelompokkan items sesuai sort_order master. Kategori tidak terdaftar → bucket "Lainnya" di akhir.
- Render: `<tbody v-for="grp in groupedItems">` dengan header row `.kat-group-head` (nama + count + subtotal) lalu rows item. Add-item row tetap di `<tbody>` terpisah. Datalist `kasir-cat-list` untuk autocomplete kategori di add-item.
- Print template (`Teleport to="body"`) juga grouped: `<tbody v-for="grp in groupedPrintItems">`. `generateReceipt` payload tambah `categories` (BillingCategory aktif sort_order asc) supaya cetak ikut urutan master.

**Routing fix KASIR→FARMASI**: `processPayment` (sebelumnya hardcode `current_station='SELESAI'`) sekarang panggil `queueService->advanceFromStation($kasirQueue->id, Queue::STATION_KASIR)`. `QueueService::nextAfterKasir()` cek prescription DRAFT/SUBMITTED/DISPENSING → return FARMASI atau END_OF_FLOW. Hasilnya: pasien dengan resep otomatis masuk antrean FARMASI + TV broadcast jalan dari satu sumber. Fallback ke manual SELESAI bila tidak ada queue Kasir aktif (defensive).

**BPJS**: tetap flow normal — tarif resolve dari master tarif BPJS via `getPrice` (sudah jalan). TIDAK ada bypass / panel "Konfirmasi BPJS". Validation `paid_amount: min:0.01` tetap. Setup tarif master BPJS yang harus benar; total 0 = master kosong = guard validation yang nge-flag.

**Why**: user request "seluruh item harus muncul di kasir" (Penunjang) + "kategori bisa di-set admin via UI" (grouping master) + "setelah bayar masuk farmasi kalau ada resep" (routing fix). Ditambah builder pattern supaya tidak nyentuh consolidateBilling tiap kali ada source baru.

**How to apply**: kalau ke depan ada source baru (mis. tarif refraksi, tarif konsultasi terpisah), tambah satu method `buildXxxLines(Visit $visit): array` di KasirService dan 1 baris di array_merge body `consolidateBilling`. Tarif lookup pakai `getPrice($type, $id, $guarantor, $insurer)` — kalau type baru, tambah arm di match-nya juga.

Out of scope (follow-up): UI Master Tarif Penunjang di TarifPaketLayout (sementara seed manual via DB), refraksi billing, COB lengkap.

---

## Update 2026-05-30 — kwitansi list-style + e-sign kasir + identitas + tarif Edit Tagihan

**Cetak rincian (`.rincian-print`) — TANPA TABEL**: markup `<table class="rp-items">` diganti `<div>` list (per kategori = sub-judul + subtotal, tiap item = baris flex `nama … harga` dengan dot-leader). Diskon per-item: harga gross dicoret (`.rp-row-gross`) + net + label `diskon −Rp (…%)`. CSS lama (border/`th`/`.rp-stamp`/`.c-no`/`.c-kat`) dihapus.

**TTD kwitansi**: kolom "Penanggung Jawab / Pasien" **DIHAPUS** (user minta). Sisa 1 kolom **Kasir** = badge e-sign teks ringan "✓ Ditandatangani elektronik" + nama kasir (`printData.cashier`) + nomor invoice + `paid_at` (pola sama PO [[feature-pembelian-penerimaan]], BUKAN canvas). Fallback garis manual kalau cashier null.

**BPJS**: tombol Cetak Rincian/Kwitansi tampil tapi `cetakRincian()` di-guard `isBpjsSelected` → toast tolak ("kwitansi tidak dicetak untuk pasien BPJS, ditagihkan ke BPJS"). Sumber: `selQ.visit.guarantor_type==='BPJS'`.

**Kartu identitas pasien (pt-banner)**: tambah baris `.pt-contact` (HP + alamat+province dari `patient.phone`/`address`/`province`, sudah ada di `visit.patient`; placeholder "belum diisi" kalau kosong).

**Edit Tagihan → tarif per-penjamin (poin penting)**: Backend baru `KasirService::getTarifTindakan($visitId)` (mirror DokterService tapi TANPA gate ownership dokter) → `Procedure aktif` + `getPrice('procedure',…,guarantor,insurer)`. Route `GET /kasir/tarif-tindakan?visit_id=` + `KasirController::tarifTindakan` + `kasirApi.tarifTindakan`. `tarifList` di-fetch saat `watch(editTagihan)` true.

**Tambah tindakan = KONSEP PERSIS Tab Tindakan DokterView** (revisi 2026-05-30, ganti dropdown awal): search-driven picker `.add-tindakan-bar` di atas tabel (muncul saat editTagihan) — input cari (`tindakanSearch`) → dropdown `filteredTarif` (kode/kategori/nama/harga + ikon +/✓) → klik (`@mousedown.prevent="addTindakanFromTarif(t)"`) **langsung POST `storeItem`** (kasir persist server-side, BEDA dgn DokterView yg staging lokal autosave). `reference_id=procedure.id` (validation `nullable|uuid` OK). `tarifInInvoice()` cek by `item_type==='TINDAKAN' && description===name` utk badge ✓. Guard `addingTindakanIds`. Outside-click handler di `onMounted`. CSS `.tindakan-search-*`/`.tarif-list-*`/`.tarif-kode`/`.tarif-kat` disalin dari DokterView. Add-item-row lama disederhanakan → "Item manual lain" (non-tindakan). Verified E2E: pick Administrasi Rp 75rb → billing_item net 75rb, total invoice ter-recalc; smoke 35/35; build hijau.

UI rincian tabel di-clean (`/ui-ux-pro-max`): hover lebih kalem (`var(--gl)` cuma item), group head flat (border-left 3px `--ga`, buang gradient), garis pemisah hanya antar-item, input `.tbl-fi` height 30px konsisten.

**Kartu identitas pasien (pt-banner)**: baris `.pt-contact` = HP + **alamat**(+province) dari `patient.phone`/`address`/`province` (sudah ada di `visit.patient`). Placeholder "belum diisi" kalau kosong. KasirDemoSeeder dikasih field `address` per profil.

**1 tombol "Setting Print" (2026-06-01)**: tombol gear di header kartu Rincian Tagihan → popover (`.print-set-pop`, klik-luar tutup via `onClickOutsideTindakan` yg di-extend + `printSetRef`) berisi 5 checkbox toggle elemen cetak: `show_logo/show_stamp/show_esign/show_footer/show_watermark`. Auto-save per-toggle (optimistic + rollback). Disimpan per klinik di kolom JSON baru `clinic_profiles.receipt_print_settings` (migration `2026_06_01_000004`, cast array) + `ClinicProfile::RECEIPT_PRINT_DEFAULTS` & `receiptPrintSettings()` (default ditimpa tersimpan). Backend `KasirService::getReceiptPrintSettings`/`updateReceiptPrintSettings` (merge key dikenal saja, cast bool). `generateReceipt` gate `logo_url/stamp_url/watermark_type` → null jika toggle off + tambah `print_settings` ke payload; template gate `rp-esign` (show_esign) & footer direktur (show_footer). Route `GET|PUT /kasir/print-settings` + `KasirController::getPrintSettings`/`updatePrintSettings` + `kasirApi.getPrintSettings`/`updatePrintSettings`, fetch di onMounted. Verified E2E: toggle stamp+watermark off → receipt stamp_url/watermark_type null; smoke 35/35; build hijau. (NB: stempel `stamp_path` masih BELUM ada UI upload — toggle show_stamp hanya efektif kalau stempel sudah ada.)

**BPJS = KONFIRMASI tanpa bayar (2026-05-30)**: pasien BPJS tidak bayar di kasir → ditagih via klaim INA-CBG. Panel kanan untuk `isBpjsSelected && status!=='PAID'` ganti jadi box "Ditanggung BPJS Kesehatan" (biru `.cover-bpjs`) + 1 tombol "Konfirmasi (Ditanggung BPJS)" → `prosesKonfirmasiBpjs` → `kasirApi.confirmBpjs`. Backend `KasirService::confirmBpjsCoverage` (BEDA dari `confirmInsuranceCoverage`: TIDAK set covered_amount, TIDAK buat draft klaim TPA — BPJS jalur klaim sendiri): finalize jika DRAFT → status PAID, payment_method='BPJS', paid_amount=0, advanceFromStation. Guard `guarantor_type==='BPJS'`. Route `POST /kasir/invoice/{id}/confirm-bpjs` + `KasirController::confirmBpjs`. Tombol "Proses Pembayaran" & "Campuran" disembunyikan utk BPJS belum-PAID. Verified E2E: BPJS invoice→PAID method=BPJS paid=0 covered=0, visit→SELESAI; smoke 35/35. (Pola sama full-cover asuransi `isFullCover`/`prosesKonfirmasiCover`, urutan v-if: BPJS dulu, lalu asuransi, lalu metode bayar normal.)
