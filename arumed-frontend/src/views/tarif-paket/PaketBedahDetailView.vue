<script setup>
/**
 * PaketBedahDetailView — sub-page detail satu paket bedah.
 *
 * Route: /tarif-paket/paket-bedah/:id
 *
 * Layout: header info paket + 2 panel berdampingan
 *   - Kiri  : Komposisi Items (tabel dengan add/remove/edit qty)
 *   - Kanan : Tarif Jual per Penjamin (auto-diskon ditampilkan)
 *
 * Cara kerja diskon (backend): selisih total_base_price (Σ qty × default_price)
 * dengan sell_price per penjamin. Ditampilkan otomatis di kolom diskon.
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTarifPaketStore } from '@/stores/tarifPaketStore'
import { masterApi } from '@/services/api'
import MasterFormModal from '@/components/master-data/MasterFormModal.vue'

const route = useRoute()
const router = useRouter()
const store = useTarifPaketStore()

const paketId = computed(() => route.params.id)
const paket = computed(() => store.paketDetail)

// ─── Dropdown source ─────────────────────────────────────────────────────
const procedures = ref([])
const medications = ref([])
const bhpItems = ref([])
const iolItems = ref([])
const insurers = ref([])

async function loadDropdowns() {
  try {
    const [p, m, b, i, ins] = await Promise.all([
      masterApi.tindakan.list({ per_page: 500, active: 1 }),
      masterApi.obat.list({ per_page: 500, active: 1 }),
      masterApi.bhp.list({ per_page: 500, active: 1 }),
      masterApi.iol.list({ per_page: 500, available_only: 1 }),
      masterApi.penjamin(),
    ])
    procedures.value  = p.data?.data?.data ?? p.data?.data ?? []
    medications.value = m.data?.data?.data ?? m.data?.data ?? []
    bhpItems.value    = b.data?.data?.data ?? b.data?.data ?? []
    iolItems.value    = i.data?.data?.data ?? i.data?.data ?? []
    insurers.value    = ins.data?.data?.data ?? ins.data?.data ?? []
  } catch (e) {
    showToast('e', 'Gagal memuat dropdown')
  }
}

// ─── Modal Item ──────────────────────────────────────────────────────────
const itemModal = ref({
  open: false,
  mode: 'create',
  payload: { item_type: 'PROCEDURE', item_id: '', quantity: 1, default_price: '', notes: '' },
  errors: null, submitting: false, editingId: null,
})

function emptyItemForm() {
  return { item_type: 'PROCEDURE', item_id: '', quantity: 1, default_price: '', notes: '' }
}

function openAddItem() {
  itemModal.value = {
    open: true, mode: 'create',
    payload: emptyItemForm(),
    errors: null, submitting: false, editingId: null,
  }
}

function openEditItem(item) {
  itemModal.value = {
    open: true, mode: 'edit',
    payload: {
      item_type: item.item_type,
      item_id: item.item_id,
      quantity: item.quantity,
      default_price: item.default_price,
      notes: item.notes ?? '',
    },
    errors: null, submitting: false, editingId: item.id,
  }
}

const itemOptionsForType = computed(() => {
  switch (itemModal.value.payload.item_type) {
    case 'PROCEDURE':  return procedures.value.map((p) => ({ value: p.id, label: p.name, suggestedPrice: p.base_price }))
    case 'MEDICATION': return medications.value.map((m) => ({ value: m.id, label: m.name, suggestedPrice: m.price }))
    case 'BHP':        return bhpItems.value.map((b) => ({ value: b.id, label: b.name,    suggestedPrice: b.price }))
    case 'IOL':        return iolItems.value.map((io) => ({ value: io.id, label: `${io.brand} ${io.model} (${io.power}D)${io.serial_number ? ' SN:' + io.serial_number : ''}`, suggestedPrice: io.price }))
    default: return []
  }
})

// Saat user pilih item, auto-suggest default_price kalau masih kosong
watch(() => itemModal.value.payload.item_id, (newId) => {
  if (!newId || itemModal.value.mode !== 'create') return
  const opt = itemOptionsForType.value.find((o) => o.value === newId)
  if (opt && (itemModal.value.payload.default_price === '' || itemModal.value.payload.default_price === 0)) {
    itemModal.value.payload.default_price = opt.suggestedPrice ?? 0
  }
})

watch(() => itemModal.value.payload.item_type, () => {
  // Reset item_id saat type berubah
  itemModal.value.payload.item_id = ''
  if (itemModal.value.mode === 'create') itemModal.value.payload.default_price = ''
})

const itemFields = computed(() => {
  if (itemModal.value.mode === 'edit') {
    // Edit: tipe & item terkunci (unique constraint)
    return [
      { key: 'item_type',     label: 'Tipe',             type: 'select',   disabled: true, cols: 1, options: ITEM_TYPE_OPTIONS },
      { key: 'item_id',       label: 'Item',             type: 'select',   disabled: true, cols: 1, options: itemOptionsForType.value },
      { key: 'quantity',      label: 'Jumlah',           type: 'number',   required: true, min: 1, cols: 1 },
      { key: 'default_price', label: 'Harga Snapshot (Rp)', type: 'number', required: true, min: 0, cols: 1, hint: 'Bisa override harga master saat ini' },
      { key: 'notes',         label: 'Catatan',          type: 'text',     cols: 2 },
    ]
  }
  return [
    { key: 'item_type',     label: 'Tipe',             type: 'select',   required: true, cols: 1, options: ITEM_TYPE_OPTIONS },
    { key: 'item_id',       label: 'Item',             type: 'select',   required: true, cols: 1, options: itemOptionsForType.value },
    { key: 'quantity',      label: 'Jumlah',           type: 'number',   required: true, min: 1, cols: 1 },
    { key: 'default_price', label: 'Harga Snapshot (Rp)', type: 'number', required: true, min: 0, cols: 1, hint: 'Auto-isi dari master saat pilih item' },
    { key: 'notes',         label: 'Catatan',          type: 'text',     cols: 2 },
  ]
})

const ITEM_TYPE_OPTIONS = [
  { value: 'PROCEDURE',  label: 'Tindakan' },
  { value: 'MEDICATION', label: 'Obat' },
  { value: 'BHP',        label: 'BHP' },
  { value: 'IOL',        label: 'IOL' },
]

async function onSubmitItem(payload) {
  itemModal.value.submitting = true
  itemModal.value.errors = null
  try {
    if (itemModal.value.mode === 'create') {
      await store.addItem(paketId.value, payload)
      showToast('s', 'Item ditambahkan')
    } else {
      await store.updateItem(paketId.value, itemModal.value.editingId, {
        quantity: payload.quantity,
        default_price: payload.default_price,
        notes: payload.notes,
      })
      showToast('s', 'Item diperbarui')
    }
    itemModal.value.open = false
  } catch (e) {
    if (e.response?.status === 422) itemModal.value.errors = e.response.data?.errors ?? null
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan item')
  } finally {
    itemModal.value.submitting = false
  }
}

async function removeItem(item) {
  if (!confirm(`Hapus item ${item.item_name ?? '—'} dari paket?`)) return
  try {
    await store.removeItem(paketId.value, item.id)
    showToast('s', 'Item dihapus')
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  }
}

// ─── Modal Tariff ────────────────────────────────────────────────────────
const tariffModal = ref({
  open: false,
  payload: { insurer_id: '', sell_price: 0, is_active: true },
  errors: null, submitting: false, editingId: null,
})

function openAddTariff() {
  tariffModal.value = {
    open: true,
    payload: { insurer_id: '', sell_price: paket.value?.total_base_price ?? 0, is_active: true },
    errors: null, submitting: false, editingId: null,
  }
}

function openEditTariff(t) {
  tariffModal.value = {
    open: true,
    payload: {
      insurer_id: t.insurer_id ?? '',
      sell_price: t.sell_price,
      is_active: t.is_active,
    },
    errors: null, submitting: false, editingId: t.id,
  }
}

const tariffFields = computed(() => {
  const insurerOpts = [{ value: '', label: 'SEMUA (default)' }, ...insurers.value.map((i) => ({ value: i.id, label: i.name }))]
  return [
    { key: 'insurer_id',     label: 'Penjamin',    type: 'select',   options: insurerOpts, cols: 2, hint: 'Kosongkan = berlaku untuk semua penjamin' },
    { key: 'sell_price',     label: 'Harga Jual (Rp)', type: 'number', required: true, min: 0, cols: 2, hint: `Base total paket: Rp ${Number(paket.value?.total_base_price ?? 0).toLocaleString('id-ID')} — selisih jadi diskon` },
    { key: 'is_active',      label: 'Aktif',       type: 'checkbox', cols: 1 },
  ]
})

async function onSubmitTariff(payload) {
  tariffModal.value.submitting = true
  tariffModal.value.errors = null
  try {
    const data = { ...payload, insurer_id: payload.insurer_id || null }
    await store.upsertTariff(paketId.value, data)
    showToast('s', 'Tarif paket disimpan')
    tariffModal.value.open = false
  } catch (e) {
    if (e.response?.status === 422) tariffModal.value.errors = e.response.data?.errors ?? null
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan tarif')
  } finally {
    tariffModal.value.submitting = false
  }
}

async function removeTariff(t) {
  if (!confirm(`Hapus tarif untuk ${t.insurer?.name ?? 'SEMUA'}?`)) return
  try {
    await store.removeTariff(paketId.value, t.id)
    showToast('s', 'Tarif dihapus')
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  }
}

// ─── Derived ─────────────────────────────────────────────────────────────
const itemsEnriched = computed(() => {
  return (paket.value?.items ?? []).map((it) => {
    // Backend showPaket() sudah enrich item_name/item_code/subtotal
    return {
      ...it,
      _type_label: ITEM_TYPE_OPTIONS.find((t) => t.value === it.item_type)?.label ?? it.item_type,
    }
  })
})

const tariffsRaw = computed(() => paket.value?.package_tariffs ?? paket.value?.packageTariffs ?? [])

const formatRp = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID')

const toast = ref(null)
function showToast(t, msg) {
  toast.value = { type: t, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

// ─── CSV per-paket (template / export / import komposisi paket ini) ─────────
const csvBusy = ref(false)
const csvFileInput = ref(null)
const openMenu = ref(null)   // 'template' | 'export' | null

function toggleMenu(name) {
  openMenu.value = openMenu.value === name ? null : name
}
function closeMenuOnOutside(e) {
  if (!e.target.closest?.('.pd-split')) openMenu.value = null
}

async function onDownloadTemplate(format = 'csv') {
  openMenu.value = null
  csvBusy.value = true
  try {
    await store.downloadPaketTemplateOne(paketId.value, paket.value?.name, format)
    showToast('s', `Template komposisi diunduh (${format.toUpperCase()})`)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal mengunduh template')
  } finally { csvBusy.value = false }
}

async function onExportCsv(format = 'csv') {
  openMenu.value = null
  csvBusy.value = true
  try {
    await store.exportPaketCsvOne(paketId.value, paket.value?.name, format)
    showToast('s', `Komposisi paket diunduh (${format.toUpperCase()})`)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal mengunduh')
  } finally { csvBusy.value = false }
}

function triggerImport() {
  csvFileInput.value?.click()
}

async function onImportFile(e) {
  const file = e.target.files?.[0]
  e.target.value = '' // reset supaya bisa pilih file sama lagi
  if (!file) return
  if (!confirm(`Ganti komposisi paket "${paket.value?.name}" dari file ini? Item lama akan diganti (tarif jual per penjamin tidak berubah).`)) return
  csvBusy.value = true
  try {
    const res = await store.importPaketCsvOne(file, paketId.value)
    const fail = res?.items_lookup_fail ?? 0
    const ins = res?.items_inserted ?? 0
    showToast(fail ? 'w' : 's', `Komposisi diperbarui: ${ins} item${fail ? `, ${fail} gagal lookup` : ''}.`)
    if (res?.errors?.length) {
      console.warn('[import paket]', res.errors)
    }
  } catch (err) {
    showToast('e', err.response?.data?.message ?? 'Gagal import komposisi')
  } finally { csvBusy.value = false }
}

onMounted(async () => {
  document.addEventListener('click', closeMenuOnOutside)
  await Promise.all([store.fetchPaketDetail(paketId.value), loadDropdowns()])
})
onUnmounted(() => document.removeEventListener('click', closeMenuOnOutside))

watch(paketId, async (id) => {
  if (id) await store.fetchPaketDetail(id)
})
</script>

<template>
  <div class="pd-wrap">
    <!-- Back button -->
    <button class="pd-back" @click="router.push('/tarif-paket/paket-bedah')">
      <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      Kembali ke daftar paket
    </button>

    <!-- Loading / Error -->
    <div v-if="store.paketDetailLoading" class="pd-loading">Memuat detail paket…</div>
    <div v-else-if="store.paketDetailError" class="pd-error">{{ store.paketDetailError }}</div>

    <template v-else-if="paket">
      <!-- Header info paket -->
      <header class="pd-head">
        <div class="pd-head-info">
          <h2>{{ paket.name }}</h2>
          <div class="pd-meta">
            <span v-if="paket.category" class="pd-meta-pill">{{ paket.category }}</span>
            <span class="pd-meta-pill" :class="paket.is_active ? 'on' : 'off'">{{ paket.is_active ? 'Aktif' : 'Nonaktif' }}</span>
          </div>
          <p v-if="paket.description" class="pd-desc">{{ paket.description }}</p>
        </div>
        <div class="pd-head-stats">
          <div class="pd-stat">
            <span class="pd-stat-label">Total Base Price</span>
            <span class="pd-stat-value">{{ formatRp(paket.total_base_price) }}</span>
            <span class="pd-stat-sub">Σ qty × harga snapshot per item</span>
          </div>
        </div>
      </header>

      <div class="pd-grid">
        <!-- ITEMS PANEL -->
        <section class="pd-panel">
          <div class="pd-panel-head">
            <div>
              <h3>Komposisi Items</h3>
              <p>Tindakan / Obat / BHP / IOL — harga snapshot saat ditambahkan.</p>
            </div>
            <div class="pd-head-actions">
              <div class="pd-split">
                <button class="pd-btn-ghost" :disabled="csvBusy" title="Unduh template komposisi paket ini (terisi) — pilih format" @click.stop="toggleMenu('template')">
                  <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  Template
                  <svg class="pd-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div v-if="openMenu === 'template'" class="pd-menu">
                  <button @click="onDownloadTemplate('csv')">CSV (.csv)</button>
                  <button @click="onDownloadTemplate('xlsx')">Excel (.xlsx)</button>
                </div>
              </div>
              <div class="pd-split">
                <button class="pd-btn-ghost" :disabled="csvBusy || !itemsEnriched.length" title="Unduh komposisi paket ini — pilih format" @click.stop="toggleMenu('export')">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  Export
                  <svg class="pd-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div v-if="openMenu === 'export'" class="pd-menu">
                  <button @click="onExportCsv('csv')">CSV (.csv)</button>
                  <button @click="onExportCsv('xlsx')">Excel (.xlsx)</button>
                </div>
              </div>
              <button class="pd-btn-ghost" :disabled="csvBusy" title="Ganti komposisi paket ini dari CSV/Excel" @click="triggerImport">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Import
              </button>
              <input ref="csvFileInput" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:none" @change="onImportFile" />
              <button class="pd-btn-primary" @click="openAddItem">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Tambah Item
              </button>
            </div>
          </div>

          <table v-if="itemsEnriched.length" class="pd-table">
            <thead>
              <tr><th>Tipe</th><th>Item</th><th class="r">Qty</th><th class="r">Harga</th><th class="r">Subtotal</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="it in itemsEnriched" :key="it.id">
                <td><span class="pd-type-pill" :data-t="it.item_type">{{ it._type_label }}</span></td>
                <td>
                  <div class="pd-item-cell">
                    <strong>{{ it.item_name || '—' }}</strong>
                  </div>
                </td>
                <td class="r">{{ it.quantity }}</td>
                <td class="r">{{ formatRp(it.default_price) }}</td>
                <td class="r"><strong>{{ formatRp(it.subtotal) }}</strong></td>
                <td class="r" style="white-space:nowrap">
                  <button class="pd-icon-btn" title="Edit qty/harga" @click="openEditItem(it)">
                    <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                  </button>
                  <button class="pd-icon-btn pd-icon-danger" title="Hapus" @click="removeItem(it)">
                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                  </button>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr><td colspan="4" class="r"><strong>Base Total</strong></td><td class="r"><strong>{{ formatRp(paket.total_base_price) }}</strong></td><td></td></tr>
            </tfoot>
          </table>
          <div v-else class="pd-empty">Belum ada item. Klik <strong>Tambah Item</strong> untuk mulai mengisi komposisi paket.</div>
        </section>

        <!-- TARIFFS PANEL -->
        <section class="pd-panel">
          <div class="pd-panel-head">
            <div>
              <h3>Tarif Jual per Penjamin</h3>
              <p>Sistem otomatis hitung diskon: <em>Base Total − Harga Jual</em>.</p>
            </div>
            <button class="pd-btn-primary" @click="openAddTariff" :disabled="!itemsEnriched.length" :title="!itemsEnriched.length ? 'Isi komposisi item dulu' : ''">
              <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Tambah Tarif
            </button>
          </div>

          <table v-if="tariffsRaw.length" class="pd-table">
            <thead>
              <tr><th>Penjamin</th><th class="r">Harga Jual</th><th class="r">Diskon</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="t in tariffsRaw" :key="t.id">
                <td>
                  <span class="pd-insurer-pill" :class="{ all: !t.insurer_id }">{{ t.insurer?.name ?? 'SEMUA' }}</span>
                </td>
                <td class="r"><strong>{{ formatRp(t.sell_price) }}</strong></td>
                <td class="r">
                  <div v-if="t.discount_amount > 0" class="pd-disc">
                    <span class="pd-disc-amt">−{{ formatRp(t.discount_amount) }}</span>
                    <span class="pd-disc-pct">({{ t.discount_percent }}%)</span>
                  </div>
                  <span v-else class="pd-no-disc">—</span>
                </td>
                <td><span class="pd-status" :class="t.is_active ? 'on' : 'off'">{{ t.is_active ? 'Aktif' : 'Off' }}</span></td>
                <td class="r" style="white-space:nowrap">
                  <button class="pd-icon-btn" title="Edit" @click="openEditTariff(t)">
                    <svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                  </button>
                  <button class="pd-icon-btn pd-icon-danger" title="Hapus" @click="removeTariff(t)">
                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="pd-empty">Belum ada tarif jual. Klik <strong>Tambah Tarif</strong> untuk set harga per penjamin.</div>
        </section>
      </div>
    </template>

    <!-- Modals -->
    <MasterFormModal
      v-model:open="itemModal.open"
      v-model="itemModal.payload"
      :title="itemModal.mode === 'create' ? 'Tambah Item ke Paket' : 'Edit Item Paket'"
      :fields="itemFields"
      :submitting="itemModal.submitting"
      :errors="itemModal.errors"
      :submit-label="itemModal.mode === 'create' ? 'Tambah' : 'Simpan'"
      width="580px"
      @submit="onSubmitItem"
    />

    <MasterFormModal
      v-model:open="tariffModal.open"
      v-model="tariffModal.payload"
      :title="tariffModal.editingId ? 'Edit Tarif Paket' : 'Tambah Tarif Paket'"
      :fields="tariffFields"
      :submitting="tariffModal.submitting"
      :errors="tariffModal.errors"
      submit-label="Simpan Tarif"
      width="560px"
      @submit="onSubmitTariff"
    />

    <Teleport to="body">
      <div v-if="toast" class="pd-toast-wrap">
        <div class="pd-toast" :class="`pd-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.pd-wrap { display: flex; flex-direction: column; gap: 1rem; }

.pd-back { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12px; font-weight: 500; cursor: pointer; align-self: flex-start; transition: background 0.15s, color 0.15s; }
.pd-back:hover { background: var(--gl); color: var(--td); border-color: var(--ga); }
.pd-back svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.pd-loading, .pd-error { padding: 2rem; text-align: center; color: var(--tm); font-size: 13px; }
.pd-error { color: var(--et); }

.pd-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1.5rem; padding: 1.2rem 1.3rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 12px; flex-wrap: wrap; }
.pd-head-info h2 { font-family: 'Space Grotesk', serif; font-size: 22px; color: var(--td); margin: 0 0 6px; }
.pd-meta { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
.pd-meta-pill { display: inline-block; padding: 3px 9px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bc); color: var(--tm); border: 1px solid var(--gb); }
.pd-meta-pill.on { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.pd-meta-pill.off { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.pd-desc { font-size: 13px; color: var(--tm); margin: 0; max-width: 600px; }

.pd-head-stats { display: flex; gap: 1rem; }
.pd-stat { background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; padding: 0.8rem 1.1rem; min-width: 200px; display: flex; flex-direction: column; gap: 2px; }
.pd-stat-label { font-size: 10.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.06em; }
.pd-stat-value { font-size: 20px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.pd-stat-sub { font-size: 11px; color: var(--tu); }

.pd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; align-items: start; }
@media (max-width: 1100px) { .pd-grid { grid-template-columns: 1fr; } }

.pd-panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.8rem; }
.pd-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.pd-panel-head h3 { font-family: 'Space Grotesk', serif; font-size: 16px; color: var(--td); margin: 0; }
.pd-panel-head p { font-size: 12px; color: var(--tm); margin: 2px 0 0; }

.pd-btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 8px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 12px; font-weight: 500; cursor: pointer; flex-shrink: 0; }
.pd-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.pd-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.pd-btn-primary svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.pd-head-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
.pd-btn-ghost { display: inline-flex; align-items: center; gap: 5px; padding: 7px 11px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 12px; font-weight: 500; cursor: pointer; }
.pd-btn-ghost:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); color: var(--td); }
.pd-btn-ghost:disabled { opacity: 0.45; cursor: not-allowed; }
.pd-btn-ghost svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pd-split { position: relative; }
.pd-caret { width: 11px !important; height: 11px !important; margin-left: 1px; }
.pd-menu { position: absolute; top: calc(100% + 4px); right: 0; z-index: 50; background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,0.14); padding: 4px; min-width: 140px; display: flex; flex-direction: column; gap: 2px; }
.pd-menu button { text-align: left; padding: 7px 10px; border: none; background: transparent; border-radius: 6px; font-size: 12px; color: var(--td); cursor: pointer; }
.pd-menu button:hover { background: var(--gl); color: var(--ga); }

.pd-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.pd-table th, .pd-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--gb); }
.pd-table th { font-size: 11px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; background: var(--bs); }
.pd-table .r { text-align: right; }
.pd-table tfoot td { background: var(--bs); padding: 10px; border-top: 2px solid var(--gb); font-size: 13px; }

.pd-type-pill { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 600; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.pd-type-pill[data-t="PROCEDURE"]  { background: var(--gl); color: var(--td); border-color: var(--ga); }
.pd-type-pill[data-t="MEDICATION"] { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.pd-type-pill[data-t="BHP"]        { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.pd-type-pill[data-t="IOL"]        { background: var(--pb); color: var(--pt); border-color: var(--pbd); }

.pd-item-cell { display: flex; flex-direction: column; gap: 1px; }
.pd-item-cell strong { font-weight: 500; color: var(--td); }

.pd-insurer-pill { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.pd-insurer-pill.all { background: var(--bs); color: var(--tm); border-color: var(--gb); }
.pd-class-pill { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 600; }
.pd-class-pill[data-c="UMUM"] { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.pd-class-pill[data-c="BPJS"] { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.pd-class-pill[data-c="ASURANSI"] { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.pd-class-pill[data-c="PERUSAHAAN"] { background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); }
.pd-class-pill[data-c="SOSIAL"] { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.pd-disc { display: flex; flex-direction: column; gap: 1px; }
.pd-disc-amt { font-weight: 600; color: var(--et); font-variant-numeric: tabular-nums; }
.pd-disc-pct { font-size: 10.5px; color: var(--tu); }
.pd-no-disc { color: var(--tu); }

.pd-status { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
.pd-status.on { background: var(--sb); color: var(--st); }
.pd-status.off { background: var(--eb); color: var(--et); }

.pd-icon-btn { width: 26px; height: 26px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 3px; }
.pd-icon-btn svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pd-icon-btn:hover { background: var(--gl); color: var(--td); border-color: var(--ga); }
.pd-icon-danger:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }

.pd-empty { padding: 1.8rem 1rem; text-align: center; color: var(--tu); font-size: 12.5px; background: var(--bs); border-radius: 8px; }

.pd-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.pd-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; animation: pd-toast-in 0.2s ease; }
.pd-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.pd-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
@keyframes pd-toast-in { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
