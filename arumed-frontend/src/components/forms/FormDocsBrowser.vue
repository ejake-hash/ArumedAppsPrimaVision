<script setup>
/**
 * FormDocsBrowser — daftar terpadu dokumen rekam medis untuk satu kunjungan.
 *
 * Menggabungkan 3 section (Resume / Surat / Consent) jadi SATU daftar yang bisa
 * dicari + difilter status, dikelompokkan per kategori. Logika isi/cetak/TTD
 * tetap di FormRMRenderer (di-reuse apa adanya per item).
 *
 * Endpoint /rekam-medis/forms mewajibkan `section`, jadi fetch dilakukan paralel
 * per section lalu di-merge di sisi client (tidak menyentuh backend).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { formTemplateApi } from '@/services/api'
import FormRMRenderer from './FormRMRenderer.vue'

const props = defineProps({
  visitId:   { type: String, required: true },
  patientId: { type: String, default: null },
  // Station + seksi dibuat parametrik agar komponen reusable lintas stasiun.
  // Default: Dokter dgn 3 seksi (perilaku lama, backward-compatible).
  station:   { type: String, default: 'dokter' },
  // Sembunyikan toolbar cari + filter status (utk daftar ringkas, mis. modal Bedah).
  showToolbar: { type: Boolean, default: true },
  sections:  { type: Array, default: () => ([
    { key: 'resume_output', label: 'Resume Medis' },
    { key: 'surat',         label: 'Surat-Surat' },
    { key: 'consent',       label: 'Consent & Persetujuan' },
  ]) },
})

const allForms = ref([])
const loading  = ref(false)
const error    = ref('')

const searchText   = ref('')
const statusFilter = ref('all')   // all | todo | draft | final

const STATUS_FILTERS = [
  { key: 'all',   label: 'Semua' },
  { key: 'todo',  label: 'Perlu diisi' },
  { key: 'draft', label: 'Draft' },
  { key: 'final', label: 'Final' },
]

// Per-section fetch dgn allSettled — satu section gagal (mis. 403) TIDAK
// mem-blank seluruh daftar. Kegagalan dirangkum di `error` agar terlihat.
async function load() {
  if (!props.visitId) return
  loading.value = true
  error.value = ''
  const settled = await Promise.allSettled(
    props.sections.map((s) =>
      formTemplateApi
        .forms({ station: props.station, section: s.key, visit_id: props.visitId })
        .then((res) => (res.data?.data ?? []).map((f) => ({
          ...f, _section: s.key, _sectionLabel: s.label,
        }))),
    ),
  )
  const ok = []
  const fails = []
  settled.forEach((r, i) => {
    if (r.status === 'fulfilled') ok.push(...r.value)
    else {
      const sec = props.sections[i]?.key
      const e = r.reason
      fails.push(`${sec}: ${e?.response?.status ?? ''} ${e?.response?.data?.message ?? e?.message ?? 'gagal'}`.trim())
    }
  })
  allForms.value = ok
  if (fails.length) error.value = `Sebagian dokumen gagal dimuat — ${fails.join(' · ')}`
  loading.value = false
}

onMounted(load)
defineExpose({ load })
watch(() => props.visitId, load)

// Status existing doc → bucket intent. existing_document null = belum ada doc.
function bucketOf(form) {
  const st = form.existing_document?.status
  if (!st) return 'todo'
  if (st === 'FINALIZED' || st === 'FINAL') return 'final'
  if (['DRAFT', 'RENDERED', 'WAITING_SIGNATURE', 'PENDING_SIGNATURE'].includes(st)) return 'draft'
  // VOID / REJECTED / lainnya → bisa dikerjakan ulang.
  return 'todo'
}

const groupedForms = computed(() => {
  const q = searchText.value.trim().toLowerCase()
  const sf = statusFilter.value

  const match = (f) => {
    if (sf !== 'all' && bucketOf(f) !== sf) return false
    if (q) {
      const hay = `${f.name ?? ''} ${f.code ?? ''}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  }

  // Selektivitas per-fungsi (backend `relevance`): 'recommended' di-pin ke atas,
  // 'optional' menyusul. Stabil (preserve urutan asli dalam tiap bucket).
  const relRank = (f) => (f.relevance === 'optional' ? 1 : 0)

  return props.sections
    .map((s) => ({
      key: s.key,
      label: s.label,
      items: allForms.value
        .filter((f) => f._section === s.key && match(f))
        .map((f, i) => ({ f, i }))
        .sort((a, b) => (relRank(a.f) - relRank(b.f)) || (a.i - b.i))
        .map((x) => x.f),
    }))
    .filter((g) => g.items.length > 0)
})

const totalMatches = computed(() =>
  groupedForms.value.reduce((n, g) => n + g.items.length, 0),
)

function resetFilters() {
  searchText.value = ''
  statusFilter.value = 'all'
}
</script>

<template>
  <div class="fdb">
    <!-- Sticky toolbar: search + filter status -->
    <div v-if="showToolbar" class="fdb-toolbar">
      <div class="fdb-search">
        <svg class="fdb-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          v-model="searchText"
          class="fdb-search-input"
          type="text"
          placeholder="Cari dokumen (nama / kode)…"
          aria-label="Cari dokumen"
        />
        <button
          v-if="searchText"
          class="fdb-search-clear"
          type="button"
          aria-label="Hapus pencarian"
          @click="searchText = ''"
        >×</button>
      </div>

      <div class="fdb-chips" role="group" aria-label="Filter status dokumen">
        <button
          v-for="sf in STATUS_FILTERS" :key="sf.key"
          type="button"
          :class="['fdb-chip', statusFilter === sf.key ? 'active' : '']"
          :aria-pressed="statusFilter === sf.key"
          @click="statusFilter = sf.key"
        >{{ sf.label }}</button>
      </div>
    </div>

    <!-- Banner error (boleh tampil bersama kartu — allSettled) -->
    <div v-if="error" class="fdb-error-banner">
      <span>{{ error }}</span>
      <button type="button" class="fdb-reload-btn" @click="load">Muat ulang</button>
    </div>

    <!-- States -->
    <div v-if="loading" class="fdb-state fdb-loading">
      <span class="fdb-spin" aria-hidden="true"></span>
      Memuat dokumen…
    </div>

    <div v-else-if="!allForms.length" class="fdb-state fdb-empty">
      <svg class="fdb-empty-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <div>
        <div class="fdb-empty-title">Belum ada dokumen termuat</div>
        <div class="fdb-empty-hint">Station <em>{{ station }}</em> · {{ sections.length }} section diminta, 0 dokumen kembali.<br>Klik Muat ulang; bila tetap kosong, cek Network tab (request <code>forms?station={{ station }}</code>).</div>
      </div>
      <button class="fdb-reset-btn" type="button" @click="load">Muat ulang</button>
    </div>

    <div v-else-if="!totalMatches" class="fdb-state fdb-empty">
      <svg class="fdb-empty-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <div>
        <div class="fdb-empty-title">Tidak ada dokumen yang cocok</div>
        <div class="fdb-empty-hint">Coba ubah kata kunci atau filter status.</div>
      </div>
      <button class="fdb-reset-btn" type="button" @click="resetFilters">Reset filter</button>
    </div>

    <!-- Grouped list -->
    <div v-else class="fdb-groups">
      <section v-for="g in groupedForms" :key="g.key" class="fdb-group">
        <h4 class="fdb-group-title">{{ g.label }} <span class="fdb-group-count">{{ g.items.length }}</span></h4>
        <div class="fdb-list">
          <div
            v-for="t in g.items"
            :key="t.id"
            :class="['fdb-item', t.relevance === 'optional' && 'fdb-item-optional']"
          >
            <span v-if="t.relevance === 'recommended'" class="fdb-rec-badge">Disarankan</span>
            <FormRMRenderer
              :template="t"
              :visit-id="visitId"
              :patient-id="patientId"
              @deleted="load"
            />
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.fdb { display: flex; flex-direction: column; gap: 0; }

/* ── Toolbar (sticky) ─────────────────────────────────────────────────────── */
.fdb-toolbar {
  position: sticky; top: 0; z-index: 2;
  display: flex; flex-direction: column; gap: 0.55rem;
  padding-bottom: 0.75rem; margin-bottom: 0.25rem;
  background: var(--bc);
  border-bottom: 1px solid var(--gb);
}
.fdb-search {
  position: relative; display: flex; align-items: center;
}
.fdb-search-icon {
  position: absolute; left: 10px; width: 15px; height: 15px;
  fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; pointer-events: none;
}
.fdb-search-input {
  width: 100%; padding: 0.55rem 2rem 0.55rem 2rem;
  border: 1px solid var(--gb); border-radius: 9px;
  font: inherit; font-size: 13px; color: var(--td); background: var(--bs);
  transition: border-color .15s, box-shadow .15s;
}
.fdb-search-input::placeholder { color: var(--tu); }
.fdb-search-input:focus {
  outline: none; border-color: #1763d4;
  box-shadow: 0 0 0 3px rgba(23, 99, 212, 0.12);
  background: var(--bc);
}
.fdb-search-clear {
  position: absolute; right: 6px; width: 24px; height: 24px;
  display: inline-flex; align-items: center; justify-content: center;
  border: 0; border-radius: 6px; background: transparent;
  font-size: 17px; line-height: 1; color: var(--tu); cursor: pointer;
}
.fdb-search-clear:hover { background: var(--bg); color: var(--td); }

.fdb-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.fdb-chip {
  padding: 5px 12px; min-height: 30px;
  border: 1.5px solid var(--gb); border-radius: 999px;
  background: var(--bc); color: var(--tm);
  font: inherit; font-size: 12px; font-weight: 600; cursor: pointer;
  transition: all .15s;
}
.fdb-chip:hover { border-color: #c6dcfb; color: var(--td); }
.fdb-chip:focus-visible { outline: 2px solid #1763d4; outline-offset: 2px; }
.fdb-chip.active {
  background: #1763d4; color: #fff !important; border-color: #1763d4;
}

/* ── States ───────────────────────────────────────────────────────────────── */
.fdb-state { padding: 1.5rem 1rem; font-size: 13px; color: var(--tm); }
.fdb-loading { display: flex; align-items: center; justify-content: center; gap: 8px; }
.fdb-spin {
  width: 13px; height: 13px; border-radius: 50%;
  border: 2px solid var(--gb); border-top-color: #1763d4;
  animation: fdb-spin .7s linear infinite;
}
@keyframes fdb-spin { to { transform: rotate(360deg); } }
.fdb-error {
  color: #b42323; background: #fff0f0; border: 1px solid #fbb;
  border-radius: 8px; padding: 0.7rem 1rem;
}
.fdb-error-banner {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  margin: 0.5rem 0; padding: 0.55rem 0.85rem;
  background: #fff0f0; border: 1px solid #fbb; border-radius: 8px;
  font-size: 12px; color: #b42323;
}
.fdb-reload-btn {
  flex-shrink: 0; padding: 4px 12px; border: 1px solid #b42323; border-radius: 6px;
  background: #fff; color: #b42323; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer;
}
.fdb-reload-btn:hover { background: #b42323; color: #fff; }
.fdb-empty {
  display: flex; flex-direction: column; align-items: center; gap: 0.6rem;
  text-align: center; padding: 2rem 1rem;
}
.fdb-empty-icon {
  width: 34px; height: 34px; fill: none; stroke: var(--th);
  stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round;
}
.fdb-empty-title { font-size: 13px; font-weight: 700; color: var(--tm); }
.fdb-empty-hint { margin-top: 2px; font-size: 11.5px; color: var(--th); line-height: 1.4; }
.fdb-empty-hint em { color: var(--tm); font-style: normal; font-weight: 600; }
.fdb-reset-btn {
  margin-top: 0.3rem; padding: 6px 14px;
  border: 1px solid #1763d4; border-radius: 8px;
  background: #1763d4; color: #fff !important;
  font: inherit; font-size: 12px; font-weight: 600; cursor: pointer;
}
.fdb-reset-btn:hover { filter: brightness(1.08); }

/* ── Grouped list ─────────────────────────────────────────────────────────── */
.fdb-groups { display: flex; flex-direction: column; gap: 1rem; padding-top: 0.5rem; }
.fdb-group { display: flex; flex-direction: column; gap: 0.45rem; }
.fdb-group-title {
  display: flex; align-items: center; gap: 7px; margin: 0;
  font-size: 11px; font-weight: 700; letter-spacing: 0.06em;
  text-transform: uppercase; color: var(--tu);
}
.fdb-group-count {
  padding: 1px 8px; border-radius: 999px;
  background: var(--bg); color: var(--tm);
  font-size: 10.5px; font-weight: 700; letter-spacing: 0;
  font-variant-numeric: tabular-nums;
}
.fdb-list { display: flex; flex-direction: column; gap: 0.5rem; }

/* ── Relevance (selektivitas per-fungsi) ──────────────────────────────────── */
.fdb-item { position: relative; }
/* Form tak cocok konteks bedah tapi tetap bisa dipilih via search → diturunkan
   visualnya, tidak disembunyikan (soft). */
.fdb-item-optional { opacity: 0.72; }
.fdb-item-optional:hover, .fdb-item-optional:focus-within { opacity: 1; }
.fdb-rec-badge {
  position: absolute; top: 6px; right: 8px; z-index: 1;
  font-size: 9.5px; font-weight: 700; letter-spacing: 0.02em;
  color: #0a7d3f; background: #e6f7ec; border: 1px solid #b6e6c8;
  padding: 1px 7px; border-radius: 999px; pointer-events: none;
}
</style>
