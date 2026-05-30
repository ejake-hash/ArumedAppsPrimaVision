---
name: feature-admisi-view
description: "AdmisiView.vue cleanup 2026-05-26 — buang field yang tidak disimpan backend, wire wilayah ke WilayahPicker (emsifa), penjamin filter by guarantor type, placeholder \"Segera Hadir\". +2026-05-28: stat cards per-penjamin pakai invoice PAID, tambah card Umum (7 card sebaris)."
metadata: 
  node_type: memory
  type: project
  originSessionId: d653f3e1-0270-418e-815d-cdc50743e42f
---

`arumed-frontend/src/views/AdmisiView.vue` direvisi 2026-05-26.

## Field form yang DIHAPUS dari wizard pendaftaran
Alasan: backend `daftarKunjungan` tidak menerima/menyimpan field-field ini:
- `familyPhone` — kolom tidak ada di tabel patients
- `companyName`, `companyNo` — sub-field PERUSAHAAN diganti dropdown `insurer_id`
- `socialOrg` — sub-field SOSIAL diganti dropdown `insurer_id`
- `diagnosis` ICD-10 — akan terisi otomatis saat SEP terbit via VClaim; ditandai "Segera Hadir"

(COB dulu placeholder "Segera Hadir" — SUDAH di-wire 2026-05-29, lihat section COB di bawah.)

**Why:** sebelumnya user input → silent ignored. Misleading data quality. Tunggu wiring backend.

## Wilayah → WilayahPicker
Swap dari hardcoded dataset ke `<WilayahPicker v-model:province/regency/district>` (emsifa external API via `services/wilayah.js`). Field form: `province` (string nama), `regency`, `district`, `addressDetail`. Tidak lagi pakai `kabupaten`/`kecamatan`/`kelurahan`.

## Penjamin dropdown (ASURANSI/PERUSAHAAN/SOSIAL)
**Source:** `masterApi.penjamin({ per_page: 200 })` (backend pakai `paginate(20)` by default — wajib unwrap `payload?.data ?? payload` di store karena response shape paginator `{data:[...], total, ...}`, BUKAN array langsung).

**Filter:** `filteredInsurers` filter `insurers.type === form.guarantor` (kolom `insurers.type`: ASURANSI/PERUSAHAAN/SOSIAL). Saat user ganti tipe penjamin, `setGuarantor()` reset `insurer_id`/`insuranceName`/`insuranceNo`/`insuranceSearch` agar tidak kirim insurer dari type berbeda.

**Tujuan bisnis:** pasien dengan ASURANSI/PERUSAHAAN/SOSIAL akan pakai tarif dari penjamin yang di-set di `tarif-paket/metode-bayar` (lookup via `visits.insurer_id`).

**Empty state:** "Tidak ada penjamin tipe {X} — tambah via Tarif & Paket → Metode Bayar"

## Print thermal antrean
Function `printAdmisiTicket({ queueNo, patientName, doctor, poliklinik, room })` di-trigger setelah `submitRegistration` sukses. Format 80mm sama dengan AnjunganView. Pakai `window.open()` + `w.print()` (bukan `@media print` body) karena print baru terjadi setelah submit, bukan saat view dirender.

## Placeholder "Segera Hadir"
[[feature-anjungan-kiosk]] pakai pola yang sama. Tombol "Sinkron Layar TV", General Consent (RM-0.1), Diagnosis Awal BPJS — disabled dengan badge `.badge-soon` kuning. (COB & "Cek Status BPJS" SUDAH di-wire — lihat section COB + Aksi Cepat di bawah.)

## 2026-05-30: Aksi Cepat (kanan) — Cek Rujukan + Cek Status BPJS LIVE ke VClaim
Kartu "Aksi Cepat" (kolom kanan) = 2 trigger button collapse (search bar default HIDDEN, muncul saat trigger diklik; state tunggal `quickPanel` '' | 'rujukan' | 'peserta', `toggleQuickPanel`). (1) **Cek No. Rujukan**: toggle FKTP/Antar-RS + input → `integrasiApi.cekRujukan({no_rujukan, sumber})` → `/integrasi/vclaim/cek-rujukan` (backend sumber `fktp`→checkRujukanFktp / `rs`→checkRujukan). Hasil peserta/diagnosa/poli/faskes/tgl + tombol "Daftarkan dengan Rujukan Ini" (`pakaiRujukanQuick` → `openWizard()` DULU krn reset form, lalu set guarantor=BPJS+sepType=rujukan+referralNo). State sendiri `rujukanQuick`, TERPISAH dari `rujukanCheck` form wizard. (2) **Cek Status BPJS** = Cek Peserta VClaim (DULU placeholder "Segera Hadir"): toggle NIK/No.Kartu → `integrasiApi.cekPeserta({identifier, type})` → `/integrasi/vclaim/cek-peserta`. Hasil nama/noKartu/hakKelas/status(active warna)/COB/jenisPeserta. State `pesertaQuick`. Envelope `data.is_success`+`response.{rujukan|peserta}`, 503 non-blocking. Lihat [[feature-bridging-vclaim]].

## 2026-05-30: Detail Pasien tab — Edit/Update data pasien
Tab "Detail Pasien" (modal Profil Pasien) dapat tombol "Edit Data Pasien" → mode edit inline (state `profileEdit`, `startProfileEdit`/`cancelProfileEdit`/`saveProfileEdit`). Field editable: name(wajib)/date_of_birth/gender/phone/blood_type/bpjs_number/province/address/allergy_notes. NO.RM + NIK read-only (`.cf-v.locked`). Wire ke `admisiStore.updatePasien(id, payload)` → `PATCH /admisi/pasien/{id}` (sudah ada sblmnya), per-field error 422 di-map (`e.errors`). Setelah simpan: `profilePatient = {...p, ...updated}` (merge — backend return `$patient->fresh()` incl. accessor `photo_url` jadi avatar tetap, lihat [[feedback-patient-photo-url-pick]]). `openProfile` reset `profileEdit.open=false`. Provinsi pakai input teks biasa (BUKAN WilayahPicker — patients hanya 1 kolom `province` + `address` free-text). No.Telepon dulu kelihatan "—" karena data kosong, bukan field hilang — edit ini mengisinya.

## PERAWAT station handling dihapus
Skill arsitektur hanya menyebut `TRIASE` & `REFRAKSIONIS`. Kalau muncul `PERAWAT` dari backend, fallback ke "Menunggu".

## Dead code dihapus
- `admisiStore.js`: `fetchDoctorSchedules`/`createSchedule`/`updateSchedule`/`deleteSchedule` + state `doctorSchedules`/`scheduleLoading`/`scheduleError`
- `api.js`: `admisiApi.jadwalDokter`/`storeJadwal`/`updateJadwal`/`deleteJadwal`

Endpoint `/admisi/jadwal-dokter*` sudah dipindah ke `/jadwal-dokter/*` standalone (skill Section 5.15) — pakai `jadwalDokterApi` + `jadwalDokterStore.fetchAktifHariIni()`.

## Bug fixes 2026-05-26

**v-show unmount crash** — wizard step pakai `v-if` BUKAN `v-show` karena kombinasi `v-show` + `<Transition modal-fade>` + komponen anak (WilayahPicker) menyebabkan crash `Cannot read properties of null (reading 'style')` saat tutup modal → aplikasi freeze. Detail di [[feedback-vue-vshow-unmount-bug]].

**Dokter dropdown hanya 1** — `UserSeeder` lama hanya bikin 1 user account `dokter` (link ke EMP-DOK-001). `JadwalDokterService::getAll` filter `whereHas('user.role', name='dokter')` → 2 dokter lain invisible. Fix: tambah `dokter2`/`dokter3` user di `UserSeeder.php` link ke EMP-DOK-002/003. Re-seed via `php artisan db:seed --class=UserSeeder`. Default credentials: `dokter2`/`888888`, `dokter3`/`888888`.

## Stat cards 2026-05-28: definisi "selesai" = invoice PAID
Stat per penjamin di dashboard admisi dihitung dari Visit hari ini yang punya `billingInvoice.status='PAID'` (sudah lunas di kasir), BUKAN total kunjungan dengan guarantor X. Tambah card "Umum" sebelah BPJS — grid `stats-row` jadi `repeat(7, 1fr)` (sebelumnya 6). Urutan: Total · BPJS · Umum · Asuransi/Lain · Bedah · SEP · Batal.

Backend: `AdmisiService::getDashboard()` → query `Visit::whereHas('billingInvoice', status=PAID)->groupBy('guarantor_type')`, lalu jumlahkan ASURANSI+PERUSAHAAN+SOSIAL untuk `asuransi_count`. Response tambah `umum_count`.

Frontend: `admisiStore.stats.umum`, `vpBpjs/vpUmum/vpAsuransi` semua baca dari `admisiStore.stats.*` (bukan lagi filter `vpAll` lokal).

**Why:** user mau lihat berapa pasien yang sudah selesai bayar per jenis penjamin, bukan total kunjungan. Card "Total Kunjungan" tetap pakai count semua visit hari ini.

**How to apply:** kalau definisi "selesai" mau diubah (mis. include PARTIALLY_PAID atau pakai `current_station=SELESAI`), edit query di `AdmisiService::getDashboard()` baris `$paidPerPenjamin`.

## 2026-05-29: Tgl lahir, SOSIAL→tarif, COB di-wire

**Tanggal lahir DD/MM/YYYY** — step Konfirmasi tampil `fmtDate(form.birthDate)`. **Input data pasien (step 1) diganti dari `<input type="date">` jadi input teks auto-mask DD/MM/YYYY** (user minta: type=date ikut locale OS, sering MM/DD/YYYY). `form.birthDate` TETAP ISO `YYYY-MM-DD` sbg source of truth (backend `date|before:today`, calcAge, submit tak berubah). Helper baru: `isoToDmy`, `dmyToIso` (validasi tanggal nyata, tolak 31/02 via roundtrip), `maskDmy` (sisip `/` otomatis). Ref tampilan `birthDateText` disinkron via `watch(()=>form.birthDate, immediate)` → prefill pasien lama & reset form auto-terisi. Invalid/belum lengkap → `form.birthDate=''` → `canProceedStep1` blokir lanjut + hint merah "Tanggal tidak valid". `@input="onBirthTextInput"` (bukan v-model lagi, pakai `:value="birthDateText"`).

**Penjamin SOSIAL → tarif** — TIDAK ada perubahan kode, sudah jalan: frontend kirim `insurer_id` utk SOSIAL, `KasirService::resolveTariffInsurerId` resolve SOSIAL ke insurer pilihan / fallback insurer sistem SOSIAL. Lihat [[kasir-getprice-resolve]].

**COB (Coordination of Benefits) — WIRED.** Rule: pasien bisa punya 2 penjamin. Penjamin2 SELALU tipe `ASURANSI`. **COB hanya muncul utk penjamin utama ASURANSI / PERUSAHAAN** (TIDAK utk SOSIAL/BPJS/UMUM — user minta SOSIAL dihilangkan 2026-05-29).
- Frontend `AdmisiView.vue`: state `cobEnabled/cobInsurerId/cobInsuranceName/cobInsuranceNo`, combo `cobInsurers` (filter `type==='ASURANSI'` & exclude `form.insurer_id`), `setGuarantor` reset COB bila pindah ke tipe non-ASURANSI/PERUSAHAAN, guard di `selectInsurer` cegah penjamin1==penjamin2, `canProceedStep2` wajib `cobInsurerId` bila enabled. Payload kirim `cob:{penjamin1_type, penjamin1_insurer_id, penjamin2_type:'ASURANSI', penjamin2_insurer_id, notes}`.
- Backend: validasi `cob.*` di `AdmisiController` (kedua: `daftarKunjungan` + `daftarkanWalkIn`); `cob.penjamin1_type` in `ASURANSI,PERUSAHAAN`, `penjamin2_type` in `ASURANSI`, `penjamin2_insurer_id` `different:cob.penjamin1_insurer_id`. `AdmisiService::saveCob($visitId,$cob)` (helper baru) `updateOrCreate` ke `visit_cob`, dipanggil di `registerVisit` + `daftarkanWalkIn`.

**Gotcha:** tarif/billing tetap pakai penjamin UTAMA (`visit.guarantor_type`+`visit.insurer_id`). `KasirService::calculateCOBDiscount` masih placeholder `return 0` — split plafon per penjamin BELUM diimplementasi (di luar scope wiring). COB tersimpan sbg data 2 penjamin saja.

## Bug fix 2026-05-28: walk-in `doctor_schedule_id` hilang
`AdmisiService::daftarkanWalkIn` validate `doctor_schedule_id` required di controller tapi `$visit->update([...])` tidak include field tersebut → visit walk-in selalu punya `doctor_schedule_id = NULL` meski user pilih dokter. Akibatnya `QueueService::enqueue(DOKTER)` fallback prefix ke `"D"` polos (bukan `"D{room}"`), dan AntreanTV flash tidak bisa resolve `{poliklinik}{ruang}` → fallback ke string `"Pemeriksaan Dokter"`. Fix: tambah `'doctor_schedule_id' => $data['doctor_schedule_id']` di array update visit pada `daftarkanWalkIn`. **Why:** field di-validate tapi tidak di-persist → silent data loss. **How to apply:** kalau ada visit lama dengan `doctor_schedule_id = NULL` + queue `D-NNN` (tanpa room), patch manual via tinker. Visit baru via walk-in setelah fix ini akan benar otomatis.
