# Palette UX & Accessibility Journal

## 2026-02-19 - Accessibility Foundations
**Learning:** Foundational accessibility features like "Skip to content" and `role="alert"` for errors are often overlooked but provide immense value for assistive technology users. Implementing these in base layouts and core components ensures consistency across the entire application.
**Action:** Always check for the presence of a skip link in the main layout and ensure `<main>` tags have a consistent ID. Ensure all `@error` blocks in Blade/Livewire include `role="alert"`.
