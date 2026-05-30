<script setup>
/**
 * RequestObatModal — sisi UNIT Farmasi untuk meminta obat ke gudang Inventori Farmasi.
 *  - Buat Permintaan : pilih obat + qty → create + submit (status SUBMITTED).
 *  - Permintaan Saya : pantau status; DELIVERED → "Terima" (close) → stok unit bertambah.
 * Aksi gudang (approve/kirim/tolak) datang via toast realtime di FarmasiView.
 */
import { ref, computed, watch } from 'vue'
import { unitRequestApi } from '@/services/api'

const props = defineProps({
  open:        { type: Boolean, default: false },
  medications: { type: Array,   default: () => [] },
})
const emit = defineEmits(['close', 'changed'])

const tab = ref('create')   // 'create' | 'status' | 'list'

const STATUS = {
  DRAFT:     { label: 'Draft',     cls: 's-draft' },
  SUBMITTED: { label: 'Menunggu',  cls: 's-wait' },
  APPROVED:  { label: 'Disetujui', cls: 's-info' },
  DELIVERED: { label: 'Dikirim',   cls: 's-ship' },
  CLOSED:    { label: 'Diterima',  cls: 's-ok' },
  REJECTED:  { label: 'Ditolak',   cls: 's-err' },
}

// ─── Permintaan Saya ──────────────────────────────────────────────────────
const list        = ref([])
const listLoading = ref(false)
const busyId      = ref(null)

async function fetchList() {
  listLoading.value = true
  try {
    const res = await unitRequestApi.list({ station: 'FARMASI', per_page: 50 })
    const p = res.data?.data
    list.value = (p && Array.isArray(p.data)) ? p.data : (Array.isArray(p) ? p : [])
  } catch (e) {
    emit('changed', { type: 'w', message: e.response?.data?.message ?? 'Gagal memuat permintaan' })
  } finally {
    listLoading.value = false
  }
}

async function act(row, fn, msg, refreshStok = false) {
  busyId.value = row.id
  try {
    await fn()
    emit('changed', { type: 's', message: msg, refreshStok })
    await fetchList()
  } catch (e) {
    emit('changed', { type: 'w', message: e.response?.data?.message ?? 'Aksi gagal' })
  } finally {
    busyId.value = null
  }
}

const submitReq = (row) => act(row, () => unitRequestApi.submit(row.id), `Permintaan ${row.request_number} dikirim ke gudang`)
const terimaReq = (row) => act(row, () => unitRequestApi.close(row.id), `Barang ${row.request_number} diterima — stok diperbarui`, true)
function hapusReq(row) {
  if (!confirm(`Hapus permintaan ${row.request_number}?`)) return
  act(row, () => unitRequestApi.remove(row.id), 'Permintaan dihapus')
}

const statusList = computed(() => list.value.filter((r) => r.status === 'APPROVED' || r.status === 'DELIVERED'))

// ─── Buat Permintaan ──────────────────────────────────────────────────────
const rows   = ref([emptyRow()])
const saving = ref(false)
function emptyRow() { return { item_id: '', qty: 1 } }
function addRow() { rows.value.push(emptyRow()) }
function removeRow(i) { rows.value.splice(i, 1); if (!rows.value.length) addRow() }
function unitOf(id) { return props.medications.find((m) => m.id === id)?.unit ?? '' }

async function saveRequest() {
  const items = rows.value
    .filter((r) => r.item_id && Number(r.qty) > 0)
    .map((r) => ({ item_type: 'MEDICATION', item_id: r.item_id, qty_requested: Number(r.qty) }))
  if (!items.length) {
    emit('changed', { type: 'w', message: 'Pilih minimal 1 obat dengan qty > 0' }); return
  }
  saving.value = true
  try {
    const res = await unitRequestApi.create({ requesting_station: 'FARMASI', items })
    const created = res.data?.data
    if (created?.id) await unitRequestApi.submit(created.id)
    emit('changed', { type: 's', message: 'Permintaan dikirim ke gudang' })
    rows.value = [emptyRow()]
    tab.value = 'list'
    await fetchList()
  } catch (e) {
    emit('changed', { type: 'w', message: e.response?.data?.message ?? 'Gagal membuat permintaan' })
  } finally {
    saving.value = false
  }
}

function fmtDate(d) {
  return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}

function itemsSummary(row) {
  const items = Array.isArray(row?.items) ? row.items : []
  if (!items.length) return '—'
  return items.map((it) => {
    const qty  = Number(it.qty_requested ?? it.qty ?? 0)
    const name = it.item_name ?? '-'
    const unit = it.item_unit ? ` ${it.item_unit}` : ''
    return `${name} × ${qty}${unit}`
  }).join(', ')
}

watch(() => props.open, (o) => {
  if (o) { tab.value = 'create'; rows.value = [emptyRow()]; fetchList() }
})
</script>

<template>
  <div v-if="open" class="ro-overlay" @click.self="emit('close')">
    <div class="ro-modal">
      <div class="ro-head">
        <h3>Minta Barang ke Gudang</h3>
        <button class="ro-x" @click="emit('close')" aria-label="Tutup">
          <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="ro-tabs">
        <button :class="['ro-tab', tab === 'create' ? 'a' : '']" @click="tab = 'create'">Buat Permintaan</button>
        <button :class="['ro-tab', tab === 'status' ? 'a' : '']" @click="tab = 'status'">
          Status
          <span v-if="statusList.length" class="ro-tabcount">{{ statusList.length }}</span>
        </button>
        <button :class="['ro-tab', tab === 'list' ? 'a' : '']" @click="tab = 'list'">Histori</button>
      </div>

      <!-- STATUS (Disetujui / Dikirim — siap diterima) -->
      <div v-if="tab === 'status'" class="ro-body">
        <table class="ro-table">
          <thead>
            <tr>
              <th style="width:44px">No.</th>
              <th>No. Permintaan</th>
              <th>Barang</th>
              <th>Tanggal</th>
              <th class="c">Status</th>
              <th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="listLoading"><td colspan="6" class="ro-state">Memuat…</td></tr>
            <tr v-else-if="!statusList.length"><td colspan="6" class="ro-state">Belum ada permintaan yang disetujui.</td></tr>
            <tr v-for="(r, i) in statusList" :key="r.id">
              <td>{{ i + 1 }}</td>
              <td><strong>{{ r.request_number }}</strong></td>
              <td class="ro-items">{{ itemsSummary(r) }}</td>
              <td>{{ fmtDate(r.request_date) }}</td>
              <td class="c">
                <span class="ro-badge" :class="STATUS[r.status]?.cls">{{ STATUS[r.status]?.label ?? r.status }}</span>
              </td>
              <td class="c">
                <button v-if="r.status === 'DELIVERED'" class="ro-btn ok" :disabled="busyId === r.id" @click="terimaReq(r)">
                  {{ busyId === r.id ? 'Memproses…' : 'Terima' }}
                </button>
                <span v-else class="ro-muted">menunggu kirim</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- HISTORI (semua status, read-only) -->
      <div v-else-if="tab === 'list'" class="ro-body">
        <table class="ro-table">
          <thead>
            <tr>
              <th style="width:44px">No.</th>
              <th>No. Permintaan</th>
              <th>Barang</th>
              <th>Tanggal</th>
              <th class="c">Status</th>
              <th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="listLoading"><td colspan="6" class="ro-state">Memuat…</td></tr>
            <tr v-else-if="!list.length"><td colspan="6" class="ro-state">Belum ada permintaan. Buka tab "Buat Permintaan".</td></tr>
            <tr v-for="(r, i) in list" :key="r.id">
              <td>{{ i + 1 }}</td>
              <td><strong>{{ r.request_number }}</strong></td>
              <td class="ro-items">{{ itemsSummary(r) }}</td>
              <td>{{ fmtDate(r.request_date) }}</td>
              <td class="c">
                <span class="ro-badge" :class="STATUS[r.status]?.cls">{{ STATUS[r.status]?.label ?? r.status }}</span>
              </td>
              <td class="c">
                <div class="ro-acts">
                  <button v-if="r.status === 'DRAFT'" class="ro-btn" :disabled="busyId === r.id" @click="submitReq(r)">Submit</button>
                  <button v-if="r.status === 'DRAFT'" class="ro-btn danger" :disabled="busyId === r.id" @click="hapusReq(r)">Hapus</button>
                  <span v-if="['SUBMITTED','APPROVED','DELIVERED','CLOSED','REJECTED'].includes(r.status)" class="ro-muted">—</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- BUAT PERMINTAAN -->
      <div v-else class="ro-body">
        <table class="ro-table">
          <thead>
            <tr>
              <th style="width:44px">No.</th>
              <th>Obat</th>
              <th style="width:120px" class="c">Qty</th>
              <th style="width:60px">Unit</th>
              <th style="width:44px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="i">
              <td>{{ i + 1 }}</td>
              <td>
                <select v-model="r.item_id" class="ro-input">
                  <option value="">— pilih obat —</option>
                  <option v-for="m in medications" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
              </td>
              <td class="c"><input v-model.number="r.qty" type="number" min="1" class="ro-input ro-qty" /></td>
              <td class="ro-muted">{{ unitOf(r.item_id) || '—' }}</td>
              <td class="c">
                <button class="ro-btn danger" @click="removeRow(i)" aria-label="Hapus baris">×</button>
              </td>
            </tr>
          </tbody>
        </table>
        <button class="ro-addrow" @click="addRow">+ Tambah Baris</button>
      </div>

      <div class="ro-foot">
        <button class="ro-btn ghost" @click="emit('close')">Tutup</button>
        <button v-if="tab === 'create'" class="ro-btn primary" :disabled="saving" @click="saveRequest">
          {{ saving ? 'Mengirim…' : 'Kirim Permintaan' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ro-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
.ro-modal { background: var(--bc); border-radius: 12px; max-width: 720px; width: 100%; max-height: 88vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.ro-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--gb); }
.ro-head h3 { margin: 0; font-size: 16px; color: var(--td); font-family: 'Space Grotesk', serif; }
.ro-x { background: none; border: none; cursor: pointer; color: var(--tu); padding: 4px; }
.ro-x svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.ro-x:hover { color: var(--td); }

.ro-tabs { display: flex; gap: 4px; padding: 10px 20px 0; border-bottom: 1px solid var(--gb); }
.ro-tab { padding: 7px 14px; font-size: 12.5px; font-weight: 500; color: var(--tu); background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; font-family: 'Inter', sans-serif; }
.ro-tab:hover { color: var(--td); }
.ro-tab.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.ro-tabcount { display: inline-block; min-width: 18px; padding: 1px 6px; margin-left: 6px; font-size: 10.5px; font-weight: 700; line-height: 1.4; color: #fff; background: var(--ga); border-radius: 10px; text-align: center; }

.ro-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
.ro-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.ro-table th, .ro-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--gb); }
.ro-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.ro-table td.c, .ro-table th.c { text-align: center; }
.ro-state { text-align: center; padding: 22px; color: var(--tu); }
.ro-muted { color: var(--tu); }

.ro-input { width: 100%; height: 30px; font-size: 12px; border: 1.5px solid var(--gb); border-radius: 6px; padding: 0 8px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.ro-input:focus { border-color: var(--ga); background: #fff; }
.ro-qty { text-align: center; }
.ro-addrow { margin-top: 10px; padding: 6px 12px; font-size: 12px; font-weight: 600; color: var(--ga); background: var(--gl); border: 1.5px dashed var(--ga); border-radius: 7px; cursor: pointer; font-family: 'Inter', sans-serif; }

.ro-items { color: var(--tm); font-size: 12px; line-height: 1.4; max-width: 280px; }
.ro-acts { display: flex; gap: 5px; justify-content: center; }
.ro-btn { padding: 5px 11px; font-size: 11.5px; font-weight: 600; border-radius: 6px; border: 1.5px solid var(--gb); background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; }
.ro-btn:hover:not(:disabled) { border-color: var(--ga); color: var(--ga); }
.ro-btn:disabled { opacity: .5; cursor: not-allowed; }
.ro-btn.primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.ro-btn.primary:hover:not(:disabled) { background: var(--gm); color: #fff; }
.ro-btn.ok { background: var(--ga); color: #fff; border-color: var(--ga); }
.ro-btn.ok:hover:not(:disabled) { background: var(--gm); color: #fff; }
.ro-btn.danger { color: var(--et); border-color: var(--ebd); }
.ro-btn.danger:hover:not(:disabled) { background: var(--eb); color: var(--et); }
.ro-btn.ghost { background: transparent; }

.ro-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid var(--gb); }

.ro-badge { display: inline-block; padding: 2px 9px; border-radius: 4px; font-size: 10.5px; font-weight: 700; }
.s-draft { background: var(--bs); color: var(--tu); }
.s-wait  { background: #fef3c7; color: #92400e; }
.s-info  { background: #dbeafe; color: #1e40af; }
.s-ship  { background: #ede9fe; color: #5b21b6; }
.s-ok    { background: var(--sb); color: var(--st); }
.s-err   { background: var(--eb); color: var(--et); }
</style>
