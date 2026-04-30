# Crockenhill Design Style Guide

This guide captures the current UI language in this Laravel 12 + Livewire + Tailwind v3 project and defines the standards for new feature work.

## Visual References

Screenshots of key page types live in `docs/design-references/`. They are gitignored — regenerate locally using the commands below.

| File | URL | What it shows |
|------|-----|---------------|
| `home-desktop.png` | `http://localhost/` | Full-page desktop: hero, nav, card grids, CTA gradient border |
| `home-mobile.png` | `http://localhost/` | Mobile: hamburger nav, stacked hero, responsive cards |
| `sermons-index-desktop.png` | `http://localhost/christ/sermons` | Sermon card grid, filter bar, listing layout (desktop) |
| `sermons-index-mobile.png` | `http://localhost/christ/sermons` | Sermon cards stacked, mobile filter bar |
| `content-page-desktop.png` | `http://localhost/church` | Standard public page: heading, prose rail, related pages |
| `section-page.png` | `http://localhost/church` | Section landing page pattern |
| `component-gallery-desktop.png` | `http://localhost/dev/components` | All shared components with all variants |

### Regenerating screenshots

```bash
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
OUT="docs/design-references"
mkdir -p "$OUT"

# Full-page desktop (1440px wide)
for url_slug in "/:home-desktop" "/christ/sermons:sermons-index-desktop" "/church:content-page-desktop" "/dev/components:component-gallery-desktop"; do
  url="${url_slug%%:*}"; name="${url_slug##*:}"
  "$CHROME" --headless=new --window-size=1440,5000 --screenshot="$OUT/$name.png" --hide-scrollbars "http://localhost$url" 2>/dev/null
done

# Mobile viewport (375px wide)
for url_slug in "/:home-mobile" "/christ/sermons:sermons-index-mobile"; do
  url="${url_slug%%:*}"; name="${url_slug##*:}"
  "$CHROME" --headless=new --window-size=375,5000 --screenshot="$OUT/$name.png" --hide-scrollbars "http://localhost$url" 2>/dev/null
done
```

## 1. Scope and Goals

- Keep public pages warm, welcoming, and content-led.
- Keep admin pages efficient, scannable, and task-focused.
- Reuse existing Blade components before adding new one-off markup.
- Prefer the modern Livewire component patterns already used in `resources/views/livewire/admin/**`.

## 2. Core UI Architecture

### Preferred Shell Pattern (new work)

New pages should use component-based shells. These push head metadata onto stacks consumed by `layouts/main.blade.php` and expose a `$slot` for body content:

| Shell | Tag | Use for |
|---|---|---|
| Public CMS pages | `<x-page.shell>` | Controller-rendered CMS pages via `pages/show.blade.php` |
| Auth pages | `<x-auth.shell>` | Login, register, password-reset, verify-email |
| Admin pages | `<x-admin.shell>` | Controller-rendered admin pages (not Livewire full-page) |

**`<x-page.shell>` props:** `heading` (required), `metaDescription`, `description`, `headingpicture`, `headingpictureMobile`, `headingpictureTablet`, `area`, `slug`, `links`, `canonical`, `showToolbar` (default: `true` — pass `:show-toolbar="false"` to suppress breadcrumbs and edit buttons). Has a `$fullWidth` named slot for content that breaks out of the content wrapper.

**`<x-auth.shell>` props:** `heading` (required), `description`. No toolbar, no related pages.

**`<x-admin.shell>` props:** `heading` (required), `title` (optional, defaults to heading). Renders breadcrumbs and toast container.

For **Livewire full-page admin components**, continue using the `#[Layout('layouts.admin')]` attribute and composing `<x-admin.page>`, `<x-admin.list-shell>`, or `<x-admin.form-shell>` directly inside the component view — no `<x-admin.shell>` wrapper needed.

### Legacy Layout Pattern (tolerated, not preferred)

The following `@extends`-based patterns remain active for the existing 22 public views. Do not use these for new pages. Migration to `<x-page.shell>` is tracked as a Phase 2 follow-up:

- `@extends('layouts.page')` — public content pages (sermons, meetings, calendar, songs, children's corner)

`layouts/main.blade.php` reads both `@push` stacks (new shells) and `@section` yields (legacy views) for `title`, `meta_description`, `meta_tags`, and `canonical` — this dual-consumer pattern ensures legacy views keep rendering correctly until Phase 2.

### Base Layout and Shared Shell Components

- HTML root: `resources/views/layouts/main.blade.php`
- Legacy public shell: `resources/views/layouts/page.blade.php` (retained for legacy `@extends` views)
- Legacy admin shell: `resources/views/layouts/admin.blade.php` (retained for Livewire `#[Layout]` consumers)
- Header: `resources/views/components/layout/header.blade.php`
- Footer: `resources/views/components/layout/footer.blade.php`
- Page header switcher (image or text): `resources/views/components/page-header.blade.php`
- Width wrapper: `resources/views/components/content-wrapper.blade.php`

### Tailwind + SCSS Layering

- Entry stylesheet: `resources/css/app.scss`
- Tailwind config: `tailwind.config.js`
- Component layer partials: `resources/css/tailwind/components/_*.scss`

Rule: new UI should primarily be Tailwind utility classes with optional `@layer components` extraction for repeated patterns.

## 3. Design Tokens

### Brand Colors (from `tailwind.config.js`)

- `cbc-teal.light`: `#249a97`
- `cbc-teal`: `#1d686a`
- `cbc-teal.dark`: `#145557`
- `cbc-teal.deeper`: `#0f4143`
- `cbc-teal.darkest`: `#134e4a`
- `cbc-crimson`: `#6b0f1a`
- `cbc-rose-muted`: `#c07c84`

### Supporting Neutrals

- Background baseline: `bg-slate-200` (body)
- Cards/surfaces: `bg-white`, `border-gray-200/300`, subtle shadow
- Secondary text: `text-gray-500/600/700`

### Gradients and Texture

- Pattern texture: `bg-cbc-pattern bg-cover` (header/footer and some action buttons)
- Teal CTA gradient: `bg-[linear-gradient(120deg,...cbc-teal...)]`
- Dark image overlay: `bg-gradient-to-t from-black/35 via-black/10 to-transparent`

Rule: for new primary public CTAs, use teal gradient or cbc pattern, not generic blue.

## 4. Typography

### Fonts

- Body font: `font-sans` (`Lato`)
- Display font: `font-display` (`Oswald`)

### Scale and Roles

- Primary page title (public): `text-6xl font-display`
- Section heading: shared `<x-h2>` (`text-3xl sm:text-4xl`, gradient text treatment)
- Sub-section heading: shared `<x-h3>` (`text-2xl sm:text-3xl`, centred teal-dark display font)
- Admin page heading: `text-3xl font-display`
- Body copy: prose blocks via `prose`

Rule: use display font for major headings/nav labels; keep body and metadata in sans.

## 5. Spacing and Layout

### Width Conventions

- Standard content rail: `<x-content-wrapper>` default `max-w-2xl xl:max-w-3xl`
- Card grids (public listings): often expand to `lg:max-w-5xl xl:max-w-7xl`

### Grid Patterns

- Card lists: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4`
- Admin form pages: `grid grid-cols-1 lg:grid-cols-3` with main `lg:col-span-2`
- Filter bars: `flex flex-wrap gap-4`

### Spacing Rhythm

- Section top spacing often `mt-6` or `mt-8`
- Card internal spacing usually `p-6`
- Form vertical rhythm `space-y-4` for fields

Rule: prefer `gap-*` for sibling spacing in new layouts.

## 6. Component Standards

### Buttons

Use shared components:

- Link/button hybrid: `<x-button>`
- Form submit/action: `<x-form-button>`

Preferred variants:

- Primary action: `primary` (cbc-pattern branded button)
- Public CTA inside teal gradient border: `secondary` (slate bg, teal text)
- Neutral secondary: `outline` or `ghost`
- Destructive: `danger` (cbc-crimson)

Rule: do not introduce plain `<a>` or `<button>` styling when an existing button component fits.

### Inputs and Form Controls

Use shared controls:

- `<x-input>`
- `<x-select>`
- `<x-textarea>`
- `<x-toggle>`

Patterns already built in:

- Label + hint + error states
- Focus ring consistency (`focus:ring-cbc-teal`)
- Optional loading indicators and clearable input behavior

Livewire rule: use `wire:model.live` for responsive filters/forms where immediate feedback is intended.

### Cards

Shared card components define the current look:

- Generic surface: `<x-card>`
- Public promo: `<x-page-card>`, `<x-sermon-card>`, `<x-clickable-card>`
- Calendar/event: `<x-calendar-event-card>`

Rule: prefer rounded corners (`rounded-lg` or `rounded-xl`), light borders, and subtle shadows.

### Tables (Admin)

Common structure:

- Wrapper: `overflow-x-auto`
- Table: `min-w-full divide-y divide-gray-200`
- Header: `bg-gray-50` with `text-xs uppercase tracking-wider`
- Row hover: `hover:bg-gray-50`

## 7. Interaction Patterns

### Navigation

- Use `wire:navigate` for internal links.
- Keep external links as standard anchors without `wire:navigate`.

### Motion

- Alpine transitions used for:
  - Mobile menu
  - Toast notifications
  - Expand/collapse sections
- Home hero has custom typewriter/fade animation in `_home.scss`.
- Respect reduced motion (`@media (prefers-reduced-motion: reduce)` already implemented for home hero).

### Feedback States

- Inline alerts/toasts for success/error.
- `wire:loading` spinners on async actions.
- Progress bars for uploads/processing status.

## 8. Accessibility Baseline

Keep and extend current good patterns:

- Skip link in main layout.
- `aria-label` for icon-only actions.
- Proper `role="alert"` for critical messages.
- `x-cloak` on Alpine hidden elements.
- Visible focus states (`focus:ring-*`, `focus-visible:ring-*`).

When adding UI, ensure keyboard operation for all interactive controls.

## 9. Public vs Admin Visual Direction

### Public Pages

- Stronger brand expression: display typography, hero imagery, textured/gradient CTA blocks.
- Content readability via centered `prose` rails.
- Visual emphasis on cards for navigation to ministry content.

### Admin Pages

- Neutral, functional surfaces with clear hierarchy.
- Fast scanning: dense but readable tables, badges, compact filters.
- Consistent top action row: title + primary action + filters + table.

## 10. Design Rationale

Understanding *why* a decision was made helps apply it correctly in new situations.

### Why centered prose rails at `max-w-2xl`?
The congregation skews older. Shorter line lengths (≈65–70 characters) reduce reading fatigue and improve comprehension. It also keeps sermon text and devotional content feeling intimate rather than like a news feed.

### Why teal gradient / `bg-cbc-pattern` for primary CTAs — not plain green?
The `primary` variant uses the cbc-pattern texture — a strong branded treatment suited to primary actions. The `secondary` variant (slate bg / teal text) is designed to sit inside a teal gradient border, giving public CTAs warmth and brand identity without being overpowering.

### Why `wire:navigate` on internal links?
Livewire's navigation preserves the SPA feel (progress bar, no full reload) and keeps Alpine component state across navigations. Omitting it causes noticeable white flashes and slower perceived performance.

### Why `x-button` / `x-form-button` components instead of raw elements?
Consistency in focus rings, loading states, icon sizing, and variant colour ramps is only achievable through a shared component. One-off button styling tends to drift (wrong focus ring colour, missing `disabled` state during Livewire loading), which degrades both accessibility and visual cohesion.

### Why Oswald for headings, Lato for body?
Oswald is condensed and authoritative — it works well at large display sizes in the hero and section headings. Lato is highly legible at small sizes and reads comfortably for older users on screens and in prose. The pairing provides clear typographic hierarchy without relying on weight alone.

### Why `bg-slate-200` body background instead of white?
A pure white body would make white card surfaces disappear — there would be no visual depth. The soft slate background creates the layered card-on-surface effect that gives the layout its structure. It also reduces eye strain for users reading long sermon content.

### Why `prose` for body copy instead of manual typography classes?
Sermon transcripts and page content come from a CMS and may contain arbitrary HTML (headings, lists, blockquotes, links). Tailwind's `prose` plugin applies a carefully tuned typographic scale to all these elements consistently. Manual classes would need to be reapplied each time new content types appear.

### Why avoid `@layer components` SCSS unless strictly necessary?
Utility classes are visible at the call site — you can read a template and understand immediately what it looks like. Extracted component classes hide that context, making it harder to trace why something looks a certain way. Only extract to `@layer components` when the exact same multi-class pattern repeats 4+ times across unrelated files.

## 11. Legacy Patterns to Avoid in New Work

Some older pages still use older styling conventions. Do not copy these into new features.

Avoid:

- Indigo/blue focus ring chains like `focus:border-indigo-300 focus:ring-indigo-200`
- Hand-rolled form markup where shared form components exist
- Mixed legacy utility patterns (`w-100`, ad-hoc button class blocks)
- New one-off SCSS when a Tailwind utility/component can be reused

Prefer migration path:

1. Replace raw fields with `x-input/x-select/x-textarea/x-toggle`.
2. Replace raw action buttons with `x-button/x-form-button`.
3. Move list/table UIs onto the modern admin table pattern.

Legacy examples (use as migration targets, not design references):

- `resources/views/pages/create.blade.php`
- `resources/views/pages/edit.blade.php`
- `resources/views/meetings/create.blade.php`
- `resources/views/meetings/edit.blade.php`
- `resources/views/livewire/auth/*.blade.php`
- `resources/views/errors/{403,404,500}.blade.php`

## 12. New Feature UI Checklist

Before merging UI work:

1. Reused existing components where possible.
2. Internal links use `wire:navigate`.
3. Brand tokens (cbc palette, display type) used intentionally.
4. Mobile layout validated (stacking, wrapping, hit areas).
5. Loading, empty, error, and success states included.
6. Keyboard and focus behavior verified.
7. No new legacy-style indigo focus/input patterns introduced.

## 13. Quick Starter Patterns

### Standard Admin Page Shell

```blade
<div class="space-y-6">
  <div class="flex justify-between items-center">
    <div>
      <h1 class="font-display text-3xl">Title</h1>
      <p class="text-gray-600">Subtitle</p>
    </div>
    <x-button link="#" variant="primary" icon="plus" inline>
      Create
    </x-button>
  </div>

  <div class="flex flex-wrap gap-4">
    <x-input placeholder="Search..." wire:model.live.debounce="search" class="w-64" />
    <x-select placeholder="Filter" wire:model.live="filter" class="w-48" />
  </div>

  <x-card>
    <div class="overflow-x-auto">
      <!-- table -->
    </div>
  </x-card>
</div>
```

### Standard Public CTA Block

```blade
<div class="mx-auto w-full max-w-[34rem] px-6 text-center">
  <div class="w-full rounded-xl bg-[linear-gradient(120deg,theme(colors.cbc-teal.light)_0%,theme(colors.cbc-teal.DEFAULT)_55%,theme(colors.cbc-teal.dark)_100%)] p-[1.5px]">
    <x-button link="/target" variant="secondary" size="lg" class="w-full rounded-[11px]">
      Call to action
    </x-button>
  </div>
</div>
```
