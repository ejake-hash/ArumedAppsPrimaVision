# PLAN MIGRASI GABUNGAN — Prima Vision → Arumed (Go-Live)

**Tujuan:** referensi tunggal untuk eksekusi migrasi saat go-live. Menggabungkan **Gelombang-1** (pasien/kunjungan/refraksi/dokter — TERUJI di dev) + **Gelombang-2** (master harga/tarif/paket/resep — **CODED & TERUJI dev 2026-06-01**).

**Tanggal:** 2026-05-31 · **Target go-live:** ~2026-06-01 (libur, Prima Vision beku).
**Target produksi:** server TERPISAH, DB Postgres BARU kosong (bukan `dbprimavision` dev).

> Sumber detail: Gel-1 di `MAPPING_FASE3.md` + `OBSERVASI.md`. Gel-2 di dokumen ini bagian B.

---

## RINGKASAN STATUS

| Gelombang | Cakupan | Status | Command |
|---|---|---|---|
| **1** | insurers, employees, patients, visits, refraksi, dokter | ✅ TERUJI full-run dev (0 FK orphan) | `migrasi:primavision` (sudah ada) |
| **2** | harga obat, master obat/bhp, tindakan+tarif, paket bedah, resep | ✅ **CODED & TERUJI dev 2026-06-01** (live 5 step + idempotent 2× + resep E2E) | `migrasi:primavision-master` (SUDAH dibuat) |

### Hasil uji LIVE dev Gel-2 (2026-06-01) — `migrasi:primavision-master`
- medications **411** · bhp_items **126** · inventory_prices **530** · procedures **697** (base_price>0: **421**) · surgery_packages **280** (skip 57) · surgery_package_tariffs **280**.
- resep E2E (3 visit dummy): 3 prescriptions + 4 items, signa parsed (`2xos`→2x/OS, `4xods`→4x/ODS), is_bedah OK, status DISPENSED.
- **Idempotent terbukti** run 2×: count identik, 0 duplikat code, inventory_prices UNIQUE utuh.
- **5 bug difix saat coding**: (1) `code` NOT NULL → autogen SEBELUM insert via `upsertByLegacy` firstOrNew; (2) `medications.formularium` NOT NULL → default `NON-FORNAS`; (3) `genCode` lexicographic (MED-999>MED-1000 salah) → MAX numerik + cache; (4) `legacy_uuid` belum di fillable `Medication`+`Prescription` → ditambah; (5) `prescriptions.prescribed_by_id` NOT NULL → fallback DPJP.

---

# BAGIAN A — GELOMBANG 1 (sudah teruji)

Command `php -d memory_limit=1024M artisan migrasi:primavision` — CSV gzip streaming (`Docs/migrasi data/csv/`), idempotent via legacy_uuid, urutan: insurers→employees→patients→visits→refraksi→dokter.

**Hasil dev (terverifikasi):** patients 55.442 · visits 53.140 · employees 159 · insurers 230 · refraksi 50.264 · dokter 51.159 · 0 FK orphan. soap_objective dirakit dari refraksi (~95%).

**Migrasi DB sudah ada:** `2026_06_06_000005` (patients legacy), `000006` (legacy_uuid 7 tabel), `000007` (refraction raw_data).

**Gotcha penting:** memory_limit wajib 1024M; cek `grep -c legacy_uuid app/Models/*.php` sebelum run (model sempat ter-revert di dev).

---

# BAGIAN B — GELOMBANG 2 (master harga/tarif/resep) — ✅ CODED & TERUJI

Sumber: `Docs/migrasi data/csv2/` (21 file). Mengisi gap kritis: DB Arumed `inventory_prices=0`, `procedures=9`, `procedure_tariffs=9`, `surgery_packages=1`, `prescriptions=0`.

## Arsitektur harga Arumed (otoritatif per user)
- **Harga OBAT/BHP/IOL → `inventory_prices`** (hpp/margin/ppn/hja). Dipakai e-resep dokter & Penentuan Harga.
- **`procedure_tariffs` → harga TINDAKAN/JASA** (konsultasi dll), per-penjamin.
- (Catatan: jalur lama `getPrice('medication')→medication_tariffs` di KasirService TIDAK dipakai plan ini — isu implementasi terpisah.)

## Keputusan user (FINAL, terkunci)
1. **Obat:** SEMUA obat baru belum-ada → `medications` (137 "Obat Tindakan" operasi + 55 lain). 1 master, tak pisah operasi/pulang. Alkes(86)+Bhp(37)+CSSD → `bhp_items`.
2. **Harga obat/bhp → `inventory_prices` SAJA** dari `harga_obat`. Bukan *_tariffs.
3. **Tarif `umum eksekutif` (343) DIABAIKAN.**
4. **Resep yatim (visit/dokter/obat tak dikenal) DI-SKIP.**
5. **Pembelian/stok/opname DILEWATI** (set manual).
6. **Paket bedah:** header→surgery_packages (nama+total). Rincian HANYA identifikasi BHP/CSSD baru→master (harga TIDAK dari paket). Jasa/sewa/obat di rincian→abaikan.
7. **Obat operasi vs pulang = flag `is_bedah`** di resep (bukan master). Simpan `is_bedah` saat migrasi. **TODO Arumed: UI rincian biaya** pisahkan obat operasi (tercakup paket, jangan dobel-tagih).
8. **Matching: exact by-name** (case-insensitive).

## Kecocokan TERUJI (read-only test 2026-06-01) — angka FINAL
| Sumber (rows) | → Arumed | Hasil tes nyata |
|---|---|---|
| `obat` (537) | `medications`+`bhp_items` | **410 Obat→med, 87 Alkes+39 Bhp→bhp, 1 jenis kosong** ("Ranitidine"→med). Praktis SEMUA baru (DB target kosong). *(angka lama 359/178 KELIRU)* |
| `harga_obat` (536) | `inventory_prices` | **536/536 (0 yatim)**. 8 link by-UUID putus → diselamatkan match by-NAME (keputusan #8). hpp semua >0. |
| `tindakan_*` (697) | `procedures` | **607 unik by-name → 606 baru**. |
| `carabayar_tindakan_rawat_jalan` (3.484) | `procedure_tariffs` | **96% match** pasca Gel-1: 3.013/3.141 (−343 eksekutif). Sisa 128 = 3 alias + 1 kosong. *(vs hanya 707 tanpa Gel-1)* |
| `paket_bedah` (337) | `surgery_packages` | **337 baru. ⚠️ 57 total≤0** (1 nama kosong) → skip. |
| `list_paket_bedah_baru` (13.249) | ekstraksi BHP baru | **210 BHP/CSSD unik**. Harga TIDAK dari sini. |
| `resep` (111.297) | `prescriptions`+items | **99,9% visit match** (111.228/111.297), 69 yatim skip. 43.779 header, **17.442 is_bedah**. |

### Parser signa TERUJI (`test_signa_parse.php`)
- **87,5% terurai penuh** (frequency+route); 12% sisanya signa kosong di sumber. Efektif ~99,4% dari baris berisi.
- `4XODS`→4x/ODS · `2X1`→2x/ORAL · `/3 jam os`→8x/OS · `k/p`→PRN · `/jam`→tiap 1 jam.

## ⚠️ DEPENDENSI KERAS: Gel-2 WAJIB SETELAH Gel-1 (TERUJI)
- **Tarif** butuh `insurers` hasil Gel-1 (236 penjamin). Tanpa Gel-1: match 707/3.141; dengan: 3.013/3.141 (96%).
- **Resep** butuh `visits.legacy_uuid` hasil Gel-1. Tanpa: 100% yatim; dengan: 99,9% match.
Urutan impor: **Gel-1 commit penuh → baru Gel-2** (warm-cache insurer & visit dari DB).

## Alias penjamin (3 manual) untuk tarif — sisa 4% tak auto-match:
| carabayar (sumber tarif) | → insurer Arumed | baris |
|---|---|---|
| `BPJS Ketenagakerjaan` | `BPJS Tenaga Kerja` | 28 |
| `Lonsum` | `PT.PP.London Sumatera Indonesia Tbk.` | 16 |
| `PT.PP.London Sumatera Utara Tbk.` | `PT.PP.London Sumatera Indonesia Tbk.` | 45 |
| `Asuransi Admedika Group` | (penjamin baru — buat / fallback UMUM) | 38 |

## KEPUTUSAN MAPPING FINAL (terkunci 2026-06-01, sudah di-coding)
- **base_price procedures** = `buku_tarif` (by-name) → fallback tarif `Umum` carabayar. **39% (274) tetap 0** (gap sumber, diterima).
- **Kategori procedures** = `label` buku_tarif (Tindakan Bedah/Administrasi/Laboratorium/dst) → fallback nama sumber (Rawat Jalan/Bedah/Non Bedah). 268 dari label, 430 fallback.
- **Kategori bhp_items**: Alkes→`MEDICAL_SUPPLIES`, Bhp→`MEDICAL_BHP`, CSSD→`INSTRUMENT_SET`.
- **inventory_prices.item_type** (CHECK MEDICATION/BHP/IOL): BHP & Alkes & CSSD semua → `BHP`.
- **Konversi unit obat** = `hitung_kecil` (hitung_besar semua=1, 0 anomali).
- **is_bedah** = kolom boolean `prescription_items.is_bedah`. **Yatim** SKIP (69 resep, 1 paket).

## Parsing per tabel (ringkas)
- **A. `harga_obat` → `inventory_prices`** (MEDICATION): hpp/margin_resep/ppn/hja_resep. UNIQUE (type,item).
- **B1. obat jenis=Obat belum-ada → `medications`** (unit besar/kecil/konversi, golongan, code autogen).
- **B2. obat jenis=Alkes/Bhp + CSSD → `bhp_items`** (MEDICAL_SUPPLIES/MEDICAL_BHP) + inventory_prices(BHP).
- **C1. tindakan_* → `procedures`** (kategori dari sumber, base_price dari buku_tarif/UMUM).
- **C2. carabayar_tindakan_rawat_jalan → `procedure_tariffs`** (tindakan by-name, carabayar→insurer, skip eksekutif).
- **D. resep → `prescriptions`+`prescription_items`** (group registrasi+tanggal+dokter; signa→frequency, jumlah_kecil→quantity, posisimata→route, **simpan is_bedah**; skip yatim).
- **E1. paket_bedah → `surgery_packages`** + `surgery_package_tariffs` (UMUM, sell_price=total).
- **E2. list_paket_bedah_baru** → ekstrak BHP/CSSD baru ke bhp_items (harga tidak dari sini).

## Migrasi DB tambahan untuk Gel-2 (✅ SUDAH dibuat: `2026_06_08_000010`)
Migrasi `2026_06_08_000010_add_legacy_uuid_gel2_tables` (Ran di dev):
1. `legacy_uuid` di 6 tabel: `procedures`, `procedure_tariffs`, `surgery_packages`, `surgery_package_tariffs`, `bhp_items`, `prescription_items`. (medications/prescriptions/insurers/visits sudah dapat di `000006`.)
2. `prescription_items.is_bedah` (boolean default false).
3. `inventory_prices` punya UNIQUE (item_type,item_id) → idempotent via updateOrCreate.

## Command Gel-2 (✅ SUDAH dibuat & teruji)
`migrasi:primavision-master` (`app/Console/Commands/MigratePrimaVisionMaster.php`) — pola sama Gel-1 (CSV gzip streaming dari `csv2/`, idempotent via legacy_uuid + match by-name, `--only=`/`--dry-run`/`--limit=`). Urutan: medications→bhp→inventory_prices(harga)→procedures→procedure_tariffs(tarif)→surgery_packages(paket)→resep. ⚠️ WAJIB warm-cache insurer+visit dari DB (Gel-1 commit dulu). Helper `upsertByLegacy` (autogen code SEBELUM insert) + parser signa.

---

# BAGIAN C — RUNBOOK GO-LIVE GABUNGAN (server produksi baru)

> **Pemasangan aplikasi** (Nginx/PHP-FPM/DB/clone) ada di `Docs/PROMPT-DEPLOY-PRODUCTION.md`
> (server `192.168.100.20:8080`, jangan ganggu port 8000). Runbook di bawah = **pengisian data**.
> ⚠️ csv/csv2 upload via SCP (sudah di-.gitignore, JANGAN via git — data pasien).

```
FASE 0 — Persiapan code
  1. Deploy repo Arumed ke server prod (git pull / copy backend/)
  2. composer install --no-dev --optimize-autoloader
  3. .env prod (DB Postgres BARU kosong, APP_ENV=production)
  4. php artisan key:generate (bila perlu) + config:clear

FASE 1 — Struktur & sistem
  5. php artisan migrate --force   (termasuk 000005/006/007 + migrasi Gel-2 baru)
  6. php artisan db:seed --force   (InsurerSystem + User + RBAC + ClinicProfile) — WAJIB sebelum migrasi

FASE 2 — Upload data (BUKAN via git — sensitif)
  7. Upload csv/ + csv2/ ke <root>/Docs/migrasi data/  via SCP

FASE 3 — Migrasi Gelombang 1 (pasien dst)
  8. php -d memory_limit=1024M artisan migrasi:primavision
  9. Verifikasi: count + FK orphan (patients 55.442, visits 53.140, dst)

FASE 4 — Migrasi Gelombang 2 (master/harga/resep)
  10. php -d memory_limit=1024M artisan migrasi:primavision-master
  11. Verifikasi: inventory_prices, procedures, procedure_tariffs, prescriptions

FASE 5 — Go-live
  12. Build frontend + start service
  13. Smoke: login · cari pasien lama · RME (refraksi+SOAP+resep) ·
      Kasir (tarif tindakan ≠ Rp0) · e-resep (harga obat dari inventory_prices) ·
      registrasi pasien baru
```

**Gotcha runbook:**
- Seed sistem (langkah 6) HARUS sebelum migrasi (insurer UMUM/BPJS, employee login).
- Path CSV di ROOT repo (bukan backend/).
- `memory_limit=1024M` wajib di kedua command.
- Idempotent — aman re-run bila gagal di tengah.

---

# YANG MASIH HARUS DIKERJAKAN SEBELUM BESOK

| # | Item | Status |
|---|---|---|
| 1 | Migrasi DB Gel-2 (legacy_uuid 6 tabel + is_bedah) | ✅ `2026_06_08_000010` (Ran) |
| 2 | Command `migrasi:primavision-master` | ✅ dibuat & teruji (idempotent 2×) |
| 3 | Model $fillable + legacy_uuid tabel Gel-2 | ✅ 8 model |
| 4 | Commit Gel-2 (model/migrasi/command) | ✅ commit `f302610` (branch Server-Dev) |
| 5 | `.gitignore` folder csv/csv2 (data sensitif) | ✅ + BFG bersihkan history + force-push Server-Dev |
| 6 | Commit Gel-1 (model/migrasi/command) | ✅ sudah di history (commit 1ec1707→4b42678 pasca-BFG) |
| 7 | origin/main disamakan ke versi bersih (saat push produksi) | ⏳ sisa |
| 8 | (TODO produk, non-blocking) UI rincian biaya pisah obat is_bedah | ❌ belum |

## Pertanyaan terbuka — TERJAWAB oleh tes & keputusan user 2026-06-01
1. ~~Insurer carabayar tak ketemu~~ → 96% auto-match pasca Gel-1; 3 alias + "Asuransi Admedika Group"(38)→penjamin baru.
2. ~~base_price procedures~~ → buku_tarif → fallback tarif Umum (39% tetap 0).
3. ~~resep dose/route/duration_days~~ → parser signa 87,5% (route dari signa+posisimata; duration kosong).
4. ~~is_bedah~~ → kolom boolean `prescription_items.is_bedah`.
