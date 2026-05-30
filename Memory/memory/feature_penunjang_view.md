---
name: feature-penunjang-view
description: "PenunjangView wire-up 2026-05-28 — antrean kiri samakan PerawatView (2-baris filter), wire skip ke backend, kanan per-order workflow (Mulai/Input Hasil/Selesaikan) dengan form generic + Biometri OD/OS, upload attachment, validasi tombol global."
metadata: 
  node_type: memory
  type: project
  originSessionId: dc444724-9c62-48ef-baf9-8e2f737ef756
---

PenunjangView ([arumed-frontend/src/views/PenunjangView.vue](arumed-frontend/src/views/PenunjangView.vue)) revamp 2026-05-28:

**Kiri — kartu antrean**:
- Tab filter samakan PerawatView: 2 baris.
  - Baris-1 `.primary-filter`: `Belum Dipanggil` / `Selesai` + counter dari `store.belumDipanggilCount` & `store.selesaiCount`. State `qPrimary` ('waiting' | 'done').
  - Baris-2 `.ptype-tabs`: `Semua` / `BPJS` / `Umum/Asuransi` polos. State `qSecondary`.
- `filteredQueue` = combine `qPrimary` × `qSecondary` × `qSearch`. State lama `qTab` + `tabCounts` dihapus.
- Button-press tier-3: `pendingCallIds` + `pendingSkipIds` lock disable + transform scale + box-shadow.
- `skipPt` wire ke backend `PUT /penunjang/antrian/{id}/lewati` via `store.lewatiAntrian`. Pola delegasi `QueueService::lewati` (sesuai [[queue-advance-station-pattern]]).

**Kanan — per-order workflow** (mengganti list read-only):
- State store baru: `resultsByOrderId` (map order_id → DiagnosticResult), `hasilSaving` (Set), `selectedOrders`/`pendingOrdersCount`/`canFinalizeQueue` getter.
- `pickPatient` async — auto-load `showHasil(orderId)` untuk semua order existing.
- Tiap order punya action sesuai status:
  - REQUESTED → tombol `Mulai` (call `prosesOrder` → status IN_PROGRESS, auto-open panel) + `Batal`.
  - IN_PROGRESS → tombol `Input Hasil` (toggle panel) + `Batal`.
  - COMPLETED → tombol `Lihat` (panel read-only).
- Panel hasil:
  - Form khusus **Biometri**: grid OD/OS dengan AL/K1/K2/ACD/Rec.IOL/iol_type/brand sesuai schema [[flow-penunjang-dokter]] yang dipakai `generateIolRecommendation` ([PenunjangService.php:313](backend/app/Services/PenunjangService.php#L313)). **UPDATE 2026-05-29**: penanda biometri kini = KODE master `BIOM` (bukan string nama 'Biometri'). Cek pakai const lokal `BIOMETRI_CODE='BIOM'`; backend pakai `DiagnosticTestType::BIOMETRI_CODE`. Biometri jadi master row penuh. Detail [[feature-penunjang-tarif-wiring]].
  - Form **generic**: ringkasan + kesimpulan + catatan. Pakai schema `expertise_data = { ringkasan, kesimpulan }` (di luar Biometri).
  - Upload lampiran via endpoint baru `POST /penunjang/hasil/upload-attachment` (disk `public`, folder `penunjang-hasil`, mime image/* + PDF, max 10 MB) → return `{path, url}`.
  - Actions panel: Tutup / Simpan Draf (`saveHasil` = updateOrCreate) / Selesaikan Hasil (`finalizeHasil` = save dulu lalu `selesaiHasil`).

**Tombol global "Selesai & Kembalikan ke Dokter"**:
- Disable ketika `selectedOrders.length > 0 && pendingOrdersCount > 0`. Boleh tetap klik kalau tidak ada order sama sekali (backward-compat dengan pasien tanpa order).
- Subtitle dinamis: 4 mode (already-completed / no-orders / N pending / ready).

**Why**: sebelumnya kolom kanan cuma read-only list + 1 tombol global yang auto-close semua order. Sekarang sesuai [[flow-penunjang-dokter]] dua trigger (per-order finalize + global) dengan validasi.

**How to apply**:
- Test biometri → expertise_data harus include `od.recommended_iol_power` / `os.recommended_iol_power` supaya auto-IOL recommendation jalan (lihat [PenunjangService.php:328](backend/app/Services/PenunjangService.php#L328)).
- Test lain → `expertise_data` cuma `{ringkasan, kesimpulan}`. Schema longgar (JSONB), backend hanya validate top-level array di [PenunjangController storeHasil](backend/app/Http/Controllers/PenunjangController.php#L147).
- File upload pakai disk `public` standard Laravel — perlu `php artisan storage:link` sekali.
