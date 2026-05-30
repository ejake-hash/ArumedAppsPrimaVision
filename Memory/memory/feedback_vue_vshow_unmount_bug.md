---
name: feedback-vue-vshow-unmount-bug
description: "Vue 3.5 v-show crash 'Cannot read properties of null (reading style)' saat unmount cascade dalam <Transition>. Fix dengan v-if."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: d653f3e1-0270-418e-815d-cdc50743e42f
---

Jangan pakai `v-show` pada `<div>` yang membungkus komponen anak kompleks (terutama yang punya child elements dengan ref/directive lain), kalau div itu berada di dalam `<Transition>` modal.

**Why:** Vue 3.5 punya bug saat unmount cascade. `v-show` pasang `beforeUnmount` hook yang baca `el.style`. Saat Transition unmount cascade, DOM element bisa ter-detach sebelum hook jalan → `Cannot read properties of null (reading 'style')` → aplikasi freeze karena error loop di promise.

Stacktrace khas:
```
TypeError: Cannot read properties of null (reading 'style')
  at setDisplay (chunk-XXXX.js)
  at beforeUnmount
  at invokeDirectiveHook
  at unmount
  at unmountChildren  ← cascade dari <Transition>
```

Insiden 2026-05-26: AdmisiView wizard pendaftaran. Modal `<Transition name="modal-fade">` membungkus 3 step `<div v-show="wizardStep === N">`. Step 1 berisi `<WilayahPicker>` (komponen anak dengan 3 select cascading). Saat tutup modal → freeze.

**How to apply:** Untuk wizard / tab dalam modal `<Transition>`, gunakan `v-if` bukan `v-show`. Trade-off acceptable karena form state biasanya di-lift ke parent `reactive()` jadi tidak hilang. Re-mount komponen anak (re-fetch dari API) terjadi tiap navigasi step, tapi user jarang bolak-balik.

Aturan praktis: `v-show` aman untuk element sederhana (div + text/input tanpa nested component). `v-if` lebih aman untuk container yang berisi komponen anak, terutama di dalam `<Transition>`.

Related: [[feature-admisi-view]]
