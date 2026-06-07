<script setup>
/**
 * LayoutEditor — TipTap v2 wrapper untuk edit layout_html.
 *
 * Props:
 *   - modelValue : string HTML
 *   - placeholders : string[] daftar key field (untuk popover insert)
 *
 * Catatan:
 *   - TipTap menyimpan content sebagai HTML; placeholder `{{key}}` ditulis
 *     sebagai plain text inline. Saat substitusi (DocumentRenderer), engine
 *     regex ganti `{{key}}` jadi nilai konkret.
 *   - Toolbar minimal: bold, italic, h2/h3, ul/ol, table, insert placeholder.
 */
import { onBeforeUnmount, ref, watch, nextTick } from 'vue'
import { Editor, EditorContent } from '@tiptap/vue-3'
import { StarterKit } from '@tiptap/starter-kit'
import { Table } from '@tiptap/extension-table'
import { TableRow } from '@tiptap/extension-table-row'
import { TableCell } from '@tiptap/extension-table-cell'
import { TableHeader } from '@tiptap/extension-table-header'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholders: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const editor = ref(null)
const showInsertMenu = ref(false)

// Mode editor: 'visual' (TipTap WYSIWYG) | 'source' (HTML mentah).
// WAJIB untuk layout kompleks (tabel kop berkop inline-style) yang DIBUANG/dinormalisasi
// TipTap — di mode source HTML diedit apa adanya tanpa dirusak.
const mode = ref('visual')
const sourceDraft = ref(props.modelValue ?? '')
const sourceRef = ref(null)   // <textarea> utk insert placeholder di posisi kursor

function setMode(m) {
  if (m === mode.value) return
  if (m === 'source') {
    // Masuk source → ambil HTML terkini (raw, belum dimangle bila belum disentuh visual).
    sourceDraft.value = props.modelValue ?? ''
  } else {
    // Kembali ke visual → muat source ke TipTap (⚠️ bisa menormalisasi HTML kompleks).
    editor.value?.commands.setContent(sourceDraft.value ?? '', false)
  }
  mode.value = m
}

function onSourceInput() {
  // Source = sumber kebenaran saat mode 'source' → emit HTML mentah apa adanya.
  emit('update:modelValue', sourceDraft.value)
}

editor.value = new Editor({
  content: props.modelValue,
  extensions: [
    StarterKit,
    Table.configure({ resizable: false }),
    TableRow, TableHeader, TableCell,
  ],
  onUpdate: ({ editor }) => {
    emit('update:modelValue', editor.getHTML())
  },
})

watch(() => props.modelValue, (val) => {
  // Sinkron source draft dengan perubahan luar (mis. load template) saat mode source.
  if (mode.value === 'source' && sourceDraft.value !== val) {
    sourceDraft.value = val ?? ''
  }
  if (!editor.value || mode.value === 'source') return
  // Hindari loop infinite — hanya set kalau benar-benar beda dari current.
  if (editor.value.getHTML() !== val) {
    editor.value.commands.setContent(val ?? '', false)
  }
})

onBeforeUnmount(() => {
  editor.value?.destroy()
})

function exec(fn) {
  if (!editor.value) return
  fn(editor.value.chain().focus()).run()
}

function insertPlaceholder(key) {
  showInsertMenu.value = false
  const token = `{{${key}}}`
  if (mode.value === 'source') {
    // Sisip di posisi kursor textarea (atau di akhir bila tak fokus).
    const ta = sourceRef.value
    const s = ta?.selectionStart ?? sourceDraft.value.length
    const e = ta?.selectionEnd ?? sourceDraft.value.length
    sourceDraft.value = sourceDraft.value.slice(0, s) + token + sourceDraft.value.slice(e)
    onSourceInput()
    nextTick(() => { if (ta) { ta.focus(); ta.selectionStart = ta.selectionEnd = s + token.length } })
    return
  }
  editor.value?.chain().focus().insertContent(token).run()
}
</script>

<template>
  <div class="le-wrap">
    <div class="le-toolbar">
      <!-- Toggle Visual / HTML mentah -->
      <div class="le-mode">
        <button type="button" :class="{ active: mode === 'visual' }" @click="setMode('visual')">Visual</button>
        <button type="button" :class="{ active: mode === 'source' }" @click="setMode('source')" title="Edit HTML mentah (untuk layout kompleks/berkop)">&lt;/&gt; HTML</button>
      </div>
      <span class="le-divider"></span>

      <template v-if="mode === 'visual'">
        <button type="button" @click="exec(c => c.toggleBold())" :class="{ active: editor?.isActive('bold') }"><strong>B</strong></button>
        <button type="button" @click="exec(c => c.toggleItalic())" :class="{ active: editor?.isActive('italic') }"><em>I</em></button>
        <span class="le-divider"></span>
        <button type="button" @click="exec(c => c.toggleHeading({ level: 2 }))" :class="{ active: editor?.isActive('heading', { level: 2 }) }">H2</button>
        <button type="button" @click="exec(c => c.toggleHeading({ level: 3 }))" :class="{ active: editor?.isActive('heading', { level: 3 }) }">H3</button>
        <button type="button" @click="exec(c => c.setParagraph())">¶</button>
        <span class="le-divider"></span>
        <button type="button" @click="exec(c => c.toggleBulletList())" :class="{ active: editor?.isActive('bulletList') }">• List</button>
        <button type="button" @click="exec(c => c.toggleOrderedList())" :class="{ active: editor?.isActive('orderedList') }">1. List</button>
        <span class="le-divider"></span>
        <button type="button" @click="exec(c => c.insertTable({ rows: 3, cols: 2, withHeaderRow: false }))">+ Tabel</button>
      </template>
      <span v-else class="le-source-hint">Mode HTML mentah — aman untuk tabel/kop kompleks. Sisipkan data dgn "Insert Field".</span>

      <div class="le-spacer"></div>

      <div class="le-insert">
        <button type="button" class="le-insert-btn" @click="showInsertMenu = !showInsertMenu">
          &#123;&#123; Insert Field
        </button>
        <div v-if="showInsertMenu" class="le-insert-menu">
          <div class="le-insert-empty" v-if="placeholders.length === 0">
            Belum ada field. Tambahkan di panel kanan dulu.
          </div>
          <button
            v-for="key in placeholders"
            :key="key"
            type="button"
            class="le-insert-item"
            @click="insertPlaceholder(key)"
          >
            <span>&#123;&#123;{{ key }}&#125;&#125;</span>
          </button>
        </div>
      </div>
    </div>

    <EditorContent v-show="mode === 'visual'" :editor="editor" class="le-content" />
    <textarea
      v-show="mode === 'source'"
      ref="sourceRef"
      v-model="sourceDraft"
      class="le-source"
      spellcheck="false"
      placeholder="<div>…HTML layout… {{nama_pasien}} …</div>"
      @input="onSourceInput"
    ></textarea>
  </div>
</template>

<style scoped>
.le-wrap {
  border: 1px solid var(--gb); border-radius: 8px; overflow: hidden;
  background: var(--bc);
  display: flex; flex-direction: column;
}

.le-toolbar {
  display: flex; align-items: center; gap: 0.25rem;
  padding: 0.4rem 0.5rem; border-bottom: 1px solid var(--gb); background: var(--bg);
  flex-wrap: wrap;
}
.le-toolbar button {
  min-width: 32px; padding: 0.25rem 0.55rem;
  border: 1px solid transparent; background: transparent;
  border-radius: 4px; cursor: pointer; font-size: 13px; color: var(--td);
}
.le-toolbar button:hover { background: var(--bc); border-color: var(--gb); }
.le-toolbar button.active { background: var(--pri); color: white; }
.le-divider { display: inline-block; width: 1px; height: 16px; background: var(--gb); margin: 0 0.25rem; }
.le-spacer { flex: 1; }

/* Toggle Visual / HTML */
.le-mode { display: inline-flex; border: 1px solid var(--gb); border-radius: 6px; overflow: hidden; }
.le-mode button { min-width: 0; padding: 0.25rem 0.6rem; border: 0; border-radius: 0; font-size: 12px; font-weight: 600; }
.le-mode button.active { background: var(--pri); color: #fff; }
.le-source-hint { font-size: 11.5px; color: var(--tm); font-style: italic; }

/* Textarea HTML mentah */
.le-source {
  width: 100%; min-height: 320px; max-height: 60vh; resize: vertical;
  padding: 1rem; border: 0; outline: none; background: #0f172a; color: #e2e8f0;
  font-family: 'SFMono-Regular', Consolas, monospace; font-size: 12.5px; line-height: 1.55;
  tab-size: 2; white-space: pre; overflow: auto;
}

.le-insert { position: relative; }
.le-insert-btn { background: #f0f7ff !important; color: var(--pri) !important; }
.le-insert-menu {
  position: absolute; right: 0; top: 100%; margin-top: 4px;
  min-width: 220px; max-height: 280px; overflow-y: auto;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 6px;
  box-shadow: 0 4px 18px rgba(0,0,0,0.08); z-index: 5;
}
.le-insert-item {
  display: block !important; width: 100%; padding: 0.4rem 0.75rem !important;
  text-align: left !important; border: 0 !important; border-radius: 0 !important;
  font-family: monospace; font-size: 12.5px !important; color: var(--td) !important;
  background: transparent !important;
}
.le-insert-item:hover { background: var(--bg) !important; }
.le-insert-empty { padding: 0.75rem; color: var(--tm); font-size: 12.5px; }

.le-content { padding: 1rem; min-height: 320px; max-height: 60vh; overflow-y: auto; }
.le-content :deep(.ProseMirror) { outline: none; min-height: 280px; font-size: 14px; line-height: 1.5; }
.le-content :deep(.ProseMirror h2) { font-size: 17px; margin-top: 1rem; }
.le-content :deep(.ProseMirror h3) { font-size: 15px; margin-top: 0.75rem; }
.le-content :deep(.ProseMirror table) { border-collapse: collapse; margin: 0.5rem 0; }
.le-content :deep(.ProseMirror th), .le-content :deep(.ProseMirror td) {
  border: 1px solid #999; padding: 4px 8px; min-width: 60px;
}
</style>
