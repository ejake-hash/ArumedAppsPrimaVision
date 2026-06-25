<script setup>
/**
 * IcareModal — viewer riwayat pelayanan i-Care JKN BPJS.
 *
 * Alur: (1) layar INFORMED CONSENT pasien wajib disetujui dulu → (2) panggil
 * `loader()` yang mengembালikan URL viewer dari BPJS → (3) tampilkan dalam
 * <iframe>. Token URL bersifat sekali pakai, jadi URL selalu di-generate
 * on-demand tiap modal dibuka (tidak di-cache). Bila iframe diblokir BPJS
 * (X-Frame-Options/CSP), tersedia tombol "Buka di tab baru".
 *
 * Props:
 *   - open        : boolean — tampil/tutup (v-model:open)
 *   - patientName : string  — nama pasien (untuk teks consent)
 *   - loader      : () => Promise<axiosResponse>  — pemanggil endpoint i-Care;
 *                   diharapkan resolve ke res.data.data.url
 *
 * Emit:
 *   - 'update:open' : boolean
 */
import { ref, watch } from 'vue'

const props = defineProps({
  open:        { type: Boolean, default: false },
  patientName: { type: String, default: '' },
  loader:      { type: Function, required: true },
})
const emit = defineEmits(['update:open'])

const step = ref('consent') // consent | loading | view | error
const url = ref('')
const errorMsg = ref('')

// Reset tiap kali dibuka (state tidak boleh bocor antar pasien / token kedaluwarsa).
watch(() => props.open, (isOpen) => {
  if (isOpen) {
    step.value = 'consent'
    url.value = ''
    errorMsg.value = ''
  }
})

async function setuju() {
  step.value = 'loading'
  errorMsg.value = ''
  try {
    const res = await props.loader()
    const u = res?.data?.data?.url ?? res?.data?.url ?? ''
    if (!u) throw new Error('URL riwayat i-Care tidak diterima dari BPJS.')
    url.value = u
    step.value = 'view'
  } catch (err) {
    errorMsg.value = err?.response?.data?.message ?? err?.message ?? 'Gagal mengambil riwayat i-Care.'
    step.value = 'error'
  }
}

function bukaTabBaru() {
  if (url.value) window.open(url.value, '_blank', 'noopener')
}

function close() {
  emit('update:open', false)
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="ic-overlay" @click.self="close">
      <div class="ic-modal" :class="{ 'ic-modal--wide': step === 'view' }">
        <header class="ic-head">
          <h3>Riwayat i-Care JKN<span v-if="patientName"> — {{ patientName }}</span></h3>
          <button class="ic-x" aria-label="Tutup" @click="close">×</button>
        </header>

        <!-- 1. Informed consent -->
        <div v-if="step === 'consent'" class="ic-body ic-consent">
          <div class="ic-consent-icon">🔒</div>
          <p class="ic-consent-title">Persetujuan Pasien (Informed Consent)</p>
          <p class="ic-consent-text">
            Riwayat pelayanan kesehatan peserta JKN selama 1 tahun terakhir (lintas faskes)
            akan ditampilkan dari BPJS Kesehatan. Akses ini hanya boleh dilakukan oleh tenaga
            medis berwenang <strong>atas persetujuan pasien</strong>.
          </p>
          <p class="ic-consent-text">
            Pastikan pasien <strong>{{ patientName || 'ini' }}</strong> telah menyetujui aksesnya.
          </p>
        </div>

        <!-- 2. Loading -->
        <div v-else-if="step === 'loading'" class="ic-body ic-center">
          <div class="ic-spinner" /> <p>Mengambil riwayat dari BPJS…</p>
        </div>

        <!-- 3. Viewer -->
        <div v-else-if="step === 'view'" class="ic-body ic-frame-wrap">
          <iframe :src="url" class="ic-frame" title="Riwayat i-Care JKN" referrerpolicy="no-referrer" />
        </div>

        <!-- 4. Error -->
        <div v-else class="ic-body ic-center">
          <div class="ic-err-icon">⚠️</div>
          <p class="ic-err">{{ errorMsg }}</p>
        </div>

        <footer class="ic-foot">
          <button class="ic-btn-ghost" @click="close">Tutup</button>
          <button v-if="step === 'consent'" class="ic-btn-primary" @click="setuju">Setuju &amp; Tampilkan</button>
          <button v-if="step === 'error'" class="ic-btn-primary" @click="setuju">Coba lagi</button>
          <button v-if="step === 'view'" class="ic-btn-primary" @click="bukaTabBaru">Buka di tab baru</button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.ic-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center; z-index: 1100;
}
.ic-modal {
  background: #fff; border-radius: 12px; width: min(520px, 94vw);
  max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;
  box-shadow: 0 10px 40px rgba(0,0,0,0.25);
}
.ic-modal--wide { width: min(1040px, 96vw); height: 88vh; }
.ic-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; border-bottom: 1px solid #e5e7eb;
}
.ic-head h3 { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; }
.ic-x { border: 0; background: transparent; font-size: 1.6rem; line-height: 1; cursor: pointer; color: #64748b; }
.ic-body { padding: 18px; overflow: auto; flex: 1; }
.ic-center { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; text-align: center; min-height: 200px; }
.ic-consent { text-align: center; }
.ic-consent-icon { font-size: 2.4rem; }
.ic-consent-title { font-weight: 700; font-size: 1.05rem; margin: 8px 0 12px; color: #0f172a; }
.ic-consent-text { color: #475569; line-height: 1.6; margin: 0 auto 10px; max-width: 420px; }
.ic-frame-wrap { padding: 0; }
.ic-frame { width: 100%; height: 100%; border: 0; }
.ic-err-icon { font-size: 2.2rem; }
.ic-err { color: #b91c1c; max-width: 420px; }
.ic-spinner {
  width: 36px; height: 36px; border-radius: 50%;
  border: 4px solid #e2e8f0; border-top-color: #2563eb; animation: ic-spin 0.8s linear infinite;
}
@keyframes ic-spin { to { transform: rotate(360deg); } }
.ic-foot {
  display: flex; justify-content: flex-end; gap: 10px;
  padding: 12px 18px; border-top: 1px solid #e5e7eb; background: #f8fafc;
}
.ic-btn-ghost, .ic-btn-primary { padding: 8px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
.ic-btn-ghost { background: #fff; border-color: #cbd5e1; color: #334155; }
.ic-btn-primary { background: #2563eb; color: #fff; }
.ic-btn-primary:hover { background: #1d4ed8; }
</style>
