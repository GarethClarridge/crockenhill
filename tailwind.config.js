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
        'cbc-pattern': "url('../svg/pattern.svg')",
      },
      colors: {
        'cbc-teal': {
          light:   '#249a97',
          DEFAULT: '#1d686a',
          dark:    '#145557',
          deeper:  '#0f4143',
          darkest: '#134e4a',
        },
        'cbc-crimson': '#6b0f1a',
        'cbc-rose-muted': '#c07c84',
      },
      keyframes: {
        progress: {
          '0%': { width: '0%' },
          '50%': { width: '70%' },
          '100%': { width: '100%' },
        }
      },
      animation: {
        progress: 'progress 2s ease-in-out infinite',
      }
    }
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
  ],
}
