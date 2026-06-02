<script setup>
/**
 * TarifPaketLayout — shell sub-modul /tarif-paket/*.
 *
 * Layout pattern mirror MasterDataLayout — vertical tabs nav kiri + RouterView kanan.
 * 3 section: Tarif Tindakan (master procedures), Metode Bayar (master insurers),
 * dan Paket Bedah.
 */
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const route = useRoute()

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
    section: 'Tarif Kamar',
    items: [
      { to: '/tarif-paket/tarif-kamar', label: 'Tarif Kamar Inap', icon: 'bed' },
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
    </header>

    <div class="tpl-grid">
      <aside class="tpl-nav">
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

.tpl-header h1 { font-family: 'Space Grotesk', serif; font-size: 26px; color: var(--td); margin: 0; line-height: 1.2; }
.tpl-sub { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

.tpl-grid { display: grid; grid-template-columns: 220px 1fr; gap: 1.5rem; align-items: start; }
@media (max-width: 900px) { .tpl-grid { grid-template-columns: 1fr; } }

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
