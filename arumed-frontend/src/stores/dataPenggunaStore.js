import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { userApi, roleApi, permissionApi, auditLogApi } from '@/services/api'

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

  // Audit Log (read-only system_logs)
  const auditLogs    = ref([])
  const auditMeta    = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
  const auditActions = ref([])      // facet daftar action utk dropdown filter
  const auditLoading = ref(false)
  const auditFilter  = ref({ search: '', action: '', user_id: '', date_from: '', date_to: '', page: 1, per_page: 25 })

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

  async function updateModuleLabel(module, label) {
    const { data } = await permissionApi.updateModuleLabel(module, label)
    permissionGroups.value = data.data ?? permissionGroups.value
    return data.data
  }

  async function resetModuleLabel(module) {
    const { data } = await permissionApi.resetModuleLabel(module)
    permissionGroups.value = data.data ?? permissionGroups.value
    return data.data
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

  async function resetUserPin(id) {
    const { data } = await userApi.resetPin(id)
    // PIN baru → tandai user has_pin true di list
    const idx = users.value.findIndex((u) => u.id === id)
    if (idx !== -1) users.value[idx] = { ...users.value[idx], has_pin: true }
    return data.data?.new_pin ?? null
  }

  // ─── CSV: Template / Export / Import ──────────────────────────────────────
  function triggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }

  // format: 'csv' (default) | 'xlsx' — backend menyalurkan ke csvOrXlsx().
  async function downloadUserTemplate(format = 'csv') {
    const isXlsx = format === 'xlsx'
    const res = await userApi.csvTemplate(isXlsx ? 'xlsx' : undefined)
    triggerDownload(res.data, `template-pengguna.${isXlsx ? 'xlsx' : 'csv'}`)
  }

  async function exportUsersCsv(format = 'csv') {
    const isXlsx = format === 'xlsx'
    const res = await userApi.exportCsv(isXlsx ? 'xlsx' : undefined)
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `data-pengguna-${today}.${isXlsx ? 'xlsx' : 'csv'}`)
  }

  async function importUsersCsv(file) {
    const { data } = await userApi.importCsv(file)
    await fetchUsers()
    return data?.data ?? data    // { created, skipped, errors }
  }

  // ─── CSV / Excel: Daftar Role ──────────────────────────────────────────────
  // format: 'csv' (default) | 'xlsx' — backend menyalurkan ke csvOrXlsx().
  async function downloadRoleTemplate(format = 'csv') {
    const isXlsx = format === 'xlsx'
    const res = await roleApi.csvTemplate(isXlsx ? 'xlsx' : undefined)
    triggerDownload(res.data, `template-role.${isXlsx ? 'xlsx' : 'csv'}`)
  }

  async function exportRolesCsv(format = 'csv') {
    const isXlsx = format === 'xlsx'
    const res = await roleApi.exportCsv(isXlsx ? 'xlsx' : undefined)
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '')
    triggerDownload(res.data, `data-role-${today}.${isXlsx ? 'xlsx' : 'csv'}`)
  }

  async function importRolesCsv(file) {
    const { data } = await roleApi.importCsv(file)
    await fetchRoles()
    return data?.data ?? data    // { created, updated, skipped, errors }
  }

  // ─── Audit Log ────────────────────────────────────────────────────────────
  async function fetchAuditLogs() {
    auditLoading.value = true
    error.value = null
    try {
      const f = auditFilter.value
      const params = { page: f.page, per_page: f.per_page }
      if (f.search)    params.search    = f.search
      if (f.action)    params.action    = f.action
      if (f.user_id)   params.user_id   = f.user_id
      if (f.date_from) params.date_from = f.date_from
      if (f.date_to)   params.date_to   = f.date_to

      const { data } = await auditLogApi.list(params)
      const payload = data.data ?? {}
      const logs    = payload.logs ?? {}
      auditLogs.value    = logs.data ?? []
      auditMeta.value    = {
        current_page: logs.current_page ?? 1,
        last_page:    logs.last_page ?? 1,
        total:        logs.total ?? 0,
        per_page:     logs.per_page ?? f.per_page,
      }
      // Facet action hanya perlu di-set sekali (stabil); jangan timpa dgn kosong.
      const facets = payload.facets?.actions ?? []
      if (facets.length) auditActions.value = facets
    } catch (e) {
      error.value = e.response?.data?.message ?? 'Gagal memuat audit log'
    } finally {
      auditLoading.value = false
    }
  }

  function setAuditPage(page) {
    auditFilter.value.page = page
    return fetchAuditLogs()
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
    auditLogs, auditMeta, auditActions, auditLoading, auditFilter,

    // computed
    roleById, permissionFlat,

    // actions
    fetchPermissions, updateModuleLabel, resetModuleLabel,
    fetchRoles, createRole, updateRole, deleteRole, syncRolePermissions,
    fetchUsers, createUser, updateUser, deleteUser, toggleUserAktif, resetUserPassword, resetUserPin,
    downloadUserTemplate, exportUsersCsv, importUsersCsv,
    downloadRoleTemplate, exportRolesCsv, importRolesCsv,
    fetchAuditLogs, setAuditPage,
    loadAll,
  }
})
