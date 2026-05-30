---
name: project-arumed
description: "Arumed Apps — Klinik Mata Arunika HIS, Vue 3 + Laravel 13.8 fullstack, backend di backend/, frontend di arumed-frontend/. Tech stack, dev port, dokumentasi arsitektur lengkap."
metadata: 
  node_type: memory
  type: project
  originSessionId: ec6fc0c4-c948-49e3-a047-f7cd65331c6c
---

Arumed Apps adalah HIS (Hospital Information System) khusus oftalmologi untuk **Klinik Mata Arunika**, 100% paperless, memenuhi PMK No. 24/2022, terintegrasi BPJS (VClaim/Antrean/iCare/INA-CBGs) dan Satu Sehat.

**Why:** Transformasi digital workflow klinis (admisi → triase/refraksi paralel → dokter → penunjang/bedah → kasir → farmasi → selesai) dengan BPJS bridging + audit-ready document trail.

**How to apply:** Untuk setiap pekerjaan di repo ini, **wajib panggil skill [[skillarchitecturearumed]]** dulu sebelum eksplorasi manual — skill itu adalah single source of truth untuk: 18 modul + ~270 endpoint, 71 migration, alur antrean (QueueService orchestrator), gate validation per station, RBAC matrix (14×R/W/D), pola envelope response, BPJS integration points. Hemat banyak round-trip eksplorasi.

## Struktur repo

- **Backend** (`backend/`) — Laravel 13.8 + PHP 8.3, PostgreSQL (`DB_CONNECTION=pgsql`, db: `arumed_apps`). Port dev `:8000`. JWT auth (`tymon/jwt-auth`). Base API `/api/v1/*`. Health: `GET /up`.
- **Frontend** (`arumed-frontend/`) — Vue 3.5 + Vite 5, port dev `:5173`. Proxy `/api` → `:8000`. Tidak ada UI library eksternal — semua komponen custom dengan design token di `src/assets/styles/tokens.css`. State: Pinia (composition API). HTTP: axios instance di `src/services/api.js` dengan response interceptor untuk dispatch `arumed:session-expired`/`forbidden`/`server-error` events.

## Pola wajib diikuti

- **Backend**: Controller thin (`validate()` → delegate ke Service), Service punya semua business logic & integrasi pihak ketiga. Response envelope `{success, data, message, errors}`. Validation 422 pakai format default Laravel. Permission check via middleware `permission:foo.read|bar.write` (pipe = OR) dan `role:superadmin`.
- **Frontend**: Pakai `auth.can('module.action')` untuk guard UI (sidebar links + RouterLink `v-if`), Superadmin lolos via sentinel `["*"]`. Route guard pakai `meta.permission` di parent route (otomatis warisan ke children). Toast pattern lokal per view (CSS `*-toast-*`), bukan global state.
- **Pakai komponen reusable** kalau sudah ada — jangan duplikasi modal/table/CSV bar. Lihat `src/components/master-data/` untuk pola generic table+modal yang sudah matang.
- **PHP 8.4 deprecation safety** — `fputcsv`/`str_getcsv` di backend ini berjalan di PHP 8.4; selalu pass param eksplisit `(',', '"', '\\')` untuk hindari deprecation warning.

## Status modul (per 2026-05-26)

**Fully implemented backend + frontend wired**: Auth, Admisi (+ kiosk Anjungan walk-in flow), Perawat/Triase, Refraksionis, Dokter (RME tab2/3/4), Penunjang, Bedah, Farmasi, Kasir, Klaim BPJS, Rekam Medis, Dashboard, Jadwal Dokter (CRUD mingguan + queue prefix `D{room}` dinamis), RBAC (DataPengguna view + matriks R/W/D **23 modul**), Antrean TV public display, **Master Data (Stage 1: 8 resource — lihat [[feature-master-data-stage1]])**, **Inventori Farmasi lengkap (Master Obat/BHP/IOL + Penentuan Harga + Supplier + Pembelian/PO + Penerimaan/GRN dengan stok per-batch — lihat [[feature-pembelian-penerimaan]])**.

**Belum dibangun**: Stage 2 Master Data (Penjamin, Paket Bedah, Tarif Obat/BHP/IOL, Jenis/Template/Nomor Dokumen, Stasiun Document Mapping), Audit Log tab di DataPengguna (placeholder), BPJS bridging penuh di kiosk Anjungan (BPJS flow masih UI mock), Pengaturan view (StubView).

## Catatan lain

- Default credentials seed (lihat `UserSeeder`): `superadmin/Superadmin@123`, role per-alur: `<role>/888888` (dokter, perawat, refraksionis, penunjang, farmasi, kasir, verifikator, admisi).
- File `auth.js` di stores adalah re-export shim ke `authStore.js` (legacy import path masih ada untuk back-compat).
- `queueStore.js` generic tidak dipakai siapa pun — kandidat penghapusan.
