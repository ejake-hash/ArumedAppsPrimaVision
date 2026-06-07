<script setup>
/**
 * TarifPaketLayout — shell sub-modul /tarif-paket/*.
 *
 * Layout pattern mirror MasterDataLayout — vertical tabs nav kiri + RouterView kanan.
 * 3 section: Tarif Tindakan (master procedures), Metode Bayar (master insurers),
 * dan Paket Bedah.
 *
 * Sub-nav kiri BISA DISEMBUNYIKAN (tombol di header) supaya tabel tarif yang
 * lebar dapat memakai lebar penuh. Pilihan disimpan di localStorage agar
 * konsisten antar-halaman & saat reload.
 */
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const route = useRoute()

const NAV_KEY = 'tarifpaket.navHidden'
const navHidden = ref(localStorage.getItem(NAV_KEY) === '1')
function toggleNav() {
  navHidden.value = !navHidden.value
  localStorage.setItem(NAV_KEY, navHidden.value ? '1' : '0')
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
      { to: '/tarif-paket/kategori-tagihan', label: 'Kategori Tagihan', icon: 'tag' },
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
      <button
        class="tpl-nav-toggle"
        :class="{ active: navHidden }"
        @click="toggleNav"
        :title="navHidden ? 'Tampilkan menu samping' : 'Sembunyikan menu samping (tabel lebih lebar)'"
      >
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
        <span>{{ navHidden ? 'Tampilkan Menu' : 'Sembunyikan Menu' }}</span>
      </button>
    </header>

    <div class="tpl-grid" :class="{ 'nav-hidden': navHidden }">
      <aside v-show="!navHidden" class="tpl-nav">
        <template v-for="sec in tabs" :key="sec.section">
          <div class="tpl-nav-section">{{ sec.section }}</div>
          <RouterLink
            v-for="item in sec.items"
            :key="item.to"
            :to="item.to"
            class="tpl-nav-item"
            :class="{ 'router-link-exact-active': route.path.startsWith(item.to) }"
          >
            <svg v-if="item.icon === 'money'" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            <svg v-else-if="item.icon === 'card'" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <svg v-else-if="item.icon === 'package'" viewBox="0 0 24 24"><path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <svg v-else-if="item.icon === 'tag'" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
            <svg v-else-if="item.icon === 'bed'" viewBox="0 0 24 24"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>
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

.tpl-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.tpl-header h1 { font-family: 'Space Grotesk', serif; font-size: 26px; color: var(--td); margin: 0; line-height: 1.2; }
.tpl-sub { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

/* Tombol sembunyi/tampil sub-nav */
.tpl-nav-toggle { display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0; padding: 8px 12px; border: 1px solid var(--gb); border-radius: 9px; background: var(--bc); color: var(--tm); font-size: 12.5px; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.tpl-nav-toggle svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tpl-nav-toggle:hover { background: var(--bs); color: var(--td); border-color: var(--ga); }
.tpl-nav-toggle.active { background: var(--gl); color: var(--td); border-color: var(--ga); }
.tpl-nav-toggle.active svg { stroke: var(--ga); }

.tpl-grid { display: grid; grid-template-columns: 220px 1fr; gap: 1.5rem; align-items: start; }
.tpl-grid.nav-hidden { grid-template-columns: 1fr; }

.tpl-nav { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.6rem 0.5rem; position: sticky; top: 1.5rem; display: flex; flex-direction: column; gap: 1px; }
.tpl-nav-section { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--tu); padding: 0.6rem 0.7rem 0.3rem; margin-top: 0.3rem; }
.tpl-nav-section:first-child { margin-top: 0; }

.tpl-nav-item { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: var(--tm); font-size: 13px; transition: background 0.15s, color 0.15s; }
.tpl-nav-item svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
.tpl-nav-item:hover { background: var(--bs); color: var(--td); }
.tpl-nav-item.router-link-exact-active { background: var(--gl); color: var(--td); font-weight: 500; }
.tpl-nav-item.router-link-exact-active svg { stroke: var(--ga); }

.tpl-content { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1.3rem 1.4rem; min-height: 60vh; }
</style>
