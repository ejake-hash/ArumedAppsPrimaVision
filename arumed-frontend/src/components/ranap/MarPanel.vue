<script setup>
import { ref, watch, computed } from 'vue'
import { ranapApi } from '@/services/api'

// eMAR: pencatatan pemberian obat ke pasien (PKPO 4.3).
const props = defineProps({ visitId: { type: String, required: true } })
const emit = defineEmits(['notify'])

const orders = ref([])           // order obat aktif (dispensed)
const administrations = ref([])  // riwayat pemberian
const busy = ref(false)

const empty = () => ({ prescription_item_id: null, medication_id: null, medication_name: '', dose: '', route: '', status: 'GIVEN', reason: '', notes: '', administered_at: '' })
const form = ref(empty())

const ROUTE_OPTS = ['Oral', 'Tetes Mata (OD)', 'Tetes Mata (OS)', 'Tetes Mata (ODS)', 'Salep Mata', 'IV', 'IM', 'SC', 'Topikal', 'Sublingual', 'Rektal', 'Inhalasi']
const STATUS_LABEL = { GIVEN: 'Diberikan', HELD: 'Ditunda', SKIPPED: 'Dilewati' }
const STATUS_PILL = { GIVEN: 'pill-given', HELD: 'pill-held', SKIPPED: 'pill-skipped' }

async function load() {
  if (!props.visitId) return
  try {
    const r = await ranapApi.marBoard(props.visitId)
    orders.value = r.data?.data?.orders ?? []
    administrations.value = r.data?.data?.administrations ?? []
  } catch { orders.value = []; administrations.value = [] }
}
watch(() => props.visitId, load, { immediate: true })

// Jumlah pemberian per order (untuk badge "sudah N×").
const givenCountByItem = computed(() => {
  const m = {}
  for (const a of administrations.value) {
    if (a.prescription_item_id && a.status === 'GIVEN') m[a.prescription_item_id] = (m[a.prescription_item_id] || 0) + 1
  }
  return m
})

function pickOrder(o) {
  form.value = { ...empty(), prescription_item_id: o.prescription_item_id, medication_id: o.medication_id, medication_name: o.name, dose: o.dose || '', route: o.route || '' }
}
function resetForm() { form.value = empty() }

function fmt(dt) { return dt ? new Date(dt).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }) : '—' }

async function submit() {
  if (!form.value.medication_name && !form.value.medication_id) { emit('notify', { msg: 'Pilih obat atau isi nama obat', ok: false }); return }
  if (form.value.status !== 'GIVEN' && !form.value.reason.trim()) { emit('notify', { msg: 'Isi alasan untuk status Ditunda/Dilewati', ok: false }); return }
  busy.value = true
  try {
    const payload = { ...form.value }
    if (!payload.administered_at) delete payload.administered_at
    await ranapApi.recordAdministration(props.visitId, payload)
    emit('notify', { msg: 'Pemberian obat dicatat', ok: true })
    resetForm()
    await load()
  } catch (e) { emit('notify', { msg: e.response?.data?.message ?? 'Gagal mencatat pemberian', ok: false }) }
  finally { busy.value = false }
}

async function removeAdm(a) {
  busy.value = true
  try {
    await ranapApi.deleteAdministration(props.visitId, a.id)
    emit('notify', { msg: 'Catatan pemberian dihapus', ok: true })
    await load()
  } catch (e) { emit('notify', { msg: e.response?.data?.message ?? 'Gagal menghapus', ok: false }) }
  finally { busy.value = false }
}

defineExpose({ load })
</script>

<template>
  <div class="mar">
    <p class="mar-hint">Catat pemberian obat ke pasien (jam &amp; perawat tercatat otomatis). Sumber order = obat yang sudah <strong>diserahkan Farmasi</strong> ke ruangan.</p>

    <!-- Order obat aktif -->
    <h4>Obat Aktif (diserahkan Farmasi)</h4>
    <div v-if="orders.length" class="mar-orders">
      <button v-for="o in orders" :key="o.prescription_item_id" type="button" class="mar-order" @click="pickOrder(o)">
        <div class="mar-order-name">{{ o.name }}<span v-if="givenCountByItem[o.prescription_item_id]" class="mar-given">{{ givenCountByItem[o.prescription_item_id] }}× diberikan</span></div>
        <div class="mar-order-sub">{{ [o.dose, o.frequency, o.route].filter(Boolean).join(' · ') || 'aturan pakai belum diisi' }}</div>
        <span class="mar-order-add">+ Catat</span>
      </button>
    </div>
    <p v-else class="muted-sm">Belum ada obat diserahkan Farmasi. Bisa juga catat pemberian manual di bawah.</p>

    <!-- Form catat pemberian -->
    <div class="mar-form">
      <h4>{{ form.medication_name ? `Catat: ${form.medication_name}` : 'Catat Pemberian (manual)' }}</h4>
      <div class="mar-grid">
        <label class="mar-f-name">Obat<input v-model="form.medication_name" placeholder="nama obat" :readonly="!!form.prescription_item_id" /></label>
        <label>Dosis<input v-model="form.dose" placeholder="mis. 1 tab" /></label>
        <label>Rute
          <select v-model="form.route"><option value="">—</option><option v-for="r in ROUTE_OPTS" :key="r" :value="r">{{ r }}</option></select>
        </label>
        <label>Status
          <select v-model="form.status"><option value="GIVEN">Diberikan</option><option value="HELD">Ditunda</option><option value="SKIPPED">Dilewati</option></select>
        </label>
        <label>Jam (kosong = sekarang)<input v-model="form.administered_at" type="datetime-local" /></label>
        <label v-if="form.status !== 'GIVEN'" class="mar-f-name">Alasan<input v-model="form.reason" placeholder="alasan ditunda/dilewati" /></label>
        <label class="mar-f-name">Catatan<input v-model="form.notes" placeholder="opsional" /></label>
      </div>
      <div class="mar-actions">
        <button class="btn btn-sm btn-primary" :disabled="busy" @click="submit">{{ busy ? '…' : 'Catat Pemberian' }}</button>
        <button v-if="form.prescription_item_id || form.medication_name" class="btn btn-sm btn-secondary" @click="resetForm">Reset</button>
      </div>
    </div>

    <!-- Riwayat pemberian -->
    <h4>Riwayat Pemberian</h4>
    <table class="mar-tbl">
      <thead><tr><th>Jam</th><th>Obat</th><th>Dosis/Rute</th><th>Status</th><th>Oleh</th><th></th></tr></thead>
      <tbody>
        <tr v-for="a in administrations" :key="a.id">
          <td>{{ fmt(a.administered_at) }}</td>
          <td>{{ a.medication_name || '—' }}</td>
          <td class="muted-sm">{{ [a.dose, a.route].filter(Boolean).join(' · ') || '—' }}<template v-if="a.reason"> · {{ a.reason }}</template></td>
          <td><span class="pill" :class="STATUS_PILL[a.status]">{{ STATUS_LABEL[a.status] || a.status }}</span></td>
          <td class="muted-sm">{{ a.by || '—' }}</td>
          <td><button class="mar-del" title="Hapus" @click="removeAdm(a)">×</button></td>
        </tr>
        <tr v-if="!administrations.length"><td colspan="6" class="muted-sm">Belum ada pemberian dicatat.</td></tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.mar { font-size: 12.5px; }
.mar-hint { font-size: 11.5px; color: var(--tu); margin: .2rem 0 .8rem; }
.mar h4 { color: var(--gd); margin: .9rem 0 .45rem; font-size: .92rem; }
.muted-sm { color: var(--tu); font-size: 11.5px; }
.mar-orders { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; }
.mar-order { position: relative; text-align: left; background: var(--bc); border: 1px solid var(--gb); border-left: 3px solid var(--ga); border-radius: 9px; padding: .55rem .7rem; cursor: pointer; font-family: inherit; transition: border-color .14s, background .14s, box-shadow .14s; }
.mar-order:hover { background: var(--gl); box-shadow: 0 2px 8px rgba(0,0,0,.06); }
.mar-order-name { font-weight: 600; color: var(--td); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.mar-given { font-size: 10px; font-weight: 700; color: var(--st); background: var(--sb); border: 1px solid var(--sbd); border-radius: 999px; padding: 0 6px; }
.mar-order-sub { font-size: 11px; color: var(--tm); margin-top: 2px; }
.mar-order-add { font-size: 11px; color: var(--ga); font-weight: 700; }
.mar-form { border: 1px solid var(--gb); border-radius: 9px; padding: .7rem; margin-top: .6rem; background: var(--bs); }
.mar-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .5rem; }
.mar-grid label { display: block; font-size: 11.5px; color: var(--tm); }
.mar-f-name { grid-column: 1 / -1; }
.mar-grid input, .mar-grid select { width: 100%; box-sizing: border-box; margin-top: .15rem; padding: 6px 9px; border: 1px solid var(--gb); border-radius: 6px; font: inherit; font-size: 12.5px; background: var(--bc); color: var(--td); }
.mar-grid input:focus, .mar-grid select:focus { outline: none; border-color: var(--ga); }
.mar-actions { display: flex; gap: 6px; margin-top: .6rem; }
.mar-tbl { width: 100%; border-collapse: collapse; margin-top: .3rem; }
.mar-tbl th, .mar-tbl td { border-bottom: 1px solid var(--gb); padding: .4rem; text-align: left; color: var(--td); }
.mar-tbl th { background: var(--bs); color: var(--tm); font-weight: 600; text-transform: uppercase; font-size: 10.5px; letter-spacing: .03em; }
.pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.pill-given { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.pill-held { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.pill-skipped { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }
.mar-del { background: none; border: none; color: var(--et); cursor: pointer; font-size: 1rem; }
/* Tombol kanonik (selaras DokterView) — scoped, jadi perlu didefinisikan di komponen ini. */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 16px; height: 38px; border-radius: 9px; font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; transition: all .15s; }
.btn:active:not(:disabled) { transform: translateY(1px) scale(.99); }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; border-radius: 8px; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover:not(:disabled) { border-color: var(--ga); color: var(--td); background: var(--gl); }
</style>
