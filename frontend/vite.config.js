import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import legacy from '@vitejs/plugin-legacy'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    // legacy 必須放在 plugins 陣列內
    legacy({
      targets: ['defaults', 'not IE 11'],
    }),
  ],
  base: './',  // 解決相對路徑問題，確保部屬到子目錄也能讀取
})