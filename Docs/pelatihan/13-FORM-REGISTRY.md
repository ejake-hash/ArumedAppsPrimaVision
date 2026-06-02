# 13 — Panduan Form Registry (Template Form Rekam Medis)

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **admin rekam medis / kurator form** (perlu hak akses template form). **Form Registry** adalah tempat membuat **formulir rekam medis digital** sendiri — surat keterangan, lembar persetujuan, asesmen, resume, dan sebagainya — lalu menampilkannya di stasiun (dokter/perawat) untuk diisi dan dicetak.

Panduan ini mengikuti urutan kerja lengkap:
**(1) Buat Jenis Dokumen → (2) Buat Template lewat wizard 3 langkah → (3) Pakai form di stasiun.**

> **Istilah:** **Template** = rancangan formulir. **Jenis Dokumen** = kategori/pengelompokan template (mis. Surat, Resume, Consent). **Binding** = menghubungkan kolom form ke data pasien agar terisi otomatis. **Stasiun** = layar tempat form muncul (Dokter, Perawat, dll.). **TTD** = tanda tangan.

> **Prinsip umum:** form yang sudah **Aktif** muncul di stasiun. Dokumen yang sudah **difinalisasi/ditandatangani** tidak bisa diubah — koreksi dilakukan lewat **Addendum**.

---

## LANGKAH 1 — Buat Jenis Dokumen (kategori)

Setiap template harus punya **jenis dokumen** sebagai induk. Buat jenisnya dulu bila belum ada.

1. Buka sidebar **Master Data → Jenis Dokumen RM**.
2. Klik **Tambah**.
3. Isi formulir:
   - **Nama Jenis** (wajib) — mis. "Surat Keterangan", "Resume Medis", "Lembar Persetujuan".
   - **Kode** (wajib) — kode singkat, mis. `RM-6.1`.
   - **Induk** (opsional) — pilih jenis induk bila ini sub-kategori. *Tidak boleh memilih dirinya sendiri atau keturunannya.*
   - **Status** — aktifkan agar bisa dipakai.
4. **Simpan.** Jenis dokumen kini siap dipilih saat membuat template.

> Anda bisa kembali ke menu ini kapan saja untuk menambah/mengubah jenis dokumen. Jenis yang masih dipakai template tidak bisa dihapus (ada pengaman).

---

## LANGKAH 2 — Buat Template (wizard 3 langkah)

1. Buka sidebar **Master Data → Template Form RM**.
2. Klik **Buat Baru**. Anda masuk ke **wizard 3 langkah**: **Upload & Parse → Mapper → Assignment**.

### Langkah 2a — Upload & Parse (unggah file Word)

1. Siapkan rancangan form dalam file **Word (.docx)** (juga menerima PDF; `.docx` paling akurat). Ukuran maksimal kecil — gunakan file form biasa.
2. Seret file ke area unggah atau klik **Pilih File**.
3. Klik **Upload & Parse**. Sistem membaca file dan **otomatis membentuk daftar kolom (field)** dari tabel di dokumen:
   - Tiap baris tabel 2 kolom → satu field (label di kiri, isian di kanan).
   - Jenis kolom ditebak dari kata kunci: "Tanggal" → tanggal, "Jam" → waktu, "Jenis Kelamin" → pilihan L/P, "Nadi/Suhu/Tinggi" → angka, "Tanda tangan/TTD" → kotak tanda tangan, teks panjang → paragraf.
   - Teks/paragraf tetap (kop surat, kalimat hukum) dipertahankan sebagai isi statis.

> Yang **tidak** terbaca otomatis: tabel bersarang, tabel lebih dari 2 kolom, gambar/logo, dan kuesioner berskor. Bagian ini bisa Anda rapikan manual di langkah berikutnya.

### Langkah 2b — Mapper (identitas, kolom & tata letak)

Halaman ini punya tiga bagian: identitas template, daftar kolom, dan editor tata letak.

**Isi identitas template:**
- **Kode** — disarankan otomatis dari nama file (huruf besar/angka/garis bawah). **Catatan: kode tidak bisa diubah setelah template diaktifkan.**
- **Nama** — nama tampilan, mis. "Surat Keterangan Berobat".
- **Jenis Dokumen** — pilih jenis yang Anda buat di Langkah 1.
- **Sifat (Kind):** *Output* (untuk dicetak), *Input* (untuk diisi), atau *Hybrid* (keduanya).

**Atur tiap kolom (field):**
- **Label** — tulisan yang dilihat pengguna.
- **Tipe** — teks, teks panjang, tanggal, waktu, angka, pilihan, kotak centang, kotak tanda tangan, dll.
- **Binding (hubungkan ke data)** — klik penanda binding pada kolom, lalu pilih sumber data:
  - **DB / Data Pasien** — terisi otomatis dari data (mis. nama pasien, tanggal kunjungan).
  - **Klinik** — data klinik (mis. nama & alamat klinik).
  - **Statis** — dikosongkan; diisi manual oleh petugas saat dipakai.
- **Kotak tanda tangan:** tentukan **siapa yang menandatangani** (pasien/wali/saksi/dokter/perawat/petugas) dan apakah **wajib**. Bila wajib, dokumen tidak bisa difinalisasi sebelum tanda tangan itu ada.

**Susun tata letak (panel kanan):**
- Editor teks dengan tombol tebal/miring, judul, daftar, dan **sisip tabel**.
- Tombol **Sisipkan Field** untuk menaruh kolom (mis. nama, tanggal) di posisi yang diinginkan dalam surat.
- Teks tetap (kop, paragraf persetujuan, catatan kaki) bisa diketik bebas di sini.

Klik lanjut ke langkah berikutnya.

### Langkah 2c — Assignment (tempatkan di stasiun & aktifkan)

1. **Pilih stasiun** mana yang akan menampilkan form ini (boleh lebih dari satu — mis. Dokter, Perawat).
2. Untuk tiap stasiun, pilih **bagian (section)** tempat kartu form muncul (mis. Surat-Surat, Resume, Consent) dan **mode** (Output/Input/Hybrid).
3. Selesaikan:
   - **Simpan sebagai Draft** — tersimpan tapi belum muncul di stasiun.
   - **Simpan & Aktifkan** — tersimpan dan **langsung muncul** di stasiun yang dipilih. Setelah ini, **kode template terkunci permanen**.

> Setelah aktif, template tampil di daftar **Template Form RM** dengan tanda **Aktif**. Anda bisa menyalakan/mematikan keaktifannya dari daftar tersebut, atau membukanya kembali untuk diedit (kode tetap terkunci).

---

## LANGKAH 3 — Memakai form di stasiun

Setelah aktif, dokter/perawat melihat **kartu form** di bagian yang ditentukan pada layar stasiunnya.

### Form untuk dicetak (mode Output)
1. Buka stasiun (mis. [Pemeriksaan Dokter](04-DOKTER.md)) dengan pasien aktif.
2. Klik kartu form di bagiannya (mis. "Surat-Surat").
3. Muncul pratinjau dengan data pasien **terisi otomatis** (dari binding).
4. Klik **Cetak**.

### Form untuk diisi (mode Input)
1. Klik kartu form input → muncul formulir.
2. Isi kolom statis; kolom hitung (skor/total) terupdate otomatis.
3. Klik **Simpan sebagai Draft** → dokumen tersimpan berstatus Draft.
4. Bila ada kotak tanda tangan: **buka kanvas tanda tangan** untuk tiap penanda tangan (pasien/saksi/dokter), lalu tanda tangani.
5. Setelah semua tanda tangan wajib terkumpul, klik **Finalisasi & Kunci**. Dokumen menjadi final dan tidak bisa diubah lagi.

### Antrian Tanda Tangan Dokter
Dokumen yang menunggu tanda tangan dokter masuk ke menu **Tanda Tangan Dokumen** di sidebar. Dokter membuka menu itu, melihat pratinjau, lalu menandatangani.

---

## Mengoreksi dokumen yang sudah final (Addendum)

Dokumen yang sudah **difinalisasi tidak bisa diedit**. Untuk koreksi, gunakan **Addendum**: buka dokumen final, buat addendum berisi alasan dan isi koreksi. Addendum tertaut ke dokumen aslinya dan ikut tercetak sebagai lampiran.

---

## Kesalahan umum & tips

- **Buat Jenis Dokumen dulu** sebelum membuat template — template butuh jenis sebagai induk.
- **Kode template terkunci setelah aktif** — tentukan kode dengan benar sejak awal. Bila perlu ganti kode, buat template baru.
- **Kolom kosong saat dicetak?** Kolom itu kemungkinan ber-binding "Statis" — ubah ke "Data Pasien" bila ingin terisi otomatis.
- **Tidak bisa finalisasi?** Pastikan semua tanda tangan **wajib** sudah diisi.
- **File Word lama (.doc) gagal dibaca** — simpan ulang sebagai **.docx** lebih dulu.
- **Form tidak muncul di stasiun?** Pastikan template **Aktif** dan sudah ditempatkan pada stasiun + bagian yang benar di langkah Assignment.

---

← Sebelumnya: [Tarif & Paket](12-TARIF-DAN-PAKET.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md) · Lanjut: [Satu Sehat →](14-SATU-SEHAT-KEWAJIBAN-BRIDGING.md)
