<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import logoKlinik from '@/assets/images/logo-rs-primavision.png'

const router = useRouter()
const auth = useAuthStore()

const showPw = ref(false)
const form = ref({ username: '', password: '', remember: false })
const errors = ref({ username: '', password: '' })
const alert = ref({ show: false, type: 'e', message: '' })
const successPopup = ref(false)   // popup "Login Berhasil, Selamat Melayani!"
const forgotPopup  = ref(false)   // popup "Lupa password → hubungi Admin IT"

const alertIcons = {
  e: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".6" fill="currentColor"/>',
  s: '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>',
  w: '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/>',
  i: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/>',
}
const alertIcon = computed(() => alertIcons[alert.value.type] || alertIcons.e)

const passwordInput = ref(null)
function focusPassword() { passwordInput.value?.focus() }

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
    successPopup.value = true
    setTimeout(() => router.push(dest), 1400)
  } catch {
    alert.value = { show: true, type: 'e', message: auth.error ?? 'Login gagal. Periksa username dan password.' }
  }
}
</script>

<template>
  <div class="login-shell">
    <!-- POPUP SUKSES LOGIN -->
    <Transition name="pop">
      <div v-if="successPopup" class="success-overlay">
        <div class="success-box">
          <div class="success-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>
            </svg>
          </div>
          <h3 class="success-title">Login Berhasil</h3>
          <p class="success-sub">Selamat Melayani{{ auth.employeeName ? ` ${auth.employeeName}` : '!' }}</p>
        </div>
      </div>
    </Transition>

    <!-- POPUP LUPA PASSWORD -->
    <Transition name="pop">
      <div v-if="forgotPopup" class="success-overlay" @click.self="forgotPopup = false">
        <div class="success-box">
          <div class="success-icon forgot-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
          </div>
          <h3 class="success-title">Reset Password</h3>
          <p class="success-sub">Hubungi Admin IT untuk reset password.</p>
          <button class="bs forgot-ok" @click="forgotPopup = false">Mengerti</button>
        </div>
      </div>
    </Transition>

    <!-- FORM PANEL (centered) -->
    <div class="rp">
      <div class="card">
        <img :src="logoKlinik" alt="RUMAH SAKIT MATA PRIMA VISION" class="card-logo" />

        <div class="ch">
          <p class="ch-eye">Selamat datang kembali</p>
          <h2>Masuk ke Sistem</h2>
          <span class="ch-sub">Silahkan masukkan Username dan Password Anda</span>
        </div>

        <div v-if="alert.show" :class="['alert', 'a' + alert.type]">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" v-html="alertIcon"></svg>
          <span>{{ alert.message }}</span>
          <button class="ax" @click="alert.show = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <div class="fg">
          <label class="fl">Username</label>
          <div class="iw">
            <div class="ii"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg></div>
            <input
              v-model="form.username"
              type="text"
              class="fi"
              :class="{ err: errors.username }"
              placeholder="Username"
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
          <a href="#" class="fg2" @click.prevent="forgotPopup = true">Lupa password?</a>
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

        <p class="ver">PT. Karya Sistem Nusantara &nbsp;·&nbsp; © 2026 RS MATA PRIMA VISION MEDAN</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.login-shell {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  width: 100vw;
  /* scroll di shell + padding → kartu tidak pernah nabrak atas/bawah */
  overflow-y: auto;
  padding: 2.5rem 1.5rem;
  background: var(--bp);
}

/* FORM PANEL (centering wrapper) */
.rp {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
}
.card {
  width: 100%;
  max-width: 420px;
  background: var(--bc);
  border-radius: 20px;
  padding: 2.25rem;
  border: 1px solid rgba(0, 0, 0, 0.07);
  box-shadow: 0 12px 40px rgba(10, 42, 77, 0.12);
}
.card-logo {
  display: block;
  width: 100%;
  max-width: 260px;
  height: auto;
  margin: 0 auto 1.5rem;
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
  font-family: 'Space Grotesk', serif;
  font-size: 26px;
  color: var(--td);
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
  font-family: 'Inter', sans-serif;
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
  font-family: 'Inter', sans-serif;
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
  box-shadow: 0 4px 16px rgba(10, 42, 77, 0.2);
}
.bs:active:not(:disabled) {
  transform: scale(0.96);
  box-shadow: 0 1px 6px rgba(10, 42, 77, 0.12);
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

/* ─── POPUP SUKSES LOGIN ─── */
.success-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(10, 42, 77, 0.45);
  backdrop-filter: blur(3px);
}
.success-box {
  background: #fff;
  border-radius: 18px;
  padding: 2.25rem 2.75rem;
  text-align: center;
  box-shadow: 0 20px 60px rgba(10, 42, 77, 0.3);
  min-width: 280px;
}
.success-icon {
  width: 64px;
  height: 64px;
  margin: 0 auto 1rem;
  border-radius: 50%;
  background: var(--sb, #e8f3e9);
  color: #16a34a;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: success-pop 0.4s ease;
}
.success-icon svg { width: 34px; height: 34px; }
.success-title {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 22px;
  font-weight: 600;
  color: var(--td);
  margin-bottom: 4px;
}
.success-sub {
  font-size: 14px;
  color: var(--tu);
}
@keyframes success-pop {
  0%   { transform: scale(0.4); opacity: 0; }
  60%  { transform: scale(1.12); }
  100% { transform: scale(1); opacity: 1; }
}
/* Popup lupa password — ikon kunci biru + tombol konfirmasi */
.forgot-icon { background: var(--lb, #e6f0fa); color: var(--ld, #0E3A66); }
.forgot-ok { margin-top: 1.25rem; }
/* Transition Vue untuk overlay */
.pop-enter-active { transition: opacity 0.25s ease; }
.pop-leave-active { transition: opacity 0.2s ease; }
.pop-enter-from, .pop-leave-to { opacity: 0; }

/* HP sempit (iPhone standar / Galaxy Fold): kecilkan padding agar isi kartu tidak
   terjepit di lebar ~280–390px. Kartu tetap fluid (max-width 420). */
@media (max-width: 480px) {
  .login-shell { padding: 1.5rem 0.85rem; }
  .card { padding: 1.5rem 1.25rem; }
  .card-logo { max-width: 200px; }
}
</style>
