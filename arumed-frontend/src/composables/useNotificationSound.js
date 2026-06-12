/**
 * useNotificationSound — chime ringan untuk notifikasi (mis. inbox Inventori).
 *
 * Pola audio mengikuti AntreanTVView: cache satu HTMLAudioElement, auto-unlock
 * AudioContext pada gesture pertama (Chromium memblok autoplay sebelum interaksi),
 * dan toggle mute yang persist di localStorage.
 *
 * Pemakaian:
 *   const { muted, toggleMute, playChime } = useNotificationSound()
 *   // saat ada notif baru: if (!muted.value) playChime()
 */
import { ref } from 'vue'

const DEFAULT_SRC = '/sounds/soft-chime.mp3'

export function useNotificationSound(opts = {}) {
  const src      = opts.src ?? DEFAULT_SRC
  const muteKey  = opts.muteKey ?? 'inventori.soundMuted'
  const volume   = opts.volume ?? 0.6

  const muted = ref(localStorage.getItem(muteKey) === '1')

  let audio = null
  let unlocked = false

  function ensureAudio() {
    if (!audio) {
      audio = new Audio(src)
      audio.preload = 'auto'
      audio.volume = Math.max(0, Math.min(1, volume))
    }
    return audio
  }

  // Unlock pada gesture pertama: putar diam-diam lalu reset, agar play() berikutnya
  // (yang dipicu polling, bukan gesture) tidak diblok autoplay-policy.
  function unlock() {
    if (unlocked) return
    unlocked = true
    try {
      const a = ensureAudio()
      const prev = a.volume
      a.volume = 0
      const p = a.play()
      if (p && typeof p.then === 'function') {
        p.then(() => { a.pause(); a.currentTime = 0; a.volume = prev })
         .catch(() => { a.volume = prev })
      } else {
        a.volume = prev
      }
    } catch (_) { /* ignore */ }
    removeUnlockListeners()
  }

  const UNLOCK_EVENTS = ['pointerdown', 'keydown', 'touchstart']
  function installUnlockListeners() {
    UNLOCK_EVENTS.forEach((ev) => window.addEventListener(ev, unlock, { passive: true }))
  }
  function removeUnlockListeners() {
    UNLOCK_EVENTS.forEach((ev) => window.removeEventListener(ev, unlock))
  }
  installUnlockListeners()

  function playChime() {
    if (muted.value) return
    try {
      const a = ensureAudio()
      a.volume = Math.max(0, Math.min(1, volume))
      a.currentTime = 0
      const p = a.play()
      if (p && typeof p.catch === 'function') p.catch(() => {})
    } catch (_) { /* ignore */ }
  }

  function toggleMute() {
    muted.value = !muted.value
    localStorage.setItem(muteKey, muted.value ? '1' : '0')
  }

  function dispose() { removeUnlockListeners() }

  return { muted, toggleMute, playChime, dispose }
}
