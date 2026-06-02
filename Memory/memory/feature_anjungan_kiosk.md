---
name: feature-anjungan-kiosk
description: "AnjunganView.vue kiosk publik — status BPJS (disabled menunggu client ID VClaim), thermal print 80mm, heartbeat /up, envelope guard. Catat poin pending agar tidak digarap ulang."
metadata: 
  node_type: memory
  type: project
  originSessionId: d653f3e1-0270-418e-815d-cdc50743e42f
---

Kiosk Anjungan Mandiri (`/anjungan`, public no-auth, `arumed-frontend/src/views/AnjunganView.vue`) — revisi 2026-05-26.

**Status alur:**
- **UMUM** — fully wired ke `POST /anjungan/tiket-umum` via `anjunganApi.tiketUmum()`. Auto-print + countdown 15s + auto-reset.
- **BPJS** — card di-disable sementara dengan label "Segera Hadir" (badge kuning) + tooltip. Klik tidak ada efek. Alur lama (mockDB, state `bpjs-*`, `confirmedPatient`, `bpjsCount`, `inputVal`, `method`) sudah **dihapus total** — jangan dipasang ulang dengan mock.

**Why:** client ID BPJS VClaim belum tersedia; tidak masuk akal pasang half-baked flow yang akan confusing user. Saat client ID tersedia, perlu: (1) endpoint public kiosk baru `POST /anjungan/tiket-bpjs` (wrap `BpjsVClaimService`), (2) backend-side counter `B-NNN` via `QueueService::generateQueueNumber`, (3) tambah on-screen numpad (sudah disiapkan placeholder `activeInputRef`/`activeInputValue` di script tapi belum dipakai).

**How to apply:** kalau ada request "BPJS di kiosk", jangan langsung wire ke endpoint admisi yang `auth:api` — kiosk public tidak punya token. Buat endpoint kiosk khusus.

**Fitur lain yang sudah wired:**
- **Thermal print 80mm** — REVISI 2026-05-29: dulu cetak KOSONG (blank A4). Penyebab: (1) trik `visibility:hidden` body + `position:absolute` rapuh, (2) global `base.css` → `body{min-width:1280px}` + `html,body{height:100%}` merusak kanvas cetak. **Solusi final**: node `#print-ticket` di-`<Teleport to="body">` (sibling `#app`, lepas total dari subtree komponen), `@media print` di `<style>` UNSCOPED → `#app{display:none}` + reset `html,body{min-width:0;height:auto;overflow:visible}` + `@page{size:72mm auto;margin:0}` + `print-color-adjust:exact`. Pola sama dipakai di KasirView ([[feature-kasir-view]]). Auto-trigger `window.print()` saat `screen='ticket'` via `nextTick` + tombol "Cetak Ulang". **Gotcha cetak kosong yang BUKAN bug kode**: header/footer browser ("tanggal · URL localhost") = setting browser, matikan "Headers and footers" di dialog print. Kiosk OS harus set default printer ke thermal & enable Chrome `--kiosk-printing` flag.
- **Heartbeat `/up`** — `pingBackend()` fetch ke `${baseUrl}/up` (Laravel root, di LUAR `/api/v1`) tiap 30s. Status indicator hijau/merah/kuning di topbar. Tombol UMUM di-disable saat status `offline`. Pakai raw `fetch()`, bukan axios instance (hindari interceptor 401).
- **Envelope guard** — `goUmum()` cek `data.success === false` + validasi `queue_number` ada sebelum show tiket. Backend pakai envelope standar Section 8.1.
- **Pre-flight offline check** — `goUmum()` bail dengan error message friendly kalau `backendStatus === 'offline'` sebelum call API.

**Numpad — SKIPPED sementara:** alur UMUM tidak ada input field (1 klik tombol). Numpad akan relevan saat BPJS aktif (input kode booking/NIK). [[feedback-bertahap-konfirmasi]]

## Bugfix + Tema Biru (2026-06-01, belum commit)
2 bug diperbaiki + selaraskan tema biru Prima Vision (ikut [[reference-design-tokens-primavision]] + pola [[feature-antrean-tv]]):
- **Bug "Cetak Ulang" tak restart countdown**: tombol "Cetak Ulang" dulu cuma `triggerPrint()`. `window.print()` blocking → kalau diklik di detik akhir, countdown keburu habis saat dialog ditutup → user ketendang ke home. Fix: fungsi baru `reprintTicket()` = `triggerPrint()` + `startCountdown()` (reset 15s); tombol panggil itu.
- **Tombol UMUM bisa diklik saat loading**: `:disabled` ditambah `|| screen === 'umum-loading'` (pertahanan berlapis anti double-tiket, di samping guard `return` di `goUmum`).
- **Tema biru**: token (`--gd`/`--lm`/`--ga` dst di `assets/styles/tokens.css`) SUDAH biru, jadi gradient & accent otomatis biru. Yang dibersihkan = residu HIJAU hardcoded: `.ksk-btn.pri` color `#061d15`→`#06182E` (navy, sama teks tombol terang AntreanTVView), `.tkt-perf` tint `#f0f4f1`→`#eef4f9`. Accent `rgba(56,189,248)` (sky-400) & badge amber `#fbbf24` (semantik "Segera Hadir") DIPERTAHANKAN — identik dgn AntreanTVView. Build OK.
