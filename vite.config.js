import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'

// Served under portfolio.theo-birost.fr/clicker in production (Dokploy
// strips the /clicker prefix before it reaches this container), but the
// dev server itself still runs at the root.
export default defineConfig(({ command }) => ({
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
}))
