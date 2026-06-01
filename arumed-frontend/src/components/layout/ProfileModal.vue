<script setup>
import { ref, computed, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const auth = useAuthStore()

// ─── Tampilan profil (read-only) ───────────────────────────────────────────
const u        = computed(() => auth.user ?? {})
const emp      = computed(() => u.value.employee ?? null)
const roleText = computed(() => u.value.role?.display_name || u.value.role?.name || '—')
// dash() → tampilkan "—" untuk nilai kosong/null biar tabel rapi
const dash = (v) => (v === null || v === undefined || v === '' ? '—' : v)

// ─── Ganti password ─────────────────────────────────────────────────────────
const showPwd   = ref(false)          // toggle bagian ganti password
const curPwd    = ref('')
const newPwd    = ref('')
const confPwd   = ref('')
const showPlain = ref(false)          // tampilkan/sembunyikan teks password
const submitting = ref(false)
const msg       = ref(null)           // { type: 'ok'|'err', text }

// Validasi lokal sebelum kirim
const pwdError = computed(() => {
  if (!newPwd.value && !confPwd.value && !curPwd.value) return null
  if (newPwd.value && newPwd.value.length < 6) return 'Password baru minimal 6 karakter.'
  if (newPwd.value && confPwd.value && newPwd.value !== confPwd.value) return 'Konfirmasi password tidak cocok.'
  return null
})
const canSubmit = computed(() =>
  !submitting.value &&
  curPwd.value.length > 0 &&
  newPwd.value.length >= 6 &&
  newPwd.value === confPwd.value,
)

function resetPwdForm() {
  curPwd.value = newPwd.value = confPwd.value = ''
  showPlain.value = false
  msg.value = null
}

async function submitPwd() {
  if (!canSubmit.value) return
  submitting.value = true
  msg.value = null
  try {
    await auth.changePassword(curPwd.value, newPwd.value)
    msg.value = { type: 'ok', text: 'Password berhasil diganti.' }
    curPwd.value = newPwd.value = confPwd.value = ''
    showPlain.value = false
  } catch (e) {
    msg.value = {
      type: 'err',
      text: e?.response?.data?.message || auth.error || 'Gagal mengganti password. Periksa password lama Anda.',
    }
  } finally {
    submitting.value = false
  }
}

function close() {
  emit('update:modelValue', false)
}

// Saat modal dibuka: refresh data diri (ambil NIP/SIP/STR/NIK terbaru) & reset form
watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      showPwd.value = false
      resetPwdForm()
      auth.fetchMe?.()
    }
  },
)
</script>

<template>
  <Transition name="pm-fade">
    <div v-if="modelValue" class="pm-ov" @click.self="close">
      <div class="pm-box">
        <!-- Header -->
        <div class="pm-head">
          <span class="pm-title">Profil Akun</span>
          <button class="pm-close" @click="close" aria-label="Tutup">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <div class="pm-body">
          <!-- Kartu identitas -->
          <div class="pm-hdr-card">
            <div class="pm-av">{{ auth.initials }}</div>
            <div class="pm-hdr-info">
              <div class="pm-hdr-name">{{ auth.employeeName || '—' }}</div>
              <div class="pm-hdr-role">{{ roleText }}</div>
              <div class="pm-hdr-meta">@{{ dash(u.username) }}</div>
            </div>
          </div>

          <!-- Detail (read-only) -->
          <div class="pm-sec">Data Akun</div>
          <div class="pm-rows">
            <div class="pm-row"><span class="pm-k">Nama</span><span class="pm-v">{{ dash(auth.employeeName) }}</span></div>
            <div class="pm-row"><span class="pm-k">Role</span><span class="pm-v">{{ roleText }}</span></div>
            <div class="pm-row"><span class="pm-k">Username</span><span class="pm-v">{{ dash(u.username) }}</span></div>
            <div class="pm-row"><span class="pm-k">Email</span><span class="pm-v">{{ dash(u.email) }}</span></div>
          </div>

          <template v-if="emp">
            <div class="pm-sec">Data Tenaga Kesehatan</div>
            <div class="pm-rows">
              <div class="pm-row"><span class="pm-k">Profesi</span><span class="pm-v">{{ dash(emp.profession) }}</span></div>
              <div class="pm-row"><span class="pm-k">NIP</span><span class="pm-v">{{ dash(emp.nip) }}</span></div>
              <div class="pm-row"><span class="pm-k">NIK</span><span class="pm-v">{{ dash(emp.nik) }}</span></div>
              <div class="pm-row"><span class="pm-k">SIP</span><span class="pm-v">{{ dash(emp.sip) }}</span></div>
              <div class="pm-row"><span class="pm-k">STR</span><span class="pm-v">{{ dash(emp.str) }}</span></div>
            </div>
          </template>

          <div class="pm-note">
            Data profil di atas dikelola oleh administrator melalui menu <b>Hak Akses</b>.
            Di sini Anda hanya dapat mengganti password sendiri.
          </div>

          <!-- Ganti Password -->
          <div class="pm-divider"></div>

          <button v-if="!showPwd" class="pm-btn pm-btn-ga pm-btn-full" @click="showPwd = true">
            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Ganti Password
          </button>

          <div v-if="showPwd" class="pm-pwd">
            <div class="pm-sec">Ganti Password</div>

            <div class="pm-fg">
              <label class="pm-fl">Password Lama</label>
              <input v-model="curPwd" class="pm-fi" :type="showPlain ? 'text' : 'password'" placeholder="Password saat ini" autocomplete="current-password"/>
            </div>
            <div class="pm-fg">
              <label class="pm-fl">Password Baru</label>
              <input v-model="newPwd" class="pm-fi" :type="showPlain ? 'text' : 'password'" placeholder="Min. 6 karakter" autocomplete="new-password"/>
            </div>
            <div class="pm-fg">
              <label class="pm-fl">Konfirmasi Password Baru</label>
              <input v-model="confPwd" class="pm-fi" :type="showPlain ? 'text' : 'password'" placeholder="Ulangi password baru" autocomplete="new-password"/>
            </div>

            <label class="pm-show">
              <input type="checkbox" v-model="showPlain"/>
              <span>Tampilkan password</span>
            </label>

            <div v-if="pwdError" class="pm-msg pm-msg-err">{{ pwdError }}</div>
            <div v-if="msg" :class="['pm-msg', msg.type === 'ok' ? 'pm-msg-ok' : 'pm-msg-err']">{{ msg.text }}</div>

            <div class="pm-actions">
              <button class="pm-btn pm-btn-o" @click="showPwd = false; resetPwdForm()">Batal</button>
              <button class="pm-btn pm-btn-ga" :disabled="!canSubmit" @click="submitPwd">
                {{ submitting ? 'Menyimpan…' : 'Simpan Password' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.pm-ov { position: fixed; inset: 0; background: rgba(0,0,0,.42); z-index: 500; display: flex; align-items: center; justify-content: center; }
.pm-box { background: #fff; border-radius: 13px; box-shadow: 0 8px 32px rgba(0,0,0,.18); width: 460px; max-width: 92vw; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }

.pm-head { padding: .6rem 1rem; border-bottom: 1px solid var(--gb, #DEE4EB); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.pm-title { font-size: 13px; font-weight: 600; color: var(--td, #1a1a1a); }
.pm-close { width: 26px; height: 26px; border-radius: 5px; border: 1px solid var(--gb, #DEE4EB); background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pm-close:hover { background: #f1f5f9; }
.pm-close svg { width: 11px; height: 11px; fill: none; stroke: #64748b; stroke-width: 2; stroke-linecap: round; }

.pm-body { padding: .85rem 1rem; overflow-y: auto; flex: 1; }
.pm-body::-webkit-scrollbar { width: 4px; }
.pm-body::-webkit-scrollbar-thumb { background: var(--gb, #DEE4EB); border-radius: 4px; }

/* Kartu identitas (gradient navy konsisten dgn AppSidebar/DataPengguna) */
.pm-hdr-card { display: flex; align-items: center; gap: .75rem; padding: .7rem; background: linear-gradient(135deg, #1A5384, #0E3A66); border-radius: 10px; margin-bottom: .8rem; }
.pm-av { width: 46px; height: 46px; border-radius: 50%; background: rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 700; color: #fff !important; flex-shrink: 0; }
.pm-hdr-info { flex: 1; min-width: 0; }
.pm-hdr-name { font-size: 14px; font-weight: 600; color: #fff !important; }
.pm-hdr-role { font-size: 10.5px; color: rgba(255,255,255,.72) !important; margin-top: 1px; text-transform: capitalize; }
.pm-hdr-meta { font-size: 10px; color: rgba(255,255,255,.5) !important; margin-top: 1px; }

.pm-sec { font-size: 9px; font-weight: 600; color: #64748b; letter-spacing: .06em; text-transform: uppercase; padding-bottom: .3rem; border-bottom: 1px solid var(--gb, #DEE4EB); margin: .25rem 0 .45rem; }

.pm-rows { display: flex; flex-direction: column; gap: 2px; margin-bottom: .6rem; }
.pm-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .35rem .5rem; background: #f8fafc; border: 1px solid var(--gb, #DEE4EB); border-radius: 7px; }
.pm-k { font-size: 10.5px; color: #64748b; flex-shrink: 0; }
.pm-v { font-size: 12px; font-weight: 500; color: #000 !important; text-align: right; word-break: break-word; }

.pm-note { font-size: 10px; color: #64748b; line-height: 1.5; background: #f1f5f9; border-radius: 7px; padding: .45rem .6rem; margin-bottom: .2rem; }
.pm-note b { color: #0E3A66; }

.pm-divider { height: 1px; background: var(--gb, #DEE4EB); margin: .7rem 0; }

/* Form ganti password */
.pm-fg { display: flex; flex-direction: column; margin-bottom: .45rem; }
.pm-fl { font-size: 9px; font-weight: 600; color: #64748b; letter-spacing: .04em; text-transform: uppercase; margin-bottom: 2px; }
.pm-fi { width: 100%; background: #f8fafc; border: 1.5px solid var(--gb, #DEE4EB); border-radius: 7px; font-family: 'Inter', sans-serif; font-size: 12px; color: #000 !important; outline: none; padding: 6px 8px; height: 33px; box-sizing: border-box; transition: border-color .15s; }
.pm-fi:focus { border-color: #1FAAE0; background: #fff; box-shadow: 0 0 0 3px rgba(31,170,224,.1); }

.pm-show { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #475569; cursor: pointer; margin: .1rem 0 .5rem; user-select: none; }
.pm-show input { cursor: pointer; }

.pm-msg { font-size: 11px; border-radius: 7px; padding: .4rem .55rem; margin-bottom: .5rem; line-height: 1.4; }
.pm-msg-ok  { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.pm-msg-err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

.pm-actions { display: flex; gap: .5rem; justify-content: flex-end; }

.pm-btn { display: inline-flex; align-items: center; justify-content: center; gap: 5px; padding: 0 14px; height: 34px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .14s; border: 1.5px solid transparent; }
.pm-btn svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.pm-btn:disabled { opacity: .55; cursor: not-allowed; }
.pm-btn-full { width: 100%; }
.pm-btn-ga { background: #1763d4; color: #fff !important; border-color: #1763d4; }
.pm-btn-ga:hover:not(:disabled) { background: #0E3A66; }
.pm-btn-o { background: #fff; color: #475569 !important; border-color: var(--gb, #DEE4EB); }
.pm-btn-o:hover { background: #f1f5f9; }

/* Transition */
.pm-fade-enter-active, .pm-fade-leave-active { transition: opacity .2s ease; }
.pm-fade-enter-from, .pm-fade-leave-to { opacity: 0; }
</style>
