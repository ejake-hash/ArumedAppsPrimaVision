# 10 — Panduan Asuransi & BPJS / Klaim

← Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md)

---

## Untuk siapa & kapan dipakai

Untuk **petugas asuransi dan verifikator klaim**. Modul ini punya **dua menu** terpisah di sidebar:
- **Asuransi & TPA** — untuk pasien dengan asuransi swasta (non-BPJS).
- **Klaim BPJS** — untuk klaim pasien BPJS.

> **Istilah:** **TPA** = perusahaan pengelola klaim asuransi. **Plafon** = batas tanggungan. **Copay** = bagian yang dibayar pasien. **Cover** = jumlah yang ditanggung. **SEP** = Surat Eligibilitas Peserta BPJS. **ICD-10** = kode diagnosa, **ICD-9** = kode tindakan.

---

# Bagian A — Asuransi & TPA (asuransi swasta)

Buka lewat menu **Asuransi & TPA**. Ada **tiga tab**: Verifikasi Pending, Klaim, dan Aging Report.

## Tab 1 — Verifikasi Pending

Daftar pasien hari ini yang penjaminnya asuransi dan **belum diverifikasi**. Tugas Anda: memastikan pasien benar-benar dijamin dan berapa tanggungannya.

1. Pilih pasien dari daftar.
2. Isi formulir verifikasi:
   - **Nomor polis** dan **nama plan/produk** asuransi.
   - **Plafon** (batas tanggungan).
   - **Copay** — dalam persen dan/atau nominal tetap.
   - **Jumlah ditanggung (covered amount)**.
   - **Pengecualian (exclusions)** bila ada pembatasan.
   - **Catatan eligibilitas** bila perlu.
3. Simpan verifikasi. Setelah tersimpan, pasien hilang dari daftar Pending, dan data cover ini akan dipakai [Kasir](08-KASIR.md) untuk menghitung bagian pasien.

> Verifikasi sebaiknya dikerjakan lebih awal agar saat pasien sampai di kasir, perhitungan cover sudah siap.

## Tab 2 — Klaim

Daftar klaim asuransi. Di sini Anda memantau status dan **mengirim/mengirim ulang** klaim ke perusahaan asuransi. Setiap tindakan tercatat di **riwayat (timeline)** klaim.

## Tab 3 — Aging Report

Daftar klaim yang masih **outstanding** (belum dibayar), dengan penanda klaim yang sudah **melewati batas waktu (overdue)** sesuai ketentuan asuransi. Gunakan untuk menagih klaim yang tertunda.

---

# Bagian B — Klaim BPJS

Buka lewat menu **Klaim BPJS**. Berisi daftar klaim BPJS yang bisa disaring per status:

**Semua · Draft · Review · Terverifikasi · Terkirim · Lunas** (dan **Ditolak**).

## Alur status klaim

Klaim BPJS bergerak maju melalui tahapan:

```
DRAFT  →  REVIEW  →  TERVERIFIKASI  →  TERKIRIM   →  (LUNAS)
                                                       (DITOLAK)
```

Untuk menaikkan status, buka detail klaim lalu klik tombol **"Tandai [status berikutnya]"** (misalnya "Tandai Review", "Tandai Terverifikasi", "Tandai Terkirim").

## Memeriksa & melengkapi klaim

1. Klik sebuah klaim untuk membuka **panel detail** di sebelah kanan.
2. Periksa data **SEP**: No. SEP dan No. Kartu peserta.
3. Lengkapi **Checklist Berkas**:
   - **Resume medis**
   - **Diagnosa utama (ICD-10)**
   - **Kode tindakan (ICD-9)**
   - **Bukti penunjang**
4. Lihat **Riwayat Status** untuk menelusuri perjalanan klaim.
5. Bila berkas lengkap dan benar, naikkan status hingga **Terkirim**.

> **Kelengkapan klaim bergantung pada stasiun lain.** Resume medis, diagnosa ICD-10, dan kode tindakan ICD-9 berasal dari dokter. Bila ada yang kurang, koordinasikan dengan dokter terkait — lihat [panduan Dokter](04-DOKTER.md).

---

## Hubungan dengan Kasir

Untuk pasien BPJS, kasir cukup **mengonfirmasi** kunjungan selesai — pasien tidak membayar langsung dan **tidak perlu cetak struk**. Penyelesaian biaya dilakukan lewat **klaim** di modul ini. Lihat [panduan Kasir](08-KASIR.md).

---

## Kesalahan umum & tips

- **Verifikasi asuransi lebih awal** (sebelum pasien ke kasir) agar perhitungan cover sudah siap.
- **Perhatikan plafon & exclusions** — selisih di atas plafon menjadi tanggungan pasien.
- **Klaim BPJS tidak bisa naik status** bila berkas belum lengkap — cek checklist (resume, ICD-10, ICD-9, bukti penunjang).
- **Pantau Aging Report** secara berkala agar klaim asuransi tidak terlambat ditagih.

---

← Sebelumnya: [Rawat Inap](09-RAWAT-INAP.md) · Kembali ke [Modul Pelatihan](00-MODUL-PELATIHAN-PRIMA-VISION.md) · Lanjut: [Inventori Farmasi →](11-INVENTORI-FARMASI.md)
