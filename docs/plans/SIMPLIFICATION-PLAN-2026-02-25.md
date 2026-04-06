# Simplification Plan (2026-02-25) — Remaining Work

Updated 2026-04-06 after a codebase review against the current app state.

The historical mixed-status execution log still lives in [docs/archived-plans/SIMPLIFICATION-PLAN-2026-02-25.md](../archived-plans/SIMPLIFICATION-PLAN-2026-02-25.md).

This file now tracks only work that still appears necessary.

## Scope

- finish the remaining simplification work without reintroducing removed abstractions
- keep behavior stable while reducing the current hotspots in validation, sermon storage compatibility, schema hygiene, service boundaries, and oversized services

## Quality Gates

- `vendor/bin/sail artisan test --compact <focused test paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`

## Remaining Phases

### Phase 3: Validation Rule Convergence (Re-Scoped)

Status note:

- `MediaValidationService` is already the canonical source for request and Livewire upload rules.
- The remaining work is the final mile where older helpers still validate with their own logic.

Target files:

- `app/Services/MediaValidationService.php`
- `app/Http/Requests/ProcessMediaRequest.php`
- `app/Livewire/Traits/WithUploadLifecycle.php`
- `app/Jobs/ValidateAudioFile.php`
- `app/Services/AudioExtractionService.php`
- `app/Services/MetadataExtractionService.php`

Tasks:

- [ ] Keep `MediaValidationService` as the single source of truth for upload limits and allowed types.
- [ ] Remove the `ValidateAudioFile -> AudioExtractionService -> MediaValidationService` wrapper hop unless it still adds real value.
- [ ] Decide whether `MetadataExtractionService::validateAudioFile()` is upload validation or quality linting.
- [ ] If it is upload validation, align it with canonical config-backed limits.
- [ ] If it is quality linting, rename and scope it clearly so it no longer looks like a second validator.

Exit criteria:

- Upload validation comes from one source of truth.
- Any remaining audio-quality checks are explicitly framed as secondary analysis, not competing validation.

### Phase 8: Sermon Route / Boundary Cleanup (Re-Scoped)

Status note:

- Presenter extraction has already started.
- This phase should now focus on the remaining blurry boundaries rather than a broad rewrite.

Target files:

- `routes/web.php`
- `app/Http/Controllers/SermonController.php`
- `app/Http/Controllers/Admin/SermonAdminController.php`
- `app/Models/Sermon.php`
- `app/Presenters/SermonViewPresenter.php`

Tasks:

- [ ] Reduce duplicate sermon delete route shapes if they no longer justify separate dated and slug-only endpoints.
- [ ] Keep presenters as the canonical place for public URL and asset URL formatting.
- [ ] Move remaining presentation-only helpers out of `Sermon` where they materially couple the model to routing, SEO, or view formatting.
- [ ] Leave domain helpers on the model when they are still legitimately model-level behavior.

Exit criteria:

- Sermon route/controller responsibilities are easier to follow.
- The `Sermon` model no longer accumulates presentation helpers just because it is convenient.

### Phase 9: Legacy Fallback Retirement Across Content / Media

Status note:

- This work is still needed.
- Runtime still branches on legacy sermon storage patterns.

Target files:

- `app/Services/SermonStorageService.php`
- `app/Services/SermonStorageMaintenanceService.php`
- `app/Console/Commands/MigrateSermonStorageCommand.php`
- `app/Console/Commands/VerifySermonStorageCommand.php`

Tasks:

- [ ] Add a record-level migration path that canonicalises legacy sermon storage rows, not just file placement.
- [ ] Validate the resolved locations on the target disk before retiring legacy runtime branches.
- [ ] Retire the remaining legacy-path logic in `SermonStorageService` once the data is canonical.
- [ ] Remove `MigrateSermonStorageCommand` and `VerifySermonStorageCommand` only after the migration path is complete and verified.

Exit criteria:

- Runtime no longer branches on legacy sermon storage/path formats.
- Legacy storage cleanup is a completed migration, not an indefinitely-supported fallback path.

### Phase 12: Calendar Boundary Cleanup (Re-Scoped)

Status note:

- `CalendarAdminController` already uses constructor injection.
- The original controller-injection task is complete and should not stay on the active plan.

Target files:

- `app/Http/Controllers/Admin/CalendarAdminController.php`
- `app/Services/CalendarService.php`
- `app/Services/GoogleCalendarSyncService.php`
- calendar sync-related tests

Tasks:

- [ ] Move Google Calendar API write concerns out of `CalendarService`.
- [ ] Keep `CalendarService` focused on local read models and local categorization behavior.
- [ ] Let `GoogleCalendarSyncService` own remote sync/update concerns.
- [ ] Add focused tests around sync failure handling and retry-safe behavior.
- [ ] Only add cache invalidation work here if calendar caching is introduced or restored as part of the refactor.

Exit criteria:

- Calendar read-model logic and Google API concerns are clearly separated.
- The active plan no longer claims unfinished cache work that does not currently exist in app code.

### Phase 13: Schema Snapshot and Migration Hygiene

Status note:

- This work is still needed.
- The checked-in schema dump has drifted from the live migrations.

Target files:

- `database/schema/mysql-schema.sql`
- `database/migrations/*`
- `app/Console/Commands/AuditSchemaGuardrailsCommand.php`
- CI or local tooling that depends on schema snapshots

Tasks:

- [ ] Decide one approach and enforce it: keep the schema dump current, or stop relying on it.
- [ ] If retained, regenerate the schema dump so it matches the current schema.
- [ ] Add guardrails that catch dump drift in normal development flow.
- [ ] Remove stale tooling assumptions tied to pre-cleanup schema shapes.

Exit criteria:

- Database bootstrap paths are deterministic.
- The schema dump, if kept, reflects the real schema rather than a partially-stale snapshot.

### Phase 14: Complexity Hotspot Decomposition (Re-Scoped)

Status note:

- Some decomposition has already happened.
- The remaining target is the truly oversized services, not a blanket split-everything exercise.

Target files:

- `app/Services/ThumbnailGenerationService.php`
- `app/Services/VideoExtractionService.php`
- `app/Services/AudioTranscriptionService.php`
- `app/Services/SermonAnalysisService.php`
- `app/Services/MetadataExtractionService.php`

Tasks:

- [ ] Continue splitting pure transformation logic from IO-heavy orchestration.
- [ ] Prefer focused collaborators over growing utility megaclasses.
- [ ] Keep public service APIs stable unless a rename materially improves the boundary.
- [ ] Add tests around any newly-extracted collaborators rather than only top-level orchestration paths.

Exit criteria:

- The biggest services have lower cognitive load and clearer seams for testing and change.

## Suggested Order

1. Phase 9
2. Phase 13
3. Phase 3
4. Phase 12
5. Phase 8
6. Phase 14

## Definition of Done

- [ ] Validation is centralized and intentionally scoped.
- [ ] Sermon legacy storage fallbacks are retired after canonical migration completion.
- [ ] Sermon route/controller/model boundaries are cleaner and thinner.
- [ ] Calendar read-model and Google sync responsibilities are clearly separated.
- [ ] Schema snapshot strategy is consistent and drift-free.
- [ ] Remaining hotspot services are decomposed with focused tests.
- [ ] Required quality gates pass for each delivered phase.
