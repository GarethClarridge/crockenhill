# Simplification Plan (2026-02-25) — Remaining Work

Updated 2026-04-09 after a codebase audit against every target file.

The historical mixed-status execution log still lives in [docs/archived-plans/SIMPLIFICATION-PLAN-2026-02-25.md](../archived-plans/SIMPLIFICATION-PLAN-2026-02-25.md).

This file tracks only work that still appears necessary.

## Scope

- finish the remaining simplification work without reintroducing removed abstractions
- keep behavior stable while reducing the current hotspots in validation, sermon storage compatibility, schema hygiene, service boundaries, and oversized services

## Quality Gates

- `vendor/bin/sail artisan test --compact <focused test paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`

## Remaining Phases

### Phase 9: Legacy Storage Migration and Cleanup

Priority: **Critical** — 93% of sermons (662/710) still use legacy storage patterns.

Status note:

- Runtime branches on legacy path formats in every sermon file access.
- Migration and verification tooling is **complete and production-ready** — the remaining work is executing the migration, verifying, then stripping legacy code.

Target files:

- `app/Services/SermonStorageService.php` — legacy branching in `getSermonFileInfo()` (lines 68-101)
- `app/Services/SermonStorageMaintenanceService.php` — migration orchestrator (complete, ready to use)

Tooling (complete — no changes needed):

- `app/Console/Commands/MigrateSermonStorageCommand.php` — supports dry-run, batching, progress reporting
- `app/Console/Commands/VerifySermonStorageCommand.php` — comprehensive diagnostics with pattern breakdown

Tasks:

- [ ] Run `MigrateSermonStorageCommand` with `--dry-run` first to preview scope and catch issues.
- [ ] Execute the migration against the target disk (662 legacy sermons to canonicalize).
- [ ] Run `VerifySermonStorageCommand` to confirm all files are accessible in canonical locations.
- [ ] Strip legacy-pattern detection from `SermonStorageService::getSermonFileInfo()`.
- [ ] Remove `filetype` column dependency and `legacy_disk` configuration.
- [ ] Remove `MigrateSermonStorageCommand` and `VerifySermonStorageCommand` after verification.

Exit criteria:

- Runtime no longer branches on legacy sermon storage/path formats.
- Legacy storage cleanup is a completed migration, not an indefinitely-supported fallback path.

### Phase 13: Schema Snapshot and Migration Hygiene

Priority: **High** — schema dump is stale (10+ migrations since last regeneration on 2026-03-22).

Status note:

- `AuditSchemaGuardrailsCommand` already exists and is functional (122 lines).
- The schema dump has drifted from the live migrations.

Target files:

- `database/schema/mysql-schema.sql` — stale, needs regeneration
- CI or local tooling that depends on schema snapshots

Tasks:

- [ ] Decide one approach and enforce it: keep the schema dump current, or stop relying on it.
- [ ] If retained, regenerate the schema dump so it matches the current schema.
- [ ] Add guardrails that catch dump drift in normal development flow (e.g., CI check or git hook).
- [ ] Remove stale tooling assumptions tied to pre-cleanup schema shapes.

Exit criteria:

- Database bootstrap paths are deterministic.
- The schema dump, if kept, reflects the real schema rather than a partially-stale snapshot.

### Phase 3: Validation Rule Convergence

Priority: **Medium** — only one file has independent validation logic remaining.

Status note:

- `MediaValidationService` is the canonical source for request and Livewire upload rules.
- `ProcessMediaRequest`, `WithUploadLifecycle`, `ValidateAudioFile`, and `AudioExtractionService` all delegate correctly — no work needed on those files.
- The only remaining issue is `MetadataExtractionService::validateAudioFile()`, which has hardcoded validation (format list, 64kbps bitrate floor, 100MB size limit) that bypasses `MediaValidationService`.

Target files:

- `app/Services/MetadataExtractionService.php` — `validateAudioFile()` method (lines 552-580)

Tasks:

- [ ] Decide: is `MetadataExtractionService::validateAudioFile()` upload validation or quality linting?
- [ ] If upload validation: replace hardcoded limits with config-backed limits from `MediaValidationService`.
- [ ] If quality linting: rename the method (e.g., `assessAudioQuality()`) and scope it clearly so it no longer looks like a second upload validator.

Exit criteria:

- Upload validation comes from one source of truth.
- Any remaining audio-quality checks are explicitly framed as secondary analysis, not competing validation.

### Phase 12: Calendar Boundary Cleanup

Priority: **Low** — small scope, one method to relocate.

Status note:

- `CalendarAdminController` already uses constructor injection (complete).
- `GoogleCalendarSyncService` exists and owns sync operations.
- The only remaining issue: `CalendarService::manuallyCategorizeEvent()` (lines 92-104) performs Google API writes that belong in `GoogleCalendarSyncService`.

Target files:

- `app/Services/CalendarService.php` — `manuallyCategorizeEvent()` method
- `app/Services/GoogleCalendarSyncService.php` — should receive the relocated method

Tasks:

- [ ] Move the Google Calendar API write logic from `CalendarService::manuallyCategorizeEvent()` into `GoogleCalendarSyncService`.
- [ ] Keep `CalendarService` focused on local read models and categorization behavior.
- [ ] Add focused tests around the relocated sync behavior.

Exit criteria:

- Calendar read-model logic and Google API concerns are clearly separated.

### Phase 8: Sermon Route / Boundary Cleanup

Priority: **Medium** — confirmed duplicate routes and 5 misplaced presentation methods.

Status note:

- Two delete routes serve the same purpose: `sermons.destroy.dated` (POST `/{year}/{month}/{sermon:slug}/delete`) and `sermons.destroy` (POST `/{sermon:slug}/delete`). The dated route validates then delegates to the slug-only route.
- 5 presentation-only methods on `Sermon` model that should live on `SermonViewPresenter`: `humanDate()`, `seriesUrl()`, `metaDescription()`, `displayPreacherName()`, `displayReference()`.

Target files:

- `routes/web.php` — sermon delete route shapes
- `app/Models/Sermon.php` — presentation methods to relocate
- `app/Presenters/SermonViewPresenter.php` — destination for relocated methods
- Views that call the relocated methods (update to use presenter)

Tasks:

- [ ] Consolidate the two delete routes into a single endpoint.
- [ ] Move `humanDate()`, `seriesUrl()`, `metaDescription()`, `displayPreacherName()`, and `displayReference()` from `Sermon` to `SermonViewPresenter`.
- [ ] Update views to call presenter methods instead of model methods.
- [ ] Leave domain helpers on the model when they are legitimately model-level behavior.

Exit criteria:

- Sermon route/controller responsibilities are easier to follow.
- The `Sermon` model no longer accumulates presentation helpers just because it is convenient.

### Phase 14: Complexity Hotspot Decomposition

Priority: **Low-Medium** — incremental work, no urgency.

Status note:

- Five services remain oversized. Current line counts and public method counts:

| Service | Lines | Public Methods |
|---------|------:|---------------:|
| MetadataExtractionService | 859 | 12 |
| ThumbnailGenerationService | 847 | 7 |
| VideoExtractionService | 651 | 8 |
| AudioTranscriptionService | 616 | 8 |
| SermonAnalysisService | 568 | 6 |

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

1. **Phase 9** — Critical. 93% of sermons affected, tooling ready to execute.
2. **Phase 13** — Quick win. Schema dump regeneration and drift guardrails.
3. **Phase 3** — Quick win. Single file decision (MetadataExtractionService).
4. **Phase 12** — Quick win. Single method relocation.
5. **Phase 8** — Moderate effort. Route consolidation + 5 method moves + view updates.
6. **Phase 14** — Ongoing. Incremental decomposition of oversized services.

## Definition of Done

- [ ] Validation is centralized and intentionally scoped.
- [ ] Sermon legacy storage fallbacks are retired after canonical migration completion.
- [ ] Sermon route/controller/model boundaries are cleaner and thinner.
- [ ] Calendar read-model and Google sync responsibilities are clearly separated.
- [ ] Schema snapshot strategy is consistent and drift-free.
- [ ] Remaining hotspot services are decomposed with focused tests.
- [ ] Required quality gates pass for each delivered phase.
