/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./public/**/*.{php,html,js}",
    "./src/**/*.{php,html,js}"
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        dark: {
          base: '#121212',
          surface: '#1E1E1E',
          card: '#242424',
          border: '#333333',
          hover: '#2A2A2A',
          muted: '#9E9E9E'
        },
        imss: {
          green: '#0C6839',       // Verde institucional IMSS
          darkGreen: '#073B20',   // Verde obscuro institucional
          lightGreen: '#16a34a',  // Acento interactivo
          hoverGreen: '#09522D',  // Hover botón
          gold: '#C5A572',        // Dorado institucional de contraste
          accent: '#22c55e'
        }
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif']
      }
    },
  },
  plugins: [],
}
