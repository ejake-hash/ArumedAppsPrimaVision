---
name: feature-ttd-dokumen-view
description: "TtdDokumenView — antrian TTD dokter, rencana revamp split-view + multi-TTD + keputusan UX"
metadata: 
  node_type: memory
  type: project
  originSessionId: 3362949d-2b12-4842-9175-e1d956eab5fe
---

Halaman antrian tanda tangan dokumen dokter: `arumed-frontend/src/views/TtdDokumenView.vue`. Bagian dari Form Registry — lihat [[feature-form-registry]].

**API (formTemplateApi di services/api.js):** `ttdQueue()` GET /rekam-medis/ttd-queue → grouped by patient; `snapshot(docId)` (rendered_html, fallback `renderForm(code,visitId)`→html bila kosong); `sign(docId,payload)` per-dokumen (append-only, auto-advance status). Backend: `SignatureService::ttdQueueForDoctor` (filter dokumen status PENDING_SIGNATURE/RENDERED/DRAFT yang punya field signature_canvas signer_type='doctor' & belum di-TTD user ini). Payload pasien: id/no_rm/name/gender (TANPA photo_url di payload ini, walau Patient model auto-append photo_url di tempat lain). Dokumen: id/template_code/status/created_at/visit_id/visit_date/signature_count (TANPA template_name).

**Rencana revamp UI/UX (BELUM dieksekusi, plan disetujui konsep, eksekusi tertunda — user minta simpan memory dulu 2026-05-29). Keputusan user:**
- Layout **split-view 2 kolom**: kiri daftar (grup per pasien, kartu dokumen + checkbox), kanan **preview persisten** (v-html rendered_html) — buang alur 2-modal-bertumpuk lama (preview modal → capture modal).
- **Multi-TTD = capture ULANG tiap dokumen** (BUKAN satu capture untuk semua). Checkbox + "Tanda Tangani Semua (N)" → modal capture berurutan; auto-lanjut ke dokumen berikutnya setelah sign sukses; pakai `:key="signIdx"` agar SignatureCaptureModal remount tiap dokumen.
- **Tombol "Lewati dokumen ini" DI DALAM** SignatureCaptureModal → perlu tambah prop `progress {index,total}` + event `@skip` ke komponen itu (backward-compatible, single TTD tak berubah).
- **Warna primer = hijau brand `var(--ga)` #1f7d4a** (teks #fff). Buang `var(--pri)` (tak terdefinisi di tokens.css → pucat). Lihat [[feedback-styling-visibility]].
- **template_name PERLU** (tweak kecil backend `ttdQueueForDoctor`: tambah 1 field dari document_templates.name, reuse $tpl yg sudah di-resolve di filter, no migration). **photo_url TIDAK perlu** — avatar tetap inisial (PatientAvatar tanpa src).
- Ganti `alert()` → toast pola PerawatView (toast(type,msg) auto-dismiss 3.2s). statusLabel ramah: PENDING_SIGNATURE→"Menunggu TTD", RENDERED→"Siap TTD", DRAFT→"Draf". Button-press tier-3.

Plan file: `C:\Users\Lenovo\.claude\plans\uinya-belum-bagus-bagaimana-memoized-blossom.md`.
