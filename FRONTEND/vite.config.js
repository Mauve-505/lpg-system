import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// The production build is served from the site root, next to /api and /database.
// `emptyOutDir: false` is deliberate: the root also contains server-side folders
// that must never be deleted by a frontend build.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    plugins: [react()],
    base: '/',
    // Public assets are already served from the site root (for example
    // /images). Copying FRONTEND/public into the parent outDir is unnecessary
    // and Vite warns about it.
    publicDir: false,
    server: {
      port: 5173,
      proxy: { '/api': env.VITE_API_PROXY || 'http://localhost' },
    },
    build: {
      outDir: '..',
      emptyOutDir: false,
      assetsDir: 'assets',
    },
  }
})
