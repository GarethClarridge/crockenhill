# Simplification Plan (2026-02-25) — Remaining Work

The full mixed-status implementation log now lives in [docs/archived-plans/SIMPLIFICATION-PLAN-2026-02-25.md](/Users/garethclarridge/Projects/crockenhill/docs/archived-plans/SIMPLIFICATION-PLAN-2026-02-25.md).

This file tracks only the unfinished phases.

## Scope

- Finish the remaining simplification work without reintroducing already-removed abstractions.
- Keep behavior stable while reducing the remaining hotspots in validation, legacy fallbacks, calendar/cache handling, schema hygiene, and large service complexity.

## Quality Gates

- `vendor/bin/sail artisan test --compact <focused test paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`

## Remaining Phases

### Phase 3: Validation Rule Convergence (P2)

Target files:

- `app/Services/MediaValidationService.php`
- `app/Http/Requests/ProcessMediaRequest.php`
- `app/Jobs/ValidateAudioFile.php`
- `app/Jobs/ValidateVideoFile.php`
- `app/Services/SermonValidationService.php`
- `app/Services/SermonAudioProcessingService.php`

Tasks:

- [ ] Make `MediaValidationService` the canonical source for size/mime/extensions per media type.
- [ ] Replace duplicated hard-coded rules in jobs/services.
- [ ] Remove outdated `media-processing.processing.*` fallbacks where canonical `types.*` config exists.

Exit criteria:

- Validation inputs and error behavior come from one source of truth.

### Phase 8: Route/Model Responsibility Cleanup (P3)

Target files:

- `routes/web.php`
- `app/Http/Controllers/SermonController.php`
- `app/Http/Controllers/Admin/SermonAdminController.php`
- `app/Models/Sermon.php`

Tasks:

- [ ] Reduce overlapping route styles and wrapper endpoints where possible.
- [ ] Move presentation/SEO/podcast formatting concerns out of `Sermon` model into presenters/resources.

Exit criteria:

- Thinner model responsibilities and clearer route/controller boundaries.

### Phase 9: Legacy Fallback Retirement Across Content/Media (P1/P2)

Remaining target files:

- `app/Services/SermonStorageService.php`
- supporting migration/verification commands in `app/Console/Commands/*`

Tasks:

- [ ] Add a migration path that canonicalises legacy sermon storage records and validates resolved `do_spaces` file locations.
- [ ] Retire the remaining `SermonStorageService` legacy pattern branch once migrated.
- [ ] Remove `MigrateSermonStorageCommand.php` and `VerifySermonStorageCommand.php` only after the migration path is complete and verified.

Exit criteria:

- Runtime no longer branches on legacy sermon storage/path formats.

### Phase 12: Calendar Integration and Cache Invalidation Cleanup (P2/P3)

Target files:

- `app/Http/Controllers/Admin/CalendarAdminController.php`
- `app/Services/CalendarService.php`
- calendar sync/cache-related tests

Tasks:

- [ ] Move `CalendarAdminController` from `app(...)` service resolution to constructor injection.
- [ ] Split Google Calendar API integration concerns from categorization/business rules in `CalendarService`.
- [ ] Replace wildcard-style cache invalidation and global `Cache::flush()` with deterministic key/tag invalidation.
- [ ] Add focused tests for cache invalidation and sync error/retry behavior.

Exit criteria:

- Calendar cache invalidation is explicit/safe, and calendar controller/service boundaries are thin and testable.

### Phase 13: Schema Snapshot and Migration Hygiene (P2/P3)

Target files:

- `database/schema/mysql-schema.sql`
- `database/migrations/*`
- CI checks/scripts that rely on schema snapshots

Tasks:

- [ ] Decide one approach and enforce it: keep schema dump current, or remove schema dump usage.
- [ ] If retained, regenerate schema snapshot after cleanup migrations and add guardrails to detect drift.
- [ ] Remove stale assumptions in tooling/tests that rely on dropped legacy tables.

Exit criteria:

- Database bootstrap path is deterministic and aligned with the current migration history.

### Phase 14: Complexity Hotspot Decomposition (P3)

Target files:

- `app/Services/ThumbnailGenerationService.php`
- `app/Services/AudioTranscriptionService.php`
- `app/Services/SermonAnalysisService.php`
- `app/Services/MetadataExtractionService.php`

Tasks:

- [ ] Split mixed-responsibility services into smaller collaborators (orchestrator + focused workers).
- [ ] Isolate pure transformation logic from IO-heavy concerns.
- [ ] Keep public service APIs stable while reducing internal method sprawl and cross-cutting concerns.

Exit criteria:

- Hotspot services are decomposed into focused units with lower cognitive load and clearer test seams.

## Suggested Order

1. Phase 3
2. Phase 9
3. Phase 8
4. Phase 12
5. Phase 13
6. Phase 14

## Definition of Done

- [ ] Validation is centralized and consistent.
- [ ] Sermon legacy storage fallbacks are retired after migration completion.
- [ ] Sermon route/controller/model boundaries are cleaner and thinner.
- [ ] Calendar cache invalidation is deterministic and does not use global flush.
- [ ] Schema snapshot strategy is consistent and drift-free.
- [ ] Remaining hotspot services are decomposed with focused tests.
- [ ] All required quality gates pass for each delivered phase.
