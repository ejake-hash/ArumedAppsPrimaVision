---
name: feature_rekam_medis_rme
description: "Modul RME (RekamMedisView) revamp master-detail — agregasi seluruh aktivitas kunjungan, 8 menu, endpoint agregat"
metadata: 
  node_type: memory
  type: project
  originSessionId: 641c72d9-ea2b-4b61-b728-f114cd6f0828
---

Revamp RekamMedisView LENGKAP 2026-05-29 (dari halaman rusak → master-detail). Spec: docs/spec-rekam-medis-rme.md.

**Konsep**: RME = pusat seluruh aktivitas kunjungan pasien, diagregasi lintas waktu. Semua data digantung ke `visit_id`.

**Backend**:
- `app/Services/RmeAggregatorService.php` — 7 method (ringkasan/kunjungan/refraksi/penunjang/obat/bedah/diagnosis), tiap method return "1 baris = 1 kunjungan" + `detail` untuk expand. Cache ICD10/ICD9 lookup per-request.
- `RekamMedisController` + 8 route baru: `GET /rekam-medis/pasien/{id}/{ringkasan|refraksi|penunjang|obat|bedah|diagnosis|dokumen}`. `indexKunjungan` (`/kunjungan`) DIGANTI pakai aggregator (dulu paginator tipis → kartu kosong).
- Sumber data: refraksi=`refraction_records`+`refraction_prescriptions`; penunjang=`diagnostic_orders`+`diagnostic_results` (test_type=KODE, nama dari DiagnosticTestType); obat=`prescription_items` (DIRESEPKAN, bukan billing); bedah=`surgery_records` (join via visit, +`surgery_iol_usage`); diagnosis=`doctor_examinations` (ICD-10 utama/sekunder jsonb + ICD-9 tindakan_codes jsonb). Dokter via `doctorExamination.doctor` atau `doctorSchedule.employee`; poli via `doctorSchedule.poliklinik`.

**Frontend (master-detail)**:
- Kiri: nav 8 menu. Kanan: tabel (Ringkasan = kartu: alergi/visus-TIO tren/problem-list/kunjungan-terakhir). Klik baris → expand inline.
- Lazy-load per menu + cache di `cache.value[menu]`. Dokumen pakai paginator → unwrap `data.data.data`.
- Addendum PER-DOKUMEN (modal alasan+isi_koreksi → `POST /rekam-medis/document/{id}/addendum`, sudah ada di Form Registry — BUKAN per-kunjungan). Audit drawer → `GET /rekam-medis/document/{id}/audit-log`.
- Cetak resume A4 (`@media print` reset base.css + `#app{display:none}`, lihat [[feedback_print_a4_basecss_reset]]).
- Styling pakai warna hex eksplisit #1763d4/#000/#fff (lihat [[feedback_styling_visibility]]).

**Verified**: `npm run build` hijau; aggregator smoke-test tinker semua method OK terhadap DB nyata.

**Catatan dokumen RM**: menu Dokumen di RME = `patient_documents` (Form Registry). Sifat dokumen per-template (OUTPUT/INPUT/HYBRID, TTD wajib hanya jika ada field required) — selaras dgn DokterView yang sejak 2026-05-30 launch dokumen via MODAL (tombol "Dokumen RM"). Lihat [[feature_form_registry]] [[dokterview-module]].

Kepatuhan PMK 24/2022 dicek di spec §6. Lihat juga [[feature_refraksionis_view]] [[feature_penunjang_tarif_wiring]].
