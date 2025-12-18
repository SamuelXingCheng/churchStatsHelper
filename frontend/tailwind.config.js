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
          // 修改前: '#112041' -> 修改後: 更深的午夜藍
          base: '#0a1329',  
          
          // 修改前: '#1c2e52' -> 修改後: 用於卡片背景，深沉但與底色有區隔
          light: '#14203b', 
          
          // 修改前: '#0b1426' -> 修改後: 用於陰影或邊框
          dark: '#040812',  
        },
        // 質感點綴色 (維持不變)
        accent: {
          gold: '#C5A572',  
          blue: '#5B7C99',  
          success: '#4ADE80', 
        }
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'], 
      }
    },
  },
  plugins: [],
}