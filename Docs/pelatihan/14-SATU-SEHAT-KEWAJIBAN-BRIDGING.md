# 14 — Satu Sehat: Kewajiban Kirim Data

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **semua petugas klinis** (admisi, dokter, farmasi, inventori) dan **admin integrasi**. Panduan ini menjelaskan **apa itu Satu Sehat**, **kewajiban rumah sakit mengirim data**, dan — yang terpenting — **apa yang harus Anda lakukan sehari-hari agar data bisa terkirim dengan lengkap.**

> **Istilah:** **Satu Sehat** = platform Kementerian Kesehatan untuk pertukaran data kesehatan nasional. **NIK** = nomor KTP. **KFA** = kode standar nasional untuk obat/alkes. **IHS** = nomor identitas pasien/tenaga kesehatan di Satu Sehat. **ICD-10** = kode diagnosa.

---

## 1. Apa itu kewajiban ini?

Sesuai peraturan Kementerian Kesehatan (**PMK No. 24 Tahun 2022** tentang Rekam Medis Elektronik dan kebijakan **interoperabilitas Satu Sehat**), setiap fasilitas kesehatan **wajib menyelenggarakan rekam medis elektronik** dan **mengirimkan data pelayanan** ke platform Satu Sehat.

Aplikasi ArumedApps sudah menyiapkan pengiriman ini secara **otomatis**. Tugas petugas bukan mengirim manual, melainkan **memastikan datanya lengkap dan benar** saat melayani pasien — karena data yang kurang lengkap tidak akan terkirim.

---

## 2. Data apa saja yang dikirim?

Aplikasi mengirim **empat jenis data** pelayanan ke Satu Sehat:

| Data dikirim | Artinya | Berasal dari |
|--------------|---------|--------------|
| **Kunjungan** | Catatan bahwa pasien berkunjung/dilayani | Dibuat saat pasien didaftarkan & dilayani |
| **Diagnosa** | Penyakit pasien (kode ICD-10) | Diisi **dokter** di tab SOAP & Diagnosis |
| **Peresepan elektronik** | Resep obat yang ditulis dokter | Resep yang dibuat **dokter** |
| **Pemberian obat** | Obat yang benar-benar diserahkan ke pasien | Saat **farmasi** menyelesaikan penyerahan obat |

> Singkatnya: Satu Sehat ingin tahu **siapa berkunjung, didiagnosa apa, diberi resep apa, dan obat apa yang benar-benar diberikan.**

---

## 3. Kapan data dikirim?

- **Otomatis setiap malam:** aplikasi mengumpulkan kunjungan yang **sudah selesai** hari itu lalu mengirimnya secara terjadwal (sekitar tengah malam). Bila ada yang gagal, sistem mencoba ulang.
- **Bisa juga manual:** admin integrasi dapat memicu pengiriman lewat menu **Bridging → Satu Sehat** bila diperlukan.

Karena pengiriman terjadi setelah pasien selesai dilayani, **kelengkapan data sangat bergantung pada apa yang petugas isi selama pelayanan.**

---

## 4. Apa yang harus DILAKUKAN tiap petugas

Inilah bagian terpenting. Data hanya terkirim lengkap bila syarat-syarat berikut dipenuhi:

### 👤 Petugas Admisi
- **Isi NIK pasien dengan benar.** NIK dipakai untuk mengenali pasien di Satu Sehat. Pasien tanpa NIK menyulitkan pengiriman.
- Lihat [panduan Admisi](01-ADMISI.md).

### 🩺 Dokter
- **Selalu isi diagnosa ICD-10** di tab **SOAP & Diagnosis**. Tanpa diagnosa, data diagnosa tidak bisa dikirim — dan kunjungan jadi tidak lengkap.
- Tulis **resep lewat aplikasi** (peresepan elektronik), bukan hanya di kertas.
- Pastikan profil dokter punya **NIK** (data tenaga kesehatan). Dokter tanpa NIK membuat kunjungannya gagal terkirim. NIK dokter diatur di menu **Hak Akses / data pengguna**.
- Lihat [panduan Dokter](04-DOKTER.md).

### 💊 Farmasi
- **Selesaikan proses penyerahan obat di aplikasi** (sampai status "diserahkan"). Hanya obat yang benar-benar diserahkan yang tercatat sebagai "pemberian obat" di Satu Sehat.
- Pastikan obat yang dilayani punya **kode KFA** (lihat poin Inventori). **Obat tanpa kode KFA tidak ikut terkirim** ke Satu Sehat.
- Lihat [panduan Farmasi](07-FARMASI.md).

### 📦 Inventori / Admin Obat
- **Isi kode KFA pada setiap obat** di master obat. Ini syarat agar resep & pemberian obat bisa dilaporkan. Lihat [panduan Inventori](11-INVENTORI-FARMASI.md).

---

## 5. Memantau kesiapan & hasil pengiriman (admin integrasi)

Admin membuka menu **Bridging** di sidebar, lalu pilih **Satu Sehat**. Di sana tersedia:

- **Status koneksi** — apakah sambungan ke Satu Sehat aktif dan kapan terakhir diuji.
- **Kartu "Kesiapan Data"** — peringatan bila ada data yang belum lengkap, misalnya:
  - "*X dokter belum punya NIK*" → lengkapi di menu data pengguna.
  - "*X obat belum punya kode KFA*" → lengkapi di Inventori Farmasi.
  - "*X pasien belum punya IHS*" → ini akan terisi otomatis saat pengiriman (tidak perlu tindakan manual).
- **Ringkasan pengiriman** — jumlah kunjungan yang sudah terkirim, tertunda, dan gagal, serta rincian per jenis data (**Kunjungan / Diagnosis / Peresepan / Obat**).

Bila kartu Kesiapan Data menampilkan "**✓ Semua data siap dikirim**", artinya tidak ada yang perlu dilengkapi.

> Mencari kode KFA obat: di master obat tersedia pencarian KFA untuk mengisi kode obat dari kamus nasional. Lihat [panduan Inventori](11-INVENTORI-FARMASI.md).

---

## 6. Ringkasan singkat

| Bila Anda… | Lakukan ini supaya data terkirim |
|------------|-----------------------------------|
| Mendaftarkan pasien | Isi **NIK** dengan benar |
| Memeriksa pasien (dokter) | Isi **diagnosa ICD-10**, tulis **resep di aplikasi**, pastikan **NIK dokter** ada |
| Menyerahkan obat (farmasi) | **Selesaikan penyerahan** di aplikasi; obat harus ber-**KFA** |
| Mengelola master obat | Isi **kode KFA** tiap obat |

Dengan kebiasaan sederhana ini, kewajiban pelaporan ke Satu Sehat terpenuhi tanpa kerja tambahan — karena aplikasi yang mengirim, dan Anda yang memastikan datanya lengkap.

---

## Kesalahan umum & tips

- **Diagnosa kosong = data tidak lengkap.** Jangan menyelesaikan pasien tanpa ICD-10.
- **Resep hanya di kertas tidak terkirim.** Tulis resep lewat aplikasi.
- **Obat tanpa KFA dilewati** saat pengiriman — koordinasikan dengan Inventori untuk melengkapi KFA.
- **Tidak perlu menunggu/menekan kirim sendiri** — pengiriman berjalan otomatis. Fokuslah pada kelengkapan data.
- **Pantau "Kesiapan Data"** secara berkala (admin) agar peringatan ditindaklanjuti sebelum jadwal kirim malam hari.

---

← Sebelumnya: [Form Registry](13-FORM-REGISTRY.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)
