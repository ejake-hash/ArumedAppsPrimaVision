
  ┌─────────────────────────────────────────────────────────────────────────────┐
  │                           DokterView                                         │
  │  ┌──────────────────┐  ┌──────────────────────────────────────────────────┐  │
  │  │ Antrean Dokter  LIVE│  │                                                  │  │
  │  │ Total│Tunggu│Selesai│  │  PATIENT BAR (strip atas)                        │  │
  │  ├──────────────────┤  │  👤 Hendra Wijaya  RM/NIK/usia                     │  │
  │  │[Semua][Tgg][Prss]│  │  [BPJS·SEP] [⚠Alergi]                             │  │
  │  │[BPJS][Umum][Asnr]│  │  TD:155/95 │Nadi:92│IOP:24/26│UCVA:6/60/6/36     │  │
  │  │ 🔍 Cari...       │  │  [▼Triase] [▼RO] [▼Riwayat]                       │  │
  │  ├──────────────────┤  ├──────────────────────────────────────────────────┤  │
  │  │ B001  07:45      │  │  DRAWER (collapse, klik salah satu tombol di atas)  │  │
  │  │ Hendra Wijaya    │  │  ┌── Triase: TD/Nadi/SpO₂/Suhu/Nyeri/KGD + Keluhan│  │
  │  │ 57th·L·Poli BPJS │  │  ├── RO: UCVA/BCVA/IOP OD OS + Rx OD OS + catatan │  │
  │  │ [BPJS][Triase✓]  │  │  └── Riwayat: list kunjungan lama dx+terapi        │  │
  │  │ [RO✓]            │  ├──────────────────────────────────────────────────┤  │
  │  │ [Panggil][Lewati]│  │  TABS:  [📈Data Pasien][👁Pemeriksaan Mata]          │  │
  │  ├──────────────────┤  │         [🗒Tindakan & Resep (N)][📄SOAP & Diagnosis]  │  │
  │  │ B002  08:15      │  ├──────────────────────────────────────────────────┤  │
  │  │ Dewi Lestari     │  │                                                      │  │
  │  │ 35th·P·Poli BPJS │  │  TAB 1 — Data Pasien (read-only)                   │  │
  │  │ [BPJS][Triase✓]  │  │  • Banner "hanya-baca — diisi perawat & RO"         │  │
  │  │ [Panggil][Lewati]│  │  • Card Triase: TTV dalam kotak read-only            │  │
  │  ├──────────────────┤  │  • Card RO: Visus UCVA/BCVA/IOP + Rx OD OS          │  │
  │  │ A007  08:30      │  │                                                      │  │
  │  │ Ibu Sartini      │  │  TAB 2 — Pemeriksaan Mata                           │  │
  │  │ 62th·P·Poli Umum │  │  • Anamnesis textarea                               │  │
  │  │ [Umum][Triase✓]  │  │  • Segmen Anterior: Kornea/COA/Iris/Pupil/Lensa    │  │
  │  │ [RO✓]            │  │    → OD/OS per baris, dropdown Normal/TdkNormal     │  │
  │  │ [Panggil][Lewati]│  │  • Segmen Posterior: Papil/Macula/Retina/Vitreous   │  │
  │  └──────────────────┘  │  • Catatan Slit Lamp (textarea)                      │  │
  │                         │                                                      │  │
  │                         │  TAB 3 — Tindakan & Resep                           │  │
  │                         │  • Tabel Master Tarif (search) → tombol [+ Tambah] │  │
  │                         │  • List tindakan dipilih + qty + subtotal           │  │
  │                         │  • E-Resep: form nama obat/dosis/frek + list        │  │
  │                         │                                                      │  │
  │                         │  TAB 4 — SOAP & Diagnosis                           │  │
  │                         │  • S/O/A/P textarea (tombol "Isi O dari Triase+RO") │  │
  │                         │  • Quick pick ICD-10 + diagnosa utama/sekunder      │  │
  │                         │  • ICD-9 CM (kode prosedur)                         │  │
  │                         │  • Planning: Pulang / Rujuk / Paket Bedah + tanggal │  │
  │                         │  • [✍ Tanda Tangan Digital] → [🚀 Finalisasi RME]   │  │
  │                         └──────────────────────────────────────────────────┘  │
  └─────────────────────────────────────────────────────────────────────────────┘

  Ringkasan per zona:

  ┌───────────────────┬────────────────────────────────────────────────────────────────────────────────────┐
  │       Zona        │                                        Isi                                         │
  ├───────────────────┼────────────────────────────────────────────────────────────────────────────────────┤
  │ Left queue        │ Model lama (belum diupdate ke card model Perawat)                                  │
  ├───────────────────┼────────────────────────────────────────────────────────────────────────────────────┤
  │ Patient bar       │ Strip horizontal: avatar + info + mini vitals (TD/Nadi/IOP/UCVA) + 3 tombol drawer │
  ├───────────────────┼────────────────────────────────────────────────────────────────────────────────────┤
  │ Drawer            │ Panel collapse: Triase / Data RO / Riwayat kunjungan                               │
  ├───────────────────┼────────────────────────────────────────────────────────────────────────────────────┤
  │ Tab 1 Data        │ TTV + visus read-only (dari perawat & RO)                                          │
  ├───────────────────┼────────────────────────────────────────────────────────────────────────────────────┤
  │ Tab 2 Pemeriksaan │ Segmen Anterior & Posterior per OD/OS, slit lamp                                   │
  ├───────────────────┼────────────────────────────────────────────────────────────────────────────────────┤
  │ Tab 3 Tindakan    │ Master tarif + e-resep obat                                                        │
  ├───────────────────┼────────────────────────────────────────────────────────────────────────────────────┤
  │ Tab 4 SOAP        │ SOAP textarea + ICD-10 + ICD-9 + planning + TTD digital + finalisasi               │
  └───────────────────┴───────────────────────────────────────────────────────

  
  ┌────────────────────────────────────────────────────────────────────────────┐
  │  [📋 Total Klaim]  [⏱ Menunggu Verifikasi]  [✓ Siap Dikirim]  [✕ Ditolak] │
  │  STAT CARDS — 4 kolom, masing-masing punya angka besar + ikon berwarna     │
  └────────────────────────────────────────────────────────────────────────────┘

  ┌──────────────────────────┬─────────────────────────────────────────────────┐
  │  LEFT PANEL (380px)      │  RIGHT PANEL (flex-fill)                        │
  │  ─────────────────────   │  ──────────────────────────────────────────────  │
  │  [Daftar Klaim BPJS][LIVE]│  ← kosong jika belum pilih klaim               │
  │                          │                                                  │
  │  [🔍 Cari nama/SEP...]   │  ┌──── PATIENT BANNER (gradient hijau) ───────┐ │
  │                          │  │ [A] Nama Pasien                  Rp tarif  │ │
  │  [Dari  ][Sampai  ]      │  │ NIK · Rawat Jalan · Tgl         INA-CBGs  │ │
  │                          │  │ [BPJS xxxx][SEP xxxx][DPJP: dr.xxx][badge]│ │
  │  STATUS TABS (vertikal): │  └────────────────────────────────────────────┘ │
  │  > Semua                 │                                                  │
  │    Draft        (2)      │  [alert respons BPJS — muncul jika ada]         │
  │    Dalam Review (2)      │                                                  │
  │    Terverifikasi(1)      │  TABS: [Data Klaim] [Verifikasi & Aksi] [Audit] │
  │    Terkirim     (1)      │  ─────────────────────────────────────────────── │
  │    Selesai      (1)      │                                                  │
  │    Ditolak      (1)      │  ── Tab: DATA KLAIM ──────────────────────────  │
  │                          │  ┌─ Identitas Kepesertaan ──────────────────┐   │
  │  ┌ KLAIM ITEMS ────────┐ │  │ No.SEP · No.Kartu BPJS · NIK · DPJP ... │   │
  │  │ Siti Rahayu         │ │  └──────────────────────────────────────────┘   │
  │  │ 0901R...123         │ │  ┌─ Diagnosis & Tindakan ────────────────────┐  │
  │  │ 12 Mei · dr.Andi    │ │  │ [H26.9] Katarak · [ICD-9] Tindakan ...   │  │
  │  │ H26.9 Katarak  VERIF│ │  └──────────────────────────────────────────┘  │
  │  │ border kiri berwarna│ │  ┌─ INA-CBGs & Rincian Biaya ──────────────┐   │
  │  ├─────────────────────┤ │  │ Kode Grouper | Tarif INA | Harga Kasir  │   │
  │  │ Budi Santoso  DRAFT │ │  │ Tabel: Jasa | Tindakan | Obat | Total   │   │
  │  │ ...                 │ │  │ Selisih Kasir − INA-CBGs (merah/hijau)  │   │
  │  └─────────────────────┘ │  │ [Ekspor LUPIS]  [Cetak Detail]          │   │
  │                          │  └──────────────────────────────────────────┘  │
  │                          │  ┌─ Dokumen Pendukung ──────────────────────┐   │
  │                          │  │ [📄 Resume Medis.pdf  142KB  👁 ⬇]       │   │
  │                          │  │ [📄 Hasil OCT.pdf    2.1MB  👁 ⬇]        │   │
  │                          │  │ [Upload Dokumen]                          │   │
  │                          │  └──────────────────────────────────────────┘  │
  │                          │                                                  │
  │                          │  ── Tab: VERIFIKASI & AKSI ─────────────────── │
  │                          │  STEPPER: DRAFT→REVIEW→VERIFIED→SUBMITTED→SELESAI│
  │                          │  ┌─ Checklist Berkas ──┐ ┌─ Aksi ────────────┐ │
  │                          │  │ ☑ SEP valid         │ │ [Mulai Review]    │ │
  │                          │  │ ☑ Diagnosis ICD-10  │ │ [Verifikasi Klaim]│ │
  │                          │  │ ☐ Kode tindakan     │ │ [Kembalikan Draft]│ │
  │                          │  │ ☑ DPJP lengkap      │ │ [Kirim ke BPJS]  │ │
  │                          │  │ ☐ Dokumen dilampirkan│ │ (kontekstual per │ │
  │                          │  └────────────────────┘ │  status)          │ │
  │                          │                          └───────────────────┘ │
  │                          │                                                  │
  │                          │  ── Tab: RIWAYAT & AUDIT ───────────────────── │
  │                          │  TIMELINE (terbaru di atas):                    │
  │                          │  ● VERIFIED oleh Dewi · 14 Mei 09:30           │
  │                          │  ● REVIEWED oleh Dewi · 13 Mei 14:00           │
  │                          │  ● CREATED oleh Admin · 12 Mei 08:00           │
  │                          │  [catatan: audit tidak dapat dihapus - PMK 24] │
  └──────────────────────────┴─────────────────────────────────────────────────┘

           ┌──── MODAL KONFIRMASI (saat klik "Kirim ke BPJS") ────┐
           │  [✈ ikon] Konfirmasi Pengiriman ke BPJS              │
           │  Nama | No.SEP | Diagnosis | Tarif INA-CBGs           │
           │  ⚠ Tindakan ini TIDAK DAPAT DIBATALKAN               │
           │               [Batal]   [Ya, Kirim ke BPJS]          │
           └──────────────────────────────────────────────────────┘

                                         [toast sukses/warning/info] ← pojok kanan atas

  Keterangan ringkas tiap bagian:

  ┌────────────────┬─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┐
  │     Bagian     │                                                                   Keterangan                                                                    │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Stat Cards     │ 4 KPI atas: total klaim, menunggu, siap submit, ditolak                                                                                         │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Left Panel     │ Daftar klaim 380px lebar; filter via search, tanggal, dan tab status vertikal dengan badge count                                                │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Claim Item     │ Tiap baris: nama, no.SEP, tanggal+DPJP, diagnosis, status badge berwarna, tarif INA-CBGs. Border kiri warnanya mengikuti status                 │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Patient Banner │ Header kanan setelah klaim dipilih — gradient hijau, avatar inisial, nomor BPJS+SEP, tarif besar di kanan                                       │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Tab Data Klaim │ 4 card: identitas kepesertaan, diagnosis & tindakan (kode ICD-10/9 berpill warna), rincian biaya + selisih kasir vs INA-CBGs, dokumen pendukung │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Tab Verifikasi │ Stepper horizontal status + checklist 5 item (aktif hanya saat REVIEW) + tombol aksi kontekstual sesuai status saat ini                         │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Tab Audit      │ Timeline kronologis append-only, dilindungi PMK No. 24/2022                                                                                     │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Submit Modal   │ Konfirmasi dua langkah sebelum kirim ke VClaim — irreversible, ada warning kuning                                                               │
  ├────────────────┼─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
  │ Toast          │ Notifikasi pojok kanan atas, 3,5 detik hilang otomatis, 4 warna (s/w/i/e)                                                                       │
  └────────────────┴─────────────────────────────────────────────────────────

   Korelasi MasterDataView ↔ MasterDataTarifView

  MasterDataView.vue  (parent/shell)
  ├── RBAC routing — menus[] + canAccess() menentukan tab apa yang muncul per role                                                                                                    
  ├── Top-nav tabs — 6 section (tarif, paket, rme, icd, admin, farmasi)
  ├── Sub-nav strip — muncul ketika section punya >1 leaf menu (icd, farmasi)
  ├── Toast — infrastructure terpusat, diterima lewat @toast dari child
  │
  ├── [section=tarif / paket] → <MasterDataTarifView :mode="..." @toast="..." />
  │     ↑ DELEGASI: hanya 2 dari 6 section ini yang dioutsource ke child
  │
  ├── [section=rme]    → template inline (mock data)
  ├── [section=icd]    → template inline (mock data)
  ├── [section=admin]  → template inline (mock data)
  └── [section=farmasi]→ template inline (mock data)

  MasterDataTarifView.vue  (child — terhubung ke backend)
  ├── props.mode: 'tarif' | 'paket'
  ├── [mode=tarif] → fetch masterApi.tindakan + masterApi.tarifTindakan + masterApi.penjamin
  │     tarifMap[procedureId][classification|insurer_id] = { id, price }
  │     CRUD: upsert procedure + per-penjamin tarif matrix
  │
  └── [mode=paket] → fetch masterApi.paketBedah
        CRUD: add/edit/delete surgery package

  Titik sambung kritis:

  ┌─────────────────┬─────────────────────────────────────────────────────────────────────────────┐
  │      Aspek      │                                   Detail                                    │
  ├─────────────────┼─────────────────────────────────────────────────────────────────────────────┤
  │ Masuk           │ :mode="currentSection === 'paket' ? 'paket' : 'tarif'"                      │
  ├─────────────────┼─────────────────────────────────────────────────────────────────────────────┤
  │ Keluar          │ emit('toast', {type, msg}) → @toast="(p) => toast(p.type, p.msg)"           │
  ├─────────────────┼─────────────────────────────────────────────────────────────────────────────┤
  │ Hanya child ini │ yang terhubung ke backend real — section lain masih mock                    │
  ├─────────────────┼─────────────────────────────────────────────────────────────────────────────┤
  │ RBAC            │ tarif = superadmin, kasir / paket = superadmin, dokter — difilter di parent │
  ├─────────────────┼─────────────────────────────────────────────────────────────────────────────┤
  │ Expose          │ openNewTarif, openNewPkg, refresh — bisa dipanggil dari parent via ref      │
  └─────────────────┴─────────────────────────────────────────────────────────────────────────────┘

  ---
  Sketsa UI

  ┌─────────────────────────────────────────────────────────────────────────────────┐
  │  TOP NAV TABS  (nvt)  — disaring per role                                       │
  │  [📦 Master Tarif ●] [🔪 Paket Bedah] [📄 Template RME 7] [ICD 24] [Admin] [💊]│
  │  ─────────────────────────────────────────────────────────────────────────────── │
  │  (Sub-nav strip hanya muncul jika section punya >1 child, mis. ICD/Farmasi)     │
  │  [ICD-10] [ICD-9 CM]  atau  [BHP] [IOL] [Kategori Obat] [Alkes]                │
  └─────────────────────────────────────────────────────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    SECTION 1 — MASTER TARIF  (MasterDataTarifView mode="tarif")
  ═══════════════════════════════════════════════════════════════════════════════════

    [+ Tambah Layanan]                                          ← hdr-actions

    ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────────────────────┐
    │ 📦  42          │ │ ✓  38           │ │ 〜 Rp 1.250.000                │
    │ Total Layanan   │ │ Layanan Aktif   │ │ Rata-rata · BPJS               │
    └─────────────────┘ └─────────────────┘ └─────────────────────────────────┘

    [Semua ●] [Konsultasi] [Tindakan Bedah] [Tindakan Non-Bedah] [Penunjang]
                                           ────── Penjamin [BPJS ▾] [🔍 Cari]

    ┌────────────────────────────────────────────────────────────────────────┐
    │  KODE    NAMA LAYANAN              KATEGORI    TARIF · BPJS   STATUS  AKSI│
    ├──────────┬─────────────────────────┬───────────┬─────────────┬───────┬────┤
    │  JM-001  │ Konsultasi Dokter Sp.M  │ Konsultasi│ Rp 150.000  │[Aktif]│[✏]│
    │  TN-001  │ Phacoemulsifikasi + IOL │ Tindakan  │ Rp 3.850.000│[Aktif]│[✏]│
    │  TN-002  │ Trabekulektomi          │ Tindakan  │      —      │[Aktif]│[✏]│
    │  PN-001  │ OCT Scanning            │ Penunjang │ Rp 450.000  │[Non-aktif][✏]│
    └──────────┴─────────────────────────┴───────────┴─────────────┴───────┴────┘

    ┌─── MODAL: Tambah / Edit Layanan ─────────────────────────────────────┐
    │  [Kode*] [────────Nama Layanan*────────────────]                     │
    │  [Kategori────────────────] [Status ▾]                               │
    │  [Deskripsi______________________________________________]            │
    │                                                                       │
    │  ── TARIF PER PENJAMIN ─────────────────────────────────             │
    │  Umum                    [      1.500.000 ] [tersimpan]              │
    │  BPJS                    [      3.850.000 ] [tersimpan]              │
    │  Asuransi: Sinarmas      [              0 ] [baru     ]              │
    │  Perusahaan: XYZ Corp    [               ] [baru     ]              │
    │  (kosong = hapus tarif)                                              │
    │                                                                       │
    │  [✓ Simpan Layanan & Tarif]              [🗑]                         │
    └───────────────────────────────────────────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    SECTION 2 — PAKET BEDAH  (MasterDataTarifView mode="paket")
  ═══════════════════════════════════════════════════════════════════════════════════

    [+ Tambah Paket]

    ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────────────────────┐
    │ 📦  8           │ │ ✓  7            │ │ 💰 Rp 4.200.000                │
    │ Total Paket     │ │ Paket Aktif     │ │ Rata-rata Tarif Paket          │
    └─────────────────┘ └─────────────────┘ └─────────────────────────────────┘

    [12 paket]                                            [🔍 Cari paket]

    ┌───────────────────────────────────────────────────────────────────────┐
    │  KODE     NAMA PAKET               DURASI   TARIF         STATUS AKSI │
    ├───────────┬────────────────────────┬────────┬─────────────┬──────┬────┤
    │  PKB-001  │ Phaco + IOL Monofocal  │ 45 mnt │ Rp 3.500.000│[Aktif][Detail][✏]│
    │  PKB-002  │ Vitrektomi Posterior   │ 120 mnt│ Rp 7.500.000│[Aktif][Detail][✏]│
    │  PKB-003  │ Trabekulektomi         │ 90 mnt │ Rp 4.200.000│[Aktif][Detail][✏]│
    └───────────┴────────────────────────┴────────┴─────────────┴──────┴────┘

    ┌─── MODAL: Detail Paket ──────────────────┐  ┌── MODAL: Edit Paket ─────────────┐
    │  PKB-001 · Phaco + IOL Monofocal         │  │  [Kode*] [Nama Paket*──────────] │
    │  ┌──────┬──────────┬─────────────┬──────┐│  │  [Durasi(mnt)] [Tarif(Rp)] [Status▾]│
    │  │Kode  │Durasi    │Tarif        │Status││  │  [Deskripsi________________________]│
    │  │PKB-01│45 mnt    │Rp 3.500.000 │Aktif ││  │                                   │
    │  └──────┴──────────┴─────────────┴──────┘│  │  [✓ Simpan Paket]           [🗑] │
    │  Deskripsi prosedur...                    │  └────────────────────────────────────┘
    │  [✏ Edit Paket]  [Tutup]                  │
    └──────────────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    SECTION 3 — TEMPLATE RME  (inline MasterDataView, mock data)
  ═══════════════════════════════════════════════════════════════════════════════════

    Tab nav action: [+ Tambah Template]
    Stats: [7 Total] [7 Aktif]           Search: [🔍 Cari kode/nama]

    ┌─────────────────────────────────────────────────────────────────────────┐
    │ KODE   NAMA TEMPLATE         DESKRIPSI    FIELD DIISI OLEH VERSI STATUS AKSI │
    ├────────┬──────────────────────┬───────────┬─────┬──────────┬──────┬────┤
    │ RM-1.1 │ Asesmen Awal Kep.   │ TTV, ...  │[12] │[Perawat] │v2.1  │[Aktif][Preview][Edit]│
    │ RM-1.2 │ Asesmen Awal Dokter │ SOAP, ... │[7]  │[Dokter]  │v3.0  │[Aktif][Preview][Edit]│
    │ RM-2   │ Pemeriksaan Refraksi│ Visus,...  │[13] │[Refraksionis]│v1.5│[Aktif][Preview][Edit]│
    └────────┴──────────────────────┴───────────┴─────┴──────────┴──────┴────┘

    ┌─── MODAL: Preview Template ────────────────────┐  ┌── MODAL: Edit Template ──────────┐
    │ RM-1.1 — Asesmen Awal Keperawatan              │  │ [Kode*] [────Nama Template*────] │
    │ [Perawat]  Versi 2.1 · 12 field  [🖨 Print][Edit]│  │ [Diisi Oleh▾] [Versi] [Status▾] │
    │ Formulir pengkajian awal perawat...             │  │ [Deskripsi________________________]│
    │ ── DAFTAR FIELD FORMULIR ──                     │  │ ── DAFTAR FIELD ──               │
    │ [1 Keluhan Utama ] [2 Tekanan Darah]            │  │ 1 [Keluhan Utama────────────] [✕]│
    │ [3 Nadi          ] [4 SpO₂       ]            │  │ 2 [Tekanan Darah───────────] [✕]│
    │ [5 Suhu          ] [6 Berat Badan]            │  │ + [Nama field baru...] [+ Tambah]│
    │ ...                                             │  │ [✓ Simpan Template]        [🗑]  │
    └────────────────────────────────────────────────┘  └──────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    SECTION 4 — ICD-10 & ICD-9  (sub-nav 2 child, inline, mock)
  ═══════════════════════════════════════════════════════════════════════════════════

    Sub-nav: [ICD-10 ●] [ICD-9 CM]          ICD-10 · 14 kode   [🔍 Cari]

    ┌────────────────────────────────────────────┐
    │ KODE    DESKRIPSI                  KATEGORI │
    │ H25.9   Katarak Senilis, ...       [Lensa]  │
    │ H40.1   Glaukoma Sudut Terbuka..  [Glaukoma]│
    │ H52.1   Miopia                    [Refraksi]│
    └────────────────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    SECTION 5 — MASTER ADMINISTRASI  (inline, mock)
  ═══════════════════════════════════════════════════════════════════════════════════

    [Provinsi ●] [Kabupaten] [Kecamatan] [Kelurahan]      [🔍 Cari]

    ┌────────────────────────────────────────┐
    │ KODE    NAMA WILAYAH         LEVEL     │
    │ 31      DKI Jakarta          [provinsi]│
    │ 32      Jawa Barat           [provinsi]│
    └────────────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    SECTION 6 — ALKES & FARMASI  (sub-nav 4 child, inline, mock)
  ═══════════════════════════════════════════════════════════════════════════════════

    Sub-nav: [BHP ●] [IOL] [Kategori Obat] [Alkes]     BHP · 6 item  [🔍 Cari]

    BHP:  [KODE | NAMA | KATEGORI | SAT BESAR | SAT KECIL | STOK(merah jika <20)]
    IOL:  [KODE | NAMA | TIPE     | MERK      | SAT       | FORMULARIUM(BPJS/Umum)]
    Obat: [KODE | KATEGORI        | DESKRIPSI ]
    Alkes:[KODE | NAMA | KATEGORI | SAT BESAR | SAT KECIL ]

  ┌────────────────────────────────────────────────────────────────────────────────┐
  │  PAGE HEADER                                                                   │
  │  ┌─────────────────────────────────────────────────────────────────────────┐  │
  │  │ Master Tarif & Paket Bedah                                              │  │
  │  │ Kelola master tarif layanan per-penjamin dan paket bedah klinik.        │  │
  │  └─────────────────────────────────────────────────────────────────────────┘  │
  │                                                                                │
  │  MODE TABS  (mode-tabs)                                                        │
  │  ┌─────────────────────────────────────────────────────────────────────────┐  │
  │  │ [💵 Master Tarif ●]  [📦 Paket Bedah]                                   │  │
  │  └─────────────────────────────────────────────────────────────────────────┘  │
  └────────────────────────────────────────────────────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    MODE 1: MASTER TARIF                            (MasterDataTarifView mode="tarif")
  ═══════════════════════════════════════════════════════════════════════════════════

    Header action (kanan):                                          [+ Tambah Layanan]

    STAT CARDS — 3 kolom
    ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────────────────────┐
    │ 📦  42          │ │ ✓  38           │ │ ⚡ Rp 1.250.000                 │
    │ Total Layanan   │ │ Layanan Aktif   │ │ Rata-rata · BPJS               │
    └─────────────────┘ └─────────────────┘ └─────────────────────────────────┘

    CHIP-ROW  (filter kategori + penjamin + search)
    [Semua ●] [Konsultasi] [Tindakan Bedah] [Tindakan Non-Bedah] [Penunjang]
                                    ───── Penjamin [BPJS         ▾] [🔍 Cari]

    TABEL TARIF
    ┌──────────────────────────────────────────────────────────────────────────┐
    │ KODE   │ NAMA LAYANAN              │ KATEGORI    │ TARIF·BPJS  │ STATUS │AKSI│
    ├────────┼───────────────────────────┼─────────────┼─────────────┼────────┼────┤
    │ JM-001 │ Konsultasi Dokter Sp.M    │ Konsultasi  │ Rp 150.000  │[Aktif] │[✏]│
    │ TN-001 │ Phacoemulsifikasi + IOL   │ Tindakan B  │Rp 3.850.000 │[Aktif] │[✏]│
    │ TN-002 │ Trabekulektomi            │ Tindakan B  │     —       │[Aktif] │[✏]│
    │ PN-001 │ OCT Scanning              │ Penunjang   │ Rp 450.000  │[Non-akt]│[✏]│
    │ PN-002 │ USG Mata B-Scan           │ Penunjang   │ Rp 350.000  │[Aktif] │[✏]│
    └────────┴───────────────────────────┴─────────────┴─────────────┴────────┴────┘
     ↑ kode hijau-mono   ↑ tarif "—" jika belum ada    ↑ klik untuk toggle

    ┌──── MODAL: Tambah / Edit Layanan + Tarif Penjamin (lg, ~720px) ─────────┐
    │  Edit Layanan — Phacoemulsifikasi + IOL                          [✕]    │
    ├──────────────────────────────────────────────────────────────────────────┤
    │  [KODE*  ] [────── NAMA LAYANAN* ──────────────────────────────────]    │
    │  TN-001     Phacoemulsifikasi + IOL                                     │
    │  (disabled saat edit)                                                    │
    │                                                                           │
    │  [────── KATEGORI ──────] [STATUS ▾]                                    │
    │  Tindakan Bedah            Aktif                                         │
    │                                                                           │
    │  DESKRIPSI                                                                │
    │  [─────────────────────────────────────────────────────────────────]    │
    │                                                                           │
    │  ── TARIF PER PENJAMIN ───────────────────────────────────────           │
    │  Kosongkan untuk menghapus tarif penjamin tersebut.                     │
    │  ┌──────────────────────────────────────────────────────────────────┐   │
    │  │ Umum                       [       1.500.000 ] [tersimpan]      │   │
    │  │ BPJS                       [       3.850.000 ] [tersimpan]      │   │
    │  │ Asuransi: Sinarmas         [       4.000.000 ] [tersimpan]      │   │
    │  │ Asuransi: AdMedika         [               0 ] [baru     ]      │   │
    │  │ Perusahaan: XYZ Corp       [                 ] [baru     ]      │   │
    │  │ Sosial: Yayasan ABC        [                 ] [baru     ]      │   │
    │  │                                                          (scroll)│   │
    │  └──────────────────────────────────────────────────────────────────┘   │
    │                                                                           │
    │  [✓ Simpan Layanan & Tarif (btn-ga, full)]                  [🗑 hapus]   │
    └──────────────────────────────────────────────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    MODE 2: PAKET BEDAH                              (MasterDataTarifView mode="paket")
  ═══════════════════════════════════════════════════════════════════════════════════

    Header action (kanan):                                            [+ Tambah Paket]

    STAT CARDS — 3 kolom
    ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────────────────────┐
    │ 📦  8           │ │ ✓  7            │ │ 💰 Rp 4.200.000                │
    │ Total Paket     │ │ Paket Aktif     │ │ Rata-rata Tarif Paket          │
    └─────────────────┘ └─────────────────┘ └─────────────────────────────────┘

    CHIP-ROW
    [12 paket]                                                  [🔍 Cari paket]

    TABEL PAKET
    ┌──────────────────────────────────────────────────────────────────────────┐
    │ KODE    │ NAMA PAKET             │ DURASI │ TARIF        │ STATUS │ AKSI │
    ├─────────┼────────────────────────┼────────┼──────────────┼────────┼──────┤
    │ PKB-001 │ Phaco + IOL Monofocal  │ 45 mnt │ Rp 3.500.000 │[Aktif] │[Detail][✏]│
    │ PKB-002 │ Vitrektomi Posterior   │120 mnt │ Rp 7.500.000 │[Aktif] │[Detail][✏]│
    │ PKB-003 │ Trabekulektomi         │ 90 mnt │ Rp 4.200.000 │[Aktif] │[Detail][✏]│
    │ PKB-004 │ LASIK Bilateral        │ 30 mnt │ Rp 12.000.000│[Aktif] │[Detail][✏]│
    └─────────┴────────────────────────┴────────┴──────────────┴────────┴──────┘

    ┌──── MODAL: Detail Paket (lg) ──────────┐  ┌── MODAL: Edit Paket (lg) ────────┐
    │  Phaco + IOL Monofocal           [✕]   │  │ Edit Paket — Phaco...      [✕]  │
    ├────────────────────────────────────────┤  ├──────────────────────────────────┤
    │  ┌──────┬───────┬─────────────┬──────┐ │  │ [KODE*]  [────NAMA PAKET*────] │
    │  │KODE  │DURASI │ TARIF       │STATUS│ │  │ PKB-001  Phaco + IOL Monofocal │
    │  │PKB-01│45 mnt │Rp 3.500.000│Aktif │ │  │                                  │
    │  └──────┴───────┴─────────────┴──────┘ │  │ [DURASI(mnt)][TARIF(Rp)][STATUS▾]│
    │                                          │  │  45          3500000   Aktif    │
    │  Deskripsi prosedur:                    │  │                                  │
    │  Lorem ipsum prosedur Phaco + IOL...   │  │ DESKRIPSI                        │
    │                                          │  │ [────────────────────────────] │
    │  [✏ Edit Paket]              [Tutup]   │  │                                  │
    └────────────────────────────────────────┘  │ [✓ Simpan Paket]          [🗑]  │
                                                └──────────────────────────────────┘

  ═══════════════════════════════════════════════════════════════════════════════════
    TOAST (pojok kanan atas, auto-hide 3,2s)
    ┌──────────────────────────────────────┐
    │ ✓ Tarif "Phacoemulsifikasi" disimpan │  ← toast-s (sukses)
    └──────────────────────────────────────┘
  ═══════════════════════════════════════════════════════════════════════════════════

  Keterangan ringkas per zona:

  ┌──────────────────┬───────────────────────────────────────────────────────────────┬────────────────────────────────────────────────────────────────────────────┐
  │       Zona       │                           Komponen                            │                                   Fungsi                                   │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Page Header      │ Judul + sub                                                   │ Identitas halaman (statis)                                                 │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Mode Tabs        │ 2 tab: Tarif / Paket                                          │ Toggle prop mode di MasterDataTarifView                                    │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Stat Cards       │ 3 kolom                                                       │ Total layanan, aktif, rata-rata tarif (sesuai penjamin terpilih)           │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Chip-Row         │ Kategori chip + penjamin selector + search                    │ Filter live ke tarifList (computed)                                        │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Tabel Tarif      │ Kode (mono hijau), nama, kategori, tarif, status toggle, edit │ Kolom tarif dinamis ikut penjamin terpilih                                 │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Modal Tarif      │ Form layanan + matrix penjamin scrollable                     │ Save = upsert procedure + loop upsert tarif per-penjamin (kosong = delete) │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Tabel Paket      │ Kode, nama, durasi, tarif, status, detail/edit                │ Data dari masterApi.paketBedah                                             │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Modal Detail     │ Card 4-kolom info + deskripsi                                 │ Read-only view, bisa lompat ke Edit                                        │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Modal Edit Paket │ Form code, nama, durasi, tarif, status, deskripsi             │ Create/update via masterApi.paketBedah.create/update                       │
  ├──────────────────┼───────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────────┤
  │ Toast            │ Pojok kanan atas                                              │ Diteruskan dari child via @toast event                                     │
  └──────────────────┴───────────────────────────────────────────────────────────────┴───────────

   1. HOME (screen='home')

  ┌──────────────────────────────────────────────────────────────────┐
  │ ◉ Klinik Mata Arunika                            12:34:56        │
  │   CILEGON · ANJUNGAN MANDIRI                  Kamis, 22 Mei 2026 │
  ├──────────────────────────────────────────────────────────────────┤
  │                                                                  │
  │                      Selamat Datang                              │
  │           Pilih kategori kunjungan Anda untuk memulai            │
  │                                                                  │
  │      ┌────────────────────┐    ┌────────────────────┐            │
  │      │       [💳]         │    │       [👤]         │            │
  │      │                    │    │                    │            │
  │      │    BPJS / JKN      │    │  Umum / Pasien Baru│            │
  │      │  Kode booking,     │    │  Bayar mandiri,    │            │
  │      │  BPJS, atau NIK    │    │  asuransi, baru    │            │
  │      │                    │    │                    │            │
  │      │ ◷ SEP OTOMATIS     │    │ ◷ ANTREAN ADMISI   │            │
  │      └────────────────────┘    └────────────────────┘            │
  │                                                                  │
  ├──────────────────────────────────────────────────────────────────┤
  │ ⓘ Butuh bantuan? Loket info       Arumed · BPJS · PMK 24/2022    │
  └──────────────────────────────────────────────────────────────────┘
  2 kartu pilihan besar (270px). Klik BPJS → screen 2. Klik Umum → langsung screen 5 (tiket A###).

  2. BPJS INPUT (screen='bpjs-input')

  ┌──────────────────────────────────────────────────────────────────┐
  │                  Check-in BPJS / JKN                             │
  │         Masukkan identitas untuk validasi kepesertaan            │
  │                                                                  │
  │   ┌─────────────────────────────────────────────────────────┐    │
  │   │ [📅 Kode Booking]  [💳 No. BPJS]  [👤 NIK / KTP]        │    │
  │   └─────────────────────────────────────────────────────────┘    │
  │                                                                  │
  │   ┌─────────────────────────────────────────────────────────┐    │
  │   │  Kode booking — cth: BKG2025001                         │    │
  │   └─────────────────────────────────────────────────────────┘    │
  │   Demo — coba: BKG2025001 atau BKG2025002                        │
  │                                                                  │
  │   ┌──────────────────┐    ┌──────────────────────────────┐       │
  │   │   ← Kembali      │    │      Validasi →              │       │
  │   └──────────────────┘    └──────────────────────────────┘       │
  └──────────────────────────────────────────────────────────────────┘
  Tab tri-segmen (booking / BPJS / NIK) → 1 input besar (72px) → tombol validasi.

  3. BPJS LOADING (screen='bpjs-loading')

  ┌──────────────────────────────────────────────────────────────────┐
  │                                                                  │
  │                          ◯ (spinner)                             │
  │                                                                  │
  │                     Memvalidasi Data                             │
  │              Menghubungkan ke sistem BPJS VClaim…                │
  │                          • • •                                   │
  │                                                                  │
  └──────────────────────────────────────────────────────────────────┘
  Hardcoded delay 1.5s, lalu lookup mockDB[key] → confirm atau error.

  4. BPJS CONFIRM (screen='bpjs-confirm')

  ┌──────────────────────────────────────────────────────────────────┐
  │                Konfirmasi Data Pasien                            │
  │      Pastikan data milik Anda sebelum mencetak tiket             │
  │                                                                  │
  │   ┌─────────────────────────────────────────────────────────┐    │
  │   │  ✓ TERVALIDASI BPJS VCLAIM                              │    │
  │   │                                                         │    │
  │   │  Nama Pasien       Hendra Wijaya                        │    │
  │   │  No. BPJS          0009 8765 4321                       │    │
  │   │  Poli / Layanan    Poli Mata Umum                       │    │
  │   │  Dokter            dr. Andika P, Sp.M                   │    │
  │   │  Tanggal           15 Mei 2026                          │    │
  │   │  ───────────────────────────────────────                │    │
  │   │  No. SEP Diterbitkan   09019R000201                     │    │
  │   └─────────────────────────────────────────────────────────┘    │
  │                                                                  │
  │   ┌──────────────────┐    ┌──────────────────────────────┐       │
  │   │   Bukan Saya     │    │   🖨  Cetak Tiket            │       │
  │   └──────────────────┘    └──────────────────────────────┘       │
  └──────────────────────────────────────────────────────────────────┘

  5. TICKET (screen='ticket')

  ┌──────────────────────────────────────────────────────────────────┐
  │  ┌────────────────────┐                                          │
  │  │ Klinik Mata Arunika│       ✓                                  │
  │  │           [BPJS]   │   (lingkaran hijau)                      │
  │  │ ╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌ │                                          │
  │  │                    │   Tiket berhasil diterbitkan             │
  │  │       B003         │   Silakan duduk — nama Anda akan         │
  │  │     (huge num)     │   dipanggil                              │
  │  │ ╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌ │                                          │
  │  │ Nama      Hendra W │   ╭─────╮                                │
  │  │ Poli      Mata Umum│   │ ◌15 │  detik — layar otomatis kembali│
  │  │ Dokter    dr Andika│   ╰─────╯                                │
  │  │ No. SEP   09019R…  │                                          │
  │  │ ╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌ │   [ Selesai ]                            │
  │  └────────────────────┘                                          │
  └──────────────────────────────────────────────────────────────────┘
  Kiri: tiket fisik (kartu putih 310px, perforasi atas-bawah, nomor antrian raksasa 100px hijau). Kanan: ✓ + countdown ring 15-detik auto-reset.

  Mekanik & State

  ┌────────────────┬──────────────────────────────────────────────────────────────────────────────────────────┐
  │    Komponen    │                                          Detail                                          │
  ├────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
  │ State machine  │ home → bpjs-input → bpjs-loading → bpjs-confirm → ticket (atau home → ticket untuk umum) │
  ├────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
  │ Prefix antrian │ B### (BPJS, increment bpjsCount), A### (umum, increment umumCount)                       │
  ├────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
  │ Mock data      │ mockDB keyed by BKG… / no BPJS / NIK — lookup uppercase + strip non-alnum                │
  ├────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
  │ Auto-reset     │ setInterval 1s, countdown 15→0 → resetHome()                                             │
  ├────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
  │ Layout         │ Full-viewport 100vw × 100vh, gradient gelap, top bar 80px + body flex + bottom bar 54px  │
  ├────────────────┼──────────────────────────────────────────────────────────────────────────────────────────┤
  │ Tema           │ Hijau #8abf44 (aksen), font: DM Serif Display (heading) + DM Sans (body)                 │
  └────────────────┴──────────────────────────────────────────────────────────────────────────────────────────┘

  Berikut sketsa UI dari PerawatView.vue (Triase / Pengkajian Perawat). Layout 3 kolom grid: 340px | 1fr | 260px.

  Tata letak utama (saat pasien dipilih)

  ┌────────────────────────────┬───────────────────────────────────────────────┬──────────────────────────┐
  │  ANTREAN TRIASE   [LIVE]   │  PATIENT HEADER                               │  CATATAN SOAP   [Dikunci]│
  │  N pasien hari ini         │  ┌──┐  Budi Santoso                           │ ─────────────────────────│ 
  │ ─────────────────────────  │  │ B│  RM:00123 · 45 th · Laki-laki          │ S  Subjektif             │
  │ ┌─Belum─┬─Selesai─┬─Total┐ │  └──┘  📍 Jl. Sudirman · Jakarta              │   Keluhan                │
  │ │   8   │   12    │  20  │ │        [Baru] [BPJS · 0001234]                │   Mata kanan merah…      │
  │ └───────┴─────────┴──────┘ │                              [📄 Rekam Medis] │   RPS                    │
  │ [Belum Dipanggil] [Selesai]│                              [✓ Draft]        │   2 hari, gatal…         │
  │ [Semua][BPJS][Umum/Asur..] │ ───────────────────────────────────────────── │   ⚠ Alergi               │
  │ 🔍 Cari nama / RM / antr…  │  TD 130/85   N 78   SpO₂ 98%   KGD 110        │   Penisilin              │
  │ ─────────────────────────  │                                               │ ─────────────────────────│
  │ ┌─────────────────────┐    │  ▼ Riwayat Kunjungan  (3)         ⌄          │ O  Objektif              │
  │ │A-001 [Menunggu]     │    │  ┌───────────────────────────────────────┐    │   TD     130/85 mmHg     │
  │ │Budi S. (45L · Baru) │    │  │ [Kontrol] 12 Mei 2026                 │    │   Nadi   78 bpm          │
  │ │[BPJS][Baru]         │    │  │ TD 128/82 · N 76 · SpO₂ 99% · 36.5°C │    │   SpO₂   98%             │
  │ │[📞 Panggil][↠ Lewati]│   │  │ 💬 Mata kanan gatal                   │    │   Suhu   36.6°C          │
  │ │             07:32   │    │  └───────────────────────────────────────┘    │   Resp   18 /mnt         │
  │ ├─────────────────────┤    │                                               │   BB/TB  65 / 168        │
  │ │A-002 [Dipanggil] ●  │    │  ┌─[Tanda Vital]─[Fisik]─[Anamnesis]─[Alergi]┐│   BMI    23.0 Normal     │
  │ │Ani K. (32P · Pre-Op)│    │  │                                            ││   KGD    110 mg/dL       │
  │ │[Umum][Pre-Op]       │    │  │  KARDIOVASKULAR                            ││   Nyeri  3/10  [mild]   │
  │ ├─────────────────────┤    │  │  ┌────────────┬────┬──────┐                ││ ─────────────────────────│
  │ │ ✓ A-003 (selesai)   │    │  │  │TD 130 /  85│ N78│ SpO₂ │   mmHg         ││ A  Asesmen               │
  │ └─────────────────────┘    │  │  └────────────┴────┴──────┘                ││   Diisi oleh dokter     │
  │                            │  │                                            ││ ─────────────────────────│
  │                            │  │  ANTROPOMETRI & SUHU                       ││ P  Plan                  │
  │                            │  │  ┌────┬────┬────┬────┬────┐                ││   Diisi oleh dokter     │
  │                            │  │  │Suhu│Resp│ BB │ TB │BMI │                ││                          │
  │                            │  │  │36.5│ 18 │ 65 │168 │23.0│ Normal         ││                          │
  │                            │  │  └────┴────┴────┴────┴────┘                ││                          │
  │                            │  │                                            ││                          │
  │                            │  │  METABOLIK                                 ││                          │
  │                            │  │  ┌────┐ ┌──────────────────────────────┐   ││                          │
  │                            │  │  │KGD │ │ ● Kadar gula darah normal    │   ││                          │
  │                            │  │  │110 │ └──────────────────────────────┘   ││                          │
  │                            │  │  └────┘                                    ││                          │
  │                            │  │                                            ││                          │
  │                            │  │  SKALA NYERI (NRS 0–10)                    ││                          │
  │                            │  │  [0][1][2][3●][4][5][6][7][8][9][10]      ││                          │
  │                            │  │                                            ││                          │
  │                            │  │  [✓ Simpan TTV] [Kirim ke Dokter →] [🖨]  ││                          │
  │                            │  └────────────────────────────────────────────┘│                          │
  └────────────────────────────┴───────────────────────────────────────────────┴──────────────────────────┘

  Empty state (belum ada pasien dipilih)

  ┌──────── ANTREAN ────────┬─────────── EMPTY CARD ───────────┬── SOAP (kosong) ──┐
  │  …list antrean…         │           🛡                     │                   │
  │                         │  Pilih pasien dari daftar         │                   │
  │                         │  antrean untuk mulai pengkajian   │                   │
  │                         │  triase                           │                   │
  └─────────────────────────┴───────────────────────────────────┴───────────────────┘

  Tab "Pemeriksaan Fisik"

  ┌─[Tanda Vital]─[●Fisik]─[Anamnesis]─[Alergi]──────────────────┐
  │  Kesadaran ▾    Postur ▢    Mobilitas ▾                      │
  │  [Compos mentis][Tegak…]    [Mandiri  ]                      │
  │                                                              │
  │  KONDISI MATA (observasi awal)                               │
  │  ┌─── OD — Mata Kanan ──────┐ ┌─── OS — Mata Kiri ────────┐ │
  │  │ Tampak merah/bengkak…    │ │ Tampak merah/bengkak…     │ │
  │  └──────────────────────────┘ └───────────────────────────┘ │
  └──────────────────────────────────────────────────────────────┘

  Tab "Anamnesis"

  ┌─[Tanda Vital]─[Fisik]─[●Anamnesis]─[Alergi]──────────────────┐
  │  KELUHAN UTAMA *                                              │
  │  ┌──────────────────────────────────────────────────────────┐│
  │  │ Keluhan pasien saat datang…                              ││
  │  └──────────────────────────────────────────────────────────┘│
  │  RIWAYAT PENYAKIT SEKARANG (RPS)                              │
  │  ┌──────────────────────────────────────────────────────────┐│
  │  │ Onset, durasi, faktor pencetus…                          ││
  │  └──────────────────────────────────────────────────────────┘│
  │  Riwayat Operasi Mata     Riwayat Kacamata                    │
  │  [Tidak ada / sebutkan]   [Pakai sejak…]                      │
  └───────────────────────────────────────────────────────────────┘

  Tab "Alergi"

  ┌─[Tanda Vital]─[Fisik]─[Anamnesis]─[●Alergi]──────────────────┐
  │  ⚠ Alergi tercatat di RM: Penisilin                          │
  │                                                              │
  │  ALERGI DIKETAHUI HARI INI                                   │
  │  ┌──────────────────────────────────────────────────────┐    │
  │  │ [Penisilin ×] [Aspirin ×]  Ketik & Enter…___________ │    │
  │  └──────────────────────────────────────────────────────┘    │
  │  Pisahkan dengan koma atau Enter                              │
  └───────────────────────────────────────────────────────────────┘

  Modal Rekam Medis (Teleport)

  ┌──────────────────── 📄 Rekam Medis — Budi Santoso ─────────── × ┐
  │                                                                  │
  │  📑 DOKUMEN MEDIS  (4)                                           │
  │  ┌──────────────────────────────────────────────────────────┐    │
  │  │ 📄  Resep Kacamata        OPH-RK · 10 Mei 2026 [FINAL] ›│    │
  │  │ 📄  Laporan Operasi       OPH-LO · 28 Apr 2026 [FINAL] ›│    │
  │  │ 📄  Surat Rujukan         RUJ    · 14 Mar 2026 [DRAFT] ›│    │
  │  └──────────────────────────────────────────────────────────┘    │
  │                                                                  │
  │  ⚡ RIWAYAT TTV  (3)                                             │
  │  ┌─Tgl────┬─Klas──┬─TD────┬─N─┬─SpO₂─┬─Suhu──┬─KGD─┐            │
  │  │12 Mei  │Kontrol│128/82 │76 │ 99%  │36.5°C │ —   │            │
  │  │28 Apr  │Pre-Op │135/88 │82 │ 97%  │36.8°C │ 145 │            │
  │  └────────┴───────┴───────┴───┴──────┴───────┴─────┘            │
  └──────────────────────────────────────────────────────────────────┘

  Sketsa UI lengkap AntreanTVView.vue (1920×1080 TV layout, dark green gradient).

  1. Default state — pasien sedang dipanggil

  ┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
  │ ⊙  Klinik Mata Arunika                                                          ● Sistem Aktif  │
  │    CILEGON · LAYAR ANTREAN                                                       19:42:08       │
  │                                                                                 Rabu, 22 Mei …  │
  ├──────────────────────────────────────────────────────────────────────────────┬───────────────────┤
  │                                                                              │ Antrean Hari Ini  │
  │                                                                              │ 5 pasien · 2 dip. │
  │                                                                              │ ─────────────────│
  │                                                                              │ ┌──────────────┐ │
  │                              ⊙  (logo besar bercahaya)                       │ │ SEDANG       │ │
  │                                                                              │ │ DIPANGGIL    │ │
  │                       Klinik Mata Arunika Cilegon                            │ │              │ │
  │                                                                              │ │              │ │
  │                   Spesialis kesehatan mata terpadu                           │ │   A-007      │ │ ← 88px glow
  │                          PMK No. 24/2022                                     │ │              │ │
  │                                                                              │ │ Budi Santoso │ │
  │                                                                              │ │ Loket Admisi │ │
  │                       [Mode: placeholder / youtube /                         │ │              │ │
  │                        slideshow / video lokal]                              │ │ 📞 Silakan   │ │
  │                                                                              │ │   menuju     │ │
  │                                                                              │ │   Loket      │ │
  │                                                                              │ │   Admisi     │ │
  │                                                                              │ └──────────────┘ │
  │                                                                              │ ─────────────────│
  │                                                                              │ [Menunggu 3][Sel 2]
  │                                                                              │ ─────────────────│
  │                                                                              │ A-008  Siti R.   │
  │                                                                              │        Loket Adm │
  │                                                                              │ A-009  Ahmad F.  │
  │                                                                              │        Loket Adm │
  │                                                                              │ A-010  Dewi L.   │
  │                                                                              │        Loket Adm │
  ├──────────────────────────────────────────────────────────────────────────────┴───────────────────┤
  │ ▌ INFO │ ◌ Pendaftaran dibuka pukul 07.00 WIB · ◌ Harap siapkan BPJS, KTP… (scroll →) │  v1.0  ⚙ │
  └──────────────────────────────────────────────────────────────────────────────────────────────────┘

  Top bar (80px) — logo SVG iris 56×56, brand "Klinik Mata Arunika" + sub "CILEGON · LAYAR ANTREAN", status pill hijau dengan dot blinking, jam besar HH:MM:SS + tanggal Indonesia.

  Main panel — grid 1fr | 480px:
  - Kiri (video panel) — bisa placeholder (logo + tagline), embed YouTube, slideshow gambar, atau video lokal. Ganti via control panel.
  - Kanan (queue panel) — fixed 480px:
    - Header "Antrean Hari Ini" + sub "N pasien · M dipanggil"
    - Kartu Sedang Dipanggil (hijau gradient, no antrean 88px glow + nama + label stasiun + badge "Silakan menuju…")
    - Tab strip [Menunggu (N)][Selesai (M)]
    - List item per row: no antrean (26px serif hijau) + nama + label stasiun + status icon (jam waiting kuning / centang done hijau)

  Bottom (56px) — label "INFO" hijau solid + ticker scroll horizontal (32s loop) + version + gear icon untuk buka PIN modal.

  ---
  2. Empty state (belum ada panggilan)

  ┌────────────────────────────────────────┐
  │ Antrean Hari Ini                       │
  │ 5 pasien · 0 dipanggil                 │
  │ ─────────────────────────────────────  │
  │ ┌────────────────────────────────────┐ │
  │ │      BELUM ADA PANGGILAN           │ │ ← opacity 0.45
  │ │                                    │ │
  │ │              —                     │ │ ← 56px muted
  │ │                                    │ │
  │ │   Menunggu antrean dipanggil       │ │
  │ └────────────────────────────────────┘ │
  │ ─────────────────────────────────────  │
  │ [Menunggu 5][Selesai 0]                │
  │ A-006  Hendra W.  Loket Admisi    ⏱   │
  │ A-007  Ibu Sartini  Loket Admisi  ⏱   │
  │ A-008  Siti R.  Loket Admisi      ⏱   │
  └────────────────────────────────────────┘

  ---
  3. Tab "Selesai" aktif

  ┌────────────────────────────────────────┐
  │ [Menunggu 3][●Selesai 2]               │
  │ ─────────────────────────────────────  │
  │ A-001  Budi S.  Loket Admisi    ✓     │ ← muted opacity .65
  │ A-002  Siti R.  Loket Admisi    ✓     │
  └────────────────────────────────────────┘

  ---
  4. Flash overlay (saat baris baru CALLED) — full screen

  ┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
  │                                                                                                  │
  │                                                                                                  │
  │                                                                                                  │
  │                             NOMOR ANTREAN DIPANGGIL                                              │
  │                                                                                                  │
  │                                                                                                  │
  │                                                                                                  │
  │                                   ┌─────────────────────┐                                        │
  │                                   │                     │                                        │
  │                                   │      TR-001         │  ← clamp(100px,20vw,220px), glow pulse │
  │                                   │                     │                                        │
  │                                   └─────────────────────┘                                        │
  │                                                                                                  │
  │                                  Budi Santoso                                                    │
  │                                  Triase Perawat                                                  │
  │                                                                                                  │
  │                            📞 Silakan menuju loket admisi                                        │
  │                                                                                                  │
  │                                                                                                  │
  │                                  Ketuk untuk tutup                                               │
  │                                                                                                  │
  │                                                                                                  │
  └──────────────────────────────────────────────────────────────────────────────────────────────────┘

  Animasi 6 detik (atau klik untuk close). Gradient gelap hijau, nomor pulse glow setiap 1.5s.

  ---
  5. PIN modal (klik gear ⚙ di bottom-right)

                ┌─────────────────────────────────┐
                │            🔒  (icon)           │
                │                                 │
                │       Masukkan PIN              │
                │  Akses kontrol layar antrean    │
                │                                 │
                │   [ • ] [ • ] [ • ] [ • ]       │ ← 4 box 56×64, 28px serif
                │                                 │
                │   [ Masuk ]    [ Batal ]        │
                └─────────────────────────────────┘

  Salah PIN → shake animasi 0.5s, box border merah, pesan ⚠ PIN salah. Coba lagi.

  ---
  6. Control panel modal (setelah PIN benar)

  ┌─────────────────────────────────────────────────────────────────────────┐
  │ 📺  Kontrol Layar                                                  ×   │
  ├─────────────────────────────────────────────────────────────────────────┤
  │ [📹 Media] [🖼 Slideshow] [📜 Running Text] [👥 Antrean] [🔒 Keamanan] │
  ├─────────────────────────────────────────────────────────────────────────┤
  │                                                                         │
  │  MODE TAMPILAN PANEL KIRI                                               │
  │  ┌──────────────┬──────────────┬──────────────┬──────────────┐         │
  │  │ ⊕            │ ▶ YouTube    │ 🖼 Slideshow │ ▶ Video Lokal│         │
  │  │ Placeholder  │              │              │              │         │
  │  │ Logo & nama  │ Embed video  │ 3 gambar     │ File terpilih│         │
  │  └──────────────┴──────────────┴──────────────┴──────────────┘         │
  │                                                                         │
  │  PENGATURAN PEMUTARAN                                                   │
  │  ☑ Autoplay (mulai otomatis)                                           │
  │  ☑ Loop (ulangi dari awal)                                             │
  │                                                                         │
  │  YOUTUBE URL                                                            │
  │  [ https://www.youtube.com/watch?v=… ]  [ Terapkan ]                   │
  │  ✓ Video aktif                                                          │
  └─────────────────────────────────────────────────────────────────────────┘

  Tab "Antrean" (TV read-only — station picker)

  ┌─────────────────────────────────────────────────────────────────────────┐
  │  TAMPILKAN ANTREAN STASIUN                                              │
  │  ┌──────────────┬──────────────┬──────────────┬──────────────┐         │
  │  │ ●Loket Admisi│ Triase       │ Refraksionis │ Pemeriks. Dr │         │
  │  │ 5 · 3 menun. │ 2 · 1 menun. │ 2 · 1 menun. │ 1 · 0 menun. │         │
  │  ├──────────────┼──────────────┼──────────────┼──────────────┤         │
  │  │ Penunjang    │ Bedah        │ Kasir        │ Farmasi      │         │
  │  │ 0 · 0 menun. │ 0 · 0 menun. │ 3 · 2 menun. │ 1 · 1 menun. │         │
  │  └──────────────┴──────────────┴──────────────┴──────────────┘         │
  │                                                                         │
  │  SEDANG DIPANGGIL (Loket Admisi)                                        │
  │  A-007 · Budi Santoso                                                   │
  │                                                                         │
  │  MENUNGGU (3)                                                           │
  │  A-008  Siti R.    Loket Admisi                                         │
  │  A-009  Ahmad F.   Loket Admisi                                         │
  │  A-010  Dewi L.    Loket Admisi                                         │
  │                                                                         │
  │  ⓘ TV ini read-only. Panggil pasien dilakukan dari modul Loket Admisi.  │
  └─────────────────────────────────────────────────────────────────────────┘

  Tab "Running Text"

  ┌─────────────────────────────────────────────────────────────────────────┐
  │  PESAN RUNNING TEXT (4)                                                 │
  │  1│ Pendaftaran dibuka pukul 07.00 WIB              ✎ ↑ ↓ 🗑           │
  │  2│ Harap siapkan kartu BPJS, KTP, dan rujukan asli ✎ ↑ ↓ 🗑           │
  │  3│ Layanan Bedah Phaco buka Senin–Sabtu            ✎ ↑ ↓ 🗑           │
  │  4│ Untuk pertanyaan hubungi loket informasi        ✎ ↑ ↓ 🗑           │
  │                                                                         │
  │  TAMBAH PESAN BARU                                                      │
  │  [ Ketik pesan informasi… ]                            [ Tambah ]      │
  └─────────────────────────────────────────────────────────────────────────┘

  Tab "Slideshow"

  ┌─────────────────────────────────────────────────────────────────────────┐
  │  TAMBAH GAMBAR (URL per baris)                                          │
  │  ┌─────────────────────────────────────────────────────────┐           │
  │  │ https://contoh.com/gambar1.jpg                          │           │
  │  │ https://contoh.com/gambar2.jpg                          │           │
  │  └─────────────────────────────────────────────────────────┘           │
  │  [ Tambah ]                                                             │
  │                                                                         │
  │  DURASI PER SLIDE    ━━━━━●─────  5s                                   │
  │                                                                         │
  │  DAFTAR GAMBAR (3)                                                      │
  │  1│ [▭ thumb]  https://contoh.com/g1.jpg            🗑                │
  │  2│ [▭ thumb]  https://contoh.com/g2.jpg            🗑                │
  │  3│ [▭ thumb]  https://contoh.com/g3.jpg            🗑                │
  │                                                                         │
  │  [ Aktifkan Slideshow ]                                                 │
  └─────────────────────────────────────────────────────────────────────────┘

  Tab "Keamanan" (ganti PIN)

  ┌─────────────────────────────────────────────────────────────────────────┐
  │  UBAH PIN KONTROL                                                       │
  │  ┌────────────────────────────┐                                         │
  │  │  PIN BARU (4 digit)         │                                         │
  │  │  [ • • • • ]                │                                         │
  │  │                            │                                         │
  │  │  KONFIRMASI PIN BARU        │                                         │
  │  │  [ • • • • ]                │                                         │
  │  │                            │                                         │
  │  │  [ Simpan PIN ]            │                                         │
  │  │  ✓ PIN berhasil diubah      │                                         │
  │  └────────────────────────────┘                                         │
  └─────────────────────────────────────────────────────────────────────────┘

  ---
  Skema warna & token

  ┌───────────────────────┬─────────────────────────────────────────────────────────┐
  │        Elemen         │                          Warna                          │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Background            │ linear-gradient(180deg, #0a2e22 → #061d15) (dark green) │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Accent / primary      │ #8abf44 (lime-green)                                    │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Text utama            │ #fff                                                    │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Text muted            │ rgba(255,255,255,0.4–0.6)                               │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Status waiting (jam)  │ #fcd34d (amber)                                         │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Status done (centang) │ #8abf44 (hijau)                                         │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Status active dot     │ #8abf44 blink 1.5s                                      │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Error (PIN salah)     │ #f87171                                                 │
  ├───────────────────────┼─────────────────────────────────────────────────────────┤
  │ Now-serving glow      │ rgba(138,191,68,0.4) text-shadow                        │
  └───────────────────────┴─────────────────────────────────────────────────────────┘

  Tipografi: DM Serif Display untuk nomor antrean & judul; DM Sans untuk body.

  ---
  Realtime data flow (per perubahan terbaru)

                  ┌─────────────────────────────────────┐
                  │  Backend (Laravel + Reverb)         │
                  │  ─────────────────────────────────  │
                  │  QueueService::panggil()            │
                  │      └─→ broadcast event:           │
                  │           channel: admisi-queue     │
                  │           channel: triase-queue     │
                  └────────┬────────────────────────────┘
                           │
                           │ WS (Pusher protocol)
                           ▼
                ┌────────────────────────┐
                │ AntreanTVView.vue      │
                │ ─────────────────────  │
                │ handleQueueEvent()     │
                │   • merge ke snapshot  │
                │   • detect → CALLED    │
                │   • triggerFlash()     │
                └────────────────────────┘
                           │
                           ▼
                    [Tampil di TV]

  Fallback polling: GET /api/v1/antrean-tv/snapshot setiap 30 detik
  (jika VITE_REVERB_APP_KEY tidak ter-set atau koneksi gagal).
