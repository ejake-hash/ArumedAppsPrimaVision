# PLAN MIGRASI GABUNGAN — Prima Vision → Arumed (Go-Live)

**Tujuan:** referensi tunggal untuk eksekusi migrasi saat go-live. Menggabungkan **Gelombang-1** (pasien/kunjungan/refraksi/dokter — sudah TERUJI di dev) + **Gelombang-2** (master harga/tarif/paket/resep — plan, belum dieksekusi).

**Tanggal:** 2026-05-31 · **Target go-live:** ~2026-06-01 (libur, Prima Vision beku).
**Target produksi:** server TERPISAH, DB Postgres BARU kosong (bukan `dbprimavision` dev).

> Sumber detail: Gel-1 di `MAPPING_FASE3.md` + `OBSERVASI.md`. Gel-2 di dokumen ini bagian B.

---

## RINGKASAN STATUS

| Gelombang | Cakupan | Status | Command |
|---|---|---|---|
| **1** | insurers, employees, patients, visits, refraksi, dokter | ✅ TERUJI full-run dev (0 FK orphan) | `migrasi:primavision` (sudah ada) |
| **2** | harga obat, master obat/bhp, tindakan+tarif, paket bedah, resep | ⏳ PLAN (belum coding) | `migrasi:primavision-master` (BELUM dibuat) |

---

# BAGIAN A — GELOMBANG 1 (sudah teruji)

Command `php -d memory_limit=1024M artisan migrasi:primavision` — CSV gzip streaming (`Docs/migrasi data/csv/`), idempotent via legacy_uuid, urutan: insurers→employees→patients→visits→refraksi→dokter.

**Hasil dev (terverifikasi):** patients 55.442 · visits 53.140 · employees 159 · insurers 230 · refraksi 50.264 · dokter 51.159 · 0 FK orphan. soap_objective dirakit dari refraksi (~95%).

**Migrasi DB sudah ada:** `2026_06_06_000005` (patients legacy), `000006` (legacy_uuid 7 tabel), `000007` (refraction raw_data).

**Gotcha penting:** memory_limit wajib 1024M; cek `grep -c legacy_uuid app/Models/*.php` sebelum run (model sempat ter-revert di dev).

---

# BAGIAN B — GELOMBANG 2 (master harga/tarif/resep) — PLAN

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

## Kecocokan terverifikasi
| Sumber (rows) | → Arumed | Kecocokan |
|---|---|---|
| `obat` (537) | `medications`+`bhp_items` | 359 cocok; 178 sisa: 55 Obat→med, 86 Alkes+37 Bhp→bhp |
| `harga_obat` (536) | `inventory_prices` | 528/536 obat_uuid cocok |
| `tindakan_*` (572+107+18) | `procedures` | hampir semua baru |
| `carabayar_tindakan_rawat_jalan` (3.484) | `procedure_tariffs` | 39 penjamin; −343 eksekutif |
| `paket_bedah` (337) | `surgery_packages` | header saja |
| `list_paket_bedah_baru` (13.249) | ekstraksi BHP baru | 6% cocok; 94% jasa/beda |
| `resep` (111.297) | `prescriptions`+items | via registrasi_uuid→visit (gel-1) |

## Parsing per tabel (ringkas)
- **A. `harga_obat` → `inventory_prices`** (MEDICATION): hpp/margin_resep/ppn/hja_resep. UNIQUE (type,item).
- **B1. obat jenis=Obat belum-ada → `medications`** (unit besar/kecil/konversi, golongan, code autogen).
- **B2. obat jenis=Alkes/Bhp + CSSD → `bhp_items`** (MEDICAL_SUPPLIES/MEDICAL_BHP) + inventory_prices(BHP).
- **C1. tindakan_* → `procedures`** (kategori dari sumber, base_price dari buku_tarif/UMUM).
- **C2. carabayar_tindakan_rawat_jalan → `procedure_tariffs`** (tindakan by-name, carabayar→insurer, skip eksekutif).
- **D. resep → `prescriptions`+`prescription_items`** (group registrasi+tanggal+dokter; signa→frequency, jumlah_kecil→quantity, posisimata→route, **simpan is_bedah**; skip yatim).
- **E1. paket_bedah → `surgery_packages`** + `surgery_package_tariffs` (UMUM, sell_price=total).
- **E2. list_paket_bedah_baru** → ekstrak BHP/CSSD baru ke bhp_items (harga tidak dari sini).

## Migrasi DB tambahan untuk Gel-2 (BELUM dibuat)
1. `legacy_uuid` di: `procedures`, `procedure_tariffs`, `surgery_packages`, `surgery_package_tariffs`, `bhp_items`, `prescriptions`, `prescription_items`.
2. `inventory_prices` dedup (item_type,item_id); legacy_uuid opsional.
3. `prescription_items.is_bedah` (boolean) ATAU pakai `prescriptions.notes`.

## Command Gel-2 (BELUM dibuat)
`migrasi:primavision-master` — pola sama Gel-1 (CSV gzip streaming dari `csv2/`, idempotent, `--only=`/`--dry-run`/`--limit=`). Urutan: medications-baru→bhp-baru→inventory_prices→procedures→procedure_tariffs→surgery_packages→resep.

---

# BAGIAN C — RUNBOOK GO-LIVE GABUNGAN (server produksi baru)

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
| 1 | Migrasi DB Gel-2 (legacy_uuid 7 tabel + is_bedah) | ❌ belum |
| 2 | Command `migrasi:primavision-master` | ❌ belum (perlu coding + uji sample) |
| 3 | Model $fillable + legacy_uuid tabel Gel-2 | ❌ belum |
| 4 | Commit semua (Gel-1 model/migrasi/command + Gel-2) | ❌ belum |
| 5 | `.gitignore` folder csv/csv2 (data sensitif) | ❌ belum |
| 6 | (TODO produk, non-blocking) UI rincian biaya pisah obat is_bedah | ❌ belum |

## Pertanyaan terbuka (jawab saat eksekusi Gel-2)
1. Insurer carabayar tak ketemu: buat baru atau fallback UMUM?
2. base_price procedures: dari buku_tarif atau tarif UMUM carabayar?
3. resep dose/route/duration_days: petakan dari signa/posisimata atau kosong?
4. is_bedah: kolom `prescription_items.is_bedah` (migrasi+UI) vs `prescriptions.notes`?
