---
name: frontend-design
description: >-
  Applies Crockenhill's project-specific UI design system. Activate for any visual/UI work:
  page layout, cards, forms, buttons, typography, color/branding, interaction states, responsive
  behavior, component selection, and design consistency decisions across public and admin screens.
---

# Frontend Design Skill — Crockenhill Baptist Church

This skill is project-specific and overrides generic frontend-design advice. Follow it exactly.

## Before writing any UI code

1. Read `docs/design-style-guide.md` — it is the single source of truth for tokens, components, and patterns.
2. Check `resources/views/components/` — always reuse an existing component before writing custom markup.
3. Look at sibling views for the same area (admin vs public) to match the established visual rhythm.
4. Browse `/dev/components` (local only) to see all components rendered with their variants.

## Public pages — design intent

Warm, welcoming, content-led. The church serves an older demographic; clarity and readability come first.

- **Typography**: Major headings use `font-display` (Oswald). Body and metadata use `font-sans` (Lato).
- **Color**: The teal palette (`cbc-teal.*`) is primary. `cbc-crimson` and `cbc-emerald` are accent-only. Never introduce arbitrary colors.
- **CTAs**: Use the teal gradient or `bg-cbc-pattern` for primary public calls to action — not generic green or blue.
- **Content rails**: Wrap prose in `<x-content-wrapper>` (defaults to `max-w-2xl xl:max-w-3xl`). Card grids expand to `lg:max-w-5xl xl:max-w-7xl`.
- **Links**: Use `wire:navigate` on all internal links. Standard `<a>` only for external links.

## Admin pages — design intent

Neutral, functional, fast-scanning. No brand flourish. Dense but readable.

- **Shell**: Use the admin layout (`resources/views/layouts/admin.blade.php`).
- **Page structure**: `flex justify-between items-center` title row → filter bar (`flex flex-wrap gap-4`) → `<x-card>` containing table.
- **Tables**: `overflow-x-auto` > `min-w-full divide-y divide-gray-200`, header `bg-gray-50 text-xs uppercase tracking-wider`, row `hover:bg-gray-50`.

## Component rules

| Need | Use | Never do |
|------|-----|----------|
| Link/button | `<x-button :link="..." variant="...">` | Raw `<a>` with inline classes |
| Form submit | `<x-form-button variant="...">` | Raw `<button>` with custom classes |
| Text input | `<x-input label="..." wire:model="...">` | Hand-rolled `<input>` |
| Dropdown | `<x-select :options="..." wire:model="...">` | Raw `<select>` |
| Boolean switch | `<x-toggle label="..." wire:model="...">` | Checkbox hacks |
| White surface | `<x-card>` or `<x-card heading="...">` | `div` with ad-hoc shadow/bg |
| Section heading | `<x-h2>` | Custom gradient text markup |
| Page title | `<x-h1>` | Ad-hoc `<h1>` styling |

## Button variants

- `default` — pattern background (public brand actions)
- `primary` — green (admin save/create)
- `feature` — solid teal (public feature links)
- `featureOutline` — light bg, teal text (public CTA inside gradient border)
- `outline` — bordered white (secondary admin actions)
- `danger` — red (destructive)
- `ghost` — hover-only (icon actions)

Sizes: `xs sm md lg xl`

## CTA patterns

### Public primary CTA (teal gradient border)

```blade
<div class="mx-auto w-full max-w-[34rem] px-6 text-center">
  <div class="w-full rounded-xl bg-[linear-gradient(120deg,theme(colors.cbc-teal.light)_0%,theme(colors.cbc-teal.DEFAULT)_55%,theme(colors.cbc-teal.dark)_100%)] p-[1.5px]">
    <x-button link="/target" variant="featureOutline" size="lg" class="w-full rounded-[11px]">
      Call to action
    </x-button>
  </div>
</div>
```

### Standard admin page shell

```blade
<div class="space-y-6">
  <div class="flex justify-between items-center">
    <div>
      <h1 class="font-display text-3xl">Title</h1>
      <p class="text-gray-600">Subtitle</p>
    </div>
    <x-button link="#" variant="primary" icon="plus" inline>Create</x-button>
  </div>
  <div class="flex flex-wrap gap-4">
    <x-input placeholder="Search..." wire:model.live.debounce="search" class="w-64" />
    <x-select placeholder="Filter" wire:model.live="filter" class="w-48" />
  </div>
  <x-card>
    <div class="overflow-x-auto"><!-- table --></div>
  </x-card>
</div>
```

## Interaction patterns

- **Loading states**: Use `wire:loading` spinners on async actions; progress bars for uploads.
- **Feedback**: Inline alerts/toasts for success/error. `role="alert"` on critical messages.
- **Transitions**: Alpine transitions for modals, toasts, expand/collapse. No excessive animation on content pages.
- **Reduced motion**: Any new CSS animations must include `@media (prefers-reduced-motion: reduce)` override.

## Accessibility baseline (must maintain)

- `aria-label` on every icon-only action.
- `x-cloak` on Alpine hidden elements.
- Visible focus states (`focus:ring-*`, `focus-visible:ring-*`).
- Keyboard operation for all interactive controls.

## Anti-patterns — never introduce

- Indigo focus chains: `focus:border-indigo-300 focus:ring-indigo-200`
- Raw form markup where shared form components exist
- New one-off SCSS when a Tailwind utility or `@layer components` extraction works
- Arbitrary colors outside the `cbc-*` palette for brand elements
- `<a>` or `<button>` with hand-rolled styling when `x-button`/`x-form-button` fits
- External fonts not already loaded (Oswald, Lato only)

## Pre-merge checklist

1. Reused existing components where possible
2. Internal links use `wire:navigate`
3. Brand tokens used intentionally (no arbitrary colors)
4. Mobile layout verified (stacking, wrapping, touch targets ≥ 44px)
5. Loading, empty, error, and success states included
6. Keyboard and focus behavior works
7. No legacy indigo patterns introduced
8. PHPStan at 0 errors: `vendor/bin/sail composer phpstan`
9. Pint clean: `vendor/bin/sail bin pint --dirty`
