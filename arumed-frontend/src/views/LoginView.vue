<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import logoKlinik from '@/assets/images/logo-klinik.jpg'

const router = useRouter()
const auth = useAuthStore()

const sel = ref('dokter')
const showPw = ref(false)
const doctorModal = ref(false)
const form = ref({ username: '', password: '', remember: false })
const errors = ref({ username: '', password: '' })
const alert = ref({ show: false, type: 'e', message: '' })

const roles = [
  { id: 'admin', l: 'Admin', i: '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>' },
  { id: 'dokter', l: 'Dokter', i: '<rect x="3" y="3" width="18" height="18" rx="3"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>' },
  { id: 'perawat', l: 'Perawat / RO', i: '<path d="M12 2L4 6v6c0 5.55 3.84 10.74 8 12 4.16-1.26 8-6.45 8-12V6l-8-4z"/>' },
  { id: 'farmasi', l: 'Farmasi', i: '<path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>' },
  { id: 'kasir', l: 'Kasir', i: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>' },
  { id: 'manajer', l: 'Manajer', i: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>' },
]

const features = [
  { l: 'RME Spesialis Mata (PMK No. 24/2022)', i: '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>' },
  { l: 'Integrasi BPJS VClaim, LUPIS & INA-CBG', i: '<rect x="3" y="3" width="18" height="18" rx="3"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>' },
  { l: 'Antrean real-time WebSocket layar TV', i: '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>' },
  { l: 'Auto-send FHIR R4 ke Satu Sehat Kemenkes', i: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12"/>' },
]

const alertIcons = {
  e: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".6" fill="currentColor"/>',
  s: '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>',
  w: '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/>',
  i: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/>',
}
const alertIcon = computed(() => alertIcons[alert.value.type] || alertIcons.e)

const passwordInput = ref(null)
function focusPassword() { passwordInput.value?.focus() }

const doctors = [
  {
    name: 'dr. Ahmad Fauzi, Sp.M',
    spec: 'Spesialis Mata',
    avatar: 'AF',
    schedule: [
      { days: 'Senin – Jumat', hours: '08:00 – 14:00' },
      { days: 'Sabtu',         hours: '08:00 – 12:00' },
    ],
  },
  {
    name: 'dr. Sari Dewi, Sp.M(K)',
    spec: 'Konsultan Retina & Vitreus',
    avatar: 'SD',
    schedule: [
      { days: 'Selasa & Kamis', hours: '14:00 – 19:00' },
    ],
  },
  {
    name: 'dr. Ridwan Hakim, Sp.M',
    spec: 'Spesialis Glaukoma',
    avatar: 'RH',
    schedule: [
      { days: 'Rabu & Jumat', hours: '09:00 – 13:00' },
    ],
  },
]

// Routing per role setelah login berhasil
const roleRouteMap = {
  superadmin:   '/dashboard',
  dokter:       '/dokter',
  perawat:      '/perawat',
  refraksionis: '/refraksionis',
  penunjang:    '/penunjang',
  farmasi:      '/farmasi',
  kasir:        '/kasir',
  admisi:       '/admisi',
  verifikator:  '/bpjs',
}

function validate() {
  errors.value = { username: '', password: '' }
  let ok = true
  if (!form.value.username.trim()) { errors.value.username = 'Username tidak boleh kosong'; ok = false }
  if (!form.value.password.trim()) { errors.value.password = 'Password tidak boleh kosong'; ok = false }
  return ok
}

async function login() {
  alert.value.show = false
  if (!validate()) return

  try {
    await auth.login(form.value.username.trim(), form.value.password)
    const dest = roleRouteMap[auth.roleName] ?? '/admisi'
    alert.value = { show: true, type: 's', message: 'Login berhasil! Mengarahkan...' }
    setTimeout(() => router.push(dest), 400)
  } catch {
    alert.value = { show: true, type: 'e', message: auth.error ?? 'Login gagal. Periksa username dan password.' }
  }
}
</script>

<template>
  <div class="login-shell">
    <!-- LEFT BRAND PANEL -->
    <div class="lp">
      <div class="ring" style="width:520px;height:520px;bottom:-160px;right:-200px"></div>
      <div class="ring" style="width:380px;height:380px;bottom:-100px;right:-140px;border-color:rgba(138,191,68,0.18)"></div>
      <div class="ring" style="width:240px;height:240px;bottom:-40px;right:-80px;border-color:rgba(138,191,68,0.24)"></div>
      <div class="ring" style="width:460px;height:460px;top:-180px;left:-180px;border-color:rgba(255,255,255,0.04)"></div>
      <div class="ring" style="width:280px;height:280px;top:-100px;left:-110px;border-color:rgba(255,255,255,0.05)"></div>

      <div class="logo-area">
        <div class="eye-wrap">
          <div class="ripple r1"></div>
          <div class="ripple r2"></div>
          <div class="ripple r3"></div>
          <svg viewBox="0 0 90 90" fill="none" style="width:86px;height:86px;position:relative;z-index:1">
            <circle cx="45" cy="45" r="43" stroke="rgba(138,191,68,0.13)" stroke-width="1"/>
            <circle cx="45" cy="45" r="34" stroke="rgba(138,191,68,0.20)" stroke-width="1"/>
            <circle cx="45" cy="45" r="24" stroke="rgba(138,191,68,0.30)" stroke-width="1.5"/>
            <circle cx="45" cy="45" r="14" stroke="#8abf44" stroke-width="1.5"/>
            <circle cx="45" cy="45" r="6" fill="#8abf44" opacity="0.9"/>
            <line x1="45" y1="39" x2="45" y2="51" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
            <line x1="39" y1="45" x2="51" y2="45" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
        </div>
        <p class="brand-sub">Klinik Mata</p>
        <h1 class="brand-name">Arunika</h1>
        <p class="brand-city">Cilegon</p>
      </div>

      <div class="middle-stack">
        <p class="tagline">Sistem Informasi Manajemen Klinik Mata Terintegrasi — BPJS, Satu Sehat &amp; RME Digital</p>
        <ul class="feat-list">
          <li v-for="f in features" :key="f.l">
            <div class="feat-icon"><svg viewBox="0 0 24 24" v-html="f.i"></svg></div>
            {{ f.l }}
          </li>
        </ul>
      </div>

      <div class="pb-wrap">
        <p class="pb-lbl">Terkoneksi dengan ekosistem</p>
        <div class="pb-row">
          <span class="pb pb-bpjs">
            <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            BPJS Kesehatan
          </span>
          <span class="pb pb-ss">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M2 12h3M19 12h3"/></svg>
            Satu Sehat
          </span>
        </div>
      </div>
    </div>

    <!-- RIGHT FORM PANEL -->
    <div class="rp">
      <button class="jadwal-corner" type="button" @click="doctorModal = true">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Jadwal Dokter
      </button>
      <div class="card">
        <img :src="logoKlinik" alt="Klinik Mata Arunika" class="card-logo" />

        <div class="ch">
          <p class="ch-eye">Selamat datang kembali</p>
          <h2>Masuk ke Sistem</h2>
          <span class="ch-sub">Pilih peran dan masukkan kredensial Anda</span>
        </div>

        <div v-if="alert.show" :class="['alert', 'a' + alert.type]">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" v-html="alertIcon"></svg>
          <span>{{ alert.message }}</span>
          <button class="ax" @click="alert.show = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <label class="fl">Peran Akses</label>
        <div class="rg">
          <button
            v-for="r in roles"
            :key="r.id"
            class="rb"
            :class="{ active: sel === r.id }"
            @click="sel = r.id"
            type="button"
          >
            <svg viewBox="0 0 24 24" v-html="r.i"></svg>
            <span class="rl">{{ r.l }}</span>
          </button>
        </div>

        <div class="fg">
          <label class="fl">Username / NIP</label>
          <div class="iw">
            <div class="ii"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg></div>
            <input
              v-model="form.username"
              type="text"
              class="fi"
              :class="{ err: errors.username }"
              placeholder="Username atau NIP pegawai"
              @keydown.enter="focusPassword"
              @input="errors.username = ''"
            />
          </div>
          <p v-if="errors.username" class="em">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".6" fill="currentColor"/></svg>
            {{ errors.username }}
          </p>
        </div>

        <div class="fg">
          <label class="fl">Password</label>
          <div class="iw">
            <div class="ii"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg></div>
            <input
              ref="passwordInput"
              v-model="form.password"
              :type="showPw ? 'text' : 'password'"
              class="fi"
              :class="{ err: errors.password }"
              placeholder="Masukkan password"
              @keydown.enter="login"
              @input="errors.password = ''"
            />
            <button class="tp" type="button" @click="showPw = !showPw">
              <svg v-if="!showPw" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg v-else viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <p v-if="errors.password" class="em">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".6" fill="currentColor"/></svg>
            {{ errors.password }}
          </p>
        </div>

        <div class="rb2">
          <label class="cr">
            <input v-model="form.remember" type="checkbox" />
            <span>Ingat saya</span>
          </label>
          <a href="#" class="fg2">Lupa password?</a>
        </div>

        <button class="bs" :disabled="auth.loading" @click="login">
          <div v-if="auth.loading" class="sp"></div>
          <svg v-else viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          {{ auth.loading ? 'Memverifikasi akses...' : 'Masuk ke Sistem' }}
        </button>

        

        

        <div class="sb-row">
          <span>Terkoneksi dengan</span>
          <span class="sb sb-b">
            <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            BPJS Kesehatan
          </span>
          <span class="sb sb-s">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.22 4.22l2.12 2.12"/></svg>
            Satu Sehat
          </span>
        </div>

        <p class="ver">Arumed Apps v1.0.0 &nbsp;·&nbsp; © 2026 Klinik Mata Arunika Cilegon</p>
      </div>
    </div>
  </div>

  <!-- Doctor Schedule Modal -->
  <Teleport to="body">
    <div v-if="doctorModal" class="dm-overlay" @click.self="doctorModal = false">
      <div class="dm">
        <div class="dm-head">
          <div class="dm-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Jadwal Praktek Dokter
          </div>
          <button class="dm-close" @click="doctorModal = false">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <div class="dm-notice">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".6" fill="currentColor"/></svg>
          Jadwal ini belum terhubung ke backend. Data bersifat ilustrasi.
        </div>
        <div class="dm-body">
          <div v-for="d in doctors" :key="d.name" class="dm-card">
            <div class="dm-card-head">
              <div class="dm-avatar">{{ d.avatar }}</div>
              <div class="dm-info">
                <div class="dm-name">{{ d.name }}</div>
                <div class="dm-spec">{{ d.spec }}</div>
              </div>
            </div>
            <div class="dm-schedule">
              <div v-for="s in d.schedule" :key="s.days" class="dm-row">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span class="dm-days">{{ s.days }}</span>
                <span class="dm-hours">{{ s.hours }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.login-shell {
  display: flex;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
}

/* LEFT PANEL */
.lp {
  width: 46%;
  background: var(--gd);
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: space-between;
  padding: 3rem 2.5rem;
  overflow: hidden;
}
.ring {
  position: absolute;
  border-radius: 50%;
  pointer-events: none;
  border: 1px solid rgba(138, 191, 68, 0.11);
}
.logo-area {
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  z-index: 2;
}
.eye-wrap {
  position: relative;
  width: 92px;
  height: 92px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1.25rem;
}
.ripple {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  border: 1px solid rgba(138, 191, 68, 0.4);
}
.r1 { animation: ripple 3s ease-out infinite; }
.r2 { animation: ripple 3s ease-out 1s infinite; }
.r3 { animation: ripple 3s ease-out 2s infinite; }
.brand-sub {
  font-size: 10px;
  letter-spacing: 0.3em;
  color: var(--lm);
  text-transform: uppercase;
  margin-bottom: 4px;
  font-weight: 500;
}
.brand-name {
  font-family: 'DM Serif Display', serif;
  font-size: 46px;
  color: #fff;
  letter-spacing: -1px;
  line-height: 1;
  margin-bottom: 2px;
  font-weight: 400;
}
.brand-city {
  font-size: 10px;
  letter-spacing: 0.25em;
  color: rgba(255, 255, 255, 0.3);
  text-transform: uppercase;
}
.middle-stack {
  position: relative;
  z-index: 2;
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}
.tagline {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.4);
  text-align: center;
  line-height: 1.7;
  max-width: 270px;
}
.feat-list {
  list-style: none;
  width: 100%;
  max-width: 290px;
}
.feat-list li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  margin-bottom: 6px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.06);
  font-size: 12px;
  color: rgba(255, 255, 255, 0.55);
}
.feat-icon {
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  border-radius: 8px;
  background: rgba(138, 191, 68, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
}
.feat-icon svg {
  width: 14px;
  height: 14px;
  stroke: var(--lm);
  fill: none;
  stroke-width: 2;
  stroke-linecap: round;
}
.pb-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  z-index: 2;
}
.pb-lbl {
  font-size: 10px;
  color: rgba(255, 255, 255, 0.25);
  text-align: center;
  margin-bottom: 8px;
}
.pb-row { display: flex; gap: 8px; }
.pb {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 11px;
  font-weight: 600;
}
.pb svg {
  width: 11px;
  height: 11px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2.5;
  stroke-linecap: round;
}
.pb-bpjs {
  background: rgba(21, 128, 61, 0.25);
  color: #86efac;
  border: 1px solid rgba(134, 239, 172, 0.2);
}
.pb-ss {
  background: rgba(29, 78, 216, 0.25);
  color: #93c5fd;
  border: 1px solid rgba(147, 197, 253, 0.2);
}

/* RIGHT PANEL */
.rp {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow-y: auto;
  padding: 2rem;
  background: var(--bp);
  position: relative;
}
.jadwal-corner {
  position: absolute;
  top: 1.1rem;
  right: 1.5rem;
  z-index: 10;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 13px;
  background: var(--bc);
  border: 1px solid var(--gb);
  border-radius: 20px;
  color: var(--tm);
  font-family: 'DM Sans', sans-serif;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(0,0,0,0.07);
  transition: border-color 0.15s, color 0.15s, box-shadow 0.15s;
}
.jadwal-corner:hover { border-color: var(--ga); color: var(--gd); box-shadow: 0 3px 12px rgba(31,125,74,0.12); }
.jadwal-corner svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.card {
  width: 100%;
  max-width: 430px;
  background: var(--bc);
  border-radius: 20px;
  padding: 2.25rem;
  border: 1px solid rgba(0, 0, 0, 0.07);
}
.card-logo {
  display: block;
  max-width: 180px;
  height: auto;
  margin: 0 auto 1.25rem;
}
.ch { margin-bottom: 1.5rem; }
.ch-eye {
  font-size: 11px;
  font-weight: 500;
  color: var(--ga);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.ch h2 {
  font-family: 'DM Serif Display', serif;
  font-size: 26px;
  color: var(--gd);
  line-height: 1.1;
  font-weight: 400;
}
.ch-sub {
  font-size: 13px;
  color: var(--tu);
  display: block;
  margin-top: 3px;
}
.alert {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  padding: 10px 13px;
  border-radius: 12px;
  margin-bottom: 1.1rem;
  font-size: 12.5px;
  line-height: 1.5;
  border: 1px solid;
  transition: all 0.2s;
}
.alert svg {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
  margin-top: 1px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
}
.ax {
  margin-left: auto;
  flex-shrink: 0;
  background: none;
  border: none;
  cursor: pointer;
  opacity: 0.5;
  padding: 0 2px;
  color: inherit;
}
.ax:hover { opacity: 1; }
.ax svg { width: 13px; height: 13px; }
.ae { background: var(--eb); border-color: var(--ebd); color: var(--et); }
.as { background: var(--sb); border-color: var(--sbd); color: var(--st); }
.aw { background: var(--wb); border-color: var(--wbd); color: var(--wt); }
.ai { background: var(--ib); border-color: var(--ibd); color: var(--it); }
.fl {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: var(--tm);
  letter-spacing: 0.07em;
  text-transform: uppercase;
  margin-bottom: 8px;
}
.rg {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-bottom: 1.1rem;
}
.rb {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  padding: 10px 6px;
  border: 1.5px solid var(--gb);
  border-radius: 12px;
  background: var(--bi);
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s, transform 0.1s;
  font-family: 'DM Sans', sans-serif;
}
.rb:hover {
  border-color: var(--lm);
  background: #f2f9eb;
  transform: translateY(-1px);
}
.rb.active {
  border-color: var(--ga);
  background: var(--gl);
}
.rb svg {
  width: 18px;
  height: 18px;
  fill: none;
  stroke: var(--tm);
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.rb.active svg { stroke: var(--gd); }
.rb .rl {
  font-size: 10.5px;
  font-weight: 500;
  color: var(--tm);
  text-align: center;
  line-height: 1.2;
}
.rb.active .rl { color: var(--gd); font-weight: 600; }
.fg { margin-bottom: 1rem; }
.iw { position: relative; display: flex; align-items: center; }
.ii {
  position: absolute;
  left: 13px;
  display: flex;
  align-items: center;
}
.ii svg {
  width: 15px;
  height: 15px;
  fill: none;
  stroke: var(--th);
  stroke-width: 2;
  stroke-linecap: round;
}
.fi {
  width: 100%;
  height: 44px;
  padding: 0 42px 0 40px;
  border: 1.5px solid var(--gb);
  border-radius: 11px;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  color: var(--td);
  background: var(--bi);
  outline: none;
  transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
}
.fi::placeholder { color: var(--th); font-size: 13px; }
.fi:focus {
  border-color: var(--ga);
  background: #fff;
  box-shadow: 0 0 0 3px rgba(31, 125, 74, 0.11);
}
.fi.err { border-color: #fca5a5; background: #fff5f5; }
.fi.err:focus { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1); }
.tp {
  position: absolute;
  right: 11px;
  background: none;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  color: var(--th);
  padding: 4px;
  transition: color 0.15s;
}
.tp:hover { color: var(--tm); }
.tp svg {
  width: 15px;
  height: 15px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
}
.em {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11.5px;
  color: var(--et);
  margin-top: 5px;
}
.em svg {
  width: 12px;
  height: 12px;
  flex-shrink: 0;
  fill: none;
  stroke: currentColor;
  stroke-width: 2.5;
  stroke-linecap: round;
}
.rb2 {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.3rem;
}
.cr { display: flex; align-items: center; gap: 7px; cursor: pointer; }
.cr input { width: 15px; height: 15px; accent-color: var(--ga); cursor: pointer; }
.cr span { font-size: 13px; color: var(--tu); }
.fg2 {
  font-size: 13px;
  font-weight: 500;
  color: var(--ga);
  text-decoration: none;
  transition: color 0.15s;
}
.fg2:hover { color: var(--ld); }
.bs {
  width: 100%;
  height: 46px;
  background: var(--gd);
  color: #fff;
  border: none;
  border-radius: 11px;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: background 0.18s, transform 0.1s, box-shadow 0.18s;
  letter-spacing: 0.02em;
}
.bs:hover:not(:disabled) {
  background: var(--gm);
  box-shadow: 0 4px 16px rgba(13, 61, 46, 0.2);
}
.bs:active:not(:disabled) {
  transform: scale(0.96);
  box-shadow: 0 1px 6px rgba(13, 61, 46, 0.12);
  transition: transform 0.06s, box-shadow 0.06s;
}
.bs:disabled { background: #7aab8a; cursor: not-allowed; }
.bs svg {
  width: 16px;
  height: 16px;
  fill: none;
  stroke: white;
  stroke-width: 2;
  stroke-linecap: round;
}
.sp {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  animation: spin 0.7s linear infinite;
}
.sb-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-top: 1.4rem;
}
.sb-row > span { font-size: 11px; color: var(--th); }
.sb {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10.5px;
  font-weight: 600;
  border-radius: 6px;
  padding: 3px 9px;
  border: 1px solid;
}
.sb svg {
  width: 10px;
  height: 10px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2.5;
  stroke-linecap: round;
}
.sb-b { background: #e8f3e9; color: #2d6a31; border-color: #a7d7ac; }
.sb-s { background: #eff8ff; color: #1d4ed8; border-color: #93c5fd; }
.ver {
  text-align: center;
  font-size: 10.5px;
  color: #c0cfc6;
  margin-top: 6px;
}

/* ─── DOCTOR MODAL ─── */
.dm-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,0.45); backdrop-filter: blur(3px);
  display: flex; align-items: center; justify-content: center;
  padding: 1rem;
}
.dm {
  background: var(--bc); border-radius: 16px;
  width: 100%; max-width: 440px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.25);
  overflow: hidden;
}
.dm-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.25rem; border-bottom: 1px solid var(--gb);
}
.dm-title {
  display: flex; align-items: center; gap: 7px;
  font-size: 14px; font-weight: 600; color: var(--td);
}
.dm-title svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.dm-close {
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  border: none; background: var(--bs); border-radius: 7px; cursor: pointer; transition: background 0.15s;
}
.dm-close:hover { background: var(--eb); }
.dm-close svg { width: 14px; height: 14px; fill: none; stroke: var(--tm); stroke-width: 2; stroke-linecap: round; }
.dm-notice {
  display: flex; align-items: center; gap: 7px;
  background: var(--wb); border-bottom: 1px solid var(--wbd);
  color: var(--wt); font-size: 11.5px; padding: 8px 1.25rem;
}
.dm-notice svg { width: 13px; height: 13px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.dm-body { padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: 10px; }
.dm-card {
  border: 1px solid var(--gb); border-radius: 11px; padding: 0.85rem 1rem;
  background: var(--bs);
}
.dm-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 0.6rem; }
.dm-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--gl); border: 1.5px solid var(--ga);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: var(--gd); flex-shrink: 0;
}
.dm-name { font-size: 13px; font-weight: 600; color: var(--td); }
.dm-spec { font-size: 11px; color: var(--tu); margin-top: 1px; }
.dm-schedule { display: flex; flex-direction: column; gap: 5px; }
.dm-row { display: flex; align-items: center; gap: 7px; }
.dm-row svg { width: 12px; height: 12px; flex-shrink: 0; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.dm-days { font-size: 12px; color: var(--tm); flex: 1; }
.dm-hours {
  font-size: 11.5px; font-weight: 600; color: var(--gd);
  background: var(--gl); padding: 2px 8px; border-radius: 6px;
  font-variant-numeric: tabular-nums;
}
</style>
