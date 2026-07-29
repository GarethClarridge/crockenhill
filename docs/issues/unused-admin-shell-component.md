# 🪦 Mortician: Possibly dead — `resources/views/components/admin/shell.blade.php`

## What
An orphaned Blade component (`resources/views/components/admin/shell.blade.php`) that duplicates the standard admin layout logic and is completely unreferenced.

## Evidence of Disuse

We scanned the entire codebase for references to the `<x-admin.shell>` or `components.admin.shell` Blade component.

1. **Project-wide reference search:**
   ```bash
   grep -rn "admin\.shell" resources/views/ app/
   # Returns 0 matches

   grep -rn "components\.admin\.shell" resources/views/ app/
   # Returns 0 matches
   ```

2. **Test and Layout Analysis:**
   - In `tests/Feature/BladeShellRenderingTest.php`, there are test cases labeled under `// x-admin.shell` (such as `admin_shell_pushes_heading_as_page_title`), but these tests actually perform HTTP GET requests to `/admin/meetings`.
   - The route `/admin/meetings` loads the Livewire component layout defined in `resources/views/layouts/admin.blade.php`.
   - The file `resources/views/layouts/admin.blade.php` duplicates the layout container and notification logic found inside `resources/views/components/admin/shell.blade.php` but wraps it in standard layout templates.
   - The design style guide (`docs/design-style-guide.md`) explicitly notes that for Livewire full-page admin components, layouts (i.e. `layouts.admin`) should be used directly and that `<x-admin.shell>` is not needed. Since all admin panels are now implemented as full-page Livewire components, `<x-admin.shell>` is completely bypassed.

## Risk
**Low** — Pure removal of an unused template file.

## Recommendation
Safe to remove `resources/views/components/admin/shell.blade.php` in a future cleanup commit, as the layout behavior is fully owned and maintained by `resources/views/layouts/admin.blade.php`.
