# Crockenhill Design Style Guide

This guide captures the current UI language in this Laravel 12 + Livewire + Tailwind v3 project and defines the standards for new feature work.

## 1. Scope and Goals

- Keep public pages warm, welcoming, and content-led.
- Keep admin pages efficient, scannable, and task-focused.
- Reuse existing Blade components before adding new one-off markup.
- Prefer the modern Livewire component patterns already used in `resources/views/livewire/admin/**`.

## 2. Core UI Architecture

### Layouts

- Public base layout: `resources/views/layouts/main.blade.php`
- Public content layout: `resources/views/layouts/page.blade.php`
- Admin content layout: `resources/views/layouts/admin.blade.php`

### Shared Shell Components

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
- `cbc-emerald`: `#08a386`
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

- Primary action: `primary` (green)
- Destructive: `danger`
- Neutral secondary: `outline` or `ghost`
- Feature/public CTA: `feature` or `featureOutline`

Rule: do not introduce plain `<a>` or `<button>` styling when an existing button component fits.

### Inputs and Form Controls

Use shared controls:

- `<x-input>`
- `<x-select>`
- `<x-textarea>`
- `<x-toggle>`

Patterns already built in:

- Label + hint + error states
- Focus ring consistency (`focus:ring-green-500`)
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

## 10. Legacy Patterns to Avoid in New Work

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

## 11. New Feature UI Checklist

Before merging UI work:

1. Reused existing components where possible.
2. Internal links use `wire:navigate`.
3. Brand tokens (cbc palette, display type) used intentionally.
4. Mobile layout validated (stacking, wrapping, hit areas).
5. Loading, empty, error, and success states included.
6. Keyboard and focus behavior verified.
7. No new legacy-style indigo focus/input patterns introduced.

## 12. Quick Starter Patterns

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
    <x-button link="/target" variant="featureOutline" size="lg" class="w-full rounded-[11px]">
      Call to action
    </x-button>
  </div>
</div>
```
