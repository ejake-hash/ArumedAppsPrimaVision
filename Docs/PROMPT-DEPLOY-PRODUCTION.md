# Prompt Deploy Production — Arumed Prima Vision

**Tujuan:** Deploy aplikasi ke server PRODUCTION `192.168.100.20` (Ubuntu).
**Konteks penting:**
- Mesin `192.168.100.20` = **PRODUCTION**, sudah menjalankan **program lain di port 8000** (produksi aktif, dipakai user). **JANGAN diganggu.**
- DEV sudah terbukti jalan & test E2E di komputer lokal. Ini **penerapan ke production**, bukan uji coba.
- Aplikasi: backend Laravel 13 (PHP 8.3) di `backend/`, frontend Vue 3/Vite di `arumed-frontend/`.
- Database baru terpisah (PostgreSQL), user DB khusus, port web `:8080`, Nginx + PHP-FPM (bukan `php artisan serve`).

---

## ⚠️ Checklist SEBELUM jalankan prompt (lakukan dari PC lokal)

- [ ] **Commit & push kode terbaru ke `main` GitHub.** Per cek terakhir, `origin/main` masih versi 30 Mei dan ada ~100 file belum di-commit (RANAP, billing, dll). Kalau tidak di-push, server dapat versi lama.
- [ ] Pastikan file sampah TIDAK ikut ter-commit (`_blob_*.txt`, `_recovery/`, `hello.js`, `_scan_results.txt`, dll).
- [ ] Pastikan punya akses **sudo** di server.
- [ ] Pastikan tahu **nama database** yang mau dipakai (akan ditanya Claude saat di server).

---

## Peta Port (mesin 192.168.100.20)

| Layanan | Port | Keterangan |
|---|---|---|
| Program lama | `8000` | **JANGAN disentuh** — produksi aktif |
| Backend Laravel (PHP-FPM) | socket internal | tidak expose port langsung |
| Prima Vision (Nginx) | `8080` | akses browser + reverse proxy /api |
| PostgreSQL | `5432` | DB baru + user terpisah |

---

## PROMPT — tempel ke Claude Code di server

```
Saya menjalankan kamu di server PRODUCTION Ubuntu Linux. Bantu deploy aplikasi
"Arumed Prima Vision" (backend Laravel 13 + frontend Vue 3/Vite) dari nol.
Aplikasi ini SUDAH teruji jalan & test E2E di komputer lokal saya — jadi ini
penerapan ke production, bukan uji coba. Saya pemula urusan server: jelaskan
tiap langkah sederhana, kerja BERTAHAP, dan TUNGGU konfirmasi saya sebelum
menjalankan perintah yang mengubah sistem.

== KONDISI SERVER (PENTING) ==
- OS: Ubuntu Linux, IP: 192.168.100.20. Saya punya akses sudo.
- ⚠️ Mesin ini PRODUCTION dan SUDAH menjalankan program produksi LAIN di
  PORT 8000 yang dipakai user. JANGAN sentuh, ubah, restart, hentikan, atau
  ganggu apa pun yang terkait port 8000 / aplikasi itu / database-nya. Mutlak.
- Hari ini libur & program lama tidak dipakai, tapi tetap jaga jangan sampai
  rusak — besok harus tetap jalan normal.
- Sebelum apa pun: cek service & port apa saja yang sedang jalan (ss -tlnp,
  systemctl) supaya kita tahu apa milik program lama dan TIDAK menyentuhnya.

== REPO ==
- GitHub: https://github.com/ejake-hash/ArumedAppsPrimaVision.git  (branch: main)
- Struktur: folder backend/ (Laravel, butuh PHP 8.3 + Composer) dan
  arumed-frontend/ (Vue/Vite, butuh Node.js + npm).

== TARGET ARSITEKTUR ==
- Database PostgreSQL BARU & TERPISAH dari program lama:
  * Nama DB: SAYA SEBUTKAN NANTI saat kamu tanya (jangan putuskan sendiri).
  * Buat USER PostgreSQL baru khusus + password kuat, privilege HANYA ke DB itu.
    Jangan pakai superuser postgres untuk aplikasi.
- Web server: NGINX + PHP-FPM (BUKAN php artisan serve).
- Prima Vision diakses di http://192.168.100.20:8080 (pastikan 8080 kosong;
  kalau dipakai, lapor & usulkan port lain). Frontend dist + reverse proxy
  /api ke backend = SATU PORT 8080.
- Server block Nginx HARUS file baru terpisah di sites-available; TIDAK
  menyentuh config program lama.

== LANGKAH (bertahap, konfirmasi tiap tahap) ==
1. AUDIT dulu: OS version; cek PHP8.3/Composer/Node/npm/Nginx/PostgreSQL sudah
   ada atau belum; `ss -tlnp` untuk port terpakai (pastikan 8080 kosong);
   identifikasi service milik program lama agar tidak tersentuh. Beri ringkasan
   + rencana. JANGAN install/ubah apa pun dulu.
2. Install paket yang KURANG saja (php8.3-fpm + ekstensi: pdo_pgsql, mbstring,
   xml, curl, zip, bcmath, gd, intl; composer; nodejs+npm; nginx; postgresql).
   Kalau PHP/PostgreSQL sudah terpasang & dipakai program lama, JANGAN upgrade/
   downgrade versinya — konfirmasi ke saya dulu, cari cara yang tidak mengganggu.
3. Buat database + user PostgreSQL baru (tanya nama DB ke saya dulu).
4. Clone repo ke /var/www/arumed-primavision (atau usulkan path), branch main.
5. Backend (MODE PRODUCTION):
   - composer install --no-dev --optimize-autoloader
   - cp .env.example .env, set: APP_ENV=production, APP_DEBUG=false,
     APP_URL=http://192.168.100.20:8080, timezone Asia/Jakarta,
     DB_CONNECTION=pgsql + kredensial DB baru.
     (CATATAN: default repo DB_CONNECTION=sqlite — WAJIB ganti ke pgsql)
   - php artisan key:generate
   - php artisan migrate --force
   - SEEDER: TANYA saya dulu. Jalankan HANYA seeder esensial (Permission,
     RolePermission, master data). JANGAN PERNAH jalankan seeder demo
     (DokterDemoSeeder/BedahDemoSeeder/KasirDemoSeeder/RmeDemoSeeder) — ini
     production, data demo dilarang.
   - php artisan config:cache && php artisan route:cache
   - storage/ dan bootstrap/cache/ writable oleh www-data.
6. Frontend (arumed-frontend):
   - npm install
   - buat .env: VITE_API_URL=http://192.168.100.20:8080/api/v1
     (default repo localhost:8000 — WAJIB ganti; 8000 itu program lain).
     Kosongkan VITE_REVERB_APP_KEY (realtime off, fallback polling).
   - npm run build (hasil: folder dist).
7. NGINX: server block baru (listen 8080) di sites-available/primavision →
   symlink sites-enabled. root ke dist; /api dan rute Laravel di-pass ke
   php8.3-fpm (public/index.php). Backup (.bak) config apa pun yang diubah &
   tunjukkan diff. `nginx -t` sebelum reload. JANGAN sentuh config program lama.
8. Firewall: kalau ufw aktif, izinkan 8080 — konfirmasi dulu sebelum ubah ufw,
   dan jangan utak-atik aturan port 8000.
9. SMOKE TEST: buka http://192.168.100.20:8080, cek login muncul, /api/v1
   merespons, cek log Nginx & Laravel. Lalu PASTIKAN program lama port 8000
   MASIH JALAN NORMAL (cek ulang).
10. Buatkan deploy.sh untuk update ke depan: git pull main →
    composer install --no-dev --optimize-autoloader → migrate --force →
    config:cache → route:cache → npm install → npm run build → reload php-fpm.

== ATURAN KESELAMATAN (WAJIB) ==
- Tidak ada perintah yang mengganggu port 8000 / program lama / DB-nya.
- Jangan upgrade/downgrade PHP/PostgreSQL global yang mungkin dipakai program
  lama tanpa izin saya.
- Sebelum install paket / edit config global / systemctl / ufw: jelaskan &
  tunggu konfirmasi.
- Backup (.bak) config bersama sebelum diubah; tunjukkan diff.
- Ragu atau berisiko → BERHENTI & tanya saya.

Mulai dari LANGKAH 1 (audit) saja. Beri ringkasan kondisi server + rencana,
lalu tunggu persetujuan saya.
```

---

## Alur inject data (SETELAH aplikasi jalan)

Aturan: **uji di lokal/dev dulu → backup production → baru inject ke production.**

| Jenis data | Boleh di production? |
|---|---|
| Data demo (DokterDemo/BedahDemo/KasirDemo/RmeDemo) | ❌ TIDAK PERNAH |
| Master data (ICD-10, wilayah, tarif, obat, BHP) | ✅ setelah teruji |
| Migrasi data asli (pasien/visit Prima Vision lama, `migrasi:primavision`) | ✅ setelah verifikasi + **backup `pg_dump` dulu** |

Urutan aman inject ke production:
1. `pg_dump` backup DB production dulu (wajib).
2. Jalankan migrasi/import master yang sudah terbukti benar di lokal.
3. Verifikasi jumlah baris & cek orphan sama dengan hasil lokal.

---

## Update sistem ke depan (setelah deploy pertama)

Kode diubah di lokal → push ke GitHub → di server jalankan `./deploy.sh`.
Jangan pernah edit kode langsung di server (memicu konflik `git pull`).
File `.env`, folder `dist`, `vendor`, `node_modules` tidak ikut Git — itu sebabnya
`deploy.sh` menjalankan `composer install` & `npm run build` di server.
