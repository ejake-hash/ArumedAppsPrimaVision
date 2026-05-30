---
name: feature-dokter-demo-seeder
description: "Demo seeder pasien DOKTER: DokterDemoSeeder (3 penjamin) + SoapHistoryDemoSeeder (1 pasien, 3 kunjungan lama SOAP/CPPT/vital-history)"
metadata: 
  node_type: memory
  type: project
  originSessionId: 1c731dcd-e539-4037-a530-d799bda21d84
---

[DokterDemoSeeder.php](backend/database/seeders/DokterDemoSeeder.php) (dibuat 2026-05-30, run manual: `php artisan db:seed --class=DokterDemoSeeder`, terdaftar COMMENTED di DatabaseSeeder seperti [[feature-pre-golive-bug-audit]] BedahDemoSeeder).

**Apa yang diseed:** Untuk SETIAP dokter yang punya `DoctorSchedule` dengan `day_of_week == now()->isoWeekday()` (on-duty hari ini, `.unique('employee_id')`):
- 3 pasien WAITING di antrean DOKTER hari ini: 1 UMUM, 1 BPJS, 1 ASURANSI (profil hardcoded: Sumarni Hadi / Joko Prasetyo / Maria Gunawan, label "(Demo …)").
- Tiap pasien: **2 kunjungan lama finalized** (90 & 30 hari lalu, `current_station=SELESAI`) dengan `DoctorExamination` SOAP lengkap (soap_subjective/objective/assessment/plan + diagnosis_utama H25.1 + tindakan_codes ICD-9) → tampil sbg RIWAYAT di Rekam Medis RME (`RmeAggregatorService::kunjungan` baca soap_*).
- Kunjungan hari ini: `NurseAssessment` (vitals, finalized) + `RefractionRecord` (visus, finalized) supaya Tab 1/Pemeriksaan RME terisi.

**Syarat KRUSIAL agar tampil di antrean dokter:** `getPatientQueue` (DokterService) untuk non-superadmin filter `visits.doctor_schedule_id → doctor_schedules.employee_id == my employee_id` (TIDAK cek day_of_week/week_start). Maka tiap visit hari ini WAJIB set `doctor_schedule_id` = id jadwal dokter ybs. Superadmin lihat semua.

**Gotcha unique-constraint:** `patients.nik` UNIQUE **dan** `patients.bpjs_number` UNIQUE. Karena profil dibuat per-dokter, NIK & bpjs_number digenerate unik per (dokter,pasien) via suffix `docIndex+patIndex` + `crc32(employee->id)` (NIK 16 char, bpjs 13 char). BPJS hardcode '00099887' + suffix. Idempoten via firstOrCreate(nik) + firstOrNew(patient+visit_date / patient+visit_date+station).

**Model benar:** visus dokter baca relasi `visit.refractionRecord` = **RefractionRecord** (bukan RefractionPrescription — itu kacamata). DoctorExamination 1:1 per visit (`visit_id` unique) → "riwayat SOAP" = banyak visit lama, bukan banyak exam per visit.

Verified 2026-05-30: 2 dokter on-duty (Sabtu) × 3 = 6 antrean WAITING, idempotent re-run tetap 6, smoke 35/35. Insurer ASURANSI dev = "Admedika" (code ASR, is_system=0). Lihat [[dokterview-module]].

---

**[SoapHistoryDemoSeeder.php](backend/database/seeders/SoapHistoryDemoSeeder.php)** (dibuat 2026-05-30, run manual, COMMENTED di DatabaseSeeder) — 1 pasien "Kartika Sari (Demo Riwayat SOAP)" (nik 1271065208650199) dengan **3 KUNJUNGAN LAMA** (90/60/30 hari lalu, SELESAI) + 1 kunjungan AKTIF hari ini di TRIASE. Tujuan: menguji kartu SOAP/CPPT/"Riwayat Kunjungan".

Tiap kunjungan lama berisi: `NurseAssessment` finalized (TTV+keluhan beda-beda) + `DoctorExamination` SOAP finalized (S/O/A/P + dx H25.1 + ICD-9) + 1-2 `NurseCpptEntry`.

**3 sumber data berbeda yang di-feed (penting jangan tertukar):**
- **Kartu "Riwayat Kunjungan"** (PerawatView dropdown, `store.vitalHistory`) = `PerawatService::getVitalHistory` → **NurseAssessment finalized LINTAS-kunjungan** pasien (endpoint `GET /perawat/pasien/{id}/vital-history`, limit 20, desc). Inilah "history 3 kunjungan sebelumnya".
- **Riwayat SOAP** di Rekam Medis RME = `RmeAggregatorService::kunjungan` → DoctorExamination per visit, struktur `detail.soap.{s,o,a,p}` + `visit_date` + `is_finalized`.
- **Timeline CPPT** = `PerawatService::getCpptTimeline` **PER-VISIT** (bukan lintas-kunjungan); `nurse_cppt_entries` append-only, butuh `NurseAssessment` ada dulu (gate). Idempoten by visit_id+created_at.

Verified 2026-05-30: 4 visit (3 lama+1 aktif), getVitalHistory balikin 3 visit lama desc, RME kunjungan 3 SOAP finalized, CPPT 1/2/1, idempotent re-run (visits=4 cppt=4), smoke 35/35.
