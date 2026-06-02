---
name: project-audit-bedah-bug-2026-06-02
description: "Audit bug modul Bedah 2026-06-02 (penelusuran saja, BELUM difix, BELUM commit). 2 KRITIS terverifikasi kode: IOL hilang dari invoice pasien pre-op Admisi/RANAP + visit_id NOT NULL crash 500. +HIGH RBAC/race/kirimResep palsu/isPhaco hardcode/input tak persist."
metadata:
  type: project
---

Audit bug modul Bedah (BedahView.vue FE + BedahService/KasirService/BedahController BE) tanggal **2026-06-02**. **PENELUSURAN SAJA — belum ada fix, belum commit.** User minta jangan commit & jangan git reset. Lanjutan/komplemen [[bedah-view]] (yg mencatat field klinis BELUM persist sbg SISA out-of-scope F1-F6).

## 🔴 KRITIS (diverifikasi langsung di kode)

**B1 — IOL HILANG dari invoice (pola sama bug IGD/obat-pulang).** [KasirService.php:445](backend/app/Services/KasirService.php#L445) `buildIolLines` resolve record IOL HANYA via `$visit->doctorExamination?->surgerySchedule?->surgeryRecord`. Tapi alur **Admisi pre-op walk-in** & **RANAP→Bedah Fase 8C** set `visits.surgery_schedule_id` LANGSUNG tanpa `doctorExamination` → IOL (item termahal, jutaan) tak ditagih. BHP (`buildBhpLines` baca `$visit->surgeryRequests`) & Equipment (`$visit->equipmentUsages`) AMAN. Fix usul: `($visit->surgerySchedule ?? $visit->doctorExamination?->surgerySchedule)?->surgeryRecord` atau `SurgeryRecord::where('visit_id',$visit->id)`. BedahService::getPatientQueue:57 SUDAH pakai fallback benar; KasirService belum.

**B2 — `visit_id` NOT NULL → crash 500 saat Mulai Operasi.** [BedahService.php:251](backend/app/Services/BedahService.php#L251) `startOperation` bisa hasilkan `$visitId=null` (jadwal manual tanpa visit & tanpa supply-request) → `SurgeryRecord::create(['visit_id'=>null])`. Migrasi `2026_05_06_000001_create_surgery_records_table.php:14` = NOT NULL (diverifikasi: tak ada migrasi nullable) → SQLSTATE 23502 → 500 mentah bukan 422.

## 🟠 HIGH
- **B3 RBAC:** seluruh route group `/bedah` cuma `permission:bedah.read` ([routes/api.php:413](backend/routes/api.php#L413)); semua write (mulai/selesai/finalize/adjustBhp/storeJadwal/delete) tanpa `bedah.write` (permission itu ADA, dipakai di alat-medis). User read-only bisa jalankan operasi & ubah tagihan.
- **B4 Race double `startOperation` → 23505/500:** status SCHEDULED dicek tanpa `lockForUpdate`; `surgery_schedule_id` unik. Fix: lockForUpdate / firstOrNew (pola prepareAnesthesia). double completeOperation & finalize SUDAH aman.
- **F1 `kirimResep` PALSU:** [BedahView.vue:566](arumed-frontend/src/views/BedahView.vue#L566) cuma set `resepSent=true`+toast, TANPA API. Obat pasca-bedah tak pernah sampai Farmasi. Risiko keselamatan. (`bedahApi` memang tak punya endpoint resep-pasca.)
- **F2 `isPhaco` hardcode `false`** [BedahView.vue:54](arumed-frontend/src/views/BedahView.vue#L54): seluruh blok IOL (rencana/dipasang/laporan) tak pernah render. Di RS mata = fungsi inti mati, tak ada jalur input lot/power IOL.
- **F3 input tab tak persist:** satu-satunya payload `buildRecordPayload` ([:487](arumed-frontend/src/views/BedahView.vue#L487)) cuma notes+komplikasi+disposisi. Checklist WHO, Tim Bedah, BHP manual, anestesi, instruksi pasca, diagnosaPasca = local state → hilang reload/poll. Laporan operasi tersimpan tak lengkap (tim & implant wajib regulasi).
- **F4 polling 15s reset draft pasien non-terpilih:** `loadQueue` ([:88](arumed-frontend/src/views/BedahView.vue#L88)) hanya re-link `selP`; pasien lain di-transformQueueItem ulang. Diperparah `callPt` panggil loadQueue tiap "Panggil".

## 🟡 MEDIUM
- **B5 over-billing BHP parsial:** `terimaRequest` seed `used_qty=quantity` semua baris; `adjustBhpUsage` ([BedahService.php:760](backend/app/Services/BedahService.php#L760)) hanya update bhp_item_id yg disebut → item di-drop dari payload tetap ditagih.
- **B6 queue stuck disposisi:** `finalizeRecord` ([BedahService.php:445](backend/app/Services/BedahService.php#L445)) advance hanya `if($visitId)` & `if($bedahQueue)`; cabang RAWAT_INAP tanpa bedahQueue tak set MENUNGGU_RANAP, PULANG tanpa fallback → pasien hilang dari alur tanpa error.
- **B7 visus/IOP modal Mulai selalu kosong:** `selP.visusOD/visusOS/iopOD/iopOS` ([BedahView.vue:1437](arumed-frontend/src/views/BedahView.vue#L1437)) tak pernah di-set di transformQueueItem → verifikasi pra-bedah menyesatkan.
- **F5 `addEquipmentUsage` tanpa lock** ([BedahView.vue:284](arumed-frontend/src/views/BedahView.vue#L284)) → double-submit → billing dobel alat.

## 🟢 LOW
- `updateRecord` `array_filter(!is_null)` ([BedahService.php:360](backend/app/Services/BedahService.php#L360)) → tak bisa toggle has_complication=false / kosongkan field.
- Tombol "Cetak Laporan" ([BedahView.vue:1331](arumed-frontend/src/views/BedahView.vue#L1331)) cuma toast.
- `sendAutoRequest` IOL nullable → request IOL "kosong" terkirim ke Farmasi.

**Prioritas usul:** B1+B2 dulu (KRITIS, fix kecil terisolasi), lalu F1/F2/F3 (klinis+keselamatan), B3 (RBAC go-live).

## KEPUTUSAN FIX (user setuju 2026-06-02, fix SEDANG dikerjakan)
- **F1 resep pasca-bedah:** WIRING PENUH + search obat master. Endpoint baru `BedahService::storePostOpPrescription(visitId, items[])` buat `Prescription` status **SUBMITTED** + `PrescriptionItem` (medication_id, quantity, dose, frequency, route, duration_days, notes, **is_bedah=true**) — pola RanapService::discharge (RanapService.php:800-841). Resep muncul di Farmasi via `QueueService::nextAfterKasir` (cek status DRAFT/SUBMITTED/DISPENSING saat KASIR selesai). FE form ganti dari teks bebas → search master.
- **F2 isPhaco:** auto-deteksi nama paket/prosedur (regex katarak/phaco/IOL/SICS) + checkbox "Pasang IOL" override manual. Wiring `iolDipasang` → `storeIolUsage` (record IOL terpasang → masuk invoice via buildIolLines yg sudah difix B1).
- **GOTCHA RBAC:** `/dokter/obat` digate `rme_dokter.read` & `/master/iol` digate `master_iol.read` — Bedah TAK punya akses. WAJIB endpoint baru `/bedah/obat` + `/bedah/iol` (gate `bedah.read`) delegasi ke `DokterService::getDaftarObat` & `MasterDataController::indexIol` agar self-contained.
- **B3 fix:** tiap route write `/bedah/*` (jadwal store/update/delete, mulai/selesai, request *, adjust-bhp, record *, finalize, iol-usage) tambah `permission:bedah.write`; GET tetap `bedah.read`.
- **B2 fix:** `startOperation` guard `$visitId` null → throw 422 "jadwal belum terhubung kunjungan" (jangan insert null ke kolom NOT NULL).

## STATUS: SEMUA DIFIX 2026-06-02 (build PASS, BELUM commit)
Dikerjakan via workflow (6 agen paralel per-file + 12 verifikator adversarial + build). Verifikasi akhir 10/12 CONFIRMED, 2 PARTIAL → keduanya + 2 bug-baru sudah ditutup manual. **Build: php -l 3/3 bersih, route:list OK (55 route), vite build ✓ 6.77s.**

**Diterapkan (semua di disk, uncommitted):**
- **B1** [KasirService.php:451] buildIolLines: `($visit->surgerySchedule ?? $visit->doctorExamination?->surgerySchedule)?->surgeryRecord` + eager-load chain di :153. IOL pre-op Admisi/RANAP kini masuk invoice.
- **B2** [BedahService.php:283] guard visitId null → 422 sebelum create.
- **B3** [routes/api.php:413-447] tiap route tulis /bedah/* + `permission:bedah.write` (20 route), GET tetap read.
- **B4** [BedahService.php:267] startOperation `SurgerySchedule::lockForUpdate()->findOrFail` dalam transaksi + re-cek status → cegah race double-start 23505.
- **B5** [BedahService.php:~820] adjustBhpUsage otoritatif: item RECEIVED tak disebut di payload → used_qty=0 (cegah over-bill). KONTRAK BARU: FE wajib kirim daftar LENGKAP (BedahView saveBhpUsage memang kirim full list).
- **B6** [BedahService.php:454-507] finalizeRecord: RAWAT_INAP set MENUNGGU_RANAP walau bedahQueue null; visitId null cuma Log::warning (tak crash); PULANG tetap advance.
- **B7** [BedahService.php:114-123] payload `preop` dari RefractionRecord (relasi Visit::refractionRecord HasOne) + **FE FIX FOLLOW-UP**: BedahView transformQueueItem baca `q.preop.*` (BUKAN `q.visit.*` — mismatch key bikin fitur mati) + syncAuthoritativeFields refresh preop tiap poll.
- **F1** [BedahService.php:926-976 storePostOpPrescription + BedahController:264 + route :444 /bedah/record/{id}/resep-pasca + BedahView kirimResep:744]. Prescription status=SUBMITTED + PrescriptionItem; muncul di Farmasi via nextAfterKasir. **is_bedah SENGAJA default FALSE** (DEVIASI kontrak: is_bedah=true di-SKIP buildObatLines → obat bawa-pulang tak tertagih = kebocoran billing). **FOLLOW-UP**: tambah field `jumlah` (quantity) di form+tabel+map (dulu hardcode 1). Endpoint master Bedah-scoped baru: GET /bedah/obat (getDaftarObat) + GET /bedah/iol (getIolItems) — krn /dokter/obat & /master/iol di luar RBAC Bedah.
- **F2** [BedahView:10 IOL_RE regex + :68 isPhaco auto + checkbox toggle manual Pra-Bedah]. Blok IOL kini tampil; tak direset poll (via F4).
- **F3** [BedahView simpanIolTerpasang→storeIolUsage + listIol master picker + guard savingIol]. **FOLLOW-UP bug-baru**: recordIolUsage [BedahService.php:859] diubah `create`→`updateOrCreate` keyed (surgery_record_id, eye_side) → cegah double-charge IOL di invoice kalau Simpan diklik 2×.
- **F4** [BedahView loadQueue + syncAuthoritativeFields]: pertahankan working-state SEMUA baris (bukan cuma selP) → draft checklist/tim/bhp/iol/obat tak hilang tiap poll 15s/callPt. Hanya row baru di-transform.
- **F5** [BedahView addEquipmentUsage guard addingEquip + **FOLLOW-UP**: tombol `:disabled="addingEquip"` + label dinamis + CSS `.bd-btn-add:disabled`].

**SISA (out-of-scope, belum):** tombol "Cetak Laporan" masih toast stub (perlu layout A4 khusus); persist checklist/tim/instruksi pasca (butuh migration); idempotensi resep pasca (FE guard via resepSent, BE belum). GOTCHA: param route literal {id} → controller method WAJIB pakai `string $id` (bukan $recordId) else null binding.
