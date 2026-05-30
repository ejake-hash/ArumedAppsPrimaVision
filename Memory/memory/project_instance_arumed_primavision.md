---
name: project-instance-arumed-primavision
description: "Instance duplikat Arumed untuk RS Mata Prima Vision (Medan) — folder, DB, kredensial, branding"
metadata: 
  node_type: memory
  type: project
  originSessionId: 9198e1a5-674c-4a17-b9c0-7710ccd9a832
---

Instance klinik kedua hasil duplikasi master `d:\apps- cl` (Arunika), dibuat 2026-05-30. Master tetap utuh sebagai Arunika; semua edit branding ada di clone.

**Lokasi & identitas**
- Folder: `D:\Arumed-PrimaVision` — repo git lokal mandiri, branch `main`, tanpa remote (murni lokal)
- Klinik: **RUMAH SAKIT MATA PRIMA VISION** · kode `RSKMPV` · kota **Medan**
- Nama pendek brand (sidebar/login): **Prima Vision**
- Nama produk "Arumed Apps" sengaja DIBIARKAN (hanya Arunika→Prima Vision)
- Tema: **biru** (`tokens.css`: `--ga #1763d4`, `--gd #0a2a4d`, `--gm #11487f`, `--gl #eef4fc`, `--gb #cdd9e6`, `--lm #38bdf8`, `--ld #0284c7`)

**Database (Postgres 18, localhost:5432)**
- DB `dbprimavision`, user `postgres`, **password `arumed`** (sama dgn server PG lokal user)
- Seed bersih: pasien=0, visits=0, users=11, tidak ada baris KMA. Login `superadmin`/`Superadmin@123`, stasiun `888888`.

**Verifikasi (lulus):** login API OK + profil API = Prima Vision; `vite build` hijau (2×, termasuk setelah Bagian E warna). Diuji di port :8001 karena :8000/:5173 dipakai proses lain (zombie) di mesin user — untuk jalan normal hentikan proses itu dulu lalu `php artisan serve` + `npm run dev`.

**Yang sudah diubah:** 18 file frontend (index.html title, tokens.css, 16 .vue: teks brand + 89 hex/rgba hijau→token var(--*)) + 2 seeder backend (ClinicProfile + DoctorSchedule). Sudah **di-commit** `59ade44` di branch `main`, working tree bersih.

**Sisa opsional (butuh input user, via UI Profil Klinik):** upload logo, isi alamat/telp/email/direktur, ganti `public/favicon.jpg`.

Saat setup butuh fix [[bug-doctorscheduleseeder-week-start]]. Panduan generik di `Docs/DUPLIKASI-KLINIK.md`.
