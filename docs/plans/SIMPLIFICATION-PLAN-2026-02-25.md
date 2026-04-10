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

Status: **Complete** (2026-04-10)

- `MetadataExtractionService::validateAudioFile()` no longer exists — removed in a prior session.
- `MetadataExtractionService` contains only metadata extraction logic (duration, bitrate, format, filesize); zero validation.
- `SermonValidationService::validateAudioFile()` and `AudioExtractionService::validateAudioFile()` both delegate to `MediaValidationService::validateUploadedFile(MediaType::Audio, $file)`.
- Upload validation comes from one source of truth. Exit criteria met.

### Phase 12: Calendar Boundary Cleanup

Priority: **Low** — small scope, one method to relocate.

Status: **Complete** (2026-04-10)

- Added `GoogleCalendarSyncService::syncCategorizationToGoogle()` — owns the Google extended-property write.
- `CalendarService` now injects `GoogleCalendarSyncService` and delegates the write; it no longer imports `Spatie\GoogleCalendar\Event` directly.
- `CalendarServiceTest` updated: categorization tests now call `manuallyCategorizeEvent()` directly against a mocked `GoogleCalendarSyncService`, replacing the old workaround that bypassed the method entirely.
- `GoogleCalendarSyncServiceTest` has a focused test for the new method's graceful-failure path.
- Calendar read-model logic and Google API concerns are clearly separated. Exit criteria met.

### Phase 8: Sermon Route / Boundary Cleanup

Priority: **Medium** — confirmed duplicate routes and 5 misplaced presentation methods.

Status: **Complete** (2026-04-10)

- `sermons.destroy.dated` route and `destroyWithDate`/`assertDateMatchesUrl` controller methods removed. Delete goes through the single `sermons.destroy` (POST `/{sermon:slug}/delete`) endpoint.
- `displayPreacherName()`, `displayReference()`, and `metaDescription()` moved from `Sermon` to `SermonViewPresenter`. All callers updated (`PodcastFeedService`, `ThumbnailCanvasComposer`, `SermonItemListPresenter`, `EditSermon`, `SermonResource`, and all blade views).
- `humanDate()` and `seriesUrl()` intentionally kept on `Sermon` as Eloquent accessor attributes — they are consumed by `SermonResource` (public API contract) and would require broader refactoring to move without breaking backward-compatible property access.
- `metaDescription()` model attribute retained as a thin delegation shim (`app(SermonViewPresenter::class)->metaDescription($this)`) to preserve `$sermon->meta_description` access in tests and remaining call sites.
- All tests updated and passing. Exit criteria met.

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
