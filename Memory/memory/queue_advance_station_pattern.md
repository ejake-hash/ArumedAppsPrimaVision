---
name: queue-advance-station-pattern
description: "Pola wajib semua service station — transisi antrean ke station berikut HARUS via QueueService::advanceFromStation, JANGAN hardcode current_station/queue.status. Sumber kebenaran routing + broadcast TV di satu tempat."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 83cb5400-a8e1-4343-b99e-029d37e04567
---

Semua service station (Perawat/Refraksi/Dokter/Penunjang/Bedah/Farmasi/**Kasir**) **wajib** delegasi ke `QueueService::advanceFromStation($queueId, $station)` ketika menutup antrean / pindah ke station berikutnya. Jangan tulis manual `current_station = 'X'` atau update `queue.status = COMPLETED` secara langsung dari service station.

**Why:** Routing logic per-station (mana yang next) ada di method private `QueueService::nextAfter{Station}()`. Tiap method itu cek invariant spesifik:
- `nextAfterKasir()` cek `Prescription::whereIn('status', ['DRAFT','SUBMITTED','DISPENSING'])->exists()` → return `FARMASI` atau `END_OF_FLOW`
- `nextAfterPerawat()` cek refraksi/dokter ready, dst.

Selain routing, `advanceFromStation()` juga:
1. Mark queue lama COMPLETED + set `completed_at`.
2. Update `visit.current_station` ke station baru.
3. Enqueue queue baru di station target (dengan queue_number sesuai sequence).
4. **Broadcast event ke Antrean TV** — kalau di-bypass, TV diam, kasir / petugas station target tidak tahu ada pasien baru.

**How to apply:**
- Tutup antrean = `app(QueueService::class)->advanceFromStation($q->id, Queue::STATION_X)`.
- Bila kasus exceptional (mis. tidak ada queue aktif, atau visit dibatalkan), pakai update manual TAPI dokumentasikan kenapa.
- Audit historis pernah ada 2 bug akibat hardcode:
  - **PerawatService panggil/mulai/lewati** (fixed 2026-05-28) — sebelumnya update queue langsung, TV tidak broadcast. Lihat [[feature-antrean-tv]] gotcha.
  - **KasirService::processPayment** (fixed 2026-05-29) — hardcode `current_station='SELESAI'` walau pasien punya resep aktif. Akibatnya pasien yang harusnya antri Farmasi langsung "selesai" tanpa pernah muncul di antrean farmasi. Lihat [[feature-kasir-view]].
  - **BedahService::completeOperation** (fixed 2026-05-30) — sebelumnya bikin antrean FARMASI manual + `Visit::update(current_station=FARMASI)` (hardcode, bypass routing→tak cek resep, TV diam). Diganti: ambil queue BEDAH aktif visit lalu `advanceFromStation($q->id, STATION_BEDAH)` (auto FARMASI bila ada resep, else KASIR). Bareng fix `startOperation` yg resolve `visit_id` salah (dari surgery_requests→null bila tanpa request; kini dari `visits.surgery_schedule_id` dulu). Lihat [[bedah-view]].

## `QueueService::lewati` = TURUN 1 POSISI (bukan ke paling bawah) — 2026-05-30
Semantik "Lewati"/skip diubah (by user request): pasien turun **satu** posisi = **tukar `queue_sequence`** dengan baris aktif (WAITING/CALLED) berikutnya di station sama (orderBy seq asc, seq > seq-pasien-ini). Baris COMPLETED/CANCELLED **dilompati** supaya pasien tak tenggelam di bawah yang sudah selesai. Pasien paling bawah aktif → **no-op posisi**, hanya status di-reset ke WAITING (batalkan CALLED). Dibungkus `DB::transaction` + `lockForUpdate` (anti-race), broadcast TV utk KEDUA baris yang ditukar. Berlaku **semua station** (satu sumber: `QueueService::lewati`; Triase/Refraksi/dll delegasi). Frontend: Perawat & Refraksi `skipPt` panggil `store.fetchAntrian()` setelah lewati utk sinkron urutan (jangan reorder lokal ke bawah — itu pola lama yg dibuang di refraksiStore); toast "diturunkan 1 antrean". DULU: `queue_sequence = MAX+1` (ke paling bawah).

Pattern singkat:
```php
// SALAH (silent bypass)
$invoice->visit->update(['current_station' => 'SELESAI']);
Queue::where(...)->update(['status' => 'COMPLETED']);

// BENAR
$q = Queue::where('visit_id', $vid)->where('station', 'KASIR')
    ->whereIn('status', ['WAITING','CALLED','IN_PROGRESS'])->first();
if ($q) $this->queueService->advanceFromStation($q->id, Queue::STATION_KASIR);
```
