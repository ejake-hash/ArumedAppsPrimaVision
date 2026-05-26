/**
 * Inbox TTD Dokter
 * - List notifikasi pending signature
 * - Tanda tangan dengan PIN
 * - Tolak dokumen dengan alasan
 * - Badge count untuk header
 */

import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { notifApi } from '@/services/api'

export const useNotificationStore = defineStore('notification', () => {
  // ─── State ──────────────────────────────────────────────────────────────
  const notifications   = ref([])
  const loading         = ref(false)
  const signing         = ref(false)     // loading saat proses TTD
  const error           = ref(null)
  const lastFetched     = ref(null)

  // ─── Getters ─────────────────────────────────────────────────────────────
  const unreadCount = computed(
    () => notifications.value.filter((n) => !n.is_read).length,
  )

  const pendingSignatureCount = computed(
    () =>
      notifications.value.filter(
        (n) => n.type === 'SIGNATURE_REQUEST' && !n.is_read,
      ).length,
  )

  const groupedByDocument = computed(() => {
    const map = new Map()
    for (const notif of notifications.value) {
      const docId = notif.patient_document_id
      if (!map.has(docId)) {
        map.set(docId, { document: notif.patient_document, notifications: [] })
      }
      map.get(docId).notifications.push(notif)
    }
    return [...map.values()]
  })

  // ─── Actions ─────────────────────────────────────────────────────────────

  async function fetchNotifications() {
    loading.value = true
    error.value   = null

    try {
      const { data } = await notifApi.list()
      notifications.value = data.data ?? []
      lastFetched.value   = new Date()
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal memuat notifikasi'
    } finally {
      loading.value = false
    }
  }

  async function markRead(notifId) {
    try {
      await notifApi.baca(notifId)

      const notif = notifications.value.find((n) => n.id === notifId)
      if (notif) {
        notif.is_read = true
        notif.read_at = new Date().toISOString()
      }
    } catch {
      // silent — mark read tidak kritis
    }
  }

  async function markAllRead() {
    const unread = notifications.value.filter((n) => !n.is_read)
    await Promise.allSettled(unread.map((n) => markRead(n.id)))
  }

  /**
   * Tanda tangan dokumen dengan PIN.
   * @param  {string} documentId
   * @param  {string} pin
   * @returns {Promise<object>} dokumen yang sudah di-TTD
   */
  async function signDocument(documentId, pin) {
    signing.value = true
    error.value   = null

    try {
      const { data } = await notifApi.tandaTangan(documentId, pin)

      // Hapus notifikasi terkait dari inbox
      notifications.value = notifications.value.filter(
        (n) => n.patient_document_id !== documentId,
      )

      return data.data
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Tanda tangan gagal. Periksa PIN.'
      throw err
    } finally {
      signing.value = false
    }
  }

  /**
   * Tolak dokumen dengan alasan.
   * @param  {string} documentId
   * @param  {string} alasan
   */
  async function rejectDocument(documentId, alasan) {
    signing.value = true
    error.value   = null

    try {
      const { data } = await notifApi.tolak(documentId, alasan)

      notifications.value = notifications.value.filter(
        (n) => n.patient_document_id !== documentId,
      )

      return data.data
    } catch (err) {
      error.value = err.response?.data?.message ?? 'Gagal menolak dokumen'
      throw err
    } finally {
      signing.value = false
    }
  }

  // Auto-refresh setiap 60 detik
  let _pollInterval = null

  function startPolling(intervalMs = 60_000) {
    stopPolling()
    fetchNotifications()
    _pollInterval = setInterval(fetchNotifications, intervalMs)
  }

  function stopPolling() {
    if (_pollInterval) {
      clearInterval(_pollInterval)
      _pollInterval = null
    }
  }

  return {
    notifications,
    loading,
    signing,
    error,
    lastFetched,
    unreadCount,
    pendingSignatureCount,
    groupedByDocument,
    fetchNotifications,
    markRead,
    markAllRead,
    signDocument,
    rejectDocument,
    startPolling,
    stopPolling,
  }
})
