<script setup>
/**
 * MasterDataLayout — shell untuk halaman /master-data/*.
 *
 * Layout: vertical tabs nav kiri (8 link RouterLink) + <RouterView/> kanan.
 * Permission guard di-handle router parent (meta.permission = 'pengaturan.read').
 */
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const route = useRoute()

const tabs = [
  {
    section: 'Administrasi',
    items: [
      { to: '/master-data/profil-klinik', label: 'Profil Klinik',  icon: 'profile' },
      { to: '/master-data/wilayah',       label: 'Wilayah Indonesia', icon: 'map' },
    ],
  },
  {
    section: 'Klasifikasi',
    items: [
      { to: '/master-data/icd10', label: 'ICD-10 (Diagnosa)', icon: 'tag' },
      { to: '/master-data/icd9',  label: 'ICD-9 (Prosedur)',  icon: 'tag' },
    ],
  },
]

// Computed current tab label utk page title
const currentTabLabel = computed(() => {
  for (const sec of tabs) {
    const found = sec.items.find((it) => it.to === route.path)
    if (found) return found.label
  }
  return 'Master Data'
})
</script>

<template>
  <div class="mdl-wrap">
    <!-- Page header -->
    <header class="mdl-header">
      <div>
        <h1>Master Data</h1>
        <p class="mdl-sub">Kelola data referensi sistem — {{ currentTabLabel }}</p>
      </div>
    </header>

    <div class="mdl-grid">
      <!-- Sidebar tabs -->
      <aside class="mdl-nav">
        <template v-for="sec in tabs" :key="sec.section">
          <div class="mdl-nav-section">{{ sec.section }}</div>
          <RouterLink
            v-for="item in sec.items"
            :key="item.to"
            :to="item.to"
            class="mdl-nav-item"
          >
            <!-- Icon set -->
            <svg v-if="item.icon === 'profile'" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <svg v-else-if="item.icon === 'map'" viewBox="0 0 24 24"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
            <svg v-else-if="item.icon === 'money'" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            <svg v-else-if="item.icon === 'pill'" viewBox="0 0 24 24"><path d="M10.5 20.5a6 6 0 1 1 8.485-8.486l-8.485 8.486zM13.515 3.515a6 6 0 0 1 0 8.485l-8.485-8.485"/></svg>
            <svg v-else-if="item.icon === 'box'" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <svg v-else-if="item.icon === 'lens'" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
            <svg v-else viewBox="0 0 24 24"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>

            <span>{{ item.label }}</span>
          </RouterLink>
        </template>
      </aside>

      <!-- Content area -->
      <main class="mdl-content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.mdl-wrap { padding: 1.5rem 2rem; display: flex; flex-direction: column; gap: 1.2rem; max-width: 1400px; }

.mdl-header h1 { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--td); margin: 0; line-height: 1.2; }
.mdl-sub { font-size: 13px; color: var(--tm); margin: 4px 0 0; }

.mdl-grid {
  display: grid;
  grid-template-columns: 220px 1fr;
  gap: 1.5rem;
  align-items: start;
}
@media (max-width: 900px) { .mdl-grid { grid-template-columns: 1fr; } }

/* ─── Sidebar tabs ─── */
.mdl-nav {
  background: var(--bc);
  border: 1px solid var(--gb);
  border-radius: 12px;
  padding: 0.6rem 0.5rem;
  position: sticky;
  top: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.mdl-nav-section {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--tu);
  padding: 0.6rem 0.7rem 0.3rem;
  margin-top: 0.3rem;
}
.mdl-nav-section:first-child { margin-top: 0; }

.mdl-nav-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 10px;
  border-radius: 8px;
  text-decoration: none;
  color: var(--tm);
  font-size: 13px;
  transition: background 0.15s, color 0.15s;
}
.mdl-nav-item svg {
  width: 15px;
  height: 15px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  flex-shrink: 0;
}
.mdl-nav-item:hover { background: var(--bs); color: var(--gd); }
.mdl-nav-item.router-link-exact-active {
  background: var(--gl);
  color: var(--gd);
  font-weight: 500;
}
.mdl-nav-item.router-link-exact-active svg { stroke: var(--ga); }

/* ─── Content area ─── */
.mdl-content {
  background: var(--bc);
  border: 1px solid var(--gb);
  border-radius: 12px;
  padding: 1.3rem 1.4rem;
  min-height: 60vh;
}
</style>
