# Codex Project Instructions

This repository is a Laravel 12 church website (TALL stack: Tailwind + Alpine + Livewire + Laravel) with FFmpeg-based media processing.

## Skills

A skill is a set of local instructions in a `SKILL.md` file.

### Available skills
- `livewire-development`: Use for Livewire component creation/updates, `wire:*` directives, reactivity bugs, or Livewire tests.  
  File: `/Users/garethclarridge/Projects/crockenhill/.claude/skills/livewire-development/SKILL.md`
- `tailwindcss-development`: Use for styling/layout/Tailwind utility changes, responsive work, or dark mode updates.  
  File: `/Users/garethclarridge/Projects/crockenhill/.claude/skills/tailwindcss-development/SKILL.md`
- `frontend-design`: Use for project-specific UI design decisions on public/admin pages, design token usage, component reuse, and avoiding legacy visual patterns.  
  File: `/Users/garethclarridge/Projects/crockenhill/.claude/skills/frontend-design/SKILL.md`

### Skill usage rules
- If a task clearly matches a skill, open its `SKILL.md` and follow it.
- Use the minimal set of skills needed for the task.
- If a skill cannot be read, continue with best-effort fallback and state that briefly.

## Required workflow

- Run project commands through Sail: `vendor/bin/sail ...`
- Use Laravel conventions first (`artisan make:*`, Form Requests, Eloquent relationships, queued jobs for long-running work).
- Keep to existing structure and conventions; do not introduce new base folders without approval.
- Do not change dependencies without approval.
- Do not create documentation files unless explicitly requested.

## Boost/MCP preference

- If Laravel Boost MCP tools are available, use them first for Laravel/package docs and app inspection.
- Prefer docs-first lookups (`search-docs`) before implementing framework/package-level changes.
- Prefer MCP helpers for route/artisan/config/database introspection over ad-hoc scripts when possible.

## Quality gates before finishing code changes

- Run focused tests for changed behavior (minimum necessary scope).
- Run static analysis: `vendor/bin/sail composer phpstan` (must remain at 0 errors).
- Run formatting: `vendor/bin/sail bin pint --dirty`.

## Testing rules

- Every code change must be programmatically tested (new or updated tests).
- Prefer feature tests for HTTP/integration behavior; use unit tests for isolated logic.
- Use compact output and filters when possible, for example:  
  `vendor/bin/sail artisan test --compact tests/Feature/ExampleTest.php`  
  `vendor/bin/sail artisan test --compact --filter=testName`
- For broad runs, parallel mode is preferred:  
  `vendor/bin/sail artisan test --parallel --compact`

## Laravel/PHP conventions

- Always use explicit return types and parameter type hints.
- Always use curly braces for control structures.
- Use constructor property promotion where appropriate.
- Use `config(...)` instead of `env(...)` outside config files.
- Prefer model queries/relationships over raw `DB::` usage.
- Avoid N+1 queries via eager loading.
- In Laravel 12, middleware/bootstrapping belongs in `bootstrap/app.php` (not `app/Http/Kernel.php`).

## Frontend conventions

- Follow existing Tailwind v3 patterns in this repo.
- Use `wire:model.live` when real-time Livewire updates are intended.
- Livewire components should keep server-side state and validate/authorize actions.

## Design workflow (Codex)

- For any UI/styling/layout task, read `docs/design-style-guide.md` before making changes.
- Use `frontend-design` for all UI work; combine with `tailwindcss-development` and/or `livewire-development` only when needed.
- Reuse existing shared Blade components first, and check live variants at `/dev/components` (`resources/views/dev/components.blade.php`).
- Use project brand tokens and typography intentionally (`cbc-*` colors, `font-display` for major headings, `font-sans` for body text).
- Do not introduce legacy patterns called out in the style guide (for example, indigo focus-ring chains and one-off raw form/button markup where shared components exist).
- Before finishing UI changes, validate mobile layout, internal `wire:navigate` links, loading/empty/error/success states, and keyboard/focus behavior.

## Project context quick map

- Core domains: pages, sermons, meetings, livestream segmentation/extraction.
- Key services live in `app/Services/` (processing, storage, transcription, thumbnails, image handling).
- API processing endpoints: `/api/sermons/{audio|video|livestream}` and `/api/sermons/processing/{id}/status`.
