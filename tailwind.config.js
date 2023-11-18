/** @type {import('tailwindcss').Config} */

const defaultTheme = require('tailwindcss/defaultTheme')

module.exports = {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
  ],
  theme: {
    extend: {
      fontFamily: {
        'sans'    : ['Lato', 'Open Sans', 'Helvetica', 'Arial', 'sans-serif'],
        'display' : ['Oswald', 'Lato', 'Open Sans', 'Helvetica', 'Arial', 'sans-serif'],
      }
    }
  },
  plugins: [
    require('@tailwindcss/typography'),
    require('@tailwindcss/forms'),
  ],
}

