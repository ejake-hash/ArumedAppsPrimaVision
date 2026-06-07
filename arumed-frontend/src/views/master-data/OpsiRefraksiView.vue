<script setup>
/**
 * OpsiRefraksiView — master OPSI REFRAKSI (combobox RefraksionisView).
 *
 * Setiap "kind" (sphere/cylinder/axis/keratometri/add/visus) punya konfigurasi:
 *   - mode RANGE  → opsi di-generate dari min/max/step (mis. Axis 0–180 step 5).
 *   - mode LIST   → daftar nilai literal (mis. Visus 6/6 … NLP).
 * Admin bisa menyesuaikan tanpa mengubah data refraksi lama: input di
 * RefraksionisView berupa combobox (boleh pilih ATAU ketik), nilai disimpan varchar.
 *
 * Permission:
 *   - master_data.read  → list/view
 *   - master_data.write → edit
 */
import { computed, onMounted, ref } from 'vue'
import { masterApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('master_data.write'))

const list = ref([])
const loading = ref(false)
const toasts = ref([])
let tid = 0
function toast(type, msg) {
  const id = ++tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter((t) => t.id !== id) }, 3200)
}

async function load() {
  loading.value = true
  try {
    const { data } = await masterApi.refraksiOpsi.list()
    list.value = data.data ?? []
  } catch (e) {
    toast('w', 'Gagal memuat: ' + (e.response?.data?.message ?? e.message))
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ── Modal edit ──────────────────────────────────────────────────────────
const formOpen = ref(false)
const formBusy = ref(false)
const formError = ref('')
const editingId = ref(null)
const form = ref(blank())

function blank() {
  return { kind: '', label: '', mode: 'range', format: 'plain', min_value: null, max_value: null, step: null, values: [], is_active: true }
}

function openEdit(row) {
  editingId.value = row.id
  form.value = {
    kind: row.kind,
    label: row.label,
    mode: row.mode,
    format: row.format,
    min_value: row.min_value,
    max_value: row.max_value,
    step: row.step,
    values: Array.isArray(row.values) ? [...row.values] : [],
    is_active: !!row.is_active,
  }
  valuesText.value = (form.value.values ?? []).join('\n')
  formError.value = ''
  formOpen.value = true
}

// Untuk mode LIST: textarea baris-per-nilai ↔ array.
const valuesText = ref('')
function syncValuesFromText() {
  form.value.values = valuesText.value
    .split(/[\n,]/)
    .map((s) => s.trim())
    .filter(Boolean)
}

// Preview live di modal (meniru generator backend untuk feedback instan).
const previewOpts = computed(() => {
  const f = form.value
  if (f.mode === 'list') {
    return (valuesText.value.split(/[\n,]/).map((s) => s.trim()).filter(Boolean))
  }
  const min = Number(f.min_value), max = Number(f.max_value), step = Number(f.step)
  if (!Number.isFinite(min) || !Number.isFinite(max) || !Number.isFinite(step) || step <= 0 || max < min) return []
  const out = []
  const count = Math.round((max - min) / step)
  if (count > 2000) return ['(terlalu banyak — perkecil rentang/step)']
  for (let i = 0; i <= count; i++) out.push(fmt(min + i * step, f.format))
  return out
})
function fmt(val, format) {
  if (format === 'signed_diopter') {
    const s = val.toFixed(2)
    return (val > 0 ? '+' : '') + s
  }
  if (Math.floor(val) === val) return String(val | 0)
  return String(parseFloat(val.toFixed(2)))
}

async function save() {
  if (!canWrite.value) return
  formError.value = ''
  formBusy.value = true
  try {
    if (form.value.mode === 'list') syncValuesFromText()
    const payload = {
      label: form.value.label,
      mode: form.value.mode,
      format: form.value.format,
      is_active: form.value.is_active,
      min_value: form.value.mode === 'range' ? form.value.min_value : null,
      max_value: form.value.mode === 'range' ? form.value.max_value : null,
      step: form.value.mode === 'range' ? form.value.step : null,
      values: form.value.mode === 'list' ? form.value.values : null,
    }
    await masterApi.refraksiOpsi.update(editingId.value, payload)
    toast('s', `Opsi "${form.value.label}" tersimpan`)
    formOpen.value = false
    await load()
  } catch (e) {
    formError.value = e.response?.data?.message ?? e.message ?? 'Gagal menyimpan'
  } finally {
    formBusy.value = false
  }
}
</script>

<template>
  <div class="ofr">
    <header class="ofr-head">
      <div>
        <h2>Opsi Refraksi</h2>
        <p class="sub">Pilihan dropdown/combobox di stasiun Refraksionis (Autoref S/C/Axis, Keratometri, ADD, Visus). Petugas tetap bisa mengetik nilai di luar daftar.</p>
      </div>
      <button class="btn btn-secondary btn-sm" @click="load" :disabled="loading">↻ Muat ulang</button>
    </header>

    <div v-if="loading" class="empty">Memuat…</div>

    <div v-else class="grid">
      <div v-for="row in list" :key="row.id" class="card" :class="{ off: !row.is_active }">
        <div class="card-top">
          <div>
            <div class="ktitle">{{ row.label }}</div>
            <div class="kmeta">
              <span class="badge" :class="row.mode === 'range' ? 'b-range' : 'b-list'">{{ row.mode === 'range' ? 'Rentang' : 'Daftar' }}</span>
              <span v-if="row.format === 'signed_diopter'" class="badge b-fmt">±dioptri</span>
              <span class="kcode">{{ row.kind }}</span>
              <span v-if="!row.is_active" class="badge b-off">Nonaktif</span>
            </div>
          </div>
          <button v-if="canWrite" class="btn btn-primary btn-xs" @click="openEdit(row)">Ubah</button>
        </div>

        <div class="kbody">
          <template v-if="row.mode === 'range'">
            <div class="kv"><span>Rentang</span><b>{{ row.min_value }} … {{ row.max_value }}</b></div>
            <div class="kv"><span>Step</span><b>{{ row.step }}</b></div>
          </template>
          <div class="kv"><span>Jumlah opsi</span><b>{{ row.count }}</b></div>
          <div class="preview">
            <span v-for="(p, i) in row.preview" :key="i" class="chip">{{ p }}</span>
            <span v-if="row.count > row.preview.length" class="chip more">+{{ row.count - row.preview.length }}…</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal edit -->
    <div v-if="formOpen" class="ovl" @click.self="formOpen = false">
      <div class="modal">
        <div class="modal-head">
          <h3>Ubah Opsi — {{ form.label }} <span class="kcode">{{ form.kind }}</span></h3>
          <button class="x" @click="formOpen = false" aria-label="Tutup">×</button>
        </div>
        <div class="modal-body">
          <div class="fg">
            <label class="fl">Label</label>
            <input v-model="form.label" class="form-input" placeholder="mis. Sphere (S)" />
          </div>
          <div class="g2">
            <div class="fg">
              <label class="fl">Mode</label>
              <select v-model="form.mode" class="form-input">
                <option value="range">Rentang (generate min/max/step)</option>
                <option value="list">Daftar nilai (mis. Visus)</option>
              </select>
            </div>
            <div class="fg">
              <label class="fl">Format label</label>
              <select v-model="form.format" class="form-input">
                <option value="plain">Polos (90, 43.5)</option>
                <option value="signed_diopter">±Dioptri (+1.50, -0.75)</option>
              </select>
            </div>
          </div>

          <template v-if="form.mode === 'range'">
            <div class="g3">
              <div class="fg">
                <label class="fl">Minimum</label>
                <input v-model.number="form.min_value" type="number" step="any" class="form-input" placeholder="-25" />
              </div>
              <div class="fg">
                <label class="fl">Maksimum</label>
                <input v-model.number="form.max_value" type="number" step="any" class="form-input" placeholder="25" />
              </div>
              <div class="fg">
                <label class="fl">Step</label>
                <input v-model.number="form.step" type="number" step="any" class="form-input" placeholder="0.25" />
              </div>
            </div>
          </template>

          <template v-else>
            <div class="fg">
              <label class="fl">Daftar Nilai <span class="hint">(satu per baris atau dipisah koma)</span></label>
              <textarea v-model="valuesText" class="form-input ta" rows="6" placeholder="6/6&#10;6/9&#10;HM&#10;LP&#10;NLP"></textarea>
            </div>
          </template>

          <div class="fg chkrow">
            <label class="chk"><input type="checkbox" v-model="form.is_active" /> Aktif (tampil di combobox)</label>
          </div>

          <div class="prev-box">
            <div class="prev-title">Pratinjau opsi ({{ previewOpts.length }})</div>
            <div class="preview">
              <span v-for="(p, i) in previewOpts.slice(0, 40)" :key="i" class="chip">{{ p }}</span>
              <span v-if="previewOpts.length > 40" class="chip more">+{{ previewOpts.length - 40 }}…</span>
              <span v-if="!previewOpts.length" class="muted">— belum ada opsi valid —</span>
            </div>
          </div>

          <p v-if="formError" class="err">{{ formError }}</p>
        </div>
        <div class="modal-foot">
          <button class="btn btn-secondary" @click="formOpen = false" :disabled="formBusy">Batal</button>
          <button class="btn btn-primary" @click="save" :disabled="formBusy || !canWrite">{{ formBusy ? 'Menyimpan…' : 'Simpan' }}</button>
        </div>
      </div>
    </div>

    <!-- Toasts -->
    <div class="toasts">
      <div v-for="t in toasts" :key="t.id" class="toast" :class="'t-' + t.type">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.ofr { display: flex; flex-direction: column; gap: 1rem; }
.ofr-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
.ofr-head h2 { font-family: 'Space Grotesk', serif; font-size: 19px; color: var(--td); margin: 0; }
.sub { font-size: 12.5px; color: var(--tm); margin: 4px 0 0; max-width: 640px; line-height: 1.5; }
.empty { color: var(--tm); padding: 2rem; text-align: center; }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.9rem; }
.card { border: 1px solid var(--gb); border-radius: 12px; padding: 0.9rem 1rem; background: var(--bc); display: flex; flex-direction: column; gap: 0.7rem; }
.card.off { opacity: 0.6; }
.card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
.ktitle { font-weight: 600; color: var(--td); font-size: 14px; }
.kmeta { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; margin-top: 4px; }
.kcode { font-family: monospace; font-size: 11px; color: var(--tu); background: var(--bs); padding: 1px 6px; border-radius: 5px; }
.badge { font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.03em; }
.b-range { background: #e0f2fe; color: #0369a1; }
.b-list { background: #ede9fe; color: #6d28d9; }
.b-fmt { background: #fef3c7; color: #92400e; }
.b-off { background: #fee2e2; color: #b91c1c; }

.kbody { display: flex; flex-direction: column; gap: 5px; }
.kv { display: flex; justify-content: space-between; font-size: 12.5px; color: var(--tm); }
.kv b { color: var(--td); }
.preview { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.chip { font-size: 11px; padding: 2px 7px; border-radius: 6px; background: var(--bs); color: var(--td); border: 1px solid var(--gb); }
.chip.more { background: transparent; color: var(--tu); border-style: dashed; }
.muted { color: var(--tu); font-size: 12px; }

/* Modal (inline overlay — hindari Teleport agar CSS scoped tetap berlaku) */
.ovl { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); display: flex; align-items: center; justify-content: center; z-index: 9000; padding: 1rem; }
.modal { background: var(--bc); border-radius: 14px; width: min(560px, 100%); max-height: 90vh; overflow: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.2rem; border-bottom: 1px solid var(--gb); }
.modal-head h3 { margin: 0; font-size: 16px; color: var(--td); display: flex; align-items: center; gap: 8px; }
.x { background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: var(--tu); }
.modal-body { padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.8rem; }
.modal-foot { display: flex; justify-content: flex-end; gap: 0.6rem; padding: 0.9rem 1.2rem; border-top: 1px solid var(--gb); }

.fg { display: flex; flex-direction: column; gap: 4px; }
.fl { font-size: 12px; font-weight: 600; color: var(--tm); }
.hint { font-weight: 400; color: var(--tu); }
.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; }
.g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.7rem; }
.form-input { padding: 8px 10px; border: 1px solid var(--gb); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--td); width: 100%; }
.ta { resize: vertical; font-family: monospace; }
.chkrow { flex-direction: row; }
.chk { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--td); cursor: pointer; }
.prev-box { border: 1px dashed var(--gb); border-radius: 8px; padding: 0.6rem 0.7rem; background: var(--bs); }
.prev-title { font-size: 11px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 5px; }
.err { color: #b91c1c; font-size: 12.5px; margin: 0; }

.btn { padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid transparent; }
.btn-sm { padding: 6px 11px; font-size: 12px; }
.btn-xs { padding: 4px 10px; font-size: 11.5px; }
.btn-primary { background: var(--ga, #0E3A66); color: #fff !important; }
.btn-secondary { background: var(--bs); color: var(--td); border-color: var(--gb); }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }

.toasts { position: fixed; bottom: 1.2rem; right: 1.2rem; display: flex; flex-direction: column; gap: 0.5rem; z-index: 9500; }
.toast { padding: 10px 16px; border-radius: 9px; color: #fff; font-size: 13px; box-shadow: 0 6px 20px rgba(0,0,0,0.18); }
.t-s { background: #16a34a; } .t-w { background: #dc2626; } .t-i { background: #2563eb; }
</style>
