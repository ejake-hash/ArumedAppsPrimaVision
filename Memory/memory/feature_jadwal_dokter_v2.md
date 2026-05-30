---
name: feature-jadwal-dokter-v2
description: "Jadwal Dokter v2 — per-minggu (week_start tanpa cron), BPJS/EKSEKUTIF, prefix antrian {poli_code}{room}, import CSV"
metadata: 
  node_type: memory
  type: project
  originSessionId: b0186036-c239-4e3f-b90b-a01ed611703f
---

Revamp JadwalDokterView + backend `doctor_schedules`, mulai 2026-05-29. Tabel `doctor_schedules` dapat 3 kolom baru:

- **`service_type`** `BPJS|EKSEKUTIF` (default BPJS). Satu dokter boleh punya 2 jadwal di hari sama (1 BPJS + 1 EKS), beda jam/ruang.
- **`week_start`** date = tanggal **Senin** minggu jadwal itu berlaku. Jadwal milik minggu tertentu, bukan pola abadi lagi.
- **`poli_code`** string(10) = kode pendek poli (GLA/EKS/KAT/RET) untuk **prefix antrian**. Nama lengkap tetap di `poliklinik` untuk tampilan.

**Transisi minggu TANPA cron**: `currentWeekStart()` = `Carbon::now('Asia/Jakarta')->startOfWeek(Carbon::MONDAY)->toDateString()`. `getAktifHariIni()` filter `week_start = currentWeekStart() AND day_of_week = isoWeekday()`. Minggu Senin lewat tengah malam → startOfWeek otomatis loncat. WIB wajib (lihat [[feedback-timezone-wib]]). Minggu belum di-import → kosong (tidak fallback ke minggu lama).

**Prefix antrian** model `queuePrefix()` = `{poli_code}{room}` (mis. GLA1; room kosong → kode saja "GLA"). Fallback `D{room}` lalu `D` kalau poli_code kosong (baris lama). Rancangan: karena GLA1 ≠ EKS1, BPJS poli Glaukoma rm1 & Eksekutif poli Eksekutif rm1 (room sama, poli beda) otomatis counter terpisah lewat mekanisme "per prefix per hari" yg sudah ada. **Tampilan tetap nama** (`poliklinik`) di TV/Admisi — pasien tak lihat kode.

⚠️ **BELUM DISAMBUNG**: QueueService masih pakai `room` saat bikin antrian (generateQueueNumber ~baris 60-61 `'D'.$room`; createForVisit ~baris 113 `$visit->doctorSchedule->room`). Jadi nomor antrian RUNTIME masih `D{room}`, BUKAN `{poli_code}{room}` — model queuePrefix() benar tapi belum dipakai QueueService. TODO: ganti sumber prefix di kedua titik itu ke `poli_code` (fallback ke 'D'+room kalau null). User sudah ditawari, belum dijawab.

**Constraint duplikat** jadi per `(employee_id, day_of_week, service_type, week_start)` (dulu cuma employee+day).

**Fitur UI**: 2 tab BPJS/Eksekutif (svc-tab; warna BPJS hijau #8abf44, Eksekutif biru #1763d4), selector minggu (default minggu ini), tombol "Salin ke minggu depan" (duplikat semua baris minggu tampil → week_start+7, anti-duplikat), Download Template + Import CSV (input file hidden + modal hasil import imported/skipped/errors). Modal jadwal: segmented Jenis Layanan + konteks minggu + 3 kolom (Kode Poli/Nama Poliklinik/Ruangan) + preview prefix live. **CSV header (urutan persis kode `JadwalDokterService::CSV_HEADER`)**: `nama_dokter, jenis, kode_poli, nama_poli, hari, jam_mulai, jam_selesai, ruang` (lookup dokter by nama case-insensitive; `jenis`/`hari` toleran sinonim+numerik; partial success + error per baris). Import target week = minggu yg sedang dipilih (store.weekStart).

**Endpoint baru** (`/jadwal-dokter`, route statik WAJIB di atas `/{id}`): GET `?week_start=&service_type=` (list per minggu+layanan), GET `/minggu-tersedia`, GET `/template-csv` (blob), POST `/import-csv` (multipart file+week_start), POST `/salin-minggu-depan`. Store baru: `weekStart`/`serviceType`/`availableWeeks` state + `setWeek`/`setServiceType`/`copyToNextWeek`/`importCsv`/`fetchAvailableWeeks`.

**Backfill**: 14 baris lama → week_start=2026-05-25 (Senin minggu ini), service_type=BPJS, poli_code NULL.

**Migration**: `2026_05_30_000010` (service_type+week_start+index) & `2026_05_30_000011` (poli_code). Pola enum = `string(N)` + komentar (Postgres-friendly, BUKAN enum() Blueprint). Laravel 13.9 `change()` native (no dbal). fputcsv/fgetcsv WAJIB 5 arg `(',', '"', '\\')` (konvensi project, hindari deprecation PHP 8.4).

Lihat [[dokterview-module]], [[feature-admisi-view]], [[feature-antrean-tv]] (consumer aktif-hari-ini — otomatis ikut filter minggu, tak perlu ubah; tapi jika minggu berjalan belum di-import, dropdown dokter Admisi & panel TV KOSONG). **Status: migration+model+service+controller+route+api.js+store+UI DONE & build Vite hijau (2026-05-29). SISA: sambung QueueService ke poli_code (lihat ⚠️ di atas).** Poin 1 (hapus dead code employees/fetchEmployees/import masterApi) juga DONE.

**Sumber daftar dokter = `Employee::whereHas('user.role', name='dokter')`** (line ~32 & 302 service). Bergantung pada `users.employee_id` (akun dokter HARUS tertaut Pegawai). Bug 2026-05-29: akun dokter tanpa employee (mis. "Eza") tak muncul + ganti nama di Data Pengguna tak tersinkron → DIPERBAIKI di [[feature-rbac-user-management]] (UserService sync `employees.name` + auto-buat employee untuk role dokter). **SISA: filter masih `name='dokter'` PERSIS → role `dokter umum`/`dokter anastesi` belum ikut muncul di jadwal (user pilih "nanti saja").**
