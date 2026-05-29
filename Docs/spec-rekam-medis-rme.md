# Spesifikasi Modul Rekam Medis Elektronik (RME)

> Status: **TERIMPLEMENTASI 2026-05-29** (build hijau, aggregator smoke-test OK).
> Tanggal: 2026-05-29 · Halaman: `arumed-frontend/src/views/RekamMedisView.vue`
> Acuan regulasi: **PMK No. 24 Tahun 2022 tentang Rekam Medis**.

## STATUS IMPLEMENTASI
- Backend: `app/Services/RmeAggregatorService.php` (7 method: ringkasan/kunjungan/refraksi/penunjang/obat/bedah/diagnosis) + `RekamMedisController` (8 endpoint baru `/pasien/{id}/{menu}`) + routes. `indexKunjungan` sekarang pakai aggregator (bukan paginator lama).
- Frontend: RekamMedisView ditulis ulang → master-detail (menu kiri 8 item + tabel kanan 1 baris/kunjungan + expand inline). Addendum per-dokumen (modal → `document/{id}/addendum`), audit drawer (`document/{id}/audit-log`), cetak resume A4.
- Obat: sumber `prescription_items` (diresepkan). Addendum: per-dokumen (Form Registry).
- Build: `npm run build` hijau. Aggregator diuji via tinker terhadap DB nyata, semua method OK.

---

## 0. Ringkasan keputusan (sudah disepakati user)

| Topik | Keputusan |
|---|---|
| Tujuan halaman | **Semua**: dokter saat konsultasi + audit/legal/klaim + cetak salinan pasien + arsip |
| Layout | **Master-detail**: kolom kiri = menu jenis aktivitas; area kanan = data |
| Bentuk data kanan | **Tabel, 1 baris = 1 kunjungan** (kecuali Ringkasan = kartu) |
| Detail baris | **Klik baris → expand inline** di bawahnya |
| Sumber "Obat" | **Diresepkan** (`prescription_items`), bukan yang ditagih |
| Data mata | Dipisah: **Refraksionis** (visus/refraksi/TIO) & **Penunjang** (biometri dll) |
| Addendum/koreksi | **Per-dokumen** via Form Registry yang sudah ada (`document/{id}/addendum`), bukan per-kunjungan |
| Prinsip RME | Pusat seluruh aktivitas kunjungan: riwayat obat, bedah, tindakan, dll. diagregasi dari semua kunjungan pasien |

---

## 1. Masalah saat ini (yang harus diperbaiki)

Halaman frontend memanggil endpoint yang **tidak ada** atau data yang **tidak dikirim**:

| Frontend memanggil | Backend | Masalah |
|---|---|---|
| `GET /rekam-medis/pasien/{id}/kunjungan` | ada (`indexKunjungan`) | Data terlalu tipis — hanya `Visit + insurer + billingInvoice`. Field yang dirender kartu (`classification`, `nurse_assessment`, `doctor_examination`, `diagnostic_results`, `prescriptions`, `addenda`) **tidak dikirim** → kartu kosong semua. |
| `GET /rekam-medis/pasien/{id}/dokumen` | **tidak ada** | 404 |
| `POST /rekam-medis/kunjungan/{id}/addendum` | **tidak ada** | 404. Yang ada `POST /rekam-medis/document/{id}/addendum` (Form Registry). |

Catatan: method `RekamMedisService::getVisitHistory()` & `buildClinicalData()` **sudah ada** dan tahu cara menarik data klinis, tapi tidak dipakai oleh endpoint yang dipanggil view. `riwayatPasien` (`GET /rekam-medis/pasien/{id}`) lebih kaya tapi juga belum dipakai frontend.

---

## 2. Peta data — "seluruh aktivitas kunjungan" → tabel sumber

Setiap aktivitas menggantung ke `visit_id`. Riwayat lintas waktu = agregasi semua `visits` milik pasien.

| Aktivitas | Tabel sumber | Kolom kunci |
|---|---|---|
| Pendaftaran/penjamin | `visits` | classification, guarantor_type, current_station, visit_date, no_sep, planning_follow_up, follow_up_date |
| Asesmen perawat + TTV | `nurse_assessments` | td_sistol/diastol, nadi, suhu, spo2, respirasi, kgd, chief_complaint, pain, rps, has_allergy/allergy_detail |
| CPPT | `nurse_cppt_entries` | (1:N per visit, append + soft-edit) |
| **Refraksi/visus** | `refraction_records` | visus_awal/akhir/pinhole OD-OS, autoref sph/cyl/axis, refraksi_subjektif, keratometri K1/K2/axis, old_glasses + add, iop_od/os + iop_method, pd_distance, add_power |
| **Resep kacamata** | `refraction_prescriptions` | rx_od/os sph/cyl/axis/add, glasses_type, lens_material, coating |
| Pemeriksaan mata dokter | `doctor_examinations` | sa_* (kornea/coa/iris/pupil/lensa OD-OS), sp_* (papil/macula/retina/vitreous OD-OS), slitlamp_notes, anamnese |
| SOAP + diagnosis + tindakan | `doctor_examinations` | soap_s/o/a/p, diagnosis_utama (ICD-10), diagnosis_sekunder (jsonb), tindakan_codes (jsonb ICD-9), planning |
| **Tindakan dilakukan** | `visit_services` | procedure_id, performed_by_id, quantity, price |
| **Resep obat** | `prescriptions` + `prescription_items` | medication_id, quantity, dosage, instructions, notes |
| Obat diserahkan/ditagih | `billing_items` (item_type OBAT/BHP/IOL) | (alternatif — TIDAK dipakai sbg sumber utama) |
| **Penunjang + biometri** | `diagnostic_orders` + `diagnostic_results` | test_type, expertise_data (jsonb), attachment_path, result_status, performed_by_id |
| **Operasi/bedah** | `surgery_records` | time_in/out, operation_notes, has_complication, complication_detail, post_op_instructions, followup_date |
| IOL & BHP terpakai bedah | `surgery_iol_usage`, `surgery_request_bhp` | (detail bedah) |
| Resume medis | `medical_resumes` | resume_s/o/a/p, penunjang_results (jsonb), is_finalized |
| Tagihan | `billing_invoices` + `billing_items` | status, total, discount |
| Dokumen + TTD + consent | `patient_documents` + Form Registry + `document_signatures` | status, document_number, printed_count, signatures |
| Jejak audit | `system_logs` + `document_audit_log` | action, user, timestamp |

**Dua makna "riwayat obat"**: `prescription_items` = diresepkan dokter (DIPAKAI). `billing_items(OBAT)` = diserahkan farmasi (tidak dipakai untuk RME, ada di modul kasir).

**Catatan bedah**: `surgery_schedules` TIDAK punya kolom pasien — relasi via `visits.surgery_schedule_id`. Riwayat bedah ditarik dari `visits` yang punya `surgery_record`.

---

## 3. Layout halaman

```
┌─ SEARCH BAR (nama / No.RM / NIK) ─────────────────────────────────────────┐  [SUDAH ADA]
├─ HEADER PASIEN: avatar · nama · usia · gender · alamat · penjamin ·       │  [SUDAH ADA]
│                 ⚠ ALERGI (menonjol) · copy No.RM · [Cetak Resume]          │
├──────────────┬─────────────────────────────────────────────────────────────┤
│  MENU KIRI   │  AREA KANAN — tabel 1 baris/kunjungan (atau kartu Ringkasan) │
│  (nav)       │                                                             │
│ ● Ringkasan  │   [judul section]            [filter rentang ▾] [Cetak]      │
│ ○ Kunjungan  │   ┌──────────────────────────────────────────────────────┐  │
│ ○ Refraksi   │   │ tabel: header kolom sesuai menu terpilih             │  │
│ ○ Penunjang  │   │ baris = kunjungan (desc by tanggal)                  │  │
│ ○ Obat       │   │ klik baris → expand inline detail                    │  │
│ ○ Bedah      │   └──────────────────────────────────────────────────────┘  │
│ ○ Diagnosis  │                                                             │
│ ○ Dokumen    │                                                             │
└──────────────┴─────────────────────────────────────────────────────────────┘
```

- Kolom kiri: lebar tetap (~180–200px), item aktif ter-highlight (pakai pola visibility user: `#1763d4`, teks `#000`/`#fff`).
- Area kanan: tabel (pola `MasterTable`), header sticky, scroll-y; baris expandable.
- Responsif: di layar sempit menu kiri jadi bar horizontal (fallback) — opsional fase lanjut.

---

## 4. Definisi tiap menu (kolom tabel + expand)

### 4.1 Ringkasan (default) — KARTU, bukan tabel
Tujuan: 1 layar tahu kondisi pasien (untuk dokter).
- **Problem list**: daftar diagnosis ICD-10 unik lintas kunjungan (dedup by kode), tanggal terakhir muncul.
- **Alergi & catatan penting**: `patients.allergy_notes` + `nurse_assessments.allergy_detail` terbaru.
- **Visus & TIO terakhir** (OD/OS) dari refraksi terakhir + indikator naik/turun vs sebelumnya.
- **Kunjungan terakhir**: tanggal, dokter, klasifikasi, planning/follow-up.
- **Hitungan**: total kunjungan, total tindakan bedah, jumlah dokumen final.

### 4.2 Kunjungan — tabel
| Kolom | Sumber |
|---|---|
| Tanggal (+jam) | `visits.visit_date` |
| Klasifikasi | `visits.classification` (badge warna) |
| Dokter · Poli | join doctor/poli |
| Diagnosis utama | `doctor_examinations.diagnosis_utama` + nama |
| Stasiun akhir | `visits.current_station` |
| Penjamin | `visits.guarantor_type` (badge) |
| Badge | finalized / SEP / N penunjang / N dokumen |

**Expand**: keluhan, TTV ringkas, SOAP (S/O/A/P), planning, follow-up.

### 4.3 Refraksi — tabel (OD/OS)
| Kolom | Sumber |
|---|---|
| Tanggal | `refraction_records.examination_date` |
| Visus OD / OS | `visus_akhir_od` / `_os` (awal/pinhole di expand) |
| Rx OD (S/C×A Add) | `refraction_prescriptions.rx_od_*` |
| Rx OS (S/C×A Add) | `refraction_prescriptions.rx_os_*` |
| TIO OD / OS | `iop_od` / `iop_os` (+ metode di expand) |
| PD | `pd_distance` |
| Pemeriksa | `examined_by_id` |

**Expand**: autoref OD/OS, refraksi subjektif, keratometri K1/K2/axis, kacamata lama + ADD, tipe lensa/material/coating, clinical_notes.

### 4.4 Penunjang — tabel
| Kolom | Sumber |
|---|---|
| Tanggal | `diagnostic_orders` / `uploaded_at` |
| Jenis | `diagnostic_orders.test_type` (incl. Biometri/BIOM) |
| Ringkas hasil | ekstrak dari `expertise_data` (jsonb) |
| Lampiran | `attachment_path` (link) |
| Status | `result_status` (badge) |
| Pemeriksa | `performed_by_id` |

**Expand**: seluruh `expertise_data` (mis. biometri OD/OS lengkap), notes, reviewer.

### 4.5 Obat — tabel (sumber: diresepkan)
| Kolom | Sumber |
|---|---|
| Tanggal | `prescriptions.created_at` / visit_date |
| Obat | `prescription_items.medication.nama` |
| Qty | `quantity` |
| Dosis | `dosage` |
| Aturan pakai | `instructions` |
| Catatan | `notes` |

Catatan: 1 kunjungan bisa banyak item → tampilkan 1 baris per kunjungan dengan ringkasan (mis. "3 obat"), **expand** menampilkan seluruh item; ATAU 1 baris per item dengan kolom tanggal berulang. **Rekomendasi**: 1 baris/kunjungan + expand daftar item (konsisten dgn keputusan "1 baris/kunjungan").

### 4.6 Bedah — tabel
| Kolom | Sumber |
|---|---|
| Tanggal | `visits.visit_date` (visit yg punya surgery_record) |
| Prosedur | `doctor_examinations.tindakan_codes` / surgery_package |
| Mata | (dari catatan/laporan operasi) |
| Jam in/out | `surgery_records.time_in/out` |
| Komplikasi | `has_complication` (badge) |
| IOL terpakai | `surgery_iol_usage` |

**Expand**: operation_notes, complication_detail, post_op_instructions, followup_date, BHP terpakai (`surgery_request_bhp.used_qty`).

### 4.7 Diagnosis — tabel
| Kolom | Sumber |
|---|---|
| Tanggal | `visits.visit_date` |
| ICD-10 utama | `diagnosis_utama` + nama |
| ICD-10 sekunder | `diagnosis_sekunder` (jsonb) |
| ICD-9 tindakan | `tindakan_codes` (jsonb) |
| Planning | `planning` |

### 4.8 Dokumen — tabel (UI sudah ada, tinggal pindah ke pola tabel)
| Kolom | Sumber |
|---|---|
| Tanggal | `patient_documents.created_at` / visit_date |
| Kode · Nama | `document_type.code/name` |
| No. dokumen | `document_number` |
| Stasiun | `created_by_station` |
| Status TTD | `status` (badge: Final/Menunggu TTD/Draft/Ditolak/Void) |
| Aksi | Lihat · Print (FINAL) · Addendum (FINAL, via Form Registry) |

**Addendum**: gunakan `POST /rekam-medis/document/{id}/addendum` (alasan + isi_koreksi, perlu TTD lanjutan). Hapus UI addendum per-kunjungan yang lama.

---

## 5. Endpoint backend yang dibutuhkan

### 5.1 Perbaiki / perkaya (WAJIB agar halaman hidup)
1. **`GET /rekam-medis/pasien/{id}/kunjungan`** — perkaya payload: sertakan `nurse_assessment`, `doctor_examination` (termasuk seg. anterior/posterior), `refraction_record.prescription`, `prescriptions.items.medication`, `diagnostic_orders.result`, `surgery_record`, `patient_documents`, `addenda(dokumen)`. (Bisa kembangkan dari `getVisitHistory()` yang sudah ada.)
2. **`GET /rekam-medis/pasien/{id}/dokumen`** — bungkus `indexDokumen(['patient_id'=>$id])` yang sudah ada. (Sepele.)

### 5.2 Endpoint agregat per jenis (untuk menu kiri)
Opsi A — satu endpoint "bundle" yang kembalikan semua section sekaligus:
- `GET /rekam-medis/pasien/{id}/rme` → `{ ringkasan, kunjungan[], refraksi[], penunjang[], obat[], bedah[], diagnosis[], dokumen[] }`

Opsi B — endpoint per section (lazy-load saat menu diklik):
- `GET /rekam-medis/pasien/{id}/refraksi`
- `GET /rekam-medis/pasien/{id}/penunjang`
- `GET /rekam-medis/pasien/{id}/obat`
- `GET /rekam-medis/pasien/{id}/bedah`
- `GET /rekam-medis/pasien/{id}/diagnosis`
- (Ringkasan, Kunjungan, Dokumen sudah ada/diperbaiki di 5.1)

**Rekomendasi**: **Opsi B** (lazy per menu) — payload kecil, sesuai pola lazy-load tab Dokumen yang sudah dipakai view; Ringkasan boleh tetap 1 ringkasan ringan.

### 5.3 Addendum
Tidak buat endpoint baru. Pakai `POST /rekam-medis/document/{id}/addendum` (sudah ada). Frontend addendum dipindah ke level dokumen (menu Dokumen).

---

## 6. Kepatuhan PMK 24/2022 (checklist isi minimal RME)

| Wajib (Pasal 26) | Tercakup di menu |
|---|---|
| Identitas pasien | Header |
| Tanggal & waktu | Semua tabel (kolom tanggal) |
| Anamnesis/keluhan | Kunjungan (expand) |
| Pemeriksaan fisik & penunjang | Kunjungan + Penunjang + Refraksi |
| Diagnosis | Diagnosis + Kunjungan |
| Rencana/penatalaksanaan/tindakan | Diagnosis (planning) + Obat + Bedah + Tindakan |
| Pelayanan lain | Bedah, Penunjang |
| Nama & TTD nakes | Dokumen (status TTD + signatures) |
| Persetujuan tindakan (informed consent) | Dokumen (sebagai document_type) |
| **Addendum (koreksi, bukan hapus)** | Dokumen (Form Registry) |
| **Audit trail** | (fase lanjut) tampilkan `system_logs`/`document_audit_log` |
| **RBAC akses** | mengikuti permission modul (sudah ada) |

---

## 7. Rencana implementasi bertahap (usulan)

> Dikerjakan per langkah, tunggu konfirmasi user tiap selesai 1 langkah (gaya kerja user).

- **Langkah 1 — Sambungkan yang rusak (backend):**
  perkaya `GET .../kunjungan`; tambah `GET .../dokumen`. → halaman lama langsung hidup.
- **Langkah 2 — Frontend pola master-detail:**
  ubah tab horizontal jadi menu kiri + area kanan; menu Kunjungan & Dokumen pakai data langkah 1.
- **Langkah 3 — Refraksi & Penunjang:**
  endpoint + tabel (paling bernilai untuk klinik mata).
- **Langkah 4 — Obat, Bedah, Diagnosis:**
  endpoint agregat + tabel + expand.
- **Langkah 5 — Ringkasan:**
  problem list, visus/TIO terakhir, alergi, dll.
- **Langkah 6 — Addendum per-dokumen** (Form Registry) + audit trail tampil.
- **Langkah 7 — Cetak resume** rapi (reset base.css print, lihat memori print A4).

---

## 8. Catatan teknis (dari memori proyek)

- Envelope API: `{ success, data, message, errors }`.
- Print A4: WAJIB reset `html/body` min-width & `#app{display:none}` di `@media print` (lihat feedback print A4 blank).
- Styling visibility: tombol primary `#1763d4` + teks `#fff !important`; paksa teks `#000`.
- Vue 3.5 gotcha: hindari `<_Component>` underscore & literal `{{ '{{}}' }}` di template; v-if (bukan v-show) untuk tab dalam Transition.
- `surgery_schedules` tanpa kolom pasien → join via `visits.surgery_schedule_id`.
- Foto pasien: pastikan `photo_url` ikut di formatter (jangan sampai PatientAvatar fallback inisial).
