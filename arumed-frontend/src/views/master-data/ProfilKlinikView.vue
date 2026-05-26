<script setup>
/**
 * ProfilKlinikView — form edit singleton clinic_profiles.
 *
 * Catatan: tabel clinic_profiles BELUM punya kolom provinsi/kota/kecamatan
 * terpisah. WilayahPicker di sini bersifat **helper input** — pilih wilayah,
 * lalu klik "Tambahkan ke alamat" untuk append ke textarea address. Saat
 * skema clinic_profiles ditambah kolom wilayah, tinggal swap save handler.
 */
import { ref, onMounted, reactive } from 'vue'
import { useMasterDataStore } from '@/stores/masterDataStore'
import WilayahPicker from '@/components/master-data/WilayahPicker.vue'

const store = useMasterDataStore()

const form = reactive({
  clinic_name:       '',
  address:           '',
  phone:             '',
  email:             '',
  director_name:     '',
  director_sip:      '',
  rm_seq_length:     4,
  pdf_engine:        'puppeteer',
  watermark_enabled: false,
  watermark_type:    'ORIGINAL',
})

const wilayah = reactive({ province: '', regency: '', district: '' })

const saving = ref(false)
const errors = ref(null)
const toast = ref(null)

function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

function hydrateForm(p) {
  if (!p) return
  for (const k of Object.keys(form)) {
    if (p[k] !== undefined && p[k] !== null) form[k] = p[k]
  }
}

async function load() {
  await store.fetchProfilKlinik()
  hydrateForm(store.profilKlinik)
}

async function save() {
  saving.value = true
  errors.value = null
  try {
    await store.saveProfilKlinik({ ...form })
    showToast('s', 'Profil klinik diperbarui')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data?.errors ?? null
    }
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan profil')
  } finally {
    saving.value = false
  }
}

function appendWilayahToAddress() {
  const parts = []
  if (wilayah.district) parts.push(`Kec. ${wilayah.district}`)
  if (wilayah.regency)  parts.push(wilayah.regency)
  if (wilayah.province) parts.push(wilayah.province)

  if (parts.length === 0) {
    showToast('w', 'Pilih wilayah dulu di atas.')
    return
  }
  const segment = parts.join(', ')
  form.address = form.address
    ? `${form.address.replace(/[,\s]+$/, '')}, ${segment}`
    : segment
}

function fieldErr(key) {
  if (!errors.value) return null
  const msgs = errors.value[key]
  return Array.isArray(msgs) ? msgs[0] : msgs
}

onMounted(load)
</script>

<template>
  <div class="pk-wrap">
    <div class="pk-head">
      <h2>Profil Klinik</h2>
      <p>Data klinik akan tercetak di header dokumen, invoice, dan rekam medis.</p>
    </div>

    <div v-if="store.profilLoading" class="pk-loading">
      <span class="pk-spinner"></span> Memuat profil…
    </div>

    <div v-else-if="store.profilError" class="pk-error-banner">{{ store.profilError }}</div>

    <form v-else class="pk-form" @submit.prevent="save">
      <!-- ─── Identitas Klinik ─── -->
      <section class="pk-section">
        <header><h3>Identitas Klinik</h3></header>
        <div class="pk-grid">
          <div class="pk-field pk-col-2">
            <label>Nama Klinik <span class="pk-req">*</span></label>
            <input type="text" v-model="form.clinic_name" :class="{ 'has-error': fieldErr('clinic_name') }" />
            <p v-if="fieldErr('clinic_name')" class="pk-err">{{ fieldErr('clinic_name') }}</p>
          </div>
          <div class="pk-field pk-col-1">
            <label>Telepon</label>
            <input type="text" v-model="form.phone" placeholder="(0274) 1234567" :class="{ 'has-error': fieldErr('phone') }" />
            <p v-if="fieldErr('phone')" class="pk-err">{{ fieldErr('phone') }}</p>
          </div>
          <div class="pk-field pk-col-1">
            <label>Email</label>
            <input type="email" v-model="form.email" placeholder="info@klinik.id" :class="{ 'has-error': fieldErr('email') }" />
            <p v-if="fieldErr('email')" class="pk-err">{{ fieldErr('email') }}</p>
          </div>
          <div class="pk-field pk-col-2">
            <label>Alamat</label>
            <textarea v-model="form.address" rows="3" :class="{ 'has-error': fieldErr('address') }"></textarea>
            <p v-if="fieldErr('address')" class="pk-err">{{ fieldErr('address') }}</p>
          </div>
        </div>
      </section>

      <!-- ─── Wilayah helper ─── -->
      <section class="pk-section">
        <header>
          <h3>Wilayah</h3>
          <p class="pk-sub">Pilih wilayah, lalu klik "Tambahkan ke alamat" untuk append ke field alamat di atas. Wilayah belum disimpan terpisah di database — masuk sebagai bagian dari alamat.</p>
        </header>
        <WilayahPicker
          v-model:province="wilayah.province"
          v-model:regency="wilayah.regency"
          v-model:district="wilayah.district"
        />
        <button type="button" class="pk-btn-secondary pk-btn-inline" @click="appendWilayahToAddress">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambahkan ke alamat
        </button>
      </section>

      <!-- ─── Direktur / Penanggung Jawab ─── -->
      <section class="pk-section">
        <header><h3>Direktur / Penanggung Jawab</h3></header>
        <div class="pk-grid">
          <div class="pk-field pk-col-1">
            <label>Nama Direktur</label>
            <input type="text" v-model="form.director_name" :class="{ 'has-error': fieldErr('director_name') }" />
            <p v-if="fieldErr('director_name')" class="pk-err">{{ fieldErr('director_name') }}</p>
          </div>
          <div class="pk-field pk-col-1">
            <label>SIP Direktur</label>
            <input type="text" v-model="form.director_sip" :class="{ 'has-error': fieldErr('director_sip') }" />
            <p v-if="fieldErr('director_sip')" class="pk-err">{{ fieldErr('director_sip') }}</p>
          </div>
        </div>
      </section>

      <!-- ─── Konfigurasi Sistem ─── -->
      <section class="pk-section">
        <header><h3>Konfigurasi Dokumen</h3></header>
        <div class="pk-grid">
          <div class="pk-field pk-col-1">
            <label>Panjang Urutan No. RM</label>
            <input type="number" v-model.number="form.rm_seq_length" min="4" max="8" :class="{ 'has-error': fieldErr('rm_seq_length') }" />
            <p class="pk-hint">4–8 digit (mis. 4 → 0001, 6 → 000001)</p>
          </div>
          <div class="pk-field pk-col-1">
            <label>PDF Engine</label>
            <select v-model="form.pdf_engine">
              <option value="puppeteer">Puppeteer</option>
            </select>
          </div>
          <div class="pk-field pk-col-1">
            <label class="pk-check">
              <input type="checkbox" v-model="form.watermark_enabled" />
              <span>Aktifkan watermark di cetakan</span>
            </label>
          </div>
          <div class="pk-field pk-col-1">
            <label>Tipe Watermark</label>
            <select v-model="form.watermark_type" :disabled="!form.watermark_enabled">
              <option value="ORIGINAL">ORIGINAL</option>
              <option value="COPY">COPY</option>
              <option value="DRAFT">DRAFT</option>
            </select>
          </div>
        </div>
      </section>

      <!-- Footer actions -->
      <div class="pk-actions">
        <button type="button" class="pk-btn-secondary" @click="load" :disabled="saving">Reset</button>
        <button type="submit" class="pk-btn-primary" :disabled="saving">
          <span v-if="saving" class="pk-spinner pk-spinner-light"></span>
          {{ saving ? 'Menyimpan…' : 'Simpan Profil' }}
        </button>
      </div>
    </form>

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toast" class="pk-toast-wrap">
        <div class="pk-toast" :class="`pk-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.pk-wrap { display: flex; flex-direction: column; gap: 1.2rem; }

.pk-head h2 { font-family: 'DM Serif Display', serif; font-size: 20px; color: var(--td); margin: 0; }
.pk-head p { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

.pk-loading { display: flex; align-items: center; gap: 0.5rem; padding: 2rem; justify-content: center; color: var(--tu); font-size: 13px; }
.pk-error-banner { padding: 0.8rem 1rem; background: var(--eb); border: 1px solid var(--ebd); border-radius: 10px; color: var(--et); font-size: 13px; }

.pk-form { display: flex; flex-direction: column; gap: 1.2rem; }

.pk-section { background: var(--bs); border: 1px solid var(--gb); border-radius: 12px; padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.9rem; }
.pk-section header h3 { font-size: 14px; color: var(--td); margin: 0; font-weight: 600; }
.pk-section header .pk-sub { font-size: 12px; color: var(--tu); margin: 4px 0 0; line-height: 1.5; }

.pk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem 1rem; }
@media (max-width: 700px) { .pk-grid { grid-template-columns: 1fr; } }

.pk-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.pk-col-2 { grid-column: 1 / -1; }
.pk-field label { font-size: 11px; font-weight: 600; color: var(--tm); text-transform: uppercase; letter-spacing: 0.03em; }
.pk-req { color: var(--et); }

.pk-field input,
.pk-field select,
.pk-field textarea {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid var(--gb);
  border-radius: 9px;
  background: var(--bc);
  font-size: 13px;
  color: var(--td);
  font-family: inherit;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.pk-field input:focus,
.pk-field select:focus,
.pk-field textarea:focus {
  outline: none;
  border-color: var(--ga);
  box-shadow: 0 0 0 3px rgba(31,125,74,0.12);
}
.pk-field input:disabled,
.pk-field select:disabled,
.pk-field textarea:disabled { background: var(--bs); color: var(--tu); cursor: not-allowed; }
.pk-field textarea { resize: vertical; min-height: 70px; }
.pk-field input.has-error,
.pk-field select.has-error,
.pk-field textarea.has-error { border-color: var(--ebd); background: var(--eb); }

.pk-check { display: flex !important; align-items: center; gap: 8px; flex-direction: row !important; text-transform: none !important; letter-spacing: normal !important; font-size: 13px !important; color: var(--td) !important; font-weight: 400 !important; cursor: pointer; padding-top: 22px; }
.pk-check input { width: 16px; height: 16px; accent-color: var(--ga); margin: 0; }

.pk-err { font-size: 11px; color: var(--et); margin: 0; }
.pk-hint { font-size: 11px; color: var(--tu); margin: 0; }

.pk-actions { display: flex; justify-content: flex-end; gap: 0.7rem; padding-top: 0.3rem; }
.pk-btn-primary,
.pk-btn-secondary { padding: 9px 18px; border-radius: 9px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid; display: inline-flex; align-items: center; gap: 7px; transition: background 0.15s; }
.pk-btn-primary { background: var(--ga); color: white; border-color: var(--ga); }
.pk-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.pk-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.pk-btn-secondary { background: var(--bc); color: var(--tm); border-color: var(--gb); }
.pk-btn-secondary:hover:not(:disabled) { background: var(--bs); }
.pk-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
.pk-btn-inline { align-self: flex-start; padding: 7px 12px; font-size: 12px; }
.pk-btn-inline svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.pk-spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid var(--gb); border-top-color: var(--ga); border-radius: 50%; animation: pk-spin 0.7s linear infinite; }
.pk-spinner-light { border-color: rgba(255,255,255,0.4); border-top-color: white; }
@keyframes pk-spin { to { transform: rotate(360deg); } }

.pk-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.pk-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; animation: pk-toast-in 0.2s ease; }
.pk-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.pk-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.pk-toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
@keyframes pk-toast-in { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
