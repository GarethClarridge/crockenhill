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
        'display' : ['Oswald', 'Open Sans', 'Helvetica', 'Arial', 'sans-serif'],
      },
      backgroundImage: {
        'cbc-pattern': "url('/images/pattern-wide.png')",
      }
    }
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
  ],
}

