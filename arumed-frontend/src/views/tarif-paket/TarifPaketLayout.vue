<script setup>
/**
 * TarifPaketLayout — shell sub-modul /tarif-paket/*.
 *
 * UI diselaraskan dengan InventoriFarmasiLayout: nav kiri berkelompok yang bisa
 * DICIUTKAN jadi icon-rail (ikon tetap dapat diklik) dengan transisi halus, konten
 * lega (padding lapang, lebar penuh). Pilihan ciut disimpan di localStorage agar
 * konsisten antar-halaman & saat reload.
 */
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const route = useRoute()

const NAV_KEY = 'tarifpaket.navCollapsed'
const navCollapsed = ref(localStorage.getItem(NAV_KEY) === '1')
function toggleNav() {
  navCollapsed.value = !navCollapsed.value
  localStorage.setItem(NAV_KEY, navCollapsed.value ? '1' : '0')
}

const tabs = [
  {
    section: 'Tarif Tindakan',
    items: [
      { to: '/tarif-paket/tarif-tindakan', label: 'Daftar Tarif', icon: 'money' },
    ],
  },
  {
    section: 'Metode Bayar',
    items: [
      { to: '/tarif-paket/metode-bayar', label: 'Daftar Penjamin', icon: 'card' },
    ],
  },
  {
    section: 'Paket Bedah',
    items: [
      { to: '/tarif-paket/paket-bedah', label: 'Daftar Paket', icon: 'package' },
    ],
  },
  {
    section: 'Pengaturan',
    items: [
      { to: '/tarif-paket/kategori-tagihan', label: 'Kategori Buku Tarif', icon: 'tag' },
    ],
  },
]

const currentTabLabel = computed(() => {
  for (const sec of tabs) {
    const found = sec.items.find((it) => route.path.startsWith(it.to))
    if (found) return found.label
  }
  return 'Tarif & Paket Bedah'
})
</script>

<template>
  <div class="tpl-wrap">
    <header class="tpl-header">
      <div>
        <h1>Tarif &amp; Paket Bedah</h1>
        <p class="tpl-sub">Master tarif tindakan, metode bayar (penjamin), dan paket bedah — {{ currentTabLabel }}</p>
      </div>
    </header>

    <div class="tpl-grid" :class="{ 'nav-collapsed': navCollapsed }">
      <aside class="tpl-nav" :class="{ 'is-rail': navCollapsed }">
        <button class="tpl-nav-toggle" @click="toggleNav" :title="navCollapsed ? 'Lebarkan menu' : 'Ciutkan menu (konten lebih lebar)'">
          <svg viewBox="0 0 24 24"><polyline :points="navCollapsed ? '9 18 15 12 9 6' : '15 18 9 12 15 6'"/></svg>
        </button>
        <template v-for="sec in tabs" :key="sec.section">
          <div class="tpl-nav-section">{{ sec.section }}</div>
          <RouterLink
            v-for="item in sec.items"
            :key="item.to"
            :to="item.to"
            class="tpl-nav-item"
            :title="item.label"
            :class="{ 'router-link-exact-active': route.path.startsWith(item.to) }"
          >
            <svg v-if="item.icon === 'money'" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            <svg v-else-if="item.icon === 'card'" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <svg v-else-if="item.icon === 'package'" viewBox="0 0 24 24"><path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <svg v-else-if="item.icon === 'tag'" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            <span>{{ item.label }}</span>
          </RouterLink>
        </template>
      </aside>

      <main class="tpl-content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.tpl-wrap { padding: 1.5rem 2rem; display: flex; flex-direction: column; gap: 1.2rem; max-width: 1600px; }

.tpl-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.tpl-header h1 { font-family: 'Space Grotesk', serif; font-size: 26px; color: var(--td); margin: 0; line-height: 1.2; }
.tpl-sub { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

/* Lebar kolom via variabel → transisi halus saat ciut/lebar. */
.tpl-grid { --nav-w: 220px; display: grid; grid-template-columns: var(--nav-w) minmax(0, 1fr); gap: 1.5rem; align-items: start; transition: grid-template-columns .16s ease; }
.tpl-grid.nav-collapsed { --nav-w: 64px; }
@media (max-width: 860px) { .tpl-grid, .tpl-grid.nav-collapsed { grid-template-columns: 1fr; } .tpl-nav-toggle, .tpl-nav.is-rail { display: none; } }

.tpl-nav { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.6rem 0.5rem; position: sticky; top: 1.5rem; display: flex; flex-direction: column; gap: 1px; }
.tpl-nav-section { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--tu); padding: 0.6rem 0.7rem 0.3rem; margin-top: 0.3rem; }
.tpl-nav-section:first-child { margin-top: 0; }

/* Toggle ciutkan menu */
.tpl-nav-toggle { align-self: flex-end; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; background: var(--bs); border: 1px solid var(--gb); border-radius: 7px; cursor: pointer; color: var(--tm); margin-bottom: 2px; }
.tpl-nav-toggle:hover { color: var(--ga); border-color: var(--ga); }
.tpl-nav-toggle svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }

.tpl-nav-item { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: var(--tm); font-size: 13px; transition: background 0.15s, color 0.15s; }
.tpl-nav-item svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
.tpl-nav-item:hover { background: var(--bs); color: var(--td); }
.tpl-nav-item.router-link-exact-active { background: var(--gl); color: var(--td); font-weight: 500; }
.tpl-nav-item.router-link-exact-active svg { stroke: var(--ga); }

/* Mode rail (icon-only) — ikon tetap dapat diklik, label/section disembunyikan */
.tpl-nav.is-rail { padding: 0.6rem 0.35rem; align-items: center; }
.tpl-nav.is-rail .tpl-nav-toggle { align-self: center; }
.tpl-nav.is-rail .tpl-nav-section { height: 1px; padding: 0; margin: 6px 6px; width: 60%; background: var(--gb); overflow: hidden; text-indent: -999px; }
.tpl-nav.is-rail .tpl-nav-item { justify-content: center; padding: 9px 0; width: 40px; gap: 0; }
.tpl-nav.is-rail .tpl-nav-item span { display: none; }
.tpl-nav.is-rail .tpl-nav-item svg { width: 17px; height: 17px; }

.tpl-content { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1.6rem 1.8rem; min-height: 60vh; }
@media (max-width: 700px) { .tpl-content { padding: 1.1rem 1rem; } }
</style>
