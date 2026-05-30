---
name: feature-nakes-profile-data-pengguna
description: "Input NIP/SIP/STR nakes di modal Data Pengguna — gap ditutup 2026-05-30, disimpan ke employees via update pengguna."
metadata: 
  node_type: memory
  type: project
  originSessionId: 0c0bda5d-3afd-48ad-a3b5-97dde29a39f0
---

Gap & fix (2026-05-30): Modal "Tambah/Edit Pengguna" di `DataPenggunaView.vue` dulu TIDAK punya input NIP/SIP/STR — padahal kolom `employees.nip/sip/str` sudah lama ada & `MasterDataController::storePegawai` sudah validasi sip/str (tapi UI pegawai tak pernah expose create/update; `pegawaiApi` di api.js cuma `list`+`show`). NIK Satu Sehat & bpjs_dpjp_code sudah ada UI; NIP/SIP/STR yang belum.

**Keputusan user**: field muncul hanya untuk **nakes klinis** (dokter, perawat, refraksionis, penunjang), disimpan **lewat update/create pengguna** (1 request via `UserService`), bukan endpoint pegawai terpisah.

**Implementasi**:
- Backend `UserService::syncEmployeeProfile($user, $data)` — tulis nip/sip/str ke employee tertaut (partial: hanya key yang hadir; '' → null). **Auto-buat employee** bila user belum punya TAPI ada salah satu nilai non-null (perawat/refraksionis/penunjang; dokter sudah ditangani `ensureEmployeeForDoctor`). Dipanggil di `create` & `update` (pakai `$user->fresh()`).
- `format()` + eager-load tambah `sip`,`str` (sebelumnya cuma nip).
- `UserController::store/update` validasi `nip` (unique:employees,nip, di update abaikan employee sendiri via `$ownEmployeeId`), `sip`/`str` (nullable max:50).
- Frontend `DataPenggunaView.vue`: computed `isNakes` (NAKES_ROLES includes-match), block ".nakes-box" Profil Tenaga Kesehatan (NIP/SIP/STR) di modal sebelum block PIN, init field di openNewUser/openEditUser, payload nip/sip/str hanya saat `isNakes`.

**CSV pipeline (2026-05-30, sesi sama)**: `CSV_COLUMNS` tambah `sip`,`str` (jadi name,username,email,role,is_active,nip,sip,str). Template notes diperjelas (nakes vs non-nakes, 2 contoh baris). Export tarik sip/str. Import: helper `isNakesRole()`; NIP cocok pegawai ada→tautkan; NIP baru → role nakes BUAT pegawai, role non-nakes DITOLAK (pesan jelas, jaga niat lama "link only"); sip/str non-null ditulis via `syncEmployeeProfile` (nakes saja, non-nakes diabaikan diam). Frontend CSV generic (template/export blob + render envelope created/skipped/errors) — TANPA perubahan frontend.

Verified: smoke 35/35, build hijau, E2E tinker (perawat+NIP/SIP/STR → employee auto profesi benar; admisi NIP tak dikenal → DITOLAK; admisi kosong → tak buat employee; export header `...,nip,sip,str`). Lihat [[dataview-rbac]] / [[project-arumed]].

**Sisa/diketahui**: Form `/master/pegawai` (storePegawai) masih tak punya UI sendiri — semua input nakes lewat Data Pengguna.
