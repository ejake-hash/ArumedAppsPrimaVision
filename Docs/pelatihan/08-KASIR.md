# 08 — Panduan Kasir & Billing

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **kasir**. Di sini Anda menyusun tagihan pasien, memproses pembayaran, dan mencetak kwitansi. Buka lewat menu **Kasir & Billing** di sidebar.

Kasir adalah **pintu sebelum obat**: setelah pasien menyelesaikan tagihan, bila ada resep maka pasien lanjut ke [Farmasi](07-FARMASI.md); bila tidak, pasien selesai.

> **Istilah:** **Plafon** = batas tanggungan penjamin. **Copay** = bagian yang tetap dibayar pasien. **Cover** = jumlah yang ditanggung asuransi.

---

## Tata letak layar

- **Panel kiri:** antrean pasien hari ini (saringan Belum Bayar / Lunas, dan BPJS / Umum / Asuransi). Bisa cari berdasarkan nama / No. RM / nomor antrean.
- **Panel kanan:** rincian tagihan pasien terpilih, dikelompokkan per kategori (konsultasi, pemeriksaan, obat, BHP, IOL, tindakan, dll.).

---

## Memproses pembayaran

1. **Pilih pasien** dari antrean.
2. **Periksa rincian tagihan.** Item muncul otomatis dari dokter/penunjang/bedah/farmasi. Beri tanda centang menandakan item yang sudah masuk.
3. **Diskon (bila ada):**
   - Per item: dalam Rupiah atau persen.
   - Global (seluruh tagihan): dalam Rupiah atau persen.
4. **Penjamin & cover:**
   - **Umum:** pasien membayar penuh.
   - **Asuransi/TPA:** layar menampilkan plafon, persen/nominal copay, dan jumlah yang ditanggung (cover). Sistem memberi **estimasi bagian pasien** = nilai terbesar antara (persen × tagihan) atau copay tetap. Bila **plafon tidak cukup**, muncul peringatan bahwa selisihnya jadi tanggungan pasien.
   - **Ditanggung penuh:** bila cover menutup seluruh tagihan, pasien tidak perlu membayar — cukup **konfirmasi**.
   - **BPJS:** kunjungan BPJS dikonfirmasi sebagai selesai (klaim diproses lewat sistem BPJS).
5. **Input pembayaran:** masukkan nominal sesuai metode (tunai/debit/kredit/transfer). Nominal bayar diisi manual oleh kasir (tidak otomatis terisi).
6. **Selesaikan** transaksi hingga lunas.

---

## Mencetak kwitansi

- Cetak kwitansi/invoice untuk pasien. Anda bisa mengatur elemen yang tampil: logo/kop klinik, stempel, dan tanda tangan elektronik kasir.

> **Pasien BPJS:** tidak perlu mencetak billing/struk untuk pasien. Tagihan BPJS diselesaikan lewat proses klaim di sistem BPJS, bukan dibayar langsung pasien. Lihat [panduan Asuransi & BPJS/Klaim](10-ASURANSI-DAN-BPJS-KLAIM.md).

---

## Kesalahan umum & tips

- **Periksa diskon dan cover** sebelum menyelesaikan — terutama untuk pasien asuransi, perhatikan peringatan plafon.
- **Nominal bayar diisi manual** — pastikan sesuai uang yang diterima.
- **Pasien tidak lanjut ke farmasi?** Pasien hanya turun ke farmasi bila ada resep. Tanpa resep, pasien langsung selesai.
- **Pasien BPJS** cukup dikonfirmasi selesai; tidak perlu cetak struk pembayaran.

---

← Sebelumnya: [Farmasi](07-FARMASI.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md) · Lanjut: [Rawat Inap →](09-RAWAT-INAP.md)
