# 🪦 Mortician: Possibly dead — `<x-icon-button>` Blade Component

**Path:** `resources/views/components/icon-button.blade.php`

**What:** Reusable icon button component intended as a design primitive.

**Evidence:**
- Project-wide grep for `<x-icon-button` excluding the component gallery (`resources/views/dev/components.blade.php`) returns **zero** production hits.
- Grep for `@component('icon-button')` and `Blade::component('icon-button', ...)` returns **zero** hits.
- Grep for dynamic view resolution `view('components.icon-button')` returns **zero** hits.
- The component is only referenced in the local-only component gallery: `resources/views/dev/components.blade.php`.
- Standard UI actions in admin lists (Preachers, Users) have standardized on `<x-button variant="ghost" ...>` instead of this component.

**Risk:** Low — isolated UI primitive with no production callers.

**Recommendation:** Safe to remove. The design system has standard alternatives via `<x-button>`, and this component remains an unadopted "to-do" from March 2026.
