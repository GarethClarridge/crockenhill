# Blade Template and Layout Structure Review

Date: 2026-04-16

Scope: static, read-only review of Blade template organisation, layout inheritance, layout components, and shared page-shell contracts across public, auth, admin, and Livewire surfaces. I used the Laravel 12 Blade documentation as the standards reference. The official docs still support template inheritance, but they now present layout components first and explicitly note that components and slots are often easier to reason about than sections and yields.

## Findings

### 1. [Medium] `layouts/page` is still both a renderable page and a reusable layout

The public page shell is doing two jobs at once. `app/Http/Controllers/PageController.php:53-62` and `app/Http/Controllers/PageController.php:79-88` render `layouts/page` directly as the final response for CMS-style pages, while many other public views extend the same file as a layout, including `resources/views/auth/login.blade.php:1-8`, `resources/views/sermons/index.blade.php:1-38`, `resources/views/meetings/show.blade.php:1-122`, and `resources/views/calendar/index.blade.php:1-69`.

That is valid Blade, but it leaves the codebase without a clean distinction between a shell and a page. The direct result is that `resources/views/layouts/page.blade.php:1-92` has to carry both generic frame responsibilities and page-specific rendering rules. It also explains why `app/View/Composers/LayoutPageComposer.php:10-20` still exists as a layout-specific bridge even though most public page assembly now happens in `PageController`.

The structure works, but it is harder to evolve than a simpler split where controllers return a concrete page view and that page view consumes a dedicated layout component or layout template.

### 2. [Medium] The public layout contract is split across sections, implicit variables, and component side effects

The public shell currently mixes several different ways of passing layout state:

- `resources/views/layouts/main.blade.php:7-42` reads `title`, `meta_description`, `meta_tags`, `canonical`, and `preload` through sections and yields.
- `resources/views/layouts/page.blade.php:3-21` computes other shell state from implicit variables like `$heading`, `$description`, `$metaDescription`, and `$headingpicture`.
- `resources/views/components/meta-tags.blade.php:73-77` can also define the parent `canonical` section as a side effect when a `canonical` prop is passed.
- The auth wrapper views such as `resources/views/auth/login.blade.php:3-8`, `resources/views/auth/register.blade.php:3-8`, and `resources/views/auth/forgot-password.blade.php:3-8` still define `description` and `heading` sections that `resources/views/layouts/page.blade.php:3-34` does not read.

None of this is a framework violation, but the contract is harder to reason about than it needs to be. A page author has to know which values are variables, which are sections, which are optional, and which components can mutate parent sections during render. That is exactly the kind of implicit view coupling that becomes expensive when new public pages or metadata requirements are added.

### 3. [Medium] The admin area still mixes two page-shell systems

The admin composition layer is in much better shape than it used to be. Shared primitives now exist in `resources/views/components/admin/page.blade.php:1-21`, `resources/views/components/admin/list-shell.blade.php:1-28`, and `resources/views/components/admin/form-shell.blade.php:1-39`, and most newer Livewire screens converge on them. `resources/views/livewire/admin/pages/list-pages.blade.php:1-155` is a good example of the cleaner direction.

However, older controller-rendered screens still rely on `@extends('layouts/admin')` and then nest the newer admin components inside the yielded content, for example:

- `resources/views/meetings/index.blade.php:1-72`
- `resources/views/admin/calendar/patterns.blade.php:1-94`
- `resources/views/admin/calendar/uncategorized.blade.php:1-61`

That leaves the admin surface with two active shell patterns:

- Livewire pages using shared `x-admin.*` composition directly.
- Controller pages still routed through `resources/views/layouts/admin.blade.php:1-112` and `@section('dynamic_content')`.

This is not breaking behavior today, but it slows down convergence on one mental model for admin UI work and keeps the layout boundary more complex than the newer component layer needs.

### 4. [Low] View naming and composer ownership still show historical drift

The remaining view wiring is broadly functional, but the naming and registration patterns still reveal historical transitions:

- `app/Providers/ViewServiceProvider.php:32-38` mixes component view composers and slash-style view names.
- The provider still registers `includes.footer` at `app/Providers/ViewServiceProvider.php:33`, even though the main shell renders `x-layout.footer` from `resources/views/components/layout/footer.blade.php:1-82`.
- Public views and controllers mix `layouts.page`, `layouts/page`, `layouts.admin`, and `layouts/admin` naming styles.

This is not a standards failure on its own. Laravel resolves these view names correctly. The issue is maintainability: the view layer no longer communicates clearly which contracts are current, which registrations are legacy leftovers, and which shell pattern should be preferred for new work.

## Overall Assessment

The view tree itself is healthy. `resources/views` is grouped by domain, shared UI lives in `resources/views/components`, and Livewire templates mirror the Livewire class structure well. The project is clearly compatible with Laravel 12 Blade conventions.

What it is not, yet, is fully standardised around the more modern component-first layout direction that Laravel now documents first. The codebase feels partially modernised:

- Public pages still lean on a section-and-yield layout contract.
- Admin Livewire screens are closer to component-first composition.
- A few public and auth wrappers still carry dead or overlapping layout APIs from earlier iterations.

So the answer is “yes, it meets Laravel 12 standards”, but only in the broad sense that the patterns are still supported and valid. It does not yet feel fully aligned with the current Laravel preference for explicit component-based layout composition where that model improves clarity.

## Open Questions

- Should the app standardise on component-based shells for all new page work, with template inheritance retained only for legacy/public CMS-style pages during migration?
- Should `PageController` stop returning `layouts/page` directly and instead render a concrete page view such as `pages/show` that consumes the shell?
- Should canonical, meta, preload, and title state move onto explicit props and stacks so shell state is declared in one place instead of across variables, sections, and component side effects?
- Are the remaining controller-rendered admin pages meant to migrate onto the shared `x-admin.*` composition layer, or are they intentionally accepted as a parallel exception path?

## Clearly Improved Since Earlier Reviews

- The admin component layer now exists and is strong enough to be a real convergence target.
- The public read-side page assembly is cleaner than it was in earlier review cycles because `PageController` now owns most public page preparation.
- Shared structural pieces such as `x-layout.header`, `x-layout.footer`, `x-page-header`, `x-content-wrapper`, and `x-meta-tags` have already removed a lot of raw duplication from the view layer.
- The remaining issues are mostly contract clarity and pattern convergence problems, not wholesale view-tree chaos.
