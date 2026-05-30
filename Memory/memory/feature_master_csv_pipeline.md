---
name: feature-master-csv-pipeline
description: "Pipeline CSV template/export/import generic master data (MasterDataService) — cara menambah resource baru, exclude code, by-name upsert, dan konvensi petunjuk komentar \"#\" di template. Plus alat-medis CSV + bug model $table."
metadata: 
  node_type: memory
  type: project
  originSessionId: 9739c349-18b4-4f44-b3c3-e80b9daebea3
---

Pipeline CSV master data terpusat di [MasterDataService.php](backend/app/Services/MasterDataService.php) + dispatch whitelist di [MasterDataController.php](backend/app/Http/Controllers/MasterDataController.php) (`CSV_TYPES`). Frontend lewat `masterApi.csv.{template,export,import}('<type>')` → route `/master/{type}/{template,export,import}-csv`. Registry frontend di [masterDataStore.js](arumed-frontend/src/stores/masterDataStore.js) (`csvType: '<type>'` atau `null` = tidak support CSV). Tombol UI: `CsvActionBar.vue` (`csvShowTemplate` prop kontrol tampil tombol Template).

**Cara menambah resource baru ke CSV** (urutan, dikerjakan 2026-05-29/30 utk `alat-medis`):
1. `resourceSchema($type)` di service — tambah arm `{table, model, uniqueKey, columns, casts}`.
2. `CSV_TYPES` di controller — tambah string type.
3. routes/api.php — 3 route `template/export/import-csv` pakai `->defaults('type', '<type>')` + middleware permission.
4. masterDataStore.js REGISTRY — set `csvType`.
5. View config — hapus `csvShowTemplate: false` kalau ada.

**Pola penting:**
- `csvHeaderColumns()` meng-EXCLUDE kolom `code` untuk type yang code-nya auto-gen (`obat`/`bhp`/`alat-medis`). Code dibuat saat import baris baru (MED-/BHP-/MEQ-NNN).
- Import 2 jalur: `importItemByNameCsv` (obat/bhp/alat-medis — upsert by LOWER(name), last-row-wins, code tak ditimpa saat update) vs `importResourceCsv` generic (iol/icd — upsert by `uniqueKey`). `tindakan` punya jalur sendiri.
- Code-gen MEQ-NNN ada DUA tempat: `MedicalEquipmentService::generateCode()` (CRUD biasa) DAN `MasterDataService::generateAlatMedisCode()` (jalur import). Keduanya MEQ-%03d.

**Konvensi petunjuk "#"** (2026-05-29, jawaban user: opsi "petunjuk di file CSV" — BUKAN XLSX sheet-2, tidak ada library Excel terpasang): `csvTemplate()` menulis baris `# ...` dari `csvTemplateNotes($type)` di atas header (daftar kategori valid diambil dari konstanta model `BhpItem::CATEGORIES` / `MedicalEquipment::CATEGORIES` supaya tak basi). Importer membuang baris `#`+kosong via helper `csvDataLines()` yang dipakai di KEDUA jalur import. Jadi admin boleh biarkan petunjuk, import tetap jalan.

**Bug model $table (fixed 2026-05-29):** [MedicalEquipment.php](backend/app/Models/MedicalEquipment.php) — inflector Laravel anggap "equipment" uncountable → infer tabel `medical_equipment` (tanpa s), padahal migration+FK pakai `medical_equipments`. Error `SQLSTATE[42P01] relasi medical_equipment tidak ada`. Fix: `protected $table = 'medical_equipments'`. Child model (Tariff/Usage) aman karena countable.

**2 BUG IMPORT DIPERBAIKI 2026-05-30 (audit user "cek import/export sudah sesuai?"):**
1. **IOL import GAGAL TOTAL** — `resourceSchema('iol').uniqueKey='serial_number'` dipakai generic import sbg kunci upsert, TAPI semua IOL master `serial_number`=NULL (partial-unique only-when-not-null; serial = per unit fisik, opsional). Export→import balik → tiap baris ditolak "serial kosong". **FIX**: method baru `importIolCsv()` (mirror `importTindakanCsv`), dispatch `if($type==='iol')` di `importResourceCsv`. Identitas upsert = **brand+model+power** (case-insensitive brand/model), serial opsional. Note template IOL di-rewrite (tak lagi inherit `$common` yg sebut "name" — IOL tak punya kolom name). Keputusan user: brand+model+power.
2. **Insert item baru dgn sel KOSONG crash 23502** — caster lama set sel kosong → `null`, tapi `stock`/`min_stock` (medications/bhp_items/iol_items) NOT NULL TANPA default → INSERT not-null violation (baris di-skip dgn error DB cryptic). Kena obat/bhp/iol. **FIX**: helper baru `castCsvCell($raw,$cast)` — int/float kosong→0, bool kosong→false, string kosong→null. Menggantikan 3 blok cast duplikat (generic `importResourceCsv`, `importItemByNameCsv`, `importIolCsv`). Catatan: pd UPDATE, sel kosong numeric → 0 (bisa reset nilai lama; tapi export selalu sertakan nilai shg roundtrip aman; stock master legacy non-otoritatif → [[feature-stok-per-lokasi]]).

Verified: roundtrip export→import SEMUA 7 tipe (obat/bhp/iol/icd10/icd9/tindakan/alat-medis) = 0 skip 0 error; insert baru empty-stock obat(MED-904)/iol(stock=0) sukses; template-only import no-op; smoke 35/35.

**CSV PER-PAKET di halaman detail Paket Bedah 2026-05-30 (user minta "template tersendiri"):** Tambah 3 tombol **Template / Export / Import** di panel "Komposisi Items" [PaketBedahDetailView.vue]. Template per-paket = header notes `#` + komposisi item paket ini TERISI (siap edit/import balik). Backend: `TarifPaketService::templatePaketCsvForPackage($id)` + `exportPaketCsvForPackage($id)` (refactor `exportPaketCsv` → shared `buildPaketCsv($pkgs, withNotes)` + `paketCsvNotes()`); controller `templatePaketCsvForPackage`/`exportPaketCsvForPackage`; route `GET /tarif-paket/paket-bedah/{id}/{template,export}-csv` (didaftar sebelum `/items`, aman dari `/{id}`). Import REUSE `importPaketCsv` global (replace by nama_paket; refresh DETAIL bukan list via `importPaketCsvOne`). api.js `paket.csvTemplateOne/csvExportOne`. Verified roundtrip per-paket (5 item, 0 err), build+smoke hijau.

**RULES IMPORT TINDAKAN ditambah 2026-05-30 (user "cek tarif-tindakan + tambahkan rules"):** `importTindakanCsv` dulu longgar — 3 gap diperbaiki (keputusan user):
1. **Harga**: WAJIB `is_numeric` & >= 0. Kosong/negatif/`abc` → SKIP baris + pesan jelas. DULU `(float)"abc"` → diam-diam Rp 0 (silent, bahaya tarif).
2. **Kategori case-insensitive**: helper baru `resolveCategoryName($cat)` cocokkan ke `ProcedureCategory` LOWER(name) → kembalikan NAMA KANONIK (mis. 'tindakan'/'TINDAKAN' → 'Tindakan'); tak terdaftar → SKIP. Dipakai utk lookup existing + `generateProcedureCode`. DULU strict exact-match → 'tindakan' ditolak walau valid.
3. **Status**: helper baru `parseCsvBool($raw,$default)` — aktif:1/true/yes/y/aktif/active, nonaktif:0/false/no/n/nonaktif/inactive, kosong/tak dikenal('foo')→default(true). DULU cuma cek subset, 'foo'→nonaktif diam-diam.
Verified semua skenario (negatif/abc/kosong→skip; lowercase/upper kategori→insert kode TND-xxx kanonik; nonaktif→is_active=false; harga 0/desimal→insert). **TEMUAN DATA (bukan bug kode)**: 1 procedure existing `TND-PHACO "Phacoemulsifikasi + IOL"` berkategori "Bedah" yang TAK terdaftar di ProcedureCategory (terdaftar: Tindakan/Konsultasi/Administrasi/Penunjang) → roundtrip tindakan skip 1 baris ini (validasi benar). Perlu keputusan user: daftarkan kategori "Bedah" atau recategorize procedure ini. (Catatan: import generic obat/bhp/iol BELUM punya validasi nilai enum spt category BHP/equipment — masih apa adanya.)

**TARIF & PAKET BEDAH CSV — audit + notes + bug 2026-05-30 (user "cek template export impor di semua halaman tarif & paket bedah"):** 3 jalur CSV di /tarif-paket → lihat [[feature-tarif-paket-bedah]]. **2 BUG (akar sama: export emit baris utk master SOFT-DELETED yg import tak bisa cocokkan):**
1. **Tarif per-insurer export** (`MasterDataService::exportTarifCsvForInsurer`) — JOIN procedure_tariffs↔procedures TANPA filter `procedures.deleted_at` → ekspor tarif procedure terhapus (mis. KSL-001 "Konsultasi Dokter" soft-deleted) → import gagal "tidak ditemukan". **FIX**: tambah `->whereNull('item.deleted_at')` di JOIN.
2. **Paket bedah export** (`TarifPaketService::exportPaketCsv`) — item yg `resolveItem()` null (master soft-deleted, mis. paket "Laser PRP" item PROCEDURE terhapus) → ditulis nama KOSONG → import error "item_nama kosong". **FIX**: skip item resolveItem null; kalau SEMUA item paket null → tetap emit 1 baris header (paket tak hilang) via flag `$exportedItem`.
**NOTES PENGISIAN "#" ditambah ke 3 template** (tarif per-insurer `templateTarifCsv`, paket `templatePaketCsv`, tindakan master `tindakanCsvHeaderOnly`) — daftar kategori/item_tipe/aturan harga. **WAJIB**: importer `importTarifCsvForInsurer`/`importPaketCsv`/`importTindakanCsv` diubah pakai `csvDataLines()` (skip baris `#`) — dulu `array_filter(explode)` polos shg notes `#` akan jadi baris error. `TarifPaketService` dapat helper `csvDataLines()` sendiri (mirror MasterDataService). Verified: roundtrip tarif+paket 0 skip/0 err, import template-only no-op, master 7 tipe regression 0 err, smoke 35/35.
**ORPHAN DATA (dilaporkan, TIDAK dihapus)**: 3 procedure_tariffs → KSL-001 (procedure soft-deleted) + 1 paket-item "Laser PRP" → procedure soft-deleted. Kode sekarang aman (skip saat export). Kalau mau bersih, hapus tarif/item orphan manual.

**BELUM dikerjakan:** validasi nilai `category` saat import obat/bhp/alat-medis (kalau admin salah ketik mis. `cssd`/`INSTRUMEN`, nilai tetap tersimpan apa adanya — kolom DB string biasa, no CHECK). Tindakan SUDAH divalidasi (resolveCategoryName). Petunjuk "#" cuma mengurangi risiko, tak menjamin. User belum minta validasi enum obat/bhp ini.

Konteks modul: [[feature-inventori-farmasi]] (alat-medis = master item ke-4), [[feature-billing-items-expansion]] (tarif flat per pemakaian), [[feature-master-data-stage1]].
