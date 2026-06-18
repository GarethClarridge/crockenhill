## 2026-06-18 - Component Heading Promotion
**Pattern:** Core components like `x-card` and `x-empty-state` used `h3` for their primary headings, which often caused skipped heading levels when placed directly inside a page with an `h1`.
**Fix:** Promoted internal headings in shared components from `h3` to `h2` to align with the standard single-h1-per-page hierarchy, ensuring no levels are skipped in common layouts.
