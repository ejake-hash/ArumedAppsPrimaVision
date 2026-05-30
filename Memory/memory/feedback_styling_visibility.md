---
name: feedback-styling-visibility
description: "Tombol primary pakai var(--pri) sering tidak terlihat di environment user (kemungkinan tema light/putih). Pakai warna hardcoded #1763d4 + text #fff !important untuk tombol penting."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8fcf34a0-3abb-434b-89f2-c252f30ea7a0
---

User report: tombol primary (mis. "+ Buat Baru" di FormTemplateView) tidak kelihatan dengan styling default `background: var(--pri); color: white`.

**Why:** `var(--pri)` kemungkinan resolve ke warna putih/sangat terang di environment user, sehingga tombol invisible (background + text putih). Atau CSS var tidak ter-load di scope tertentu. User juga prefer text serba hitam untuk readability maksimal.

**How to apply:**
- Untuk tombol critical (CTA primary, "+ Baru", "Simpan", dst), HINDARI `var(--pri)` polos. Pakai warna hardcoded `#1763d4` (biru solid) + `color: #fff !important` + `font-weight: 700` + `box-shadow` ringan.
- Untuk text body di view yang user complain visibility, paksa `color: #000` via parent selector + `* { color: #000 }`. Ini override styled var seperti `--td`/`--tm` yang mungkin abu-abu terlalu light.
- Border tombol pakai `#000` (bukan `var(--gb)`) supaya tetap kelihatan tanpa background.
- Chip/badge: background tetap pastel boleh, tapi text hitam (bukan warna match background).

**Contoh fix (FormTemplateView 2026-05-30):**
```css
.ft-wrap, .ft-wrap * { color: #000; }
.ft-btn-primary {
  background: #1763d4;
  color: #fff !important;
  border-color: #1763d4;
  font-weight: 700;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.ft-btn { border: 1px solid #000; color: #000; font-weight: 600; }
```

**Audit candidate:** view lain yang pakai pola sama (`background: var(--pri); color: white`) berpotensi sama tidak kelihatan. Mis. tombol "Simpan" di MapperStep, "Daftar Pasien Baru" di AdmisiView, dst. Tunggu user feedback per-view sebelum mass-rewrite.

Links: [[project-arumed]].
