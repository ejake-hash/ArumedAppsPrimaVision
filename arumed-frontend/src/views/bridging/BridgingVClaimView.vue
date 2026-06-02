<script setup>
/**
 * BridgingVClaimView — operasi VClaim langsung: Cek Peserta, Cek Rujukan,
 * Monitoring, Referensi. Semua call lewat integrasiApi → backend BpjsClient.
 *
 * Bila integrasi belum aktif, backend membalas 503 dengan pesan jelas; halaman
 * menampilkannya sebagai banner, bukan error mentah.
 */
import { ref, reactive } from 'vue'
import { integrasiApi } from '@/services/api'

const tab = ref('peserta') // peserta | rujukan | monitoring

// Tab "Referensi" dipindah ke modul Jadwal Dokter → Pemetaan BPJS → tab
// "Referensi BPJS" (tempat kode itu dipakai untuk pemetaan poli/DPJP).
const tabs = [
  { key: 'peserta',    label: 'Cek Peserta' },
  { key: 'rujukan',    label: 'Cek Rujukan' },
  { key: 'monitoring', label: 'Monitoring' },
]

// ── Util: jalankan call + ekstrak hasil/pesan secara seragam ────────────────
// Tanggal lokal (WIB), BUKAN toISOString() (UTC) — di WIB jam 00:00–07:00 UTC
// masih tanggal kemarin → kirim tgl salah ke BPJS. sv-SE = YYYY-MM-DD.
const today = () => new Date().toLocaleDateString('sv-SE')

function makePanel() {
  return reactive({ loading: false, error: '', meta: null, data: null })
}

/**
 * Bungkus pemanggilan API. result envelope: { success, data:<bpjsResult>, message }.
 * bpjsResult: { metaData, response, is_success }.
 */
async function run(panel, fn) {
  panel.loading = true; panel.error = ''; panel.data = null; panel.meta = null
  try {
    const res = await fn()
    const bpjs = res.data?.data ?? {}
    panel.meta = bpjs.metaData ?? null
    if (bpjs.is_success) {
      panel.data = bpjs.response ?? null
    } else {
      panel.error = bpjs.metaData?.message || res.data?.message || 'Tidak ada data.'
    }
  } catch (e) {
    const s = e.response?.status
    panel.error = (s === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal memanggil layanan.')
  } finally {
    panel.loading = false
  }
}

// ── Cek Peserta ─────────────────────────────────────────────────────────────
const peserta = reactive({ type: 'nik', identifier: '', tglSep: today(), ...makePanel() })
function cekPeserta() {
  run(peserta, () => integrasiApi.cekPeserta({
    identifier: peserta.identifier.trim(), type: peserta.type, tglSep: peserta.tglSep,
  }))
}

// ── Cek Rujukan ─────────────────────────────────────────────────────────────
const rujukan = reactive({ no: '', sumber: 'rs', ...makePanel() })
function cekRujukan() {
  run(rujukan, () => integrasiApi.cekRujukan({ no_rujukan: rujukan.no.trim(), sumber: rujukan.sumber }))
}

// ── Monitoring ──────────────────────────────────────────────────────────────
const mon = reactive({ jenis: 'kunjungan', tgl: today(), jns: '2', status: '1', noKartu: '', tglMulai: today(), tglAkhir: today(), ...makePanel() })
function cekMonitoring() {
  const p = {}
  if (mon.jenis === 'kunjungan') { p.tgl = mon.tgl; p.jns = mon.jns }
  else if (mon.jenis === 'klaim') { p.tgl = mon.tgl; p.jns = mon.jns; p.status = mon.status }
  else { p.noKartu = mon.noKartu.trim(); p.tglMulai = mon.tglMulai; p.tglAkhir = mon.tglAkhir }
  run(mon, () => integrasiApi.monitoring(mon.jenis, p))
}

function pretty(v) {
  try { return JSON.stringify(v, null, 2) } catch { return String(v) }
}

// ── Monitoring → tabel ──────────────────────────────────────────────────────
// Respon BPJS bentuknya bervariasi per jenis:
//   Kunjungan → { sep: [ {noSep, nama, noKartu, ...} ] }
//   Klaim     → { sep: [ ... ] } / { list: [...] }
//   Histori   → { histori: [ ... ] } / { list: [...] }
// Ambil array baris pertama yang ketemu, fallback ke [] (→ pakai JSON mentah).
function monRows(data) {
  if (!data) return []
  if (Array.isArray(data)) return data
  const arr = data.sep ?? data.list ?? data.histori ?? data.data ?? null
  return Array.isArray(arr) ? arr : []
}

// Definisi kolom tabel monitoring (urutan + label Indonesia). Hanya kolom yang
// benar-benar ada di data yang ditampilkan, supaya tabel tetap ringkas.
const MON_COLS = [
  { key: 'noSep',        label: 'No. SEP' },
  { key: 'nama',         label: 'Nama Peserta' },
  { key: 'noKartu',      label: 'No. Kartu' },
  { key: 'tglSep',       label: 'Tgl SEP' },
  { key: 'tglPlgSep',    label: 'Tgl Pulang' },
  { key: 'jnsPelayanan', label: 'Pelayanan' },
  { key: 'kelasRawat',   label: 'Kelas' },
  { key: 'poli',         label: 'Poli' },
  { key: 'diagnosa',     label: 'Diagnosa' },
  { key: 'noRujukan',    label: 'No. Rujukan' },
]
// Kolom efektif = kolom yg punya nilai di minimal 1 baris.
function monActiveCols(rows) {
  if (!rows.length) return []
  return MON_COLS.filter(c => rows.some(r => r[c.key] != null && r[c.key] !== ''))
}
function cellVal(v) {
  if (v == null || v === '') return '—'
  if (typeof v === 'object') return v.nama ?? v.keterangan ?? JSON.stringify(v)
  return v
}

// Nilai mentah untuk CSV (tanpa em-dash penghias; kosong → string kosong).
function rawVal(v) {
  if (v == null) return ''
  if (typeof v === 'object') return v.nama ?? v.keterangan ?? JSON.stringify(v)
  return String(v)
}

// Export tabel monitoring yang sedang tampil ke CSV (UTF-8 + BOM → rapi di Excel).
function exportMonCsv() {
  const rows = monRows(mon.data)
  if (!rows.length) return
  const cols = monActiveCols(rows)
  const esc = (s) => {
    const t = String(s ?? '')
    return /[",\n;]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t
  }
  const header = ['No', ...cols.map(c => c.label)]
  const lines = [header.map(esc).join(',')]
  rows.forEach((r, i) => {
    lines.push([i + 1, ...cols.map(c => rawVal(r[c.key]))].map(esc).join(','))
  })
  const csv = '﻿' + lines.join('\r\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  const label = mon.jenis === 'histori' ? `histori_${mon.noKartu || 'peserta'}` : `${mon.jenis}_${mon.tgl}`
  a.href = url
  a.download = `monitoring_${label}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="vc">
    <div class="seg">
      <button v-for="t in tabs" :key="t.key" :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
    </div>

    <!-- CEK PESERTA -->
    <section v-if="tab === 'peserta'" class="panel">
      <div class="form-row">
        <select v-model="peserta.type" class="inp">
          <option value="nik">NIK</option>
          <option value="nokartu">No. Kartu</option>
        </select>
        <input v-model="peserta.identifier" class="inp grow" :placeholder="peserta.type === 'nik' ? 'NIK KTP (16 digit)' : 'No. Kartu BPJS'" @keyup.enter="cekPeserta" />
        <input v-model="peserta.tglSep" type="date" class="inp" />
        <button class="btn primary" :disabled="peserta.loading || !peserta.identifier" @click="cekPeserta">{{ peserta.loading ? 'Mencari…' : 'Cek' }}</button>
      </div>

      <p v-if="peserta.error" class="banner err">{{ peserta.error }}</p>
      <div v-else-if="peserta.data" class="result">
        <div class="kv-grid">
          <div><span>Nama</span><b>{{ peserta.data.nama ?? '—' }}</b></div>
          <div><span>NIK</span><b>{{ peserta.data.nik ?? '—' }}</b></div>
          <div><span>No. Kartu</span><b>{{ peserta.data.noKartu ?? '—' }}</b></div>
          <div><span>Tgl Lahir</span><b>{{ peserta.data.tglLahir ?? '—' }}</b></div>
          <div><span>Jenis Peserta</span><b>{{ peserta.data.jenisPeserta?.keterangan ?? '—' }}</b></div>
          <div><span>Hak Kelas</span><b>{{ peserta.data.hakKelas?.keterangan ?? '—' }}</b></div>
          <div>
            <span>Status</span>
            <b :class="peserta.data.statusPeserta?.kode === '0' ? 'ok' : 'warn'">{{ peserta.data.statusPeserta?.keterangan ?? '—' }}</b>
          </div>
          <div><span>Faskes</span><b>{{ peserta.data.provUmum?.nmProvider ?? '—' }}</b></div>
          <div><span>COB</span><b>{{ peserta.data.cob?.nmAsuransi ?? 'Tidak ada' }}</b></div>
        </div>
      </div>
    </section>

    <!-- CEK RUJUKAN -->
    <section v-else-if="tab === 'rujukan'" class="panel">
      <div class="form-row">
        <select v-model="rujukan.sumber" class="inp">
          <option value="rs">Dari RS (FKRTL)</option>
          <option value="fktp">Dari FKTP (Faskes 1)</option>
        </select>
        <input v-model="rujukan.no" class="inp grow" placeholder="Nomor Rujukan" @keyup.enter="cekRujukan" />
        <button class="btn primary" :disabled="rujukan.loading || !rujukan.no" @click="cekRujukan">{{ rujukan.loading ? 'Mencari…' : 'Cek' }}</button>
      </div>

      <p v-if="rujukan.error" class="banner err">{{ rujukan.error }}</p>
      <div v-else-if="rujukan.data" class="result">
        <div class="kv-grid">
          <div><span>No. Kunjungan</span><b>{{ rujukan.data.rujukan?.noKunjungan ?? '—' }}</b></div>
          <div><span>Tgl Kunjungan</span><b>{{ rujukan.data.rujukan?.tglKunjungan ?? '—' }}</b></div>
          <div><span>Diagnosa</span><b>{{ rujukan.data.rujukan?.diagnosa?.nama ?? '—' }}</b></div>
          <div><span>Poli Rujukan</span><b>{{ rujukan.data.rujukan?.poliRujukan?.nama ?? '—' }}</b></div>
          <div><span>Faskes Perujuk</span><b>{{ rujukan.data.rujukan?.provPerujuk?.nama ?? '—' }}</b></div>
          <div><span>Pelayanan</span><b>{{ rujukan.data.rujukan?.pelayanan?.nama ?? '—' }}</b></div>
          <div><span>Peserta</span><b>{{ rujukan.data.rujukan?.peserta?.nama ?? '—' }}</b></div>
          <div><span>No. Kartu</span><b>{{ rujukan.data.rujukan?.peserta?.noKartu ?? '—' }}</b></div>
        </div>
      </div>
    </section>

    <!-- MONITORING -->
    <section v-else-if="tab === 'monitoring'" class="panel">
      <div class="form-row">
        <select v-model="mon.jenis" class="inp">
          <option value="kunjungan">Kunjungan</option>
          <option value="klaim">Klaim</option>
          <option value="histori">Histori Peserta</option>
        </select>

        <template v-if="mon.jenis !== 'histori'">
          <input v-model="mon.tgl" type="date" class="inp" />
          <select v-model="mon.jns" class="inp">
            <option value="2">Rawat Jalan</option>
            <option value="1">Rawat Inap</option>
          </select>
          <select v-if="mon.jenis === 'klaim'" v-model="mon.status" class="inp">
            <option value="1">Proses Verifikasi</option>
            <option value="2">Pending</option>
            <option value="3">Klaim</option>
          </select>
        </template>
        <template v-else>
          <input v-model="mon.noKartu" class="inp grow" placeholder="No. Kartu" />
          <input v-model="mon.tglMulai" type="date" class="inp" />
          <input v-model="mon.tglAkhir" type="date" class="inp" />
        </template>

        <button class="btn primary" :disabled="mon.loading" @click="cekMonitoring">{{ mon.loading ? 'Memuat…' : 'Tampilkan' }}</button>
      </div>

      <p v-if="mon.error" class="banner err">{{ mon.error }}</p>
      <template v-else-if="mon.data">
        <!-- Tabel rapi bila data berupa daftar SEP/kunjungan -->
        <template v-if="monRows(mon.data).length">
          <div class="tbl-meta">
            <span class="count-badge">{{ monRows(mon.data).length }} data</span>
            <span class="meta-spacer"></span>
            <button class="btn ghost sm" @click="exportMonCsv">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Export CSV
            </button>
          </div>
          <div class="tbl-scroll">
            <table class="tbl mon-tbl">
              <thead>
                <tr>
                  <th class="num">No</th>
                  <th v-for="c in monActiveCols(monRows(mon.data))" :key="c.key">{{ c.label }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in monRows(mon.data)" :key="row.noSep ?? i">
                  <td class="num">{{ i + 1 }}</td>
                  <td v-for="c in monActiveCols(monRows(mon.data))" :key="c.key" :class="{ mono: c.key === 'noSep' || c.key === 'noKartu' || c.key === 'noRujukan' || c.key === 'diagnosa' }">
                    {{ cellVal(row[c.key]) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <!-- Fallback: bentuk data tak dikenali → JSON mentah -->
        <pre v-else class="json">{{ pretty(mon.data) }}</pre>
      </template>
    </section>

  </div>
</template>

<style scoped>
.vc { display: flex; flex-direction: column; gap: 1rem; }

.seg { display: inline-flex; border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; align-self: flex-start; flex-wrap: wrap; }
.seg button { padding: 8px 16px; font-size: 12.5px; font-weight: 600; border: none; background: #fff; color: var(--tm); cursor: pointer; border-right: 1px solid var(--gb); }
.seg button:last-child { border-right: none; }
.seg button.active { background: #1763d4; color: #fff; }

.panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1.1rem; display: flex; flex-direction: column; gap: 0.9rem; }
.hint { font-size: 12px; color: var(--tm); margin: 0; }

.form-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.inp { padding: 8px 10px; border: 1px solid var(--gb); border-radius: 7px; font-size: 13px; color: #000; background: #fff; }
.inp.grow { flex: 1; min-width: 180px; }
.inp:focus { outline: none; border-color: #1763d4; }
.btn { padding: 8px 16px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; font-weight: 600; background: #fff; color: #000; cursor: pointer; }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.btn.primary { background: #1763d4; color: #fff; border-color: #1763d4; }

.banner { padding: 10px 12px; border-radius: 8px; font-size: 13px; margin: 0; background: var(--bs); color: var(--td); }
.banner.err { background: #fef3c7; color: #92400e; }

.result { border-top: 1px solid var(--gb); padding-top: 0.6rem; }
.kv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.7rem 1.2rem; }
.kv-grid > div { display: flex; flex-direction: column; gap: 2px; }
.kv-grid span { font-size: 11px; color: var(--tm); text-transform: uppercase; letter-spacing: 0.03em; }
.kv-grid b { font-size: 13.5px; color: var(--td); }
.kv-grid b.ok { color: #166534; }
.kv-grid b.warn { color: #991b1b; }

.json { margin: 0; padding: 12px; background: #0f172a; color: #e2e8f0; border-radius: 8px; font-size: 11.5px; line-height: 1.5; max-height: 460px; overflow: auto; white-space: pre-wrap; word-break: break-word; }

.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl th { text-align: left; padding: 8px 10px; background: var(--bs); color: var(--tm); font-size: 11.5px; text-transform: uppercase; }
.tbl td { padding: 8px 10px; border-top: 1px solid var(--gb); color: var(--td); }
.tbl code { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--td); }

/* Monitoring table */
.tbl-meta { display: flex; align-items: center; gap: 0.5rem; }
.meta-spacer { flex: 1; }
.count-badge { font-size: 11.5px; font-weight: 700; color: #1763d4; background: #e8f0fe; padding: 3px 10px; border-radius: 999px; }
.btn.ghost { background: #fff; color: #1763d4; border-color: #1763d4; display: inline-flex; align-items: center; gap: 6px; }
.btn.ghost:hover { background: #e8f0fe; }
.btn.sm { padding: 6px 12px; font-size: 12px; }
.tbl-scroll { overflow-x: auto; border: 1px solid var(--gb); border-radius: 8px; }
.mon-tbl { font-size: 12.5px; white-space: nowrap; }
.mon-tbl thead th { position: sticky; top: 0; background: var(--bs); }
.mon-tbl tbody tr:nth-child(even) { background: #f8fafc; }
.mon-tbl tbody tr:hover { background: #eef4ff; }
.mon-tbl th.num, .mon-tbl td.num { width: 44px; text-align: right; color: var(--tm); padding-right: 14px; }
.mon-tbl td.mono { font-family: 'JetBrains Mono', monospace; font-size: 12px; }
</style>
