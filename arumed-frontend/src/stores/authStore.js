import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/services/api'

const TOKEN_KEY = 'auth_token'
const USER_KEY  = 'auth_user'

export const useAuthStore = defineStore('auth', () => {
  // ─── State ──────────────────────────────────────────────────────────────
  const token   = ref(localStorage.getItem(TOKEN_KEY) ?? null)
  const user    = ref(
    localStorage.getItem(USER_KEY)
      ? JSON.parse(localStorage.getItem(USER_KEY))
      : null,
  )
  const loading = ref(false)
  const error   = ref(null)

  // ─── Getters ─────────────────────────────────────────────────────────────
  const isAuthenticated = computed(() => !!token.value)
  const roleName        = computed(() => user.value?.role?.name ?? null)
  const isSuperadmin    = computed(() => !!user.value?.is_superadmin || roleName.value === 'superadmin')
  const isDoctor        = computed(() => ['dokter', 'dokter_anestesi', 'dokter_umum'].includes(roleName.value))
  const permissions     = computed(() => user.value?.permissions ?? [])
  const employeeName    = computed(() => user.value?.employee?.name ?? user.value?.name ?? '')
  const initials        = computed(() => {
    const name = employeeName.value
    if (!name) return '??'
    return name
      .split(' ')
      .slice(0, 2)
      .map((w) => w[0]?.toUpperCase() ?? '')
      .join('')
  })

  // ─── Permission helper ───────────────────────────────────────────────────
  /**
   * Cek apakah user punya permission key (mis. "admisi.read").
   * - Superadmin: selalu true (sentinel "*" di permissions).
   * - User tanpa role / permissions kosong: false.
   * - Bisa terima single key string, atau array (OR logic).
   */
  function can(key) {
    const perms = permissions.value
    if (perms.includes('*')) return true
    if (Array.isArray(key)) return key.some((k) => perms.includes(k))
    return perms.includes(key)
  }

  // ─── Actions ─────────────────────────────────────────────────────────────

  async function login(username, password) {
    loading.value = true
    error.value   = null

    try {
      const { data } = await authApi.login({ username, password })

      token.value = data.data.token
      user.value  = data.data.user

      localStorage.setItem(TOKEN_KEY, data.data.token)
      localStorage.setItem(USER_KEY, JSON.stringify(data.data.user))

      return data.data
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Login gagal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await authApi.logout()
    } catch {
      // token mungkin sudah expired — tetap lanjut clear
    } finally {
      _clearSession()
    }
  }

  async function fetchMe() {
    if (!token.value) return

    try {
      const { data } = await authApi.me()
      user.value = data.data
      localStorage.setItem(USER_KEY, JSON.stringify(data.data))
    } catch {
      // token invalid → clear
      _clearSession()
    }
  }

  async function refreshToken() {
    try {
      const { data } = await authApi.refresh()
      token.value = data.data.token
      localStorage.setItem(TOKEN_KEY, data.data.token)
    } catch {
      _clearSession()
    }
  }

  async function changePassword(currentPassword, newPassword) {
    loading.value = true
    error.value   = null

    try {
      await authApi.changePassword({
        current_password:      currentPassword,
        new_password:          newPassword,
        new_password_confirmation: newPassword,
      })
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal mengubah password'
      throw err
    } finally {
      loading.value = false
    }
  }

  function _clearSession() {
    token.value = null
    user.value  = null
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
  }

  // Listen for session-expired event from api.js interceptor
  window.addEventListener('arumed:session-expired', () => _clearSession())

  return {
    token,
    user,
    loading,
    error,
    isAuthenticated,
    roleName,
    isSuperadmin,
    isDoctor,
    permissions,
    employeeName,
    initials,
    can,
    login,
    logout,
    fetchMe,
    refreshToken,
    changePassword,
  }
})
