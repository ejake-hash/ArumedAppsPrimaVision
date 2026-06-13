<script setup>
/**
 * StockOpnameView — opname MASSAL stok per lokasi.
 *
 * Tampilkan SELURUH item master (Obat/BHP/IOL) di satu lokasi dalam tabel.
 * Petugas mengisi "Stok Fisik" hasil hitung di gudang; selisih (fisik − sistem)
 * dihitung otomatis.
 *
 * Dua jalur simpan:
 *   1. "Simpan & Buat Berita Acara" (utama) — seluruh baris berselisih direkam
 *      jadi SATU sesi opname (POST /inventori-farmasi/opname-session); server
 *      hitung ulang stok sistem, terapkan delta (reuse stock/opname → FEFO/batch),
 *      simpan detail per item (sistem/fisik/selisih/status/catatan) → bisa dicetak
 *      sebagai Berita Acara & muncul di Laporan "Selisih Opname".
 *   2. Per-baris "Koreksi" cepat — POST /inventori-farmasi/stock/opname (1 item);
 *      tercatat di Audit Log, DI LUAR Berita Acara. Untuk koreksi tunggal kilat.
 *
 * IOL tak didukung opname di backend → tab IOL read-only (kolom fisik dikunci).
 */
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { inventoriStockApi, opnameSessionApi, masterApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('inventori_farmasi.write'))

// ─── Profil klinik (kop Berita Acara) ────────────────────────────────────────
const clinic = ref(null)
const clinicLogoUrl = computed(() => {
  const p = clinic.value?.logo_path
  if (!p) return null
  if (p.startsWith('http')) return p
  const apiBase = import.meta.env.VITE_API_URL ?? '/api/v1'
  return `${apiBase.replace(/\/api\/v\d+\/?$/, '')}/storage/${p}`
})
async function fetchClinic() {
  try {
    const res = await masterApi.profilKlinik.show()
    clinic.value = res.data?.data ?? res.data ?? null
  } catch { /* non-fatal: BA tetap bisa dicetak tanpa kop lengkap */ }
}

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
    _note: '',             // catatan opname per item (opsional)
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
// Stok fisik valid? Kolom kosong/invalid TIDAK dihitung sebagai selisih — mencegah
// stok ter-nol-kan tak sengaja saat input dikosongkan (Number('')=0 → dianggap
// fisik 0 → Simpan men-set stok ke 0). Hanya angka >= 0 yang dihitung. Selaras guard
// `opnameFisik` di FarmasiView yang sudah memperbaiki bug yang sama.
function physicalOf(row) {
  if (row._physical === '' || row._physical === null || row._physical === undefined) return null
  const n = Number(row._physical)
  return Number.isFinite(n) && n >= 0 ? n : null
}
function deltaOf(row) {
  const f = physicalOf(row)
  return f === null ? 0 : f - (Number(row._sys) || 0)
}
function isChanged(row) {
  return physicalOf(row) !== null && Math.abs(deltaOf(row)) > 0.0001
}
const changedRows = computed(() => items.value.filter(isChanged))
const hasChanges = computed(() => changedRows.value.length > 0)

const visibleItems = computed(() =>
  onlyChanged.value ? items.value.filter(isChanged) : items.value
)

function resetRow(row) {
  row._physical = row._sys
  row._note = ''
  row._saved = false
}

// ─── Statistik (kartu Item / Ada Selisih / Lebih / Kurang) ───────────────────
const opnameStats = computed(() => {
  const changed = changedRows.value
  return {
    total:   items.value.length,
    changed: changed.length,
    plus:    changed.filter((r) => deltaOf(r) > 0).length,
    minus:   changed.filter((r) => deltaOf(r) < 0).length,
  }
})

const typeLabel = (t) => TABS.find((x) => x.key === t)?.label ?? t

// ─── Simpan per-baris (koreksi cepat, DI LUAR Berita Acara) ──────────────────
async function saveRow(row) {
  if (!opnameable.value || !canWrite.value) return
  if (!isChanged(row)) return
  const physical = physicalOf(row)
  if (physical === null) return
  row._saving = true
  try {
    const res = await inventoriStockApi.opname({
      item_type: activeTab.value,
      item_id: row.id,
      location: location.value,
      new_qty: physical,
      reason: `Koreksi cepat — ${locationLabel.value}`,
    })
    row._sys = Number(res.data?.data?.after ?? row._physical)
    row._physical = row._sys
    row._note = ''
    row._saved = true
    showToast('s', `${row.name}: koreksi tersimpan`)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan')
  } finally {
    row._saving = false
  }
}

// ─── Simpan sebagai SESI + Berita Acara (jalur utama) ────────────────────────
const opnameDate = ref(new Date().toISOString().slice(0, 10))
const sessionNotes = ref('')

async function saveSession() {
  if (!opnameable.value || !canWrite.value || saving.value) return
  const targets = changedRows.value.filter((r) => physicalOf(r) !== null)
  if (!targets.length) {
    showToast('w', 'Tidak ada selisih untuk disimpan')
    return
  }
  if (!confirm(`Buat Berita Acara opname untuk ${targets.length} item berselisih di ${locationLabel.value}?`)) return

  saving.value = true
  try {
    const payload = {
      location: location.value,
      item_type: activeTab.value,
      opname_date: opnameDate.value,
      notes: sessionNotes.value || null,
      items: targets.map((r) => ({
        item_id: r.id,
        physical_qty: physicalOf(r),
        note: r._note || null,
      })),
    }
    const res = await opnameSessionApi.create(payload)
    const session = res.data?.data ?? null
    sessionNotes.value = ''
    showToast('s', `Berita Acara ${session?.session_number ?? ''} tersimpan`)
    await refresh()           // segarkan stok sistem pasca-apply
    loadHistory()
    if (session) await printBeritaAcara(session)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan Berita Acara')
  } finally {
    saving.value = false
  }
}

// ─── Cetak Berita Acara (Teleport + window.print) ────────────────────────────
const printSession = ref(null)
function waitForLogo() {
  return new Promise((resolve) => {
    const url = clinicLogoUrl.value
    if (!url) return resolve()
    const img = new Image()
    img.onload = resolve
    img.onerror = resolve
    img.src = url
    setTimeout(resolve, 2500)
  })
}
async function printBeritaAcara(session) {
  printSession.value = session
  await nextTick()
  await waitForLogo()
  window.print()
}
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'
const fmtDateTime = (d) => d ? new Date(d).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—'

// ─── Riwayat Berita Acara (filter lokasi + jenis aktif) ──────────────────────
const history = ref([])
const historyOpen = ref(false)
const historyLoading = ref(false)
async function loadHistory() {
  if (!opnameable.value) { history.value = []; return }
  historyLoading.value = true
  try {
    const res = await opnameSessionApi.list({
      location: location.value, item_type: activeTab.value, per_page: 20,
    })
    history.value = res.data?.data ?? []
  } catch { history.value = [] } finally { historyLoading.value = false }
}
async function reprint(id) {
  try {
    const res = await opnameSessionApi.show(id)
    const session = res.data?.data ?? null
    if (session) await printBeritaAcara(session)
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memuat Berita Acara')
  }
}
// Muat ulang riwayat saat lokasi/jenis berubah.
watch([activeTab, location], loadHistory)

onMounted(() => {
  refresh()
  fetchClinic()
  loadHistory()
})
</script>

<template>
  <div class="so-wrap">
    <header class="so-head">
      <div>
        <h2>Stock Opname</h2>
        <p>
          Hitung fisik stok di lapangan lalu isi kolom <strong>Stok Fisik</strong>.
          Tekan <strong>Simpan &amp; Buat Berita Acara</strong> untuk merekam seluruh
          selisih jadi satu dokumen (positif → batch <code>OPNAME-{tgl}</code>,
          negatif → kurangi batch existing FEFO). Tombol <strong>Koreksi</strong> per
          baris untuk perbaikan tunggal kilat (di luar Berita Acara).
        </p>
      </div>
      <button
        v-if="opnameable"
        class="so-btn-ghost so-btn-hist"
        @click="historyOpen = !historyOpen"
      >Riwayat BA {{ historyOpen ? '▲' : '▼' }}</button>
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

    <!-- Riwayat Berita Acara (filter lokasi + jenis aktif) -->
    <div v-if="opnameable && historyOpen" class="so-hist">
      <div class="so-hist-head">
        <strong>Berita Acara — {{ locationLabel }} · {{ typeLabel(activeTab) }}</strong>
        <span v-if="historyLoading" class="so-dim">memuat…</span>
      </div>
      <div v-if="!history.length && !historyLoading" class="so-hist-empty">Belum ada Berita Acara.</div>
      <table v-else class="so-hist-tbl">
        <thead><tr><th>No. BA</th><th>Tanggal</th><th class="r">Item</th><th class="r">Lebih</th><th class="r">Kurang</th><th></th></tr></thead>
        <tbody>
          <tr v-for="s in history" :key="s.id">
            <td><strong>{{ s.session_number }}</strong></td>
            <td>{{ fmtDate(s.opname_date) }}</td>
            <td class="r">{{ s.total_items }}</td>
            <td class="r so-pos">{{ s.total_plus }}</td>
            <td class="r so-neg">{{ s.total_minus }}</td>
            <td class="r"><button class="so-btn-ghost so-btn-xs" @click="reprint(s.id)">Cetak</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Stat cards: Item / Ada Selisih / Lebih / Kurang -->
    <div v-if="opnameable" class="so-kpi-row">
      <div class="so-kpi"><div class="so-kpi-val">{{ opnameStats.total }}</div><div class="so-kpi-lbl">Item</div></div>
      <div class="so-kpi"><div class="so-kpi-val">{{ opnameStats.changed }}</div><div class="so-kpi-lbl">Ada Selisih</div></div>
      <div class="so-kpi"><div class="so-kpi-val so-pos">{{ opnameStats.plus }}</div><div class="so-kpi-lbl">Lebih</div></div>
      <div class="so-kpi"><div class="so-kpi-val so-neg">{{ opnameStats.minus }}</div><div class="so-kpi-lbl">Kurang</div></div>
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
            <th class="col-note" v-if="opnameable && canWrite">Catatan</th>
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
            <td class="col-note" v-if="opnameable && canWrite">
              <input
                v-if="isChanged(row)"
                type="text" maxlength="500"
                class="so-input-note"
                placeholder="Alasan selisih…"
                v-model="row._note"
              />
              <span v-else class="so-dim">—</span>
            </td>
            <td class="r col-aksi" v-if="opnameable && canWrite">
              <button
                class="so-btn-ghost so-btn-xs"
                :disabled="!isChanged(row) || row._saving"
                @click="saveRow(row)"
                title="Koreksi tunggal cepat (di luar Berita Acara)"
              >{{ row._saving ? '…' : 'Koreksi' }}</button>
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
      <div class="so-savebar-fields">
        <span class="so-savebar-info">
          <strong>{{ changedRows.length }}</strong> item berselisih di
          <strong>{{ locationLabel }}</strong>
        </span>
        <label class="so-sb-field">
          <span>Tanggal Opname</span>
          <input type="date" v-model="opnameDate" class="so-sb-date" />
        </label>
        <label class="so-sb-field so-sb-notes">
          <span>Catatan Berita Acara</span>
          <input type="text" v-model="sessionNotes" maxlength="1000" placeholder="opsional…" />
        </label>
      </div>
      <button class="so-btn-primary" :disabled="saving" @click="saveSession">
        {{ saving ? 'Menyimpan…' : `Simpan & Buat Berita Acara (${changedRows.length})` }}
      </button>
    </div>

    <Teleport to="body">
      <div v-if="toast" class="so-toast-wrap">
        <div class="so-toast" :class="`so-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>

    <!-- ===== Cetak Berita Acara — A4 (hanya tampil saat window.print) ===== -->
    <Teleport to="body">
      <div v-if="printSession" id="opname-print-root">
        <div class="ba-sheet">
          <div v-if="clinic?.letterhead_html" class="ba-kop-canon" v-html="clinic.letterhead_html"></div>
          <header v-else class="ba-kop">
            <img v-if="clinicLogoUrl" :src="clinicLogoUrl" alt="Logo" class="ba-logo" />
            <div class="ba-kop-text">
              <div class="ba-clinic">{{ clinic?.clinic_name ?? 'Rumah Sakit' }}</div>
              <div v-if="clinic?.address" class="ba-line">{{ clinic.address }}</div>
              <div class="ba-line">
                <span v-if="clinic?.phone">Telp: {{ clinic.phone }}</span>
                <span v-if="clinic?.email"> · Email: {{ clinic.email }}</span>
              </div>
            </div>
          </header>

          <h1 class="ba-title">BERITA ACARA STOCK OPNAME</h1>

          <table class="ba-meta-tbl">
            <tbody>
              <tr><td class="ba-meta-k">No. Berita Acara</td><td>:</td><td><strong>{{ printSession.session_number }}</strong></td></tr>
              <tr><td class="ba-meta-k">Tanggal Opname</td><td>:</td><td>{{ fmtDate(printSession.opname_date) }}</td></tr>
              <tr><td class="ba-meta-k">Lokasi</td><td>:</td><td>{{ LOCATIONS.find((l) => l.key === printSession.location)?.label ?? printSession.location }}</td></tr>
              <tr><td class="ba-meta-k">Jenis Item</td><td>:</td><td>{{ typeLabel(printSession.item_type) }}</td></tr>
            </tbody>
          </table>

          <table class="ba-items">
            <thead>
              <tr>
                <th style="width:30px">No</th>
                <th style="width:80px">Kode</th>
                <th>Nama Item</th>
                <th style="width:70px" class="r">Sistem</th>
                <th style="width:70px" class="r">Fisik</th>
                <th style="width:70px" class="r">Selisih</th>
                <th style="width:60px" class="c">Status</th>
                <th>Catatan</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(it, i) in (printSession.items || [])" :key="it.id">
                <td class="c">{{ i + 1 }}</td>
                <td>{{ it.item_code || '—' }}</td>
                <td>{{ it.item_name }}</td>
                <td class="r">{{ formatNum(it.system_qty) }}</td>
                <td class="r">{{ formatNum(it.physical_qty) }}</td>
                <td class="r">{{ it.delta > 0 ? '+' : '' }}{{ formatNum(it.delta) }}</td>
                <td class="c">{{ it.status }}</td>
                <td>{{ it.note || '—' }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="8" class="ba-foot">
                  Total {{ printSession.total_items }} item berselisih
                  ({{ printSession.total_plus }} lebih, {{ printSession.total_minus }} kurang)
                </td>
              </tr>
            </tfoot>
          </table>

          <div v-if="printSession.notes" class="ba-notes"><strong>Catatan:</strong> {{ printSession.notes }}</div>

          <div class="ba-sign">
            <div class="ba-sign-box">
              <div>Petugas Opname,</div>
              <div class="ba-sign-esign">
                <div class="ba-esign-check">✓ Direkam secara elektronik</div>
                <div class="ba-esign-meta">{{ fmtDateTime(printSession.applied_at) }} · {{ printSession.session_number }}</div>
              </div>
              <div class="ba-sign-name">{{ printSession.counted_by_name || auth.employeeName || '(.......................)' }}</div>
              <div class="ba-sign-sub">Petugas</div>
            </div>
            <div class="ba-sign-box">
              <div>Mengetahui,</div>
              <div class="ba-sign-space"></div>
              <div class="ba-sign-name">{{ clinic?.director_name || '(.......................)' }}</div>
              <div class="ba-sign-sub">Penanggung Jawab</div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.so-wrap { display: flex; flex-direction: column; gap: 1.25rem; padding: 0.25rem 0.25rem 4.5rem; max-width: 1320px; margin: 0 auto; width: 100%; }

.so-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
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
.so-table { width: 100%; min-width: 1040px; border-collapse: collapse; font-size: 13px; }
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

.so-savebar { position: sticky; bottom: 0.75rem; display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 0.9rem 1.25rem; background: var(--bc); border: 1px solid var(--ga); border-radius: 12px; box-shadow: 0 -4px 18px rgba(15,23,42,0.08); }
.so-savebar-info { font-size: 13px; color: var(--td); white-space: nowrap; align-self: center; }
.so-savebar-fields { display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap; flex: 1 1 auto; }
.so-sb-field { display: flex; flex-direction: column; gap: 3px; font-size: 11px; color: var(--tm); font-weight: 600; }
.so-sb-field input { height: 34px; padding: 0 10px; border: 1px solid var(--gb); border-radius: 8px; font-size: 13px; font-weight: 400; }
.so-sb-field input:focus { outline: none; border-color: var(--ga); }
.so-sb-notes { flex: 1 1 220px; min-width: 180px; }
.so-sb-date { width: 150px; }

/* Stat cards */
.so-kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.so-kpi { background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; padding: 11px 14px; }
.so-kpi-val { font-size: 22px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.so-kpi-lbl { font-size: 11px; color: var(--tm); margin-top: 2px; }
.so-pos { color: #15803d; }
.so-neg { color: #b91c1c; }

/* Catatan per item */
.col-note { min-width: 180px; }
.so-input-note { width: 100%; min-width: 160px; height: 32px; padding: 0 9px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; }
.so-input-note:focus { outline: none; border-color: var(--ga); }

/* Tombol Riwayat di header */
.so-btn-hist { white-space: nowrap; }

/* Panel Riwayat Berita Acara */
.so-hist { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.85rem 1.1rem; }
.so-hist-head { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--td); margin-bottom: 8px; }
.so-hist-empty { font-size: 12.5px; color: var(--tm); padding: 6px 2px; }
.so-hist-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.so-hist-tbl th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--tu); padding: 6px 10px; border-bottom: 1px solid var(--gb); }
.so-hist-tbl td { padding: 7px 10px; border-bottom: 1px solid var(--gb); }
.so-hist-tbl th.r, .so-hist-tbl td.r { text-align: right; }
.so-hist-tbl tr:last-child td { border-bottom: none; }

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
  .so-savebar-fields { width: 100%; }
  .so-toast-wrap { left: 1rem; right: 1rem; }
  .so-toast { min-width: 0; }
  .so-kpi-row { grid-template-columns: repeat(2, 1fr); }
}
</style>

<!-- Style cetak Berita Acara (NON-scoped agar @media print bisa sembunyikan app). -->
<style>
#opname-print-root { display: none; }

@media print {
  body > *:not(#opname-print-root) { display: none !important; }
  #opname-print-root { display: block !important; position: absolute; inset: 0; background: #fff; }
  @page { size: A4 portrait; margin: 16mm 14mm; }
  html, body { background: #fff !important; }
}

.ba-sheet { width: 182mm; margin: 0 auto; color: #000; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11pt; line-height: 1.5; }
.ba-kop { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #000; padding-bottom: 10px; }
.ba-logo { max-height: 70px; max-width: 110px; object-fit: contain; }
.ba-clinic { font-size: 17pt; font-weight: 800; letter-spacing: .01em; }
.ba-line { font-size: 9.5pt; color: #222; }

.ba-title { text-align: center; font-size: 14pt; font-weight: 800; letter-spacing: .1em; margin: 16px 0 14px; text-decoration: underline; }

.ba-meta-tbl { margin-bottom: 14px; }
.ba-meta-tbl td { padding: 1px 0; font-size: 10pt; vertical-align: top; }
.ba-meta-k { white-space: nowrap; color: #333; padding-right: 8px !important; }
.ba-meta-tbl td:nth-child(2) { padding: 0 8px 0 0 !important; }

.ba-items { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 12px; }
.ba-items th, .ba-items td { border: 1px solid #000; padding: 4px 6px; text-align: left; }
.ba-items th { background: #f0f0f0; font-weight: 700; }
.ba-items td.r, .ba-items th.r { text-align: right; }
.ba-items td.c, .ba-items th.c { text-align: center; }
.ba-foot { background: #f7f7f7; font-size: 9.5pt; font-weight: 700; text-align: right; }

.ba-notes { font-size: 9.5pt; margin-bottom: 18px; border: 1px solid #999; padding: 6px 9px; background: #fafafa; }

.ba-sign { display: flex; justify-content: space-between; gap: 40px; margin-top: 26px; }
.ba-sign-box { text-align: center; font-size: 10pt; flex: 1; max-width: 240px; }
.ba-sign-space { height: 64px; }
.ba-sign-esign { height: 64px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; }
.ba-esign-check { font-size: 9pt; font-weight: 600; color: #15803d; border: 1px dashed #86c79a; border-radius: 6px; padding: 2px 8px; }
.ba-esign-meta { font-size: 7.5pt; color: #555; font-variant-numeric: tabular-nums; }
.ba-sign-name { font-weight: 700; text-decoration: underline; }
.ba-sign-sub { font-size: 9pt; color: #333; }
</style>
