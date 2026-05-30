---
name: feature-antrean-tv
description: "AntreanTVView display antrean — grid 8 stasiun, FIFO call queue, audio/TTS preset, tab Tampilan + Media backend-persisted (sync ke semua TV), panel video 16:9 stage, placeholder centered. Termasuk gotcha schema doctor_schedules (room=nomor, poliklinik=nama) dan bug perawat-broadcast yang sudah di-fix."
metadata: 
  node_type: memory
  type: project
  originSessionId: b4d8f0d6-2df8-46ad-845d-81751bb18053
---

# Antrean TV — Display + Tab Tampilan + Audio + Media (rev 2026-05-28)

Halaman `/antrean-tv` (public, no auth, layout blank). File: [[arumed-frontend/src/views/AntreanTVView.vue]].

## Arsitektur Layout
- **Topbar**: clock + chip "Sistem Aktif" + chip kuning "Suara nonaktif" (klik untuk unlock audio, hilang setelah gesture).
- **Main grid 2 kolom**: kiri = media panel (placeholder/YouTube/slideshow/video), kanan = **grid 4×2 = 8 kartu stasiun** (ADMISI/TRIASE/REFRAKSIONIS/DOKTER/PENUNJANG/BEDAH/KASIR/FARMASI).
- **Bottom ticker**: running text editable di control panel.
- **Flash overlay full-screen**: muncul setiap CALLED event, durasi minimum (slider 3-10s default 5s, di tab Antrean).

## FIFO Call Queue
- Setiap panggilan masuk antrian `callQueue`, diproses 1-per-1 via worker `processCallQueue`.
- Jeda antar panggilan: slider `callDelay` (5-10s default 7s, tab Antrean).
- Detect transition CALLED: pakai delta `called_at` (bukan cuma transition status) — robust terhadap race condition polling vs WS dan panggil ulang.
- Polling fallback: 3 detik (turun dari 30s) saat Reverb WS tidak tersedia (saat ini default karena `BROADCAST_CONNECTION=log` di backend).

## Audio
- **6 preset oscillator** (Web Audio API generate): chime, dingdong, triple, beep, softpad, hospital.
- **5 preset MP3** (royalty-free dari orangefreesounds.com CC-BY 4.0, di [[arumed-frontend/public/sounds/]]): airport-chime, train-chime, public-bell, announcement, soft-chime. Total ~290KB.
- Slider volume, TTS voice picker (id-* di atas), slider TTS rate 0.7-1.3x.
- **Audio unlock (rev 2026-05-28 lanjut)**: full-screen overlay (z-index 9999, backdrop blur) yang BLOKIR seluruh TV saat `audioUnlocked=false`. Card tengah: ikon speaker silang pulse + judul "Sentuh Layar untuk Aktifkan Suara" + CTA hijau bounce. Klik di mana saja → `unlockAudio()` (warm-up AudioContext + speechSynthesis). Global listener `pointerdown/touchstart/keydown` tetap sebagai fallback. Chip kuning kecil "Suara nonaktif" di topbar tetap ada untuk edge-case.

**Why:** TV publik jarang disentuh — gesture untuk unlock browser autoplay policy tidak pernah terjadi. Plus auto-reload tengah malam reset `audioUnlocked` ke false. Sebelumnya operator buka TV pagi-pagi tapi tidak sadar suara mati. Overlay full-screen memastikan teknisi/operator PASTI klik sekali saat install atau pagi.

**How to apply:** `audioUnlocked` adalah ref lokal (tidak dipersist) karena browser autoplay policy memang per-tab/per-session. Jangan coba simpan di backend/localStorage — gesture HARUS terjadi di tab yang sama. Kalau mau lebih reliable di deployment, dokumentasikan Chromium kiosk flag `--autoplay-policy=no-user-gesture-required`.

## Tab Tampilan — Editor Template Backend-Persisted
Tab baru di control panel. Per-stasiun bisa edit:
- `tts_template` — string template, variabel `{nomor}` `{nama}` `{poli}` `{stasiun}`.
- `flash_label_top` — judul flash overlay.
- `flash_badge_text` — text badge bawah flash.
- `custom_poli_label` — override label `{poli}` (mis. BEDAH→"Ruang Operasi", FARMASI→"Apotek"). **Tidak berlaku untuk DOKTER** (poli auto dari jadwal dokter aktif, UI input disembunyikan).
- 5 toggle: show_name_in_flash, show_poly_in_flash, show_name_in_card, show_poly_in_card, read_name_in_tts.

Backend: tabel `tv_display_settings` (migration 2026_05_26_000050 + 000051 add custom_poli_label).
- Endpoint: `GET /antrean-tv/display-settings` (public), `PUT /antrean-tv/display-settings/{station}` (auth:api), `POST .../{station}/reset`.
- Defaults di `TvDisplaySetting::defaults()` static method.

**Why:** TV cuma display, tidak ada operator tetap, jadi template harus configurable agar tidak butuh deploy ulang saat klinik mau ubah kata-kata. Pakai `auth:api` untuk write supaya tidak siapapun bisa modifikasi via API.

**How to apply:** Saat tambah variabel template baru, update `renderTemplate()` di AntreanTVView + tambah kolom di model fillable + controller validation + defaults.

## Tab Media — Backend-Persisted Sync ke Semua TV (2026-05-28)
Operator atur media panel kiri dari panel kontrol (login required untuk write); semua TV ikut update via WS event.

Backend:
- Tabel `tv_media_settings` (migration `2026_05_28_000020` + `2026_05_28_000021` add `external_video_url`).
- Model `TvMediaSetting::singleton()`.
- Event `TvMediaUpdated` (channel `antrean-tv`, event name `media-updated`) — share channel dengan `AntreanTvUpdated` agar TV cukup 1 subscription.
- Controller `TvMediaSettingController`: `show` (public), `update` (auth:api), `uploadVideo` (multipart, replace-on-upload), `deleteVideo` (auth:api).
- Field: `media_mode` (placeholder/youtube/localvideo/slideshow), `youtube_embed_url`, `video_autoplay/loop`, `local_video_path` (disk public), `external_video_url`, `slides` (JSON array), `slide_interval`.

Frontend:
- `fetchMediaSettings()` saat mount + bind `media-updated` event → `applyMediaPayload()`.
- Upload video lokal: axios `onUploadProgress` + `AbortController` (cancel) + per-request `timeout: 15 menit`. Pre-check size 500 MB di client.
- **Opsi Video URL eksternal** (Drive/Dropbox/CDN/hosting) sebagai alternatif upload — tip Dropbox `?dl=0` → `?raw=1`, Drive `uc?export=download&id=ID`. External URL diprioritaskan kalau ada; clearing kembali ke uploaded file kalau ada.

**Why:** TV adalah display only — operator harus bisa kontrol dari komputer/TV via panel PIN tanpa restart. Sync ke semua TV via WS. Video URL ditambah karena upload 500MB ke server lokal lambat saat operator pakai WiFi/koneksi terpisah dari server.

**How to apply:** Saat tambah mode media baru, update `applyMediaPayload`, mode-card di template, validator backend, serialize. Untuk upload ≥500MB, naikkan `upload_max_filesize` + `post_max_size` di `php.ini` (sudah set: post=520M, exec=600, mem=256M; `upload_max_filesize` masih perlu manual edit ke 500M kalau belum).

## Panel Video — Stage 16:9 + Placeholder Center
- `.video-panel > *:not(.video-placeholder)` di-set `aspect-ratio: 16/9` agar YouTube/video/slideshow tidak melar mengikuti rasio panel (yang ±1.44 di TV 1080p dengan sidebar 480px).
- `.video-placeholder` exempt dari aspect-ratio → `width/height: 100%` + `justify-content: center` agar logo + nama klinik centered penuh di panel.

## Bug Fixed: Perawat → TV Broadcast (2026-05-28)
**Root cause sebelumnya**: `PerawatService::panggilAntrian/mulai/lewati` punya jalur sendiri yang hanya fire `TriaseQueueUpdated`, **tidak** `AntreanTvUpdated` → TV tidak menerima panggilan dari perawat (termasuk "Panggil Ulang").

**Fix**: ketiga method delegate ke `$this->queueService->panggil/mulai/lewati()` (sama pola dengan `RefraksiService`). `QueueService::broadcastQueueUpdate()` fire `TriaseQueueUpdated` (FE Perawat) + `AntreanTvUpdated` (TV) sekaligus. Hasil dari `QueueService` di-re-format via `formatQueueItem()` agar shape response FE tidak berubah.

**How to apply:** Saat tambah method panggil/mulai/lewati untuk station baru, **harus** delegate ke `QueueService` (jangan fire event sendiri) supaya TV ikut update.

## Auto-reset
Jam 00:00:01 setiap hari → `window.location.reload()`. State + audio unlock reset (staf perlu klik sekali pagi-pagi).

## ⚠️ Gotcha: `doctor_schedules.room` vs `poliklinik`
Skema kolom doctor_schedules (setelah migration 2026_05_25_000001_add_poliklinik):
- **`room`** = nomor ruangan ("1", "2", "3"). Dipakai `DoctorSchedule::queuePrefix()` untuk derive `D1`/`D2`/`D3`.
- **`poliklinik`** = nama poli ("Poli Glaukoma", "Poli Mata Umum", "Poli Retina", "Poli Katarak").

**Why:** Pre-2026-05-26 data lama isi `room` dengan nama poli ("Poli Glaukoma") → `queue_prefix` jadi `"DPoli Glaukoma"`, tidak match prefix antrean (`D2`), TTS DOKTER fallback ke label generic "Pemeriksaan Dokter". Fix sudah dijalankan: UPDATE DB pindah nama poli ke `poliklinik`, set `room` ke nomor. Seeder juga sudah diperbarui untuk konsistensi fresh-migrate.

**How to apply:** Saat operator buat jadwal dokter di menu admin, **wajib** isi `room` dengan nomor (bukan nama), dan `poliklinik` dengan nama poli. Mapping konsisten yang dipakai: Mata Umum=1, Glaukoma=2, Retina=3, Katarak=4. Konvensi `stationPoly()` di AntreanTVView: kalau `poliklinik` sudah berawalan "Poli", tidak double-prefix; format room sebagai " Ruang N". Output: "Poli Glaukoma Ruang 2".

## Catatan operasional
- **Audio control PIN**: default `1234`, bisa diganti di tab Keamanan (state lokal, tidak persist).
- **Multiple TV**: setting tampilan tersimpan global di backend (semua TV pakai setting yang sama). Tapi audio unlock per-tab/device.
- **Reverb belum jalan**: `BROADCAST_CONNECTION=log` di backend `.env`. Polling fallback 3s sudah cukup untuk klinik kecil. Untuk real-time <1s, perlu setup `laravel/reverb` package + `php artisan reverb:start` daemon + isi `VITE_REVERB_*` di frontend `.env`.
