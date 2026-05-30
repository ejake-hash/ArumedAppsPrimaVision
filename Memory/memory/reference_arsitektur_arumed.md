---
name: reference-arsitektur-arumed
description: "Peta arsitektur Arumed/PrimaVision — layer, angka terverifikasi, sumber detail (skill + file diagram)."
metadata: 
  node_type: memory
  type: reference
  originSessionId: e7376982-5522-493f-ae41-0da787688d19
---

Arsitektur Arumed Apps (HIS oftalmologi paperless, Laravel 13.8 + Vue 3.5). Pola: **Routes → Controller (thin) → Service (logic + integrasi) → Model**.

**Sumber detail (baca dulu sebelum tanya struktur):**
- Skill `skillarchitecturearumed` — folder tree lengkap, ±310 endpoint per modul, transisi antrean, RBAC matrix, Inventori/Tarif/Bridging. AUTHORITATIVE tapi angkanya agak ketinggalan (lihat di bawah).
- File repo `ARCHITECTURE.md` (root) + `Docs/ARSITEKTUR-DIAGRAM.md` (3 diagram Mermaid: high-level, request lifecycle, patient journey — dibuat 2026-05-30).

**Angka terverifikasi di working dir `D:\Arumed-PrimaVision` (2026-05-30), lebih besar dari skill:**
- 36 controllers · 34 services · 95 models · 149 migrations · 20 views · 17 Pinia stores.
- Selisih vs skill (27/28/72/96/18/11) = fitur setelah skill ditulis: Form Registry, RME revamp, Inventori (supplier/PO/GRN/harga + stok per-lokasi), Tarif & Paket Bedah, Bridging VClaim/Satu Sehat LIVE, Asuransi/TPA, Rujukan internal+eksternal, RBAC user mgmt.

**Pilar wajib:**
- `QueueService::advanceFromStation()` = SATU-SATUNYA orkestrator routing antar-stasiun + broadcast TV. Station-service hanya thin wrapper + gate domain. Lihat [[queue_advance_station_pattern]].
- `KasirService::getPrice` = resolver tarif sentral per-insurer, TPA-aware. Lihat [[kasir_getprice_resolve]].
- RBAC 23 modul × R/W/D, middleware role/permission, Superadmin bypass sentinel `["*"]`. Lihat [[feature_rbac_user_management]].
- Alur pasien: A → TRIASE+REFRAKSIONIS (paralel, gate AND) → D → P?/B?/K → F? → SELESAI; same-day-bedah rule.

Status per-modul lengkap ada di [[project_arumed]]. Instance ini = [[project_instance_arumed_primavision]] (DB `dbprimavision`, tema biru).
