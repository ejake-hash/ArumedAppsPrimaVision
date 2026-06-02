<script setup>
/**
 * InventoriFarmasiLayout — shell modul /inventori-farmasi/*.
 *
 * Mirror pattern MasterDataLayout (vertical tabs nav berkelompok + RouterView).
 * Menu (lihat `allTabs`): Request dari Unit, Master Item (Obat/BHP/IOL/Alat Medis),
 * Penentuan Harga, dan Pengadaan (Supplier/Pembelian/Penerimaan) + inbox notif.
 * View Obat/BHP/IOL masih reside di views/master-data/ supaya tidak memutus import
 * path internal — router yang mengarahkan ke sini, layout yang konsumsi.
 */
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/authStore'
import { inventoriInboxApi } from '@/services/api'
import InventoriStockSidebar from '@/components/inventori-farmasi/InventoriStockSidebar.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const showStockSidebar = computed(() => route.path.startsWith('/inventori-farmasi'))

const allTabs = [
  { to: '/inventori-farmasi/request-unit', label: 'Request dari Unit', icon: 'request', section: 'Operasional', perm: 'request_unit.read' },
  { to: '/inventori-farmasi/obat',       label: 'Obat',              icon: 'pill',     section: 'Master Item',  perm: 'inventori_farmasi.read' },
  { to: '/inventori-farmasi/bhp',        label: 'BHP',               icon: 'box',      section: 'Master Item',  perm: 'inventori_farmasi.read' },
  { to: '/inventori-farmasi/iol',        label: 'IOL',               icon: 'lens',     section: 'Master Item',  perm: 'inventori_farmasi.read' },
  { to: '/inventori-farmasi/alat-medis', label: 'Alat Medis',        icon: 'machine',  section: 'Master Item',  perm: 'inventori_farmasi.read' },
  { to: '/inventori-farmasi/harga',      label: 'Penentuan Harga',   icon: 'price',    section: 'Harga',        perm: 'inventori_farmasi.read' },
  { to: '/inventori-farmasi/supplier',   label: 'Supplier',          icon: 'truck',    section: 'Pengadaan',    perm: 'supplier.read' },
  { to: '/inventori-farmasi/pembelian',  label: 'Pembelian (PO)',    icon: 'cart',     section: 'Pengadaan',    perm: 'pembelian.read' },
  { to: '/inventori-farmasi/penerimaan', label: 'Penerimaan',        icon: 'inbox',    section: 'Pengadaan',    perm: 'penerimaan.read' },
]

const tabs = computed(() => allTabs.filter((t) => auth.can(t.perm)))

const currentTabLabel = computed(() => {
  const found = allTabs.find((it) => route.path.startsWith(it.to))
  return found?.label ?? 'Inventori Farmasi'
})

const groupedTabs = computed(() => {
  const map = new Map()
  for (const t of tabs.value) {
    if (!map.has(t.section)) map.set(t.section, { section: t.section, items: [] })
    map.get(t.section).items.push(t)
  }
  return [...map.values()]
})

// ─── Inbox notifications ────────────────────────────────────────────────
const canSeeInbox = computed(() => auth.can('request_unit.read'))

const inbox = ref({ total: 0, request_count: 0, return_count: 0, items: [] })
const inboxOpen = ref(false)
const inboxLoading = ref(false)
let pollTimer = null

async function fetchInbox() {
  if (!canSeeInbox.value) return
  inboxLoading.value = true
  try {
    const res = await inventoriInboxApi.list()
    inbox.value = res.data?.data ?? { total: 0, request_count: 0, return_count: 0, items: [] }
  } catch (e) {
    // silent — polling background
  } finally {
    inboxLoading.value = false
  }
}

function toggleInbox() {
  inboxOpen.value = !inboxOpen.value
  if (inboxOpen.value) fetchInbox()
}

function gotoInbox(item) {
  inboxOpen.value = false
  router.push('/inventori-farmasi/request-unit').then(() => {
    window.dispatchEvent(new CustomEvent('arumed:inventori-inbox-open', { detail: item }))
  })
}

function formatTime(v) {
  if (!v) return ''
  const d = new Date(v)
  const diff = Date.now() - d.getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 1)   return 'baru saja'
  if (mins < 60)  return `${mins}m lalu`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24)   return `${hrs}j lalu`
  const days = Math.floor(hrs / 24)
  if (days < 7)   return `${days}h lalu`
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })
}

function onDocClick(e) {
  if (!inboxOpen.value) return
  const el = e.target.closest('.if-inbox-wrap')
  if (!el) inboxOpen.value = false
}

onMounted(() => {
  if (canSeeInbox.value) {
    fetchInbox()
    pollTimer = setInterval(fetchInbox, 30_000)
    document.addEventListener('click', onDocClick)
  }
})

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer)
  document.removeEventListener('click', onDocClick)
})
</script>

<template>
  <div class="if-wrap">
    <header class="if-header">
      <div>
        <h1>Inventori Farmasi</h1>
        <p class="if-sub">Stok &amp; master Obat, BHP, dan IOL — {{ currentTabLabel }}</p>
      </div>

      <div v-if="canSeeInbox" class="if-inbox-wrap">
        <button class="if-bell" :class="{ active: inbox.total > 0 }" @click.stop="toggleInbox" :title="`${inbox.total} notifikasi baru`">
          <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span v-if="inbox.total > 0" class="if-bell-badge">{{ inbox.total > 99 ? '99+' : inbox.total }}</span>
        </button>

        <div v-if="inboxOpen" class="if-inbox-panel" @click.stop>
          <div class="if-inbox-head">
            <strong>Inbox dari Unit</strong>
            <span class="if-inbox-meta">
              {{ inbox.request_count }} request · {{ inbox.return_count }} retur
            </span>
          </div>

          <div class="if-inbox-body">
            <div v-if="inboxLoading && !inbox.items.length" class="if-inbox-empty">Memuat…</div>
            <div v-else-if="!inbox.items.length" class="if-inbox-empty">Tidak ada notifikasi baru</div>
            <button
              v-else
              v-for="item in inbox.items"
              :key="`${item.kind}-${item.id}`"
              class="if-inbox-row"
              @click="gotoInbox(item)"
            >
              <span class="if-inbox-kind" :class="item.kind === 'REQUEST' ? 'k-req' : 'k-ret'">
                {{ item.kind === 'REQUEST' ? 'Request' : 'Retur' }}
              </span>
              <div class="if-inbox-main">
                <div class="if-inbox-title">
                  <code>{{ item.number }}</code>
                  <span class="if-inbox-station">{{ item.station }}</span>
                </div>
                <div class="if-inbox-sub">
                  {{ item.items_count }} item
                  <span v-if="item.reason"> · {{ item.reason }}</span>
                  · {{ formatTime(item.created_at) }}
                </div>
              </div>
            </button>
          </div>

          <div class="if-inbox-foot">
            <RouterLink to="/inventori-farmasi/request-unit" class="if-inbox-all" @click="inboxOpen = false">
              Lihat semua →
            </RouterLink>
          </div>
        </div>
      </div>
    </header>

    <div class="if-grid" :class="{ 'has-stock': showStockSidebar }">
      <aside class="if-nav">
        <template v-for="(group, gIdx) in groupedTabs" :key="gIdx">
          <div class="if-nav-section">{{ group.section }}</div>
          <RouterLink
            v-for="item in group.items"
            :key="item.to"
            :to="item.to"
            class="if-nav-item"
          >
            <svg v-if="item.icon === 'pill'" viewBox="0 0 24 24"><path d="M10.5 20.5a6 6 0 1 1 8.485-8.486l-8.485 8.486zM13.515 3.515a6 6 0 0 1 0 8.485l-8.485-8.485"/></svg>
            <svg v-else-if="item.icon === 'box'" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <svg v-else-if="item.icon === 'lens'" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
            <svg v-else-if="item.icon === 'machine'" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="13" y1="9" x2="19" y2="9"/><line x1="13" y1="13" x2="17" y2="13"/><line x1="7" y1="20" x2="17" y2="20"/><line x1="12" y1="16" x2="12" y2="20"/></svg>
            <svg v-else-if="item.icon === 'price'" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            <svg v-else-if="item.icon === 'truck'" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            <svg v-else-if="item.icon === 'cart'" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            <svg v-else-if="item.icon === 'inbox'" viewBox="0 0 24 24"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
            <svg v-else-if="item.icon === 'request'" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="14" x2="15" y2="14"/><line x1="9" y1="18" x2="13" y2="18"/></svg>
            <span>{{ item.label }}</span>
          </RouterLink>
        </template>
      </aside>

      <main class="if-content">
        <RouterView />
      </main>

      <aside v-if="showStockSidebar" class="if-stock">
        <InventoriStockSidebar />
      </aside>
    </div>
  </div>
</template>

<style scoped>
.if-wrap { padding: 1.5rem 2rem; display: flex; flex-direction: column; gap: 1.2rem; max-width: 1400px; }
.if-wrap:has(.if-grid.has-stock) { max-width: 1760px; }

.if-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.if-header h1 { font-family: 'Space Grotesk', serif; font-size: 26px; color: var(--td); margin: 0; line-height: 1.2; }
.if-sub { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

/* Inbox bell */
.if-inbox-wrap { position: relative; }
.if-bell { position: relative; width: 40px; height: 40px; border-radius: 10px; background: var(--bc); border: 1px solid var(--gb); cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--tm); transition: all 0.15s; }
.if-bell:hover { background: var(--bs); color: var(--td); }
.if-bell.active { color: var(--ga); border-color: var(--ga); }
.if-bell svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.if-bell-badge { position: absolute; top: -4px; right: -4px; background: #dc2626; color: white; font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 9px; display: flex; align-items: center; justify-content: center; border: 2px solid var(--bc); }

.if-inbox-panel { position: absolute; top: calc(100% + 8px); right: 0; width: 380px; max-width: 90vw; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; box-shadow: 0 10px 30px rgba(15,23,42,0.12); z-index: 50; display: flex; flex-direction: column; max-height: 70vh; overflow: hidden; }
.if-inbox-head { padding: 12px 14px; border-bottom: 1px solid var(--gb); display: flex; justify-content: space-between; align-items: center; background: var(--bs); }
.if-inbox-head strong { font-size: 13px; color: var(--td); }
.if-inbox-meta { font-size: 11.5px; color: var(--tm); }

.if-inbox-body { flex: 1; overflow-y: auto; }
.if-inbox-empty { padding: 2rem; text-align: center; font-size: 13px; color: var(--tm); }
.if-inbox-row { width: 100%; text-align: left; padding: 10px 14px; border: none; background: transparent; cursor: pointer; display: flex; gap: 10px; align-items: flex-start; border-bottom: 1px solid var(--gb); }
.if-inbox-row:hover { background: var(--bs); }
.if-inbox-row:last-child { border-bottom: none; }
.if-inbox-kind { flex-shrink: 0; padding: 3px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 600; }
.k-req { background: #dbeafe; color: #1e40af; }
.k-ret { background: #fef3c7; color: #92400e; }
.if-inbox-main { flex: 1; min-width: 0; }
.if-inbox-title { display: flex; gap: 8px; align-items: center; font-size: 12.5px; color: var(--td); }
.if-inbox-title code { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--td); }
.if-inbox-station { font-size: 11px; color: var(--tm); background: var(--gl); padding: 1px 6px; border-radius: 4px; }
.if-inbox-sub { font-size: 11.5px; color: var(--tm); margin-top: 2px; }

.if-inbox-foot { border-top: 1px solid var(--gb); padding: 8px 14px; text-align: center; background: var(--bs); }
.if-inbox-all { font-size: 12.5px; color: var(--ga); text-decoration: none; font-weight: 500; }
.if-inbox-all:hover { text-decoration: underline; }

.if-grid { display: grid; grid-template-columns: 220px 1fr; gap: 1.5rem; align-items: start; }
.if-grid.has-stock { grid-template-columns: 220px minmax(0, 1fr) 340px; }
@media (max-width: 1200px) { .if-grid.has-stock { grid-template-columns: 220px minmax(0, 1fr); } .if-grid.has-stock .if-stock { display: none; } }
@media (max-width: 900px) { .if-grid, .if-grid.has-stock { grid-template-columns: 1fr; } }

.if-stock { min-width: 0; }

.if-nav { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.6rem 0.5rem; position: sticky; top: 1.5rem; display: flex; flex-direction: column; gap: 1px; }
.if-nav-section { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--tu); padding: 0.6rem 0.7rem 0.3rem; }

.if-nav-item { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: var(--tm); font-size: 13px; transition: background 0.15s, color 0.15s; }
.if-nav-item svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
.if-nav-item:hover { background: var(--bs); color: var(--td); }
.if-nav-item.router-link-exact-active { background: var(--gl); color: var(--td); font-weight: 500; }
.if-nav-item.router-link-exact-active svg { stroke: var(--ga); }

.if-content { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1.3rem 1.4rem; min-height: 60vh; }
</style>
