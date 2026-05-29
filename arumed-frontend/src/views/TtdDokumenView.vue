<script setup>
/**
 * TtdDokumenView — halaman antrian TTD dokumen untuk dokter yang login.
 *
 * Backend filter: dokumen status PENDING_SIGNATURE yang punya field
 * signature_canvas signer_type='doctor' yang belum di-TTD oleh dokter ini.
 * Grouped by patient. Sesuai design doc Section 9.3.
 */
import { computed, onMounted, ref } from 'vue'
import { formTemplateApi } from '@/services/api'
import SignatureCaptureModal from '@/components/forms/signature/SignatureCaptureModal.vue'

const groups   = ref([])     // [{ patient, documents:[{id, template_code, ...}] }]
const loading  = ref(false)
const error    = ref('')

const modalOpen = ref(false)        // preview overlay
const captureOpen = ref(false)       // signature capture modal
const currentDoc = ref(null)
const previewHtml = ref('')
const previewLoading = ref(false)

const total = computed(() => groups.value.reduce((sum, g) => sum + (g.documents?.length ?? 0), 0))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await formTemplateApi.ttdQueue()
    groups.value = data.data ?? []
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat antrian.'
  } finally {
    loading.value = false
  }
}

async function openTtd(doc) {
  currentDoc.value = doc
  modalOpen.value = true
  previewHtml.value = ''
  previewLoading.value = true
  try {
    const { data } = await formTemplateApi.snapshot(doc.id)
    previewHtml.value = data.data?.rendered_html ?? ''
    // Kalau snapshot kosong (belum FINALIZED) — render dari template via code untuk preview.
    if (!previewHtml.value && doc.template_code && doc.visit_id) {
      const r = await formTemplateApi.renderForm(doc.template_code, doc.visit_id)
      previewHtml.value = r.data.data?.html ?? ''
    }
  } catch (e) {
    previewHtml.value = '<p style="color:#b42323">(Gagal render preview)</p>'
  } finally {
    previewLoading.value = false
  }
}

async function onCapture(payload) {
  if (!currentDoc.value) return
  try {
    const userJson = localStorage.getItem('auth_user')
    let userId = null
    try { userId = JSON.parse(userJson ?? '{}')?.id ?? null } catch (_) {}

    await formTemplateApi.sign(currentDoc.value.id, {
      signer_type: 'doctor',
      signer_user_id: userId,
      signature_svg: payload.signature_svg,
      signature_png_base64: payload.signature_png_base64,
      biometric_metadata: payload.biometric_metadata,
      audit_log: payload.audit_log,
    })
    alert('Dokumen telah ditandatangani.')
    captureOpen.value = false
    modalOpen.value = false
    currentDoc.value = null
    await load()
  } catch (e) {
    alert('Gagal: ' + (e.response?.data?.message ?? e.message))
  }
}

function formatTime(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const mins = Math.floor((Date.now() - d.getTime()) / 60000)
  if (mins < 1)  return 'baru saja'
  if (mins < 60) return `${mins} menit lalu`
  const hours = Math.floor(mins / 60)
  if (hours < 24) return `${hours} jam lalu`
  return d.toLocaleDateString('id-ID')
}

onMounted(load)
</script>

<template>
  <div class="ttd-wrap">
    <header class="ttd-head">
      <div>
        <h1>Tanda Tangan Dokumen</h1>
        <p class="ttd-sub">{{ total }} dokumen menunggu tanda tangan Anda</p>
      </div>
      <button class="ttd-btn-ghost" @click="load" :disabled="loading">↻ Refresh</button>
    </header>

    <div v-if="loading && groups.length === 0" class="ttd-state">Memuat antrian…</div>
    <div v-else-if="error" class="ttd-state ttd-err">{{ error }}</div>
    <div v-else-if="groups.length === 0" class="ttd-state ttd-empty">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
      <p>Antrian tanda tangan kosong.</p>
      <p class="ttd-sub">Semua dokumen sudah Anda tanda tangani.</p>
    </div>

    <div v-else class="ttd-groups">
      <article v-for="g in groups" :key="g.patient.id" class="ttd-group">
        <header class="ttd-patient">
          <div class="ttd-patient-avatar">{{ (g.patient.name || '?').slice(0,1).toUpperCase() }}</div>
          <div>
            <strong>{{ g.patient.name }}</strong>
            <span class="ttd-patient-meta">No.RM {{ g.patient.no_rm ?? '—' }} · {{ g.patient.gender === 'L' ? 'L' : 'P' }}</span>
          </div>
          <span class="ttd-count">{{ g.documents.length }} dokumen</span>
        </header>
        <ul class="ttd-doc-list">
          <li v-for="d in g.documents" :key="d.id" class="ttd-doc">
            <div class="ttd-doc-main">
              <strong>{{ d.template_code }}</strong>
              <span class="ttd-doc-meta">
                {{ d.status }} · {{ d.signature_count }} TTD ter-capture · {{ formatTime(d.created_at) }}
              </span>
            </div>
            <button class="ttd-btn-primary" @click="openTtd(d)">Tanda Tangan</button>
          </li>
        </ul>
      </article>
    </div>

    <!-- Preview + capture modal -->
    <Teleport to="body">
      <div v-if="modalOpen && currentDoc" class="ttd-preview-overlay" @click.self="modalOpen = false">
        <div class="ttd-preview-modal">
          <header class="ttd-preview-head">
            <div>
              <h3>Preview Dokumen</h3>
              <p class="ttd-sub">{{ currentDoc.template_code }}</p>
            </div>
            <button class="ttd-btn-ghost" @click="modalOpen = false">Tutup</button>
          </header>
          <div class="ttd-preview-body">
            <div v-if="previewLoading" class="ttd-state">Memuat preview…</div>
            <div v-else v-html="previewHtml"></div>
          </div>
          <footer class="ttd-preview-foot">
            <button class="ttd-btn-primary" @click="captureOpen = true">Tanda Tangani Dokumen</button>
          </footer>
        </div>
      </div>
    </Teleport>

    <SignatureCaptureModal
      v-if="currentDoc"
      v-model="captureOpen"
      signer-type="doctor"
      signer-label="Tanda Tangan Dokter"
      :document-name="currentDoc.template_code"
      @capture="onCapture"
    />
  </div>
</template>

<style scoped>
.ttd-wrap { padding: 1.5rem 2rem; display: flex; flex-direction: column; gap: 1rem; max-width: 1100px; }

.ttd-head { display: flex; justify-content: space-between; align-items: flex-end; }
.ttd-head h1 { margin: 0; font-family: 'DM Serif Display', serif; font-size: 24px; color: var(--td); }
.ttd-sub { margin: 4px 0 0; color: var(--tm); font-size: 13px; }

.ttd-btn-ghost { padding: 0.45rem 1rem; border: 1px solid var(--gb); border-radius: 6px; background: white; cursor: pointer; font-size: 13px; }
.ttd-btn-ghost:hover { background: var(--bg); }

.ttd-state {
  padding: 2.5rem 1rem; text-align: center; color: var(--tm);
  display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
}
.ttd-err { color: #b42323; }
.ttd-empty svg { color: #1e8a3a; }
.ttd-empty p { margin: 0; }

.ttd-groups { display: flex; flex-direction: column; gap: 1rem; }
.ttd-group {
  background: var(--bc); border: 1px solid var(--gb); border-radius: 10px;
  padding: 0.85rem 1rem;
}
.ttd-patient {
  display: flex; align-items: center; gap: 0.75rem;
  padding-bottom: 0.75rem; border-bottom: 1px solid var(--gb);
  margin-bottom: 0.5rem;
}
.ttd-patient-avatar {
  width: 38px; height: 38px; border-radius: 50%;
  background: var(--pri); color: white;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 15px;
}
.ttd-patient strong { display: block; font-size: 14.5px; color: var(--td); }
.ttd-patient-meta { font-size: 11.5px; color: var(--tm); }
.ttd-count {
  margin-left: auto; padding: 3px 10px; border-radius: 999px;
  background: #fff7ec; color: #b46f00; font-size: 11.5px; font-weight: 700;
}

.ttd-doc-list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.4rem; }
.ttd-doc {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.55rem 0.75rem; background: var(--bg); border-radius: 6px;
}
.ttd-doc-main strong { display: block; font-size: 13.5px; font-family: monospace; color: var(--td); }
.ttd-doc-meta { font-size: 11.5px; color: var(--tm); }
.ttd-btn-primary {
  padding: 0.45rem 1rem; background: var(--pri); color: white; border: 0; border-radius: 6px;
  cursor: pointer; font-size: 13px; font-weight: 600;
}
.ttd-btn-primary:hover { filter: brightness(1.08); }

.ttd-preview-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center; z-index: 1050;
}
.ttd-preview-modal {
  width: min(880px, 96vw); max-height: 92vh;
  background: white; border-radius: 10px; overflow: hidden;
  display: flex; flex-direction: column;
}
.ttd-preview-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--gb);
}
.ttd-preview-head h3 { margin: 0; font-family: 'DM Serif Display', serif; font-size: 17px; }
.ttd-preview-body { padding: 1.5rem 2rem; overflow-y: auto; flex: 1; background: #fafafa; }
.ttd-preview-foot { padding: 0.85rem 1.25rem; border-top: 1px solid var(--gb); display: flex; justify-content: center; }
</style>
