---
name: flow-penunjang-dokter
description: "Alur transisi Dokter ↔ Penunjang: status queue (DI_PENUNJANG/SELESAI_PENUNJANG), requeueToDokter, dua trigger berbeda (finalizeResult per-test vs selesaiAntrian operator)"
metadata: 
  node_type: memory
  type: project
  originSessionId: e4ac9229-9f4a-4f2e-adf7-1c1566f9797c
---

Alur lengkap pasien Dokter → Penunjang → kembali ke Dokter. Status queue & trigger transisi sering bikin bingung karena ada DUA jalur berbeda yang sama-sama mengakhiri tugas penunjang.

## Status Queue (lihat `Queue::STATUS_*` di [Queue.php](backend/app/Models/Queue.php))

- `DI_PENUNJANG` = baris DOKTER di-pause, pasien sedang di stasiun penunjang. Baris turun ke akhir antrean DOKTER (`queue_sequence = maxSeq + 1`). Set oleh `DokterService::kirimKePenunjang`.
- `SELESAI_PENUNJANG` = semua hasil selesai, baris DOKTER hidup kembali di **paling atas** (`queue_sequence = minSeq - 1`). Set oleh `PenunjangService::requeueToDokter`.
- Keduanya bukan ACTIVE_STATUSES, tapi tetap callable (lihat `QueueService::panggil` line 175: `$callable = array_merge(ACTIVE_STATUSES, [AT_PENUNJANG, PENUNJANG_DONE])`).

## Anti-duplikat: satu pasien = satu baris DOKTER

`QueueService::nextAfterPenunjang` (line 436) return `NO_OP` kalau baris DOKTER pasien masih hidup (status bukan COMPLETED/CANCELLED). `requeueToDokter` me-*reuse* baris yang dipause (update status + queue_sequence), bukan bikin baru. Fallback bikin baris baru hanya kalau baris DOKTER tidak ditemukan sama sekali.

## Dua trigger requeueToDokter

1. **Per-test, otomatis**: `PenunjangService::finalizeResult` (saat hasil per-test di-finalize) → cek `pendingOrders == 0` → `requeueToDokter`. Granular: kalau ada 3 order dan baru 2 selesai, tidak trigger.
2. **Tombol "Selesai & Kembalikan ke Dokter"** (PenunjangView, prominent button): `PenunjangService::selesaiAntrian` (revisi 2026-05-28) — operator menyatakan pemeriksaan cukup. Implementasi: tandai semua DiagnosticOrder REQUESTED/IN_PROGRESS → COMPLETED **lalu** panggil `requeueToDokter` **lalu** `advanceFromStation(PENUNJANG)` (yang jadi NO_OP karena baris DOKTER sudah hidup).

**Bug yang sudah di-fix 2026-05-28**: sebelumnya `selesaiAntrian` cuma panggil `advanceFromStation` tanpa `requeueToDokter` → `nextAfterPenunjang` return NO_OP karena baris DOKTER masih `DI_PENUNJANG` (status "hidup") tapi statusnya tidak berubah → card antrean dokter stuck di pemeriksaan_penunjang, tidak naik ke atas. Lihat [[dokterview-module]] untuk reaksi UI Dokter.

## UI Dokter: status → label

- `DI_PENUNJANG` → uiStatus `penunjang`, pill kuning "Pemeriksaan Penunjang", tidak ada tombol panggil (cuma label "Sedang di Penunjang").
- `SELESAI_PENUNJANG` → uiStatus `penunjang_done`, pill hijau "Selesai Penunjang", button hijau "Lanjutkan" (class `resume`).

## Pembuatan order — KAPAN order benar-benar masuk DB

Revisi 2026-05-28 (lihat [[dokterview-module]] section "Order Penunjang — staging lokal"): klik chip jenis penunjang di modal Dokter = staging lokal saja. POST `storeOrderPenunjang` baru terjadi saat klik tombol "Konfirmasi N Pemeriksaan" di footer modal. Sebelumnya tiap klik chip langsung bikin row `diagnostic_orders` + row `Queue PENUNJANG` → menghasilkan row sampah saat user batal pilih.
