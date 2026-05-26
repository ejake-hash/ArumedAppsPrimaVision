<script setup>
import { onMounted } from 'vue'
import { RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth   = useAuthStore()

onMounted(() => {
  // Dipanggil oleh api.js interceptor saat server kembalikan 401
  window.addEventListener('arumed:session-expired', () => {
    router.push({ name: 'login' })
  })

  // Refresh user (termasuk permissions) saat app load — kalau token masih valid.
  // Tanpa ini, user yang login sebelum sistem RBAC akan punya permissions[] kosong
  // sampai logout-login ulang.
  if (auth.isAuthenticated) {
    auth.fetchMe()
  }
})
</script>

<template>
  <RouterView />
</template>
