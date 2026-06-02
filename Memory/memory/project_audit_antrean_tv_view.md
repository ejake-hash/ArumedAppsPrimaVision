---
name: project-audit-antrean-tv-view
description: "Audit bug AntreanTVView.vue — 4 bug TERVERIFIKASI masih ada di kode (CRIT localVideoObjectUrl ReferenceError, slideIndex tak di-clamp, PIN/ticker reset tengah-malam, AudioContext tak di-close). Nested-button & flash-dobel sudah aman. BELUM difix."
metadata:
  node_type: memory
  type: project
---

# Audit Bug AntreanTVView.vue (re-verifikasi 2026-06-01)

File `arumed-frontend/src/views/AntreanTVView.vue` (~3390 baris). Lihat juga [[feature-antrean-tv]]. File memory audit lama pernah HILANG dari disk; ini ditulis ulang dari pembacaan kode langsung. **Semua bug di bawah BELUM difix** (masih ada di kode per 2026-06-01).

## 🔴 4 BUG TERVERIFIKASI MASIH ADA
1. **CRITICAL — `localVideoObjectUrl is not defined`** (`onUnmounted` ~baris 510): `if (localVideoObjectUrl) URL.revokeObjectURL(localVideoObjectUrl)` — variabel ini TIDAK PERNAH dideklarasikan (yang ada `localVideoUrl` ref). Saat komponen unmount → **ReferenceError**, cleanup gagal. Fix: HAPUS baris itu (object URL tak pernah dibuat; video pakai URL backend bukan blob).
2. **MEDIUM — `slideIndex` tak di-clamp di `applyMediaPayload`** (~baris 614): `slides.value = s.slides` di-replace via WS tanpa clamp `slideIndex` → slide blank sesaat kalau index lama > panjang baru. `removeSlide` lokal SUDAH clamp (~1139), jalur WS tidak. Fix: tambah clamp setelah set slides.
3. **MEDIUM (operasional) — PIN & ticker reset tiap tengah-malam**: `controlPin` ('1234', ~527) & `tickerMessages` (~514) = ref lokal TANPA persist. Reset tengah-malam = `window.location.reload()` → balik ke default. Operator yg ganti PIN/edit ticker kehilangan perubahan tiap 00:00. Fix opsi: persist ke backend (kolom/endpoint baru) atau localStorage (per-device).
4. **MINOR — `audioCtx` tak di-`close()` di onUnmounted** (~941): bukan leak per-panggilan (di-reuse via `if(!audioCtx)`), tapi tak ditutup saat unmount. Fix: `audioCtx?.close()`.

## ✅ SUDAH AMAN (audit lama menyebut, kini tak ditemukan/termitigasi)
- **nested-button CRIT**: tak ada lagi `<button>` bersarang (grid stasiun sudah `<div>`, mode-card datar). Sudah diperbaiki/atau tak pernah ada di versi ini.
- **flash-TTS dobel WS↔polling**: termitigasi — polling HANYA start saat WS gagal (`startPolling` di catch/error, tak jalan bareng WS) + `enqueueCall` dedup key `${id}:${called_at}`.

GOTCHA: index MEMORY.md lama mencatat 6 bug "belum difix"; re-verifikasi 2026-06-01 → hanya 4 yg masih nyata. Jangan percaya daftar lama tanpa cek kode.
