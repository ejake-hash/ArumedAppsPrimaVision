/**
 * Form Template Store — Form Registry (Fase 2 frontend).
 *
 * Tanggung jawab:
 *   - CRUD master form-template (list + show + create + update + activate/deactivate)
 *   - Upload .docx → parse → poll cache result
 *   - Cache metadata: fieldRegistry + stationSections (1x load per session)
 *   - Runtime: list forms by (station, section, visit), render dry-run, finalize
 *
 * Tidak menyimpan draft wizard di store — itu di-local state komponen wizard
 * supaya navigate-away tidak kebawa ke template lain.
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { formTemplateApi } from '@/services/api'

export const useFormTemplateStore = defineStore('formTemplate', () => {
  // ─── State ─────────────────────────────────────────────────────────────
  const items = ref([])         // list template untuk halaman index
  const meta  = ref(null)       // detail template yang sedang dibuka (FormTemplateWizard)
  const loading = ref(false)
  const saving  = ref(false)
  const error   = ref(null)

  // Cache meta (load sekali per session)
  const fieldRegistry   = ref(null)    // { columns, aggregates }
  const stationSections = ref(null)    // { map, stations }
  const documentTypes   = ref(null)    // [{ id, code, name, category, ... }]

  // Parser session aktif (transient — direset saat keluar wizard)
  const parseDraft = ref(null)         // hasil parse-result terakhir
  const parsing = ref(false)

  // ─── Actions: list & meta ──────────────────────────────────────────────
  async function fetchList(params = {}) {
    loading.value = true
    error.value = null
    try {
      const { data } = await formTemplateApi.list(params)
      items.value = data.data ?? []
    } catch (e) {
      error.value = e.response?.data?.message ?? e.message
      throw e
    } finally {
      loading.value = false
    }
  }

  async function fetchOne(id) {
    loading.value = true
    error.value = null
    try {
      const { data } = await formTemplateApi.show(id)
      meta.value = data.data
      return meta.value
    } catch (e) {
      error.value = e.response?.data?.message ?? e.message
      throw e
    } finally {
      loading.value = false
    }
  }

  async function ensureFieldRegistry() {
    if (fieldRegistry.value) return fieldRegistry.value
    const { data } = await formTemplateApi.fieldRegistry()
    fieldRegistry.value = data.data
    return fieldRegistry.value
  }

  async function ensureStationSections() {
    if (stationSections.value) return stationSections.value
    const { data } = await formTemplateApi.stationSections()
    stationSections.value = data.data
    return stationSections.value
  }

  async function ensureDocumentTypes() {
    if (documentTypes.value) return documentTypes.value
    const { data } = await formTemplateApi.documentTypes()
    documentTypes.value = data.data ?? []
    return documentTypes.value
  }

  // ─── Actions: CRUD ─────────────────────────────────────────────────────
  async function create(payload) {
    saving.value = true
    error.value = null
    try {
      const { data } = await formTemplateApi.create(payload)
      return data.data
    } catch (e) {
      error.value = e.response?.data?.message ?? e.message
      throw e
    } finally {
      saving.value = false
    }
  }

  async function update(id, payload) {
    saving.value = true
    error.value = null
    try {
      const { data } = await formTemplateApi.update(id, payload)
      meta.value = data.data
      return meta.value
    } catch (e) {
      error.value = e.response?.data?.message ?? e.message
      throw e
    } finally {
      saving.value = false
    }
  }

  async function activate(id) {
    const { data } = await formTemplateApi.activate(id)
    if (meta.value && meta.value.id === id) meta.value = data.data
    return data.data
  }

  async function deactivate(id) {
    const { data } = await formTemplateApi.deactivate(id)
    if (meta.value && meta.value.id === id) meta.value = data.data
    return data.data
  }

  // ─── Actions: parser ───────────────────────────────────────────────────
  async function uploadAndParse(file) {
    parsing.value = true
    parseDraft.value = null
    error.value = null
    try {
      const { data } = await formTemplateApi.upload(file)
      parseDraft.value = data.data
      return parseDraft.value
    } catch (e) {
      error.value = e.response?.data?.message ?? e.message
      throw e
    } finally {
      parsing.value = false
    }
  }

  async function pollParseResult(parseId) {
    const { data } = await formTemplateApi.parseResult(parseId)
    parseDraft.value = data.data
    return parseDraft.value
  }

  function clearParseDraft() {
    parseDraft.value = null
  }

  // ─── Getters ───────────────────────────────────────────────────────────
  const activeCount = computed(() => items.value.filter(t => t.is_active).length)
  const draftCount  = computed(() => items.value.filter(t => !t.is_active).length)

  return {
    // state
    items, meta, loading, saving, error,
    fieldRegistry, stationSections, documentTypes,
    parseDraft, parsing,
    // getters
    activeCount, draftCount,
    // actions
    fetchList, fetchOne,
    ensureFieldRegistry, ensureStationSections, ensureDocumentTypes,
    create, update, activate, deactivate,
    uploadAndParse, pollParseResult, clearParseDraft,
  }
})
