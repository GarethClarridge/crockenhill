# Frontend/View Architecture Review

Date: 2026-03-18

## Scope

This review covered:

- Blade layouts and shared shell structure
- Shared Blade components
- Livewire templates and the Livewire classes that directly shape their UI state
- Alpine and JavaScript helpers used by frontend/admin flows
- View composers and layout composition that materially affect how views behave

## Executive Summary

The frontend is in the middle of a healthy but incomplete migration.

There is a clear design-system direction now: a style guide exists, a component gallery exists, modern admin Livewire screens are reusing `x-button`, `x-form-button`, `x-input`, `x-select`, `x-toggle`, `x-card`, and `x-admin.sortable-header`, and the public shell is already doing several good things such as a skip link, an offline banner, and widespread `wire:navigate`.

The biggest maintenance cost is not "bad Tailwind" or "too many Blade files". It is that the project has a strong primitive layer but a weak composition layer:

- There are good low-level controls.
- There is no reliable middle layer for list pages, form pages, alerts, badges, checkboxes, or CTA sections.
- A few shared components are doing too much, while others are too limited to cover real usage.
- State ownership is blurry in the Blade/Livewire/Alpine/JS boundary, especially in the upload and log-viewer flows.
- Legacy page-layout CRUD still coexists with the newer admin/Livewire architecture, so the repo currently asks maintainers to remember two frontend systems.

The result is visible drift: duplicated headers/filter bars/tables, raw controls where shared ones should exist, inconsistent navigation behavior, and several components that are technically reusable but not neutral enough to be dependable system primitives.

## What Is Working Well

- The project has a real design-language source of truth in `docs/design-style-guide.md`.
- The component gallery in `resources/views/dev/components.blade.php` is a strong investment and lowers onboarding cost.
- The public shell in `resources/views/layouts/main.blade.php:75-95` already includes an offline banner and a skip link.
- Modern admin list pages are converging on a recognisable pattern:
  - top title/action row
  - filter bar
  - white card
  - table
- `x-admin.sortable-header` is a good example of accessible reuse with `aria-sort` and a consistent interaction model in `resources/views/components/admin/sortable-header.blade.php:1-29`.

## Findings

### 1. [High] There is no reusable admin page-shell/list-shell/form-shell above the primitive components

The repo has good primitives, but each admin page still rebuilds the same page skeleton manually.

Evidence:

- Admin list shells are near-duplicates:
  - `resources/views/livewire/admin/pages/list-pages.blade.php:1-45`
  - `resources/views/livewire/admin/meetings/list-meetings.blade.php:1-38`
  - `resources/views/livewire/admin/users/list-users.blade.php:1-33`
  - `resources/views/livewire/admin/calendar-events/list-calendar-events.blade.php:1-32`
- Admin form shells are also near-duplicates:
  - `resources/views/livewire/admin/pages/page-form.blade.php:1-14`
  - `resources/views/livewire/admin/meetings/meeting-form.blade.php:1-14`
  - `resources/views/livewire/admin/users/user-form.blade.php:1-14`
- Filter-state logic is repeated in several Livewire classes rather than centralised:
  - `app/Livewire/Admin/Pages/ListPages.php:82-88`
  - plus matching `hasFilters` logic in `ListMeetings`, `ListUsers`, `ListPreachers`, `ListCalendarEvents`, and `ListSermons`

Why this matters:

- Layout, spacing, empty states, headers, action placement, and filter behaviors can only stay consistent if every page is manually kept in sync.
- Drift is already visible:
  - `list-pages` and `list-meetings` use `x-admin.sortable-header`
  - `list-users` and `list-calendar-events` fall back to raw `<th>` markup
- Any future change to the admin shell means touching many files.

Recommendation:

- Introduce a composition layer for admin pages:
  - `x-admin.page`
  - `x-admin.list-shell`
  - `x-admin.form-shell`
  - `x-admin.filter-bar`
  - `x-admin.empty-state`
- Add a small Livewire trait or computed helper for common filter-state/query-string patterns instead of recalculating `hasFilters` in each component.

### 2. [High] Some core shared components are not neutral enough to be safe design-system primitives

Several shared components currently bake in assumptions that force pages back to raw markup.

Evidence:

- `x-card` wraps all children in `.prose`:
  - `resources/views/components/card.blade.php:5-14`
- `x-card` is still used for admin tables and forms:
  - `resources/views/livewire/admin/pages/list-pages.blade.php:45-52`
  - `resources/views/livewire/admin/pages/page-form.blade.php:17-37`
- `x-button` hard-wires `wire:navigate` for every non-fragment link:
  - `resources/views/components/button.blade.php:52-61`
- `x-toggle` is Livewire-entangled by design:
  - `resources/views/components/toggle.blade.php:8-28`
- Even the component gallery has to fake a toggle with raw buttons because the real component cannot stand alone:
  - `resources/views/dev/components.blade.php:214-230`

Why this matters:

- A generic card component should be a neutral surface. Wrapping all slot content in `prose` makes it a typography opinion and a layout primitive at the same time.
- A generic button/link component should not silently assume Livewire SPA navigation for all non-`#` URLs.
- A generic boolean control should work in both Livewire and non-Livewire forms. Right now the system has no reusable checkbox/switch primitive for plain Blade forms.

Recommendation:

- Split `x-card` into two concepts:
  - neutral surface card
  - prose/content card
- Teach `x-button` to differentiate internal/external/download navigation instead of inferring everything from `#`.
- Add missing form primitives:
  - `x-checkbox`
  - a Livewire-optional `x-switch`
  - date/file controls where repeated raw markup already exists

### 3. [High] The upload and log-viewer flows have hidden state coupling across Blade, Livewire, Alpine, browser events, and child components

These flows are the clearest example of frontend state ownership being spread across too many layers.

Evidence:

- The media upload form owns Alpine state through a JS controller:
  - `resources/views/livewire/media-upload/form.blade.php:1-8`
- That same form uses raw controls, child Livewire components, and polling:
  - `resources/views/livewire/media-upload/form.blade.php:20-30`
  - `resources/views/livewire/media-upload/form.blade.php:119-155`
- The child progress component calls a parent Alpine method it does not define itself:
  - `resources/views/livewire/media-upload/progress.blade.php:8-14`
- The child status component dispatches events back up to the parent flow:
  - `app/Livewire/MediaUpload/Status.php:33-47`
  - `resources/views/livewire/media-upload/status.blade.php:89-110`
- The processing logs viewer duplicates UI state across Livewire and Alpine:
  - `app/Livewire/ProcessingLogsViewer.php:21-57`
  - `resources/views/livewire/processing-logs-viewer.blade.php:1-60`
  - `resources/views/livewire/processing-logs-viewer.blade.php:248-300`

Why this matters:

- `cancelUpload()` exists in JS, while upload lifecycle data exists in Livewire, while progress is rendered in a child component, while status actions dispatch custom events back upward.
- `expanded` and `autoRefresh` are both Livewire props and Alpine-entangled values in the log viewer.
- The log viewer also defines a global `window.logsViewer`, which makes the component harder to isolate and reason about.

Recommendation:

- Pick one owner per concern.
- For the upload flow, either:
  - keep it primarily Livewire with small Alpine-only enhancements for drag/drop, or
  - encapsulate the JS controller completely inside a single boundary instead of spreading it across parent/child components
- For the logs viewer, prefer one of:
  - fully Livewire-driven state with tiny Alpine-only transitions, or
  - a dedicated Alpine module that receives data but does not entangle and duplicate control logic

### 4. [High] The main navigation and page-layout composition rely on implicit cross-file coupling

The public shell works, but some of its most important behavior is implicit instead of local.

Evidence:

- The header’s `expanded` state is defined in the layout, not in the header component:
  - `resources/views/layouts/main.blade.php:84-86`
  - `resources/views/components/layout/header.blade.php:16-45`
  - `resources/views/components/layout/header.blade.php:73-101`
- The header component also depends on composer-injected `$pages` and `$user`:
  - `app/View/Composers/HeaderComposer.php:14-21`
  - `resources/views/components/layout/header.blade.php:114-200`
- `layouts/page` gets most of its data from route-shape inference in a view composer:
  - `app/View/Composers/LayoutPageComposer.php:31-42`
  - `app/View/Composers/LayoutPageComposer.php:77-96`
- `x-page-header` reaches into filesystem/server globals inside the view:
  - `resources/views/components/page-header.blade.php:8-14`

Why this matters:

- Reusing the header outside `layouts/main` would silently break unless it receives the same Alpine state and composed data.
- `layouts/page` behavior is driven by URL segments instead of explicit view inputs, so changing route shape can unexpectedly change layout composition behavior.
- Filesystem checks and request/route logic inside view files make the view layer harder to test and easier to surprise.

Recommendation:

- Make layout-level data dependencies explicit where possible.
- Keep views presentational; move request/route/file-resolution logic into presenters/view models.
- If the header needs local state, let the header own it rather than inheriting it from the parent layout.

### 5. [High] The repo still contains two competing frontend architectures for admin-like work

There is a visible divide between newer Livewire admin screens and older Blade CRUD screens built on `layouts/page`.

Evidence:

- Legacy CRUD/admin-ish screens:
  - `resources/views/meetings/index.blade.php:1-45`
  - `resources/views/admin/calendar/uncategorized.blade.php:1-58`
  - `resources/views/admin/calendar/patterns.blade.php:1-99`
  - `resources/views/sermons/edit.blade.php:1-79`
- Legacy table abstraction:
  - `resources/views/components/admin-table.blade.php:1-16`
- Divergent legacy action component:
  - `resources/views/components/admin-actions.blade.php:1-36`

Why this matters:

- Some authenticated workflows use `layouts/admin` with toasts and modern list patterns.
- Others still use `layouts/page`, raw alerts, raw selects, raw checkboxes, and a separate table/action system.
- That means maintainers have to remember which "admin rules" apply to which screen.

Recommendation:

- Pick a single target architecture for all admin and member-management CRUD.
- Migrate legacy pages toward:
  - `layouts/admin`
  - modern form/list shells
  - shared controls
- Retire `x-admin-table` once the remaining legacy screens are migrated.

### 6. [Medium] Navigation and CTA reuse are drifting even though the repo already has the right primitives

The project already has the right direction, but it is not enforced consistently.

Evidence:

- `x-public-cta` already standardises the teal gradient CTA wrapper:
  - `resources/views/components/public-cta.blade.php:8-19`
- But the same pattern is still hand-rolled elsewhere:
  - `resources/views/childrens-corner/index.blade.php:13-18`
  - `resources/views/church/songs/index.blade.php:93-98`
- Several internal links still bypass `wire:navigate`:
  - `resources/views/full-width-pages/community.blade.php:29-33`
  - `resources/views/full-width-pages/church.blade.php:200-212`
  - `resources/views/meetings/events.blade.php:7-10`
  - `resources/views/components/calendar-event-card.blade.php:71-73`
  - `resources/views/components/calendar-event-card.blade.php:131-133`
  - `resources/views/components/calendar-event-card.blade.php:187-188`
  - `resources/views/livewire/auth/login.blade.php:42-48`
  - `resources/views/livewire/media-upload/form.blade.php:127-130`
  - `resources/views/livewire/media-upload/status.blade.php:106-109`

Why this matters:

- Missing `wire:navigate` breaks the repo’s intended navigation feel in small, scattered ways.
- Repeating CTA wrappers instead of using `x-public-cta` guarantees visual drift.

Recommendation:

- Sweep internal links for `wire:navigate`.
- Replace hand-rolled gradient CTA wrappers with `x-public-cta`.
- Consider a small inline-link helper or prose-link convention for internal links inside CMS/public copy.

### 7. [Medium] Accessibility patterns are not centralised, so small regressions recur

The project has good intent, but the a11y contract weakens whenever a screen falls off the component path.

Evidence:

- Auth views duplicate alert markup instead of using a shared alert primitive:
  - `resources/views/livewire/auth/login.blade.php:3-7`
  - `resources/views/livewire/auth/verify-email.blade.php:5-13`
- The verify-email success notice has no live-region role:
  - `resources/views/livewire/auth/verify-email.blade.php:5-7`
- The processing-log refresh control is icon-only but only has `title`, not `aria-label`:
  - `resources/views/livewire/processing-logs-viewer.blade.php:28-41`
- Raw blue focus styles still appear in old or one-off views:
  - `resources/views/admin/calendar/uncategorized.blade.php:35-40`
  - `resources/views/meetings/show.blade.php:160-163`
  - `resources/views/livewire/media-upload/status.blade.php:91-100`

Why this matters:

- Without centralised `x-alert`, `x-checkbox`, and icon-button primitives, each screen must remember roles, labels, focus states, and color tokens on its own.
- The repo is already showing several small divergences, which is how accessibility debt accumulates.

Recommendation:

- Add shared primitives for:
  - alerts/notices
  - badges
  - checkboxes
  - icon-only buttons
- Prefer token-driven focus styles everywhere rather than view-local blue defaults.

### 8. [Medium] Several shared components mix presentation with routing, schema, authorization, or admin actions

Some components are reusable in name, but are really mini-features with multiple responsibilities.

Evidence:

- Breadcrumbs:
  - builds request-dependent breadcrumb data
  - renders JSON-LD
  - owns clipboard UI
  - `resources/views/components/breadcrumbs.blade.php:9-68`
  - `resources/views/components/breadcrumbs.blade.php:70-130`
- Public card components include admin controls:
  - `resources/views/components/page-card.blade.php:30-46`
  - `resources/views/components/sermon-card.blade.php:65-88`
  - `resources/views/components/edit-buttons.blade.php:1-17`
- `calendar-event-card` tries to serve four different variants in one template and repeats large sections of markup:
  - `resources/views/components/calendar-event-card.blade.php:17-22`
  - `resources/views/components/calendar-event-card.blade.php:28-87`
  - `resources/views/components/calendar-event-card.blade.php:88-147`
  - `resources/views/components/calendar-event-card.blade.php:148-205`

Why this matters:

- Components become harder to test and harder to safely reuse.
- Public/admin concerns leak into one another.
- Markup duplication migrates upward into components instead of disappearing.

Recommendation:

- Keep presentational components small and role-specific.
- Move breadcrumb composition/schema generation into a presenter/view model.
- Split public cards from admin overlays/actions.
- Break `calendar-event-card` into smaller dedicated variants or shared partials.

## Design-System Boundary Gaps

The repo has a good start on the primitive layer, but these gaps explain many of the raw markup fallbacks:

- No reusable alert/notice component
- No reusable badge/status-pill component
- No reusable checkbox primitive
- Toggle is Livewire-only
- No reusable icon-button primitive
- No date-range/filter-control composition
- No admin shell component family
- No public inline-link convention for internal `wire:navigate` links in prose

## Suggested Target Boundary

### 1. Data/presenter layer

Owns:

- route/page resolution
- breadcrumb generation
- schema data
- derived display metadata

Should not render UI classes.

### 2. Shell layer

Owns:

- public page shell
- admin page shell
- list shell
- form shell
- section wrapper

Should compose primitives, not business logic.

### 3. Primitive component layer

Owns:

- buttons/links
- alerts
- badges
- cards
- inputs/selects/checkboxes/switches/textareas
- icon buttons

Should be neutral and widely reusable.

### 4. Feature component layer

Owns:

- upload widget
- processing log viewer
- sermon/public cards
- event cards

Should contain feature-specific interaction, but still avoid cross-layer hidden dependencies.

## Recommended Cleanup Sequence

1. Build the missing shell components for admin list/form pages.
2. Refactor the primitive layer:
   - split `x-card`
   - make `x-button` navigation explicit
   - add `x-alert`, `x-badge`, `x-checkbox`
3. Migrate the remaining legacy admin-ish Blade screens onto the admin shell.
4. Simplify the upload/log-viewer state boundaries so each flow has a single obvious owner.
5. Sweep repeated CTA wrappers and internal-link navigation behavior.

## Bottom Line

The repo is close to having a maintainable frontend system, but it needs one more architectural pass.

The primitives are mostly there. The missing pieces are:

- a stronger composition layer above those primitives
- cleaner state ownership between Blade/Livewire/Alpine/JS
- retirement of the remaining legacy CRUD/view patterns

If those three things are addressed, the maintenance burden on future UI work should drop substantially.
