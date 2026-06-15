# Accessibility Journal (Aria ♿)

## 2026-06-15 - [Heading Hierarchy Convention]
**Pattern:** Reusable layout components (e.g., `x-card`, `x-empty-state`) and dashboard views (e.g., `members/home`) were using `<h3>` tags for primary section titles, skipping `<h2>` and breaking the logical document outline under the page `<h1>`.
**Fix:** Standardize on `<h2>` for primary structural headings within sections to ensure a continuous hierarchy (`<h1>` -> `<h2>`). Use visual utility classes (`text-lg`, `text-2xl`) to maintain design intent without sacrificing semantic structure.
