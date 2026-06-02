# Modul Pelatihan Pengguna — SIMRS ArumedApps Prima Vision

> Panduan pemakaian aplikasi untuk petugas **RS Mata Prima Vision (Medan)**.
> Ditulis dengan bahasa sederhana. Setiap petugas cukup membaca file yang sesuai dengan tugasnya.

---

## 1. Selamat datang

**ArumedApps** adalah aplikasi rumah sakit (SIMRS) yang dipakai dari pasien mendaftar sampai pasien selesai dan pulang. Semua stasiun pelayanan — pendaftaran, perawat, dokter, farmasi, kasir, dan lainnya — bekerja di dalam satu aplikasi yang sama, saling tersambung lewat **antrean**.

Aplikasi dibuka lewat **browser** (Google Chrome / Microsoft Edge). Tidak perlu instal apa pun di komputer petugas.

**Modul pelatihan ini untuk siapa?** Untuk semua petugas yang memakai aplikasi: petugas pendaftaran, perawat/triase, refraksionis, dokter, petugas penunjang, tim bedah, farmasi, kasir, petugas asuransi/BPJS, petugas gudang/inventori, dan admin.

---

## 2. Cara masuk (login)

1. Buka alamat aplikasi di browser (diberikan oleh admin/IT rumah sakit).
2. Di halaman masuk, isi **Nama Pengguna** dan **Kata Sandi**.

   > **Penting:** masuk memakai **nama pengguna (username)**, **bukan email**.

3. Klik **Masuk**.

Setiap petugas punya akun sendiri sesuai tugasnya (misalnya akun untuk dokter, perawat, kasir, dan seterusnya). Akun menentukan menu apa saja yang muncul di layar Anda — jadi wajar bila menu petugas pendaftaran berbeda dengan menu dokter.

**Mengganti kata sandi:** klik **nama/foto Anda di pojok kiri bawah** untuk membuka profil, lalu ganti kata sandi. Gantilah kata sandi bawaan saat pertama kali masuk.

**Keluar (logout):** tombol **Keluar** ada di pojok kiri bawah, di bawah nama Anda. Selalu keluar bila meninggalkan komputer.

---

## 3. Mengenal layar

- **Menu di sebelah kiri (sidebar):** daftar semua bagian aplikasi yang boleh Anda buka. Bisa diperkecil dengan tombol panah di pojok kiri atas.
- **Isi di sebelah kanan:** halaman dari menu yang sedang Anda pilih.
- **Antrean:** sebagian besar stasiun (admisi, perawat, dokter, farmasi, kasir, dst.) menampilkan **daftar antrean pasien** — biasanya di panel kiri. Anda memanggil pasien dari daftar ini, lalu mengerjakan tugasnya di panel kanan.
- **Penyaring (filter) antrean:** hampir setiap antrean punya saringan **"Menunggu / Selesai"** dan saringan penjamin **"BPJS / Umum / Asuransi"**, ditambah kotak **pencarian** (cari berdasarkan nama, nomor rekam medis, atau nomor antrean).

  > Antrean ditampilkan **per hari**. Bila pasien tidak muncul, periksa dulu saringan (mungkin tersaring "Selesai" atau penjamin tertentu) sebelum menyimpulkan ada masalah.

**Nama-nama menu yang akan Anda lihat di sidebar:**
Dashboard · Admisi · Rekam Medis · Triase / Perawat · Refraksionis · Pemeriksaan Dokter · Tanda Tangan Dokumen · Penunjang · Bedah · Pasien Terjadwal · Rawat Inap · Farmasi · Inventori Farmasi · Kasir & Billing · Klaim BPJS · Asuransi & TPA · Jadwal Dokter · Master Data · Tarif & Paket Bedah · Hak Akses · Antrean TV · BRIDGING · Pengaturan.

---

## 4. Alur layanan pasien (gambaran besar)

Inilah perjalanan pasien rawat jalan dari awal sampai pulang. Aplikasi memindahkan pasien antar stasiun **secara otomatis** begitu petugas menekan tombol **"Selesai"** di stasiunnya.

```
                         ┌──────────────────────────┐
   PASIEN DATANG  ───►   │         ADMISI           │  (pendaftaran + penjamin)
                         └────────────┬─────────────┘
                                      │
              ┌───────────────────────┴───────────────────────┐
              ▼                                                ▼
      ┌───────────────┐                              ┌───────────────────┐
      │ TRIASE/PERAWAT│   ◄─── berjalan paralel ───► │   REFRAKSIONIS     │
      │  (asesmen)    │      (1 nomor antrean sama)   │ (pemeriksaan mata) │
      └───────┬───────┘                              └─────────┬─────────┘
              └───────────────────┬──────────────────────────┘
                                  ▼  (kedua-duanya selesai)
                         ┌──────────────────────────┐
                         │   PEMERIKSAAN DOKTER      │  (SOAP, diagnosa, resep)
                         └────────────┬─────────────┘
                                      │
         ┌────────────────┬───────────┼─────────────────┬───────────────┐
         ▼                ▼           │                 ▼               ▼
   ┌───────────┐   ┌────────────┐     │           ┌───────────┐   ┌──────────────┐
   │ PENUNJANG │   │   BEDAH    │     │           │RAWAT INAP │   │   langsung   │
   │ (lalu     │   │ (operasi)  │     │           │ (opname)  │   │   ke kasir   │
   │  balik ke │   └─────┬──────┘     │           └─────┬─────┘   └──────┬───────┘
   │  dokter)  │         │            │                 │                │
   └─────┬─────┘         │            │                 │                │
         └──────────────►└────────────┴─────────────────┴────────────────┘
                                      ▼
                         ┌──────────────────────────┐
                         │      KASIR & BILLING      │  (tagihan & bayar)
                         └────────────┬─────────────┘
                                      ▼ (bila ada resep obat)
                         ┌──────────────────────────┐
                         │         FARMASI          │  (siapkan & serahkan obat)
                         └────────────┬─────────────┘
                                      ▼
                              PASIEN SELESAI / PULANG
```

**Hal-hal yang perlu dipahami dari alur di atas:**

- **Triase/Perawat dan Refraksionis berjalan bersamaan** untuk satu pasien (memakai satu nomor antrean yang sama). Pasien baru naik ke dokter setelah **kedua** stasiun ini menyelesaikan tugasnya.
- **Dari dokter, pasien bisa bercabang:** dikirim ke **Penunjang** (lalu kembali lagi ke dokter setelah hasilnya ada), dijadwalkan **Bedah**, dirujuk **Rawat Inap**, atau langsung ke **Kasir**.
- **Kasir adalah pintu sebelum obat.** Setelah pasien membayar/menyelesaikan tagihan, bila ada resep maka pasien lanjut ke **Farmasi**. Bila tidak ada resep, pasien langsung selesai.
- **Pasien bedah** umumnya: Dokter menjadwalkan → hari operasi pasien masuk antrean **Bedah** → setelah operasi → **Kasir**.

---

## 5. Daftar isi — pilih panduan sesuai tugas Anda

### Alur klinis (mengikuti perjalanan pasien)

| No. | Panduan | Untuk petugas |
|----|---------|---------------|
| 01 | [Admisi (Pendaftaran)](01-ADMISI.md) | Petugas pendaftaran/admisi |
| 02 | [Triase / Perawat](02-PERAWAT-TRIASE.md) | Perawat, triase |
| 03 | [Refraksionis](03-REFRAKSIONIS.md) | Refraksionis |
| 04 | [Pemeriksaan Dokter](04-DOKTER.md) | Dokter |
| 05 | [Penunjang](05-PENUNJANG.md) | Petugas pemeriksaan penunjang |
| 06 | [Bedah](06-BEDAH.md) | Dokter & perawat bedah |
| 07 | [Farmasi](07-FARMASI.md) | Apoteker, asisten apoteker |
| 08 | [Kasir & Billing](08-KASIR.md) | Kasir |
| 09 | [Rawat Inap](09-RAWAT-INAP.md) | Perawat & admin rawat inap |

### Modul pendukung

| No. | Panduan | Untuk petugas |
|----|---------|---------------|
| 10 | [Asuransi & BPJS / Klaim](10-ASURANSI-DAN-BPJS-KLAIM.md) | Petugas asuransi, verifikator klaim |
| 11 | [Inventori Farmasi (termasuk Request & Retur Barang)](11-INVENTORI-FARMASI.md) | Petugas gudang/inventori |
| 12 | [Tarif & Paket (termasuk Bedah & Penunjang)](12-TARIF-DAN-PAKET.md) | Admin tarif/keuangan |
| 13 | [Form Registry (Template Form Rekam Medis)](13-FORM-REGISTRY.md) | Admin rekam medis |
| 14 | [Satu Sehat — Kewajiban Kirim Data](14-SATU-SEHAT-KEWAJIBAN-BRIDGING.md) | Semua petugas klinis + admin integrasi |

---

## 6. Glosarium (arti istilah & singkatan)

Istilah yang sering muncul di aplikasi maupun di panduan ini:

| Istilah | Arti singkat |
|---------|--------------|
| **SIMRS** | Sistem Informasi Manajemen Rumah Sakit — aplikasi yang sedang Anda pakai. |
| **Rawat Jalan (Rajal)** | Pasien berobat lalu pulang di hari yang sama. |
| **Rawat Inap (Ranap)** | Pasien menginap di rumah sakit. |
| **Penjamin** | Pihak yang membayar layanan: **Umum** (bayar sendiri), **BPJS**, atau **Asuransi/TPA**. |
| **COB** | *Coordination of Benefit* — pasien dijamin dua pihak sekaligus (mis. BPJS + asuransi). |
| **Triase** | Penilaian/asesmen awal pasien oleh perawat (tanda vital, keluhan, dll.). |
| **Refraksi** | Pemeriksaan ukuran mata untuk menentukan kacamata/lensa. |
| **SOAP** | Cara dokter mencatat: **S**ubjektif (keluhan), **O**bjektif (pemeriksaan), **A**ssessment (diagnosa), **P**lan (rencana). |
| **ICD-10** | Kode standar diagnosa penyakit. |
| **ICD-9** | Kode standar tindakan/prosedur medis. |
| **RME** | Rekam Medis Elektronik — catatan medis pasien dalam bentuk digital. |
| **Penunjang** | Pemeriksaan tambahan untuk membantu diagnosa (mis. Biometri, OCT, USG mata). |
| **Biometri** | Pengukuran mata untuk menentukan kekuatan lensa tanam (IOL). |
| **IOL** | *Intraocular Lens* — lensa tanam yang dipasang saat operasi katarak. |
| **BHP** | Bahan Habis Pakai — barang medis sekali pakai (kasa, jarum, dll.). |
| **Visus** | Ketajaman penglihatan. **UCVA** = tanpa koreksi, **BCVA** = dengan koreksi terbaik. |
| **IOP / Tonometri** | Tekanan bola mata / alat ukurnya (NCT = tanpa sentuh). |
| **SEP** | Surat Eligibilitas Peserta — surat dari BPJS yang menyatakan pasien berhak dijamin. |
| **VClaim** | Sistem online BPJS untuk SEP, rujukan, dan klaim. |
| **Antrol** | Antrean Online BPJS lewat aplikasi Mobile JKN. |
| **INA-CBG** | Sistem tarif paket klaim BPJS rumah sakit. |
| **TPA** | *Third Party Administrator* — perusahaan pengelola klaim asuransi. |
| **Plafon** | Batas maksimal yang ditanggung penjamin. |
| **Copay** | Bagian biaya yang tetap dibayar pasien sendiri. |
| **Satu Sehat** | Platform Kementerian Kesehatan untuk pertukaran data kesehatan nasional. |
| **FHIR** | Format data standar internasional yang dipakai Satu Sehat. |
| **KFA** | Kamus Farmasi dan Alat Kesehatan — kode standar nasional untuk obat/alkes. |
| **NIK** | Nomor Induk Kependudukan (di KTP). |
| **PO** | *Purchase Order* — surat pesanan barang ke pemasok. |
| **GRN** | *Goods Receipt Note* — pencatatan barang yang diterima dari pemasok. |
| **FEFO** | *First Expired, First Out* — barang yang lebih cepat kedaluwarsa dipakai/dijual lebih dulu. |
| **HPP** | Harga Pokok Pembelian. |
| **HJA** | Harga Jual Apotek. |
| **PPN** | Pajak Pertambahan Nilai. |

---

## 7. Tips umum & masalah ringan

- **Pasien tidak muncul di antrean?** Periksa saringan: Menunggu vs Selesai, dan penjamin (BPJS/Umum/Asuransi). Antrean bersifat harian.
- **Lupa kata sandi?** Hubungi admin/superadmin untuk direset. Petugas tidak bisa mereset sendiri.
- **Menu yang Anda butuhkan tidak ada di sidebar?** Berarti akun Anda belum diberi hak akses untuk menu itu — minta admin menambahkannya lewat menu **Hak Akses**.
- **Tampilan layar terpotong?** Coba perkecil sidebar (tombol panah pojok kiri atas) atau perkecil zoom browser.
- **Selalu klik "Selesai"** di stasiun Anda bila tugas pasien sudah beres — itu yang membuat pasien berpindah ke stasiun berikutnya secara otomatis.

---

*Modul ini akan terus diperbarui mengikuti perkembangan aplikasi. Bila menemukan langkah yang berbeda dengan kenyataan di layar, laporkan ke admin/IT rumah sakit.*
