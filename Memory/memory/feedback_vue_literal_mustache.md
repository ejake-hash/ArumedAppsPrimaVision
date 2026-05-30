---
name: feedback-vue-literal-mustache
description: "Vue 3.5 compiler fail saat template ada literal `{{...}}` di string interpolation (`{{ '{{key}}' }}`). Symptom: 'Failed to fetch dynamically imported module' di runtime, route blank/tidak bisa diklik. WAJIB pakai HTML entity."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8fcf34a0-3abb-434b-89f2-c252f30ea7a0
---

Jangan tulis literal `{{...}}` di template Vue lewat string interpolation (`{{ '{{key}}' }}`). Vue 3.5 compiler tidak bisa parse nested mustache walaupun di-string-wrap.

**Why:** Compiler treat literal `{{` di dalam string sebagai opening interpolation baru → parse error fatal. Symptom yang misleading:
- Error di browser console: `TypeError: Failed to fetch dynamically imported module: http://localhost:5173/src/views/.../FileName.vue?t=xxx`
- Route tidak bisa diklik / blank screen
- Vite HMR cache stale (refresh biasa kadang tidak fix — butuh hard refresh Ctrl+Shift+R)

Mudah dianggap masalah network/import — padahal compile-time fail di Vue SFC.

**How to apply:**

Saat butuh menampilkan teks literal `{{placeholder}}` (mis. dokumentasi binding di Form Registry, contoh syntax di docs UI), pakai **HTML entity**:

```vue
<!-- ❌ SALAH -->
<code>{{ '{{clinic_logo}}' }}</code>

<!-- ✅ BENAR -->
<code>&#123;&#123;clinic_logo&#125;&#125;</code>
```

`&#123;` = `{`, `&#125;` = `}`. Browser render seperti literal `{{...}}`, Vue compiler tidak bingung.

**Alternative** untuk teks panjang: pakai `v-pre` directive di parent element supaya Vue skip parsing isinya:
```vue
<code v-pre>{{clinic_logo}}</code>
```

**Insiden:** ProfilKlinikView 2026-05-30 saat tambah section "Logo & Kop Surat" — dokumentasikan placeholder `{{clinic_logo}}` di section sub-text. Vue compile fail, seluruh /master-data jadi tidak bisa diklik karena ProfilKlinikView adalah default redirect target route.

**Audit candidate:** view lain yang dokumentasikan syntax binding/placeholder ke user (mis. SOP docs in-app, tooltip di MapperStep, dst). Cari pattern `{{ '...' }}` di template `<template>` block.

Links: [[feedback-vue-underscore-tag-bug]] (Vue 3.5 compiler bug terkait), [[feature-clinic-logo-kop-surat]] (kejadian).
