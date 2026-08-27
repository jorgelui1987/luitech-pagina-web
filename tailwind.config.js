/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './index.html',
    './admin.html',
    './pos.html',
    './inventario.html',
    './finanzas.html',
    './tv.html',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          cyan: '#22d3ee',
          blue: '#2563eb',
        },
      },
    },
  },
  plugins: [],
};
