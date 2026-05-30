---
name: feature-asuransi-cover-kasir
description: "Alur cover asuransi → kasir (full cover konfirmasi, partial bayar selisih) + tab \"Sedang Dilayani\""
metadata: 
  node_type: memory
  type: project
  originSessionId: 8008a28f-b98a-42d9-9d43-f28dde469e88
---

Fitur tanggungan asuransi/TPA pada tagihan (2026-05-29). Lanjutan [[feature-insurance-tpa]] & [[feature-kasir-view]].

**Kolom baru**: `billing_invoices.covered_amount/covered_by/covered_at` + `insurance_verifications.covered_amount` (migration `2026_05_30_200000_*`). `covered_amount` verif NULL = belum ditentukan; sync ke invoice via `AsuransiService::syncCoveredToInvoice` (skip kalau invoice PAID/CANCELLED, cap ke total).

**Alur**: Admin Asuransi input "Jumlah Ditanggung" di menu Asuransi (lihat rincian tagihan dulu via `GET /asuransi/billing/{visitId}`) → kasir: sisa pasien = total − covered − paid.
- Full cover (covered+paid ≥ total) → `POST /kasir/invoice/{id}/confirm-coverage` → PAID, `payment_method='INSURANCE'`, paid_amount=0. Method `KasirService::confirmInsuranceCoverage`.
- Partial → `bayar()` = selisih, metode bayar normal. `processPayment` isFullyPaid kini hitung `paid+covered ≥ total`.
- Umum (covered=0) → tak berubah.

**Tab baru AsuransiView "Sedang Dilayani"** (`tab==='dilayani'`): fix bug "data hilang setelah verifikasi" — tab Pending hanya status PENDING, begitu VERIFIED pasien lenyap. Tab ini = visit asuransi hari ini VERIFIED/ISSUE & current_station != SELESAI. Endpoint `GET /asuransi/verifikasi/in-service` (DAFTAR SEBELUM `/verifikasi/{visitId}` biar tak ke-shadow). Method `AsuransiService::inServiceVerifications`. Modal verif: panel rincian tagihan read-only + input cover + tombol "Full" + ringkasan selisih. Panel rincian: daftar item di `.bill-scroll` (max-height 220px, header sticky) + Total di `.bill-total-bar` DI LUAR area scroll (selalu terlihat) + badge "N item" — supaya tagihan banyak item tidak mendorong form cover ke bawah.

**Auto-draft klaim** (`maybeCreateInsuranceClaimDraft`): claim_amount = covered (kalau >0) else total; patient_responsibility = total − claim.

Store: `inServiceList`/`billingDetail` + `fetchInServiceVerifications`/`fetchBilling`. api: `asuransiApi.inServiceVerifications`/`getBilling`, `kasirApi.confirmCoverage`. RBAC tetap `kasir.*`. BPJS tidak disentuh.
