---
name: feature-realtime-antrean
description: "Status realtime antrean: WebSocket (Reverb) DISIAPKAN tapi belum aktif (BROADCAST=log), jalan via polling 8s seragam. Kode broadcast sudah ada di QueueService; tinggal isi .env + reverb:start saat produksi."
metadata:
  node_type: memory
  type: project
  originSessionId: realtime-antrean-2026-05-30
---

Audit realtime antrean 2026-05-30 (user tanya "antrean bisa lebih realtime?").

## Kondisi: WebSocket BELUM aktif, jalan via POLLING
- Backend `.env` aktual: `BROADCAST_CONNECTION=log` → event broadcast cuma ke log file, tidak terkirim. Tidak ada `REVERB_*` di `.env` aktual. Frontend `VITE_REVERB_APP_KEY` kosong → setiap store langsung `startPolling()` (connectWs cek appKey, kosong→fallback). Reverb tak pernah dicoba.
- **Polling diseragamkan ke 8 detik** (2026-05-30, dari 30s): perawatStore, refraksiStore, dokterStore, penunjangStore (`startPolling(intervalMs = 8_000)`) + KasirView & FarmasiView (`setInterval(fetchQueue, 8_000)`). Admisi tetap 10s (sudah cepat). AntreanTV publik 3s. BedahView 15s. notificationStore 60s (bukan antrean).

## WebSocket SUDAH DISIAPKAN (2026-05-30) — tinggal config saat produksi
- `composer require laravel/reverb` (^1.10) terinstall. `config/reverb.php` + `config/broadcasting.php` ter-publish (koneksi `reverb` ada, `default => env('BROADCAST_CONNECTION','null')` → aman, masih log).
- Event broadcast SUDAH ter-fire dari `QueueService` di semua aksi (panggil/mulai/lewati/advanceFromStation): `AntreanTvUpdated` (channel `antrean-tv`) + `TriaseQueueUpdated` (channel `triase-queue`). **Semua channel PUBLIC `Channel`** (bukan Private/Presence) → TIDAK perlu broadcasting-auth route / `channels.php` / `withBroadcasting()`. `pusher-js` ^8.5 sudah ada di frontend.
- Placeholder `.env.example` ditambah: backend (`REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME/SERVER_HOST/SERVER_PORT`) + frontend (`VITE_REVERB_APP_KEY/HOST/PORT/SCHEME`). Frontend store baca env name persis ini.

## Aktivasi produksi (3 langkah, tanpa ubah kode)
1. Backend `.env`: `BROADCAST_CONNECTION=reverb` + isi `REVERB_*` → `config:clear`.
2. Frontend `.env`: isi `VITE_REVERB_*` → **`npm run build` ulang** (Vite bake env saat build).
3. `php artisan reverb:start` sebagai daemon (systemd/supervisor) + reverse-proxy WSS (Nginx `/app` upgrade ws).
**GOTCHA paling sering**: `REVERB_APP_KEY` backend WAJIB identik dgn `VITE_REVERB_APP_KEY` frontend. Rollback = balik `log` + kosongkan VITE key + rebuild → auto polling lagi.

Panduan lengkap: `Docs/REALTIME-ANTREAN-WEBSOCKET.md`. Verifikasi: smoke 35/35 hijau pasca-install, build hijau, `.env` aktual tetap log (perilaku tak berubah).

Terkait: [[feature-antrean-tv]] (TV publik konsumen broadcast utama), [[queue-advance-station-pattern]] (semua broadcast lewat QueueService), [[feature-request-unit-notif]] (notif Farmasi juga nunggu Reverb, polling fallback 10s).
