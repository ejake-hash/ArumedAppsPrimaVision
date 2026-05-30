---
name: dokterview-module
description: "Key non-obvious facts & tech-debt in DokterView.vue (station Dokter RME) — SOAP auto-sync, hardcoded PIN/ICD, incomplete finalize wiring"
metadata: 
  node_type: memory
  type: project
  originSessionId: 5641bfb5-f642-4eef-a28d-66fdbca0f847
---

[DokterView.vue](arumed-frontend/src/views/DokterView.vue) — station Dokter, RME besar (~3000 baris). 4 tab: `data` | `pemeriksaan` | `tindakan` | `soap`. Hal yang TIDAK terlihat dari sekilas baca:

**SOAP auto-sync (Tab 4) — pakai `watch` yang MENIMPA edit manual:**
- `S` ← `exam.anamnese` (Tab Pemeriksaan)
- `O` ← triase/RO/segmen/slitlamp via `objectiveText`; hanya item yang ada nilainya yang ditampilkan (`hasVal()` skip `null`/`'—'`/`''`). Vitals = TD + N + SpO₂ + T + **KGD** (fix 2026-05-28: KGD sebelumnya tidak ikut di-push, sudah ditambahkan).
- `P` ← e-resep (`rxList`) via `planningText`, format per baris `Nama, Signa, Posisi`
- Gotcha: karena `watch` overwrite penuh, mengedit S/P lalu mengubah anamnese/e-resep akan menimpa edit. Alur normal Tab 2→3→4 jadi tak mengganggu. (Diimplementasikan 2026-05-27.)

**Finalisasi + Planning Bedah DIPERSIST (revisi 2026-05-29):**
- `doFinalize()` sekarang: (1) `dokterApi.storeTab4(visitId, {...})` simpan SOAP S/O/A/P + `diagnosis_utama`(code) + `diagnosis_sekunder`[] + `tindakan_codes`(=ICD-9 codes, bukan tarif) + `planning` + `surgery_package_id`/`surgery_date`; (2) `dokterApi.finalize(visitId)` kunci `is_finalized`; (3) `store.selesaiAntrian` advance queue. Mapping planning UI→enum: `PULANG`→`PULANG_BEROBAT_JALAN`, `BEDAH`/`RUJUK` sama (`PLANNING_ENUM`).
- **Auto-create SurgerySchedule**: `DokterService::storePlanning` → `resolveSurgerySchedule()`. Planning BEDAH + paket + tanggal → buat `surgery_schedules` (status SCHEDULED, operation_room default = `ClinicProfile.operating_rooms[0]`) & set `examination.surgery_schedule_id`. Planning bukan BEDAH → schedule lama yg masih SCHEDULED di-CANCELLED + `surgery_schedule_id`=null. `surgery_schedule_id` eksplisit (preop flow) dihormati. Routing ke stasiun BEDAH hanya jika `scheduled_date`=hari ini (lihat `QueueService::nextAfterDokter`); tanggal lain → pasien via KASIR, kembali di hari operasi. BedahView baca schedule via `visit.surgerySchedule` ?? `doctorExamination.surgerySchedule` (skenario C).
- **FIX double-queue**: `finalizeKunjungan` DULU bikin baris Queue sendiri (BEDAH/FARMASI, prefix manual, TANPA advanceFromStation) → kalau dipanggil bareng `selesaiAntrian` pasien ter-enqueue 2×. Sekarang `finalizeKunjungan` HANYA set `is_finalized`/`finalized_at`; semua routing+enqueue milik tunggal `QueueService::advanceFromStation` (lihat [[queue-advance-station-pattern]]).
- **Validasi FE**: planning BEDAH wajib `surgeryPkg` + `surgeryDate` sebelum finalize.

**Tab 2 (pemeriksaan mata) DIPERSIST (revisi 2026-05-29):**
- `doFinalize` step (1) panggil `saveTab2()` SEBELUM `storeTab4` — krusial karena `storeExamination` (POST) lempar 422 bila record sudah ada, sedang `storePlanning` pakai `firstOrCreate` yang akan membuat record. Urutan: saveTab2 → storeTab4 → finalize → selesaiAntrian.
- `buildTab2Payload()` flatten state `exam` → kolom backend `sa_<key>_<od|os>` & `sp_<key>_<od|os>` (key: kornea/coa/iris/pupil/lensa & papil/macula/retina/vitreous) + `anamnese` + `slitlamp_notes`. **Segmen kosong dikirim `null` BUKAN `''`** — rule backend `nullable|in:Normal,...` menolak string kosong.
- `saveTab2()`: PUT bila `tab2Exists`=true, else POST; POST gagal 422 → fallback PUT (anti-race vs autosave Tab 3).
- `loadTab2()` + `watch(visitId, …{immediate})` prefill `exam` dari `dokterApi.showTab2` (read-back) & set `tab2Exists`. Watcher fire SETELAH `resetFormState()` (Vue post-flush) jadi `resetExam` lalu prefill — urutan benar. SOAP auto-sync (S←anamnese, O←objectiveText) ikut ter-regenerate dari exam yang di-prefill (by design).
- Data SOAP kini tersimpan DUA bentuk: terstruktur (`sa_*`/`sp_*`/`anamnese` via Tab2) + naratif (`soap_subjective/objective/...` via Tab4).

**PIN tanda tangan dokter — per-akun, verifikasi backend (revisi 2026-05-29, GANTI hardcode lama):**
- DULU `DOCTOR_PIN = '1234'` hardcoded + cek client-side. SEKARANG: PIN per-user di kolom `users.pin` (varchar 6, **plaintext** — kolom sudah ada sejak migration awal, model `User` sudah punya `pin` di `$fillable`/`$hidden`, TANPA cast hashed).
- `doSign()` di DokterView jadi **async** → `dokterApi.verifyPin(pin)` = `POST /dokter/verify-pin` → `DokterController::verifyPin` cek `$request->user()->pin` via `hash_equals` (PIN tak pernah dikirim ke browser). Pesan jelas bila PIN belum diatur. State `pinVerifying` untuk loading. Hint UI: "diatur admin di Data Pengguna".
- PIN diatur/di-reset di [[feature-rbac-user-management]] (DataPenggunaView). Konstanta `DOCTOR_PIN` sudah dihapus dari DokterView.

**Implementasi hardcoded lain (belum ditegaskan by-design):**
- `icd10DB` & `icd9DB` adalah array statis hardcoded di file, bukan dari API/master

**TTD digital = akun login (revisi 2026-05-29):**
- Identitas penandatangan TIDAK lagi hardcoded "dr. Andika P, Sp.M". Computed `signerName` (`auth.user.employee.name` ?? `user.name`, auto-prefix "dr. " bila belum) + `signerRole` (`employee.profession` ?? `role.display_name`, + "SIP: {sip}" bila ada). Dipakai di 2 blok template (pra-TTD & pasca-TTD).
- Auth payload sudah expose `user.employee.{name, profession, sip}` + `user.role.display_name` (lihat `AuthService::buildUserPayload` ~line 170).
- **Server-side**: `finalizeKunjungan` set `digital_signature` = "{employee.name} (SIP: {sip})" + `signature_timestamp=now()` + pastikan `doctor_id` terikat ke penandatangan. Jadi atribusi tanda tangan otoritatif di DB (kolom `doctor_examinations.digital_signature`), bukan cuma label UI.

**Kartu "SOAP / CPPT" (Tab Pemeriksaan sidebar) — WIRED 2026-05-30 (dulu selalu "0 kunjungan"):**
- BUG lama: `mapPatient` set `soapHistory: []` hardcoded → kartu `soapPages` selalu kosong walau DB ada riwayat. (Kartu "Riwayat Kunjungan" di patient bar [berbeda] sudah jalan; ini kartu LAIN di sidebar Tab Pemeriksaan.)
- FIX: ref baru `soapHistoryData`/`soapHistoryLoading`/`soapPageIdx` + `loadSoapHistory()` fetch `dokterApi.riwayatKunjungan(patientId)` = `GET /rekam-medis/pasien/{id}/kunjungan` (RME aggregator, auth:api saja tanpa permission khusus → dokter boleh). Mapper `_mapSoapHistory` ambil `detail.soap.{s,o,a,p}` per visit yang TERISI (visit aktif SOAP kosong otomatis ter-skip). `watch(selP.patientId, loadSoapHistory, immediate)`. `soapPages` kini baca `soapHistoryData` (bukan `selP.soapHistory` yg sudah dibuang). Loading state "Memuat riwayat SOAP…".
- Butuh `patientId` di mapPatient (ditambah, `p.id`). Verified: build hijau, pasien DokterDemoSeeder tampil 3 kunjungan ber-SOAP. CPPT lintas-kunjungan belum ditarik (cuma SOAP dokter); CPPT `nurse_cppt_entries` per-visit, bukan di RME aggregator. Seeder: [[feature-dokter-demo-seeder]].
- **Pager (revisi 2026-05-30)**: `soapPages` di-sort DESCENDING (idx 0 = kunjungan terbaru), `loadSoapHistory` reset `soapPageIdx=0` → default tampil **kunjungan terakhir**. Arah panah: **‹ KIRI = lebih baru** (`soapPageIdx--`), **› KANAN = lebih lama** (`soapPageIdx++`) — sengaja kanan utk mundur ke kunjungan sebelumnya (permintaan user). Teks di bawah tanggal = computed `soapPageLabel`: idx 0 → "Kunjungan terakhir", selainnya → "Kunjungan sebelumnya" (ganti format lama "Kunjungan {n} / {total}").

**Kartu "Pemeriksaan Penunjang" sidebar — riwayat per-kunjungan + buang preview billing (revisi 2026-05-30):**
- HASIL di sidebar Tab Pemeriksaan kini **dipaginasi per kunjungan** (pola sama SOAP/CPPT). Sumber = RME aggregator lintas-kunjungan `dokterApi.riwayatPenunjang(patientId)` = `GET /rekam-medis/pasien/{id}/penunjang` (return rows {visit_date, test_name, eye_side, summary, status, attachment_url, detail.expertise_data}). State `penunjangHistory`/`penunjangHistoryLoading`/`penunjangPageIdx` + `loadPenunjangHistory()` + computed `penunjangPages`(group per tanggal desc)/`currentPenunjangPage`/`penunjangPageLabel`. Pager: ‹ lebih baru · › lebih lama (idx0=terakhir), `watch(selP.patientId, …, immediate)`.
- **PENTING**: `hasilPenunjang` (current-visit) DIHAPUS — `loadPenunjangData` kini HANYA load order REQUESTED kunjungan aktif ("Dipesan"). `viewHasil(h)` reuse object dari history; `attachmentPath` di-isi `r.attachment_url` (modal cek regex ekstensi gambar pakai URL).
- **Card "Penunjang (sudah diperiksa)"** (preview tagihan di Tab 3) DIHAPUS total + state mati dibuang: `penunjangBilling`/`loadPenunjangBilling`/`penunjangSubtotal`/`estimasiTotal` + arm `loadPenunjangBilling()` di watcher visitId. `tindakanSubtotal` tetap (dipakai card Tindakan). Endpoint `dokterApi.penunjangBilling` masih ada di api.js tapi tak dipanggil lagi.

**Gotcha: dropdown absolute terpotong di dalam `.card overflow:hidden` (fix 2026-05-30):**
- `.card { overflow:hidden }` (untuk sudut membulat) MEMOTONG dropdown `position:absolute` yg mengambang keluar card → dropdown search tindakan tampak "menutupi/terpotong" di atas card E-Resep di bawahnya.
- FIX: card induk dropdown diberi class `card-dropdown-host` = `overflow:visible; position:relative; z-index:5` → dropdown mengambang penuh di atas card berikutnya, tak terpotong. Card Tindakan (Tab 3) pakai ini. Pola umum: card mana pun yg memuat dropdown/popover absolute butuh host `overflow:visible`.

**UI Tab 3 (Tindakan & Resep) — footer card (revisi 2026-05-30):**
- 3 card lockable dibungkus `.tab3-stack` (flex column gap:1rem) krn `pane-locked` memutus gap dari `.af` → dulu card menempel rapat. (Pola sama dipakai di Tab SOAP.)
- Card "Catatan untuk Kasir" yg full-width DIBONGKAR → jadi **footer card** `.tab3-footer` (full-width, border/radius sama card di atas): KIRI = Catatan Kasir kompak (`.kasir-note-inline`, label+textarea rows2+counter, `flex:1`), KANAN = `.tab3-action-group` (width 230px, flex column) berisi 2 tombol **seukuran sama** (`.tab3-btn` width:100%) — "Kirim ke Kasir" (success) di atas, "Lanjut ke SOAP & Diagnosis" (primary) di bawah. Tombol `btn-lg` lama diganti `.btn` standar (38px). Responsif <820px → kolom. State `kasirNote`/autosave tak berubah.

**UI Tab SOAP (Tab 4) — layout 2 kolom (revisi 2026-05-30):**
- Wrapper `.soap-dx-grid` = grid 2 kolom `1.35fr 1fr`, `align-items:stretch` (kedua kolom sama tinggi → margin bawah sejajar). Responsif <1024px → 1 kolom.
- KIRI: card SOAP vertikal (`.soap-stack`, S→O→A→P), badge huruf S/O/A/P inline di label (`.soap-fl` + `.soap-letter` 20px) — buang hack `margin-top:18px` lama.
- KANAN: `.dx-grid` (jadi flex-column) berisi card ICD-10 + ICD-9 + **Planning** (Planning DIPINDAH ke sini dari full-width bawah, mengisi area kosong di bawah ICD).
- Card Planning: `.plan-opts` jadi **grid 3 kolom kompak** (Pulang|Bedah|Rujuk, vertical-center icon+judul, `.plan-sub` di-`display:none`, centang absolute pojok kanan-atas). Judul dipendekkan: "Pulang Berobat Jalan"→"Pulang", "Jadwalkan Bedah"→"Bedah". Sub-form kondisional (tanggal kontrol/paket bedah/rujuk faskes) tetap render vertikal di kolom sempit.

**Tanda Tangan → tombol kecil + modal PIN (revisi 2026-05-30, GANTI card besar):**
- Card "Tanda Tangan Digital" besar (avatar+PIN inline) DIBONGKAR → **tombol kecil di sudut kanan** (`.sig-bar` justify-end → `.sig-mini-btn` "🔒 Tanda Tangan"). Setelah TTD → chip hijau `.sig-mini-chip` "✓ Ditandatangani · {ts}" + tombol "Hapus" (jika `!finalized`).
- Klik tombol → `openSignModal()` → **modal Teleport** `showSignModal` (`.sig-modal-box` max 380px): identitas dokter + input PIN + Batal/Tanda Tangan. `doSign()` (logika lama, `dokterApi.verifyPin`) hanya ganti `showPinForm`→`showSignModal`. State `showPinForm` lama DIHAPUS. Reset di `resetFormState`/`undoSign`. Gating finalisasi (`signed`) tak berubah.

**Yang SUDAH tersambung backend:** Tindakan & e-resep autosave (debounce 600ms) via `dokterApi.storeTindakan` / `storeResep`; order penunjang via `storeOrderPenunjang` + `kirimKePenunjang` (lihat [[penunjangview-module]]).

**E-resep: daftar obat = MASTER, stok = lokasi FARMASI (fix 2026-05-29):**
- BUG ditemukan: `DokterService::getDaftarObat` dulu **INNER JOIN** `inventory_prices` → obat yang belum di-set harga (HJA) HILANG TOTAL dari pencarian e-resep (di dev: 4 dari 5 obat tak muncul), tanpa penjelasan ke dokter. **FIX: ganti LEFT JOIN** → semua obat aktif muncul; yang belum diset harga `hja=0` tetap bisa diresepkan (konsisten dgn Tarif Tindakan yg pakai `getPrice` → 0). Harga final tetap resolve di kasir.
- Kolom **stok** di dropdown obat dokter dulu = `medications.stock` legacy (tak otoritatif, sering 0, menyesatkan). **FIX: ganti ke on-hand lokasi `FARMASI`** dari `inventory_stocks` via `leftJoinSub` (SUM qty_on_hand where location=FARMASI). Cuma INFO ketersediaan unit Farmasi; dokter tetap bisa resep walau 0 (dispensing/transfer urusan Farmasi, lihat [[feature-stok-per-lokasi]]). UI dropdown: label "Farmasi: N" hijau bila >0 / amber bila 0 (`.rx-stok.ok/.zero`). Daftar obat TIDAK difilter per lokasi — dokter meresepkan dari katalog, bukan dari stok.
- Konsumen `getDaftarObat` cuma DokterView e-resep (endpoint `GET /dokter/obat`). Verified: smoke 35/35, build hijau, stok target=25 muncul saat ada stok FARMASI.

**500 saat simpan resep oleh akun TANPA employee (fix 2026-05-29):**
- Gejala: `POST /dokter/kunjungan/{id}/resep` → 500. Muncul "saat klik Tindakan" karena autosave e-resep ikut jalan. **Tindakan TIDAK error** (`visit_services.performed_by_id` nullable), e-resep error (`prescriptions.prescribed_by_id` **NOT NULL**, FK employees).
- Akar: login sbg **Superadmin** (`users.employee_id=NULL`). `storePrescription` set `prescribed_by_id=$user->employee_id` (null) → **SQLSTATE 23502** NOT NULL violation. **Diperburuk**: controller `error($e->getMessage(), $e->getCode())` mengoper SQLSTATE `"23502"` sbg HTTP status → `InvalidArgumentException` (status 100–599 invalid) MENUTUPI pesan asli → 500 generik.
- **FIX 1** (keputusan user: blokir, jangan auto-null): `DokterService::storePrescription` guard di awal — kalau `items` tidak kosong & `!$user->employee_id` → throw 422 "Akun Anda tidak terhubung ke data pegawai/dokter… login dgn akun dokter." Pengosongan resep (items kosong) tetap diizinkan tanpa employee.
- **FIX 2** (cegah masking, lindungi semua caller): `DokterController::error()` clamp `$status` di luar 100–599 → 500. Jadi SQLSTATE tak pernah jadi HTTP status lagi.
- Verified: Superadmin+item → 422 jelas; Superadmin+kosong → OK (null); smoke 35/35. Catatan: `prescribed_by_id` tetap NOT NULL by design (resep wajib punya peresep valid).

**Tarif tindakan per-penjamin (Tab 3) — fix 2026-05-28:**
- Endpoint `GET /dokter/tarif-tindakan?visit_id=` → `DokterService::getTarifTindakan` → delegasi ke `KasirService::getPrice` (sentral, lihat [[kasir-getprice-resolve]]).
- Sebelum fix: `getPrice` masih query kolom `classification` yang sudah di-drop migration 2026_05_26_000011 → query gagal silent → semua harga Rp 0.
- Sesudah fix: resolve insurer dari `visit.insurer_id` (TPA-aware via `Insurer::tariffInsurerId()` = parent_id ?? id), fallback ke insurer sistem dari `guarantor_type` (UMUM/BPJS/SOSIAL), terakhir fallback UMUM.
- **Konsekuensi data**: visit lama dengan `insurer_id=NULL` ditolerir (fallback ke insurer sistem), tapi sebaiknya Admisi auto-link ke insurer sistem saat create visit untuk data bersih. Tariff untuk UMUM perlu diisi manual lewat Tarif & Paket → Metode Bayar → UMUM (saat ini banyak kosong → harga 0 untuk pasien UMUM).

**UI/UX revisi 2026-05-28 (Tab 3 & 4):**
- **Tab 4 Objektif**: KGD di `objectiveText` dibungkus `toInt(nd.kgd)` (sebelumnya raw decimal dari DB ikut tertulis di SOAP O).
- **Tab 3 Tindakan**: pola "Tambah Tindakan" collapsible diganti **search-driven dropdown**. Toggle button + panel list dihapus. Sekarang satu `<input>` search di top — dropdown hasil render saat `tindakanSearchFocus && tindakanSearch.trim()` non-empty. `filteredTarif` return `[]` saat query kosong (sebelumnya return full list) + `.slice(0, 50)` + hint "Menampilkan 50 teratas" bila penuh. Aman untuk 100+ item master tarif. Pakai `@mousedown.prevent` (bukan `@click`) supaya klik item tidak ditelan blur. Close-on-outside-click via `document.addEventListener('mousedown', _handleTindakanClickOutside)` di onMounted + template ref `tindakanSearchRef` (bukan v-click-outside directive). CSS class baru: `.tindakan-search-wrap/-field/-icon/-input/-clear/-drop/-hint`. State `showTarifList` masih ada tapi tidak dipakai di template (legacy, bisa dihapus nanti).
- **Tab 3 E-Resep**: refactor jadi CSS Grid `.rx-form-grid` (7 kolom: Nama·Qty·Jumlah·Signa·Durasi·Posisi·[+]). Tiap field punya `<label class="rx-fl">` di atasnya (uppercase, kecil) — label per-field, bukan satu header bar. Form muncul otomatis saat `newRx.medication_id` ter-set (langsung saat user `pickObat`). Tombol Tambah jadi ikon `+` saja, sejajar kolom lain. Responsive <1100px → 2 kolom (nama & btn full-width). Field Posisi `<select>`: `''` (— bukan tetes) / `OD (Kanan)` / `OS (Kiri)` / `ODS (Kedua)`. Default `newRx.posisi = 'ODS'` (sebelumnya `'Tetes ODS'` invalid). Helper `normalizePosisi()` map nilai lama DB (mis. `"Tetes ODS"` → `"ODS"`) saat load. Display meta `rx-row` pakai `.filter(Boolean).join(' · ')` agar posisi kosong tidak bikin trailing separator.
- **TDZ bugfix**: `watch(() => selP.value?.visitId, ...{ immediate: true })` untuk loadTarifTindakan+loadTindakanResep DIPINDAH ke setelah deklarasi `loadTindakanResep` (~line 666), karena callback `immediate` mengakses `tindakanList`/`rxList` yang di-declare lebih bawah → ReferenceError: Cannot access 'tindakanList' before initialization. Bug ini muncul setelah revisi sebelumnya menggeser line number.

**Read-only setelah finalisasi (revisi 2026-05-28 sore):**
- `isLocked = computed(() => signed.value || selP.value?.status === 'done')` — pasien COMPLETED otomatis read-only, walau `signed` di-reset oleh `resetFormState` saat pickPt ulang.
- `store.clearSelected()` di `doFinalize` DIHAPUS — pasien tetap terpilih setelah klik Finalisasi, dokter bisa langsung lihat Tab Tindakan & Resep (read-only via `pane-locked` opacity .65 + pointer-events:none).
- Saat klik pasien lain lalu balik ke pasien finalized: `loadTindakanResep` reload data dari backend, panel tetap locked karena `status === 'done'`.

**Tombol "Simpan & Kirim ke Kasir" Tab 3 (revisi 2026-05-28 malam):**
- Card baru **Catatan untuk Kasir**: textarea max 500 char, autosave via `watch(kasirNote, scheduleSaveResep)` → ikut payload `storeResep.notes` (kolom `prescriptions.notes` sudah ada di backend validation, nullable|string|max:500). Load ulang dari `presc.notes` di `loadTindakanResep`.
- Tombol `btn-success btn-lg` **"Simpan & Kirim ke Kasir"** disabled bila tindakan & resep kosong. Klik → modal `showSendKasirModal` (pattern Teleport to body + .modal-overlay/.modal-box-sm seperti penunjang modal).
- Modal teks: *"Tindakan dan resep akan dikirim ke **kasir** dan data tidak bisa diubah lagi."* + ringkasan jumlah + preview catatan. Tombol **YA, Kirim** (`btn-success`) & **Batal**.
- `konfirmKirimKasir()`: clearTimeout kedua autosave timer, `await saveTindakan()` + `await saveResep()` (flush debounce), set `tab3Sent = true`.
- **Penting**: `tab3Sent` HANYA mengunci UI Tab 3 (pane-locked + notice "sudah dikunci, lanjutkan ke SOAP" + tombol "Buka Kembali"). Pengiriman fisik ke kasir TETAP terjadi saat `doFinalize()` → `store.selesaiAntrian` (advance queue) di Tab 4. By design seperti ini supaya alur backend tidak berubah.
- Struktur template: outer `<div class="af">` (TIDAK pane-locked), inner `<div :class="pane-locked-if-locked">` wrap 3 card (Tindakan + E-Resep + Catatan Kasir), action row (`.tab3-actions`) di luar wrapper sehingga tombol "Lanjut ke SOAP" tetap clickable saat locked.
- State `kasirNote`, `tab3Sent`, `showSendKasirModal`, `sendingToKasir` semuanya di-reset di `resetFormState()`.

**Card antrean + button press (revisi 2026-05-28 malam):**
- Wrapper `.dokter > .qp > .qp-card` dipertahankan (banyak style RME bergantung), tapi *isi* queue card disamakan PerawatView: list pakai `<div role="list">` + `<div role="listitem">` (bukan `<template>`), `qi-time` pindah ke kolom kanan (margin-left:auto).
- Button Panggil sadar **recall**: `p.rawStatus !== 'WAITING'` → label "Panggil Ulang" + class `call recall` (amber #fef3c7/#b45309) + icon refresh; WAITING → icon telepon.
- `:disabled="pendingCallIds.includes(p.id)"` mencegah double-click. `pendingCallIds` array sudah ada di script, tinggal di-wire ke template.
- Tier-3 button press: `.q-act-btn:active:not(:disabled) { transform: scale(0.93); box-shadow: inset 0 1px 3px ... }` + warna press berbeda per varian (call/recall/resume/skip). `:disabled { opacity:.55; cursor:wait }`.

**Order Penunjang — staging lokal (revisi 2026-05-28 malam):**
- `orderPenunjang(t)` klik chip = toggle add/remove di array lokal saja (entry `_persisted:false`, id `tmp-{master.id}`). TIDAK ada API call per chip.
- `addCustomPenunjang()` juga staging lokal (id `tmp-custom-{Date.now()}`).
- `removePenunjang(id)` cek `_persisted`: staging cukup pop array; persisted pakai `cancelOrderPenunjang` API.
- `confirmPenunjang()` adalah **satu-satunya titik** yang benar-benar POST `storeOrderPenunjang` (loop semua staging) → lalu `kirimKePenunjang`. Sebelumnya order langsung dibuat per klik chip → bikin row Queue PENUNJANG sampah saat user batal pilih.
- Chip matching `penunjangOrders.find(x => x.name === t.name)` (by name, bukan id) karena id staging `tmp-*` ≠ id backend UUID. Label badge: "Dipilih" (bukan "Dipesan") agar reflect staging state.
- Cleanup: var `pendingOrderKeys` dihapus (tidak relevan lagi).

**Bugfix `Collection::load does not exist` ([DokterService.php:355](backend/app/Services/DokterService.php#L355)):**
- `collect($created)->load('procedure')` → `Collection::make($created)->load('procedure')`. `Illuminate\Support\Collection` (dari helper `collect()`) tidak punya `load()`; harus `Eloquent\Collection` (sudah di-import line 23). Trigger: autosave Tab 3 Tindakan (debounce 600ms).

**Form Registry placement — flexbox trap (revisi 2026-05-30):**
- `.rme-card` = `display:flex; flex-direction:column; overflow:hidden` ([DokterView.vue:2556-2560](arumed-frontend/src/views/DokterView.vue#L2556)). Anak: `.ptb` · `.dwr` · `.rmtabs` · `.rmc` (`flex:1; overflow-y:auto`).
- **JANGAN** tempatkan section baru sebagai sibling `.rmc` di dalam `.rme-card`. Flexbox akan membagi tinggi → `.rmc` terdesak jadi sliver kecil walau punya `flex:1`. Gejala visual: tab content cuma muncul 1-baris sliver, section baru "menggumpal" di bawah.
- ✅ Pattern benar: section yang muncul cuma di 1 tab → taruh **di dalam `<div v-if="tab === 'X'" class="af">`** (jadi anak `.rmc` yang scrollable).

**Form Registry → MODAL + launcher di patient bar (revisi 2026-05-30 sore, GANTI inline-stack):**
- DULU 3 `<FormSection>` (Resume Medis · Surat-Surat · Consent) ditumpuk inline di akhir Tab SOAP → halaman memanjang ke bawah / scroll panjang.
- SEKARANG isi modal `showFormDocsModal` = **`<FormDocsBrowser>`** (lihat entri di bawah), BUKAN lagi 3 `<FormSection>`. Modal: Teleport to body, reuse `.modal-overlay`/`.modal-box` + varian `.modal-box-forms` (max-width 760px). Banner `.rmd-form-banner` lama + CSS `.rme-form-registry-stack` DIHAPUS dari DokterView.
- Tombol launcher **"Dokumen RM"** ditaruh di patient bar (`.dbtns`, sebelah Triase/RO/Riwayat) → `v-if="store.selectedVisitId"` → tampil di **SEMUA tab** (bukan cuma SOAP). Class `.db-doc` (aksen biru #1763d4 + ikon dokumen). State `showFormDocsModal` di-deklarasi dekat `showPenunjangModal` (~line 524). CSS `.rmd-form-launcher*` sempat dibuat lalu DIHAPUS (dead) karena launcher card di Tab SOAP dibatalkan. Import DokterView: `FormSection` DIGANTI `FormDocsBrowser` (FormSection masih dipakai AdmisiView, jadi komponennya tetap ada).
- **Sifat tiap dokumen = per-template, BUKAN hardcode** (lihat [[feature-form-registry]]): `kind` template menentukan alur — `OUTPUT`=cetak saja (data auto), `INPUT`=wajib isi (field `required` divalidasi FE+BE) → Draft → Finalisasi & Lock, `HYBRID`=tab Isi Data + Preview/Cetak. **Tanda tangan wajib HANYA jika** template punya field `signature_canvas` ber-`required:true`; selama TTD wajib belum lengkap → tombol Finalisasi terkunci (`canFinalize`/`requiredSignersUnsigned` di [FormRMRenderer.vue:289-298](arumed-frontend/src/components/forms/FormRMRenderer.vue#L289)). FINALIZED = immutable, koreksi via Addendum (PMK 24/2022).

**FormDocsBrowser — daftar dokumen terpadu (BARU 2026-05-30 sore):**
- [FormDocsBrowser.vue](arumed-frontend/src/components/forms/FormDocsBrowser.vue) — isi modal Dokumen RM. Props `visitId`+`patientId`. Menggabungkan 3 section (`resume_output`/`surat`/`consent`) jadi SATU daftar: **3 fetch paralel** `Promise.all` ke `formTemplateApi.forms({station:'dokter',section,visit_id})` lalu merge (tiap form ditandai `_section`/`_sectionLabel`). FRONTEND-ONLY — endpoint `/rekam-medis/forms` mewajibkan `section` jadi tidak bisa 1 call (lihat RekamMedisController::indexForms validasi `required`).
- **Search bar sticky** (filter nama+kode, case-insensitive, tombol clear ×) + **chip filter status** (Semua/Perlu diisi/Draft/Final). `bucketOf(form)`: existing_document null → `todo` ("Perlu diisi"); `DRAFT/RENDERED/WAITING_SIGNATURE/PENDING_SIGNATURE` → `draft`; `FINALIZED/FINAL` → `final`; `VOID/REJECTED` → `todo` (bisa dikerjakan ulang).
- `groupedForms` = filter + group per SECTIONS (urutan tetap), group 0-hasil disembunyikan, ada counter per grup. Empty state: belum-ada-template vs tidak-cocok-filter (+ tombol "Reset filter").
- Tiap item = `<FormRMRenderer>` di-reuse APA ADANYA (logika buka/isi/validasi/TTD/finalisasi/cetak/addendum + badge status tidak diubah). Chip aktif #1763d4+`color:#fff` (per [[feedback-styling-visibility]]). Build verified hijau; live-test belum dilakukan.

**Topbar identitas dokter (revisi 2026-05-30):**
- Strip di pojok kanan atas panel RME (`.dv-topbar` > `.dv-doctor`), `v-if="isDoctorAccount"` → **hanya akun dokter** yang tampil. `isDoctorAccount` = profesi/role/role.display_name mengandung "dokter".
- `doctorName` (auto-prefix "dr. ") + `doctorSip` (`auth.user.employee.sip`, fallback "—") — sumber data = akun login (Data Pengguna → employee), bukan teks statis. Tulisan dipaksa `color:#000` (hitam). Komputasi dekat `myEmployeeId` (~line 18-34). Mirip pola `signerName`/`signerRole`.

**FormSection & FormRMRenderer style polish (2026-05-30):**
- (HISTORIS) `.rme-form-registry-stack` + banner `.rmd-form-banner` SUDAH DIHAPUS dari DokterView — diganti FormDocsBrowser (lihat di atas). `<FormSection>` kini hanya dipakai AdmisiView.
- [FormSection.vue](arumed-frontend/src/components/forms/FormSection.vue): section card dengan `SECTION_META` mapping `section` key → `{icon, subtitle}`. Mapping: `resume_output`=clipboard·biru, `surat`=mail·oranye, `consent`=shield·hijau, `identitas`=user·ungu. Subtitle bisa di-override via prop `subtitle`. Empty state ada icon + hint "Tambahkan di Master Form RM". MASIH dipakai AdmisiView (section `identitas`).
- [FormRMRenderer.vue:373-398](arumed-frontend/src/components/forms/FormRMRenderer.vue#L373) trigger `.frr-card` = 3-zone (leading icon tinted per mode · main name+meta inline · trailing status badge + chevron). Mode icon: OUTPUT=file-text/biru, INPUT=edit-3/oranye, HYBRID=layers/ungu. Hover: border biru #1763d4 + bg #f3f8ff + chevron translateX(2px). Modal tabs underline `#1763d4`, primary button `#1763d4` + `color:#fff !important` (visibility per [[feedback-styling-visibility]]).
- AdmisiView juga pakai `<FormSection>` (section `identitas`) — tidak terdampak negatif karena prop `subtitle` optional, fallback auto dari SECTION_META.

**Paket Bedah cascade kategori (Tab 4 Planning, 2026-05-29):**
- `surgeryPackages` BUKAN lagi hardcoded — `loadSurgeryPackages()` fetch `masterApi.paketBedah.list({active:1, per_page:200})` (response `data.data.data` paginator). Map `{id,code,name,category,price}`, paket tanpa kategori → "Tanpa Kategori".
- 2 dropdown cascade: **Kategori** (`surgeryCategory`, opsi dari `surgeryCategories` = Set unik category sort alfabet) → **Paket** (`surgeryPkg`, opsi dari `filteredSurgeryPackages` = filter by kategori, disabled sampai kategori dipilih). `watch(surgeryCategory)` reset `surgeryPkg`. `surgeryDate` field date di bawah.

**Jam Bedah + preview jadwal per tanggal (Tab 4 Planning, 2026-05-29):**
- **Jam bedah OPSIONAL** (`surgeryTime`, HH:MM). Dropdown slot 07:00–17:00 per 30 menit (`SURGERY_TIME_SLOTS`, dibangun sekali via IIFE). Jam yang sudah terisi pada tanggal itu ditandai `· terisi` + `disabled` (`bookedSurgeryTimes` = Set dari preview). Dikirim ke backend sebagai `surgery_time` di `doFinalize` (hanya saat planning BEDAH, else null). TIDAK ada guard wajib di FE (konsisten backend `nullable`). Backend `surgery_time` → `scheduled_time` di `surgery_schedules` (sudah ditangani `resolveSurgerySchedule` [DokterService.php:611](backend/app/Services/DokterService.php#L611)).
- **Preview jumlah pasien bedah per tanggal**: `watch(surgeryDate)` → reset `surgeryTime` + `loadBedahSlot(d)` → `dokterApi.bedahSlot(tanggal)` = **endpoint baru** `GET /dokter/bedah/slot?tanggal=` → `DokterController::bedahSlot` → `DokterService::getBedahSlot` balikin `{tanggal, total, slots:[{time,room,package_name}]}` (hanya status SCHEDULED, eager-load ringan `surgeryPackage:id,name`). UI: badge "**N** pasien bedah terjadwal" + chip jam·ruang (tooltip nama paket). State `bedahSlotInfo`/`bedahSlotLoading`, di-reset di `resetFormState`. CSS `.bedah-preview*`/`.bedah-slot-chip` (warna #1763d4 per [[feedback-styling-visibility]]).
- Endpoint baru ini berdiri sendiri; route lawas `/dokter/jadwal-bedah` (`indexJadwalBedah`/`storeJadwalBedah`) masih dirujuk di api.php TAPI method-nya TIDAK ADA di controller (dead route, 500 bila dipanggil — tak dipakai FE). Sinkron ke BedahView lihat [[feature-bedah-view]].

**Hasil Penunjang tampil lengkap di sisi dokter (Tab 2 sidebar, 2026-05-29):**
- `loadPenunjangData` map hasil: `kesimpulan`/`ringkasan` (dari `expertise_data`), `notes`, `biometri`(od/os utk test_type Biometri), `attachmentUrl`+`attachmentPath`, `status`. Modal "Lihat Hasil" render section terstruktur + tabel biometri OD/OS + preview gambar inline (klik → tab baru) / link PDF. Backend: `DiagnosticResult` punya accessor `attachment_url` (`$appends`, `Storage::disk('public')->url(attachment_path)`). Sidebar item ada ikon paperclip bila ada lampiran + badge "Diproses" untuk IN_PROGRESS. Order IN_PROGRESS juga dikembalikan `getHasilPenunjang` (whereIn COMPLETED, IN_PROGRESS).

Arsitektur lengkap & endpoint: skill `skillarchitecturearumed`.
