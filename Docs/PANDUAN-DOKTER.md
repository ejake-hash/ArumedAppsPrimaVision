# Panduan Modul **Dokter (RME / Pemeriksaan Dokter)**

> Panduan penggunaan untuk dokter — RS Mata Prima Vision.
> Rute aplikasi: **`/dokter`** (menu sidebar **"Pemeriksaan Dokter"**, butuh permission `rme_dokter.read`).
> Sumber kode: [DokterView.vue](arumed-frontend/src/views/DokterView.vue) (4385 baris) + [DokterService.php](backend/app/Services/DokterService.php) (1589 baris). Diverifikasi dari kode 2026-05-31.

---

## 1. Gambaran umum

Modul Dokter adalah tempat dokter memeriksa pasien rawat jalan oftalmologi **setelah** pasien melewati **Triase/Perawat** dan **Refraksi**. Di sini dokter:

1. Memilih & memanggil pasien dari antrean,
2. Membaca data Triase + Refraksi (tab Data Pasien),
3. Mengisi **pemeriksaan mata** (anamnese + segmen anterior/posterior + slit lamp),
4. Menulis **tindakan** + **e-resep** (dikirim ke Kasir & Farmasi),
5. Mengisi **SOAP**, memilih **diagnosis (ICD-10)** & **tindakan (ICD-9)**, lalu memilih **Planning**,
6. Bila perlu: **order Penunjang**, **rujukan** (antar-poli / keluar BPJS),
7. **Tanda tangan** lalu **Selesai** → pasien otomatis lanjut ke stasiun berikutnya.

**Alur antrean** (otomatis): `A → (Triase + Refraksi paralel) → DOKTER → (Penunjang? / Bedah? / Ranap?) → Kasir → Farmasi → Selesai`. Routing ditentukan **terpusat** oleh `QueueService::advanceFromStation`; modul Dokter tidak menentukan stasiun berikutnya sendiri.

---

## 2. Tata letak layar

### Panel kiri — Antrean & status
- **Status Saya Hari Ini** ([DokterView.vue:1518](arumed-frontend/src/views/DokterView.vue#L1518)): jadwal dokter login hari ini. Bisa **edit ruangan** (`saveRoom`, [:38](arumed-frontend/src/views/DokterView.vue#L38)) & **aktif/non-aktif** (`toggleMyAktif`, [:49](arumed-frontend/src/views/DokterView.vue#L49)) tanpa pindah ke menu Jadwal Dokter. Prefix antrean = `D{room}`.
- **Statistik**: Menunggu / Selesai / Total.
- **Filter**:
  - Status (`qFilter`): "Belum Selesai" / "Selesai".
  - Penjamin (`ptypeFilter`, [:1589](arumed-frontend/src/views/DokterView.vue#L1589)): Semua / BPJS / Umum & Asuransi.
  - Pencarian nama/no. antrean (`qSearch`).
- **Kartu pasien** menampilkan: no. antrean, nama, RM, umur, penjamin, **alergi**, status. Aksi:
  - **Panggil / Panggil ulang** → `callPt` ([:399](arumed-frontend/src/views/DokterView.vue#L399)).
  - **Buka RME** → `pickPt` ([:392](arumed-frontend/src/views/DokterView.vue#L392)) — buka pasien & reset form.
  - **Skip** → `skipPt` ([:413](arumed-frontend/src/views/DokterView.vue#L413)) — turun 1 posisi.

> Status antrean (`uiStatus`, [:72](arumed-frontend/src/views/DokterView.vue#L72)): Menunggu / Proses / **Pemeriksaan Penunjang** (oranye) / **Selesai Penunjang** (hijau muda) / Selesai.

### Panel kanan — 4 TAB ([DokterView.vue:1805-1818](arumed-frontend/src/views/DokterView.vue#L1805-L1818))
| `tab` | Label tombol | Isi |
|---|---|---|
| `data` | **Data Pasien** | Read-only: Triase (vital) + Refraksi (RO) |
| `pemeriksaan` | **Pemeriksaan Mata** | Anamnese + Segmen Anterior/Posterior + Slit lamp |
| `tindakan` | **Tindakan & Resep** | Daftar tindakan + e-resep → Kasir/Farmasi |
| `soap` | **SOAP & Diagnosis** | SOAP, Diagnosis ICD-10/ICD-9, Planning, Rujukan, TTD, **Selesai** |

> ⚠️ Catatan koreksi: array `const TABS`/`label:` di baris 14-19 **bukan** definisi tab — itu master `saFields`/`spFields` (lihat poin 4). Tab sebenarnya pakai variabel `tab` dengan tombol di baris 1805-1818.

---

## 3. Tab **Data Pasien** (`tab === 'data'`, [DokterView.vue:1829](arumed-frontend/src/views/DokterView.vue#L1829))

Read-only — agar dokter tak perlu pindah layar:
- **Triase/Perawat**: TD, nadi, SpO₂, suhu, KGD, nyeri, keluhan utama.
- **Refraksi (RO)** ([roRows](arumed-frontend/src/views/DokterView.vue#L217)): Autoref, Keratometri, **IOP** (≥22 mmHg disorot kuning), Visus UCVA/BCVA, Pinhole, Refraksi Subjektif, ADD, Kacamata Lama — semua per OD/OS.

---

## 4. Tab **Pemeriksaan Mata** (`tab === 'pemeriksaan'`, [DokterView.vue:1911](arumed-frontend/src/views/DokterView.vue#L1911))

Dokter mengisi hasil slit lamp:
- **Anamnese** (textarea, [:1927](arumed-frontend/src/views/DokterView.vue#L1927)) — jadi sumber auto-fill SOAP-S.
- **Segmen Anterior** (`saFields`, [:454](arumed-frontend/src/views/DokterView.vue#L454)): Kornea, COA, Iris, Pupil, Lensa — per OD ([:1953](arumed-frontend/src/views/DokterView.vue#L1953)) & OS ([:1957](arumed-frontend/src/views/DokterView.vue#L1957)).
- **Segmen Posterior** (`spFields`, [:461](arumed-frontend/src/views/DokterView.vue#L461)): Papil, Macula, Retina, Vitreous — per OD ([:1984](arumed-frontend/src/views/DokterView.vue#L1984)) & OS ([:1988](arumed-frontend/src/views/DokterView.vue#L1988)).
  - Tiap field dropdown: **Normal / Tidak Normal / Tidak Dapat Dinilai** (`segmenOpts`).
- **Catatan Slit Lamp** (textarea, [:2009](arumed-frontend/src/views/DokterView.vue#L2009)).

**Penyimpanan**: auto-save via `watch` saat pasien berganti (`loadTab2`/`saveTab2`, [:500](arumed-frontend/src/views/DokterView.vue#L500)/[:523](arumed-frontend/src/views/DokterView.vue#L523)). Logika POST-baru-vs-PUT-update: kalau record sudah ada → PUT; kalau 422 (sudah ada) → fallback PUT. Backend: `storeExamination`/`updateExamination` ([DokterService.php:206](backend/app/Services/DokterService.php#L206)/[:256](backend/app/Services/DokterService.php#L256)), tabel `doctor_examinations`. Segmen kosong dikirim `null` (bukan string kosong) agar lolos validasi enum.

> ✅ Verifikasi: binding mata kiri (OS) **sudah benar** (`exam.sa[f.key].os` / `exam.sp[f.key].os`). Tidak ada bug seperti yang sempat saya duga.

---

## 5. Tab **Tindakan & Resep** (`tab === 'tindakan'`, [DokterView.vue:2152](arumed-frontend/src/views/DokterView.vue#L2152))

### a) Tindakan (prosedur)
- Cari tindakan (`tindakanSearch`, [:2184](arumed-frontend/src/views/DokterView.vue#L2184)) dari tarif kunjungan (`loadTarifTindakan`, [:771](arumed-frontend/src/views/DokterView.vue#L771) → [DokterService.php:281](backend/app/Services/DokterService.php#L281)).
- Tambah (`addTindakan`, [:793](arumed-frontend/src/views/DokterView.vue#L793)); atur qty (−/+); subtotal Rp.
- Auto-save 600ms (`scheduleSaveTindakan`, [:904](arumed-frontend/src/views/DokterView.vue#L904)) → `storeVisitServices` ([DokterService.php:357](backend/app/Services/DokterService.php#L357), replace-all).

### b) E-Resep
- Cari obat (`obatSearch`, [:2267](arumed-frontend/src/views/DokterView.vue#L2267)) dari inventori berharga (`loadObat`, [:817](arumed-frontend/src/views/DokterView.vue#L817) → `getDaftarObat` [DokterService.php:304](backend/app/Services/DokterService.php#L304); stok = on-hand lokasi FARMASI, dokter tetap bisa resep walau 0).
- Tambah baris (`addRx`, [:851](arumed-frontend/src/views/DokterView.vue#L851)): **qty**, **jumlah** ("1 tetes"), **signa** ("3×/hari"), **durasi** ("7 hari"), **posisi** OD/OS/ODS (`normalizePosisi`, [:842](arumed-frontend/src/views/DokterView.vue#L842)).
- **Catatan untuk Kasir** (`kasirNote`, [:2355](arumed-frontend/src/views/DokterView.vue#L2355)).
- Auto-save 600ms (`scheduleSaveResep`, [:917](arumed-frontend/src/views/DokterView.vue#L917)) → `storePrescription` ([DokterService.php:404](backend/app/Services/DokterService.php#L404)).

### c) Kunci & kirim
- **"Simpan & Kirim ke Kasir"** → `konfirmKirimKasir` ([:943](arumed-frontend/src/views/DokterView.vue#L943)) → flush kedua timer, set `tab3Sent`, tab jadi read-only. Resep masuk antrean Farmasi.

> Tarif diselesaikan di Kasir per penjamin via `KasirService::getPrice`. Hindari menambah preview tarif ke daftar tindakan (risiko dobel-tagih).

---

## 6. Tab **SOAP & Diagnosis** (`tab === 'soap'`, [DokterView.vue:2385](arumed-frontend/src/views/DokterView.vue#L2385)) — tab paling kritis

### a) SOAP
- **S** ([:2419](arumed-frontend/src/views/DokterView.vue#L2419)): auto-fill dari anamnese; "Ulang Sinkron" (`resyncSoapS`, [:1037](arumed-frontend/src/views/DokterView.vue#L1037)). Berhenti auto-sync jika dokter ketik (`markSoapDirty`).
- **O** ([:2431](arumed-frontend/src/views/DokterView.vue#L2431)): auto-generate dari vital+RO+segmen (`objectiveText`); "Ulang Sinkron" (`resyncSoapO`, [:1038](arumed-frontend/src/views/DokterView.vue#L1038)).
- **A (Asesmen)** ([:2439](arumed-frontend/src/views/DokterView.vue#L2439)): **WAJIB** diisi.
- **P** ([:2451](arumed-frontend/src/views/DokterView.vue#L2451)): auto-fill dari e-resep (`resyncSoapP`, [:1039](arumed-frontend/src/views/DokterView.vue#L1039)).

### b) Diagnosis ICD-10 & Tindakan ICD-9
- **Dx Utama** ([:2475](arumed-frontend/src/views/DokterView.vue#L2475)) — `setDxUtama` ([:1069](arumed-frontend/src/views/DokterView.vue#L1069)). **WAJIB**.
- **Dx Sekunder** ([:2505](arumed-frontend/src/views/DokterView.vue#L2505)) — `addDxSekunder` ([:1070](arumed-frontend/src/views/DokterView.vue#L1070)), boleh banyak, tak boleh sama dgn utama.
- **Tindakan ICD-9** ([:2541](arumed-frontend/src/views/DokterView.vue#L2541)) — `addIcd9` ([:1096](arumed-frontend/src/views/DokterView.vue#L1096)).

> ⚠️ GAP: master ICD-10/ICD-9 di tab ini **hardcoded di frontend** (`filteredIcd10`/`filteredIcd9`), terpisah dari master backend. Menambah kode baru perlu ubah kode.

### c) Planning ([:2570](arumed-frontend/src/views/DokterView.vue#L2570)) — **WAJIB pilih satu** (`PLANNING_ENUM`, [:1400](arumed-frontend/src/views/DokterView.vue#L1400))
- **PULANG** → opsional tanggal kontrol (BPJS → Surat Kontrol otomatis ke VClaim).
- **BEDAH** → **wajib** paket bedah + tanggal (jam opsional); preview slot (`loadBedahSlot`, [:1299](arumed-frontend/src/views/DokterView.vue#L1299)); checkbox **pre-op H-1** (`requiresInpatient`) → `inpatient_reason=PRE_OP`. Backend buat `SurgerySchedule`; bila tanggal=hari ini → route ke BEDAH.
- **RAWAT_INAP** → `inpatient_reason=OBSERVASI`, pasien masuk papan "Menunggu Kamar".
- **RUJUK** → lihat poin 7.

### d) Rujukan ([:2726](arumed-frontend/src/views/DokterView.vue#L2726)) — 2 mode (`rujukMode`)
- **INTERNAL** (antar-poli): pilih dokter/poli tujuan (`loadInternalTargets` [:1122](arumed-frontend/src/views/DokterView.vue#L1122) → `getRujukInternalTargets` [DokterService.php:1432](backend/app/Services/DokterService.php#L1432)); kirim (`submitRujukInternal` [:1152](arumed-frontend/src/views/DokterView.vue#L1152) → `rujukInternal` [:1480](backend/app/Services/DokterService.php#L1480)). Pasien masuk antrean hari ini bila jadwal masih buka.
- **EKSTERNAL** (BPJS/faskes lain): cari faskes (`searchFaskes` [:1192](arumed-frontend/src/views/DokterView.vue#L1192)) + poli (`searchPoliRujuk` [:1206](arumed-frontend/src/views/DokterView.vue#L1206)) dari referensi VClaim; isi tipe & jenis layanan + diagnosis (auto dari Dx utama); kirim (`submitRujukKeluar` [:1225](arumed-frontend/src/views/DokterView.vue#L1225) → `storeRujukanKeluar` [DokterService.php:1121](backend/app/Services/DokterService.php#L1121)). **Pasien BPJS wajib punya SEP.**

### e) Tanda tangan digital
- **"Tandatangani"** → modal PIN (`openSignModal` [:1363](arumed-frontend/src/views/DokterView.vue#L1363) / `doSign` [:1368](arumed-frontend/src/views/DokterView.vue#L1368)). Setelah signed, panel terkunci (`isLocked`); bisa **Hapus TTD** (`undoSign`, [:1388](arumed-frontend/src/views/DokterView.vue#L1388)).

---

## 7. Order **Penunjang** (modal, [DokterView.vue:654](arumed-frontend/src/views/DokterView.vue#L654))

- Pilih jenis penunjang (`loadPenunjangTypes` [:548](arumed-frontend/src/views/DokterView.vue#L548)) atau "Lainnya" (`addCustomPenunjang` [:696](arumed-frontend/src/views/DokterView.vue#L696)); kirim (`confirmPenunjang` [:717](arumed-frontend/src/views/DokterView.vue#L717) → `storeOrderPenunjang` [DokterService.php:832](backend/app/Services/DokterService.php#L832)).
- `kirimKePenunjang` ([DokterService.php:104](backend/app/Services/DokterService.php#L104)): pause baris DOKTER → status `DI_PENUNJANG`, turun ke bawah. Wajib sudah ada order terbuka (else 422).
- Setelah penunjang selesai, pasien naik lagi ke dokter yang sama (`SELESAI_PENUNJANG`). Lihat memory *Flow Penunjang ↔ Dokter*. Riwayat hasil lintas-kunjungan via `loadPenunjangHistory` ([:568](arumed-frontend/src/views/DokterView.vue#L568)).

---

## 8. Dokumen Rekam Medis (Form Registry)

- Modal **FormDocsBrowser** ([DokterView.vue:9](arumed-frontend/src/views/DokterView.vue#L9), import komponen) untuk mengisi/menandatangani template form RM. Lihat memory *Form Registry* & *DokterView Module*.

---

## 9. Menyelesaikan pasien — `doFinalize` ([DokterView.vue:1402](arumed-frontend/src/views/DokterView.vue#L1402))

**Validasi wajib** sebelum boleh selesai:
1. Diagnosis utama ICD-10 ([:1403](arumed-frontend/src/views/DokterView.vue#L1403))
2. SOAP Assessment (A) ([:1404](arumed-frontend/src/views/DokterView.vue#L1404))
3. Planning ([:1405](arumed-frontend/src/views/DokterView.vue#L1405))
4. Jika BEDAH → paket + tanggal ([:1406-1408](arumed-frontend/src/views/DokterView.vue#L1406-L1408))
5. Tanda tangan ([:1410](arumed-frontend/src/views/DokterView.vue#L1410))

**Langkah eksekusi** ([:1416-1444](arumed-frontend/src/views/DokterView.vue#L1416-L1444)):
1. `saveTab2()` — simpan anamnese + segmen.
2. `dokterApi.storeTab4()` — SOAP + diagnosis + planning + bedah + follow-up (backend buat SurgerySchedule bila BEDAH).
3. `dokterApi.finalize()` — kunci RME (`is_finalized=true`, [DokterService.php:778](backend/app/Services/DokterService.php#L778)).
4. `store.selesaiAntrian()` → `selesaiAntrian` ([DokterService.php:89](backend/app/Services/DokterService.php#L89)) → `QueueService::advanceFromStation($queue, STATION_DOKTER)` — **sumber tunggal** routing + broadcast TV.
5. `autoSubmitSuratKontrol()` — non-blocking, kirim Surat Kontrol BPJS bila Pulang+kontrol+BPJS.

Pasca-selesai: `finalized=true`, filter pindah ke "Selesai", pasien **tidak** di-clear (dokter bisa lihat read-only).

---

## 10. Method backend `DokterService.php` (43 method publik)

| Baris | Method | Fungsi |
|---|---|---|
| [47](backend/app/Services/DokterService.php#L47) | `getPatientQueue` | Antrean dokter (filter per dokter login) |
| [75](backend/app/Services/DokterService.php#L75) | `panggilAntrian` | Panggil/recall pasien |
| [89](backend/app/Services/DokterService.php#L89) | `selesaiAntrian` | Advance dokter → Penunjang/Bedah/Kasir |
| [104](backend/app/Services/DokterService.php#L104) | `kirimKePenunjang` | Pause baris → DI_PENUNJANG |
| [176](backend/app/Services/DokterService.php#L176) | `getPatientData` | Detail Tab Data (triase+refraksi) |
| [196](backend/app/Services/DokterService.php#L196)/[206](backend/app/Services/DokterService.php#L206)/[256](backend/app/Services/DokterService.php#L256) | `getTab2`/`storeExamination`/`updateExamination` | Pemeriksaan mata |
| [281](backend/app/Services/DokterService.php#L281) | `getTarifTindakan` | Tarif tindakan untuk kunjungan |
| [304](backend/app/Services/DokterService.php#L304) | `getDaftarObat` | Daftar obat (stok FARMASI) |
| [345](backend/app/Services/DokterService.php#L345)/[357](backend/app/Services/DokterService.php#L357)/[384](backend/app/Services/DokterService.php#L384) | `getVisitServices`/`storeVisitServices`/`deleteVisitService` | Tindakan (replace-all) |
| [392](backend/app/Services/DokterService.php#L392)/[404](backend/app/Services/DokterService.php#L404) | `getPrescriptions`/`storePrescription` | Resep → Farmasi |
| [462](backend/app/Services/DokterService.php#L462)/[475](backend/app/Services/DokterService.php#L475)/[528](backend/app/Services/DokterService.php#L528) | `getTab4`/`storePlanning`/`updatePlanning` | SOAP+diagnosis+planning (+side-effect bedah/inap/follow-up) |
| [695](backend/app/Services/DokterService.php#L695) | `getBedahSlot` | Preview slot bedah |
| [718](backend/app/Services/DokterService.php#L718)/[736](backend/app/Services/DokterService.php#L736)/[741](backend/app/Services/DokterService.php#L741) | `storeFollowUp`/`updateFollowUp`/`deleteFollowUp` | Kontrol |
| [778](backend/app/Services/DokterService.php#L778) | `finalizeKunjungan` | Kunci RME + signature |
| [823](backend/app/Services/DokterService.php#L823)/[832](backend/app/Services/DokterService.php#L832)/[865](backend/app/Services/DokterService.php#L865)/[878](backend/app/Services/DokterService.php#L878) | `getOrderPenunjang`/`storeOrderPenunjang`/`cancelOrderPenunjang`/`getHasilPenunjang` | Penunjang |
| [888](backend/app/Services/DokterService.php#L888) | `getIolRekomendasi` | Rekomendasi IOL |
| [909](backend/app/Services/DokterService.php#L909) | `getPenunjangBilling` | Preview tagihan penunjang |
| [954](backend/app/Services/DokterService.php#L954)/[964](backend/app/Services/DokterService.php#L964)/[1071](backend/app/Services/DokterService.php#L1071)/[1091](backend/app/Services/DokterService.php#L1091) | `getResumeMedis`/`generateMedicalResume`/`updateResumeMedis`/`finalizeResumeMedis` | Resume medis |
| [1121](backend/app/Services/DokterService.php#L1121) | `storeRujukanKeluar` | Rujukan eksternal → VClaim |
| [1205](backend/app/Services/DokterService.php#L1205)/[1219](backend/app/Services/DokterService.php#L1219) | `getSuratKontrol`/`submitSuratKontrol` | Surat Kontrol BPJS |
| [1284](backend/app/Services/DokterService.php#L1284)/[1295](backend/app/Services/DokterService.php#L1295) | `getInboxNotifications`/`markNotificationRead` | Notifikasi |
| [1309](backend/app/Services/DokterService.php#L1309)/[1381](backend/app/Services/DokterService.php#L1381) | `signDocument`/`rejectDocument` | TTD / tolak dokumen |
| [1432](backend/app/Services/DokterService.php#L1432)/[1480](backend/app/Services/DokterService.php#L1480) | `getRujukInternalTargets`/`rujukInternal` | Rujukan internal |

---

## 11. GAP / catatan

1. **ICD-10 & ICD-9 hardcoded di frontend** (tab SOAP) — terpisah dari master backend. Perlu dipindah ke API agar terpelihara.
2. **Auto-save tanpa indikator** "Saving…/✓" — dokter tak tahu sudah tersimpan; ada risiko timer 600ms belum flush saat finalisasi (dimitigasi `konfirmKirimKasir`).
3. **Surat Kontrol BPJS non-blocking** — bila VClaim error, pasien tetap final; status dicek manual di Bridging.
4. **Slot bedah bukan lock realtime** — booking bersamaan bisa konflik.
5. Method mati `bppvBpjsReferral_DELETED_` ([DokterService.php:422](backend/app/Services/DokterService.php#L422)) — abaikan (deprecated).
