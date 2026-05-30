---
name: feedback-print-a4-basecss-reset
description: Print A4/kwitansi blank kalau @media print tidak reset base.css min-width:1280px + height:100%
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8008a28f-b98a-42d9-9d43-f28dde469e88
---

Print dokumen (A4 kwitansi, struk thermal) muncul BLANK kalau `@media print` tidak mereset base.css.

**Why:** `src/assets/styles/base.css` punya `body { min-width: 1280px }` + `html, body { height: 100% }`. Saat print, kanvas 1280px penuh-tinggi mendorong isi keluar halaman pertama → halaman kosong (hanya header/footer browser).

**How to apply:** di blok `<style>` UNSCOPED, dalam `@media print` WAJIB:
```css
html, body { width:auto !important; min-width:0 !important; height:auto !important; overflow:visible !important; }
#app { display: none !important; }   /* lebih andal dari body > *:not(.print-node) */
```
Node print di-`<Teleport to="body">` (sibling #app), base `display:none` di luar @media print, `display:block !important` di dalam. Pola terbukti di AnjunganView (thermal) & KasirView (A4 kwitansi, fix 2026-05-29). Dulu KasirView cuma pakai `body > *:not(.rincian-print)` tanpa reset → blank. Lihat [[feature-anjungan-kiosk]], [[feature-kasir-view]], [[feature-asuransi-cover-kasir]].
