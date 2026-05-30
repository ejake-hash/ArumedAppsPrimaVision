---
name: feature-perawat-view
description: "PerawatView.vue cleanup 2026-05-26 — fix endpoint /mulai (bukan /selesai), wire skip ke backend, gabung 4 tab jadi 1 (vital+keluhan+alergi), relax panggil gate, validation TD+KGD+keluhan wajib."
metadata: 
  node_type: memory
  type: project
  originSessionId: d653f3e1-0270-418e-815d-cdc50743e42f
---

`arumed-frontend/src/views/PerawatView.vue` direvisi 2026-05-26.

## Status-transition fix
**Bug lama:** saat user klik pasien CALLED, store panggil `perawatApi.selesai(id)` (endpoint finalize-and-advance). Backend reject 422 karena gate `asesmen.is_finalized`, error silent. Pasien stuck di CALLED, `started_at` tidak ter-set.

**Fix:** tambah `perawatApi.mulai(id)` ([api.js:113](arumed-frontend/src/services/api.js#L113)) → `PUT /perawat/antrian/{id}/mulai`. Store rename `panggilSelesai` → `_mulaiQuiet` ([perawatStore.js](arumed-frontend/src/stores/perawatStore.js#L97)) yang call endpoint mulai. CALLED → IN_PROGRESS sekarang benar.

## Skip ke backend
**Bug lama:** `skipPt()` cuma swap array lokal. Refresh page → urutan kembali normal.

**Fix:** panggil `store.lewatiAntrian(id)` → `PUT /perawat/antrian/{id}/lewati` → backend reset `queue_sequence` ke MAX+1. View refresh `fetchAntrian()` setelahnya untuk sinkron urutan.

## Tab restructure
**Before:** 4 tab (Tanda Vital / Pemeriksaan Fisik / Anamnesis / Alergi). Tab Fisik full field bohong (tanpa v-model, kolom DB tidak ada).

**After:** 1 card "Asesmen Triase" berisi (urut dari atas):
1. Tanda Vital (TD/Nadi/SpO2/Suhu/Resp/BB/TB/BMI/KGD/Pain)
2. Keluhan & Anamnesis (keluhan_utama wajib + RPS + assessment_notes — semuanya ter-bind ke `form` dan masuk payload)
3. Skrining Alergi (`form.allergies` + display `patient.allergy_notes` dari RM)
4. Tombol Simpan

`activeTab` state, tabs UI, dan 3 tab section lama dihapus. Tombol "Cetak Gelang" dibuang.

**Field baru ter-bind:** `assessment_notes` (sudah ada kolom di DB sejak migration awal, sebelumnya tidak dipakai).

## Validation simpan
**Wajib:** TD sistolik + TD diastolik + KGD + keluhan utama.
**Optional:** nadi, suhu, respirasi, SpO2, BB, TB, pain, RPS, notes, alergi.

Backend `PerawatController::storeAsesmen` validation diubah dari `required` → `nullable` untuk nadi/suhu/respirasi, `nullable` → `required` untuk kgd ([PerawatController.php:81-100](backend/app/Http/Controllers/PerawatController.php#L81)). DB kolom sudah nullable semua → tidak perlu migrasi.

**Why:** sebelumnya 5 field wajib (TD sistol/diastol, nadi, suhu, respirasi) memaksa perawat input lengkap meski hanya TD yang biasanya diukur cepat di triase.

## Panggil ulang gate
**Bug lama:** `PerawatService::panggilAntrian` reject `Antrian sudah dipanggil atau selesai` kalau status ≠ WAITING. User panggil ulang 2x → muncul error misleading (notif itu seharusnya muncul saat selesai → tab Selesai).

**Fix:** accept WAITING/CALLED/IN_PROGRESS. WAITING/CALLED → CALLED (update `called_at`). IN_PROGRESS tetap IN_PROGRESS (re-call saat pasien sedang diperiksa). Reject hanya kalau COMPLETED/CANCELLED dengan pesan "Antrian sudah selesai atau dibatalkan". Konsisten dgn pola di AdmisiView Section 5.1b.

## Yang tetap dipertahankan
- Reverb WS `triase-queue` + fallback polling 30s
- Gate `asesmen.is_finalized` di `selesaiAntrian` backend (sebelum advance ke DOKTER)
- Read-only UI saat `is_finalized` ([:disabled="store.isFinalized"])
- Rekam Medis modal pakai endpoint `perawatApi.rekamMedis(patientId)` & `perawatApi.dokumen(docId)`
- Vital history panel (collapsible riwayat kunjungan dengan TTV)

Related: [[feature-admisi-view]] (pola panggil ulang, AdmisiView jadi reference implementation untuk relax gate).

## CPPT (Catatan Perkembangan Pasien Terintegrasi) — 2026-05-26 lanjutan

**Tabel baru `nurse_cppt_entries`** (migration `2026_05_26_000020`):
- `visit_id` FK, `nurse_assessment_id` FK nullable (link ke asesmen awal)
- TTV opsional (td_sistol/td_diastol/nadi/suhu/respirasi/spo2/kgd/pain_scale)
- `notes` text WAJIB (di service level)
- Audit: `created_by_id`, `created_at`, `edited_at`, `edited_by_id` (soft-edit, versi lama TIDAK disimpan — jejak siapa+kapan saja)
- Append-only timeline per visit. Tidak ada DELETE. Index `(visit_id, created_at)`.

**Why pisahkan dari `nurse_assessments` 1:1**: gate TR→DOKTER (Section 11.3) butuh `NurseAssessment.is_finalized` jelas — kalau drop unique constraint dan jadi 1:N, ambigu "yang mana yang dianggap final". Tabel terpisah jaga gate intact + semantik bersih (asesmen awal = baseline, CPPT = observasi lanjutan).

**Endpoint backend** (skill Section 5.3 perlu update):
- `GET /perawat/cppt/visit/{visitId}` — timeline descending
- `POST /perawat/cppt` — body `{visit_id, td_sistol?, ..., notes (wajib)}`. Gate: visit aktif (`current_station ≠ SELESAI`) + asesmen awal harus ada.
- `PUT /perawat/cppt/{id}` — edit existing, set `edited_at` + `edited_by_id`. Gate: visit aktif.

**Frontend state** di `perawatStore`:
- `cpptEntries`, `cpptLoading`, `cpptSaving`
- Actions: `loadCpptTimeline(visitId)`, `addCpptEntry(payload)`, `updateCpptEntry(id, payload)`
- Auto-load saat `pickPatient` (paralel dgn vitalHistory)

**Frontend view UX:**
- **`cpptMode`** state: `'idle' | 'new' | 'edit:<id>'`. Idle = form locked kalau finalized. New/edit = form unlock untuk TTV input + notes wajib.
- **`formLocked`** computed = `isFinalized && !isCpptMode` (sebelumnya pakai langsung `isFinalized` di 16 tempat — semua diganti).
- **Tombol "Update Asesmen"** muncul di action-row card ketika `!isCpptMode && isFinalized`. Klik → `startNewCppt()` → `cpptMode='new'`, banner kuning "Mode Tambah CPPT" muncul, form unlock untuk edit TTV dan notes.
- **Tombol "Edit"** di setiap CPPT entry di timeline. Klik → `startEditCppt(entry)` → form hydrate dari entry itu + `cpptMode='edit:<id>'`.
- **Tombol "Batal"** untuk cancel CPPT mode (revert form ke asesmen awal).
- **Validasi save CPPT**: hanya `notes` wajib. TTV semua optional (perawat bisa cuma catat TD baru + notes).

**Card kanan "CPPT / SOAP"** (sebelumnya "Catatan SOAP"):
- Header section "Asesmen Awal Triase" — baseline read-only (S: keluhan+RPS+alergi, O: TTV+notes), badge "Final" kalau is_finalized.
- Divider "CPPT Lanjutan (N)"
- Timeline descending: setiap entry tampil timestamp + author, TTV diff, notes, badge "Diedit" kalau soft-edited (tooltip waktu+nama), tombol Edit (disabled kalau visit selesai atau sedang dalam CPPT mode lain).
- Empty state: "Belum ada CPPT lanjutan. Klik Update Asesmen..."

**Skenario klinis utama**: pasien mau operasi dengan TD/KGD tinggi → dokter instruksi cek ulang → perawat klik "Update Asesmen" → input TD baru + notes "Cek ulang pre-op, TD 150/90 setelah istirahat 30 menit" → simpan. Kalau salah ketik, klik Edit di entry tersebut → koreksi → entry ter-update + jejak "Diedit" muncul.

## Bug fix lanjutan 2026-05-26 (sesi compact)

**Bug A — tombol "Update Asesmen" tidak muncul setelah finalize.**
Root cause: `pickPatient()` punya early return untuk status COMPLETED yang skip `loadAsesmen()` + `loadCpptTimeline()`. Akibatnya `isFinalized` stay false, tombol di-hide.
Fix: hapus early return COMPLETED — semua status sekarang load asesmen + CPPT (read-only kalau visit selesai).

**Bug B — action-row v-if chain compiler quirk.**
Sebelumnya pakai `v-if / v-else-if / v-else` dengan `<template>` di tengah → Vue 3.5 compiler kadang gagal evaluate cabang Update Asesmen. Restruktur jadi **3 v-if independen** (tidak rantai): (1) CPPT-mode buttons (`isCpptMode`), (2) Save Asesmen (`!isCpptMode && !isFinalized`), (3) Update Asesmen (`!isCpptMode && isFinalized`). Lebih predictable, tidak ada interaksi `<template v-else-if>`.

**Bug C — nadi/suhu/respirasi masih wajib di finalize gate.**
Frontend validation sudah relax (cuma TD+KGD+keluhan), tapi `PerawatService::finalizeAssessment` punya gate kedua di service-level yang re-check `nadi`, `suhu`, `respirasi` non-null sebelum advance ke DOKTER. Akibatnya simpan sukses tapi "Kirim ke Dokter" gagal 422.
Fix [PerawatService.php:251](backend/app/Services/PerawatService.php#L251): gate sekarang hanya require `['td_sistol', 'td_diastol', 'kgd', 'chief_complaint']`. Bonus: `storeAssessment` tambah `?? null` defensif untuk nadi/suhu/respirasi.

Related: [[feedback-vue-vshow-unmount-bug]] (pola Vue 3.5 compiler edge cases).

