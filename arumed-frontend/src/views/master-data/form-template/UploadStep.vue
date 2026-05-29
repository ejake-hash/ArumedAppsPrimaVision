<script setup>
/**
 * UploadStep — Step 1 wizard. Drag-drop .docx → upload → parse sync.
 * Emit @complete dengan draft parser hasil + source_file_path saat selesai.
 */
import { ref } from 'vue'
import { useFormTemplateStore } from '@/stores/formTemplateStore'

const emit  = defineEmits(['complete'])
const store = useFormTemplateStore()

const dragging = ref(false)
const file     = ref(null)
const errorMsg = ref('')

function onDragOver(e) {
  e.preventDefault()
  dragging.value = true
}
function onDragLeave() {
  dragging.value = false
}
function onDrop(e) {
  e.preventDefault()
  dragging.value = false
  const dropped = e.dataTransfer?.files?.[0]
  if (dropped) setFile(dropped)
}
function onPick(e) {
  const picked = e.target.files?.[0]
  if (picked) setFile(picked)
}

function setFile(f) {
  if (!f.name.toLowerCase().endsWith('.docx')) {
    errorMsg.value = 'Hanya file .docx yang didukung. Convert .doc/.pdf ke .docx dulu.'
    return
  }
  if (f.size > 5 * 1024 * 1024) {
    errorMsg.value = 'Ukuran file melebihi 5MB.'
    return
  }
  errorMsg.value = ''
  file.value = f
}

async function start() {
  if (!file.value) return
  errorMsg.value = ''
  try {
    const draft = await store.uploadAndParse(file.value)
    emit('complete', draft.draft, draft.source_file_path)
  } catch (e) {
    errorMsg.value = e.response?.data?.message ?? 'Gagal parse file.'
  }
}
</script>

<template>
  <div class="us-wrap">
    <div
      class="us-dropzone"
      :class="{ dragging, busy: store.parsing }"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <input
        id="us-file"
        type="file"
        accept=".docx"
        class="us-file-input"
        @change="onPick"
        :disabled="store.parsing"
      />

      <div v-if="store.parsing" class="us-busy">
        <div class="us-spinner"></div>
        <p>Memparsing dokumen…</p>
        <p class="us-hint">Proses ini sinkron, biasanya selesai dalam 5 detik.</p>
      </div>

      <template v-else>
        <svg class="us-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5-5 5 5M12 5v12"/>
        </svg>
        <p class="us-title">Tarik file <strong>.docx</strong> ke sini, atau</p>
        <label for="us-file" class="us-btn">Pilih File</label>
        <p class="us-hint">Max 5MB. Hanya format Word 2007+ (.docx). PDF tidak didukung di v1.</p>

        <div v-if="file" class="us-file-info">
          <strong>{{ file.name }}</strong>
          <span class="us-file-size">{{ (file.size / 1024).toFixed(1) }} KB</span>
          <button class="us-btn-primary" @click="start">Upload &amp; Parse</button>
        </div>

        <div v-if="errorMsg" class="us-error">{{ errorMsg }}</div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.us-wrap { display: flex; justify-content: center; padding: 1rem 0; }
.us-dropzone {
  position: relative;
  width: 100%; max-width: 640px;
  padding: 3rem 2rem;
  border: 2px dashed var(--gb);
  border-radius: 12px;
  background: var(--bc);
  text-align: center;
  transition: all 180ms;
}
.us-dropzone.dragging { border-color: var(--pri); background: #f0f7ff; }
.us-dropzone.busy { opacity: 0.75; }

.us-file-input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }

.us-icon { width: 48px; height: 48px; color: var(--tm); margin-bottom: 0.5rem; }
.us-title { margin: 0.5rem 0; color: var(--td); font-size: 15px; }
.us-hint { margin: 0.5rem 0 0; font-size: 12.5px; color: var(--tm); }

.us-btn {
  display: inline-block; padding: 0.55rem 1.25rem;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 6px;
  font-size: 14px; cursor: pointer; color: var(--td);
}
.us-btn:hover { background: var(--bg); }

.us-btn-primary {
  margin-top: 0.75rem;
  padding: 0.55rem 1.25rem;
  background: var(--pri); color: white; border: 0; border-radius: 6px;
  cursor: pointer; font-size: 14px;
}
.us-btn-primary:hover { filter: brightness(1.08); }

.us-file-info {
  margin-top: 1.25rem;
  padding: 0.75rem 1rem;
  background: var(--bg);
  border-radius: 6px;
  display: flex; flex-direction: column; gap: 0.4rem; align-items: center;
}
.us-file-size { font-size: 12px; color: var(--tm); }

.us-error {
  margin-top: 0.75rem; padding: 0.5rem 0.75rem;
  background: #fff0f0; color: #b42323; border-radius: 6px; font-size: 13px;
}

.us-busy { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
.us-spinner {
  width: 36px; height: 36px;
  border: 3px solid var(--gb); border-top-color: var(--pri); border-radius: 50%;
  animation: us-spin 800ms linear infinite;
}
@keyframes us-spin { to { transform: rotate(360deg); } }
</style>
