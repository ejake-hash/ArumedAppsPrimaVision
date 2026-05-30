<script setup>
/**
 * BridgingLayout — shell modul /bridging/*.
 * Sub-route per halaman (Konfigurasi & Status, Log). VClaim & Antrean menyusul.
 * Pola mengikuti InventoriFarmasiLayout (nav vertikal + RouterView), style ringan.
 */
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/authStore'

const route = useRoute()
const auth = useAuthStore()

const allTabs = [
  { to: '/bridging/konfigurasi', label: 'Konfigurasi & Status', icon: 'cog' },
  { to: '/bridging/vclaim',      label: 'VClaim',               icon: 'card' },
  { to: '/bridging/antrean',     label: 'Antrean Online',       icon: 'queue' },
  { to: '/bridging/satusehat',   label: 'Satu Sehat',           icon: 'health' },
  { to: '/bridging/log',         label: 'Log Integrasi',        icon: 'list' },
]

// Permission integrasi.* belum tentu ada di semua deployment — fallback: superadmin.
const tabs = computed(() =>
  allTabs.filter(() => auth.can?.('integrasi.read') || auth.isSuperadmin),
)

const currentLabel = computed(
  () => allTabs.find((t) => route.path.startsWith(t.to))?.label ?? 'Bridging',
)
</script>

<template>
  <div class="br-wrap">
    <header class="br-header">
      <h1>Bridging BPJS</h1>
      <p class="br-sub">Integrasi VClaim, Antrean Online &amp; Satu Sehat — {{ currentLabel }}</p>
    </header>

    <div class="br-grid">
      <aside class="br-nav">
        <RouterLink v-for="item in tabs" :key="item.to" :to="item.to" class="br-nav-item">
          <svg v-if="item.icon === 'cog'" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          <svg v-else-if="item.icon === 'card'" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <svg v-else-if="item.icon === 'queue'" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
          <svg v-else-if="item.icon === 'health'" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
          <svg v-else viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          <span>{{ item.label }}</span>
        </RouterLink>
      </aside>

      <main class="br-content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.br-wrap { padding: 1.5rem 2rem; display: flex; flex-direction: column; gap: 1.2rem; max-width: 1400px; }
.br-header h1 { font-family: 'Space Grotesk', serif; font-size: 26px; color: var(--td); margin: 0; line-height: 1.2; }
.br-sub { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

.br-grid { display: grid; grid-template-columns: 220px 1fr; gap: 1.5rem; align-items: start; }
@media (max-width: 900px) { .br-grid { grid-template-columns: 1fr; } }

.br-nav { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 0.6rem 0.5rem; position: sticky; top: 1.5rem; display: flex; flex-direction: column; gap: 1px; }
.br-nav-item { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: var(--tm); font-size: 13px; transition: background 0.15s, color 0.15s; }
.br-nav-item svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
.br-nav-item:hover { background: var(--bs); color: var(--td); }
.br-nav-item.router-link-active { background: var(--gl); color: var(--td); font-weight: 600; }

.br-content { min-width: 0; }
</style>
