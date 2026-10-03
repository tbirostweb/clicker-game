import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'

// Served under portfolio.theo-birost.fr/clicker in production (Dokploy
// strips the /clicker prefix before it reaches this container), but the
// dev server itself still runs at the root.
export default defineConfig(({ command }) => ({
  base: command === 'build' ? '/clicker/' : '/',
  plugins: [
      tailwindcss(),
      vue()],
}))
