<script setup>
/**
 * StockOpnameView — opname MASSAL stok per lokasi.
 *
 * Tampilkan SELURUH item master (Obat/BHP/IOL) di satu lokasi dalam tabel.
 * Petugas mengisi "Stok Fisik" hasil hitung di gudang; selisih (fisik − sistem)
 * dihitung otomatis. Baris yang berubah disimpan SEKALIGUS via endpoint opname
 * existing (mode new_qty / set-total) — backend yang menerapkan delta FEFO /
 * batch OPNAME-{tgl}. Tidak ada perubahan backend; murni komposisi dari:
 *   - GET  /inventori-farmasi/stock/{type}?location=  (stok sistem per item)
 *   - POST /inventori-farmasi/stock/opname            (set total per item)
 *
 * Catatan: IOL tidak didukung opname di backend (POST opname hanya MEDICATION|BHP),
 * jadi tab IOL hanya read-only — kolom fisik dikunci.
 */
import { ref, computed, onMounted } from 'vue'
import { inventoriStockApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('inventori_farmasi.write'))

const TABS = [
  { key: 'MEDICATION', label: 'Obat', opnameable: true },
  { key: 'BHP',        label: 'BHP',  opnameable: true },
  { key: 'IOL',        label: 'IOL',  opnameable: false },
]
const LOCATIONS = [
  { key: 'INVENTORI', label: 'Gudang' },
  { key: 'FARMASI',   label: 'Farmasi' },
  { key: 'BEDAH',     label: 'Bedah' },
]

const activeTab = ref('MEDICATION')
const location = ref('INVENTORI')
const search = ref('')
const items = ref([])
const loading = ref(false)
const error = ref(null)
const saving = ref(false)
const onlyChanged = ref(false)

const toast = ref(null)
function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

const tabMeta = computed(() => TABS.find((t) => t.key === activeTab.value))
const opnameable = computed(() => !!tabMeta.value?.opnameable)
const locationLabel = computed(() => LOCATIONS.find((l) => l.key === location.value)?.label ?? location.value)

const formatNum = (v) => Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 2 })

// ─── Fetch ────────────────────────────────────────────────────────────────
function enrichRow(r) {
  const sys = Number(r.total_qty ?? 0)
  return {
    id: r.id,
    code: r.code,
    name: r.name,
    unit: r.unit,
    _sys: sys,             // stok sistem (snapshot saat load)
    _physical: sys,        // input fisik — default = sistem (belum dihitung)
    _saving: false,
    _saved: false,
  }
}

async function refresh() {
  loading.value = true
  error.value = null
  try {
    const params = { location: location.value }
    if (search.value.trim()) params.search = search.value.trim()
    const res = await inventoriStockApi.list(activeTab.value, params)
    items.value = Array.isArray(res.data?.data) ? res.data.data.map(enrichRow) : []
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat stok'
    items.value = []
  } finally {
    loading.value = false
  }
}

let searchTimer = null
function onSearchInput(v) {
  search.value = v
  clearTimeout(searchTimer)
  searchTimer = setTimeout(refresh, 300)
}

function switchTab(key) {
  if (activeTab.value === key) return
  if (hasChanges.value && !confirm('Ada selisih belum disimpan. Lanjut ganti tab?')) return
  activeTab.value = key
  search.value = ''
  refresh()
}

function switchLocation(key) {
  if (location.value === key) return
  if (hasChanges.value && !confirm('Ada selisih belum disimpan. Lanjut ganti lokasi?')) return
  location.value = key
  refresh()
}

// ─── Selisih ────────────────────────────────────────────────────────────────
function deltaOf(row) {
  return (Number(row._physical) || 0) - (Number(row._sys) || 0)
}
function isChanged(row) {
  return Math.abs(deltaOf(row)) > 0.0001
}
const changedRows = computed(() => items.value.filter(isChanged))
const hasChanges = computed(() => changedRows.value.length > 0)

const visibleItems = computed(() =>
  onlyChanged.value ? items.value.filter(isChanged) : items.value
)

function resetRow(row) {
  row._physical = row._sys
  row._saved = false
}

// ─── Simpan ──────────────────────────────────────────────────────────────────
async function saveRow(row) {
  if (!opnameable.value || !canWrite.value) return
  if (!isChanged(row)) return
  row._saving = true
  try {
    const res = await inventoriStockApi.opname({
      item_type: activeTab.value,
      item_id: row.id,
      location: location.value,
      new_qty: Number(row._physical) || 0,
      reason: `Opname massal — ${locationLabel.value}`,
    })
    row._sys = Number(res.data?.data?.after ?? row._physical)
    row._physical = row._sys
    row._saved = true
    showToast('s', `${row.name}: opname tersimpan`)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan')
  } finally {
    row._saving = false
  }
}

async function saveAll() {
  if (!opnameable.value || !canWrite.value || saving.value) return
  const targets = changedRows.value
  if (!targets.length) {
    showToast('w', 'Tidak ada selisih untuk disimpan')
    return
  }
  if (!confirm(`Simpan ${targets.length} penyesuaian stok di lokasi ${locationLabel.value}?`)) return

  saving.value = true
  let ok = 0
  const fails = []
  // Serial agar lock per item tidak saling tabrakan & pesan error jelas per item.
  for (const row of targets) {
    try {
      const res = await inventoriStockApi.opname({
        item_type: activeTab.value,
        item_id: row.id,
        location: location.value,
        new_qty: Number(row._physical) || 0,
        reason: `Opname massal — ${locationLabel.value}`,
      })
      row._sys = Number(res.data?.data?.after ?? row._physical)
      row._physical = row._sys
      row._saved = true
      ok++
    } catch (e) {
      fails.push(`${row.name}: ${e.response?.data?.message ?? 'gagal'}`)
    }
  }
  saving.value = false

  if (fails.length) {
    showToast('e', `${ok} tersimpan, ${fails.length} gagal. ${fails[0]}`)
  } else {
    showToast('s', `${ok} penyesuaian stok tersimpan`)
  }
}

onMounted(refresh)
</script>

<template>
  <div class="so-wrap">
    <header class="so-head">
      <div>
        <h2>Stock Opname</h2>
        <p>
          Hitung fisik stok di lapangan lalu isi kolom <strong>Stok Fisik</strong>.
          Sistem menyimpan selisih: positif → batch <code>OPNAME-{tgl}</code>,
          negatif → kurangi batch existing (FEFO).
        </p>
      </div>
    </header>

    <!-- Tabs -->
    <div class="so-tabs">
      <button
        v-for="t in TABS" :key="t.key"
        class="so-tab" :class="{ active: activeTab === t.key }"
        @click="switchTab(t.key)"
      >{{ t.label }}</button>
    </div>

    <!-- Toolbar: lokasi + search + only-changed -->
    <div class="so-toolbar">
      <div class="so-loc">
        <span class="so-loc-lbl">Lokasi</span>
        <div class="so-loc-seg">
          <button
            v-for="l in LOCATIONS" :key="l.key"
            class="so-loc-btn" :class="{ active: location === l.key }"
            @click="switchLocation(l.key)"
          >{{ l.label }}</button>
        </div>
      </div>

      <div class="so-search">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          type="text"
          :value="search"
          placeholder="Cari nama / kode…"
          @input="(e) => onSearchInput(e.target.value)"
        />
      </div>

      <label class="so-filter">
        <input type="checkbox" v-model="onlyChanged" />
        <span>Hanya yang berubah ({{ changedRows.length }})</span>
      </label>
    </div>

    <div v-if="!opnameable" class="so-readonly">
      Opname IOL belum didukung — tab ini hanya menampilkan stok (read-only).
    </div>

    <!-- Table -->
    <div class="so-table-wrap">
      <div v-if="loading" class="so-state">Memuat…</div>
      <div v-else-if="error" class="so-state so-err">{{ error }}</div>
      <div v-else-if="!visibleItems.length" class="so-state">
        {{ onlyChanged ? 'Tidak ada item yang berubah.' : (search ? 'Tidak ada hasil.' : 'Belum ada item.') }}
      </div>

      <table v-else class="so-table">
        <thead>
          <tr>
            <th class="r col-no">No</th>
            <th class="col-name">Item</th>
            <th class="r col-num">Stok Sistem</th>
            <th class="r col-num">Stok Fisik</th>
            <th class="r col-delta">Selisih</th>
            <th class="c col-status">Status</th>
            <th class="r col-aksi" v-if="opnameable && canWrite">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(row, i) in visibleItems" :key="row.id"
            :class="{ changed: isChanged(row), saved: row._saved }"
          >
            <td class="r col-no">{{ i + 1 }}</td>
            <td class="col-name">
              <div class="so-name-cell">
                <strong>{{ row.name }}</strong>
                <span v-if="row.code && row.code !== '-'" class="so-name-sub">{{ row.code }}</span>
              </div>
            </td>
            <td class="r col-num">
              <span class="so-sys">{{ formatNum(row._sys) }}</span>
              <small class="so-unit">{{ row.unit }}</small>
            </td>
            <td class="r col-num">
              <input
                v-if="opnameable && canWrite"
                type="number" min="0" step="any"
                class="so-input-num"
                v-model.number="row._physical"
              />
              <span v-else class="so-dim">{{ formatNum(row._sys) }}</span>
            </td>
            <td class="r col-delta">
              <span
                v-if="isChanged(row)"
                class="so-delta" :class="deltaOf(row) > 0 ? 'pos' : 'neg'"
              >{{ deltaOf(row) > 0 ? '+' : '' }}{{ formatNum(deltaOf(row)) }}</span>
              <span v-else class="so-dim">0</span>
            </td>
            <td class="c col-status">
              <span v-if="row._saving" class="so-badge so-badge-info">…</span>
              <span v-else-if="row._saved" class="so-badge so-badge-ok">Tersimpan</span>
              <span v-else-if="isChanged(row)" class="so-badge so-badge-warn">Perubahan</span>
              <span v-else class="so-dim">—</span>
            </td>
            <td class="r col-aksi" v-if="opnameable && canWrite">
              <button
                class="so-btn-primary so-btn-xs"
                :disabled="!isChanged(row) || row._saving"
                @click="saveRow(row)"
              >{{ row._saving ? '…' : 'Simpan' }}</button>
              <button
                v-if="isChanged(row)"
                class="so-btn-ghost so-btn-xs"
                :disabled="row._saving"
                @click="resetRow(row)"
                title="Kembalikan ke stok sistem"
              >↺</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Sticky save bar -->
    <div v-if="opnameable && canWrite && hasChanges" class="so-savebar">
      <span class="so-savebar-info">
        <strong>{{ changedRows.length }}</strong> item berubah di
        <strong>{{ locationLabel }}</strong>
      </span>
      <button class="so-btn-primary" :disabled="saving" @click="saveAll">
        {{ saving ? 'Menyimpan…' : `Simpan semua (${changedRows.length})` }}
      </button>
    </div>

    <Teleport to="body">
      <div v-if="toast" class="so-toast-wrap">
        <div class="so-toast" :class="`so-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.so-wrap { display: flex; flex-direction: column; gap: 1.25rem; padding: 0.25rem 0.25rem 4.5rem; max-width: 1320px; margin: 0 auto; width: 100%; }

.so-head h2 { font-family: 'Space Grotesk', serif; font-size: 21px; color: var(--td); margin: 0; }
.so-head p { font-size: 13px; color: var(--tm); margin: 6px 0 0; max-width: 720px; line-height: 1.5; }
.so-head code { background: var(--bs); border: 1px solid var(--gb); border-radius: 4px; padding: 0 4px; font-size: 11px; color: var(--td); }

.so-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); }
.so-tab { padding: 10px 20px; border: none; background: transparent; color: var(--tm); font-size: 13px; font-weight: 500; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.so-tab:hover { color: var(--td); }
.so-tab.active { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }

.so-toolbar { display: flex; align-items: center; gap: 1rem 1.25rem; flex-wrap: wrap; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.85rem 1.1rem; }
.so-loc { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.so-loc-lbl { font-size: 11px; font-weight: 600; color: var(--tm); text-transform: uppercase; letter-spacing: 0.04em; }
.so-loc-seg { display: flex; gap: 5px; flex-wrap: wrap; }
.so-loc-btn { padding: 6px 13px; font-size: 12px; font-weight: 600; color: var(--tm); background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; cursor: pointer; }
.so-loc-btn:hover { background: var(--gl); color: var(--td); }
.so-loc-btn.active { background: var(--ga); color: #fff; border-color: var(--ga); }

.so-search { position: relative; flex: 1 1 240px; min-width: 200px; max-width: 380px; }
.so-search svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; fill: none; stroke: var(--tu); stroke-width: 2; }
.so-search input { width: 100%; height: 36px; padding: 0 10px 0 34px; border-radius: 9px; border: 1px solid var(--gb); font-size: 13px; }
.so-search input:focus { outline: none; border-color: var(--ga); }

.so-filter { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--tm); cursor: pointer; user-select: none; white-space: nowrap; }

.so-readonly { font-size: 12.5px; color: var(--wt); background: var(--wb); border: 1px solid var(--wbd); border-radius: 9px; padding: 10px 14px; }

/* overflow-x:auto = jaring pengaman: bila kolom sempit (jendela kecil), tabel bisa
   digeser, BUKAN terpotong seperti saat overflow:hidden. border-radius dipindah ke
   wrapper luar agar sudut tetap rapi walau isinya scroll. */
.so-table-wrap { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow-x: auto; }
.so-state { padding: 2.5rem 2rem; text-align: center; font-size: 13px; color: var(--tm); }
.so-err { color: var(--et); }

/* min-width = jumlah lebar kolom: 48+220+150+150+100+110+120 ≈ 900px. Di bawah ini
   wrapper-nya scroll, kolom tak diremas/terpotong. */
.so-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 13px; }
.so-table thead { background: var(--bs); }
.so-table th { padding: 11px 14px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--tu); border-bottom: 1px solid var(--gb); }
.so-table th.r, .so-table td.r { text-align: right; }
.so-table th.c, .so-table td.c { text-align: center; }
.so-table td { padding: 10px 14px; border-bottom: 1px solid var(--gb); vertical-align: middle; }
.so-table tr:last-child td { border-bottom: none; }
.so-table tr.changed td { background: rgba(250, 204, 21, 0.07); }
.so-table tr.saved td { background: rgba(34, 197, 94, 0.06); }

.col-no { width: 48px; color: var(--tu); font-variant-numeric: tabular-nums; }
.col-name { min-width: 220px; }
.col-num { width: 150px; }
.col-delta { width: 100px; }
.col-status { width: 110px; }
.col-aksi { width: 120px; }

.so-name-cell { display: flex; flex-direction: column; gap: 1px; }
.so-name-cell strong { font-weight: 500; color: var(--td); }
.so-name-sub { font-size: 11px; color: var(--tu); }

.so-sys { font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; }
.so-unit { font-size: 10.5px; color: var(--tu); margin-left: 4px; }
.so-input-num { width: 128px; height: 34px; padding: 0 10px; border-radius: 7px; border: 1px solid var(--gb); font-size: 13px; text-align: right; font-variant-numeric: tabular-nums; }
.so-input-num:focus { outline: none; border-color: var(--ga); }
.so-dim { color: var(--tu); }

.so-delta { font-weight: 700; font-variant-numeric: tabular-nums; }
.so-delta.pos { color: #15803d; }
.so-delta.neg { color: #b91c1c; }

.so-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.03em; }
.so-badge-ok   { background: var(--sb); color: var(--st); }
.so-badge-warn { background: var(--wb); color: var(--wt); }
.so-badge-info { background: var(--ib); color: var(--it); }

.so-btn-primary { padding: 6px 14px; border-radius: 7px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 12.5px; font-weight: 500; cursor: pointer; transition: background 0.15s; }
.so-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.so-btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.so-btn-ghost { padding: 5px 9px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; margin-left: 4px; }
.so-btn-ghost:hover:not(:disabled) { background: var(--bs); color: var(--td); }
.so-btn-xs { padding: 4px 10px; font-size: 11.5px; }

.so-savebar { position: sticky; bottom: 0.75rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 0.9rem 1.25rem; background: var(--bc); border: 1px solid var(--ga); border-radius: 12px; box-shadow: 0 -4px 18px rgba(15,23,42,0.08); }
.so-savebar-info { font-size: 13px; color: var(--td); }

.so-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
.so-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; }
.so-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.so-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.so-toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.so-toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }

/* ─── Responsif: di bawah 900px, stasiun sempit → toolbar menumpuk 1 kolom,
   kontrol melebar penuh, dan savebar menyusun info di atas tombol. ───────── */
@media (max-width: 900px) {
  .so-wrap { gap: 1rem; padding-bottom: 4rem; }
  .so-toolbar { flex-direction: column; align-items: stretch; gap: 0.85rem; }
  .so-loc { justify-content: space-between; }
  .so-search { max-width: none; }
  .so-savebar { flex-direction: column; align-items: stretch; }
  .so-savebar-info { text-align: center; }
  .so-savebar .so-btn-primary { width: 100%; }
  .so-toast-wrap { left: 1rem; right: 1rem; }
  .so-toast { min-width: 0; }
}
</style>
