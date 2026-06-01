<script setup>
/**
 * BridgingAuditKlaimView — jejak audit & log grouping INA-CBGs per klaim BPJS.
 * Read-only. Pilih klaim (GET /klaim) → muat audit-log & grouping-log.
 * Pola tabel/expand mengikuti BridgingLogView.vue.
 */
import { ref, reactive, onMounted, watch } from 'vue'
import api from '@/services/api'

// ── Daftar klaim (panel pilih) ──────────────────────────────────────────────
const search   = ref('')
const claims   = ref([])
const loadingClaims = ref(false)
const selectedId = ref(null)

async function loadClaims() {
  loadingClaims.value = true
  try {
    const params = { per_page: 100 }
    if (search.value.trim()) params.search = search.value.trim()
    const { data } = await api.get('/klaim', { params })
    claims.value = data.data?.data ?? data.data ?? []
  } catch (e) {
    claims.value = []
  } finally {
    loadingClaims.value = false
  }
}

function selectClaim(c) {
  selectedId.value = c.id
  loadLogs(c.id)
}

// ── Log per klaim ───────────────────────────────────────────────────────────
const tab        = ref('audit')         // audit | grouping
const auditRows  = ref([])
const groupRows  = ref([])
const loadingLogs = ref(false)
const expanded   = ref(null)

async function loadLogs(id) {
  loadingLogs.value = true
  expanded.value = null
  try {
    const [a, g] = await Promise.all([
      api.get(`/klaim/${id}/audit-log`).catch(() => ({ data: { data: [] } })),
      api.get(`/klaim/grouping-log/${id}`).catch(() => ({ data: { data: [] } })),
    ])
    auditRows.value = a.data?.data ?? []
    groupRows.value = g.data?.data ?? []
  } catch (e) {
    auditRows.value = []
    groupRows.value = []
  } finally {
    loadingLogs.value = false
  }
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function fmtTime(t) {
  if (!t) return '—'
  return new Date(t).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
function rupiah(v) {
  const n = Number(v ?? 0)
  return 'Rp ' + n.toLocaleString('id-ID')
}
function pretty(v) {
  if (v == null) return '—'
  try { return JSON.stringify(typeof v === 'string' ? JSON.parse(v) : v, null, 2) }
  catch { return String(v) }
}
function groupClass(s) {
  return String(s || '').toUpperCase() === 'SUCCESS' || String(s || '').toUpperCase() === 'BERHASIL' ? 'ok' : 'fail'
}

watch(search, () => loadClaims())
onMounted(() => loadClaims())
</script>

<template>
  <div class="wrap">
    <!-- Panel kiri: pilih klaim -->
    <aside class="picker">
      <input v-model="search" type="text" placeholder="Cari SEP / pasien…" class="inp" @keyup.enter="loadClaims" />
      <p v-if="loadingClaims" class="muted">Memuat…</p>
      <p v-else-if="!claims.length" class="muted">Tidak ada klaim.</p>
      <ul v-else class="claim-list">
        <li
          v-for="c in claims"
          :key="c.id"
          class="claim-item"
          :class="{ active: selectedId === c.id }"
          @click="selectClaim(c)"
        >
          <div class="ci-top">
            <code>{{ c.no_sep ?? '—' }}</code>
            <span class="ci-status">{{ c.status ?? '—' }}</span>
          </div>
          <div class="ci-name">{{ c.visit?.patient?.name ?? '—' }}</div>
        </li>
      </ul>
    </aside>

    <!-- Panel kanan: log -->
    <main class="logs">
      <p v-if="!selectedId" class="muted center">Pilih klaim di sebelah kiri untuk melihat jejak audit &amp; log grouping.</p>

      <template v-else>
        <div class="seg">
          <button :class="{ active: tab === 'audit' }" @click="tab = 'audit'; expanded = null">Audit Status ({{ auditRows.length }})</button>
          <button :class="{ active: tab === 'grouping' }" @click="tab = 'grouping'; expanded = null">Grouping INA-CBGs ({{ groupRows.length }})</button>
        </div>

        <p v-if="loadingLogs" class="muted">Memuat log…</p>

        <!-- ── AUDIT STATUS ── -->
        <template v-else-if="tab === 'audit'">
          <p v-if="!auditRows.length" class="muted">Belum ada riwayat audit untuk klaim ini.</p>
          <table v-else class="tbl">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Aksi</th>
                <th>Perubahan Status</th>
                <th>Oleh</th>
                <th>Catatan</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in auditRows" :key="row.id">
                <td>{{ fmtTime(row.created_at) }}</td>
                <td><code>{{ row.action ?? '—' }}</code></td>
                <td>
                  <span class="muted">{{ row.old_status ?? '—' }}</span>
                  <span class="arr"> → </span>
                  <strong>{{ row.new_status ?? '—' }}</strong>
                </td>
                <td>{{ row.performed_by?.name ?? '—' }}</td>
                <td class="notes">{{ row.notes ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </template>

        <!-- ── GROUPING INA-CBGs ── -->
        <template v-else>
          <p v-if="!groupRows.length" class="muted">Belum ada log grouping untuk klaim ini.</p>
          <table v-else class="tbl">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>CBG</th>
                <th>Tarif</th>
                <th>Severity</th>
                <th>Engine</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="row in groupRows" :key="row.id">
                <tr class="rw" @click="expanded = expanded === row.id ? null : row.id">
                  <td>{{ fmtTime(row.created_at) }}</td>
                  <td><code>{{ row.cbg_code ?? '—' }}</code></td>
                  <td>{{ rupiah(row.cbg_tarif) }}</td>
                  <td>{{ row.severity_level ?? '—' }}</td>
                  <td>{{ row.engine_type ?? '—' }} <span v-if="row.grouper_version" class="muted">v{{ row.grouper_version }}</span></td>
                  <td><span class="dot" :class="groupClass(row.status)">{{ row.status ?? '—' }}</span></td>
                  <td class="chev">{{ expanded === row.id ? '▾' : '▸' }}</td>
                </tr>
                <tr v-if="expanded === row.id" class="detail">
                  <td colspan="7">
                    <div v-if="row.error_message" class="err">⚠ {{ row.error_message }}</div>
                    <div class="payloads">
                      <div>
                        <div class="pl-title">Input Diagnosis</div>
                        <pre>{{ pretty(row.input_diagnosis) }}</pre>
                      </div>
                      <div>
                        <div class="pl-title">Input Tindakan</div>
                        <pre>{{ pretty(row.input_tindakan) }}</pre>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </template>
      </template>
    </main>
  </div>
</template>

<style scoped>
.wrap { display: grid; grid-template-columns: 280px 1fr; gap: 1.2rem; align-items: start; }
@media (max-width: 900px) { .wrap { grid-template-columns: 1fr; } }

.muted { color: var(--tm); font-size: 13px; }
.center { text-align: center; padding: 2rem 1rem; }

/* Picker */
.picker { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.7rem; display: flex; flex-direction: column; gap: 0.6rem; position: sticky; top: 1.5rem; }
.inp { padding: 7px 9px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12.5px; color: #000; background: #fff; width: 100%; box-sizing: border-box; }
.claim-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 3px; max-height: 70vh; overflow: auto; }
.claim-item { padding: 8px 9px; border-radius: 8px; cursor: pointer; border: 1px solid transparent; }
.claim-item:hover { background: var(--bs); }
.claim-item.active { background: var(--gl); border-color: #1763d4; }
.ci-top { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
.ci-top code { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--td); }
.ci-status { font-size: 10px; font-weight: 600; color: var(--tm); text-transform: uppercase; }
.ci-name { font-size: 12.5px; color: var(--td); margin-top: 2px; }

/* Logs */
.logs { min-width: 0; display: flex; flex-direction: column; gap: 0.9rem; }
.seg { display: inline-flex; border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; align-self: flex-start; }
.seg button { padding: 7px 14px; font-size: 12.5px; font-weight: 600; border: none; background: #fff; color: var(--tm); cursor: pointer; }
.seg button.active { background: #1763d4; color: #fff; }

.tbl { width: 100%; border-collapse: collapse; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; font-size: 13px; }
.tbl th { text-align: left; padding: 9px 12px; background: var(--bs); color: var(--tm); font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.tbl td { padding: 9px 12px; border-top: 1px solid var(--gb); color: var(--td); vertical-align: top; }
.tbl code { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--td); }
.notes { max-width: 280px; white-space: pre-wrap; word-break: break-word; }
.arr { color: var(--tm); }
.rw { cursor: pointer; }
.rw:hover { background: var(--bs); }
.chev { color: var(--tm); width: 24px; }

.dot { font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 12px; }
.dot.ok { background: #dcfce7; color: #166534; }
.dot.fail { background: #fee2e2; color: #991b1b; }

.detail td { background: #fafbfc; }
.payloads { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
@media (max-width: 800px) { .payloads { grid-template-columns: 1fr; } }
.pl-title { font-size: 11px; font-weight: 600; color: var(--tm); margin-bottom: 3px; text-transform: uppercase; }
.detail pre { margin: 0; padding: 8px; background: #0f172a; color: #e2e8f0; border-radius: 6px; font-size: 11px; line-height: 1.4; max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
.err { margin-bottom: 0.6rem; font-size: 12px; color: #991b1b; }
</style>
