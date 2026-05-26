<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { anjunganApi } from '@/services/api'

// ─── Clock ──────────────────────────────────────────────────────────────────
const clock = ref('')
const dateStr = ref('')
const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']
const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']
function updateClock() {
  const n = new Date()
  clock.value = [n.getHours(), n.getMinutes(), n.getSeconds()].map((x) => String(x).padStart(2, '0')).join(':')
  dateStr.value = `${days[n.getDay()]}, ${n.getDate()} ${months[n.getMonth()]} ${n.getFullYear()}`
}
updateClock()
let clockTimer = null

// ─── Backend heartbeat ──────────────────────────────────────────────────────
// Status: 'online' | 'offline' | 'checking'
const backendStatus = ref('checking')
let heartbeatTimer = null

async function pingBackend() {
  // /up adalah Laravel health probe (root, di luar /api/v1). Fetch langsung.
  const baseUrl = (import.meta.env.VITE_API_URL ?? '/api/v1').replace(/\/api\/v1\/?$/, '')
  const url = `${baseUrl}/up`
  try {
    const ctrl = new AbortController()
    const t = setTimeout(() => ctrl.abort(), 4000)
    const res = await fetch(url, { method: 'GET', signal: ctrl.signal, cache: 'no-store' })
    clearTimeout(t)
    backendStatus.value = res.ok ? 'online' : 'offline'
  } catch {
    backendStatus.value = 'offline'
  }
}

const statusLabel = computed(() => {
  if (backendStatus.value === 'online')   return 'Sistem Online'
  if (backendStatus.value === 'offline')  return 'Sistem Offline'
  return 'Memeriksa Koneksi'
})

// ─── Screen state machine ───────────────────────────────────────────────────
// 'home' | 'umum-loading' | 'umum-error' | 'ticket'
const screen = ref('home')
const ticket = ref(null) // { type, qNum, poli }
const countdown = ref(15)
let cdTimer = null
const umumError = ref('')

// ─── Numpad target (untuk extensibility — saat BPJS aktif, bind ke input) ──
const activeInputRef = ref(null)
const activeInputValue = ref('')

// 2π × 42 ≈ 264 (stroke-dasharray circumference for r=42 in 100×100 viewBox)
const arcOffset = computed(() => 264 * (1 - countdown.value / 15))

// ─── UMUM flow ──────────────────────────────────────────────────────────────
async function goUmum() {
  if (backendStatus.value === 'offline') {
    umumError.value = 'Sistem sedang tidak terhubung. Silakan hubungi petugas loket.'
    screen.value = 'umum-error'
    return
  }

  umumError.value = ''
  screen.value = 'umum-loading'
  try {
    const { data } = await anjunganApi.tiketUmum()

    // Envelope guard — backend bisa return 200 dengan success:false
    if (!data || data.success === false) {
      throw new Error(data?.message ?? 'Gagal menerbitkan tiket.')
    }

    const payload = data.data ?? {}
    const qNum = payload.queue_number
    if (!qNum) {
      throw new Error('Nomor antrean tidak diterima dari server.')
    }

    ticket.value = {
      type: 'umum',
      qNum,
      poli: 'Loket Admisi',
    }
    screen.value = 'ticket'
    startCountdown()
    // Auto-print ke printer thermal
    nextTick(() => triggerPrint())
  } catch (err) {
    umumError.value = err?.response?.data?.message
                   ?? err?.message
                   ?? 'Gagal mengambil tiket. Silakan hubungi petugas loket.'
    screen.value = 'umum-error'
  }
}

// ─── Print (thermal 80mm) ───────────────────────────────────────────────────
function triggerPrint() {
  // window.print() men-trigger printer default OS. CSS @media print di-style
  // ke 80mm width (standar struk thermal). Kiosk OS perlu set default printer
  // ke thermal printer.
  try {
    window.print()
  } catch {
    // Silent fail — print bukan blocker, user tetap bisa lihat tiket di layar
  }
}

// ─── Countdown auto-reset ───────────────────────────────────────────────────
function startCountdown() {
  countdown.value = 15
  cdTimer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) resetHome()
  }, 1000)
}

function resetHome() {
  clearInterval(cdTimer)
  cdTimer = null
  screen.value = 'home'
  ticket.value = null
  countdown.value = 15
  umumError.value = ''
  activeInputValue.value = ''
  activeInputRef.value = null
}

// ─── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(() => {
  clockTimer = setInterval(updateClock, 1000)
  pingBackend()
  heartbeatTimer = setInterval(pingBackend, 30_000)
})
onUnmounted(() => {
  clearInterval(clockTimer)
  clearInterval(cdTimer)
  clearInterval(heartbeatTimer)
})
</script>

<template>
  <div class="anjungan">

    <!-- ─── TOP BAR ─── -->
    <div class="ksk-top">
      <div class="ksk-logo">
        <svg class="ksk-logo-svg" viewBox="0 0 90 90" fill="none">
          <circle cx="45" cy="45" r="43" stroke="rgba(138,191,68,0.18)" stroke-width="1"/>
          <circle cx="45" cy="45" r="34" stroke="rgba(138,191,68,0.28)" stroke-width="1"/>
          <circle cx="45" cy="45" r="24" stroke="rgba(138,191,68,0.42)" stroke-width="1.5"/>
          <circle cx="45" cy="45" r="14" stroke="#8abf44" stroke-width="1.5"/>
          <circle cx="45" cy="45" r="6" fill="#8abf44" opacity="0.9"/>
          <line x1="45" y1="39" x2="45" y2="51" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="39" y1="45" x2="51" y2="45" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <div class="ksk-brand">
          <span class="ksk-brand-name">Klinik Mata Arunika</span>
          <span class="ksk-brand-sub">Cilegon · Anjungan Mandiri</span>
        </div>
      </div>

      <div class="ksk-clock-box">
        <div class="ksk-clock">{{ clock }}</div>
        <div class="ksk-date">{{ dateStr }}</div>
        <div :class="['ksk-status', backendStatus]">
          <span class="dot"></span>
          {{ statusLabel }}
        </div>
      </div>
    </div>

    <!-- ─── SCREEN AREA ─── -->
    <div class="ksk-body">

      <!-- HOME -->
      <div v-if="screen === 'home'" class="screen home-screen">
        <div class="home-head">
          <h1 class="welcome-title">Selamat Datang</h1>
          <p class="welcome-sub">Pilih kategori kunjungan Anda untuk memulai</p>
        </div>
        <div class="choice-grid">
          <!-- BPJS — disabled sementara, menunggu integrasi VClaim -->
          <button class="choice-card bpjs-card disabled" disabled aria-disabled="true" title="Segera hadir — integrasi BPJS dalam proses">
            <span class="cc-coming">Segera Hadir</span>
            <div class="cc-icon">
              <svg viewBox="0 0 64 64" fill="none">
                <rect x="6" y="14" width="52" height="36" rx="5" stroke="rgba(255,255,255,0.35)" stroke-width="2.5"/>
                <path d="M6 24h52" stroke="rgba(255,255,255,0.35)" stroke-width="2.5"/>
                <rect x="14" y="33" width="14" height="9" rx="2" fill="rgba(255,255,255,0.08)" stroke="rgba(255,255,255,0.35)" stroke-width="1.5"/>
                <path d="M36 34h14M36 40h8" stroke="rgba(255,255,255,0.35)" stroke-width="2" stroke-linecap="round"/>
              </svg>
            </div>
            <span class="cc-label">BPJS / JKN</span>
            <span class="cc-desc">Check-in mandiri dengan kode booking, kartu BPJS, atau NIK</span>
            <span class="cc-badge muted">Integrasi VClaim Berjalan</span>
          </button>

          <button class="choice-card umum-card" @click="goUmum" :disabled="backendStatus === 'offline'">
            <div class="cc-icon">
              <svg viewBox="0 0 64 64" fill="none">
                <circle cx="32" cy="22" r="11" stroke="rgba(255,255,255,0.75)" stroke-width="2.5"/>
                <path d="M9 56c0-12.7 10.3-23 23-23s23 10.3 23 23" stroke="rgba(255,255,255,0.75)" stroke-width="2.5" stroke-linecap="round"/>
              </svg>
            </div>
            <span class="cc-label">Umum / Pasien Baru</span>
            <span class="cc-desc">Bayar mandiri, asuransi swasta, atau kunjungan pertama</span>
            <span class="cc-badge umum">Antrean Admisi</span>
          </button>
        </div>

        <p class="home-foot-hint">
          Pasien BPJS sementara silakan menuju <strong>Loket Admisi</strong> untuk diproses petugas.
        </p>
      </div>

      <!-- UMUM LOADING -->
      <div v-else-if="screen === 'umum-loading'" class="screen center-screen">
        <div class="load-ring"></div>
        <h2 class="load-title">Menerbitkan Tiket Antrean</h2>
        <p class="load-sub">Menghubungkan ke loket admisi…</p>
        <div class="load-dots">
          <span></span><span></span><span></span>
        </div>
      </div>

      <!-- UMUM ERROR -->
      <div v-else-if="screen === 'umum-error'" class="screen center-screen">
        <div class="ta-check err">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h2 class="load-title">Gagal Mengambil Tiket</h2>
        <p class="load-sub err-msg">{{ umumError }}</p>
        <button class="ksk-btn sec sm" @click="resetHome">Kembali</button>
      </div>

      <!-- TICKET SCREEN -->
      <div v-else-if="screen === 'ticket'" class="screen ticket-screen">
        <div class="ticket-wrap">
          <div class="ticket" id="thermal-ticket">
            <div class="tkt-header">
              <span class="tkt-clinic">Klinik Mata Arunika</span>
              <span class="tkt-type u">Pasien Umum</span>
            </div>
            <div class="tkt-perf"></div>
            <div class="tkt-num">{{ ticket.qNum }}</div>
            <div class="tkt-sep-line"></div>
            <div class="tkt-body umum-body">
              <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <p>Menuju <strong>Loket Admisi</strong><br/>untuk menyelesaikan pendaftaran</p>
            </div>
            <div class="tkt-perf"></div>
          </div>
        </div>

        <div class="ticket-aside">
          <div class="ta-check">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <p class="ta-msg">Tiket berhasil diterbitkan</p>
          <p class="ta-hint">Silakan duduk — nama Anda akan dipanggil</p>

          <div class="cdown-wrap">
            <div class="cdown-ring">
              <svg viewBox="0 0 100 100">
                <circle class="cdown-bg" cx="50" cy="50" r="42"/>
                <circle
                  class="cdown-fg"
                  cx="50" cy="50" r="42"
                  :style="{ strokeDashoffset: arcOffset }"
                />
              </svg>
              <span class="cdown-num">{{ countdown }}</span>
            </div>
            <span class="cdown-label">detik — layar otomatis kembali</span>
          </div>

          <div class="ta-actions">
            <button class="ksk-btn sec sm" @click="triggerPrint" title="Cetak ulang tiket">
              <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Cetak Ulang
            </button>
            <button class="ksk-btn pri sm" @click="resetHome">Selesai</button>
          </div>
        </div>
      </div>

    </div><!-- /ksk-body -->

    <!-- ─── BOTTOM BAR ─── -->
    <div class="ksk-bottom">
      <div class="kb-help">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Butuh bantuan? Hubungi petugas di loket informasi
      </div>
      <div class="kb-brand">Arumed Apps · BPJS VClaim Terintegrasi · PMK No. 24/2022</div>
    </div>

  </div>
</template>

<style scoped>
/* ─── ROOT ─── */
.anjungan {
  width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
  background: linear-gradient(155deg, var(--gd) 0%, var(--gm) 100%);
  color: #fff;
  font-family: 'DM Sans', sans-serif;
  overflow: hidden;
}

/* ─── TOP BAR ─── */
.ksk-top {
  height: 80px;
  padding: 0 2.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(0, 0, 0, 0.15);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  flex-shrink: 0;
}
.ksk-logo { display: flex; align-items: center; gap: 1rem; }
.ksk-logo-svg { width: 50px; height: 50px; }
.ksk-brand { display: flex; flex-direction: column; }
.ksk-brand-name {
  font-family: 'DM Serif Display', serif;
  font-size: 22px;
  line-height: 1;
  letter-spacing: 0.01em;
}
.ksk-brand-sub {
  font-size: 11px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(138, 191, 68, 0.75);
  margin-top: 5px;
}
.ksk-clock-box { text-align: right; }
.ksk-clock {
  font-family: 'DM Serif Display', serif;
  font-size: 30px;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}
.ksk-date { font-size: 12px; color: rgba(255, 255, 255, 0.45); margin-top: 4px; }

/* Heartbeat indicator */
.ksk-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  font-size: 11px;
  letter-spacing: 0.04em;
  color: rgba(255, 255, 255, 0.45);
  text-transform: uppercase;
}
.ksk-status .dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.3);
  box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
}
.ksk-status.online .dot {
  background: #8abf44;
  animation: pulseDot 2s infinite;
}
.ksk-status.offline .dot { background: #ef4444; }
.ksk-status.offline { color: #fca5a5; }
@keyframes pulseDot {
  0% { box-shadow: 0 0 0 0 rgba(138, 191, 68, 0.55); }
  70% { box-shadow: 0 0 0 6px rgba(138, 191, 68, 0); }
  100% { box-shadow: 0 0 0 0 rgba(138, 191, 68, 0); }
}

/* ─── BODY ─── */
.ksk-body {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem 2.5rem;
  overflow: hidden;
}

/* ─── SCREEN FADE ─── */
.screen {
  width: 100%;
  animation: fadeUp 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(18px); }
  to { opacity: 1; transform: translateY(0); }
}

/* ─── HOME SCREEN ─── */
.home-screen { max-width: 900px; margin: 0 auto; }
.home-head { text-align: center; margin-bottom: 2.5rem; }
.welcome-title {
  font-family: 'DM Serif Display', serif;
  font-size: 54px;
  font-weight: 400;
  line-height: 1.05;
  margin-bottom: 0.65rem;
}
.welcome-sub { font-size: 18px; color: rgba(255, 255, 255, 0.55); }
.choice-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
.choice-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.85rem;
  padding: 2.25rem 1.75rem 2rem;
  min-height: 270px;
  background: rgba(255, 255, 255, 0.07);
  border: 1.5px solid rgba(255, 255, 255, 0.14);
  border-radius: 22px;
  cursor: pointer;
  transition: all 0.22s cubic-bezier(0.22, 1, 0.36, 1);
  font-family: 'DM Sans', sans-serif;
  color: #fff;
  text-align: center;
}
.choice-card:not(:disabled):hover {
  background: rgba(138, 191, 68, 0.13);
  border-color: #8abf44;
  transform: translateY(-4px);
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(138, 191, 68, 0.3);
}
.choice-card:disabled {
  cursor: not-allowed;
  opacity: 0.55;
  background: rgba(255, 255, 255, 0.04);
  border-style: dashed;
}
.choice-card.disabled .cc-label,
.choice-card.disabled .cc-desc {
  color: rgba(255, 255, 255, 0.4);
}
.cc-coming {
  position: absolute;
  top: 14px;
  right: 14px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  padding: 4px 10px;
  border-radius: 20px;
  background: rgba(251, 191, 36, 0.18);
  color: #fbbf24;
  border: 1px solid rgba(251, 191, 36, 0.4);
}
.cc-icon { width: 72px; height: 72px; display: flex; align-items: center; justify-content: center; margin-bottom: 0.25rem; }
.cc-icon svg { width: 64px; height: 64px; }
.cc-label { font-size: 24px; font-weight: 600; }
.cc-desc { font-size: 14px; color: rgba(255, 255, 255, 0.5); line-height: 1.45; max-width: 240px; }
.cc-badge {
  font-size: 10.5px;
  font-weight: 700;
  padding: 5px 14px;
  border-radius: 20px;
  background: rgba(138, 191, 68, 0.18);
  color: #8abf44;
  border: 1px solid rgba(138, 191, 68, 0.38);
  letter-spacing: 0.05em;
  text-transform: uppercase;
  margin-top: auto;
}
.cc-badge.umum { background: rgba(255, 255, 255, 0.08); color: rgba(255, 255, 255, 0.6); border-color: rgba(255, 255, 255, 0.2); }
.cc-badge.muted { background: rgba(255, 255, 255, 0.05); color: rgba(255, 255, 255, 0.35); border-color: rgba(255, 255, 255, 0.12); }

.home-foot-hint {
  margin-top: 2rem;
  text-align: center;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.4);
}
.home-foot-hint strong { color: rgba(138, 191, 68, 0.85); font-weight: 600; }

/* ─── LOADING / ERROR SCREEN ─── */
.center-screen {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.25rem;
  text-align: center;
}
.load-ring {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  border: 4px solid rgba(138, 191, 68, 0.2);
  border-top-color: #8abf44;
  animation: spin 0.85s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.load-title { font-family: 'DM Serif Display', serif; font-size: 34px; font-weight: 400; }
.load-sub { font-size: 16px; color: rgba(255, 255, 255, 0.45); }
.load-sub.err-msg { max-width: 420px; color: #fca5a5; }
.load-dots { display: flex; gap: 8px; }
.load-dots span {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgba(138, 191, 68, 0.5);
  animation: blink 1.4s infinite;
}
.load-dots span:nth-child(2) { animation-delay: 0.2s; }
.load-dots span:nth-child(3) { animation-delay: 0.4s; }
@keyframes blink {
  0%, 100% { opacity: 0.3; }
  50% { opacity: 1; }
}

/* ─── BUTTONS ─── */
.ksk-btn {
  flex: 1;
  height: 68px;
  border-radius: 14px;
  font-family: 'DM Sans', sans-serif;
  font-size: 17px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 2px solid;
  white-space: nowrap;
}
.ksk-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.ksk-btn.pri { background: #8abf44; border-color: #8abf44; color: #061d15; }
.ksk-btn.pri:hover { background: var(--ld); border-color: var(--ld); }
.ksk-btn.sec { background: transparent; border-color: rgba(255, 255, 255, 0.25); color: rgba(255, 255, 255, 0.75); }
.ksk-btn.sec:hover { border-color: rgba(255, 255, 255, 0.55); color: #fff; }
.ksk-btn.sm { flex: none; padding: 0 1.5rem; height: 48px; font-size: 14px; }

/* ─── TICKET SCREEN ─── */
.ticket-screen {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4rem;
  max-width: 900px;
  margin: 0 auto;
}
.ticket-wrap { flex-shrink: 0; }
.ticket {
  width: 310px;
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 24px 64px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.1);
  overflow: hidden;
}
.tkt-header {
  background: var(--gd);
  padding: 1.1rem 1.4rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.tkt-clinic { font-family: 'DM Serif Display', serif; font-size: 14px; color: #fff; }
.tkt-type {
  font-size: 9px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 20px;
  letter-spacing: 0.07em;
  text-transform: uppercase;
}
.tkt-type.u { background: rgba(255, 255, 255, 0.12); color: rgba(255, 255, 255, 0.75); border: 1px solid rgba(255, 255, 255, 0.25); }
.tkt-perf {
  height: 14px;
  background: repeating-linear-gradient(90deg, transparent, transparent 6px, #fff 6px, #fff 7px) #f0f4f1;
}
.tkt-num {
  font-family: 'DM Serif Display', serif;
  font-size: 100px;
  color: #8abf44;
  text-align: center;
  line-height: 1;
  padding: 0.5rem 0 0.75rem;
  letter-spacing: 0.02em;
}
.tkt-sep-line { height: 1.5px; background: repeating-linear-gradient(90deg, var(--gb) 0, var(--gb) 6px, transparent 6px, transparent 12px); margin: 0 1.25rem; }
.umum-body {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.5rem;
  padding: 1rem 1.4rem 1.25rem;
}
.umum-body svg { width: 26px; height: 26px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.umum-body p { font-size: 14px; color: var(--td); line-height: 1.55; margin: 0; }
.umum-body strong { color: var(--gd); }

/* Ticket aside */
.ticket-aside {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 1.25rem;
}
.ta-check {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: rgba(138, 191, 68, 0.18);
  border: 2px solid rgba(138, 191, 68, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
}
.ta-check svg { width: 24px; height: 24px; fill: none; stroke: #8abf44; stroke-width: 2.5; stroke-linecap: round; }
.ta-check.err { background: rgba(220, 38, 38, 0.18); border-color: rgba(220, 38, 38, 0.45); }
.ta-check.err svg { stroke: #fca5a5; }
.ta-msg { font-family: 'DM Serif Display', serif; font-size: 28px; font-weight: 400; line-height: 1.2; }
.ta-hint { font-size: 16px; color: rgba(255, 255, 255, 0.5); max-width: 280px; line-height: 1.5; margin-top: -0.5rem; }

/* Countdown ring */
.cdown-wrap { display: flex; align-items: center; gap: 1rem; }
.cdown-ring { position: relative; width: 76px; height: 76px; flex-shrink: 0; }
.cdown-ring svg { width: 76px; height: 76px; }
.cdown-bg { fill: none; stroke: rgba(255, 255, 255, 0.08); stroke-width: 9; }
.cdown-fg {
  fill: none;
  stroke: #8abf44;
  stroke-width: 9;
  stroke-linecap: round;
  stroke-dasharray: 264;
  transform: rotate(-90deg);
  transform-origin: 50px 50px;
  transition: stroke-dashoffset 1s linear;
}
.cdown-num {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: #fff;
}
.cdown-label { font-size: 14px; color: rgba(255, 255, 255, 0.45); max-width: 160px; line-height: 1.4; }

.ta-actions { display: flex; gap: 0.75rem; }

/* ─── BOTTOM BAR ─── */
.ksk-bottom {
  height: 54px;
  padding: 0 2.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(0, 0, 0, 0.18);
  border-top: 1px solid rgba(255, 255, 255, 0.07);
  flex-shrink: 0;
}
.kb-help {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.4);
}
.kb-help svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.kb-brand { font-size: 11px; color: rgba(255, 255, 255, 0.18); letter-spacing: 0.04em; }
</style>

<!-- ─── PRINT STYLE (Thermal 80mm) ─── -->
<!-- Tidak scoped — agar @page apply ke seluruh dokumen saat window.print() -->
<style>
@media print {
  @page {
    size: 80mm auto;
    margin: 0;
  }

  /* Sembunyikan seluruh halaman, hanya tampilkan tiket */
  body * { visibility: hidden !important; }
  #thermal-ticket, #thermal-ticket * { visibility: visible !important; }

  #thermal-ticket {
    position: absolute !important;
    left: 0 !important;
    top: 0 !important;
    width: 80mm !important;
    background: #fff !important;
    color: #000 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    padding: 4mm 0 !important;
  }

  /* Header thermal (B/W) — center align, hilangkan badge tipe */
  #thermal-ticket .tkt-header {
    background: #fff !important;
    color: #000 !important;
    padding: 2mm 4mm !important;
    border-bottom: 1px dashed #000;
    display: block !important;
    text-align: center !important;
  }
  #thermal-ticket .tkt-clinic {
    color: #000 !important;
    font-size: 13pt !important;
    font-weight: 700;
    display: block !important;
    text-align: center !important;
  }
  #thermal-ticket .tkt-type { display: none !important; }

  #thermal-ticket .tkt-perf { display: none !important; }
  #thermal-ticket .tkt-sep-line {
    background: none !important;
    border-top: 1px dashed #000;
    height: 0 !important;
    margin: 2mm 4mm !important;
  }

  #thermal-ticket .tkt-num {
    color: #000 !important;
    font-size: 56pt !important;
    padding: 2mm 0 !important;
    font-weight: 700;
  }

  #thermal-ticket .umum-body {
    padding: 2mm 4mm !important;
    color: #000 !important;
  }
  #thermal-ticket .umum-body p,
  #thermal-ticket .umum-body strong {
    color: #000 !important;
    font-size: 10pt !important;
  }
  #thermal-ticket .umum-body svg { stroke: #000 !important; }
}
</style>
