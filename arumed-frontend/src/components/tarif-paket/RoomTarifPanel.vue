<script setup>
/**
 * RoomTarifPanel — pengelola Tarif Kamar Rawat Inap (per malam, per penjamin).
 *
 * Tampilan ringkas: 1 BARIS per PENJAMIN. Tarif tiap kelas (VIP/1/2/3) diisi
 * lewat MODAL "Atur Tarif" — satu kali simpan mengisi semua kelas untuk penjamin itu.
 *
 * Pola insurer-only: identitas tarif = (room_class × insurer_id), TANPA classification.
 * UMUM/BPJS/SOSIAL = insurer sistem. Backend roomTarifApi.upsert per (kelas,insurer);
 * modal memanggilnya beberapa kali (loop per kelas) — backend tidak berubah.
 */
import { ref, computed, onMounted } from 'vue'
import { roomTarifApi, roomApi, masterApi } from '@/services/api'

const tariffs = ref([])         // semua RoomTariff (flat, dari API)
const insurers = ref([])        // semua penjamin non-child
const roomClasses = ref([])     // distinct kelas dari master Room (mis. ['VIP','1','2','3'])
const loading = ref(false)
const toast = ref(null)

const TYPE_LABEL = { UMUM: 'Umum', BPJS: 'BPJS', ASURANSI: 'Asuransi', PERUSAHAAN: 'Perusahaan', SOSIAL: 'Sosial' }
const formatRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID')
// Ringkas: 800000 → "800rb", 1000000 → "1jt", 1500000 → "1,5jt"
function shortRp(n) {
  const v = Number(n || 0)
  if (v >= 1_000_000) { const j = v / 1_000_000; return (Number.isInteger(j) ? j : j.toFixed(1).replace('.', ',')) + 'jt' }
  if (v >= 1000) return Math.round(v / 1000) + 'rb'
  return String(v)
}

function notify(msg, ok = true) {
  toast.value = { msg, ok }
  setTimeout(() => (toast.value = null), 3000)
}

// Urutan kelas: VIP dulu, lalu angka menaik.
function sortClasses(arr) {
  return [...arr].sort((a, b) => {
    const na = a === 'VIP' ? -1 : parseInt(a, 10)
    const nb = b === 'VIP' ? -1 : parseInt(b, 10)
    return na - nb
  })
}

// Baris tabel = penjamin yang PUNYA tarif. { insurer, items:[{room_class, price, id}] }.
const rows = computed(() => {
  const byInsurer = new Map()
  for (const t of tariffs.value) {
    const key = t.insurer_id ?? '_null'
    if (!byInsurer.has(key)) byInsurer.set(key, { insurer: t.insurer, insurer_id: t.insurer_id, items: [] })
    byInsurer.get(key).items.push({ id: t.id, room_class: t.room_class, price: Number(t.price) })
  }
  const out = [...byInsurer.values()]
  for (const r of out) r.items = sortClasses(r.items.map((i) => i.room_class)).map((c) => r.items.find((i) => i.room_class === c))
  // Urutkan baris: sistem (UMUM/BPJS/SOSIAL) dulu, lalu nama.
  return out.sort((a, b) => {
    const sysA = a.insurer?.is_system ? 0 : 1
    const sysB = b.insurer?.is_system ? 0 : 1
    if (sysA !== sysB) return sysA - sysB
    return (a.insurer?.name ?? '').localeCompare(b.insurer?.name ?? '')
  })
})

// Penjamin yang BELUM punya tarif (untuk dropdown "Tambah Penjamin").
const insurersWithoutTarif = computed(() => {
  const have = new Set(tariffs.value.map((t) => t.insurer_id))
  return insurers.value.filter((i) => !have.has(i.id))
})

async function loadAll() {
  loading.value = true
  try {
    const [tRes, rRes] = await Promise.all([roomTarifApi.list(), roomApi.list()])
    tariffs.value = tRes.data?.data ?? []
    const roomList = rRes.data?.data ?? []
    const set = new Set(roomList.map((r) => r.kelas_rawat).filter(Boolean))
    roomClasses.value = sortClasses([...set])
  } catch {
    tariffs.value = []
  } finally {
    loading.value = false
  }
  // Insurer untuk dropdown tambah (sekali saja cukup, tapi refresh murah).
  try {
    const res = await masterApi.penjamin.list({ per_page: 200 })
    const payload = res.data?.data
    const list = Array.isArray(payload?.data) ? payload.data : (Array.isArray(payload) ? payload : [])
    insurers.value = list.filter((i) => !i.parent_id) // child TPA mewarisi tarif parent
  } catch {
    insurers.value = []
  }
}

// ── MODAL ATUR TARIF ────────────────────────────────────────────────────────
const modal = ref({ open: false, insurer: null, saving: false })
// rows kelas dalam modal: { room_class, price (string), existingId }
const modalRows = ref([])

function openModalForInsurer(insurer, existingItems = []) {
  modal.value = { open: true, insurer, saving: false }
  const byClass = new Map(existingItems.map((i) => [i.room_class, i]))
  // Kelas dari master Room; kalau tarif punya kelas yang tak ada di Room, ikutkan juga.
  const classes = sortClasses([...new Set([...roomClasses.value, ...existingItems.map((i) => i.room_class)])])
  modalRows.value = classes.map((c) => ({
    room_class: c,
    price: byClass.has(c) ? String(byClass.get(c).price) : '',
    existingId: byClass.get(c)?.id ?? null,
  }))
}

function openEdit(row) {
  openModalForInsurer(row.insurer, row.items)
}

const addInsurerId = ref('')
function openAddInsurer() {
  if (!addInsurerId.value) { notify('Pilih penjamin dulu', false); return }
  const ins = insurers.value.find((i) => i.id === addInsurerId.value)
  if (!ins) return
  openModalForInsurer(ins, [])
  addInsurerId.value = ''
}

async function saveModal() {
  if (!modal.value.insurer) return
  modal.value.saving = true
  try {
    for (const r of modalRows.value) {
      const filled = r.price !== '' && r.price !== null && Number(r.price) >= 0 && String(r.price).trim() !== ''
      if (filled) {
        await roomTarifApi.upsert({
          room_class: r.room_class,
          insurer_id: modal.value.insurer.id,
          price: Number(r.price),
        })
      } else if (r.existingId) {
        // Dikosongkan padahal sebelumnya ada → hapus tarif kelas itu.
        await roomTarifApi.destroy(r.existingId)
      }
    }
    notify(`Tarif ${modal.value.insurer.name} disimpan`)
    modal.value.open = false
    await loadAll()
  } catch (e) {
    notify(e.response?.data?.message ?? 'Gagal menyimpan tarif', false)
  } finally {
    modal.value.saving = false
  }
}

async function deleteRow(row) {
  if (!confirm(`Hapus SEMUA tarif kamar untuk ${row.insurer?.name ?? 'penjamin ini'}?`)) return
  try {
    for (const it of row.items) {
      if (it.id) await roomTarifApi.destroy(it.id)
    }
    notify('Tarif penjamin dihapus')
    await loadAll()
  } catch (e) {
    notify(e.response?.data?.message ?? 'Gagal menghapus', false)
  }
}

onMounted(loadAll)
</script>

<template>
  <div class="rt">
    <!-- TOOLBAR: tambah penjamin -->
    <div class="rt-toolbar">
      <select v-model="addInsurerId" class="rt-add-select">
        <option value="">— Tambah tarif untuk penjamin… —</option>
        <option v-for="ins in insurersWithoutTarif" :key="ins.id" :value="ins.id">
          {{ ins.name }}{{ ins.type && TYPE_LABEL[ins.type] && ins.name.toUpperCase() !== ins.type ? ` (${TYPE_LABEL[ins.type]})` : '' }}
        </option>
      </select>
      <button type="button" class="rt-btn-primary" :disabled="!addInsurerId" @click="openAddInsurer">+ Atur Tarif</button>
    </div>

    <p class="rt-hint">
      Satu baris per penjamin. Klik <strong>Atur Tarif</strong> untuk mengisi harga tiap kelas
      (VIP/1/2/3) sekaligus. Tarif ditagih per malam sesuai <strong>kelas HAK</strong> pasien;
      jika tarif penjamin tidak ada, sistem memakai tarif UMUM.
    </p>

    <!-- TABEL per penjamin -->
    <div class="rt-table-wrap">
      <table class="rt-table">
        <thead>
          <tr><th style="width:230px">Penjamin</th><th>Tarif per Kelas</th><th style="width:150px" class="c">Aksi</th></tr>
        </thead>
        <tbody>
          <tr v-if="loading"><td colspan="3" class="rt-state">Memuat…</td></tr>
          <tr v-else-if="!rows.length"><td colspan="3" class="rt-state">Belum ada tarif kamar. Pilih penjamin di atas lalu klik “Atur Tarif”.</td></tr>
          <tr v-for="row in rows" :key="row.insurer_id ?? '_null'">
            <td>
              <span class="rt-insurer-name">{{ row.insurer?.name ?? '—' }}</span>
              <span v-if="row.insurer?.type" class="rt-type-pill" :data-t="row.insurer.type">{{ TYPE_LABEL[row.insurer.type] ?? row.insurer.type }}</span>
            </td>
            <td>
              <div class="rt-chips">
                <span v-for="it in row.items" :key="it.room_class" class="rt-chip" :title="formatRp(it.price)">
                  <b>{{ it.room_class }}</b> {{ shortRp(it.price) }}
                </span>
                <span v-if="!row.items.length" class="rt-dim">—</span>
              </div>
            </td>
            <td class="c">
              <div class="rt-actions">
                <button type="button" class="rt-btn-sm" @click="openEdit(row)">Atur Tarif</button>
                <button type="button" class="rt-btn-sm rt-btn-del" @click="deleteRow(row)">Hapus</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- MODAL ATUR TARIF -->
    <Teleport to="body">
      <div v-if="modal.open" class="rt-modal-bg" @click.self="modal.open = false">
        <div class="rt-modal">
          <h3>
            Atur Tarif Kamar
            <span class="rt-modal-ins">{{ modal.insurer?.name }}<span v-if="modal.insurer?.type" class="rt-type-pill" :data-t="modal.insurer.type">{{ TYPE_LABEL[modal.insurer.type] ?? modal.insurer.type }}</span></span>
          </h3>
          <p class="rt-modal-sub">Isi harga per malam tiap kelas. Kosongkan kelas yang tidak ditanggung penjamin ini.</p>

          <table class="rt-modal-tbl">
            <thead><tr><th>Kelas</th><th>Harga / malam (Rp)</th></tr></thead>
            <tbody>
              <tr v-for="r in modalRows" :key="r.room_class">
                <td><span class="rt-kelas">Kelas {{ r.room_class }}</span></td>
                <td><input v-model="r.price" type="number" min="0" placeholder="kosongkan = tidak ditanggung" class="rt-modal-input" /></td>
              </tr>
              <tr v-if="!modalRows.length"><td colspan="2" class="rt-state">Belum ada kelas kamar. Tambah Room dulu di Master Data → Fasilitas &amp; Ruang.</td></tr>
            </tbody>
          </table>

          <div class="rt-modal-actions">
            <button type="button" class="rt-btn-ghost" @click="modal.open = false">Batal</button>
            <button type="button" class="rt-btn-primary" :disabled="modal.saving || !modalRows.length" @click="saveModal">
              {{ modal.saving ? 'Menyimpan…' : 'Simpan Tarif' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="toast" class="rt-toast" :class="{ err: !toast.ok }">{{ toast.msg }}</div>
    </Teleport>
  </div>
</template>

<style scoped>
.rt { display: flex; flex-direction: column; gap: 0.9rem; font-family: var(--font-sans); }

.rt-toolbar { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.rt-add-select { height: 34px; min-width: 280px; padding: 0 10px; border: 1px solid var(--gb); border-radius: 8px; font-size: 13px; background: var(--bc); color: var(--td); }
.rt-add-select:focus { outline: none; border-color: var(--ga); }

.rt-btn-primary { height: 34px; padding: 0 16px; border-radius: 8px; border: 1px solid var(--gd); background: var(--gd); color: #fff; font-size: 13px; font-weight: 500; cursor: pointer; }
.rt-btn-primary:hover:not(:disabled) { background: var(--gm); }
.rt-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.rt-btn-ghost { height: 34px; padding: 0 16px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 13px; cursor: pointer; }
.rt-btn-ghost:hover { border-color: var(--ga); color: var(--td); }

.rt-hint { font-size: 12px; color: var(--tm); line-height: 1.5; background: var(--gl); border: 1px solid var(--ga); border-radius: 8px; padding: 0.55rem 0.8rem; }
.rt-hint strong { color: var(--ld); }

.rt-table-wrap { border: 1px solid var(--gb); border-radius: 10px; overflow: hidden; }
.rt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rt-table th, .rt-table td { padding: 0.6rem 0.8rem; text-align: left; border-bottom: 1px solid var(--gb); color: var(--td); vertical-align: middle; }
.rt-table th { background: var(--bs); font-size: 11.5px; font-weight: 600; color: var(--tm); text-transform: uppercase; letter-spacing: .03em; }
.rt-table tbody tr:last-child td { border-bottom: none; }
.rt-table tbody tr:hover { background: var(--bs); }
.rt-table td.c, .rt-table th.c { text-align: center; }
.rt-state { text-align: center; padding: 22px; color: var(--tu); }

.rt-insurer-name { font-weight: 600; color: var(--td); }
.rt-type-pill { display: inline-block; margin-left: 7px; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 600; letter-spacing: .02em; }
.rt-type-pill[data-t="UMUM"]       { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.rt-type-pill[data-t="BPJS"]       { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.rt-type-pill[data-t="ASURANSI"]   { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.rt-type-pill[data-t="PERUSAHAAN"] { background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); }
.rt-type-pill[data-t="SOSIAL"]     { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.rt-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.rt-chip { display: inline-flex; align-items: baseline; gap: 4px; padding: 2px 9px; border-radius: 14px; font-size: 11.5px; background: var(--bs); border: 1px solid var(--gb); color: var(--tm); font-variant-numeric: tabular-nums; }
.rt-chip b { color: var(--gd); font-weight: 700; }
.rt-dim { color: var(--tu); }

.rt-actions { display: inline-flex; gap: 5px; }
.rt-btn-sm { padding: 4px 11px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12px; font-weight: 500; cursor: pointer; }
.rt-btn-sm:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.rt-btn-del { color: var(--et); }
.rt-btn-del:hover { border-color: var(--ebd); background: var(--eb); color: var(--et); }

/* MODAL */
.rt-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 9200; padding: 1rem; }
.rt-modal { background: var(--bc); border-radius: 12px; width: 440px; max-width: 94vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,.25); padding: 0; }
.rt-modal h3 { margin: 0; padding: 0.9rem 1.2rem; background: linear-gradient(135deg, var(--gm), var(--gd)); color: #fff; font-family: var(--font-display); font-size: 16px; display: flex; flex-direction: column; gap: 4px; }
.rt-modal-ins { font-family: var(--font-sans); font-size: 12.5px; font-weight: 400; opacity: .95; }
.rt-modal-ins .rt-type-pill { margin-left: 6px; }
.rt-modal-sub { font-size: 12px; color: var(--tm); margin: 0; padding: 0.7rem 1.2rem 0; }
.rt-modal-tbl { width: 100%; border-collapse: collapse; margin: 0.5rem 0; }
.rt-modal-tbl th, .rt-modal-tbl td { padding: 0.5rem 1.2rem; text-align: left; font-size: 13px; color: var(--td); }
.rt-modal-tbl th { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: var(--tu); font-weight: 600; }
.rt-modal-tbl td:first-child { width: 110px; }
.rt-kelas { display: inline-block; padding: 2px 9px; border-radius: 6px; font-size: 12px; font-weight: 600; background: var(--gl); color: var(--ld); }
.rt-modal-input { width: 100%; box-sizing: border-box; padding: 7px 10px; border: 1px solid var(--gb); border-radius: 6px; font-size: 13px; background: var(--bc); color: var(--td); }
.rt-modal-input:focus { outline: none; border-color: var(--ga); }
.rt-modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; padding: 0.8rem 1.2rem; border-top: 1px solid var(--gb); background: var(--bs); }

.rt-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: var(--st); color: #fff; padding: 0.7rem 1.2rem; border-radius: 9px; z-index: 9300; font-size: 13px; box-shadow: 0 8px 24px rgba(0,0,0,.18); }
.rt-toast.err { background: var(--et); }
</style>
