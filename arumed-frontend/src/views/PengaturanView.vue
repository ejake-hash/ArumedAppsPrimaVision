<script setup>
/**
 * PengaturanView — halaman Pengaturan.
 * Saat ini berisi: Penomoran Dokumen (format nomor RME/Invoice/SEP/dll).
 *
 * Token format: {CODE} {CLINIC} {SEQ} {YYYY} {MM} {DD}
 *   - dipakai generator backend (RekamMedisService::generateDocumentNumber)
 *
 * Permission:
 *   - master_data.read  → lihat
 *   - master_data.write → tambah/edit/hapus
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { masterApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useAppearance, FONT_SCALES } from '@/composables/useAppearance'

const auth = useAuthStore()
const canWrite = computed(() => auth.can?.('master_data.write') ?? auth.isSuperadmin)

// ── Tampilan (ukuran font & kepadatan) — preferensi per-perangkat ────────────
const appearance = useAppearance()

const list    = ref([])
const loading = ref(false)
const clinicCode = ref('RSKMPV')

const RESET_OPTIONS = [
  { value: 'NEVER',   label: 'Tidak pernah (berlanjut terus)' },
  { value: 'DAILY',   label: 'Harian' },
  { value: 'MONTHLY', label: 'Bulanan' },
  { value: 'YEARLY',  label: 'Tahunan' },
]
const RESET_LABEL = { NEVER: 'Tidak pernah', DAILY: 'Harian', MONTHLY: 'Bulanan', YEARLY: 'Tahunan' }

// ── Load ─────────────────────────────────────────────────────────────────────
async function loadList() {
  loading.value = true
  try {
    const { data } = await masterApi.nomorDokumen.list()
    list.value = data.data ?? []
  } catch (e) {
    alert('Gagal memuat: ' + (e.response?.data?.message ?? e.message))
    list.value = []
  } finally {
    loading.value = false
  }
}

async function loadClinicCode() {
  try {
    const { data } = await masterApi.profilKlinik.show()
    if (data.data?.clinic_code) clinicCode.value = data.data.clinic_code
  } catch { /* fallback default */ }
}

// ── Preview nomor ────────────────────────────────────────────────────────────
function buildPreview(format, seqLen, nextSeq = 1) {
  if (!format) return '—'
  const now = new Date()
  const pad = (n, w) => String(n).padStart(w, '0')
  const seq = pad(nextSeq, Math.max(3, Number(seqLen) || 7))
  return String(format)
    .replaceAll('{CLINIC}', clinicCode.value)
    .replaceAll('{SEQ}', seq)
    .replaceAll('{YYYY}', String(now.getFullYear()))
    .replaceAll('{MM}', pad(now.getMonth() + 1, 2))
    .replaceAll('{DD}', pad(now.getDate(), 2))
    .replaceAll('{CODE}', form.document_type_code || 'CODE')
}

function rowPreview(row) {
  // {CODE} pada baris pakai kode baris itu sendiri
  const built = buildPreview(row.format, row.seq_length, (row.last_seq ?? 0) + 1)
  return built.replaceAll('CODE', row.document_type_code)
}

// ── Modal form ───────────────────────────────────────────────────────────────
const showModal = ref(false)
const editingId = ref(null)
const saving = ref(false)
const formError = ref('')
const form = reactive({
  document_type_code: '',
  format: '',
  prefix: '',
  reset_period: 'NEVER',
  seq_length: 7,
})

const previewLive = computed(() => buildPreview(form.format, form.seq_length, 1))

function openCreate() {
  editingId.value = null
  formError.value = ''
  Object.assign(form, { document_type_code: '', format: '', prefix: '', reset_period: 'NEVER', seq_length: 7 })
  showModal.value = true
}

function openEdit(row) {
  editingId.value = row.id
  formError.value = ''
  Object.assign(form, {
    document_type_code: row.document_type_code,
    format: row.format,
    prefix: row.prefix ?? '',
    reset_period: row.reset_period ?? 'NEVER',
    seq_length: row.seq_length ?? 7,
  })
  showModal.value = true
}

function closeModal() { showModal.value = false }

async function save() {
  formError.value = ''
  if (!editingId.value && !form.document_type_code.trim()) {
    formError.value = 'Kode tipe dokumen wajib diisi.'
    return
  }
  if (!form.format.trim()) {
    formError.value = 'Format wajib diisi.'
    return
  }
  saving.value = true
  try {
    const payload = {
      format: form.format.trim(),
      prefix: form.prefix.trim() || null,
      reset_period: form.reset_period,
      seq_length: Number(form.seq_length) || 7,
    }
    if (editingId.value) {
      await masterApi.nomorDokumen.update(editingId.value, payload)
    } else {
      await masterApi.nomorDokumen.create({ ...payload, document_type_code: form.document_type_code.trim().toUpperCase() })
    }
    showModal.value = false
    await loadList()
  } catch (e) {
    formError.value = e.response?.data?.message ?? 'Gagal menyimpan.'
  } finally {
    saving.value = false
  }
}

async function remove(row) {
  if (!confirm(`Hapus konfigurasi nomor "${row.document_type_code}"?`)) return
  try {
    await masterApi.nomorDokumen.remove(row.id)
    await loadList()
  } catch (e) {
    alert(e.response?.data?.message ?? 'Gagal menghapus.')
  }
}

onMounted(() => { loadList(); loadClinicCode() })
</script>

<template>
  <div class="pg-wrap">
    <header class="pg-header">
      <div>
        <h1>Pengaturan</h1>
        <p class="pg-sub">Atur tampilan aplikasi dan penomoran dokumen.</p>
      </div>
    </header>

    <!-- ── Bagian: Tampilan ──────────────────────────────────────────────── -->
    <section class="card sec">
      <div class="sec-head">
        <h2>Tampilan</h2>
        <p class="sec-sub">Ukuran teks dan kepadatan tampilan. Tersimpan di perangkat ini.</p>
      </div>

      <div class="opt-row">
        <div class="opt-label">
          <span class="opt-title">Ukuran Font</span>
          <span class="opt-desc">Perbesar seluruh teks &amp; elemen secara proporsional.</span>
        </div>
        <div class="seg">
          <button
            v-for="s in FONT_SCALES"
            :key="s.value"
            class="seg-btn"
            :class="{ active: appearance.state.fontScale === s.value }"
            @click="appearance.setFontScale(s.value)"
          >{{ s.label }}</button>
        </div>
      </div>

      <div class="opt-row">
        <div class="opt-label">
          <span class="opt-title">Kepadatan</span>
          <span class="opt-desc">Mode “Kompak” merapatkan baris tabel agar muat lebih banyak.</span>
        </div>
        <div class="seg">
          <button
            class="seg-btn"
            :class="{ active: appearance.state.density === 'normal' }"
            @click="appearance.setDensity('normal')"
          >Normal</button>
          <button
            class="seg-btn"
            :class="{ active: appearance.state.density === 'compact' }"
            @click="appearance.setDensity('compact')"
          >Kompak</button>
        </div>
      </div>

      <div class="sec-foot">
        <button class="btn-ghost" @click="appearance.reset()">Kembalikan default</button>
      </div>
    </section>

    <!-- ── Bagian: Penomoran Dokumen ─────────────────────────────────────── -->
    <div class="sec-head sec-head--inline">
      <h2>Penomoran Dokumen</h2>
      <p class="sec-sub">Atur format nomor otomatis tiap jenis dokumen.</p>
      <button v-if="canWrite" class="btn-primary" @click="openCreate">+ Tambah Tipe</button>
    </div>

    <!-- Legenda token -->
    <div class="legend">
      <span class="lg-title">Token yang tersedia:</span>
      <code>{CLINIC}</code><span class="lg-d">kode klinik ({{ clinicCode }})</span>
      <code>{SEQ}</code><span class="lg-d">nomor urut</span>
      <code>{YYYY}</code><code>{MM}</code><code>{DD}</code><span class="lg-d">tanggal</span>
      <code>{CODE}</code><span class="lg-d">kode tipe</span>
    </div>

    <p v-if="loading" class="muted">Memuat…</p>
    <p v-else-if="!list.length" class="muted">Belum ada konfigurasi. Klik “+ Tambah Tipe”.</p>

    <table v-else class="tbl">
      <thead>
        <tr>
          <th>Kode</th>
          <th>Format</th>
          <th>Contoh Nomor</th>
          <th>Reset</th>
          <th>Panjang</th>
          <th>Terakhir</th>
          <th v-if="canWrite"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list" :key="row.id">
          <td><code class="code-pill">{{ row.document_type_code }}</code></td>
          <td><code class="fmt">{{ row.format }}</code></td>
          <td><span class="example">{{ rowPreview(row) }}</span></td>
          <td>{{ RESET_LABEL[row.reset_period] ?? row.reset_period }}</td>
          <td>{{ row.seq_length }}</td>
          <td>{{ row.last_seq ?? 0 }}</td>
          <td v-if="canWrite" class="actions">
            <button class="btn-ghost" @click="openEdit(row)">Edit</button>
            <button class="btn-ghost danger" @click="remove(row)">Hapus</button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Modal -->
    <Transition name="fade">
      <div v-if="showModal" class="modal-backdrop" @click.self="closeModal">
        <div class="modal">
          <h2>{{ editingId ? 'Edit' : 'Tambah' }} Penomoran Dokumen</h2>

          <label class="fld">
            <span>Kode Tipe Dokumen <em>*</em></span>
            <input
              v-model="form.document_type_code"
              type="text"
              :disabled="!!editingId"
              placeholder="mis. RME, INVOICE, SEP"
              maxlength="20"
              @input="form.document_type_code = form.document_type_code.toUpperCase()"
            />
            <small v-if="editingId" class="hint">Kode tidak dapat diubah.</small>
          </label>

          <label class="fld">
            <span>Format <em>*</em></span>
            <input v-model="form.format" type="text" placeholder="RME/{CLINIC}/{YYYY}{MM}/{SEQ}" maxlength="255" />
          </label>

          <div class="row2">
            <label class="fld">
              <span>Reset Urutan</span>
              <select v-model="form.reset_period">
                <option v-for="o in RESET_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
            <label class="fld">
              <span>Panjang Seq (3–10)</span>
              <input v-model.number="form.seq_length" type="number" min="3" max="10" />
            </label>
          </div>

          <div class="preview-box">
            <span class="pv-label">Contoh hasil:</span>
            <span class="pv-val">{{ previewLive }}</span>
          </div>

          <p v-if="formError" class="form-error">{{ formError }}</p>

          <div class="modal-actions">
            <button class="btn-ghost" @click="closeModal">Batal</button>
            <button class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Menyimpan…' : 'Simpan' }}</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.pg-wrap { padding: 1.5rem 2rem; display: flex; flex-direction: column; gap: 1.1rem; max-width: 1100px; }
.pg-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
.pg-header h1 { font-family: 'Space Grotesk', serif; font-size: 26px; color: var(--td); margin: 0; line-height: 1.2; }
.pg-sub { font-size: 13px; color: var(--tm); margin: 4px 0 0; }
.muted { color: var(--tm); font-size: 13px; }

.btn-primary { background: #1763d4; color: #fff !important; border: none; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-ghost { background: #fff; color: #000; border: 1px solid var(--gb); border-radius: 7px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.btn-ghost.danger { color: #991b1b; border-color: #fecaca; }
.btn-ghost:hover { background: var(--bs); }

/* Legenda */
.legend { display: flex; flex-wrap: wrap; align-items: center; gap: 5px 8px; background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; padding: 9px 12px; font-size: 12px; }
.legend .lg-title { font-weight: 600; color: var(--td); margin-right: 4px; }
.legend code { font-family: 'JetBrains Mono', monospace; font-size: 11px; background: #0f172a; color: #e2e8f0; padding: 2px 6px; border-radius: 5px; }
.legend .lg-d { color: var(--tm); margin-right: 8px; }

/* Tabel */
.tbl { width: 100%; border-collapse: collapse; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; font-size: 13px; }
.tbl th { text-align: left; padding: 9px 12px; background: var(--bs); color: var(--tm); font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.tbl td { padding: 9px 12px; border-top: 1px solid var(--gb); color: var(--td); vertical-align: middle; }
.code-pill { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; font-weight: 700; background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 6px; }
.fmt { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--td); }
.example { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 6px; }
.actions { display: flex; gap: 6px; justify-content: flex-end; }

/* Modal */
.modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); display: flex; align-items: center; justify-content: center; z-index: 9000; padding: 1rem; }
.modal { background: #fff; border-radius: 14px; padding: 1.4rem 1.5rem; width: 100%; max-width: 480px; display: flex; flex-direction: column; gap: 0.8rem; box-shadow: 0 20px 50px rgba(0,0,0,0.25); }
.modal h2 { font-family: 'Space Grotesk', serif; font-size: 18px; color: #000; margin: 0 0 0.2rem; }
.fld { display: flex; flex-direction: column; gap: 4px; }
.fld > span { font-size: 12px; font-weight: 600; color: #000; }
.fld em { color: #dc2626; font-style: normal; }
.fld input, .fld select { padding: 8px 10px; border: 1px solid var(--gb); border-radius: 8px; font-size: 13px; color: #000; background: #fff; }
.fld input:disabled { background: #f1f5f9; color: #64748b; }
.hint { font-size: 11px; color: var(--tm); }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }

.preview-box { display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px dashed var(--gb); border-radius: 8px; padding: 9px 11px; }
.pv-label { font-size: 11.5px; font-weight: 600; color: #475569; }
.pv-val { font-family: 'JetBrains Mono', monospace; font-size: 12.5px; color: #166534; font-weight: 600; }

.form-error { color: #991b1b; font-size: 12.5px; margin: 0; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 0.3rem; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.18s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* ── Bagian Tampilan ── */
.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; }
.sec { padding: 1.1rem 1.3rem; display: flex; flex-direction: column; gap: 0.9rem; }
.sec-head h2 { font-family: 'Space Grotesk', serif; font-size: 17px; color: var(--td); margin: 0; }
.sec-sub { font-size: 12.5px; color: var(--tm); margin: 3px 0 0; }
.sec-head--inline { display: flex; align-items: center; gap: 10px; margin-top: 0.3rem; }
.sec-head--inline h2 { font-size: 17px; }
.sec-head--inline .sec-sub { flex: 1; }

.opt-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.55rem 0; border-top: 1px solid var(--gb); }
.opt-row:first-of-type { border-top: none; }
.opt-label { display: flex; flex-direction: column; gap: 2px; }
.opt-title { font-size: 13.5px; font-weight: 600; color: var(--td); }
.opt-desc { font-size: 12px; color: var(--tm); }

.seg { display: inline-flex; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 3px; gap: 2px; flex-shrink: 0; }
.seg-btn { background: transparent; border: none; border-radius: 6px; padding: 6px 13px; font-size: 12.5px; font-weight: 600; color: var(--tm); cursor: pointer; white-space: nowrap; transition: background 0.12s, color 0.12s; }
.seg-btn:hover { color: var(--td); }
.seg-btn.active { background: var(--gd); color: #fff; }

.sec-foot { display: flex; justify-content: flex-end; }
</style>
