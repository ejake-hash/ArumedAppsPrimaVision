<script setup>
import { ref, watch, computed } from 'vue'
import { ranapApi } from '@/services/api'

// Balance cairan (intake/output) — STARKES PAP.
const props = defineProps({ visitId: { type: String, required: true } })
const emit = defineEmits(['notify'])

const records = ref([])
const summary = ref({ intake_24h: 0, output_24h: 0, balance_24h: 0, intake_total: 0, output_total: 0, balance_total: 0 })
const busy = ref(false)

const CAT_INTAKE = ['Oral', 'Infus', 'Transfusi', 'Obat IV', 'Enteral/NGT']
const CAT_OUTPUT = ['Urin', 'Drain', 'Muntah', 'BAB', 'NGT', 'IWL', 'Perdarahan']

const empty = () => ({ direction: 'INTAKE', category: '', volume_ml: null, recorded_at: '', notes: '' })
const form = ref(empty())
const catOptions = computed(() => (form.value.direction === 'INTAKE' ? CAT_INTAKE : CAT_OUTPUT))

async function load() {
  if (!props.visitId) return
  try {
    const r = await ranapApi.fluidBalance(props.visitId)
    records.value = r.data?.data?.records ?? []
    summary.value = r.data?.data?.summary ?? summary.value
  } catch { records.value = [] }
}
watch(() => props.visitId, load, { immediate: true })

function fmt(dt) { return dt ? new Date(dt).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }) : '—' }
function ml(n) { return `${Number(n || 0).toLocaleString('id-ID')} ml` }

async function submit() {
  if (!form.value.volume_ml || form.value.volume_ml < 1) { emit('notify', { msg: 'Isi volume (ml)', ok: false }); return }
  busy.value = true
  try {
    const payload = { ...form.value }
    if (!payload.recorded_at) delete payload.recorded_at
    await ranapApi.addFluidBalance(props.visitId, payload)
    emit('notify', { msg: 'Catatan cairan ditambahkan', ok: true })
    form.value = empty()
    await load()
  } catch (e) { emit('notify', { msg: e.response?.data?.message ?? 'Gagal menambah', ok: false }) }
  finally { busy.value = false }
}

async function removeRec(r) {
  busy.value = true
  try {
    await ranapApi.deleteFluidBalance(props.visitId, r.id)
    emit('notify', { msg: 'Catatan dihapus', ok: true })
    await load()
  } catch (e) { emit('notify', { msg: e.response?.data?.message ?? 'Gagal menghapus', ok: false }) }
  finally { busy.value = false }
}

defineExpose({ load })
</script>

<template>
  <div class="fb">
    <p class="fb-hint">Catat asupan (intake) &amp; keluaran (output) cairan. Saldo = intake − output.</p>

    <!-- Ringkasan 24 jam -->
    <div class="fb-summary">
      <div class="fb-card fb-in"><div class="fb-card-v">{{ ml(summary.intake_24h) }}</div><div class="fb-card-l">Intake 24 jam</div></div>
      <div class="fb-card fb-out"><div class="fb-card-v">{{ ml(summary.output_24h) }}</div><div class="fb-card-l">Output 24 jam</div></div>
      <div class="fb-card" :class="summary.balance_24h >= 0 ? 'fb-bal-pos' : 'fb-bal-neg'">
        <div class="fb-card-v">{{ summary.balance_24h >= 0 ? '+' : '' }}{{ ml(summary.balance_24h) }}</div>
        <div class="fb-card-l">Saldo 24 jam</div>
      </div>
    </div>

    <!-- Form tambah -->
    <div class="fb-form">
      <h4>+ Catat Cairan</h4>
      <div class="fb-grid">
        <label>Arah
          <select v-model="form.direction"><option value="INTAKE">Intake (masuk)</option><option value="OUTPUT">Output (keluar)</option></select>
        </label>
        <label>Kategori
          <input v-model="form.category" list="fbCats" placeholder="kategori" autocomplete="off" />
          <datalist id="fbCats"><option v-for="c in catOptions" :key="c" :value="c" /></datalist>
        </label>
        <label>Volume (ml)<input v-model.number="form.volume_ml" type="number" min="1" placeholder="mis. 500" /></label>
        <label>Jam (kosong = sekarang)<input v-model="form.recorded_at" type="datetime-local" /></label>
        <label class="fb-f-wide">Catatan<input v-model="form.notes" placeholder="opsional" /></label>
      </div>
      <div class="fb-actions">
        <button class="btn btn-sm btn-primary" :disabled="busy" @click="submit">{{ busy ? '…' : '+ Tambah' }}</button>
      </div>
    </div>

    <!-- Tabel catatan -->
    <h4>Riwayat ({{ records.length }})</h4>
    <table class="fb-tbl">
      <thead><tr><th>Jam</th><th>Arah</th><th>Kategori</th><th class="r">Volume</th><th>Oleh</th><th></th></tr></thead>
      <tbody>
        <tr v-for="r in records" :key="r.id">
          <td>{{ fmt(r.recorded_at) }}</td>
          <td><span class="pill" :class="r.direction === 'INTAKE' ? 'pill-in' : 'pill-out'">{{ r.direction === 'INTAKE' ? 'Intake' : 'Output' }}</span></td>
          <td>{{ r.category || '—' }}<template v-if="r.notes"> · <span class="muted-sm">{{ r.notes }}</span></template></td>
          <td class="r">{{ ml(r.volume_ml) }}</td>
          <td class="muted-sm">{{ r.by || '—' }}</td>
          <td><button class="fb-del" title="Hapus" @click="removeRec(r)">×</button></td>
        </tr>
        <tr v-if="!records.length"><td colspan="6" class="muted-sm">Belum ada catatan cairan.</td></tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.fb { font-size: 12.5px; }
.fb-hint { font-size: 11.5px; color: var(--tu); margin: .2rem 0 .8rem; }
.fb h4 { color: var(--gd); margin: .9rem 0 .45rem; font-size: .92rem; }
.muted-sm { color: var(--tu); font-size: 11.5px; }
.fb-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
.fb-card { border: 1px solid var(--gb); border-radius: 10px; padding: .6rem .8rem; }
.fb-card-v { font-size: 18px; font-weight: 700; font-family: var(--font-mono); }
.fb-card-l { font-size: 11px; color: var(--tm); margin-top: 2px; }
.fb-in { background: var(--ib); border-color: var(--ibd); } .fb-in .fb-card-v { color: var(--it); }
.fb-out { background: var(--wb); border-color: var(--wbd); } .fb-out .fb-card-v { color: var(--wt); }
.fb-bal-pos { background: var(--sb); border-color: var(--sbd); } .fb-bal-pos .fb-card-v { color: var(--st); }
.fb-bal-neg { background: var(--eb); border-color: var(--ebd); } .fb-bal-neg .fb-card-v { color: var(--et); }
.fb-form { border: 1px solid var(--gb); border-radius: 9px; padding: .7rem; margin-top: .7rem; background: var(--bs); }
.fb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .5rem; }
.fb-grid label { display: block; font-size: 11.5px; color: var(--tm); }
.fb-f-wide { grid-column: 1 / -1; }
.fb-grid input, .fb-grid select { width: 100%; box-sizing: border-box; margin-top: .15rem; padding: 6px 9px; border: 1px solid var(--gb); border-radius: 6px; font: inherit; font-size: 12.5px; background: var(--bc); color: var(--td); }
.fb-grid input:focus, .fb-grid select:focus { outline: none; border-color: var(--ga); }
.fb-actions { margin-top: .6rem; }
.fb-tbl { width: 100%; border-collapse: collapse; margin-top: .3rem; }
.fb-tbl th, .fb-tbl td { border-bottom: 1px solid var(--gb); padding: .4rem; text-align: left; color: var(--td); }
.fb-tbl th { background: var(--bs); color: var(--tm); font-weight: 600; text-transform: uppercase; font-size: 10.5px; letter-spacing: .03em; }
.fb-tbl .r { text-align: right; font-variant-numeric: tabular-nums; }
.pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.pill-in { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.pill-out { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.fb-del { background: none; border: none; color: var(--et); cursor: pointer; font-size: 1rem; }
/* Tombol kanonik (selaras DokterView) — scoped, jadi perlu didefinisikan di komponen ini. */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 16px; height: 38px; border-radius: 9px; font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; transition: all .15s; }
.btn:active:not(:disabled) { transform: translateY(1px) scale(.99); }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; border-radius: 8px; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
</style>
