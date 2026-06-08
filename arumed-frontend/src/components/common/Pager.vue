<script setup>
/**
 * Pager — kontrol paginasi ringan (dipakai client-side maupun server-side).
 * Props: page (halaman aktif), lastPage (total halaman), total (opsional, jumlah data).
 * Emit: update:page + change(n) saat user pindah halaman.
 */
import { computed } from 'vue'

const props = defineProps({
  page:     { type: Number, default: 1 },
  lastPage: { type: Number, default: 1 },
  total:    { type: Number, default: null },
})
const emit = defineEmits(['update:page', 'change'])

// Window 5 halaman di sekitar halaman aktif.
const pages = computed(() => {
  const last = props.lastPage || 1
  const cur  = props.page || 1
  let start = Math.max(1, cur - 2)
  const end = Math.min(last, start + 4)
  start = Math.max(1, end - 4)
  const out = []
  for (let i = start; i <= end; i++) out.push(i)
  return out
})

function go(n) {
  if (n < 1 || n > (props.lastPage || 1) || n === props.page) return
  emit('update:page', n)
  emit('change', n)
}
</script>

<template>
  <div v-if="lastPage > 1" class="pgr">
    <div class="pgr-info">
      Halaman {{ page }} dari {{ lastPage }}<span v-if="total != null"> · {{ total }} data</span>
    </div>
    <div class="pgr-nav">
      <button :disabled="page <= 1" @click="go(1)">«</button>
      <button :disabled="page <= 1" @click="go(page - 1)">‹</button>
      <button
        v-for="p in pages"
        :key="p"
        :class="{ active: p === page }"
        @click="go(p)"
      >{{ p }}</button>
      <button :disabled="page >= lastPage" @click="go(page + 1)">›</button>
      <button :disabled="page >= lastPage" @click="go(lastPage)">»</button>
    </div>
  </div>
</template>

<style scoped>
.pgr {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 12px 4px 4px;
}
.pgr-info { font-size: 12px; color: var(--tu); }
.pgr-nav { display: flex; gap: 4px; }
.pgr-nav button {
  min-width: 32px;
  height: 32px;
  padding: 0 8px;
  border: 1px solid var(--gb);
  background: var(--bc);
  border-radius: 7px;
  cursor: pointer;
  font-size: 12px;
  color: var(--td);
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.pgr-nav button:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); }
.pgr-nav button.active {
  background: var(--ga);
  border-color: var(--ga);
  color: #fff;
  font-weight: 600;
}
.pgr-nav button:disabled { opacity: 0.45; cursor: not-allowed; }
</style>
