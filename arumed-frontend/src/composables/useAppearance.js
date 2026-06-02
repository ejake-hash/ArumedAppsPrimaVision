/**
 * useAppearance — preferensi tampilan global (ukuran font & kepadatan tata letak).
 *
 * Disimpan di localStorage (per-perangkat, bukan per-akun) dan diterapkan ke
 * elemen <html> sehingga berlaku di SELURUH halaman, termasuk sebelum login.
 *
 * Mekanisme:
 *  - Ukuran font  → CSS `zoom` pada <html>. Dipilih (bukan rem) karena seluruh
 *    komponen memakai satuan px; rem tidak akan menskalakannya. `zoom` mem-
 *    perbesar/perkecil semuanya secara proporsional (didukung Chromium/WebKit).
 *  - Kepadatan    → atribut `data-density="compact"` pada <html>; aturan CSS di
 *    base.css merapatkan padding tabel/kartu saat mode kompak aktif.
 *
 * Dipakai dua tempat:
 *  - main.js  → applyAppearance() saat boot (sebelum app mount).
 *  - PengaturanView (bagian "Tampilan") → state reaktif + setter.
 */
import { reactive, watch } from 'vue'

const STORAGE_KEY = 'pv.appearance'

// Pilihan skala font yang ditawarkan ke pengguna.
export const FONT_SCALES = [
  { value: 0.9, label: 'Kecil' },
  { value: 1.0, label: 'Normal' },
  { value: 1.1, label: 'Besar' },
  { value: 1.25, label: 'Sangat Besar' },
]

const DEFAULTS = { fontScale: 1.0, density: 'normal' }

function clampScale(v) {
  const n = Number(v)
  if (!Number.isFinite(n)) return DEFAULTS.fontScale
  return Math.min(1.25, Math.max(0.9, n))
}

function load() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return { ...DEFAULTS }
    const saved = JSON.parse(raw)
    return {
      fontScale: clampScale(saved.fontScale),
      density: saved.density === 'compact' ? 'compact' : 'normal',
    }
  } catch {
    return { ...DEFAULTS }
  }
}

// State reaktif tunggal (singleton) — dibagikan ke semua pemanggil composable.
const state = reactive(load())

/** Terapkan state saat ini ke elemen <html>. */
export function applyAppearance() {
  const el = document.documentElement
  el.style.zoom = String(state.fontScale)
  el.setAttribute('data-density', state.density)
}

// Persist + terapkan ulang setiap kali state berubah.
watch(
  state,
  () => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
    } catch { /* storage penuh / mode privat — abaikan */ }
    applyAppearance()
  },
  { deep: true },
)

export function useAppearance() {
  return {
    state,
    setFontScale(v) { state.fontScale = clampScale(v) },
    setDensity(d) { state.density = d === 'compact' ? 'compact' : 'normal' },
    reset() {
      state.fontScale = DEFAULTS.fontScale
      state.density = DEFAULTS.density
    },
  }
}
