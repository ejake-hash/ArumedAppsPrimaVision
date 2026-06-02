# Rencana Presentasi Canva — Modul Pelatihan Prima Vision

> **Status:** OUTLINE SIAP, BELUM di-generate. Widget tinjauan Canva tidak tampil di sisi user (2026-06-01), jadi generate ditunda. File ini menyimpan rencana agar bisa dieksekusi kapan saja.

## Tujuan
Satu deck presentasi Canva dari modul pelatihan (file `00`–`14` di `Docs/pelatihan/`), untuk pelatihan petugas RS Mata Prima Vision.

## Ketentuan (dikonfirmasi user)
1. **Palet Prima Vision** — pakai Brand Kit Canva `kAG9cyVdaJI` (biru navy `#0E3A66` + cyan `#1FAAE0`). Lihat [[reference_design_tokens_primavision]].
2. **Bahasa mudah** — istilah teknis dijelaskan inline (SEP, IOL, BHP, TPA, KFA, dll.).
3. **Ringkas** — maksimal 4 poin per slide.
4. **Konsep + Fitur + Alur + Fungsi** — tiap modul 2 slide: "Konsep & Fitur" + "Alur & Fungsi".
5. **Satu deck utuh** (00–14), ~35 slide.
6. Catatan transisi live inline saja (Dokter: form resume/laporan operasi masih fisik; Kasir: BPJS tak cetak billing).

## Parameter Canva (untuk tool)
- topic: `Pelatihan SIMRS ArumedApps Prima Vision — Modul Lengkap 00–14`
- audience: `educational`
- style: `professional`
- length: `comprehensive`
- brand_kit_id: `kAG9cyVdaJI` (nama: Prima Vision)

## Cara generate (mekanisme Canva MCP)
Canva mewajibkan 2 langkah untuk presentasi:
1. `request-outline-review` dengan `pages` (35 slide di bawah) → user tinjau di widget.
2. Setelah disetujui → `generate-design-structured` (parameter sama) membuat deck.

**Masalah saat ini:** widget tinjauan tidak tampil di sisi user. Opsi saat akan eksekusi nanti:
- Coba `request-outline-review` lagi (mungasin widget muncul di sesi lain), ATAU
- Bila tetap tak tampil, user beri izin lisan "lanjut generate" → panggil `generate-design-structured` langsung (tool ini syaratnya outline sudah ditinjau; bila terus menolak tanpa approval widget, mungkin perlu reconnect konektor Canva, atau buat per-bagian deck kecil).
- Alternatif: pecah jadi beberapa deck kecil (Pengantar / Klinis 01–09 / Pendukung 10–14) bila satu deck 35 slide gagal/timeout.

## Outline 35 slide (final, siap kirim ulang)

**Pengantar (6)**
1. Pelatihan SIMRS ArumedApps Prima Vision — sampul (subjudul, tema navy+cyan, ruang logo)
2. Pengantar: Apa itu ArumedApps? — SIMRS dari daftar s/d pulang; satu aplikasi tersambung antrean; via browser; untuk semua petugas
3. Login & Mengenal Layar — username bukan email; akun tentukan menu; ganti sandi; logout; sidebar kiri, isi kanan; daftar antrean
4. Antrean & Penyaring — filter Menunggu/Selesai + penjamin; pencarian; harian; tekan Selesai = pindah otomatis
5. Alur Layanan Pasien — Admisi→(Triase+Refraksi paralel)→Dokter→[Penunjang/Bedah/Ranap/Kasir]→Kasir→Farmasi→Selesai
6. Poin Penting Alur — Triase+Refraksi harus dua-duanya selesai; penunjang balik ke dokter; kasir pintu sebelum obat; selalu Selesai

**Alur Klinis 01–09 (18 — 2 slide/modul)**
7. 01 Admisi — Konsep & Fitur (pendaftaran, cari dulu, penjamin Umum/BPJS/Asuransi/COB, wizard 3 langkah)
8. 01 Admisi — Alur & Fungsi (isi identitas+NIK, BPJS cek+SEP, consent, cetak antrean, masuk Triase/Refraksi)
9. 02 Triase/Perawat — Konsep & Fitur (paralel Refraksi, tanda vital, BMI, keluhan wajib, alergi, CPPT)
10. 02 Triase/Perawat — Alur & Fungsi (Simpan Asesmen kunci; tiket dokter setelah dua selesai; Update Asesmen=CPPT; Kirim ke Bedah)
11. 03 Refraksionis — Konsep & Fitur (paralel Triase; OD/OS; 5 langkah; resep kacamata)
12. 03 Refraksionis — Alur & Fungsi (Autoref→Tonometri→Visus→Refraksi→Rx Final; Simpan & Lanjut)
13. 04 Dokter — Konsep & Fitur (4 tab: Data Pasien, Pemeriksaan Mata, Tindakan & Resep, SOAP & Diagnosis)
14. 04 Dokter — Alur & Fungsi (SOAP+ICD-10 wajib, e-resep, TTD/PIN, rencana tentukan tujuan, finalisasi; form resume/lap.operasi masih fisik)
15. 05 Penunjang — Konsep & Fitur (2 tab Antrean+Jenis; Biometri=ukur lensa IOL; unggah lampiran)
16. 05 Penunjang — Alur & Fungsi (pilih pasien→Mulai→isi hasil/Biometri OD-OS→Simpan→Selesai→balik ke dokter)
17. 06 Bedah — Konsep & Fitur (2 layar: Pasien Terjadwal +request/retur BHP/IOL; Bedah 3 tahap)
18. 06 Bedah — Alur & Fungsi (Pra-Bedah checklist+tim; Intraop waktu/anestesi/IOL/BHP/komplikasi; Laporan disposisi PULANG→Kasir / RAWAT INAP→Menunggu Kamar; obat pasca-op→Farmasi; lap.operasi resmi masih fisik)
19. 07 Farmasi — Konsep & Fitur (Dispensing + POS Apotek; pasien tiba stlh kasir; request/retur obat)
20. 07 Farmasi — Alur & Fungsi (verifikasi→siapkan cek jumlah→etiket→serah+TTD pasien; stok turun saat serah; POS FEFO)
21. 08 Kasir — Konsep & Fitur (pintu sebelum obat; tagihan per kategori; diskon item/global; cover asuransi plafon/copay)
22. 08 Kasir — Alur & Fungsi (pilih pasien, tagihan+diskon, estimasi copay+peringatan plafon, bayar+cetak; BPJS cukup konfirmasi tak cetak)
23. 09 Rawat Inap — Konsep & Fitur (3 tab Papan/Menunggu Kamar/Aktif; status bed; masuk dari dokter/bedah)
24. 09 Rawat Inap — Alur & Fungsi (Admit bed+kelas hak/titip kelas; Transfer; Discharge pulang/rujuk+obat pulang→Farmasi+SPRI; ke Kasir)

**Modul Pendukung 10–14 (10 — 2 slide/modul)**
25. 10 Asuransi & BPJS/Klaim — Konsep & Fitur (2 menu; Asuransi&TPA 3 tab Verifikasi/Klaim/Aging; Klaim BPJS per status; TPA=pengelola)
26. 10 Asuransi & BPJS/Klaim — Alur & Fungsi (Asuransi isi polis/plafon/copay/cover; BPJS status Draft→Review→Verified→Terkirim; checklist SEP/resume/ICD10/ICD9/penunjang)
27. 11 Inventori — Konsep & Fitur (Master Item Obat/BHP/IOL/Alat tanpa harga-stok; Pengadaan Supplier/PO/GRN; Harga; Request dari Unit)
28. 11 Inventori — Request, Retur & Harga (PO→Penerimaan batch+expiry naik stok; Request dari Unit 4 sub-tab; Kirim potong stok FEFO; HJA=HPP×(1+margin)×PPN; KFA obat utk Satu Sehat)
29. 12 Tarif & Paket — Konsep & Fitur (Tarif Tindakan termasuk Penunjang; Tarif Kamar; Metode Bayar; Paket Bedah; Kategori Tagihan)
30. 12 Tarif & Paket — Alur & Fungsi (harga beda per penjamin; Umum di Tarif Tindakan→khusus di Metode Bayar; Paket Bedah komponen Tindakan/BHP/IOL/Obat+qty)
31. 13 Form Registry — Konsep & Fitur (template=rancangan form; Jenis Dokumen=kategori; binding=isi otomatis; form aktif=kartu di stasiun)
32. 13 Form Registry — Alur 3 Langkah (1 buat Jenis Dokumen; 2 wizard Upload→Mapper→Assignment+Aktifkan; 3 pakai cetak/isi+TTD+finalisasi; Addendum)
33. 14 Satu Sehat — Kewajiban Kirim Data (platform Kemenkes PMK 24/2022; kirim otomatis Kunjungan/Diagnosa/Peresepan/Pemberian obat; jadwal tengah malam)
34. 14 Satu Sehat — Yang Harus Dilakukan Petugas (Admisi NIK; Dokter ICD-10+resep aplikasi+NIK dokter; Farmasi selesaikan serah+obat ber-KFA; Inventori isi KFA)

**Penutup (1)**
35. Penutup & Tips Umum (pasien tak muncul→cek filter; lupa sandi→admin; menu tak ada→hak akses; selalu Selesai; modul lengkap ada di dokumen panduan)

## Catatan
- Logo: user akan upload. Bila sudah ada, bisa di-`upload-asset-from-url`/sisipkan ke sampul, atau atur lewat Brand Kit.
- Isi tiap slide bersumber dari file `00`–`14` yang sudah diverifikasi vs UI nyata.
