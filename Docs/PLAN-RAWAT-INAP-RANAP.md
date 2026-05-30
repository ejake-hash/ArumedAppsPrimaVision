# Plan: Modul Rawat Inap (RANAP) — Arumed / Prima Vision (IGD ditunda)

> Disalin dari plan kerja 2026-05-30 ke folder Docs supaya ikut sync ke komputer rumah.
> **Status: belum dieksekusi.** Mulai dari Fase 1. Kerja BERTAHAP per fase, tunggu konfirmasi tiap fase.
> Memory terkait: `project-rawat-inap-igd` (di `.claude/.../memory/`). Diagram arsitektur: `Docs/ARSITEKTUR-DIAGRAM.md`.

## Context

Arumed (instance RS Mata Prima Vision Medan) saat ini **100% rawat jalan**. Branding sudah "RS Mata", jadi rawat inap & IGD memang in-scope bisnis. Aplikasi punya asumsi rawat-jalan yang tertanam di banyak tempat dan harus diperluas tanpa merusak alur existing.

**Fokus tahap ini: RAWAT INAP penuh.** IGD diturunkan — hanya **kolom/struktur datanya disiapkan** di Fase 1 (agar tak perlu migrasi ulang nanti), sedangkan **seluruh alur & UI IGD ditunda** ke fase paling akhir.

**Keputusan user (final):**
- Scope MVP Rawat Inap: **operasional + billing + BPJS/SatuSehat inap + transfer kamar** (semua in). IGD: data-only di Fase 1, alur/UI ditunda.
- Model data: **perluas tabel `visits`** (bukan tabel admission terpisah) + tabel pendukung.
- IGD (nanti): **triase berlevel** (ESI 1-5 / warna) yang menentukan prioritas antrean (bukan FIFO).
- **LOS** = per malam, minimum 1 hari (masuk dihitung, pulang tidak; masuk=pulang → 1).
- **IGD → RANAP**: buat **SEP rawat inap baru** (jnsPelayanan=1), SEP IGD tetap.
- **Room charge**: digenerate **sekaligus saat discharge** (hitung LOS → buat semua baris biaya kamar). Tanpa cron.
- **Penentu jenis pelayanan**: **otomatis per pintu masuk** — daftar di admisi = `RAJAL`, masuk via menu IGD = `IGD`, `RANAP` selalu turunan keputusan dokter (planning RAWAT_INAP / disposisi IGD RANAP). Petugas tidak memilih manual.
- **Master Bangsal & Bed**: **dikelola dari halaman Profil Klinik** (sesuai permintaan user), TAPI backing-store = tabel `wards`/`beds` (bukan JSON), supaya status occupancy real-time tetap akurat. Form di Profil Klinik = editor/pintu ke tabel tsb (pola mirip editor `operating_rooms`, tapi persist ke tabel).
- **Kwitansi 2 tipe**: nomor invoice prefix per jenis (`INV-RJ/...`, `INV-RI/...`, `INV-IGD/...`) dengan **counter TERPISAH per tipe** (masing-masing mulai 001). Kwitansi RI menampilkan blok tambahan: kamar/hari × LOS, kelas, tgl masuk/keluar, lama rawat.

**Outcome:** jenis pelayanan RANAP hidup berdampingan dengan rawat jalan (RAJAL), lengkap dari admit → alur klinis harian → billing → klaim BPJS/Satu Sehat, dengan **zero regression** pada alur rawat jalan. Struktur data IGD sudah siap untuk diaktifkan belakangan.

---

## Menu / Fitur Modul Rawat Inap (cakupan UI)

Semua masuk MVP (operasional + billing + BPJS/SatuSehat + transfer kamar).

- **A. Papan Bangsal (Bed Board)** — tampilan utama: grid bed per bangsal dengan status warna (Kosong/Terisi/Cleaning/Maintenance), ringkasan okupansi, klik bed terisi → detail pasien.
- **B. Admit / Assign Bed** — daftar "Menunggu Kamar" (pasien yang dokter-nya set `planning=RAWAT_INAP`), aksi pilih bangsal+bed kosong → set kelas, DPJP, tgl masuk → bed jadi terisi.
- **C. Detail Pasien Rawat Inap (Lembar Pasien)** — per-pasien aktif: identitas/penjamin/kelas/DPJP/lama rawat + sub-bagian **Visite Dokter (CPPT/SOAP harian)** · **Asuhan Keperawatan/Observasi (TTV harian)** · **Tindakan & Order Penunjang** · **Resep & Pemberian Obat (depo bangsal)**. Reuse pola CPPT/DoctorExamination & NurseAssessment.
- **D. Rincian Biaya Berjalan (Running Bill)** — akumulasi real-time dari `inpatient_charges`: kamar/hari × lama rawat + visite + tindakan + obat + penunjang.
- **E. Transfer / Pindah Kamar** — pindah bed/kelas/bangsal; bed lama dilepas, baru terisi, histori `bed_assignments` (untuk billing kelas per periode).
- **F. Pemulangan (Discharge)** — jenis pulang (sehat/rujuk/APS/meninggal) + resume pulang + obat pulang → hitung LOS → generate room charge → bed cleaning → lanjut Kasir lalu Farmasi.
- **G. (Setup) Master Bangsal & Bed + Tarif Kamar** — bangsal/bed dikelola **di Profil Klinik**; tarif kamar per-kelas per-penjamin di Tarif & Paket.

---

## Temuan kunci dari eksplorasi (manfaatkan — sudah terverifikasi)

1. `KasirService::consolidateBilling` **sudah builder-pipeline** (`array_merge` baris 164-173). Room charge = **tambah builder**, bukan refactor besar.
2. `getPrice($type,$id,$guarantorType,$insurerId)` (`KasirService.php:458`, match baris 464) dengan fallback insurer UMUM. Tambah `'room'` di sini.
3. **Inventory per-lokasi sudah ada**: `InventoryStock::LOCATIONS = [INVENTORI, BEDAH, FARMASI]`, kolom `location string(20)`. Depo RANAP = tambah konstanta `LOC_RANAP` (tanpa migrasi struktur), reuse alur Request Unit.
4. `doctor_examinations.planning` = `string(50)` → tambah nilai `RAWAT_INAP` tanpa migrasi enum (hook poli→ranap).
5. **BPJS ref methods sudah ada tapi belum dipanggil**: `BpjsVClaimService::updateTglPulang()` (baris 188), `refKelasRawat`/`refRuangRawat`/`refCaraKeluar`/`refPascaPulang` (439-443). Tinggal dipanggil.
6. **Titik regresi tertinggi**: `QueueService::resolveNextStation` match statement (`QueueService.php:424-437`). Dan guard "1 invoice" (`KasirService.php:157`), guard "1 visit aktif/pasien" (`AdmisiService.php:581-595`).
7. Belum ada tabel wards/beds/triage/inpatient sama sekali.

---

## Fase 1 — Data layer (additive, no behavior change) · Risiko: RENDAH

Migrasi prefix `2026_06_05_*` (setelah migrasi terakhir `2026_06_01_*`). Semua UUID (`HasUuids`, `foreignUuid`). **JANGAN edit migrasi lama.**

### Migrasi & model
- **`..._000001_add_inpatient_columns_to_visits_table`** — kolom baru di `visits` (semua nullable/default → row lama otomatis benar):
  `jenis_pelayanan` string(10) default `'RAJAL'` (RAJAL|IGD|RANAP), `kelas_rawat` string(5) null, `admission_at`/`discharge_at` timestamp null, `discharge_type` string(20) null (PULANG_SEHAT|RUJUK|APS|MENINGGAL), `discharge_summary` text null, `triase_level` string(5) null, `triase_color` string(10) null (MERAH|KUNING|HIJAU|HITAM), `igd_arrival_at` timestamp null, `igd_disposition` string(20) null (PULANG|RANAP|RUJUK|MENINGGAL), `ranap_ward_id`/`ranap_bed_id`/`dpjp_employee_id` uuid null (cache denormalized, tanpa constrained — pola `bpjs_referral_in_id`). Index: `jenis_pelayanan`, `triase_level`, `(jenis_pelayanan, current_station)`.
- **`..._000002_create_wards_table`** (Bangsal) — `code`(unique), `name`, `kelas_rawat`, `type`(BANGSAL|ICU|ISOLASI|HCU), `bpjs_kelas_code`, `bpjs_ruang_code`, `gender_policy`, `is_active`, softDeletes.
- **`..._000003_create_beds_table`** — `ward_id`(FK cascade), `code`, `label`, `kelas_rawat`(override), `status` default `AVAILABLE` (AVAILABLE|OCCUPIED|CLEANING|MAINTENANCE|RESERVED), `is_active`, softDeletes. `unique(ward_id, code)`, index `status`.
- **`..._000004_create_room_tariffs_table`** (mirror `procedure_tariffs`) — `ward_class_id` string(5) (KELAS sebagai key), `insurer_id`(FK cascade), `price` decimal(14,2), `is_active`. `unique(ward_class_id, insurer_id)`.
- **`..._000005_create_bed_assignments_table`** (histori pindah kamar) — `visit_id`(FK cascade), `bed_id`(FK restrict), `ward_id`, `kelas_rawat`(snapshot), `assigned_at`, `released_at`(null=aktif), `assigned_by_id`, `reason`(ADMISSION|TRANSFER|UPGRADE_KELAS). Index `visit_id`,`bed_id`,`released_at`.
- **`..._000006_create_inpatient_charges_table`** (running daily) — `visit_id`(FK cascade), `charge_date`, `charge_type`(ROOM|VISITE|TINDAKAN|OBAT|BHP|PENUNJANG|LAINNYA), `reference_type`/`reference_id`, `description`, `quantity`, `unit_price`, `total_price`, `is_billed` bool default false, `created_by_id`. Index `(visit_id,charge_date)`, `(visit_id,is_billed)`.
- **`..._000007_create_igd_triage_records_table`** — `visit_id`(FK cascade), `triage_level`, `triage_color`, `chief_complaint`, vital signs (pola `nurse_assessments`), `gcs_e/v/m`, `triaged_by_id`, `triaged_at`, `disposition`, softDeletes. *(Tabel disiapkan sekarang; belum dipakai sampai IGD diaktifkan — menghindari migrasi ulang.)*

### Edit model existing
- `Visit.php` — tambah kolom baru ke `$fillable` + `$casts` (datetime untuk `*_at`); relasi `wards/beds/bedAssignments()/inpatientCharges()/igdTriageRecord()`.
- `InventoryStock.php` — tambah `LOC_RANAP = 'RANAP'` ke `LOCATIONS` (tanpa migrasi).

### Model baru
`Ward` (hasMany beds), `Bed` (belongsTo ward + status const), `BedAssignment`, `InpatientCharge`, `IgdTriageRecord`, `RoomTariff` — mirror pola `DoctorSchedule`/`SurgerySchedule`.

> **Catatan master bed di Profil Klinik:** `wards`/`beds` TETAP tabel (untuk status occupancy real-time). UI pengelolaannya diletakkan di halaman Profil Klinik (Fase 5), bukan menu master terpisah — form profil jadi editor CRUD ke tabel ini. JANGAN simpan bed sebagai JSON di `clinic_profiles` (status real-time mustahil dengan JSON).

**Verifikasi:** `php artisan migrate` lalu `migrate:rollback` bersih; row `visits` lama default `RAJAL`; seed contoh Ward/Bed via Tinker.

---

## Fase 2 — Queue & alur RANAP · Risiko: TERTINGGI (regresi rawat jalan)

### `Queue.php`
- Const baru (RANAP): `STATION_RANAP='RANAP'`. Tambah ke `STATIONS[]`. `PREFIX_MAP`: RANAP→`RI`.
- Const IGD (`STATION_IGD_TRIASE`/`STATION_IGD_DOKTER`, prefix `IGT`/`IG`) — **boleh ditambahkan sekarang sebagai konstanta saja** (tak dipakai sampai IGD aktif) atau ditunda. Tidak ada efek perilaku.
- **`..._000009_add_priority_to_queues_table`** — kolom `priority` smallint default 0 (siapkan untuk triase IGD nanti; RANAP & RAJAL = 0, urutan tak berubah).

### `QueueService.php` — refactor resolveNextStation (mitigasi regresi)
Pecah by `jenis_pelayanan` SEBELUM match station; **body match RAJAL existing dipindah PERSIS** ke `resolveNextRajal()` (zero-diff copy-paste dari baris 426-436):
```php
return match ($visit->jenis_pelayanan ?? 'RAJAL') {
    'RANAP' => $this->resolveNextRanap($visit, $fromStation),
    // 'IGD' => $this->resolveNextIgd(...)  // diaktifkan di fase IGD (akhir)
    default => $this->resolveNextRajal($visit, $fromStation),
};
```
- **`resolveNextRanap`** (long-lived): `RANAP→KASIR` (gate `discharge_at` terisi); `PENUNJANG→RANAP` (NO_OP jika baris RANAP masih hidup); `KASIR→nextAfterKasir` (reuse); `FARMASI→END_OF_FLOW`.
- Sort tetap `queue_sequence` untuk semua station (priority belum dipakai sampai IGD aktif).

### Model station RANAP (long-lived, BUKAN sequential)
1 baris `queues` station=RANAP status=IN_PROGRESS bertahan berhari-hari = "kartu pasien di papan bangsal". Visite/tindakan/obat = sub-aktivitas yang menulis `inpatient_charges`, **bukan** queue baru (tidak panggil `advanceFromStation`).

### Service baru (thin-wrapper → QueueService, reuse enqueue/advanceFromStation)
- `RanapService`: `admit` (assign bed → `bed_assignments` + `beds.status=OCCUPIED` + `visits.ranap_bed_id` + `admission_at` + `jenis_pelayanan='RANAP'` + enqueue RANAP IN_PROGRESS; sumber: poli planning RAWAT_INAP / elektif), `transferBed`, `addVisite`, `addCharge`, `discharge` (set `discharge_at`/type → advance RANAP→KASIR; release bed → CLEANING).
- Controllers thin: `RanapController`, `WardController`.
- `IgdService`/`IgdController` — **ditunda** ke fase IGD.

### Hook poli → RANAP
Dokter set `planning='RAWAT_INAP'` (string, tanpa migrasi). `nextAfterDokter` (`QueueService.php:484`): jika RAWAT_INAP → set `current_station='MENUNGGU_RANAP'` + sentinel (tutup queue dokter tanpa enqueue otomatis). Petugas ranap pilih bed via `RanapService::admit`. Papan "Menunggu Kamar" mencegah pasien nyangkut.

### Guard "1 visit aktif" (`AdmisiService.php:581`)
Longgarkan by `jenis_pelayanan`: RANAP (long-lived) tidak terblok oleh guard ini (pasien boleh punya visit RANAP aktif + visit RAJAL kontrol terpisah bila perlu).

**Verifikasi:** REGRESI RAJAL full `A→TR→D→K→F→SELESAI` identik DULU; lalu poli set RAWAT_INAP → "Menunggu Kamar" → admit (cek `beds` OCCUPIED, queue RANAP IN_PROGRESS) → discharge → KASIR.

---

## Fase 3 — Billing rawat inap (`KasirService`) · Risiko: SEDANG

- Guard "1 invoice" (baris 157) tetap: RANAP = 1 invoice FINAL saat discharge. Pastikan **tidak ada auto-consolidate dini** untuk RANAP (consolidate hanya saat masuk KASIR post-discharge).
- **Builder baru** ke pipeline `array_merge` (baris 164-173), masing-masing return `[]` jika `jenis_pelayanan!=='RANAP'` (zero-diff): `buildRoomChargeLines`, `buildVisiteLines`, `buildInpatientMiscLines`. Sumber kebenaran = `inpatient_charges where is_billed=false`; set `is_billed=true` dalam transaksi consolidate.
- **Room charge digenerate saat discharge** (`RanapService::discharge`): `LOS = max(1, jumlah malam admission_at..discharge_at)`; buat `inpatient_charges` type=ROOM × LOS dengan harga `getPrice('room', $visit->kelas_rawat, $visit->guarantor_type, $visit->insurer_id)`.
- **`getPrice` tambah type** (match baris 464): `'room' => ['room_tariffs', 'ward_class_id']`. Resolve insurer + fallback UMUM jalan otomatis.
- **Penomoran invoice 2 tipe** (`generateInvoiceNumber` baris 1254): sisipkan segmen jenis dari `visit.jenis_pelayanan` → `INV-RJ/{code}/{Y}/{m}/{seq}`, `INV-RI/...`, `INV-IGD/...`. **Counter terpisah per tipe**: hitung `$lastSeq` di-scope ke prefix tipe yang sama (mis. `where('invoice_number','ilike',"INV-RI/%")` dalam bulan berjalan), bukan `count()` global. Kwitansi RI tampilkan blok kamar/LOS/kelas/tgl masuk-keluar di template cetak (conditional `jenis_pelayanan==='RANAP'`).

**Verifikasi:** discharge RANAP LOS=3 kelas 2 UMUM → invoice 3×room + visite + items, total benar, `is_billed` set; nomor `INV-RI/...` dengan counter sendiri; alur RAJAL tak berubah (tetap `INV-RJ/...`).

---

## Fase 4 — BPJS & Satu Sehat (conditional by jenis_pelayanan) · Risiko: SEDANG

- **SEP** (`AdmisiService::bpjsGenerateSep` baris 1256): `jnsPelayanan` conditional ('1' RANAP / '2' RAJAL/IGD); persist `klsRawat` ke `visits.kelas_rawat` saat admit & baca darinya; `tglMasuk` dari `admission_at`; map `ward.bpjs_kelas_code/bpjs_ruang_code`. **IGD→RANAP = SEP inap baru** (jnsPelayanan='1'), SEP IGD tetap.
- **updateTglPulang** (sudah ada `BpjsVClaimService:188`): `RanapService::discharge` → jika BPJS + ada `no_sep` → panggil dengan `discharge_at`, try/catch non-blocking (pola `maybeSubmitLpkBpjs`).
- **INA-CBGs/LUPIS** (`KlaimService::generateLupis` baris 220-233): `jnsPelayanan` dari `claim.visit.jenis_pelayanan`; tambah `tglMasuk`(admission_at), `tglPulang`(discharge_at, bukan updated_at), `los`, `kelasRawat`(visit.kelas_rawat), `caraKeluar/kondisiPulang`(dari discharge_type). `prepareClaimData` (baris 72) load kolom inap.
- **Satu Sehat** (`SatusehatService::buildEncounterPayload` baris 912): class conditional RANAP→IMP, IGD→EMER, else AMB. `period.start`=admission_at, `period.end`=discharge_at??now() untuk RANAP. Location skip (MVP).

**Verifikasi:** `previewPayloads` visit RANAP → class IMP + period inap; LUPIS RANAP → jnsPelayanan '1' + los/tgl; via log tables tanpa submit live.

---

## Fase 5 — RBAC + Frontend (RANAP) · Risiko: RENDAH-SEDANG

- **PermissionSeeder** (`$modules` baris 18): tambah `'rawat_inap'` (+`'igd'` boleh ikut didaftarkan agar matrix siap, walau UI-nya nanti). Master bangsal pakai permission `pengaturan`/profil existing (karena dikelola di Profil Klinik), tidak perlu modul baru. **RolePermissionSeeder** matrix: dokter[rawat_inap RW], perawat[rawat_inap RW], admisi[rawat_inap RW], kasir[rawat_inap R], farmasi[rawat_inap RW], manajemen[R]. Idempotent → re-seed.
- **Routes** (`routes/api.php`, grup auth:api): `Route::prefix('rawat-inap')` (RanapController), `master/bangsal`+`master/bed` (WardController), `tarif-paket/tarif/room` (reuse pola TarifPaket).
- **Frontend**: view `rawat-inap/RawatInapView.vue` (papan bed/bangsal + papan "Menunggu Kamar" + detail pasien: visite/keperawatan/tindakan/obat + running bill + transfer + discharge). Store `rawatInapStore.js`. `api.js`: `rawatInapApi`, `bangsalApi`, `roomTarifApi`. Router `/rawat-inap` meta.permission `rawat_inap.read`. Sidebar `AppSidebar.vue`: RouterLink `v-if="auth.can('rawat_inap.read')"` di section Klinis.
- **Master Bangsal & Bed di Profil Klinik**: tambah section/tab "Bangsal & Tempat Tidur" di view Profil Klinik existing (MasterData). CRUD bangsal (nama/kelas/tipe) + bed (nomor/kelas/status) persist ke `master/bangsal` & `master/bed` (tabel `wards`/`beds`). Ringkasan occupancy (X terisi / Y total). Tarif kamar per-kelas di `tarif-paket/tarif/room`.
- **AntreanTV**: RANAP = papan bangsal → **filter agar TIDAK muncul** di TV ruang tunggu (RANAP bukan antrean panggil).

**Verifikasi:** per-role sidebar + router guard; CRUD bangsal/bed di Profil Klinik; papan bed; e2e UI poli→RAWAT_INAP→Menunggu Kamar→admit→visite→discharge→kasir.

---

## Fase 6 — IGD (ditunda, fase akhir)
Aktifkan struktur IGD yang sudah disiapkan di Fase 1: stasiun `IGD_TRIASE`/`IGD_DOKTER` di Queue.php, cabang `resolveNextIgd` di QueueService (triase berlevel by `priority`, disposisi PULANG/RANAP/RUJUK), `IgdService`+`IgdController`+routes `igd/*`, view `IgdView.vue` (papan triase warna) + `igdStore`, permission `igd.*` aktif di sidebar/router, IGD tampil di AntreanTV (sort priority/warna), SEP IGD (jnsPelayanan='2') + Encounter EMER. IGD→RANAP = SEP inap baru.

## Fase 7 — Polish (opsional)
Events realtime (mirror `TriaseQueueUpdated`); Satu Sehat Location resource (ward/bed); depo RANAP Request Unit penuh + FEFO dispensing; papan bed di Dashboard.

---

## Risiko & mitigasi (ringkas)
1. **`resolveNextStation`** — pindah body RAJAL PERSIS ke `resolveNextRajal()`; backfill `jenis_pelayanan='RAJAL'`; test regresi RAJAL sebelum lanjut fase berikut.
2. **Guard invoice** — RANAP tetap 1 invoice saat discharge; jangan consolidate dini.
3. **Guard "1 visit aktif"** — longgarkan by jenis_pelayanan.
4. **Sort priority** — hanya station IGD; station lain tetap `queue_sequence`.
5. **`getAllActive` auto-tambah RANAP ke TV** — filter RANAP dari TV ruang tunggu.

## Reuse eksplisit
`QueueService::enqueue/advanceFromStation/panggil/lewati/broadcastQueueUpdate`; `KasirService::getPrice` + `consolidateBilling` builder pipeline; `inventory_stocks` per-lokasi (+RANAP); `insurance_verifications`; `BpjsVClaimService::updateTglPulang/refKelasRawat/refRuangRawat/refCaraKeluar/refPascaPulang` (sudah ada); pola non-blocking `maybeSubmitLpkBpjs`; `DoctorExamination.planning` (+RAWAT_INAP, no migration); pola `DoctorSchedule/SurgerySchedule` untuk Ward/Bed; `MasterDataLayout` untuk master bangsal.

## Catatan eksekusi
User prefer **kerja bertahap per fase, tunggu konfirmasi** sebelum lanjut. Kerjakan Fase 1 dulu, verifikasi, lapor, baru lanjut. Dev DB Postgres (`dbprimavision`).
