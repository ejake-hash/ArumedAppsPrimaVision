import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'

import './assets/styles/tokens.css'
import './assets/styles/base.css'

import { applyAppearance } from './composables/useAppearance'

// Terapkan preferensi tampilan (ukuran font & kepadatan) sebelum app mount,
// agar tidak ada kedipan dari nilai default ke nilai tersimpan.
applyAppearance()

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
