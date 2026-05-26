# Arumed Apps — Build Progress

## Terakhir Update: 2026-05-18
## Posisi Sekarang: Phase 4 Backend API SELESAI SEMUA ✓ — Next: Phase 5 Frontend

---

## Phase 1: Database Migrations (Target: 65 tabel + 3 kolom follow-up)

### Core Migrations:
- [x] Batch 1:  clinic_profiles, roles, employees, users
- [x] Batch 2:  insurers, procedures, icd10, icd9, bhp_items, iol_items, medications, surgery_packages
- [x] Batch 3:  patients, visits (+ 3 kolom follow-up), queues, visit_cob
- [x] Batch 4:  procedure_tariffs, medication_tariffs, bhp_tariffs, iol_tariffs
- [x] Batch 5a: surgery_schedules, nurse_assessments, refraction_records, refraction_prescriptions, iol_recommendations
- [x] Batch 5b: doctor_examinations, visit_services, medical_resumes
- [x] Batch 6:  surgery_records, surgery_requests, surgery_request_bhp, surgery_request_iol, surgery_iol_usage
- [x] Batch 7:  diagnostic_orders, diagnostic_results, prescriptions, prescription_items
- [x] Batch 8:  billing_invoices, billing_items, bpjs_claims, claim_audit_logs
- [x] Batch 9:  tariffs, system_logs
- [x] Batch 10a: document_number_configs, document_types, document_templates, station_document_mappings, patient_documents
- [x] Batch 10b: document_verifications, notifications, medical_records, medical_records_versions
- [x] Batch 11a: integration_configs, bpjs_referrals_in, bpjs_referrals_out, bpjs_control_letters
- [x] Batch 11b: bpjs_vclaim_logs, bpjs_antrean_logs, bpjs_icare_logs, inacbgs_grouping_logs, satusehat_sync_logs, satusehat_resource_logs

## Phase 2: Models
- [x] Batch 1: Role, Employee, User, ClinicProfile
- [x] Batch 2: Insurer, Procedure, Icd10Code, Icd9Code, BhpItem, IolItem, Medication, SurgeryPackage
- [x] Batch 3: Patient, Visit (+ follow-up), Queue, VisitCob
- [x] Batch 4: NurseAssessment, RefractionRecord, RefractionPrescription, IolRecommendation, DoctorExamination, VisitService, MedicalResume
- [x] Batch 5: SurgerySchedule, SurgeryRecord, SurgeryRequest, SurgeryRequestBhp, SurgeryRequestIol, SurgeryIolUsage
- [x] Batch 6: Prescription, PrescriptionItem, BillingInvoice, BillingItem, BpjsClaim, ClaimAuditLog
- [x] Batch 7: DocumentType, DocumentTemplate, PatientDocument, DocumentVerification, Notification, BpjsReferralIn, BpjsReferralOut, BpjsControlLetter, IntegrationConfig, SatusehatSyncLog

## Phase 3: Seeders
- [x] RoleSeeder
- [x] EmployeeSeeder + UserSeeder
- [x] ClinicProfileSeeder
- [x] IntegrationConfigSeeder
- [x] ICD10Seeder + ICD9Seeder
- [x] DocumentTypeSeeder
- [x] StationMappingSeeder

## Phase 4: Backend API
- [x] Routes setup (api.php) — 140+ endpoints, semua modul, kebab-case, auth:api middleware
- [x] AuthController + AuthService — JWT login/logout/refresh/me + system_logs + changePassword
- [ ] AdmisiController + AdmisiService
- [x] PerawatController + PerawatService — antrian triase, asesmen TTV, finalize → parallel check → auto-queue Dokter, vital history
- [x] RefraksiController + RefraksiService — antrian, refraksi OD/OS, resep kacamata, IOL rekomendasi, finalize → parallel check, riwayat
- [x] DokterController + DokterService — Tab 1-4, follow-up KRITIS, medical resume auto-gen, inbox TTD sign/reject, order penunjang
- [x] PenunjangController + PenunjangService — order CRUD, hasil per test type, finalize → re-queue Dokter, Biometri → auto IOL rec
- [x] BedahController + BedahService — jadwal, time in/out, laporan, post-op, supply request BHP+IOL, IOL usage tracking
- [x] FarmasiController + FarmasiService — dispensing + stok deduct, surgery request BHP+IOL, assign IOL, kirim ke Bedah, stok alert
- [x] KasirController + KasirService — invoice consolidate, tariff fallback 3-level, COB, payment, receipt data, laporan
- [x] KlaimController + KlaimService — prepare, INA-CBGs grouping+log, LUPIS, workflow DRAFT→REVIEW→VERIFIED→SUBMITTED, audit log
- [x] RekamMedisController + RekamMedisService — search, timeline, dokumen CRUD, submit→notif, void, cetak PDF data, QR verify, medical record+versioning
- [x] DashboardController + DashboardService — stats, chart, top diagnoses, revenue, follow-up 3 widgets, stok alert, BPJS expired, Satu Sehat status
- [x] MasterDataController + MasterDataService — CRUD semua master + CSV import/export tarif 4 tipe + soft delete
- [x] IntegrasiController + IntegrasiService + 4 placeholder services (SatuSehat, VClaim, Antrean, INA-CBGs)

## Phase 5: Frontend
- [x] api.js + stores setup — axios + JWT interceptor, authStore, visitStore, queueStore, notificationStore, followUpStore
- [ ] Revisi 15 views existing
- [ ] DokterView.vue (INCLUDE: follow-up input di Tab 4)
- [ ] DashboardView.vue (INCLUDE: follow-up widgets)
- [ ] Views baru: MasterDataView, InboxTTD, IntegrasiView

## Phase 6: Testing
- [ ] Test per modul (Postman)
- [ ] Test end-to-end flow pasien
- [ ] Test follow-up flow (pasien dengan kontrol ulang)
- [ ] Test BPJS flow (mock)
