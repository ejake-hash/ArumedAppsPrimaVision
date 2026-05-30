---
name: feedback-flex-column-scroll-sibling
description: "Anti-pola: nambah section sibling ke .rmc/scroll area di dalam flex column container — flexbox bagi tinggi, scroll area terdesak jadi sliver. Taruh DI DALAM scroll area, bukan sibling."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 769dca4a-fc0d-470f-9507-c2450958d69c
---

Saat parent pakai `display:flex; flex-direction:column; overflow:hidden` dan punya anak `flex:1; overflow-y:auto` (scroll area), **JANGAN tambah section baru sebagai sibling** scroll area itu. Flexbox akan membagi tinggi total → scroll area terdesak jadi sliver kecil walau punya `flex:1`.

**Why:** Kasus nyata 2026-05-30 di [DokterView.vue](arumed-frontend/src/views/DokterView.vue) — `.rme-card` (flex column overflow hidden) punya anak `.rmc` (flex:1 overflow-y:auto, container Tab content). Saya tambah `.rme-form-registry-stack` sebagai sibling `.rmc` → Tab SOAP content (form SOAP + ICD + finalisasi) tampil cuma sliver kuning ~30px, block dokumen RM mendominasi viewport. User: "halaman tab jadi tertutup", "dokumen malah menutupi halaman soap".

**How to apply:**

- Pattern benar: section yang muncul cuma di 1 tab/state → taruh **di dalam `<div v-if="tab === 'X'">` di dalam scroll area** (jadi anak scroll yang ikut scroll, bukan sibling yang ikut bagi tinggi).
- Sinyal anti-pola: parent CSS punya `flex-direction:column` + `overflow:hidden`, anak ada yang `flex:1; overflow-y:auto`. Tambah sibling baru ke parent ini = trap.
- Kalau memang harus sibling permanen (mis. footer fixed), tambah ke parent dengan `flex-shrink:0` + tinggi eksplisit — bukan flex grow.
- Pola yang sering terlibat di Arumed: `.rme-card`, `.qp-card`, `.modal-box`, `.farmasi-shell` semua flex column overflow hidden — selalu cek struktur scroll dulu sebelum sisip section baru.

Lihat juga [[dokterview-module]] section "Form Registry placement — flexbox trap" untuk fix-nya.
