# 09 — Panduan Rawat Inap

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **perawat dan admin rawat inap (ranap)**. Di sini Anda mengelola tempat tidur, menerima pasien menginap, memindahkan kamar, dan memulangkan pasien. Buka lewat menu **Rawat Inap** di sidebar.

Pasien masuk ke rawat inap dari dua arah: dirujuk **dokter** (rencana opname) atau dari **bedah** (pasca-operasi yang perlu menginap).

---

## Tata letak layar

Tiga tab di bagian atas:
- **Papan (Board)** — peta tempat tidur per ruang.
- **Menunggu Kamar** — pasien yang sudah perlu menginap tapi belum dapat kamar.
- **Aktif** — pasien yang sedang dirawat.

---

## A. Papan tempat tidur (Board)

Menampilkan semua kamar dan tempat tidur dengan statusnya:
- **AVAILABLE** (tersedia / kosong)
- **OCCUPIED** (terisi)
- Status bersih-bersih untuk bed yang baru ditinggalkan.

Gunakan papan ini untuk melihat sekilas ketersediaan kamar.

---

## B. Menerima pasien menginap (Admit)

1. Buka tab **Menunggu Kamar**, pilih pasien.
2. Klik **Admit** lalu isi:
   - **Tempat tidur (bed)** — pilih dari bed yang tersedia.
   - **Kelas hak** pasien (kelas yang menjadi haknya).
   - Waktu masuk (bila perlu).
3. Bila bed yang dipilih berbeda kelas dengan hak pasien (**titip kelas**), aplikasi akan minta konfirmasi — tarif tetap mengikuti **kelas hak** pasien.
4. Simpan. Pasien kini masuk daftar **Aktif** dan bed menjadi terisi.

---

## C. Memindahkan kamar (Transfer)

Pada pasien aktif, pilih **Transfer**, lalu pilih **bed tujuan** dan alasan. Pasien pindah, bed lama dibebaskan.

---

## D. Memulangkan pasien (Discharge)

1. Pada pasien aktif, buka **Discharge (Pulang)**.
2. Pilih jenis kepulangan (mis. **pulang** atau **dirujuk**), isi ringkasan dan rencana kontrol.
3. **Obat pulang (opsional):** tambahkan obat untuk dibawa pulang — akan **ditagih** dan **diteruskan ke [Farmasi](07-FARMASI.md)**.
4. Untuk pasien BPJS, bila ada rencana rawat inap lanjutan, isi data **SPRI** sesuai kebutuhan.
5. Selesaikan. Pasien dipulangkan dan diarahkan ke **Kasir** untuk penyelesaian tagihan (bila ada obat pulang, juga ke Farmasi).

---

## E. SEP, riwayat, dan BPJS rawat inap

Di modul Rawat Inap tersedia juga pengelolaan **SEP** (lihat/perbarui), **surat kontrol / SPRI** saat pulang, dan **riwayat BPJS** pasien rawat inap. Detail klaim BPJS ada di [panduan Asuransi & BPJS/Klaim](10-ASURANSI-DAN-BPJS-KLAIM.md).

---

## Kesalahan umum & tips

- **Pilih bed & kelas hak** keduanya saat admit — bila salah satu kosong, pasien tidak bisa di-admit.
- **Titip kelas:** boleh menempatkan pasien di bed kelas berbeda, tetapi tarif mengikuti kelas hak — konfirmasikan dengan benar.
- **Obat pulang** dimasukkan saat discharge agar ikut ditagih dan disiapkan farmasi; jangan lupa bila pasien membawa obat pulang.
- Rawat inap berbeda dari rawat jalan: satu pasien bisa dirawat beberapa hari — kartunya tetap di papan sampai dipulangkan.

---

← Sebelumnya: [Kasir & Billing](08-KASIR.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)
