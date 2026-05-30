---
name: feedback-vue-underscore-tag-bug
description: "Vue 3.5 compiler panic 'Codegen node is missing' ketika tag komponen punya prefix underscore (mis. <_CrudResourceView>) dikombinasi dengan named slot kompleks. Workaround: rename variable import (file path boleh tetap)."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: f7fa0de3-9019-4de1-8fc4-e54c7f1a38b8
---

Jangan render komponen Vue dengan tag yang punya prefix underscore — mis. `<_CrudResourceView>` — di template, terutama kalau komponen tersebut menerima named slot dengan binding (`<template #filters="{ updateFilter }">`).

**Why:** Vue 3.5 compiler punya bug `assert "Codegen node is missing for element/if/for node"` (compiler-core.cjs.js:2098) yang trigger fatal di rollup build. Reproducible dengan template seminimum:

```vue
<_CrudResourceView :config="config">
  <template #filters="{ updateFilter }">
    <button @click="updateFilter('a', 1)">A</button>
  </template>
</_CrudResourceView>
```

Sementara `<CrudResourceView>` (tanpa underscore) compile OK dengan template yang sama persis. File ekstensi `.vue` boleh tetap pakai underscore (`_CrudResourceView.vue`) sebagai konvensi internal — yang masalah hanya **tag di template HTML**.

**How to apply:** Saat bikin atau modifikasi komponen reusable:
1. Kalau file pakai underscore prefix (konvensi "private/shared component"), **alias di import**: `import CrudResourceView from './_CrudResourceView.vue'` (variable name TANPA underscore), lalu pakai `<CrudResourceView>` di template.
2. Jangan rename file (path berubah → break import di tempat lain). Cukup rename variable.
3. Convention safe: filename underscore = "private detail", tag template = nama PascalCase tanpa underscore.

Insiden ini ketika ekspansi 5 view Master Data (ObatView, BhpView, IolView, Icd10View, Icd9View) saat tambah slot `#filters` ke `_CrudResourceView`. Build error di vite report file ObatView, tapi root cause sebenarnya di compile tag `<_CrudResourceView>` dengan slot kompleks. Diagnosa: compile per-file standalone pakai `@vue/compiler-sfc` lalu skip ke tag minimum reproduction.
