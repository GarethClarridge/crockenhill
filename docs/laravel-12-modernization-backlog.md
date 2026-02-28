# Laravel 12 Modernization Backlog

_Last updated: 2026-02-28_

## Scope
This backlog converts the code audit findings into implementation-ready work. Items are intentionally PR-sized, ordered by impact and risk reduction, and aligned to Laravel 12 conventions.

## Delivery Rules
- Keep PRs small and vertically complete.
- Prefer framework-native Laravel patterns over custom abstractions.
- Preserve behavior unless explicitly marked as a breaking change.
- For each PR, include focused tests that prove changed behavior.

## Global Quality Gates (every PR)
1. `vendor/bin/sail artisan test --compact <focused-tests>`
2. `vendor/bin/sail composer phpstan`
3. `vendor/bin/sail bin pint --dirty`

## Phase 0: Immediate Risk Reduction (Week 1)

### BL-001 - Fix media type validation bug in admin upload request
- Priority: P1
- Scope: `ProcessMediaRequest` enum/type handling
- Implementation:
  1. Convert incoming `type` to `MediaType::tryFrom(...)`.
  2. Use enum-aware rule generation for file validation.
  3. Remove impossible string-vs-enum comparisons.
- Acceptance criteria:
  1. Audio/video/livestream each enforce their configured max size and extension rules.
  2. Invalid type fails validation with a clear error.
- Estimated effort: S
- Dependencies: None

### BL-002 - Add request validation tests for media upload rules
- Priority: P1
- Scope: Unit/feature tests for `ProcessMediaRequest`
- Implementation:
  1. Add tests proving rule differences across all media types.
  2. Add negative tests for unsupported types.
- Acceptance criteria:
  1. Tests fail on current buggy logic and pass after BL-001.
  2. Test names clearly document intended constraints.
- Estimated effort: S
- Dependencies: BL-001

### BL-003 - Enforce formatting gate in CI and clear existing violations
- Priority: P1
- Scope: workflow + existing test-file style issues
- Implementation:
  1. Add `vendor/bin/pint --test` (or equivalent) to CI.
  2. Fix the current 7 reported style violations in tests.
- Acceptance criteria:
  1. CI fails on style regressions.
  2. `pint --test` passes cleanly in CI.
- Estimated effort: S
- Dependencies: None

## Phase 1: Authorization and Routing Standardization (Weeks 1-2)

### BL-004 - Replace email-domain Gate bypass with explicit authorization model
- Priority: P1
- Scope: `AuthServiceProvider`, policies, middleware
- Implementation:
  1. Remove broad `Gate::before` domain super-bypass.
  2. Keep admin authorization explicit (`is_admin`/ability/policy).
  3. Introduce a dedicated, testable super-admin mechanism only if required.
- Acceptance criteria:
  1. No implicit full-access authorization based on email suffix.
  2. All protected routes/actions still have intentional access control.
  3. Authorization test coverage expanded for admin/non-admin/verified states.
- Estimated effort: M
- Dependencies: None

### BL-005 - Normalize admin route surface to `/admin`
- Priority: P1
- Scope: route definitions and legacy redirects
- Implementation:
  1. Make `/admin/*` the canonical admin UI and action surface.
  2. Convert legacy admin paths to redirect-only compatibility layer.
  3. Remove duplicate admin route ownership across areas.
- Acceptance criteria:
  1. One canonical path per admin action.
  2. Legacy links continue to work via redirects during transition.
  3. Route tests cover canonical + redirect behavior.
- Estimated effort: M
- Dependencies: BL-004

### BL-006 - Standardize route names and controller action naming
- Priority: P2
- Scope: sermons/public routes and references
- Implementation:
  1. Rename mixed-style route names to Laravel-standard dotted names.
  2. Rename non-standard controller method names (`getSerieses`, etc.) to conventional action names.
  3. Maintain temporary aliases where needed to avoid hard breaks.
- Acceptance criteria:
  1. Route list follows consistent naming scheme.
  2. No broken links in templates/tests.
  3. Deprecated aliases documented and scheduled for removal.
- Estimated effort: M
- Dependencies: BL-005

## Phase 2: Service Boundaries and Codebase Simplification (Weeks 2-4)

### BL-007 - Extract transcript storage resolution into a dedicated service
- Priority: P2
- Scope: duplicated transcript disk/path logic
- Implementation:
  1. Introduce `TranscriptStorageService` for read/write/fallback disk resolution.
  2. Replace duplicated disk candidate logic in model/jobs.
  3. Add targeted unit tests for fallback order and failure handling.
- Acceptance criteria:
  1. No duplicated `getTranscriptReadDisks()` logic remains.
  2. Transcript read behavior remains unchanged and covered by tests.
- Estimated effort: M
- Dependencies: None

### BL-008 - Centralize sermon series query logic
- Priority: P2
- Scope: repository, jobs, Livewire listing filters
- Implementation:
  1. Consolidate distinct-series retrieval into one reusable service/repository API.
  2. Replace direct duplicated query blocks in jobs/components.
  3. Add caching policy in one place only.
- Acceptance criteria:
  1. One authoritative series-retrieval implementation.
  2. Existing filters and AI analysis prompts still work.
- Estimated effort: S
- Dependencies: None

### BL-009 - Decompose `Sermon` model responsibilities
- Priority: P2
- Scope: model methods for storage URL, sitemap, transcript I/O, metadata assembly
- Implementation:
  1. Move non-ORM responsibilities to dedicated services/presenters.
  2. Keep model focused on relationships, casts, scopes, and light accessors.
  3. Update callers incrementally to reduce blast radius.
- Acceptance criteria:
  1. `Sermon` class size and responsibility footprint reduced.
  2. Behavior parity maintained via tests.
- Estimated effort: L
- Dependencies: BL-007, BL-008

### BL-010 - Simplify media processing job constructors and compatibility layers
- Priority: P2
- Scope: especially `GenerateThumbnail` and pipeline jobs
- Implementation:
  1. Standardize job payload contracts for pipeline-driven execution.
  2. Remove or isolate legacy variadic constructor patterns behind adapters.
  3. Keep backward compatibility via a short-lived compatibility dispatch layer.
- Acceptance criteria:
  1. Jobs have explicit constructor signatures.
  2. Queue pipeline remains functionally equivalent.
  3. Old entry points still pass until deprecation removal PR.
- Estimated effort: L
- Dependencies: BL-009

## Phase 3: Provider and Config Modernization (Weeks 4-5)

### BL-011 - Split `AppServiceProvider` concerns by domain
- Priority: P3
- Scope: provider composition and boot logic placement
- Implementation:
  1. Move rate limiter definitions to a dedicated provider/bootstrap section.
  2. Consolidate duplicate header view composer registration.
  3. Keep observer registration in a clear, single location.
- Acceptance criteria:
  1. `AppServiceProvider` no longer acts as a catch-all.
  2. Provider responsibilities are clearly separated and discoverable.
- Estimated effort: S
- Dependencies: None

### BL-012 - Remove config drift and backward-compat key ambiguity
- Priority: P3
- Scope: bootstrap + config fallbacks
- Implementation:
  1. Replace direct `env()` usage in bootstrap with config-backed settings.
  2. Standardize on one canonical config key per concern (remove dual key fallbacks).
  3. Add config-level tests for resolved values.
- Acceptance criteria:
  1. No runtime `env()` lookups outside config files.
  2. Config keys are singular and documented in code comments.
- Estimated effort: M
- Dependencies: BL-011

### BL-013 - Increase strict typing coverage in app code
- Priority: P3
- Scope: add `declare(strict_types=1)` and tighten signatures incrementally
- Implementation:
  1. Apply strict types to high-churn files first (controllers/requests/services touched by previous items).
  2. Fix resulting coercion edge cases and type declarations.
- Acceptance criteria:
  1. Strict-typed file count materially increases from current baseline.
  2. No behavior regressions in tests.
- Estimated effort: M
- Dependencies: BL-001 through BL-012 (incremental)

## Phase 4: Database and Repo Hygiene (Week 5)

### BL-014 - Introduce schema baseline and migration lifecycle policy
- Priority: P3
- Scope: long migration chain with corrective patches
- Implementation:
  1. Generate and commit a schema dump for faster, cleaner bootstrapping.
  2. Document policy for future corrective migrations (forward-only, minimal, reversible where possible).
  3. Identify candidates for archival/pruning strategy (without breaking deploy safety).
- Acceptance criteria:
  1. New environments can bootstrap reliably from schema baseline.
  2. Migration strategy reduces future drift cleanup work.
- Estimated effort: M
- Dependencies: None

### BL-015 - Repository hygiene cleanup
- Priority: P3
- Scope: remove tracked OS/editor artifacts and stale folder clutter
- Implementation:
  1. Remove committed `.DS_Store` files.
  2. Ensure `.gitignore` protects against reintroduction.
  3. Remove/justify stray directories like `app/app`.
- Acceptance criteria:
  1. No OS artifact files tracked in git.
  2. Directory structure is intentional and minimal.
- Estimated effort: S
- Dependencies: None

## Recommended PR Order
1. BL-001
2. BL-002
3. BL-003
4. BL-004
5. BL-005
6. BL-006
7. BL-007
8. BL-008
9. BL-009
10. BL-010
11. BL-011
12. BL-012
13. BL-013
14. BL-014
15. BL-015

## Milestone Exit Criteria
- M1 (Security and correctness): BL-001 to BL-005 complete.
- M2 (Structural simplification): BL-006 to BL-010 complete.
- M3 (Framework alignment): BL-011 to BL-013 complete.
- M4 (Operational hygiene): BL-014 to BL-015 complete.
