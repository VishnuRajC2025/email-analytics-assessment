import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    port: 3000,
    proxy: {
      '/events': 'http://localhost:8080',
      '/campaigns': 'http://localhost:8080',
    }
  }
})
