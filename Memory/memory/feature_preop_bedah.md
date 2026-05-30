---
name: feature-preop-bedah
description: "Flow PREOP_BEDAH bypass DOKTER — Admisi auto-suggest banner (hari ini hijau / hari lain kuning + auto-shift), TR+REF wajib, tombol manual \"Kirim ke Bedah\" oleh perawat/dokter umum, BEDAH → KASIR → FARMASI. Implementasi 2026-05-29."
metadata: 
  node_type: memory
  type: project
  originSessionId: 7de13162-1df9-4918-8fa8-95b6c13ffa96
---

# Flow PREOP_BEDAH (Admisi → TR+REF → BEDAH → K → F)

Pasien dgn jadwal bedah hari ini bypass stasiun DOKTER. Diimplementasi 2026-05-29.

**Why:** Pasien yg sudah dijadwalkan bedah tidak perlu konsul ulang dokter spesialis — asesmen preop cukup oleh perawat atau dokter umum di triase. Refraksi tetap wajib (cek visus/IOP). Sebelumnya flow sama dgn regular (A→TR→D→BEDAH).

**How to apply:** Saat menambah fitur terkait queue/visit type/preop bedah, ingat 3 entry-point ke BEDAH:

| Skenario | Cara masuk | Flow |
|---|---|---|
| **A** Jadwal hari ini | Banner hijau di Admisi | A(PREOP) → TR+REF → tombol manual "Kirim ke Bedah" → BEDAH → K → F |
| **B** Jadwal hari lain | Banner kuning + auto-shift `scheduled_date` → today + audit log | Sama dgn A |
| **C** Tanpa jadwal | Flow regular A → TR → DOKTER, dokter pilih `planning=BEDAH` + tanggal hari ini | A → TR+REF → DOKTER → routing existing `nextAfterDokter` → BEDAH → K → F |

## Schema baru

- `visits.visit_type` (string 20, default `REGULAR`, enum logis `REGULAR|PREOP_BEDAH`)
- `visits.surgery_schedule_id` (FK nullable → `surgery_schedules`)
- Tabel `surgery_schedule_audit_logs` (id, surgery_schedule_id, old_date, new_date, reason, changed_by_id, changed_at) — trail untuk auto-shift skenario B
- Migrations: `2026_05_29_000001_add_visit_type_to_visits_table.php`, `2026_05_29_000002_create_surgery_schedule_audit_logs_table.php`

## Gotcha penting

**Auto-enqueue DOKTER ada di 3 tempat — semua harus skip kalau PREOP_BEDAH:**
1. `QueueService::nextAfterTriaseOrRefraksi()` → return `NO_OP` saat preop (transisi manual)
2. `PerawatService::checkReadyForDoctor()` → early-return `true` tanpa enqueue
3. `RefraksiService::checkReadyForDoctor()` (duplikat by design) → sama

Kalau ada tempat baru yg auto-enqueue DOKTER, ingat tambah cek `visit_type === 'PREOP_BEDAH'`.

**Tombol "Kirim ke Bedah" gate:** `PerawatService::kirimKeBedah($queueId)` — role check `user->role->name IN [perawat, dokter, superadmin]` (BUKAN `user->employee->role`). Validasi visit_type=PREOP_BEDAH + TR+REF finalize + anti-duplikat queue BEDAH hari ini. Endpoint: `POST /perawat/antrian/{queueId}/kirim-ke-bedah`.

**Auto-shift jadwal (skenario B):** Saat user pilih "Preop Hari Ini" untuk schedule yg `scheduled_date != today`, `AdmisiService::registerVisit()` update `scheduled_date=today()` dalam transaksi yg sama + insert audit log. Kalender bedah di tanggal asli jadi kosong, hari ini terisi.

## Dashboard "Bedah"

`AdmisiService::getDashboard().stat_cards.bedah_count` = `Queue` station=BEDAH + status=COMPLETED + hari ini (bukan lagi `Visit.classification='Pre-Op'`). Frontend `vpBedah` di AdmisiView ambil dari `admisiStore.stats.bedah` (single source of truth). Label kartu tetap "Bedah" (sesuai preferensi user 2026-05-29), tapi semantiknya = bedah yg sudah selesai hari ini. Cover semua skenario A/B/C.

## File kunci

- Backend: [QueueService.php#L371](backend/app/Services/QueueService.php) `nextAfterTriaseOrRefraksi`, [PerawatService.php](backend/app/Services/PerawatService.php) `kirimKeBedah` + `checkReadyForDoctor`, [AdmisiService.php](backend/app/Services/AdmisiService.php) `getJadwalBedahAktif` + `registerVisit` (auto-shift logic), [BedahService.php#L31](backend/app/Services/BedahService.php) `getPatientQueue` (format lengkap)
- Frontend: [AdmisiView.vue](arumed-frontend/src/views/AdmisiView.vue) banner preop, [PerawatView.vue](arumed-frontend/src/views/PerawatView.vue) badge + card "Kirim ke Bedah" + polling parallel-status 10s, [BedahView.vue](arumed-frontend/src/views/BedahView.vue) wire `/bedah/antrian` + polling 15s

## Terkait
- [[feature-bedah-view]] — BedahView UI yg di-wire (sebelumnya empty mock)
- [[feature-admisi-view]] — banner preop ditambahkan saat selectPatient
- [[feature-perawat-view]] — badge PREOP + card alt "Kirim ke Bedah"
