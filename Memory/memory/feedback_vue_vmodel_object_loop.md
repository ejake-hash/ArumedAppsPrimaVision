---
name: feedback-vue-vmodel-object-loop
description: "Gotcha MasterFormModal: (1) v-model objek + 2 deep watcher spread {...v} → 'Maximum recursive updates exceeded', fix guard bandingkan nilai; (2) field wajib `*` dibuat merah+legenda; (3) modal anak (KFA) harus z-index > 9000 biar tak di belakang."
metadata:
  node_type: memory
  type: feedback
  originSessionId: mfm-recursive-2026-05-30
---

**Bug:** "Maximum recursive updates exceeded in component <_CrudResourceView>" saat isi/save form master. Akar: `MasterFormModal.vue` pola two-way `v-model` objek:
- Parent `v-model="modal.payload"` → tiap emit reassign `modal.payload` jadi objek BARU.
- Child `form = ref({...props.modelValue})` + 2 watcher deep: `external→local` (`form.value={...v}`) & `local→external` (`emit('update:modelValue',{...v})`).
- Karena tiap sisi selalu spread `{...}` (referensi baru), deep-watcher tak pernah "settle" → ping-pong rekursif sampai limit Vue. Dipicu lebih parah oleh reassign saat submit (`modal.errors`/`submitting`) + `setModalField` (KFA pick).

**Fix (2026-05-30):** guard KEDUA watcher dgn perbandingan nilai — `if (JSON.stringify(v) !== JSON.stringify(form.value)) form.value={...v}` dan `if (JSON.stringify(v) !== JSON.stringify(props.modelValue)) emit(...)`. Begitu kedua sisi structurally equal → watcher no-op → loop berhenti. Aman karena payload master = objek flat skalar (no fn/circular). **Why:** memutus echo: emit tak lagi memicu watcher balik kalau nilai sudah sama. Verified build hijau.

**How to apply:** SETIAP komponen dgn `v-model` objek + internal `ref` copy + watcher dua-arah WAJIB guard anti-echo (bandingkan nilai, jangan andalkan referensi). Atau lebih baik: `defineModel()` (Vue 3.4+) / computed get-set, hindari internal copy + dual watcher. Pola berisiko ini ada di `MasterFormModal` (dipakai SEMUA form master via [[feature-master-data-stage1]]). Terkait gotcha Vue lain: [[feedback-vue-vshow-unmount-bug]], [[feedback-vue-literal-mustache]], [[feedback-vue-underscore-tag-bug]].

**2 fix lain di MasterFormModal sesi sama (2026-05-30):**
1. **Field wajib `*`**: `*` sudah dirender (`v-if="f.required"`) tapi samar (`var(--et)`). Dibuat **merah tebal** `#dc2626 font-weight:700` + tambah legenda "* wajib diisi" di footer modal. Berlaku SEMUA form master. Field wajib akurat = yg `required:true` di config view (cocok dgn rule backend); Obat: name+formularium; BHP/Alat Medis: name; IOL: brand/model/iol_type/power (+cylinder/axis jika TORIC); ICD: code+description.
2. **Modal KFA muncul di BELAKANG form** (ObatView "Cari KFA"): modal anak dibuka DARI ATAS MasterFormModal tapi z-index lebih rendah (1200 < 9000). **Fix**: `.kfa-overlay z-index:9500`. **Gotcha umum**: MasterFormModal pakai `Teleport to=body` + `z-index:9000`; modal apa pun yg dibuka DI ATASNYA (via `field-action`) harus z-index > 9000.
