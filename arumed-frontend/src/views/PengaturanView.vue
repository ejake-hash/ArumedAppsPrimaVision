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
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { masterApi, kasirApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useAppearance, FONT_SCALES } from '@/composables/useAppearance'
import KwitansiPrint from '@/components/kasir/KwitansiPrint.vue'

const auth = useAuthStore()
const canWrite = computed(() => auth.can?.('master_data.write') ?? auth.isSuperadmin)
// Tab Kwitansi hanya untuk yang punya hak kasir (baca invoice + cetak kwitansi).
const canKasir = computed(() => auth.can?.('kasir.read') ?? auth.isSuperadmin)

// ── Tab aktif ────────────────────────────────────────────────────────────────
const activeTab = ref('umum')   // 'umum' | 'kwitansi'

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

// ═══════════════════════════════════════════════════════════════════════════
// TAB KWITANSI — telusuri invoice yang sudah di-generate lalu ubah TAMPILAN
// cetaknya (Tgl Invoice / Tgl Bayar / Tgl Kunjungan / Metode Bayar).
//
// Override disimpan di localStorage browser (per-ID invoice), BUKAN ke DB:
//   - Data asli di backend 100% utuh (created_at/paid_at/visit_date tak disentuh).
//   - Cetak ulang dari perangkat yang sama tetap konsisten (override termuat lagi).
//   - Konsekuensi: override bersifat per-perangkat/per-browser (tak tersinkron
//     lintas-komputer). Cukup untuk reprint dari stasiun yang sama.
// Cetak memakai komponen bersama KwitansiPrint (kop/struktur identik KasirView).
// ═══════════════════════════════════════════════════════════════════════════
const OVR_PREFIX = 'kwitansi_print_override:'

function todayStr() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const PAY_METHODS = [
  { value: 'CASH',        label: 'Tunai' },
  { value: 'CREDIT_CARD', label: 'Debit/Kredit' },
  { value: 'TRANSFER',    label: 'Transfer' },
  { value: 'BPJS',        label: 'BPJS' },
  { value: 'INSURANCE',   label: 'Ditanggung Asuransi' },
  { value: 'WAIVED',      label: 'Gratis / Diskon 100%' },
]

// ── Konversi format tanggal (kwitansi pakai dd/mm/yyyy & dd/mm/yyyy HH:mm) ──
function dmyToInput(s) {                 // "16/06/2026" → "2026-06-16"
  const m = String(s ?? '').match(/(\d{2})\/(\d{2})\/(\d{4})/)
  return m ? `${m[3]}-${m[2]}-${m[1]}` : ''
}
function dmyhmToInput(s) {                // "16/06/2026 10:30" → "2026-06-16T10:30"
  const m = String(s ?? '').match(/(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/)
  if (!m) return ''
  return `${m[3]}-${m[2]}-${m[1]}T${m[4] ?? '00'}:${m[5] ?? '00'}`
}
function inputToDmy(s) {                  // "2026-06-16" → "16/06/2026"
  const m = String(s ?? '').match(/(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : ''
}
function inputToDmyHm(s) {                // "2026-06-16T10:30" → "16/06/2026 10:30"
  const m = String(s ?? '').match(/(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : ''
}

function loadOverride(id) {
  try { return JSON.parse(localStorage.getItem(OVR_PREFIX + id) || 'null') } catch { return null }
}
function persistOverride(id, ovr) { localStorage.setItem(OVR_PREFIX + id, JSON.stringify(ovr)) }
function dropOverride(id) { localStorage.removeItem(OVR_PREFIX + id) }

// ── Daftar invoice (riwayat) ──────────────────────────────────────────────────
// Basis tanggal: 'created_at' (Tgl Invoice — SELURUH invoice tanggal itu, termasuk
// belum bayar) atau 'paid_at' (Tgl Bayar — hanya yang sudah ditutup hari itu).
const kwDate      = ref(todayStr())
const kwDateField = ref('created_at')
const kwSearch    = ref('')
const kwList      = ref([])
const kwLoading   = ref(false)

const DATE_FIELDS = [
  { value: 'created_at', label: 'Tgl Invoice' },
  { value: 'paid_at',    label: 'Tgl Bayar' },
]

async function loadKwList() {
  if (!canKasir.value) return
  kwLoading.value = true
  try {
    const { data } = await kasirApi.invoiceList({
      tanggal: kwDate.value || todayStr(),
      date_field: kwDateField.value,
      search: kwSearch.value.trim() || undefined,
      per_page: 300,
    })
    const payload = data.data
    kwList.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (e) {
    alert('Gagal memuat invoice: ' + (e.response?.data?.message ?? e.message))
    kwList.value = []
  } finally {
    kwLoading.value = false
  }
}

// ── Invoice terpilih + form override ─────────────────────────────────────────
const selId        = ref(null)
const receiptData  = ref(null)     // hasil generateReceipt (data asli backend)
const kwBusy       = ref(false)
const kwMsg        = ref('')
const printData    = ref(null)     // data yang diteruskan ke <KwitansiPrint>
const hasOverride  = ref(false)
const asli = reactive({ date: '', visit_date: '', paid_at: '', payment_method: '' })
const ovr  = reactive({ date: '', visit_date: '', paid_at: '', payment_method: '' })

function metodeLabel(code) {
  return PAY_METHODS.find((m) => m.value === code)?.label ?? (code || '—')
}

async function selectKw(row) {
  if (!row?.id) return
  selId.value = row.id
  kwMsg.value = ''
  receiptData.value = null
  kwBusy.value = true
  try {
    const { data } = await kasirApi.cetakInvoice(row.id)
    const rec = data.data
    receiptData.value = rec
    // Nilai asli (apa adanya dari backend) untuk referensi "Asli: …".
    asli.date           = rec.invoice?.date ?? ''
    asli.visit_date     = rec.invoice?.visit_date ?? ''
    asli.paid_at        = rec.invoice?.paid_at ?? ''
    asli.payment_method = rec.invoice?.payment_method ?? ''
    // Prefill form dari override tersimpan (bila ada) atau dari nilai asli.
    const saved = loadOverride(row.id)
    hasOverride.value = !!saved
    ovr.date           = dmyToInput(saved?.date ?? asli.date)
    ovr.visit_date     = dmyToInput(saved?.visit_date ?? asli.visit_date)
    ovr.paid_at        = dmyhmToInput(saved?.paid_at ?? asli.paid_at)
    ovr.payment_method = saved?.payment_method ?? asli.payment_method ?? ''
  } catch (e) {
    alert('Gagal memuat kwitansi: ' + (e.response?.data?.message ?? e.message))
    selId.value = null
  } finally {
    kwBusy.value = false
  }
}

// Bentuk override (format tampilan dd/mm/yyyy) dari nilai form saat ini.
function currentOverride() {
  return {
    date:           inputToDmy(ovr.date),
    visit_date:     inputToDmy(ovr.visit_date),
    paid_at:        inputToDmyHm(ovr.paid_at),
    payment_method: ovr.payment_method || null,
  }
}

// Terapkan override ke salinan data kwitansi (tak mengubah receiptData asli).
function applyOverride(base) {
  const d = JSON.parse(JSON.stringify(base))
  const o = currentOverride()
  if (!d.invoice) d.invoice = {}
  if (o.date)           d.invoice.date           = o.date
  if (o.visit_date)     d.invoice.visit_date     = o.visit_date
  if (o.paid_at)        d.invoice.paid_at        = o.paid_at
  if (o.payment_method) d.invoice.payment_method = o.payment_method
  return d
}

function saveOverride() {
  if (!selId.value) return
  persistOverride(selId.value, currentOverride())
  hasOverride.value = true
  kwMsg.value = 'Override cetak tersimpan di perangkat ini.'
}

function resetOverride() {
  if (!selId.value) return
  dropOverride(selId.value)
  hasOverride.value = false
  ovr.date           = dmyToInput(asli.date)
  ovr.visit_date     = dmyToInput(asli.visit_date)
  ovr.paid_at        = dmyhmToInput(asli.paid_at)
  ovr.payment_method = asli.payment_method ?? ''
  kwMsg.value = 'Dikembalikan ke nilai asli.'
}

// Cetak: simpan override dulu (agar reprint konsisten), lalu render & print.
async function cetakKw() {
  if (!receiptData.value) return
  saveOverride()
  printData.value = applyOverride(receiptData.value)
  await nextTick()
  setTimeout(() => window.print(), 80)
}

function openKwTab() {
  activeTab.value = 'kwitansi'
  if (!kwList.value.length) loadKwList()
}

onMounted(() => { loadList(); loadClinicCode() })
</script>

<template>
  <div class="pg-wrap">
    <header class="pg-header">
      <div>
        <h1>Pengaturan</h1>
        <p class="pg-sub">Atur tampilan aplikasi, penomoran dokumen, dan cetak kwitansi.</p>
      </div>
    </header>

    <!-- ── Tab ───────────────────────────────────────────────────────────── -->
    <nav class="tabs">
      <button class="tab" :class="{ active: activeTab === 'umum' }" @click="activeTab = 'umum'">Umum</button>
      <button v-if="canKasir" class="tab" :class="{ active: activeTab === 'kwitansi' }" @click="openKwTab">Kwitansi</button>
    </nav>

    <!-- ════════════════════════ TAB: UMUM ════════════════════════════════ -->
    <div v-show="activeTab === 'umum'">
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
    <!-- ════════════════════════ /TAB: UMUM ═══════════════════════════════ -->

    <!-- ════════════════════════ TAB: KWITANSI ════════════════════════════ -->
    <div v-if="activeTab === 'kwitansi'" class="kw-tab">
      <div class="sec-head">
        <h2>Cetak Kwitansi</h2>
        <p class="sec-sub">
          Telusuri invoice yang sudah dibayar, lalu ubah <strong>tampilan cetak</strong>
          (Tgl Invoice / Tgl Bayar / Tgl Kunjungan / Metode Bayar). Data asli di sistem
          tidak berubah; perubahan hanya untuk tampilan/print dan tersimpan di perangkat ini.
        </p>
      </div>

      <div class="kw-grid">
        <!-- Kolom kiri: daftar invoice -->
        <div class="kw-list-col card">
          <div class="kw-filter">
            <label class="fld">
              <span>Berdasarkan</span>
              <select v-model="kwDateField" @change="loadKwList">
                <option v-for="f in DATE_FIELDS" :key="f.value" :value="f.value">{{ f.label }}</option>
              </select>
            </label>
            <label class="fld">
              <span>Tanggal</span>
              <input v-model="kwDate" type="date" @change="loadKwList" />
            </label>
            <label class="fld kw-grow">
              <span>Cari (nama / no. RM / no. invoice)</span>
              <input v-model="kwSearch" type="text" placeholder="opsional — ketik lalu Enter" @keyup.enter="loadKwList" />
            </label>
            <button class="btn-primary kw-search-btn" :disabled="kwLoading" @click="loadKwList">
              {{ kwLoading ? 'Memuat…' : 'Muat' }}
            </button>
          </div>

          <p v-if="kwLoading" class="muted">Memuat…</p>
          <p v-else-if="!kwList.length" class="muted">Tidak ada invoice pada tanggal/kriteria ini.</p>

          <template v-else>
            <p class="kw-count">{{ kwList.length }} kwitansi pada tanggal ini</p>
            <ul class="kw-list">
              <li
                v-for="row in kwList"
                :key="row.id"
                class="kw-item"
                :class="{ active: selId === row.id }"
                @click="selectKw(row)"
              >
                <div class="kw-item-main">
                  <span class="kw-item-name">{{ row.visit?.patient?.name ?? '—' }}</span>
                  <span class="kw-item-inv">{{ row.invoice_number }}</span>
                </div>
                <div class="kw-item-meta">
                  <span>RM {{ row.visit?.patient?.no_rm ?? '—' }}</span>
                  <span class="kw-chip" :class="row.status === 'PAID' ? 'paid' : 'unpaid'">{{ row.status === 'PAID' ? 'Lunas' : 'Belum' }}</span>
                  <span class="kw-item-amt">Rp {{ Number(row.total ?? 0).toLocaleString('id-ID') }}</span>
                </div>
              </li>
            </ul>
          </template>
        </div>

        <!-- Kolom kanan: form override -->
        <div class="kw-edit-col card">
          <p v-if="!selId" class="muted kw-placeholder">Pilih invoice di sebelah kiri untuk mengubah tampilan cetaknya.</p>
          <p v-else-if="kwBusy" class="muted kw-placeholder">Memuat kwitansi…</p>

          <template v-else-if="receiptData">
            <div class="kw-edit-head">
              <div>
                <div class="kw-edit-title">{{ receiptData.patient?.name ?? '—' }}</div>
                <div class="kw-edit-sub">{{ receiptData.invoice?.number }} · {{ receiptData.invoice?.is_paid ? 'LUNAS' : 'BELUM LUNAS' }}</div>
              </div>
              <span v-if="hasOverride" class="kw-badge-ovr">● Override aktif</span>
            </div>

            <label class="fld">
              <span>Tgl Invoice</span>
              <input v-model="ovr.date" type="date" />
              <small class="hint">Asli: {{ asli.date || '—' }}</small>
            </label>

            <label class="fld">
              <span>Tgl Kunjungan</span>
              <input v-model="ovr.visit_date" type="date" />
              <small class="hint">Asli: {{ asli.visit_date || '—' }}</small>
            </label>

            <label class="fld">
              <span>Tgl Bayar</span>
              <input v-model="ovr.paid_at" type="datetime-local" />
              <small class="hint">Asli: {{ asli.paid_at || '—' }}</small>
            </label>

            <label class="fld">
              <span>Metode Bayar</span>
              <select v-model="ovr.payment_method">
                <option value="">— (kosongkan)</option>
                <option v-for="m in PAY_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
              <small class="hint">Asli: {{ asli.payment_method ? metodeLabel(asli.payment_method) : '—' }}</small>
            </label>

            <p v-if="kwMsg" class="kw-msg">{{ kwMsg }}</p>

            <div class="kw-actions">
              <button class="btn-ghost danger" :disabled="!hasOverride" @click="resetOverride">Kembalikan Asli</button>
              <button class="btn-ghost" @click="saveOverride">Simpan</button>
              <button class="btn-primary" :disabled="kwBusy" @click="cetakKw">Simpan &amp; Cetak</button>
            </div>
          </template>
        </div>
      </div>
    </div>
    <!-- ════════════════════════ /TAB: KWITANSI ═══════════════════════════ -->

    <!-- Template cetak bersama (teleport ke <body>, hanya muncul saat print) -->
    <KwitansiPrint :data="printData" />
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

/* ── Tab ── */
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); margin-bottom: 0.3rem; }
.tab { background: transparent; border: none; border-bottom: 2px solid transparent; padding: 8px 16px; font-size: 13.5px; font-weight: 600; color: var(--tm); cursor: pointer; margin-bottom: -1px; }
.tab:hover { color: var(--td); }
.tab.active { color: #1763d4; border-bottom-color: #1763d4; }

/* ── Tab Kwitansi ── */
.kw-tab { display: flex; flex-direction: column; gap: 0.9rem; }
.kw-grid { display: grid; grid-template-columns: minmax(320px, 1.2fr) 1fr; gap: 1rem; align-items: start; }
@media (max-width: 860px) { .kw-grid { grid-template-columns: 1fr; } }

.kw-list-col { padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.7rem; }
.kw-filter { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: flex-end; }
.kw-grow { flex: 1 1 180px; }
.kw-search-btn { white-space: nowrap; height: 36px; }

.kw-count { font-size: 11.5px; color: var(--tm); margin: 0; }
.kw-chip { font-size: 10px; font-weight: 700; border-radius: 5px; padding: 1px 6px; }
.kw-chip.paid { color: #15803d; background: #dcfce7; }
.kw-chip.unpaid { color: #b45309; background: #fef3c7; }
.kw-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; max-height: 60vh; overflow-y: auto; }
.kw-item { border: 1px solid var(--gb); border-radius: 9px; padding: 8px 11px; cursor: pointer; transition: border-color 0.12s, background 0.12s; }
.kw-item:hover { background: var(--bs); }
.kw-item.active { border-color: #1763d4; background: #eff5ff; }
.kw-item-main { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
.kw-item-name { font-size: 13px; font-weight: 600; color: var(--td); }
.kw-item-inv { font-family: 'JetBrains Mono', monospace; font-size: 10.5px; color: var(--tm); white-space: nowrap; }
.kw-item-meta { display: flex; justify-content: space-between; gap: 8px; font-size: 11.5px; color: var(--tm); margin-top: 2px; }
.kw-item-amt { font-weight: 600; color: var(--td); }

.kw-edit-col { padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.75rem; }
.kw-placeholder { padding: 1.5rem 0.5rem; text-align: center; }
.kw-edit-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; padding-bottom: 0.6rem; border-bottom: 1px solid var(--gb); }
.kw-edit-title { font-size: 15px; font-weight: 700; color: var(--td); }
.kw-edit-sub { font-size: 11.5px; color: var(--tm); font-family: 'JetBrains Mono', monospace; margin-top: 2px; }
.kw-badge-ovr { font-size: 10.5px; font-weight: 700; color: #b45309; background: #fef3c7; border-radius: 6px; padding: 3px 8px; white-space: nowrap; }
.kw-msg { font-size: 12px; color: #15803d; margin: 0; }
.kw-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 0.4rem; flex-wrap: wrap; }
</style>
