import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// In Docker the dev server sits behind Caddy (host port 80), which also proxies /api to Laravel.
export default defineConfig({
  plugins: [react()],
  server: {
    allowedHosts: true,
    hmr: { clientPort: Number(process.env.VITE_HMR_PORT ?? 80) },
  },
  build: {
    chunkSizeWarningLimit: 800,
  },
})
