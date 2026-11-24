// Local testing only: the normal dev config plus a proxy so the TV pages can reach the backend
// (php artisan serve on port 8000) through the same origin, also when opened from a TV on the LAN.
// Start with:  npx vite --config vite.tv.config.mjs --host 0.0.0.0
import { mergeConfig } from 'vite'
import base from './vite.config.mjs'

export default (env) =>
  mergeConfig(base(env), {
    server: {
      proxy: {
        '/api': { target: process.env.TV_API_PROXY || 'http://host.docker.internal:8000', changeOrigin: true },
      },
    },
  })
