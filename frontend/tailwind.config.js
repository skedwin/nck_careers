/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        nck: {
          green: '#0B5C3B',
          greenDark: '#08462D',
          greenLight: '#E7F3EE',
          gold: '#C4A35A',
          slate: '#1F2937',
          mist: '#F3F6F5',
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
