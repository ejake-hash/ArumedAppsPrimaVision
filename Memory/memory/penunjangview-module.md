---
name: penunjangview-module
description: "Key non-obvious facts in PenunjangView.vue (station Penunjang) — two main tabs, no result-entry form, finishExam returns patient to dokter"
metadata: 
  node_type: memory
  type: project
  originSessionId: 5641bfb5-f642-4eef-a28d-66fdbca0f847
---

[PenunjangView.vue](arumed-frontend/src/views/PenunjangView.vue) — station Pemeriksaan Penunjang. Pakai `penunjangStore` + polling. Hal yang TIDAK terlihat dari sekilas baca:

- **Dua main tab:** `antrean` (operasional antrean) + `jenis` (master jenis penunjang — meng-embed komponen [JenisPenunjangView.vue](arumed-frontend/src/views/master-data/JenisPenunjangView.vue)).
- **Tidak ada form input hasil di view ini — BY DESIGN** (dikonfirmasi user 2026-05-27, bukan fitur yang tertinggal). `finishExam()` hanya memanggil `store.selesaiAntrian(q.id)` → pasien dikembalikan ke antrean DOKTER. Order penunjang dibuat di sisi Dokter (lihat [[dokterview-module]]); hasil dibaca/diinterpretasi dokter, bukan diinput petugas penunjang di sini. (Catatan: empty-state masih bertuliskan "untuk mulai input hasil pemeriksaan penunjang" — teks lama yang tidak sesuai perilaku sebenarnya.)
- `selectedOrders` = `store.selectedQueue?.visit?.diagnostic_orders ?? []` — daftar order yang ditampilkan di panel detail.
- `skipPt` = reorder client-side (turunkan 1 posisi) — pola sama persis di PerawatView/RefraksionisView/DokterView.
- Filter tab antrean: `SEMUA` | `BPJS` | `UMUM` (umum/asuransi = guarantor_type ≠ BPJS) | `SELESAI` (status COMPLETED).

Arsitektur lengkap & endpoint: skill `skillarchitecturearumed`.
