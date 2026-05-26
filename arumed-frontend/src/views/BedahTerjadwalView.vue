<script setup>
import { ref, computed } from 'vue'

// ── Mock: pasien terjadwal (tanggal > today) ───────────────────────────────
const jadwal = ref([
  {
    id: 101, qNum: 'SCH-001', name: 'Tuti Handayani', rm: 'RM-2025-0031',
    age: 64, gender: 'P', ptype: 'bpjs',
    dpjp: 'dr. Andi Wijaya, Sp.M',
    diagnosa: 'H25.9 — Katarak senilis OD',
    prosedur: 'Phacoemulsifikasi OD',
    scheduledDate: '2026-05-28', scheduledTime: '08:00', ruang: 'OK 1',
    paketOperasi: {
      kode: 'PKB-001', nama: 'Fakoemulsifikasi (Phaco) + IOL Standar',
      bhpItems: [
        { item: 'BSS 500ml',            jumlah: 1, satuan: 'Botol' },
        { item: 'Viscoelastic (OVD)',   jumlah: 1, satuan: 'Syringe' },
        { item: 'Keratome 2.75mm',      jumlah: 1, satuan: 'Pcs' },
        { item: 'Spons Microsponge',    jumlah: 4, satuan: 'Pcs' },
        { item: 'Cannula I/A',          jumlah: 1, satuan: 'Pcs' },
      ],
      iolItems: [
        { item: 'IOL Monofocal Foldable (Alcon SN60WF)', jumlah: 1, satuan: 'Pcs', power: '+21.0 D' },
      ],
    },
  },
  {
    id: 102, qNum: 'SCH-002', name: 'Irwan Setiawan', rm: 'RM-2025-0044',
    age: 52, gender: 'L', ptype: 'umum',
    dpjp: 'dr. Rina Kusuma, Sp.M',
    diagnosa: 'H40.1 — Glaukoma sudut terbuka OS',
    prosedur: 'Trabekulektomi OS',
    scheduledDate: '2026-05-29', scheduledTime: '09:30', ruang: 'OK 2',
    paketOperasi: {
      kode: 'PKB-004', nama: 'Trabekulektomi (Bedah Glaukoma)',
      bhpItems: [
        { item: 'Vicryl 7-0',         jumlah: 2, satuan: 'Pcs' },
        { item: 'Spons Microsponge',  jumlah: 4, satuan: 'Pcs' },
        { item: 'BSS 500ml',          jumlah: 1, satuan: 'Botol' },
        { item: 'Kapas Steril',       jumlah: 4, satuan: 'Lembar' },
      ],
      iolItems: [],
    },
  },
  {
    id: 103, qNum: 'SCH-003', name: 'Mariam Halim', rm: 'RM-2025-0058',
    age: 70, gender: 'P', ptype: 'bpjs',
    dpjp: 'dr. Andi Wijaya, Sp.M',
    diagnosa: 'H25.9 — Katarak senilis OS',
    prosedur: 'Phacoemulsifikasi OS',
    scheduledDate: '2026-05-30', scheduledTime: '07:30', ruang: 'OK 1',
    paketOperasi: {
      kode: 'PKB-001', nama: 'Fakoemulsifikasi (Phaco) + IOL Standar',
      bhpItems: [
        { item: 'BSS 500ml',            jumlah: 1, satuan: 'Botol' },
        { item: 'Viscoelastic (OVD)',   jumlah: 1, satuan: 'Syringe' },
        { item: 'Keratome 2.75mm',      jumlah: 1, satuan: 'Pcs' },
        { item: 'Spons Microsponge',    jumlah: 4, satuan: 'Pcs' },
      ],
      iolItems: [
        { item: 'IOL Monofocal Foldable (B+L MX60)', jumlah: 1, satuan: 'Pcs', power: '+20.5 D' },
      ],
    },
  },
])

// ── Mock: permintaan BHP/IOL ke gudang/farmasi ─────────────────────────────
const requests = ref([
  {
    id: 1, tanggal: '2026-05-26', source: 'manual',
    requestedBy: 'Petugas Bedah', notes: 'Stok cadangan minggu ini',
    bhpItems: [
      { item: 'BSS 500ml', jumlah: 5, satuan: 'Botol' },
      { item: 'Spons Microsponge', jumlah: 20, satuan: 'Pcs' },
    ],
    iolItems: [],
    status: 'APPROVED',
  },
  {
    id: 2, tanggal: '2026-05-28', source: 'jadwal',
    pasienId: 101, pasienName: 'Tuti Handayani', paketKode: 'PKB-001',
    requestedBy: 'Petugas Bedah', notes: 'Untuk operasi Tuti Handayani — Phaco OD',
    bhpItems: [
      { item: 'BSS 500ml',            jumlah: 1, satuan: 'Botol' },
      { item: 'Viscoelastic (OVD)',   jumlah: 1, satuan: 'Syringe' },
      { item: 'Keratome 2.75mm',      jumlah: 1, satuan: 'Pcs' },
      { item: 'Spons Microsponge',    jumlah: 4, satuan: 'Pcs' },
      { item: 'Cannula I/A',          jumlah: 1, satuan: 'Pcs' },
    ],
    iolItems: [
      { item: 'IOL Monofocal Foldable (Alcon SN60WF)', jumlah: 1, satuan: 'Pcs', power: '+21.0 D' },
    ],
    status: 'PENDING',
  },
])

// ── UI State ───────────────────────────────────────────────────────────────
const tab = ref('pasien')
const showModal = ref(false)
const modalMode = ref('paket')      // 'paket' | 'manual'
const sourcePasien = ref(null)
const form = ref(emptyForm())
const newBhp = ref({ item: '', jumlah: 1, satuan: 'Pcs' })
const newIol = ref({ item: '', jumlah: 1, satuan: 'Pcs', power: '' })
const toasts = ref([])
let toastId = 0

const bhpOptions = ['BSS 500ml', 'Viscoelastic (OVD)', 'Spatula Sinskey', 'Spons Microsponge', 'Kapas Steril', 'Silk Suture 8-0', 'Vicryl 7-0', 'Cannula I/A', 'Keratome 2.75mm', 'Cystitome', 'Tampon Lensa']

function emptyForm() {
  return { tanggal: new Date().toISOString().slice(0, 10), bhpItems: [], iolItems: [], notes: '' }
}

// ── Computed ───────────────────────────────────────────────────────────────
const requestsByDate = computed(() => {
  const groups = {}
  const sorted = [...requests.value].sort((a, b) => a.tanggal.localeCompare(b.tanggal))
  for (const r of sorted) {
    if (!groups[r.tanggal]) groups[r.tanggal] = []
    groups[r.tanggal].push(r)
  }
  return Object.entries(groups).map(([date, items]) => ({ date, items }))
})

const statusLabel = { PENDING: 'Menunggu', APPROVED: 'Disetujui', REJECTED: 'Ditolak', DELIVERED: 'Terkirim' }
const statusCls   = { PENDING: 'st-pending', APPROVED: 'st-approved', REJECTED: 'st-rejected', DELIVERED: 'st-delivered' }

// ── Helpers ────────────────────────────────────────────────────────────────
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' })
}

function toast(type, msg) {
  const id = ++toastId
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 3000)
}

// ── Modal actions ──────────────────────────────────────────────────────────
function openRequestFromPasien(p) {
  sourcePasien.value = p
  modalMode.value = 'paket'
  form.value = {
    tanggal: p.scheduledDate,
    bhpItems: p.paketOperasi.bhpItems.map(b => ({ ...b })),
    iolItems: p.paketOperasi.iolItems.map(i => ({ ...i })),
    notes: `Untuk operasi ${p.prosedur} — ${p.name} (${p.qNum})`,
  }
  showModal.value = true
}

function openRequestManual() {
  sourcePasien.value = null
  modalMode.value = 'manual'
  form.value = emptyForm()
  showModal.value = true
}

function addBhpRow() {
  if (!newBhp.value.item) { toast('w', 'Pilih item BHP'); return }
  form.value.bhpItems.push({ ...newBhp.value })
  newBhp.value = { item: '', jumlah: 1, satuan: 'Pcs' }
}
function removeBhpRow(i) { form.value.bhpItems.splice(i, 1) }

function addIolRow() {
  if (!newIol.value.item.trim()) { toast('w', 'Nama IOL wajib'); return }
  form.value.iolItems.push({ ...newIol.value })
  newIol.value = { item: '', jumlah: 1, satuan: 'Pcs', power: '' }
}
function removeIolRow(i) { form.value.iolItems.splice(i, 1) }

function submitRequest() {
  if (!form.value.bhpItems.length && !form.value.iolItems.length) {
    toast('w', 'Tambah minimal 1 item BHP atau IOL'); return
  }
  const newReq = {
    id: Date.now(),
    tanggal: form.value.tanggal,
    source: modalMode.value === 'paket' ? 'jadwal' : 'manual',
    pasienId: sourcePasien.value?.id,
    pasienName: sourcePasien.value?.name,
    paketKode: sourcePasien.value?.paketOperasi?.kode,
    requestedBy: 'Petugas Bedah',
    notes: form.value.notes,
    bhpItems: [...form.value.bhpItems],
    iolItems: [...form.value.iolItems],
    status: 'PENDING',
  }
  requests.value.push(newReq)
  showModal.value = false
  toast('s', `Permintaan ${modalMode.value === 'paket' ? `untuk ${sourcePasien.value.name}` : 'manual'} terkirim ke gudang/farmasi`)
  tab.value = 'request'
}
</script>

<template>
  <div class="bdt">
    <!-- HEADER -->
    <div class="bdt-head">
      <div>
        <h1>Bedah · Pasien Terjadwal</h1>
        <p class="bdt-sub">Penjadwalan operasi mendatang &amp; permintaan BHP/IOL ke gudang / farmasi</p>
      </div>
    </div>

    <!-- TAB NAV -->
    <div class="bdt-tabs" role="tablist">
      <button :class="['bdt-tab', tab === 'pasien' ? 'a' : '']" @click="tab = 'pasien'">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Pasien Terjadwal
        <span class="bdt-tab-ct">{{ jadwal.length }}</span>
      </button>
      <button :class="['bdt-tab', tab === 'request' ? 'a' : '']" @click="tab = 'request'">
        <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        Request BHP/IOL
        <span class="bdt-tab-ct">{{ requests.length }}</span>
      </button>
    </div>

    <!-- ───────── TAB 1: PASIEN TERJADWAL ───────── -->
    <div v-if="tab === 'pasien'" class="bdt-body">
      <div class="bdt-table">
        <div class="bdt-row bdt-row-head">
          <div class="col-date">Tanggal</div>
          <div class="col-name">Nama Pasien</div>
          <div class="col-dx">Diagnosa</div>
          <div class="col-op">Jenis Operasi</div>
          <div class="col-paket">Paket Operasi</div>
          <div class="col-act"></div>
        </div>

        <div v-for="p in jadwal" :key="p.id" class="bdt-row">
          <div class="col-date">
            <div class="dt-day">{{ fmtDate(p.scheduledDate) }}</div>
            <div class="dt-time">{{ p.scheduledTime }} · {{ p.ruang }}</div>
          </div>
          <div class="col-name">
            <div class="pt-name">{{ p.name }}</div>
            <div class="pt-meta">{{ p.rm }} · {{ p.age }} th · {{ p.gender }} · <span :class="['ptype', `ptype-${p.ptype}`]">{{ p.ptype.toUpperCase() }}</span></div>
            <div class="pt-dpjp">DPJP: {{ p.dpjp }}</div>
          </div>
          <div class="col-dx">{{ p.diagnosa }}</div>
          <div class="col-op">{{ p.prosedur }}</div>
          <div class="col-paket">
            <span class="paket-kode">{{ p.paketOperasi.kode }}</span>
            <div class="paket-nama">{{ p.paketOperasi.nama }}</div>
            <div class="paket-count">{{ p.paketOperasi.bhpItems.length }} BHP · {{ p.paketOperasi.iolItems.length }} IOL</div>
          </div>
          <div class="col-act">
            <button class="btn-req" @click="openRequestFromPasien(p)">
              <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Request BHP/IOL
            </button>
          </div>
        </div>

        <div v-if="!jadwal.length" class="bdt-empty">Belum ada pasien terjadwal</div>
      </div>
    </div>

    <!-- ───────── TAB 2: REQUEST DASHBOARD ───────── -->
    <div v-else class="bdt-body">
      <div class="dash-head">
        <div>
          <div class="dash-title">Permintaan BHP &amp; IOL ke Gudang / Farmasi</div>
          <div class="dash-sub">{{ requests.length }} permintaan · {{ requestsByDate.length }} tanggal</div>
        </div>
        <button class="btn-add" @click="openRequestManual">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Permintaan
        </button>
      </div>

      <div v-if="!requests.length" class="bdt-empty">Belum ada permintaan</div>

      <div v-for="grp in requestsByDate" :key="grp.date" class="dash-group">
        <div class="dash-date">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          {{ fmtDate(grp.date) }}
          <span class="dash-date-ct">{{ grp.items.length }} permintaan</span>
        </div>

        <div v-for="r in grp.items" :key="r.id" class="req-card">
          <div class="req-top">
            <div class="req-source">
              <span :class="['source-pill', r.source === 'jadwal' ? 'src-jadwal' : 'src-manual']">
                <svg v-if="r.source === 'jadwal'" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
                <svg v-else viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                {{ r.source === 'jadwal' ? 'Dari Jadwal' : 'Manual' }}
              </span>
              <span v-if="r.pasienName" class="req-pasien">{{ r.pasienName }}</span>
              <span v-if="r.paketKode" class="req-paket">{{ r.paketKode }}</span>
            </div>
            <span :class="['status-pill', statusCls[r.status]]">{{ statusLabel[r.status] }}</span>
          </div>

          <div class="req-items">
            <div v-if="r.bhpItems.length" class="items-block">
              <div class="items-label">BHP ({{ r.bhpItems.length }} item)</div>
              <table class="items-tbl">
                <tr v-for="(b, i) in r.bhpItems" :key="i">
                  <td>{{ b.item }}</td>
                  <td class="num">{{ b.jumlah }} {{ b.satuan }}</td>
                </tr>
              </table>
            </div>
            <div v-if="r.iolItems.length" class="items-block items-iol">
              <div class="items-label">IOL ({{ r.iolItems.length }} item)</div>
              <table class="items-tbl">
                <tr v-for="(o, i) in r.iolItems" :key="i">
                  <td>{{ o.item }} <span v-if="o.power" class="iol-pwr">{{ o.power }}</span></td>
                  <td class="num">{{ o.jumlah }} {{ o.satuan }}</td>
                </tr>
              </table>
            </div>
          </div>

          <div v-if="r.notes" class="req-notes">{{ r.notes }}</div>
          <div class="req-foot">
            <span>Diminta oleh: <b>{{ r.requestedBy }}</b></span>
          </div>
        </div>
      </div>
    </div>

    <!-- ───────── MODAL: REQUEST BHP/IOL ───────── -->
    <div v-if="showModal" class="overlay" @click.self="showModal = false">
      <div class="modal">
        <div class="modal-head">
          <div>
            <div class="modal-title">
              <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              {{ modalMode === 'paket' ? `Request BHP/IOL — ${sourcePasien?.name}` : 'Tambah Permintaan BHP/IOL' }}
            </div>
            <div v-if="modalMode === 'paket' && sourcePasien" class="modal-sub">
              {{ sourcePasien.prosedur }} · Paket {{ sourcePasien.paketOperasi.kode }} — {{ sourcePasien.paketOperasi.nama }}
            </div>
            <div v-else class="modal-sub">Permintaan manual ke gudang / farmasi</div>
          </div>
          <button class="modal-close" @click="showModal = false">×</button>
        </div>

        <div class="modal-body">
          <div class="fld">
            <label>Tanggal Permintaan</label>
            <input type="date" v-model="form.tanggal" />
          </div>

          <!-- BHP -->
          <div class="sec">
            <div class="sec-hd">BHP <span class="sec-ct">{{ form.bhpItems.length }}</span></div>
            <table class="form-tbl" v-if="form.bhpItems.length">
              <thead><tr><th>Item</th><th>Jml</th><th>Satuan</th><th></th></tr></thead>
              <tbody>
                <tr v-for="(b, i) in form.bhpItems" :key="i">
                  <td>{{ b.item }}</td>
                  <td><input type="number" min="1" v-model.number="b.jumlah" class="qty-input" /></td>
                  <td>{{ b.satuan }}</td>
                  <td><button class="del-btn" @click="removeBhpRow(i)">✕</button></td>
                </tr>
              </tbody>
            </table>
            <div v-else class="empty-inline">Belum ada BHP</div>
            <div class="add-row">
              <select v-model="newBhp.item">
                <option value="">— Pilih BHP —</option>
                <option v-for="o in bhpOptions" :key="o">{{ o }}</option>
              </select>
              <input type="number" min="1" v-model.number="newBhp.jumlah" style="width:60px" />
              <select v-model="newBhp.satuan">
                <option>Pcs</option><option>Botol</option><option>Syringe</option><option>Lembar</option><option>Set</option>
              </select>
              <button class="btn-add-sm" @click="addBhpRow">+ Tambah BHP</button>
            </div>
          </div>

          <!-- IOL -->
          <div class="sec">
            <div class="sec-hd">IOL <span class="sec-ct">{{ form.iolItems.length }}</span></div>
            <table class="form-tbl" v-if="form.iolItems.length">
              <thead><tr><th>Item / Model</th><th>Power</th><th>Jml</th><th>Satuan</th><th></th></tr></thead>
              <tbody>
                <tr v-for="(o, i) in form.iolItems" :key="i">
                  <td>{{ o.item }}</td>
                  <td>{{ o.power || '—' }}</td>
                  <td><input type="number" min="1" v-model.number="o.jumlah" class="qty-input" /></td>
                  <td>{{ o.satuan }}</td>
                  <td><button class="del-btn" @click="removeIolRow(i)">✕</button></td>
                </tr>
              </tbody>
            </table>
            <div v-else class="empty-inline">Belum ada IOL</div>
            <div class="add-row">
              <input type="text" v-model="newIol.item" placeholder="Nama / Model IOL" style="flex:2" />
              <input type="text" v-model="newIol.power" placeholder="Power (mis. +21.0 D)" style="flex:1" />
              <input type="number" min="1" v-model.number="newIol.jumlah" style="width:60px" />
              <button class="btn-add-sm" @click="addIolRow">+ Tambah IOL</button>
            </div>
          </div>

          <div class="fld">
            <label>Catatan</label>
            <textarea v-model="form.notes" rows="2" placeholder="Catatan tambahan untuk gudang / farmasi…"></textarea>
          </div>
        </div>

        <div class="modal-foot">
          <button class="btn-sec" @click="showModal = false">Batal</button>
          <button class="btn-primary" @click="submitRequest">
            <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Kirim Permintaan
          </button>
        </div>
      </div>
    </div>

    <!-- ───────── TOAST ───────── -->
    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.bdt { padding: 1rem 1.25rem; }

/* Header */
.bdt-head { margin-bottom: 1rem; }
.bdt-head h1 { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--gd); line-height: 1.1; }
.bdt-sub { font-size: 12px; color: var(--tu); margin-top: 4px; }

/* Tabs */
.bdt-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); margin-bottom: 1rem; padding: 0 4px; }
.bdt-tab { display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; font-size: 12.5px; font-weight: 600; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'DM Sans', sans-serif; transition: color .14s; }
.bdt-tab:hover { color: var(--td); }
.bdt-tab.a { color: var(--ga); border-bottom-color: var(--ga); }
.bdt-tab svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.bdt-tab-ct { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: var(--gl); color: var(--ga); }
.bdt-tab.a .bdt-tab-ct { background: var(--ga); color: #fff; }

.bdt-body { min-height: 50vh; }
.bdt-empty { text-align: center; padding: 2rem; font-size: 13px; color: var(--th); background: var(--bi); border: 1px dashed var(--gb); border-radius: 10px; }

/* ── TAB 1: list table ── */
.bdt-table { display: flex; flex-direction: column; border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; background: var(--bc); }
.bdt-row { display: grid; grid-template-columns: 170px 1.4fr 1.5fr 1.2fr 1.4fr 150px; gap: 14px; padding: 12px 16px; border-bottom: 1px solid var(--gb); align-items: flex-start; }
.bdt-row:last-child { border-bottom: none; }
.bdt-row-head { background: var(--bs); font-size: 10.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.05em; padding: 10px 16px; }
.bdt-row:not(.bdt-row-head):hover { background: var(--gl); }

.col-date { font-size: 12px; }
.dt-day { font-weight: 700; color: var(--gd); }
.dt-time { font-size: 11px; color: var(--tu); margin-top: 2px; }
.col-name .pt-name { font-size: 13.5px; font-weight: 600; color: var(--td); }
.col-name .pt-meta { font-size: 11px; color: var(--tu); margin-top: 2px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.col-name .pt-dpjp { font-size: 10.5px; color: var(--tu); margin-top: 3px; }
.ptype { font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 4px; }
.ptype-bpjs { background: #dbeafe; color: #1e40af; }
.ptype-umum { background: var(--gl); color: var(--ga); }
.ptype-asn  { background: var(--wb); color: var(--wt); }

.col-dx { font-size: 12px; color: var(--td); line-height: 1.5; }
.col-op { font-size: 12px; color: var(--gm); font-weight: 500; line-height: 1.5; }

.col-paket .paket-kode { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 7px; background: var(--gl); color: var(--ga); border-radius: 4px; margin-bottom: 3px; font-family: 'DM Mono', monospace; }
.col-paket .paket-nama { font-size: 11.5px; color: var(--td); line-height: 1.4; }
.col-paket .paket-count { font-size: 10px; color: var(--tu); margin-top: 3px; }

.col-act { display: flex; justify-content: flex-end; align-items: flex-start; }
.btn-req { display: inline-flex; align-items: center; gap: 5px; padding: 7px 12px; background: var(--ga); color: #fff; border: none; border-radius: 8px; font-size: 11.5px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .14s; white-space: nowrap; }
.btn-req:hover { background: var(--gm); }
.btn-req svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ── TAB 2: dashboard ── */
.dash-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.dash-title { font-size: 14px; font-weight: 700; color: var(--td); }
.dash-sub { font-size: 11.5px; color: var(--tu); margin-top: 3px; }
.btn-add { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; background: var(--ga); color: #fff; border: none; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .14s; }
.btn-add:hover { background: var(--gm); }
.btn-add svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.dash-group { margin-bottom: 1.25rem; }
.dash-date { display: flex; align-items: center; gap: 7px; font-size: 11.5px; font-weight: 700; color: var(--gd); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.55rem; padding: 0 4px; }
.dash-date svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.dash-date-ct { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; background: var(--gl); color: var(--ga); margin-left: 4px; }

.req-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 12px 14px; margin-bottom: 8px; }
.req-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 9px; flex-wrap: wrap; gap: 8px; }
.req-source { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.source-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 20px; }
.source-pill svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.src-jadwal { background: #dbeafe; color: #1e40af; }
.src-manual { background: var(--gl); color: var(--ga); }
.req-pasien { font-size: 12.5px; font-weight: 600; color: var(--td); }
.req-paket { font-size: 10px; font-weight: 700; padding: 2px 6px; background: var(--bi); color: var(--tu); border-radius: 4px; font-family: 'DM Mono', monospace; }

.status-pill { font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 20px; letter-spacing: 0.03em; }
.st-pending   { background: #fef3c7; color: #92400e; }
.st-approved  { background: var(--sb); color: var(--st); }
.st-rejected  { background: var(--eb); color: var(--et); }
.st-delivered { background: #dbeafe; color: #1e40af; }

.req-items { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 9px; }
.items-block { background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; padding: 8px 10px; }
.items-block.items-iol { background: #faf5ff; border-color: #e9d5ff; }
.items-label { font-size: 10px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
.items-tbl { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.items-tbl td { padding: 3px 0; color: var(--td); }
.items-tbl td.num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; color: var(--gm); white-space: nowrap; padding-left: 8px; }
.iol-pwr { display: inline-block; margin-left: 5px; font-size: 10px; font-weight: 700; color: #7e22ce; background: #f3e8ff; padding: 1px 5px; border-radius: 4px; }

.req-notes { font-size: 11.5px; color: var(--tm); padding: 6px 10px; background: var(--bi); border-radius: 6px; line-height: 1.45; margin-bottom: 7px; }
.req-foot { display: flex; justify-content: space-between; font-size: 10.5px; color: var(--tu); }

/* ── MODAL ── */
.overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 200; padding: 1rem; }
.modal { background: var(--bc); border-radius: 14px; width: 100%; max-width: 720px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.15); overflow: hidden; }
.modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--gb); }
.modal-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: var(--td); }
.modal-title svg { width: 16px; height: 16px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.modal-sub { font-size: 11.5px; color: var(--tu); margin-top: 4px; line-height: 1.5; }
.modal-close { width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bs); font-size: 16px; cursor: pointer; color: var(--tu); flex-shrink: 0; line-height: 1; }
.modal-close:hover { background: var(--eb); color: var(--et); }

.modal-body { padding: 16px 18px; overflow-y: auto; }
.fld { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.fld label { font-size: 10.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; }
.fld input, .fld textarea { padding: 8px 10px; border: 1.5px solid var(--gb); border-radius: 8px; font-size: 12.5px; font-family: 'DM Sans', sans-serif; background: var(--bs); color: var(--td); outline: none; resize: vertical; }
.fld input:focus, .fld textarea:focus { border-color: var(--ga); background: #fff; }
.fld input[type="date"] { max-width: 200px; }

.sec { margin-bottom: 14px; padding: 11px 12px; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; }
.sec-hd { font-size: 11px; font-weight: 700; color: var(--td); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.sec-ct { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: var(--gl); color: var(--ga); }
.empty-inline { font-size: 11px; color: var(--th); font-style: italic; padding: 6px 0; }

.form-tbl { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 8px; background: var(--bc); border-radius: 6px; overflow: hidden; }
.form-tbl th { background: var(--bi); padding: 6px 8px; text-align: left; font-size: 10px; font-weight: 700; color: var(--tu); text-transform: uppercase; }
.form-tbl td { padding: 6px 8px; border-top: 1px solid var(--bg); color: var(--td); }
.qty-input { width: 56px; padding: 4px 6px; border: 1px solid var(--gb); border-radius: 5px; font-size: 12px; font-family: 'DM Sans', sans-serif; text-align: center; }
.del-btn { background: none; border: none; color: var(--et); cursor: pointer; font-size: 13px; padding: 2px 6px; border-radius: 4px; }
.del-btn:hover { background: var(--eb); }

.add-row { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 6px; }
.add-row select, .add-row input { padding: 6px 8px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12px; font-family: 'DM Sans', sans-serif; background: var(--bc); }
.add-row select { flex: 2; }
.btn-add-sm { padding: 6px 12px; background: var(--gl); border: 1px solid var(--ga); color: var(--gm); border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; white-space: nowrap; font-family: 'DM Sans', sans-serif; }
.btn-add-sm:hover { background: var(--ga); color: #fff; }

.modal-foot { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 18px; border-top: 1px solid var(--gb); background: var(--bs); }
.btn-sec { padding: 9px 18px; background: var(--bg); border: 1px solid var(--gb); color: var(--tm); border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.btn-sec:hover { border-color: var(--ga); }
.btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; background: var(--ga); color: #fff; border: none; border-radius: 8px; font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: 'DM Sans', sans-serif; }
.btn-primary:hover { background: var(--gm); }
.btn-primary svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ── TOAST ── */
.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0,0,0,.08); min-width: 230px; max-width: 320px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
</style>
