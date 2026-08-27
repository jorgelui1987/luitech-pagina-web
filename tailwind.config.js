/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.html',
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
