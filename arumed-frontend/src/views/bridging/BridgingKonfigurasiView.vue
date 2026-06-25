<script setup>
/**
 * BridgingKonfigurasiView — Konfigurasi & Status semua sistem integrasi.
 * Inti "zero-code activation": paste credential → Test → Aktifkan.
 *
 * Credential bersifat write-only: tidak pernah dikirim balik dari server (masked).
 * Field rahasia hanya dikirim saat diisi; dibiarkan kosong = tidak diubah.
 */
import { ref, reactive, onMounted, computed } from 'vue'
import { integrasiApi } from '@/services/api'

const loading   = ref(true)
const saving    = ref(null)   // system_name yang sedang disimpan
const testing   = ref(null)   // system_name yang sedang dites
const rows      = ref([])     // gabungan status + config
const toast     = reactive({ show: false, ok: true, msg: '' })

// Sistem yang punya form credential BPJS lengkap (cons_id/secret/user_key).
// REKAM_MEDIS = keluarga service 'ihs' (sama iCare): cons_id/secret sama VClaim,
// user_key ikut layanan iCare/ihs.
const BPJS_SYSTEMS = ['VCLAIM', 'ANTREAN', 'ICARE', 'REKAM_MEDIS']

// Urutan tampil kartu: baris 1 = SATUSEHAT · VCLAIM · ANTREAN,
// baris 2 = ICARE · REKAM_MEDIS · INACBGS. Sistem di luar daftar ini
// disembunyikan (mis. LUPIS).
const ORDER = ['SATUSEHAT', 'VCLAIM', 'ANTREAN', 'ICARE', 'REKAM_MEDIS', 'INACBGS']

const LABELS = {
  VCLAIM:      'BPJS VClaim',
  ANTREAN:     'BPJS Antrean Online',
  ICARE:       'BPJS iCare',
  REKAM_MEDIS: 'Rekam Medis BPJS',
  SATUSEHAT:   'Satu Sehat',
  INACBGS:     'E-Klaim INA-CBG (WS)',
}

// Draft input per-sistem (tidak ikut load dari server untuk field rahasia).
const drafts = reactive({})

function flash(ok, msg) {
  toast.ok = ok; toast.msg = msg; toast.show = true
  setTimeout(() => (toast.show = false), 3500)
}

async function load() {
  loading.value = true
  try {
    const [statusRes, configRes] = await Promise.all([
      integrasiApi.status(),
      integrasiApi.listConfig(),
    ])
    const statusList = statusRes.data?.data ?? []
    const configList = configRes.data?.data ?? []
    const cfgById = Object.fromEntries(configList.map((c) => [c.system_name, c]))

    rows.value = statusList
      // Tampilkan hanya sistem yang ada di ORDER (LUPIS dsb. disembunyikan).
      .filter((s) => ORDER.includes(s.system_name))
      .sort((a, b) => ORDER.indexOf(a.system_name) - ORDER.indexOf(b.system_name))
      .map((s) => {
        const cfg = cfgById[s.system_name] ?? {}
        if (!drafts[s.system_name]) {
          drafts[s.system_name] = {
            base_url:    s.base_url ?? cfg.base_url ?? '',
            service_name: cfg.configuration?.service_name ?? '',
            kode_faskes: cfg.configuration?.kode_faskes ?? '',
            cons_id:     '',
            secret_key:  '',
            user_key:    '',
            // Satu Sehat (FHIR / OAuth2). Field rahasia tetap write-only.
            // Pilihan environment dihilangkan dari UI — selalu Production.
            env:             cfg.configuration?.env ?? 'production',
            organization_id: cfg.configuration?.organization_id ?? '',
            location_id:     cfg.configuration?.location_id ?? '',
            client_id:       '',
            client_secret:   '',
            // E-Klaim INA-CBG (WS ws.php). Encryption Key write-only.
            kode_tarif:    cfg.configuration?.kode_tarif ?? 'CS',
            key_encoding:  cfg.configuration?.key_encoding ?? 'hex',
            verify_ssl:    cfg.configuration?.verify_ssl ?? false,
            encryption_key: '',
            notes:       cfg.notes ?? '',
          }
        }
        // Draft dipertahankan antar-load (jangan timpa ketikan user), TAPI field
        // yang masih kosong di-refresh dari server — location_id bisa berubah dari
        // tab Satu Sehat → Location tanpa lewat form ini (cegah draft stale).
        const dd = drafts[s.system_name]
        if (!dd.location_id && cfg.configuration?.location_id) dd.location_id = cfg.configuration.location_id
        if (!dd.organization_id && cfg.configuration?.organization_id) dd.organization_id = cfg.configuration.organization_id
        return { ...s, configuration: cfg.configuration ?? {}, notes: cfg.notes }
      })
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal memuat konfigurasi')
  } finally {
    loading.value = false
  }
}

function buildPayload(sys) {
  const d = drafts[sys.system_name]
  const cred = {}

  if (isSatusehat(sys.system_name)) {
    const payload = {
      base_url: d.base_url || null,
      configuration: {
        ...(sys.configuration ?? {}),
        // Environment dipaksa Production (pilihan dihilangkan dari UI).
        env:             'production',
        // organization_id / location_id non-secret → boleh disimpan di configuration.
        // HANYA kirim bila diisi: draft kosong/stale pernah MENGHAPUS location_id
        // (di-set via tab Location) → semua Bundle ditolak 400 RuleNumber 10120.
        ...(d.organization_id ? { organization_id: d.organization_id } : {}),
        ...(d.location_id ? { location_id: d.location_id } : {}),
      },
      notes: d.notes || null,
    }
    // Credential rahasia (write-only): hanya dikirim bila diisi.
    if (d.client_id)     cred.client_id     = d.client_id
    if (d.client_secret) cred.client_secret = d.client_secret
    if (Object.keys(cred).length) payload.credentials = cred
    return payload
  }

  if (isEklaim(sys.system_name)) {
    const payload = {
      base_url: d.base_url || null,
      configuration: {
        ...(sys.configuration ?? {}),
        kode_tarif:   d.kode_tarif || 'CS',
        key_encoding: d.key_encoding || 'hex',
        verify_ssl:   !!d.verify_ssl,
      },
      notes: d.notes || null,
    }
    // Encryption Key write-only: hanya dikirim bila diisi.
    if (d.encryption_key) payload.credentials = { encryption_key: d.encryption_key.trim() }
    return payload
  }

  // Pengaman: field KOSONG tidak pernah dikirim → tak menimpa service_name/
  // kode_faskes tersimpan jadi null (sebab "reset ke vclaim-rest-dev" saat
  // configuration belum termuat). Hanya kirim nilai yang benar-benar diisi.
  const configuration = { ...(sys.configuration ?? {}) }
  if (d.service_name) configuration.service_name = d.service_name
  if (d.kode_faskes)  configuration.kode_faskes  = d.kode_faskes
  const payload = {
    base_url: d.base_url || null,
    configuration,
    notes: d.notes || null,
  }
  // Credential hanya dikirim jika ada yang diisi (write-only, tidak menimpa dgn kosong).
  if (d.cons_id)    cred.cons_id    = d.cons_id
  if (d.secret_key) cred.secret_key = d.secret_key
  if (d.user_key)   cred.user_key   = d.user_key
  if (Object.keys(cred).length) payload.credentials = cred
  return payload
}

async function save(sys) {
  saving.value = sys.system_name
  try {
    await integrasiApi.updateConfig(sys.id, buildPayload(sys))
    // Kosongkan field rahasia setelah tersimpan (jangan tahan di memori UI).
    const d = drafts[sys.system_name]
    d.cons_id = ''; d.secret_key = ''; d.user_key = ''; d.encryption_key = ''
    flash(true, `Konfigurasi ${LABELS[sys.system_name] ?? sys.system_name} disimpan`)
    await load()
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal menyimpan')
  } finally {
    saving.value = null
  }
}

async function toggleEnable(sys) {
  saving.value = sys.system_name
  try {
    await integrasiApi.updateConfig(sys.id, { is_enabled: !sys.is_enabled })
    flash(true, `${LABELS[sys.system_name] ?? sys.system_name} ${!sys.is_enabled ? 'diaktifkan' : 'dinonaktifkan'}`)
    await load()
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal mengubah status')
  } finally {
    saving.value = null
  }
}

async function test(sys) {
  testing.value = sys.system_name
  try {
    const res = await integrasiApi.testKoneksi(sys.system_name)
    const ok = res.data?.data?.success
    flash(!!ok, res.data?.data?.message ?? (ok ? 'Koneksi OK' : 'Koneksi gagal'))
    await load()
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Test koneksi gagal')
  } finally {
    testing.value = null
  }
}

function statusBadge(s) {
  if (!s.has_credentials) return { cls: 'b-empty', txt: 'Belum dikonfigurasi' }
  if (!s.is_enabled)      return { cls: 'b-off',   txt: 'Nonaktif' }
  if (s.last_test_status === 'SUCCESS') return { cls: 'b-ok',   txt: 'Aktif · Terhubung' }
  if (s.last_test_status === 'FAILED')  return { cls: 'b-fail', txt: 'Aktif · Test gagal' }
  return { cls: 'b-on', txt: 'Aktif · Belum dites' }
}

const isBpjs = (name) => BPJS_SYSTEMS.includes(name)
const isSatusehat = (name) => name === 'SATUSEHAT'
const isEklaim = (name) => name === 'INACBGS'
const isRekamMedis = (name) => name === 'REKAM_MEDIS'

// ── Kelola Lokasi Satu Sehat ─────────────────────────────────────────────────
const loc = reactive({
  open: false, loading: false, items: [], activeId: null,
  busy: null,                 // id baris yang sedang diproses
  newName: '', registering: false,
  editId: null, editName: '', // inline edit
})

async function openLoc() {
  loc.open = true
  await loadLoc()
}

async function loadLoc() {
  loc.loading = true
  try {
    const res = await integrasiApi.satusehatLocations()
    const d = res.data?.data ?? {}
    if (!d.success) { flash(false, d.message || 'Gagal memuat lokasi'); loc.items = []; return }
    loc.items = d.items ?? []
    loc.activeId = d.active_id ?? null
  } catch (e) {
    flash(false, (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message ?? 'Gagal memuat lokasi'))
  } finally {
    loc.loading = false
  }
}

async function registerLoc() {
  if (!loc.newName.trim()) { flash(false, 'Isi nama lokasi dulu'); return }
  loc.registering = true
  try {
    const res = await integrasiApi.satusehatRegisterLocation({ name: loc.newName.trim(), set_active: loc.items.length === 0 })
    const d = res.data?.data ?? {}
    if (!d.success) { flash(false, d.message || 'Gagal mendaftar lokasi'); return }
    flash(true, `Lokasi "${d.name}" didaftarkan`)
    loc.newName = ''
    await loadLoc()
  } catch (e) {
    flash(false, (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message ?? 'Gagal mendaftar lokasi'))
  } finally {
    loc.registering = false
  }
}

async function setActiveLoc(item) {
  loc.busy = item.id
  try {
    await integrasiApi.satusehatSetActiveLocation(item.id)
    loc.activeId = item.id
    flash(true, `"${item.name}" jadi lokasi aktif`)
    await load() // refresh kartu (location_id di config berubah)
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal set lokasi aktif')
  } finally {
    loc.busy = null
  }
}

function startEditLoc(item) { loc.editId = item.id; loc.editName = item.name }
async function saveEditLoc(item) {
  if (!loc.editName.trim()) { flash(false, 'Nama tidak boleh kosong'); return }
  loc.busy = item.id
  try {
    await integrasiApi.satusehatUpdateLocation(item.id, { name: loc.editName.trim(), status: item.status || 'active', physical_type: item.physical_type || 'ro' })
    flash(true, 'Lokasi diperbarui')
    loc.editId = null
    await loadLoc()
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal update lokasi')
  } finally {
    loc.busy = null
  }
}

async function deleteLoc(item) {
  if (!confirm(`Nonaktifkan lokasi "${item.name}"? (Satu Sehat tidak menghapus permanen, hanya set inactive)`)) return
  loc.busy = item.id
  try {
    await integrasiApi.satusehatDeleteLocation(item.id, { name: item.name, physical_type: item.physical_type || 'ro' })
    flash(true, 'Lokasi dinonaktifkan')
    await loadLoc()
    await load()
  } catch (e) {
    flash(false, e.response?.data?.message ?? 'Gagal menonaktifkan lokasi')
  } finally {
    loc.busy = null
  }
}

onMounted(load)
</script>

<template>
  <div class="cfg">
    <p v-if="loading" class="muted">Memuat…</p>

    <div v-else class="cards">
      <section v-for="sys in rows" :key="sys.id" class="card">
        <header class="card-head">
          <div>
            <h3>{{ LABELS[sys.system_name] ?? sys.system_name }}</h3>
            <code class="sys-code">{{ sys.system_name }}</code>
          </div>
          <span class="badge" :class="statusBadge(sys).cls">{{ statusBadge(sys).txt }}</span>
        </header>

        <p v-if="sys.notes" class="note">{{ sys.notes }}</p>

        <div class="form">
          <label class="fld">
            <span>Base URL</span>
            <input v-model="drafts[sys.system_name].base_url" type="text" placeholder="https://apijkn-dev.bpjs-kesehatan.go.id" />
          </label>

          <template v-if="isBpjs(sys.system_name)">
            <p v-if="isRekamMedis(sys.system_name)" class="ek-hint">
              Service family <code>ihs</code> (sama iCare). <strong>Cons ID &amp; Consumer Secret sama dengan VClaim</strong>; User Key ikut layanan iCare/ihs. Kode Faskes = PPK (mis. <code>0038R137</code>) — dipakai sebagai kunci enkripsi data RME.
            </p>
            <label class="fld">
              <span>Service Name</span>
              <input v-model="drafts[sys.system_name].service_name" type="text" :placeholder="isRekamMedis(sys.system_name) ? 'ihs' : 'vclaim-rest-dev'" />
            </label>
            <label class="fld">
              <span>Kode Faskes (PPK)</span>
              <input v-model="drafts[sys.system_name].kode_faskes" type="text" :placeholder="isRekamMedis(sys.system_name) ? '0038R137' : '0301Rxxx'" />
            </label>

            <div class="cred-grp">
              <div class="cred-title">
                Credential
                <span class="cred-hint">{{ sys.has_credentials ? 'tersimpan · isi untuk mengganti' : 'belum diisi' }}</span>
              </div>
              <label class="fld">
                <span>Cons ID</span>
                <input v-model="drafts[sys.system_name].cons_id" type="text" autocomplete="off" :placeholder="sys.has_credentials ? '••••• (tersimpan)' : 'cons_id dari BPJS'" />
              </label>
              <label class="fld">
                <span>Consumer Secret</span>
                <input v-model="drafts[sys.system_name].secret_key" type="password" autocomplete="new-password" :placeholder="sys.has_credentials ? '••••• (tersimpan)' : 'secret key'" />
              </label>
              <label class="fld">
                <span>User Key</span>
                <input v-model="drafts[sys.system_name].user_key" type="password" autocomplete="new-password" :placeholder="sys.has_credentials ? '••••• (tersimpan)' : 'user_key layanan'" />
              </label>
            </div>
          </template>

          <template v-else-if="isSatusehat(sys.system_name)">
            <label class="fld">
              <span>Organization ID</span>
              <input v-model="drafts[sys.system_name].organization_id" type="text" autocomplete="off" placeholder="378de02d-..." />
            </label>
            <label class="fld">
              <span>Location ID <em class="opt">(opsional)</em></span>
              <input v-model="drafts[sys.system_name].location_id" type="text" autocomplete="off" placeholder="ID ruang/poli dari dashboard" />
            </label>
            <button type="button" class="loc-link" :disabled="!sys.has_credentials" :title="!sys.has_credentials ? 'Isi & simpan credential dulu' : ''" @click="openLoc">
              🗺️ Kelola Lokasi (Satu Sehat)
            </button>

            <div class="cred-grp">
              <div class="cred-title">
                Credential
                <span class="cred-hint">{{ sys.has_credentials ? 'tersimpan · isi untuk mengganti' : 'belum diisi' }}</span>
              </div>
              <label class="fld">
                <span>Client ID</span>
                <input v-model="drafts[sys.system_name].client_id" type="text" autocomplete="off" :placeholder="sys.has_credentials ? '••••• (tersimpan)' : 'client_id Satu Sehat'" />
              </label>
              <label class="fld">
                <span>Client Secret</span>
                <input v-model="drafts[sys.system_name].client_secret" type="password" autocomplete="new-password" :placeholder="sys.has_credentials ? '••••• (tersimpan)' : 'client_secret Satu Sehat'" />
              </label>
            </div>
          </template>

          <template v-else-if="isEklaim(sys.system_name)">
            <p class="ek-hint">Web Service E-Klaim INA-CBG lokal (<code>ws.php</code>). Base URL diisi alamat ws.php aplikasi E-Klaim di jaringan RS.</p>
            <label class="fld">
              <span>Kode Tarif INA-CBG</span>
              <input v-model="drafts[sys.system_name].kode_tarif" type="text" placeholder="CS" />
            </label>
            <label class="fld">
              <span>Encoding Key</span>
              <select v-model="drafts[sys.system_name].key_encoding">
                <option value="hex">hex (64 karakter)</option>
                <option value="base64">base64</option>
                <option value="raw">raw</option>
              </select>
            </label>
            <label class="fld ek-check">
              <input v-model="drafts[sys.system_name].verify_ssl" type="checkbox" />
              <span>Verifikasi SSL (matikan untuk HTTP LAN)</span>
            </label>

            <div class="cred-grp">
              <div class="cred-title">
                Encryption Key
                <span class="cred-hint">{{ sys.has_credentials ? 'tersimpan · isi untuk mengganti' : 'belum diisi' }}</span>
              </div>
              <label class="fld">
                <span>Encryption Key (dari E-Klaim → Setup → Integrasi SIMRS)</span>
                <input v-model="drafts[sys.system_name].encryption_key" type="password" autocomplete="new-password" :placeholder="sys.has_credentials ? '••••• (tersimpan)' : '64 karakter hex'" />
              </label>
            </div>
          </template>
        </div>

        <footer class="card-foot">
          <button
            class="btn ghost"
            :disabled="testing === sys.system_name || !sys.has_credentials"
            :title="!sys.has_credentials ? 'Isi & simpan credential dulu sebelum test' : ''"
            @click="test(sys)"
          >
            {{ testing === sys.system_name ? 'Mengetes…' : 'Test Koneksi' }}
          </button>
          <button class="btn primary" :disabled="saving === sys.system_name" @click="save(sys)">
            {{ saving === sys.system_name ? 'Menyimpan…' : 'Simpan' }}
          </button>
          <button
            class="btn"
            :class="sys.is_enabled ? 'danger' : 'on'"
            :disabled="saving === sys.system_name || !sys.has_credentials"
            :title="!sys.has_credentials ? 'Isi & simpan credential dulu' : ''"
            @click="toggleEnable(sys)"
          >
            {{ sys.is_enabled ? 'Nonaktifkan' : 'Aktifkan' }}
          </button>
        </footer>
      </section>
    </div>

    <!-- Modal Kelola Lokasi Satu Sehat -->
    <Teleport to="body">
      <div v-if="loc.open" class="loc-overlay" @click.self="loc.open = false">
        <div class="loc-box">
          <div class="loc-head">
            <span>Kelola Lokasi — Satu Sehat</span>
            <button class="loc-close" @click="loc.open = false">✕</button>
          </div>
          <div class="loc-body">
            <p class="loc-hint">Lokasi (ruang/poli) dipakai di field <code>Encounter.location</code>. Yang "Aktif" jadi default untuk semua kunjungan.</p>

            <!-- Daftar lokasi baru -->
            <div class="loc-new">
              <input v-model="loc.newName" placeholder="Nama lokasi baru (mis. Poliklinik Mata)" @keyup.enter="registerLoc" />
              <button class="loc-btn primary" :disabled="loc.registering" @click="registerLoc">{{ loc.registering ? 'Mendaftar…' : '+ Daftarkan' }}</button>
            </div>

            <p v-if="loc.loading" class="muted">Memuat lokasi…</p>
            <p v-else-if="!loc.items.length" class="muted">Belum ada lokasi terdaftar.</p>
            <table v-else class="loc-tbl">
              <thead><tr><th>Nama</th><th>Status</th><th>Aktif</th><th></th></tr></thead>
              <tbody>
                <tr v-for="it in loc.items" :key="it.id" :class="{ inactive: it.status === 'inactive' }">
                  <td>
                    <template v-if="loc.editId === it.id">
                      <input v-model="loc.editName" class="loc-edit-inp" @keyup.enter="saveEditLoc(it)" />
                    </template>
                    <template v-else>
                      <div class="loc-name">{{ it.name }}</div>
                      <code class="loc-id">{{ it.id }}</code>
                    </template>
                  </td>
                  <td><span class="loc-st" :class="it.status">{{ it.status }}</span></td>
                  <td>
                    <span v-if="loc.activeId === it.id" class="loc-active-badge">✓ Aktif</span>
                    <button v-else class="loc-btn xs" :disabled="loc.busy === it.id || it.status === 'inactive'" @click="setActiveLoc(it)">Jadikan Aktif</button>
                  </td>
                  <td class="loc-actions">
                    <template v-if="loc.editId === it.id">
                      <button class="loc-btn xs primary" :disabled="loc.busy === it.id" @click="saveEditLoc(it)">Simpan</button>
                      <button class="loc-btn xs" @click="loc.editId = null">Batal</button>
                    </template>
                    <template v-else>
                      <button class="loc-btn xs" @click="startEditLoc(it)">Edit</button>
                      <button v-if="it.status !== 'inactive'" class="loc-btn xs danger" :disabled="loc.busy === it.id" @click="deleteLoc(it)">Nonaktifkan</button>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Teleport>

    <transition name="fade">
      <div v-if="toast.show" class="toast" :class="toast.ok ? 't-ok' : 't-fail'">{{ toast.msg }}</div>
    </transition>
  </div>
</template>

<style scoped>
.cfg { position: relative; }
.muted { color: var(--tm); font-size: 13px; }

/* 3 kolom: baris 1 = SATUSEHAT · VCLAIM · ANTREAN, baris 2 = ICARE · INACBGS. */
.cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
@media (max-width: 1100px) { .cards { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 720px)  { .cards { grid-template-columns: 1fr; } }

.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.7rem; }
.card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
.card-head h3 { margin: 0; font-size: 15px; color: var(--td); }
.sys-code { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--tm); }
.note { font-size: 12px; color: var(--tm); margin: 0; line-height: 1.4; }

.badge { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.b-empty { background: #f1f5f9; color: #64748b; }
.b-off   { background: #fef3c7; color: #92400e; }
.b-on    { background: #e0e7ff; color: #3730a3; }
.b-ok    { background: #dcfce7; color: #166534; }
.b-fail  { background: #fee2e2; color: #991b1b; }

.form { display: flex; flex-direction: column; gap: 0.5rem; }
.fld { display: flex; flex-direction: column; gap: 3px; }
.fld span { font-size: 11.5px; color: var(--tm); font-weight: 500; }
.fld input, .fld select { padding: 7px 9px; border: 1px solid var(--gb); border-radius: 7px; font-size: 13px; color: #000; background: #fff; }
.fld input:focus, .fld select:focus { outline: none; border-color: #1763d4; }
.fld .opt { font-style: normal; color: var(--tm); font-weight: 400; }

/* E-Klaim INA-CBG */
.ek-hint { font-size: 11.5px; color: var(--tm); margin: 0 0 0.2rem; line-height: 1.45; }
.ek-hint code { font-family: 'JetBrains Mono', monospace; font-size: 11px; background: var(--bs); padding: 1px 5px; border-radius: 4px; }
.ek-check { flex-direction: row; align-items: center; gap: 7px; }
.ek-check input { width: auto; }
.ek-check span { font-size: 12px; color: var(--td); }

.cred-grp { border: 1px dashed var(--gb); border-radius: 8px; padding: 0.6rem; display: flex; flex-direction: column; gap: 0.5rem; }
.cred-title { font-size: 12px; font-weight: 600; color: var(--td); display: flex; justify-content: space-between; align-items: center; }
.cred-hint { font-weight: 400; font-size: 11px; color: var(--tm); }

.card-foot { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.2rem; }
.btn { padding: 7px 12px; border-radius: 7px; font-size: 12.5px; font-weight: 600; border: 1px solid var(--gb); cursor: pointer; background: #fff; color: #000; }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.btn.primary { background: #1763d4; color: #fff; border-color: #1763d4; }
.btn.ghost { background: #fff; color: #1763d4; border-color: #1763d4; }
.btn.on { background: #166534; color: #fff; border-color: #166534; }
.btn.danger { background: #fff; color: #991b1b; border-color: #fca5a5; }

.toast { position: fixed; bottom: 24px; right: 24px; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #fff; box-shadow: 0 8px 24px rgba(15,23,42,0.18); z-index: 100; }
.t-ok { background: #166534; }
.t-fail { background: #991b1b; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* Tombol kelola lokasi di kartu SATUSEHAT */
.loc-link { align-self: flex-start; background: transparent; border: none; color: #1763d4; font-size: 12px; font-weight: 600; cursor: pointer; padding: 0; margin-top: -2px; }
.loc-link:disabled { color: var(--tm); cursor: not-allowed; }

/* Modal Kelola Lokasi */
.loc-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.45); display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 1rem; }
.loc-box { background: #fff; border-radius: 14px; width: 720px; max-width: 100%; max-height: 86vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 50px rgba(15,23,42,0.25); }
.loc-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid var(--gb); font-weight: 600; color: var(--td); font-size: 15px; }
.loc-close { border: none; background: transparent; font-size: 16px; cursor: pointer; color: var(--tm); }
.loc-body { padding: 16px 18px; overflow-y: auto; }
.loc-hint { font-size: 12px; color: var(--tm); margin: 0 0 0.9rem; line-height: 1.5; }
.loc-hint code { font-family: 'JetBrains Mono', monospace; font-size: 11px; background: var(--bs); padding: 1px 5px; border-radius: 4px; }
.loc-new { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
.loc-new input { flex: 1; padding: 7px 10px; border: 1px solid var(--gb); border-radius: 7px; font-size: 13px; color: #000; }
.loc-new input:focus { outline: none; border-color: #1763d4; }
.loc-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.loc-tbl th { text-align: left; padding: 7px 8px; background: var(--bs); color: var(--tm); font-size: 11px; text-transform: uppercase; }
.loc-tbl td { padding: 8px 8px; border-top: 1px solid var(--gb); color: #000; vertical-align: middle; }
.loc-tbl tr.inactive { opacity: 0.5; }
.loc-name { font-weight: 500; }
.loc-id { font-family: 'JetBrains Mono', monospace; font-size: 10px; color: var(--tm); }
.loc-edit-inp { width: 100%; padding: 5px 8px; border: 1px solid #1763d4; border-radius: 6px; font-size: 13px; color: #000; }
.loc-st { padding: 2px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 600; }
.loc-st.active { background: #dcfce7; color: #166534; }
.loc-st.inactive { background: #f1f5f9; color: #64748b; }
.loc-active-badge { color: #166534; font-weight: 700; font-size: 12px; }
.loc-actions { display: flex; gap: 5px; white-space: nowrap; }
.loc-btn { padding: 5px 10px; border: 1px solid var(--gb); border-radius: 6px; font-size: 11.5px; font-weight: 600; background: #fff; color: #000; cursor: pointer; }
.loc-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.loc-btn.xs { padding: 3px 8px; font-size: 11px; }
.loc-btn.primary { background: #1763d4; color: #fff; border-color: #1763d4; }
.loc-btn.danger { background: #fff; color: #991b1b; border-color: #fca5a5; }
</style>
