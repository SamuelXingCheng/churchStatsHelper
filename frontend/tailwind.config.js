/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{vue,js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        // 定義您的品牌色
        navy: {
          base: '#112041',  // 您指定的 R17 G32 B65
          light: '#1c2e52', // 稍微亮一點，用於卡片背景
          dark: '#0b1426',  // 深色，用於對比或陰影
        },
        // 質感點綴色 (霧金/灰藍)
        accent: {
          gold: '#C5A572',  // 穩重的金色
          blue: '#5B7C99',  // 莫蘭迪藍
          success: '#4ADE80', // 稍微柔和的綠色
        }
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'], // 建議引入 Inter 字體會更有質感
      }
    },
  },
  plugins: [],
}