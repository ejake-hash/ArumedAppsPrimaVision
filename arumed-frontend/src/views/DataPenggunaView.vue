<script setup>
import { ref, computed, onMounted } from 'vue'
import { useDataPenggunaStore } from '@/stores/dataPenggunaStore'
import { useAuthStore } from '@/stores/auth'

const store = useDataPenggunaStore()
const auth  = useAuthStore()

// ─── Cosmetic role colors (frontend only — derived from name) ────────────────
const ROLE_COLOR_PALETTE = ['#082b20','#1d4ed8','#7e22ce','#0891b2','#15803d','#b45309','#be185d','#9f1239','#374151','#0d3d2e','#1f7d4a','#8abf44']
function colorOf(role) {
  if (!role?.name) return 'var(--th)'
  if (role.name === 'superadmin') return '#082b20'
  let h = 0
  for (const c of role.name) h = (h * 31 + c.charCodeAt(0)) >>> 0
  return ROLE_COLOR_PALETTE[h % ROLE_COLOR_PALETTE.length]
}

// ─── Permission ↔ matrix helpers ──────────────────────────────────────────────
// Backend: permission_keys = ["admisi.read", "admisi.write", ...]
// Template: perms = { admisi: ['R','W'], kasir: ['R'] }
const ACTION_CODE_TO_KEY = { R: 'read', W: 'write', D: 'delete' }
const ACTION_KEY_TO_CODE = { read: 'R', write: 'W', delete: 'D' }

function keysToPerms(keys = []) {
  const out = {}
  for (const k of keys) {
    if (k === '*') continue
    const [mod, action] = k.split('.')
    const code = ACTION_KEY_TO_CODE[action]
    if (!mod || !code) continue
    if (! out[mod]) out[mod] = []
    if (! out[mod].includes(code)) out[mod].push(code)
  }
  return out
}

function permsToIds(perms) {
  const ids = []
  for (const [mod, codes] of Object.entries(perms || {})) {
    for (const c of codes) {
      const key = `${mod}.${ACTION_CODE_TO_KEY[c]}`
      const found = store.permissionFlat.find((p) => p.key === key)
      if (found) ids.push(found.id)
    }
  }
  return ids
}

// ─── Tab navigation ──────────────────────────────────────────────────────────
const pgTab = ref('roles')

// ─── Modal state ─────────────────────────────────────────────────────────────
const modal       = ref(null)        // 'role' | 'user' | 'userDetail'
const selRole     = ref(null)
const selUser     = ref(null)

const editRole = ref({
  id: null, name: '', display_name: '', description: '',
  is_active: true, is_system: false, perms: {},
})

const editUser = ref({
  id: null, name: '', username: '', email: '',
  role_id: '', employee_id: null,
  password: '', pin: '', has_pin: false, is_active: true,
})

// ─── Filters ─────────────────────────────────────────────────────────────────
const srUser     = ref('')
const filterRole = ref('')
const filterAktif = ref('')

// PIN tanda tangan relevan untuk akun dokter, dan superadmin (agar bisa menguji
// alur tanda tangan tanpa harus punya akun dokter terpisah).
const canHavePin = computed(() => {
  const r = store.roleById[editUser.value.role_id]
  return r?.name === 'dokter' || r?.name === 'superadmin'
})

const filtUsers = computed(() => {
  let list = store.users
  const s = srUser.value.trim().toLowerCase()
  if (s) list = list.filter((u) =>
    u.name?.toLowerCase().includes(s) ||
    u.username?.toLowerCase().includes(s) ||
    u.email?.toLowerCase().includes(s),
  )
  if (filterRole.value)  list = list.filter((u) => u.role?.id === filterRole.value)
  if (filterAktif.value !== '') list = list.filter((u) => String(u.is_active) === filterAktif.value)
  return list
})

// ─── Modules untuk matrix (dari permissionGroups, urut abjad) ────────────────
const modules = computed(() =>
  store.permissionGroups
    .map((g) => ({ id: g.module, nama: g.label, sub: '' }))
    .sort((a, b) => a.nama.localeCompare(b.nama, 'id')),
)

// Modul yang tampil di matriks (terfilter pencarian)
const srMod = ref('')
const matrixModules = computed(() => {
  const s = srMod.value.trim().toLowerCase()
  if (! s) return modules.value
  return modules.value.filter((m) => m.nama.toLowerCase().includes(s))
})

// ─── Toast ───────────────────────────────────────────────────────────────────
const toasts = ref([])
let tid = 0
function toast(type, msg) {
  const id = ++tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3500)
}
function errMsg(e, fallback) {
  return e?.response?.data?.message ?? e?.message ?? fallback
}

// ─── Role actions ────────────────────────────────────────────────────────────
function initPerms() {
  const p = {}
  for (const m of modules.value) p[m.id] = []
  return p
}
function openNewRole() {
  editRole.value = {
    id: null, name: '', display_name: '', description: '',
    is_active: true, is_system: false, perms: initPerms(),
  }
  modal.value = 'role'
}
function openEditRole(r) {
  editRole.value = {
    id: r.id,
    name: r.name,
    display_name: r.display_name ?? '',
    description: r.description ?? '',
    is_active: r.is_active,
    is_system: r.is_system,
    perms: keysToPerms(r.permission_keys),
  }
  modal.value = 'role'
}
function togglePerm(modId, code) {
  const perms = editRole.value.perms
  if (! perms[modId]) perms[modId] = []
  const i = perms[modId].indexOf(code)
  if (i >= 0) perms[modId].splice(i, 1)
  else perms[modId].push(code)
}
async function saveRole() {
  const d = editRole.value
  if (! d.name?.trim()) { toast('w', 'Kode role wajib diisi'); return }
  if (! d.display_name?.trim()) d.display_name = d.name

  const payload = {
    name: d.name.trim(),
    display_name: d.display_name.trim(),
    description: d.description?.trim() || null,
    is_active: !!d.is_active,
    permission_ids: permsToIds(d.perms),
  }

  try {
    if (d.id) {
      await store.updateRole(d.id, payload)
      toast('s', `Role ${d.display_name} diperbarui`)
    } else {
      await store.createRole(payload)
      toast('s', `Role ${d.display_name} dibuat`)
    }
    modal.value = null
    await store.fetchRoles()
  } catch (e) {
    toast('e', errMsg(e, 'Gagal menyimpan role'))
  }
}
async function deleteRole() {
  const d = editRole.value
  if (! d.id || d.is_system) { toast('e', 'Role system tidak bisa dihapus'); return }
  if (! confirm(`Yakin hapus role "${d.display_name}"?`)) return
  try {
    await store.deleteRole(d.id)
    toast('w', `Role ${d.display_name} dihapus`)
    modal.value = null
    if (selRole.value?.id === d.id) selRole.value = null
  } catch (e) {
    toast('e', errMsg(e, 'Gagal menghapus role'))
  }
}

// Matrix tab — toggle perm langsung di table
async function toggleMatrixPerm(role, modId, code) {
  if (role.name === 'superadmin') {
    toast('i', 'Superadmin bypass — permission selalu penuh, tidak perlu di-set.')
    return
  }
  const perms = keysToPerms(role.permission_keys)
  if (! perms[modId]) perms[modId] = []
  const i = perms[modId].indexOf(code)
  if (i >= 0) perms[modId].splice(i, 1)
  else perms[modId].push(code)

  try {
    await store.syncRolePermissions(role.id, permsToIds(perms))
  } catch (e) {
    toast('e', errMsg(e, 'Gagal update permission'))
  }
}

function hasPerm(role, modId, code) {
  if (role?.permission_keys?.includes('*')) return true
  return role?.permission_keys?.includes(`${modId}.${ACTION_CODE_TO_KEY[code]}`)
}

// ─── Edit nama modul (label-only, matriks) ───────────────────────────────────
const editingMod      = ref(null)   // modId yang sedang diedit
const editingModLabel = ref('')
function startEditMod(mod) {
  editingMod.value = mod.id
  editingModLabel.value = mod.nama
}
async function saveModLabel(mod) {
  const label = editingModLabel.value.trim()
  if (! label || label === mod.nama) { editingMod.value = null; return }
  try {
    await store.updateModuleLabel(mod.id, label)
    toast('s', `Nama modul diperbarui → ${label}`)
  } catch (e) {
    toast('e', errMsg(e, 'Gagal mengubah nama modul'))
  } finally {
    editingMod.value = null
  }
}
async function resetModLabel(mod) {
  try {
    await store.resetModuleLabel(mod.id)
    toast('s', 'Nama modul dikembalikan ke default')
  } catch (e) {
    toast('e', errMsg(e, 'Gagal reset nama modul'))
  } finally {
    editingMod.value = null
  }
}

// ─── User actions ────────────────────────────────────────────────────────────
function openNewUser() {
  const defaultRoleId = store.roles.find((r) => !r.is_system && r.name !== 'superadmin')?.id
                     ?? store.roles[0]?.id ?? ''
  editUser.value = {
    id: null, name: '', username: '', email: '',
    role_id: defaultRoleId, employee_id: null,
    password: '', pin: '', has_pin: false, is_active: true,
  }
  modal.value = 'user'
}
function openEditUser(u) {
  editUser.value = {
    id: u.id, name: u.name, username: u.username, email: u.email,
    role_id: u.role?.id ?? '', employee_id: u.employee?.id ?? null,
    password: '', pin: '', has_pin: !!u.has_pin, is_active: u.is_active,
  }
  modal.value = 'user'
}
async function saveUser() {
  const d = editUser.value
  if (! d.name || ! d.username || ! d.email || ! d.role_id) {
    toast('w', 'Nama, username, email, role wajib diisi')
    return
  }
  if (! d.id && ! d.password) {
    toast('w', 'Password awal wajib diisi untuk user baru')
    return
  }

  // PIN hanya berlaku untuk dokter/superadmin — abaikan input PIN jika role lain.
  const pin = canHavePin.value ? d.pin : ''
  if (pin && !/^\d{4,6}$/.test(pin)) {
    toast('w', 'PIN harus 4–6 digit angka')
    return
  }

  const payload = {
    name:        d.name,
    username:    d.username,
    email:       d.email,
    role_id:     d.role_id,
    employee_id: d.employee_id || null,
    is_active:   !!d.is_active,
  }
  if (d.password) payload.password = d.password
  if (pin)        payload.pin = pin

  try {
    if (d.id) {
      await store.updateUser(d.id, payload)
      toast('s', `Pengguna ${d.name} diperbarui`)
    } else {
      await store.createUser(payload)
      toast('s', `Pengguna ${d.name} dibuat`)
    }
    modal.value = null
  } catch (e) {
    toast('e', errMsg(e, 'Gagal menyimpan pengguna'))
  }
}
async function toggleUserAktif(u) {
  try {
    await store.toggleUserAktif(u.id)
    toast(u.is_active ? 'w' : 's', `${u.name} ${u.is_active ? 'dinonaktifkan' : 'diaktifkan'}`)
  } catch (e) {
    toast('e', errMsg(e, 'Gagal mengubah status'))
  }
}
async function resetUserPwd() {
  if (! editUser.value.id) return
  if (! confirm(`Reset password ${editUser.value.name}? Password baru akan ditampilkan setelah reset.`)) return
  try {
    const newPwd = await store.resetUserPassword(editUser.value.id)
    toast('s', `Password baru: ${newPwd}`)
    alert(`Password baru untuk ${editUser.value.name}:\n\n${newPwd}\n\nSimpan/salin sebelum menutup dialog ini.`)
  } catch (e) {
    toast('e', errMsg(e, 'Gagal reset password'))
  }
}
async function resetUserPin() {
  if (! editUser.value.id) return
  if (! confirm(`Reset PIN tanda tangan ${editUser.value.name}? PIN baru (6 digit) akan ditampilkan sekali setelah reset.`)) return
  try {
    const newPin = await store.resetUserPin(editUser.value.id)
    editUser.value.has_pin = true
    toast('s', `PIN baru: ${newPin}`)
    alert(`PIN tanda tangan baru untuk ${editUser.value.name}:\n\n${newPin}\n\nBerikan ke dokter ybs & simpan sebelum menutup dialog ini. PIN tidak bisa dilihat lagi.`)
  } catch (e) {
    toast('e', errMsg(e, 'Gagal reset PIN'))
  }
}
async function deleteUser(u) {
  if (! confirm(`Yakin hapus pengguna "${u.name}"? Aksi ini tidak bisa dibatalkan.`)) return
  try {
    await store.deleteUser(u.id)
    toast('w', `${u.name} dihapus`)
  } catch (e) {
    toast('e', errMsg(e, 'Gagal menghapus pengguna'))
  }
}

// ─── CSV: Template / Export / Import ──────────────────────────────────────────
const fileInput   = ref(null)
const csvBusy     = ref(false)
const importResult = ref(null)   // { created, skipped, errors } → tampil di modal

async function downloadTemplate() {
  try {
    await store.downloadUserTemplate()
    toast('s', 'Template CSV diunduh')
  } catch (e) {
    toast('e', errMsg(e, 'Gagal mengunduh template'))
  }
}
async function exportUsers() {
  try {
    await store.exportUsersCsv()
    toast('s', 'Data pengguna diekspor')
  } catch (e) {
    toast('e', errMsg(e, 'Gagal mengekspor data'))
  }
}
function pickImportFile() {
  fileInput.value?.click()
}
async function onImportFile(ev) {
  const file = ev.target.files?.[0]
  ev.target.value = ''   // reset supaya file sama bisa dipilih ulang
  if (! file) return
  csvBusy.value = true
  try {
    const res = await store.importUsersCsv(file)
    importResult.value = res
    modal.value = 'importResult'
    const c = res?.created?.length ?? 0
    toast(c ? 's' : 'i', `Import selesai — ${c} pengguna baru ditambahkan`)
  } catch (e) {
    toast('e', errMsg(e, 'Gagal mengimpor CSV'))
  } finally {
    csvBusy.value = false
  }
}

function copyImportResult() {
  const rows = importResult.value?.created ?? []
  if (! rows.length) return
  const text = rows.map((r) => `${r.name}\t${r.username}\t${r.email}\t${r.password}`).join('\n')
  navigator.clipboard?.writeText(text)
    .then(() => toast('s', 'Daftar akun + password disalin'))
    .catch(() => toast('e', 'Gagal menyalin'))
}

// ─── Initial load ────────────────────────────────────────────────────────────
onMounted(async () => {
  await store.loadAll()
})

function userCount(roleId) {
  return store.users.filter((u) => u.role?.id === roleId).length
}
function lastLoginText(u) {
  if (! u.last_login_at) return '—'
  return new Date(u.last_login_at).toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}
</script>

<template>
  <div class="dp">

    <!-- ─── MODAL: EDIT ROLE ─── -->
    <div v-if="modal === 'role'" class="ov" @click.self="modal = null">
      <div class="mbx lg">
        <div class="mh">
          <span class="mht">{{ editRole.id ? 'Edit Role — ' + editRole.display_name : 'Buat Role Baru' }}</span>
          <button class="mcl" @click="modal = null"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="mb">
          <div class="g3" style="margin-bottom:.55rem">
            <div class="fg"><label class="fl">Kode (internal)</label><input v-model="editRole.name" class="fi" :disabled="editRole.is_system" placeholder="contoh: admisi"/></div>
            <div class="fg"><label class="fl">Nama Tampil</label><input v-model="editRole.display_name" class="fi" placeholder="contoh: Petugas Admisi"/></div>
            <div class="fg"><label class="fl">Status</label>
              <select v-model="editRole.is_active" class="fs"><option :value="true">Aktif</option><option :value="false">Non-aktif</option></select>
            </div>
          </div>
          <div class="fg" style="margin-bottom:.55rem"><label class="fl">Deskripsi</label><textarea v-model="editRole.description" class="fta" rows="2" placeholder="Deskripsi peran..."></textarea></div>
          <div class="sec"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Hak Akses per Modul</div>
          <div v-if="editRole.name === 'superadmin'" class="info-msg">
            Superadmin bypass — permission tidak perlu di-set, otomatis penuh.
          </div>
          <div v-else class="g2">
            <div v-for="mod in modules" :key="mod.id" class="perm-row">
              <div class="perm-mod">{{ mod.nama }}</div>
              <div style="display:flex;gap:4px;flex-shrink:0">
                <div v-for="p in ['R','W','D']" :key="p"
                     :class="['perm-toggle', editRole.perms[mod.id]&&editRole.perms[mod.id].includes(p)?'on':'off']"
                     @click="togglePerm(mod.id,p)"
                     :title="p==='R'?'Baca':p==='W'?'Tulis':'Hapus'">{{ p }}</div>
              </div>
            </div>
          </div>
          <div style="display:flex;gap:.4rem;margin-top:.75rem">
            <button class="btn btn-ga btn-lg btn-full" @click="saveRole"><svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/></svg>Simpan Role</button>
            <button v-if="editRole.id && !editRole.is_system" class="btn btn-e" @click="deleteRole"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Hapus</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── MODAL: EDIT USER ─── -->
    <div v-if="modal === 'user'" class="ov" @click.self="modal = null">
      <div class="mbx lg">
        <div class="mh">
          <span class="mht">{{ editUser.id ? 'Edit Pengguna — ' + editUser.name : 'Tambah Pengguna Baru' }}</span>
          <button class="mcl" @click="modal = null"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="mb">
          <div class="g2" style="margin-bottom:.4rem">
            <div class="fg"><label class="fl">Nama Lengkap</label><input v-model="editUser.name" class="fi" placeholder="Nama lengkap..."/></div>
            <div class="fg"><label class="fl">Username</label><input v-model="editUser.username" class="fi" placeholder="username login"/></div>
          </div>
          <div class="g2" style="margin-bottom:.4rem">
            <div class="fg"><label class="fl">Email</label><input v-model="editUser.email" class="fi" type="email" placeholder="email@klinik.com"/></div>
            <div class="fg"><label class="fl">Role</label>
              <select v-model="editUser.role_id" class="fs">
                <option v-for="r in store.roles" :key="r.id" :value="r.id">{{ r.display_name || r.name }}</option>
              </select>
            </div>
          </div>
          <div class="g2" style="margin-bottom:.4rem">
            <div class="fg"><label class="fl">{{ editUser.id ? 'Password (kosongkan jika tidak diubah)' : 'Password Awal' }}</label><input v-model="editUser.password" class="fi" type="password" placeholder="Min. 6 karakter"/></div>
            <div class="fg"><label class="fl">Status</label>
              <select v-model="editUser.is_active" class="fs"><option :value="true">Aktif</option><option :value="false">Non-aktif</option></select>
            </div>
          </div>
          <div v-if="canHavePin" class="g2" style="margin-bottom:.4rem">
            <div class="fg">
              <label class="fl">PIN Tanda Tangan (dokter)
                <span v-if="editUser.has_pin" style="color:var(--st);text-transform:none;letter-spacing:0">· sudah diatur</span>
              </label>
              <input v-model="editUser.pin" class="fi" type="password" inputmode="numeric" maxlength="6"
                     :placeholder="editUser.has_pin ? 'Kosongkan jika tidak diubah · isi untuk ganti' : '4–6 digit angka (opsional)'"/>
            </div>
            <div class="fg" style="justify-content:flex-end">
              <span style="font-size:9.5px;color:var(--tu);line-height:1.4">PIN dipakai untuk menandatangani dokumen RM. Tersedia untuk akun dokter &amp; superadmin (untuk pengujian).</span>
            </div>
          </div>
          <div style="display:flex;gap:.4rem;margin-top:.5rem">
            <button class="btn btn-ga btn-lg btn-full" @click="saveUser"><svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/></svg>Simpan Pengguna</button>
            <button v-if="editUser.id && auth.isSuperadmin" class="btn btn-i" @click="resetUserPwd"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>Reset Password</button>
            <button v-if="editUser.id && auth.isSuperadmin && canHavePin" class="btn btn-i" @click="resetUserPin"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>Reset PIN</button>
          </div>
          <div v-if="editUser.id && auth.isSuperadmin" class="default-note">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span><b>Nilai default sistem:</b> Reset Password membuat password acak (ditampilkan sekali). Reset PIN membuat PIN 6 digit acak (ditampilkan sekali). PIN lama & password tidak bisa dilihat — hanya bisa direset.</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── MODAL: DETAIL USER ─── -->
    <div v-if="modal === 'userDetail'" class="ov" @click.self="modal = null">
      <div class="mbx">
        <div class="mh">
          <span class="mht">Detail Pengguna — {{ selUser?.name }}</span>
          <div style="display:flex;gap:.35rem">
            <button class="btn btn-sm btn-o" @click="openEditUser(selUser)">Edit</button>
            <button class="mcl" @click="modal = null"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
          </div>
        </div>
        <div class="mb" v-if="selUser">
          <div class="user-profile-hdr">
            <div class="user-av-lg" :style="{ background: colorOf(selUser.role) }">{{ selUser.name?.charAt(0) }}</div>
            <div class="upd-info">
              <div class="upd-name">{{ selUser.name }}</div>
              <div class="upd-role">{{ selUser.role?.display_name || selUser.role?.name || '—' }}<span v-if="selUser.employee?.profession"> · {{ selUser.employee.profession }}</span></div>
              <div class="upd-meta">{{ selUser.username }} · {{ selUser.email }}</div>
            </div>
            <span :class="['pill', selUser.is_active ? 'p-ok' : 'p-off']" style="margin-left:auto;align-self:flex-start">{{ selUser.is_active ? 'Aktif' : 'Non-aktif' }}</span>
          </div>
          <div class="sec"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Hak Akses dari Role: {{ selUser.role?.display_name || selUser.role?.name }}</div>
          <div class="g2" style="margin-bottom:.6rem">
            <div v-for="mod in modules" :key="mod.id" class="perm-view-row">
              <span class="perm-mod">{{ mod.nama }}</span>
              <div style="display:flex;gap:3px">
                <span v-for="p in ['R','W','D']" :key="p" class="perm-badge"
                      :style="{ background: hasPerm(store.roleById[selUser.role?.id], mod.id, p) ? 'var(--ga)' : 'var(--gb)',
                                color: hasPerm(store.roleById[selUser.role?.id], mod.id, p) ? '#fff' : 'var(--tu)' }">{{ p }}</span>
              </div>
            </div>
          </div>
          <div class="sec"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Login Terakhir</div>
          <div class="log-item">
            <div class="log-dot" :style="{ background: 'var(--st)' }"></div>
            <div class="log-time">{{ lastLoginText(selUser) }}</div>
            <div>
              <div class="log-msg">{{ selUser.last_login_at ? 'Login terakhir' : 'Belum pernah login' }}</div>
              <div class="log-who">Akun dibuat: {{ new Date(selUser.created_at).toLocaleDateString('id-ID') }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── MODAL: HASIL IMPORT CSV ─── -->
    <div v-if="modal === 'importResult'" class="ov" @click.self="modal = null">
      <div class="mbx lg">
        <div class="mh">
          <span class="mht">Hasil Import Pengguna</span>
          <button class="mcl" @click="modal = null"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="mb" v-if="importResult">
          <div class="imp-stats">
            <div class="imp-stat ok"><div class="imp-num">{{ importResult.created?.length ?? 0 }}</div><div class="imp-lbl">Ditambah</div></div>
            <div class="imp-stat warn"><div class="imp-num">{{ importResult.skipped?.length ?? 0 }}</div><div class="imp-lbl">Dilewati</div></div>
            <div class="imp-stat err"><div class="imp-num">{{ importResult.errors?.length ?? 0 }}</div><div class="imp-lbl">Gagal</div></div>
          </div>

          <!-- Akun baru + password -->
          <template v-if="importResult.created?.length">
            <div class="sec" style="margin-top:.7rem">
              <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
              Akun Baru — Simpan/salin password sebelum menutup
              <button class="btn btn-sm btn-o" style="margin-left:auto" @click="copyImportResult">Salin Semua</button>
            </div>
            <div class="imp-warn">Password hanya ditampilkan sekali di sini. Setelah dialog ditutup, password tidak bisa dilihat lagi (harus reset).</div>
            <div style="overflow-x:auto">
              <table class="tbl">
                <thead><tr><th>Nama</th><th>Username</th><th>Email</th><th>Password</th></tr></thead>
                <tbody>
                  <tr v-for="(r, i) in importResult.created" :key="i">
                    <td style="font-weight:500;font-size:12px">{{ r.name }}</td>
                    <td class="mono-cell">{{ r.username }}</td>
                    <td style="font-size:10.5px;color:var(--tu)">{{ r.email }}</td>
                    <td class="mono-cell" style="font-weight:600;color:var(--it)">{{ r.password }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <!-- Dilewati -->
          <template v-if="importResult.skipped?.length">
            <div class="sec" style="margin-top:.7rem"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Dilewati (sudah terdaftar)</div>
            <div class="imp-list">
              <div v-for="(s, i) in importResult.skipped" :key="i" class="imp-row warn">
                <span class="imp-rowno">Baris {{ s.row }}</span>
                <span class="mono-cell">{{ s.username }}</span>
                <span style="color:var(--tu);font-size:10.5px">{{ s.reason }}</span>
              </div>
            </div>
          </template>

          <!-- Gagal -->
          <template v-if="importResult.errors?.length">
            <div class="sec" style="margin-top:.7rem"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>Gagal Diproses</div>
            <div class="imp-list">
              <div v-for="(er, i) in importResult.errors" :key="i" class="imp-row err">
                <span class="imp-rowno">Baris {{ er.row }}</span>
                <span class="mono-cell">{{ er.username || '—' }}</span>
                <span style="color:var(--et);font-size:10.5px">{{ er.reason }}</span>
              </div>
            </div>
          </template>

          <div style="margin-top:.85rem">
            <button class="btn btn-ga btn-lg btn-full" @click="modal = null">Selesai</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── NAV TABS ─── -->
    <div class="nvt">
      <button :class="['nt', pgTab === 'roles' ? 'a' : '']" @click="pgTab = 'roles'">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Daftar Role
      </button>
      <button :class="['nt', pgTab === 'matrix' ? 'a' : '']" @click="pgTab = 'matrix'">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        Matriks Hak Akses
      </button>
      <button :class="['nt', pgTab === 'users' ? 'a' : '']" @click="pgTab = 'users'">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        Pengguna
      </button>
      <button :class="['nt', pgTab === 'audit' ? 'a' : '']" @click="pgTab = 'audit'">
        <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Audit Log
      </button>
      <div class="nt-actions">
        <button v-if="pgTab === 'roles'" class="btn btn-o btn-sm" @click="openNewRole"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Buat Role</button>
      </div>
    </div>

    <!-- ─── TAB: DAFTAR ROLE ─── -->
    <div v-if="pgTab === 'roles'" class="pg">
      <div v-if="store.rolesLoading" class="loading-state">Memuat data role...</div>
      <div v-else-if="!store.roles.length" class="empty-state">Belum ada role.</div>
      <template v-else>
        <div class="role-grid3">
          <div v-for="r in store.roles" :key="r.id" :class="['role-card', selRole && selRole.id === r.id ? 'sel' : '']" @click="selRole = r">
            <div class="role-name">
              <span class="role-color" :style="{ background: colorOf(r) }"></span>
              {{ r.display_name || r.name }}
              <span v-if="r.is_system" class="sys-badge">SYSTEM</span>
              <span v-if="!r.is_active" class="off-badge">OFF</span>
            </div>
            <div class="role-desc">{{ r.description || '—' }}</div>
            <div class="role-foot">
              <div class="role-tags">
                <span v-if="r.name === 'superadmin'" class="rt">Semua Modul (Bypass)</span>
                <span v-else class="rt">{{ r.permission_keys?.length ?? 0 }} permission</span>
              </div>
              <div style="display:flex;align-items:center;gap:.35rem">
                <span class="role-cnt">{{ r.user_count ?? 0 }} user</span>
                <button class="btn btn-sm btn-o" @click.stop="openEditRole(r)">Edit</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Role detail panel -->
        <div v-if="selRole" class="card">
          <div class="ch">
            <div class="cht"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Detail Hak Akses — <span :style="{ color: colorOf(selRole) }">{{ selRole.display_name || selRole.name }}</span></div>
            <button class="btn btn-sm btn-o" @click="openEditRole(selRole)"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Role</button>
          </div>
          <div class="cb">
            <div class="g4-mod">
              <div v-for="mod in modules" :key="mod.id" class="mod-detail-card">
                <div class="mod-detail-name">{{ mod.nama }}</div>
                <div style="display:flex;gap:3px;margin-top:.3rem">
                  <span v-for="p in ['R','W','D']" :key="p" class="perm-badge"
                        :style="{ background: hasPerm(selRole, mod.id, p) ? 'var(--ga)' : 'var(--gb)',
                                  color: hasPerm(selRole, mod.id, p) ? '#fff' : 'var(--tu)' }">{{ p==='R'?'Baca':p==='W'?'Tulis':'Hapus' }}</span>
                </div>
              </div>
            </div>
            <div class="divider"></div>
            <div class="role-users-label">Pengguna dengan Role Ini ({{ userCount(selRole.id) }})</div>
            <div style="display:flex;gap:.35rem;flex-wrap:wrap">
              <div v-for="u in store.users.filter((uu) => uu.role?.id === selRole.id)" :key="u.id" class="role-user-chip" @click="selUser=u; modal='userDetail'">
                <div class="chip-av" :style="{ background: colorOf(selRole) }">{{ u.name?.charAt(0) }}</div>
                <span>{{ u.name }}</span>
                <span :class="['pill', u.is_active ? 'p-ok' : 'p-off']" style="font-size:7.5px">{{ u.is_active ? 'Aktif' : 'Off' }}</span>
              </div>
              <div v-if="userCount(selRole.id) === 0" style="font-size:11px;color:var(--tu);padding:.3rem">Belum ada pengguna dengan role ini.</div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ─── TAB: MATRIKS HAK AKSES ─── -->
    <div v-if="pgTab === 'matrix'" class="pg">
      <div class="matrix-info">
        <span><b>R</b> = Baca · <b>W</b> = Tulis · <b>D</b> = Hapus. Klik kotak untuk toggle (langsung tersimpan). Klik nama modul untuk ubah label tampilan. Superadmin bypass — tidak perlu di-set.</span>
        <input v-model="srMod" class="fi" style="width:200px;height:28px;flex-shrink:0" placeholder="Cari modul..."/>
      </div>
      <div v-if="store.rolesLoading || store.permissionsLoading" class="loading-state">Memuat matriks...</div>
      <div v-else class="card">
        <div class="cb" style="padding:0">
          <div class="matrix-wrap">
            <table class="matrix-tbl">
              <thead>
                <tr>
                  <th class="col-no" rowspan="2">No.</th>
                  <th class="sticky mod-head" style="background:var(--gd)">Modul / Fitur</th>
                  <th v-for="r in store.roles" :key="r.id" :colspan="3" class="mod-head" :style="{ background: colorOf(r) + 'dd' }">
                    <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:80px">{{ r.display_name || r.name }}</div>
                  </th>
                </tr>
                <tr>
                  <th class="sticky" style="background:var(--bs)">Modul</th>
                  <template v-for="r in store.roles" :key="r.id">
                    <th>R</th><th>W</th><th>D</th>
                  </template>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!matrixModules.length">
                  <td :colspan="2 + store.roles.length * 3" style="text-align:center;padding:1rem;color:var(--tu);font-size:11px">Tidak ada modul cocok dengan "{{ srMod }}".</td>
                </tr>
                <tr v-for="(mod, idx) in matrixModules" :key="mod.id">
                  <td class="col-no">{{ idx + 1 }}</td>
                  <td class="sticky">
                    <div v-if="editingMod === mod.id" class="mod-edit">
                      <input v-model="editingModLabel" class="fi mod-edit-inp"
                             @keyup.enter="saveModLabel(mod)" @keyup.esc="editingMod = null" autofocus/>
                      <button class="mod-edit-btn ok" @click="saveModLabel(mod)" title="Simpan">✓</button>
                      <button class="mod-edit-btn" @click="resetModLabel(mod)" title="Kembalikan ke default">↺</button>
                      <button class="mod-edit-btn" @click="editingMod = null" title="Batal">✕</button>
                    </div>
                    <div v-else class="mod-name-cell" @click="startEditMod(mod)" title="Klik untuk ubah nama tampilan">
                      <span>{{ mod.nama }}</span>
                      <svg class="mod-edit-ic" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </div>
                  </td>
                  <template v-for="r in store.roles" :key="r.id">
                    <td v-for="p in ['R','W','D']" :key="p">
                      <div :class="['perm-toggle', hasPerm(r, mod.id, p) ? 'on' : 'off', r.name === 'superadmin' ? 'locked' : '']"
                           @click="toggleMatrixPerm(r, mod.id, p)">
                        {{ hasPerm(r, mod.id, p) ? '✓' : '—' }}
                      </div>
                    </td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── TAB: PENGGUNA ─── -->
    <div v-if="pgTab === 'users'" class="pg">
      <div class="tb-row">
        <input v-model="srUser" class="fi" style="width:200px" placeholder="Cari nama, username, email..."/>
        <select v-model="filterRole" class="fs" style="width:180px">
          <option value="">Semua Role</option>
          <option v-for="r in store.roles" :key="r.id" :value="r.id">{{ r.display_name || r.name }}</option>
        </select>
        <select v-model="filterAktif" class="fs" style="width:130px">
          <option value="">Semua Status</option>
          <option value="true">Aktif</option>
          <option value="false">Non-aktif</option>
        </select>
        <div style="margin-left:auto;display:flex;gap:.35rem">
          <button class="btn btn-o btn-sm" @click="downloadTemplate" title="Unduh template CSV kosong"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Template</button>
          <button class="btn btn-o btn-sm" @click="exportUsers" title="Ekspor semua pengguna ke CSV"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Export</button>
          <button class="btn btn-i btn-sm" :disabled="csvBusy" @click="pickImportFile" title="Impor pengguna dari CSV"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>{{ csvBusy ? 'Mengimpor...' : 'Import' }}</button>
          <button class="btn btn-lm btn-sm" @click="openNewUser"><svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Tambah Pengguna</button>
        </div>
        <input ref="fileInput" type="file" accept=".csv,text/csv" style="display:none" @change="onImportFile"/>
      </div>
      <div class="card">
        <div v-if="store.usersLoading" class="loading-state">Memuat data pengguna...</div>
        <div v-else-if="!filtUsers.length" class="empty-state">Tidak ada pengguna sesuai filter.</div>
        <div v-else style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>Nama</th><th>Username</th><th>Email</th><th>Role</th><th>Jabatan / NIP</th><th>Status</th><th>Login Terakhir</th><th>Aksi</th></tr></thead>
            <tbody>
              <tr v-for="u in filtUsers" :key="u.id">
                <td>
                  <div style="display:flex;align-items:center;gap:.45rem">
                    <div class="user-av" :style="{ background: colorOf(u.role), color: '#fff' }">{{ u.name?.charAt(0) }}</div>
                    <div style="font-weight:500;font-size:12px">{{ u.name }}</div>
                  </div>
                </td>
                <td class="mono-cell">{{ u.username }}</td>
                <td style="font-size:10.5px;color:var(--tu)">{{ u.email }}</td>
                <td>
                  <div style="display:flex;align-items:center;gap:4px">
                    <span class="role-color" :style="{ background: colorOf(u.role), width:'7px', height:'7px' }"></span>
                    <span style="font-size:11px;font-weight:500">{{ u.role?.display_name || u.role?.name || '—' }}</span>
                  </div>
                </td>
                <td style="font-size:10.5px;color:var(--tu)">
                  <div v-if="u.employee">{{ u.employee.profession || '—' }}</div>
                  <div v-if="u.employee?.nip" style="font-size:9px;color:var(--th)">{{ u.employee.nip }}</div>
                  <span v-if="!u.employee">—</span>
                </td>
                <td><span :class="['pill', u.is_active ? 'p-ok' : 'p-off']">{{ u.is_active ? 'Aktif' : 'Non-aktif' }}</span></td>
                <td style="font-size:10px;color:var(--tu);font-variant-numeric:tabular-nums">{{ lastLoginText(u) }}</td>
                <td style="white-space:nowrap">
                  <button class="btn btn-sm btn-o" @click="selUser=u; modal='userDetail'" style="margin-right:2px">Detail</button>
                  <button class="btn btn-sm btn-o" @click="openEditUser(u)" style="margin-right:2px">Edit</button>
                  <button class="btn btn-sm" :class="u.is_active ? 'btn-w' : 'btn-ga'" @click="toggleUserAktif(u)" style="margin-right:2px">{{ u.is_active ? 'Non-aktif' : 'Aktifkan' }}</button>
                  <button v-if="u.username !== 'superadmin' && u.id !== auth.user?.id" class="btn btn-sm btn-e" @click="deleteUser(u)">Hapus</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ─── TAB: AUDIT LOG (placeholder) ─── -->
    <div v-if="pgTab === 'audit'" class="pg">
      <div class="card">
        <div class="cb empty-state" style="padding:2rem 1rem">
          <div style="font-size:13px;font-weight:600;color:var(--td);margin-bottom:.3rem">Audit Log</div>
          <div style="font-size:11.5px;color:var(--tu)">
            Audit log immutable (PMK No. 24/2022) akan terhubung ke <code>system_logs</code> backend pada tahap berikutnya.
          </div>
        </div>
      </div>
    </div>

    <!-- ─── TOAST ─── -->
    <div class="twrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', 'toast-' + t.type]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.dp { display: flex; flex-direction: column; }

/* ─── NAV TABS ─── */
.nvt { display: flex; align-items: center; background: var(--bc); border-bottom: 2px solid var(--gb); padding: 0 4px; flex-shrink: 0; gap: 2px; }
.nt { padding: .45rem .85rem; font-size: 11px; font-weight: 500; color: var(--tu); cursor: pointer; border: none; background: none; border-bottom: 2px solid transparent; margin-bottom: -2px; font-family: 'DM Sans', sans-serif; display: flex; align-items: center; gap: 4px; white-space: nowrap; transition: all .15s; flex-shrink: 0; }
.nt:hover { color: var(--tm); }
.nt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.nt svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.nt-actions { margin-left: auto; display: flex; gap: .35rem; padding: .3rem 0; flex-shrink: 0; }

/* ─── PAGE AREA ─── */
.pg { padding: .85rem 0; display: flex; flex-direction: column; gap: .65rem; }
.loading-state { padding: 1.5rem; text-align: center; color: var(--tu); font-size: 12px; }
.empty-state { padding: 1.5rem; text-align: center; color: var(--tu); font-size: 12px; }

.info-msg { background: var(--ib); color: var(--it); padding: .5rem .8rem; border-radius: 7px; font-size: 11px; border: 1px solid var(--ibd); margin-bottom: .5rem; }

/* ─── CARD ─── */
.card { background: var(--bc); border-radius: 11px; border: 1px solid var(--gb); overflow: hidden; }
.ch { padding: .6rem .9rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: .3rem; flex-wrap: wrap; }
.cht { font-size: 12px; font-weight: 600; color: var(--td); display: flex; align-items: center; gap: 5px; }
.cht svg { width: 11px; height: 11px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.chs { font-size: 10px; color: var(--tu); }
.cb { padding: .7rem .9rem; }

/* ─── ROLE GRID ─── */
.role-grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: .55rem; }
.role-card { background: var(--bc); border: 1.5px solid var(--gb); border-radius: 10px; padding: .65rem .8rem; cursor: pointer; transition: all .15s; }
.role-card:hover { border-color: rgba(138, 191, 68, .4); background: var(--gl); }
.role-card.sel { border-color: var(--ga); background: var(--gl); }
.role-color { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; display: inline-block; margin-right: 5px; }
.role-name { font-size: 12.5px; font-weight: 600; color: var(--td); display: flex; align-items: center; margin-bottom: 2px; }
.role-desc { font-size: 10px; color: var(--tu); line-height: 1.4; margin-bottom: .35rem; }
.role-foot { display: flex; align-items: center; justify-content: space-between; margin-top: .35rem; }
.role-tags { display: flex; gap: 3px; flex-wrap: wrap; }
.rt { font-size: 8px; font-weight: 600; padding: 1px 5px; border-radius: 20px; background: var(--gl); color: var(--ga); border: 1px solid var(--sbd); }
.role-cnt { font-size: 10px; color: var(--tu); }
.sys-badge { font-size: 8px; font-weight: 600; padding: 1px 5px; border-radius: 20px; background: var(--ib); color: var(--it); margin-left: 5px; }
.off-badge { font-size: 8px; font-weight: 600; padding: 1px 5px; border-radius: 20px; background: var(--eb); color: var(--et); margin-left: 5px; }

/* Role detail card */
.g4-mod { display: grid; grid-template-columns: repeat(4, 1fr); gap: .4rem; }
.mod-detail-card { padding: .45rem .6rem; background: var(--bs); border-radius: 8px; border: 1px solid var(--gb); }
.mod-detail-name { font-size: 10.5px; font-weight: 600; color: var(--td); margin-bottom: 1px; }
.role-users-label { font-size: 11px; font-weight: 600; color: var(--tm); margin-bottom: .3rem; }
.role-user-chip { display: flex; align-items: center; gap: .35rem; padding: .3rem .6rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 20px; cursor: pointer; transition: background .12s; }
.role-user-chip:hover { background: var(--gl); border-color: var(--ga); }
.chip-av { width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 7.5px; font-weight: 700; color: #fff; flex-shrink: 0; }
.role-user-chip span { font-size: 11px; font-weight: 500; color: var(--td); }

/* ─── MATRIX ─── */
.matrix-info { background: var(--ib); border: 1px solid var(--ibd); border-radius: 9px; padding: .5rem .85rem; font-size: 11px; color: var(--it); display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.matrix-wrap { overflow-x: auto; }
.matrix-tbl { border-collapse: collapse; min-width: 900px; }
.matrix-tbl th { font-size: 9px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: .05em; padding: 6px 8px; border-bottom: 2px solid var(--gb); white-space: nowrap; text-align: center; }
.matrix-tbl th.sticky { text-align: left; position: sticky; left: 34px; background: var(--bc); z-index: 2; min-width: 160px; }
.matrix-tbl th.col-no, .matrix-tbl td.col-no { position: sticky; left: 0; width: 34px; min-width: 34px; max-width: 34px; text-align: center; background: var(--bc); font-size: 10px; font-weight: 600; color: var(--tu); font-variant-numeric: tabular-nums; }
.matrix-tbl th.col-no { z-index: 3; border-bottom: 2px solid var(--gb); }
.matrix-tbl td.col-no { z-index: 2; }
.matrix-tbl th.mod-head { background: linear-gradient(135deg, var(--gm), var(--gd)); color: rgba(255,255,255,.8); font-size: 8.5px; border-bottom: none; }
.matrix-tbl td { padding: 4px 6px; border-bottom: 1px solid rgba(0,0,0,.04); text-align: center; vertical-align: middle; }
.matrix-tbl td.sticky { text-align: left; position: sticky; left: 34px; background: var(--bc); z-index: 1; font-size: 11.5px; font-weight: 500; padding: 4px 8px; }
.matrix-tbl tr:hover td { background: var(--bi); }
.matrix-tbl tr:hover td.sticky { background: var(--bi); }
.perm-toggle { width: 24px; height: 24px; border-radius: 6px; border: 1.5px solid var(--gb); display: flex; align-items: center; justify-content: center; cursor: pointer; margin: 0 auto; transition: all .14s; font-size: 10px; font-weight: 700; user-select: none; }
.perm-toggle.on { background: var(--ga); border-color: var(--ga); color: #fff; }
.perm-toggle.on:hover { background: var(--et); border-color: var(--et); }
.perm-toggle.off { background: var(--bs); color: var(--th); }
.perm-toggle.off:hover { background: var(--gl); border-color: var(--ga); }
.perm-toggle.locked { opacity: .4; cursor: not-allowed; }

/* Edit nama modul (kolom sticky matriks) */
.mod-name-cell { display: flex; align-items: center; gap: 5px; cursor: pointer; font-size: 11.5px; font-weight: 500; }
.mod-name-cell:hover .mod-edit-ic { opacity: 1; }
.mod-edit-ic { width: 10px; height: 10px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; opacity: 0; transition: opacity .12s; flex-shrink: 0; }
.mod-edit { display: flex; align-items: center; gap: 3px; }
.mod-edit-inp { width: 140px; height: 26px; font-size: 11.5px; padding: 2px 6px; }
.mod-edit-btn { width: 22px; height: 22px; border-radius: 5px; border: 1px solid var(--gb); background: var(--bc); cursor: pointer; font-size: 11px; color: var(--tm); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mod-edit-btn:hover { border-color: var(--ga); color: var(--ga); }
.mod-edit-btn.ok:hover { background: var(--ga); border-color: var(--ga); color: #fff; }

/* ─── TOOLBAR ROW ─── */
.tb-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }

/* ─── TABLE ─── */
.tbl { width: 100%; border-collapse: collapse; }
.tbl th { font-size: 8.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: .06em; padding: 6px 8px; border-bottom: 2px solid var(--gb); text-align: left; white-space: nowrap; }
.tbl td { padding: 5px 8px; border-bottom: 1px solid rgba(0,0,0,.045); font-size: 11.5px; color: var(--td); vertical-align: middle; }
.tbl tr:last-child td { border-bottom: none; }
.tbl tr:hover td { background: var(--bi); }
.user-av { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
.mono-cell { font-family: monospace; font-size: 10.5px; color: var(--it); }

/* ─── PILLS / BADGES ─── */
.pill { display: inline-flex; align-items: center; font-size: 8.5px; font-weight: 600; padding: 1px 6px; border-radius: 20px; border: 1px solid; white-space: nowrap; }
.p-ok  { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.p-off { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.perm-badge { font-size: 8px; font-weight: 700; padding: 2px 5px; border-radius: 20px; }

/* ─── AUDIT LOG ─── */
.log-item { display: flex; align-items: flex-start; gap: .6rem; padding: .45rem 0; }
.log-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
.log-time { font-size: 9.5px; color: var(--tu); white-space: nowrap; font-variant-numeric: tabular-nums; min-width: 130px; }
.log-msg { font-size: 11px; color: var(--td); }
.log-who { font-size: 9.5px; color: var(--tu); }

/* ─── MODAL ─── */
.ov { position: fixed; inset: 0; background: rgba(0,0,0,.42); z-index: 400; display: flex; align-items: center; justify-content: center; }
.mbx { background: var(--bc); border-radius: 13px; box-shadow: 0 8px 32px rgba(0,0,0,.16); width: 560px; max-width: 92vw; max-height: 88vh; overflow: hidden; display: flex; flex-direction: column; animation: fadeUp .22s ease; }
.mbx.lg { width: 700px; }
.mh { padding: .6rem 1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.mht { font-size: 13px; font-weight: 600; color: var(--td); }
.mb { padding: .85rem 1rem; overflow-y: auto; flex: 1; }
.mb::-webkit-scrollbar { width: 3px; }
.mb::-webkit-scrollbar-thumb { background: var(--gb); }
.mcl { width: 26px; height: 26px; border-radius: 5px; border: 1px solid var(--gb); background: var(--bc); cursor: pointer; display: flex; align-items: center; justify-content: center; }
.mcl:hover { background: var(--eb); border-color: var(--ebd); }
.mcl svg { width: 11px; height: 11px; fill: none; stroke: var(--tm); stroke-width: 2; stroke-linecap: round; }

/* Modal form helpers */
.fl { font-size: 9px; font-weight: 600; color: var(--tm); letter-spacing: .04em; text-transform: uppercase; margin-bottom: 2px; display: block; }
.fg { display: flex; flex-direction: column; }
.g2 { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; }
.g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .4rem; }
.fi { width: 100%; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 7px; font-family: 'DM Sans', sans-serif; font-size: 12px; color: var(--td); outline: none; transition: border-color .15s; padding: 5px 7px; height: 31px; box-sizing: border-box; }
.fi:focus { border-color: var(--ga); background: #fff; box-shadow: 0 0 0 3px rgba(31,125,74,.08); }
.fi:disabled { background: var(--bi); color: var(--tm); cursor: not-allowed; }
.fs { width: 100%; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 7px; font-family: 'DM Sans', sans-serif; font-size: 12px; color: var(--td); outline: none; height: 31px; padding: 5px 7px; cursor: pointer; }
.fs:focus { border-color: var(--ga); background: #fff; }
.fta { width: 100%; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 7px; font-family: 'DM Sans', sans-serif; font-size: 12px; color: var(--td); outline: none; resize: vertical; min-height: 52px; padding: 6px 7px; line-height: 1.5; box-sizing: border-box; }
.fta:focus { border-color: var(--ga); background: #fff; }
.sec { font-size: 9px; font-weight: 600; color: var(--tm); letter-spacing: .06em; text-transform: uppercase; display: flex; align-items: center; gap: 4px; padding-bottom: .3rem; border-bottom: 1px solid var(--gb); margin-bottom: .4rem; }
.sec svg { width: 10px; height: 10px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.divider { height: 1px; background: var(--gb); margin: .4rem 0; }

/* Perm rows in modal */
.perm-row { display: flex; align-items: center; justify-content: space-between; padding: .35rem .55rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 7px; }
.perm-view-row { display: flex; align-items: center; justify-content: space-between; padding: .3rem .55rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 7px; }
.perm-mod { font-size: 11px; font-weight: 500; color: var(--td); }

/* User detail modal */
.user-profile-hdr { display: flex; align-items: center; gap: .75rem; padding: .65rem; background: linear-gradient(135deg, var(--gm), var(--gd)); border-radius: 10px; margin-bottom: .65rem; }
.user-av-lg { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-family: 'DM Serif Display', serif; font-size: 16px; color: #fff; flex-shrink: 0; }
.upd-info { flex: 1; min-width: 0; }
.upd-name { font-size: 14px; font-weight: 600; color: #fff; font-family: 'DM Serif Display', serif; }
.upd-role { font-size: 10px; color: rgba(255,255,255,.6); margin-top: 1px; }
.upd-meta { font-size: 9.5px; color: rgba(255,255,255,.4); margin-top: 1px; }

/* ─── BUTTONS ─── */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 3px; padding: 0 10px; height: 29px; border-radius: 7px; font-family: 'DM Sans', sans-serif; font-size: 11px; font-weight: 500; cursor: pointer; transition: all .14s; border: 1.5px solid transparent; flex-shrink: 0; white-space: nowrap; }
.btn svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.btn-sm  { height: 25px; padding: 0 7px; font-size: 10.5px; }
.btn-lg  { height: 36px; padding: 0 16px; font-size: 12.5px; font-weight: 600; }
.btn-full{ width: 100%; }
.btn-g   { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-g:hover:not(:disabled) { background: var(--gm); }
.btn-ga  { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-ga:hover:not(:disabled) { background: var(--gm); }
.btn-lm  { background: var(--lm); color: var(--gd); border-color: var(--lm); }
.btn-lm:hover:not(:disabled) { background: #7aad38; }
.btn-o   { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-o:hover:not(:disabled) { border-color: var(--ga); color: var(--gd); }
.btn-i   { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.btn-e   { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.btn-w   { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.btn:disabled { opacity: .4; cursor: not-allowed; }

/* ─── IMPORT RESULT MODAL ─── */
.imp-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; }
.imp-stat { text-align: center; padding: .55rem; border-radius: 9px; border: 1px solid; }
.imp-stat.ok   { background: var(--sb); border-color: var(--sbd); }
.imp-stat.warn { background: var(--wb); border-color: var(--wbd); }
.imp-stat.err  { background: var(--eb); border-color: var(--ebd); }
.imp-num { font-size: 22px; font-weight: 700; font-family: 'DM Serif Display', serif; line-height: 1; }
.imp-stat.ok   .imp-num { color: var(--st); }
.imp-stat.warn .imp-num { color: var(--wt); }
.imp-stat.err  .imp-num { color: var(--et); }
.imp-lbl { font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--tm); margin-top: 2px; }
.imp-warn { background: var(--wb); border: 1px solid var(--wbd); color: var(--wt); font-size: 10.5px; padding: .45rem .7rem; border-radius: 7px; margin-bottom: .5rem; }
.imp-list { display: flex; flex-direction: column; gap: 3px; }
.imp-row { display: flex; align-items: center; gap: .6rem; padding: .35rem .6rem; border-radius: 7px; border: 1px solid var(--gb); background: var(--bs); }
.imp-row.warn { border-color: var(--wbd); }
.imp-row.err  { border-color: var(--ebd); }
.imp-rowno { font-size: 9px; font-weight: 600; color: var(--tu); white-space: nowrap; min-width: 56px; }

/* Catatan default sistem (superadmin) */
.default-note { display: flex; align-items: flex-start; gap: .45rem; margin-top: .6rem; padding: .5rem .7rem; background: var(--ib); border: 1px solid var(--ibd); border-radius: 7px; font-size: 10.5px; color: var(--it); line-height: 1.5; }
.default-note svg { width: 13px; height: 13px; fill: none; stroke: var(--it); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }

/* ─── TOAST ─── */
.twrap { position: fixed; top: .65rem; right: .65rem; z-index: 999; display: flex; flex-direction: column; gap: 3px; pointer-events: none; }
.toast { display: flex; align-items: center; padding: 6px 10px; border-radius: 8px; font-size: 11px; font-weight: 500; border: 1px solid; animation: slideIn .3s ease; pointer-events: all; min-width: 190px; box-shadow: 0 3px 10px rgba(0,0,0,.08); }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }

@keyframes slideIn { from { opacity: 0; transform: translateX(12px); } to { opacity: 1; transform: translateX(0); } }
@keyframes fadeUp  { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
</style>
