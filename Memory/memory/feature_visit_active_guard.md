---
name: feature-visit-active-guard
description: "Guard cegah visit aktif ganda di AdmisiService::registerVisit — pasien tidak boleh punya >1 kunjungan berjalan (current_station != SELESAI). Tolak keras 422. + temuan: antrian difilter harian (whereDate today) jadi visit lintas-hari 'hilang' tapi sebenarnya nyangkut."
metadata:
  node_type: memory
  type: project
  originSessionId: visit-guard-2026-05-30
---

**Konteks (2026-05-30):** User lapor "data pasien hilang di antrian" — pasien seed kemarin (belum selesai alur) tak tampil hari ini. **Investigasi: data TIDAK hilang.** Semua `getPatientQueue` per-station difilter `whereDate('created_at', today())` (antrian HARIAN by design). 33 visit kemarin (2026-05-29) masih utuh di DB, 32 masih AKTIF (`current_station` ∈ ADMISI/TRIASE/DOKTER/FARMASI/KASIR/BEDAH — belum SELESAI), tersebar di 8 stasiun. Tidak tampil karena `created_at` kemarin. **`visits` TIDAK punya kolom `status`** — state alur = `current_station` (SELESAI = selesai).

**Visit lifecycle:** tidak ada auto-cleanup/carry-over visit lintas hari → visit belum-selesai jadi "ekor" yang nyangkut (tetap aktif, tak tampil di antrian harian). Belum ada scheduler expire. (User belum minta auto-cleanup; pilih guard dulu.)

**GUARD ditambah `AdmisiService::registerVisit` (keputusan user: TOLAK KERAS 422):** sebelum `Visit::create`, cek `Visit::where('patient_id',$pid)->where('current_station','!=','SELESAI')->whereNull(deleted_at)->lockForUpdate()->first()`. Kalau ada → throw 422 dgn pesan menyebut **nama pasien + no_registrasi + visit_date + current_station** ("Selesaikan atau batalkan kunjungan itu dulu"). `lockForUpdate` anti-race 2 registrasi bareng.

**3 path Visit::create (guard HANYA di registerVisit, sengaja):**
- `AdmisiService::registerVisit` (1 visit per pasien) → GUARD di sini.
- `AdmisiService::ambilTiketUmumKiosk` (Anjungan walk-in) → buat patient placeholder BARU tiap kali → guard tak relevan (pasien fresh, no prior visit).
- `DokterService` rujukan internal (line ~1469) → child visit `parent_visit_id` utk pasien SAMA, SENGAJA multi-visit → guard TIDAK kena (beda method). Jangan tambah guard di sini.

**Verified:** pasien dgn visit aktif (Tuti Handayani @DOKTER) → ditolak 422 pesan jelas; pasien bebas → visit dibuat normal (REG-...→TRIASE). Frontend: `admisiStore.daftarKunjungan` propagate `response.data.message` → `e.message` → toast di AdmisiView catch (line ~1278). Smoke 35/35.

**UI VISIBILITY 2026-05-30 (krn visit nyangkut TAK terlihat di UI mana pun — semua view filter `whereDate today`):** 2 fitur ditambah supaya petugas bisa LIHAT & bereskan:
1. **Peringatan visit aktif saat cari pasien** — `cariPasien` kini sertakan `active_visit` (no_registrasi/visit_date/current_station) per pasien (1 query batch anti-N+1). AdmisiView: badge merah "● kunjungan aktif" di dropdown hasil cari + banner merah `.active-visit-banner` saat pasien dipilih (sebut stasiun+tgl, "registrasi akan ditolak") + toast warning. `selectedActiveVisit` ref, reset di openWizard/setPatientMode.
2. **Filter "Belum Selesai (semua tgl)" di Daftar Kunjungan** — param baru `unfinished` di `getKunjungan` (DROP filter tanggal, ganti `current_station != SELESAI` lintas-hari) + controller `->only([..,'unfinished'])`. Store `visitsFilter.unfinished`, `fetchVisits` kirim `unfinished=1` & SKIP `tanggal`. AdmisiView tombol merah `.unfinished-toggle` (`showUnfinished` + `toggleUnfinished`). Tabel sudah punya kolom Tanggal (bedakan hari) + aksi Cancel per-baris (`cancelKunjungan`) → ekor nyangkut bisa dibatalkan dari sini. Verified: 47 visit belum-selesai lintas-hari (35 dari 29 Mei) tampil; build+smoke hijau.

Terkait: [[feature-admisi-view]], [[project-pre-golive-bug-audit]] (#8 zombie row = visit soft-deleted, beda dari ini), [[queue-advance-station-pattern]].
