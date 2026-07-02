<script setup>
/**
 * Kartu "SOAP / CPPT" lintas-episode — dipakai bersama oleh DokterView,
 * PerawatView, RefraksionisView. Menampilkan timeline CPPT terpadu (semua PPA:
 * Dokter / Perawat / Refraksionis) dengan navigasi panah per tanggal kunjungan
 * (descending — kunjungan terbaru lebih dulu di idx 0).
 *
 * Sumber data = RmeAggregatorService::cppt (1 mesin) via endpoint per-stasiun;
 * tiap view mengoper `fetcher`-nya sendiri (mis. refraksiApi.riwayatCppt) agar
 * RBAC route stasiun tetap berlaku. READ-ONLY.
 */
import { ref, computed, watch } from 'vue'

const props = defineProps({
  // ID pasien aktif. Null → kartu kosong.
  patientId: { type: String, default: null },
  // (patientId) => Promise<axiosResponse> dengan bentuk { data: { data: [...] } }.
  fetcher: { type: Function, required: true },
  title: { type: String, default: 'SOAP / CPPT' },
})

const entries = ref([])
const loading = ref(false)
const pageIdx = ref(0)

async function load() {
  pageIdx.value = 0
  if (!props.patientId) { entries.value = []; return }
  loading.value = true
  try {
    const { data } = await props.fetcher(props.patientId)
    entries.value = data.data ?? []
  } catch {
    entries.value = []
  } finally {
    loading.value = false
  }
}
watch(() => props.patientId, load, { immediate: true })
// Parent memanggil reload() sehabis finalisasi/tanda tangan agar kartu segar.
defineExpose({ reload: load })

// Kelompokkan entri per tanggal (descending). idx 0 = kunjungan terakhir.
const pages = computed(() => {
  const groups = new Map()
  for (const e of entries.value ?? []) {
    const key = e.date ?? '—'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(e)
  }
  return [...groups.entries()]
    .sort((a, b) => {
      const ta = Date.parse(a[0]), tb = Date.parse(b[0])
      if (!Number.isNaN(ta) && !Number.isNaN(tb)) return tb - ta      // tanggal terbaru dulu
      return a[0] < b[0] ? 1 : a[0] > b[0] ? -1 : 0                    // fallback string desc
    })
    .map(([date, items]) => ({ date, items }))
})
const currentPage = computed(() => pages.value[pageIdx.value] ?? pages.value[0] ?? null)
const pageLabel = computed(() => (pageIdx.value === 0 ? 'Kunjungan terakhir' : 'Kunjungan sebelumnya'))

// Stasiun asal yang muncul pada tanggal ini (dedup, urut kemunculan). Menegaskan
// timeline ini LINTAS-EPISODE: satu tanggal bisa memuat >1 stasiun (mis. Poli + IGD).
const pageStations = computed(() => {
  const seen = []
  for (const e of currentPage.value?.items ?? []) {
    const ep = e.episode || 'RAJAL'
    if (!seen.includes(ep)) seen.push(ep)
  }
  return seen
})

function epLabel(e) {
  return ({ RAJAL: 'Rawat Jalan', IGD: 'IGD', RANAP: 'Rawat Inap', POLI: 'Poli' })[e] ?? (e ?? '–')
}
function ppaLabel(r) {
  return ({
    DOKTER: 'Dokter', PERAWAT: 'Perawat', REFRAKSIONIS: 'Refraksionis',
    APOTEKER: 'Apoteker', GIZI: 'Gizi', FISIOTERAPIS: 'Fisioterapis', LAINNYA: 'PPA',
  })[r] ?? (r ?? '')
}
function ppaClass(r) {
  return 'ppa-' + String(r || 'LAINNYA').toLowerCase()
}
function fmtDT(dt) {
  if (!dt) return '–'
  const d = new Date(String(dt).replace(' ', 'T'))
  if (isNaN(d)) return dt
  return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
function fmtDate(s) {
  if (!s) return '–'
  const d = new Date(String(s).replace(' ', 'T'))
  if (isNaN(d)) return s
  return d.toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })
}

// Sketsa mata (OD/OS) — thumbnail read-only di timeline; klik → perbesar (lightbox).
function hasSketsa(d) { return !!(d && (d.od?.png_base64 || d.os?.png_base64)) }
const lightbox = ref(null)   // { src, label } | null
function openLightbox(src, label) { if (src) lightbox.value = { src, label } }
function closeLightbox() { lightbox.value = null }
</script>

<template>
  <div class="cpc card">
    <div class="cpc-head">
      <div class="cpc-title">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        {{ title }}
      </div>
      <span class="cpc-count">{{ pages.length }} kunjungan</span>
    </div>

    <div v-if="loading" class="cpc-empty">Memuat riwayat…</div>
    <div v-else-if="!pages.length" class="cpc-empty">Belum ada catatan SOAP/CPPT</div>

    <template v-else-if="currentPage">
      <!-- Navigasi per tanggal: panah KIRI ‹ → kunjungan lebih baru, KANAN › → lebih lama. -->
      <div class="cpc-pager">
        <button class="cpc-pager-btn" title="Kunjungan lebih baru" :disabled="pageIdx <= 0" @click="pageIdx--">‹</button>
        <div class="cpc-pager-info">
          <div class="cpc-pager-date">{{ fmtDate(currentPage.date) }}</div>
          <div class="cpc-pager-sub">{{ pageLabel }} · {{ currentPage.items.length }} entri</div>
          <div v-if="pageStations.length" class="cpc-pager-stations">
            <span v-for="ep in pageStations" :key="ep" class="cpc-ep" :class="'ep-' + ep" title="Stasiun asal">{{ epLabel(ep) }}</span>
          </div>
        </div>
        <button class="cpc-pager-btn" title="Kunjungan lebih lama" :disabled="pageIdx >= pages.length - 1" @click="pageIdx++">›</button>
      </div>

      <div class="cpc-list">
        <div v-for="(c, i) in currentPage.items" :key="i" class="cpc-item" :class="ppaClass(c.ppa_role)">
          <div class="cpc-item-head">
            <span class="cpc-ppa" :class="ppaClass(c.ppa_role)">{{ ppaLabel(c.ppa_role) || (c.kind === 'SOAP' ? 'Dokter' : 'PPA') }}</span>
            <span v-if="c.kind === 'ASESMEN'" class="cpc-kind-tag" title="Asesmen awal triase">Asesmen Awal</span>
            <span class="cpc-ep" :class="'ep-' + c.episode" title="Stasiun asal CPPT">{{ epLabel(c.episode) }}</span>
            <span class="cpc-when">{{ fmtDT(c.datetime) }}</span>
          </div>
          <div v-if="c.author" class="cpc-by">{{ c.author }}</div>

          <div v-if="c.signed_at || c.verified_by" class="cpc-badges">
            <span v-if="c.signed_at" class="cpc-badge sgn" title="Ditandatangani penulis (PIN)">✓ Ditandatangani</span>
            <span v-if="c.verified_by" class="cpc-badge vrf" title="Diverifikasi DPJP">✓ Terverifikasi DPJP</span>
          </div>

          <!-- Body grid: tiap blok (vitals / S / O / A / P / Dx / Instruksi) mengisi
               kolom secara responsif → di kartu lebar memenuhi 2–3 kolom, di sempit
               (sidebar Dokter/Perawat/Refraksionis) otomatis menyusut 1 kolom. -->
          <div class="cpc-body">
            <div v-if="c.vitals && Object.keys(c.vitals).length" class="cpc-vt span-all">
              <span v-if="c.vitals.td">TD {{ c.vitals.td }}</span>
              <span v-if="c.vitals.nadi">N {{ c.vitals.nadi }}</span>
              <span v-if="c.vitals.spo2">SpO₂ {{ c.vitals.spo2 }}</span>
              <span v-if="c.vitals.suhu">S {{ c.vitals.suhu }}°</span>
              <span v-if="c.vitals.visus_od">VOD {{ c.vitals.visus_od }}</span>
              <span v-if="c.vitals.visus_os">VOS {{ c.vitals.visus_os }}</span>
              <span v-if="c.vitals.iop_od || c.vitals.iop_os">TIO {{ c.vitals.iop_od ?? '–' }}/{{ c.vitals.iop_os ?? '–' }}</span>
            </div>

            <div v-if="c.soap?.s" class="cpc-soap"><b class="s">S</b> {{ c.soap.s }}</div>
            <div v-if="c.soap?.o" class="cpc-soap"><b class="o">O</b> {{ c.soap.o }}</div>
            <div v-if="c.soap?.a" class="cpc-soap"><b class="a">A</b> {{ c.soap.a }}</div>
            <div v-if="c.soap?.p" class="cpc-soap"><b class="p">P</b> {{ c.soap.p }}</div>

            <div v-if="c.diagnosis" class="cpc-dx"><b>Dx:</b> {{ c.diagnosis }} {{ c.diagnosis_nama }}</div>
            <div v-if="c.instruksi" class="cpc-dx"><b>Instruksi:</b> {{ c.instruksi }}</div>

            <!-- Sketsa mata dari Pemeriksaan dokter — thumbnail read-only, klik utk perbesar. -->
            <div v-if="hasSketsa(c.eye_drawings)" class="cpc-sketsa span-all">
              <span class="cpc-sketsa-lbl">Sketsa</span>
              <img
                v-if="c.eye_drawings.od?.png_base64" class="cpc-sketsa-img"
                :src="c.eye_drawings.od.png_base64" alt="Sketsa OD" title="OD — klik perbesar"
                @click="openLightbox(c.eye_drawings.od.png_base64, 'OD (Kanan)')"
              />
              <img
                v-if="c.eye_drawings.os?.png_base64" class="cpc-sketsa-img"
                :src="c.eye_drawings.os.png_base64" alt="Sketsa OS" title="OS — klik perbesar"
                @click="openLightbox(c.eye_drawings.os.png_base64, 'OS (Kiri)')"
              />
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Lightbox perbesar sketsa (read-only). -->
    <Teleport to="body">
      <div v-if="lightbox" class="cpc-lb-overlay" @click.self="closeLightbox">
        <div class="cpc-lb">
          <div class="cpc-lb-head">
            <span>Sketsa Mata — {{ lightbox.label }}</span>
            <button class="cpc-lb-x" aria-label="Tutup" @click="closeLightbox">×</button>
          </div>
          <img :src="lightbox.src" :alt="'Sketsa ' + lightbox.label" class="cpc-lb-img" />
        </div>
      </div>
    </Teleport>

    <slot name="footer" />
  </div>
</template>

<style scoped>
.cpc { display: flex; flex-direction: column; min-width: 0; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
.cpc-head { display: flex; align-items: center; justify-content: space-between; padding: 0.7rem 0.9rem; border-bottom: 1px solid #eef0f3; }
.cpc-title { display: flex; align-items: center; gap: 0.45rem; font-size: 13px; font-weight: 700; color: #1e293b; }
.cpc-title svg { width: 16px; height: 16px; fill: none; stroke: #0e7490; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.cpc-count { font-size: 11px; color: #94a3b8; }
.cpc-empty { padding: 1.4rem 0.9rem; text-align: center; font-size: 12px; color: #94a3b8; }

/* Pager */
.cpc-pager { display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0.7rem; background: #f8fafc; border-bottom: 1px solid #eef0f3; }
.cpc-pager-btn { width: 30px; height: 30px; flex-shrink: 0; border: 1px solid #d8dee6; background: #fff; border-radius: 8px; font-size: 18px; line-height: 1; color: #334155; cursor: pointer; }
.cpc-pager-btn:hover:not(:disabled) { background: #eef2f7; border-color: #b6c0cc; }
.cpc-pager-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.cpc-pager-info { flex: 1; text-align: center; min-width: 0; }
.cpc-pager-date { font-size: 12.5px; font-weight: 700; color: #1e293b; }
.cpc-pager-sub { font-size: 10.5px; color: #94a3b8; }
.cpc-pager-stations { display: flex; flex-wrap: wrap; gap: 4px; justify-content: center; margin-top: 4px; }

/* List */
/* Tanpa batas tinggi / scroll dalam: entri tampil penuh; bila terlalu panjang
   penjelajahan via pager per-tanggal kunjungan (panah ‹ ›) di atas. */
.cpc-list { padding: 0.5rem; display: flex; flex-direction: column; gap: 0.5rem; }
.cpc-item { border: 1px solid #eef0f3; border-left: 3px solid #cbd5e1; border-radius: 8px; padding: 0.55rem 0.65rem; background: #fff; }
.cpc-item.ppa-dokter { border-left-color: #16a34a; }
.cpc-item.ppa-perawat { border-left-color: #d97706; }
.cpc-item.ppa-refraksionis { border-left-color: #0891b2; }
.cpc-item.ppa-apoteker { border-left-color: #7c3aed; }

.cpc-item-head { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
.cpc-ppa { font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 999px; color: #fff; background: #64748b; }
.cpc-ppa.ppa-dokter { background: #16a34a; }
.cpc-ppa.ppa-perawat { background: #d97706; }
.cpc-ppa.ppa-refraksionis { background: #0891b2; }
.cpc-ppa.ppa-apoteker { background: #7c3aed; }
.cpc-kind-tag { font-size: 9.5px; font-weight: 700; padding: 1px 7px; border-radius: 999px; background: #fff7ed; color: #b45309; border: 1px solid #fed7aa; }
/* Chip STASIUN ASAL — warna per episode, konsisten dgn IGD/Ranap
   (IGD merah · Rawat Inap ungu · Rawat Jalan/Poli biru). Menonjolkan asal lintas-episode. */
.cpc-ep { font-size: 9.5px; font-weight: 700; padding: 1px 7px; border-radius: 999px; background: #eef2f7; color: #475569; }
.cpc-ep.ep-IGD { background: #fee2e2; color: #b91c1c; }
.cpc-ep.ep-RANAP { background: #ede9fe; color: #6d28d9; }
.cpc-ep.ep-RAJAL, .cpc-ep.ep-POLI { background: #e0f2fe; color: #0369a1; }
.cpc-when { margin-left: auto; font-size: 10.5px; color: #94a3b8; }
.cpc-by { font-size: 11px; color: #64748b; margin-top: 2px; }

.cpc-badges { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.cpc-badge { font-size: 9.5px; font-weight: 600; padding: 1px 7px; border-radius: 999px; }
.cpc-badge.sgn { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.cpc-badge.vrf { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }

/* Body responsif: blok mengisi kolom (auto-fit). Lebar → 2-3 kolom terisi penuh;
   sempit (<~580px, mis. sidebar) → 1 kolom (menumpuk seperti semula). */
.cpc-body { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 6px 16px; margin-top: 6px; align-items: start; }
.cpc-body > .span-all { grid-column: 1 / -1; }

.cpc-vt { display: flex; flex-wrap: wrap; gap: 4px 8px; font-size: 11px; color: #475569; }
.cpc-vt span { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; }

.cpc-soap { font-size: 11.5px; color: #334155; line-height: 1.45; white-space: pre-wrap; }
.cpc-soap b { display: inline-block; width: 15px; font-weight: 800; }
.cpc-soap b.s { color: #1d4ed8; }
.cpc-soap b.o { color: #64748b; }
.cpc-soap b.a { color: #7e22ce; }
.cpc-soap b.p { color: #b45309; }
.cpc-dx { font-size: 11px; color: #475569; }
.cpc-dx b { color: #1e293b; }

/* Sketsa mata: baris thumbnail OD|OS (klik → lightbox). */
.cpc-sketsa { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.cpc-sketsa-lbl { font-size: 10.5px; font-weight: 700; color: #64748b; }
.cpc-sketsa-img {
  width: 84px; height: 70px; object-fit: contain;
  border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; cursor: zoom-in;
}
.cpc-sketsa-img:hover { border-color: #94a3b8; }

/* Lightbox */
.cpc-lb-overlay {
  position: fixed; inset: 0; z-index: 1200; background: rgba(15, 23, 42, 0.72);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
}
.cpc-lb { background: #fff; border-radius: 10px; overflow: hidden; max-width: 96vw; max-height: 92vh; display: flex; flex-direction: column; }
.cpc-lb-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.7rem 1rem; border-bottom: 1px solid #eef0f3; font-size: 13px; font-weight: 700; color: #1e293b; }
.cpc-lb-x { background: 0; border: 0; font-size: 22px; line-height: 1; color: #64748b; cursor: pointer; }
.cpc-lb-img { max-width: 90vw; max-height: 80vh; object-fit: contain; background: #fff; display: block; }
</style>
