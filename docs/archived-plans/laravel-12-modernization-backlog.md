> **Archived 2026-07-05.** Written for Laravel 12 (Feb 2026); the project is now on Laravel 13. Item statuses were never reconciled after the June 2026 review implementation and testing remediation landed much of this work. **Do not work from this file** — use `docs/plans/README.md` for current trackers.

# Laravel 12 Modernization Backlog

_Last updated: 2026-02-28_

## Reassessment Summary
This backlog has been revalidated against the current codebase after recent feature growth (church services, song catalog, preacher workflows, and expanded admin Livewire surface).

Key outcomes:
- The original backlog is still directionally correct.
- Most high-risk items remain open or partially complete.
- New priority work is needed for schema freshness CI and authorization coverage on newer admin domains.

## Status Legend
- `Open`: no meaningful implementation yet.
- `Partial`: implementation exists, but acceptance criteria are not met.
- `Adjusted`: scope/priority changed based on current code state.

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
- Status: `Open`
- Priority: P1
- Scope: `ProcessMediaRequest` enum/type handling
- Implementation:
  1. Convert incoming `type` to `MediaType::tryFrom(...)`.
  2. Pass `MediaType` to `MediaValidationService::rulesForType(...)`.
  3. Remove impossible string-vs-enum comparisons in rules/messages.
- Acceptance criteria:
  1. Audio/video/livestream each enforce their configured max size and extension rules.
  2. Invalid type fails validation with a clear error.
- Estimated effort: S
- Dependencies: None

### BL-002 - Add request validation tests for media upload rules
- Status: `Open`
- Priority: P1
- Scope: Unit/feature tests for `ProcessMediaRequest`
- Implementation:
  1. Add tests proving rule differences across all media types.
  2. Add negative tests for unsupported types.
  3. Add tests for type-specific validation messages.
- Acceptance criteria:
  1. Tests fail on current buggy logic and pass after BL-001.
  2. Test names clearly document intended constraints.
- Estimated effort: S
- Dependencies: BL-001

### BL-003 - Enforce formatting gate in CI and clear existing violations
- Status: `Partial` (local `pint --test` reports 7 style violations; CI gate missing)
- Priority: P1
- Scope: workflow + existing test-file style issues
- Implementation:
  1. Add `vendor/bin/pint --test` to CI.
  2. Fix currently failing style issues in tests.
- Acceptance criteria:
  1. CI fails on style regressions.
  2. `pint --test` passes cleanly in CI.
- Estimated effort: S
- Dependencies: None

### BL-004 - Replace email-domain Gate bypass with explicit authorization model
- Status: `Open`
- Priority: P1
- Scope: `AuthServiceProvider`, policies, middleware
- Implementation:
  1. Remove broad `Gate::before` email-domain super-bypass.
  2. Keep admin authorization explicit (`is_admin`/policy/ability).
  3. Keep verified-email checks explicit where required.
- Acceptance criteria:
  1. No implicit full-access authorization based on email suffix.
  2. All protected routes/actions still have intentional access control.
  3. Authorization test coverage expanded for admin/non-admin/verified states.
- Estimated effort: M
- Dependencies: None

### BL-016 - Refresh schema baseline and add schema freshness CI guard
- Status: `Open` (new)
- Priority: P1
- Scope: `database/schema/mysql-schema.sql`, CI workflow
- Implementation:
  1. Regenerate schema dump so it includes all migrations through current head.
  2. Add CI check that fails when migrations exist beyond the schema dump baseline.
  3. Document refresh command and required cadence in code comments/workflow step.
- Acceptance criteria:
  1. Schema dump includes latest migration set.
  2. CI fails when new migration files are added without a refreshed schema dump.
  3. New environments bootstrap reliably from current baseline.
- Estimated effort: S
- Dependencies: None

## Phase 1: Authorization and Routing Surface Consolidation (Weeks 1-2)

### BL-005 - Normalize admin route surface to `/admin`
- Status: `Partial` (`/admin/*` exists; key actions still canonical under `/church/members/*`)
- Priority: P1
- Scope: route definitions and legacy redirects
- Implementation:
  1. Make `/admin/*` the canonical admin UI and action surface, including sermon upload and calendar admin actions.
  2. Convert `/church/members/*` admin paths to redirect-only compatibility layer.
  3. Keep `memberHome` as a dashboard if needed, but remove route ownership duplication.
- Acceptance criteria:
  1. One canonical path per admin action.
  2. Legacy links continue to work via redirects during transition.
  3. Route tests cover canonical + redirect behavior.
- Estimated effort: M
- Dependencies: BL-004

### BL-017 - Finish legacy admin route migration and member dashboard linkage
- Status: `Open` (new)
- Priority: P1
- Scope: `routes/web.php`, member/admin templates, tests
- Implementation:
  1. Migrate remaining legacy action links (`admin.sermon-upload.*`, `admin.calendar.*`) to new `/admin/*` canonical names.
  2. Update member dashboard links/forms to canonical route names.
  3. Ensure `/admin` default destination is intentional and test-covered.
- Acceptance criteria:
  1. Member/admin templates no longer depend on legacy action routes.
  2. Canonical route names are used across views/components/tests.
  3. Redirect compatibility remains explicit and temporary.
- Estimated effort: M
- Dependencies: BL-005

### BL-018 - Expand explicit policy coverage for new admin domains
- Status: `Open` (new)
- Priority: P1
- Scope: policies/abilities for church services, songs, service sections, preachers
- Implementation:
  1. Add policies (or explicit abilities) for newer admin-managed models/domains.
  2. Replace undefined legacy ability checks in templates (`@can('edit-*')`) with explicit policy/ability checks.
  3. Add unit/feature tests proving admin/non-admin/verified behavior across new domains.
- Acceptance criteria:
  1. Authorization does not rely on implicit gate fallthrough.
  2. No hidden coupling to deprecated `Gate::before` behavior.
  3. New admin domains have explicit, testable authorization contracts.
- Estimated effort: M
- Dependencies: BL-004

### BL-006 - Standardize route names and controller action naming
- Status: `Open`
- Priority: P2
- Scope: sermons/public routes and references
- Implementation:
  1. Rename mixed-style route names to Laravel-standard dotted names.
  2. Rename non-standard controller method names (`getSerieses`, etc.) to conventional action names.
  3. Maintain temporary aliases where needed to avoid hard breaks.
- Acceptance criteria:
  1. Route list follows consistent naming scheme.
  2. No broken links in templates/tests.
  3. Deprecated aliases are explicitly marked and scheduled for removal.
- Estimated effort: M
- Dependencies: BL-005, BL-017

## Phase 2: Service Boundaries and Codebase Simplification (Weeks 2-4)

### BL-007 - Complete transcript storage resolution centralization
- Status: `Partial` (`TranscriptStorageService` exists; read/fallback logic still duplicated in model/jobs)
- Priority: P2
- Scope: duplicated transcript disk/path logic
- Implementation:
  1. Move transcript read-disk fallback logic into `TranscriptStorageService`.
  2. Replace duplicated logic in `Sermon` model and transcript-processing jobs.
  3. Add targeted tests for fallback order and failure handling.
- Acceptance criteria:
  1. No duplicated `getTranscriptReadDisks()` logic remains.
  2. Transcript read behavior remains unchanged and covered by tests.
- Estimated effort: M
- Dependencies: None

### BL-008 - Centralize sermon series query logic
- Status: `Partial` (`SermonRepository` exists but duplicate query blocks remain)
- Priority: P2
- Scope: repository, jobs, Livewire listing filters
- Implementation:
  1. Keep one authoritative distinct-series retrieval API.
  2. Replace direct duplicated query blocks in jobs/components/controllers.
  3. Keep cache policy in one location.
- Acceptance criteria:
  1. One authoritative series-retrieval implementation.
  2. Existing filters and AI analysis prompts still work.
- Estimated effort: S
- Dependencies: None

### BL-009 - Decompose `Sermon` model responsibilities
- Status: `Open` (model remains large and cross-concern)
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
- Status: `Partial` (legacy variadic constructor still active in `GenerateThumbnail`)
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
- Status: `Partial` (some responsibilities still centralized)
- Priority: P3
- Scope: provider composition and boot logic placement
- Implementation:
  1. Move rate limiter definitions to dedicated provider/bootstrap section.
  2. Consolidate duplicate header composer registration.
  3. Keep observer registration in one clear location.
- Acceptance criteria:
  1. `AppServiceProvider` no longer acts as a catch-all.
  2. Provider responsibilities are clearly separated and discoverable.
- Estimated effort: S
- Dependencies: None

### BL-012 - Remove config drift and backward-compat key ambiguity
- Status: `Partial` (`service` and `service_type` dual key fallback still present; runtime `env()` remains in bootstrap)
- Priority: P3
- Scope: bootstrap + config fallbacks
- Implementation:
  1. Replace direct `env()` usage in bootstrap with config-backed settings.
  2. Standardize one canonical config key per concern and remove dual-key fallback.
  3. Add config-level tests for resolved values.
- Acceptance criteria:
  1. No runtime `env()` lookups outside config files.
  2. Config keys are singular and documented in code comments.
- Estimated effort: M
- Dependencies: BL-011

### BL-013 - Increase strict typing coverage in app code
- Status: `Partial` (baseline approx. 132/254 app PHP files strict-typed)
- Priority: P3
- Scope: add `declare(strict_types=1)` and tighten signatures incrementally
- Implementation:
  1. Apply strict types to high-churn and high-LOC files first.
  2. Fix coercion edge cases and type declarations.
  3. Track strict-typed coverage per PR.
- Acceptance criteria:
  1. Strict-typed file count materially increases from current baseline.
  2. No behavior regressions in tests.
- Estimated effort: M
- Dependencies: BL-001 through BL-012 (incremental)

## Phase 4: Database Lifecycle and Repo Hygiene (Week 5)

### BL-014 - Define migration lifecycle policy after schema baseline stabilization
- Status: `Adjusted` (schema dump exists but freshness has drifted; policy still missing)
- Priority: P2
- Scope: migration lifecycle policy and long chain maintenance
- Implementation:
  1. Document forward-only migration policy for future corrective changes.
  2. Define when/where to use schema dumps vs full migration replay in CI.
  3. Identify archival/pruning strategy candidates without reducing deploy safety.
- Acceptance criteria:
  1. Migration policy is explicit and enforceable.
  2. Team has a consistent, low-drift migration workflow.
- Estimated effort: M
- Dependencies: BL-016

### BL-015 - Repository hygiene cleanup
- Status: `Partial` (`.DS_Store` tracking risk addressed; stale folder clutter remains)
- Priority: P3
- Scope: remove stale folder clutter and keep ignores intentional
- Implementation:
  1. Remove/justify stray directories like `app/app`.
  2. Keep `.gitignore` aligned to current tooling/output.
  3. Add a lightweight CI or pre-commit check for known junk patterns if needed.
- Acceptance criteria:
  1. Directory structure is intentional and minimal.
  2. Hygiene regressions are prevented automatically.
- Estimated effort: S
- Dependencies: None

## Recommended PR Order
1. BL-001
2. BL-002
3. BL-003
4. BL-004
5. BL-018
6. BL-005
7. BL-017
8. BL-006
9. BL-016
10. BL-007
11. BL-008
12. BL-009
13. BL-010
14. BL-011
15. BL-012
16. BL-013
17. BL-014
18. BL-015

## Milestone Exit Criteria
- M1 (Security and correctness): BL-001 to BL-005 complete.
- M2 (Authorization and route consolidation): BL-017, BL-018, BL-006 complete.
- M3 (Structural simplification): BL-007 to BL-010 complete.
- M4 (Framework alignment): BL-011 to BL-013 complete.
- M5 (Operational hygiene): BL-014 to BL-016 complete, BL-015 complete.
