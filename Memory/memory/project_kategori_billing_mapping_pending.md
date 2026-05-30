---
name: project-kategori-billing-mapping-pending
description: PENDING diskusi — jadikan master Kategori Tagihan pusat + set relasi/pemetaan ke kategori billing lewat UI
metadata: 
  node_type: memory
  type: project
  originSessionId: 8008a28f-b98a-42d9-9d43-f28dde469e88
---

PENDING (2026-05-29, user harus pergi sebelum memutuskan). Lanjutan dari [[feature-kasir-view]] grouping kategori.

**Kondisi sekarang**: `billing_items.category` ditentukan saat `consolidateBilling` dengan pola "ambil dari field master, kalau kosong default":
- TINDAKAN ← `procedures.category` (default 'Tindakan')
- PENUNJANG ← `procedures.category` (default 'Penunjang')
- BHP ← `bhp_items.category` (default 'BHP')
- ALAT ← `medical_equipments.category` (default 'Alat Kesehatan')
- OBAT/IOL/REGISTRASI = hardcoded
`billing_categories` master = FLAT (name+sort_order, TANPA FK/child), cocok by-name (case-insensitive) untuk urutan/grup di kwitansi. Tak cocok → grup 'Lainnya' di akhir.

**Keinginan user**: master Kategori Tagihan jadi PUSAT, relasi/pemetaan di-set lewat UI (bukan field di tiap master + by-name).

**3 keputusan belum dijawab** (ditanya via AskUserQuestion, user skip semua):
1. Jenis relasi: per-item master / per-item_type / per-kategori-sumber → billing category?
2. Hierarki: flat vs parent→child?
3. Fallback item tanpa pemetaan: 'Lainnya' vs default per item_type dulu?

Saat user kembali: tanyakan ulang 3 ini sebelum desain/implementasi.
