<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { useRoute } from 'vue-router'
import { antreanTvApi } from '@/services/api'
import logoPvPutih from '@/assets/images/logo-pv-putih.png'

const route = useRoute()

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
  clinic_name:         'RUMAH SAKIT MATA PRIMA VISION',
  clinic_subtitle:     'Medan · Layar Antrean',
  placeholder_title:   'RS MATA PRIMA VISION MEDAN',
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
    .replaceAll('{dokter}',  q.dokter && q.dokter !== '—' ? q.dokter : '')
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

// Nama dokter untuk antrean DOKTER (dari jadwal dokter aktif via queue_prefix).
// Stasiun lain tak punya konsep dokter di alur ini → null (baris disembunyikan).
function stationDoctor(row, stationKey) {
  if (stationKey !== 'DOKTER') return null
  const prefix = prefixOf(row.queue_number)
  const doc    = prefix ? doctorByPrefix.value[prefix] : null
  return doc?.nama_dokter ?? null
}

function mapStationRow(row, stationKey) {
  return {
    id:     row.id,
    num:    row.queue_number,
    name:   row.patient_name ?? '—',
    poly:   stationPoly(row, stationKey),
    dokter: stationDoctor(row, stationKey),
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

// ─── Mode "Poliklinik" (1 layar dinamis: poli + farmasi + media berputar) ────
// Aktif via query ?mode=poli. Layout grid 8-stasiun lama tetap dipakai bila tidak.
const tvMode = computed(() => (route.query.mode === 'poli' ? 'poli' : 'grid'))

// Status yang dianggap "sedang dilayani" (tampil sebagai nomor dipanggil).
const ACTIVE_CALLED = ['CALLED', 'IN_PROGRESS']

// Antrean poliklinik DIPECAH PER DOKTER (regulasi BPJS): tiap dokter aktif
// punya nomor yang sedang dipanggil + jumlah menunggu di antreannya sendiri.
// Diturunkan dari snapshot.DOKTER.rows (di-maintain WS + poll) → realtime.
const poliklinikView = computed(() => {
  const rows = snapshot.value.DOKTER?.rows ?? []
  const groups = {}
  for (const r of rows) {
    const p = prefixOf(r.queue_number)
    if (!p) continue
    ;(groups[p] ??= []).push(r)
  }
  // Tampilkan semua dokter aktif (termasuk yang 0 antrean) + prefix yang ada
  // antrean walau dokternya tak terjadwal (fallback, mis. jadwal terhapus).
  const prefixes = new Set([...Object.keys(doctorByPrefix.value), ...Object.keys(groups)])
  return [...prefixes]
    .sort((a, b) => a.localeCompare(b, undefined, { numeric: true }))
    .map((p) => {
      const doc     = doctorByPrefix.value[p] || null
      const grp     = groups[p] ?? []
      const waiting = grp.filter((r) => r.status === 'WAITING')
      const called  = [...grp].reverse().find((r) => ACTIVE_CALLED.includes(r.status)) || null
      const poliRaw = (doc?.poliklinik || '').trim()
      const poli    = poliRaw
        ? (/^poli\b/i.test(poliRaw) ? poliRaw : `Poli ${poliRaw}`)
        : 'Poliklinik'
      return {
        prefix:       p,
        dokter:       doc?.nama_dokter ?? null,
        poli,
        room:         doc?.room ?? null,
        serviceType:  doc?.service_type ?? null,
        calledNum:    called?.queue_number ?? null,
        calledName:   called?.patient_name ?? null,
        waitingCount: waiting.length,
        nextNum:      waiting[0]?.queue_number ?? null,
      }
    })
})

// Jumlah kolom papan poli (auto-fit, maks 4) — untuk class layout.
const poliCols = computed(() => Math.min(Math.max(poliklinikView.value.length, 1), 4))

// Antrean farmasi (1 stasiun): nomor dipanggil + jumlah menunggu + daftar
// nomor siap diambil (CALLED terakhir) & antrean berikutnya.
const farmasiView = computed(() => {
  const rows    = snapshot.value.FARMASI?.rows ?? []
  const waiting = rows.filter((r) => r.status === 'WAITING')
  const called  = [...rows].reverse().find((r) => ACTIVE_CALLED.includes(r.status)) || null
  const ready   = rows
    .filter((r) => ACTIVE_CALLED.includes(r.status))
    .sort((a, b) => String(b.called_at ?? '').localeCompare(String(a.called_at ?? '')))
    .slice(0, 4)
    .map((r) => r.queue_number)
  return {
    calledNum:    called?.queue_number ?? null,
    calledName:   called?.patient_name ?? null,
    waitingCount: waiting.length,
    nextList:     waiting.slice(0, 6).map((r) => r.queue_number),
    readyList:    ready,
  }
})

// Stasiun alur lainnya — strip ringkas pada scene "Stasiun".
const STRIP_STATIONS = ['ADMISI', 'TRIASE', 'REFRAKSIONIS', 'PENUNJANG', 'BEDAH', 'KASIR']
const stationStripView = computed(() =>
  STRIP_STATIONS.map((key) => ({
    key,
    label:        stationLabel[key] ?? key,
    calledNum:    stationView.value[key]?.called?.num ?? null,
    waitingCount: stationView.value[key]?.waiting.length ?? 0,
    nextNum:      stationView.value[key]?.waiting[0]?.num ?? null,
  }))
)

// ─── Mesin rotasi scene (mode poli) ─────────────────────────────────────────
// Panel berputar: Media (lebar dominan) → Poliklinik → Farmasi → Stasiun.
// Scene kosong di-skip. Lebar media menyusut saat scene antrean aktif.
const sceneLabel = { media: 'Media', poli: 'Poliklinik', farmasi: 'Farmasi', stasiun: 'Stasiun' }
const SCENES = [
  { key: 'media',   dur: 25 },
  { key: 'poli',    dur: 20 },
  { key: 'farmasi', dur: 15 },
  { key: 'stasiun', dur: 12 },
]
const activeScene = ref('media')
let sceneTimer = null

function sceneHasContent(key) {
  if (key === 'media')   return true
  if (key === 'poli')    return poliklinikView.value.length > 0
  if (key === 'farmasi') return !!farmasiView.value.calledNum || farmasiView.value.waitingCount > 0
  if (key === 'stasiun') return stationStripView.value.some((s) => s.calledNum || s.waitingCount > 0)
  return false
}
function pickNextScene() {
  const order = SCENES.map((s) => s.key)
  const idx = order.indexOf(activeScene.value)
  for (let i = 1; i <= order.length; i++) {
    const cand = order[(idx + i) % order.length]
    if (sceneHasContent(cand)) return cand
  }
  return 'media'
}
function scheduleNextScene() {
  if (sceneTimer) clearTimeout(sceneTimer)
  const dur = (SCENES.find((s) => s.key === activeScene.value)?.dur ?? 20) * 1000
  sceneTimer = setTimeout(() => {
    activeScene.value = pickNextScene()
    scheduleNextScene()
  }, dur)
}
function startSceneRotation() {
  if (!sceneHasContent(activeScene.value)) activeScene.value = pickNextScene()
  scheduleNextScene()
}
function stopSceneRotation() {
  if (sceneTimer) { clearTimeout(sceneTimer); sceneTimer = null }
}

// Scene yang punya konten (untuk indikator titik).
const visibleScenes = computed(() => SCENES.filter((s) => sceneHasContent(s.key)))

// Pindah mode tanpa reload (query ?mode berubah, komponen dipakai ulang oleh
// router) → start/stop rotasi agar timer tidak bocor atau mati saat dibutuhkan.
watch(tvMode, (m) => {
  if (m === 'poli') startSceneRotation()
  else stopSceneRotation()
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
        id:     called.id,
        num:    called.queue_number,
        name:   called.patient_name ?? '—',
        poly:   stationPoly(called, key),
        dokter: stationDoctor(called, key),
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
// Safety poll SELALU jalan berdampingan dengan WS — WS memberi update instan,
// poll merekonsiliasi event yang sempat hilang (Reverb restart, blip jaringan,
// gap saat resubscribe). Aman dari panggilan dobel karena `announcedCalls`
// (dedup durable) menyaring panggilan yang sudah diumumkan via WS.
const POLL_WS_OK   = 45_000   // WS sehat → poll lambat (sekadar jaring pengaman)
const POLL_WS_DOWN = 3_000    // WS mati → poll cepat (jadi sumber update utama)

function connectWs() {
  // Selalu mulai dengan poll cepat sampai WS mengonfirmasi 'connected'.
  setPollRate(POLL_WS_DOWN)

  const appKey = import.meta.env.VITE_REVERB_APP_KEY
  if (!appKey) return   // tak ada WS → tetap polling cepat selamanya

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
    tvChannel.bind('media-updated', handleMediaEvent)

    // (Re)connect: rekonsiliasi snapshot untuk menutup event yang hilang selama
    // gap, lalu turunkan poll ke mode lambat. pusher-js auto-resubscribe channel
    // + binding tetap melekat, jadi tak perlu bind ulang.
    pusher.connection.bind('connected', () => {
      fetchSnapshot()
      registerDevice()   // rekonsiliasi media efektif TV ini setelah gap
      setPollRate(POLL_WS_OK)
    })
    // WS goyah → percepat poll supaya update tetap cepat sampai WS pulih.
    const degrade = () => setPollRate(POLL_WS_DOWN)
    pusher.connection.bind('unavailable',  degrade)
    pusher.connection.bind('disconnected', degrade)
    pusher.connection.bind('error',        degrade)
  }).catch(() => setPollRate(POLL_WS_DOWN))
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
        id:     normalized.id,
        num:    normalized.queue_number,
        name:   normalized.patient_name,
        poly:   stationPoly(normalized, station),
        dokter: stationDoctor(normalized, station),
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

// Broadcast media bertarget: device_key null = perubahan GLOBAL (terapkan hanya
// bila TV ini synced); device_key === deviceKey TV ini = perubahan khusus TV ini
// (selalu terapkan; payload memuat flag `synced` yang memutakhirkan mediaSynced).
// device_key milik TV lain → diabaikan.
function handleMediaEvent(payload) {
  const targetKey = payload?.device_key ?? null
  if (targetKey === null) {
    if (mediaSynced.value) applyMediaPayload(payload?.media)
  } else if (targetKey === deviceKey) {
    applyMediaPayload(payload?.media)
  }
}

// Setel ulang interval poll snapshot. Idempoten: hentikan timer lama dulu lalu
// pasang yang baru. Tidak melepas binding WS — poll & WS sengaja koeksis
// (lihat catatan di POLL_WS_OK/DOWN); dedup `announcedCalls` cegah dobel.
let currentPollMs = null
function setPollRate(intervalMs) {
  if (currentPollMs === intervalMs && pollInterval) return
  currentPollMs = intervalMs
  stopPolling()
  pollInterval = setInterval(fetchSnapshot, intervalMs)
}

function stopPolling() {
  if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
  currentPollMs = null
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
  await Promise.all([fetchSnapshot(), fetchActiveDoctors(), fetchDisplaySettings(), fetchAudioDefaults(), fetchBrandingSettings(), registerDevice()])
  connectWs()
  // Refresh dokter aktif tiap 2 menit (toggle aktif/non-aktif dari menu dokter)
  doctorPollInterval = setInterval(fetchActiveDoctors, 120_000)
  // Heartbeat status TV tiap 60 detik (last_seen → indikator online di panel)
  heartbeatInterval = setInterval(heartbeat, 60_000)
  // TTS voices: load awal + listen perubahan (Chrome lazy-load voice list)
  loadTtsVoices()
  if (window.speechSynthesis) {
    window.speechSynthesis.onvoiceschanged = loadTtsVoices
  }
  // Auto-reload tengah malam — reset state & fetch snapshot tanggal baru
  scheduleMidnightReset()
  // Audio unlock listener — auto-aktif saat user pertama interact
  installUnlockListeners()
  // Mode poli: mulai rotasi scene (media ⇄ poliklinik ⇄ farmasi ⇄ stasiun)
  if (tvMode.value === 'poli') startSceneRotation()
})

onUnmounted(() => {
  clearInterval(timer)
  if (doctorPollInterval) clearInterval(doctorPollInterval)
  if (heartbeatInterval) clearInterval(heartbeatInterval)
  disconnectWs()
  stopSlideshow()
  stopSceneRotation()
  if (slideIntervalDebounce) clearTimeout(slideIntervalDebounce)
  if (window.speechSynthesis) {
    window.speechSynthesis.cancel()
    // Lepas handler global — kalau tidak, onvoiceschanged tetap merujuk
    // loadTtsVoices (closure komponen ini) walau sudah unmount → leak +
    // menumpuk saat navigasi/auto-reload tengah malam.
    window.speechSynthesis.onvoiceschanged = null
  }
  if (midnightTimer) clearTimeout(midnightTimer)
  removeUnlockListeners()
  // Tutup AudioContext + lepas cache <audio> supaya tidak menumpuk saat
  // navigasi keluar-masuk TV.
  audioCtx?.close()
  audioCtx = null
  audioCache.clear()
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
// PIN kontrol disimpan per-perangkat di localStorage supaya tetap bertahan
// setelah reload (termasuk auto-reload tengah malam). Default '1234' bila belum
// pernah diubah / storage tidak tersedia.
const PIN_STORAGE_KEY = 'av_tv_control_pin'
function loadStoredPin() {
  try {
    const v = localStorage.getItem(PIN_STORAGE_KEY)
    return /^\d{4}$/.test(v) ? v : '1234'
  } catch (_) {
    return '1234'
  }
}
const controlPin = ref(loadStoredPin())
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
    openMediaControl()
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

// ─── Cakupan tampilan media + registry TV per-perangkat ─────────────────────
// slideScope: 'panel' (panel kiri, default) | 'fullscreen' (seluruh layar).
// flashOverFullscreen: saat fullscreen, apakah flash panggilan tetap muncul.
const slideScope = ref('panel')
const flashOverFullscreen = ref(true)

// Identitas perangkat (per-TV) — token unik persist di localStorage supaya TV
// ini dikenali server walau di-reload (termasuk auto-reload tengah malam).
const DEVICE_KEY_STORAGE = 'av_tv_device_key'
function loadOrCreateDeviceKey() {
  try {
    let k = localStorage.getItem(DEVICE_KEY_STORAGE)
    if (!k || k.length < 8) {
      k = 'tv_' + ((window.crypto?.randomUUID?.() ?? (Math.random().toString(36).slice(2) + Date.now().toString(36))))
      localStorage.setItem(DEVICE_KEY_STORAGE, k)
    }
    return k
  } catch (_) {
    return 'tv_eph_' + Math.random().toString(36).slice(2)
  }
}
const deviceKey   = loadOrCreateDeviceKey()
const deviceId    = ref(null)         // UUID dari server (untuk update/delete)
const deviceName  = ref('TV ini')
const mediaSynced = ref(true)         // true = ikut media global; false = mandiri
const devices     = ref([])           // daftar TV terdaftar (untuk panel kontrol)

// ─── Editor media TERPISAH dari tampilan layar ini ───────────────────────────
// Admin bisa memilih TARGET (Global / TV mana pun) dan menyunting medianya TANPA
// mengubah layar yang sedang dipakai admin. `editor` = draft target terpilih;
// `editorTarget` = ke mana perubahan disimpan.
const editorTarget = ref({ kind: 'global', id: null, key: null, name: 'Semua TV (Global)' })
function blankEditor() {
  return {
    media_mode: 'placeholder', youtube_embed_url: '', external_video_url: '',
    video_autoplay: true, video_loop: true,
    has_uploaded_file: false, local_video_name: '', local_video_url: '',
    slides: [], slide_interval: 8, slide_scope: 'panel', flash_over_fullscreen: true,
  }
}
const editor = reactive(blankEditor())
const editorLoading = ref(false)
let lastEditorInterval = null   // guard agar load tidak memicu write-back interval

// Saat fullscreen + flash dimatikan → TV jadi papan iklan murni: jangan
// umumkan panggilan sama sekali (tanpa flash & tanpa TTS).
const suppressCalls = computed(() => slideScope.value === 'fullscreen' && !flashOverFullscreen.value)

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
  if (s.slide_scope) slideScope.value = s.slide_scope
  if (typeof s.flash_over_fullscreen === 'boolean') flashOverFullscreen.value = s.flash_over_fullscreen
  if (typeof s.synced === 'boolean') mediaSynced.value = s.synced
  if (s.device_name) deviceName.value = s.device_name
  if (Array.isArray(s.slides)) slides.value = s.slides
  if (typeof s.slide_interval === 'number') slideDuration.value = s.slide_interval
  if (Array.isArray(s.ticker_messages)) tickerMessages.value = s.ticker_messages

  // Clamp slideIndex: slides bisa berubah (mis. broadcast dari TV lain) dan
  // index lama bisa melebihi panjang baru → render slide invalid.
  if (slideIndex.value >= slides.value.length) slideIndex.value = 0

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

// Daftarkan TV ini ke server (upsert by device_key) + ambil media efektifnya
// (global bila synced, override bila mandiri). Dipanggil saat mount & tiap WS
// (re)connect untuk rekonsiliasi. Fallback ke media global bila gagal.
async function registerDevice() {
  try {
    const { data } = await antreanTvApi.registerDevice({ device_key: deviceKey })
    const d = data.data ?? {}
    if (d.device) { deviceId.value = d.device.id; deviceName.value = d.device.name }
    if (d.media)  applyMediaPayload(d.media)
  } catch {
    await fetchMediaSettings()
  }
}

// Namai TV INI dari layar TV sendiri — TANPA login admin. Memakai endpoint
// register publik (scoped ke device_key milik TV ini), jadi operator di lokasi
// bisa memberi nama ("TV Lobi", "TV Lt. 2") walau panel hanya dibuka via PIN.
// fetchDevices() di-refresh untuk admin yang login; no-op bila tak berhak.
async function renameThisDevice(name) {
  const trimmed = (name ?? '').trim()
  if (!trimmed || trimmed === (deviceName.value ?? '')) return
  try {
    const { data } = await antreanTvApi.registerDevice({ device_key: deviceKey, name: trimmed })
    const d = data.data ?? {}
    if (d.device) { deviceId.value = d.device.id; deviceName.value = d.device.name }
    showMediaMsg('Nama TV ini tersimpan.', 'ok')
    fetchDevices()
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan nama TV ini', 'err')
  }
}

// Heartbeat ringan: perbarui last_seen di server tanpa menyentuh tampilan
// layar ini (beda dari registerDevice yang juga apply media). Supaya status
// online/offline TV akurat walau koneksi WS bertahan lama tanpa reconnect.
async function heartbeat() {
  try { await antreanTvApi.registerDevice({ device_key: deviceKey }) } catch { /* offline sementara */ }
}
let heartbeatInterval = null

// Ambil daftar TV terdaftar untuk panel kontrol (butuh login/permission).
async function fetchDevices() {
  try {
    const { data } = await antreanTvApi.listDevices()
    devices.value = data.data ?? []
  } catch {
    devices.value = []
  }
  scrollToThisDevice()
}

// Baris di daftar yang merupakan TV yang SEDANG membuka panel ini (cocok
// device_key di localStorage). Null bila panel dibuka dari perangkat lain
// (mis. laptop admin) yang belum/tidak terdaftar di daftar ini.
const thisDeviceInList = computed(() =>
  devices.value.find((d) => d.device_key === deviceKey) ?? null
)
const thisDeviceRow = ref(null)   // ref elemen baris "TV ini" untuk auto-scroll

// Gulir + sorot baris TV ini ketika daftar dimuat, supaya admin yang membuka
// panel DI TV bersangkutan langsung melihat baris mana miliknya di antara
// banyak "TV Baru" serupa. No-op bila TV ini tak ada di daftar.
function scrollToThisDevice() {
  if (!thisDeviceInList.value) return
  nextTick(() => {
    thisDeviceRow.value?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  })
}

// Saat panel kontrol dibuka: muat daftar TV + media target (default Global).
async function openMediaControl() {
  await fetchDevices()
  await selectTarget('global')
}

// Isi `editor` dari payload media (respons mediaSettings / deviceMedia / update).
function fillEditor(m) {
  if (!m) return
  editor.media_mode        = m.media_mode ?? 'placeholder'
  editor.youtube_embed_url = m.youtube_embed_url ?? ''
  editor.external_video_url = m.external_video_url ?? ''
  editor.video_autoplay    = m.video_autoplay !== false
  editor.video_loop        = m.video_loop !== false
  editor.has_uploaded_file = !!m.has_uploaded_file
  editor.local_video_name  = m.local_video_name ?? ''
  editor.local_video_url   = m.local_video_url ?? ''
  editor.slides            = Array.isArray(m.slides) ? m.slides : []
  editor.slide_interval    = typeof m.slide_interval === 'number' ? m.slide_interval : 8
  editor.slide_scope       = m.slide_scope ?? 'panel'
  editor.flash_over_fullscreen = m.flash_over_fullscreen !== false
  lastEditorInterval = editor.slide_interval   // cegah watch menulis-balik
}

// Muat media TARGET terpilih (Global atau satu TV) ke editor.
async function loadEditor() {
  editorLoading.value = true
  try {
    if (editorTarget.value.kind === 'global') {
      const { data } = await antreanTvApi.mediaSettings()
      fillEditor(data.data)
    } else {
      const { data } = await antreanTvApi.deviceMedia(editorTarget.value.key)
      fillEditor(data.data.media)
    }
  } catch {
    Object.assign(editor, blankEditor())
  } finally {
    editorLoading.value = false
  }
}

// Ganti target edit (dari dropdown). value = 'global' atau device id.
async function selectTarget(value) {
  if (value === 'global') {
    editorTarget.value = { kind: 'global', id: null, key: null, name: 'Semua TV (Global)' }
  } else {
    const d = devices.value.find((x) => x.id === value)
    if (!d) return
    editorTarget.value = { kind: 'device', id: d.id, key: d.device_key, name: d.name }
  }
  await loadEditor()
}

// Simpan perubahan ke TARGET terpilih. Hanya menerapkan ke LAYAR INI bila target
// memang memengaruhi layar ini (global & TV ini synced, atau target = TV ini).
async function saveTarget(payload) {
  try {
    let media
    if (editorTarget.value.kind === 'global') {
      const { data } = await antreanTvApi.updateMedia(payload)
      media = data.data
      if (mediaSynced.value) applyMediaPayload(media)
    } else {
      const { data } = await antreanTvApi.updateDevice(editorTarget.value.id, payload)
      media = data.data.media
      if (editorTarget.value.key === deviceKey) applyMediaPayload(media)
    }
    fillEditor(media)
    fetchDevices()   // segarkan badge Global/Mandiri + status
    return media
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan (perlu login)', 'err')
    throw err
  }
}

// Pesan sukses dengan nama target.
function targetLabel(base) {
  return editorTarget.value.kind === 'global'
    ? `${base} — berlaku semua TV (Global).`
    : `${base} — di ${editorTarget.value.name}.`
}

// Ubah nama / lokasi TV (salah satu di daftar).
async function renameDevice(id, name) {
  try {
    await antreanTvApi.updateDevice(id, { name })
    if (id === deviceId.value) deviceName.value = name
    await fetchDevices()
    showMediaMsg('Nama TV tersimpan.', 'ok')
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan nama', 'err')
  }
}

// Kembalikan satu TV ke media GLOBAL (lepas mode mandiri).
async function syncDeviceToGlobal(id) {
  try {
    const { data } = await antreanTvApi.updateDevice(id, { media_synced: true })
    if (id === deviceId.value) applyMediaPayload(data.data.media)
    await fetchDevices()
    showMediaMsg('TV dikembalikan ke media global.', 'ok')
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyinkronkan', 'err')
  }
}

async function deleteDevice(id) {
  if (!confirm('Hapus pendaftaran TV ini? TV yang masih hidup akan mendaftar ulang (ikut global) saat reload.')) return
  try {
    await antreanTvApi.deleteDevice(id)
    await fetchDevices()
    showMediaMsg('TV dihapus.', 'ok')
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menghapus', 'err')
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
  const embed = buildYoutubeUrl(id, { autoplay: editor.video_autoplay, loop: editor.video_loop })
  try {
    await saveTarget({
      media_mode:        'youtube',
      youtube_embed_url: embed,
      video_autoplay:    editor.video_autoplay,
      video_loop:        editor.video_loop,
    })
    youtubeDraft.value = ''
    showMediaMsg(targetLabel('YouTube disiarkan'), 'ok')
  } catch { /* pesan sudah ditampilkan saveTarget */ }
}

// Re-sync video options (autoplay/loop) — kalau lagi mode youtube,
// rebuild embed URL dengan opsi baru.
async function syncVideoOptions() {
  const payload = {
    video_autoplay: editor.video_autoplay,
    video_loop:     editor.video_loop,
  }
  if (editor.media_mode === 'youtube' && editor.youtube_embed_url) {
    const m = editor.youtube_embed_url.match(/\/embed\/([A-Za-z0-9_-]{11})/)
    if (m) payload.youtube_embed_url = buildYoutubeUrl(m[1], { autoplay: editor.video_autoplay, loop: editor.video_loop })
  }
  try { await saveTarget(payload) } catch { /* noop */ }
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
    fillEditor(data.data)
    if (mediaSynced.value) applyMediaPayload(data.data)   // video file = media global
    showMediaMsg('Video diupload & disiarkan ke semua TV (Global).', 'ok')
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
  if (!confirm('Hapus video lokal global? TV yang memakai video ini kembali ke placeholder.')) return
  try {
    const { data } = await antreanTvApi.deleteMediaVideo()
    fillEditor(data.data)
    if (mediaSynced.value) applyMediaPayload(data.data)
    showMediaMsg('Video lokal global dihapus.', 'ok')
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menghapus (perlu login)', 'err')
  }
}

async function applyExternalVideoUrl() {
  const url = externalVideoDraft.value.trim()
  if (!url) { showMediaMsg('Paste URL video MP4 dulu', 'err'); return }
  if (!/^https?:\/\//i.test(url)) { showMediaMsg('URL harus dimulai http:// atau https://', 'err'); return }
  try {
    await saveTarget({ external_video_url: url, media_mode: 'localvideo' })
    externalVideoDraft.value = ''
    showMediaMsg(targetLabel('URL video disiarkan'), 'ok')
  } catch { /* noop */ }
}

async function clearExternalVideoUrl() {
  try {
    await saveTarget({
      external_video_url: null,
      // Kalau tidak ada file upload sebagai fallback, balik ke placeholder.
      media_mode: editor.has_uploaded_file ? 'localvideo' : 'placeholder',
    })
    showMediaMsg('URL video dihapus.', 'ok')
  } catch { /* noop */ }
}

async function setMediaMode(mode) {
  // Untuk localvideo, butuh ada file upload (global) atau URL video eksternal.
  if (mode === 'localvideo' && !editor.local_video_url && !editor.external_video_url) {
    showMediaMsg('Belum ada video. Upload video (Global) atau isi URL video dulu.', 'err')
    return
  }
  try { await saveTarget({ media_mode: mode }) } catch { /* noop */ }
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

// Dedup DURABLE panggilan. Cek flashQueue/callQueue saja rapuh: flashQueue
// tak pernah di-null-kan & bisa tergeser panggilan berikutnya, lalu duplikat
// lama lolos → diumumkan 2×. Sumber dobel: (a) double-click "Panggil" operator
// (2 called_at beda), (b) WS + polling tumpang-tindih saat Reverb sempat error.
// Map id → { calledAt, at(ms) }. RECALL_COOLDOWN: panggil-ulang id sama < ambang
// dianggap duplikat/double-click (re-call asli pasien tak hadir jauh lebih lama).
const announcedCalls = new Map()
const RECALL_COOLDOWN_MS = 6000

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
  // TV mode papan iklan murni (fullscreen + flash dimatikan): jangan umumkan
  // panggilan sama sekali (tanpa flash & tanpa TTS).
  if (suppressCalls.value) return
  const calledAt = q.called_at ?? ''
  const key = `${q.id}:${calledAt}`
  // 1. Sudah sedang tampil / sudah antri (event WS duplikat berturut-turut).
  const flashKey = flashQueue.value ? `${flashQueue.value.id}:${flashQueue.value.called_at ?? ''}` : null
  if (flashKey === key) return
  if (callQueue.value.some((x) => `${x.id}:${x.called_at ?? ''}` === key)) return
  // 2. Dedup durable (menutup celah flashQueue tergeser + WS/polling dobel +
  //    double-click). Panggilan persis sama → lewati; re-call id sama terlalu
  //    cepat (< RECALL_COOLDOWN) → lewati (double-click), tapi re-call asli
  //    (jeda lebih lama) tetap diumumkan.
  const prev = announcedCalls.get(q.id)
  if (prev) {
    if (prev.calledAt === calledAt) return
    if (Date.now() - prev.at < RECALL_COOLDOWN_MS) return
  }
  announcedCalls.set(q.id, { calledAt, at: Date.now() })
  if (announcedCalls.size > 300) announcedCalls.delete(announcedCalls.keys().next().value)
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
      // Fallback: TTS kadang stuck di browser tertentu, force resolve setelah 10s.
      // Timer di-clear saat onend/onerror supaya tidak menggantung sampai 10s.
      const fallback = setTimeout(resolve, 10_000)
      utter.onend   = () => { clearTimeout(fallback); resolve() }
      utter.onerror = () => { clearTimeout(fallback); resolve() }
      synth.speak(utter)
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
  let text = renderTemplate(tmpl, speakable)
  // Jaminan TUJUAN ikut terucap: kalau template (mis. hasil edit operator saat
  // tes) tak memuat {poli} sehingga nama poli absen dari hasil, sambungkan di
  // akhir. Tak menambah bila poli sudah disebut (cegah dobel "menuju ...").
  const poli = String(q.poly ?? '').trim()
  if (poli && !text.toLowerCase().includes(poli.toLowerCase())) {
    text = text.replace(/[.\s]*$/, '') + `, silakan menuju ${poli}.`
  }
  return text
}

// --- SLIDESHOW ---
const slides = ref([])
const slidesDraft = ref('')
const slideIndex = ref(0)
const slideDuration = ref(5)
let slideTimer = null

// Simpan daftar slide TARGET (editor.slides) — optimistik update editor.slides.
async function editorPersistSlides(nextSlides, opts = {}) {
  editor.slides = nextSlides
  const payload = { slides: nextSlides }
  if (opts.mode) payload.media_mode = opts.mode
  try { await saveTarget(payload) } catch { /* noop */ }
}

async function addSlides() {
  const urls = slidesDraft.value.split('\n').map(u => u.trim()).filter(u => u)
  if (!urls.length) return
  const next = [...editor.slides, ...urls.map(url => ({ url }))]
  slidesDraft.value = ''
  await editorPersistSlides(next)
}

// Upload satu/lebih gambar dari perangkat → simpan ke server → tambahkan URL-nya
// ke daftar slide TARGET terpilih.
const slideUploading = ref(false)
async function handleSlideImageFiles(e) {
  const files = Array.from(e.target.files ?? [])
  e.target.value = ''   // reset agar file sama bisa dipilih lagi
  if (!files.length) return
  slideUploading.value = true
  const uploaded = []
  try {
    for (const file of files) {
      const fd = new FormData()
      fd.append('image', file)
      const { data } = await antreanTvApi.uploadMediaImage(fd)
      if (data?.data?.url) uploaded.push({ url: data.data.url })
    }
    if (uploaded.length) {
      await editorPersistSlides([...editor.slides, ...uploaded])
      showMediaMsg(targetLabel(`${uploaded.length} gambar diunggah`), 'ok')
    }
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal mengunggah gambar (perlu login / >10MB)', 'err')
  } finally {
    slideUploading.value = false
  }
}

// Simpan cakupan tampilan (panel/fullscreen) + flag flash — ke TARGET terpilih.
async function saveScope(patch) {
  try {
    await saveTarget({
      slide_scope:           patch.slide_scope ?? editor.slide_scope,
      flash_over_fullscreen: patch.flash_over_fullscreen ?? editor.flash_over_fullscreen,
    })
  } catch { /* noop */ }
}

async function removeSlide(idx) {
  const next = editor.slides.filter((_, i) => i !== idx)
  const opts = (next.length === 0 && editor.media_mode === 'slideshow')
    ? { mode: 'placeholder' }
    : {}
  await editorPersistSlides(next, opts)
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

// Durasi per-slide TARGET (editor.slide_interval) → simpan debounce. Guard
// `lastEditorInterval` mencegah load men-trigger penyimpanan balik.
let slideIntervalDebounce = null
watch(() => editor.slide_interval, (v) => {
  if (v === lastEditorInterval) return
  clearTimeout(slideIntervalDebounce)
  slideIntervalDebounce = setTimeout(() => saveTarget({ slide_interval: v }), 400)
})

async function applySlideshowMode() {
  if (editor.slides.length === 0) return
  try {
    await saveTarget({ media_mode: 'slideshow' })
    showMediaMsg(targetLabel('Slideshow diaktifkan'), 'ok')
  } catch { /* noop */ }
}

// --- TICKER EDITOR ---
const tickerDraft = ref('')
const tickerEditIdx = ref(-1)
const tickerEditVal = ref('')

// Persist daftar pesan ticker ke backend (singleton media) → broadcast ke
// semua TV via TvMediaUpdated. Mirror persistSlides. applyMediaPayload yang
// menyetel ulang tickerMessages.value dari respons supaya satu sumber data.
async function persistTicker(next) {
  try {
    const { data } = await antreanTvApi.updateMedia({ ticker_messages: next })
    applyMediaPayload(data.data)
  } catch (err) {
    showMediaMsg(err.response?.data?.message ?? 'Gagal menyimpan running text (perlu login)', 'err')
  }
}

async function addTickerMsg() {
  const msg = tickerDraft.value.trim()
  if (!msg) return
  const next = [...tickerMessages.value, msg]
  tickerDraft.value = ''
  await persistTicker(next)
}

async function removeTickerMsg(idx) {
  const next = tickerMessages.value.filter((_, i) => i !== idx)
  await persistTicker(next)
}

async function moveTickerMsg(idx, dir) {
  const next = [...tickerMessages.value]
  const target = idx + dir
  if (target < 0 || target >= next.length) return
  ;[next[idx], next[target]] = [next[target], next[idx]]
  await persistTicker(next)
}

function startEditTicker(idx) {
  tickerEditIdx.value = idx
  tickerEditVal.value = tickerMessages.value[idx]
}

async function saveEditTicker(idx) {
  const val = tickerEditVal.value.trim()
  tickerEditIdx.value = -1
  if (!val || val === tickerMessages.value[idx]) return
  const next = [...tickerMessages.value]
  next[idx] = val
  await persistTicker(next)
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
  try { localStorage.setItem(PIN_STORAGE_KEY, newPin.value) } catch (_) { /* storage penuh / private mode */ }
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
        <img :src="branding.logo_data || logoPvPutih" alt="Logo rumah sakit" class="tv-logo-img" />
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

    <!-- MAIN — MODE GRID (semua stasiun, layout lama) -->
    <div v-if="tvMode === 'grid'" class="tv-main">
      <!-- VIDEO/INFO PANEL -->
      <div class="video-panel">
        <!-- Placeholder -->
        <div v-if="mediaMode === 'placeholder'" class="video-placeholder">
          <img :src="branding.logo_data || logoPvPutih" alt="Logo rumah sakit" class="video-logo-img" />
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
              <div v-if="stationView[key].called.dokter"
                   class="sc-called-dokter">{{ stationView[key].called.dokter }}</div>
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

    <!-- MAIN — MODE POLIKLINIK (1 layar dinamis: media + antrean berputar) -->
    <div v-else-if="tvMode === 'poli'" class="tv-main poli-main" :class="`scene-${activeScene}`">
      <!-- MEDIA (lebar dinamis: dominan saat scene media, menyusut saat antrean) -->
      <div class="poli-media">
        <div v-if="mediaMode === 'placeholder'" class="video-placeholder">
          <img :src="branding.logo_data || logoPvPutih" alt="Logo rumah sakit" class="video-logo-img" />
          <h2 v-if="branding.placeholder_title" class="video-title">{{ branding.placeholder_title }}</h2>
          <p v-if="branding.placeholder_tagline" class="video-tagline">{{ branding.placeholder_tagline }}</p>
        </div>
        <iframe v-else-if="mediaMode === 'youtube'" :src="youtubeEmbedUrl" class="yt-frame"
                allow="autoplay; encrypted-media" allowfullscreen frameborder="0"></iframe>
        <div v-else-if="mediaMode === 'slideshow' && slides.length" class="slideshow">
          <img v-for="(s, i) in slides" :key="i" :src="s.url"
               :class="['slide-img', { active: i === slideIndex }]" alt="" />
        </div>
        <video v-else-if="mediaMode === 'localvideo' && localVideoUrl" :src="localVideoUrl"
               :loop="videoLoop" :autoplay="videoAutoplay" muted class="local-video"></video>
        <div v-else class="video-placeholder">
          <img :src="branding.logo_data || logoPvPutih" alt="Logo rumah sakit" class="video-logo-img" />
        </div>
      </div>

      <!-- PANEL ANTREAN (scene berputar) -->
      <div class="poli-queue">
        <div class="scene-dots">
          <span v-for="s in visibleScenes" :key="s.key"
                :class="['scene-dot', { active: s.key === activeScene }]">{{ sceneLabel[s.key] }}</span>
        </div>

        <Transition name="scene" mode="out-in">
          <div :key="activeScene" class="scene-body">

            <!-- SCENE: POLIKLINIK -->
            <div v-if="activeScene === 'poli'" :class="['poli-board', `cols-${poliCols}`]">
              <div v-for="d in poliklinikView" :key="d.prefix"
                   :class="['poli-card', d.calledNum ? 'has-called' : '']">
                <div class="pc-head">
                  <span class="pc-poli">{{ d.poli }}<span v-if="d.room"> · Ruang {{ d.room }}</span></span>
                  <span v-if="d.serviceType" :class="['pc-svc', d.serviceType.toLowerCase()]">
                    {{ d.serviceType === 'EKSEKUTIF' ? 'Eksekutif' : 'BPJS' }}
                  </span>
                </div>
                <div v-if="d.dokter" class="pc-dokter">{{ d.dokter }}</div>
                <div class="pc-body">
                  <div class="pc-cell">
                    <div class="pc-lbl">Dipanggil</div>
                    <div :class="['pc-num', { muted: !d.calledNum }]">{{ d.calledNum || '—' }}</div>
                  </div>
                  <div class="pc-cell">
                    <div class="pc-lbl">Menunggu</div>
                    <div class="pc-wait-num">{{ d.waitingCount }}</div>
                  </div>
                </div>
                <div class="pc-next">{{ d.nextNum ? `Berikutnya: ${d.nextNum}` : 'Belum ada antrean berikutnya' }}</div>
              </div>
            </div>

            <!-- SCENE: FARMASI -->
            <div v-else-if="activeScene === 'farmasi'" class="farmasi-board">
              <div class="board-title">Antrean Farmasi</div>
              <div class="fb-main">
                <div :class="['fb-call', { 'has-called': farmasiView.calledNum }]">
                  <div class="pc-lbl">Sedang Dipanggil</div>
                  <div :class="['fb-num', { muted: !farmasiView.calledNum }]">{{ farmasiView.calledNum || '—' }}</div>
                  <div class="fb-wait">{{ farmasiView.waitingCount }} menunggu</div>
                </div>
                <div class="fb-side">
                  <div class="fb-side-lbl">Siap diambil</div>
                  <div class="fb-chips">
                    <span v-for="n in farmasiView.readyList" :key="n" class="fb-chip ready">{{ n }}</span>
                    <span v-if="!farmasiView.readyList.length" class="fb-empty">—</span>
                  </div>
                  <div class="fb-side-lbl">Antrean berikutnya</div>
                  <div class="fb-chips">
                    <span v-for="n in farmasiView.nextList" :key="n" class="fb-chip">{{ n }}</span>
                    <span v-if="!farmasiView.nextList.length" class="fb-empty">—</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- SCENE: STASIUN LAIN -->
            <div v-else-if="activeScene === 'stasiun'" class="strip-board">
              <div class="board-title">Antrean Stasiun Lain</div>
              <div class="strip-grid">
                <div v-for="s in stationStripView" :key="s.key"
                     :class="['strip-card', s.calledNum ? 'has-called' : '']">
                  <div class="strip-name">{{ s.label }}</div>
                  <div :class="['strip-num', { muted: !s.calledNum }]">{{ s.calledNum || '—' }}</div>
                  <div class="strip-wait">{{ s.waitingCount }} menunggu</div>
                </div>
              </div>
            </div>

            <!-- SCENE: MEDIA → strip ringkas antrean (info tak hilang) -->
            <div v-else class="media-strip">
              <div class="board-title">Ringkasan Antrean</div>
              <div class="ms-sec-lbl">Poliklinik</div>
              <div class="ms-rows">
                <div v-for="d in poliklinikView" :key="d.prefix" class="ms-row">
                  <span class="ms-poli">{{ d.poli }}<span v-if="d.dokter"> — {{ d.dokter }}</span></span>
                  <span :class="['ms-call', { muted: !d.calledNum }]">{{ d.calledNum || '—' }}</span>
                  <span class="ms-w">{{ d.waitingCount }} antri</span>
                </div>
                <div v-if="!poliklinikView.length" class="fb-empty">Tidak ada dokter aktif</div>
              </div>
              <div class="ms-sec-lbl">Farmasi</div>
              <div class="ms-rows">
                <div class="ms-row">
                  <span class="ms-poli">Pengambilan Obat</span>
                  <span :class="['ms-call', { muted: !farmasiView.calledNum }]">{{ farmasiView.calledNum || '—' }}</span>
                  <span class="ms-w">{{ farmasiView.waitingCount }} antri</span>
                </div>
              </div>
            </div>

          </div>
        </Transition>
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
      <div class="tv-bottom-right">PT. Karya Sistem Nusantara</div>
      <!-- PIN trigger button -->
      <button class="ctrl-btn" @click="openPinModal" title="Kontrol Layar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- FULL-SCREEN MEDIA OVERLAY — saat slideScope = 'fullscreen', media menutupi
       seluruh layar (papan iklan). Z-index 500: di bawah panel kontrol (1000) &
       flash panggilan (10000) supaya operator tetap bisa kontrol & panggilan
       tetap muncul (bila diaktifkan). -->
  <Teleport to="body">
    <div v-if="slideScope === 'fullscreen'" class="media-fullscreen">
      <!-- Slideshow -->
      <div v-if="mediaMode === 'slideshow' && slides.length" class="slideshow fs">
        <img
          v-for="(s, i) in slides"
          :key="i"
          :src="s.url"
          :class="['slide-img', { active: i === slideIndex }]"
          alt=""
        />
      </div>
      <!-- YouTube -->
      <iframe
        v-else-if="mediaMode === 'youtube'"
        :src="youtubeEmbedUrl"
        class="yt-frame fs"
        allow="autoplay; encrypted-media"
        allowfullscreen
        frameborder="0"
      ></iframe>
      <!-- Video lokal/eksternal -->
      <video
        v-else-if="mediaMode === 'localvideo' && localVideoUrl"
        :src="localVideoUrl"
        :loop="videoLoop"
        :autoplay="videoAutoplay"
        muted
        class="local-video fs"
      ></video>
      <!-- Placeholder -->
      <div v-else class="video-placeholder fs">
        <img :src="branding.logo_data || logoPvPutih" alt="Logo rumah sakit" class="video-logo-img" />
        <h2 v-if="branding.placeholder_title" class="video-title">{{ branding.placeholder_title }}</h2>
        <p v-if="branding.placeholder_tagline" class="video-tagline">{{ branding.placeholder_tagline }}</p>
      </div>
    </div>
  </Teleport>

  <!-- FULL-SCREEN FLASH OVERLAY (queue called) -->
  <Teleport to="body">
    <div v-if="flashVisible" class="flash-overlay" @click="unlockAudio(); flashVisible = false">
      <div class="flash-content">
        <div class="flash-label-top">{{ flashSettings.flash_label_top || 'Nomor Antrean Dipanggil' }}</div>
        <div class="flash-num">{{ flashQueue?.num }}</div>
        <div v-if="flashSettings.show_name_in_flash !== false && flashQueue?.name && flashQueue.name !== '—'" class="flash-name">
          {{ flashQueue.name }}
        </div>
        <div v-if="flashSettings.show_poly_in_flash !== false" class="flash-poly">{{ flashQueue?.poly }}</div>
        <div v-if="flashQueue?.dokter" class="flash-dokter">{{ flashQueue.dokter }}</div>
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
          <svg viewBox="0 0 24 24" fill="none" stroke="var(--lm)" stroke-width="1.5" stroke-linecap="round">
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
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--lm)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
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
            Rumah Sakit
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
            <!-- TARGET: pilih TV mana yang diatur (Global / TV tertentu) -->
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Atur tampilan untuk</p>
              <select class="ctrl-input" :value="editorTarget.kind === 'global' ? 'global' : editorTarget.id"
                      @change="selectTarget($event.target.value)">
                <option value="global">🌐 Semua TV (Global)</option>
                <option v-for="d in devices" :key="d.id" :value="d.id">
                  📺 {{ d.name }}{{ d.device_key === deviceKey ? ' — TV ini' : '' }}
                  ({{ d.media_synced ? 'ikut Global' : 'Mandiri' }}{{ d.online ? ', online' : ', offline' }})
                </option>
              </select>
              <p class="ctrl-lbl" style="opacity:.6; font-weight:400; margin-top:8px">
                <template v-if="editorTarget.kind === 'global'">
                  Perubahan berlaku untuk <strong>semua TV</strong> yang mengikuti Global. TV yang diatur sendiri (Mandiri) tidak ikut.
                </template>
                <template v-else>
                  Perubahan <strong>hanya untuk {{ editorTarget.name }}</strong>. Mengatur medianya otomatis membuat TV ini "Mandiri" (lepas dari Global).
                </template>
                <span v-if="editorLoading"> · memuat…</span>
              </p>
            </div>

            <!-- TV INI: selalu tampil (data dari register publik), bisa dinamai
                 langsung di layar TV tanpa login admin. -->
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl" style="margin:0">TV ini (layar yang sedang Anda lihat)</p>
              <p class="ctrl-lbl" style="opacity:.55; font-weight:400; margin-top:2px">
                Beri nama sesuai lokasi (mis. "TV Lobi", "TV Lt. 2"). Tersimpan langsung untuk TV ini — tidak perlu login.
              </p>
              <div class="tvdev-row is-this">
                <span class="tvdev-dot on" title="TV ini"></span>
                <input
                  class="ctrl-input tvdev-name"
                  :value="deviceName"
                  placeholder="Nama / lokasi TV ini"
                  @keydown.enter="renameThisDevice($event.target.value); $event.target.blur()"
                  @blur="renameThisDevice($event.target.value)"
                />
                <span class="tvdev-this">★ TV ini</span>
              </div>
            </div>

            <!-- Daftar TV terdaftar: beri nama/lokasi, status, kelola (perlu login admin) -->
            <div class="ctrl-sub-section">
              <div class="ctrl-row" style="align-items:center; justify-content:space-between">
                <p class="ctrl-lbl" style="margin:0">Semua TV Terdaftar ({{ devices.length }})</p>
                <button class="ctrl-action-btn" style="padding:4px 12px; font-size:12px" @click="fetchDevices">Muat Ulang</button>
              </div>
              <p class="ctrl-lbl" style="opacity:.55; font-weight:400; margin-top:2px">
                Beri nama sesuai lokasi (mis. "TV Lobi", "TV Lt. 2"). Tiap TV yang membuka halaman ini terdaftar otomatis.
              </p>
              <p v-if="devices.length && !thisDeviceInList" class="tvdev-hint">
                ⚠️ TV yang Anda pakai sekarang tidak ada di daftar — panel ini sepertinya dibuka dari perangkat lain (mis. laptop). Buka halaman ini <strong>langsung di TV</strong> agar baris "TV ini" tersorot.
              </p>
              <p v-else-if="thisDeviceInList" class="tvdev-hint ok">
                ✓ Baris bertanda <strong>TV ini</strong> (tersorot) adalah layar yang sedang Anda lihat. Ganti namanya sesuai lokasi.
              </p>
              <div v-if="devices.length === 0" class="ctrl-empty">
                Daftar semua TV hanya muncul untuk admin yang login. Untuk menamai layar ini, pakai kotak <strong>"TV ini"</strong> di atas.
              </div>
              <div
                v-for="d in devices"
                :key="d.id"
                :ref="(el) => { if (d.device_key === deviceKey) thisDeviceRow = el }"
                :class="['tvdev-row', { 'is-this': d.device_key === deviceKey }]">
                <span :class="['tvdev-dot', d.online ? 'on' : 'off']" :title="d.online ? 'Online' : 'Offline'"></span>
                <input
                  class="ctrl-input tvdev-name"
                  :value="d.name"
                  placeholder="Nama / lokasi TV"
                  @keydown.enter="renameDevice(d.id, $event.target.value)"
                  @blur="$event.target.value !== d.name && renameDevice(d.id, $event.target.value)"
                />
                <span :class="['tvdev-badge', d.media_synced ? 'global' : 'self']">
                  {{ d.media_synced ? 'Global' : 'Mandiri' }}
                </span>
                <span v-if="d.device_key === deviceKey" class="tvdev-this">★ TV ini</span>
                <button class="icon-btn" title="Atur tampilan TV ini" @click="selectTarget(d.id)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/></svg>
                </button>
                <button v-if="!d.media_synced" class="icon-btn" title="Kembalikan ke media global" @click="syncDeviceToGlobal(d.id)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                </button>
                <button class="icon-btn danger" title="Hapus pendaftaran" @click="deleteDevice(d.id)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </div>
            </div>

            <p class="ctrl-lbl">Mode Tampilan — <strong>{{ editorTarget.name }}</strong></p>
            <div class="mode-grid">
              <button :class="['mode-card', { active: editor.media_mode === 'placeholder' }]" @click="setMediaMode('placeholder')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>Placeholder</span>
                <small>Logo + nama rumah sakit</small>
              </button>
              <button :class="['mode-card', { active: editor.media_mode === 'youtube' }]" @click="editor.media_mode === 'youtube' ? null : setMediaMode('youtube')" :disabled="!editor.youtube_embed_url">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M22.54 6.42a2.78 2.78 0 00-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46A2.78 2.78 0 001.46 6.42 29 29 0 001 12a29 29 0 00.46 5.58 2.78 2.78 0 001.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 001.95-1.96A29 29 0 0023 12a29 29 0 00-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02"/></svg>
                <span>YouTube</span>
                <small>{{ editor.youtube_embed_url ? 'Video tersimpan' : 'Belum ada URL' }}</small>
              </button>
              <button :class="['mode-card', { active: editor.media_mode === 'localvideo' }]"
                      @click="(editor.local_video_url || editor.external_video_url) ? setMediaMode('localvideo') : null"
                      :disabled="!editor.local_video_url && !editor.external_video_url">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <span>Video</span>
                <small>{{ editor.local_video_name || editor.external_video_url ? 'Video tersimpan' : 'Upload (Global) / isi URL' }}</small>
              </button>
            </div>

            <!-- Upload video FILE: hanya untuk Global (penyimpanan bersama) -->
            <div v-if="editorTarget.kind === 'global'" class="ctrl-sub-section" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <button class="ctrl-action-btn" :disabled="mediaUploading" @click="$refs.videoFileInput.click()">
                {{ mediaUploading ? 'Mengupload…' : '⬆ Upload Video File (Global)' }}
              </button>
              <span class="ctrl-lbl" style="opacity:.55;font-weight:400;margin:0">Maks 500 MB. File video dipakai bersama semua TV Global.</span>
            </div>
            <p v-else class="ctrl-lbl" style="opacity:.55;font-weight:400">
              Untuk TV tertentu: pakai <strong>YouTube</strong>, <strong>URL video</strong>, atau <strong>Slideshow gambar</strong> (upload file video hanya tersedia di Global).
            </p>
            <input ref="videoFileInput" type="file" accept="video/*" style="display:none" @change="handleVideoFile" />
            <input ref="videoFileInput" type="file" accept="video/*" style="display:none" @change="handleVideoFile" />

            <!-- Upload progress -->
            <div v-if="mediaUploading" class="ctrl-sub-section">
              <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:4px;color:#fff;gap:8px">
                <span style="color:#fff">Mengupload video…</span>
                <span style="font-variant-numeric:tabular-nums;color:#fff">{{ mediaUploadPct }}% · {{ mediaUploadInfo }}</span>
              </div>
              <div style="height:8px;background:rgba(255,255,255,.1);border-radius:4px;overflow:hidden">
                <div :style="{ width: mediaUploadPct + '%', height: '100%', background: 'var(--lm)', transition: 'width .15s' }"></div>
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

            <!-- Info video file (Global saja) + ganti/hapus -->
            <div v-if="editorTarget.kind === 'global' && editor.has_uploaded_file" class="ctrl-sub-section" style="display:flex;align-items:center;gap:10px;justify-content:space-between">
              <div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;color:#fff">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                <span style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ editor.local_video_name || 'video lokal' }}</span>
              </div>
              <div style="display:flex;gap:8px;flex-shrink:0">
                <button class="ctrl-action-btn" :disabled="mediaUploading" @click="$refs.videoFileInput.click()">
                  Ganti Video
                </button>
                <button class="ctrl-action-btn" style="background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.3)" @click="deleteLocalVideo">
                  Hapus File
                </button>
              </div>
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
              <div v-if="editor.external_video_url" style="display:flex;align-items:center;gap:8px;justify-content:space-between;margin-top:8px;padding:8px 10px;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);border-radius:8px">
                <div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;color:#fff">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="var(--lm)" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                  <span style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">URL aktif: {{ editor.external_video_url }}</span>
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
                  <input type="checkbox" v-model="editor.video_autoplay" @change="syncVideoOptions" />
                  <span class="toggle-track"></span>
                  <span class="toggle-label">Autoplay (mulai otomatis)</span>
                </label>
                <label class="ctrl-toggle">
                  <input type="checkbox" v-model="editor.video_loop" @change="syncVideoOptions" />
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
              <p v-if="editor.media_mode === 'youtube' && editor.youtube_embed_url" class="ctrl-ok">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                YouTube aktif di {{ editorTarget.name }}
              </p>
            </div>
          </div>

          <!-- TAB: SLIDESHOW -->
          <div v-if="activeTab === 'slideshow'" class="ctrl-section">
            <!-- Penanda target (ikut pilihan di tab Media) -->
            <p class="ctrl-lbl" style="opacity:.7; font-weight:500; margin-top:-2px">
              Target: <strong>{{ editorTarget.name }}</strong> — ganti di tab Media.
            </p>

            <!-- Upload gambar dari perangkat -->
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Upload Gambar dari Perangkat</p>
              <input ref="slideImageInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple style="display:none" @change="handleSlideImageFiles" />
              <button class="ctrl-action-btn" :disabled="slideUploading" @click="$refs.slideImageInput.click()">
                {{ slideUploading ? 'Mengunggah…' : '⬆ Pilih Gambar (bisa banyak)' }}
              </button>
              <p class="ctrl-hint" style="margin-top:6px">JPG, PNG, WebP, atau GIF. Maks 10 MB per gambar. Gambar disimpan di server lalu masuk daftar slide.</p>
            </div>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Tambah Gambar via URL (per baris)</p>
              <textarea
                v-model="slidesDraft"
                class="ctrl-textarea"
                rows="3"
                placeholder="https://contoh.com/gambar1.jpg&#10;https://contoh.com/gambar2.jpg"
              ></textarea>
              <button class="ctrl-action-btn" style="margin-top:8px" @click="addSlides">Tambah URL</button>
            </div>

            <!-- Cakupan tampilan: panel kiri vs fullscreen -->
            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Cakupan Tampilan</p>
              <div class="target-grid">
                <button :class="['target-card', { active: editor.slide_scope === 'panel' }]" @click="editor.slide_scope = 'panel'; saveScope({ slide_scope: 'panel' })">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="11" y1="4" x2="11" y2="20"/></svg>
                  <span>Panel Kiri</span>
                  <small>Slideshow di panel, grid antrean tetap tampil</small>
                </button>
                <button :class="['target-card', { active: editor.slide_scope === 'fullscreen' }]" @click="editor.slide_scope = 'fullscreen'; saveScope({ slide_scope: 'fullscreen' })">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M16 3h3a2 2 0 0 1 2 2v3"/><path d="M8 21H5a2 2 0 0 1-2-2v-3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
                  <span>Layar Penuh</span>
                  <small>Slideshow menutupi seluruh layar (papan iklan)</small>
                </button>
              </div>
              <label v-if="editor.slide_scope === 'fullscreen'" class="ctrl-toggle" style="margin-top:10px">
                <input type="checkbox" :checked="editor.flash_over_fullscreen" @change="editor.flash_over_fullscreen = $event.target.checked; saveScope({ flash_over_fullscreen: $event.target.checked })" />
                <span class="toggle-track"></span>
                <span class="toggle-label">Tetap tampilkan panggilan pasien (flash nomor + suara) di atas slideshow</span>
              </label>
              <p v-if="editor.slide_scope === 'fullscreen' && !editor.flash_over_fullscreen" class="ctrl-lbl" style="opacity:.6; font-weight:400; margin-top:4px; color:#fcd34d">
                ⚠ TV target jadi papan iklan murni — panggilan pasien TIDAK ditampilkan.
              </p>
            </div>

            <div class="ctrl-sub-section">
              <div class="ctrl-row" style="align-items:center; gap:1rem">
                <p class="ctrl-lbl" style="margin:0">Durasi per Slide</p>
                <input type="range" v-model.number="editor.slide_interval" min="3" max="30" step="1" class="ctrl-range" />
                <span class="ctrl-dur-val">{{ editor.slide_interval }}s</span>
              </div>
            </div>

            <div class="ctrl-sub-section">
              <p class="ctrl-lbl">Daftar Gambar ({{ editor.slides.length }})</p>
              <div v-if="editor.slides.length === 0" class="ctrl-empty">Belum ada gambar ditambahkan</div>
              <div v-for="(s, i) in editor.slides" :key="i" class="slide-row">
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

            <button v-if="editor.slides.length" class="ctrl-action-btn" @click="applySlideshowMode">
              Aktifkan Slideshow di {{ editorTarget.name }}
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
                <div
                  v-for="(meta, key) in soundPresets"
                  :key="key"
                  :class="['sound-card', { active: soundPreset === key }]"
                  role="button"
                  tabindex="0"
                  @click="soundPreset = key"
                  @keydown.enter="soundPreset = key"
                  @keydown.space.prevent="soundPreset = key"
                >
                  <span class="sound-card-name">{{ meta.label }}</span>
                  <small>{{ meta.desc }}</small>
                  <button class="sound-test-btn" @click.stop="playSound(key)" title="Tes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="6 4 20 12 6 20 6 4"/></svg>
                  </button>
                </div>
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
              <button class="ctrl-action-btn" style="background:var(--lm); color:#06182E" @click="saveAudioDefaults">
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
                <p class="ctrl-lbl">Logo Rumah Sakit</p>
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
                <label class="ctrl-field-lbl">Nama Rumah Sakit</label>
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
                <button class="ctrl-action-btn" style="background:var(--lm); color:#06182E" @click="saveBranding">Simpan</button>
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
  /* Pin ke viewport (bukan 100vw/100vh): 100vw memasukkan lebar scrollbar →
     overflow 1px → muncul scrollbar + gutter putih di kanan/bawah. position:fixed
     inset:0 mengisi layar persis di semua kondisi/resolusi TV. */
  position: fixed;
  inset: 0;
  background: linear-gradient(180deg, #0B2440 0%, #06182E 100%);
  color: #fff;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  font-family: 'Inter', sans-serif;
}

/* TOP BAR */
.tv-topbar {
  height: 80px;
  padding: 0 2rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(0, 0, 0, 0.2);
  border-bottom: 1px solid rgba(56, 189, 248, 0.15);
  flex-shrink: 0;
}
.tv-logo { display: flex; align-items: center; gap: 1rem; }
.tv-logo svg { width: 56px; height: 56px; }
.tv-logo-img { width: 56px; height: 56px; object-fit: contain; }
.tv-brand { display: flex; flex-direction: column; }
.tv-brand-name {
  font-family: 'Space Grotesk', serif;
  font-size: 26px;
  line-height: 1;
}
.tv-brand-sub {
  font-size: 12px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(56, 189, 248, 0.7);
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
  background: linear-gradient(180deg, rgba(56, 189, 248, 0.08), rgba(56, 189, 248, 0.02));
  border: 1px solid rgba(56, 189, 248, 0.25);
  border-radius: 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 0 80px rgba(56, 189, 248, 0.1);
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
  background: var(--lm);
  color: #06182E;
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
  background: rgba(56, 189, 248, 0.15);
  border: 1px solid rgba(56, 189, 248, 0.3);
  padding: 6px 14px;
  border-radius: 30px;
  font-size: 13px;
  font-weight: 500;
}
.tv-status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--lm);
  animation: blink 1.5s infinite;
}
.tv-clock-wrap { text-align: right; }
.tv-clock {
  font-family: 'Space Grotesk', serif;
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
  background: #06182E;
  border: 1px solid rgba(56, 189, 248, 0.15);
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
  font-family: 'Space Grotesk', serif;
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
.dot.active { background: var(--lm); }

/* QUEUE PANEL */
.queue-panel {
  background: rgba(0, 0, 0, 0.25);
  border: 1px solid rgba(56, 189, 248, 0.15);
  border-radius: 18px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.queue-header {
  padding: 1rem 1.5rem;
  border-bottom: 1px solid rgba(56, 189, 248, 0.15);
}

.queue-header-title {
  font-family: 'Space Grotesk', serif;
  font-size: 20px;
}
.queue-header-sub {
  font-size: 12px;
  color: #fff;
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
  background: linear-gradient(135deg, rgba(56, 189, 248, 0.18), rgba(56, 189, 248, 0.04));
  border-color: rgba(56, 189, 248, 0.45);
  box-shadow: 0 0 18px rgba(56, 189, 248, 0.15);
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
  color: #fff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.station-card.has-called .sc-name { color: #fff; }
.sc-count {
  font-size: 9.5px;
  font-weight: 600;
  color: #fff;
  background: rgba(255, 255, 255, 0.12);
  padding: 2px 7px;
  border-radius: 10px;
  flex-shrink: 0;
}
.sc-called-lbl {
  font-size: 9px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: #fff;
  font-weight: 600;
  margin-bottom: 0.2rem;
}
.sc-called-lbl.muted { color: #fff; }
.sc-called-num {
  font-family: 'Space Grotesk', serif;
  font-size: 38px;
  line-height: 1;
  color: #fff;
  letter-spacing: 0.03em;
  text-shadow: 0 0 18px rgba(56, 189, 248, 0.3);
}
.sc-called-num.muted {
  font-size: 28px;
  color: #fff;
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
  color: #fff;
  margin-top: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sc-called-dokter {
  font-size: 11px;
  font-weight: 600;
  color: #7dd3fc;
  margin-top: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sc-next {
  font-size: 10.5px;
  color: #fff;
  margin-top: 4px;
}
.sc-next strong { color: #fff; font-weight: 700; }

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
  font-family: 'Inter', sans-serif;
  border-radius: 7px;
  cursor: pointer;
  transition: all 0.15s;
}
.station-tab:hover { color: #fff; background: rgba(56, 189, 248, 0.08); }
.station-tab.active {
  background: rgba(56, 189, 248, 0.18);
  border-color: rgba(56, 189, 248, 0.4);
  color: #fff;
}
.ctrl-section code {
  background: rgba(56, 189, 248, 0.12);
  color: var(--lm);
  padding: 1px 6px;
  border-radius: 4px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
}
.ctrl-feedback { font-size: 12px; padding: 6px 12px; border-radius: 8px; align-self: center; }
.ctrl-feedback.ok { background: rgba(56, 189, 248, 0.15); color: var(--lm); }
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
  font-family: 'Inter', sans-serif;
  color: rgba(255, 255, 255, 0.7);
  display: flex;
  flex-direction: column;
  gap: 3px;
  transition: all 0.15s;
}
.sound-card:hover { background: rgba(56, 189, 248, 0.08); border-color: rgba(56, 189, 248, 0.25); }
.sound-card.active {
  background: rgba(56, 189, 248, 0.15);
  border-color: rgba(56, 189, 248, 0.5);
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
  background: rgba(56, 189, 248, 0.18);
  border: 1px solid rgba(56, 189, 248, 0.35);
  color: var(--lm);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s;
}
.sound-test-btn:hover { background: rgba(56, 189, 248, 0.35); }
.sound-test-btn svg { width: 10px; height: 10px; }

.now-serving {
  margin: 1.25rem;
  padding: 1.5rem;
  background: linear-gradient(135deg, rgba(56, 189, 248, 0.18), rgba(56, 189, 248, 0.05));
  border: 1.5px solid rgba(56, 189, 248, 0.35);
  border-radius: 16px;
  text-align: center;
}
.now-serving.empty { opacity: 0.45; }
.ns-label {
  font-size: 11px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: rgba(56, 189, 248, 0.7);
  font-weight: 600;
  margin-bottom: 0.5rem;
}
.ns-number {
  font-family: 'Space Grotesk', serif;
  font-size: 88px;
  line-height: 1;
  color: var(--lm);
  letter-spacing: 0.04em;
  margin-bottom: 0.5rem;
  text-shadow: 0 0 30px rgba(56, 189, 248, 0.4);
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
  background: rgba(56, 189, 248, 0.2);
  color: var(--lm);
  border: 1px solid rgba(56, 189, 248, 0.4);
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
  cursor: pointer; font-family: 'Inter', sans-serif; transition: all .2s;
}
.ns-done-btn:hover { background: rgba(56,189,248,.2); border-color: rgba(56,189,248,.4); color: var(--lm); }
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
  font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: all .15s;
}
.qtab-tv:hover { color: rgba(255,255,255,.7); }
.qtab-tv.a { color: var(--lm); border-bottom-color: var(--lm); }
.qtab-ct { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: rgba(56,189,248,.2); color: var(--lm); }
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
  font-family: 'Space Grotesk', serif;
  font-size: 26px;
  color: var(--lm);
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
.qi-status.done { background: rgba(56, 189, 248, 0.15); color: var(--lm); }
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
  border-top: 1px solid rgba(56, 189, 248, 0.15);
  flex-shrink: 0;
}
.ticker-label {
  background: var(--lm);
  color: #06182E;
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
  stroke: var(--lm);
  stroke-width: 2;
  stroke-linecap: round;
}
.ticker-sep { color: rgba(56, 189, 248, 0.5); font-size: 18px; }
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
  color: var(--lm);
  background: rgba(56, 189, 248, 0.1);
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
  background: linear-gradient(160deg, #123154, #06182E);
  border: 1px solid rgba(56, 189, 248, 0.25);
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
  background: rgba(56, 189, 248, 0.1);
  border: 1px solid rgba(56, 189, 248, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1.25rem;
}
.pin-icon svg { width: 26px; height: 26px; }
.pin-title {
  font-family: 'Space Grotesk', serif;
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
  border: 1.5px solid rgba(56, 189, 248, 0.25);
  background: rgba(0, 0, 0, 0.3);
  color: var(--lm);
  font-size: 28px;
  text-align: center;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
  font-family: 'Space Grotesk', serif;
  caret-color: transparent;
}
.pin-box:focus {
  border-color: var(--lm);
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
}
.pin-box.filled { border-color: rgba(56, 189, 248, 0.5); }
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
  background: var(--lm);
  color: #06182E;
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
  /* 10000 > audio-unlock-overlay (9999): saat TV baru menyala & belum di-unlock,
     flash panggilan tetap tampil DI ATAS overlay unlock, dan klik flash sekaligus
     meng-unlock audio untuk panggilan berikutnya. */
  position: fixed; inset: 0; z-index: 10000;
  background: linear-gradient(135deg, #06182E 0%, #0B2440 50%, #06182E 100%);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; animation: flash-in .3s ease;
}
@keyframes flash-in { from { opacity: 0; transform: scale(1.04); } to { opacity: 1; transform: scale(1); } }
.flash-content { text-align: center; }
.flash-label-top {
  font-size: 16px; letter-spacing: .3em; text-transform: uppercase;
  color: rgba(56,189,248,.7); font-weight: 600; margin-bottom: 1rem;
  font-family: 'Inter', sans-serif;
}
.flash-num {
  font-family: 'Space Grotesk', serif;
  font-size: clamp(100px, 20vw, 220px); line-height: 1;
  color: var(--lm); letter-spacing: .04em;
  text-shadow: 0 0 80px rgba(56,189,248,.6), 0 0 40px rgba(56,189,248,.3);
  animation: flash-pulse 1.5s ease-in-out infinite;
}
@keyframes flash-pulse { 0%,100%{text-shadow:0 0 80px rgba(56,189,248,.6),0 0 40px rgba(56,189,248,.3)} 50%{text-shadow:0 0 120px rgba(56,189,248,.9),0 0 60px rgba(56,189,248,.5)} }
.flash-name {
  font-size: clamp(22px, 3vw, 40px); font-weight: 500; margin-top: 1rem; color: #fff;
  font-family: 'Inter', sans-serif;
}
.flash-poly { font-size: clamp(14px, 2vw, 24px); color: rgba(255,255,255,.5); margin-top: .4rem; font-family: 'Inter', sans-serif; }
.flash-dokter { font-size: clamp(16px, 2.2vw, 28px); font-weight: 600; color: #7dd3fc; margin-top: .3rem; font-family: 'Inter', sans-serif; }
.flash-badge {
  display: inline-flex; align-items: center; gap: 8px; margin-top: 2rem;
  background: rgba(56,189,248,.2); border: 1.5px solid rgba(56,189,248,.4);
  color: var(--lm); padding: 10px 24px; border-radius: 30px;
  font-size: clamp(13px, 1.5vw, 18px); font-weight: 700; font-family: 'Inter', sans-serif;
}
.flash-badge svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.flash-hint { font-size: 12px; color: rgba(255,255,255,.3); margin-top: 2.5rem; font-family: 'Inter', sans-serif; }

/* Ctrl toggles */
.ctrl-toggles { display: flex; flex-direction: column; gap: 10px; }
.ctrl-toggle { display: flex; align-items: center; gap: 10px; cursor: pointer; }
.ctrl-toggle input { display: none; }
.toggle-track {
  width: 36px; height: 20px; border-radius: 10px; background: rgba(255,255,255,.15);
  position: relative; transition: background .2s; flex-shrink: 0;
}
.ctrl-toggle input:checked ~ .toggle-track { background: var(--lm); }
.toggle-track::after {
  content: ''; position: absolute; width: 14px; height: 14px; border-radius: 50%;
  background: #fff; top: 3px; left: 3px; transition: left .2s;
}
.ctrl-toggle input:checked ~ .toggle-track::after { left: 19px; }
.toggle-label { font-size: 12px; color: rgba(255,255,255,.7); }

/* Antrean tab controls */
.antr-ctrl-row { display: flex; gap: .5rem; margin-bottom: .85rem; flex-wrap: wrap; }
.antr-call { background: rgba(56,189,248,.2) !important; color: var(--lm) !important; border-color: rgba(56,189,248,.35) !important; }
.antr-call:hover:not(:disabled) { background: rgba(56,189,248,.35) !important; }
.antr-done { background: rgba(255,255,255,.08) !important; color: rgba(255,255,255,.7) !important; }
.antr-current { background: rgba(56,189,248,.12); border: 1px solid rgba(56,189,248,.3); border-radius: 10px; padding: .7rem 1rem; text-align: center; margin-top: .4rem; }
.antr-lbl { font-size: 9px; letter-spacing: .2em; text-transform: uppercase; color: rgba(56,189,248,.7); font-weight: 600; margin-bottom: .25rem; }
.antr-num { font-family: 'Space Grotesk', serif; font-size: 36px; color: var(--lm); line-height: 1; }
.antr-meta { font-size: 11px; color: rgba(255,255,255,.5); margin-top: .25rem; }
.antr-empty { font-size: 12px; color: rgba(255,255,255,.3); text-align: center; padding: .65rem; }
.antr-list { display: flex; flex-direction: column; gap: 5px; }
.antr-item { display: flex; align-items: center; gap: .55rem; padding: .5rem .7rem; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.06); border-radius: 8px; }
.antr-item-num { font-size: 12px; font-weight: 700; color: var(--lm); width: 50px; flex-shrink: 0; font-family: 'JetBrains Mono', monospace; }
.antr-item-name { flex: 1; font-size: 12px; color: rgba(255,255,255,.8); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.antr-item-poly { font-size: 10px; color: rgba(255,255,255,.35); flex-shrink: 0; }
.antr-call-btn { width: 26px; height: 26px; border-radius: 6px; background: rgba(56,189,248,.15); border: 1px solid rgba(56,189,248,.3); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--lm); transition: all .15s; flex-shrink: 0; }
.antr-call-btn:hover { background: rgba(56,189,248,.3); }
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
  background: linear-gradient(160deg, #102C4D, #07182C);
  border: 1px solid rgba(56, 189, 248, 0.2);
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
  border-bottom: 1px solid rgba(56, 189, 248, 0.15);
  flex-shrink: 0;
}
.ctrl-header-left {
  display: flex;
  align-items: center;
  gap: 10px;
  font-family: 'Space Grotesk', serif;
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
  border-bottom: 1px solid rgba(56, 189, 248, 0.12);
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
  font-family: 'Inter', sans-serif;
}
.ctrl-tab svg { width: 15px; height: 15px; }
.ctrl-tab:hover { background: rgba(255, 255, 255, 0.05); color: rgba(255, 255, 255, 0.75); }
.ctrl-tab.active {
  background: rgba(56, 189, 248, 0.15);
  border-color: rgba(56, 189, 248, 0.3);
  color: var(--lm);
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
  border: 1px dashed rgba(56, 189, 248, 0.3);
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
  border: 1px solid rgba(56, 189, 248, 0.2);
  border-radius: 10px;
  padding: 10px 14px;
  color: #fff;
  font-size: 14px;
  font-family: 'Inter', sans-serif;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.ctrl-input:focus {
  border-color: rgba(56, 189, 248, 0.5);
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.1);
}
.ctrl-input::placeholder { color: rgba(255, 255, 255, 0.25); }
.ctrl-textarea {
  width: 100%;
  box-sizing: border-box;
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(56, 189, 248, 0.2);
  border-radius: 10px;
  padding: 10px 14px;
  color: #fff;
  font-size: 13px;
  font-family: 'Inter', sans-serif;
  outline: none;
  resize: vertical;
  transition: border-color 0.2s;
}
.ctrl-textarea:focus { border-color: rgba(56, 189, 248, 0.5); }
.ctrl-textarea::placeholder { color: rgba(255, 255, 255, 0.25); }
.ctrl-action-btn {
  padding: 10px 20px;
  background: var(--lm);
  color: #06182E;
  border: none;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: opacity 0.2s, transform 0.15s;
  flex-shrink: 0;
  font-family: 'Inter', sans-serif;
}
.ctrl-action-btn:hover { opacity: 0.85; transform: translateY(-1px); }
.ctrl-action-btn:disabled { opacity: 0.35; cursor: not-allowed; transform: none; }
.ctrl-err { font-size: 13px; color: #f87171; margin: 4px 0 0; }
.ctrl-ok {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--lm);
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
.ctrl-feedback.ok { background: rgba(56, 189, 248, 0.12); color: var(--lm); border: 1px solid rgba(56, 189, 248, 0.25); }
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
  font-family: 'Inter', sans-serif;
}
.mode-card svg { width: 28px; height: 28px; }
.mode-card span { font-size: 14px; font-weight: 600; }
.mode-card small { font-size: 11px; color: rgba(255, 255, 255, 0.3); }
.mode-card:hover {
  border-color: rgba(56, 189, 248, 0.25);
  background: rgba(56, 189, 248, 0.06);
  color: rgba(255, 255, 255, 0.8);
}
.mode-card.active {
  border-color: var(--lm);
  background: rgba(56, 189, 248, 0.12);
  color: var(--lm);
}
.mode-card.active small { color: rgba(56, 189, 248, 0.6); }
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
.ctrl-range { flex: 1; accent-color: var(--lm); }
.ctrl-dur-val {
  font-size: 14px;
  font-weight: 600;
  color: var(--lm);
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
.icon-btn.ok:hover { background: rgba(56, 189, 248, 0.15); color: var(--lm); border-color: rgba(56, 189, 248, 0.3); }

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

/* TARGET cards (Global vs TV ini) & cakupan (panel vs fullscreen) */
.target-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
.target-card {
  display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
  padding: 12px 14px; border-radius: 12px; cursor: pointer; text-align: left;
  background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1);
  color: #fff; transition: border-color .15s, background .15s;
}
.target-card svg { width: 22px; height: 22px; margin-bottom: 4px; color: rgba(56,189,248,.8); }
.target-card span { font-weight: 600; font-size: 14px; }
.target-card small { font-size: 11px; color: rgba(255,255,255,.55); }
.target-card:hover { background: rgba(255,255,255,.07); }
.target-card.active { border-color: var(--lm); background: rgba(56,189,248,.12); }
.target-card:disabled { opacity: .4; cursor: not-allowed; }

/* Daftar TV terdaftar */
.tvdev-row {
  display: flex; align-items: center; gap: 8px; padding: 6px 0;
  border-bottom: 1px solid rgba(255,255,255,.06);
}
.tvdev-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.tvdev-dot.on  { background: #34d399; box-shadow: 0 0 6px #34d399; }
.tvdev-dot.off { background: rgba(255,255,255,.25); }
.tvdev-name { flex: 1; min-width: 0; padding: 5px 8px; font-size: 13px; }
.tvdev-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; flex-shrink: 0; }
.tvdev-badge.global { background: rgba(56,189,248,.18); color: #7dd3fc; }
.tvdev-badge.self   { background: rgba(252,211,77,.18); color: #fcd34d; }
.tvdev-this {
  font-size: 10px; font-weight: 800; letter-spacing: .3px; flex-shrink: 0;
  padding: 2px 9px; border-radius: 20px;
  background: rgba(52,211,153,.2); color: #6ee7b7;
  border: 1px solid rgba(52,211,153,.45);
}
/* Sorot baris TV yang sedang membuka panel ini agar mudah dibedakan dari
   baris "TV Baru" lain yang serupa. */
.tvdev-row.is-this {
  background: rgba(52,211,153,.08);
  border-radius: 8px;
  box-shadow: inset 0 0 0 1px rgba(52,211,153,.35);
  padding-left: 8px; padding-right: 8px;
  animation: tvdev-pulse 1.6s ease-out 2;
}
@keyframes tvdev-pulse {
  0%   { box-shadow: inset 0 0 0 1px rgba(52,211,153,.35), 0 0 0 0 rgba(52,211,153,.5); }
  100% { box-shadow: inset 0 0 0 1px rgba(52,211,153,.35), 0 0 0 10px rgba(52,211,153,0); }
}
.tvdev-hint {
  font-size: 11.5px; line-height: 1.45; margin: 8px 0 4px;
  padding: 7px 10px; border-radius: 8px;
  background: rgba(252,211,77,.12); color: #fde68a;
  border: 1px solid rgba(252,211,77,.25);
}
.tvdev-hint.ok {
  background: rgba(52,211,153,.1); color: #a7f3d0;
  border-color: rgba(52,211,153,.28);
}

/* Media fullscreen overlay (papan iklan) */
.media-fullscreen {
  position: fixed; inset: 0; z-index: 500; background: #06182E;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.media-fullscreen .slideshow.fs,
.media-fullscreen .yt-frame.fs,
.media-fullscreen .local-video.fs,
.media-fullscreen .video-placeholder.fs {
  width: 100%; height: 100%;
}
.media-fullscreen .slideshow.fs { position: relative; }
.media-fullscreen .yt-frame.fs { border: 0; }
.media-fullscreen .local-video.fs { object-fit: contain; background: #000; }
.media-fullscreen .video-placeholder.fs {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem;
}

/* ═══════════════════════════════════════════════════════════════════════════
   MODE POLIKLINIK — 1 layar dinamis (media + antrean berputar)
   Lebar media/antrean bertukar sesuai scene aktif (transisi flex-grow).
   ═══════════════════════════════════════════════════════════════════════════ */
.poli-main {
  display: flex;
  gap: 1.25rem;
}
.poli-media,
.poli-queue {
  min-width: 0;
  min-height: 0;
  transition: flex-grow 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}
/* Default (scene antrean aktif): antrean dominan 70%, media menyusut 30% */
.poli-media { flex: 3 1 0; }
.poli-queue { flex: 7 1 0; }
/* Scene media aktif: media dominan 70%, strip antrean 30% */
.poli-main.scene-media .poli-media { flex-grow: 7; }
.poli-main.scene-media .poli-queue { flex-grow: 3; }

/* Panel media — samakan tampilan dengan .video-panel */
.poli-media {
  background: #06182E;
  border: 1px solid rgba(56, 189, 248, 0.15);
  border-radius: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  position: relative;
}
.poli-media > *:not(.video-placeholder) {
  aspect-ratio: 16 / 9;
  width: 100%;
  max-width: 100%;
  max-height: 100%;
}

/* Panel antrean */
.poli-queue {
  background: rgba(0, 0, 0, 0.25);
  border: 1px solid rgba(56, 189, 248, 0.15);
  border-radius: 18px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 1rem 1.1rem;
}
.scene-dots {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-bottom: 0.9rem;
  flex-shrink: 0;
}
.scene-dot {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  padding: 4px 12px;
  border-radius: 30px;
  color: rgba(255, 255, 255, 0.5);
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid transparent;
  transition: all 0.3s;
}
.scene-dot.active {
  color: #06182E;
  background: var(--lm);
  border-color: var(--lm);
}
.scene-body {
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* Transisi antar scene */
.scene-enter-active,
.scene-leave-active { transition: opacity 0.4s ease, transform 0.4s ease; }
.scene-enter-from { opacity: 0; transform: translateY(14px); }
.scene-leave-to   { opacity: 0; transform: translateY(-14px); }

/* ── Papan poliklinik (kartu per dokter) ── */
.poli-board {
  display: grid;
  gap: 1rem;
  height: 100%;
  align-content: start;
  grid-auto-rows: 1fr;
  overflow-y: auto;
}
.poli-board.cols-1 { grid-template-columns: 1fr; }
.poli-board.cols-2 { grid-template-columns: repeat(2, 1fr); }
.poli-board.cols-3 { grid-template-columns: repeat(3, 1fr); }
.poli-board.cols-4 { grid-template-columns: repeat(4, 1fr); }

.poli-card {
  display: flex;
  flex-direction: column;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(56, 189, 248, 0.15);
  border-radius: 16px;
  padding: 1rem 1.1rem;
  overflow: hidden;
}
.poli-card.has-called {
  border-color: var(--lm);
  background: rgba(56, 189, 248, 0.1);
  box-shadow: 0 0 30px rgba(56, 189, 248, 0.12);
}
.pc-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.pc-poli {
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #7dd3fc;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pc-svc {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.05em;
  padding: 2px 8px;
  border-radius: 20px;
  text-transform: uppercase;
}
.pc-svc.bpjs     { background: rgba(34, 197, 94, 0.18);  color: #4ade80; }
.pc-svc.eksekutif { background: rgba(252, 211, 77, 0.18); color: #fcd34d; }
.pc-dokter {
  font-size: 17px;
  font-weight: 600;
  color: #fff;
  margin-top: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pc-body {
  display: flex;
  align-items: flex-end;
  gap: 1rem;
  margin: 0.7rem 0 0.5rem;
}
.pc-cell { display: flex; flex-direction: column; }
.pc-cell:last-child { margin-left: auto; text-align: right; }
.pc-lbl {
  font-size: 10px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.6);
  margin-bottom: 0.2rem;
}
.pc-num {
  font-family: 'Space Grotesk', serif;
  font-size: clamp(28px, 3.4vw, 64px);
  line-height: 1;
  color: #fff;
  letter-spacing: 0.02em;
  text-shadow: 0 0 22px rgba(56, 189, 248, 0.35);
}
.pc-num.muted { color: rgba(255, 255, 255, 0.4); text-shadow: none; }
.pc-wait-num {
  font-family: 'Space Grotesk', serif;
  font-size: clamp(24px, 2.6vw, 48px);
  line-height: 1;
  color: var(--lm);
}
.pc-next {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.7);
  margin-top: auto;
  padding-top: 0.4rem;
  border-top: 1px dashed rgba(56, 189, 248, 0.18);
}
/* Kecilkan angka saat kolom rapat */
.poli-board.cols-3 .pc-num { font-size: clamp(26px, 2.4vw, 48px); }
.poli-board.cols-4 .pc-num { font-size: clamp(22px, 2vw, 40px); }
.poli-board.cols-3 .pc-dokter,
.poli-board.cols-4 .pc-dokter { font-size: 15px; }

/* ── Judul papan (farmasi/stasiun/ringkasan) ── */
.board-title {
  font-family: 'Space Grotesk', serif;
  font-size: 22px;
  margin-bottom: 1rem;
  color: #fff;
}

/* ── Papan farmasi ── */
.farmasi-board { height: 100%; display: flex; flex-direction: column; }
.fb-main { flex: 1; display: flex; gap: 1.25rem; min-height: 0; }
.fb-call {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(56, 189, 248, 0.15);
  border-radius: 18px;
  padding: 1.5rem;
}
.fb-call.has-called {
  border-color: var(--lm);
  background: rgba(56, 189, 248, 0.1);
  box-shadow: 0 0 40px rgba(56, 189, 248, 0.15);
}
.fb-num {
  font-family: 'Space Grotesk', serif;
  font-size: clamp(60px, 9vw, 160px);
  line-height: 1;
  color: #fff;
  text-shadow: 0 0 40px rgba(56, 189, 248, 0.4);
  margin: 0.4rem 0;
}
.fb-num.muted { color: rgba(255, 255, 255, 0.4); text-shadow: none; }
.fb-wait { font-size: 18px; color: var(--lm); font-weight: 600; }
.fb-side {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  overflow-y: auto;
}
.fb-side-lbl {
  font-size: 12px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.55);
  margin-top: 0.4rem;
}
.fb-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.fb-chip {
  font-family: 'Space Grotesk', serif;
  font-size: 24px;
  padding: 6px 14px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: #fff;
}
.fb-chip.ready {
  background: rgba(34, 197, 94, 0.15);
  border-color: rgba(34, 197, 94, 0.4);
  color: #4ade80;
}
.fb-empty { color: rgba(255, 255, 255, 0.4); font-size: 18px; }

/* ── Strip stasiun lain ── */
.strip-board { height: 100%; display: flex; flex-direction: column; }
.strip-grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.9rem;
  align-content: start;
}
.strip-card {
  display: flex;
  flex-direction: column;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(56, 189, 248, 0.15);
  border-radius: 14px;
  padding: 0.9rem 1rem;
}
.strip-card.has-called { border-color: var(--lm); background: rgba(56, 189, 248, 0.1); }
.strip-name {
  font-size: 13px;
  font-weight: 600;
  color: #7dd3fc;
  letter-spacing: 0.03em;
}
.strip-num {
  font-family: 'Space Grotesk', serif;
  font-size: clamp(30px, 3vw, 52px);
  line-height: 1;
  color: #fff;
  margin: 0.3rem 0;
}
.strip-num.muted { color: rgba(255, 255, 255, 0.4); }
.strip-wait { font-size: 12px; color: rgba(255, 255, 255, 0.6); }

/* ── Strip ringkas saat scene media ── */
.media-strip { height: 100%; display: flex; flex-direction: column; overflow-y: auto; }
.ms-sec-lbl {
  font-size: 12px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--lm);
  margin: 0.6rem 0 0.4rem;
}
.ms-rows { display: flex; flex-direction: column; gap: 0.4rem; }
.ms-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.55rem 0.8rem;
  background: rgba(255, 255, 255, 0.04);
  border-radius: 10px;
}
.ms-poli {
  flex: 1;
  font-size: 14px;
  color: #fff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ms-call {
  font-family: 'Space Grotesk', serif;
  font-size: 22px;
  color: #fff;
  min-width: 84px;
  text-align: right;
}
.ms-call.muted { color: rgba(255, 255, 255, 0.4); }
.ms-w {
  font-size: 12px;
  color: var(--lm);
  font-weight: 600;
  min-width: 64px;
  text-align: right;
}
</style>
