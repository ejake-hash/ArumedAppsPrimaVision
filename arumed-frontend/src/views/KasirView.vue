<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { kasirApi } from '@/services/api'

// ─── Antrean Kasir ──────────────────────────────────────────────────────────
const queue        = ref([])
const queueLoading = ref(false)
const qTab         = ref('all')
const qSearch      = ref('')

function ptypeOf(q) {
  const g = (q.visit?.guarantor_type ?? '').toUpperCase()
  if (g === 'BPJS') return 'bpjs'
  if (g === 'ASURANSI' || g === 'PERUSAHAAN') return 'asn'
  return 'umum'
}
function isLunas(q) {
  return q.status === 'COMPLETED' || q.visit?.billing_invoice?.status === 'PAID'
}
function formatTime(ts) {
  if (!ts) return '--:--'
  const d = new Date(ts)
  return Number.isNaN(d.getTime()) ? '--:--'
    : d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false })
}

const filtQ = computed(() => {
  let l = queue.value
  if (qTab.value === 'selesai') l = l.filter((q) => isLunas(q))
  else {
    l = l.filter((q) => !isLunas(q))
    if (qTab.value === 'bpjs')      l = l.filter((q) => ptypeOf(q) === 'bpjs')
    else if (qTab.value === 'umum') l = l.filter((q) => ptypeOf(q) !== 'bpjs')
  }
  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    l = l.filter((q) =>
      (q.visit?.patient?.name ?? '').toLowerCase().includes(s) ||
      (q.queue_number ?? '').toLowerCase().includes(s) ||
      (q.visit?.patient?.no_rm ?? '').toLowerCase().includes(s),
    )
  }
  return l
})

const selesaiCount = computed(() => queue.value.filter((q) => isLunas(q)).length)

async function fetchQueue() {
  queueLoading.value = true
  try {
    const { data } = await kasirApi.antrian()
    queue.value = data.data ?? []
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat antrean')
  } finally {
    queueLoading.value = false
  }
}

// ─── Detail invoice terpilih ────────────────────────────────────────────────
const pg          = ref('tagihan')
const selQ        = ref(null)    // queue item dipilih
const selInv      = ref(null)    // full BillingInvoice + items
const selInvLoading = ref(false)

async function pickP(q) {
  selQ.value         = q
  selInv.value       = null
  selPM.value        = null
  uangDibayar.value  = 0
  showMixed.value    = false
  editTagihan.value  = false
  if (!q.visit?.id) return

  selInvLoading.value = true
  try {
    // Coba ambil invoice yang sudah ada
    let { data } = await kasirApi.showInvoice(q.visit.id)
    if (!data.data) {
      // Belum ada → generate dari sumber-sumber visit
      try {
        ({ data } = await kasirApi.generateInvoice(q.visit.id))
      } catch (err) {
        toast('w', err.response?.data?.message ?? 'Gagal generate invoice')
        return
      }
    }
    selInv.value = data.data
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat tagihan')
  } finally {
    selInvLoading.value = false
  }
}

async function callPt(q, e) {
  e.stopPropagation()
  try {
    const { data } = await kasirApi.panggilAntrian(q.id)
    Object.assign(q, data.data)
    toast('i', `Memanggil ${q.visit?.patient?.name ?? ''} (${q.queue_number ?? ''})`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memanggil pasien')
  }
}

// ─── Hitungan tagihan ───────────────────────────────────────────────────────
const subtotal = () => Number(selInv.value?.subtotal ?? 0)
const discountAmount = computed(() => Number(selInv.value?.discount ?? 0))
const taxAmount      = computed(() => Number(selInv.value?.tax ?? 0))
const totalTagihan   = computed(() => Number(selInv.value?.total ?? 0))
const paidAmount     = computed(() => Number(selInv.value?.paid_amount ?? 0))
const sisaTagihan    = computed(() => Math.max(0, totalTagihan.value - paidAmount.value))

function bayar() { return sisaTagihan.value }

// ─── Metode pembayaran ──────────────────────────────────────────────────────
// `code` cocok dengan enum backend: CASH | CREDIT_CARD | TRANSFER | BPJS.
// QRIS dipetakan ke TRANSFER karena belum ada enum khusus.
const payMethods = [
  { id: 1, code: 'CASH',        name: 'Tunai',        icon: '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/>', bg: 'var(--gl)', color: 'var(--ga)' },
  { id: 2, code: 'CREDIT_CARD', name: 'Debit/Kredit', icon: '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>', bg: 'var(--ib)', color: 'var(--it)' },
  { id: 3, code: 'TRANSFER',    name: 'Transfer',     icon: '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/>', bg: 'var(--pb)', color: 'var(--pt)' },
  { id: 4, code: 'TRANSFER',    name: 'QRIS',         icon: '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>', bg: 'var(--wb)', color: 'var(--wt)' },
]
const selPM = ref(null)
const uangDibayar = ref(0)
const paying = ref(false)

// Mixed / campuran payment
const showMixed = ref(false)
const mixedAmounts = ref({ 1: 0, 2: 0, 3: 0, 4: 0 })
const mixedTotal = computed(() => Object.values(mixedAmounts.value).reduce((a, b) => a + (Number(b) || 0), 0))

function toggleMixed() {
  showMixed.value = !showMixed.value
  if (showMixed.value) {
    selPM.value = 99
    mixedAmounts.value = { 1: 0, 2: 0, 3: 0, 4: 0 }
    if (selInv.value) mixedAmounts.value[1] = bayar()
  } else {
    selPM.value = null
  }
}

// ─── Edit tagihan (CRUD billing items) ─────────────────────────────────────
const editTagihan = ref(false)
const newItem = ref({ description: '', item_type: 'TINDAKAN', quantity: 1, unit_price: 0 })
const itemTypes = ['REGISTRASI', 'TINDAKAN', 'OBAT', 'IOL', 'BHP', 'LAINNYA']

async function removeItem(item) {
  if (!item?.id) return
  try {
    await kasirApi.deleteItem(item.id)
    selInv.value.items = selInv.value.items.filter((i) => i.id !== item.id)
    await refreshInvoice()
    toast('s', 'Item dihapus')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menghapus item')
  }
}

async function addItem() {
  if (!selInv.value?.id) return
  if (!newItem.value.description.trim()) { toast('w', 'Keterangan item wajib diisi'); return }
  try {
    const payload = {
      item_type:   newItem.value.item_type,
      description: newItem.value.description,
      quantity:    Number(newItem.value.quantity) || 1,
      unit_price:  Number(newItem.value.unit_price) || 0,
    }
    await kasirApi.storeItem(selInv.value.id, payload)
    await refreshInvoice()
    newItem.value = { description: '', item_type: 'TINDAKAN', quantity: 1, unit_price: 0 }
    toast('s', 'Item ditambahkan')
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menambahkan item')
  }
}

async function refreshInvoice() {
  if (!selQ.value?.visit?.id) return
  try {
    const { data } = await kasirApi.showInvoice(selQ.value.visit.id)
    if (data.data) selInv.value = data.data
  } catch { /* ignore */ }
}

// ─── Proses pembayaran ──────────────────────────────────────────────────────
async function prosesBayar() {
  if (!selInv.value) { toast('w', 'Tagihan belum siap'); return }
  if (!selPM.value)  { toast('w', 'Pilih metode pembayaran terlebih dahulu'); return }
  const total = bayar()
  if (selPM.value === 1 && uangDibayar.value < total) { toast('w', 'Uang diterima kurang'); return }
  if (selPM.value === 99 && mixedTotal.value < total) { toast('w', 'Total pembayaran campuran masih kurang'); return }

  paying.value = true
  try {
    // Pastikan invoice FINALIZED sebelum diproses
    if (selInv.value.status === 'DRAFT') {
      const { data } = await kasirApi.finalizeInvoice(selInv.value.id)
      selInv.value = data.data
    }

    if (selPM.value === 99) {
      // Mixed → kirim satu bayar per metode yang nominalnya > 0
      for (const pm of payMethods) {
        const amount = Number(mixedAmounts.value[pm.id] || 0)
        if (amount <= 0) continue
        const { data } = await kasirApi.bayarInvoice(selInv.value.id, {
          paid_amount:    amount,
          payment_method: pm.code,
        })
        selInv.value = data.data
        if (selInv.value.status === 'PAID') break
      }
    } else {
      const pm = payMethods.find((p) => p.id === selPM.value)
      const amount = selPM.value === 1 ? Math.min(uangDibayar.value, total) : total
      const { data } = await kasirApi.bayarInvoice(selInv.value.id, {
        paid_amount:    amount,
        payment_method: pm.code,
      })
      selInv.value = data.data
    }

    if (selInv.value.status === 'PAID') {
      // Sinkronkan status antrean lokal (backend sudah set COMPLETED)
      const localQ = queue.value.find((q) => q.id === selQ.value?.id)
      if (localQ) localQ.status = 'COMPLETED'
      toast('s', `Pembayaran lunas — invoice ${selInv.value.invoice_number}`)
    } else {
      toast('i', `Pembayaran sebagian — tersisa Rp ${sisaTagihan.value.toLocaleString('id-ID')}`)
    }
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memproses pembayaran')
  } finally {
    paying.value = false
  }
}

// ─── History pembayaran ─────────────────────────────────────────────────────
const history       = ref([])
const historyLoading = ref(false)
const hSearch       = ref('')
const hFilterPtype  = ref('')
const hFilterMetode = ref('')

async function fetchHistory() {
  historyLoading.value = true
  try {
    const { data } = await kasirApi.invoiceList({ status: 'PAID', per_page: 50 })
    const payload  = data.data
    history.value  = Array.isArray(payload) ? payload : (payload?.data ?? [])
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memuat history')
  } finally {
    historyLoading.value = false
  }
}

function metodeLabel(code) {
  return ({ CASH: 'Tunai', CREDIT_CARD: 'Debit/Kredit', TRANSFER: 'Transfer', BPJS: 'BPJS' })[code] ?? (code ?? '—')
}
function ptypeOfHistory(h) {
  const g = (h.visit?.guarantor_type ?? '').toUpperCase()
  if (g === 'BPJS') return 'bpjs'
  if (g === 'ASURANSI' || g === 'PERUSAHAAN') return 'asn'
  return 'umum'
}

const histFiltered = computed(() =>
  history.value.filter((h) => {
    const name = h.visit?.patient?.name ?? ''
    const inv  = h.invoice_number ?? ''
    const metode = metodeLabel(h.payment_method)
    return (
      (!hSearch.value || name.toLowerCase().includes(hSearch.value.toLowerCase()) || inv.toLowerCase().includes(hSearch.value.toLowerCase())) &&
      (!hFilterPtype.value || ptypeOfHistory(h) === hFilterPtype.value) &&
      (!hFilterMetode.value || metode === hFilterMetode.value)
    )
  }),
)

// ─── Lifecycle / polling ────────────────────────────────────────────────────
let _poll = null
onMounted(() => {
  fetchQueue()
  fetchHistory()
  _poll = setInterval(fetchQueue, 30_000)
})
onUnmounted(() => { if (_poll) clearInterval(_poll) })

// ─── Toast ──────────────────────────────────────────────────────────────────
const toasts = ref([])
let tid = 0
function toast(type, msg) {
  const id = ++tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3000)
}
</script>

<template>
  <div class="kasir">
    <div class="grid">
      <!-- LEFT QUEUE -->
      <aside class="qp">
        <div class="qph">
          <div class="qpht"><span>Antrean Kasir</span><span class="live-pill">LIVE</span></div>
          <div class="qst">
            <div class="qsc"><div class="qsc-v">{{ queue.length }}</div><div class="qsc-l">Total</div></div>
            <div class="qsc w"><div class="qsc-v">{{ queue.filter((q) => !isLunas(q)).length }}</div><div class="qsc-l">Belum Bayar</div></div>
            <div class="qsc d"><div class="qsc-v">{{ selesaiCount }}</div><div class="qsc-l">Lunas</div></div>
          </div>
        </div>
        <div class="qtabs">
          <button :class="['qtab', qTab === 'all' ? 'a' : '']" @click="qTab = 'all'">Semua</button>
          <button :class="['qtab', qTab === 'bpjs' ? 'a' : '']" @click="qTab = 'bpjs'">BPJS</button>
          <button :class="['qtab', qTab === 'umum' ? 'a' : '']" @click="qTab = 'umum'">Umum/Asn</button>
          <button :class="['qtab', qTab === 'selesai' ? 'a' : '']" @click="qTab = 'selesai'">
            Selesai
            <span v-if="selesaiCount" class="qtab-ct">{{ selesaiCount }}</span>
          </button>
        </div>
        <div class="qsw"><input v-model="qSearch" class="fi" placeholder="Cari pasien / no..." /></div>
        <div class="ql">
          <div v-if="queueLoading && !queue.length" class="empty-q">Memuat antrean…</div>
          <div v-for="q in filtQ" :key="q.id"
            :class="['qi', selQ && selQ.id === q.id ? 'ac' : '', isLunas(q) ? 'dn' : '']"
            @click="pickP(q)">
            <div :class="['qis', ptypeOf(q)]"></div>
            <div class="qib">
              <div class="qitop">
                <span class="qinum">{{ q.queue_number }}</span>
                <span class="qitime">{{ formatTime(q.called_at ?? q.created_at) }}</span>
              </div>
              <div class="qiname">{{ q.visit?.patient?.name ?? '—' }}</div>
              <div class="qimeta">{{ q.visit?.patient?.no_rm ?? '—' }}</div>
              <div class="qitags">
                <span :class="['qit', ptypeOf(q) === 'bpjs' ? 'qit-b' : ptypeOf(q) === 'asn' ? 'qit-a' : 'qit-u']">
                  {{ ptypeOf(q) === 'bpjs' ? 'BPJS' : ptypeOf(q) === 'asn' ? 'Asn' : 'Umum' }}
                </span>
                <span v-if="isLunas(q)" class="qit qit-d">Lunas</span>
                <span v-else class="qit qit-w">Belum Bayar</span>
              </div>
              <div v-if="!isLunas(q)" class="q-actions" @click.stop>
                <button class="q-act call" @click="callPt(q, $event)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                  Panggil
                </button>
              </div>
            </div>
          </div>
          <div v-if="!queueLoading && !filtQ.length" class="empty-q">Tidak ada antrean</div>
        </div>
      </aside>

      <!-- RIGHT -->
      <section class="rp">
        <div class="nvt">
          <button :class="['nt', pg === 'tagihan' ? 'a' : '']" @click="pg = 'tagihan'">
            <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            Tagihan Aktif
          </button>
          <button :class="['nt', pg === 'history' ? 'a' : '']" @click="pg = 'history'">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
            History
          </button>
        </div>

        <!-- TAGIHAN -->
        <div v-if="pg === 'tagihan'">
          <div v-if="!selQ" class="empty-state">
            <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            <p>Pilih pasien dari antrean kasir untuk memproses pembayaran</p>
          </div>
          <div v-else-if="selInvLoading" class="empty-state">
            <p>Memuat tagihan…</p>
          </div>
          <template v-else-if="selInv">
            <!-- Patient header -->
            <div class="pt-banner">
              <div class="pt-av">{{ (selQ.visit?.patient?.name ?? '—').charAt(0) }}</div>
              <div class="pt-info">
                <div class="pt-name">{{ selQ.visit?.patient?.name ?? '—' }}</div>
                <div class="pt-meta">{{ selQ.visit?.patient?.no_rm ?? '—' }} · {{ selInv.invoice_number ?? 'Invoice' }}</div>
                <div class="pt-tags">
                  <span :class="['ptg', ptypeOf(selQ) === 'bpjs' ? 'ptg-b' : ptypeOf(selQ) === 'asn' ? 'ptg-a' : 'ptg-u']">
                    {{ ptypeOf(selQ) === 'bpjs' ? 'BPJS' : ptypeOf(selQ) === 'asn' ? 'Asuransi' : 'Umum' }}
                  </span>
                  <span v-if="selInv.status === 'PAID'" class="ptg ptg-ok">LUNAS</span>
                  <span v-else-if="selInv.status === 'PARTIALLY_PAID'" class="ptg ptg-ok">Bayar Sebagian</span>
                </div>
              </div>
              <div class="pt-total">
                <div class="pt-total-v">Rp {{ sisaTagihan.toLocaleString('id-ID') }}</div>
                <div class="pt-total-l">Sisa Bayar</div>
              </div>
            </div>

            <div class="layout">
              <!-- LEFT: detail tagihan -->
              <div class="col-left">
                <div v-if="selInv.notes" class="note-warning">
                  <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                  <span><b>Catatan:</b> {{ selInv.notes }}</span>
                </div>

                <div class="card">
                  <div class="card-head">
                    <div class="card-head-title">
                      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                      Rincian Tagihan
                    </div>
                    <div style="display:flex;gap:.4rem">
                      <button :class="['btn btn-sm', editTagihan ? 'btn-primary' : 'btn-secondary']"
                        :disabled="['PAID','CANCELLED'].includes(selInv.status)"
                        @click="editTagihan = !editTagihan">
                        <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        {{ editTagihan ? 'Selesai Edit' : 'Edit Tagihan' }}
                      </button>
                      <button class="btn btn-sm btn-secondary" @click="toast('i', 'Cetak rincian tagihan')">
                        <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Cetak
                      </button>
                    </div>
                  </div>
                  <table class="tbl">
                    <thead>
                      <tr>
                        <th>Keterangan</th>
                        <th>Kategori</th>
                        <th class="num">Qty</th>
                        <th class="num">Harga Satuan</th>
                        <th class="num">Total</th>
                        <th v-if="editTagihan"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="item in (selInv.items ?? [])" :key="item.id">
                        <td class="strong">{{ item.description }}</td>
                        <td><span :class="['kat-pill', `kat-${(item.item_type || '').toLowerCase()}`]">{{ item.item_type }}</span></td>
                        <td class="num">{{ item.quantity }}</td>
                        <td class="num">Rp {{ Number(item.unit_price).toLocaleString('id-ID') }}</td>
                        <td class="num strong">Rp {{ Number(item.total_price).toLocaleString('id-ID') }}</td>
                        <td v-if="editTagihan">
                          <button class="del-btn" @click="removeItem(item)" :disabled="(selInv.items?.length ?? 0) <= 1">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                          </button>
                        </td>
                      </tr>
                      <tr v-if="editTagihan" class="add-item-row">
                        <td><input v-model="newItem.description" class="fi tbl-fi" placeholder="Keterangan item..." /></td>
                        <td><select v-model="newItem.item_type" class="fi tbl-fi tbl-select"><option v-for="t in itemTypes" :key="t">{{ t }}</option></select></td>
                        <td class="num"><input v-model.number="newItem.quantity" type="number" min="1" class="fi tbl-fi tbl-num" /></td>
                        <td class="num"><input v-model.number="newItem.unit_price" type="number" min="0" class="fi tbl-fi tbl-num" /></td>
                        <td class="num">Rp {{ ((newItem.quantity||0) * (newItem.unit_price||0)).toLocaleString('id-ID') }}</td>
                        <td>
                          <button class="add-item-btn" @click="addItem">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                          </button>
                        </td>
                      </tr>
                      <tr v-if="!(selInv.items ?? []).length"><td colspan="6" class="empty-row">Belum ada item</td></tr>
                    </tbody>
                  </table>
                  <div class="tbl-foot">
                    <div class="totals">
                      <div class="row"><span>Subtotal</span><span class="num">Rp {{ subtotal().toLocaleString('id-ID') }}</span></div>
                      <div v-if="discountAmount" class="row red"><span>Diskon</span><span class="num">−Rp {{ discountAmount.toLocaleString('id-ID') }}</span></div>
                      <div v-if="taxAmount" class="row"><span>Pajak</span><span class="num">Rp {{ taxAmount.toLocaleString('id-ID') }}</span></div>
                      <div class="row"><span>Total Tagihan</span><span class="num">Rp {{ totalTagihan.toLocaleString('id-ID') }}</span></div>
                      <div v-if="paidAmount" class="row blue"><span>Sudah Dibayar</span><span class="num">−Rp {{ paidAmount.toLocaleString('id-ID') }}</span></div>
                      <div class="row grand"><span>Sisa Bayar</span><span class="num">Rp {{ sisaTagihan.toLocaleString('id-ID') }}</span></div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- RIGHT: aksi (tanpa TTD card) -->
              <div class="col-right">
                <div class="card">
                  <div class="card-head">
                    <div class="card-head-title">
                      <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                      Metode Pembayaran
                    </div>
                    <button :class="['btn btn-sm', showMixed ? 'btn-primary' : 'btn-secondary']" @click="toggleMixed">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/></svg>
                      Campuran
                    </button>
                  </div>
                  <div class="card-body">
                    <!-- Standard single payment -->
                    <template v-if="!showMixed">
                      <div class="pm-grid">
                        <div v-for="pm in payMethods" :key="pm.id" :class="['pm', selPM === pm.id ? 'sel' : '']"
                          @click="selPM = pm.id; uangDibayar = bayar(); showMixed = false">
                          <div class="pm-icon" :style="{ background: pm.bg }">
                            <svg viewBox="0 0 24 24" :stroke="pm.color" v-html="pm.icon"></svg>
                          </div>
                          <span>{{ pm.name }}</span>
                        </div>
                      </div>
                      <div v-if="selPM === 1" class="fg cash-row">
                        <label class="fl">Uang Diterima</label>
                        <input v-model.number="uangDibayar" type="number" class="fi" />
                        <div v-if="uangDibayar >= bayar()" class="kembalian">
                          Kembalian: Rp {{ (uangDibayar - bayar()).toLocaleString('id-ID') }}
                        </div>
                      </div>
                    </template>

                    <!-- Mixed / campuran payment -->
                    <template v-else>
                      <div class="mixed-header">
                        <span class="mixed-lbl">Total Tagihan</span>
                        <span class="mixed-total">Rp {{ bayar().toLocaleString('id-ID') }}</span>
                      </div>
                      <div class="mixed-list">
                        <div v-for="pm in payMethods" :key="pm.id" class="mixed-row">
                          <div class="pm-icon sm" :style="{ background: pm.bg }">
                            <svg viewBox="0 0 24 24" :stroke="pm.color" v-html="pm.icon"></svg>
                          </div>
                          <span class="mixed-name">{{ pm.name }}</span>
                          <input v-model.number="mixedAmounts[pm.id]" type="number" min="0" class="fi mixed-input" placeholder="0" />
                        </div>
                      </div>
                      <div :class="['mixed-status', mixedTotal >= bayar() ? 'ok' : 'warn']">
                        <span>Terbayar: Rp {{ mixedTotal.toLocaleString('id-ID') }}</span>
                        <span v-if="mixedTotal < bayar()">Kurang: Rp {{ (bayar() - mixedTotal).toLocaleString('id-ID') }}</span>
                        <span v-else>Kembalian: Rp {{ (mixedTotal - bayar()).toLocaleString('id-ID') }}</span>
                      </div>
                    </template>

                    <button class="btn btn-success btn-full btn-lg"
                      :disabled="!selPM || (selPM === 1 && uangDibayar < bayar()) || (selPM === 99 && mixedTotal < bayar()) || selInv.status === 'PAID' || selInv.status === 'CANCELLED'"
                      @click="prosesBayar">
                      <div v-if="paying" class="sp"></div>
                      <svg v-else viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                      {{ selInv.status === 'PAID' ? 'Sudah Lunas' : paying ? 'Memproses...' : 'Proses Pembayaran' }}
                    </button>
                    <button v-if="selInv.status === 'PAID'" class="btn btn-secondary btn-full btn-sm" style="margin-top:.35rem" @click="toast('i', `Lihat kwitansi ${selInv.invoice_number}`)">
                      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                      Lihat Kwitansi {{ selInv.invoice_number }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </template>
          <div v-else class="empty-state">
            <p>Tagihan tidak tersedia untuk pasien ini.</p>
          </div>
        </div>

        <!-- HISTORY -->
        <div v-if="pg === 'history'">
          <div class="stat-row">
            <div class="stat-card">
              <div class="stat-icon" style="background: var(--sb)"><svg viewBox="0 0 24 24" stroke="var(--st)"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></div>
              <div><div class="stat-val">{{ history.length }}</div><div class="stat-lbl">Transaksi Hari Ini</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-icon" style="background: var(--gl)"><svg viewBox="0 0 24 24" stroke="var(--ga)"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/></svg></div>
              <div><div class="stat-val">Rp {{ (history.reduce((a, b) => a + Number(b.paid_amount ?? 0), 0) / 1000000).toFixed(2) }}jt</div><div class="stat-lbl">Total Pendapatan</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-icon" style="background: #dbeafe"><svg viewBox="0 0 24 24" stroke="#1e40af"><rect x="3" y="4" width="18" height="18" rx="2"/></svg></div>
              <div><div class="stat-val">{{ history.filter((h) => ptypeOfHistory(h) === 'bpjs').length }}</div><div class="stat-lbl">Pasien BPJS</div></div>
            </div>
            <div class="stat-card">
              <div class="stat-icon" style="background: var(--gl)"><svg viewBox="0 0 24 24" stroke="var(--ga)"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
              <div><div class="stat-val">{{ history.filter((h) => ptypeOfHistory(h) !== 'bpjs').length }}</div><div class="stat-lbl">Umum / Asuransi</div></div>
            </div>
          </div>

          <div class="card">
            <div class="card-head">
              <div class="card-head-title">
                <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                Riwayat Pembayaran
              </div>
              <div class="filter-row">
                <input v-model="hSearch" class="fi" placeholder="Cari pasien/no invoice..." />
                <select v-model="hFilterPtype" class="fi">
                  <option value="">Semua jenis</option>
                  <option value="bpjs">BPJS</option>
                  <option value="umum">Umum</option>
                  <option value="asn">Asuransi</option>
                </select>
                <select v-model="hFilterMetode" class="fi">
                  <option value="">Semua metode</option>
                  <option>Tunai</option><option>Debit/Kredit</option><option>Transfer</option><option>BPJS</option>
                </select>
              </div>
            </div>
            <table class="tbl">
              <thead>
                <tr>
                  <th>No. Invoice</th>
                  <th>Pasien</th>
                  <th>Jenis</th>
                  <th>Metode</th>
                  <th class="num">Total</th>
                  <th>Jam</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="historyLoading && !history.length"><td colspan="6" class="empty-row">Memuat history…</td></tr>
                <tr v-for="h in histFiltered" :key="h.id">
                  <td class="strong">{{ h.invoice_number }}</td>
                  <td>{{ h.visit?.patient?.name ?? '—' }}<div class="muted">{{ h.visit?.patient?.no_rm ?? '—' }}</div></td>
                  <td><span :class="['kat-pill', `kat-${ptypeOfHistory(h)}`]">{{ ptypeOfHistory(h).toUpperCase() }}</span></td>
                  <td>{{ metodeLabel(h.payment_method) }}</td>
                  <td class="num strong">Rp {{ Number(h.paid_amount ?? h.total).toLocaleString('id-ID') }}</td>
                  <td class="muted">{{ formatTime(h.paid_at ?? h.updated_at) }}</td>
                </tr>
                <tr v-if="!historyLoading && !histFiltered.length"><td colspan="6" class="empty-row">Tidak ada transaksi yang cocok</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.kasir { padding: 0; }
.grid { display: grid; grid-template-columns: 280px 1fr; gap: 1rem; align-items: start; }

/* Queue */
.qp { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; }
.qph { padding: 0.7rem 0.85rem; border-bottom: 1px solid var(--gb); }
.qpht { display: flex; align-items: center; justify-content: space-between; font-size: 12.5px; font-weight: 600; color: var(--td); margin-bottom: 0.55rem; }
.live-pill { font-size: 9px; font-weight: 700; padding: 2px 7px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; }
.qst { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
.qsc { background: var(--bs); border: 1px solid var(--gb); border-radius: 7px; padding: 5px; text-align: center; }
.qsc-v { font-size: 15px; font-weight: 700; color: var(--td); line-height: 1; }
.qsc-l { font-size: 8.5px; color: var(--tu); margin-top: 2px; }
.qsc.w .qsc-v { color: var(--wt); }
.qsc.d .qsc-v { color: var(--st); }

.qtabs { display: flex; border-bottom: 1px solid var(--gb); }
.qtab { flex: 1; padding: 7px 4px; font-size: 10px; font-weight: 500; color: var(--tu); cursor: pointer; border: none; background: none; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'DM Sans', sans-serif; display: inline-flex; align-items: center; justify-content: center; gap: 3px; }
.qtab.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.qtab-ct { font-size: 8.5px; font-weight: 700; padding: 0 4px; border-radius: 8px; background: var(--sb); color: var(--st); }

.qsw { padding: 0.45rem 0.55rem; border-bottom: 1px solid var(--gb); }
.fi { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'DM Sans', sans-serif; outline: none; color: var(--td); }
.fi:focus { border-color: var(--ga); background: #fff; box-shadow: 0 0 0 3px rgba(31, 125, 74, 0.09); }

.ql { flex: 1; overflow-y: auto; padding: 0.35rem 0.45rem; max-height: calc(100vh - 280px); }
.qi { display: flex; gap: 0; padding: 0; background: var(--bc); border: 1.5px solid var(--gb); border-radius: 8px; margin-bottom: 4px; overflow: hidden; cursor: pointer; transition: all 0.14s; }
.qi:hover { border-color: var(--lm); }
.qi.ac { border-color: var(--ga); background: var(--gl); }
.qi.dn { opacity: 0.45; }
.qis { width: 3px; flex-shrink: 0; }
.qis.bpjs { background: #3b82f6; }
.qis.umum { background: var(--lm); }
.qis.asn { background: var(--pt); }
.qib { flex: 1; padding: 6px 8px; min-width: 0; }
.qitop { display: flex; justify-content: space-between; margin-bottom: 2px; }
.qinum { font-weight: 700; font-size: 12.5px; color: var(--ga); }
.qitime { font-size: 9px; color: var(--tu); }
.qiname { font-size: 11.5px; font-weight: 500; color: var(--td); }
.qimeta { font-size: 9.5px; color: var(--tu); margin-top: 1px; }
.qitags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; }
.qit { font-size: 8.5px; font-weight: 700; padding: 1px 5px; border-radius: 4px; }
.qit-b { background: #dbeafe; color: #1e40af; }
.qit-u { background: var(--gl); color: var(--ga); }
.qit-a { background: var(--pb); color: var(--pt); }
.qit-sign { background: var(--sb); color: var(--st); }
.qit-d { background: var(--sb); color: var(--st); }
.qit-w { background: var(--wb); color: var(--wt); }
.empty-q { text-align: center; padding: 1.5rem; font-size: 11px; color: var(--th); }

/* Panggil / Lewati */
.q-actions { display: flex; gap: 3px; margin-top: 5px; padding-top: 4px; border-top: 1px dashed var(--gb); }
.q-act { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; font-size: 9.5px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'DM Sans',sans-serif; transition: all .12s; background: none; }
.q-act svg { width: 9px; height: 9px; }
.q-act.call { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.q-act.call:hover { background: var(--ga); color: #fff; }
.q-act.skip { color: var(--tu); border-color: var(--gb); }
.q-act.skip:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

/* Right */
.rp { display: flex; flex-direction: column; gap: 0.75rem; }
.nvt { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); padding: 0 0.5rem; }
.nt { padding: 0.6rem 1rem; font-size: 12px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'DM Sans', sans-serif; display: inline-flex; align-items: center; gap: 6px; }
.nt:hover { color: var(--td); }
.nt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.nt svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.empty-state { padding: 4rem 2rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 0.85rem; color: var(--th); text-align: center; }
.empty-state svg { width: 56px; height: 56px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.empty-state p { font-size: 13px; }

.pt-banner { background: linear-gradient(135deg, var(--gm), var(--gd)); color: #fff; padding: 0.85rem 1.1rem; border-radius: 12px; display: flex; align-items: center; gap: 0.85rem; margin-bottom: 0.85rem; }
.pt-av { width: 46px; height: 46px; border-radius: 50%; background: rgba(138, 191, 68, 0.2); border: 2px solid rgba(138, 191, 68, 0.3); color: var(--lm); font-size: 18px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-family: 'DM Serif Display', serif; }
.pt-info { flex: 1; min-width: 0; }
.pt-name { font-family: 'DM Serif Display', serif; font-size: 18px; line-height: 1.1; }
.pt-meta { font-size: 11px; color: rgba(255, 255, 255, 0.6); margin-top: 3px; }
.pt-tags { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
.ptg { font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 20px; }
.ptg-b { background: rgba(147, 197, 253, 0.2); color: #93c5fd; border: 1px solid rgba(147, 197, 253, 0.2); }
.ptg-a { background: rgba(217, 70, 239, 0.18); color: #f0abfc; border: 1px solid rgba(217, 70, 239, 0.25); }
.ptg-u { background: rgba(138, 191, 68, 0.2); color: var(--lm); border: 1px solid rgba(138, 191, 68, 0.25); }
.ptg-ok { background: rgba(134, 239, 172, 0.2); color: var(--sbd); border: 1px solid rgba(134, 239, 172, 0.25); }
.pt-total { text-align: right; flex-shrink: 0; }
.pt-total-v { font-size: 22px; font-weight: 700; color: var(--lm); font-variant-numeric: tabular-nums; line-height: 1; }
.pt-total-l { font-size: 9.5px; color: rgba(255, 255, 255, 0.45); text-transform: uppercase; letter-spacing: 0.08em; margin-top: 4px; }

.layout { display: grid; grid-template-columns: 1fr 340px; gap: 0.85rem; }
.col-right { display: flex; flex-direction: column; gap: 0.7rem; }

.note-warning { display: flex; gap: 8px; align-items: flex-start; padding: 9px 13px; background: var(--wb); border: 1px solid var(--wbd); border-radius: 9px; color: var(--wt); font-size: 11.5px; margin-bottom: 0.7rem; }
.note-warning svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }

.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.card-head { padding: 0.7rem 1.1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-body { padding: 1rem; }

.tbl { width: 100%; border-collapse: collapse; }
.tbl th { font-size: 10px; font-weight: 600; color: var(--tu); letter-spacing: 0.06em; text-transform: uppercase; padding: 9px 13px; border-bottom: 1px solid var(--gb); text-align: left; }
.tbl td { padding: 9px 13px; border-bottom: 1px solid rgba(0, 0, 0, 0.03); font-size: 12px; color: var(--td); }
.tbl tr:last-child td { border-bottom: none; }
.tbl tr:hover td { background: var(--bi); }
.tbl .num { text-align: right; font-variant-numeric: tabular-nums; }
.tbl .strong { font-weight: 600; }
.tbl .muted { color: var(--tu); font-size: 10.5px; }
.empty-row { text-align: center !important; color: var(--th); padding: 1.5rem !important; }

.kat-pill { font-size: 9.5px; font-weight: 600; padding: 2px 7px; border-radius: 20px; }
.kat-konsultasi { background: var(--ib); color: var(--it); }
.kat-tindakan { background: var(--wb); color: var(--wt); }
.kat-farmasi { background: var(--gl); color: var(--ga); }
.kat-penunjang { background: var(--pb); color: var(--pt); }
.kat-bpjs { background: #dbeafe; color: #1e40af; }
.kat-umum { background: var(--gl); color: var(--ga); }
.kat-asn { background: var(--pb); color: var(--pt); }

/* Editable table cells */
.tbl-fi { height: 26px; font-size: 11px; padding: 0 6px; border-radius: 5px; }
.tbl-num { width: 80px; text-align: right; }
.tbl-select { font-size: 11px; }
.del-btn { width: 26px; height: 26px; border-radius: 5px; border: 1px solid var(--ebd); background: var(--eb); color: var(--et); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .12s; }
.del-btn:hover:not(:disabled) { background: var(--et); color: #fff; }
.del-btn:disabled { opacity: .35; cursor: not-allowed; }
.del-btn svg { width: 12px; height: 12px; }
.add-item-row td { background: var(--gl); border-top: 1px dashed var(--ga) !important; }
.add-item-btn { width: 26px; height: 26px; border-radius: 5px; border: 1px solid var(--ga); background: var(--ga); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.add-item-btn svg { width: 12px; height: 12px; }

.tbl-foot { padding: 0.75rem 1.1rem; border-top: 2px solid var(--gb); background: var(--bi); }
.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; margin-bottom: 0.6rem; }
.fg { display: flex; flex-direction: column; gap: 4px; }
.fl { font-size: 10px; font-weight: 600; color: var(--tm); letter-spacing: 0.05em; text-transform: uppercase; }
.totals { padding-top: 0.4rem; border-top: 1px solid var(--gb); }
.totals .row { display: flex; justify-content: space-between; font-size: 12px; padding: 3px 0; color: var(--tm); }
.totals .row.blue { color: var(--it); }
.totals .row.red { color: var(--et); }
.totals .row.grand { font-size: 15px; font-weight: 700; color: var(--gd); padding: 6px 0 0; border-top: 1px dashed var(--gb); margin-top: 4px; }

.ttd-pill { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
.ttd-pill.ok { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.ttd-pill.pend { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.ok-note { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 8px; padding: 7px 11px; font-size: 11px; margin-bottom: 0.5rem; }
.warn-note { color: var(--wt); font-size: 10.5px; margin-bottom: 0.5rem; }

/* Mixed payment */
.mixed-header { display: flex; justify-content: space-between; align-items: center; padding: .5rem .6rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; margin-bottom: .55rem; }
.mixed-lbl { font-size: 11px; color: var(--tu); font-weight: 600; }
.mixed-total { font-size: 14px; font-weight: 700; color: var(--gd); }
.mixed-list { display: flex; flex-direction: column; gap: 5px; margin-bottom: .5rem; }
.mixed-row { display: flex; align-items: center; gap: 8px; }
.pm-icon.sm { width: 24px; height: 24px; border-radius: 6px; flex-shrink: 0; }
.pm-icon.sm svg { width: 11px; height: 11px; fill: none; stroke-width: 2; stroke-linecap: round; }
.mixed-name { font-size: 11.5px; color: var(--td); width: 90px; flex-shrink: 0; }
.mixed-input { height: 28px; font-size: 11.5px; text-align: right; }
.mixed-status { display: flex; justify-content: space-between; font-size: 11px; font-weight: 600; padding: 5px 9px; border-radius: 7px; margin-bottom: .55rem; }
.mixed-status.ok { background: var(--sb); color: var(--st); }
.mixed-status.warn { background: var(--wb); color: var(--wt); }

.pm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 0.6rem; }
.pm { display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 9px 6px; border: 1.5px solid var(--gb); border-radius: 9px; cursor: pointer; transition: all 0.14s; background: var(--bs); }
.pm:hover { border-color: var(--lm); }
.pm.sel { border-color: var(--ga); background: var(--gl); }
.pm-icon { width: 26px; height: 26px; border-radius: 7px; display: flex; align-items: center; justify-content: center; }
.pm-icon svg { width: 13px; height: 13px; fill: none; stroke-width: 2; stroke-linecap: round; }
.pm span { font-size: 11px; font-weight: 500; color: var(--td); }
.pm.sel span { color: var(--gd); font-weight: 600; }
.cash-row { margin-bottom: 0.6rem; }
.kembalian { font-size: 11px; color: var(--st); margin-top: 3px; font-weight: 600; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 14px; height: 36px; border-radius: 8px; font-family: 'DM Sans', sans-serif; font-size: 12.5px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; }
.btn-lg { height: 42px; padding: 0 18px; font-size: 13px; font-weight: 600; }
.btn-full { width: 100%; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover { background: var(--gm); }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover:not(:disabled) { background: var(--gm); }
.btn-success:disabled { background: var(--th); cursor: not-allowed; }
.btn-info { background: var(--it); color: #fff; border-color: var(--it); }
.btn-info:hover { background: #1e40af; }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--gd); background: var(--gl); }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.sp { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255, 255, 255, 0.3); border-top-color: #fff; animation: spin 0.7s linear infinite; }

.stat-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.6rem; margin-bottom: 0.75rem; }
.stat-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 0.75rem; display: flex; align-items: center; gap: 9px; }
.stat-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 16px; height: 16px; fill: none; stroke-width: 2; stroke-linecap: round; }
.stat-val { font-size: 16px; font-weight: 700; color: var(--td); line-height: 1; }
.stat-lbl { font-size: 10px; color: var(--tu); margin-top: 2px; }

.filter-row { display: flex; gap: 0.4rem; align-items: center; }
.filter-row .fi { width: 150px; height: 28px; font-size: 11px; }

.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); min-width: 230px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
</style>
