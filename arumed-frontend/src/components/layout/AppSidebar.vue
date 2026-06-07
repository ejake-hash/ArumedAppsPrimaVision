<script setup>
import { ref, reactive, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAdmisiStore } from '@/stores/admisiStore'
import { authApi, formTemplateApi } from '@/services/api'
import { useUiShell } from '@/composables/useUiShell'
import logoPv from '@/assets/images/logo-pv.png'

const auth    = useAuthStore()
const admisi  = useAdmisiStore()
const router  = useRouter()
const collapsed = ref(false)
const { mobileNavOpen, closeMobileNav } = useUiShell()

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}

// ─── Ganti Password ─────────────────────────────────────────────────────────
const showPwdModal = ref(false)
const pwdSaving = ref(false)
const pwdError = ref('')
const pwdOk = ref('')
const pwdForm = reactive({ current: '', next: '', confirm: '' })
const pwdShow = reactive({ current: false, next: false, confirm: false })

function openPwdModal() {
  pwdForm.current = ''; pwdForm.next = ''; pwdForm.confirm = ''
  pwdShow.current = false; pwdShow.next = false; pwdShow.confirm = false
  pinForm.password = ''; pinForm.pin = ''; pinForm.confirm = ''
  pinShow.pin = false
  pwdError.value = ''; pwdOk.value = ''
  showPwdModal.value = true
}
function closePwdModal() {
  if (pwdSaving.value) return
  showPwdModal.value = false
}

async function submitChangePassword() {
  pwdError.value = ''; pwdOk.value = ''
  if (!pwdForm.current) { pwdError.value = 'Password saat ini wajib diisi.'; return }
  if ((pwdForm.next || '').length < 8) { pwdError.value = 'Password baru minimal 8 karakter.'; return }
  if (pwdForm.next !== pwdForm.confirm) { pwdError.value = 'Konfirmasi password tidak cocok.'; return }
  if (pwdForm.next === pwdForm.current) { pwdError.value = 'Password baru tidak boleh sama dengan password lama.'; return }

  pwdSaving.value = true
  try {
    await authApi.changePassword({
      current_password: pwdForm.current,
      new_password: pwdForm.next,
      new_password_confirmation: pwdForm.confirm,
    })
    pwdOk.value = 'Password berhasil diubah.'
    pwdForm.current = ''; pwdForm.next = ''; pwdForm.confirm = ''
  } catch (e) {
    pwdError.value = e.response?.data?.message ?? 'Gagal mengubah password.'
  } finally {
    pwdSaving.value = false
  }
}

// ─── PIN tanda tangan (khusus dokter) ───────────────────────────────────────
const pinForm = reactive({ password: '', pin: '', confirm: '' })
const pinShow = reactive({ pin: false })

async function submitChangePin() {
  pwdError.value = ''; pwdOk.value = ''
  if (!pinForm.password) { pwdError.value = 'Masukkan password saat ini untuk mengubah PIN.'; return }
  if (!/^\d{4,6}$/.test(pinForm.pin)) { pwdError.value = 'PIN harus 4–6 digit angka.'; return }
  if (pinForm.pin !== pinForm.confirm) { pwdError.value = 'Konfirmasi PIN tidak cocok.'; return }

  pwdSaving.value = true
  try {
    await authApi.changePin({ current_password: pinForm.password, pin: pinForm.pin })
    pwdOk.value = 'PIN tanda tangan berhasil diubah.'
    pinForm.password = ''; pinForm.pin = ''; pinForm.confirm = ''
  } catch (e) {
    pwdError.value = e.response?.data?.message ?? 'Gagal mengubah PIN.'
  } finally {
    pwdSaving.value = false
  }
}

async function resetToDefault() {
  pwdError.value = ''; pwdOk.value = ''
  const msg = auth.isDoctor
    ? 'Reset password ke 888888 dan KOSONGKAN PIN? Anda harus mengatur PIN baru sebelum bisa tanda tangan.'
    : 'Reset password ke 888888?'
  if (!window.confirm(msg)) return

  pwdSaving.value = true
  try {
    await authApi.resetToDefault()
    pwdOk.value = auth.isDoctor
      ? 'Password direset ke 888888 & PIN dikosongkan. Atur PIN baru bila perlu.'
      : 'Password direset ke 888888.'
    pwdForm.current = ''; pwdForm.next = ''; pwdForm.confirm = ''
    pinForm.password = ''; pinForm.pin = ''; pinForm.confirm = ''
  } catch (e) {
    pwdError.value = e.response?.data?.message ?? 'Gagal mereset kredensial.'
  } finally {
    pwdSaving.value = false
  }
}

// ─── Badge antrian TTD dokter ────────────────────────────────────────────────
const ttdCount = ref(0)
onMounted(async () => {
  if (!auth.can('rekam_medis.read')) return
  try {
    const { data } = await formTemplateApi.ttdCount()
    ttdCount.value = data.data?.count ?? 0
  } catch (_) { /* abaikan — badge opsional */ }
})
</script>

<template>
  <aside :class="['sidebar', { collapsed, 'mobile-open': mobileNavOpen }]">

    <div class="sb-logo">
      <img :src="logoPv" alt="Prima Vision" class="sb-logo-img" />
      <div class="sb-brand">
        <div class="sb-brand-name">PT. Karya Sistem<br />Nusantara</div>
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
      <RouterLink v-if="auth.can('rekam_medis.read')" to="/rekam-medis" class="sb-item" title="Rekam Medis">
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
      <RouterLink v-if="auth.can('ttd_dokumen.read')" to="/ttd-dokumen" class="sb-item" title="Tanda Tangan Dokumen">
        <svg viewBox="0 0 24 24"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><path d="M3 21l8-8"/></svg>
        <span>Tanda Tangan Dokumen</span>
        <span v-if="ttdCount > 0" class="sb-badge">{{ ttdCount }}</span>
      </RouterLink>
      <RouterLink v-if="auth.can('penunjang.read')" to="/penunjang" class="sb-item" title="Penunjang">
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
      <RouterLink v-if="auth.can('ruang_tindakan.read')" to="/ruang-tindakan" class="sb-item" title="Ruang Tindakan (Laser YAG/PRP)">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/></svg>
        <span>Ruang Tindakan</span>
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
      <RouterLink v-if="auth.can('asuransi.read')" to="/asuransi" class="sb-item" title="Asuransi & Klaim TPA">
        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><polyline points="9 12 11 14 15 10"/></svg>
        <span>Asuransi & TPA</span>
      </RouterLink>
      <RouterLink v-if="auth.can('marketing.read')" to="/laporan-marketing" class="sb-item" title="Laporan Marketing">
        <svg viewBox="0 0 24 24"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 11-5.8-1.6"/></svg>
        <span>Laporan Marketing</span>
      </RouterLink>

      <div class="sb-section">Sistem</div>
      <RouterLink v-if="auth.can('jadwal_dokter.write') || auth.isSuperadmin" to="/jadwal-dokter" class="sb-item" title="Jadwal Dokter">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/><line x1="12" y1="14" x2="12" y2="14"/><line x1="16" y1="14" x2="16" y2="14"/></svg>
        <span>Jadwal Dokter</span>
      </RouterLink>
      <RouterLink v-if="auth.can('master_data.read') || auth.can('rawat_inap.read')" to="/master-data" class="sb-item" title="Master Data">
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
      <RouterLink v-if="auth.can('antrian_tv.read')" to="/antrean-tv" class="sb-item" title="Antrean TV">
        <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        <span>Antrean TV</span>
      </RouterLink>
      <RouterLink v-if="auth.can('integrasi.read') || auth.isSuperadmin" to="/bridging" class="sb-item" title="Bridging">
        <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        <span>BRIDGING</span>
      </RouterLink>
      <RouterLink v-if="auth.can('master_data.read')" to="/pengaturan" class="sb-item" title="Pengaturan">
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
        <button class="sb-pwd-icon" @click="openPwdModal" title="Ganti Password">
          <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        </button>
      </div>
      <button class="sb-logout" @click="handleLogout" title="Keluar">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Keluar</span>
      </button>
      <div class="sb-copyright">© 2026 PT. Karya Sistem Nusantara</div>
    </div>

    <!-- Modal Ganti Password (inline agar CSS scoped berlaku) -->
    <div v-if="showPwdModal" class="pwd-overlay" @click.self="closePwdModal">
      <div class="pwd-box">
        <div class="pwd-head">
          <div class="pwd-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Ganti Password
          </div>
          <button class="pwd-close" @click="closePwdModal" :disabled="pwdSaving">×</button>
        </div>

        <form class="pwd-body" @submit.prevent="submitChangePassword">
          <div class="pwd-fg">
            <label>Password Saat Ini</label>
            <div class="pwd-inwrap">
              <input :type="pwdShow.current ? 'text' : 'password'" v-model="pwdForm.current"
                class="pwd-input" autocomplete="current-password" placeholder="Password lama" />
              <button type="button" class="pwd-eye" @click="pwdShow.current = !pwdShow.current"
                :title="pwdShow.current ? 'Sembunyikan' : 'Tampilkan'">{{ pwdShow.current ? '🙈' : '👁' }}</button>
            </div>
          </div>

          <div class="pwd-fg">
            <label>Password Baru</label>
            <div class="pwd-inwrap">
              <input :type="pwdShow.next ? 'text' : 'password'" v-model="pwdForm.next"
                class="pwd-input" autocomplete="new-password" placeholder="Minimal 8 karakter" />
              <button type="button" class="pwd-eye" @click="pwdShow.next = !pwdShow.next"
                :title="pwdShow.next ? 'Sembunyikan' : 'Tampilkan'">{{ pwdShow.next ? '🙈' : '👁' }}</button>
            </div>
          </div>

          <div class="pwd-fg">
            <label>Konfirmasi Password Baru</label>
            <div class="pwd-inwrap">
              <input :type="pwdShow.confirm ? 'text' : 'password'" v-model="pwdForm.confirm"
                class="pwd-input" autocomplete="new-password" placeholder="Ulangi password baru" />
              <button type="button" class="pwd-eye" @click="pwdShow.confirm = !pwdShow.confirm"
                :title="pwdShow.confirm ? 'Sembunyikan' : 'Tampilkan'">{{ pwdShow.confirm ? '🙈' : '👁' }}</button>
            </div>
          </div>

          <div class="pwd-actions">
            <button type="button" class="pwd-btn cancel" @click="closePwdModal" :disabled="pwdSaving">Tutup</button>
            <button type="submit" class="pwd-btn save" :disabled="pwdSaving">
              {{ pwdSaving ? 'Menyimpan…' : 'Simpan Password' }}
            </button>
          </div>
        </form>

        <!-- PIN Tanda Tangan — khusus akun dokter -->
        <template v-if="auth.isDoctor">
          <div class="pwd-sep"><span>PIN Tanda Tangan</span></div>
          <form class="pwd-body pwd-body-pin" @submit.prevent="submitChangePin">
            <div class="pwd-fg">
              <label>Password Saat Ini</label>
              <input type="password" v-model="pinForm.password"
                class="pwd-input" autocomplete="current-password" placeholder="Konfirmasi dengan password" />
            </div>
            <div class="pwd-fg">
              <label>PIN Baru</label>
              <div class="pwd-inwrap">
                <input :type="pinShow.pin ? 'text' : 'password'" v-model="pinForm.pin"
                  class="pwd-input" inputmode="numeric" maxlength="6" placeholder="4–6 digit angka" />
                <button type="button" class="pwd-eye" @click="pinShow.pin = !pinShow.pin"
                  :title="pinShow.pin ? 'Sembunyikan' : 'Tampilkan'">{{ pinShow.pin ? '🙈' : '👁' }}</button>
              </div>
            </div>
            <div class="pwd-fg">
              <label>Konfirmasi PIN Baru</label>
              <input :type="pinShow.pin ? 'text' : 'password'" v-model="pinForm.confirm"
                class="pwd-input" inputmode="numeric" maxlength="6" placeholder="Ulangi PIN" />
            </div>
            <div class="pwd-actions">
              <button type="submit" class="pwd-btn save" :disabled="pwdSaving">
                {{ pwdSaving ? 'Menyimpan…' : 'Simpan PIN' }}
              </button>
            </div>
          </form>
        </template>

        <!-- Reset ke default — berlaku semua user (PIN hanya dikosongkan bila dokter) -->
        <div class="pwd-sep danger"><span>Reset</span></div>
        <div class="pwd-body pwd-body-reset">
          <p class="pwd-reset-hint">
            Kembalikan password ke <b>888888</b><template v-if="auth.isDoctor"> dan kosongkan PIN (atur PIN baru sebelum menandatangani dokumen)</template>.
          </p>
          <button type="button" class="pwd-btn reset" @click="resetToDefault" :disabled="pwdSaving">
            {{ auth.isDoctor ? 'Reset Password & PIN ke Default' : 'Reset Password ke Default' }}
          </button>
        </div>

        <!-- Pesan global (berlaku untuk semua aksi di modal) -->
        <div v-if="pwdError || pwdOk" class="pwd-foot-msg">
          <div v-if="pwdError" class="pwd-msg err">{{ pwdError }}</div>
          <div v-if="pwdOk" class="pwd-msg ok">{{ pwdOk }}</div>
        </div>
      </div>
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

/* ─── Mode drawer (≤1024px, HANYA rute `app-fluid`: TTD Dokumen) ──────────────
   Sidebar keluar dari aliran flex (position:fixed) lalu di-slide dari kiri.
   Mode "collapsed" desktop diabaikan di sini — drawer selalu lebar penuh.
   Buka/tutup dikendalikan kelas .mobile-open dari useUiShell. Di rute desktop
   biasa (tanpa `app-fluid`) sidebar tetap kolom tetap walau jendela sempit. */
@media (max-width: 1024px) {
  html.app-fluid .sidebar,
  html.app-fluid .sidebar.collapsed {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 70;
    width: var(--sidebar);
    transform: translateX(-100%);
    transition: transform 0.25s ease;
  }
  html.app-fluid .sidebar.mobile-open {
    transform: translateX(0);
    box-shadow: 8px 0 32px rgba(15, 30, 50, 0.22);
  }
  /* Chevron collapse adalah fitur desktop — di drawer pakai scrim untuk menutup */
  html.app-fluid .sb-toggle-top { display: none; }
  /* Paksa isi tampil penuh walau state collapsed tertinggal dari desktop */
  html.app-fluid .collapsed .sb-brand,
  html.app-fluid .collapsed .sb-section,
  html.app-fluid .collapsed .sb-item > span,
  html.app-fluid .collapsed .sb-user-info,
  html.app-fluid .collapsed .sb-logout span { display: revert; }
  html.app-fluid .collapsed .sb-item { justify-content: flex-start; padding: 8px 10px; gap: 9px; }
}

/* ─── LOGO / HEADER ─── */
.sb-logo {
  padding: 0.65rem 1rem;
  border-bottom: 1px solid var(--gb);
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
  position: relative;
}
.collapsed .sb-brand { display: none; }
/* Saat collapsed (60px), logo + tombol chevron tak muat sebaris → ter-clip
   (overflow:hidden) sehingga tombol BUKA KEMBALI hilang. Tumpuk vertikal:
   logo di atas, chevron di bawah — keduanya pasti terlihat & bisa diklik. */
.collapsed .sb-logo {
  flex-direction: column;
  gap: 6px;
  padding-left: 0;
  padding-right: 0;
}
.sb-logo-img {
  height: 34px; width: auto; max-width: 40px;
  object-fit: contain; flex-shrink: 0;
}
.collapsed .sb-logo-img { margin: 0 auto; height: 28px; }
.sb-brand { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.sb-brand-name {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 10.5px; color: var(--td); line-height: 1.1; font-weight: 700; letter-spacing: 0.02em;
  white-space: nowrap;
}
.sb-brand-sub {
  font-size: 7.5px; color: var(--ga); line-height: 1.2;
  letter-spacing: 0.04em; text-transform: uppercase; margin-top: 2px; font-weight: 600;
  white-space: nowrap;
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

/* ─── COPYRIGHT (footer sidebar) ─── */
.sb-copyright {
  margin-top: 8px;
  text-align: center;
  font-size: 10.5px;
  line-height: 1.3;
  color: var(--tu);
}
.collapsed .sb-copyright { display: none; }

/* Ikon kecil Ganti Password — di samping nama akun */
.sb-pwd-icon {
  flex-shrink: 0; width: 28px; height: 28px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 7px; border: 1px solid var(--gb); background: var(--bg);
  cursor: pointer; transition: background 0.15s, border-color 0.15s;
  padding: 0;
}
.sb-pwd-icon:hover { background: var(--gl); border-color: var(--ga); }
.sb-pwd-icon:hover svg { stroke: var(--ga); }
.sb-pwd-icon svg {
  width: 15px; height: 15px; fill: none; stroke: var(--tu); stroke-width: 2;
  stroke-linecap: round; stroke-linejoin: round; transition: stroke 0.15s;
}
.collapsed .sb-pwd-icon { display: none; }

/* ── Modal Ganti Password ──────────────────────────────────────────────── */
.pwd-overlay {
  position: fixed; inset: 0; z-index: 9000;
  background: rgba(15, 30, 50, 0.45);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
}
.pwd-box {
  width: 100%; max-width: 380px;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 14px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.25); overflow: hidden;
  font-family: 'Inter', sans-serif;
}
.pwd-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--gb);
}
.pwd-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: var(--td); }
.pwd-title svg { width: 17px; height: 17px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.pwd-close {
  border: none; background: none; cursor: pointer; font-size: 22px; line-height: 1;
  color: var(--tu); padding: 0 4px;
}
.pwd-close:hover { color: var(--td); }
.pwd-close:disabled { opacity: 0.4; cursor: not-allowed; }
.pwd-body { padding: 1rem 1.1rem 1.1rem; display: flex; flex-direction: column; gap: 0.7rem; }
.pwd-fg { display: flex; flex-direction: column; gap: 4px; }
.pwd-fg label { font-size: 11px; font-weight: 600; color: var(--tm); }
.pwd-inwrap { position: relative; display: flex; align-items: center; }
.pwd-input {
  width: 100%; padding: 8px 34px 8px 11px; font-size: 13px;
  border: 1px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--td);
  font-family: 'Inter', sans-serif; transition: border-color 0.15s;
}
.pwd-input:focus { outline: none; border-color: var(--ga); }
.pwd-eye {
  position: absolute; right: 6px; border: none; background: none; cursor: pointer;
  font-size: 14px; line-height: 1; padding: 2px; opacity: 0.7;
}
.pwd-eye:hover { opacity: 1; }
.pwd-msg { font-size: 11.5px; padding: 7px 10px; border-radius: 7px; line-height: 1.35; }
.pwd-msg.err { background: var(--eb); border: 1px solid var(--ebd); color: var(--et); }
.pwd-msg.ok  { background: var(--gl); border: 1px solid var(--ga); color: var(--gd); }
.pwd-actions { display: flex; gap: 8px; margin-top: 0.3rem; }
.pwd-btn {
  flex: 1; padding: 9px 12px; border-radius: 8px; font-size: 13px; font-weight: 600;
  cursor: pointer; transition: all 0.15s; font-family: 'Inter', sans-serif;
}
.pwd-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.pwd-btn.cancel { border: 1px solid var(--gb); background: var(--bg); color: var(--tm); }
.pwd-btn.cancel:hover:not(:disabled) { background: var(--bs); }
.pwd-btn.save { border: 1px solid var(--ga); background: var(--ga); color: #fff; }
.pwd-btn.save:hover:not(:disabled) { filter: brightness(0.95); }
.pwd-btn.reset { flex: 1; border: 1px solid var(--ebd); background: var(--eb); color: var(--et); }
.pwd-btn.reset:hover:not(:disabled) { background: var(--et); color: #fff; }

/* Pemisah antar-section dalam modal */
.pwd-sep {
  display: flex; align-items: center; gap: 8px;
  padding: 0 1.1rem; margin-top: 0.2rem;
  color: var(--tu); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
}
.pwd-sep::before, .pwd-sep::after { content: ''; flex: 1; height: 1px; background: var(--gb); }
.pwd-sep.danger { color: var(--et); }
.pwd-sep.danger::before, .pwd-sep.danger::after { background: var(--ebd); }
.pwd-body-pin, .pwd-body-reset { padding-top: 0.6rem; padding-bottom: 0.6rem; }
.pwd-reset-hint { font-size: 11px; color: var(--tu); line-height: 1.4; margin: 0 0 0.2rem; }
.pwd-foot-msg { padding: 0 1.1rem 1.1rem; }
</style>
