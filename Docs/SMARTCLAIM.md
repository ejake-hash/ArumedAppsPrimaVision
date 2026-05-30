# ARUMED — SMART CLAIM (Klaim Otomatis HIS → BPJS)

> Status: **ROADMAP / PLANNING**. Dibangun di atas fondasi Bridging VClaim
> ([BRIDGING VCLAIM.md](BRIDGING%20VCLAIM.md) + [ARUMED_BRIDGING_BPJS_PLAN.md](ARUMED_BRIDGING_BPJS_PLAN.md)).
> Tujuan: petugas klaim **tidak ketik ulang** data di aplikasi BPJS/E-Klaim — semua
> ditarik dari data yang sudah ada di HIS Arumed, lalu didorong otomatis ke BPJS.
>
> **Prinsip realistis**: smart claim itu BERTAHAP (L1→L4), bukan satu tombol ajaib.
> Penghalang utama = INA-CBG grouper ada di aplikasi **E-Klaim LOKAL** (bukan REST internet).

---

## 0. Peta Sistem — 3 kanal yang harus terisi otomatis

```
                 HIS ARUMED (sumber data tunggal)
   ┌──────────────────────────────────────────────────────────┐
   │ patients · visits(no_sep) · doctor_examinations           │
   │ (diagnosis_utama/sekunder ICD-10, tindakan_codes ICD-9)   │
   │ visit_services · prescriptions · diagnostic_results       │
   │ billing_invoices · medical_resumes · Form Registry        │
   └───────┬───────────────────┬──────────────────────┬────────┘
           │                   │                      │
        ① VClaim            ② E-Klaim (INA-CBG)     ③ Berkas digital
        REST internet        web service LOKAL       (resume, hasil)
        cons_id+HMAC         key AES sendiri          upload/FHIR
           │                   │                      │
   SEP·LPK·SuratKontrol   grouper→kodeCBG+tarif   lampiran verifikasi
           │                   │                      │
           └───────────┬───────┴──────────────────────┘
                       ▼
            BPJS Verifikator → Klaim dibayar
```

Catatan kunci:
- **① VClaim** = sudah kita bangun (auth HMAC + decrypt). Termasuk endpoint jembatan
  `/{svc}/sep/cbg/{noSep}` (ambil data SEP utk INA-CBG) & `/Monitoring/Klaim/...` (status+biaya).
- **② E-Klaim** = aplikasi terpisah, LOKAL, enkripsi & key sendiri. Grouper ada DI SINI.
- **③ Berkas** = pondasi sudah ada (medical_resumes, Form Registry, diagnostic_results).

---

## 1. Empat Level Otomasi (roadmap)

| Level | Output | Kanal | Sumber data Arumed | Kesulitan | Status |
|---|---|---|---|---|---|
| **L1 Auto-SEP** | SEP otomatis saat pasien masuk | ① VClaim | peserta+rujukan+visit | mudah | ⬜ (fondasi jalan) |
| **L2 Auto-LPK** | Lembar Pengajuan Klaim ke VClaim | ① VClaim | doctor_examinations (ICD-10/9) | sedang | ⬜ |
| **L3 Auto-Grouper** | kode CBG + tarif | ② E-Klaim | LPK + SEP | **sulit** (lokal) | ⬜ |
| **L4 Auto-Submit** | klaim final terkirim + tracking | ①+② | L2+L3+berkas | sulit | ⬜ |

> Bahkan berhenti di **L1+L2** sudah memangkas banyak kerja manual. L3/L4 butuh
> aplikasi E-Klaim terinstal + key aktivasi Kemenkes.

---

## 2. L1 — Auto-SEP (detail)

**Trigger**: pasien BPJS selesai didaftar di Admisi.
**Langkah otomatis**:
1. `GET /Peserta/nik|nokartu/...` → validasi peserta (GATE: `statusPeserta.kode="0"` AKTIF).
2. `GET /Rujukan/RS/{noRujukan}` (atau by nokartu) → ambil diagnosa, poli, faskes asal.
3. `POST /SEP/2.0/insert` → terbitkan SEP. Field utama (terverifikasi dari dok):
   - `noKartu` ← peserta · `tglSep` ← today · `ppkPelayanan` ← kdppk
   - `jnsPelayanan="2"` (R.Jalan) · `klsRawat.klsRawatHak` ← `peserta.hakKelas.kode`
   - `rujukan.{asalRujukan,tglRujukan,noRujukan,ppkRujukan}` ← data Rujukan
   - `diagAwal` ← `rujukan.diagnosa.kode` · `poli.tujuan` ← mapping poli mata
   - **`katarak.katarak`** ← 1 jika kasus katarak (RELEVAN klinik mata!)
   - `dpjpLayan` ← kode DPJP (mapping dokter) · `noTelp` ← patient.phone
4. Simpan `response.sep.noSep` → `visits.no_sep`; log `bpjs_vclaim_logs`.
5. `updateOrCreate` `bpjs_claims` (DRAFT) + `bpjs_referrals_in`.

**Output**: pasien punya SEP tanpa petugas buka VClaim.

---

## 3. L2 — Auto-LPK (detail)

**Trigger**: dokter finalize pemeriksaan (atau saat kasir/selesai).
**Langkah otomatis**:
1. Kumpulkan dari `doctor_examinations`:
   - `diagnosa[]`: `diagnosis_utama` (level 1) + `diagnosis_sekunder[]` (level 2)
   - `procedure[]`: `tindakan_codes[]` (ICD-9 CM)
2. `POST /LPK/insert` body `t_lpk` (form-urlencoded):
   `noSep, tglMasuk, tglKeluar, jaminan="1", poli, perawatan{ruangRawat,kelasRawat,
   spesialistik,caraKeluar,kondisiPulang}, diagnosa[], procedure[], rencanaTL{...}`.
3. Log + update `bpjs_claims`.

**Output**: data klinis menempel ke SEP, siap di-grouping.

---

## 4. L3 — Auto-Grouper via E-Klaim (KONEKSI E-KLAIM — detail)

### 4.1 Kenapa beda total dari VClaim
| Aspek | VClaim | **E-Klaim (INA-CBG)** |
|---|---|---|
| Lokasi | internet `apijkn...` | **server LOKAL di klinik** (mis. `http://192.168.x.x:8082/E-Klaim/ws`) |
| Auth | cons_id+secret+HMAC | **key AES sendiri** dari aktivasi (BUKAN cons_id) |
| Enkripsi | response saja (AES+LZString) | **request DAN response** AES-256-CBC (NO LZString) |
| Struktur | `request.t_sep{}` | `{ "metadata": {"method": "..."}, "data": {...} }` lalu SELURUHNYA dienkripsi |
| Grouper | ❌ tidak ada | ✅ **ADA DI SINI** |
| Update | jarang | versi grouper **tiap tahun** |

### 4.2 Cara komunikasi E-Klaim (POST terenkripsi)
```
payload = { "metadata": { "method": "<nama_method>" }, "data": { ... } }
body    = base64( AES-256-CBC-encrypt( json(payload), KEY_EKLAIM, IV_EKLAIM ) )
POST  http://{host-eklaim}/ws    body=body   (header key sesuai panduan E-Klaim)
response = AES-256-CBC-decrypt( base64_decode(resp), KEY_EKLAIM, IV_EKLAIM )  → JSON
```
> KEY_EKLAIM didapat saat **aktivasi aplikasi E-Klaim** di faskes (beda dari kredensial VClaim).

### 4.3 Urutan method E-Klaim (alur grouping)
```
1. new_claim          → buat klaim (kirim nomor_sep, nomor_kartu, tgl_masuk/keluar)
2. set_claim_data     → isi diagnosa(ICD-10)[], procedure(ICD-9)[], tarif RS, dll
3. grouper            → STAGE 1 (hitung kandidat CBG)
4. grouper (stage 2)  → tarif final + special CMG/topup
5. (opsional) reedit  → revisi bila perlu
6. claim_final        → kunci klaim
7. send_claim         → kirim ke BPJS (atau via VClaim pengajuan)
```
Output `grouper` = **kode CBG** (mis. `N-3-15-0`) + **tarif** → simpan `inacbgs_grouping_logs`
(`cbg_code`, `cbg_tarif`, `severity_level`, `grouper_version`, input diagnosa+tindakan).

### 4.4 Jembatan dari VClaim
`GET /{svc}/sep/cbg/{noSep}` (VClaim) → data peserta SEP siap-pakai untuk `set_claim_data`
E-Klaim (`pesertasep`: kelamin, klsRawat, nama, noKartuBpjs, noRujukan, tglLahir, tglPelayanan, tktPelayanan).

### 4.5 Konsekuensi arsitektur Arumed
- **Kelas terpisah** `App\Services\Bpjs\EklaimClient` (auth & enkripsi beda → JANGAN pakai BpjsClient).
- Config E-Klaim baris baru di `integration_configs` (`system_name='EKLAIM'`): base_url lokal + key.
- Karena server lokal: butuh konektivitas LAN HIS↔E-Klaim; bila beda jaringan, tidak jalan.

---

## 5. L4 — Auto-Submit + Tracking

1. Gabungkan: SEP (L1) + LPK (L2) + CBG/tarif (L3) + berkas (③).
2. Finalisasi klaim (`claim_final` + `send_claim`), set `bpjs_claims.status=SUBMITTED`.
3. **Tracking**: `GET /Monitoring/Klaim/Tanggal/{tgl}/JnsPelayanan/2/Status/{1|2|3}` →
   tarik status + `biaya` (byPengajuan/bySetujui/byTarifGruper/byTarifRS/byTopup) +
   `Inacbg.kode`. Update `bpjs_claims` (VERIFIED/SELESAI/DITOLAK).
4. `GET /Monitoring/Kunjungan/...` & `/monitoring/HistoriPelayanan/...` untuk rekonsiliasi.

---

## 6. Pemetaan ke flow Arumed (titik trigger)

| Titik di flow Arumed (existing) | Aksi smart claim | Level |
|---|---|---|
| Admisi — pasien BPJS daftar | cek peserta+rujukan → Insert SEP | L1 |
| Dokter — finalize pemeriksaan | kumpulkan ICD-10/9 → siapkan/POST LPK | L2 |
| Kasir / SELESAI | LPK final + grouping (E-Klaim) + draft klaim | L2/L3 |
| Job harian / tombol manual | Monitoring status klaim → update tracking | L4 |

Pondasi data SUDAH ADA: `bpjs_claims` (DRAFT→...→SELESAI), `inacbgs_grouping_logs`,
`doctor_examinations` (diagnosa+tindakan terstruktur), `medical_resumes`, Form Registry.

---

## 7. SKETSA UI — Menu "Smart Claim"

> Lokasi: sidebar grup BPJS/Klaim → route `/klaim` (permission `bpjs.read/write`).
> Konsisten palet hijau Arumed (`--ga`, `--td`, `--bc`), toast lokal.

### 7.1 Halaman utama — Worklist Klaim (master-detail)
```
┌─ Smart Claim ─────────────────────────────────────────────────────────────┐
│ [ Tab: Worklist | Verifikasi | Tracking | Pengaturan Bridging ]            │
│                                                                            │
│ Filter: [Tgl ▼] [Poli ▼] [Status: Semua▼] [🔍 cari nama/noSEP]   [Refresh] │
│                                                                            │
│ ┌────────────────────────────────────────┬─────────────────────────────┐  │
│ │ DAFTAR KLAIM (kiri)                     │ DETAIL KLAIM (kanan)        │  │
│ │ ● ARSTNUU  · SEP 0301..039              │ Pasien: ARSTNUU (P, Kls 3)  │  │
│ │   H25.9 Katarak · ⬤ DRAFT               │ SEP: 0301R0010323V000039    │  │
│ │ ─────────────────────────────────────── │ Dx: H25.9 + sekunder        │  │
│ │ ○ MUHAMMAD J · SEP 0301..040            │ Tindakan: 13.41 (Phaco)     │  │
│ │   I21.9 · ⬤ GROUPING                    │ ─ Status pipeline ─         │  │
│ │ ○ SUSANTI    · SEP 1828..001            │  ✅ SEP   ✅ LPK            │  │
│ │   C46 · ⬤ SUBMITTED                     │  ⏳ Grouper  ⬜ Submit      │  │
│ │ ...                                      │ ─ Biaya (INA-CBG) ─         │  │
│ │                                          │  CBG: N-3-15-0  DIALYSIS    │  │
│ │ [● DRAFT ●REVIEW ●GROUPING ●SUBMITTED]   │  Tarif RS    : 1.170.689    │  │
│ │  (legend warna status)                   │  Tarif Gruper:   991.200    │  │
│ │                                          │  Disetujui   :         0    │  │
│ │                                          │ ─ Aksi ─                    │  │
│ │                                          │ [Generate SEP] [Kirim LPK]  │  │
│ │                                          │ [Grouping E-Klaim] [Submit] │  │
│ │                                          │ [Lihat Berkas] [Log]        │  │
│ └────────────────────────────────────────┴─────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────────┘
```

### 7.2 Panel Pipeline per klaim (stepper status)
```
 [①SEP]──[②LPK]──[③Grouper]──[④Submit]──[⑤Verif BPJS]
   ✅       ✅        ⏳           ⬜          ⬜
 noSep    diag+     kodeCBG     klaim       status
 terbit   tindakan  +tarif      terkirim    dari Monitoring
 Tiap step: badge hijau(ok)/amber(proses)/abu(belum)/merah(gagal) + tombol retry.
```

### 7.3 Drawer Detail Biaya (dari Monitoring/Klaim)
```
┌ Rincian Biaya Klaim ───────────────┐
│ INA-CBG : N-3-15-0 — DIALYSIS      │
│ Severity: III                      │
│ ─────────────────────────────────  │
│ Tarif RS (byTarifRS)  : 1.170.689  │
│ Tarif Gruper          :   991.200  │
│ Top-up                :         0  │
│ Diajukan (byPengajuan):   991.200  │
│ Disetujui (bySetujui) :         0  │  ← terisi setelah verifikasi
│ ─────────────────────────────────  │
│ Selisih RS vs Gruper  :   179.489  │  ← highlight (potensi rugi)
└────────────────────────────────────┘
```

### 7.4 Tab "Pengaturan Bridging" (gabung dengan menu Bridging BPJS)
```
┌ Koneksi ───────────────────────────────────────────────┐
│ VCLAIM   [base_url ............] consid[..] secret[••••] │
│          user_key[••••] kdppk[....]   [Simpan][Test]🟢   │
│ ANTREAN  [base_url ............] user_key[••••] [Test]🟢 │
│ EKLAIM   [host lokal http://192.168..:8082] key[••••]    │
│          [Simpan][Test]🟡  (server lokal — perlu LAN)    │
│ Status terakhir test + waktu. Toggle Aktif per sistem.  │
└──────────────────────────────────────────────────────────┘
```

### 7.5 Komponen frontend (rencana)
- `views/klaim/SmartClaimView.vue` — worklist master-detail + stepper.
- `views/integrasi/IntegrasiView.vue` — tab Koneksi (VClaim/Antrean/E-Klaim) + Log.
- `stores/klaimStore.js`, `stores/integrasiStore.js`.
- `services/api.js`: `klaimApi`, `integrasiApi`.

---

## 8. Kebutuhan & Prasyarat (checklist)

**Administratif**
- [ ] PKS BPJS + kode faskes (kdppk).
- [ ] Credential VClaim + Antrean (DEV→PROD) dari Trust Mark.
- [ ] **Aplikasi E-Klaim terinstal** di server klinik + **key aktivasi** (untuk L3).
- [ ] Konektivitas LAN HIS ↔ server E-Klaim.

**Mapping data (Arumed)**
- [ ] kdppk → `ppkPelayanan`.
- [ ] kode poli BPJS (mata) → `poli.tujuan`.
- [ ] kode DPJP per dokter → `dpjpLayan` (usul kolom `employees.bpjs_dpjp_code`).
- [ ] referensi: ruangRawat/kelasRawat/caraKeluar/kondisiPulang (LPK).

**Teknis**
- [ ] `BpjsClient` (VClaim/Antrean) — auth HMAC + decrypt.
- [ ] `EklaimClient` (terpisah) — AES request+response, method new_claim..send_claim.
- [ ] `integration_configs` baris EKLAIM (encrypted).

---

## 9. Urutan eksekusi yang disarankan

```
FASE A (fondasi VClaim)   : BpjsClient + UI Bridging + Auto-SEP (L1)        ← prioritas
FASE B (LPK)              : Auto-LPK saat finalize/kasir (L2)
FASE C (E-Klaim)          : EklaimClient + grouping (L3) — saat E-Klaim siap
FASE D (klaim penuh)      : Submit + Monitoring tracking + UI worklist (L4)
```
> FASE A–B bisa jalan tanpa E-Klaim. FASE C–D menyusul; tidak memblokir pelayanan pasien.

---

## 10. Risiko & catatan

- **E-Klaim lokal = titik kegagalan**: bila server E-Klaim mati/beda jaringan, L3 berhenti → sediakan fallback "grouping manual" (petugas buka E-Klaim) tanpa memblokir L1/L2.
- **Akurasi coding dokter** menentukan tarif CBG. Smart claim tidak menebak ICD.
- **Versi grouper tahunan** → `grouper_version` wajib dicatat tiap klaim.
- **Form-urlencoded** untuk endpoint POST VClaim (Rujukan/SEP/LPK) — BpjsClient dukung 2 mode body (JSON + form).
- **Tidak menyentuh** flow non-BPJS; semua aksi BPJS aman saat integrasi nonaktif (503 rapi).

## Log revisi
- 2026-05-29 — draft awal SMARTCLAIM (roadmap L1–L4, koneksi E-Klaim, sketsa UI). Berbasis dok VClaim lengkap di BRIDGING VCLAIM.md (Insert SEP 2.0, sep/cbg, Monitoring Klaim).
