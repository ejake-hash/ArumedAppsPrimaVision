---
name: feedback-bertahap-konfirmasi
description: "User Arumed prefer kerja bertahap per poin plan, tunggu konfirmasi sebelum lanjut. Jangan auto-eksekusi semua poin sekaligus walau plan sudah approved."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: ec6fc0c4-c948-49e3-a047-f7cd65331c6c
---

User di project Arumed Apps prefer kerja **bertahap per poin plan**, bukan eksekusi semua sekaligus walau plan sudah di-`ExitPlanMode`. Setelah selesai 1 atau beberapa poin, **berhenti dan tanya konfirmasi** untuk lanjut ke poin berikutnya.

**Why:** User mengatakan eksplisit di tengah sesi 2026-05-26: *"buatakan bertahap, backend dulu ya kalau sudah tanya saya kembali untuk lanjut"*. Kemudian setiap kali saya selesai grouping poin, user reply *"lanjut ke poin X"* atau *"lanjut ke poin Y"* — pola berulang sampai akhir. Memberi user kontrol untuk:
- Review hasil sebelum saya tambah scope (mungkin ada koreksi sebelum lanjut)
- Test backend sebelum frontend (kalau backend masih buggy, frontend percuma)
- Skip/reorder poin tanpa harus rollback kerja yang sudah jalan

**How to apply:**
- Saat user setuju plan multi-poin, **kelompokkan tasks per poin** atau per layer (mis. "backend dulu" = poin 1-3, "frontend foundation" = poin 4, dst.) — jangan ambil semua 8 poin sekaligus.
- Setelah selesai 1 grup, **berhenti dan tanya** "Lanjut ke poin X?" — tunggu user reply. Tidak perlu pasang ScheduleWakeup atau loop, ini hands-on collaboration.
- Format laporan per grup: ringkas (✅ checklist + bullet apa yang dibuat + verify hasil), bukan paragraf panjang. User butuh decision point, bukan reading material.
- Kalau user reply "lanjut" tanpa nomor, asumsikan poin berikutnya di urutan plan. Kalau ada ambiguitas (mis. user reply "lanjut ke poin 4-6 sekaligus"), follow itu.
- Verify build/test setelah tiap grup (Vite build, tinker smoke test) sebelum lapor selesai — supaya user tidak perlu test sendiri sebelum approve lanjut.
