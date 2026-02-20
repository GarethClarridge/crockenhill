# Palette UX & Accessibility Journal

## 2026-02-19 - Accessibility Foundations
**Learning:** Foundational accessibility features like "Skip to content" and `role="alert"` for errors are often overlooked but provide immense value for assistive technology users. Implementing these in base layouts and core components ensures consistency across the entire application.
**Action:** Always check for the presence of a skip link in the main layout and ensure `<main>` tags have a consistent ID. Ensure all `@error` blocks in Blade/Livewire include `role="alert"`.

## 2026-03-03 - Interactive Toggles and Feedback
**Learning:** Interactive toggles (like mobile menus or expanding sections) and asynchronous feedback (like toast notifications) require specific ARIA attributes to be accessible. `aria-expanded`, `aria-controls`, and `aria-live` are essential for conveying state changes to screen reader users. Refactoring imperative JS toggles into declarative Alpine.js components makes implementing these attributes much easier and more maintainable in the TALL stack.
**Action:** Use `aria-expanded` and `aria-controls` for all UI toggles. Use `aria-live="polite"` and appropriate roles (`status`/`alert`) for dynamic notifications.
