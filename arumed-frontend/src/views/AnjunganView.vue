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
  const ctrl = new AbortController()
  const t = setTimeout(() => ctrl.abort(), 4000)
  try {
    const res = await fetch(url, { method: 'GET', signal: ctrl.signal, cache: 'no-store' })
    backendStatus.value = res.ok ? 'online' : 'offline'
  } catch {
    backendStatus.value = 'offline'
  } finally {
    // Selalu bersihkan timer abort — di jalur error/abort dulu tak ter-clear
    // sehingga timer menggantung (dan bisa hidup melewati unmount).
    clearTimeout(t)
  }
}

const statusLabel = computed(() => {
  if (backendStatus.value === 'online')   return 'Sistem Online'
  if (backendStatus.value === 'offline')  return 'Sistem Offline'
  return 'Memeriksa Koneksi'
})

// ─── Bridging status (apakah Check-in BPJS/JKN mandiri aktif) ────────────────
// Kartu BPJS di home aktif HANYA bila bridging Antrean BPJS aktif di backend.
const bridgingAntrean = ref(false)
async function fetchBridgingStatus() {
  try {
    const { data } = await anjunganApi.status()
    bridgingAntrean.value = data?.data?.antrean_enabled === true
  } catch {
    bridgingAntrean.value = false // gagal cek → kartu tetap nonaktif (aman)
  }
}

// ─── Screen state machine ───────────────────────────────────────────────────
// 'home' | 'umum-loading' | 'umum-error' | 'ticket'
// BPJS: 'bpjs-choice' | 'bpjs-booking' | 'bpjs-onsite' | 'bpjs-loading'
const screen = ref('home')
const ticket = ref(null) // { type, qNum, poli, dokter, nomorantrean }
const countdown = ref(15)
let cdTimer = null
const umumError = ref('') // dipakai bersama untuk error UMUM & BPJS

// ─── BPJS input state (2 jalur) ──────────────────────────────────────────────
// Jalur kode booking (scan/ketik) + jalur onsite (NIK + rujukan + pilih dokter).
const bookingInput = ref('')      // kode booking / NIK / kartu (scan barcode = ketik+Enter)
const onsiteNik = ref('')
const onsiteRujukan = ref('')     // no rujukan (scan/ketik)
const onsiteHp = ref('')          // no HP (wajib BPJS /antrean/add)
const onsiteDoctor = ref('')      // doctor_schedule_id terpilih
const doctors = ref([])
const doctorsLoading = ref(false)
const focusedField = ref('booking') // target numpad: 'booking' | 'nik' | 'rujukan'
const bookingInputEl = ref(null)    // utk autofocus (scanner barcode → input)
const onsiteNikEl = ref(null)

// Numpad: bantu input digit (NIK/kartu) di kiosk layar-sentuh. Field alfanumerik
// (kode booking / rujukan) tetap bisa via scanner/keyboard (mengetik ke input).
function npAppend(s) {
  if (umumError.value) umumError.value = ''
  const f = focusedField.value
  if (f === 'booking' && bookingInput.value.length < 24) bookingInput.value += s
  else if (f === 'nik' && onsiteNik.value.length < 19) onsiteNik.value += s
  else if (f === 'rujukan' && onsiteRujukan.value.length < 30) onsiteRujukan.value += s
  else if (f === 'hp' && onsiteHp.value.length < 15) onsiteHp.value += s
}
function npBackspace() {
  const f = focusedField.value
  if (f === 'booking') bookingInput.value = bookingInput.value.slice(0, -1)
  else if (f === 'nik') onsiteNik.value = onsiteNik.value.slice(0, -1)
  else if (f === 'rujukan') onsiteRujukan.value = onsiteRujukan.value.slice(0, -1)
  else if (f === 'hp') onsiteHp.value = onsiteHp.value.slice(0, -1)
}
function npClear() {
  const f = focusedField.value
  if (f === 'booking') bookingInput.value = ''
  else if (f === 'nik') onsiteNik.value = ''
  else if (f === 'rujukan') onsiteRujukan.value = ''
  else if (f === 'hp') onsiteHp.value = ''
}

async function loadDoctors() {
  doctorsLoading.value = true
  try {
    const { data } = await anjunganApi.dokterAktif()
    doctors.value = Array.isArray(data?.data) ? data.data : []
  } catch {
    doctors.value = []
  } finally {
    doctorsLoading.value = false
  }
}

const cdTotal = ref(15)
// 2π × 42 ≈ 264 (stroke-dasharray circumference for r=42 in 100×100 viewBox)
const arcOffset = computed(() => 264 * (1 - countdown.value / cdTotal.value))

// ─── UMUM flow ──────────────────────────────────────────────────────────────
async function goUmum() {
  if (screen.value === 'umum-loading') return // cegah double-submit
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
    // Jadwalkan auto-reset DULU, baru cetak. window.print() bisa blocking di
    // browser non-kiosk; bila dialog tak pernah ditutup, countdown yang
    // dijadwalkan setelahnya tak akan pernah mulai → kiosk terjebak di layar
    // tiket. setInterval ter-suspend selama dialog blocking terbuka, jadi
    // menjadwalkan lebih dulu aman dan menjamin layar selalu kembali ke home.
    nextTick(() => {
      startCountdown()
      triggerPrint()
    })
  } catch (err) {
    umumError.value = err?.response?.data?.message
                   ?? err?.message
                   ?? 'Gagal mengambil tiket. Silakan hubungi petugas loket.'
    screen.value = 'umum-error'
  }
}

// ─── BPJS / JKN flow ──────────────────────────────────────────────────────
// Dua jalur: (1) kode booking Mobile JKN (+ scan), (2) ambil antrean onsite.
// ONSITE DINONAKTIFKAN SEMENTARA: BPJS menolak WS Tambah Antrean (`add`) dengan
// "API Versi 2" untuk faskes ini (menunggu klarifikasi/registrasi sisi BPJS).
// Sampai itu beres, kiosk hanya melayani check-in via Kode Booking (Mobile JKN).
// Cukup ubah ke true untuk mengaktifkan kembali setelah `add` jalan.
const ONSITE_ENABLED = false

function goBpjs() {
  if (!bridgingAntrean.value) return
  if (backendStatus.value === 'offline') {
    umumError.value = 'Sistem sedang tidak terhubung. Silakan hubungi petugas loket.'
    screen.value = 'umum-error'
    return
  }
  umumError.value = ''
  // Onsite nonaktif → langsung ke jalur Kode Booking (lewati layar pilihan).
  if (!ONSITE_ENABLED) { chooseBooking(); return }
  screen.value = 'bpjs-choice'
}

function chooseBooking() {
  umumError.value = ''
  bookingInput.value = ''
  focusedField.value = 'booking'
  screen.value = 'bpjs-booking'
  nextTick(() => bookingInputEl.value?.focus())
}

// "Kembali" dari layar BPJS: ke layar pilihan bila onsite aktif, selain itu ke home.
function backFromBpjs() {
  if (ONSITE_ENABLED) { screen.value = 'bpjs-choice'; umumError.value = '' }
  else resetHome()
}

function chooseOnsite() {
  umumError.value = ''
  onsiteNik.value = ''
  onsiteRujukan.value = ''
  onsiteHp.value = ''
  onsiteDoctor.value = ''
  focusedField.value = 'nik'
  screen.value = 'bpjs-onsite'
  loadDoctors()
  nextTick(() => onsiteNikEl.value?.focus())
}

// Tiket sementara saat menunggu validasi sidik jari (antrean sudah terbit).
const pendingTicket = ref(null)

// Bungkus pemanggilan API + tampilkan tiket (dipakai kedua jalur).
async function runBpjs(promise) {
  umumError.value = ''
  screen.value = 'bpjs-loading'
  try {
    const { data } = await promise
    if (!data || data.success === false) {
      throw new Error(data?.message ?? 'Permintaan gagal.')
    }
    const p = data.data ?? {}
    const base = {
      type: 'bpjs',
      qNum: p.queue_number ?? p.nomorantrean ?? '—',
      poli: p.namapoli ?? 'Poliklinik',
      dokter: p.namadokter ?? '',
      nomorantrean: p.nomorantrean ?? '',
      kodebooking: p.kodebooking ?? '',
    }

    // Poli wajib sidik jari & belum tervalidasi → tahan SEP, minta FRISTA dulu.
    if (p.fp_required) {
      pendingTicket.value = base
      umumError.value = ''
      screen.value = 'bpjs-fingerprint'
      return
    }

    showTicket(base, p)
  } catch (err) {
    umumError.value = err?.response?.data?.message
                   ?? err?.message
                   ?? 'Gagal memproses. Silakan hubungi petugas loket.'
    screen.value = 'umum-error'
  }
}

function showTicket(base, p) {
  ticket.value = {
    ...base,
    sep: p.sep ?? null,          // { no_sep, tgl_sep, peserta, diagnosa, kelas }
    sepError: p.sep_error ?? null,
  }
  screen.value = 'ticket'
  nextTick(() => {
    startCountdown()
    triggerPrint()
  })
}

// Setelah pasien validasi sidik jari di FRISTA → terbitkan SEP.
async function lanjutFingerprint() {
  if (!pendingTicket.value?.kodebooking) { resetHome(); return }
  umumError.value = ''
  screen.value = 'bpjs-loading'
  try {
    const { data } = await anjunganApi.terbitkanSep({ kodebooking: pendingTicket.value.kodebooking })
    const p = data?.data ?? {}
    if (p.fp_required) {
      umumError.value = 'Sidik jari belum terdeteksi. Pastikan sudah berhasil di aplikasi FRISTA, lalu coba lagi.'
      screen.value = 'bpjs-fingerprint'
      return
    }
    showTicket(pendingTicket.value, p)
  } catch (err) {
    umumError.value = err?.response?.data?.message ?? err?.message ?? 'Gagal menerbitkan SEP.'
    screen.value = 'bpjs-fingerprint'
  }
}

// Jalur 1 — kode booking (atau NIK/kartu) dari Mobile JKN.
function submitBooking() {
  const val = bookingInput.value.trim()
  if (val.length < 5) {
    umumError.value = 'Scan atau ketik kode booking, NIK, atau No. Kartu BPJS.'
    return
  }
  const payload =
    /^\d{16}$/.test(val) ? { nik: val }
    : /^\d{13}$/.test(val) ? { nomorkartu: val }
    : { kodebooking: val }
  runBpjs(anjunganApi.checkinBpjs(payload))
}

// Jalur 2 — ambil antrean onsite: NIK/kartu + no rujukan + pilih dokter.
function submitOnsite() {
  const nik = onsiteNik.value.trim()
  const hp = onsiteHp.value.trim()
  if (nik.length < 5) { umumError.value = 'Masukkan NIK atau No. Kartu BPJS.'; return }
  if (hp.length < 8) { umumError.value = 'Masukkan No. HP (wajib untuk antrean BPJS).'; return }
  if (!onsiteDoctor.value) { umumError.value = 'Pilih dokter tujuan terlebih dahulu.'; return }

  // 16 digit → NIK, 13 digit → kartu BPJS (sama seperti backend).
  const idField = /^\d{13}$/.test(nik) ? { nomorkartu: nik } : { nik }
  runBpjs(anjunganApi.ambilAntreanBpjs({
    doctor_schedule_id: onsiteDoctor.value,
    ...idField,
    nohp: hp,
    nomorreferensi: onsiteRujukan.value.trim() || undefined,
  }))
}

// ─── Print (thermal 80mm) ───────────────────────────────────────────────────
function triggerPrint() {
  // window.print() men-trigger printer default OS. CSS @media print di-style
  // ke 80mm width (standar struk thermal). Kiosk OS perlu set default printer
  // ke thermal printer (Epson TM-T82X: pilih paper "Roll Paper 80 x 297 mm",
  // margin 0, skala 100%/"Default" — jangan "Fit to page" agar tak menyusut).
  try {
    window.print()
  } catch {
    // Silent fail — print bukan blocker, user tetap bisa lihat tiket di layar
  }
}

// Cetak ulang dari layar tiket: print + restart countdown 15s. Tanpa restart,
// dialog print yang blocking bisa membuat countdown lama keburu habis → user
// ketendang ke home tepat setelah menutup dialog cetak.
function reprintTicket() {
  // Restart countdown dulu (alasan sama dgn goUmum: jamin auto-reset terjadwal
  // walau dialog cetak blocking).
  startCountdown()
  triggerPrint()
}

// ─── Countdown auto-reset ───────────────────────────────────────────────────
function startCountdown() {
  clearInterval(cdTimer) // jaga-jaga bila timer lama belum dibersihkan
  // Tiket BPJS dengan SEP butuh waktu baca lebih lama (data SEP di layar).
  cdTotal.value = (ticket.value?.type === 'bpjs' && ticket.value?.sep) ? 30 : 15
  countdown.value = cdTotal.value
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
  bookingInput.value = ''
  onsiteNik.value = ''
  onsiteRujukan.value = ''
  onsiteHp.value = ''
  onsiteDoctor.value = ''
  pendingTicket.value = null
}

// ─── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(() => {
  clockTimer = setInterval(updateClock, 1000)
  pingBackend()
  fetchBridgingStatus()
  // Heartbeat: ping backend + segarkan status bridging (toggle di admin langsung terlihat).
  heartbeatTimer = setInterval(() => { pingBackend(); fetchBridgingStatus() }, 30_000)
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
          <circle cx="45" cy="45" r="43" stroke="rgba(56,189,248,0.18)" stroke-width="1"/>
          <circle cx="45" cy="45" r="34" stroke="rgba(56,189,248,0.28)" stroke-width="1"/>
          <circle cx="45" cy="45" r="24" stroke="rgba(56,189,248,0.42)" stroke-width="1.5"/>
          <circle cx="45" cy="45" r="14" stroke="var(--lm)" stroke-width="1.5"/>
          <circle cx="45" cy="45" r="6" fill="var(--lm)" opacity="0.9"/>
          <line x1="45" y1="39" x2="45" y2="51" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="39" y1="45" x2="51" y2="45" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <div class="ksk-brand">
          <span class="ksk-brand-name">RUMAH SAKIT MATA PRIMA VISION</span>
          <span class="ksk-brand-sub">Medan · Anjungan Mandiri</span>
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
          <!-- BPJS — aktif HANYA saat bridging Antrean BPJS aktif di backend -->
          <button
            class="choice-card bpjs-card"
            :class="{ disabled: !bridgingAntrean }"
            :disabled="!bridgingAntrean || backendStatus === 'offline'"
            :aria-disabled="!bridgingAntrean"
            :title="bridgingAntrean ? 'Check-in mandiri BPJS / JKN' : 'Segera hadir — bridging BPJS belum aktif'"
            @click="goBpjs"
          >
            <span v-if="!bridgingAntrean" class="cc-coming">Segera Hadir</span>
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
            <span class="cc-badge" :class="bridgingAntrean ? '' : 'muted'">
              {{ bridgingAntrean ? 'Check-in Antrean BPJS' : 'Integrasi VClaim Berjalan' }}
            </span>
          </button>

          <button class="choice-card umum-card" @click="goUmum" :disabled="backendStatus === 'offline' || screen === 'umum-loading'">
            <div class="cc-icon">
              <svg viewBox="0 0 64 64" fill="none">
                <circle cx="32" cy="22" r="11" stroke="rgba(255,255,255,0.75)" stroke-width="2.5"/>
                <path d="M9 56c0-12.7 10.3-23 23-23s23 10.3 23 23" stroke="rgba(255,255,255,0.75)" stroke-width="2.5" stroke-linecap="round"/>
              </svg>
            </div>
            <span class="cc-label">Loket Admisi</span>
            <span class="cc-desc">Bayar mandiri, asuransi swasta, atau kunjungan pertama</span>
            <span class="cc-badge umum">Antrean Admisi</span>
          </button>
        </div>

        <p class="home-foot-hint">
          <template v-if="bridgingAntrean">
            Pasien BPJS yang sudah mengambil antrean di <strong>Mobile JKN</strong> dapat check-in mandiri di sini.
          </template>
          <template v-else>
            Pasien BPJS sementara silakan menuju <strong>Loket Admisi</strong> untuk diproses petugas.
          </template>
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

      <!-- BPJS CHOICE — 2 jalur -->
      <div v-else-if="screen === 'bpjs-choice'" class="screen bpjs-screen">
        <div class="bpjs-head">
          <h2 class="bpjs-title">Check-in BPJS / JKN</h2>
          <p class="bpjs-sub">Pilih cara pendaftaran Anda</p>
        </div>
        <div class="bpjs-choice-grid">
          <button class="bpjs-opt" @click="chooseBooking">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 21v.01M21 14v.01M14 21v.01"/></svg>
            <span class="bo-label">Punya Kode Booking</span>
            <span class="bo-desc">Sudah ambil antrean di Mobile JKN — scan / ketik kode booking</span>
          </button>
          <button class="bpjs-opt" @click="chooseOnsite">
            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
            <span class="bo-label">Ambil Antrean Baru</span>
            <span class="bo-desc">Belum punya antrean — masukkan NIK, no. rujukan, lalu pilih dokter</span>
          </button>
        </div>
        <div class="bpjs-actions one">
          <button class="ksk-btn sec" @click="resetHome">Kembali</button>
        </div>
      </div>

      <!-- BPJS BOOKING — kode booking (scan/ketik) -->
      <div v-else-if="screen === 'bpjs-booking'" class="screen bpjs-screen">
        <div class="bpjs-head">
          <h2 class="bpjs-title">Kode Booking</h2>
          <p class="bpjs-sub"><strong>Scan barcode</strong> tiket atau ketik kode booking / NIK / No. Kartu</p>
        </div>
        <input
          ref="bookingInputEl"
          v-model="bookingInput"
          class="np-input"
          inputmode="text"
          autocomplete="off"
          placeholder="Scan / ketik di sini…"
          @focus="focusedField = 'booking'"
          @keyup.enter="submitBooking"
        />
        <p v-if="umumError" class="np-error">{{ umumError }}</p>
        <div class="numpad">
          <button v-for="n in [1,2,3,4,5,6,7,8,9]" :key="n" class="np-key" @click="npAppend(n)">{{ n }}</button>
          <button class="np-key np-fn" @click="npClear">C</button>
          <button class="np-key" @click="npAppend(0)">0</button>
          <button class="np-key np-fn" @click="npBackspace" aria-label="Hapus">⌫</button>
        </div>
        <div class="bpjs-actions">
          <button class="ksk-btn sec" @click="backFromBpjs">Kembali</button>
          <button class="ksk-btn pri" :disabled="bookingInput.trim().length < 5" @click="submitBooking">Check-in</button>
        </div>
      </div>

      <!-- BPJS ONSITE — NIK + rujukan + pilih dokter -->
      <div v-else-if="screen === 'bpjs-onsite'" class="screen bpjs-screen wide">
        <div class="bpjs-head">
          <h2 class="bpjs-title">Ambil Antrean BPJS</h2>
          <p class="bpjs-sub">Lengkapi data berikut lalu pilih dokter tujuan</p>
        </div>

        <div class="onsite-fields">
          <label class="of-row">
            <span class="of-label">NIK / No. Kartu BPJS</span>
            <input
              ref="onsiteNikEl"
              v-model="onsiteNik"
              class="np-input sm"
              inputmode="numeric"
              autocomplete="off"
              placeholder="Scan / ketik NIK atau No. Kartu…"
              @focus="focusedField = 'nik'"
            />
          </label>
          <label class="of-row">
            <span class="of-label">No. Rujukan <em>(opsional)</em></span>
            <input
              v-model="onsiteRujukan"
              class="np-input sm"
              inputmode="text"
              autocomplete="off"
              placeholder="Scan barcode rujukan / ketik…"
              @focus="focusedField = 'rujukan'"
            />
          </label>
          <label class="of-row">
            <span class="of-label">No. HP <em>(wajib)</em></span>
            <input
              v-model="onsiteHp"
              class="np-input sm"
              inputmode="numeric"
              autocomplete="off"
              placeholder="08xxxxxxxxxx"
              @focus="focusedField = 'hp'"
            />
          </label>
        </div>

        <div class="doctor-pick">
          <span class="of-label">Pilih Dokter (praktek hari ini)</span>
          <div v-if="doctorsLoading" class="dp-empty">Memuat jadwal dokter…</div>
          <div v-else-if="doctors.length === 0" class="dp-empty">Belum ada dokter praktek hari ini.</div>
          <div v-else class="dp-list">
            <button
              v-for="d in doctors"
              :key="d.id"
              class="dp-item"
              :class="{ active: onsiteDoctor === d.id }"
              :disabled="d.sisa_jkn === 0"
              @click="onsiteDoctor = d.id"
            >
              <span class="dp-name">{{ d.nama_dokter }}</span>
              <span class="dp-meta">{{ d.poliklinik }}<template v-if="d.room"> · {{ d.room }}</template></span>
              <span class="dp-quota" :class="{ warn: d.hampir_penuh, full: d.sisa_jkn === 0 }">
                {{ d.sisa_jkn === 0 ? 'Penuh' : `Sisa JKN: ${d.sisa_jkn ?? '—'}` }}
              </span>
            </button>
          </div>
        </div>

        <p v-if="umumError" class="np-error">{{ umumError }}</p>
        <div class="bpjs-actions">
          <button class="ksk-btn sec" @click="backFromBpjs">Kembali</button>
          <button class="ksk-btn pri" :disabled="onsiteNik.trim().length < 5 || !onsiteDoctor" @click="submitOnsite">Ambil Antrean</button>
        </div>
      </div>

      <!-- BPJS FINGERPRINT — validasi sidik jari (FRISTA) sebelum SEP -->
      <div v-else-if="screen === 'bpjs-fingerprint'" class="screen bpjs-screen">
        <div class="bpjs-head">
          <h2 class="bpjs-title">Validasi Sidik Jari</h2>
          <p class="bpjs-sub">Poli ini wajib validasi sidik jari BPJS sebelum SEP diterbitkan</p>
        </div>

        <div class="fp-illu">
          <svg viewBox="0 0 24 24"><path d="M12 11c0 3-1 5-1 7M9 9a3 3 0 015-2M6 12c0-4 3-7 7-6M7 19c1-2 1-4 1-6a4 4 0 018 0M17 19v-7a5 5 0 00-5-5"/></svg>
        </div>

        <ol class="fp-steps">
          <li>Buka aplikasi <strong>FRISTA</strong> di komputer ini</li>
          <li>Tempelkan jari sampai <strong>berhasil tervalidasi</strong></li>
          <li>Kembali ke layar ini, tekan <strong>Lanjutkan</strong></li>
        </ol>

        <p v-if="umumError" class="np-error">{{ umumError }}</p>
        <p class="fp-foot">Nomor antrean Anda sudah aman: <strong>{{ pendingTicket?.qNum }}</strong></p>

        <div class="bpjs-actions">
          <button class="ksk-btn sec" @click="resetHome">Nanti / ke Admisi</button>
          <button class="ksk-btn pri" @click="lanjutFingerprint">Lanjutkan</button>
        </div>
      </div>

      <!-- BPJS LOADING -->
      <div v-else-if="screen === 'bpjs-loading'" class="screen center-screen">
        <div class="load-ring"></div>
        <h2 class="load-title">Memproses…</h2>
        <p class="load-sub">Menghubungkan ke BPJS…</p>
        <div class="load-dots"><span></span><span></span><span></span></div>
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
          <div class="ticket">
            <div class="tkt-header">
              <span class="tkt-clinic">RUMAH SAKIT MATA PRIMA VISION</span>
            </div>
            <div class="tkt-perf"></div>
            <div class="tkt-num">{{ ticket.qNum }}</div>
            <div class="tkt-sep-line"></div>
            <div class="tkt-body umum-body">
              <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <p v-if="ticket.type === 'bpjs'">
                <strong>{{ ticket.poli }}</strong>
                <template v-if="ticket.dokter"><br/>{{ ticket.dokter }}</template>
                <template v-if="ticket.nomorantrean"><br/>Antrean BPJS: <strong>{{ ticket.nomorantrean }}</strong></template>
              </p>
              <p v-else>Menuju <strong>Loket Admisi</strong><br/>untuk menyelesaikan pendaftaran</p>
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

          <!-- SEP (hanya tampil di layar, tidak dicetak) -->
          <div v-if="ticket.type === 'bpjs' && ticket.sep" class="sep-card">
            <div class="sep-head">
              <span class="sep-badge">SEP Terbit</span>
              <span class="sep-no">{{ ticket.sep.no_sep }}</span>
            </div>
            <div class="sep-rows">
              <div v-if="ticket.sep.peserta"><span>Peserta</span><strong>{{ ticket.sep.peserta }}</strong></div>
              <div v-if="ticket.sep.diagnosa"><span>Diagnosa</span><strong>{{ ticket.sep.diagnosa }}</strong></div>
              <div v-if="ticket.sep.kelas"><span>Kelas</span><strong>Kelas {{ ticket.sep.kelas }}</strong></div>
              <div v-if="ticket.sep.tgl_sep"><span>Tanggal</span><strong>{{ ticket.sep.tgl_sep }}</strong></div>
            </div>
            <p class="sep-note">SEP sudah terbit otomatis — langsung menuju ruang pemeriksaan, tidak perlu ke admisi.</p>
          </div>
          <div v-else-if="ticket.type === 'bpjs' && ticket.sepError" class="sep-card warn">
            <span class="sep-badge warn">Lanjut ke Admisi</span>
            <p class="sep-note">{{ ticket.sepError }}</p>
          </div>

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
            <button class="ksk-btn sec sm" @click="reprintTicket" title="Cetak ulang tiket">
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

  <!-- ─── PRINT-ONLY TICKET (Thermal 80mm) ─── -->
  <!-- Teleport ke <body> agar lepas TOTAL dari subtree .anjungan (#app).
       Saat print kita sembunyikan #app, node ini tetap tampil. Tidak pakai
       trik visibility/absolute (rapuh karena body min-width:1280px). -->
  <Teleport to="body">
    <div v-if="ticket" id="print-ticket" aria-hidden="true">
      <div class="pt-clinic">RUMAH SAKIT MATA PRIMA VISION</div>
      <div class="pt-sub">Medan · Anjungan Mandiri</div>
      <div class="pt-rule"></div>
      <div class="pt-label">NOMOR ANTREAN</div>
      <div class="pt-num">{{ ticket.qNum }}</div>
      <div class="pt-rule"></div>
      <template v-if="ticket.type === 'bpjs'">
        <div class="pt-dest"><strong>{{ ticket.poli }}</strong></div>
        <div v-if="ticket.dokter" class="pt-note">{{ ticket.dokter }}</div>
        <div v-if="ticket.nomorantrean" class="pt-note">Antrean BPJS: {{ ticket.nomorantrean }}</div>
      </template>
      <template v-else>
        <div class="pt-dest">Menuju <strong>{{ ticket.poli }}</strong></div>
        <div class="pt-note">untuk menyelesaikan pendaftaran</div>
      </template>
      <div class="pt-time">{{ dateStr }} · {{ clock }}</div>
    </div>
  </Teleport>
</template>

<style scoped>
/* ─── ROOT ─── */
.anjungan {
  /* Pin ke viewport (bukan 100vw/100vh yang memasukkan lebar scrollbar →
     gutter putih). position:fixed inset:0 mengisi layar kiosk persis. */
  position: fixed;
  inset: 0;
  display: flex;
  flex-direction: column;
  background: linear-gradient(155deg, var(--gd) 0%, var(--gm) 100%);
  color: #fff;
  font-family: 'Inter', sans-serif;
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
  font-family: 'Space Grotesk', serif;
  font-size: 22px;
  line-height: 1;
  letter-spacing: 0.01em;
}
.ksk-brand-sub {
  font-size: 11px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(56, 189, 248, 0.75);
  margin-top: 5px;
}
.ksk-clock-box { text-align: right; }
.ksk-clock {
  font-family: 'Space Grotesk', serif;
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
  background: var(--lm);
  animation: pulseDot 2s infinite;
}
.ksk-status.offline .dot { background: #ef4444; }
.ksk-status.offline { color: #fca5a5; }
@keyframes pulseDot {
  0% { box-shadow: 0 0 0 0 rgba(56, 189, 248, 0.55); }
  70% { box-shadow: 0 0 0 6px rgba(56, 189, 248, 0); }
  100% { box-shadow: 0 0 0 0 rgba(56, 189, 248, 0); }
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
  font-family: 'Space Grotesk', serif;
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
  font-family: 'Inter', sans-serif;
  color: #fff;
  text-align: center;
}
.choice-card:not(:disabled):hover {
  background: rgba(56, 189, 248, 0.13);
  border-color: var(--lm);
  transform: translateY(-4px);
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(56, 189, 248, 0.3);
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
  background: rgba(56, 189, 248, 0.18);
  color: var(--lm);
  border: 1px solid rgba(56, 189, 248, 0.38);
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
.home-foot-hint strong { color: rgba(56, 189, 248, 0.85); font-weight: 600; }

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
  border: 4px solid rgba(56, 189, 248, 0.2);
  border-top-color: var(--lm);
  animation: spin 0.85s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.load-title { font-family: 'Space Grotesk', serif; font-size: 34px; font-weight: 400; }
.load-sub { font-size: 16px; color: rgba(255, 255, 255, 0.45); }
.load-sub.err-msg { max-width: 420px; color: #fca5a5; }
.load-dots { display: flex; gap: 8px; }
.load-dots span {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgba(56, 189, 248, 0.5);
  animation: blink 1.4s infinite;
}
.load-dots span:nth-child(2) { animation-delay: 0.2s; }
.load-dots span:nth-child(3) { animation-delay: 0.4s; }
@keyframes blink {
  0%, 100% { opacity: 0.3; }
  50% { opacity: 1; }
}

/* ─── BPJS CHECK-IN SCREEN (numpad) ─── */
.bpjs-screen {
  max-width: 460px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 1.1rem;
}
.bpjs-head { text-align: center; }
.bpjs-title { font-family: 'Space Grotesk', serif; font-size: 34px; font-weight: 400; }
.bpjs-sub { font-size: 15px; color: rgba(255, 255, 255, 0.55); margin-top: 0.35rem; }
.bpjs-sub strong { color: var(--lm); font-weight: 600; }

.np-error { text-align: center; font-size: 13.5px; color: #fca5a5; margin-top: -0.4rem; }

.numpad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.7rem; }
.np-key {
  height: 64px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.07);
  border: 1.5px solid rgba(255, 255, 255, 0.14);
  color: #fff;
  font-family: 'Space Grotesk', serif;
  font-size: 26px;
  cursor: pointer;
  transition: all 0.12s;
}
.np-key:hover { background: rgba(56, 189, 248, 0.16); border-color: var(--lm); }
.np-key:active { transform: scale(0.96); }
.np-key.np-fn { font-size: 22px; color: rgba(255, 255, 255, 0.6); background: rgba(255, 255, 255, 0.03); }

/* Layar fingerprint (FRISTA) */
.fp-illu { display: flex; justify-content: center; }
.fp-illu svg { width: 72px; height: 72px; fill: none; stroke: var(--lm); stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.fp-steps {
  margin: 0 auto;
  max-width: 360px;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  padding-left: 1.4rem;
  color: rgba(255, 255, 255, 0.8);
  font-size: 15px;
  line-height: 1.5;
}
.fp-steps li { padding-left: 0.2rem; }
.fp-steps strong { color: #fff; font-weight: 600; }
.fp-foot { text-align: center; font-size: 13.5px; color: rgba(255, 255, 255, 0.5); }
.fp-foot strong { color: var(--lm); font-family: 'Space Grotesk', serif; }

.bpjs-actions { display: flex; gap: 0.8rem; margin-top: 0.3rem; }
.bpjs-actions.one { justify-content: center; }
.bpjs-actions .ksk-btn { height: 60px; }
.bpjs-screen.wide { max-width: 620px; }

/* Input teks (scanner barcode mengetik ke sini) */
.np-input {
  height: 64px;
  width: 100%;
  border-radius: 14px;
  background: rgba(0, 0, 0, 0.22);
  border: 1.5px solid rgba(255, 255, 255, 0.16);
  color: #fff;
  font-family: 'Space Grotesk', serif;
  font-size: 26px;
  letter-spacing: 0.04em;
  text-align: center;
  outline: none;
  transition: border-color 0.15s;
}
.np-input::placeholder { font-family: 'Inter', sans-serif; font-size: 16px; letter-spacing: normal; color: rgba(255, 255, 255, 0.3); }
.np-input:focus { border-color: var(--lm); }
.np-input.sm { height: 54px; font-size: 21px; }

/* Pilihan 2 jalur */
.bpjs-choice-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
.bpjs-opt {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  padding: 1.75rem 1.25rem;
  min-height: 200px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.07);
  border: 1.5px solid rgba(255, 255, 255, 0.14);
  color: #fff;
  cursor: pointer;
  text-align: center;
  transition: all 0.2s cubic-bezier(0.22, 1, 0.36, 1);
  font-family: 'Inter', sans-serif;
}
.bpjs-opt:hover { background: rgba(56, 189, 248, 0.13); border-color: var(--lm); transform: translateY(-3px); }
.bpjs-opt svg { width: 44px; height: 44px; fill: none; stroke: var(--lm); stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; margin-bottom: 0.35rem; }
.bo-label { font-size: 20px; font-weight: 600; }
.bo-desc { font-size: 13.5px; color: rgba(255, 255, 255, 0.5); line-height: 1.45; }

/* Form onsite */
.onsite-fields { display: flex; flex-direction: column; gap: 0.85rem; }
.of-row { display: flex; flex-direction: column; gap: 0.4rem; }
.of-label { font-size: 13px; font-weight: 600; color: rgba(255, 255, 255, 0.65); letter-spacing: 0.02em; }
.of-label em { font-style: normal; font-weight: 400; color: rgba(255, 255, 255, 0.4); }

.doctor-pick { display: flex; flex-direction: column; gap: 0.5rem; }
.dp-empty { font-size: 14px; color: rgba(255, 255, 255, 0.45); padding: 0.75rem; text-align: center; }
.dp-list { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; max-height: 210px; overflow-y: auto; padding-right: 2px; }
.dp-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 0.7rem 0.9rem;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.06);
  border: 1.5px solid rgba(255, 255, 255, 0.12);
  color: #fff;
  cursor: pointer;
  text-align: left;
  transition: all 0.15s;
  font-family: 'Inter', sans-serif;
}
.dp-item:hover:not(:disabled) { border-color: rgba(56, 189, 248, 0.5); }
.dp-item.active { background: rgba(56, 189, 248, 0.16); border-color: var(--lm); }
.dp-item:disabled { opacity: 0.4; cursor: not-allowed; }
.dp-name { font-size: 15px; font-weight: 600; }
.dp-meta { font-size: 12px; color: rgba(255, 255, 255, 0.5); }
.dp-quota { font-size: 11px; color: var(--lm); margin-top: 2px; }
.dp-quota.warn { color: #fbbf24; }
.dp-quota.full { color: #fca5a5; }

/* ─── BUTTONS ─── */
.ksk-btn {
  flex: 1;
  height: 68px;
  border-radius: 14px;
  font-family: 'Inter', sans-serif;
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
.ksk-btn.pri { background: var(--lm); border-color: var(--lm); color: #06182E; }
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
.tkt-clinic { font-family: 'Space Grotesk', serif; font-size: 14px; color: #fff; }
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
  background: repeating-linear-gradient(90deg, transparent, transparent 6px, #fff 6px, #fff 7px) #eef4f9;
}
.tkt-num {
  font-family: 'Space Grotesk', serif;
  font-size: 100px;
  color: var(--lm);
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
.umum-body strong { color: var(--td); }

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
  background: rgba(56, 189, 248, 0.18);
  border: 2px solid rgba(56, 189, 248, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
}
.ta-check svg { width: 24px; height: 24px; fill: none; stroke: var(--lm); stroke-width: 2.5; stroke-linecap: round; }
.ta-check.err { background: rgba(220, 38, 38, 0.18); border-color: rgba(220, 38, 38, 0.45); }
.ta-check.err svg { stroke: #fca5a5; }
.ta-msg { font-family: 'Space Grotesk', serif; font-size: 28px; font-weight: 400; line-height: 1.2; }
.ta-hint { font-size: 16px; color: rgba(255, 255, 255, 0.5); max-width: 280px; line-height: 1.5; margin-top: -0.5rem; }

/* Kartu SEP di layar tiket (tidak dicetak) */
.sep-card {
  width: 320px;
  background: rgba(56, 189, 248, 0.08);
  border: 1.5px solid rgba(56, 189, 248, 0.35);
  border-radius: 14px;
  padding: 0.9rem 1.1rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}
.sep-card.warn { background: rgba(251, 191, 36, 0.08); border-color: rgba(251, 191, 36, 0.4); }
.sep-head { display: flex; align-items: center; gap: 0.6rem; }
.sep-badge {
  font-size: 10px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
  padding: 3px 9px; border-radius: 20px;
  background: rgba(56, 189, 248, 0.2); color: var(--lm); border: 1px solid rgba(56, 189, 248, 0.4);
}
.sep-badge.warn { background: rgba(251, 191, 36, 0.18); color: #fbbf24; border-color: rgba(251, 191, 36, 0.4); }
.sep-no { font-family: 'Space Grotesk', serif; font-size: 18px; letter-spacing: 0.02em; color: #fff; }
.sep-rows { display: flex; flex-direction: column; gap: 0.3rem; }
.sep-rows > div { display: flex; justify-content: space-between; gap: 1rem; font-size: 13px; }
.sep-rows span { color: rgba(255, 255, 255, 0.45); }
.sep-rows strong { color: #fff; font-weight: 600; text-align: right; }
.sep-note { font-size: 12.5px; color: rgba(255, 255, 255, 0.55); line-height: 1.45; }

/* Countdown ring */
.cdown-wrap { display: flex; align-items: center; gap: 1rem; }
.cdown-ring { position: relative; width: 76px; height: 76px; flex-shrink: 0; }
.cdown-ring svg { width: 76px; height: 76px; }
.cdown-bg { fill: none; stroke: rgba(255, 255, 255, 0.08); stroke-width: 9; }
.cdown-fg {
  fill: none;
  stroke: var(--lm);
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
<!-- Tidak scoped — agar @page + reset body apply ke seluruh dokumen. -->
<style>
/* Node print disembunyikan total di layar; hanya muncul saat @media print. */
#print-ticket { display: none; }

@media print {
  @page {
    /* Rol thermal 80×297mm. Lebar penuh 80mm; tinggi `auto` agar tiket
       PENDEK/compact (hanya sepanjang konten) — bukan 297mm penuh yang boros
       feed kertas tiap cetak. Jika driver printer dipatok 80×297mm dan memaksa
       feed panjang, ganti `auto` → `297mm`. */
    size: 80mm auto;
    margin: 0;
  }

  /* Lepas semua constraint global yang merusak cetak thermal:
     base.css → body { min-width: 1280px } & html,body { height:100% }. */
  html, body {
    width: auto !important;
    min-width: 0 !important;
    height: auto !important;
    /* overflow:hidden (bukan visible) → cegah 1px overflow memicu halaman
       kedua kosong yang membuat TM-T82X memotong 2x & boros kertas. */
    overflow: hidden !important;
    background: #fff !important;
    margin: 0 !important;
    padding: 0 !important;
  }

  /* #print-ticket di-teleport ke <body> (sibling #app). Sembunyikan seluruh
     app, sisakan node print. */
  #app { display: none !important; }

  #print-ticket {
    display: block !important;
    width: 80mm;            /* lebar penuh rol 80mm */
    box-sizing: border-box;
    /* padding sisi 6mm → area teks efektif ~68mm, aman di dalam lebar cetak
       head TM-T82X (≈72mm/576 dot @203dpi) sehingga tepi tak terpotong.
       padding bawah 8mm → clearance auto-cutter (pisau memotong beberapa mm
       dari baris terakhir) agar timestamp tak mepet/terpotong saat di-cut. */
    padding: 2mm 6mm 8mm;
    background: #fff;
    color: #000;
    font-family: 'Inter', Arial, sans-serif;
    /* Bobot dasar lebih tebal: head thermal 203dpi membuat teks tipis +
       anti-alias jadi abu/pudar; weight 600 menjaga semua teks tetap pekat. */
    font-weight: 600;
    text-align: center;
    /* Jaga tiket utuh dalam 1 potongan — jangan terpecah antar halaman. */
    page-break-inside: avoid;
    break-inside: avoid;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  /* Naikkan teks terkecil ke ≥9pt + bobot tebal agar tak hilang saat dicetak
     thermal (8pt tipis = baris pertama yang pudar/putus). */
  #print-ticket .pt-clinic { font-size: 13pt; font-weight: 800; line-height: 1.2; }
  #print-ticket .pt-sub    { font-size: 9pt; font-weight: 600; margin-top: 1mm; letter-spacing: 0.04em; }
  #print-ticket .pt-rule   { border-top: 1px solid #000; margin: 2.5mm 0; }
  #print-ticket .pt-label  { font-size: 9pt; letter-spacing: 0.16em; font-weight: 700; }
  #print-ticket .pt-num    { font-size: 52pt; font-weight: 800; line-height: 1; margin: 1mm 0; }
  #print-ticket .pt-dest   { font-size: 11pt; font-weight: 600; }
  #print-ticket .pt-dest strong { font-weight: 800; }
  #print-ticket .pt-note   { font-size: 9pt; font-weight: 600; margin-top: 0.5mm; }
  #print-ticket .pt-time   { font-size: 9pt; font-weight: 600; margin-top: 3mm; }
}
</style>
