---
name: feature-inventori-farmasi
description: Modul standalone Inventori Farmasi (/inventori-farmasi/*) — pindahan Obat/BHP/IOL dari Master Data. Permission baru `inventori_farmasi.*`. View komponennya tetap di views/master-data/ untuk hindari putus path internal — router yang reroute.
metadata:
  node_type: memory
  type: project
  originSessionId: f7fa0de3-9019-4de1-8fc4-e54c7f1a38b8
---

Modul standalone **Inventori Farmasi** di `/inventori-farmasi/*` — dibuat 2026-05-26 saat user minta Obat/BHP/IOL keluar dari Master Data. Lihat [[feature-master-data-stage1]] untuk konteks asal.

**Why:** Obat/BHP/IOL secara semantik adalah inventori operasional milik farmasi, bukan master data referensi. Pemisahan modul juga memungkinkan role granular: petugas farmasi dapat permission `inventori_farmasi.*` tanpa harus dapat akses `pengaturan.read` (Master Data).

**How to apply:** Untuk fitur baru yang touch Obat/BHP/IOL, gunakan path frontend `/inventori-farmasi/*` (sidebar utama section Operasional, dekat menu Farmasi). Endpoint backend tetap sama: `/master/obat`, `/master/bhp`, `/master/iol` (tidak dipindah — back-compat).

## Struktur

**Frontend**:
- [InventoriFarmasiLayout.vue](arumed-frontend/src/views/inventori-farmasi/InventoriFarmasiLayout.vue) — shell baru, mirror pattern `MasterDataLayout` dengan 3 tab (Obat, BHP, IOL).
- View komponen (`ObatView.vue`, `BhpView.vue`, `IolView.vue`) **tetap di folder [arumed-frontend/src/views/master-data/](arumed-frontend/src/views/master-data/)** — tidak dipindah secara fisik karena akan memutus path import `./_CrudResourceView.vue`. Router yang reroute via `import('@/views/master-data/ObatView.vue')`.
- [Router](arumed-frontend/src/router/index.js): block route `/inventori-farmasi` dengan `meta.permission = 'inventori_farmasi.read'`. Default redirect ke `/inventori-farmasi/obat`. Route lama `/master-data/{obat,bhp,iol}` **dihapus**.
- Section "Inventori" dihapus dari [MasterDataLayout.vue](arumed-frontend/src/views/master-data/MasterDataLayout.vue) sidebar.
- Link "Inventori Farmasi" di [AppSidebar.vue](arumed-frontend/src/components/layout/AppSidebar.vue) section **Operasional** (setelah Farmasi), guard `auth.can('inventori_farmasi.read')`.

**Backend RBAC**:
- Modul baru `inventori_farmasi` di [PermissionSeeder.php](backend/database/seeders/PermissionSeeder.php) — 3 permission (R/W/D). Total seeder sekarang 20 modul × 3 = 60 permission.
- Role `farmasi` di [RolePermissionSeeder.php](backend/database/seeders/RolePermissionSeeder.php) dapat `inventori_farmasi.{R,W,D}` full.
- Permission lama `master_obat / master_bhp / master_iol` **dibiarkan** (tidak di-drop) untuk back-compat data role yang sudah di-assign manual.

## Catatan operasional

- User yang sudah login sebelum re-seed perlu logout+login agar `permissions` di token JWT refresh.
- Superadmin lolos via sentinel `["*"]` — tidak perlu re-seed.
- Backend tarif obat/bhp/iol per-penjamin (`medication_tariffs`, `bhp_tariffs`, `iol_tariffs`) **tetap ada** tapi tidak diakses lewat UI di Metode Bayar (tab dihapus, hanya Tindakan). Bisa dihapus penuh nanti kalau confirmed tidak dipakai.

## Yang belum

- View Obat/BHP/IOL belum di-rebrand untuk konteks "Inventori" (judul masih "Master Obat", "Master BHP", "Master IOL"). Cosmetic — bisa update text di config `_CrudResourceView` props.
- Tidak ada cross-link ke modul Farmasi operasional yang sudah ada (`/farmasi`) — kalau farmasi mau lihat stok detail per item, harus pindah ke `/inventori-farmasi/{obat|bhp|iol}` secara manual.
