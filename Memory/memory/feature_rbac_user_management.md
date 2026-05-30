---
name: feature-rbac-user-management
description: "DataPenggunaView (modul RBAC) — CSV export/import pengguna, PIN tanda tangan dokter, reset password/PIN khusus superadmin"
metadata: 
  node_type: memory
  type: project
  originSessionId: 5dc4af71-8128-478d-816d-7c8329334a1d
---

[DataPenggunaView.vue](arumed-frontend/src/views/DataPenggunaView.vue) = UI manajemen RBAC, 4 tab: Daftar Role · Matriks Hak Akses · Pengguna · Audit Log (placeholder). Store [dataPenggunaStore.js](arumed-frontend/src/stores/dataPenggunaStore.js), endpoint `/rbac/*`. Permission key format `modul.action` (read/write/delete) ↔ matrix `{modul:['R','W','D']}` via `keysToPerms`/`permsToIds`. Superadmin = bypass (`permission_keys` berisi `'*'`), toggle dikunci.

**CSV Export/Import Pengguna (2026-05-29):**
- 3 tombol di toolbar tab Pengguna: Template · Export · Import (+ hidden file input).
- Backend: `UserService::csvTemplate()/exportCsv()/importCsv()`, `UserController::csvTemplate/exportCsv/importCsv`. Route `/rbac/users/csv-template`, `/rbac/users/export`, `/rbac/users/import` — **didefinisikan SEBELUM `/users/{id}`** agar tidak ketangkap wildcard.
- Kolom CSV: `name, username, email, role, is_active, nip`. **Password TIDAK ada di file** (auto-generate). Kolom `role` diisi **kode/name** role (bukan display_name), lookup case-insensitive. Baris diawali `#` = petunjuk, di-skip (`csvDataLines`). `nip` opsional → link ke `employees.nip`.
- Import: hanya **tambah** user baru. Duplikat username/email = **skip & lapor**. Password di-`Str::random(10)` per user. Hasil `{created[{name,username,email,password}], skipped[{row,username,reason}], errors[{row,username,reason}]}`. Modal `importResult` menampilkan password sekali + tombol "Salin Semua" (TSV). Pola CSV meniru [[feature-master-csv-pipeline]] (`#` notes, `str_getcsv` arg `,",\`).

**PIN tanda tangan dokter (2026-05-29):**
- Kolom `users.pin` (varchar 6, **plaintext**) — sudah ada sejak migration awal, model `User` sudah punya `pin` di `$fillable`/`$hidden` (TANPA cast hashed). Tidak perlu migration.
- Diatur admin di modal Edit Pengguna (field "PIN Tanda Tangan", 4–6 digit, validasi FE regex + backend `digits_between:4,6`). `format()` expose **`has_pin: bool`** (BUKAN nilai PIN). Update: key `pin` `''`/null = hapus PIN, angka = set.
- Verifikasi saat dokter TTD: `POST /dokter/verify-pin` (cek `auth user->pin` via `hash_equals`). Lihat [[dokterview-module]] (DULU hardcode `DOCTOR_PIN='1234'`).

**Reset Password & Reset PIN — KHUSUS SUPERADMIN (2026-05-29):**
- Password di-hash satu arah → **tidak bisa dilihat**, hanya reset (acak, tampil sekali). PIN plaintext tapi by-design **tidak ditampilkan** — hanya reset (`UserService::resetPin` = `random_int` 6 digit, tampil sekali via alert).
- 2 tombol di modal Edit Pengguna: Reset Password + Reset PIN, `v-if="editUser.id && auth.isSuperadmin"`. Gate **backend** `UserController::denyIfNotSuperadmin` (403) di `resetPassword` & `resetPin` — UI cuma menyembunyikan, backend yang menegakkan.
- Panel `.default-note` (superadmin) menjelaskan nilai default sistem. Route `PUT /rbac/users/{id}/reset-pin`.
- Deteksi superadmin FE: `auth.isSuperadmin` ([authStore.js](arumed-frontend/src/stores/authStore.js)). Backend: `User::isSuperadmin()`.

**Koneksi User↔Employee (fix 2026-05-29):** Dua tabel terpisah — `users` (akun login, dikelola Data Pengguna) vs `employees` (identitas medis: name+gelar/sip/str/profession/bpjs_dpjp_code, dipakai Jadwal Dokter/RME/TTD/billing). `users.employee_id` = penghubung (kolom "Pegawai" di tabel UI). **Modal Tambah/Edit Pengguna TIDAK punya field pemilih `employee_id`** (hanya `employee_nik` Satu Sehat, kondisional) → user dibuat via UI selalu tanpa employee. Akibatnya:
- **Bug "Jadwal Dokter belum konek":** (1) ganti nama dokter di Data Pengguna dulu hanya ubah `users.name`, Jadwal Dokter pakai `employees.name` → tak terlihat. **FIX: `UserService::update` sync `employees.name` saat `name` diubah & user punya employee_id.** (2) `JadwalDokterService` ambil dari `Employee::whereHas('user.role', name='dokter')` → user dokter TANPA employee (mis. "Eza") tak muncul (4 user dokter → 3 di jadwal). **FIX: `UserService::ensureEmployeeForDoctor` — saat create/update user role mengandung "dokter" tanpa employee_id, auto-buat `employees` (name=user.name, profession=role.display_name) + tautkan.** User existing tanpa employee perlu di-save ulang (atau backfill) untuk dapat employee.
- Helper dipanggil di `create` (punya `$role`) & `update` (resolve `Role::find($user->role_id)`). Hanya role dokter; perawat/admin dll TIDAK auto-buat. Verified: Eza→dapat employee, dokter baru→dapat, perawat→tidak; smoke 35/35.
- **Gotcha role:** tabel `roles` TANPA kolom `code` (identifikasi via `name`). Ada 3 role dokter: `dokter`/`dokter umum`/`dokter anastesi`. `JadwalDokterService` filter masih `name='dokter'` PERSIS → Dokter Umum/Anastesi belum muncul di jadwal (user pilih "nanti saja" 2026-05-29, BELUM diubah). `bpjs_dpjp_code` punya UI sendiri di `BpjsMappingModal` (jadwal-dokter), bukan di Data Pengguna. Lihat [[feature-jadwal-dokter-v2]], [[dokterview-module]].

**Belum:** validasi import belum cek format email per-baris ketat; tab Audit Log masih placeholder; PIN plaintext (kalau perlu aman → ubah `users.pin` ke varchar 255 + cast hashed + verify pakai `Hash::check`); **modal Data Pengguna belum punya dropdown pilih employee existing (auto-buat saja); filter Jadwal Dokter belum mencakup role dokter umum/anastesi.**
