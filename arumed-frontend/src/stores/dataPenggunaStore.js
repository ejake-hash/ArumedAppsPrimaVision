import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { userApi, roleApi, permissionApi } from '@/services/api'

export const useDataPenggunaStore = defineStore('dataPengguna', () => {
  // ─── State ──────────────────────────────────────────────────────────────
  const users               = ref([])
  const roles               = ref([])
  const permissionGroups    = ref([])     // [{ module, label, permissions: [{id, key, action, label}] }]

  const usersLoading        = ref(false)
  const rolesLoading        = ref(false)
  const permissionsLoading  = ref(false)
  const error               = ref(null)

  // Filter Users
  const userFilter = ref({ search: '', role_id: '', is_active: '' })

  // ─── Computed ───────────────────────────────────────────────────────────
  const roleById = computed(() => {
    const m = {}
    for (const r of roles.value) m[r.id] = r
    return m
  })

  const permissionFlat = computed(() => {
    const flat = []
    for (const g of permissionGroups.value) {
      for (const p of g.permissions) {
        flat.push({ ...p, module: g.module, moduleLabel: g.label })
      }
    }
    return flat
  })

  // ─── Permissions ────────────────────────────────────────────────────────
  async function fetchPermissions() {
    permissionsLoading.value = true
    error.value = null
    try {
      const { data } = await permissionApi.list()
      permissionGroups.value = data.data ?? []
    } catch (e) {
      error.value = e.response?.data?.message ?? 'Gagal memuat permissions'
    } finally {
      permissionsLoading.value = false
    }
  }

  // ─── Roles ──────────────────────────────────────────────────────────────
  async function fetchRoles() {
    rolesLoading.value = true
    error.value = null
    try {
      const { data } = await roleApi.list()
      roles.value = data.data ?? []
    } catch (e) {
      error.value = e.response?.data?.message ?? 'Gagal memuat roles'
    } finally {
      rolesLoading.value = false
    }
  }

  async function createRole(payload) {
    const { data } = await roleApi.create(payload)
    await fetchRoles()
    return data.data
  }

  async function updateRole(id, payload) {
    const { data } = await roleApi.update(id, payload)
    // Update in-place
    const idx = roles.value.findIndex((r) => r.id === id)
    if (idx !== -1 && data.data) roles.value[idx] = data.data
    return data.data
  }

  async function deleteRole(id) {
    await roleApi.remove(id)
    roles.value = roles.value.filter((r) => r.id !== id)
  }

  async function syncRolePermissions(id, permissionIds) {
    const { data } = await roleApi.syncPermissions(id, permissionIds)
    const idx = roles.value.findIndex((r) => r.id === id)
    if (idx !== -1 && data.data) roles.value[idx] = data.data
    return data.data
  }

  // ─── Users ──────────────────────────────────────────────────────────────
  async function fetchUsers() {
    usersLoading.value = true
    error.value = null
    try {
      const params = {}
      if (userFilter.value.search)    params.search    = userFilter.value.search
      if (userFilter.value.role_id)   params.role_id   = userFilter.value.role_id
      if (userFilter.value.is_active !== '') params.is_active = userFilter.value.is_active

      const { data } = await userApi.list(params)
      users.value = data.data ?? []
    } catch (e) {
      error.value = e.response?.data?.message ?? 'Gagal memuat users'
    } finally {
      usersLoading.value = false
    }
  }

  async function createUser(payload) {
    const { data } = await userApi.create(payload)
    await fetchUsers()
    return data.data
  }

  async function updateUser(id, payload) {
    const { data } = await userApi.update(id, payload)
    const idx = users.value.findIndex((u) => u.id === id)
    if (idx !== -1 && data.data) users.value[idx] = data.data
    return data.data
  }

  async function deleteUser(id) {
    await userApi.remove(id)
    users.value = users.value.filter((u) => u.id !== id)
  }

  async function toggleUserAktif(id) {
    const { data } = await userApi.toggleAktif(id)
    const idx = users.value.findIndex((u) => u.id === id)
    if (idx !== -1 && data.data) users.value[idx] = data.data
    return data.data
  }

  async function resetUserPassword(id, newPassword = null) {
    const payload = newPassword ? { new_password: newPassword } : {}
    const { data } = await userApi.resetPassword(id, payload)
    return data.data?.new_password ?? null
  }

  // ─── Initial load (load semua untuk halaman manajemen) ──────────────────
  async function loadAll() {
    await Promise.allSettled([fetchPermissions(), fetchRoles(), fetchUsers()])
  }

  return {
    // state
    users, roles, permissionGroups,
    usersLoading, rolesLoading, permissionsLoading,
    error,
    userFilter,

    // computed
    roleById, permissionFlat,

    // actions
    fetchPermissions,
    fetchRoles, createRole, updateRole, deleteRole, syncRolePermissions,
    fetchUsers, createUser, updateUser, deleteUser, toggleUserAktif, resetUserPassword,
    loadAll,
  }
})
