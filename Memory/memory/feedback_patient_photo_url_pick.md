---
name: feedback-patient-photo-url-pick
description: "Saat formatter response mem-pick kolom `patient` secara manual (array literal), wajib sertakan `photo_url` — jika tidak, `PatientAvatar.vue` jatuh ke inisial padahal foto sudah tersimpan."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 50407ad8-11f0-4530-b348-22c3dfb71758
---

Setiap response yang mengirim objek `patient` ke frontend HARUS menyertakan field `photo_url`. Komponen `PatientAvatar.vue` render `<img>` saat `props.src` ada, fallback ke inisial saat null/broken.

**Why:** `Patient` model punya `protected $appends = ['photo_url']` (accessor dari `photo_path` via `Storage::disk('public')->url(...)`), jadi serialisasi Eloquent otomatis ikut menyertakan field ini. TAPI banyak service mem-pick kolom secara manual via array literal (`['id' => $patient->id, 'name' => ..., ...]`) — pattern ini meng-bypass `$appends`, jadi `photo_url` hilang dari payload meskipun foto tersimpan di disk.

Insiden 2026-05-28: PerawatView avatar tetap tampil inisial karena `PerawatService::formatQueueItem()` di [PerawatService.php:610-621](backend/app/Services/PerawatService.php) tidak include `photo_url`. Fix: tambah `'photo_url' => $patient->photo_url` ke array.

**How to apply:**
- Saat menulis/review service yang men-shape array `patient` manual, cek apakah `photo_url` sudah ada. Kalau belum, tambahkan.
- Daftar service yang berpotensi terkena bug ini: PerawatService, DokterService, RefraksiService, KasirService, BedahService, PenunjangService, FarmasiService, RekamMedisService, AdmisiService — review semua `formatQueueItem`/`transformPatient`/sejenisnya.
- Alternatif aman: `$patient->only([...])` atau langsung kirim model + `makeHidden(['allergy_notes'])` — keduanya menghormati `$appends`.
- Saat user lapor "avatar/foto pasien tidak muncul", cek dulu payload backend (Network tab) sebelum nyalahkan komponen frontend.

Terkait: [[perawat-view]], [[arumed-project]]
