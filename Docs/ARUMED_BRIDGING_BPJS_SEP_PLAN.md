# Plan Detail — Insert SEP 2.0 (V5) — Bridging BPJS Arumed

> Bagian dari [ARUMED_BRIDGING_BPJS_PLAN.md](ARUMED_BRIDGING_BPJS_PLAN.md).
> SEP = Surat Eligibilitas Peserta — diterbitkan saat pasien BPJS MASUK (di Admisi),
> setelah cek peserta (V1/V2) + cek rujukan (V3/V4) sukses. `noSep` hasilnya disimpan
> di `visits.no_sep` dan jadi kunci untuk klaim & surat kontrol.
>
> **STATUS: DRAFT struktur standar VClaim 2.0.** Kolom "DARI MANA" sudah dipetakan ke
> Arumed. Tinggal dikoreksi dengan REQUEST/RESPONSE asli dari Trust Mark (menu SEP →
> Insert SEP 2.0). Bagian yang masih ?? = tunggu spec asli.

---

## 1. Endpoint (dugaan standar — KONFIRMASI dari Trust Mark)

```
PATH   : /SEP/2.0/insert        ??  (menu "Insert SEP 2.0 New")
METHOD : POST
ENCRYPTED RESPONSE : ya
BODY   : { "request": { "t_sep": { ... } } }
```

## 2. Struktur REQUEST `request.t_sep` + pemetaan sumber Arumed

Legenda kolom **DARI MANA**:
- `RUJUKAN` = hasil V3/V4 (`response.rujukan.*`)
- `PESERTA` = hasil V1/V2 atau `rujukan.peserta.*`
- `VISIT`   = tabel `visits` Arumed
- `PATIENT` = tabel `patients`
- `KONFIG`  = `integration_configs` / `clinic_profiles` (kdppk, dll)
- `INPUT`   = diisi petugas Admisi di UI
- `STATIS`  = nilai tetap / default

| Field t_sep | Contoh | DARI MANA | Catatan |
|---|---|---|---|
| `noKartu` | "0011336526592" | PESERTA `peserta.noKartu` | hasil cek peserta |
| `tglSep` | "2026-05-29" | STATIS `today()` | tgl pelayanan, yyyy-MM-dd |
| `ppkPelayanan` | "{kdppk}" | KONFIG kode faskes Arumed | dari config |
| `jnsPelayanan` | "2" | STATIS | **2 = rawat jalan** (klinik mata RJ) |
| `klsRawat.klsRawatHak` | "1" | PESERTA `peserta.hakKelas.kode` | kelas hak |
| `klsRawat.klsRawatNaik` | "" | INPUT | naik kelas (kosong = tidak) |
| `klsRawat.pembiayaan` | "" | INPUT | isi jika naik kelas |
| `klsRawat.penanggungJawab` | "" | INPUT | isi jika naik kelas |
| `noMR` | "RM-26-0001" | PATIENT `no_rm` | no RM internal Arumed |
| `rujukan.asalRujukan` | "2" | RUJUKAN | 1=FKTP, 2=RS. Dari `rujukan.pelayanan`? KONFIRM |
| `rujukan.tglRujukan` | "2026-05-20" | RUJUKAN `rujukan.tglKunjungan` | |
| `rujukan.noRujukan` | "0304R00..." | RUJUKAN `rujukan.noKunjungan` | |
| `rujukan.ppkRujukan` | "0304R005" | RUJUKAN `rujukan.provPerujuk.kode` | faskes asal |
| `catatan` | "Katarak OD" | INPUT | catatan admisi |
| `diagAwal` | "H25.9" | RUJUKAN `rujukan.diagnosa.kode` | ICD-10 dari rujukan |
| `poli.tujuan` | "MAT" | KONFIG/INPUT (§B3 mapping poli) | kode poli BPJS (mata) |
| `poli.eksekutif` | "0" | VISIT/INPUT | 0=reguler, 1=eksekutif (jadwal EKSEKUTIF Arumed?) |
| `cob.cob` | "0" | VISIT `visit_cob` | 1 jika ada COB |
| `katarak.katarak` | "0" | INPUT | 1 jika prosedur katarak (relevan klinik mata!) |
| `jaminan.lakaLantas` | "0" | STATIS | klinik mata: 0 |
| `jaminan.penjamin.*` | | STATIS/INPUT | terkait laka — default kosong |
| `tujuanKunj` | "0" | STATIS/INPUT | 0=normal |
| `flagProcedure` | "" | INPUT | terkait tujuanKunj≠0 |
| `kdPenunjang` | "" | INPUT | terkait tujuanKunj≠0 |
| `assesmentPel` | "" | INPUT | terkait tujuanKunj≠0 |
| `skdp.noSurat` | "" | RUJUKAN/INPUT | surat kontrol/SKDP (jika kontrol) |
| `skdp.kodeDPJP` | "" | KONFIG (§B3 mapping dokter) | kode DPJP BPJS |
| `dpjpLayan` | "12345" | KONFIG (§B3 mapping dokter) | kode dokter BPJS yg melayani |
| `noTelp` | "08123..." | PATIENT `phone` | |
| `user` | "Admisi Arumed" | VISIT (user login) | nama operator |

> ⚠️ Field di atas = **struktur standar SEP 2.0**. SEP 2.0 menambah beberapa field vs versi lama
> (mis. `tujuanKunj`, `flagProcedure`, `katarak`, `jaminan`). KONFIRM nama & wajib/opsional dari Trust Mark.

## 3. Struktur RESPONSE sukses (dugaan)

```json
{
  "metaData": { "code": "200", "message": "OK" },
  "response": {
    "sep": {
      "noSep": "0304R0010526V000123",
      "tglSep": "2026-05-29",
      "...": "...field tambahan SEP 2.0..."
    }
  }
}
```
Field dipakai Arumed: `response.sep.noSep` → simpan `visits.no_sep`.

## 4. Aksi Arumed setelah SEP sukses

1. `visits.no_sep = response.sep.noSep`.
2. Buat/`updateOrCreate` `bpjs_claims` (DRAFT) dengan `no_sep`, `patient_nik`, `diagnosis_utama`.
3. Update/buat `bpjs_referrals_in` dari data rujukan (no_rujukan, fktp_kode, sisa_kunjungan).
4. Log ke `bpjs_vclaim_logs` action `GENERATE_SEP` (request+response, decrypted).
5. Lanjutkan flow admisi (queue normal).

## 5. Pra-syarat data Arumed (mapping §B3 — DIISI USER)

| Mapping | Status | Catatan |
|---|---|---|
| kdppk (kode faskes BPJS Arumed) | ?? | untuk `ppkPelayanan` |
| Kode poli BPJS (mata) | ?? | untuk `poli.tujuan` (mis. "MAT") |
| Kode dokter BPJS (DPJP) per dokter Arumed | ?? | untuk `dpjpLayan`/`skdp.kodeDPJP`. Simpan di mana? (usul: kolom baru `employees.bpjs_dpjp_code`) |
| jnsPelayanan default | "2" (RJ) | konfirmasi |
| Mapping eksekutif (service_type EKSEKUTIF → poli.eksekutif=1) | ?? | nyambung Jadwal Dokter v2 |

## 6. Endpoint terkait SEP (untuk dilengkapi setelah V5)

| ID | Fungsi | Path dugaan | Status |
|---|---|---|---|
| V6 | Update SEP 2.0 | `/SEP/2.0/update` | ?? |
| V7 | Delete SEP 2.0 | `/SEP/2.0/delete` (DELETE/body) | ?? |
| V8 | Cari SEP by noSEP | `/SEP/{noSep}` | ?? |
| V9 | Cari SEP terakhir by noRujukan | `/SEP/internal/{noRujukan}` ?? | ?? |

## 7. Yang dibutuhkan dari Trust Mark untuk finalkan V5

1. **REQUEST body asli** Insert SEP 2.0 (struktur `t_sep` lengkap + field mana wajib).
2. **RESPONSE sukses asli** (struktur `response.sep`).
3. **RESPONSE gagal** (contoh `metaData.code` selain 200 + message).
4. Path pasti (`/SEP/2.0/insert`).
5. Arti `asalRujukan` (1 vs 2) & sumbernya.

## Log revisi
- 2026-05-29 — draft plan SEP 2.0 (pemetaan field → sumber Arumed; struktur standar, tunggu spec asli).
