---
name: feedback_form_registry_sisip_murah_mahal
description: Menyisipkan Form Registry di tombol UI biasanya MURAH; mahal hanya jika TTD/isi sebelum Visit ada. Jelaskan pemicu biaya di awal.
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 9ecb388d-02f1-4ad7-a9f4-57051acb536d
---

Saat user minta "sisipkan form X di tombol Y", default-nya itu pekerjaan **KECIL** (~30-50 baris): reuse `FormDocsBrowser` (parametrize `station`, saat ini hardcode `'dokter'` di baris ~50) + tag template ke `(station, section)` via `station_assignments`. Auto-fill jalan otomatis lewat binding `db`/`aggregate`. Mayoritas form (Perawat asesmen, Dokter resume/surat, Bedah laporan, GC pasien LAMA) masuk kategori ini karena `visit_id` sudah ada.

Pekerjaan jadi **BESAR** HANYA jika ada syarat "isi/TTD form SEBELUM pasien punya Visit/patient_id" — contoh: General Consent pasien BARU sebelum cetak antrean (lihat [[feature_general_consent_admisi]]). Itu melawan asumsi inti Form Registry ("Visit selalu ada"), memaksa bikin jalur render+TTD baru dari nol (`renderForPreview`, endpoint preview, capture tanpa docId). ~7 file vs ~1-2 file.

**Why:** User bertanya kenapa effort 1 fitur (GC) besar sekali padahal "jenis dokumen & template sudah ada tinggal taruh". User BENAR — yang mahal bukan Form Registry-nya, tapi syarat bisnis "sebelum Visit ada" yang aku terima apa adanya tanpa menandai biayanya. User puas dengan penjelasan; tidak minta perubahan kode (GC tetap dipertahankan).

**How to apply:** Saat user minta taruh form di stasiun mana pun, anggap MURAH secara default. Sebelum mulai, cek: apakah `visit_id`/`patient_id` SUDAH ada di titik form itu dipakai? Kalau ya → jalur ringan FormDocsBrowser. Kalau user menambahkan syarat "sebelum konfirmasi/registrasi/Visit lahir" → SEBUTKAN DULU bahwa itu pemicu biaya besar dan tawarkan alternatif "isi setelah pasien terdaftar" yang jauh lebih murah, biar user pegang kendali trade-off. Jangan diam-diam membangun jalur mahal. Selaras [[feedback_bertahap_konfirmasi]].
