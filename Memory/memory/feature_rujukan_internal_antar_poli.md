---
name: feature-rujukan-internal-antar-poli
description: Fitur BPJS rujukan & dokumen — Rujuk Internal antar-poli (visit-anak), Rujuk Eksternal/Keluar (VClaim), Surat Kontrol (terbit otomatis), Edit Surat Kontrol & Edit/Update SEP (di modal Detail Kunjungan Admisi). 5 fitur LIVE; SEP Internal ditunda.
metadata: 
  node_type: memory
  type: project
  originSessionId: e9a5b5a6-c1ba-42c6-9bb6-20704f7211e0
---

# Rujukan Internal Antar-Poli (mis. Poli Mata Umum → Poli Vitreo-Retina)

Arumed ternyata **multi-poli/sub-spesialis** ([DoctorScheduleSeeder] "poliklinik = nama poli sub-spesialisasi": Poli Mata Umum/Katarak/Glaukoma/Retina). Sebelumnya **tidak ada konsep rujukan internal sama sekali** (satu-satunya "internal" = komentar `BpjsVClaimService::getSepInternal`). Fitur ini menambahkannya.

## Keputusan desain (user, 2026-05-30)
- **Poli BPJS BERBEDA** per sub-spesialis (bukan 1 poli "Mata") → tiap poli idealnya SEP/klaim sendiri. Maka pola **VISIT ANAK** (bukan 1:N examination).
- **Jadwal-aware**: dr. tujuan praktik HARI INI → visit anak langsung masuk antrean DOKTER; tidak praktik hari ini → visit anak jadi penanda `current_station=ADMISI`, **pasien daftar ulang** di hari praktik (petugas Admisi memunculkan; TIDAK ada auto-enqueue/scheduler).
- **Kenapa visit-anak, bukan 1:N**: `doctor_examinations.visit_id` UNIK & `billing_invoices.visit_id` UNIK (1:1 per visit) + RmeAggregator pakai `$v->doctorExamination` singular per visit. Jalur 1:N akan membongkar RME+billing+finalize (risiko tinggi) DAN bertentangan dgn BPJS poli-beda. Visit-anak = kebanyakan MENAMBAH, inti tak disentuh. Lihat [[dokterview-module]] [[feature-kasir-view]] [[feature-rekam-medis-rme]].
- **UI**: bukan kartu planning ke-4. Kartu **"Rujuk"** existing dijadikan **dua-mode**: Internal (antar-poli) vs Eksternal (faskes lain, perilaku lama `planning=RUJUK`).
- **Submit Internal = tombol sendiri** "Buat Rujukan ke Poli Ini" (BUKAN ikut "Simpan Planning") — aksi kreatif harus eksplisit & anti-duplikat; backend `rujukInternal` endpoint mandiri (bukan cabang storePlanning), jadi tak perlu guard anti-dobel.

## Backend (LIVE, smoke 35/35, build hijau)
- **Migration** `2026_06_01_000002_add_internal_referral_to_visits_table` (ditulis via Bash heredoc — file PHP baru di `d:\apps- cl` lewat Write tool bisa jadi lokasi-hantu, lihat [[feedback-php-ssl-cacert-windows]] catatan tooling). 3 kolom di `visits`: `parent_visit_id` (FK visits nullOnDelete), `internal_referral_from_schedule_id` (FK doctor_schedules), `internal_referral_reason` + index parent.
- **Model Visit**: fillable +3 kolom; relasi `parentVisit`/`childVisits`/`internalReferralFromSchedule`.
- **DokterService** (`app/Services/DokterService.php`):
  - `getRujukInternalTargets($visitId)` — semua `doctor_schedules` minggu ini (`DoctorSchedule::forWeek(currentWeekStart())` + `is_active`) KECUALI `visit.doctor_schedule_id` sendiri; tiap baris ada `is_today` (day_of_week == isoWeekday hari ini), `day_label`, start/end. Untuk picker.
  - `rujukInternal($visitId, $targetScheduleId, $reason)` — bikin Visit anak (copy patient/guarantor/insurer, `classification='Rujukan Internal'`, doctor_schedule_id=target, parent+from+reason, no_registrasi via `generateChildNoRegistrasi` = REG-Ymd-NNN withTrashed seperti AdmisiService). `is_today` → station DOKTER + `queueService->enqueue(child,'DOKTER')`; else station ADMISI tanpa enqueue. Return {child_visit, enqueued, target}.
  - helper `dayLabel()` (1=Senin..7=Minggu).
- **DokterController**: `rujukInternalTargets` (GET) + `rujukInternal` (POST, validate target_schedule_id uuid|exists + reason nullable).
- **Routes** (grup dokter, api.php): `GET /dokter/kunjungan/{visitId}/rujuk-internal/targets`, `POST /dokter/kunjungan/{visitId}/rujuk-internal`.
- **AdmisiController**: whitelist `classification` `in:...,Rujukan Internal` (2 tempat: register+walkin) agar visit anak bisa diproses ulang di Admisi.
- **Helper siap-pakai yg dipakai**: `DoctorSchedule::scopeAktifHariIni`/`currentWeekStart`/`forWeek`/`weekStartFor` (lihat [[feature-jadwal-dokter-v2]]). roles pakai kolom `name` (spatie), bukan `code` — cek superadmin via `$user->isSuperadmin()`.

## Frontend (LIVE, build hijau)
- **api.js** `dokterApi`: `rujukInternalTargets(visitId)` + `rujukInternal(visitId, data)`.
- **DokterView.vue** Tab 4: kartu "Rujuk" sub-label jadi "Antar-poli / faskes lain"; saat planning='RUJUK' muncul **2 tab mode** (`rujukMode` INTERNAL/EXTERNAL). Internal: `<select>` tujuan grouped `<optgroup>` per poli (`internalTargetsByPoli`), preview jadwal-aware (`rip-today` hijau #1f7d4a / `rip-later` amber #c47f17), tombol `.btn-rujuk-internal` (#1763d4 + text putih, lihat [[feedback-styling-visibility]]) → `submitRujukInternal` (toast hasil enqueued vs daftar-ulang). State di-reset di fungsi reset-pasien (`rujukMode='EXTERNAL'`). Visit id aktif = `store.selectedVisitId`.

## RUJUK EKSTERNAL (faskes lain) — VClaim Rujukan Keluar (LIVE 2026-05-30)
Kartu "Rujuk" yang sama punya 2 mode (`rujukMode` INTERNAL/EXTERNAL di DokterView Tab 4). Mode EXTERNAL = rujuk ke RS/faskes lain.
- **VClaim PUNYA rujukan keluar** (`BpjsVClaimService`): `insertRujukanKeluar` POST `/Rujukan/2.0/insert`, update/delete, list/show, jumlahSepRujukan, listSpesialistikRujukan. `t_rujukan` insert = {noSep, tglRujukan, ppkDirujuk(faskes), jnsPelayanan(1 Rinap/2 RJ), catatan, diagRujukan(ICD10), tipeRujukan(0 penuh/1 partial/2 rujuk balik), poliRujukan(kode poli), user}. Response → `response.rujukan.noRujukan`. **WAJIB noSep** → cuma pasien BPJS ber-SEP.
- **Kondisi AWAL (sebelum sesi ini)**: `storeRujukanKeluar` cuma simpan DRAFT lokal (TIDAK kirim VClaim) + frontend NOL wiring (mode EXTERNAL cuma catat `rujukFaskes`/`rujukAlasan` via storePlanning planning=RUJUK). Tabel `bpjs_referrals_out` + endpoint `POST /dokter/rujukan-keluar` + `GET /integrasi/bpjs/rujukan-keluar[/{id}]` sudah ada.
- **Yang dikerjakan (sesi ini)**:
  - Migration `2026_06_01_000003_add_vclaim_fields_to_bpjs_referrals_out` (+`poli_rujukan`,`poli_rujukan_nama`,`tipe_rujukan`,`jns_pelayanan`,`tgl_rujukan`) + fillable/cast model. ⚠️ heredoc PHP: JANGAN tulis `*/` di dalam komentar `/** */` (mis. `faskes_*/diagnosa_*`) — menutup blok komentar lebih awal → parse error. Tulis "faskes tujuan, diagnosa" biasa.
  - `DokterService::storeRujukanKeluar` DIPERLUAS + **inject `BpjsVClaimService $vclaim` ke ctor** (dulu cuma Request/QueueService/KasirService): simpan semua field; bila `guarantor_type='BPJS'` && `no_sep` && VCLAIM `is_enabled` → kirim `insertRujukanKeluar` **BLOCKING**, code≠200 → throw 422 (dokter lihat pesan BPJS), sukses → simpan noRujukan + vclaim_response + status `SUCCESS`. Non-BPJS → status `LOCAL` (tak hit VClaim). BPJS tanpa SEP / VCLAIM off → tetap `DRAFT`. Mapping faskes/poli dari INPUT dokter (bukan auto). DB transaction.
  - Controller validasi +5 field; pesan respons bedakan SUCCESS (No rujukan) vs lokal.
  - Frontend DokterView mode EXTERNAL **bercabang**: pasien BPJS (`selP.ptype==='bpjs'`) → form lengkap (cari Faskes via `integrasiApi.referensi('faskes',{q,jns:'2'})` + cari Poli `referensi('poli',{q})` + diagnosa prefill dari `diagnosisUtama` watch + tipe/jns/catatan) + tombol **"Terbitkan Rujukan BPJS"** → `dokterApi.rujukanKeluar` (blocking, toast noRujukan). Non-BPJS → form lama faskes+alasan (lokal, ikut Simpan Planning). State `rk` reactive + reset di reset-pasien. `integrasiApi` ditambah ke import DokterView. Picker pakai `.rk-search/.rk-result-list/.rk-picked`.
- **Status**: backend LIVE (smoke 35/35, jalur LOCAL teruji tinker; jalur BPJS-SUCCESS belum diuji nyata — butuh SEP asli + VClaim aktif). Frontend build hijau. Jalur BPJS sandbox-testable saat ada pasien BPJS ber-SEP.
- **Catatan**: `storeRujukanKeluar` response Satu Sehat-style? TIDAK — ini VClaim (`metaData.code`/`response`). insertRujukanKeluar bisa gagal kalau noSep/diag/faskes/poli salah → pesan BPJS tampil ke dokter (by design blocking).

## SURAT KONTROL BPJS (Rencana Kontrol) — terbit OTOMATIS saat finalisasi (LIVE 2026-05-30)
Tanggal kontrol = field "Tanggal Kontrol Berikutnya" di planning **Pulang** (PULANG_BEROBAT_JALAN) Tab 4. Padanan BPJS = Surat Kontrol / SKDP (supaya kunjungan kontrol berikutnya bikin SEP tanpa rujukan FKTP baru).
- **Backend SUDAH LENGKAP sebelumnya** (beda dari rujukan keluar): `BpjsVClaimService::postSuratKontrol` POST `/RencanaKontrol/v2/Insert` (+update/delete/get/list/SPRI). `IntegrasiService::submitSuratKontrol($letterId)` benar2 kirim VClaim (mapping dokter `bpjs_dpjp_code`+poli `BpjsPoliMapping::bpjsCodeFor`, payload {noSEP,kodeDokter,poliKontrol,tglRencanaKontrol,user}, parse `response.noSuratKontrol`, status SUCCESS/FAILED). 3 endpoint integrasi: `GET/POST /integrasi/bpjs/surat-kontrol[/{id}][/{id}/submit]`. DRAFT `BpjsControlLetter` dibuat auto oleh `DokterService::handlePlanningFollowUp` saat dokter set follow_up_date+BPJS.
- **Yang KURANG cuma UI** (frontend NOL wiring; surat-kontrol di AdmisiView itu INPUT pasif no.SK lama utk bikin SEP, bukan penerbit).
- **Temuan kritis alur**: `doFinalize` di DokterView = SATU tombol (saveTab2 → storeTab4 [bikin DRAFT] → finalize/kunci → advanceFromStation). TIDAK ada "Simpan Planning" terpisah. Jadi DRAFT baru ada saat finalisasi & tab langsung terkunci+pasien pindah station. **Keputusan user: terbit OTOMATIS saat alur finalisasi selesai** (bukan tombol manual).
- **Yang dikerjakan (sesi ini)**:
  - Backend DokterService +`getSuratKontrol($visitId)` (letter terbaru visit, authorizeVisitOwnership) +`submitSuratKontrol($visitId)` (replika logika IntegrasiService TAPI di DokterService pakai `$this->vclaim` yg sudah diinject — hindari nyeret IntegrasiService ctor yg besar; guard: SUCCESS→tolak, no_sep kosong→422, VCLAIM off→503, poli belum mapping→422; blocking, FAILED disimpan). +2 endpoint grup dokter `GET /dokter/kunjungan/{visitId}/surat-kontrol` + `POST .../surat-kontrol/submit`.
  - Frontend DokterView: `autoSubmitSuratKontrol(visitId)` dipanggil di AKHIR `doFinalize` (setelah toast sukses) **non-blocking** (try/catch, gagal→toast warning "bisa diterbitkan ulang dari Bridging", finalisasi tetap final) — HANYA bila `isBpjsPatient` && planning PULANG && tanggalKontrol terisi. Panel hasil di sub-panel Pulang (read-only): `sk-info` (sebelum, "akan terbit otomatis"), `sk-ok` (No. SK), `sk-fail`. `dokterApi.getSuratKontrol/submitSuratKontrol`. State `suratKontrol` reset di reset-pasien.
- **Status**: backend LIVE (smoke 35/35, getSuratKontrol teruji tinker), frontend build hijau. Jalur terbit-sukses ke VClaim belum diuji nyata (butuh SEP asli + VClaim aktif + poli ter-mapping).
- **Catatan**: kalau auto-submit gagal, letter tetap DRAFT/FAILED di DB → bisa di-resubmit nanti via UI Bridging (endpoint integrasi `submitSuratKontrol` masih ada, by letterId). UI daftar terpusat Bridging itu BELUM dibuat (opsi B yg tak dipilih).

## EDIT SURAT KONTROL BPJS di kartu detail Admisi (LIVE 2026-05-30)
Keputusan user: **SEMUA operasi edit/update BPJS disisip di AdmisiView, modal "Detail Kunjungan"** (yg sudah punya section SEP Terbitkan/Batalkan + section SKDP placeholder 2 tombol disabled). Mulai dari **Edit Surat Kontrol** (paling simpel: cuma ubah tgl kontrol).
- **VClaim sudah punya `updateRencanaKontrol`** (`PUT /RencanaKontrol/v2/Update`, payload {noSuratKontrol WAJIB, noSEP, kodeDokter, poliKontrol, tglRencanaKontrol, user, +formPRB opsional}). TAPI sebelum sesi ini **belum ada route/UI** (cuma method service).
- **Kondisi temuan**: operasi update SEP (`updateSep` PUT `/SEP/2.0/update`) → endpoint `PUT /integrasi/vclaim/sep` + `integrasiApi.updateSep` SUDAH ADA tapi **NOL UI**. Edit Surat Kontrol → method service ada, route belum. `updTglPlg` (RANAP) ada tapi tak relevan klinik mata RJ. Yang ada UI cuma create (Terbitkan SEP/SK) + cancel SEP.
- **Yang dikerjakan (Edit Surat Kontrol)**: backend di **AdmisiService** (bukan DokterController — biar konsisten `/admisi/bpjs/*` yg dipakai AdmisiView): `bpjsGetSuratKontrol($visitId)` (letter terbaru + prefill poli/dokter dari mapping) + `bpjsEditSuratKontrol($data)` (guard: hanya status SUCCESS + ada no_surat_kontrol; selain itu 422 "terbitkan dulu"; kirim updateRencanaKontrol; code≠200→422; sukses→sync tgl lokal). AdmisiService SUDAH inject BpjsVClaimService. 2 route: `GET /admisi/bpjs/surat-kontrol/{visitId}` + `PUT /admisi/bpjs/edit-surat-kontrol`. Frontend AdmisiView: `admisiApi.bpjs.getSuratKontrol/editSuratKontrol`, state `skAction`, load saat `openVisitDetail` (BPJS only), section SKDP placeholder DIGANTI fungsional (status badge Terbit/Draft/Gagal + tombol "Edit Tanggal Kontrol" HANYA jika SUCCESS → input date + Simpan/Batal). DRAFT→info "terbit otomatis saat dokter finalisasi"; FAILED→"resubmit dari Bridging".
- **Status**: backend LIVE (smoke 35/35, getSK+guard teruji tinker — guard nolak DRAFT via assertBpjsEnabled di env tanpa cred, guard status SUCCESS jalan saat VClaim aktif). Frontend build hijau. Update sukses ke VClaim belum diuji nyata.
- Edit SK hanya ubah TANGGAL (poli/dokter ikut mapping otomatis, tak diubah manual).

## EDIT/UPDATE SEP di kartu detail Admisi (LIVE 2026-05-30)
**PENTING: "Edit SEP" = "Update SEP" = SATU operasi VClaim** (`PUT /SEP/2.0/update`). BPJS tak punya operasi terpisah. Penamaan UI "Edit", teknis "Update". (3 op SEP yg beda jangan ketukar: update `/SEP/2.0/update` = ubah isi; `updtglplg` = tgl pulang RANAP [tak relevan RJ]; `delete` = batal [sudah ada UI "Batalkan SEP"].)
- Field editable payload penuh: klsRawat, diagAwal, catatan, noTelp, poli, cob, katarak, dpjpLayan. **User pilih RINGKAS** (5 field: kls_rawat, diag_awal, catatan, no_telp, katarak); sisanya (poli/dpjp) tetap dari mapping Jadwal Dokter (tak diubah manual). Bisa ditambah belakangan.
- Backend: `AdmisiService::bpjsUpdateSep($data)` (guard no_sep wajib; build t_sep ringkas + mapping poli/dpjp; updateSep; code≠200→422). Route `PUT /admisi/bpjs/update-sep`. `integrasiApi.updateSep`/endpoint `PUT /integrasi/vclaim/sep` lama TETAP ADA tapi tak dipakai UI (kita pakai jalur /admisi biar konsisten).
- Frontend AdmisiView: `admisiApi.bpjs.updateSep`, state `sepEdit` (form 5 field), section SEP modal detail: saat sudah ada noSep → tombol "Edit SEP" (samping Batalkan) → form ringkas collapsible (`vd-sep-edit`) + Simpan ke BPJS. `sepEdit.open` reset di openVisitDetail. **Prefill kosong** (field detail SEP tak di-cache lokal — dokter isi koreksi; bisa ditingkatkan baca SEP existing via vclaim nanti).
- **Status**: backend LIVE (smoke 35/35), frontend build hijau. Update sukses ke VClaim belum diuji nyata.
- **SISA**: prefill form Edit SEP dari data SEP existing (sekarang kosong); tambah field bila perlu.

## PENEMPATAN FINAL + cara tes (dikonfirmasi 2026-05-30)
User minta **semua operasi edit BPJS di satu tempat: AdmisiView → modal "Detail Kunjungan" pasien** (tombol detail per baris antrean). Tombol **kondisional** (sengaja, sudah benar — user setuju "tampilkan saat datanya ada"):
- **Edit SEP** muncul HANYA setelah SEP terbit (ada `no_sep`); sebelum itu yang tampil "Terbitkan SEP". Setelah terbit → "Edit SEP" + "Batalkan SEP".
- **Edit Tanggal Kontrol** muncul HANYA bila Surat Kontrol status SUCCESS. DRAFT→info "terbit otomatis saat finalisasi dokter"; belum ada→info "dibuat saat dokter set tgl kontrol planning Pulang".
- **Kenapa di screenshot user belum kelihatan**: SEP "Belum terbit" + SK "Belum ada" + **VClaim belum diaktifkan** (hint "aktif setelah bridging VClaim dinyalakan"). Lingkaran: VClaim off → SEP tak bisa terbit → Edit SEP tak muncul.
- **Cara tes (butuh VClaim sandbox aktif)**: 1) Bridging→Konfigurasi: paste credential→Test→Aktifkan. 2) Jadwal Dokter→Pemetaan BPJS (poli+DPJP). 3) modal detail: Terbitkan SEP→tombol Edit SEP muncul. 4) Surat Kontrol: via DokterView planning Pulang+tgl→finalisasi→SUCCESS→Edit Tanggal Kontrol muncul di Admisi.

## BADGE SISI PENERIMA — SELESAI 2026-05-30
"Tampilkan di UI" (badge "Rujukan dari Poli X") **SUDAH DIBUAT**. Backend eager-load relasi `internalReferralFromSchedule:id,poliklinik` di 3 query: `DokterService::getPatientQueue`, `AdmisiService::getAntrian`, `AdmisiService::getKunjunganById`. Frontend:
- **DokterView** patient bar: badge `.ptg-rujuk` (teal #cffafe/#0e7490) "↪ Rujukan dari {poli}" — field `internalRefFrom` di mapPatient (dari `v.internal_referral_from_schedule?.poliklinik`).
- **AdmisiView** modal Detail Kunjungan: banner `.vd-rujuk-banner` "↪ Kunjungan ini rujukan internal dari {poli}" (muncul bila `classification === 'Rujukan Internal'`). `internalRefFrom` ditambah di 3 mapper (antrean + 2 detail).
- Build hijau. (Pakai `classification` yg memang selalu ikut + relasi poli asal opsional.)

## SISA / belum
- **SEP Internal BPJS DITUNDA** (sengaja, "alur Arumed dulu"). ⚠️ method yg ADA di `BpjsVClaimService` cuma `getSepInternal` (GET) + `deleteSepInternal` (DELETE) — **belum tentu ada INSERT** SEP internal; WAJIB verifikasi `Docs/BRIDGING VCLAIM.md` sebelum janji. Tak ada route/UI BPJS SEP internal.
- **Cetak SEP A4** (di modal Admisi) — belum dibuat.
- **Prefill form Edit SEP** dari data SEP existing — sekarang KOSONG (dokter isi manual); bisa baca SEP via vclaim nanti.
- **Uji E2E semua jalur BPJS dgn VClaim sandbox** — semua jalur (rujuk eksternal/keluar, surat kontrol terbit, edit SK, edit SEP) BELUM diuji nyata; cuma build+smoke hijau. Butuh aktifkan VClaim sandbox + mapping poli/DPJP + pasien BPJS ber-SEP.
- Pasien UMUM yg dirujuk internal akan dapat **2 invoice** (1 per visit) — konsekuensi visit-anak; "tagihan gabungan" = fitur terpisah bila diminta.

## Gotcha sesi 2026-05-30 (insiden tooling + git)
- ⚠️ **JANGAN tumpuk puluhan tool-call paralel yang saling bergantung** — sempat bikin teks reasoning Claude BOCOR ke template AdmisiView (mengganti blok tombol Edit SEP). Gejala: build gagal + baris prosa ("Wait, that's not right...", "Let me reconstruct", lone `...`) di file. Fix: Edit blok rusak → kembalikan section SEP utuh. Pelajaran: kerja **1 langkah-1 verifikasi**, scan stray-prose + `npm run build` sebagai gerbang. Pulih total (build EXIT=0, smoke 35/35).
- **File HANTU dihapus**: `backend/backend/database/migrations/...000010_add_satusehat_nik_ihs_columns.php` (folder `backend` GANDA, duplikat dari `000011` yg sudah benar+migrated — sisa bug Write-tool path `d:\apps- cl`). `rm -rf backend/backend`. Migration asli `000011` aman.
- **Git BELUM di-commit** (user tunda): branch `fix/pre-golive-500-bugs`, ~69 modified + 31 untracked = akumulasi banyak sesi (BPJS VClaim+Satu Sehat+rujukan+inventori+dll). Semua lolos build+smoke. File lintas-fitur (api.js/routes/api.php/AdmisiView/DokterView/IntegrasiService) tak bisa dipecah per-hunk (git add -p tak ada di env). **JANGAN commit `.claude/settings.json`** (config harness lokal).

## Gotcha terverifikasi
- Queue number visit anak = `D{room}` (mis. D2-001) bukan `{poli_code}{room}` — itu **bug pre-existing** QueueService (lihat [[feature-jadwal-dokter-v2]] "SISA: QueueService masih D{room}"), BUKAN dari fitur ini.
- `doctor_examinations.visit_id` & `billing_invoices.visit_id` UNIK — jangan coba 1 visit 2 dokter.

## Gotcha terverifikasi
- Queue number visit anak = `D{room}` (mis. D2-001) bukan `{poli_code}{room}` — itu **bug pre-existing** QueueService (lihat [[feature-jadwal-dokter-v2]] "SISA: QueueService masih D{room}"), BUKAN dari fitur ini.
- `doctor_examinations.visit_id` & `billing_invoices.visit_id` UNIK — jangan coba 1 visit 2 dokter.
