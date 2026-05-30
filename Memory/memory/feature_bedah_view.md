---
name: bedah-view
description: "BedahView.vue — modul OK/Bedah. WIRING INTI F1-F6 SELESAI 2026-05-30: lifecycle Mulai/TimeOut/Finalisasi wired ke surgery_record (ADVANCE dipindah TimeOut→Finalize, kolom finalized_at baru), prefill diagnosa/DPJP + notes round-trip, panel Jadwal dihapus. Belum commit. Sisa: IOL usage, tab Pasca persist, Cetak A4."
metadata: 
  node_type: memory
  type: project
  originSessionId: 84b7bf5d-8784-4143-ba2c-da6173fa3dbf
---

[BedahView.vue](arumed-frontend/src/views/BedahView.vue) — view modul Bedah/OK. Layout disamakan [[perawat-view]]: root `.bedah > .main-grid` (grid `340px 1fr`), kiri `.col-queue` + kanan `.col-work` (4 tab: Pra-Bedah / Intraoperatif / Laporan / Pasca). Ruang OK dynamic via [[ruang-ok-setting]] (`operatingRooms` dari `masterStore.profilKlinik?.operating_rooms`).

**Sudah di-wire ke backend:**
- **Antrian** (`bedahApi.antrian` → `/bedah/antrian`): `loadQueue` + `transformQueueItem`, polling 15s, panggil/panggil-ulang (`panggilAntrian`), selesai → KASIR (`selesaiAntrian`). Badge PREOP untuk `visit_type=PREOP_BEDAH`. Lihat [[feature_bedah_view]] lama untuk detail UI antrean.
- ~~**Jadwal Operasi (bedah terjadwal)** — wire 2026-05-29~~ **DIHAPUS di F5 2026-05-30** (keputusan user "panel jadwal kurang fungsi"). Panel kiri BedahView kini HANYA Antrean. Endpoint `/bedah/jadwal` + `bedahApi.jadwal` tetap hidup utk BedahTerjadwalView. (Histori: dulu toggle leftMode antrean|jadwal + date picker + card per-operasi.)
- **Tim Bedah combobox** — **wire 2026-05-29**: `loadEmployees()` dari `masterApi.pegawai.list({per_page:200})` → `/master/pegawai` (paginated, response `data.data.data`). Map `name` + `role:=profession`. Diisi parallel dgn loadQueue di onMounted (sebelumnya `employees` selalu kosong).
- **Tab Intraop**: Pemakaian BHP (`adjustBhpUsage` per request RECEIVED) + Pemakaian Alat Medis (`alatMedisApi` recordUsage/deleteUsage). Lihat [[billing-items-expansion]].

**Bug fix 2026-05-29**: `transformQueueItem` sekarang set `scheduleId: sched?.id` — `addEquipmentUsage` sudah baca `selP.value.scheduleId` tapi sebelumnya selalu `undefined`.

**Sinkron jam-bedah-opsional (2026-05-29)**: jam bedah kini OPSIONAL dari [[dokterview-module]] Tab 4 (`scheduled_time` bisa null). BedahView dirapikan agar konsisten saat jam kosong — semua baca `surgery_schedule.scheduled_time` yang sama: (1) `transformQueueItem` normalkan `scheduledTime` ke `null` (bukan `'—'`); (2) pill jam kartu antrean → `fmtJamJadwal` (buang detik), kosong → badge oranye **"Jam belum diatur"** (`.pill-time-na`); (3) detail "Jadwal Operasi" → tak lagi "— WIB", kosong → **"Jam belum ditentukan dokter"** (`.bd-val-na`); (4) panel Jadwal blok waktu → **"Belum dijam"** (`.bd-sched-time.na`, amber #9a6700). `fmtJamJadwal(t)` tetap `slice(0,5)` + guard null. Catatan: BedahView jadwal pakai endpoint berat `/bedah/jadwal`; DokterView preview pakai endpoint ringan baru `/dokter/bedah/slot` — beda endpoint, sumber tabel sama.

**Masih local state (field klinis, BELUM persist — lihat F3/F4):**
- Tab Pra-Bedah (checklist, Tim assign, IOL rencana) + sebagian field tab Laporan/Pasca yg belum di-prefill dari record. CATATAN: lifecycle (Mulai/TimeOut/Finalisasi) + ringkasan laporan (teknik/temuan/catatan/komplikasi) SUDAH di-persist via F2 (`buildRecordPayload`), tapi prefill balik dari `showRecord` belum lengkap (cuma timIn/timOut/finalized).
- `isPhaco` hardcode `false` di transformQueueItem (harusnya derive dari prosedur/ICD).
- Backend punya endpoint penuh belum dipakai FE: jadwal CRUD (store/update/delete), `/jadwal/{id}/mulai|selesai`, `/record/*`, `/iol-usage/*`, request kirim/update.

## WIRING INTI — F1+F2 DONE 2026-05-30 (build hijau + smoke 35/35)
Plan: `plans/imperative-wondering-whisper.md`. Scope: persist data klinis (NO kolom DB baru — KECUALI 1 kolom ditambah, lihat ⚠️). **Backend BERES**: (B1) `startOperation` resolve `visit_id` dari `visits.surgery_schedule_id` dulu; (B2) advance delegasi `advanceFromStation` (lihat [[queue-advance-station-pattern]]).

⚠️ **REVISI GERBANG ADVANCE (keputusan user "lebih tepat advance setelah finalize")**: advance ke Farmasi/Kasir **DIPINDAH dari `completeOperation` (Time Out) → `finalizeRecord`**. Konsekuensi: (1) migration BARU `2026_06_01_000005_add_finalized_at_to_surgery_records_table` (kolom `finalized_at` timestamp nullable; model `SurgeryRecord` +fillable +cast); (2) `completeOperation` kini cuma set schedule DONE + isi laporan (TIDAK advance); (3) `finalizeRecord` set `finalized_at` + guard double-finalize (422 bila sudah ada) + advance; (4) pesan controller diselaraskan. Flow: **Sign In (mulai) → IN_PROGRESS+record · Time Out (selesai) → DONE+laporan (TIDAK jalan) · Finalize → kunci+advance**.

**F1 DONE**: 8 method `bedahApi` ditambah ([api.js](arumed-frontend/src/services/api.js) grup "Operasi lifecycle"+"Laporan operasi"): mulaiOperasi(id)/selesaiOperasi(**id,data**)/showRecord(scheduleId)/storeRecord/updateRecord/storePostOp/finalizeRecord. (IOL storeIolUsage/updateIolUsage SENGAJA belum — fokus 8 = flow laporan.)

**F2 DONE** ([BedahView.vue](arumed-frontend/src/views/BedahView.vue)): `doMulaiOperasi`→`mulaiOperasi(scheduleId)` (simpan `recordId`); `doTimeOut`→`selesaiOperasi(scheduleId, buildRecordPayload())` (payload dari teknik/temuan/catatan+komplikasi, TIDAK advance); `doFinalisasi`→resolve recordId (atau `showRecord`) lalu `finalizeRecord` (INI yg advance, ganti `selesaiAntrian` lama); `pickPt` jadi async → hidrasi recordId/timIn/timOut/finalized via `showRecord` saat status≠MENUNGGU (utk reload). Tambah `busyOp` ref (lock 3 tombol), `recordId` di row default. Tombol Finalisasi kini `:disabled="!selP.timOut"` (dulu `!selP.timIn`) — backend wajib time_out. Semua guard `scheduleId` null (operasi tanpa jadwal tak bisa pakai endpoint ini).

**F3 DONE** (build hijau + round-trip 6/6): notes berlabel utk round-trip 3 field UI↔1 kolom DB. `buildRecordPayload` tulis `operation_notes` = `[Teknik Operasi]\n…\n\n[Temuan Intraoperatif]\n…\n\n[Catatan Intraoperatif]\n…` (const `NOTE_SECTIONS`); `parseRecordNotes(text)` pecah balik (fallback tanpa label → semua ke Teknik agar tak hilang). `pickPt` hidrasi diperluas: prefill teknikOp/temuanIntra/catatanIntra (hanya bila field kosong, jaga edit user) + komplikasi/komplikasiNote dari `has_complication`/`complication_detail`. `komplikasiTipe` (dropdown) TAK bisa prefill (tak ada kolom) → kosong pasca-reload, catatan tetap di komplikasiNote.

**F4 DONE** (build hijau + smoke 35/35): prefill diagnosa/DPJP. Backend `getPatientQueue` ([BedahService.php](backend/app/Services/BedahService.php)) eager-load +`leadSurgeon`+`doctorExamination.doctor`, payload `visit.diagnosa`=`doctorExamination.diagnosis_utama` (KODE ICD-10, bukan nama — exam tak simpan deskripsi) + `visit.dpjp`=`schedule.leadSurgeon.name` fallback `exam.doctor.name`. FE `transformQueueItem` map `q.visit?.diagnosa/dpjp` (dulu hardcode ''). Empty-state: Operator `|| '—'`, chip diagnosis kosong → italic muted "Belum ada diagnosis dari dokter" (`.bd-dx-chip-empty`).

**F5 DONE** (build hijau): panel Jadwal Operasi + toggle `leftMode` DIHAPUS dari BedahView (panel kiri kini cuma Antrean, card-head tanpa ternary). Dihapus: refs schedules/loadingSchedules/jadwalDate, `loadSchedules`, `leftMode`, 2 watcher, `SCHED_STATUS`/`schedStatusMeta`, `jadwalDateLabel`, CSS .bd-mode-*/.bd-jadwal-*/.bd-sched-*/.sched-*. DIPERTAHANKAN: `fmtJamJadwal` (dipakai kartu antrean+detail). `bedahApi.jadwal` TETAP ADA (kini cuma dipakai [BedahTerjadwalView.vue](arumed-frontend/src/views/BedahTerjadwalView.vue):193). Sweep referensi = 0 sisa.

**F6 DONE** (build hijau): 3 tombol hapus `bd-del` (BHP/Alat Medis/Obat) ganti glyph `✕`→SVG `<line>` X + `aria-label`/`title` kontekstual + `svg[aria-hidden]`. CSS `.bd-del` jadi inline-flex center + `svg{15px}`.

**SERI WIRING INTI F1–F6 SELESAI 2026-05-30** (build hijau + smoke 35/35 + round-trip notes 6/6). **E2E SERVICE-LEVEL TERUJI 2026-05-30** (tinker dlm transaksi rollback, ALL PASS): Sign In→IN_PROGRESS+record(time_in)+finalized_at null · Time Out→DONE+time_out, **finalized_at MASIH null + queue MASIH BEDAH (TIDAK advance)** · Finalize→finalized_at terisi + **advance** (BEDAH row COMPLETED + baris KASIR WAITING baru + visit.current_station=KASIR; FARMASI bila ada resep aktif) · double-finalize→throw 422 "sudah dikunci". Membuktikan koreksi gerbang user (advance hanya stlh finalize) berfungsi end-to-end. CATATAN: belum uji lewat HTTP+JWT/klik UI nyata, baru service layer. **SISA out-of-scope (bukan F)**: IOL usage wiring (storeIolUsage/updateIolUsage belum dipakai); tab Pasca persist (post_op_instructions/followup_date kini null di payload — `storePostOp` ada di api.js tapi belum dipanggil); diagnosis tampil KODE ICD (resolve nama=join ICD-10); persist Tim/Checklist (butuh migration); Cetak A4 laporan. ⚠️ **BELUM di-commit** (mengikuti pola user tunda commit) — perubahan: migration finalized_at + BedahService/Controller/Model + api.js (8 method) + BedahView (F2-F6).
Catatan RBAC: route group `bedah` cuma `auth:api`, TANPA `permission:`.

## Bugfix UI 2026-05-30 (sebelum wiring)
- **Gender banner selalu "Perempuan"** (double-map): `transformQueueItem` sudah ubah `L/P`→`Laki-laki/Perempuan`, tapi banner re-cek `selP.gender==='L'`. Fix: banner cetak `selP.gender` langsung.
- **Tim Bedah filter per peran**: combobox dulu tampil SEMUA pegawai di semua field (Operator munculkan Refraksionis). `filteredEmployees(key)` kini cocokkan `user.role.name`: Operator=dokter, Asisten1/2+Scrub+Circulating=perawat, Anestesiologis=dokter berprofesi anestesi (fallback semua dokter). `loadEmployees` simpan `roleName`+`prof`. Data SUDAH dari tabel `employees` yg sama dgn Data Pengguna (`/master/pegawai`, eager `user.role`).
- **Halaman terpotong**: dropdown combobox absolute diklip `.bd-card{overflow:hidden}` + card terakhir mentok. Fix: `.bd-card-combo{overflow:visible}` + `.bd-tabcont` padding-bottom 220px.

## BedahRiwayatSeeder (baru 2026-05-30)
Seeder ke-4 modul bedah (selain BedahDemoSeeder/BedahTerjadwalSeeder/BedahBhpRequestSeeder). Isi gap yg tak disentuh seeder lain: **operasi DONE + surgery_records lengkap**. 2 DONE (1 komplikasi PCR/vitrektomi, 1 bersih) + IOL usage + 1 IN_PROGRESS (time_in only). Reuse paket aktif pertama + IOL pertama (jalankan BedahDemoSeeder dulu). Idempoten. Run manual (commented di DatabaseSeeder). Gotcha: pakai paket aktif PERTAMA → bisa "Laser PRP" bukan Phaco kalau itu yg pertama.

**Behavior `laporanFinalized=true`**: semua input tab disabled, banner read-only di Laporan. Gotcha kosmetik (belum fix per user): quick-obat buttons masih muncul saat resepSent/laporanFinalized.
