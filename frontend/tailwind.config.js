/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        nck: {
          green: '#4B1E6D',
          greenDark: '#351456',
          greenLight: '#F3EAF8',
          gold: '#C9A227',
          slate: '#1F2937',
          mist: '#F7F2FA',
        },
      },
      fontFamily: {
        sans: ['"Source Sans 3"', 'Segoe UI', 'sans-serif'],
        display: ['"Cormorant Garamond"', 'Georgia', 'serif'],
      },
      boxShadow: {
        panel: '0 10px 30px rgba(8, 70, 45, 0.08)',
      },
    },
  },
  plugins: [],
};
