<script setup>
/**
 * AssignmentStep — Step 3 wizard.
 * Pilih station + section (dari SectionRegistry) + mode (OUTPUT/INPUT/HYBRID).
 * Tombol "Preview Render" → POST sementara → render dry-run via existing
 * /rekam-medis/form/{code}/render? — atau lebih aman: parent persist dulu
 * baru render. Untuk Fase 2, preview pakai render-as-draft (TODO Fase 3).
 *
 * Tombol "Simpan" → persist tanpa activate. "Simpan & Aktifkan" → persist + activate.
 */
import { computed } from 'vue'
import { useFormTemplateStore } from '@/stores/formTemplateStore'

const props = defineProps({
  modelValue: { type: Object, required: true },
})
const emit = defineEmits(['update:modelValue', 'back', 'save', 'save-and-activate'])

const store = useFormTemplateStore()

const draft = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const stations = computed(() => store.stationSections?.stations ?? [])
const sectionMap = computed(() => store.stationSections?.map ?? {})

function isAssigned(station) {
  return draft.value.station_assignments.some(a => a.station === station)
}

function toggleStation(station) {
  if (isAssigned(station)) {
    draft.value.station_assignments = draft.value.station_assignments.filter(a => a.station !== station)
  } else {
    // Default section = pertama dari map; default mode = sama dengan kind template.
    const firstSection = (sectionMap.value[station] ?? [])[0]
    if (!firstSection) return
    draft.value.station_assignments.push({
      station,
      section: firstSection,
      mode: draft.value.kind === 'INPUT' ? 'INPUT' : 'OUTPUT',
    })
  }
}

function assignmentFor(station) {
  return draft.value.station_assignments.find(a => a.station === station)
}

function isReadyToActivate() {
  return draft.value.station_assignments.length > 0
}
</script>

<template>
  <div class="as-wrap">
    <section class="as-card">
      <h4>Assign ke Station</h4>
      <p class="as-hint">Pilih station mana yang menampilkan form ini. Section memetakan ke layout DokterView/PerawatView dst.</p>

      <div class="as-stations">
        <div
          v-for="station in stations"
          :key="station"
          class="as-station-card"
          :class="{ active: isAssigned(station) }"
        >
          <label class="as-station-head">
            <input type="checkbox" :checked="isAssigned(station)" @change="toggleStation(station)" />
            <strong>{{ station }}</strong>
          </label>

          <div v-if="isAssigned(station)" class="as-station-detail">
            <label>
              <span>Section</span>
              <select v-model="assignmentFor(station).section">
                <option v-for="s in (sectionMap[station] ?? [])" :key="s" :value="s">{{ s }}</option>
              </select>
            </label>
            <label>
              <span>Mode</span>
              <select v-model="assignmentFor(station).mode">
                <option value="OUTPUT">OUTPUT (cetak)</option>
                <option value="INPUT">INPUT (entry data)</option>
                <option value="HYBRID">HYBRID</option>
              </select>
            </label>
          </div>
        </div>
      </div>
    </section>

    <section class="as-card">
      <h4>Preview Layout (tanpa data)</h4>
      <p class="as-hint">Placeholder <code>&#123;&#123;key&#125;&#125;</code> ditampilkan apa adanya. Untuk preview dengan data real, simpan dulu lalu test di station Vue.</p>
      <div class="as-preview" v-html="draft.layout_html ?? ''"></div>
    </section>

    <footer class="as-foot">
      <button class="as-btn" @click="emit('back')">← Step 2</button>
      <div class="as-foot-actions">
        <button class="as-btn" @click="emit('save')">Simpan sebagai Draft</button>
        <button class="as-btn-primary" :disabled="!isReadyToActivate()" @click="emit('save-and-activate')">
          Simpan &amp; Aktifkan
        </button>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.as-wrap { display: flex; flex-direction: column; gap: 1rem; }
.as-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; padding: 1rem 1.25rem; }
.as-card h4 { margin: 0 0 0.25rem; font-family: 'Space Grotesk', serif; font-size: 16px; color: var(--td); }
.as-hint { color: var(--tm); font-size: 12.5px; margin: 0 0 0.75rem; }

.as-stations { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.6rem; }
.as-station-card {
  border: 1px solid var(--gb); border-radius: 6px; padding: 0.75rem;
  background: white; transition: all 150ms;
}
.as-station-card.active { border-color: var(--pri); background: #f0f7ff; }
.as-station-head { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 14px; }
.as-station-head strong { text-transform: capitalize; }
.as-station-detail { margin-top: 0.6rem; display: flex; flex-direction: column; gap: 0.4rem; }
.as-station-detail label { display: flex; flex-direction: column; font-size: 11.5px; color: var(--tm); gap: 3px; }
.as-station-detail select {
  padding: 0.3rem 0.5rem; border: 1px solid var(--gb); border-radius: 4px; font-size: 12.5px;
}

.as-preview {
  max-height: 60vh; overflow-y: auto;
  border: 1px solid var(--gb); border-radius: 6px;
  padding: 1rem;
  background: #fafafa;
  font-size: 13px;
}

.as-foot { display: flex; justify-content: space-between; align-items: center; padding-top: 0.5rem; }
.as-foot-actions { display: flex; gap: 0.5rem; }
.as-btn, .as-btn-primary {
  padding: 0.55rem 1rem; border: 1px solid var(--gb); border-radius: 6px;
  background: var(--bc); cursor: pointer; font-size: 13.5px;
}
.as-btn-primary { background: var(--pri); color: white; border-color: var(--pri); }
.as-btn-primary:hover:not(:disabled) { filter: brightness(1.08); }
.as-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
