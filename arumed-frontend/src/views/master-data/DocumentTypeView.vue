<script setup>
/**
 * DocumentTypeView — master Jenis Dokumen RM.
 *
 * Fitur:
 *   - Table list (include inactive) dengan filter category/search
 *   - Tombol "+ Baru" → modal form (create / edit)
 *   - Tombol Hapus → delete-guard (refuse kalau ada template/dokumen/child)
 *   - Toggle is_active in-place
 *
 * Permission:
 *   - master_data.read  → list + view
 *   - master_data.write → create/update/delete/toggle
 */
import { computed, onMounted, ref } from 'vue'
import { formTemplateApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('master_data.write'))

const list = ref([])
const loading = ref(false)
const search = ref('')
const filterCategory = ref('all')
const filterStatus = ref('all') // all | active | inactive

const FREQUENCY_OPTIONS = ['ONCE_LIFETIME', 'PER_VISIT', 'PER_EPISODE']
const GENERATE_OPTIONS = ['MANUAL', 'AUTO', 'HYBRID']
const CATEGORY_OPTIONS = ['ADMINISTRASI', 'KLINIS', 'PENUNJANG', 'BEDAH', 'FARMASI', 'BILLING']

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return list.value.filter((dt) => {
    if (filterCategory.value !== 'all' && dt.category !== filterCategory.value) return false
    if (filterStatus.value === 'active' && !dt.is_active) return false
    if (filterStatus.value === 'inactive' && dt.is_active) return false
    if (!q) return true
    return (
      (dt.name?.toLowerCase().includes(q)) ||
      (dt.code?.toLowerCase().includes(q))
    )
  })
})

const parentOptions = computed(() =>
  list.value.filter((dt) => dt.is_active),
)

async function load() {
  loading.value = true
  try {
    const { data } = await formTemplateApi.documentTypes({ all: 1 })
    list.value = data.data ?? []
  } catch (e) {
    alert('Gagal load: ' + (e.response?.data?.message ?? e.message))
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ── Modal form ──────────────────────────────────────────────────────────
const formOpen = ref(false)
const editingId = ref(null)
const formError = ref('')
const formBusy = ref(false)
const form = ref(blankForm())

function blankForm() {
  return {
    code: '',
    name: '',
    fill_frequency: 'PER_VISIT',
    generate_type: 'MANUAL',
    category: '',
    parent_id: null,
    show_in_rme: true,
    sort_order: null,
    is_active: true,
    required_signatures: [],
  }
}

function openCreate() {
  editingId.value = null
  form.value = blankForm()
  // Auto sort_order = max + 1
  const maxSort = list.value.reduce((m, dt) => Math.max(m, dt.sort_order ?? 0), 0)
  form.value.sort_order = maxSort + 1
  formError.value = ''
  formOpen.value = true
}

function openEdit(dt) {
  editingId.value = dt.id
  form.value = {
    code:                dt.code ?? '',
    name:                dt.name ?? '',
    fill_frequency:      dt.fill_frequency ?? 'PER_VISIT',
    generate_type:       dt.generate_type ?? 'MANUAL',
    category:            dt.category ?? '',
    parent_id:           dt.parent_id ?? null,
    show_in_rme:         !!dt.show_in_rme,
    sort_order:          dt.sort_order ?? 0,
    is_active:           !!dt.is_active,
    required_signatures: Array.isArray(dt.required_signatures) ? [...dt.required_signatures] : [],
  }
  formError.value = ''
  formOpen.value = true
}

function addSignatureRow() {
  form.value.required_signatures.push({ role: '', sign_type: 'digital', is_required: true })
}

function removeSignatureRow(idx) {
  form.value.required_signatures.splice(idx, 1)
}

async function saveForm() {
  if (!form.value.code.trim() || !form.value.name.trim()) {
    formError.value = 'Code dan Name wajib diisi.'
    return
  }
  formBusy.value = true
  formError.value = ''
  const payload = {
    ...form.value,
    code: form.value.code.trim(),
    name: form.value.name.trim(),
    category: form.value.category || null,
    parent_id: form.value.parent_id || null,
    sort_order: form.value.sort_order ?? 0,
    required_signatures: form.value.required_signatures.length
      ? form.value.required_signatures.filter(s => s.role?.trim())
      : null,
  }
  try {
    if (editingId.value) {
      await formTemplateApi.updateDocumentType(editingId.value, payload)
    } else {
      await formTemplateApi.createDocumentType(payload)
    }
    formOpen.value = false
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    if (errs) {
      formError.value = Object.values(errs).flat().join(', ')
    } else {
      formError.value = e.response?.data?.message ?? 'Gagal simpan.'
    }
  } finally {
    formBusy.value = false
  }
}

async function deleteDt(dt) {
  if (!confirm(`Hapus "${dt.name}"? Aksi ini tidak bisa di-undo.`)) return
  try {
    await formTemplateApi.deleteDocumentType(dt.id)
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Gagal hapus.')
  }
}

async function toggleActive(dt) {
  try {
    await formTemplateApi.updateDocumentType(dt.id, {
      code: dt.code,
      name: dt.name,
      fill_frequency: dt.fill_frequency,
      generate_type: dt.generate_type,
      category: dt.category,
      parent_id: dt.parent_id,
      show_in_rme: dt.show_in_rme,
      sort_order: dt.sort_order,
      is_active: !dt.is_active,
      required_signatures: dt.required_signatures,
    })
    await load()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Gagal toggle status.')
  }
}

function parentName(id) {
  if (!id) return '—'
  const p = list.value.find((dt) => dt.id === id)
  return p ? `${p.code} · ${p.name}` : '(tidak ditemukan)'
}

function formatFreq(f) {
  return ({ ONCE_LIFETIME: '1x seumur hidup', PER_VISIT: 'Per kunjungan', PER_EPISODE: 'Per episode' })[f] ?? f
}
</script>

<template>
  <div class="dt-wrap">
    <header class="dt-head">
      <div>
        <h2>Jenis Dokumen Rekam Medis</h2>
        <p class="dt-sub">{{ filtered.length }} jenis · master kategori untuk Template Form RM</p>
      </div>
      <button v-if="canWrite" class="dt-btn-primary" @click="openCreate">+ Baru</button>
    </header>

    <div class="dt-toolbar">
      <input v-model="search" class="dt-search" placeholder="Cari code / nama…" />
      <select v-model="filterCategory" class="dt-select">
        <option value="all">Semua Kategori</option>
        <option v-for="c in CATEGORY_OPTIONS" :key="c" :value="c">{{ c }}</option>
      </select>
      <select v-model="filterStatus" class="dt-select">
        <option value="all">Semua Status</option>
        <option value="active">Aktif</option>
        <option value="inactive">Nonaktif</option>
      </select>
    </div>

    <div v-if="loading" class="dt-empty">Memuat…</div>
    <div v-else-if="filtered.length === 0" class="dt-empty">
      Belum ada jenis dokumen. Klik "+ Baru" untuk menambahkan.
    </div>

    <table v-else class="dt-table">
      <thead>
        <tr>
          <th>Code</th>
          <th>Nama</th>
          <th>Kategori</th>
          <th>Frekuensi</th>
          <th>Generate</th>
          <th>Parent</th>
          <th>Sort</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="dt in filtered" :key="dt.id" :class="{ inactive: !dt.is_active }">
          <td><code>{{ dt.code }}</code></td>
          <td>{{ dt.name }}</td>
          <td><span v-if="dt.category" class="dt-chip">{{ dt.category }}</span></td>
          <td>{{ formatFreq(dt.fill_frequency) }}</td>
          <td>{{ dt.generate_type }}</td>
          <td class="dt-parent">{{ parentName(dt.parent_id) }}</td>
          <td>{{ dt.sort_order }}</td>
          <td>
            <span class="dt-status" :class="dt.is_active ? 'st-active' : 'st-inactive'">
              {{ dt.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
          </td>
          <td class="dt-actions">
            <template v-if="canWrite">
              <button class="dt-btn-sm" @click="openEdit(dt)">Edit</button>
              <button class="dt-btn-sm" @click="toggleActive(dt)">
                {{ dt.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
              </button>
              <button class="dt-btn-sm dt-btn-danger" @click="deleteDt(dt)">Hapus</button>
            </template>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Modal form -->
    <Teleport to="body">
      <div v-if="formOpen" class="dt-overlay" @click.self="formOpen = false">
        <div class="dt-modal">
          <header class="dt-modal-head">
            <h3>{{ editingId ? 'Edit Jenis Dokumen' : 'Jenis Dokumen Baru' }}</h3>
            <button class="dt-btn-sm" @click="formOpen = false">Tutup</button>
          </header>
          <div class="dt-modal-body">
            <div class="dt-grid">
              <label>
                Code <span class="req">*</span>
                <input v-model="form.code" placeholder="RM-X.Y atau kode klinik" :disabled="formBusy" />
                <small>Format bebas, tapi disarankan RM-Group.NoUrut (mis. RM-9.1)</small>
              </label>
              <label>
                Nama <span class="req">*</span>
                <input v-model="form.name" placeholder="Nama jenis dokumen" :disabled="formBusy" />
              </label>
              <label>
                Frekuensi Pengisian <span class="req">*</span>
                <select v-model="form.fill_frequency" :disabled="formBusy">
                  <option v-for="f in FREQUENCY_OPTIONS" :key="f" :value="f">{{ formatFreq(f) }} ({{ f }})</option>
                </select>
              </label>
              <label>
                Tipe Generate <span class="req">*</span>
                <select v-model="form.generate_type" :disabled="formBusy">
                  <option v-for="g in GENERATE_OPTIONS" :key="g" :value="g">{{ g }}</option>
                </select>
              </label>
              <label>
                Kategori
                <select v-model="form.category" :disabled="formBusy">
                  <option value="">— Tidak dikategorikan —</option>
                  <option v-for="c in CATEGORY_OPTIONS" :key="c" :value="c">{{ c }}</option>
                </select>
              </label>
              <label>
                Parent (opsional)
                <select v-model="form.parent_id" :disabled="formBusy">
                  <option :value="null">— Tidak ada parent —</option>
                  <option
                    v-for="p in parentOptions"
                    :key="p.id"
                    :value="p.id"
                    :disabled="p.id === editingId"
                  >
                    {{ p.code }} · {{ p.name }}
                  </option>
                </select>
                <small>Self-ref untuk sub-jenis (mis. IC Katarak → parent IC Bedah Umum)</small>
              </label>
              <label>
                Sort Order
                <input v-model.number="form.sort_order" type="number" :disabled="formBusy" />
              </label>
              <label class="dt-check">
                <input v-model="form.show_in_rme" type="checkbox" :disabled="formBusy" />
                Tampil di RME
              </label>
              <label class="dt-check">
                <input v-model="form.is_active" type="checkbox" :disabled="formBusy" />
                Aktif
              </label>
            </div>

            <div class="dt-section">
              <header class="dt-section-head">
                <strong>Required Signatures</strong>
                <button class="dt-btn-sm" @click="addSignatureRow" :disabled="formBusy">+ Tambah Signer</button>
              </header>
              <div v-if="form.required_signatures.length === 0" class="dt-section-empty">
                Tidak ada signer wajib. Tambahkan jika dokumen butuh TTD spesifik role.
              </div>
              <div v-for="(sig, i) in form.required_signatures" :key="i" class="dt-sig-row">
                <input v-model="sig.role" placeholder="Role (mis. DOKTER, PERAWAT)" :disabled="formBusy" />
                <select v-model="sig.sign_type" :disabled="formBusy">
                  <option value="digital">Digital</option>
                  <option value="wet">Basah (manual)</option>
                </select>
                <label class="dt-check">
                  <input v-model="sig.is_required" type="checkbox" :disabled="formBusy" />
                  Wajib
                </label>
                <button class="dt-btn-sm dt-btn-danger" @click="removeSignatureRow(i)" :disabled="formBusy">×</button>
              </div>
            </div>

            <div v-if="formError" class="dt-err">{{ formError }}</div>
          </div>

          <footer class="dt-modal-foot">
            <button class="dt-btn-sm" @click="formOpen = false" :disabled="formBusy">Batal</button>
            <button class="dt-btn-primary" @click="saveForm" :disabled="formBusy">
              {{ formBusy ? 'Menyimpan…' : (editingId ? 'Update' : 'Simpan') }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.dt-wrap, .dt-wrap * { color: #000; }
.dt-wrap { display: flex; flex-direction: column; gap: 1rem; }
.dt-head { display: flex; justify-content: space-between; align-items: flex-end; }
.dt-head h2 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 22px; }
.dt-sub { margin: 4px 0 0; font-size: 13px; }

.dt-toolbar { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.dt-search {
  flex: 1; min-width: 220px; padding: 0.5rem 0.75rem; border: 1px solid #000; border-radius: 6px; font-size: 14px;
}
.dt-select {
  padding: 0.5rem 0.75rem; border: 1px solid #000; border-radius: 6px; font-size: 13.5px; background: #fff;
}

.dt-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #000; border-radius: 8px; overflow: hidden; }
.dt-table th, .dt-table td { padding: 0.65rem 0.85rem; text-align: left; font-size: 13px; border-bottom: 1px solid #ddd; vertical-align: middle; }
.dt-table th { background: #f5f5f5; font-weight: 700; }
.dt-table tr.inactive { opacity: 0.6; background: #fafafa; }
.dt-table tr:last-child td { border-bottom: 0; }

.dt-chip { display: inline-block; padding: 2px 8px; background: #eef6ff; border-radius: 999px; font-size: 11px; font-weight: 700; }
.dt-parent { font-size: 12px; max-width: 220px; }

.dt-status { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.dt-status.st-active   { background: #ecf9ee; }
.dt-status.st-inactive { background: #f0f1f4; }

.dt-actions { display: flex; gap: 0.3rem; flex-wrap: wrap; }

.dt-btn-primary {
  padding: 0.5rem 1rem; border: 1px solid #1763d4; border-radius: 6px;
  background: #1763d4; color: #fff !important; font-weight: 700; cursor: pointer; font-size: 13.5px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.dt-btn-primary:hover { background: #134fa8; }
.dt-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

.dt-btn-sm {
  padding: 0.3rem 0.6rem; border: 1px solid #000; border-radius: 5px;
  background: #fff; font-size: 11.5px; font-weight: 600; cursor: pointer;
}
.dt-btn-sm:hover { background: #f0f0f0; }
.dt-btn-sm:disabled { opacity: 0.5; cursor: not-allowed; }
.dt-btn-danger { border-color: #c83b3b; color: #c83b3b !important; }
.dt-btn-danger:hover { background: #ffe5e5; }

.dt-empty {
  padding: 2rem; text-align: center; background: #fff;
  border: 1px dashed #000; border-radius: 8px; font-size: 14px;
}

/* Modal */
.dt-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.dt-modal {
  width: min(720px, 94vw); max-height: 92vh; background: #fff; border-radius: 10px;
  display: flex; flex-direction: column; overflow: hidden;
}
.dt-modal-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.85rem 1.25rem; border-bottom: 1px solid #000;
}
.dt-modal-head h3 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 17px; }

.dt-modal-body { padding: 1rem 1.25rem; overflow-y: auto; flex: 1; background: #fafafa; }
.dt-modal-foot {
  display: flex; gap: 0.6rem; justify-content: flex-end;
  padding: 0.75rem 1.25rem; border-top: 1px solid #ddd; background: #fff;
}

.dt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem 1rem; }
.dt-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; font-weight: 600; }
.dt-grid label input,
.dt-grid label select {
  font: inherit; font-weight: normal; padding: 0.45rem 0.65rem;
  border: 1px solid #000; border-radius: 5px; background: #fff;
}
.dt-grid label small { color: #666; font-weight: normal; font-size: 11px; }
.req { color: #c83b3b; }

.dt-check { flex-direction: row !important; align-items: center; gap: 8px !important; }
.dt-check input { width: 16px; height: 16px; }

.dt-section { margin-top: 1.25rem; padding: 0.75rem 1rem; background: #fff; border: 1px solid #ddd; border-radius: 8px; }
.dt-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.dt-section-empty { padding: 0.5rem; color: #666; font-size: 12px; font-style: italic; }
.dt-sig-row {
  display: grid; grid-template-columns: 1.5fr 1fr auto auto; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;
}
.dt-sig-row input, .dt-sig-row select {
  padding: 0.35rem 0.5rem; border: 1px solid #000; border-radius: 5px; font-size: 12px; background: #fff;
}

.dt-err {
  margin-top: 0.85rem; padding: 0.55rem 0.85rem;
  background: #fff0f0; border: 1px solid #c83b3b; border-radius: 6px;
  color: #c83b3b !important; font-size: 12.5px;
}
</style>
