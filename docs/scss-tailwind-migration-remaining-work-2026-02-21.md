# SCSS -> Tailwind Migration: Remaining Work

Date: February 21, 2026

## Goal

Complete the migration away from legacy `resources/css/cbc/*.scss` partials so the frontend styling system is fully Tailwind-first, predictable, and easier to maintain.

## Current State Snapshot

Completed:
- Home page hero/typewriter/nav styles were migrated to Tailwind component-layer SCSS:
  - `resources/css/tailwind/components/_home.scss`

Still active in app build:
- `resources/css/app.scss` still imports:
  - `cbc/mixins`
  - `cbc/cards`
  - `cbc/header`
  - `cbc/footer`
  - `cbc/pages`
  - `cbc/sermons`
  - `cbc/text`

Legacy partials present but not currently imported in `app.scss`:
- `resources/css/cbc/_cookies.scss`
- `resources/css/cbc/_fullwidth.scss`
- `resources/css/cbc/_map.scss`
- `resources/css/cbc/_meetings.scss`
- `resources/css/cbc/_home.scss` (replaced)

## Remaining Work

### 1. Audit and Classify Legacy Rules

Objective:
- Build a definitive keep/migrate/delete decision list for every selector in active legacy partials.

Tasks:
- For each active partial (`_cards`, `_header`, `_footer`, `_pages`, `_sermons`, `_text`), list selectors and usage locations.
- Confirm whether selectors are still used in Blade templates, markdown-rendered content, admin pages, or dynamic content.
- Mark each selector as:
  - `Migrate to Tailwind component class`
  - `Replace with inline utility classes in Blade`
  - `Delete as dead CSS`

Deliverable:
- A tracked checklist (in PR description or migration issue) with selector-level dispositions.

### 2. Migrate Active Legacy Partials to Tailwind Component Layers

Create focused files under `resources/css/tailwind/components/` and move styles in this order:

1. Typography and global link styles
- Source: `resources/css/cbc/_text.scss`
- Target suggestion: `resources/css/tailwind/components/_typography.scss`
- Notes:
  - Keep Filament font reset behavior explicit.
  - Avoid broad global selectors unless required.

2. Header/nav legacy rules
- Source: `resources/css/cbc/_header.scss`
- Target suggestion: `resources/css/tailwind/components/_header.scss`
- Notes:
  - Prefer Blade utility classes where possible.
  - Keep only reusable component-level rules in `@layer components`.

3. Cards/content image rules
- Source: `resources/css/cbc/_cards.scss`
- Target suggestion: `resources/css/tailwind/components/_cards.scss`
- Notes:
  - Review `.main-content p img` float behavior and replace with responsive utility patterns if possible.

4. Sermon presentation rules
- Source: `resources/css/cbc/_sermons.scss`
- Target suggestion: `resources/css/tailwind/components/_sermons.scss`
- Notes:
  - Preserve `sermon-details` visual semantics while migrating spacing/color to Tailwind classes.

5. Page editor-specific rules
- Source: `resources/css/cbc/_pages.scss`
- Target suggestion: `resources/css/tailwind/components/_pages.scss`
- Notes:
  - Keep `#rendered-content img { width: 100% }` behavior or move to the relevant editor markup.

6. Footer-specific rule
- Source: `resources/css/cbc/_footer.scss`
- Target suggestion: merge into footer component markup or `resources/css/tailwind/components/_footer.scss`.

### 3. Remove Dependency on Legacy Mixins and Variables

Objective:
- Eliminate `cbc/mixins` and `variables` coupling from migrated components.

Tasks:
- Replace custom breakpoint mixins with Tailwind responsive variants.
- Replace `map.get($theme-colors, ...)` usage with Tailwind palette classes or CSS variables where needed.
- Replace spacing map usage with Tailwind spacing utilities or `@apply`.

### 4. Resolve Non-Imported Legacy Partials (Stale or Deferred)

For each non-imported file, choose and execute one path:
- `Migrate now` if still needed
- `Delete` if unused

Files:
- `resources/css/cbc/_cookies.scss`
  - Contains variables that are not imported in this file; likely stale and should be confirmed/deleted or rewritten.
- `resources/css/cbc/_fullwidth.scss`
- `resources/css/cbc/_map.scss`
- `resources/css/cbc/_meetings.scss`

### 5. Retire Legacy Imports and Files

After migration:
- Remove all remaining `@use 'cbc/*'` imports from `resources/css/app.scss`.
- Keep only Tailwind component imports under `resources/css/tailwind/components/`.
- Delete migrated/dead files from `resources/css/cbc/`.

## Suggested Execution Sequence

1. Complete selector audit and disposition map.
2. Migrate `_text` and `_header` first (lowest risk, broad impact).
3. Migrate `_footer` and `_pages`.
4. Migrate `_sermons` and `_cards` (higher regression risk).
5. Resolve non-imported stale partials.
6. Remove legacy imports and clean up `resources/css/cbc/`.

## Definition of Done

Technical:
- `resources/css/app.scss` has no `@use 'cbc/*'` lines.
- All required styles exist under Tailwind component layers and/or Blade utility classes.
- `resources/css/cbc/` is removed or contains only explicitly approved legacy leftovers.

Verification:
- `./vendor/bin/sail bin pint --dirty`
- Build succeeds (`vite`/Sail frontend build path used by project).
- Visual QA on key pages:
  - Home
  - Sermons listing/detail
  - Header/navigation (desktop + mobile)
  - Footer
  - Page editor preview
  - Meeting/community pages that previously relied on legacy full-width/map styles

Regression guardrails:
- No loss of reduced-motion behavior already implemented for home animation.
- No global typography regressions in Filament admin font overrides.
