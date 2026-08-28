import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

// Preview build for the Mall Management branch — same app, but the API proxy
// points at the LOCAL PHP backend (127.0.0.1:8767) instead of live krtaker.com.
export default defineConfig({
  base: './',
  plugins: [vue()],
  build: { outDir: 'dist-preview', assetsDir: 'assets', chunkSizeWarningLimit: 900 },
  server: {
    host: '0.0.0.0',
    port: 5174,
    allowedHosts: true,
    proxy: {
      '/api': { target: 'http://127.0.0.1:8767', changeOrigin: true, secure: false },
    },
  },
})
