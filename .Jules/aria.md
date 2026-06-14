# Aria Journal

## 2026-06-14 - Improve focus visibility for hover-only elements
**Pattern:** Some interactive elements (like sort chevrons or remove buttons) were only visible on hover, making them invisible to keyboard users when focused.
**Fix:** Add `group-focus-visible:opacity-100` or `focus-visible:opacity-100` to ensure they appear when focused via keyboard.

## 2026-06-14 - Improve descriptive alt text for image preview
**Pattern:** Generic alt text like "Preview of selected image" is less helpful than more descriptive text.
**Fix:** Use more descriptive alt text like "Preview of the image selected for upload" to provide better context for screen reader users.
