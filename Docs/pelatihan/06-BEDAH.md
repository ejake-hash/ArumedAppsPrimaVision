# 06 — Panduan Bedah

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **dokter bedah dan perawat bedah**. Modul ini punya **dua layar** di sidebar:
- **Bedah** — antrean operasi hari ini (pelaksanaan operasi).
- **Pasien Terjadwal** — daftar pasien yang sudah dijadwalkan operasi + permintaan barang ke gudang.

> **Istilah:** **IOL** = lensa tanam (operasi katarak). **BHP** = bahan habis pakai (kasa, jarum, dll.). **OD/OS** = mata kanan/kiri.

---

## A. Layar "Pasien Terjadwal"

Untuk menyiapkan operasi sebelum hari-H.

1. Pilih **minggu** lewat pemilih tanggal (atau lihat semua jadwal mendatang).
2. Lihat daftar pasien terjadwal beserta diagnosa dan prosedurnya. Untuk pasien katarak, tampil **rekomendasi IOL** per mata dari hasil pemeriksaan.
3. **Minta barang ke gudang (Request BHP/IOL):** ajukan kebutuhan BHP dan IOL untuk operasi. Permintaan masuk ke [Inventori Farmasi](11-INVENTORI-FARMASI.md) untuk disetujui dan dikirim.
4. **Retur barang:** kembalikan BHP/IOL yang tidak jadi terpakai.

---

## B. Layar "Bedah" (pelaksanaan operasi)

Panel kanan dibagi menjadi tiga tab: **Pra-Bedah → Intraop → Laporan**.

### Tab Pra-Bedah (checklist keselamatan)
Tandai daftar periksa sebelum operasi:
- **Identitas** pasien sudah benar.
- **Consent** (persetujuan tindakan) sudah ada.
- **Lokasi/marking** mata yang dioperasi benar.
- **Pupil** sudah disiapkan.
- **Alergi** sudah dicek.

Isi juga **Tim Bedah:** operator, asisten 1, asisten 2, perawat instrumen (scrub), perawat sirkuler (circ), dan anestesi.

### Tab Intraop (selama operasi)
- **Waktu:** catat jam masuk dan keluar kamar operasi (ada penghitung waktu berjalan).
- **Jenis anestesi** (mis. Topikal — pilihan bawaan, atau lainnya).
- **IOL yang dipasang:** merk, kekuatan (power), tipe, nomor seri.
- **Pemakaian BHP & alat** selama operasi.
- **Teknik operasi & temuan.**
- **Komplikasi:** tandai ada/tidak; bila ada, isi jenis dan catatannya.

### Tab Laporan (penutup)
- **Disposisi pasca-operasi:**
  - **PULANG** → pasien lanjut ke **Kasir**.
  - **RAWAT INAP** → pasien masuk daftar **Menunggu Kamar** di [Rawat Inap](09-RAWAT-INAP.md).
- **Obat pasca-operasi** (bila ada) akan diteruskan ke [Farmasi](07-FARMASI.md).
- **Finalisasi** laporan operasi untuk menyelesaikan dan memindahkan pasien.

> **Catatan saat masa peralihan ke aplikasi:** **laporan operasi resmi** untuk sementara masih ditulis pada **form fisik (kertas)**. Pencatatan di aplikasi (checklist, tim, IOL, BHP, disposisi) tetap dijalankan agar billing dan alur pasien berjalan.

---

## Kesalahan umum & tips

- **Lengkapi checklist Pra-Bedah** sebelum memulai — ini bagian keselamatan pasien.
- **Catat IOL & BHP yang benar-benar dipakai** — ini dasar tagihan dan pengurangan stok.
- **Pilih disposisi yang tepat** (Pulang vs Rawat Inap) karena menentukan ke mana pasien pergi.
- **Retur barang yang tidak terpakai** lewat layar Pasien Terjadwal agar stok gudang akurat.

---

← Sebelumnya: [Penunjang](05-PENUNJANG.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md) · Lanjut: [Farmasi →](07-FARMASI.md)
