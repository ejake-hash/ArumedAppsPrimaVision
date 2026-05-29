<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { antreanTvApi } from '@/services/api'

const clock = ref('')
const dateStr = ref('')
const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu']
const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']

function updateClock() {
  const n = new Date()
  clock.value = [n.getHours(), n.getMinutes(), n.getSeconds()].map((x) => String(x).padStart(2, '0')).join(':')
  dateStr.value = `${days[n.getDay()]}, ${n.getDate()} ${months[n.getMonth()]} ${n.getFullYear()}`
}
updateClock()
let timer = null
let pollInterval = null
let pusher = null
let tvChannel = null
let midnightTimer = null

// Schedule reload halaman tepat jam 00:00:00 (tengah malam berikutnya).
// Tujuan: clear semua state hari sebelumnya (snapshot, call queue, dll) +
// pastikan fetch snapshot fresh untuk tanggal baru.
function scheduleMidnightReset() {
  if (midnightTimer) clearTimeout(midnightTimer)
  const now = new Date()
  const next = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 1, 0)
  const delay = next.getTime() - now.getTime()
  midnightTimer = setTimeout(() => {
    window.location.reload()
  }, delay)
}

// ─── Queue state (driven by backend) ────────────────────────────────────────
// TV menampilkan SEMUA stasiun secara grid (4x2). Saat ada event CALLED,
// nomor antrean masuk FIFO `callQueue` lalu diproses 1-per-1 (full-screen
// flash + TTS), dengan jeda `callDelay` detik antar panggilan.
const stationLabel = {
  ADMISI:       'Loket Admisi',
  TRIASE:       'Triase Perawat',
  REFRAKSIONIS: 'Refraksionis',
  DOKTER:       'Pemeriksaan Dokter',
  PENUNJANG:    'Penunjang',
  BEDAH:        'Bedah',
  KASIR:        'Kasir',
  FARMASI:      'Farmasi',
}
const stationOrder = ['ADMISI','TRIASE','REFRAKSIONIS','DOKTER','PENUNJANG','BEDAH','KASIR','FARMASI']

const snapshot = ref({})          // raw response per-station
const fetchError = ref(null)

// Nomor "sedang dipanggil" per stasiun — persist sampai backend mark COMPLETED
// atau ada panggilan baru di stasiun yang sama. Diisi dari snapshot saat fetch
// dan dari event WS saat status berubah ke CALLED.
const currentCalledByStation = ref({})  // { ADMISI: {num, name, poly}, ... }

// Display settings per-stasiun (TTS template, flash text, badge, toggle).
// Di-load dari backend saat mount; tab "Tampilan" di control panel bisa edit.
const displaySettings = ref({})   // { ADMISI: {tts_template, flash_label_top, ...}, ... }

async function fetchDisplaySettings() {
  try {
    const { data } = await antreanTvApi.displaySettings()
    displaySettings.value = data.data ?? {}
  } catch {
    displaySettings.value = {}
  }
}

// Load audio defaults dari backend dan apply ke state lokal. Dipanggil sekali
// saat mount — operator tidak perlu set ulang setiap hari.
async function fetchAudioDefaults() {
  try {
    const { data } = await antreanTvApi.audioSettings()
    const s = data.data ?? {}
    if (s.sound_preset)   soundPreset.value   = s.sound_preset
    if (typeof s.sound_volume === 'number')   soundVolume.value   = s.sound_volume
    if (typeof s.audio_enabled === 'boolean') audioEnabled.value  = s.audio_enabled
    if (typeof s.flash_duration === 'number') flashDuration.value = s.flash_duration
    if (typeof s.call_delay === 'number')     callDelay.value     = s.call_delay
    if (s.tts_voice_name !== undefined)       ttsVoiceName.value  = s.tts_voice_name ?? ''
    if (typeof s.tts_rate === 'number')       ttsRate.value       = s.tts_rate
  } catch {
    // Biarkan default in-code dipakai
  }
}

// ─── Branding (logo + nama klinik) ──────────────────────────────────────────
// Singleton di backend; dipakai di bar atas & panel placeholder (panel kiri).
// Logo disimpan sebagai data URL base64 (≤512 KB) supaya tidak perlu storage file.
const brandingDefaults = {
  logo_data:           null,
  clinic_name:         'Klinik Mata Arunika',
  clinic_subtitle:     'Cilegon · Layar Antrean',
  placeholder_title:   'Klinik Mata Arunika Cilegon',
  placeholder_tagline: 'Spesialis kesehatan mata terpadu — PMK No. 24/2022',
}
const branding = ref({ ...brandingDefaults })

async function fetchBrandingSettings() {
  try {
    const { data } = await antreanTvApi.brandingSettings()
    branding.value = { ...brandingDefaults, ...(data.data ?? {}) }
  } catch {
    branding.value = { ...brandingDefaults }
  }
}

// Editor (tab "Klinik"). Draft di-load saat tab dibuka, baru di-save ke backend.
const brandingDraft = ref(null)
const brandingSaveMsg = ref('')
const brandingSaveMsgType = ref('')   // 'ok' | 'err'
const MAX_LOGO_BYTES = 512 * 1024

function loadBrandingDraft() {
  brandingDraft.value = { ...brandingDefaults, ...branding.value }
}

function handleLogoFile(e) {
  const file = e.target.files?.[0]
  e.target.value = ''   // reset supaya file yang sama bisa dipilih ulang
  if (!file) return
  const okTypes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp']
  if (!okTypes.includes(file.type)) {
    brandingSaveMsg.value = 'Format logo harus PNG, JPG, SVG, atau WebP.'
    brandingSaveMsgType.value = 'err'
    return
  }
  if (file.size > MAX_LOGO_BYTES) {
    brandingSaveMsg.value = `Ukuran logo maksimal 512 KB (file ini ${(file.size / 1024).toFixed(0)} KB).`
    brandingSaveMsgType.value = 'err'
    return
  }
  const reader = new FileReader()
  reader.onload = () => {
    if (brandingDraft.value) brandingDraft.value.logo_data = reader.result
    brandingSaveMsg.value = ''
  }
  reader.onerror = () => {
    brandingSaveMsg.value = 'Gagal membaca file logo.'
    brandingSaveMsgType.value = 'err'
  }
  reader.readAsDataURL(file)
}

function removeLogo() {
  if (brandingDraft.value) brandingDraft.value.logo_data = null
}

// Reset ke default DAN langsung persist ke backend (berlaku semua TV).
// Mengisi ulang draft + branding live supaya operator bisa langsung tweak lalu
// Simpan kalau mau. Mirror resetDisplaySetting di tab "Tampilan".
async function resetBrandingToDefault() {
  brandingSaveMsg.value = ''
  try {
    const { data } = await antreanTvApi.resetBranding()
    const fresh = { ...brandingDefaults, ...(data.data ?? {}) }
    branding.value = fresh
    brandingDraft.value = { ...fresh }
    brandingSaveMsg.value = data.message ?? 'Dikembalikan ke default untuk semua TV.'
    brandingSaveMsgType.value = 'ok'
  } catch (err) {
    brandingSaveMsg.value = err.response?.data?.message ?? 'Gagal reset (perlu login).'
    brandingSaveMsgType.value = 'err'
  }
  setTimeout(() => { brandingSaveMsg.value = '' }, 4000)
}

async function saveBranding() {
  if (!brandingDraft.value) return
  brandingSaveMsg.value = ''
  try {
    const { data } = await antreanTvApi.updateBranding(brandingDraft.value)
    branding.value = { ...brandingDefaults, ...(data.data ?? {}) }
    brandingSaveMsg.value = data.message ?? 'Tersimpan untuk semua TV.'
    brandingSaveMsgType.value = 'ok'
  } catch (err) {
    brandingSaveMsg.value = err.response?.data?.message ?? 'Gagal menyimpan (perlu login).'
    brandingSaveMsgType.value = 'err'
  }
  setTimeout(() => { brandingSaveMsg.value = '' }, 4000)
}

// Resolve template variabel {nomor}, {nama}, {poli}, {stasiun} dari payload q.
function renderTemplate(tmpl, q) {
  if (!tmpl) return ''
  return String(tmpl)
    .replaceAll('{nomor}',   q.num ?? '')
    .replaceAll('{nama}',    q.name && q.name !== '—' ? q.name : '')
    .replaceAll('{poli}',    q.poly ?? '')
    .replaceAll('{stasiun}', stationLabel[q.station] ?? q.station ?? '')
    .replace(/\s+,/g, ',')   // bersihkan koma kembar saat {nama} kosong
    .replace(/\s{2,}/g, ' ')
    .trim()
}

function settingsFor(station) {
  return displaySettings.value[station] ?? {}
}

const flashSettings = computed(() => {
  if (!flashQueue.value) return {}
  return settingsFor(flashQueue.value.station)
})

const flashBadgeRendered = computed(() => {
  if (!flashQueue.value) return ''
  const tmpl = flashSettings.value.flash_badge_text || 'Silakan menuju {poli}'
  return renderTemplate(tmpl, flashQueue.value)
})

// ─── Active doctors hari ini (untuk panel TV) ───────────────────────────────
const activeDoctors = ref([])     // [{id, queue_prefix, nama_dokter, poliklinik, room, start_time, end_time}]
let doctorPollInterval = null

async function fetchActiveDoctors() {
  try {
    const { data } = await antreanTvApi.dokterAktif()
    activeDoctors.value = data.data ?? []
  } catch {
    activeDoctors.value = []
  }
}

// Map queue_prefix → schedule info (untuk antrean DOKTER: D1, D2, dst)
const doctorByPrefix = computed(() => {
  const m = {}
  for (const d of activeDoctors.value) {
    if (d.queue_prefix) m[d.queue_prefix] = d
  }
  return m
})

function prefixOf(queueNumber) {
  if (!queueNumber) return null
  // Format DOKTER: "D1-001" / "D2-001"; Format lain: "A-001", "T-001", "TR-001", dst.
  const m = String(queueNumber).match(/^([A-Z]+\d*)/)
  return m ? m[1] : null
}

function stationPoly(row, stationKey) {
  // DOKTER: selalu auto dari jadwal dokter aktif (override custom_poli_label
  // tidak berlaku — supaya info poli + ruangan tidak hilang).
  if (stationKey === 'DOKTER') {
    const prefix = prefixOf(row.queue_number)
    const doc    = prefix ? doctorByPrefix.value[prefix] : null
    if (doc) {
      // poliklinik bisa sudah berawalan "Poli" (mis. "Poli Glaukoma") —
      // jangan double-prefix. Untuk room (mis. "1"), tampilkan sebagai "Ruang 1".
      const poliRaw = (doc.poliklinik || '').trim()
      const poli    = poliRaw
        ? (/^poli\b/i.test(poliRaw) ? poliRaw : `Poli ${poliRaw}`)
        : 'Pemeriksaan Dokter'
      const room    = doc.room ? ` Ruang ${doc.room}` : ''
      return `${poli}${room}`
    }
  }
  // Stasiun lain: pakai custom_poli_label dari display settings kalau diisi,
  // fallback ke stationLabel default ("Bedah", "Farmasi", dst).
  const custom = displaySettings.value[stationKey]?.custom_poli_label
  if (custom && String(custom).trim() !== '') return custom
  return stationLabel[stationKey] ?? stationKey
}

function mapStationRow(row, stationKey) {
  return {
    id:     row.id,
    num:    row.queue_number,
    name:   row.patient_name ?? '—',
    poly:   stationPoly(row, stationKey),
    status: row.status === 'CALLED'      ? 'called'
          : row.status === 'COMPLETED'   ? 'done'
          : 'waiting',
    rawStatus: row.status,
    station:   stationKey,
    called_at: row.called_at ?? null,
  }
}

// Computed per-station untuk grid: { ADMISI: {rows, waiting, called}, ... }
const stationView = computed(() => {
  const out = {}
  for (const key of stationOrder) {
    const st = snapshot.value[key]
    const rows = (st?.rows ?? []).map((r) => mapStationRow(r, key))
    out[key] = {
      rows,
      waiting:    rows.filter((q) => q.status === 'waiting'),
      called:     currentCalledByStation.value[key] ?? null,
      totalCount: rows.length,
    }
  }
  return out
})

// ─── Fetch snapshot ─────────────────────────────────────────────────────────
function refreshCurrentCalled() {
  // Recompute `currentCalledByStation` dari snapshot setelah fetch ulang.
  // Ambil row dengan status=CALLED terbaru per stasiun (jika ada).
  const next = {}
  for (const key of stationOrder) {
    const st = snapshot.value[key]
    if (!st?.rows) continue
    const called = [...st.rows].reverse().find((r) => r.status === 'CALLED')
    if (called) {
      next[key] = {
        id:   called.id,
        num:  called.queue_number,
        name: called.patient_name ?? '—',
        poly: stationPoly(called, key),
      }
    }
  }
  currentCalledByStation.value = next
}

// Catatan called_at terakhir per queue ID, dipakai untuk detect transition
// CALLED baru saat polling. Setelah fetch, bandingkan dengan snapshot baru:
// kalau ada row CALLED dengan called_at berbeda (atau tidak ada catatan
// sebelumnya), trigger flash + suara.
const lastCalledAtById = new Map()
let isInitialFetch = true

async function fetchSnapshot() {
  try {
    const { data } = await antreanTvApi.snapshot()
    const newSnapshot = data.data ?? {}

    // Detect transition CALLED hanya setelah fetch awal (supaya pasien yang
    // memang sudah dipanggil sebelum TV dibuka tidak men-trigger flash massal).
    if (!isInitialFetch) {
      const newCalls = []
      for (const key of stationOrder) {
        const rows = newSnapshot[key]?.rows ?? []
        for (const r of rows) {
          if (r.status !== 'CALLED') continue
          const prev = lastCalledAtById.get(r.id)
          if (!prev || prev !== r.called_at) {
            newCalls.push({ row: r, station: key })
          }
        }
      }
      // Push ke FIFO queue sesuai urutan called_at (terlama duluan)
      newCalls.sort((a, b) => String(a.row.called_at ?? '').localeCompare(String(b.row.called_at ?? '')))
      for (const { row, station } of newCalls) {
        enqueueCall(mapStationRow(row, station))
      }
    }

    // Update catatan called_at per ID dari snapshot baru
    lastCalledAtById.clear()
    for (const key of stationOrder) {
      const rows = newSnapshot[key]?.rows ?? []
      for (const r of rows) {
        if (r.status === 'CALLED') lastCalledAtById.set(r.id, r.called_at ?? null)
      }
    }

    snapshot.value = newSnapshot
    fetchError.value = null
    refreshCurrentCalled()
    isInitialFetch = false
  } catch (err) {
    fetchError.value = err.response?.data?.message ?? 'Gagal memuat antrean'
  }
}

// ─── Reverb WebSocket subscription ──────────────────────────────────────────
// Channel `antrean-tv` adalah generic channel yang menerima update untuk
// SEMUA station (ADMISI/TRIASE/REFRAKSIONIS/DOKTER/PENUNJANG/BEDAH/KASIR/FARMASI).
// Backend fire AntreanTvUpdated dari QueueService::broadcastQueueUpdate +
// AdmisiService::ambilTiketUmumKiosk.
function connectWs() {
  const appKey = import.meta.env.VITE_REVERB_APP_KEY
  if (!appKey) {
    startPolling()
    return
  }

  import('pusher-js').then(({ default: Pusher }) => {
    pusher = new Pusher(appKey, {
      wsHost:            import.meta.env.VITE_REVERB_HOST ?? 'localhost',
      wsPort:            Number(import.meta.env.VITE_REVERB_PORT ?? 6001),
      wssPort:           Number(import.meta.env.VITE_REVERB_PORT ?? 6001),
      forceTLS:          (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
      enabledTransports: ['ws', 'wss'],
      disableStats:      true,
    })

    tvChannel = pusher.subscribe('antrean-tv')
    tvChannel.bind('queue-updated', handleQueueEvent)
    tvChannel.bind('media-updated', (payload) => applyMediaPayload(payload?.media))

    pusher.connection.bind('error', () => startPolling())
  }).catch(() => startPolling())
}

function handleQueueEvent(payload) {
  // payload: { action: 'added'|'updated', queue: {...} }
  const q = payload?.queue
  if (!q) return

  const station = q.station
  if (!snapshot.value[station]) snapshot.value[station] = { rows: [], waiting: 0, total: 0 }

  const rows = snapshot.value[station].rows ?? []
  const idx  = rows.findIndex((r) => r.id === q.id)

  // Detect CALLED untuk flash. Logic lama (status transition) bocor saat
  // polling fallback sudah membaca status=CALLED sebelum WS event sampai
  // (race condition), atau saat operator klik "panggil ulang" (status
  // sudah CALLED). Pakai delta `called_at`: kalau timestamp ini BEDA dari
  // catatan lokal terakhir untuk queue ini, anggap panggilan baru.
  const prev = idx !== -1 ? rows[idx] : null
  const becameCalled = q.status === 'CALLED' && (
    !prev ||
    prev.status !== 'CALLED' ||
    (q.called_at && q.called_at !== prev.called_at)
  )

  const normalized = {
    id:             q.id,
    queue_number:   q.queue_number,
    queue_sequence: q.queue_sequence,
    status:         q.status,
    visit_id:       q.visit_id,
    patient_name:   q.patient?.name ?? q.patient_name ?? '—',
    no_rm:          q.patient?.no_rm ?? q.no_rm ?? null,
    called_at:      q.called_at ?? null,
  }

  if (idx === -1) {
    rows.push(normalized)
  } else {
    rows[idx] = { ...rows[idx], ...normalized }
  }
  rows.sort((a, b) => (a.queue_sequence ?? 0) - (b.queue_sequence ?? 0))
  snapshot.value = { ...snapshot.value, [station]: { ...snapshot.value[station], rows } }

  // Maintain currentCalledByStation: set saat CALLED, clear saat COMPLETED/CANCELLED.
  if (q.status === 'CALLED') {
    currentCalledByStation.value = {
      ...currentCalledByStation.value,
      [station]: {
        id:   normalized.id,
        num:  normalized.queue_number,
        name: normalized.patient_name,
        poly: stationPoly(normalized, station),
      },
    }
  } else if ((q.status === 'COMPLETED' || q.status === 'CANCELLED')
             && currentCalledByStation.value[station]?.id === normalized.id) {
    const next = { ...currentCalledByStation.value }
    delete next[station]
    currentCalledByStation.value = next
  }

  if (becameCalled) {
    enqueueCall(mapStationRow(normalized, station))
  }
}

function startPolling(intervalMs = 3_000) {
  // Polling fallback saat WS Reverb tidak tersedia. Interval kecil supaya
  // panggilan dari stasiun lain ter-detect cepat (max 3 detik delay).
  // fetchSnapshot membandingkan called_at lama vs baru untuk trigger flash.
  stopPolling()
  pollInterval = setInterval(fetchSnapshot, intervalMs)
}

function stopPolling() {
  if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
}

function disconnectWs() {
  tvChannel?.unbind_all()
  pusher?.disconnect()
  pusher = null
  tvChannel = null
  stopPolling()
}

onMounted(async () => {
  timer = setInterval(updateClock, 1000)
  await Promise.all([fetchSnapshot(), fetchActiveDoctors(), fetchDisplaySettings(), fetchAudioDefaults(), fetchBrandingSettings(), fetchMediaSettings()])
  connectWs()
  // Refresh dokter aktif tiap 2 menit (toggle aktif/non-aktif dari menu dokter)
  doctorPollInterval = setInterval(fetchActiveDoctors, 120_000)
  // TTS voices: load awal + listen perubahan (Chrome lazy-load voice list)
  loadTtsVoices()
  if (window.speechSynthesis) {
    window.speechSynthesis.onvoiceschanged = loadTtsVoices
  }
  // Auto-reload tengah malam — reset state & fetch snapshot tanggal baru
  scheduleMidnightReset()
  // Audio unlock listener — auto-aktif saat user pertama interact
  installUnlockListeners()
})

onUnmounted(() => {
  clearInterval(timer)
  if (doctorPollInterval) clearInterval(doctorPollInterval)
  disconnectWs()
  stopSlideshow()
  window.speechSynthesis?.cancel()
  if (midnightTimer) clearTimeout(midnightTimer)
  removeUnlockListeners()
  if (localVideoObjectUrl) URL.revokeObjectURL(localVideoObjectUrl)
})

// --- TICKER (reactive) ---
const tickerMessages = ref([
  'Pendaftaran dibuka pukul 07.00 WIB',
  'Harap siapkan kartu BPJS, KTP, dan rujukan asli',
  'Layanan Bedah Phaco buka Senin–Sabtu',
  'Untuk pertanyaan hubungi loket informasi',
])
const tickerDuration = computed(() => `${tickerMessages.value.length * 8}s`)

// --- PIN GATE ---
const showPinModal = ref(false)
const pinDigits = ref(['', '', '', ''])
const pinError = ref(false)
const pinShake = ref(false)
const controlPin = ref('1234')
const pinRefs = ref([])

function openPinModal() {
  pinDigits.value = ['', '', '', '']
  pinError.value = false
  pinShake.value = false
  showPinModal.value = true
  nextTick(() => pinRefs.value[0]?.focus())
}

function onPinInput(idx, e) {
  const val = e.target.value.replace(/\D/g, '')
  pinDigits.value[idx] = val.slice(-1)
  if (val && idx < 3) {
    nextTick(() => pinRefs.value[idx + 1]?.focus())
  }
  if (pinDigits.value.every(d => d !== '')) {
    submitPin()
  }
}

function onPinKeydown(idx, e) {
  if (e.key === 'Backspace' && !pinDigits.value[idx] && idx > 0) {
    nextTick(() => pinRefs.value[idx - 1]?.focus())
  }
}

function submitPin() {
  const entered = pinDigits.value.join('')
  if (entered === controlPin.value) {
    showPinModal.value = false
    showControl.value = true
    activeTab.value = 'media'
  } else {
    pinError.value = true
    pinShake.value = true
    pinDigits.value = ['', '', '', '']
    setTimeout(() => { pinShake.value = false }, 600)
    nextTick(() => pinRefs.value[0]?.focus())
  }
}

// --- CONTROL PANEL ---
const showControl = ref(false)
const activeTab = ref('media')

// --- MEDIA (backend singleton — sync ke semua TV via TvMediaUpdated event) ---
// Semua perubahan: PUT/POST/DELETE ke /antrean-tv/media-settings → backend
// broadcast → applyMediaPayload() apply ke state lokal (termasuk TV yg
// melakukan perubahan, via toOthers exclude — kita handle juga dari respons
// supaya UI feedback instan).
const mediaMode = ref('placeholder')
const youtubeDraft = ref('')
const youtubeEmbedUrl = ref('')
const youtubeError = ref('')
const mediaSaveMsg = ref('')
const mediaSaveMsgType = ref('')
const mediaUploading = ref(false)
const mediaUploadPct = ref(0)
const mediaUploadInfo = ref('') // mis. "120 MB / 500 MB"
let mediaUploadAbort = null
const localVideoUrl = ref('')
const localVideoName = ref('')
const externalVideoUrl = ref('')   // URL eksternal aktif (dari backend)
const externalVideoDraft = ref('') // input draft di panel
const hasUploadedFile = ref(false)
const videoLoop = ref(true)
const videoAutoplay = ref(true)

function showMediaMsg(msg, type) {
  mediaSaveMsg.value = msg
  mediaSaveMsgType.value = type
  setTimeout(() => { mediaSaveMsg.value = '' }, 4000)
}

// Apply payload dari backend (fetch awal atau broadcast TvMediaUpdated)
function applyMediaPayload(s) {
  if (!s) return
  if (s.media_mode) mediaMode.value = s.media_mode
  youtubeEmbedUrl.value = s.youtube_embed_url ?? ''
  if (typeof s.video_autoplay === 'boolean') videoAutoplay.value = s.video_autoplay
  if (typeof s.video_loop === 'boolean')     videoLoop.value     = s.video_loop
  localVideoUrl.value  = s.local_video_url ?? ''
  localVideoName.value = s.local_video_name ?? ''
  externalVideoUrl.value = s.external_video_url ?? ''
  hasUploadedFile.value  = !!s.has_uploaded_file
  if (Array.isArray(s.slides)) slides.value = s.slides
  if (typeof s.slide_interval === 'number') slideDuration.value = s.slide_interval

  // Restart slideshow timer kalau mode aktif slideshow
  stopSlideshow()
  if (mediaMode.value === 'slideshow' && slides.value.length > 1) startSlideshow()
}

async function fetchMediaSettings() {
  try {
    const { data } = await antreanTvApi.mediaSettings()
    applyMediaPayload(data.data)
  } catch {
    // Biarkan default in-code
  }
}

function buildYoutubeUrl(id, opts) {
  const loop = opts.loop ? `&loop=1&playlist=${id}` : ''
  const auto = opts.autoplay ? '&autoplay=1' : ''
  return `https://www.youtube.com/embed/${id}?mute=1${auto}${loop}`
}

async function applyYoutube() {
  youtubeError.value = ''
  const url = youtubeDraft.value.trim()
  let id = ''
  const patterns = [
    /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([A-Za-z0-9_-]{11})/,
    /^([A-Za-z0-9_-]{11})$/,
  ]
  for (const p of patterns) {
    const m = url.match(p)
    if (m) { id = m[1]; break }
  }
  if (!id) { youtubeError.value = 'URL YouTube tidak valid'; return }
  const embed = buildYoutubeUrl(id, { autoplay: videoAutoplay.value, loop: videoLoop.value })
  try {
    const { data } = await antreanTvApi.updateMedia({
      media_mode:        'youtube',
      youtube_embed_url: embed,
      video_autoplay:    videoAutoplay.value,
      video_loop:        videoLoop.value,
    })
    applyMediaPayload(data.data)
    showMediaMsg('YouTube disiarkan ke TV.', 'ok')
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan (perlu login)', 'err')
  }
}

// Re-sync video options (autoplay/loop) — kalau lagi mode youtube,
// rebuild embed URL dengan opsi baru.
async function syncVideoOptions() {
  const payload = {
    video_autoplay: videoAutoplay.value,
    video_loop:     videoLoop.value,
  }
  if (mediaMode.value === 'youtube' && youtubeEmbedUrl.value) {
    const m = youtubeEmbedUrl.value.match(/\/embed\/([A-Za-z0-9_-]{11})/)
    if (m) payload.youtube_embed_url = buildYoutubeUrl(m[1], { autoplay: videoAutoplay.value, loop: videoLoop.value })
  }
  try {
    const { data } = await antreanTvApi.updateMedia(payload)
    applyMediaPayload(data.data)
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan (perlu login)', 'err')
  }
}

function fmtBytes(n) {
  if (!n && n !== 0) return ''
  const mb = n / (1024 * 1024)
  return mb >= 1 ? `${mb.toFixed(1)} MB` : `${(n / 1024).toFixed(0)} KB`
}

async function handleVideoFile(e) {
  const file = e.target.files[0]
  if (!file) return
  // Pre-check ukuran (500 MB) supaya gagal cepat sebelum upload.
  const MAX_BYTES = 500 * 1024 * 1024
  if (file.size > MAX_BYTES) {
    showMediaMsg(`File terlalu besar (${fmtBytes(file.size)}). Maks 500 MB.`, 'err')
    e.target.value = ''
    return
  }
  const fd = new FormData()
  fd.append('video', file)
  mediaUploading.value = true
  mediaUploadPct.value = 0
  mediaUploadInfo.value = `0 / ${fmtBytes(file.size)}`
  mediaUploadAbort = new AbortController()
  try {
    const { data } = await antreanTvApi.uploadMediaVideo(fd, (ev) => {
      if (!ev.total) return
      mediaUploadPct.value = Math.round((ev.loaded / ev.total) * 100)
      mediaUploadInfo.value = `${fmtBytes(ev.loaded)} / ${fmtBytes(ev.total)}`
    }, mediaUploadAbort.signal)
    applyMediaPayload(data.data)
    showMediaMsg('Video diupload dan disiarkan ke TV.', 'ok')
  } catch (err) {
    // Axios membatalkan dengan CanceledError saat AbortController.abort()
    if (err?.code === 'ERR_CANCELED' || err?.name === 'CanceledError') {
      showMediaMsg('Upload dibatalkan.', 'err')
    } else {
      showMediaMsg(err.response?.data?.message ?? 'Gagal upload (perlu login / file > 500MB / koneksi terputus)', 'err')
    }
  } finally {
    mediaUploading.value = false
    mediaUploadPct.value = 0
    mediaUploadInfo.value = ''
    mediaUploadAbort = null
    e.target.value = '' // reset input agar bisa pilih file yang sama lagi
  }
}

function cancelUpload() {
  if (mediaUploadAbort) mediaUploadAbort.abort()
}

async function deleteLocalVideo() {
  if (!confirm('Hapus video lokal? TV akan kembali ke placeholder.')) return
  try {
    const { data } = await antreanTvApi.deleteMediaVideo()
    applyMediaPayload(data.data)
    showMediaMsg('Video lokal dihapus.', 'ok')
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menghapus (perlu login)', 'err')
  }
}

async function applyExternalVideoUrl() {
  const url = externalVideoDraft.value.trim()
  if (!url) { showMediaMsg('Paste URL video MP4 dulu', 'err'); return }
  if (!/^https?:\/\//i.test(url)) { showMediaMsg('URL harus dimulai http:// atau https://', 'err'); return }
  try {
    const { data } = await antreanTvApi.updateMedia({
      external_video_url: url,
      media_mode:         'localvideo',
    })
    applyMediaPayload(data.data)
    externalVideoDraft.value = ''
    showMediaMsg('URL video disiarkan ke TV.', 'ok')
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan URL (perlu login / URL invalid)', 'err')
  }
}

async function clearExternalVideoUrl() {
  try {
    const { data } = await antreanTvApi.updateMedia({
      external_video_url: null,
      // Kalau tidak ada file upload sebagai fallback, balik ke placeholder.
      media_mode: hasUploadedFile.value ? 'localvideo' : 'placeholder',
    })
    applyMediaPayload(data.data)
    showMediaMsg('URL video dihapus.', 'ok')
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menghapus URL', 'err')
  }
}

async function setMediaMode(mode) {
  // Untuk localvideo, hanya bisa kalau sudah ada file upload.
  if (mode === 'localvideo' && !localVideoUrl.value) {
    showMediaMsg('Upload video lokal dulu via tombol "Pilih file video".', 'err')
    return
  }
  try {
    const { data } = await antreanTvApi.updateMedia({ media_mode: mode })
    applyMediaPayload(data.data)
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan (perlu login)', 'err')
  }
}

// --- FLASH OVERLAY + FIFO CALL QUEUE ---------------------------------------
// Setiap event CALLED masuk ke `callQueue`. Worker `processCallQueue` ambil
// satu, tampilkan flash full-screen, putar chime, lalu TTS bahasa Indonesia.
// Setelah TTS selesai (atau timeout fallback), tunggu `callDelay` detik
// sebelum proses panggilan berikutnya.
const flashVisible = ref(false)
const flashQueue = ref(null)
const callDelay = ref(7)          // detik antara panggilan (5-10)
const flashDuration = ref(5)      // durasi minimum flash full-screen (detik, 3-10)
const callQueue = ref([])         // FIFO antrian panggilan
const isProcessingCall = ref(false)
const audioEnabled = ref(true)    // bisa di-mute via control panel

// Sound preset registry. Dua jenis:
// - oscillator-based: di-generate Web Audio API (no file)
// - mp3-based:        load file dari /sounds/ via HTMLAudioElement
// Property `mp3` & `duration` (estimasi ms) hanya untuk jenis mp3.
const soundPresets = {
  // Oscillator presets
  chime:    { label: 'Chime',          desc: '2 nada bell lembut'       },
  dingdong: { label: 'Ding-dong',      desc: '2 nada turun, bel pintu'  },
  triple:   { label: 'Bell Triple',    desc: '3 nada cepat ringan'      },
  beep:     { label: 'Notification',   desc: 'Beep elektronik tegas'    },
  softpad:  { label: 'Soft Pad',       desc: '3 nada harmonis lembut'   },
  hospital: { label: 'Hospital Ping',  desc: 'Nada panjang dengan echo' },
  // MP3 presets — suara realistis ala bandara/stasiun (royalty-free CC-BY 4.0
  // dari orangefreesounds.com)
  airport:    { label: 'Bandara',        desc: 'Ding khas bandara', mp3: '/sounds/airport-chime.mp3', duration: 2200 },
  train:      { label: 'Kereta / Metro', desc: 'Ding-dong stasiun', mp3: '/sounds/train-chime.mp3',   duration: 2400 },
  publicBell: { label: 'Bel Umum',       desc: 'Bel pengumuman publik', mp3: '/sounds/public-bell.mp3', duration: 2000 },
  announce:   { label: 'Pengumuman',     desc: 'Chime pengumuman formal', mp3: '/sounds/announcement.mp3', duration: 2500 },
  softMp3:    { label: 'Soft Chime',     desc: 'Chime lembut natural', mp3: '/sounds/soft-chime.mp3', duration: 3500 },
}
const soundPreset = ref('chime')
const soundVolume = ref(0.45)     // 0..1

// TTS voice picker — voices load async di sebagian browser
const ttsVoices = ref([])
const ttsVoiceName = ref('')      // empty = auto pilih id-ID pertama
const ttsRate = ref(0.95)

function loadTtsVoices() {
  if (!window.speechSynthesis) return
  const all = window.speechSynthesis.getVoices()
  // Prioritaskan voice id-* di depan, tapi tetap tampilkan semua supaya
  // operator bisa pilih voice lain (mis. en-US sebagai fallback).
  ttsVoices.value = [...all].sort((a, b) => {
    const ai = a.lang?.toLowerCase().startsWith('id') ? 0 : 1
    const bi = b.lang?.toLowerCase().startsWith('id') ? 0 : 1
    return ai - bi
  })
}

function enqueueCall(q) {
  // Dedup hanya kalau ID + called_at SAMA dengan yang sedang tampil/antri
  // (mencegah event WS duplikat). Panggil ulang (called_at baru) tetap
  // diizinkan masuk antrian.
  const key = `${q.id}:${q.called_at ?? ''}`
  const flashKey = flashQueue.value ? `${flashQueue.value.id}:${flashQueue.value.called_at ?? ''}` : null
  if (flashKey === key) return
  if (callQueue.value.some((x) => `${x.id}:${x.called_at ?? ''}` === key)) return
  callQueue.value.push(q)
  if (!isProcessingCall.value) processCallQueue()
}

async function processCallQueue() {
  if (isProcessingCall.value) return
  isProcessingCall.value = true
  while (callQueue.value.length > 0) {
    const q = callQueue.value.shift()
    await playCallAnnouncement(q)
    await sleep(callDelay.value * 1000)
  }
  isProcessingCall.value = false
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function playCallAnnouncement(q) {
  flashQueue.value = q
  flashVisible.value = true
  const startedAt = Date.now()

  // Jalankan audio (sound + TTS) sebagai task paralel, JANGAN gate durasi
  // flash pada selesainya — kalau TTS skip cepat (voice tidak tersedia atau
  // audio belum unlock), flash akan kelihatan sangat singkat. Sebagai
  // gantinya, kita garansi durasi flash minimum `flashDuration` detik.
  const audioTask = audioEnabled.value
    ? (async () => {
        const dur = playSound(soundPreset.value)
        await sleep(dur + 200)
        await speakAnnouncement(q)
      })()
    : Promise.resolve()

  // Tunggu mana yang lebih lama: audio selesai ATAU durasi minimum tercapai.
  const minMs = flashDuration.value * 1000
  await Promise.all([
    audioTask,
    (async () => {
      const elapsed = Date.now() - startedAt
      if (elapsed < minMs) await sleep(minMs - elapsed)
    })(),
  ])
  // Pastikan benar-benar mencapai minimum (kalau audio lebih cepat dari awal)
  const elapsedFinal = Date.now() - startedAt
  if (elapsedFinal < minMs) await sleep(minMs - elapsedFinal)

  flashVisible.value = false
  await sleep(400)  // beri jeda fade-out sebelum next
}

// Browser block audio sampai user gesture pertama. TV view tidak punya
// operator tetap, jadi kita pasang listener global SEKALI untuk semua jenis
// interaksi (click/touch/keydown). Begitu user pertama interact, audio
// auto-unlock tanpa UI. Sampai itu terjadi, panggilan tetap masuk antrean &
// flash visual tetap muncul — hanya audio yang tertahan.
const audioUnlocked = ref(false)

async function unlockAudio() {
  if (audioUnlocked.value) return
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)()
    if (audioCtx.state === 'suspended') await audioCtx.resume()
  } catch (_) { /* ignore */ }
  try {
    const synth = window.speechSynthesis
    if (synth) {
      const u = new SpeechSynthesisUtterance(' ')
      u.volume = 0
      synth.speak(u)
    }
  } catch (_) { /* ignore */ }
  audioUnlocked.value = true
  removeUnlockListeners()
}

function installUnlockListeners() {
  const events = ['pointerdown', 'touchstart', 'keydown']
  events.forEach((ev) => window.addEventListener(ev, unlockAudio, { once: false, passive: true }))
}
function removeUnlockListeners() {
  const events = ['pointerdown', 'touchstart', 'keydown']
  events.forEach((ev) => window.removeEventListener(ev, unlockAudio))
}

// Sound presets: oscillator-based di-generate Web Audio API, MP3-based load
// dari /public/sounds/. Setiap call return durasi (ms) supaya
// playCallAnnouncement bisa tunggu sebelum TTS mulai.
let audioCtx = null
// Cache HTMLAudioElement per preset MP3 supaya tidak fetch ulang tiap panggil.
const audioCache = new Map()

function playSound(preset) {
  const meta = soundPresets[preset]
  // MP3-based preset
  if (meta?.mp3) return playMp3(meta)
  // Oscillator-based preset
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)()
    if (audioCtx.state === 'suspended') audioCtx.resume()
    const ctx = audioCtx
    const v = soundVolume.value
    switch (preset) {
      case 'dingdong': return playDingDong(ctx, v)
      case 'triple':   return playTriple(ctx, v)
      case 'beep':     return playBeep(ctx, v)
      case 'softpad':  return playSoftPad(ctx, v)
      case 'hospital': return playHospitalPing(ctx, v)
      case 'chime':
      default:         return playChimeSound(ctx, v)
    }
  } catch (_) {
    return 0   // AudioContext diblokir; skip tanpa error
  }
}

function playMp3(meta) {
  try {
    let audio = audioCache.get(meta.mp3)
    if (!audio) {
      audio = new Audio(meta.mp3)
      audio.preload = 'auto'
      audioCache.set(meta.mp3, audio)
    }
    audio.volume = Math.max(0, Math.min(1, soundVolume.value))
    audio.currentTime = 0
    const p = audio.play()
    // Chromium throw promise rejection kalau autoplay diblokir; tangkap diam-diam.
    if (p && typeof p.catch === 'function') p.catch(() => {})
    return meta.duration ?? 2500
  } catch (_) {
    return 0
  }
}

function tone(ctx, { freq, start, dur, type = 'sine', vol = 0.4, attack = 0.05, release = null }) {
  const osc  = ctx.createOscillator()
  const gain = ctx.createGain()
  osc.type   = type
  osc.frequency.value = freq
  osc.connect(gain).connect(ctx.destination)
  const t0 = ctx.currentTime + start
  const rel = release ?? dur * 0.85
  gain.gain.setValueAtTime(0.0001, t0)
  gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, vol), t0 + attack)
  gain.gain.exponentialRampToValueAtTime(0.0001, t0 + rel)
  osc.start(t0)
  osc.stop(t0 + dur)
}

function playChimeSound(ctx, v) {
  // 2 nada A5 + E6, naik, bell lembut
  tone(ctx, { freq: 880,  start: 0,    dur: 0.5, vol: v })
  tone(ctx, { freq: 1320, start: 0.25, dur: 0.5, vol: v })
  return 800
}

function playDingDong(ctx, v) {
  // 2 nada turun (E6 → C5), seperti bel pintu
  tone(ctx, { freq: 1320, start: 0,    dur: 0.6, vol: v })
  tone(ctx, { freq: 660,  start: 0.35, dur: 0.7, vol: v })
  return 1100
}

function playTriple(ctx, v) {
  // 3 nada cepat A5-C6-E6
  tone(ctx, { freq: 880,  start: 0,    dur: 0.22, vol: v })
  tone(ctx, { freq: 1047, start: 0.15, dur: 0.22, vol: v })
  tone(ctx, { freq: 1320, start: 0.30, dur: 0.32, vol: v })
  return 700
}

function playBeep(ctx, v) {
  // 2 nada square wave tegas
  tone(ctx, { freq: 1000, start: 0,    dur: 0.18, vol: v * 0.7, type: 'square', attack: 0.005 })
  tone(ctx, { freq: 1500, start: 0.22, dur: 0.18, vol: v * 0.7, type: 'square', attack: 0.005 })
  return 500
}

function playSoftPad(ctx, v) {
  // 3 nada harmonis bersamaan (chord C major: C5, E5, G5), fade in/out
  tone(ctx, { freq: 523, start: 0, dur: 1.4, vol: v * 0.6, attack: 0.4, release: 1.2 })
  tone(ctx, { freq: 659, start: 0, dur: 1.4, vol: v * 0.5, attack: 0.4, release: 1.2 })
  tone(ctx, { freq: 784, start: 0, dur: 1.4, vol: v * 0.5, attack: 0.4, release: 1.2 })
  return 1500
}

function playHospitalPing(ctx, v) {
  // Nada panjang dengan echo (delay) — feel rumah sakit
  const osc  = ctx.createOscillator()
  const gain = ctx.createGain()
  const delay = ctx.createDelay()
  const feedback = ctx.createGain()
  delay.delayTime.value = 0.22
  feedback.gain.value = 0.35
  osc.type = 'sine'
  osc.frequency.value = 988    // B5
  osc.connect(gain)
  gain.connect(ctx.destination)
  gain.connect(delay)
  delay.connect(feedback)
  feedback.connect(delay)
  delay.connect(ctx.destination)
  const t0 = ctx.currentTime
  gain.gain.setValueAtTime(0.0001, t0)
  gain.gain.exponentialRampToValueAtTime(v, t0 + 0.04)
  gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.5)
  osc.start(t0)
  osc.stop(t0 + 0.6)
  return 1300   // termasuk waktu echo fade
}

// TTS bahasa Indonesia via Web Speech API.
function speakAnnouncement(q) {
  return new Promise((resolve) => {
    try {
      const synth = window.speechSynthesis
      if (!synth) { resolve(); return }
      synth.cancel()
      // Allow override (dipakai oleh preview di tab Tampilan)
      const text = q._ttsText ?? buildAnnouncementText(q)
      const utter = new SpeechSynthesisUtterance(text)
      utter.lang = 'id-ID'
      utter.rate = ttsRate.value
      utter.pitch = 1.0
      // Voice: pakai pilihan user kalau ada, fallback ke id-ID pertama.
      const voices = synth.getVoices()
      let chosen = null
      if (ttsVoiceName.value) chosen = voices.find((v) => v.name === ttsVoiceName.value)
      if (!chosen) chosen = voices.find((v) => v.lang?.toLowerCase().startsWith('id'))
      if (chosen) utter.voice = chosen
      utter.onend   = () => resolve()
      utter.onerror = () => resolve()
      synth.speak(utter)
      // Fallback: TTS kadang stuck di browser tertentu, force resolve setelah 10s.
      setTimeout(resolve, 10_000)
    } catch (_) {
      resolve()
    }
  })
}

function buildAnnouncementText(q) {
  // Ambil template dari displaySettings; transformasi nomor dulu supaya
  // SpeechSynthesis Indonesia tidak baca "A001" sebagai "A nol nol satu".
  const settings = settingsFor(q.station)
  const speakable = {
    ...q,
    num:  String(q.num ?? '').replace('-', ' nomor '),
    // Sembunyikan {nama} dari TTS jika read_name_in_tts=false
    name: settings.read_name_in_tts === false ? '' : q.name,
  }
  const tmpl = settings.tts_template
            || 'Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}.'
  return renderTemplate(tmpl, speakable)
}

// --- SLIDESHOW ---
const slides = ref([])
const slidesDraft = ref('')
const slideIndex = ref(0)
const slideDuration = ref(5)
let slideTimer = null

async function persistSlides(nextSlides, opts = {}) {
  const payload = { slides: nextSlides }
  if (opts.mode) payload.media_mode = opts.mode
  if (opts.interval !== undefined) payload.slide_interval = opts.interval
  try {
    const { data } = await antreanTvApi.updateMedia(payload)
    applyMediaPayload(data.data)
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan (perlu login)', 'err')
  }
}

async function addSlides() {
  const urls = slidesDraft.value.split('\n').map(u => u.trim()).filter(u => u)
  if (!urls.length) return
  const next = [...slides.value, ...urls.map(url => ({ url }))]
  slidesDraft.value = ''
  await persistSlides(next)
}

async function removeSlide(idx) {
  const next = slides.value.filter((_, i) => i !== idx)
  if (slideIndex.value >= next.length) slideIndex.value = Math.max(0, next.length - 1)
  const opts = (next.length === 0 && mediaMode.value === 'slideshow')
    ? { mode: 'placeholder' }
    : {}
  await persistSlides(next, opts)
}

function startSlideshow() {
  stopSlideshow()
  if (slides.value.length < 2) return
  slideTimer = setInterval(() => {
    slideIndex.value = (slideIndex.value + 1) % slides.value.length
  }, slideDuration.value * 1000)
}

function stopSlideshow() {
  if (slideTimer) { clearInterval(slideTimer); slideTimer = null }
}

// slideDuration kontrol di tab slideshow — disimpan ke backend saat user
// adjust. Lokal effect (restart timer) di-handle di applyMediaPayload yang
// ter-trigger oleh respons updateMedia/broadcast.
let slideIntervalDebounce = null
watch(slideDuration, (v) => {
  clearTimeout(slideIntervalDebounce)
  slideIntervalDebounce = setTimeout(() => {
    persistSlides(slides.value, { interval: v })
  }, 400)
})

async function applySlideshowMode() {
  if (slides.value.length === 0) return
  slideIndex.value = 0
  try {
    const { data } = await antreanTvApi.updateMedia({ media_mode: 'slideshow' })
    applyMediaPayload(data.data)
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan (perlu login)', 'err')
  }
}

// --- TICKER EDITOR ---
const tickerDraft = ref('')
const tickerEditIdx = ref(-1)
const tickerEditVal = ref('')

function addTickerMsg() {
  const msg = tickerDraft.value.trim()
  if (!msg) return
  tickerMessages.value.push(msg)
  tickerDraft.value = ''
}

function removeTickerMsg(idx) {
  tickerMessages.value.splice(idx, 1)
}

function moveTickerMsg(idx, dir) {
  const arr = tickerMessages.value
  const target = idx + dir
  if (target < 0 || target >= arr.length) return
  ;[arr[idx], arr[target]] = [arr[target], arr[idx]]
}

function startEditTicker(idx) {
  tickerEditIdx.value = idx
  tickerEditVal.value = tickerMessages.value[idx]
}

function saveEditTicker(idx) {
  if (tickerEditVal.value.trim()) tickerMessages.value[idx] = tickerEditVal.value.trim()
  tickerEditIdx.value = -1
}

// --- CHANGE PIN ---
const newPin = ref('')
const newPinConfirm = ref('')
const pinChangeMsg = ref('')
const pinChangeMsgType = ref('') // 'ok' | 'err'

function changePin() {
  pinChangeMsg.value = ''
  if (!/^\d{4}$/.test(newPin.value)) {
    pinChangeMsg.value = 'PIN harus 4 digit angka'
    pinChangeMsgType.value = 'err'
    return
  }
  if (newPin.value !== newPinConfirm.value) {
    pinChangeMsg.value = 'Konfirmasi PIN tidak cocok'
    pinChangeMsgType.value = 'err'
    return
  }
  controlPin.value = newPin.value
  newPin.value = ''
  newPinConfirm.value = ''
  pinChangeMsg.value = 'PIN berhasil diubah'
  pinChangeMsgType.value = 'ok'
  setTimeout(() => { pinChangeMsg.value = '' }, 3000)
}

// --- DISPLAY SETTINGS EDITOR (tab Tampilan) ---
const editStation = ref('ADMISI')
const editDraft = ref(null)         // copy editable dari displaySettings[editStation]
const displaySaveMsg = ref('')
const displaySaveMsgType = ref('')  // 'ok' | 'err'

function loadEditDraft() {
  const s = settingsFor(editStation.value)
  editDraft.value = {
    tts_template:        s.tts_template ?? '',
    flash_label_top:     s.flash_label_top ?? '',
    flash_badge_text:    s.flash_badge_text ?? '',
    custom_poli_label:   s.custom_poli_label ?? '',
    show_name_in_flash:  s.show_name_in_flash !== false,
    show_poly_in_flash:  s.show_poly_in_flash !== false,
    show_name_in_card:   s.show_name_in_card !== false,
    show_poly_in_card:   s.show_poly_in_card === true,
    read_name_in_tts:    s.read_name_in_tts !== false,
  }
}

watch(editStation, loadEditDraft)
watch(displaySettings, () => { if (editDraft.value === null) loadEditDraft() }, { immediate: false })

async function saveDisplaySetting() {
  if (!editDraft.value) return
  displaySaveMsg.value = ''
  try {
    const { data } = await antreanTvApi.updateDisplay(editStation.value, editDraft.value)
    displaySettings.value = { ...displaySettings.value, [editStation.value]: data.data }
    displaySaveMsg.value = data.message ?? 'Tersimpan'
    displaySaveMsgType.value = 'ok'
  } catch (err) {
    displaySaveMsg.value = err.response?.data?.message ?? 'Gagal menyimpan'
    displaySaveMsgType.value = 'err'
  }
  setTimeout(() => { displaySaveMsg.value = '' }, 3000)
}

async function resetDisplaySetting() {
  displaySaveMsg.value = ''
  try {
    const { data } = await antreanTvApi.resetDisplay(editStation.value)
    displaySettings.value = { ...displaySettings.value, [editStation.value]: data.data }
    loadEditDraft()
    displaySaveMsg.value = data.message ?? 'Dikembalikan ke default'
    displaySaveMsgType.value = 'ok'
  } catch (err) {
    displaySaveMsg.value = err.response?.data?.message ?? 'Gagal reset'
    displaySaveMsgType.value = 'err'
  }
  setTimeout(() => { displaySaveMsg.value = '' }, 3000)
}

// Preview rendered berdasar editDraft (live, tidak perlu save dulu)
const previewQueue = computed(() => {
  const isDoctor = editStation.value === 'DOKTER'
  // DOKTER selalu auto. Stasiun lain pakai draft custom_poli_label, fallback ke label default.
  const customPoli = editDraft.value?.custom_poli_label?.trim()
  const poly = isDoctor
    ? 'Poli Glaukoma Ruang 2'
    : (customPoli || stationLabel[editStation.value] || editStation.value)
  return {
    num:     isDoctor ? 'D1-007' : `${editStation.value.charAt(0)}-003`,
    name:    'Budi Santoso',
    poly,
    station: editStation.value,
  }
})

const previewTtsText = computed(() => {
  if (!editDraft.value) return ''
  const speakable = {
    ...previewQueue.value,
    num:  String(previewQueue.value.num).replace('-', ' nomor '),
    name: editDraft.value.read_name_in_tts ? previewQueue.value.name : '',
  }
  return renderTemplate(editDraft.value.tts_template || '', speakable)
})

const previewBadgeText = computed(() => {
  if (!editDraft.value) return ''
  return renderTemplate(editDraft.value.flash_badge_text || '', previewQueue.value)
})

function previewSpeak() {
  if (!editDraft.value) return
  speakAnnouncement({ ...previewQueue.value, _ttsText: previewTtsText.value })
}

// --- AUDIO DEFAULTS — save state saat ini sebagai default backend ---
const audioSaveMsg = ref('')
const audioSaveMsgType = ref('')   // 'ok' | 'err'

async function saveAudioDefaults() {
  audioSaveMsg.value = ''
  try {
    await antreanTvApi.updateAudio({
      sound_preset:   soundPreset.value,
      sound_volume:   soundVolume.value,
      audio_enabled:  audioEnabled.value,
      flash_duration: flashDuration.value,
      call_delay:     callDelay.value,
      tts_voice_name: ttsVoiceName.value || null,
      tts_rate:       ttsRate.value,
    })
    audioSaveMsg.value = 'Tersimpan sebagai default untuk semua TV.'
    audioSaveMsgType.value = 'ok'
  } catch (err) {
    audioSaveMsg.value = err.response?.data?.message ?? 'Gagal menyimpan (perlu login)'
    audioSaveMsgType.value = 'err'
  }
  setTimeout(() => { audioSaveMsg.value = '' }, 4000)
}
</script>

<template>
  <div class="tv">
    <!-- AUDIO UNLOCK OVERLAY — block UI sampai user gesture pertama.
         Browser autoplay policy memblokir AudioContext + speechSynthesis sampai
         ada klik/touch. TV publik jarang disentuh, jadi overlay ini memastikan
         operator/teknisi pasti klik sekali saat pasang/restart. -->
    <div v-if="!audioUnlocked" class="audio-unlock-overlay" @click="unlockAudio">
      <div class="audio-unlock-card">
        <div class="audio-unlock-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
            <line x1="23" y1="9" x2="17" y2="15"/>
            <line x1="17" y1="9" x2="23" y2="15"/>
          </svg>
        </div>
        <h2 class="audio-unlock-title">Sentuh Layar untuk Aktifkan Suara</h2>
        <p class="audio-unlock-sub">
          Browser memblokir suara sampai ada interaksi. Klik di mana saja untuk
          mengaktifkan bunyi panggilan dan TTS untuk sesi ini.
        </p>
        <div class="audio-unlock-cta">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 11.24V7.5a2.5 2.5 0 0 1 5 0v3.74"/>
            <path d="M14 11.24V5.5a2.5 2.5 0 0 1 5 0v8.74"/>
            <path d="M19 14V9.5a2.5 2.5 0 0 1 5 0V17a8 8 0 0 1-8 8h-2a8 8 0 0 1-8-8v-2.5a2.5 2.5 0 0 1 5 0V14"/>
          </svg>
          <span>Klik untuk mengaktifkan</span>
        </div>
      </div>
    </div>

    <!-- TOP BAR -->
    <div class="tv-topbar">
      <div class="tv-logo">
        <img v-if="branding.logo_data" :src="branding.logo_data" alt="Logo klinik" class="tv-logo-img" />
        <svg v-else viewBox="0 0 90 90" fill="none">
          <circle cx="45" cy="45" r="43" stroke="rgba(138,191,68,0.15)" stroke-width="1" />
          <circle cx="45" cy="45" r="34" stroke="rgba(138,191,68,0.22)" stroke-width="1" />
          <circle cx="45" cy="45" r="24" stroke="rgba(138,191,68,0.32)" stroke-width="1.5" />
          <circle cx="45" cy="45" r="14" stroke="#8abf44" stroke-width="1.5" />
          <circle cx="45" cy="45" r="6" fill="#8abf44" opacity="0.9" />
          <line x1="45" y1="39" x2="45" y2="51" stroke="#fff" stroke-width="1.5" stroke-linecap="round" />
          <line x1="39" y1="45" x2="51" y2="45" stroke="#fff" stroke-width="1.5" stroke-linecap="round" />
        </svg>
        <div class="tv-brand">
          <span class="tv-brand-name">{{ branding.clinic_name }}</span>
          <span v-if="branding.clinic_subtitle" class="tv-brand-sub">{{ branding.clinic_subtitle }}</span>
        </div>
      </div>
      <div class="tv-right">
        <div class="tv-status"><span class="tv-status-dot"></span>Sistem Aktif</div>
        <div v-if="!audioUnlocked" class="tv-audio-warn" @click="unlockAudio" title="Klik untuk aktifkan suara">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
            <line x1="23" y1="9" x2="17" y2="15"/>
            <line x1="17" y1="9" x2="23" y2="15"/>
          </svg>
          <span>Suara nonaktif</span>
        </div>
        <div class="tv-clock-wrap">
          <div class="tv-clock">{{ clock }}</div>
          <div class="tv-date">{{ dateStr }}</div>
        </div>
      </div>
    </div>

    <!-- MAIN -->
    <div class="tv-main">
      <!-- VIDEO/INFO PANEL -->
      <div class="video-panel">
        <!-- Placeholder -->
        <div v-if="mediaMode === 'placeholder'" class="video-placeholder">
          <img v-if="branding.logo_data" :src="branding.logo_data" alt="Logo klinik" class="video-logo-img" />
          <svg v-else viewBox="0 0 90 90" fill="none">
            <circle cx="45" cy="45" r="43" stroke="rgba(138,191,68,0.08)" stroke-width="1" />
            <circle cx="45" cy="45" r="34" stroke="rgba(138,191,68,0.12)" stroke-width="1" />
            <circle cx="45" cy="45" r="24" stroke="rgba(138,191,68,0.18)" stroke-width="1.5" />
            <circle cx="45" cy="45" r="14" stroke="rgba(138,191,68,0.3)" stroke-width="1.5" />
            <circle cx="45" cy="45" r="6" fill="rgba(138,191,68,0.2)" />
            <line x1="45" y1="39" x2="45" y2="51" stroke="rgba(138,191,68,0.4)" stroke-width="1.5" stroke-linecap="round" />
            <line x1="39" y1="45" x2="51" y2="45" stroke="rgba(138,191,68,0.4)" stroke-width="1.5" stroke-linecap="round" />
          </svg>
          <h2 v-if="branding.placeholder_title" class="video-title">{{ branding.placeholder_title }}</h2>
          <p v-if="branding.placeholder_tagline" class="video-tagline">{{ branding.placeholder_tagline }}</p>
        </div>

        <!-- YouTube Embed -->
        <iframe
          v-else-if="mediaMode === 'youtube'"
          :src="youtubeEmbedUrl"
          class="yt-frame"
          allow="autoplay; encrypted-media"
          allowfullscreen
          frameborder="0"
        ></iframe>

        <!-- Slideshow -->
        <div v-else-if="mediaMode === 'slideshow' && slides.length" class="slideshow">
          <img
            v-for="(s, i) in slides"
            :key="i"
            :src="s.url"
            :class="['slide-img', { active: i === slideIndex }]"
            alt=""
          />
          <div v-if="slides.length > 1" class="slide-dots">
            <span
              v-for="(s, i) in slides"
              :key="i"
              :class="['dot', { active: i === slideIndex }]"
            ></span>
          </div>
        </div>

        <!-- Video Lokal -->
        <video
          v-else-if="mediaMode === 'localvideo' && localVideoUrl"
          :src="localVideoUrl"
          :loop="videoLoop"
          :autoplay="videoAutoplay"
          muted
          controls
          class="local-video"
        ></video>
      </div>

      <!-- QUEUE PANEL -->
      <div class="queue-panel">
        <div class="queue-header">
          <div class="queue-header-title">Antrean Semua Stasiun</div>
          <div class="queue-header-sub">Update otomatis · {{ callQueue.length }} panggilan dalam antrian</div>
        </div>

        <!-- GRID 8 STASIUN (4x2) -->
        <div class="station-grid">
          <div
            v-for="key in stationOrder"
            :key="key"
            :class="['station-card', stationView[key].called ? 'has-called' : '']"
          >
            <div class="sc-head">
              <span class="sc-name">{{ key === 'DOKTER' ? 'POLIKLINIK' : stationLabel[key] }}</span>
              <span class="sc-count">{{ stationView[key].waiting.length }} menunggu</span>
            </div>
            <template v-if="stationView[key].called">
              <div class="sc-called-lbl">Dipanggil</div>
              <div class="sc-called-num">{{ stationView[key].called.num }}</div>
              <div v-if="settingsFor(key).show_name_in_card !== false && stationView[key].called.name && stationView[key].called.name !== '—'"
                   class="sc-called-name">{{ stationView[key].called.name }}</div>
              <div v-if="settingsFor(key).show_poly_in_card === true"
                   class="sc-called-poly">{{ stationView[key].called.poly }}</div>
            </template>
            <template v-else>
              <div class="sc-called-lbl muted">Belum ada panggilan</div>
              <div class="sc-called-num muted">—</div>
              <div class="sc-next" v-if="stationView[key].waiting[0]">
                Berikutnya: <strong>{{ stationView[key].waiting[0].num }}</strong>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- BOTTOM TICKER -->
    <div class="tv-bottom">
      <div class="ticker-label">Info</div>
      <div class="ticker-wrap">
        <div class="ticker-inner" :style="{ animationDuration: tickerDuration }">
          <template v-for="rep in 2" :key="rep">
            <template v-for="(msg, i) in tickerMessages" :key="`${rep}-${i}`">
              <span class="ticker-item">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".5" fill="currentColor"/></svg>
                {{ msg }}
              </span>
              <span class="ticker-sep">·</span>
            </template>
          </template>
        </div>
      </div>
      <div class="tv-bottom-right">Arumed Apps v1.0</div>
      <!-- PIN trigger button -->
      <button class="ctrl-btn" @click="openPinModal" title="Kontrol Layar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- FULL-SCREEN FLASH OVERLAY (queue called) -->
  <Teleport to="body">
    <div v-if="flashVisible" class="flash-overlay" @click="flashVisible = false">
      <div class="flash-content">
        <div class="flash-label-top">{{ flashSettings.flash_label_top || 'Nomor Antrean Dipanggil' }}</div>
        <div class="flash-num">{{ flashQueue?.num }}</div>
        <div v-if="flashSettings.show_name_in_flash !== false && flashQueue?.name && flashQueue.name !== '—'" class="flash-name">
          {{ flashQueue.name }}
        </div>
        <div v-if="flashSettings.show_poly_in_flash !== false" class="flash-poly">{{ flashQueue?.poly }}</div>
        <div class="flash-badge">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/></svg>
          {{ flashBadgeRendered }}
        </div>
        <div class="flash-hint">Ketuk untuk tutup</div>
      </div>
    </div>
  </Teleport>

  <!-- PIN MODAL -->
  <Teleport to="body">
    <div v-if="showPinModal" class="pin-overlay" @click.self="showPinModal = false">
      <div :class="['pin-dialog', { shake: pinShake }]">
        <div class="pin-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="#8abf44" stroke-width="1.5" stroke-linecap="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
          </svg>
        </div>
        <h3 class="pin-title">Masukkan PIN</h3>
        <p class="pin-sub">Akses kontrol layar antrean</p>
        <div class="pin-inputs">
          <input
            v-for="(_, idx) in pinDigits"
            :key="idx"
            :ref="el => pinRefs[idx] = el"
            type="password"
            inputmode="numeric"
            maxlength="1"
            :value="pinDigits[idx]"
            :class="['pin-box', { filled: pinDigits[idx], error: pinError }]"
            @input="onPinInput(idx, $event)"
            @keydown="onPinKeydown(idx, $event)"
          />
        </div>
        <p v-if="pinError" class="pin-err">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          PIN salah. Coba lagi.
        </p>
        <div class="pin-actions">
          <button class="pin-btn primary" @click="submitPin">Masuk</button>
          <button class="pin-btn ghost" @click="showPinModal = false">Batal</button>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- CONTROL PANEL MODAL -->
  <Teleport to="body">
    <div v-if="showControl" class="ctrl-overlay" @click.self="showControl = false">
      <div class="ctrl-panel">
        <div class="ctrl-header">
          <div class="ctrl-header-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="#8abf44" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="3" width="20" height="14" rx="2"/>
              <line x1="8" y1="21" x2="16" y2="21"/>
              <line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
            <span>Kontrol Layar</span>
          </div>
          <button class="ctrl-close" @click="showControl = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <nav class="ctrl-tabs">
          <button :class="['ctrl-tab', { active: activeTab === 'media' }]" @click="activeTab = 'media'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
            Media
          </button>
          <button :class="['ctrl-tab', { active: activeTab === 'branding' }]" @click="activeTab = 'branding'; loadBrandingDraft()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><line x1="9" y1="9" x2="9" y2="9.01"/><line x1="9" y1="13" x2="9" y2="13.01"/></svg>
            Klinik
          </button>
          <button :class="['ctrl-tab', { active: activeTab === 'slideshow' }]" @click="activeTab = 'slideshow'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            Slideshow
          </button>
          <button :class="['ctrl-tab', { active: activeTab === 'ticker' }]" @click="activeTab = 'ticker'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="15" y2="18"/></svg>
            Running Text
          </button>
          <button :class="['ctrl-tab', { active: activeTab === 'antrean' }]" @click="activeTab = 'antrean'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            Antrean
          </button>
          <button :class="['ctrl-tab', { active: activeTab === 'tampilan' }]" @click="activeTab = 'tampilan'; if (!editDraft) loadEditDraft()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            Tampilan
          </button>
          <button :class="['ctrl-tab', { active: activeTab === 'pin' }]" @click="activeTab = 'pin'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Keamanan
          </button>
        </nav>

        <div class="ctrl-body">
          <!-- TAB: MEDIA -->
          <div v-if="activeTab === 'media'" class="ctrl-section">
            <p class="ctrl-lbl">Mode Tampilan Panel Kiri (sinkron ke semua TV)</p>
            <div class="mode-grid">
              <button :class="['mode-card', { active: mediaMode === 'placeholder' }]" @click="setMediaMode('placeholder')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>Placeholder</span>
                <small>Logo + nama klinik</small>
              </button>
              <button :class="['mode-card', { active: mediaMode === 'youtube' }]" @click="mediaMode === 'youtube' ? null : setMediaMode('youtube')" :disabled="!youtubeEmbedUrl">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M22.54 6.42a2.78 2.78 0 00-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46A2.78 2.78 0 001.46 6.42 29 29 0 001 12a29 29 0 00.46 5.58 2.78 2.78 0 001.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 001.95-1.96A29 29 0 0023 12a29 29 0 00-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02"/></svg>
                <span>YouTube</span>
                <small>{{ youtubeEmbedUrl ? 'Video tersimpan' : 'Belum ada URL' }}</small>
              </button>
              <button :class="['mode-card', { active: mediaMode === 'localvideo' }]" @click="$refs.videoFileInput.click()" :disabled="mediaUploading">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <span>Video Lokal</span>
                <small>{{ mediaUploading ? 'Mengupload…' : (localVideoName || 'Pilih file video') }}</small>
              </button>
            </div>
            <input ref="videoFileInput" type="file" accept="video/*" style="display:none" @change="handleVideoFile" />

            <!-- Upload progress -->
            <div v-if="mediaUploading" class="ctrl-sub-section">
              <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:4px;color:#fff;gap:8px">
                <span style="color:#fff">Mengupload video…</span>
                <span style="font-variant-numeric:tabular-nums;color:#fff">{{ mediaUploadPct }}% · {{ mediaUploadInfo }}</span>
              </div>
              <div style="height:8px;background:rgba(255,255,255,.1);border-radius:4px;overflow:hidden">
                <div :style="{ width: mediaUploadPct + '%', height: '100%', background: '#8abf44', transition: 'width .15s' }"></div>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;gap:8px">
                <p style="font-size:11px;color:rgba(255,255,255,.7);margin:0">Jangan tutup tab — TV akan otomatis update saat upload selesai.</p>
                <button class="ctrl-action-btn" style="background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.3);padding:4px 12px;font-size:12px" @click="cancelUpload">
                  Batalkan
                </button>
              </div>
            </div>

            <p v-if="mediaSaveMsg" :class="['ctrl-' + (mediaSaveMsgType === 'ok' ? 'ok' : 'err')]" style="margin-top:8px">
              {{ mediaSaveMsg }}
            </p>

            <!-- Info video lokal + tombol hapus (cuma kalau file upload, bukan URL) -->
            <div v-if="hasUploadedFile" class="ctrl-sub-section" style="display:flex;align-items:center;gap:10px;justify-content:space-between">
              <div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;color:#fff">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                <span style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ localVideoName || 'video lokal' }}</span>
              </div>
              <button class="ctrl-action-btn" style="background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.3)" @click="deleteLocalVideo">
                Hapus File
              </button>
            </div>

            <!-- Video URL eksternal (Drive/Dropbox/CDN/hosting) — alternatif upload -->
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Video URL (alternatif tanpa upload)</p>
              <div class="ctrl-row">
                <input
                  v-model="externalVideoDraft"
                  class="ctrl-input"
                  type="text"
                  placeholder="https://example.com/video.mp4"
                  @keydown.enter="applyExternalVideoUrl"
                />
                <button class="ctrl-action-btn" @click="applyExternalVideoUrl">Terapkan</button>
              </div>
              <p style="font-size:11px;color:rgba(255,255,255,.55);margin:4px 0 0">
                Paste link MP4 langsung. Untuk Dropbox: ganti <code>?dl=0</code> jadi <code>?raw=1</code>. Untuk Drive: gunakan <code>uc?export=download&amp;id=FILE_ID</code>.
              </p>
              <div v-if="externalVideoUrl" style="display:flex;align-items:center;gap:8px;justify-content:space-between;margin-top:8px;padding:8px 10px;background:rgba(138,191,68,.08);border:1px solid rgba(138,191,68,.2);border-radius:8px">
                <div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;color:#fff">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#8abf44" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                  <span style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">URL aktif: {{ externalVideoUrl }}</span>
                </div>
                <button class="ctrl-action-btn" style="background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.3);padding:4px 10px;font-size:12px" @click="clearExternalVideoUrl">
                  Hapus URL
                </button>
              </div>
            </div>

            <!-- Loop / Autoplay toggles -->
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Pengaturan Pemutaran</p>
              <div class="ctrl-toggles">
                <label class="ctrl-toggle">
                  <input type="checkbox" v-model="videoAutoplay" @change="syncVideoOptions" />
                  <span class="toggle-track"></span>
                  <span class="toggle-label">Autoplay (mulai otomatis)</span>
                </label>
                <label class="ctrl-toggle">
                  <input type="checkbox" v-model="videoLoop" @change="syncVideoOptions" />
                  <span class="toggle-track"></span>
                  <span class="toggle-label">Loop (ulangi dari awal)</span>
                </label>
              </div>
            </div>

            <!-- YouTube URL input -->
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">YouTube URL</p>
              <div class="ctrl-row">
                <input
                  v-model="youtubeDraft"
                  class="ctrl-input"
                  type="text"
                  placeholder="https://www.youtube.com/watch?v=..."
                  @keydown.enter="applyYoutube"
                />
                <button class="ctrl-action-btn" @click="applyYoutube">Terapkan</button>
              </div>
              <p v-if="youtubeError" class="ctrl-err">{{ youtubeError }}</p>
              <p v-if="mediaMode === 'youtube' && youtubeEmbedUrl" class="ctrl-ok">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                Video aktif di semua TV
              </p>
            </div>
          </div>

          <!-- TAB: SLIDESHOW -->
          <div v-if="activeTab === 'slideshow'" class="ctrl-section">
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Tambah Gambar (URL per baris)</p>
              <textarea
                v-model="slidesDraft"
                class="ctrl-textarea"
                rows="4"
                placeholder="https://contoh.com/gambar1.jpg&#10;https://contoh.com/gambar2.jpg"
              ></textarea>
              <button class="ctrl-action-btn" style="margin-top:8px" @click="addSlides">Tambah</button>
            </div>

            <div class="ctrl-sub-section">
              <div class="ctrl-row" style="align-items:center; gap:1rem">
                <p class="ctrl-lbl" style="margin:0">Durasi per Slide</p>
                <input type="range" v-model.number="slideDuration" min="3" max="30" step="1" class="ctrl-range" />
                <span class="ctrl-dur-val">{{ slideDuration }}s</span>
              </div>
            </div>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Daftar Gambar ({{ slides.length }})</p>
              <div v-if="slides.length === 0" class="ctrl-empty">Belum ada gambar ditambahkan</div>
              <div v-for="(s, i) in slides" :key="i" class="slide-row">
                <span class="slide-idx">{{ i + 1 }}</span>
                <div class="slide-thumb-wrap">
                  <img :src="s.url" class="slide-thumb" alt="" />
                </div>
                <span class="slide-url">{{ s.url }}</span>
                <button class="icon-btn danger" @click="removeSlide(i)" title="Hapus">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </div>
            </div>

            <button v-if="slides.length" class="ctrl-action-btn" @click="applySlideshowMode">
              Aktifkan Slideshow
            </button>
          </div>

          <!-- TAB: TICKER -->
          <div v-if="activeTab === 'ticker'" class="ctrl-section">
            <p class="ctrl-lbl">Pesan Running Text ({{ tickerMessages.length }})</p>
            <div class="ticker-list">
              <div v-for="(msg, i) in tickerMessages" :key="i" class="ticker-row">
                <span class="ticker-idx">{{ i + 1 }}</span>
                <input
                  v-if="tickerEditIdx === i"
                  v-model="tickerEditVal"
                  class="ctrl-input ticker-edit-input"
                  @keydown.enter="saveEditTicker(i)"
                  @keydown.escape="tickerEditIdx = -1"
                  autofocus
                />
                <span v-else class="ticker-msg-text">{{ msg }}</span>
                <div class="ticker-row-actions">
                  <button v-if="tickerEditIdx === i" class="icon-btn ok" @click="saveEditTicker(i)" title="Simpan">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </button>
                  <button v-else class="icon-btn" @click="startEditTicker(i)" title="Edit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>
                  <button class="icon-btn" @click="moveTickerMsg(i, -1)" :disabled="i === 0" title="Naik">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="18 15 12 9 6 15"/></svg>
                  </button>
                  <button class="icon-btn" @click="moveTickerMsg(i, 1)" :disabled="i === tickerMessages.length - 1" title="Turun">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
                  </button>
                  <button class="icon-btn danger" @click="removeTickerMsg(i)" title="Hapus">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M9 6V4h6v2"/></svg>
                  </button>
                </div>
              </div>
              <div v-if="tickerMessages.length === 0" class="ctrl-empty">Belum ada pesan</div>
            </div>
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Tambah Pesan Baru</p>
              <div class="ctrl-row">
                <input
                  v-model="tickerDraft"
                  class="ctrl-input"
                  type="text"
                  placeholder="Ketik pesan informasi..."
                  @keydown.enter="addTickerMsg"
                />
                <button class="ctrl-action-btn" @click="addTickerMsg">Tambah</button>
              </div>
            </div>
          </div>

          <!-- TAB: ANTREAN — Pengaturan FIFO call queue + audio -->
          <div v-if="activeTab === 'antrean'" class="ctrl-section">
            <p class="ctrl-lbl">Pengaturan Panggilan</p>

            <div class="ctrl-sub-section">
              <div class="ctrl-row" style="align-items:center; gap:1rem">
                <p class="ctrl-lbl" style="margin:0; min-width:170px">Durasi Flash Layar</p>
                <input type="range" v-model.number="flashDuration" min="3" max="10" step="1" class="ctrl-range" />
                <span class="ctrl-dur-val">{{ flashDuration }}s</span>
              </div>
              <p class="ctrl-lbl" style="opacity:.55; margin-top:6px; font-weight:400">
                Berapa lama tampilan nomor besar di layar penuh ditahan minimum.
              </p>
            </div>

            <div class="ctrl-sub-section">
              <div class="ctrl-row" style="align-items:center; gap:1rem">
                <p class="ctrl-lbl" style="margin:0; min-width:170px">Jeda Antar Panggilan</p>
                <input type="range" v-model.number="callDelay" min="5" max="10" step="1" class="ctrl-range" />
                <span class="ctrl-dur-val">{{ callDelay }}s</span>
              </div>
              <p class="ctrl-lbl" style="opacity:.55; margin-top:6px; font-weight:400">
                Jika beberapa stasiun memanggil bersamaan, panggilan diproses berurutan dengan jeda ini.
              </p>
            </div>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Audio</p>
              <div class="ctrl-toggles">
                <label class="ctrl-toggle">
                  <input type="checkbox" v-model="audioEnabled" />
                  <span class="toggle-track"></span>
                  <span class="toggle-label">Bunyi notifikasi + suara panggilan (TTS)</span>
                </label>
              </div>
            </div>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Pilih Bunyi Notifikasi</p>
              <div class="sound-grid">
                <button
                  v-for="(meta, key) in soundPresets"
                  :key="key"
                  :class="['sound-card', { active: soundPreset === key }]"
                  @click="soundPreset = key"
                >
                  <span class="sound-card-name">{{ meta.label }}</span>
                  <small>{{ meta.desc }}</small>
                  <button class="sound-test-btn" @click.stop="playSound(key)" title="Tes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="6 4 20 12 6 20 6 4"/></svg>
                  </button>
                </button>
              </div>
            </div>

            <div class="ctrl-sub-section">
              <div class="ctrl-row" style="align-items:center; gap:1rem">
                <p class="ctrl-lbl" style="margin:0; min-width:90px">Volume</p>
                <input type="range" v-model.number="soundVolume" min="0" max="1" step="0.05" class="ctrl-range" />
                <span class="ctrl-dur-val">{{ Math.round(soundVolume * 100) }}%</span>
              </div>
            </div>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Suara Panggilan (TTS)</p>
              <label class="ctrl-field-lbl">Voice</label>
              <select v-model="ttsVoiceName" class="ctrl-input">
                <option value="">Auto — Bahasa Indonesia pertama</option>
                <option v-for="v in ttsVoices" :key="v.name" :value="v.name">
                  {{ v.name }} ({{ v.lang }})
                </option>
              </select>
              <div class="ctrl-row" style="align-items:center; gap:1rem; margin-top:10px">
                <p class="ctrl-lbl" style="margin:0; min-width:90px">Kecepatan</p>
                <input type="range" v-model.number="ttsRate" min="0.7" max="1.3" step="0.05" class="ctrl-range" />
                <span class="ctrl-dur-val">{{ ttsRate.toFixed(2) }}×</span>
              </div>
            </div>

            <div class="ctrl-sub-section">
              <button class="ctrl-action-btn"
                      @click="playCallAnnouncement({ num: 'A-001', name: 'Tes Audio', poly: 'Loket Admisi', station: 'ADMISI', id: 'test-' + Date.now() })">
                Tes Panggilan Lengkap (Bunyi + Suara)
              </button>
            </div>

            <div class="ctrl-sub-section" style="border-top:1px solid rgba(255,255,255,.08); padding-top:1rem; display:flex; gap:.5rem; align-items:center; flex-wrap:wrap">
              <button class="ctrl-action-btn" style="background:#8abf44; color:#061d15" @click="saveAudioDefaults">
                💾 Simpan sebagai Default
              </button>
              <p v-if="audioSaveMsg" :class="['ctrl-feedback', audioSaveMsgType]">{{ audioSaveMsg }}</p>
              <p v-else class="ctrl-lbl" style="opacity:.55; font-weight:400; margin:0; flex:1; min-width:200px">
                Bunyi, volume, jeda, TTS — semua tersimpan & dipakai default di semua TV.
              </p>
            </div>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Status Antrean Panggilan</p>
              <div class="antr-current">
                <div class="antr-lbl">Dalam Antrian</div>
                <div class="antr-num">{{ callQueue.length }}</div>
                <div class="antr-meta">{{ isProcessingCall ? 'Sedang memproses panggilan...' : 'Menunggu panggilan baru' }}</div>
              </div>
            </div>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Ringkasan Per Stasiun</p>
              <div class="antr-list">
                <div v-for="key in stationOrder" :key="key" class="antr-item">
                  <span class="antr-item-num">{{ stationView[key].called?.num ?? '—' }}</span>
                  <span class="antr-item-name">{{ stationLabel[key] }}</span>
                  <span class="antr-item-poly">{{ stationView[key].waiting.length }} menunggu</span>
                </div>
              </div>
              <p v-if="fetchError" class="ctrl-err" style="margin-top:8px">{{ fetchError }}</p>
              <p class="ctrl-lbl" style="margin-top:12px; opacity:.6; font-weight:400">
                TV read-only. Panggil pasien dilakukan dari modul tiap stasiun (Admisi/Perawat/Dokter/dst).
              </p>
            </div>
          </div>

          <!-- TAB: TAMPILAN — editor template per-stasiun -->
          <div v-if="activeTab === 'tampilan'" class="ctrl-section">
            <p class="ctrl-lbl">Pengaturan Tampilan Per-Stasiun</p>
            <p class="ctrl-lbl" style="opacity:.55; font-weight:400; margin-top:-4px">
              Edit format teks suara (TTS), label flash full-screen, badge bawah, dan field di kartu grid.
              Variabel: <code>{nomor}</code>, <code>{nama}</code>, <code>{poli}</code>, <code>{stasiun}</code>.
            </p>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Stasiun</p>
              <div class="station-tabs">
                <button
                  v-for="key in stationOrder"
                  :key="key"
                  :class="['station-tab', { active: editStation === key }]"
                  @click="editStation = key"
                >{{ stationLabel[key] }}</button>
              </div>
            </div>

            <template v-if="editDraft">
              <div class="ctrl-sub-section">
                <label class="ctrl-field-lbl">Teks Suara Panggilan (TTS)</label>
                <textarea v-model="editDraft.tts_template" class="ctrl-textarea" rows="2"
                          placeholder="Nomor antrean {nomor}, atas nama {nama}, silakan menuju {poli}."></textarea>
                <p class="ctrl-lbl" style="opacity:.55; font-weight:400; margin-top:4px">
                  Preview: <em>"{{ previewTtsText }}"</em>
                </p>
                <button class="ctrl-action-btn" style="margin-top:6px" @click="previewSpeak">▶ Tes Suara</button>
              </div>

              <div class="ctrl-sub-section">
                <label class="ctrl-field-lbl">Label Atas Flash Layar Penuh</label>
                <input v-model="editDraft.flash_label_top" class="ctrl-input" type="text" placeholder="Nomor Antrean Dipanggil" />
              </div>

              <div v-if="editStation !== 'DOKTER'" class="ctrl-sub-section">
                <label class="ctrl-field-lbl">Tujuan / Ruangan <code>{poli}</code></label>
                <input v-model="editDraft.custom_poli_label" class="ctrl-input" type="text"
                       :placeholder="`Default: ${stationLabel[editStation] ?? editStation}`" />
                <p class="ctrl-lbl" style="opacity:.55; font-weight:400; margin-top:4px">
                  Label yang menggantikan <code>{poli}</code> di TTS, badge, dan kartu.
                  Contoh: untuk BEDAH bisa diisi "Ruang Operasi", untuk FARMASI "Apotek".
                  Kosongkan untuk pakai default ("{{ stationLabel[editStation] ?? editStation }}").
                </p>
              </div>
              <div v-else class="ctrl-sub-section">
                <p class="ctrl-lbl" style="opacity:.6; font-weight:400">
                  <code>{poli}</code> untuk DOKTER di-resolve otomatis dari jadwal dokter aktif
                  (mis. "Poli Glaukoma Ruang 2"), tidak bisa di-override manual.
                </p>
              </div>

              <div class="ctrl-sub-section">
                <label class="ctrl-field-lbl">Badge Bawah Flash</label>
                <input v-model="editDraft.flash_badge_text" class="ctrl-input" type="text" placeholder="Silakan menuju {poli}" />
                <p class="ctrl-lbl" style="opacity:.55; font-weight:400; margin-top:4px">
                  Preview: <em>"{{ previewBadgeText }}"</em>
                </p>
              </div>

              <div class="ctrl-sub-section">
                <p class="ctrl-lbl">Tampilan Flash Layar Penuh</p>
                <div class="ctrl-toggles">
                  <label class="ctrl-toggle">
                    <input type="checkbox" v-model="editDraft.show_name_in_flash" />
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Tampilkan nama pasien</span>
                  </label>
                  <label class="ctrl-toggle">
                    <input type="checkbox" v-model="editDraft.show_poly_in_flash" />
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Tampilkan tujuan/poli</span>
                  </label>
                </div>
              </div>

              <div class="ctrl-sub-section">
                <p class="ctrl-lbl">Kartu Grid Stasiun</p>
                <div class="ctrl-toggles">
                  <label class="ctrl-toggle">
                    <input type="checkbox" v-model="editDraft.show_name_in_card" />
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Tampilkan nama pasien di kartu</span>
                  </label>
                  <label class="ctrl-toggle">
                    <input type="checkbox" v-model="editDraft.show_poly_in_card" />
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Tampilkan tujuan/poli di kartu</span>
                  </label>
                </div>
              </div>

              <div class="ctrl-sub-section">
                <p class="ctrl-lbl">TTS Suara</p>
                <div class="ctrl-toggles">
                  <label class="ctrl-toggle">
                    <input type="checkbox" v-model="editDraft.read_name_in_tts" />
                    <span class="toggle-track"></span>
                    <span class="toggle-label">Bacakan nama pasien di TTS</span>
                  </label>
                </div>
              </div>

              <div class="ctrl-sub-section" style="display:flex; gap:.5rem; flex-wrap:wrap">
                <button class="ctrl-action-btn" @click="saveDisplaySetting">Simpan Stasiun {{ stationLabel[editStation] }}</button>
                <button class="ctrl-action-btn" style="background:rgba(255,255,255,.08); color:rgba(255,255,255,.7)"
                        @click="resetDisplaySetting">Kembalikan Default</button>
                <p v-if="displaySaveMsg" :class="['ctrl-feedback', displaySaveMsgType]" style="margin-left:.5rem">{{ displaySaveMsg }}</p>
              </div>
            </template>
            <div v-else class="ctrl-empty">Memuat...</div>
          </div>

          <!-- TAB: BRANDING / KLINIK -->
          <div v-if="activeTab === 'branding'" class="ctrl-section">
            <template v-if="brandingDraft">
              <!-- LOGO -->
              <div class="ctrl-sub-section">
                <p class="ctrl-lbl">Logo Klinik</p>
                <div class="brand-logo-row">
                  <div class="brand-logo-preview">
                    <img v-if="brandingDraft.logo_data" :src="brandingDraft.logo_data" alt="Pratinjau logo" />
                    <span v-else class="brand-logo-empty">Belum ada logo<br>(pakai logo bawaan)</span>
                  </div>
                  <div class="brand-logo-actions">
                    <input ref="logoFileInput" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="display:none" @change="handleLogoFile" />
                    <button class="ctrl-action-btn" @click="$refs.logoFileInput.click()">Pilih / Ganti Logo</button>
                    <button v-if="brandingDraft.logo_data" class="ctrl-action-btn brand-btn-muted" @click="removeLogo">Hapus Logo</button>
                    <p class="ctrl-hint">Format PNG, JPG, SVG, atau WebP. Disarankan persegi (mis. 512×512 px) dengan latar transparan. Maksimal 512 KB. Logo tampil ~56 px di bar atas dan ~180 px di panel placeholder.</p>
                  </div>
                </div>
              </div>

              <!-- TEKS BAR ATAS -->
              <div class="ctrl-sub-section">
                <p class="ctrl-lbl">Teks Bar Atas (Header)</p>
                <label class="ctrl-field-lbl">Nama Klinik</label>
                <input v-model="brandingDraft.clinic_name" class="ctrl-input" type="text" maxlength="120" :placeholder="brandingDefaults.clinic_name" />
                <label class="ctrl-field-lbl" style="margin-top:12px">Sub-judul Header</label>
                <input v-model="brandingDraft.clinic_subtitle" class="ctrl-input" type="text" maxlength="160" :placeholder="brandingDefaults.clinic_subtitle" />
              </div>

              <!-- TEKS PLACEHOLDER -->
              <div class="ctrl-sub-section">
                <p class="ctrl-lbl">Teks Panel Placeholder (Panel Kiri)</p>
                <label class="ctrl-field-lbl">Judul</label>
                <input v-model="brandingDraft.placeholder_title" class="ctrl-input" type="text" maxlength="160" :placeholder="brandingDefaults.placeholder_title" />
                <label class="ctrl-field-lbl" style="margin-top:12px">Tagline / Keterangan</label>
                <textarea v-model="brandingDraft.placeholder_tagline" class="ctrl-textarea" rows="2" maxlength="300" :placeholder="brandingDefaults.placeholder_tagline"></textarea>
              </div>

              <div class="ctrl-sub-section" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center">
                <button class="ctrl-action-btn" style="background:#8abf44; color:#061d15" @click="saveBranding">Simpan</button>
                <button class="ctrl-action-btn brand-btn-muted" @click="resetBrandingToDefault">Set ke Default</button>
                <p v-if="brandingSaveMsg" :class="['ctrl-feedback', brandingSaveMsgType]" style="margin-left:.5rem">{{ brandingSaveMsg }}</p>
              </div>
            </template>
            <div v-else class="ctrl-empty">Memuat...</div>
          </div>

          <!-- TAB: SECURITY / PIN -->
          <div v-if="activeTab === 'pin'" class="ctrl-section">
            <p class="ctrl-lbl">Ubah PIN Kontrol</p>
            <div class="ctrl-sub-section" style="max-width:340px">
              <label class="ctrl-field-lbl">PIN Baru (4 digit)</label>
              <input v-model="newPin" class="ctrl-input" type="password" inputmode="numeric" maxlength="4" placeholder="••••" />
              <label class="ctrl-field-lbl" style="margin-top:12px">Konfirmasi PIN Baru</label>
              <input v-model="newPinConfirm" class="ctrl-input" type="password" inputmode="numeric" maxlength="4" placeholder="••••" @keydown.enter="changePin" />
              <button class="ctrl-action-btn" style="margin-top:16px" @click="changePin">Simpan PIN</button>
              <p v-if="pinChangeMsg" :class="['ctrl-feedback', pinChangeMsgType]">{{ pinChangeMsg }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.tv {
  width: 100vw;
  height: 100vh;
  background: linear-gradient(180deg, #0a2e22 0%, #061d15 100%);
  color: #fff;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  font-family: 'DM Sans', sans-serif;
}

/* TOP BAR */
.tv-topbar {
  height: 80px;
  padding: 0 2rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(0, 0, 0, 0.2);
  border-bottom: 1px solid rgba(138, 191, 68, 0.15);
  flex-shrink: 0;
}
.tv-logo { display: flex; align-items: center; gap: 1rem; }
.tv-logo svg { width: 56px; height: 56px; }
.tv-logo-img { width: 56px; height: 56px; object-fit: contain; }
.tv-brand { display: flex; flex-direction: column; }
.tv-brand-name {
  font-family: 'DM Serif Display', serif;
  font-size: 26px;
  line-height: 1;
}
.tv-brand-sub {
  font-size: 12px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(138, 191, 68, 0.7);
  margin-top: 4px;
}
.tv-right { display: flex; align-items: center; gap: 1.5rem; }
.tv-audio-warn {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(252, 211, 77, 0.15);
  border: 1px solid rgba(252, 211, 77, 0.4);
  color: #fcd34d;
  padding: 5px 12px;
  border-radius: 30px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
.tv-audio-warn:hover { background: rgba(252, 211, 77, 0.25); }
.tv-audio-warn svg { width: 13px; height: 13px; }

/* Audio unlock overlay — full-screen, blokir UI sampai user klik */
.audio-unlock-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(4, 14, 10, 0.92);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  cursor: pointer;
  animation: audio-unlock-fade-in 0.3s ease-out;
}
@keyframes audio-unlock-fade-in {
  from { opacity: 0; }
  to   { opacity: 1; }
}
.audio-unlock-card {
  max-width: 560px;
  text-align: center;
  padding: 3rem 2.5rem;
  background: linear-gradient(180deg, rgba(138, 191, 68, 0.08), rgba(138, 191, 68, 0.02));
  border: 1px solid rgba(138, 191, 68, 0.25);
  border-radius: 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 0 80px rgba(138, 191, 68, 0.1);
}
.audio-unlock-icon {
  width: 96px;
  height: 96px;
  margin: 0 auto 1.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(252, 211, 77, 0.12);
  border: 2px solid rgba(252, 211, 77, 0.4);
  border-radius: 50%;
  color: #fcd34d;
  animation: audio-unlock-pulse 2s ease-in-out infinite;
}
.audio-unlock-icon svg { width: 48px; height: 48px; }
@keyframes audio-unlock-pulse {
  0%, 100% { transform: scale(1);    box-shadow: 0 0 0 0   rgba(252, 211, 77, 0.4); }
  50%      { transform: scale(1.05); box-shadow: 0 0 0 18px rgba(252, 211, 77, 0); }
}
.audio-unlock-title {
  font-size: 28px;
  font-weight: 700;
  color: #fff;
  margin: 0 0 0.75rem;
  letter-spacing: -0.01em;
}
.audio-unlock-sub {
  font-size: 15px;
  color: rgba(255, 255, 255, 0.7);
  line-height: 1.6;
  margin: 0 0 2rem;
}
.audio-unlock-cta {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 14px 28px;
  background: #8abf44;
  color: #061d15;
  border-radius: 30px;
  font-weight: 700;
  font-size: 15px;
  letter-spacing: 0.02em;
  animation: audio-unlock-bounce 1.6s ease-in-out infinite;
}
.audio-unlock-cta svg { width: 20px; height: 20px; }
@keyframes audio-unlock-bounce {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-4px); }
}
.tv-status {
  display: flex;
  align-items: center;
  gap: 8px;
  background: rgba(138, 191, 68, 0.15);
  border: 1px solid rgba(138, 191, 68, 0.3);
  padding: 6px 14px;
  border-radius: 30px;
  font-size: 13px;
  font-weight: 500;
}
.tv-status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #8abf44;
  animation: blink 1.5s infinite;
}
.tv-clock-wrap { text-align: right; }
.tv-clock {
  font-family: 'DM Serif Display', serif;
  font-size: 32px;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}
.tv-date {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.5);
  margin-top: 4px;
}

/* MAIN */
.tv-main {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr 480px;
  gap: 1.25rem;
  padding: 1.25rem;
  overflow: hidden;
}

/* VIDEO PANEL */
.video-panel {
  background: #061d15;
  border: 1px solid rgba(138, 191, 68, 0.15);
  border-radius: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  position: relative;
}
/* Stage 16:9 — kunci rasio konten (YouTube/video/slideshow) ke 1920:1080.
   Lebar mengisi panel, tinggi dihitung dari aspect-ratio; kalau tinggi panel
   lebih kecil dari yang dibutuhkan, fallback ke tinggi penuh dengan lebar
   16:9 (letterbox kiri/kanan, bukan distorsi).
   Placeholder dikecualikan — mengisi panel penuh agar logo & teks center. */
.video-panel > *:not(.video-placeholder) {
  aspect-ratio: 16 / 9;
  width: 100%;
  max-width: 100%;
  max-height: 100%;
}
.video-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.5rem;
  text-align: center;
}
.video-placeholder svg { width: 180px; height: 180px; }
.video-logo-img { width: 200px; height: 200px; object-fit: contain; }
.video-title {
  font-family: 'DM Serif Display', serif;
  font-size: 30px;
  color: #fff;
  font-weight: 400;
}
.video-tagline {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.4);
  max-width: 360px;
  line-height: 1.6;
}

/* YouTube iframe */
.yt-frame {
  width: 100%;
  height: 100%;
  border: none;
  display: block;
}

/* Slideshow */
.slideshow {
  width: 100%;
  height: 100%;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
}
.slide-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  opacity: 0;
  transition: opacity 0.6s ease;
}
.slide-img.active { opacity: 1; }
.slide-dots {
  position: absolute;
  bottom: 14px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: 6px;
  z-index: 2;
}
.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.35);
  transition: background 0.3s;
}
.dot.active { background: #8abf44; }

/* QUEUE PANEL */
.queue-panel {
  background: rgba(0, 0, 0, 0.25);
  border: 1px solid rgba(138, 191, 68, 0.15);
  border-radius: 18px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.queue-header {
  padding: 1rem 1.5rem;
  border-bottom: 1px solid rgba(138, 191, 68, 0.15);
}

.queue-header-title {
  font-family: 'DM Serif Display', serif;
  font-size: 20px;
}
.queue-header-sub {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.4);
  margin-top: 3px;
}

/* STATION GRID (4 kolom × 2 baris) */
.station-grid {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr 1fr;
  grid-auto-rows: 1fr;
  gap: 8px;
  padding: 1rem;
  overflow-y: auto;
}
.station-card {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 12px;
  padding: 0.7rem 0.85rem;
  display: flex;
  flex-direction: column;
  min-height: 0;
  transition: border-color 0.25s, background 0.25s;
}
.station-card.has-called {
  background: linear-gradient(135deg, rgba(138, 191, 68, 0.18), rgba(138, 191, 68, 0.04));
  border-color: rgba(138, 191, 68, 0.45);
  box-shadow: 0 0 18px rgba(138, 191, 68, 0.15);
}
.sc-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 0.4rem;
}
.sc-name {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.65);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.station-card.has-called .sc-name { color: #8abf44; }
.sc-count {
  font-size: 9.5px;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.4);
  background: rgba(255, 255, 255, 0.06);
  padding: 2px 7px;
  border-radius: 10px;
  flex-shrink: 0;
}
.sc-called-lbl {
  font-size: 9px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: rgba(138, 191, 68, 0.75);
  font-weight: 600;
  margin-bottom: 0.2rem;
}
.sc-called-lbl.muted { color: rgba(255, 255, 255, 0.3); }
.sc-called-num {
  font-family: 'DM Serif Display', serif;
  font-size: 38px;
  line-height: 1;
  color: #8abf44;
  letter-spacing: 0.03em;
  text-shadow: 0 0 18px rgba(138, 191, 68, 0.3);
}
.sc-called-num.muted {
  font-size: 28px;
  color: rgba(255, 255, 255, 0.2);
  text-shadow: none;
}
.sc-called-name {
  font-size: 12px;
  color: #fff;
  margin-top: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sc-called-poly {
  font-size: 10.5px;
  color: rgba(255, 255, 255, 0.55);
  margin-top: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sc-next {
  font-size: 10.5px;
  color: rgba(255, 255, 255, 0.45);
  margin-top: 4px;
}
.sc-next strong { color: rgba(138, 191, 68, 0.85); font-weight: 700; }

/* STATION TABS (control panel, tab Tampilan) */
.station-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 4px;
  background: rgba(0, 0, 0, 0.25);
  border-radius: 10px;
}
.station-tab {
  padding: 6px 12px;
  border: 1px solid transparent;
  background: transparent;
  color: rgba(255, 255, 255, 0.55);
  font-size: 12px;
  font-weight: 600;
  font-family: 'DM Sans', sans-serif;
  border-radius: 7px;
  cursor: pointer;
  transition: all 0.15s;
}
.station-tab:hover { color: #fff; background: rgba(138, 191, 68, 0.08); }
.station-tab.active {
  background: rgba(138, 191, 68, 0.18);
  border-color: rgba(138, 191, 68, 0.4);
  color: #fff;
}
.ctrl-section code {
  background: rgba(138, 191, 68, 0.12);
  color: #8abf44;
  padding: 1px 6px;
  border-radius: 4px;
  font-family: 'DM Mono', monospace;
  font-size: 11px;
}
.ctrl-feedback { font-size: 12px; padding: 6px 12px; border-radius: 8px; align-self: center; }
.ctrl-feedback.ok { background: rgba(138, 191, 68, 0.15); color: #8abf44; }
.ctrl-feedback.err { background: rgba(239, 68, 68, 0.15); color: #f87171; }

/* SOUND PRESET GRID (control panel) */
.sound-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
}
.sound-card {
  position: relative;
  padding: 10px 12px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 10px;
  text-align: left;
  cursor: pointer;
  font-family: 'DM Sans', sans-serif;
  color: rgba(255, 255, 255, 0.7);
  display: flex;
  flex-direction: column;
  gap: 3px;
  transition: all 0.15s;
}
.sound-card:hover { background: rgba(138, 191, 68, 0.08); border-color: rgba(138, 191, 68, 0.25); }
.sound-card.active {
  background: rgba(138, 191, 68, 0.15);
  border-color: rgba(138, 191, 68, 0.5);
  color: #fff;
}
.sound-card-name { font-size: 13px; font-weight: 600; }
.sound-card small { font-size: 10.5px; color: rgba(255, 255, 255, 0.4); }
.sound-card.active small { color: rgba(255, 255, 255, 0.65); }
.sound-test-btn {
  position: absolute;
  top: 8px; right: 8px;
  width: 24px; height: 24px;
  border-radius: 6px;
  background: rgba(138, 191, 68, 0.18);
  border: 1px solid rgba(138, 191, 68, 0.35);
  color: #8abf44;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s;
}
.sound-test-btn:hover { background: rgba(138, 191, 68, 0.35); }
.sound-test-btn svg { width: 10px; height: 10px; }

.now-serving {
  margin: 1.25rem;
  padding: 1.5rem;
  background: linear-gradient(135deg, rgba(138, 191, 68, 0.18), rgba(138, 191, 68, 0.05));
  border: 1.5px solid rgba(138, 191, 68, 0.35);
  border-radius: 16px;
  text-align: center;
}
.now-serving.empty { opacity: 0.45; }
.ns-label {
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: rgba(138, 191, 68, 0.7);
  font-weight: 600;
  margin-bottom: 0.5rem;
}
.ns-number {
  font-family: 'DM Serif Display', serif;
  font-size: 88px;
  line-height: 1;
  color: #8abf44;
  letter-spacing: 0.04em;
  margin-bottom: 0.5rem;
  text-shadow: 0 0 30px rgba(138, 191, 68, 0.4);
}
.ns-number.muted { font-size: 56px; color: rgba(255, 255, 255, 0.3); text-shadow: none; }
.ns-name {
  font-size: 22px;
  font-weight: 500;
  margin-bottom: 4px;
}
.ns-poly { font-size: 13px; color: rgba(255, 255, 255, 0.5); }
.ns-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 0.85rem;
  background: rgba(138, 191, 68, 0.2);
  color: #8abf44;
  border: 1px solid rgba(138, 191, 68, 0.4);
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}
.ns-badge svg {
  width: 14px;
  height: 14px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
}
.ns-done-btn {
  display: inline-flex; align-items: center; gap: 5px;
  margin-top: .65rem; padding: 5px 14px;
  background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
  border-radius: 20px; color: rgba(255,255,255,.7); font-size: 11px; font-weight: 600;
  cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .2s;
}
.ns-done-btn:hover { background: rgba(138,191,68,.2); border-color: rgba(138,191,68,.4); color: #8abf44; }
.ns-done-btn svg { width: 12px; height: 12px; }

/* Queue tabs bar */
.queue-tabs-bar {
  display: flex; border-bottom: 1px solid rgba(255,255,255,.08);
  margin: 0 1.25rem .5rem; gap: 0; flex-shrink: 0;
}
.qtab-tv {
  flex: 1; padding: 8px 4px; font-size: 11px; font-weight: 600;
  color: rgba(255,255,255,.4); background: none; border: none; cursor: pointer;
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  font-family: 'DM Sans', sans-serif; display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: all .15s;
}
.qtab-tv:hover { color: rgba(255,255,255,.7); }
.qtab-tv.a { color: #8abf44; border-bottom-color: #8abf44; }
.qtab-ct { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: rgba(138,191,68,.2); color: #8abf44; }
.qtab-ct.done { background: rgba(255,255,255,.1); color: rgba(255,255,255,.5); }

.queue-list {
  flex: 1;
  overflow-y: auto;
  padding: 0 1.25rem 1.25rem;
}
.queue-section-lbl {
  font-size: 10px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.3);
  font-weight: 600;
  margin-bottom: 0.5rem;
}
.queue-section-lbl.mt { margin-top: 1rem; }
.queue-item {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.65rem 0.9rem;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.05);
  border-radius: 12px;
  margin-bottom: 6px;
}
.queue-item.done { opacity: 0.65; }
.qi-num {
  font-family: 'DM Serif Display', serif;
  font-size: 26px;
  color: #8abf44;
  letter-spacing: 0.03em;
  min-width: 70px;
}
.qi-num.muted { color: rgba(255, 255, 255, 0.25); }
.qi-info { flex: 1; min-width: 0; }
.qi-name {
  font-size: 14px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.qi-name.muted { color: rgba(255, 255, 255, 0.35); }
.qi-poly { font-size: 11px; color: rgba(255, 255, 255, 0.4); margin-top: 2px; }
.qi-status {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.qi-status svg {
  width: 16px;
  height: 16px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
}
.qi-status.waiting { background: rgba(252, 211, 77, 0.15); color: #fcd34d; }
.qi-status.done { background: rgba(138, 191, 68, 0.15); color: #8abf44; }
.qi-empty {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.25);
  text-align: center;
  padding: 1rem;
}

/* BOTTOM TICKER */
.tv-bottom {
  height: 56px;
  display: flex;
  align-items: center;
  background: rgba(0, 0, 0, 0.3);
  border-top: 1px solid rgba(138, 191, 68, 0.15);
  flex-shrink: 0;
}
.ticker-label {
  background: #8abf44;
  color: #061d15;
  padding: 6px 18px;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.1em;
  margin: 0 1rem;
  border-radius: 4px;
  flex-shrink: 0;
}
.ticker-wrap { flex: 1; overflow: hidden; position: relative; }
.ticker-inner {
  display: inline-flex;
  white-space: nowrap;
  animation: scroll-left 32s linear infinite;
  gap: 0.85rem;
  align-items: center;
}
.ticker-item {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.7);
}
.ticker-item svg {
  width: 14px;
  height: 14px;
  fill: none;
  stroke: #8abf44;
  stroke-width: 2;
  stroke-linecap: round;
}
.ticker-sep { color: rgba(138, 191, 68, 0.5); font-size: 18px; }
.tv-bottom-right {
  padding: 0 0.75rem;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.25);
  letter-spacing: 0.05em;
  flex-shrink: 0;
}

/* CONTROL TRIGGER BUTTON */
.ctrl-btn {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: none;
  background: transparent;
  color: rgba(255, 255, 255, 0.22);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 0.75rem;
  flex-shrink: 0;
  transition: color 0.2s, background 0.2s;
}
.ctrl-btn svg { width: 16px; height: 16px; }
.ctrl-btn:hover {
  color: #8abf44;
  background: rgba(138, 191, 68, 0.1);
}

/* PIN MODAL */
.pin-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.78);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  backdrop-filter: blur(4px);
}
.pin-dialog {
  background: linear-gradient(160deg, #0e3524, #081f15);
  border: 1px solid rgba(138, 191, 68, 0.25);
  border-radius: 20px;
  padding: 2.5rem 2rem;
  width: 340px;
  text-align: center;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
}
.pin-dialog.shake { animation: pin-shake 0.5s ease; }
.pin-icon {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: rgba(138, 191, 68, 0.1);
  border: 1px solid rgba(138, 191, 68, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1.25rem;
}
.pin-icon svg { width: 26px; height: 26px; }
.pin-title {
  font-family: 'DM Serif Display', serif;
  font-size: 22px;
  font-weight: 400;
  margin: 0 0 4px;
}
.pin-sub {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.4);
  margin: 0 0 1.5rem;
}
.pin-inputs {
  display: flex;
  gap: 12px;
  justify-content: center;
  margin-bottom: 1rem;
}
.pin-box {
  width: 56px;
  height: 64px;
  border-radius: 12px;
  border: 1.5px solid rgba(138, 191, 68, 0.25);
  background: rgba(0, 0, 0, 0.3);
  color: #8abf44;
  font-size: 28px;
  text-align: center;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
  font-family: 'DM Serif Display', serif;
  caret-color: transparent;
}
.pin-box:focus {
  border-color: #8abf44;
  box-shadow: 0 0 0 3px rgba(138, 191, 68, 0.15);
}
.pin-box.filled { border-color: rgba(138, 191, 68, 0.5); }
.pin-box.error { border-color: rgba(239, 68, 68, 0.6); }
.pin-err {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 13px;
  color: #f87171;
  margin: 0 0 1rem;
}
.pin-err svg { width: 14px; height: 14px; }
.pin-actions { display: flex; gap: 10px; justify-content: center; margin-top: 1rem; }
.pin-btn {
  padding: 10px 24px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  border: none;
  transition: opacity 0.2s, transform 0.15s;
}
.pin-btn:hover { opacity: 0.85; transform: translateY(-1px); }
.pin-btn.primary {
  background: #8abf44;
  color: #061d15;
}
.pin-btn.ghost {
  background: rgba(255, 255, 255, 0.07);
  color: rgba(255, 255, 255, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.1);
}

/* LOCAL VIDEO */
.local-video { width: 100%; height: 100%; object-fit: contain; background: #000; display: block; }

/* FLASH OVERLAY */
.flash-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: linear-gradient(135deg, #061d15 0%, #0a2e22 50%, #061d15 100%);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; animation: flash-in .3s ease;
}
@keyframes flash-in { from { opacity: 0; transform: scale(1.04); } to { opacity: 1; transform: scale(1); } }
.flash-content { text-align: center; }
.flash-label-top {
  font-size: 16px; letter-spacing: .3em; text-transform: uppercase;
  color: rgba(138,191,68,.7); font-weight: 600; margin-bottom: 1rem;
  font-family: 'DM Sans', sans-serif;
}
.flash-num {
  font-family: 'DM Serif Display', serif;
  font-size: clamp(100px, 20vw, 220px); line-height: 1;
  color: #8abf44; letter-spacing: .04em;
  text-shadow: 0 0 80px rgba(138,191,68,.6), 0 0 40px rgba(138,191,68,.3);
  animation: flash-pulse 1.5s ease-in-out infinite;
}
@keyframes flash-pulse { 0%,100%{text-shadow:0 0 80px rgba(138,191,68,.6),0 0 40px rgba(138,191,68,.3)} 50%{text-shadow:0 0 120px rgba(138,191,68,.9),0 0 60px rgba(138,191,68,.5)} }
.flash-name {
  font-size: clamp(22px, 3vw, 40px); font-weight: 500; margin-top: 1rem; color: #fff;
  font-family: 'DM Sans', sans-serif;
}
.flash-poly { font-size: clamp(14px, 2vw, 24px); color: rgba(255,255,255,.5); margin-top: .4rem; font-family: 'DM Sans', sans-serif; }
.flash-badge {
  display: inline-flex; align-items: center; gap: 8px; margin-top: 2rem;
  background: rgba(138,191,68,.2); border: 1.5px solid rgba(138,191,68,.4);
  color: #8abf44; padding: 10px 24px; border-radius: 30px;
  font-size: clamp(13px, 1.5vw, 18px); font-weight: 700; font-family: 'DM Sans', sans-serif;
}
.flash-badge svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.flash-hint { font-size: 12px; color: rgba(255,255,255,.3); margin-top: 2.5rem; font-family: 'DM Sans', sans-serif; }

/* Ctrl toggles */
.ctrl-toggles { display: flex; flex-direction: column; gap: 10px; }
.ctrl-toggle { display: flex; align-items: center; gap: 10px; cursor: pointer; }
.ctrl-toggle input { display: none; }
.toggle-track {
  width: 36px; height: 20px; border-radius: 10px; background: rgba(255,255,255,.15);
  position: relative; transition: background .2s; flex-shrink: 0;
}
.ctrl-toggle input:checked ~ .toggle-track { background: #8abf44; }
.toggle-track::after {
  content: ''; position: absolute; width: 14px; height: 14px; border-radius: 50%;
  background: #fff; top: 3px; left: 3px; transition: left .2s;
}
.ctrl-toggle input:checked ~ .toggle-track::after { left: 19px; }
.toggle-label { font-size: 12px; color: rgba(255,255,255,.7); }

/* Antrean tab controls */
.antr-ctrl-row { display: flex; gap: .5rem; margin-bottom: .85rem; flex-wrap: wrap; }
.antr-call { background: rgba(138,191,68,.2) !important; color: #8abf44 !important; border-color: rgba(138,191,68,.35) !important; }
.antr-call:hover:not(:disabled) { background: rgba(138,191,68,.35) !important; }
.antr-done { background: rgba(255,255,255,.08) !important; color: rgba(255,255,255,.7) !important; }
.antr-current { background: rgba(138,191,68,.12); border: 1px solid rgba(138,191,68,.3); border-radius: 10px; padding: .7rem 1rem; text-align: center; margin-top: .4rem; }
.antr-lbl { font-size: 9px; letter-spacing: .2em; text-transform: uppercase; color: rgba(138,191,68,.7); font-weight: 600; margin-bottom: .25rem; }
.antr-num { font-family: 'DM Serif Display', serif; font-size: 36px; color: #8abf44; line-height: 1; }
.antr-meta { font-size: 11px; color: rgba(255,255,255,.5); margin-top: .25rem; }
.antr-empty { font-size: 12px; color: rgba(255,255,255,.3); text-align: center; padding: .65rem; }
.antr-list { display: flex; flex-direction: column; gap: 5px; }
.antr-item { display: flex; align-items: center; gap: .55rem; padding: .5rem .7rem; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.06); border-radius: 8px; }
.antr-item-num { font-size: 12px; font-weight: 700; color: #8abf44; width: 50px; flex-shrink: 0; font-family: 'DM Mono', monospace; }
.antr-item-name { flex: 1; font-size: 12px; color: rgba(255,255,255,.8); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.antr-item-poly { font-size: 10px; color: rgba(255,255,255,.35); flex-shrink: 0; }
.antr-call-btn { width: 26px; height: 26px; border-radius: 6px; background: rgba(138,191,68,.15); border: 1px solid rgba(138,191,68,.3); display: flex; align-items: center; justify-content: center; cursor: pointer; color: #8abf44; transition: all .15s; flex-shrink: 0; }
.antr-call-btn:hover { background: rgba(138,191,68,.3); }
.antr-call-btn svg { width: 12px; height: 12px; }

/* CONTROL PANEL MODAL */
.ctrl-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.72);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  backdrop-filter: blur(4px);
}
.ctrl-panel {
  background: linear-gradient(160deg, #0d3221, #071a10);
  border: 1px solid rgba(138, 191, 68, 0.2);
  border-radius: 20px;
  width: min(860px, 94vw);
  max-height: 88vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 32px 80px rgba(0, 0, 0, 0.55);
}
.ctrl-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid rgba(138, 191, 68, 0.15);
  flex-shrink: 0;
}
.ctrl-header-left {
  display: flex;
  align-items: center;
  gap: 10px;
  font-family: 'DM Serif Display', serif;
  font-size: 20px;
  color: #fff;
}
.ctrl-header-left svg { width: 22px; height: 22px; }
.ctrl-close {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.05);
  color: rgba(255, 255, 255, 0.5);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s, color 0.2s;
}
.ctrl-close svg { width: 16px; height: 16px; }
.ctrl-close:hover { background: rgba(239, 68, 68, 0.15); color: #f87171; }

.ctrl-tabs {
  display: flex;
  gap: 4px;
  padding: 0.75rem 1.5rem;
  border-bottom: 1px solid rgba(138, 191, 68, 0.12);
  flex-shrink: 0;
}
.ctrl-tab {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 16px;
  border-radius: 8px;
  border: 1px solid transparent;
  background: transparent;
  color: rgba(255, 255, 255, 0.45);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  font-family: 'DM Sans', sans-serif;
}
.ctrl-tab svg { width: 15px; height: 15px; }
.ctrl-tab:hover { background: rgba(255, 255, 255, 0.05); color: rgba(255, 255, 255, 0.75); }
.ctrl-tab.active {
  background: rgba(138, 191, 68, 0.15);
  border-color: rgba(138, 191, 68, 0.3);
  color: #8abf44;
}

.ctrl-body {
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
}

.ctrl-section { display: flex; flex-direction: column; gap: 1.25rem; }
.ctrl-sub-section { display: flex; flex-direction: column; gap: 8px; }
.ctrl-lbl {
  font-size: 11px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.35);
  font-weight: 600;
  margin: 0;
}
.ctrl-field-lbl {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.55);
  display: block;
}
.ctrl-hint {
  font-size: 12px;
  line-height: 1.5;
  color: rgba(255, 255, 255, 0.4);
  margin: 4px 0 0;
}
/* Branding tab */
.brand-logo-row { display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap; }
.brand-logo-preview {
  width: 120px;
  height: 120px;
  flex-shrink: 0;
  border: 1px dashed rgba(138, 191, 68, 0.3);
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.brand-logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.brand-logo-empty {
  font-size: 11px;
  text-align: center;
  color: rgba(255, 255, 255, 0.3);
  line-height: 1.5;
  padding: 0 8px;
}
.brand-logo-actions { display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 200px; }
.brand-btn-muted { background: rgba(255, 255, 255, 0.08) !important; color: rgba(255, 255, 255, 0.7) !important; }
.ctrl-row { display: flex; gap: 8px; }
.ctrl-input {
  flex: 1;
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(138, 191, 68, 0.2);
  border-radius: 10px;
  padding: 10px 14px;
  color: #fff;
  font-size: 14px;
  font-family: 'DM Sans', sans-serif;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.ctrl-input:focus {
  border-color: rgba(138, 191, 68, 0.5);
  box-shadow: 0 0 0 3px rgba(138, 191, 68, 0.1);
}
.ctrl-input::placeholder { color: rgba(255, 255, 255, 0.25); }
.ctrl-textarea {
  width: 100%;
  box-sizing: border-box;
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(138, 191, 68, 0.2);
  border-radius: 10px;
  padding: 10px 14px;
  color: #fff;
  font-size: 13px;
  font-family: 'DM Sans', sans-serif;
  outline: none;
  resize: vertical;
  transition: border-color 0.2s;
}
.ctrl-textarea:focus { border-color: rgba(138, 191, 68, 0.5); }
.ctrl-textarea::placeholder { color: rgba(255, 255, 255, 0.25); }
.ctrl-action-btn {
  padding: 10px 20px;
  background: #8abf44;
  color: #061d15;
  border: none;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: opacity 0.2s, transform 0.15s;
  flex-shrink: 0;
  font-family: 'DM Sans', sans-serif;
}
.ctrl-action-btn:hover { opacity: 0.85; transform: translateY(-1px); }
.ctrl-action-btn:disabled { opacity: 0.35; cursor: not-allowed; transform: none; }
.ctrl-err { font-size: 13px; color: #f87171; margin: 4px 0 0; }
.ctrl-ok {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #8abf44;
  margin: 4px 0 0;
}
.ctrl-ok svg { width: 14px; height: 14px; }
.ctrl-empty {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.25);
  text-align: center;
  padding: 1.5rem;
  border: 1px dashed rgba(255, 255, 255, 0.1);
  border-radius: 10px;
}
.ctrl-feedback {
  font-size: 13px;
  margin: 8px 0 0;
  padding: 8px 12px;
  border-radius: 8px;
}
.ctrl-feedback.ok { background: rgba(138, 191, 68, 0.12); color: #8abf44; border: 1px solid rgba(138, 191, 68, 0.25); }
.ctrl-feedback.err { background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); }

/* Mode cards */
.mode-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}
.mode-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 1.25rem 0.75rem;
  border-radius: 14px;
  border: 1.5px solid rgba(255, 255, 255, 0.08);
  background: rgba(0, 0, 0, 0.2);
  color: rgba(255, 255, 255, 0.5);
  cursor: pointer;
  transition: all 0.2s;
  font-family: 'DM Sans', sans-serif;
}
.mode-card svg { width: 28px; height: 28px; }
.mode-card span { font-size: 14px; font-weight: 600; }
.mode-card small { font-size: 11px; color: rgba(255, 255, 255, 0.3); }
.mode-card:hover {
  border-color: rgba(138, 191, 68, 0.25);
  background: rgba(138, 191, 68, 0.06);
  color: rgba(255, 255, 255, 0.8);
}
.mode-card.active {
  border-color: #8abf44;
  background: rgba(138, 191, 68, 0.12);
  color: #8abf44;
}
.mode-card.active small { color: rgba(138, 191, 68, 0.6); }
.mode-card:disabled { opacity: 0.4; cursor: not-allowed; }

/* Slideshow rows */
.slide-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  background: rgba(0, 0, 0, 0.2);
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 10px;
  margin-bottom: 6px;
}
.slide-idx {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.3);
  min-width: 18px;
  text-align: center;
}
.slide-thumb-wrap {
  width: 48px;
  height: 32px;
  border-radius: 6px;
  overflow: hidden;
  flex-shrink: 0;
  background: rgba(0,0,0,0.4);
}
.slide-thumb { width: 100%; height: 100%; object-fit: cover; }
.slide-url {
  flex: 1;
  font-size: 12px;
  color: rgba(255, 255, 255, 0.4);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ctrl-range { flex: 1; accent-color: #8abf44; }
.ctrl-dur-val {
  font-size: 14px;
  font-weight: 600;
  color: #8abf44;
  min-width: 32px;
  text-align: right;
}

/* Ticker rows */
.ticker-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 1rem; }
.ticker-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  background: rgba(0, 0, 0, 0.2);
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 10px;
}
.ticker-idx {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.3);
  min-width: 18px;
  text-align: center;
}
.ticker-msg-text {
  flex: 1;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.7);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ticker-edit-input { flex: 1; font-size: 13px; }
.ticker-row-actions { display: flex; gap: 4px; flex-shrink: 0; }

/* Icon buttons */
.icon-btn {
  width: 30px;
  height: 30px;
  border-radius: 7px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(255, 255, 255, 0.04);
  color: rgba(255, 255, 255, 0.45);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.18s;
}
.icon-btn svg { width: 13px; height: 13px; }
.icon-btn:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
.icon-btn:disabled { opacity: 0.25; cursor: not-allowed; }
.icon-btn.danger:hover { background: rgba(239, 68, 68, 0.15); color: #f87171; border-color: rgba(239, 68, 68, 0.2); }
.icon-btn.ok:hover { background: rgba(138, 191, 68, 0.15); color: #8abf44; border-color: rgba(138, 191, 68, 0.3); }

/* Animations */
@keyframes scroll-left {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}
@keyframes blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
@keyframes pin-shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-8px); }
  40% { transform: translateX(8px); }
  60% { transform: translateX(-6px); }
  80% { transform: translateX(6px); }
}
</style>
