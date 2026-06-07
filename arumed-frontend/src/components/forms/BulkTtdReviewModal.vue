<script setup>
/**
 * BulkTtdReviewModal — telaah berurutan banyak dokumen lalu tandatangani semua
 * sekaligus dengan 1× PIN (stempel digital + QR via backend bulkSign).
 *
 * Alur medis-legal: dokter WAJIB membuka/melewati TIAP berkas sebelum tombol
 * "Tandatangani semua" aktif (penghitung "ditelaah X/M"). Preview di-cache di Map
 * agar Prev/Next instan. Hasil dipetakan ke "X berhasil, Y dilewati, Z gagal".
 *
 * Props:  documents: Array<{id, template_name, template_code, visit_id, status, patient}>
 * Emits:  close — tutup tanpa aksi.
 *         done({ signedIds }) — selesai; parent buang baris sukses & refresh.
 */
import { computed, onMounted, ref } from 'vue'
import { formTemplateApi } from '@/services/api'

const props = defineProps({
  documents: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'done'])

const idx          = ref(0)
const previewHtml  = ref('')
const previewLoading = ref(false)
const cache        = new Map()       // docId → html
const reviewed     = ref(new Set())  // docId yang sudah ditelaah

// PIN
const pinMode  = ref(false)
const pinValue = ref('')
const pinError = ref('')
const busy     = ref(false)

// Hasil
const result = ref(null) // { signed:[], skipped:[], failed:[] }

const total      = computed(() => props.documents.length)
const current     = computed(() => props.documents[idx.value] ?? null)
const reviewedCount = computed(() => reviewed.value.size)
const allReviewed = computed(() => total.value > 0 && reviewedCount.value >= total.value)

function patientName(d) { return d?.patient?.name ?? '—' }

async function loadPreview(i) {
  const doc = props.documents[i]
  if (!doc) return
  // Tandai sudah ditelaah.
  if (!reviewed.value.has(doc.id)) {
    const s = new Set(reviewed.value)
    s.add(doc.id)
    reviewed.value = s
  }
  if (cache.has(doc.id)) {
    previewHtml.value = cache.get(doc.id)
    return
  }
  previewLoading.value = true
  previewHtml.value = ''
  try {
    const { data } = await formTemplateApi.snapshot(doc.id)
    let html = data.data?.rendered_html ?? ''
    if (!html && doc.template_code && doc.visit_id) {
      const r = await formTemplateApi.renderForm(doc.template_code, doc.visit_id)
      html = r.data.data?.html ?? ''
    }
    if (!html) html = '<p style="color:#7b8794">(Tidak ada pratinjau)</p>'
    cache.set(doc.id, html)
    previewHtml.value = html
  } catch (e) {
    previewHtml.value = '<p style="color:#b42323">(Gagal render preview)</p>'
  } finally {
    previewLoading.value = false
  }
}

function goto(i) {
  if (i < 0 || i >= total.value) return
  idx.value = i
  loadPreview(i)
}
function prev() { goto(idx.value - 1) }
function next() { goto(idx.value + 1) }

function openPin() {
  if (!allReviewed.value) return
  pinValue.value = ''
  pinError.value = ''
  pinMode.value = true
}

async function submit() {
  const pin = pinValue.value.trim()
  if (!/^\d{4,6}$/.test(pin)) { pinError.value = 'PIN harus 4–6 digit angka.'; return }
  busy.value = true
  pinError.value = ''
  try {
    const ids = props.documents.map((d) => d.id)
    const { data } = await formTemplateApi.bulkSign(ids, pin)
    result.value = data.data ?? { signed: [], skipped: [], failed: [] }
    pinMode.value = false
  } catch (e) {
    pinError.value = e.response?.status === 401
      ? 'PIN tidak sesuai.'
      : (e.response?.data?.message ?? 'Gagal menandatangani.')
  } finally {
    busy.value = false
  }
}

function finish() {
  emit('done', { signedIds: result.value?.signed ?? [] })
}

onMounted(() => loadPreview(0))
</script>

<template>
  <Teleport to="body">
    <div class="bk-overlay" @click.self="emit('close')">
      <div class="bk-modal">
        <header class="bk-head">
          <div>
            <h3>Telaah &amp; Tandatangani</h3>
            <p class="bk-sub">{{ total }} dokumen · ditelaah {{ reviewedCount }}/{{ total }}</p>
          </div>
          <button class="bk-close" @click="emit('close')" title="Tutup">×</button>
        </header>

        <!-- Hasil -->
        <div v-if="result" class="bk-result">
          <div class="res-row"><b>{{ result.signed.length }}</b> berhasil ditandatangani</div>
          <div v-if="result.skipped.length" class="res-row res-skip">
            <b>{{ result.skipped.length }}</b> dilewati (menunggu TTD pihak lain)
            <ul><li v-for="s in result.skipped" :key="s.id">{{ s.reason }}</li></ul>
          </div>
          <div v-if="result.failed.length" class="res-row res-fail">
            <b>{{ result.failed.length }}</b> gagal
            <ul><li v-for="f in result.failed" :key="f.id">{{ f.error }}</li></ul>
          </div>
          <button class="btn bk-finish" @click="finish">Selesai</button>
        </div>

        <!-- Telaah -->
        <div v-else class="bk-body">
          <aside class="bk-list">
            <button
              v-for="(d, i) in documents"
              :key="d.id"
              class="bk-item"
              :class="{ on: i === idx, done: reviewed.has(d.id) }"
              @click="goto(i)"
            >
              <span class="bk-item-name">{{ d.template_name ?? d.template_code }}</span>
              <span class="bk-item-sub">{{ patientName(d) }}</span>
              <span v-if="reviewed.has(d.id)" class="bk-check">✓</span>
            </button>
          </aside>
          <div class="bk-viewer">
            <div class="bk-vnav">
              <button class="lnk" :disabled="idx <= 0" @click="prev">‹ Sebelumnya</button>
              <span class="bk-pos">Berkas {{ idx + 1 }} dari {{ total }}</span>
              <button class="lnk" :disabled="idx >= total - 1" @click="next">Berikutnya ›</button>
            </div>
            <div class="bk-doc">
              <div v-if="previewLoading" class="bk-state">Memuat preview…</div>
              <div v-else v-html="previewHtml"></div>
            </div>
          </div>
        </div>

        <footer v-if="!result" class="bk-foot">
          <span class="bk-foot-hint" v-if="!allReviewed">
            Buka semua berkas dulu untuk mengaktifkan tanda tangan ({{ reviewedCount }}/{{ total }}).
          </span>
          <span class="bk-foot-hint ok" v-else>Semua berkas sudah ditelaah.</span>
          <button class="btn" :disabled="!allReviewed" @click="openPin">Tandatangani semua</button>
        </footer>
      </div>

      <!-- PIN -->
      <div v-if="pinMode" class="pin-overlay" @click.self="pinMode = false">
        <div class="pin-modal">
          <h4 class="pin-title">Tanda Tangan Elektronik</h4>
          <p class="pin-hint">Masukkan PIN untuk menandatangani <b>{{ total }}</b> dokumen sekaligus (stempel + QR).</p>
          <input
            v-model="pinValue"
            type="password"
            inputmode="numeric"
            maxlength="6"
            class="pin-input"
            placeholder="••••••"
            autocomplete="off"
            @keyup.enter="submit()"
          />
          <div v-if="pinError" class="pin-err">{{ pinError }}</div>
          <div class="pin-actions">
            <button type="button" class="lnk" :disabled="busy" @click="pinMode = false">Batal</button>
            <button type="button" class="btn" :disabled="busy" @click="submit()">
              {{ busy ? 'Memproses…' : 'Tanda Tangani' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.bk-overlay {
  --ink: #1f2937; --muted: #6b7280; --faint: #9ca3af;
  --line: #e5e7eb; --accent: #4b5563; --danger: #b42323; --ok: #1E9E63; --warn: #6b7280;
  position: fixed; inset: 0; background: rgba(0,0,0,.35);
  display: flex; align-items: center; justify-content: center; z-index: 1250; padding: 1rem;
  color: var(--ink); font-size: 13px;
}
.bk-modal {
  width: min(980px, 96vw); height: min(86vh, 720px); background: #fff; border-radius: 12px;
  overflow: hidden; display: flex; flex-direction: column;
}
.bk-head {
  display: flex; justify-content: space-between; align-items: center; gap: 1rem;
  padding: 1rem 1.4rem; border-bottom: 1px solid var(--line);
}
.bk-head h3 { margin: 0; font-size: 15px; font-weight: 600; }
.bk-sub { margin: 3px 0 0; font-size: 12.5px; color: var(--muted); }
.bk-close { width: 28px; height: 28px; border-radius: 6px; border: 0; background: none; cursor: pointer; color: var(--faint); font-size: 22px; line-height: 1; flex-shrink: 0; }
.bk-close:hover { background: #f1f2f4; color: var(--ink); }

.bk-body { flex: 1; display: flex; min-height: 0; }
.bk-list { width: 230px; flex-shrink: 0; border-right: 1px solid var(--line); overflow-y: auto; }
.bk-item {
  width: 100%; text-align: left; border: 0; border-bottom: 1px solid #f1f2f4; background: transparent;
  padding: 0.65rem 0.9rem; cursor: pointer; position: relative; display: flex; flex-direction: column; gap: 2px;
}
.bk-item:hover { background: #fafafa; }
.bk-item.on { background: #f5f8fb; box-shadow: inset 2px 0 0 var(--accent); }
.bk-item-name { font-size: 12.5px; font-weight: 500; }
.bk-item-sub { font-size: 11.5px; color: var(--faint); }
.bk-check { position: absolute; right: 10px; top: 11px; color: var(--ok); font-size: 12px; }

.bk-viewer { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.bk-vnav {
  display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
  padding: 0.6rem 1.2rem; border-bottom: 1px solid var(--line);
}
.bk-pos { font-size: 12.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
.bk-doc { flex: 1; overflow-y: auto; padding: 1.5rem 2rem; }
.bk-state { text-align: center; color: var(--faint); padding: 2rem; }

.bk-foot {
  display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap;
  padding: 0.9rem 1.4rem; border-top: 1px solid var(--line);
}
.bk-foot-hint { font-size: 12px; color: var(--muted); }
.bk-foot-hint.ok { color: var(--ok); }

.bk-result { flex: 1; overflow-y: auto; padding: 1.8rem 2rem; }
.res-row { font-size: 13.5px; margin-bottom: 0.9rem; }
.res-row b { font-size: 17px; font-weight: 600; }
.res-skip b { color: var(--warn); }
.res-fail b { color: var(--danger); }
.res-row ul { margin: 6px 0 0; padding-left: 1.2rem; font-size: 12px; color: var(--muted); }
.bk-finish { margin-top: 0.6rem; }

/* Buttons / links */
.btn {
  padding: 7px 14px; border: 1px solid var(--accent); border-radius: 7px;
  font-size: 13px; font-weight: 500; background: var(--accent); color: #fff; cursor: pointer; transition: opacity .15s;
}
.btn:hover:not(:disabled) { opacity: 0.88; }
.btn:disabled { opacity: 0.45; cursor: not-allowed; }
.lnk { border: 0; background: none; padding: 4px 2px; cursor: pointer; font-size: 13px; color: var(--accent); font-weight: 500; }
.lnk:hover:not(:disabled) { text-decoration: underline; }
.lnk:disabled { opacity: 0.4; cursor: not-allowed; }

/* PIN */
.pin-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.35);
  display: flex; align-items: center; justify-content: center; z-index: 1350; padding: 1rem;
}
.pin-modal { width: min(340px, 94vw); background: #fff; border-radius: 12px; padding: 1.6rem; text-align: center; }
.pin-title { margin: 0 0 8px; font-size: 15px; font-weight: 600; }
.pin-hint { margin: 0 0 16px; font-size: 12.5px; color: var(--muted); }
.pin-input { width: 100%; padding: 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 22px; letter-spacing: 8px; text-align: center; box-sizing: border-box; color: var(--ink); }
.pin-input:focus { outline: none; border-color: var(--accent); }
.pin-err { margin: 10px 0 0; font-size: 12.5px; color: var(--danger); }
.pin-actions { display: flex; gap: 1rem; justify-content: center; align-items: center; margin-top: 1.2rem; }

/* ─── HP sempit: master-detail (list 230px | viewer) jadi tumpukan vertikal ─── */
@media (max-width: 640px) {
  .bk-modal { width: 96vw; height: 92vh; }
  .bk-body { flex-direction: column; }
  /* List jadi strip tab horizontal di atas (geser samping), bukan kolom kiri. */
  .bk-list {
    width: 100%; flex-shrink: 0; display: flex; gap: 6px; padding: 6px;
    overflow-x: auto; overflow-y: hidden;
    border-right: 0; border-bottom: 1px solid var(--line);
  }
  .bk-item { min-width: 150px; border-bottom: 0; border-radius: 8px; }
  .bk-viewer { flex: 1; min-height: 0; }
  .bk-doc { padding: 1rem; }
  .bk-foot { flex-wrap: wrap; gap: 0.5rem; }
}
</style>
