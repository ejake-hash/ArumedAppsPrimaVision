# Arumed Apps — Panduan Go-Live & Development Pasca-Live

Dokumen ini merangkum 3 topik:
1. Cara deploy Arumed ke production (live)
2. Alur aman update fitur setelah live
3. Sejauh mana Claude Code bisa bantu test/debug pra-live

Project: Vue 3.5 + Laravel 13.8 (HIS Oftalmologi)
Disusun: 2026-05-28

---

## 1. Deployment ke Production

### 1.1 Siapkan Server
- VPS Linux (Ubuntu 22.04/24.04), spec minimal **4 vCPU / 8 GB RAM / 100 GB SSD** untuk klinik kecil-menengah
- Install: Nginx, PHP 8.3 (+ ext: `pdo_pgsql`, `mbstring`, `gd`, `zip`, `bcmath`, `redis`), PostgreSQL 16, Redis, Node 20, Composer, Git, Certbot
- Domain + SSL (Let's Encrypt gratis), misal `app.kliniknya.com` (frontend) + `api.kliniknya.com` (backend)

### 1.2 Database Production
- Buat DB PostgreSQL baru + user khusus (jangan pakai `postgres` superuser)
- Backup otomatis harian (`pg_dump` + cron → simpan ke object storage seperti Wasabi/S3/Cloudflare R2)

### 1.3 Deploy Backend Laravel
```bash
git clone <repo>
composer install --no-dev --optimize-autoloader
cp .env.example .env   # isi DB, APP_URL, APP_ENV=production, APP_DEBUG=false
php artisan key:generate
php artisan migrate --force
php artisan config:cache route:cache view:cache
php artisan storage:link
```
- Set permission `storage/` & `bootstrap/cache/` ke `www-data`
- Nginx vhost arahkan ke `public/`, PHP-FPM socket
- **Queue worker** via `supervisor` (penting: notifikasi, broadcast, sync Satu Sehat)
- **Scheduler** via cron: `* * * * * php artisan schedule:run`

### 1.4 Deploy Frontend Vue
```bash
npm ci
npm run build   # output: dist/
```
- Set `VITE_API_BASE_URL=https://api.kliniknya.com` sebelum build
- Upload `dist/` ke Nginx static atau Cloudflare Pages/Vercel (lebih gampang + CDN gratis)

### 1.5 Hardening Wajib
- Firewall (ufw): hanya port 80/443/22, DB jangan di-expose
- Fail2ban untuk SSH
- HTTPS-only (redirect 80→443), HSTS
- CORS backend dibatasi ke domain frontend saja
- `.env` permission 600, jangan ke-commit
- Disable `APP_DEBUG`, `telescope`, `tinker` di prod
- Rate-limit endpoint login & VClaim

### 1.6 Integrasi Eksternal
- Daftar **consumer ID/secret VClaim BPJS production** (bukan dev/sandbox lagi) → ganti di `.env`
- Daftar **Satu Sehat production** + ganti `BASE_URL` & client credentials
- Printer thermal: install driver di komputer klien (sudah pakai web print, jadi cuma butuh browser + printer terdeteksi OS)

### 1.7 Monitoring & Backup
- **Log**: Laravel log + rotate harian (default), atau forward ke Grafana Loki / Sentry untuk error tracking
- **Uptime**: UptimeRobot/BetterStack ping `/up` heartbeat
- **Backup**: DB harian + folder `storage/app/` (foto pasien, dokumen) ke S3-compatible
- **Update**: workflow `git pull` → `migrate` → `cache:clear` via skrip `deploy.sh` atau pakai Deployer/Envoyer

### 1.8 Pra-Live Checklist
- Smoke test semua modul utama (admisi → kasir → farmasi → bedah) di staging dulu
- Training user (dokter, perawat, kasir, farmasi) — minimal 1 hari per role
- Siapkan **rollback plan** (snapshot VPS + DB dump sebelum migrate)
- Siapkan kontak support (kamu/tim) di jam operasional klinik

### 1.9 Hosting Alternatif (Lebih Simpel)
Kalau tidak mau urus server sendiri:
- **Backend**: Laravel Cloud / Forge + DigitalOcean (~$12–24/bln) — auto-deploy dari Git
- **Frontend**: Cloudflare Pages (gratis)
- **DB**: Supabase / Neon Postgres (gratis tier untuk mulai)
- **Storage file**: Cloudflare R2 (gratis 10 GB)

Estimasi biaya bulanan klinik kecil:
- Self-hosting VPS: **~Rp 300–500 rb/bln**
- Managed: **~Rp 500 rb–1 jt/bln**

---

## 2. Alur Aman Update Fitur Setelah Live

### 2.1 Branching Strategy
```
main         → production (yang live)
staging      → mirror production untuk uji coba
develop      → integrasi fitur
feature/xxx  → fitur baru per developer
hotfix/xxx   → bugfix urgent langsung dari main
```
**Tidak ada commit langsung ke `main`** — selalu via PR + review.

### 2.2 Environment Bertingkat
- **Local** (laptop) → coding & unit test
- **Staging** (VPS terpisah / subdomain `staging.kliniknya.com`) → mirror production, **DB clone dari production yang sudah di-anonymize** (nama pasien, NIK, no HP di-mask)
- **Production** → yang dipakai klinik

Staging WAJIB ada. Jangan pernah test fitur baru langsung di production walau "cuma kecil".

### 2.3 Alur Development Fitur Baru
1. Buat `feature/nama-fitur` dari `develop`
2. Coding + test lokal (minimal happy path manual + unit test untuk service kritikal)
3. Push → buka PR ke `develop` → self-review / code-review
4. Merge ke `develop` → auto-deploy ke staging
5. **UAT di staging** bareng user (dokter/perawat/kasir relevan) → minimal 2–3 hari pemakaian nyata
6. Kalau lolos UAT → PR `develop` → `main`
7. Deploy ke production (lihat 2.5)

### 2.4 Database Migration — Bagian Paling Berbahaya
Aturan emas:
- **Migration harus backward-compatible** (additive only): tambah kolom nullable, tambah tabel baru, **jangan drop/rename kolom langsung**
- Kalau perlu rename: **2-step deploy**
  - Deploy 1: tambah kolom baru, copy data, app tulis ke dua-duanya
  - Deploy 2: setelah stabil 1–2 minggu, app baca dari kolom baru, drop kolom lama
- Untuk perubahan besar (alter tipe, drop tabel): **maintenance window** + announce ke klinik H-1
- **Selalu test migration di staging dengan DB clone production** dulu — kadang mulus di DB kosong tapi crash di DB 500 ribu row
- **Backup DB sebelum migrate** (otomatis di skrip deploy)

### 2.5 Deploy ke Production (Zero-Downtime)
Pakai pattern **atomic deploy** (Deployer / Envoyer / skrip manual):
```
releases/
  20260601-0900/  ← deploy baru
  20260530-1400/  ← deploy lama (rollback target)
current → symlink ke release aktif
```

Step skrip deploy:
1. **Backup DB** (`pg_dump` → S3)
2. Clone repo ke folder release baru
3. `composer install --no-dev`, `npm ci && npm run build`
4. Symlink `.env`, `storage/`
5. **Maintenance mode**: `php artisan down --render="errors::503"` (10–30 detik)
6. `php artisan migrate --force`
7. Pindahkan symlink `current` → release baru
8. `php artisan up`
9. Reload PHP-FPM (`systemctl reload php8.3-fpm`)
10. Restart queue worker (`supervisorctl restart laravel-worker:*`)
11. Smoke test otomatis (curl `/up`, hit 2-3 endpoint kritikal)

Total downtime: **< 1 menit**, biasanya 10–20 detik.

### 2.6 Feature Flag (Untuk Fitur Berisiko)
Untuk fitur besar (misal: integrasi BPJS baru, modul asuransi TPA), pakai flag:
```php
if (config('features.asuransi_tpa_enabled')) { ... }
```
Deploy code dulu dalam keadaan **OFF**, nyalakan via `.env` setelah yakin. Kalau bermasalah → matikan tanpa rollback code.

### 2.7 Rollback Plan
Setiap deploy harus punya **tombol panik**:
- **Code rollback**: symlink `current` balik ke release sebelumnya + reload FPM (< 30 detik)
- **DB rollback**: kalau migration bermasalah → restore dari backup pre-deploy (lebih lambat, 5–30 menit). Inilah kenapa migration harus backward-compatible — biar code bisa di-rollback **tanpa** rollback DB
- Latih skrip rollback **sebelum** beneran butuh

### 2.8 Jadwal Deploy yang Aman
- **Jangan deploy Jumat sore / sebelum libur panjang** (kalau ada bug, weekend repot)
- Klinik biasanya tutup malam → deploy jam **21:00–22:00** weekday
- Untuk fitur besar: koordinasi sama klinik, mungkin **Minggu pagi** (klinik tutup)
- Hindari deploy saat jam ramai (08:00–12:00, 16:00–19:00)

### 2.9 Monitoring Pasca-Deploy
30–60 menit setelah deploy, pantau aktif:
- **Error log** Laravel (`storage/logs/laravel.log`) atau Sentry — error rate naik?
- **Response time** endpoint utama — ada yang lemot?
- **Queue worker** — job stuck/failed?
- **DB**: query lambat baru? (pakai `pg_stat_statements`)
- **User feedback**: WA grup klinik, ada keluhan?

Kalau ada anomali → langsung rollback, jangan "tunggu sebentar mungkin hilang".

### 2.10 Hotfix Process
Bug critical di production:
1. Branch `hotfix/xxx` dari `main` (bukan dari develop)
2. Fix minimal — jangan sekalian refactor
3. Test di staging cepat (15–30 menit)
4. Deploy ke production
5. **Backport** ke `develop` biar tidak hilang di rilis berikutnya

### 2.11 Communication
- Changelog internal per release (apa berubah, dampaknya apa)
- Notif ke user klinik kalau ada perubahan UI/flow ("mulai besok tombol X pindah ke …")
- Untuk update besar: training singkat 15–30 menit

### TL;DR Alur Teraman
`feature branch` → PR → `develop` → staging → UAT user → PR → `main` → backup DB → maintenance mode singkat → migrate → atomic deploy → smoke test → monitor 1 jam → tenang.

Tiga hal yang paling sering bikin kacau di sistem live:
1. **Migration tidak backward-compatible**
2. **Deploy tanpa staging**
3. **Tanpa rollback plan**

---

## 3. Peran Claude Code dalam Testing Pra-Live

### 3.1 Yang Claude Code BISA Bantu

#### Static Analysis & Code Review
- Baca semua controller/service/store → cari bug logika (race condition, N+1, missing validation, RBAC bocor, SQL injection, XSS)
- Cek konsistensi: endpoint vs frontend call, payload shape, envelope response
- Audit migration: kolom nullable yang harusnya not-null, FK hilang, index kurang
- Cari dead code, duplikasi, fungsi yang tidak pernah dipanggil
- Pakai `/code-review` atau `/security-review` untuk diff yang sudah ada

#### Generate Test Otomatis
- **Feature test** Laravel (PHPUnit/Pest) untuk endpoint kritikal: admisi → perawat → dokter → kasir → farmasi flow
- **Unit test** untuk service kompleks: QueueService, KasirService::getPrice, consumeStock FEFO, consolidateBilling
- **Migration test** (rollback + re-migrate clean)
- Setup factory + seeder untuk data dummy

#### Manual Test Scripted
- **Checklist UAT** per modul (step-by-step yang user harus klik + expected result)
- **Skrip curl/Postman collection** untuk test endpoint end-to-end
- Bantu reproduce bug yang ditemukan → cari root cause → patch

#### Debugging Aktif
- Kasih stack trace / error log → Claude trace ke baris kode penyebab
- Live debug: tambah logging strategis, jalankan, baca output, perbaiki
- Pakai skill `/verify` untuk run app + observe behavior + screenshot

#### Audit Pra-Live Spesifik
Hal-hal yang sering kelewat:
- Semua endpoint sudah ada RBAC middleware?
- Semua input user di-validate? (request rules lengkap)
- File upload: size limit, mime check, path traversal?
- Mass assignment guard (`$fillable`/`$guarded`)?
- Query yang return ke user tidak bocorin kolom sensitif?
- Soft delete dipakai konsisten di model yang harus audit-able?
- Timezone consistency (server, DB, frontend)?
- Decimal/uang pakai tipe yang benar (bukan float)?
- `.env.example` lengkap, tidak ada secret ke-commit?

### 3.2 Yang Claude Code TIDAK BISA (Atau Terbatas)

#### True End-to-End Browser Test
- Tidak bisa benar-benar **klik UI Vue** seperti user nyata (kecuali via Playwright/Cypress yang kamu setup)
- Bisa generate test Playwright/Cypress, tapi kamu yang harus jalankan + lihat hasilnya
- Bisa pakai `/run` untuk launch app + ambil screenshot, tapi interaksi kompleks (multi-step wizard, drag-drop, print preview) terbatas

#### Test Hardware / Integrasi Eksternal
- **Printer thermal**: harus kamu colok printer + cetak nyata
- **BPJS VClaim production**: butuh consumer ID asli + data pasien asli — Claude tidak bisa
- **Satu Sehat production**: sama, butuh kredensial real
- **Kamera webcam** (foto pasien di kiosk): harus device fisik
- **Barcode scanner**, **kartu BPJS reader**: hardware

#### UX / Domain Validation
- Tidak tahu apakah alur kerja sesuai SOP klinik nyata — hanya dokter/perawat yang tahu
- Tidak bisa judge apakah label/istilah medis sudah benar
- Tidak bisa validasi "ini ergonomis untuk perawat yang lagi buru-buru?"

#### Beban / Performance Real
- Bisa kasih estimasi + optimasi query, tapi tidak bisa simulate 50 pasien bersamaan dengan data real
- Untuk load test: kamu pakai k6/Artillery, Claude bantu tulis skripnya

### 3.3 Strategi Realistis Pra-Live (3–4 Minggu)

**Minggu 1 — Audit & Hardening**
- Claude jalankan `/security-review` full project
- Claude audit RBAC matrix (23 modul × 69 permission) → cek tiap endpoint
- Claude audit validation rules tiap controller
- Fix temuan critical/high

**Minggu 2 — Test Otomatis**
- Claude generate feature test untuk 8–10 flow utama (admisi→kasir, antrian, bedah, farmasi, BPJS sync, dll)
- Claude generate unit test untuk service kritikal (QueueService, KasirService::getPrice, consumeStock)
- Run di CI (GitHub Actions) → fix yang gagal
- Target: minimal **70% coverage** service layer + 100% endpoint utama ke-test

**Minggu 3 — UAT Terpandu**
- Claude bikin **checklist UAT detail per role** (admisi, perawat, dokter, kasir, farmasi, bedah, manajer)
- Kamu + 1–2 user klinik jalankan checklist di **staging dengan data anonim production**
- Bug yang ketemu → log → Claude bantu fix → re-test
- Test edge case: kartu BPJS expired, retur obat partial, batal bedah di tengah, listrik mati saat transaksi (resume queue?)

**Minggu 4 — Integrasi Real & Soft Launch**
- Test integrasi BPJS/Satu Sehat dengan **sandbox production** (sudah dapat credential klien)
- Test printer thermal di lokasi klinik
- **Soft launch**: 1 hari operasional klinik **paralel** (sistem lama tetap jalan, sistem baru shadow) → bandingkan hasil
- Kalau cocok → cutover

### 3.4 Area Paling Krusial untuk Di-Test

Berdasarkan kompleksitas project ini, area paling berisiko bug:
1. **Queue transitions** (A→TR→D→P/B→K→F→SELESAI) — sentinel, panggil-ulang, auto-advance, race condition multi-user
2. **Billing consolidation** — multi insurer, TPA inheritance, diskon flex, paket bedah composite, BPJS skip otomatis
3. **Stock consume FEFO** — batch+expiry, retry 23505, generator nomor MAX+1
4. **PREOP_BEDAH flow** — 3 entry-point, shift jadwal, skip enqueue dokter
5. **Form Registry** — TipTap binding, aggregate resolver, parser docx
6. **Concurrent edit** — 2 perawat assess pasien sama, 2 kasir tagih invoice sama

Empat area ini wajib ada **integration test + manual UAT dengan skenario tabrakan**.

### 3.5 Kesimpulan

Claude bisa jadi **QA engineer + senior reviewer** yang produktif — cover ~70% kebutuhan testing pra-live (kode, logika, security, test otomatis). 30% sisanya (UAT user real, hardware, integrasi production, domain judgment) **wajib manual** dan butuh kamu + user klinik.

### 3.6 Urutan Mulai yang Disarankan
1. **Security review dulu** (paling cepat ketahuan red flag)
2. **Audit RBAC** (sering bocor & efek serius)
3. **Generate test suite** (jadi safety net buat development selanjutnya)
4. **Buat checklist UAT** (jadi panduan kamu + user)
