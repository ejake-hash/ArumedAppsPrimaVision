<script setup>
import { ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAdmisiStore } from '@/stores/admisiStore'
import logoPv from '@/assets/images/logo-pv.png'

const auth    = useAuthStore()
const admisi  = useAdmisiStore()
const router  = useRouter()
const collapsed = ref(false)

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <aside :class="['sidebar', { collapsed }]">

    <div class="sb-logo">
      <img :src="logoPv" alt="Prima Vision" class="sb-logo-img" />
      <div class="sb-brand">
        <div class="sb-brand-name">SIMRS</div>
        <div class="sb-brand-sub">RS Mata Prima Vision</div>
      </div>
      <button
        class="sb-toggle-top"
        @click="collapsed = !collapsed"
        :title="collapsed ? 'Perluas sidebar' : 'Sembunyikan sidebar'"
      >
        <svg viewBox="0 0 24 24">
          <polyline v-if="!collapsed" points="15 18 9 12 15 6"/>
          <polyline v-else points="9 18 15 12 9 6"/>
        </svg>
      </button>
    </div>

    <nav class="sb-nav">
      <div class="sb-section">Utama</div>
      <RouterLink to="/dashboard" class="sb-item" title="Dashboard">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span>Dashboard</span>
      </RouterLink>
      <RouterLink v-if="auth.can('admisi.read')" to="/admisi" class="sb-item" title="Admisi">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
        <span>Admisi</span>
        <span class="sb-badge">{{ admisi.antrianCount }}</span>
      </RouterLink>

      <div class="sb-section">Klinis</div>
      <RouterLink v-if="auth.can('rme_dokter.read')" to="/rekam-medis" class="sb-item" title="Rekam Medis">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <span>Rekam Medis</span>
      </RouterLink>
      <RouterLink v-if="auth.can('perawat.read')" to="/perawat" class="sb-item" title="Triase / Perawat">
        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        <span>Triase / Perawat</span>
      </RouterLink>
      <RouterLink v-if="auth.can('refraksionis.read')" to="/refraksionis" class="sb-item" title="Refraksionis">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        <span>Refraksionis</span>
      </RouterLink>
      <RouterLink v-if="auth.can('rme_dokter.read')" to="/dokter" class="sb-item" title="Pemeriksaan Dokter">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <span>Pemeriksaan Dokter</span>
      </RouterLink>
      <RouterLink v-if="auth.can('rme_dokter.read')" to="/ttd-dokumen" class="sb-item" title="Tanda Tangan Dokumen">
        <svg viewBox="0 0 24 24"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><path d="M3 21l8-8"/></svg>
        <span>Tanda Tangan Dokumen</span>
      </RouterLink>
      <RouterLink to="/penunjang" class="sb-item" title="Penunjang">
        <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
        <span>Penunjang</span>
      </RouterLink>
      <RouterLink v-if="auth.can('bedah.read')" to="/bedah" class="sb-item" title="Bedah">
        <svg viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
        <span>Bedah</span>
      </RouterLink>
      <RouterLink v-if="auth.can('bedah.read')" to="/bedah/terjadwal" class="sb-item sb-subitem" title="Pasien Terjadwal">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Pasien Terjadwal</span>
      </RouterLink>
      <RouterLink v-if="auth.can('rawat_inap.read')" to="/rawat-inap" class="sb-item" title="Rawat Inap">
        <svg viewBox="0 0 24 24"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>
        <span>Rawat Inap</span>
      </RouterLink>

      <RouterLink v-if="auth.can('igd.read')" to="/igd" class="sb-item" title="IGD (Darurat)">
        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><line x1="12" y1="8" x2="12" y2="14"/><line x1="9" y1="11" x2="15" y2="11"/></svg>
        <span>IGD (Darurat)</span>
      </RouterLink>

      <div class="sb-section">Operasional</div>
      <RouterLink v-if="auth.can('farmasi.read')" to="/farmasi" class="sb-item" title="Farmasi">
        <svg viewBox="0 0 24 24"><path d="M3 3h18v18H3zM3 9h18M9 21V9"/></svg>
        <span>Farmasi</span>
      </RouterLink>
      <RouterLink v-if="auth.can('inventori_farmasi.read')" to="/inventori-farmasi" class="sb-item" title="Inventori Farmasi">
        <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        <span>Inventori Farmasi</span>
      </RouterLink>
      <RouterLink v-if="auth.can('kasir.read')" to="/kasir" class="sb-item" title="Kasir & Billing">
        <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        <span>Kasir & Billing</span>
      </RouterLink>
      <RouterLink v-if="auth.can('bpjs.read')" to="/bpjs" class="sb-item" title="Klaim BPJS">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Klaim BPJS</span>
      </RouterLink>
      <RouterLink v-if="auth.can('kasir.read')" to="/asuransi" class="sb-item" title="Asuransi & Klaim TPA">
        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><polyline points="9 12 11 14 15 10"/></svg>
        <span>Asuransi & TPA</span>
      </RouterLink>

      <div class="sb-section">Sistem</div>
      <RouterLink v-if="auth.can('admisi.write') || auth.isSuperadmin" to="/jadwal-dokter" class="sb-item" title="Jadwal Dokter">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="16" y1="14" x2="16" y2="14"/></svg>
        <span>Jadwal Dokter</span>
      </RouterLink>
      <RouterLink v-if="auth.can('pengaturan.read')" to="/master-data" class="sb-item" title="Master Data">
        <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v6c0 1.66 4.03 3 9 3s9-1.34 9-3V5"/><path d="M3 11v6c0 1.66 4.03 3 9 3s9-1.34 9-3v-6"/></svg>
        <span>Master Data</span>
      </RouterLink>
      <RouterLink v-if="auth.can('tarif_paket.read')" to="/tarif-paket" class="sb-item" title="Tarif & Paket Bedah">
        <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <span>Tarif &amp; Paket Bedah</span>
      </RouterLink>
      <RouterLink v-if="auth.can('role_akses.read')" to="/DataPengguna" class="sb-item" title="Hak Akses">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        <span>Hak Akses</span>
      </RouterLink>
      <RouterLink to="/antrean-tv" class="sb-item" title="Antrean TV">
        <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        <span>Antrean TV</span>
      </RouterLink>
      <RouterLink v-if="auth.can('integrasi.read') || auth.isSuperadmin" to="/bridging" class="sb-item" title="Bridging">
        <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        <span>BRIDGING</span>
      </RouterLink>
      <RouterLink v-if="auth.can('pengaturan.read')" to="/pengaturan" class="sb-item" title="Pengaturan">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
        <span>Pengaturan</span>
      </RouterLink>
    </nav>

    <div class="sb-foot" v-if="auth.user">
      <div class="sb-user">
        <div class="sb-avatar">{{ auth.initials }}</div>
        <div class="sb-user-info">
          <div class="sb-uname">{{ auth.employeeName }}</div>
          <div class="sb-urole">{{ auth.roleName }}</div>
        </div>
      </div>
      <button class="sb-logout" @click="handleLogout" title="Keluar">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Keluar</span>
      </button>
    </div>

  </aside>
</template>

<style scoped>
.sidebar {
  width: var(--sidebar);
  background: var(--bc);
  border-right: 1px solid var(--gb);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  overflow: hidden;
  height: 100vh;
  transition: width 0.25s ease;
}
.sidebar.collapsed { width: 60px; }

/* ─── LOGO / HEADER ─── */
.sb-logo {
  padding: 1.1rem 1rem 1rem;
  border-bottom: 1px solid var(--gb);
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
  position: relative;
}
.collapsed .sb-brand { display: none; }
.sb-logo-img {
  width: 34px; height: 34px;
  object-fit: contain; flex-shrink: 0;
}
.collapsed .sb-logo-img { margin: 0 auto; }
.sb-brand { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.sb-brand-name {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 18px; color: var(--td); line-height: 1; font-weight: 700; letter-spacing: 0.04em;
}
.sb-brand-sub {
  font-size: 9px; color: var(--ga);
  letter-spacing: 0.08em; text-transform: uppercase; margin-top: 3px; font-weight: 600;
}

/* ─── COLLAPSE TOGGLE (top-right of header) ─── */
.sb-toggle-top {
  flex-shrink: 0;
  width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid var(--gb);
  background: var(--bg);
  border-radius: 7px;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}
.sb-toggle-top:hover { background: var(--gl); border-color: var(--ga); }
.sb-toggle-top svg {
  width: 13px; height: 13px;
  fill: none; stroke: var(--tu);
  stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round;
  transition: stroke 0.15s;
}
.sb-toggle-top:hover svg { stroke: var(--ga); }
.collapsed .sb-toggle-top { margin: 0 auto; }

/* ─── NAV ─── */
.sb-nav { flex: 1; overflow-y: auto; padding: 0.75rem 0.6rem; }
.sb-nav::-webkit-scrollbar { width: 3px; }
.sb-nav::-webkit-scrollbar-thumb { background: var(--gb); }
.sb-section {
  font-size: 9px; font-weight: 700;
  color: var(--th); letter-spacing: 0.15em;
  text-transform: uppercase; padding: 0.4rem 0.5rem 0.3rem; margin-top: 0.5rem;
}
.collapsed .sb-section { display: none; }
.sb-item {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 10px; border-radius: 9px;
  cursor: pointer; transition: background 0.15s, color 0.15s;
  margin-bottom: 2px; text-decoration: none;
  border: 1px solid transparent;
}
.collapsed .sb-item { justify-content: center; padding: 8px; gap: 0; }
.sb-item:hover { background: var(--bg); }
.sb-item.router-link-active {
  background: var(--gl);
  border-color: var(--gb);
}
.sb-item svg {
  width: 16px; height: 16px; fill: none;
  stroke: var(--tu); stroke-width: 2;
  stroke-linecap: round; flex-shrink: 0;
}
.sb-item.router-link-active svg { stroke: var(--ga); }
.sb-item span { font-size: 12.5px; color: var(--tm); font-weight: 500; }
.collapsed .sb-item > span { display: none; }
.sb-item.router-link-active span { color: var(--ga); font-weight: 600; }
.sb-badge {
  margin-left: auto;
  background: var(--ga); color: #fff;
  font-size: 9px !important; font-weight: 700 !important;
  padding: 1px 6px; border-radius: 20px;
}

/* ─── SUB-ITEM (indented child of parent menu) ─── */
.sb-subitem { margin-left: 14px; padding-left: 12px; position: relative; }
.sb-subitem::before {
  content: '';
  position: absolute;
  left: 4px; top: 50%;
  width: 6px; height: 1px;
  background: var(--gb);
}
.sb-subitem svg { width: 13px; height: 13px; }
.sb-subitem span { font-size: 11.5px; }
.collapsed .sb-subitem { margin-left: 0; padding-left: 8px; }
.collapsed .sb-subitem::before { display: none; }

/* ─── FOOTER ─── */
.sb-foot {
  padding: 0.75rem 0.8rem 0.85rem;
  border-top: 1px solid var(--gb);
  flex-shrink: 0; display: flex; flex-direction: column; gap: 6px;
}
.collapsed .sb-foot { padding: 0.75rem 0.5rem; }
.sb-user { display: flex; align-items: center; gap: 8px; }
.collapsed .sb-user { justify-content: center; }
.sb-user-info { min-width: 0; flex: 1; }
.collapsed .sb-user-info { display: none; }
.sb-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--gl);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: var(--ga); flex-shrink: 0;
}
.sb-uname { font-size: 12px; color: var(--td); font-weight: 600; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sb-urole { font-size: 10px; color: var(--tu); white-space: nowrap; text-transform: capitalize; }

/* ─── LOGOUT BUTTON ─── */
.sb-logout {
  width: 100%; display: flex; align-items: center; gap: 8px;
  padding: 7px 10px; border-radius: 9px;
  border: 1px solid var(--gb);
  background: var(--bg);
  cursor: pointer; transition: background 0.15s, border-color 0.15s;
  font-family: 'Inter', sans-serif;
}
.sb-logout:hover { background: var(--eb); border-color: var(--ebd); }
.sb-logout:hover svg { stroke: var(--et); }
.sb-logout:hover span { color: var(--et); }
.sb-logout svg {
  width: 15px; height: 15px; fill: none;
  stroke: var(--tu); stroke-width: 2;
  stroke-linecap: round; flex-shrink: 0; transition: stroke 0.15s;
}
.sb-logout span { font-size: 12.5px; color: var(--tm); font-weight: 500; transition: color 0.15s; }
.collapsed .sb-logout { justify-content: center; padding: 7px; gap: 0; }
.collapsed .sb-logout span { display: none; }
</style>
