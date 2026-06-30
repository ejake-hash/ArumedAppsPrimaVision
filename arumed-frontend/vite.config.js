import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    rollupOptions: {
      output: {
        // Pisahkan HANYA vendor inti (dipakai eager: framework + axios) ke satu
        // chunk stabil agar cache browser awet antar-deploy. Library berat
        // (chart.js, tiptap, pdf-lib, jszip, zxing, pusher) SENGAJA tidak digrup
        // di sini — semuanya hanya dijangkau lewat dynamic import, biarkan Rollup
        // menyimpannya di chunk lazy masing-masing.
        manualChunks(id) {
          if (/[\\/]node_modules[\\/](vue|vue-router|pinia|@vue|axios)[\\/]/.test(id)) {
            return 'vendor-core'
          }
        },
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
        secure: false,
      },
    },
  },
})
