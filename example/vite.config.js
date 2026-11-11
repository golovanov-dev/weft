import { defineConfig } from 'vite';
import { resolve } from 'node:path';

// Frontend source lives in /frontend, built assets go to /public/dist.
// In production the PHP layout reads the manifest from public/dist/.vite/manifest.json.
export default defineConfig({
  root: 'frontend',
  base: '/dist/',
  build: {
    outDir: resolve(__dirname, 'public/dist'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: resolve(__dirname, 'frontend/src/main.js'),
    },
  },
  server: {
    // Dev server; with DEV_MODE on the layout loads http://localhost:5173/dist/… (origin + base,
    // app.vite.dev_server in app/Configs/app.php).
    port: 5173,
    strictPort: true,
    origin: 'http://localhost:5173',
  },
});
