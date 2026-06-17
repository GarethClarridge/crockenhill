## 2026-08-11 - Heading Hierarchy Standardisation
**Pattern:** Content regions (cards, empty states, footers) jumping from `<h1>` to `<h3>` causing accessibility skips.
**Fix:** Standardise internal structural headings in base components and layouts to use `<h2>`. Visual font size remains controlled by Tailwind utility classes, preserving design while fixing semantic hierarchy.
