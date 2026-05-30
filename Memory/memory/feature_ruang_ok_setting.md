---
name: ruang-ok-setting
description: "Daftar Ruang OK disimpan di clinic_profiles.operating_rooms (JSON array), bukan tabel master terpisah. Diedit lewat Profil Klinik, dipakai BedahView untuk radio Ruang OK."
metadata: 
  node_type: memory
  type: project
  originSessionId: 84b7bf5d-8784-4143-ba2c-da6173fa3dbf
---

Ruang Operasi (OK) dinamis lewat **setting global di Profil Klinik**, bukan master tabel terpisah.

**Why:** user memilih pendekatan paling ringan (tanpa migrasi tabel master baru) — di klinik mata jumlah OK biasanya kecil (1–5) dan jarang berubah, jadi cukup disimpan sebagai JSON array di `clinic_profiles`. Tidak butuh CRUD endpoint terpisah dan tidak butuh seeder.

**How to apply:**
- Skema: `clinic_profiles.operating_rooms` JSON nullable. Default seed `["OK 1","OK 2","OK 3"]` di migration 2026_05_28_000010. Model cast `'operating_rooms' => 'array'`.
- Validasi PUT `/master/profil-klinik`: `array|min:1|max:20`, items `string|max:50|distinct`.
- UI edit: section "Ruang Operasi (OK)" di [[feature_master_data_stage1]] ProfilKlinikView (chip + add input + enter-to-add).
- BedahView: `operatingRooms = computed(() => masterStore.profilKlinik?.operating_rooms ?? [])`, di-fetch sekali via `masterStore.fetchProfilKlinik()` di onMounted. Radio Ruang OK render dari array ini. Fallback teks "Belum ada ruang OK — atur di Profil Klinik" kalau array kosong.
- Tidak ada validasi cross-modul kalau ruang yang dipakai di `surgery_schedules.operation_room` dihapus dari setting — string lama tetap tersimpan, tinggal tidak muncul di radio pilihan baru.
