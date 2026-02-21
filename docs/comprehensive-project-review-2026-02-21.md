# Comprehensive Project Review

Date: February 21, 2026  
Project: Crockenhill Laravel 12 (TALL stack)

## Executive Summary

The codebase has strong foundations (Laravel 12 conventions in many areas, broad automated test coverage, passing static analysis, and meaningful security hardening), but maintainability risk is high due to architectural sprawl and incomplete migration away from legacy patterns.

The largest risks to simplicity and long-term reliability are:

1. Queue routing mismatch for livestream-audio jobs (can strand production jobs).
2. Over-centralized presentation logic in `ViewServiceProvider` (382 lines, mixed concerns, repeated query patterns).
3. Incomplete service refactor around media processing (duplicated orchestration and retry logic).
4. Mixed frontend architecture (Tailwind + legacy SCSS + large inline Alpine logic + large Livewire component classes).
5. Stale code and legacy artifacts (dead container bindings, outdated Filament references/assets, backup files, placeholder health-check internals).

Overall assessment:

- Runtime correctness: **Good**, with a few concrete correctness bugs.
- Security posture: **Improved**, with a few hardening inconsistencies.
- Simplicity/maintainability: **Needs focused refactor cycles**.
- TALL alignment: **Moderate** (good adoption, but uneven execution).

## Scope and Method

This review covered architecture, code quality, maintainability, and TALL best-practice alignment across routing, providers, controllers, Livewire components, services, models, frontend assets, and deployment configuration.

Verification commands run via Sail:

1. `./vendor/bin/sail composer phpstan` -> **0 errors**.
2. `./vendor/bin/sail artisan test --parallel --compact` -> **OK (1579 tests, 4590 assertions)**.
3. `./vendor/bin/sail bin pint --dirty` -> **PASS (no changes required)**.

## Current Strengths

1. Media-processing API access control is significantly improved:
   - `app/Http/Middleware/EnsureMediaProcessingAccess.php:21-33`
   - `routes/api.php:30-36,42-47,51-56,60-65`
2. Processing log visibility has owner-scoped access for non-admin users:
   - `app/Models/MediaProcessingLog.php:190-197`
   - `app/Services/UnifiedMediaProcessor.php:169-176`
3. Safe markdown rendering is centralized:
   - `app/Services/SafeMarkdownRenderer.php:14-17`
   - `config/markdown.php:13-16`
4. Quality gates are already part of day-to-day workflow and are currently green.

## Findings (Prioritized)

### Critical

#### C1. Queue mismatch can leave livestream-related job chains unprocessed

Evidence:

- Livestream audio chains are dispatched to `livestream-audio`:
  - `app/Services/SermonAudioProcessingService.php:318,335`
  - `app/Services/SermonJobPipelineService.php:36,53`
- Worker queues do **not** include `livestream-audio`:
  - `docker/production/supervisord.conf:30`
  - `docker-compose.yml:48`

Impact:

- Real production jobs can remain queued indefinitely.
- Operational failures present as "stuck processing" instead of explicit failures.

Recommendation:

1. Standardize queue names in config and code (`config/media-processing.php` as single source).
2. Add startup checks/tests validating dispatched queues are consumed by workers.
3. Add alerting for queue age/length per queue.

---

### High

#### H1. Concrete photo-loading bug in meeting controller

Evidence:

- `glob` pattern is malformed for brace expansion:
  - `app/Http/Controllers/MeetingController.php:82-86`
  - Current pattern builds `*.jpg,jpeg,png,...` instead of `*.{jpg,jpeg,png,...}`.

Impact:

- Meeting photo discovery can silently fail.

Recommendation:

1. Fix the glob pattern immediately.
2. Prefer using `Meeting::getPhotosAttribute()` instead of duplicate filesystem scanning in controller:
   - `app/Models/Meeting.php:365-409`

#### H2. View composition is over-centralized and brittle

Evidence:

- Single provider contains broad routing/page assembly concerns:
  - `app/Providers/ViewServiceProvider.php` (382 lines)
- Route-segment branching and repeated query logic:
  - `app/Providers/ViewServiceProvider.php:103-334`
- Full table scans for pages in composers:
  - `app/Providers/ViewServiceProvider.php:352-355,374`
- Suspicious copy/paste query mismatch (`members` branch querying `sermons` area):
  - `app/Providers/ViewServiceProvider.php:215-216,269-270`

Impact:

- High cognitive load and regression risk for any page-related change.
- Hard to test reliably; hidden coupling to URL structure.

Recommendation:

1. Split into dedicated composers/view models per page type.
2. Move data selection into controllers/services with explicit contracts.
3. Introduce query objects or small page-data assemblers.

#### H3. Partial refactor left duplicated orchestration logic

Evidence:

- Duplicate retry/manual-review heuristics in two services:
  - `app/Services/SermonJobPipelineService.php:153-218`
  - `app/Services/SermonStatusManagementService.php:268-333`
- Duplicate queue routing logic:
  - `app/Services/SermonJobPipelineService.php:35-37`
  - `app/Services/SermonAudioProcessingService.php:317-319`

Impact:

- Inconsistent behavior over time and difficult bug fixing.

Recommendation:

1. Create one canonical orchestration service for processing state transitions.
2. Keep domain rules (retry/manual-review) in one policy-like class.
3. Keep dispatch concerns and status concerns separated but not duplicated.

#### H4. Frontend architecture is fragmented (TALL misalignment)

Evidence:

- Tailwind and legacy SCSS systems are mixed in one pipeline:
  - `resources/css/app.scss:1-13`
  - `resources/css/cbc/_home.scss` (large legacy partial)
- Very large Livewire component and heavy client logic in blade:
  - `app/Livewire/MediaUpload.php` (558 lines)
  - `resources/views/livewire/media-upload.blade.php:1-125`
- Comment/behavior drift in upload throttling:
  - `resources/views/livewire/media-upload.blade.php:7,62`

Impact:

- Slower onboarding, harder debugging, blurred server/client state boundaries.

Recommendation:

1. Split `MediaUpload` into smaller Livewire components (form, progress, status).
2. Move complex Alpine logic into dedicated JS modules and keep blade declarative.
3. Define a migration plan from legacy SCSS to Tailwind component patterns.

---

### Medium

#### M1. Dead container binding references missing class

Evidence:

- Binding references non-existent class:
  - `app/Providers/AppServiceProvider.php:128-131`
- No implementation exists in `app/Services`.

Impact:

- Latent runtime failure if resolved; unnecessary confusion for maintainers.

Recommendation:

1. Remove obsolete binding.
2. Add a small provider test/assertion for core bindings.

#### M2. Health checks/troubleshooting return placeholders in production path

Evidence:

- Log extraction explicitly placeholder:
  - `app/Services/SermonStatusManagementService.php:340-349`
- Queue health always reports healthy with note:
  - `app/Services/SermonStatusManagementService.php:448-457`

Impact:

- Operational diagnosis can be misleading during incidents.

Recommendation:

1. Replace placeholder responses with real queue metrics and failed-job signals.
2. Integrate structured log lookup (or remove the feature until implemented).

#### M3. Asset versioning uses non-deterministic timestamps

Evidence:

- Build ID seeded from `Date.now()` and appended to all filenames:
  - `vite.config.mjs:5,24-30`

Impact:

- Cache busts every build regardless of content changes.
- Harder reproducible builds and less efficient CDN caching.

Recommendation:

1. Remove timestamp suffix and rely on content hash.
2. Keep deterministic outputs for stable deploys and rollback analysis.

#### M4. Security/hardening defaults are inconsistent

Evidence:

- Proxy trust set to wildcard:
  - `bootstrap/app.php:20`
- Custom CORS middleware fallback behavior and credentialed responses:
  - `app/Http/Middleware/HandleCors.php:22,33,43-46,63`
- CORS middleware applied to media upload route but not status/cancel/retry routes:
  - `routes/api.php:30-36` vs `routes/api.php:42-47,51-56,60-65`

Impact:

- Harder to reason about request trust and cross-origin behavior.
- Potential misconfiguration risk in production environments.

Recommendation:

1. Restrict trusted proxies to known infrastructure.
2. Move to standard Laravel CORS config/middleware unless custom behavior is essential.
3. Keep CORS policy consistent across related API endpoints.

#### M5. Timezone choice may cause schedule drift for UK-local operations

Evidence:

- App timezone is `GMT`:
  - `config/app.php:66`
- Scheduler tasks rely on app timezone:
  - `bootstrap/app.php:15-18`

Impact:

- If business intent is UK local time, DST (BST) behavior may not match expectations.

Recommendation:

1. If operational intent is local UK time, set `Europe/London`.
2. Add tests/assertions around schedule timing assumptions.

#### M6. Legacy/stale artifacts are creating architectural noise

Evidence:

- Filament references in comments even though Filament packages are not present:
  - `routes/web.php:163-164`
  - `app/Http/Controllers/PageController.php:15-16`
  - no `filament/*` packages in `composer.json`/`composer.lock`
- 53 tracked Filament vendor override blade files under `resources/views/vendor/filament-panels/`.
- Backup template tracked in repo:
  - `resources/views/sermons/sermon.blade.php.backup`

Impact:

- Confusing system boundaries and upgrade/migration uncertainty.

Recommendation:

1. Remove stale comments/assets not part of active runtime architecture.
2. Archive migration remnants in a separate branch or docs appendix.
3. Enforce a "no backup/generated artifacts" policy in git.

#### M7. Type-safety and signature consistency are uneven

Evidence:

- `app` contains 211 PHP files; 55 declare strict types (156 do not).
- Example older components lack strict types/explicit typing:
  - `app/Livewire/Auth/Register.php:1,26,41`
  - `app/Livewire/Auth/VerifyEmail.php:10`

Impact:

- Inconsistent coding model and increased runtime/type ambiguity.

Recommendation:

1. Add strict types incrementally per module.
2. Enforce explicit return/property types for touched files.
3. Add CI rule for new/modified files to meet typing baseline.

#### M8. Livewire test coverage still has blind spots

Evidence:

- Components with no direct test references include:
  - `app/Livewire/Admin/Meetings/ListMeetings.php`
  - `app/Livewire/Admin/Meetings/CreateMeeting.php`
  - `app/Livewire/Admin/Meetings/EditMeeting.php`
  - `app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php`
  - `app/Livewire/Admin/CalendarEvents/EditCalendarEvent.php`
  - `app/Livewire/Admin/Pages/CreatePage.php`
  - `app/Livewire/Admin/Pages/EditPage.php`

Impact:

- Refactors in admin flows risk behavior regressions without fast detection.

Recommendation:

1. Add focused Livewire tests for untested admin CRUD components.
2. Prioritize components with most business impact and highest churn.

---

### Low

#### L1. Duplicate file existence check in `Page::hasImage()`

Evidence:

- Duplicate `.webp` check appears twice:
  - `app/Models/Page.php:237-238`

Impact:

- Likely intended jpg/webp fallback was missed; minor correctness issue.

Recommendation:

1. Replace duplicate check with intended fallback (e.g., jpg).
2. Add a unit test for legacy heading-image fallback behavior.

#### L2. Minor repo hygiene issues

Evidence:

- Temporary/commentary artifacts in core files:
  - `routes/web.php:8` (`// Added this line`)

Impact:

- Low direct risk, but contributes to long-term maintainability drift.

Recommendation:

1. Remove transient comments during routine cleanup.
2. Keep core framework files concise and intention-revealing.

## TALL Alignment Review

### Laravel

- Good: modern bootstrap usage (`bootstrap/app.php`) and broad use of policies/gates.
- Needs work: large providers/services with mixed responsibilities reduce Laravel convention clarity.

### Livewire

- Good: substantial Livewire adoption in admin and upload flows.
- Needs work: component size and responsibility boundaries (e.g., `MediaUpload`) should be reduced.

### Alpine

- Good: used for UI interaction where needed.
- Needs work: very large inline `x-data` blobs should become module-backed and testable.

### Tailwind

- Good: Tailwind is active and used across many views.
- Needs work: legacy SCSS system remains heavily coupled, causing a mixed styling model.

## Recommended Roadmap

### 0-2 Weeks (Quick Wins)

1. Fix queue mismatch for `livestream-audio`.
2. Fix meeting photo glob bug.
3. Remove dead `Registrar` binding.
4. Fix `Page::hasImage()` duplicate fallback check.
5. Remove backup file and stale "Added this line" comments.

### 2-6 Weeks (Stabilization)

1. Break `ViewServiceProvider` into dedicated composers/view models.
2. Consolidate processing retry/manual-review logic into one canonical service.
3. Replace placeholder health-check internals with real metrics.
4. Make Vite output deterministic (drop timestamp suffix).
5. Add missing Livewire tests for meetings/calendar/pages admin components.

### 6-12 Weeks (Architecture Simplification)

1. Refactor `MediaUpload` into smaller Livewire components + isolated JS modules.
2. Establish a formal legacy-retirement plan for SCSS and old image fallbacks.
3. Tighten proxy/CORS/session/timezone operational defaults with environment-specific config.
4. Introduce module-level architecture boundaries for media processing (ingest, orchestration, status, recovery).

## Suggested Engineering Standards Going Forward

1. New/changed files must use strict types and explicit return types.
2. No new controller/provider methods > ~80 lines without extraction rationale.
3. One authoritative implementation per domain rule (no duplicated retry/status policy logic).
4. No stale framework references (e.g., Filament) unless runtime dependency exists.
5. Keep quality gates mandatory: tests, phpstan, pint.

## Final Note

The project is in a healthy operational state today, but maintainability pressure is clearly accumulating in a few hotspots. Addressing the critical queue issue and then systematically reducing architectural concentration (providers/services/components) will produce the highest return for reliability and developer velocity.
