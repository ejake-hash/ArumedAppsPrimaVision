---
name: project-rebrand-klinik-rumah-sakit
description: "Rebrand teks UI 'Klinik' → 'Rumah Sakit' (14 teks tampilan di 8 file FE). HANYA teks yang dilihat user; nama variabel/kolom DB/route/komponen UTUH. Poliklinik & faskes-lain TIDAK diganti. Belum commit."
metadata:
  node_type: project
  type: project
---

# Rebrand "Klinik" → "Rumah Sakit" (teks UI saja, 2026-06-01)

User minta semua kata "Klinik" diganti "Rumah Sakit", tapi dikonfirmasi cakupan = **teks tampilan (UI) SAJA** — TIDAK menyentuh nama variabel/kolom DB/route/komponen (mencegah kerusakan sistem). Build OK. **Belum commit** (lihat [[feedback-jangan-tawarkan-commit]]).

## 14 teks diganti (8 file)
- `AntreanTVView.vue`: "Logo + nama klinik", "Logo Klinik", "Nama Klinik", 2× `alt="Logo klinik"` → "...Rumah Sakit".
- `BedahView.vue`: "...atur di Profil Klinik" → "Profil Rumah Sakit".
- `KasirView.vue`: "Logo / Kop klinik", "Stempel klinik", fallback `'Klinik'`, "Penanggung Jawab Klinik:" → "...Rumah Sakit".
- `RekamMedisView.vue`: header cetak "KLINIK MATA" → "RUMAH SAKIT MATA".
- `inventori-farmasi/PembelianView.vue`: fallback `'Klinik'` → `'Rumah Sakit'`.
- `inventori-farmasi/RequestUnitView.vue`: "unit klinik" → "unit rumah sakit".
- `AdmisiView.vue`: "Klinik Utama Mata · FKRTL" → "Rumah Sakit Mata · FKRTL".
- `master/form-template/BindingPickerModal.vue`: tab "Klinik" → "Rumah Sakit" (teks saja; nilai `tab==='clinic'` UTUH).
- placeholder email contoh: `DataPenggunaView` `email@klinik.com` & `ProfilKlinikView` `info@klinik.id` → `@rumahsakit.id`.

## SENGAJA TIDAK diganti (penting!)
- **`Poliklinik` / `poliklinik`** — istilah poli, makna BEDA. JANGAN ganti.
- **DokterView** placeholder "Nama RS / Klinik / Puskesmas" — daftar jenis faskes rujukan eksternal ("Klinik" = faskes lain, bukan RS ini).
- **`kode klinik`** (PengaturanView, DocumentTypeView) — label teknis kode {CLINIC}.
- **Komentar kode** (`// Kop klinik` dst) — tak tampil ke user.
- **Nama teknis UTUH**: kolom/tabel `clinic_profiles`, komponen `ProfilKlinikView`, route `/master-data/profil-klinik`, binding `clinic.*`, fungsi `profilKlinik`/`fetchProfilKlinik`. Catatan: judul UI ProfilKlinikView SUDAH "Profil Institusi" (bukan "Klinik") sejak sebelumnya.
