<script setup>
/**
 * BindingPickerModal — pilih binding untuk satu field di MapperStep.
 *
 * Props:
 *   - modelValue : boolean (v-model open/close)
 *   - field      : { key, label, type, binding, _suggestion? }
 *   - registry   : { columns: {...}, aggregates: {...} } dari FieldRegistry endpoint
 *
 * Emit:
 *   - update:modelValue : close
 *   - select : binding object baru → parent terapkan ke field
 */
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  field:      { type: Object, default: () => ({}) },
  registry:   { type: Object, default: () => ({ columns: {}, aggregates: {} }) },
})
const emit = defineEmits(['update:modelValue', 'select'])

const tab = ref('db')      // db | aggregate | clinic | static
const search = ref('')

// Seed tab dari binding existing saat modal dibuka
watch(() => props.modelValue, (open) => {
  if (!open) return
  const kind = props.field?.binding?.kind
  tab.value = (kind === 'clinic' || kind === 'aggregate' || kind === 'static') ? kind : 'db'
  search.value = ''
})

const dbOptions = computed(() => {
  const out = []
  const cols = props.registry?.columns ?? {}
  for (const [resource, fields] of Object.entries(cols)) {
    if (resource === 'clinic') continue   // clinic handled di tab terpisah
    for (const [key, meta] of Object.entries(fields)) {
      out.push({
        path: `${resource}.${key}`,
        label: meta.label,
        type: meta.type,
        resource,
      })
    }
  }
  return out
})

const clinicOptions = computed(() => {
  const out = []
  const cols = props.registry?.columns?.clinic ?? {}
  for (const [key, meta] of Object.entries(cols)) {
    out.push({ path: `clinic.${key}`, label: meta.label, type: meta.type })
  }
  return out
})

const aggregateOptions = computed(() => {
  const out = []
  for (const [key, meta] of Object.entries(props.registry?.aggregates ?? {})) {
    for (const fmt of (meta.formats ?? [null])) {
      out.push({
        source: key,
        format: fmt,
        label: meta.label + (fmt ? ` — ${fmt}` : ''),
      })
    }
  }
  return out
})

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  const filterFn = (item) => {
    if (!q) return true
    const hay = `${item.label} ${item.path ?? ''} ${item.source ?? ''}`.toLowerCase()
    return hay.includes(q)
  }
  if (tab.value === 'db')        return dbOptions.value.filter(filterFn)
  if (tab.value === 'clinic')    return clinicOptions.value.filter(filterFn)
  if (tab.value === 'aggregate') return aggregateOptions.value.filter(filterFn)
  return []
})

function pickDb(opt) {
  emit('select', { kind: 'db', source: opt.path })
  close()
}
function pickClinic(opt) {
  emit('select', { kind: 'clinic', source: opt.path })
  close()
}
function pickAggregate(opt) {
  emit('select', { kind: 'aggregate', source: opt.source, format: opt.format })
  close()
}
function pickStatic() {
  emit('select', { kind: 'static', value: null })
  close()
}

function close() {
  emit('update:modelValue', false)
}
</script>

<template>
  <Teleport to="body">
    <div v-if="modelValue" class="bp-overlay" @click.self="close">
      <div class="bp-modal">
        <header class="bp-head">
          <div>
            <h3>Pilih Binding</h3>
            <p class="bp-sub">Field: <code>{{ field.key }}</code> — {{ field.label }}</p>
          </div>
          <button class="bp-close" @click="close" aria-label="Tutup">×</button>
        </header>

        <!-- Suggestion banner -->
        <div v-if="field._suggestion?.suggestions?.length" class="bp-sugg">
          <div class="bp-sugg-head">
            Saran auto-suggest
            <span class="bp-sugg-tier" :class="`tier-${field._suggestion.tier}`">
              {{ field._suggestion.tier }} · {{ field._suggestion.confidence }}%
            </span>
          </div>
          <button
            v-for="s in field._suggestion.suggestions"
            :key="s.path"
            class="bp-sugg-item"
            @click="pickDb({ path: s.path })"
          >
            <code>{{ s.path }}</code>
            <span>{{ s.label }}</span>
            <span class="bp-sugg-sim">{{ s.similarity }}%</span>
          </button>
        </div>

        <nav class="bp-tabs">
          <button :class="{ active: tab === 'db' }"        @click="tab = 'db'">DB</button>
          <button :class="{ active: tab === 'aggregate' }" @click="tab = 'aggregate'">Aggregate</button>
          <button :class="{ active: tab === 'clinic' }"    @click="tab = 'clinic'">Klinik</button>
          <button :class="{ active: tab === 'static' }"    @click="tab = 'static'">Static</button>
        </nav>

        <div v-if="tab !== 'static'" class="bp-search">
          <input v-model="search" :placeholder="`Cari di ${tab}…`" />
        </div>

        <div class="bp-list">
          <template v-if="tab === 'db'">
            <button v-for="opt in filtered" :key="opt.path" class="bp-item" @click="pickDb(opt)">
              <div>
                <code class="bp-path">{{ opt.path }}</code>
                <span class="bp-label">{{ opt.label }}</span>
              </div>
              <span class="bp-type">{{ opt.type }}</span>
            </button>
          </template>

          <template v-else-if="tab === 'clinic'">
            <button v-for="opt in filtered" :key="opt.path" class="bp-item" @click="pickClinic(opt)">
              <div>
                <code class="bp-path">{{ opt.path }}</code>
                <span class="bp-label">{{ opt.label }}</span>
              </div>
              <span class="bp-type">{{ opt.type }}</span>
            </button>
          </template>

          <template v-else-if="tab === 'aggregate'">
            <button v-for="opt in filtered" :key="opt.source + '|' + (opt.format ?? '')" class="bp-item" @click="pickAggregate(opt)">
              <div>
                <code class="bp-path">{{ opt.source }}</code>
                <span class="bp-label">{{ opt.label }}</span>
              </div>
              <span class="bp-type">aggregate</span>
            </button>
          </template>

          <template v-else>
            <!-- Static: free text / null -->
            <div class="bp-static">
              <p>Field <strong>static</strong> tidak terikat ke data DB. Nilai null sekarang (admin/dokter isi manual saat INPUT di Fase 3).</p>
              <button class="bp-btn-primary" @click="pickStatic">Set sebagai Static (null)</button>
            </div>
          </template>

          <div v-if="tab !== 'static' && filtered.length === 0" class="bp-empty">
            Tidak ada hasil cocok.
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.bp-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
}
.bp-modal {
  width: min(720px, 92vw); max-height: 86vh;
  background: var(--bc); border-radius: 10px; overflow: hidden;
  display: flex; flex-direction: column;
}

.bp-head {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding: 1rem 1.25rem; border-bottom: 1px solid var(--gb);
}
.bp-head h3 { margin: 0; font-size: 17px; font-family: 'DM Serif Display', serif; }
.bp-sub { margin: 4px 0 0; font-size: 12.5px; color: var(--tm); }
.bp-close { background: 0; border: 0; font-size: 22px; cursor: pointer; color: var(--tm); line-height: 1; }

.bp-sugg { padding: 0.75rem 1.25rem; background: #f6faff; border-bottom: 1px solid var(--gb); }
.bp-sugg-head { font-size: 12px; color: var(--tm); margin-bottom: 0.5rem; display: flex; gap: 0.5rem; align-items: center; }
.bp-sugg-tier { padding: 2px 8px; border-radius: 999px; font-weight: 700; font-size: 10.5px; text-transform: uppercase; }
.bp-sugg-tier.tier-high   { background: #ecf9ee; color: #1e8a3a; }
.bp-sugg-tier.tier-medium { background: #fff4d8; color: #946600; }
.bp-sugg-tier.tier-low    { background: #f0f1f4; color: #5a6068; }

.bp-sugg-item {
  display: flex; align-items: center; gap: 1rem; width: 100%;
  padding: 0.5rem 0.75rem; border: 1px solid var(--gb); border-radius: 6px;
  background: white; cursor: pointer; margin-bottom: 0.4rem; text-align: left;
}
.bp-sugg-item:hover { background: var(--bg); }
.bp-sugg-item code { font-size: 12px; color: var(--pri); }
.bp-sugg-item span:first-of-type { font-size: 13px; }
.bp-sugg-sim { margin-left: auto; font-size: 11.5px; color: var(--tm); }

.bp-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--gb); }
.bp-tabs button {
  flex: 1; padding: 0.6rem 1rem; background: var(--bc); border: 0;
  cursor: pointer; font-size: 13px; color: var(--tm);
  border-bottom: 2px solid transparent;
}
.bp-tabs button.active { color: var(--pri); border-bottom-color: var(--pri); font-weight: 600; }

.bp-search { padding: 0.5rem 1.25rem; border-bottom: 1px solid var(--gb); }
.bp-search input {
  width: 100%; padding: 0.45rem 0.75rem; border: 1px solid var(--gb); border-radius: 6px;
  font-size: 13.5px;
}

.bp-list { padding: 0.5rem 1.25rem; overflow-y: auto; flex: 1; }
.bp-item {
  display: flex; justify-content: space-between; align-items: center;
  width: 100%; padding: 0.55rem 0.75rem; border: 0; background: transparent;
  border-bottom: 1px solid var(--gb); cursor: pointer; text-align: left;
}
.bp-item:hover { background: var(--bg); }
.bp-item code.bp-path { font-size: 12px; color: var(--pri); display: block; }
.bp-item .bp-label { font-size: 13px; color: var(--td); }
.bp-item .bp-type { font-size: 11.5px; color: var(--tm); padding: 2px 6px; background: var(--bg); border-radius: 999px; }

.bp-static { padding: 1rem; }
.bp-static p { font-size: 13.5px; color: var(--tm); }
.bp-btn-primary {
  padding: 0.55rem 1.25rem; background: var(--pri); color: white;
  border: 0; border-radius: 6px; cursor: pointer; font-size: 14px;
}

.bp-empty { padding: 1.5rem; text-align: center; color: var(--tm); font-size: 13px; }
</style>
