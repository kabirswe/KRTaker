import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

// KRTaker app-v3 — Vue frontend for the existing headless PHP API.
// base './' → relative asset paths so the SPA can live at any path on cPanel
// (dashboard-v3.html or /app-v3/). Hash routing → no .htaccess rewrites needed.
export default defineConfig({
  base: './',
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/apple-touch-icon.png'],
      manifest: {
        name: 'KRTaker — AI Caretaker',
        short_name: 'KRTaker',
        description: 'Key Responsibility Taker · Managed Buildings',
        theme_color: '#2F80ED',
        background_color: '#FFFFFF',
        display: 'standalone',
        start_url: '.',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
          { src: 'icons/apple-touch-icon.png', sizes: '180x180', type: 'image/png' },
        ],
      },
      workbox: {
        // SPA shell precache (hashed assets); API is never intercepted (server auth).
        navigateFallback: null,
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        runtimeCaching: [
          {
            // Network-first for the app shell so updates land immediately; fallback to cache offline.
            urlPattern: ({ url }) => url.pathname.endsWith('.html'),
            handler: 'NetworkFirst',
            options: { cacheName: 'krtaker-shell', networkTimeoutSeconds: 3 },
          },
          {
            // Network-first for bootstrap data; offline → last-loaded snapshot (read-only banner).
            urlPattern: ({ url }) => url.pathname.includes('/api/app-bootstrap'),
            handler: 'NetworkFirst',
            options: { cacheName: 'krtaker-data', networkTimeoutSeconds: 4 },
          },
        ],
      },
    }),
  ],
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    chunkSizeWarningLimit: 900,
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    // Dev proxy → live API keeps the SPA calling relative ../api/ like production.
    proxy: {
      '/api': { target: 'https://krtaker.com', changeOrigin: true, secure: false },
    },
  },
})
