---
name: howto-test-satusehat-manual
description: "Prosedur tes manual end-to-end Bridging Satu Sehat di UI (sandbox): konfigurasi, KFA, NIK dokter, sync, verifikasi dashboard"
metadata: 
  node_type: memory
  type: reference
  originSessionId: 8398aec9-3f53-4042-a6c0-14a31040adf6
---

Cara tes manual Bridging Satu Sehat (sandbox) lewat UI Arumed. Integrasi LENGKAP — lihat [[feature-bridging-satusehat]]. Mulai server: `cd backend; composer dev` (backend+queue+log+Vite). **WAJIB cacert terpasang** [[feedback-php-ssl-cacert-windows]] (restart php setelah edit php.ini).

**0. Prasyarat sekali jalan (sudah dilakukan di dev, ulangi di prod):**
- Bridging → Konfigurasi → kartu **Satu Sehat**: isi Environment (sandbox), Organization ID, Client ID, Client Secret → **Simpan** → **Test Koneksi** (toast hijau "token diterima") → **Aktifkan**. Status badge "Aktif · Terhubung".
- (sandbox sudah: org_id `378de02d-...`, location_id `d96972fb-...`, NIK dokter uji `1271161411980001`→IHS `10010456878`, obat Cendo Xytrol KFA `93004665`).

**1. Isi NIK dokter** (Data Pengguna): edit user role=dokter yg punya employee → field "NIK Dokter (Satu Sehat)" → isi 16 digit (uji sandbox: `1271161411980001`) → Simpan Pengguna. Ganti NIK → IHS auto-reset.

**2. Isi KFA obat** (Inventori Farmasi → master Obat): tambah/edit obat → tombol **Cari KFA** → ketik nama → Pilih → kfa_code terisi → Simpan. Atau CSV import (kolom kfa_code). Obat tanpa KFA = di-SKIP saat sync (tidak gagalkan visit).

**3. Buat kunjungan lengkap** (alur normal): Admisi → … → Dokter isi diagnosis (ICD-10, mis. H25.0) + resep → Farmasi dispense (status DISPENSED utk MedicationDispense) → sampai `current_station=SELESAI`. **Pasien & dokter HARUS punya NIK yg resolve di Satu Sehat.** Di SANDBOX hanya NIK dummy resmi yg resolve (NIK asli→null, di-skip aman). Di PRODUKSI NIK asli resolve.

**4. Kirim & verifikasi** (Bridging → tab **Satu Sehat**):
- Lihat banner koneksi hijau + 4 stat-card (Kunjungan/Diagnosis/Peresepan/Obat Pulang).
- Klik **Sync Manual** → toast "Sync selesai: SUCCESS (terkirim N)". Kartu angka naik.
- Panel **Kesiapan Data**: peringatan dokter tanpa NIK / obat tanpa KFA / pasien tanpa IHS (klik link ke master).
- Tabel **Riwayat Batch**: status + tombol **Retry** utk PARTIAL/FAILED.
- Tab **Log Integrasi**: detail per-resource (payload/response).

**5. Otomatis (tanpa klik):** scheduler `satusehat:batch-sync` jalan 23:59 WIB (+ retry 01:00). Perlu cron OS jalankan `php artisan schedule:run` tiap menit. Manual: `php artisan satusehat:batch-sync`.

**Cara tes cepat via tinker (tanpa UI), 1 visit:** `app(SatusehatService::class)->boot(); ->syncVisitBundle($visit)` (visit di-load with patient/doctorExamination.doctor/prescriptions.items.medication). Idempoten: identifier sama → server tolak duplicate(20002) → auto-resolve encounter id. Untuk kirim ULANG visit uji: ganti `no_registrasi` jadi unik + set `satusehat_encounter_id=null`.

**Gotcha verifikasi:**
- HTTP 200 + entry `201 Created` = sukses; cek `entry[].response.resourceID`.
- Sandbox NIK: pasien dummy `9999999999999999`→IHS P02029536465; Practitioner `1271161411980001`→10010456878. `patients.nik` UNIQUE (1 NIK dummy = 1 pasien).
- Dashboard `visits.synced` pakai `visit_date` dalam rentang; kartu pakai `created_at` log.
- Cek statistik resmi di portal Satu Sehat sandbox (login akun faskes) — angka Encounter/Condition/Medication* naik.
