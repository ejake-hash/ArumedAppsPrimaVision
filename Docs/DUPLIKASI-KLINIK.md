# Panduan Duplikasi Klinik (Instalasi Terpisah)

Cara menggandakan Arumed Apps menjadi **instance klinik baru** yang berdiri sendiri:
server/DB sendiri, identitas (nama, logo, alamat, tema warna) berbeda, **mulai dari
database bersih** (hanya struktur + seeder dasar, tanpa data pasien/transaksi klinik lama).

> Verifikasi fakta repo (per 2026-05-29): DB produksi = **PostgreSQL**, folder frontend =
> `arumed-frontend/`, identitas dinamis disimpan di tabel `clinic_profiles`, tema warna
> bersumber dari `arumed-frontend/src/assets/styles/tokens.css`. Folder
> `backend/storage/app/public/` **tidak** ikut ter-clone (di-ignore git), jadi logo/file
> klinik lama otomatis bersih di hasil clone.

---

## Ringkasan langkah

| # | Langkah | Sentuh kode? |
|---|---------|--------------|
| 1 | Clone kode (tanpa node_modules/vendor/.env/data) | tidak |
| 2 | Backend: `.env` baru + DB Postgres baru + migrate & seed | tidak |
| 3 | Ganti identitas dinamis (`ClinicProfileSeeder` + upload logo via UI) | 1 seeder |
| 4 | Frontend: install + `<title>` + favicon | minimal |
| 5 | Ganti tema warna (`tokens.css`) | 1 file CSS |
| 6 | (Opsional) teks brand hardcode di ~10 `.vue` | manual |

---

## Langkah 1 — Clone kode

Jangan copy-paste folder mentah (akan ikut `node_modules`, `vendor`, `.env`, dan DB
klinik lama). Gunakan git:

```powershell
# dari folder induk
git clone "d:\apps- cl" "d:\klinik-kedua"
cd "d:\klinik-kedua"
```

Yang **tidak** ikut ter-clone (sudah di-ignore git, jadi aman): `.env`, `vendor/`,
`node_modules/`, dan seluruh isi `backend/storage/app/public/` (logo, foto pasien, media TV).

---

## Langkah 2 — Backend: env + database bersih

Engine yang dipakai produksi adalah **PostgreSQL**. Buat database kosong baru lebih dulu
(mis. lewat pgAdmin atau psql):

```powershell
# contoh buat DB via psql (sesuaikan user/host)
psql -U postgres -h 127.0.0.1 -c "CREATE DATABASE klinik_kedua;"
```

Lalu siapkan backend:

```powershell
cd "d:\klinik-kedua\backend"
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan jwt:secret
```

Edit `backend\.env` — minimal yang wajib diganti:

```env
APP_NAME=KlinikKedua
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=klinik_kedua
DB_USERNAME=postgres
DB_PASSWORD=__password_anda__
```

> Catatan: `.env.example` defaultnya `DB_CONNECTION=sqlite`. Untuk paritas dengan
> produksi, set `pgsql` seperti di atas.

Jalankan migrasi + seeder **dasar**:

```powershell
php artisan migrate --seed
```

`DatabaseSeeder` sudah dikonfigurasi bersih: `PatientVisitSeeder` (data demo
pasien/kunjungan) **di-comment**, jadi `--seed` hanya mengisi struktur dasar:
RBAC (role/permission), user awal, profil klinik, insurer sistem, integrasi, ICD-10/9,
jenis dokumen, mapping stasiun, jadwal dokter contoh, setting TV. **Tidak ada data
pasien/transaksi.**

### Akun login awal (dari seeder)

| Username | Password | Role |
|----------|----------|------|
| `superadmin` | `Superadmin@123` | Superadmin |
| `admisi`, `perawat`, `refraksionis`, `dokter`, `penunjang`, `farmasi`, `kasir`, `verifikator` | `888888` | sesuai nama |

> **Ganti password `superadmin` segera** setelah login pertama di instalasi nyata.

---

## Langkah 3 — Ganti identitas klinik (dinamis)

### 3a. Profil klinik (1 seeder)

Edit `backend\database\seeders\ClinicProfileSeeder.php` sebelum seed (atau ubah lewat UI
**Profil Klinik** setelah login):

```php
ClinicProfile::updateOrCreate(
    ['clinic_code' => 'KK'],               // kode klinik baru
    [
        'clinic_name' => 'Nama Klinik Baru',
        'clinic_code' => 'KK',
        'address'     => 'Alamat klinik baru',
        'phone'       => '(0xxx) xxxxxxx',
        'email'       => 'info@klinikbaru.id',
        // ...field lain biarkan default
    ]
);
```

Kalau seeder sudah terlanjur jalan, cukup edit dari **UI → Profil Klinik**, atau ulang:
`php artisan db:seed --class=ClinicProfileSeeder` (idempoten via `updateOrCreate`).

### 3b. Logo klinik

Tidak perlu sentuh file. Login sebagai admin → menu **Profil Klinik** → **upload logo**
(disimpan ke `storage/app/public/clinic/`, disk public, maks 2MB, png/jpg/svg/webp).
Logo otomatis dipakai di kop surat/cetak via `BindingResolver` (`{{clinic_logo}}`).

---

## Langkah 4 — Frontend

```powershell
cd "d:\klinik-kedua\arumed-frontend"
npm install
```

Ganti **judul tab & favicon** di `arumed-frontend\index.html`:

```html
<link rel="icon" type="image/jpeg" href="/favicon.jpg" />   <!-- ganti file favicon.jpg -->
<title>Nama Klinik Baru</title>                             <!-- ganti judul -->
```

Jalankan:

```powershell
npm run dev      # mode pengembangan
# atau
npm run build    # build produksi → folder dist/
```

---

## Langkah 5 — Ganti tema warna (1 file)

Seluruh warna brand bersumber dari **design tokens** di
`arumed-frontend\src\assets\styles\tokens.css`. Untuk ganti tema, ubah blok warna brand
di `:root` — **tidak perlu sentuh file `.vue` mana pun**.

Variabel brand utama:

| Var | Arti | Default (hijau Arunika) |
|-----|------|--------------------------|
| `--gd` | hijau/brand tergelap | `#0d3d2e` |
| `--gm` | brand medium | `#145e38` |
| `--ga` | **brand primer** | `#1f7d4a` |
| `--gl` | brand sangat muda (bg lembut) | `#eaf6ef` |
| `--gb` | border brand | `#d1ddd5` |
| `--lm` / `--ld` | aksen lime | `#8abf44` / `#5a8228` |

Contoh ganti ke tema **biru** (ganti ~7 baris saja):

```css
:root {
  --gd: #0a2a4d;
  --gm: #11487f;
  --ga: #1763d4;   /* primer */
  --gl: #eef4fc;
  --gb: #cdd9e6;
  --lm: #38bdf8;
  --ld: #0284c7;
}
```

Variabel semantik (`--sb/--eb/--wb/--ib` = success/error/warning/info) sebaiknya
**dibiarkan** — itu standar UX, bukan identitas brand.

> **Catatan jujur:** ~90% tema ikut `tokens.css`. Masih ada ~10% warna *hardcode* (mis.
> `#1763d4`, `color:#000 !important`) tersebar di sebagian `.vue` (badge, struk cetak) —
> peninggalan keputusan styling lama. Untuk presisi 100% perlu cari-ganti manual, atau
> refactor token (lihat Langkah 6).

---

## Langkah 6 — (Opsional) Teks brand hardcode di frontend

Nama "Klinik Mata Arunika" / "Arunika" masih tertanam di ~10 file `.vue`. Untuk klinik
baru, dua pilihan:

**A. Cari-ganti cepat** (cukup untuk satu instalasi) — ganti string di file-file ini:

- `src/views/LoginView.vue` (brand, tagline, footer, alt logo)
- `src/components/layout/AppSidebar.vue` (nama brand sidebar)
- `src/views/AnjunganView.vue` (kiosk + tiket cetak)
- `src/views/AntreanTVView.vue` (default `clinic_name`, placeholder)
- `src/views/AdmisiView.vue`, `PerawatView.vue`, `RefraksionisView.vue` (kop cetak)
- `src/views/DashboardView.vue` (judul dashboard)
- `src/views/KasirView.vue`, `RekamMedisView.vue` (footer cetak)

**B. Refactor sekali, rapi selamanya** (rekomendasi untuk template berulang) — buat satu
sumber identitas frontend (komposabel `useClinicIdentity()` yang baca dari API
`clinic_profiles` dengan fallback ke env `VITE_*`), lalu semua view membacanya. Setelah
ini, duplikat klinik berikutnya = cukup ganti DB, tanpa edit `.vue` lagi.

> Opsi B belum dikerjakan — minta dilanjutkan jika diinginkan.

---

## Checklist verifikasi pasca-duplikasi

- [ ] Login `superadmin` berhasil, password sudah diganti.
- [ ] Profil Klinik menampilkan nama/alamat klinik **baru**.
- [ ] Logo baru muncul di cetak/kop surat.
- [ ] Tidak ada data pasien/kunjungan lama (DB bersih).
- [ ] Tema warna sesuai brand baru (cek Login + Sidebar + tombol primer).
- [ ] `<title>` & favicon sudah diganti.
- [ ] Backend jalan (`php artisan serve`) & frontend terhubung (cek `APP_URL`/base API).
