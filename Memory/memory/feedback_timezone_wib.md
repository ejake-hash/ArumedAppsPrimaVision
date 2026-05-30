---
name: feedback_timezone_wib
description: Backend timezone harus Asia/Jakarta (WIB) bukan UTC — fix reset harian penomoran antrean
metadata: 
  node_type: memory
  type: project
  originSessionId: c0529804-0a1d-45d8-94f1-5807312f470a
---

Backend `config/app.php` semula `timezone => 'UTC'`. Diubah 2026-05-29 jadi `env('APP_TIMEZONE', 'Asia/Jakarta')`, + `APP_TIMEZONE=Asia/Jakarta` di `.env` dan `.env.example`.

**Why:** Klinik di WIB (UTC+7). `today()`/`whereDate('created_at', today())` dihitung di UTC → reset harian penomoran antrean (`generateQueueNumber` di [[queue-advance-station-pattern]] / QueueService) terjadi jam 07:00 WIB, bukan tengah malam. Pasien datang 00:00–06:59 WIB melanjutkan sequence hari sebelumnya (tidak mulai dari A-001). Gejala yang memicu temuan: user ambil tiket umum AnjunganView dapat A-003 padahal merasa baru — ternyata A-001/A-002 hari yang sama, tapi confirm bug timezone riil.

**How to apply:** Setelah ubah config WAJIB `php artisan config:clear`. Data `created_at` lama TIDAK perlu migrasi — Laravel selalu simpan timestamp UTC di DB, yang berubah hanya interpretasi saat baca/tampil. Penomoran antrean = `MAX(queue_sequence)+1` per station per `whereDate('created_at', today())` (logika benar, hanya boundary hari yang salah sebelum fix).
