# 12 — Panduan Tarif & Paket (termasuk Bedah & Penunjang)

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **admin tarif/keuangan**. Di sini Anda mengatur harga layanan: tarif tindakan & penunjang, tarif kamar inap, daftar penjamin beserta tarif khususnya, paket bedah, dan kategori tagihan. Buka lewat menu **Tarif & Paket Bedah** di sidebar.

Di sebelah kiri ada **menu samping** per bagian:
- **Tarif Tindakan:** Daftar Tarif
- **Tarif Kamar:** Tarif Kamar Inap
- **Metode Bayar:** Daftar Penjamin
- **Paket Bedah:** Daftar Paket
- **Kategori Tagihan**

> **Istilah:** **Penjamin** = pihak pembayar (Umum/BPJS/Asuransi). **BHP** = bahan habis pakai. **IOL** = lensa tanam.

> **Konsep penting — harga per penjamin:** harga bisa berbeda untuk Umum, BPJS, dan tiap Asuransi. Saat pasien ditagih di [Kasir](08-KASIR.md), sistem mengambil harga sesuai penjamin pasien.

---

## A. Tarif Tindakan (termasuk Penunjang)

Daftar semua layanan ber-tarif, dikelompokkan per **kategori**: Pemeriksaan, Tindakan/Prosedur, Konsultasi, **Penunjang**, Operasi/Bedah, Terapi.

- **Penunjang masuk di sini** sebagai kategori "Penunjang" — jadi pemeriksaan penunjang (mis. Biometri, OCT) diberi tarif lewat menu ini, sama seperti tindakan lain.
- Kolom harga pada daftar ini adalah **harga Umum**. Harga khusus untuk **BPJS / Asuransi** diatur terpisah di **Metode Bayar → Tarif per penjamin** (lihat bagian C).

Tambah/ubah tarif lewat tombol pada halaman; pilih kategori yang sesuai.

---

## B. Tarif Kamar Inap

Mengatur harga sewa kamar per malam untuk rawat inap.
- Harga ditetapkan **per penjamin** (Umum/BPJS/Asuransi).
- Atur tarif untuk tiap kamar/kelas yang tersedia.

> Daftar kamar & tempat tidurnya sendiri dikelola di **Master Data → Fasilitas & Ruang**; di sini hanya **harganya**.

---

## C. Metode Bayar (Daftar Penjamin) — tempat mengatur harga per penjamin

Menu **Metode Bayar** berisi daftar penjamin (Umum, BPJS, dan tiap perusahaan asuransi).

- **Klik salah satu penjamin** untuk membuka detailnya. Di sana Anda mengatur **tarif tindakan** dan **tarif paket** **khusus** untuk penjamin tersebut.
- Inilah cara membuat harga BPJS berbeda dari harga Umum, atau asuransi A berbeda dari asuransi B.

Alurnya: tetapkan harga Umum di **Tarif Tindakan** (bagian A) → lalu sesuaikan harga khusus per penjamin di sini.

---

## D. Paket Bedah

Paket bedah menggabungkan beberapa komponen menjadi satu harga (mis. paket operasi katarak).

1. Buka **Paket Bedah → Daftar Paket**, pilih atau buat paket.
2. Di halaman detail, susun komponen paket. Tiap komponen bisa berupa:
   - **Tindakan/Prosedur**
   - **BHP**
   - **IOL**
   - **Obat**
3. Atur **jumlah (qty)** tiap komponen. Sistem menampilkan **subtotal dan total**.
4. Harga komponen mengikuti **per penjamin**, sehingga total paket bisa berbeda untuk Umum/BPJS/Asuransi.

---

## E. Kategori Tagihan

Mengatur **pengelompokan** baris pada invoice (mis. konsultasi, pemeriksaan, obat, BHP, IOL, tindakan) dan urutannya. Kategori ini yang dipakai [Kasir](08-KASIR.md) untuk menyusun rincian tagihan secara rapi per kelompok.

---

## Kesalahan umum & tips

- **Harga Umum dulu, baru per penjamin** — tetapkan di Tarif Tindakan, lalu sesuaikan di Metode Bayar untuk BPJS/Asuransi.
- **Penunjang = kategori "Penunjang"** di Tarif Tindakan — jangan lupa memberinya tarif agar bisa ditagih.
- **Tarif Kamar di menu ini, daftar kamar di Master Data** — jangan tertukar.
- **Periksa total paket bedah** untuk tiap penjamin sebelum dipakai, karena harga komponen berbeda per penjamin.

---

← Sebelumnya: [Inventori Farmasi](11-INVENTORI-FARMASI.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md) · Lanjut: [Form Registry →](13-FORM-REGISTRY.md)
