# 11 — Panduan Inventori Farmasi (termasuk Request & Retur Barang)

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **petugas gudang/inventori farmasi**. Di sini Anda mengelola data barang, pembelian dari pemasok, penerimaan barang, permintaan & retur dari unit (farmasi/bedah), dan penentuan harga jual. Buka lewat menu **Inventori Farmasi** di sidebar.

Di sebelah kiri ada **menu samping** yang dikelompokkan per bagian:
- **Operasional:** Request dari Unit
- **Master Item:** Obat · BHP · IOL · Alat Medis
- **Harga:** Penentuan Harga
- **Pengadaan:** Supplier · Pembelian (PO) · Penerimaan

Di pojok kanan atas ada **lonceng notifikasi** yang berkedip saat ada Request/Retur baru dari unit.

> **Istilah:** **BHP** = bahan habis pakai. **IOL** = lensa tanam. **PO** = surat pesanan ke pemasok. **GRN** = pencatatan barang yang diterima. **FEFO** = yang lebih cepat kedaluwarsa keluar lebih dulu. **HPP** = harga pokok pembelian. **HJA** = harga jual apotek. **PPN** = pajak.

---

## A. Master barang (Obat · BHP · IOL · Alat Medis)

Empat menu **Master Item** adalah **daftar master barang**. Master hanya menyimpan **identitas barang** — bukan harga jual atau stok (harga diatur di menu Penentuan Harga; stok terbentuk dari penerimaan).

- **Obat:** kode, nama, kode KFA (untuk Satu Sehat), nama generik, pabrik, sediaan (tablet/tetes mata/dll.), golongan, formularium, satuan besar/kecil, stok minimum.
- **BHP:** nama, kategori, satuan, pabrik, stok minimum, masa kedaluwarsa (untuk yang habis pakai).
- **IOL:** merk, model, tipe (monofokal/multifokal/toric), material, power, nomor seri (unik), barcode, lot/batch, kedaluwarsa.
- **Alat Medis:** alat pakai-ulang (instrumen/peralatan).

Tiap menu punya pencarian dan saringan (mis. golongan, kategori, status aktif, stok menipis). Tambah/ubah data lewat tombol pada halaman masing-masing.

> **Isi kode KFA pada obat** bila ada — diperlukan agar data peresepan/pemberian obat bisa dikirim ke Satu Sehat. Lihat [panduan Satu Sehat](14-SATU-SEHAT-KEWAJIBAN-BRIDGING.md).

---

## B. Supplier (pemasok)

Daftar pemasok: nama, kontak, alamat, termin pembayaran, status aktif. Dipakai saat membuat Pembelian.

---

## C. Pembelian (Purchase Order / PO)

Pesanan barang ke pemasok.

1. Buka menu **Pembelian (PO)**, buat PO baru.
2. Pilih **supplier**, lalu tambahkan item (Obat/BHP/IOL), jumlah, dan harga satuan.
3. Status PO berjalan: **Draft → Terkirim → Sebagian → Diterima**. PO hanya bisa diubah saat masih **Draft**.
4. Kirim PO ke pemasok; bisa **dicetak** dengan kop surat.

Stok belum bertambah saat PO dibuat — stok masuk saat barang **diterima** (lihat Penerimaan).

---

## D. Penerimaan (Goods Receipt / GRN)

Mencatat barang yang datang dari pemasok.

1. Buka menu **Penerimaan**, buat penerimaan baru.
2. Dua cara: **dari PO** yang sudah ada, atau **langsung** (tanpa PO).
3. Untuk tiap item, isi **jumlah diterima**, **nomor batch**, dan **tanggal kedaluwarsa** (harus melewati hari ini).
4. Simpan. **Stok otomatis bertambah** sesuai batch & kedaluwarsa. Status PO terkait ikut diperbarui.

---

## E. Request Unit — Permintaan & Retur Barang

Menu **Request dari Unit** mengatur perpindahan barang antara gudang dan unit pemakai (Farmasi, Bedah). Ada **empat sub-tab**:

| Sub-tab | Isi |
|---------|-----|
| **Permintaan dari Unit** | Permintaan barang dari unit yang masuk ke gudang |
| **Pending Pengiriman** | Permintaan yang sudah **disetujui** tapi belum dikirim |
| **Retur dari Unit** | Pengembalian barang dari unit ke gudang |
| **History** | Arsip permintaan & retur yang sudah selesai |

> Status permintaan: **Menunggu** (baru masuk) → **Disetujui** → **Dikirim**, atau **Ditolak**. Status retur: **Menunggu** → **Diterima**, atau **Ditolak**.

### Memproses permintaan dari unit
1. Buka sub-tab **Permintaan dari Unit**. Saring berdasarkan unit/stasiun dan status (bawaan: Menunggu).
2. Untuk tiap permintaan berstatus **Menunggu**, Anda dapat **Setujui** atau **Tolak** (Tolak meminta alasan).
3. Permintaan yang disetujui pindah ke **Pending Pengiriman**. Di sana klik **Kirim** — akan muncul jendela untuk mengatur **jumlah dikirim** dan **batch** tiap item. Saat dikirim, **stok gudang berkurang** dan barang berpindah ke lokasi unit, mengikuti aturan **FEFO** (batch yang lebih cepat kedaluwarsa keluar lebih dulu).

### Memproses retur dari unit
1. Buka sub-tab **Retur dari Unit**. Saring berdasarkan status.
2. **Terima** retur (barang kembali menambah stok gudang) atau **Tolak** bila tidak sesuai.

> Sisi unit (cara Farmasi mengajukan request & retur) ada di [panduan Farmasi](07-FARMASI.md); sisi Bedah ada di [panduan Bedah](06-BEDAH.md). Modul ini adalah **sisi gudang** yang menyetujui dan mengirim/menerima.

---

## F. Penentuan Harga

Menentukan harga jual barang. Tersedia per jenis (Obat/BHP/IOL).

1. Buka menu **Penentuan Harga**, pilih jenis barang.
2. Untuk tiap baris, isi/ubah langsung: **HPP** (harga pokok), **margin %**, dan apakah kena **PPN**.
3. **HJA** (harga jual) dihitung otomatis dengan rumus:

   **HJA = HPP × (1 + margin%) × (PPN aktif? 1 + tarif PPN : 1)**

4. Ada juga pengaturan **tarif PPN global** — bila diubah, semua HJA dihitung ulang otomatis.
5. Simpan per baris.

---

## Stok per lokasi & opname

Stok dicatat **per lokasi** (Gudang Inventori, Bedah, Farmasi). Saat mengirim ke unit, stok berpindah lokasi — bukan hilang. Konsumsi di tiap unit memotong stok di lokasi unit itu. Untuk penyesuaian fisik (opname), gunakan fasilitas stok yang tersedia agar catatan cocok dengan barang nyata.

---

## Kesalahan umum & tips

- **Master tidak punya stok/harga** — stok lahir dari **Penerimaan**, harga dari **Penentuan Harga**.
- **Tanggal kedaluwarsa wajib** dan harus di masa depan saat menerima barang — penting untuk FEFO.
- **Isi KFA pada obat** agar peresepan/pemberian obat bisa dilaporkan ke Satu Sehat.
- **Permintaan unit tidak mengurangi stok sampai dikirim** — pastikan menekan Kirim di sub-tab Pending Pengiriman.
- **PO hanya bisa diubah saat Draft** — periksa item dengan teliti sebelum mengirim ke pemasok.

---

← Sebelumnya: [Asuransi & BPJS/Klaim](10-ASURANSI-DAN-BPJS-KLAIM.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md) · Lanjut: [Tarif & Paket →](12-TARIF-DAN-PAKET.md)
