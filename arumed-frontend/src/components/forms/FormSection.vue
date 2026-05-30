<script setup>
/**
 * FormSection — runtime loader form per (station, section, visit).
 *
 * Pakai di station Vue:
 *   <FormSection station="dokter" section="surat" :visit-id="visitId" title="Surat-Surat" />
 *
 * Akan fetch /rekam-medis/forms dan render list FormRMRenderer untuk tiap
 * template yang ter-assign ke (station, section). Auto-hide kalau tidak ada
 * template aktif (cleaner UI di station yang belum onboarding form).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { formTemplateApi } from '@/services/api'
import FormRMRenderer from './FormRMRenderer.vue'

const props = defineProps({
  station: { type: String, required: true },
  section: { type: String, required: true },
  visitId: { type: String, required: true },
  // Pass-through ke FormRMRenderer → SignatureService.capture()
  patientId: { type: String, default: null },
  title:    { type: String, default: '' },
  subtitle: { type: String, default: '' },
  hideIfEmpty: { type: Boolean, default: true },
})

const forms = ref([])
const loading = ref(false)
const error = ref('')

// Mapping section → meta default (icon + subtitle) supaya tiap section
// punya identitas visual. Bisa di-override via prop subtitle.
const SECTION_META = {
  resume_output: { icon: 'clipboard', subtitle: 'Ringkasan kunjungan untuk dibawa pasien pulang' },
  surat:         { icon: 'mail',      subtitle: 'Surat sakit, rujukan, keterangan sehat' },
  consent:       { icon: 'shield',    subtitle: 'Informed consent & persetujuan tindakan' },
  identitas:     { icon: 'user',      subtitle: 'Data identitas pasien & wali' },
}

const meta = computed(() => SECTION_META[props.section] ?? { icon: 'file', subtitle: '' })
const resolvedSubtitle = computed(() => props.subtitle || meta.value.subtitle)

async function load() {
  if (!props.visitId) return
  loading.value = true
  error.value = ''
  try {
    const { data } = await formTemplateApi.forms({
      station: props.station,
      section: props.section,
      visit_id: props.visitId,
    })
    forms.value = data.data ?? []
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal load forms.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [props.station, props.section, props.visitId], load)
</script>

<template>
  <section v-if="!hideIfEmpty || loading || forms.length > 0" class="fs-wrap">
    <header v-if="title" class="fs-head">
      <span class="fs-icon" :class="`fs-icon-${meta.icon}`" aria-hidden="true">
        <svg v-if="meta.icon === 'clipboard'" viewBox="0 0 24 24"><path d="M9 2h6a2 2 0 012 2v2H7V4a2 2 0 012-2z"/><path d="M7 4H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V6a2 2 0 00-2-2h-2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>
        <svg v-else-if="meta.icon === 'mail'" viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <svg v-else-if="meta.icon === 'shield'" viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><polyline points="9 12 11 14 15 10"/></svg>
        <svg v-else-if="meta.icon === 'user'" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <svg v-else viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      </span>
      <div class="fs-titles">
        <h3>{{ title }}</h3>
        <p v-if="resolvedSubtitle" class="fs-sub">{{ resolvedSubtitle }}</p>
      </div>
      <span class="fs-count" v-if="forms.length">{{ forms.length }}</span>
    </header>

    <div v-if="loading" class="fs-state fs-loading">
      <span class="fs-spin" aria-hidden="true"></span>
      Memuat dokumen…
    </div>
    <div v-else-if="error" class="fs-state fs-error">{{ error }}</div>
    <div v-else-if="forms.length === 0" class="fs-state fs-empty">
      <svg class="fs-empty-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <div>
        <div class="fs-empty-title">Belum ada template aktif</div>
        <div class="fs-empty-hint">Tambahkan template di <em>Master Form RM</em> untuk section ini.</div>
      </div>
    </div>

    <div v-else class="fs-list">
      <FormRMRenderer
        v-for="t in forms"
        :key="t.id"
        :template="t"
        :visit-id="visitId"
        :patient-id="patientId"
      />
    </div>
  </section>
</template>

<style scoped>
.fs-wrap {
  background: var(--bc);
  border: 1px solid var(--gb);
  border-radius: 12px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
  overflow: hidden;
}

.fs-head {
  display: flex; align-items: center; gap: 0.7rem;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid var(--gb);
  background: linear-gradient(180deg, var(--bs) 0%, var(--bc) 100%);
}
.fs-icon {
  flex-shrink: 0; width: 34px; height: 34px; border-radius: 9px;
  display: inline-flex; align-items: center; justify-content: center;
  background: #eef6ff; color: #1763d4;
}
.fs-icon svg {
  width: 17px; height: 17px; fill: none; stroke: currentColor;
  stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.fs-icon-clipboard { background: #eef6ff; color: #1763d4; }
.fs-icon-mail      { background: #fff7ec; color: #b46f00; }
.fs-icon-shield    { background: #ecf9ee; color: #1e6a35; }
.fs-icon-user      { background: #f0ecff; color: #5d3fc9; }

.fs-titles { flex: 1; min-width: 0; }
.fs-titles h3 {
  margin: 0; font-size: 14.5px;
  font-family: 'Space Grotesk', serif;
  color: var(--td); line-height: 1.2;
}
.fs-sub {
  margin: 2px 0 0; font-size: 11.5px; color: var(--tm); line-height: 1.3;
}
.fs-count {
  flex-shrink: 0;
  padding: 2px 9px; border-radius: 999px;
  background: #eef6ff; color: #1763d4;
  font-size: 11px; font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.fs-state {
  padding: 0.85rem 1rem;
  font-size: 12.5px; color: var(--tm);
}
.fs-loading {
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.fs-spin {
  width: 12px; height: 12px; border-radius: 50%;
  border: 2px solid var(--gb); border-top-color: #1763d4;
  animation: fs-spin 0.7s linear infinite;
}
@keyframes fs-spin { to { transform: rotate(360deg); } }
.fs-error {
  color: #b42323;
  background: #fff0f0;
  border-top: 1px solid #fbb;
}
.fs-empty {
  display: flex; align-items: center; gap: 0.7rem;
  padding: 0.85rem 1rem;
  background: var(--bs);
  border-top: 1px dashed var(--gb);
}
.fs-empty-icon {
  width: 28px; height: 28px; flex-shrink: 0;
  fill: none; stroke: var(--th);
  stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round;
}
.fs-empty-title {
  font-size: 12.5px; font-weight: 600; color: var(--tm); line-height: 1.25;
}
.fs-empty-hint {
  margin-top: 2px; font-size: 11px; color: var(--th); line-height: 1.3;
}
.fs-empty-hint em { color: var(--tm); font-style: normal; font-weight: 600; }

.fs-list {
  display: flex; flex-direction: column; gap: 0.5rem;
  padding: 0.6rem 0.75rem 0.75rem;
}
</style>
