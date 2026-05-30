---
name: feature-refraksionis-view
description: "RefraksionisView.vue revisi 2026-05-26 — wire skipPt ke endpoint backend, payload lengkap (autoref+keratometri+kacamata lama+ADD+IOP+prescription), prefill dari record existing, save draft per step, button-press feedback, filter sekunder gabung Umum & Asuransi"
metadata: 
  node_type: memory
  type: project
  originSessionId: b6231da8-00e2-4ebc-810a-77442837afde
---

# Refraksionis View — revisi 2026-05-26

Revisi besar `arumed-frontend/src/views/RefraksionisView.vue` + store + backend
untuk menutup lima gap: skip lokal-only, payload tak lengkap, no prefill,
saveStep tanpa persist, `v-if` pemeriksaanLoading. Plus filter sekunder
disederhanakan.

**Why:** form refraksionis sebelumnya cuma persist subset field saat finalize
— keratometri, kacamata lama, ADD presbyopia, dan seluruh resep kacamata
(jenis lensa/material/coating) hilang. Skip juga lokal-array swap (tidak
panggil API), jadi reload reset urutan. UX masih bisa diperbaiki dengan
button-press feedback supaya user tahu klik sudah didaftar.

**How to apply:** rujukan saat menyentuh lagi refraksionis flow, atau saat
membangun station lain (perawat/dokter/penunjang) yang punya pola
"queue list + multi-tab wizard + finalize" — pattern di sini reusable.

## Backend tambahan

- `RefraksiService::lewatiAntrian(queueId)` — reset `queue_sequence` ke
  MAX+1 (mirror `PerawatService::lewatiAntrian`).
- `RefraksiController::lewatiAntrian` + route `PUT /refraksi/antrian/{id}/lewati`.
- (Note: ada juga endpoint generic `PUT /antrian/{id}/lewati` di
  `QueueController` — untuk station ini sengaja pakai per-station agar
  konsisten dengan pattern Perawat & ada hook untuk broadcast nantinya.)

## Frontend store (`refraksiStore.js`)

- Tambah state `prescription` (RefractionPrescription, relasi dari
  `pemeriksaan.prescription`).
- `lewatiAntrian(queueId)` — call `refraksiApi.lewati`, lalu reorder baris
  ke akhir array lokal supaya UI segera reflect server.
- `pickPatient` sekarang juga isi `prescription` dari payload
  `showPemeriksaan` (backend sudah `with('prescription')`).
- `saveResep(payload)` — auto create/update via `refraction_record_id`
  (WAJIB pemeriksaan sudah tersimpan dulu).
- `clearSelected` reset prescription juga.

## View — perubahan utama

### 1. Payload lengkap (`buildPemeriksaanPayload` / `buildResepPayload`)
- Autoref OD/OS + Keratometri (k_od_1/2 → `keratometri1_od/2_od`,
  k_add_* di UI dipakai sebagai `keratometri_axis_*`).
- Refraksi subjektif OD/OS + `add_power_od/os` (ADD presbyopia tab Refine).
- Kacamata lama lengkap (S/C/Ax/ADD untuk OD & OS).
- IOP OD/OS + method + Visus (awal/akhir/pinhole) + PD + clinical_notes.
- Prescription: `rx_od_sph/cyl/axis/add` (sph/cyl/axis dari refine,
  add dari `rxFinal.od_add/os_add`), `glasses_type`, `lens_material`,
  `coating`, `notes`.

### 2. Prefill (`fillFormFromRecord(rec, presc)`)
- Dipanggil di `pickPt` setelah `store.pickPatient`.
- Mapping balik dari decimal-string backend → string v-model. Tidak
  pakai `Number()` karena input text dengan tanda minus / titik desimal
  butuh tetap string sampai user edit ulang.
- Reset form kalau record null (`resetForm()`).

### 3. saveStep persist ke backend
- Setiap tombol "Simpan & Lanjut" + "Simpan Draft" call
  `store.savePemeriksaan(buildPemeriksaanPayload())` lalu pindah tab.
- Guard `isFinalized` → skip backend call (record sudah dikunci, backend
  tolak update — fail fast tanpa request).
- Semua tombol `:disabled="store.saving || store.isFinalized"`.

### 4. sendToDoctor urutan: save record → save resep → finalize
- Resep gagal ≠ blocker → toast warning + tetap finalize record supaya
  antrean tidak macet. User bisa update resep lewat path lain nanti.
- Setelah finalize: `clearSelected`, `resetForm`, switch ke tab "Selesai".

### 5. pemeriksaanLoading sebagai disabled, bukan v-if
- Card "Kirim ke Dokter" SELALU tampil; tombol `:disabled` saat
  `store.finalizing || store.saving || store.pemeriksaanLoading || store.isFinalized`.
- Sub-text adaptif: "Memuat data refraksi…" / "Sudah dikunci…" /
  "Isi refraksi dulu" / "Klik untuk kunci+kirim".

### 6. Filter sekunder
- Tiga tombol: **Semua / BPJS / Umum & Asuransi** (gabung `umum`+`asn`
  jadi satu chip; nilai filter `'UmumAsn'`).

### 7. Button-press feedback (Panggil & Lewati)
- `pendingCallIds` (sudah ada) + `pendingSkipIds` (baru) → per-row pending state.
- Tombol `:disabled` saat pending, ikon di-swap ke spinner mini `.q-sp`,
  label berubah ("Memanggil…" / "Melewati…"), `aria-busy` + `aria-pressed`.
- CSS efek depress: `:active:not(:disabled)` + `.is-pressed` →
  `translateY(1px) scale(0.97)` + `inset box-shadow`. Tier visual:
  hover (bg swap) → active (depress instan) → pending (terkunci sampai
  response API balik).

## Bugfix 2026-05-30 — refraksi mulai/lewati TIDAK broadcast TV (delegasi ke QueueService)
Audit antrean Perawat & Refraksionis: `RefraksiService::mulaiAntrian` & `lewatiAntrian` dulu update `Queue` MANUAL (`$queue->update(...)`) → tak pernah panggil `broadcastQueueUpdate` → **TV station R + dashboard tidak update real-time** saat refraksionis klik Mulai/Lewati (cuma ketahuan saat polling 30s klien lain). `panggilAntrian` sudah benar (delegasi). Ini gap yang sama yg sudah di-fix utk PerawatService 2026-05-26 (lihat [[feature-antrean-tv]]) tapi RefraksiService TIDAK ikut. **FIX: keduanya sekarang `$this->queueService->mulai()/lewati()` (guard station tetap di service refraksi, lalu delegasi).** Guard identik (mulai butuh CALLED → "Panggil pasien terlebih dahulu." 422; lewati butuh WAITING/CALLED). Verified E2E (status/seq/started_at/called_at sama persis) + smoke 35/35. Pelajaran: SEMUA station WAJIB delegasi panggil/mulai/lewati/selesai ke QueueService — lihat [[queue-advance-station-pattern]]. Audit: PerawatService ✓, KasirService ✓, RefraksiService ✓ (fix ini). Perawat & antrean lain di-cek aman.

## Files
- `backend/app/Services/RefraksiService.php` — `lewatiAntrian()`, `mulaiAntrian()` (delegasi QueueService 2026-05-30)
- `backend/app/Http/Controllers/RefraksiController.php` — `lewatiAntrian()`
- `backend/routes/api.php` — `PUT /refraksi/antrian/{id}/lewati`
- `arumed-frontend/src/services/api.js` — `refraksiApi.lewati`
- `arumed-frontend/src/stores/refraksiStore.js`
- `arumed-frontend/src/views/RefraksionisView.vue`

Lihat juga [[feature-perawat-view]] untuk pattern queue+asesmen serupa.
