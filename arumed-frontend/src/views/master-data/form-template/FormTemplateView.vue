<script setup>
/**
 * FormTemplateView — list halaman Master → Form Template.
 *
 * Menampilkan semua template yang ter-link ke Form Registry (kolom `code` not null).
 * Tombol "Buat Baru" → wizard Step 1 (upload .docx).
 * Klik baris → wizard Step 2 (edit mapper + assignment).
 */
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useFormTemplateStore } from '@/stores/formTemplateStore'

const router = useRouter()
const store  = useFormTemplateStore()

const search = ref('')
const activeFilter = ref('all') // all | active | draft

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return store.items.filter(t => {
    if (activeFilter.value === 'active' && !t.is_active) return false
    if (activeFilter.value === 'draft'  &&  t.is_active) return false
    if (!q) return true
    return (t.name?.toLowerCase().includes(q)) || (t.code?.toLowerCase().includes(q))
  })
})

onMounted(() => store.fetchList())

function goNew() {
  router.push({ name: 'master-form-template-new' })
}

function goEdit(id) {
  router.push({ name: 'master-form-template-edit', params: { id } })
}

async function toggleActive(t) {
  try {
    if (t.is_active) {
      if (!confirm(`Nonaktifkan template "${t.name}"? Form ini tidak akan muncul di station selama nonaktif.`)) return
      await store.deactivate(t.id)
    } else {
      if (!confirm(`Aktifkan template "${t.name}"? Code akan dikunci permanen setelah aktif pertama kali.`)) return
      await store.activate(t.id)
    }
    await store.fetchList()
  } catch (e) {
    alert('Gagal: ' + (e.response?.data?.message ?? e.message))
  }
}
</script>

<template>
  <div class="ft-wrap">
    <header class="ft-head">
      <div>
        <h2>Template Form Rekam Medis</h2>
        <p class="ft-sub">{{ store.activeCount }} aktif · {{ store.draftCount }} draft</p>
      </div>
      <button class="ft-btn ft-btn-primary" @click="goNew">+ Buat Baru</button>
    </header>

    <div class="ft-toolbar">
      <input v-model="search" placeholder="Cari nama / kode template…" class="ft-search" />
      <div class="ft-filter">
        <button :class="{ active: activeFilter === 'all' }"    @click="activeFilter = 'all'">Semua</button>
        <button :class="{ active: activeFilter === 'active' }" @click="activeFilter = 'active'">Aktif</button>
        <button :class="{ active: activeFilter === 'draft' }"  @click="activeFilter = 'draft'">Draft</button>
      </div>
    </div>

    <div v-if="store.loading" class="ft-empty">Memuat…</div>
    <div v-else-if="filtered.length === 0" class="ft-empty">
      Belum ada template. Klik "Buat Baru" untuk upload .docx pertama.
    </div>

    <table v-else class="ft-table">
      <thead>
        <tr>
          <th>Kode</th>
          <th>Nama</th>
          <th>Jenis</th>
          <th>Kompleksitas</th>
          <th>Versi</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="t in filtered" :key="t.id" @click="goEdit(t.id)" class="ft-row">
          <td><code>{{ t.code }}</code></td>
          <td>{{ t.name }}</td>
          <td>
            <span class="ft-chip" :class="`ft-chip-${t.kind?.toLowerCase()}`">{{ t.kind }}</span>
          </td>
          <td>{{ formatComplexity(t.complexity_kind) }}</td>
          <td>v{{ t.version }}</td>
          <td>
            <span class="ft-chip" :class="t.is_active ? 'ft-chip-active' : 'ft-chip-draft'">
              {{ t.is_active ? 'Aktif' : 'Draft' }}
            </span>
          </td>
          <td @click.stop>
            <button class="ft-btn-sm" @click="toggleActive(t)">
              {{ t.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script>
function formatComplexity(c) {
  const map = {
    SIMPLE_BINDING:   'Simple',
    SCORED_FORM:      'Scored',
    CUSTOM_COMPONENT: 'Custom',
  }
  return map[c] ?? c
}
</script>

<style scoped>
.ft-wrap { display: flex; flex-direction: column; gap: 1rem; color: #000; }
.ft-wrap, .ft-wrap * { color: #000; }
.ft-head { display: flex; justify-content: space-between; align-items: flex-end; }
.ft-head h2 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 22px; color: #000; }
.ft-sub { margin: 4px 0 0; color: #000; font-size: 13px; }

.ft-toolbar { display: flex; gap: 1rem; align-items: center; }
.ft-search {
  flex: 1; padding: 0.5rem 0.75rem; border: 1px solid var(--gb); border-radius: 6px;
  font-size: 14px; background: var(--bc); color: #000;
}
.ft-filter { display: flex; gap: 0.25rem; border: 1px solid var(--gb); border-radius: 6px; overflow: hidden; }
.ft-filter button {
  padding: 0.5rem 0.85rem; border: 0; background: var(--bc); cursor: pointer; font-size: 13px; color: #000;
}
.ft-filter button.active { background: #1763d4; color: #fff; }

.ft-table { width: 100%; border-collapse: collapse; background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; }
.ft-table th, .ft-table td { padding: 0.75rem 1rem; text-align: left; font-size: 13.5px; border-bottom: 1px solid var(--gb); color: #000; }
.ft-table th { background: var(--bg); font-weight: 600; color: #000; }
.ft-row { cursor: pointer; transition: background 120ms; }
.ft-row:hover { background: var(--bg); }
.ft-row:last-child td { border-bottom: 0; }

.ft-chip { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.ft-chip-output { background: #eef6ff; color: #000; }
.ft-chip-input  { background: #fff7ec; color: #000; }
.ft-chip-hybrid { background: #f0ecff; color: #000; }
.ft-chip-active { background: #ecf9ee; color: #000; }
.ft-chip-draft  { background: #f0f1f4; color: #000; }

.ft-btn, .ft-btn-sm {
  padding: 0.55rem 1rem; border: 1px solid #000; border-radius: 6px; background: var(--bc);
  color: #000; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 120ms;
}
.ft-btn-sm { padding: 0.35rem 0.7rem; font-size: 12.5px; }
.ft-btn-primary {
  background: #1763d4; color: #fff !important; border-color: #1763d4;
  font-weight: 700; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.ft-btn-primary:hover { background: #134fa8; }
.ft-btn-sm:hover { background: var(--bg); }

.ft-empty { padding: 2rem; text-align: center; color: #000; font-size: 14px; background: var(--bc); border: 1px dashed var(--gb); border-radius: 8px; }
</style>
