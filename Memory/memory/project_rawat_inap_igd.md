---
name: project-rawat-inap-igd
description: "Rencana modul Rawat Inap (RANAP) + IGD untuk Arumed/PrimaVision — fokus RANAP dulu, IGD ditunda."
metadata: 
  node_type: memory
  type: project
  originSessionId: e7376982-5522-493f-ae41-0da787688d19
---

Rencana penambahan modul **Rawat Inap (RANAP)** ke Arumed (instance PrimaVision, RS Mata) yang saat ini 100% rawat jalan. Plan file lengkap: `C:\Users\Lenovo\.claude\plans\nah-dari-arsitektur-ini-quiet-ripple.md` (2026-05-30, belum dieksekusi). Lihat arsitektur dasar di [[reference_arsitektur_arumed]].

**Keputusan user (FINAL, jangan diubah tanpa konfirmasi):**
- **Fokus RANAP penuh; IGD DITUNDA** — IGD hanya disiapkan KOLOM/STRUKTUR DATA di Fase 1 (tabel `igd_triage_records`, kolom IGD di `visits`, kolom `priority` di `queues`) agar tak migrasi ulang; semua alur+UI IGD pindah ke fase akhir (Fase 6).
- Model data: **perluas tabel `visits`** (bukan tabel admission terpisah) + tabel pendukung.
- MVP RANAP mencakup: operasional + billing + BPJS/SatuSehat inap + transfer kamar (semua in).
- **Jenis pelayanan ditentukan OTOMATIS per pintu masuk**: admisi→RAJAL, IGD→IGD, RANAP selalu turunan keputusan dokter (`doctor_examinations.planning='RAWAT_INAP'` → papan "Menunggu Kamar" → petugas ranap admit pilih bed). Petugas TIDAK pilih manual.
- **Master Bangsal & Bed dikelola di halaman PROFIL KLINIK** (bukan menu master terpisah), TAPI backing-store = tabel `wards`/`beds` (BUKAN JSON di clinic_profiles — JSON tak bisa status occupancy real-time). Form profil = editor CRUD ke tabel.
- **Kwitansi 2 tipe**: `INV-RJ/...` vs `INV-RI/...` dengan **counter TERPISAH per tipe** (masing-masing mulai 001). Kwitansi RI tampil blok kamar/LOS/kelas/tgl masuk-keluar.
- **LOS** = per malam, min 1 (masuk dihitung, pulang tidak; masuk=pulang→1). **Room charge digenerate sekaligus saat discharge** (tanpa cron). **IGD→RANAP = SEP inap baru** (jnsPelayanan=1), SEP IGD tetap.

**Menu modul Rawat Inap (UI, semua MVP):** A.Papan Bangsal/Bed Board · B.Admit/Assign Bed (+daftar "Menunggu Kamar") · C.Detail Pasien Inap (visite CPPT/SOAP harian + asuhan keperawatan/TTV + tindakan/order + resep/obat depo bangsal) · D.Running Bill · E.Transfer/Pindah Kamar · F.Discharge · G.Setup master bangsal/bed di Profil Klinik + tarif kamar.

**Urutan eksekusi (kerja BERTAHAP per fase, tunggu konfirmasi tiap fase — lihat [[feedback_bertahap_konfirmasi]]):**
1. Data layer (7 migrasi `2026_06_05_*` additive: kolom inap di `visits`, `wards`,`beds`,`room_tariffs`,`bed_assignments`,`inpatient_charges`,`igd_triage_records`; kolom `priority` di `queues`; model baru; edit Visit.php + InventoryStock LOC_RANAP).
2. Queue & alur RANAP (cabang RANAP di `QueueService::resolveNextStation` — body RAJAL dipindah PERSIS ke `resolveNextRajal`; station RANAP long-lived; `RanapService`).
3. Billing inap (`KasirService`: builder room/visite, `getPrice('room')`, nomor invoice 2-tipe counter terpisah, room charge saat discharge).
4. BPJS & SatuSehat conditional (SEP inap jnsPelayanan=1, INA-CBGs +los/tgl, Encounter IMP/EMER).
5. RBAC + Frontend RANAP (permission `rawat_inap.*`, RawatInapView + store, master bed di Profil Klinik).
6. IGD (aktifkan struktur yang sudah disiapkan — fase akhir).

**Titik REGRESI paling sensitif (uji rawat jalan dulu sebelum lanjut):** `QueueService::resolveNextStation` (match, QueueService.php:424), guard "1 invoice" (KasirService.php:157), guard "1 visit aktif" (AdmisiService.php:581 — dilonggarkan untuk RANAP). Dev DB Postgres `dbprimavision`.
