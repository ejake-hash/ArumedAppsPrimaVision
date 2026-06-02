# 04 — Panduan Pemeriksaan Dokter

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **dokter**. Di sini Anda memeriksa pasien, menetapkan diagnosa, menulis resep dan tindakan, lalu mengirim pasien ke tahap berikutnya. Buka lewat menu **Pemeriksaan Dokter** di sidebar.

> **Istilah:** **SOAP** = cara mencatat: **S**ubjektif (keluhan), **O**bjektif (pemeriksaan), **A**ssessment (diagnosa), **P**lan (rencana). **ICD-10** = kode diagnosa, **ICD-9** = kode tindakan.

---

## Tata letak layar

- **Panel kiri:** antrean pasien (saringan Semua / BPJS / Umum & Asuransi). Pasien yang sedang di penunjang ditandai khusus.
- **Panel kanan:** berkas pasien terpilih, dibagi menjadi **4 tab**:
  1. **Data Pasien**
  2. **Pemeriksaan Mata**
  3. **Tindakan & Resep**
  4. **SOAP & Diagnosis**

---

## Tab 1 — Data Pasien

Berisi data yang sudah diisi stasiun sebelumnya (hanya untuk dilihat):
- Hasil **Triase** (tanda vital, keluhan, alergi).
- Hasil **Refraksi** (autoref, keratometri, IOP, visus UCVA/BCVA, pinhole, refraksi subjektif, ADD, kacamata lama).

Gunakan tab ini untuk meninjau kondisi pasien sebelum memeriksa.

---

## Tab 2 — Pemeriksaan Mata

Catat temuan pemeriksaan fisik mata, antara lain segmen depan dan belakang:
- Segmen anterior: **Kornea, COA, Iris, Pupil, Lensa**.
- Segmen posterior: **Papil, Macula, Retina, Vitreous**.

Isi per mata (OD/OS) sesuai temuan.

---

## Tab 3 — Tindakan & Resep

- **Tindakan/Prosedur:** tambahkan tindakan yang dilakukan (harga mengikuti penjamin pasien secara otomatis).
- **E-Resep:** tambahkan obat — nama obat, dosis, aturan pakai, dan rute. Resep tersimpan otomatis sambil Anda mengetik.
- Catatan untuk kasir bila ada.

> Resep dan tindakan disimpan otomatis. Untuk benar-benar mengirim pasien, lanjutkan ke tab **SOAP & Diagnosis** dan lakukan finalisasi.

---

## Tab 4 — SOAP & Diagnosis

Ini tab penutup. Yang perlu diisi:
- **Assessment** dan **Plan** — wajib.
- **Diagnosis ICD-10** (diagnosa utama dan sekunder bila ada). Untuk tindakan, tambahkan **ICD-9**.

  > Pengisian diagnosa ICD-10 di sini penting karena ikut dikirim ke Satu Sehat — lihat [panduan Satu Sehat](14-SATU-SEHAT-KEWAJIBAN-BRIDGING.md).

- **Rencana lanjutan (Plan)** menentukan ke mana pasien pergi setelah dokter:
  - **Perlu pemeriksaan penunjang** → kirim ke Penunjang (pasien kembali ke Anda setelah hasilnya ada).
  - **Perlu operasi** → jadwalkan/kirim ke Bedah.
  - **Perlu rawat inap** → arahkan ke Rawat Inap.
  - **Selesai** → pasien lanjut ke Kasir.
- **Rujukan** (bila perlu): internal (antar poli) atau eksternal (mis. rujuk balik BPJS lewat VClaim).
- **Tanda tangan / PIN dokter** untuk mengesahkan dokumen.

### Menyelesaikan pasien
Setelah SOAP, diagnosa, dan tanda tangan lengkap, **finalisasi**. Pasien akan dikirim ke tahap berikutnya (Penunjang / Bedah / Rawat Inap / Kasir) sesuai rencana.

---

## Mengirim pasien ke Penunjang & menerimanya kembali

1. Buka **Order Penunjang**, pilih jenis pemeriksaan (mis. Biometri), kirim.
2. Pasien turun ke [Penunjang](05-PENUNJANG.md). Antreannya tetap milik Anda (ditandai sedang di penunjang).
3. Setelah petugas penunjang memasukkan hasil, pasien **otomatis kembali** ke antrean Anda (ditandai "Selesai Penunjang").
4. Lanjutkan pemeriksaan, lalu finalisasi seperti biasa.

---

## Dokumen Rekam Medis (form)

Selain SOAP, sebagian dokumen rekam medis bisa diisi/ditandatangani lewat aplikasi (lihat [Form Registry](13-FORM-REGISTRY.md)).

> **Catatan saat masa peralihan ke aplikasi:** sebagian form rekam medis (mis. **resume medis** dan **laporan operasi**) untuk sementara masih diisi pada **form fisik (kertas)**. Yang lewat aplikasi tetap berjalan normal: SOAP, diagnosa, resep, rujukan, dan SEP.

---

## Kesalahan umum & tips

- **Assessment & Plan wajib** sebelum finalisasi — bila tombol selesai tidak aktif, periksa kedua kotak ini.
- **Selalu isi diagnosa ICD-10** — selain untuk klaim, ini syarat data terkirim ke Satu Sehat.
- **Pasien tidak pindah setelah diperiksa?** Pastikan Anda sudah **finalisasi** di tab SOAP & Diagnosis, bukan hanya menyimpan resep.
- Resep tersimpan otomatis, tapi pengiriman pasien hanya terjadi saat finalisasi.

---

← Sebelumnya: [Refraksionis](03-REFRAKSIONIS.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md) · Lanjut: [Penunjang →](05-PENUNJANG.md)
