# Artisan Command Architecture Review (2026-03-18)

## Scope

Reviewed the custom Artisan commands in `app/Console/Commands`, schedule wiring in `bootstrap/app.php`, and the services/jobs/actions those commands orchestrate.

Focus areas:

- duplication with reusable services/jobs/actions
- idempotency and rerun safety
- dry-run support and operator supportability
- destructive-action safety
- logging quality and exit-code behavior
- transaction boundaries
- long-running and scheduled command behavior

## Snapshot

### Commands already moving in the right direction

These are close to the ideal "thin wrapper over application logic" shape:

- `service-tracking:sync-songs` -> `App\Services\SongCatalogSyncService`
- `service-tracking:link-songs` -> `App\Services\ChurchServiceSongLinker`
- `media-processing:backfill-extracted-identity` -> `App\Services\MediaProcessingIdentityResolver`
- `sitemap:generate` -> `App\Services\SitemapService`
- `calendar:sync` -> `App\Services\GoogleCalendarSyncService` (wrapper is thin, but the underlying service has a serious deletion bug)

### Commands still carrying too much application logic

These commands are still doing business logic, state transitions, or file-migration orchestration directly in the command class:

- `livestream:create-sermon`
- `sermons:enrich-scripture`
- `scripture:refresh-passages`
- `preachers:cutover`
- `sermons:migrate-storage`
- `sermons:migrate-livestream-audio`
- `sermons:migrate-local-files`
- `media:cleanup-temp-files`
- `meetings:migrate-photos`
- `images:convert-to-webp`
- `media:extract-frames`

### Coverage snapshot

I found dedicated command tests for 10 of the 21 custom commands. The highest-risk commands without dedicated command tests are:

- `images:convert-to-webp`
- `media:cleanup-temp-files`
- `meetings:migrate-photos`
- `sermons:migrate-storage`
- `sermons:migrate-livestream-audio`
- `sermons:migrate-local-files`
- `calendar:sync`
- `sermons:verify-storage`

That gap matters because most of those commands mutate files or persistent state and are intended for operator use during recovery or migration work.

## Findings

### [P1] `calendar:sync` can delete healthy local rows after a transient per-event sync failure

**Why it matters**

`App\Services\GoogleCalendarSyncService::syncFromGoogleCalendar()` treats "failed to process this upstream event" the same as "this upstream event no longer exists". A transient exception inside `syncSingleEvent()` therefore causes the local `CalendarEvent` row to be deleted on the same run.

**Evidence**

- `app/Services/GoogleCalendarSyncService.php:40-58`
  - collects all existing local event IDs for the sync window
  - only appends an event ID to `$processedEventIds` after `syncSingleEvent()` succeeds
  - deletes `array_diff($existingEventIds, $processedEventIds)`
- `app/Console/Commands/SyncGoogleCalendarCommand.php:16-39`
  - the command is just a wrapper, so the operator has no extra safeguard here
- `tests/Unit/Services/GoogleCalendarSyncServiceTest.php:26-73`
  - coverage only exercises `syncSingleEvent()`, not the failure semantics of `syncFromGoogleCalendar()`

**Operator impact**

- a bad payload or temporary Google/API issue can silently remove still-valid local events
- the next successful sync may recreate them, but any local state tied to the row can churn in the meantime
- scheduled syncs make this hard to notice quickly

**Recommendation**

Only mark an event as deletable when it was absent from the upstream feed, not when it failed local processing. Split "seen upstream" from "processed successfully", and log/report failed IDs separately.

### [P1] `media:cleanup-temp-files` is unsafe for queued/manual-review workflows

**Why it matters**

The scheduled temp-file cleanup deletes files purely by age, without checking whether a `MediaProcessingLog` still points at them or whether a manual-review / retry flow still depends on them.

**Evidence**

- `bootstrap/app.php:17`
  - scheduled every six hours, with no `withoutOverlapping()`
- `app/Console/Commands/CleanupOrphanedTempFiles.php:41-93`
  - deletes from `livestream/temp`, `temp/video-processing`, `temp/audio-validation`, and other working directories solely by age
- `app/Services/UnifiedMediaProcessor.php:343-352`
  - direct video uploads are stored under `temp/video-processing`
- `app/Actions/ConfirmLivestreamSermonSegment.php:64-88`
  - manual-review confirmation requires the original source video to still exist
- `app/Models/MediaProcessingLog.php:456-467`
  - source-video existence is checked against the temp disk path stored on the log
- `app/Services/ProcessingPipelineBuilder.php:122-134`
  - the normal lifecycle already has an explicit end-of-pipeline cleanup step

**Operator impact**

- queued or backlogged runs older than 24 hours can lose source files before retries/manual review happen
- manual-review flows can become unrecoverable even though the database row still exists
- cleanup can race with active scheduled work because the command is not overlap-protected

**Recommendation**

Move scheduled temp cleanup behind an application service that:

- excludes files referenced by non-terminal `media_processing_logs`
- treats manual-review runs as in-use
- only deletes files after the pipeline's own cleanup job should reasonably have handled them
- logs counts by category and returns non-zero when cleanup partially fails

### [P1] `livestream:create-sermon` bypasses the real livestream application flow and can succeed after partial failure

**Why it matters**

This command is effectively its own alternate sermon-publication pipeline. It auto-selects the longest speech segment, creates a `Sermon` directly, performs extraction itself, and still returns success even if extraction fails after the database row is created.

**Evidence**

- `app/Console/Commands/ProcessVideoCommand.php:33-62`
  - auto-selects the longest speech segment and creates the sermon directly with `Sermon::create(...)`
- `app/Console/Commands/ProcessVideoCommand.php:66-123`
  - performs extraction/storage inline and only logs an error if extraction fails; the command still exits `0`
- `app/Console/Commands/ProcessVideoCommand.php:67-69`
  - uses the default `Storage` facade rather than the configured temp disk, so it can diverge from the main pipeline's storage rules
- `app/Actions/ConfirmLivestreamSermonSegment.php:29-75`
  - the current application flow already has a reusable action for manual confirmation, transactionally updating review state and dispatching the post-review chain
- `app/Jobs/SubmitToProcessing.php:127-165`
  - the normal livestream pipeline uses `SermonCreationService` and `SermonMetadataIntegrationService`
- `app/Jobs/PublishApprovedServiceSection.php:56-142`
  - approved-section publication also uses transactional state transitions plus `SermonCreationService`
- `app/Jobs/ProcessTranscriptWithAI.php:141-142`
  - the normal pipeline also dispatches scripture enrichment afterward

**Operator impact**

- creates sermon rows that bypass the canonical creation path
- can leave partial records behind while reporting success
- ignores the newer manual-review / post-review workflow already present in the app

**Recommendation**

Retire this command or reduce it to a thin wrapper over a reusable action. If it must stay, it should call the same review-confirmation / sermon-creation actions used by the app and fail hard when downstream extraction/storage fails.

### [P1] `images:convert-to-webp` can rewrite references even when no matching conversion happened

**Why it matters**

This command has a destructive workflow, but its reference-update phase is broader than the set of images actually converted. The code builds a replacements map, then ignores it and blindly rewrites every `.jpg`/`.jpeg` reference it can regex-match across the app.

**Evidence**

- `app/Console/Commands/ConvertJpgToWebp.php:56-67`
  - conversion and reference-updating are independently optional
- `app/Console/Commands/ConvertJpgToWebp.php:166-179`
  - `updateCodeReferences()` builds `$replacements`
- `app/Console/Commands/ConvertJpgToWebp.php:243-291`
  - `updateFilesInDirectory()` accepts `$replacements` but never uses it; instead it rewrites any regex-matched `.jpg`/`.jpeg` string to `.webp`
- `app/Console/Commands/ConvertJpgToWebp.php:79-80`
  - the only confirmation prompt is for deleting originals, not for broad codebase rewrites

**Operator impact**

- `--skip-convert` can still rewrite references to `.webp` without creating the target files
- unrelated or external JPG references can be mutated
- supportability is low because there is no scoped diff/audit output beyond console lines

**Recommendation**

Make reference updates derive strictly from the set of successfully converted files. Do not rewrite references when `--skip-convert` is used unless an explicit input mapping is supplied.

### [P2] `meetings:migrate-photos` is not rerunnable after a partial success

**Why it matters**

The command skips an entire meeting as soon as the meeting has any media in the `photos` collection. That means a partially successful run is not safely rerunnable: if one file migrated and the second failed, a rerun skips the meeting entirely and the remaining legacy files are stranded.

**Evidence**

- `app/Console/Commands/MeetingMigratePhotosCommand.php:31-37`
  - skips the whole meeting when `getMedia('photos')->isNotEmpty()`
- `app/Console/Commands/MeetingMigratePhotosCommand.php:67-78`
  - migration happens one file at a time and can partially succeed within the same meeting

**Operator impact**

- partial migrations require manual cleanup or manual import work
- dry-run exists, but resumability/idempotency is still weak
- there is no per-file dedupe key or audit trail to show what remains

**Recommendation**

Promote this to a reusable migration service that dedupes at the file level, not the meeting level. A rerun should skip already-imported files and continue importing the rest.

### [P2] `media:cleanup-unpublished-section-assets` leaves approved sections in a dangling state after deleting their files

**Why it matters**

The command deletes extracted media for `APPROVED`, `REJECTED`, and `PENDING_APPROVAL` sections, but it only nulls the file columns. It does not transition the publication state or add audit metadata explaining why approved assets disappeared.

**Evidence**

- `app/Console/Commands/CleanupUnpublishedSectionAssetsCommand.php:26-45`
  - targets `PENDING_APPROVAL`, `REJECTED`, and `APPROVED`
- `app/Console/Commands/CleanupUnpublishedSectionAssetsCommand.php:71-79`
  - deletes files and clears extraction columns, but leaves `publication_status` unchanged
- `app/Jobs/PublishApprovedServiceSection.php:73-92`
  - approved publication later requires the video/audio paths to exist and will fail if they do not
- `app/Models/ServiceSection.php:154-158`
  - approved sections are allowed to transition to `NOT_APPLICABLE`, but the cleanup command does not use that state machine
- `app/Jobs/PrepareSectionPublicationCandidates.php:110-116` and `app/Jobs/PrepareSectionPublicationCandidates.php:131-138`
  - already-approved sections are generally left alone by candidate preparation

**Operator impact**

- an approved section can remain approved while being impossible to publish
- later failures look like missing-file bugs rather than an intentional expiry/cleanup action
- operators lose the audit trail of why the candidate disappeared

**Recommendation**

Treat asset expiry as a state transition, not just a file deletion. At minimum, move approved expired rows to `NOT_APPLICABLE` or another explicit expired state and record cleanup metadata.

## Secondary observations

### 1. The scripture commands duplicate logic that already exists in jobs/actions

- `app/Console/Commands/EnrichSermonsScripture.php:66-140`
- `app/Console/Commands/RefreshScripturePassages.php:43-105`
- `app/Actions/QueueScriptureEnrichment.php:19-29`
- `app/Jobs/FetchBibleTextForSermon.php:32-127`
- `app/Jobs/ProcessTranscriptWithAI.php:141-142`
- `app/Livewire/Admin/Sermons/EditSermon.php:157-167`

The app already has a shared queue-dispatch action for scripture enrichment, but the command still manually dispatches jobs in one mode and reimplements passage-resolution logic in another. This should become a shared enrichment service/action so the CLI, admin UI, and processing pipeline cannot drift.

### 2. Storage migration logic is fragmented across multiple commands instead of one reusable operator service

- `app/Console/Commands/MigrateSermonStorageCommand.php:52-283`
- `app/Console/Commands/MigrateLivestreamAudioFiles.php:44-160`
- `app/Console/Commands/MigrateLocalFilesToSpacesCommand.php:19-94`
- `app/Console/Commands/VerifySermonStorageCommand.php:19-127`
- `app/Services/SermonStorageService.php:19-58`
- `app/Services/SermonStorageService.php:151-247`

The codebase already has `SermonStorageService`, but the migration commands still each carry their own path heuristics, copy loops, verification logic, and operator output. The result is inconsistent failure handling:

- `sermons:migrate-storage` always returns success from `handle()`
- verification and migration logic are split across separate code paths
- there is no single resumable, auditable storage-maintenance action

### 3. Schedule hardening is inconsistent

- `bootstrap/app.php:16-23`

The unattended commands with the most destructive or network-heavy behavior should all follow the same overlap rules. Right now:

- `media:cleanup-unpublished-section-assets` and `scripture:refresh-passages` use `withoutOverlapping()`
- `calendar:sync` and `media:cleanup-temp-files` do not

### 4. `preachers:cutover` still reimplements logic the application already has

- `app/Console/Commands/PreacherCutoverCommand.php:31-170`
- `app/Services/PreacherResolutionService.php:18-103`

The command owns name normalization, canonical creation, alias creation, and sermon linking directly even though the application already has a reusable preacher-resolution service. That makes the command harder to evolve safely than it needs to be.

## Suggested direction

If this area is going to be cleaned up incrementally, I would do it in this order:

1. Fix correctness bugs first:
   - `calendar:sync`
   - `media:cleanup-temp-files`
   - `livestream:create-sermon`
   - `images:convert-to-webp`

2. Consolidate operator actions second:
   - scripture enrichment / refresh into a shared service
   - sermon storage migration / verification into a shared operator service
   - meeting photo migration into a rerunnable import service

3. Standardize console safety rules last:
   - dry-run for all destructive commands
   - non-zero exit codes when any item fails
   - overlap protection for unattended commands
   - structured summary logging for supportability
