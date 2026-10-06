import { defineConfig, loadEnv } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { assertProductionApiUrl } from './vite-api-url.js'

// Served under portfolio.theo-birost.fr/clicker in production (Dokploy
// strips the /clicker prefix before it reaches this container), but the
// dev server itself still runs at the root.
export default defineConfig(({ command, mode }) => {
  if (command === 'build' && mode === 'production') {
    // Fails the build when VITE_API_URL is missing or not HTTPS (Docker
    // passes it as a build argument / environment variable).
    assertProductionApiUrl(loadEnv(mode, process.cwd(), 'VITE_').VITE_API_URL)
  }

  return {
    base: command === 'build' ? '/clicker/' : '/',
    define: {
      // vue-i18n: compile messages with the AST/JIT compiler instead of
      // `new Function`, so the strict Content-Security-Policy (no
      // 'unsafe-eval', see docker/security-headers.conf) does not break i18n.
      __INTLIFY_JIT_COMPILATION__: true,
      __INTLIFY_PROD_DEVTOOLS__: false,
    },
    plugins: [
      tailwindcss(),
      vue()],
  }
})
