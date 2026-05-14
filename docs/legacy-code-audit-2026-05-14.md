# Legacy Code Audit

**Date:** 2026-05-14
**Branch:** master
**Reference commit:** `2654dc98e` — "Thread processing_id through SermonAnalysisInterface; Stop reading extracted_date/extracted_service from JSON; service_sections.confidence is the only runtime authority"

The codebase is in remarkably clean shape after recent modernization. Findings are ordered by confidence below.

## Confident removals

### 1. Stale comment in `app/Http/Controllers/PageController.php:94-100`

References a `layouts/page` template that was deleted in commit `237896e81`. The actual fallback is now `pages.show`. Pure documentation rot — safe to update or delete.

## Candidates worth a decision

### 2. Legacy importers — possibly one-shots that have served their purpose

- `app/Services/LegacySermonImporter.php` + `app/Console/Commands/ImportLegacySermonBatchCommand.php` — imports MP3s from a CSV tape index.
- `app/Services/LegacyPlayDateSongUsageImporter.php` + `app/Console/Commands/ImportLegacySongUsageCommand.php` — backfills song usage from old SQL dumps.

If the historic import (commit `b5088b74e` "Historic video import") is complete and won't be re-run, these plus the `play_date` table (if backing the second importer) can be removed.

### 3. Commented-out delete in `app/Services/SermonStorageService.php:286`

```php
// Storage::disk($info['disk'])->delete($info['path']);
```

Either uncomment it (if it should actually delete) or remove the comment entirely. Commented-out destructive code is neither a working safeguard nor a clear "don't do this" warning.

## What is NOT removable (recorded so this doesn't get re-audited)

### `extracted_date` / `extracted_service` on `MediaProcessingLog`

The recent commit `2654dc98e` says "Stop reading extracted_date/extracted_service from JSON". The audit confirms the **dedicated columns** are still authoritative. They are read in:

- `SermonCreationService`
- `ProcessingInitiator`
- `MediaProcessingIdentityResolver`
- `PerformVisualAnalysis`
- `SermonCreationOptions`

The commit was about removing reads from a JSON blob, not these columns. Don't touch them.

### `status` + `current_step` + `dedup_key` triple state on `MediaProcessingLog`

Looks like a parallel state machine but isn't. `awaitingManualSermonReview()` scope at `MediaProcessingLog.php:402-412` intentionally uses `status=Failed` with `current_step=manual_review_required` to express "failed-but-recoverable". Matches the `manual_review_pending_state.md` memory note. Intentional — keep it.

### Both new repositories

`MeetingListRepository` and `PreacherListRepository` are wired in across:

- `ListCalendarEvents`
- `EditCalendarEvent`
- `BrowseSermons`
- `ListSermons`
- `SermonController`
- `SermonArchiveSeoPresenter`

No leftover inline query duplication.

### Modern Laravel 12 bootstrap

Clean — no `Console\Kernel`, no `Http\Kernel` registrations, no `RouteServiceProvider`, no `env()` outside config files, no orphan entries in `config/app.php`.

### Intervention Image

Fully on `intervention/image-laravel` v1.x (`Image::read()`); zero `Image::make()` v2 leftovers.

### Admin authorization

Only one mechanism (`WithAdminAuthorization` trait + middleware). No parallel `$isAdmin` flags or stale gates/policies. Pinned by `tests/Integration/Livewire/Traits/AdminLivewireComponentsUseTraitTest.php`.

### Livestream / segmentation pipeline

Unified through `UnifiedMediaProcessor`. No "old" branches or `if ($oldFormat)` style conditionals.

## Categories searched (all clear)

- Explicit deprecation markers (`@deprecated`, `LEGACY`, `TODO: remove`, etc.)
- Backwards-compat shims, `class_alias`, re-exports
- Orphaned PHP classes, Blade views, Livewire components, JS files
- Routes pointing to nonexistent controllers
- Old framework patterns (Kernel classes, manual middleware registration)
- Unused config keys (custom configs all actively read)
- Dead test infrastructure
- Large commented-out code blocks
- Stale controller methods

## Recommended next actions

1. Clean up the `PageController` stale comment.
2. Clean up the `SermonStorageService` commented-out destructive line.
3. Decide whether the `Legacy*Importer` classes have done their job — if yes, remove them, their commands, and (if applicable) drop the `play_date` table in a migration.
