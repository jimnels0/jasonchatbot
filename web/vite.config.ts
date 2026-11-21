import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The API is PHP on the same host (/api). `npm run dev` proxies it to the temp server.
export default defineConfig({
  plugins: [react()],
  server: { proxy: { '/api': { target: 'https://bot.pennybiddeals.com', changeOrigin: true } } },
});
