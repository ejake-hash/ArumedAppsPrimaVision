// Backward-compat shim — semua views yang import dari @/stores/auth
// otomatis mendapatkan store production tanpa perlu ubah import satu per satu.
export { useAuthStore } from './authStore.js'
