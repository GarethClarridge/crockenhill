# Palette UX & Accessibility Journal

## 2026-02-19 - Accessibility Foundations
**Learning:** Foundational accessibility features like "Skip to content" and `role="alert"` for errors are often overlooked but provide immense value for assistive technology users. Implementing these in base layouts and core components ensures consistency across the entire application.
**Action:** Always check for the presence of a skip link in the main layout and ensure `<main>` tags have a consistent ID. Ensure all `@error` blocks in Blade/Livewire include `role="alert"`.

## 2026-03-03 - Interactive Toggles and Feedback
**Learning:** Interactive toggles (like mobile menus or expanding sections) and asynchronous feedback (like toast notifications) require specific ARIA attributes to be accessible. `aria-expanded`, `aria-controls`, and `aria-live` are essential for conveying state changes to screen reader users. Refactoring imperative JS toggles into declarative Alpine.js components makes implementing these attributes much easier and more maintainable in the TALL stack.
**Action:** Use `aria-expanded` and `aria-controls` for all UI toggles. Use `aria-live="polite"` and appropriate roles (`status`/`alert`) for dynamic notifications.

## 2026-03-05 - Semantic Switches and Connectivity Feedback
**Learning:** For interactive switches, wrapping everything in a `<label>` is not enough for modern accessibility standards. Using a `<button role="switch">` with an explicit `aria-labelledby` pointing to a descriptive label provides much clearer intent to assistive technologies. Additionally, global connectivity indicators like `wire:offline` are critical for TALL stack applications where many interactions depend on a stable server connection; providing this feedback at the layout level ensures users are never left wondering why a button isn't responding.
**Action:** Always prefer `<button role="switch">` for toggles and ensure they are correctly labeled. Include a global `wire:offline` indicator in the main layout for all Livewire-heavy applications.
## 2026-02-13 - Integrated Loading States for Form Components
**Learning:** Adding automated loading indicators to base form components (input, select) that target their `wire:model` provides immediate feedback for debounced search and live validation without requiring per-instance configuration. Fallback `aria-label` from placeholders ensures accessibility when formal labels are missing.
**Action:** Use `wire:loading` with `wire:target` in base components and ensure conflicting elements (like clear buttons) use `wire:loading.remove`.

## 2026-02-23 - Scoped Loading States and Loop Accessibility
**Learning:** In complex Livewire forms with many interactive elements (like dynamic lists of points), global loading states cause confusing UI flicker. Enhancing base button components to automatically target their own 'wire:click' or 'wire:submit' actions provides precise, scoped feedback. Additionally, icon-only buttons within loops require contextual 'aria-label' attributes (e.g., "Remove alias: [name]") to provide clear intent to screen reader users beyond just the action type.
**Action:** Automatically derive 'wire:target' from 'wire:click' or 'wire:submit' in 'form-button' components. Ensure all loop-based buttons include unique identifying information in their ARIA labels.

## 2026-03-06 - Integrated Character Counters and Bulk Action Polish
**Learning:** For fields with strict character limits (like SEO descriptions), relying on server-side validation or PHP-calculated counters creates a laggy experience. Integrating an Alpine.js-powered live counter into base `x-input` and `x-textarea` components provides immediate, accessible feedback. Additionally, bulk action buttons (like 'Delete Selected') feel more polished when using `x-transition` to avoid layout jumps, and master checkboxes in tables MUST have an explicit `aria-label` to provide context for screen readers.
**Action:** Use the `maxlength` prop on `x-input` and `x-textarea` for real-time length feedback. Always use `x-transition` for dynamic bulk action UI and ensure master checkboxes are correctly labeled.

## 2026-02-28 - Semantic Utility Separation and Theme Alignment
**Learning:** Adding utility actions (like 'Copy Link') to navigation containers (like breadcrumbs) can confuse screen readers if not semantically separated. Using a wrapper `div` with `flex-wrap` and `justify-between` allows utilities to sit alongside navigation while remaining outside the `<nav>` element. Furthermore, aligning utility buttons with the project's specific color palette (`cbc-teal`) and using standard icon components ensures a cohesive look and feel that respects the existing design system.
**Action:** Always place non-navigation interactive elements outside the `<nav>` tag. Use project-specific color tokens (`cbc-teal`) and established icon patterns for all micro-UX utilities.

## 2026-03-25 - Context-aware Empty States
**Learning:** Empty states in admin interfaces are often dead ends. By providing a clear call-to-action (CTA) when no items exist, we guide users on their next step and improve onboarding. Distinguishing between "no results for filters" and "no items in database" prevents confusion.
**Action:** Enhance the `x-admin.empty-state` component to support an action slot and provide different default icons/descriptions based on whether filters are active.

## 2026-03-27 - Centralized Loading Feedback for Data-Intensive Listings
**Learning:** In data-heavy admin listings (TALL stack), users often feel a 'stutter' during sorting, filtering, or pagination on average connections. Adding a global loading state (`opacity-50`) to the base `list-shell` component via `wire:loading.class.delay.200ms` provides consistent, non-flickering feedback across all listing modules. This requires the base `x-card` component to correctly merge the `$attributes` bag into its root element to support Livewire directives.
**Action:** Always ensure base layout/container components like `x-card` support attribute merging. Use `.delay.200ms` for loading states to avoid unnecessary UI flashing on fast connections.

## 2026-03-28 - Accessible Icon-Only Utility Buttons
**Learning:** When refactoring utility buttons (like "Copy Link") to support icon-only modes for dense UIs, removing the text label entirely from the DOM also removes important screen reader feedback (like `aria-live` announcements). Using a `sr-only` class instead of conditional Blade `@if` logic ensures that assistive technology users still receive success confirmations (e.g., "Copied!"). Furthermore, using `Js::from()` for injecting dynamic data into Alpine.js handlers prevents XSS and JS syntax errors.
**Action:** Use `sr-only` to hide labels while preserving accessibility feedback. Always use `{{ \Illuminate\Support\Js::from($data) }}` when passing PHP variables into JavaScript expressions in Blade templates.
