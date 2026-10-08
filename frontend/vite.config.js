import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Libraries in named chunks: they change far less often than the app, so browsers
// keep them cached across deploys. Pages are split by route (src/app/pageLoaders.js);
// the chart library only loads with the pages that draw charts.
// Higher priority is claimed first: small helpers the app shares with the chart
// library (clsx) must not be pulled into the charts chunk, or every page would load it.
const VENDOR_GROUPS = [
  { name: 'vendor-react', priority: 30, test: /[\\/]node_modules[\\/](react|react-dom|scheduler|react-router|react-router-dom|cookie|set-cookie-parser)[\\/]/ },
  { name: 'vendor-ui', priority: 30, test: /[\\/]node_modules[\\/](clsx|lucide-react)[\\/]/ },
  { name: 'vendor-data', priority: 20, test: /[\\/]node_modules[\\/]@tanstack[\\/]/ },
  { name: 'vendor-i18n', priority: 20, test: /[\\/]node_modules[\\/](i18next|react-i18next|html-parse-stringify|void-elements)[\\/]/ },
  { name: 'vendor-charts', priority: 10, test: /[\\/]node_modules[\\/](recharts|d3-[^\\/]+|victory-vendor|decimal\.js-light|es-toolkit|@reduxjs|immer|react-redux|redux|reselect|eventemitter3|internmap)[\\/]/ },
]

// In development /api is proxied to the PHP server, so the browser sees one
// origin — exactly like production, where Vercel rewrites /api to Render.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    rolldownOptions: {
      output: {
        codeSplitting: { groups: VENDOR_GROUPS },
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: false,
      },
    },
  },
})
