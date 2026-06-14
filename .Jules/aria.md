# Aria Journal

## 2026-06-14 - Focus visibility for hover-only elements
**Pattern:** Interactive elements using hover-only visibility (e.g., `group-hover:opacity-100`) are invisible to keyboard-only users when focused.
**Fix:** Apply corresponding focus-visible classes (e.g., `group-focus-visible:opacity-100`) to ensure these elements become visible during keyboard navigation.

## 2026-06-14 - Descriptive alt text for image previews
**Pattern:** Generic alt text like "Preview of selected image" provides insufficient context for screen reader users.
**Fix:** Use more descriptive alt text like "Preview of the image selected for upload" to provide better context.
