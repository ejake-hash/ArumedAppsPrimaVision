<script setup>
/**
 * FormTemplateWizard — shell 3-step untuk create/edit template.
 *
 * Route:
 *   /master-data/form-template/new     → mulai dari Step 1 (upload)
 *   /master-data/form-template/:id     → mulai dari Step 2 (load existing → edit)
 *
 * State wizard di-keep di komponen ini (bukan store) supaya navigate-away tidak
 * leak draft ke template lain. Persist baru di Step 3 "Simpan & Aktifkan".
 *
 * Catatan: gunakan v-if (BUKAN v-show) untuk switch step di dalam Transition —
 * v-show + Transition bisa freeze child component (lihat feedback memory).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFormTemplateStore } from '@/stores/formTemplateStore'
import UploadStep from './UploadStep.vue'
import MapperStep from './MapperStep.vue'
import AssignmentStep from './AssignmentStep.vue'

const route  = useRoute()
const router = useRouter()
const store  = useFormTemplateStore()

const isEdit = computed(() => !!route.params.id)
const currentStep = ref(isEdit.value ? 2 : 1)

// Wizard draft (single source of truth saat di wizard)
const draft = ref({
  id: null,
  code: '',
  name: '',
  document_type_id: null,
  kind: 'OUTPUT',
  complexity_kind: 'SIMPLE_BINDING',
  layout_html: '',
  field_schema: { layout_mode: 'single_page', fields: [] },
  station_assignments: [],
  version: 1,
  is_active: false,
  code_locked_at: null,
})

const loading = ref(false)

onMounted(async () => {
  await Promise.all([
    store.ensureFieldRegistry(),
    store.ensureStationSections(),
    store.ensureDocumentTypes(),
  ])

  if (isEdit.value) {
    loading.value = true
    try {
      const t = await store.fetchOne(route.params.id)
      draft.value = {
        id: t.id,
        code: t.code,
        name: t.name,
        document_type_id: t.document_type_id,
        kind: t.kind ?? 'OUTPUT',
        complexity_kind: t.complexity_kind ?? 'SIMPLE_BINDING',
        layout_html: t.layout_html ?? '',
        field_schema: t.field_schema ?? { layout_mode: 'single_page', fields: [] },
        station_assignments: t.station_assignments ?? [],
        version: t.version ?? 1,
        is_active: !!t.is_active,
        code_locked_at: t.code_locked_at,
      }
    } catch (e) {
      alert('Gagal load template: ' + (e.response?.data?.message ?? e.message))
      router.push({ name: 'master-form-template' })
    } finally {
      loading.value = false
    }
  }
})

// Step 1 → Step 2: parser draft applied ke wizard draft
function onParseComplete(parserDraft, sourceFilePath) {
  draft.value.code = parserDraft.suggested_code ?? draft.value.code
  draft.value.name = parserDraft.suggested_name ?? draft.value.name
  draft.value.layout_html = parserDraft.layout_html ?? ''
  draft.value.field_schema = {
    layout_mode: 'single_page',
    fields: (parserDraft.fields ?? []).map(f => ({
      key: f.key,
      label: f.label,
      type: f.type,
      binding: f.binding ?? { kind: 'static', value: null },
      // suggestion utility — di-strip saat submit
      _suggestion: f.binding_suggestion ?? null,
    })),
  }
  draft.value._source_file_path = sourceFilePath
  currentStep.value = 2
}

function onStepBack() {
  if (currentStep.value > 1) currentStep.value -= 1
}
function onStepNext() {
  if (currentStep.value < 3) currentStep.value += 1
}

function onCancel() {
  if (confirm('Batalkan perubahan wizard? Draft yang belum disimpan akan hilang.')) {
    router.push({ name: 'master-form-template' })
  }
}

watch(() => route.params.id, (id) => {
  if (!id && currentStep.value !== 1) currentStep.value = 1
})

// Step 3 "Simpan" — convert draft → API payload (strip helper props), POST/PUT.
async function persistDraft({ thenActivate = false } = {}) {
  const payload = {
    document_type_id: draft.value.document_type_id,
    name: draft.value.name,
    code: draft.value.code,
    kind: draft.value.kind,
    complexity_kind: draft.value.complexity_kind,
    layout_html: draft.value.layout_html,
    field_schema: stripHelperProps(draft.value.field_schema),
    station_assignments: draft.value.station_assignments,
    page_size: 'A4',
    orientation: 'portrait',
    source_file_path: draft.value._source_file_path ?? null,
  }

  try {
    let saved
    if (draft.value.id) {
      saved = await store.update(draft.value.id, payload)
    } else {
      saved = await store.create(payload)
      draft.value.id = saved.id
    }

    if (thenActivate) {
      await store.activate(saved.id)
    }

    return saved
  } catch (e) {
    alert('Gagal simpan: ' + (e.response?.data?.message ?? e.message))
    throw e
  }
}

function stripHelperProps(schema) {
  return {
    ...schema,
    fields: (schema.fields ?? []).map(({ _suggestion, ...keep }) => keep),
  }
}

defineExpose({ persistDraft })
</script>

<template>
  <div class="wz-wrap">
    <header class="wz-head">
      <div>
        <h2>{{ isEdit ? 'Edit Form Template' : 'Buat Form Template Baru' }}</h2>
        <p class="wz-sub" v-if="draft.code">{{ draft.code }} — v{{ draft.version }}</p>
      </div>
      <button class="wz-btn-ghost" @click="onCancel">Batal</button>
    </header>

    <!-- Step indicator -->
    <ol class="wz-steps">
      <li :class="{ active: currentStep === 1, done: currentStep > 1 }" @click="!isEdit && (currentStep = 1)">
        <span class="wz-step-num">1</span>
        <span>Upload &amp; Parse</span>
      </li>
      <li :class="{ active: currentStep === 2, done: currentStep > 2 }" @click="currentStep = 2">
        <span class="wz-step-num">2</span>
        <span>Identitas &amp; Binding</span>
      </li>
      <li :class="{ active: currentStep === 3 }" @click="currentStep = 3">
        <span class="wz-step-num">3</span>
        <span>Station &amp; Aktivasi</span>
      </li>
    </ol>

    <div v-if="loading" class="wz-loading">Memuat template…</div>

    <section v-else-if="currentStep === 1">
      <UploadStep @complete="onParseComplete" />
    </section>

    <section v-else-if="currentStep === 2">
      <MapperStep
        v-model="draft"
        @back="onStepBack"
        @next="onStepNext"
      />
    </section>

    <section v-else-if="currentStep === 3">
      <AssignmentStep
        v-model="draft"
        @back="onStepBack"
        @save="persistDraft({ thenActivate: false }).then(() => router.push({ name: 'master-form-template' }))"
        @save-and-activate="persistDraft({ thenActivate: true }).then(() => router.push({ name: 'master-form-template' }))"
      />
    </section>
  </div>
</template>

<style scoped>
.wz-wrap { display: flex; flex-direction: column; gap: 1.25rem; }
.wz-head { display: flex; justify-content: space-between; align-items: flex-end; }
.wz-head h2 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 22px; color: var(--td); }
.wz-sub { margin: 4px 0 0; color: var(--tm); font-size: 13px; }

.wz-btn-ghost {
  padding: 0.5rem 1rem; border: 1px solid var(--gb); border-radius: 6px; background: var(--bc);
  cursor: pointer; font-size: 13.5px; color: var(--tm);
}

.wz-steps {
  display: flex; gap: 0; list-style: none; padding: 0; margin: 0;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; overflow: hidden;
}
.wz-steps li {
  flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
  padding: 0.85rem 1rem; font-size: 13.5px; color: var(--tm); cursor: pointer;
  border-right: 1px solid var(--gb); transition: all 150ms;
}
.wz-steps li:last-child { border-right: 0; }
.wz-steps li.active { background: var(--pri); color: white; }
.wz-steps li.done { background: #ecf9ee; color: #1e8a3a; }
.wz-step-num {
  display: inline-flex; width: 22px; height: 22px; border-radius: 50%;
  background: white; color: var(--td); align-items: center; justify-content: center;
  font-weight: 700; font-size: 12px;
}
.wz-steps li.active .wz-step-num { background: white; color: var(--pri); }
.wz-steps li.done .wz-step-num { background: #1e8a3a; color: white; }

.wz-loading { padding: 3rem; text-align: center; color: var(--tm); }
</style>
