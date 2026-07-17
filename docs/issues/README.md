# Open Issues

Consolidated tracker for audit findings (Mortician = dead code/assets, Pathfinder =
broken links/SEO, public UX review = visitor journeys). Last reconciled against the codebase
**2026-07-17** and production **2026-07-12**.

Convention: agent-generated per-issue reports get folded into this file (and, where the work is
plan-shaped, into `docs/plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md`) and the source
report files are then deleted — they live in git history. Resolved items are listed at the bottom
for provenance.

---

## 🟠 July simplification implementation review — production audit pending (2026-07-13)

**Status updated 2026-07-15:** the repository and runtime fixes for O22–O31 and O33–O37 are
implemented in `929663ec9` and `1d3988f62`. The quality gate passed with 5,914 parallel PHPUnit
tests (18,205 assertions), 47 Dusk tests (78 assertions), PHPStan at 0 errors, Pint, the production
frontend build, and the schema/symlink/case-collision guards.

O32 remains an operational production gate, not a repository-code task. This checkout has no
production SSH host/key; those values exist only as GitHub Actions environment secrets, so this
session cannot truthfully record counts for audio, video, transcripts, or thumbnail variants.
O25's one-off existing-data audit is covered by that same all-asset audit. The preventative O25/O26
runtime work is complete: every protected children's-talk locator now moves through verified,
compare-and-set private storage with retryable cleanup and change-triggered observer coverage.
Do not close O32 until the read-only production counts have actually been captured.

Review scope: `e72da7c4f^..614c21765`, covering suggested delivery-order items 1 and
3–6. No issue was found in the deterministic mock-analysis stub (`0281bd5f0`), the canonical-path
migration itself (`81e87a43b`), or the unrelated Vite patch in the range. The findings below
distinguish regressions from completion/gate gaps: a mechanical item is not complete when it deletes
its recovery tooling before the documented production invariant has actually been proved.

Priority convention: **P1** blocks the next deployment or can lose/expose data; **P2** is a concrete
behavioural or operability defect for the next follow-up; **P3** is incomplete cleanup or stale
guidance that should ride the nearest owning change.

### O22 · [P2] The required `frontend-design` skill is now a dangling symlink

Commit `e72da7c4f` deleted `.claude/skills/frontend-design.md`, but the tracked
`.claude/skills/frontend-design/SKILL.md` symlink still targets `../frontend-design.md`. `test -e`
therefore fails, while `AGENTS.md` and `CLAUDE.md` still require agents to load that skill before UI
work. The deleted file was not a redundant copy: it was the symlink's content target.

**Suggested fix:** restore the skill as a real cross-tool skill, preferably at
`.agents/skills/frontend-design/SKILL.md`, repoint the Claude discovery symlink, and update the path
in `AGENTS.md`. Add a lightweight quality-gate check that every tracked skill symlink resolves to a
readable file.

### O23 · [P2] The schema-drift check tells future migrations to delete themselves

Commit `bb9323683` changed `scripts/check-schema-dump-current.sh:28` to prescribe
`schema:dump --prune` whenever a migration is absent from the dump. Following that routine advice
after creating and locally applying a migration records it in the developer's dump and deletes its
PHP file; if it has not been applied, `--prune` still deletes it. Existing production databases do
not load a fresh-install dump, so they are then left with no migration that can add the new column
or table.

**Suggested fix:** make the routine remediation `vendor/bin/sail artisan migrate` followed by
`vendor/bin/sail artisan schema:dump`, without `--prune`, so the local dump is current and the
migration remains deployable. State explicitly that `--prune` is reserved for a deliberate
quarterly squash after every included migration has been verified on every long-lived environment.
Add a regression test around the script's failure guidance.

### O24 · [P2] Two July migrations were pruned without recorded production evidence

Commit `bb9323683` removed
`2026_07_07_190047_add_first_line_key_to_songs_table.php` and
`2026_07_10_155218_add_archive_eval_to_inbound_emails_status_enum.php`. Their effects and migration
rows are now represented in version-controlled migration history only by
`database/schema/mysql-schema.sql`, while live code queries `songs.first_line_key` and writes the
`archive_eval` enum value. The reviewed change records no production `migrate:status` or schema
evidence for those two recent migrations; fresh-database CI cannot detect an existing database that
missed them.

**Suggested fix:** verify both migration rows and the resulting column/index/enum immediately
(before the next deployment if this squash has not deployed yet). If either is absent, restore its
original migration file from git until all long-lived environments have applied it. Record this
check as a mandatory gate for future squashes.

### O25 · [P1] The retired children's-talk backfill leaves later media publicly addressable

Item 2.3/2.4 is marked complete after a dry run claimed no children's talk used a non-`private/`
path, but the retired command audited audio only. The retained
`MoveSermonToPrivateStorage` job does not move `video_file_path` or `transcript_file_path`, and
`SermonObserver:21-25` dispatches it only on creation or a content-type change. Same-type
reprocessing can later replace audio/video/transcript paths and `GenerateThumbnail:140-146` can add
public thumbnail paths without another dispatch. The configured production transcript and sermon
disks can both be public Spaces, so possession of the origin/CDN URL bypasses the guarded Laravel
route for members-only material.

**Suggested fix:** immediately audit every children's-talk audio, video, transcript, primary,
plain, card and candidate-thumbnail path in production. Extend the mover to video and transcripts,
and dispatch whenever any protected media path changes to a non-private value. Add tests that cover
same-type reprocessing, late thumbnail generation, and the absence of every protected asset from
public storage after the move.

### O26 · [P1] The retained private-storage mover can delete the only good copy

`MoveSermonToPrivateStorage:66-80` ignores `writeStream()`'s boolean result, deletes the public
source, and only then updates the database. The same sequence is repeated for primary, plain, card
and candidate thumbnails. The local disk does not set `throw => true`, so a failed write returns
`false`; a database failure after deletion leaves the row pointing at a missing source, and a retry
cannot recover because that source no longer exists.

**Suggested fix:** make each move an idempotent two-phase operation: copy and close the stream,
verify the target, compare-and-set the database path, then persist or dispatch source deletion as a
separately retryable cleanup step. Recover correctly when a verified target already exists but the
row still names the source; do not let a normal retry skip a failed public-source deletion merely
because the row now starts `private/`. Test write, verification, database-update and cleanup-retry
failures for every asset shape.

### O27 · [P1] The upload Cancel button no longer cancels the browser upload

Commit `614c21765` changed the button at
`resources/views/livewire/media-upload/progress.blade.php:12` to a server-side
`wire:click="cancelUpload"`. That method only resets PHP state; the refactor removed the JavaScript
call to Livewire's client-side `$cancelUpload('mediaFile')`. The outstanding transfer therefore
continues, and `resources/js/livewire/media-upload-controller.js:72-83` still calls
`uploadComplete` when its finish event arrives, with no cancelled guard.

**Suggested fix:** invoke Livewire's client upload cancellation for `mediaFile`, reset server state
from its cancellation callback, and reinstate an explicit cancelled guard before automatic
processing. Add a Dusk regression using a throttled upload that proves transferred bytes stop and
processing never starts.

### O28 · [P2] Terminal processing states expose a second, unusable file picker

`resources/views/livewire/media-upload/form.blade.php:25` shows the upload form for every state
except `Processing` and `Completed`, including `Failed`, `Cancelled` and `ManualReview`. Those
states can retain the previous `processingId`. Selecting a file changes the state to `Uploading`,
but `MediaUpload::uploadComplete():116-118` then silently returns because that old ID is still set.
The explicit **Upload Another File** action would have cleared it, so the page presents two paths
that behave differently.

**Suggested fix:** render the picker only for `Idle`/`Uploading` (and a pre-processing validation
failure with no `processingId`), or make every new selection atomically reset the old run first.
Cover failed, cancelled, manual-review and validation-failure states in Dusk.

### O29 · [P2] Retiring `ProcessingReview` strands two supported manual-review cases

The replacement inbox has no working action for a manual-review run with no resolved date/service:
`review-inbox.blade.php:274-293` labels its link **Create this service** but points straight back to
the same filtered inbox. A second supported configuration is also broken: the upload page remains
available when `service-tracking.enabled=false`, yet both `MediaUpload::statusUrl` and
`ManualReviewRequired` link to the service inbox, whose component deliberately aborts with 404 in
that configuration. The focused `ProcessingReview` surface that previously handled segments has
been deleted.

**Suggested fix:** provide one authorised segment-confirmation/association action that carries the
`processingId` and works before a service identity exists. Keep a minimal fallback when service
tracking is disabled, or explicitly disable workflows that can pause for review in that mode.
Test unattributed runs and the disabled-tracking configuration end to end.

### O30 · [P2] Durable diagnostics report completed-step durations as negative numbers

`GetMediaProcessingStatus:85-87` calls
`$completedAt->diffInSeconds($startedAt)`. Installed Carbon uses a signed difference by default, so
a normally ordered step produces a negative duration. The integration fixture creates a one-minute
completed step but never asserts its `duration_seconds` value.

**Suggested fix:** calculate from start to completion (or explicitly request an absolute
difference) and assert that the existing fixture returns positive `60`.

### O31 · [P2] Flattening sermon-analysis retries also removed live failure diagnostics

Commit `356caaa89` correctly removed the service-level retry loop, but
`SermonAnalysisService:133-148` now records only a generic failed step. Its `ErrorException` path at
lines 279–280 rethrows before recording HTTP status and API duration; the deleted
`handleAnalysisAttemptError()` used the still-live `logApiCall()` to preserve HTTP status/duration
for `ErrorException`, and `logError()` for wrapped/generic failures. Those demonstrably live details
are now lost. (The deleted transport/TypeError branches are not relied on here: those exceptions
were already caught or wrapped inside `executeAiRequest()`.)

**Suggested fix:** preserve one structured API/error log from the flattened catch, including HTTP
status, duration and sanitised context, without restoring attempt counters or service-level retry
scaffolding. Add focused 401/500 and wrapped/generic-failure assertions.

### O32 · [P2] The storage-collapse production gate verified audio, not "all files"

The completion evidence in the July backlog records 698/698 accessible `audio_file_path` values,
then closes the broader **all files accessible** gate and deletes the maintenance/verifier tooling.
Runtime storage also owns video, transcripts and primary/plain/card/candidate thumbnails. A
reference to one of those assets on the wrong disk would not have appeared in the recorded
verification.

**Suggested fix:** run a read-only, path-aware production audit across every referenced sermon
asset field and thumbnail candidate, and record counts by asset kind. Run the old audio verifier
from the pre-deletion release/tag if useful; do not restore do-not-invest tooling to the repository.
If the audit finds a non-audio problem, obtain explicit remediation approval and use scoped tooling
with a declared deletion trigger.

**Self-service path (added 2026-07-16):** `audit:sermon-assets` is a read-only artisan command
covering every referenced asset field and thumbnail candidate (existence on the expected disk plus
children's-talk private placement, which also closes the O25 one-off audit). Dispatch
`production-audit.yml` (`gh workflow run production-audit.yml`) once this branch is on `master`;
runs use the `production-audit` environment and wait for maintainer approval. The workflow output is
public, so it prints counts only — run the command with `--details` on the server to identify
affected sermons.

### O33 · [P2] Private plain-thumbnail URLs serve the wrong asset

`SermonStorageService::getPlainThumbnailDeliveryUrl():387-394` routes a private plain thumbnail to
`sermons.thumbnail`, but `SermonAssetController::serveThumbnail():142-162` reads
`thumbnail_file_path`, not `plain_thumbnail_file_path`. A consumer of the private plain-thumbnail
delivery URL therefore gets the branded primary image or a 404 when only the plain variant exists.

**Suggested fix:** add a guarded plain-thumbnail route/action, or a tightly validated thumbnail
variant parameter, and assert that the response bytes come from the plain path.

### O34 · [P2] A transient storage failure is cached as missing enclosure metadata forever

Item 2.3 claims stale enclosure-metadata failures disappear, but
`SermonStorageService::fileMetadata():534-551` uses `Cache::rememberForever()` and converts any
storage exception into null size/mtime values. `PodcastFeedService:73-78` then publishes enclosure
length `0`. One temporary Spaces failure persists until a sermon update or an unrelated cache
flush.

**Suggested fix:** do not cache failure results. Give successful metadata a bounded TTL; a short
retry/backoff window for transient storage errors is optional. Test both metadata recovery after the
disk becomes available and recovery/invalidation of `PodcastFeedService`'s separate flexible feed
cache.

### O35 · [P2] Dead-code deletion removed live public song-usage policy coverage

Commit `356caaa89` deleted the public `query()` method and its tests, but the same qualification
policy still powers `statsForSong()` and `usageHistoryForSong()` at
`PublicSongUsageService:71-116`. The surviving suite covers only an unmatched completed
livestream; it no longer proves that a confirmed match counts or that failed, pending, processing
and non-livestream logs leave order-of-service usage eligible.

**Suggested fix:** port those policy cases from the deleted `query()` tests into the single
surviving canonical `tests/Integration/Services/PublicSongUsageServiceTest.php` suite, asserting
against both live methods and covering both count and history output. Do not recreate either legacy
duplicate suite.

### O36 · [P3] Item 2.1 left more test-pinned dead surface and retry residue

Repo-wide caller checks found no production callers for multiple surviving
`VideoStorageService` wrappers (`extract*`, `moveToSermonStorage`, `getStorageStats`, permanent
upload, URL/existence helpers and the no-op `cleanup`). Separately,
`config/media-processing.php:133-134` still exposes `ANALYSIS_MAX_RETRIES` and
`ANALYSIS_RETRY_DELAY_BASE` with no production reader, while the one-shot analysis path logs that
an overlong title is "retrying" immediately before it throws.

**Suggested fix:** repeat the zero-production-caller audit, delete confirmed orphan methods with
their preservative tests and unused dependencies, remove the two dead config keys/test setup, and
change the title message to say the result is being rejected.

### O37 · [P3] Mechanical cleanup left stale paths, contracts and test scaffolding

Examples found in the reviewed range:

- `scripts/post-deploy-smoke.sh:12` still names deleted `monitoring.base_url`;
- `bootstrap/app.php:31` points to deleted `config/schedule-monitor.php`;
- `CreatesSlugViolatingSermons:14-20` points to a pruned migration rather than the dump/constraint;
- `MeetingShowPresenterTest:25-27` injects a dependency removed from the constructor; and
- `SermonProcessingLogger:11-17` still advertises statistical analysis and health checks that were
  deleted.

PHP currently tolerates the presenter's extra constructor argument, so the test stays green while
documenting a false contract.

**Suggested fix:** correct the operational comments to the live config/default sources, reference
the schema dump or named constraint, remove the false test dependency, and narrow the logger
contract. Fold any presenter work scheduled for deletion into backlog item 3.1 rather than
investing in that seam separately.

### Review verification

- Three focused Sail runs passed (27, 59 and 69 tests respectively; their overlapping coverage is
  not summed), including analysis/dead-code, storage/security, upload/status and review-inbox paths.
- `vendor/bin/sail composer phpstan` completed with **0 errors**.
- The dangling skill was reproduced with `test -e`; Carbon's installed signed-difference contract
  and Livewire's installed `$cancelUpload` client API were checked against local vendor source.
- The full parallel suite was not independently completed: this session was denied Docker access
  for that command. Do not treat the focused green runs as closing the behavioural findings above.

### Follow-up review of the O22–O37 fixes (2026-07-16)

A second review pass over `e72da7c4f..881e5d034` confirmed every repository-side fix and found
five further defects, all resolved in the follow-up commits on this branch:

1. **Retired per-run review URL 404'd.** `614c21765` deleted
   `admin/services/processing/{processingLog}/review` without a redirect, so manual-review
   emails sent before that deploy dead-ended, contrary to the retired-URL 302 convention.
   Restored as a redirect to `admin.recordings.sermon-segment`.
2. **Legacy poisoned metadata cache entries survived the O34 fix.** A pre-fix
   `rememberForever` null/null entry passed the new shape check and was served forever for a
   never-updated sermon. Null values are now rejected as legacy failures and re-read.
3. **The podcast feed's zero-enclosure self-heal was silent.** A genuinely missing audio file
   defeats the feed cache on every request; that now logs a warning naming the sermon ids.
4. **One bad asset blocked the private-storage mover's remaining moves.** Failures are now
   collected per asset so every other asset is still protected before the job rethrows, and a
   stale *unreferenced* private target from a crashed attempt is replaced instead of failing
   verification on every retry (referenced targets are still preserved).
5. **Source-deletion reference checks loaded the sermons table once per deletion.** One
   snapshot per cleanup run now answers all of them.

### O38 · [P3/operational] Confirm all stored password hashes are bcrypt before trusting `HASH_VERIFY=true`

`bb9323683` adopted the framework default `HASH_VERIFY=true` (was `false`) and added
`rehash_on_login`. With verify on, `Hash::check()` **throws** for a stored hash that is not the
configured algorithm instead of returning false, so any legacy non-bcrypt hash row would now 500 a
login attempt. Run `SELECT COUNT(*) FROM users WHERE password NOT LIKE '$2y$%'` in production; if
non-zero, decide per-row remediation before relying on the new default. This checkout has no
production access, so the check is recorded here rather than executed.

**Self-service path (added 2026-07-16):** `audit:password-hashes` counts stored hashes by algorithm
(never printing hash material or user ids) and fails when any non-`$2y$` row exists. Dispatch it via
`production-audit.yml` as described under O32.

## 🟠 July simplification delivery-order item 7 review (2026-07-17)

**Status updated 2026-07-17 (same day):** every repository-side finding is resolved and merged.
O39 was closed with production deploy evidence and an expand/contract convention in `AGENTS.md`,
and O51's doc drift fixed, in PR #1229. O42/O43 were fixed in PR #1230 (bulk-delete cache eviction;
lock-protected categorisation), O40/O41 in PR #1231 (targeted exposure-transition eviction with
warm-cache regression tests; deleting-time relation preload), O46/O47 in PR #1233 (uncached sitemap
source queries; atomic file replacement), O44/O48/O49 in PR #1236 (versioned `podcast_feed_v2_*`
key; explicit `role="speaker"`; config-derived HTTP freshness — supersedes auto-closed #1232), and
O50 in PR #1234 (framework-private flexible-cache key knowledge removed; the framework contract the
strategy relies on is pinned by `FlexibleCacheInvalidationTest`). Codex could not review these PRs
(usage limits); the substitute was the local multi-angle `/code-review` process, whose findings were
addressed on PR #1231 before merge.

**Only O45 remains open** — it is an operator-side action with no repository change: inspect the
production Google Calendar share, demote the service account to the read-only event-details role if
it still has write access, run one scheduled and one manual sync, and record the role plus the
successful read here.

Review scope: merged PRs #1221, #1222, #1223, #1224, #1225 and #1227, which cumulatively
implement backlog items 3.1–3.6. Each merge commit was reviewed separately (the history between
some PRs contains unrelated work), then the combined current-`master` behaviour was traced through
its controllers, presenters, observers, caches, scheduled commands, migrations, deployment order
and surviving tests. The merge commits are `85452f9a4`, `876a88869`, `d326ac09c`, `7a4404814`,
`3e168bf75` and `274a79e91` respectively. No actionable issue was found in PR #1221's presentation
convergence or PR #1223's presenter collapse; their output shapes and access checks remain intact.

Priority follows the convention above: **P1** blocks a safe deployment or can expose data;
**P2** is a concrete behavioural or operability defect; **P3** is incomplete cleanup, resilience
or source-of-truth drift.

### O39 · [P1/deploy] The recurrence-column migration is incompatible with the live deployment order

PR #1225 drops the recurrence CHECK, two indexes and three columns in
`2026_07_16_222742_drop_recurrence_columns_from_meetings_table.php:17-22`, but the production
workflow runs migrations at `.github/workflows/deploy.yml:429-433` while the outgoing release keeps
serving until line 443. That outgoing #1224 code selects and filters `is_recurring`/`frequency` in
the admin meeting list and hydrates/writes all three removed fields in its meeting form. Its list,
create and edit requests can therefore fail with unknown-column errors during the first deployment.

The migration is also not retry-safe. Laravel compiles the CHECK drop, each index drop and the
column drop into separate `ALTER TABLE` statements; MySQL does not transact the group. If a later
statement fails, no migration row is recorded, but retry starts by dropping an already-absent CHECK
while the outgoing release may now face a partially contracted schema.

**Suggested fix:** do not deploy this migration through the current migrate-before-swap sequence.
Use a two-release expand/contract deployment, or an explicit maintenance/swap procedure in which no
incompatible release can serve. Make the DDL one atomic `ALTER TABLE` where supported, or explicitly
idempotent with a tested partial-failure recovery path. If it has already run successfully in
production, record that one-time evidence before closing the deployment gate.

**Production evidence (2026-07-17):** the migration has already run successfully in production. The
deploy for merge `3e168bf75` (GitHub Actions run 29549070288's predecessor, run **29547382894**)
completed green 2026-07-17 01:25–01:35 UTC, and the subsequent #1227 deploy also succeeded, so the
schema contraction applied cleanly on the first attempt and no retry-path recovery was needed. The
migrate-before-swap incompatibility window passed without a recorded failure (low-traffic window;
the at-risk surfaces were admin-only). The one-time deployment gate is therefore closed. The durable
fix is process, not code: an expand/contract convention for destructive migrations has been added to
`AGENTS.md` (Laravel 13 structure section) so future drops ship one release behind the code that
stops reading the fields.

### O40 · [P1] TTL-only listing caches can publish a video URL after an operator hides it

PR #1222 removed `SitemapCacheObserver`'s targeted sermon-list invalidation. The surviving
`SermonObserver` does not evict `sermons_service_*`, series or preacher caches when
`video_visibility_override` or `video_quality_status` changes. A cached Sermon therefore retains
its old visibility fields after an operator force-hides or rejects the video.

`SermonController:261-268` passes that cached model to `SermonItemListPresenter`, which emits a
`VideoObject.contentUrl` from `SermonStorageService`'s direct public-storage/CDN URL. The guarded
asset controller correctly denies the current model, but the public object URL bypasses it and can
remain usable after the listing cache refreshes. The same missing targeted invalidation leaves
deleted/reclassified sermons in podcast and listing caches, and leaves newly restricted Pages in
cached navigation/cards (the page route itself remains guarded).

With `[300, 86400]`, all requests can see the stale value for the remainder of the five-minute
fresh interval; after that, normally the first later request plus concurrent races sees it before
the deferred refresh. A quiet key can produce that response at any point before the 24-hour hard
expiry. It is not continuous 24-hour exposure, but a single response is sufficient to disclose the
durable direct URL.

**Suggested fix:** retain TTL-only freshness for ordinary edits, but immediately evict affected
public collections/feed keys on deletion and on access/exposure transitions (`content_type`, video
visibility/quality, Page `admin`/`area`/`navigation`). Add warm-cache regression tests that revoke
visibility and prove no HTML, JSON-LD or RSS response contains the hidden media URL.

### O41 · [P2] Deleting a linked Page can leave its body in the surviving Meeting cache

`PublicReadModelCacheObserver::deleted()` calls `loadMissing('meeting')` only after the Page has
been deleted. The `meetings.page_id` foreign key is `ON DELETE SET NULL`, so an ordinarily resolved
admin-delete Page has already lost that relationship and the observer never forgets the meeting
key. `PublicMeetingReadModelCache` has copied the Page heading, rendered body, descriptions and
image URLs into scalar cached data; `/community/{meeting}` remains routable with no linked Page and
can serve that deleted content from its old read model.

The unit observer test preloads the relation and calls `updated()`, so it does not exercise the
actual delete/FK ordering. The same five-minute/one-later-response SWR qualification from O40
applies.

**Suggested fix:** retain the linked meeting id before the Page delete/FK action and invalidate the
meeting key after commit. Add an integration test that warms the public meeting response, deletes
its Page through the real model path, and proves the next response contains neither its body nor
images.

### O42 · [P2] Google removals bypass the observer that refreshes public meeting events

`GoogleCalendarSyncService:66-67` removes events with a mass Eloquent `delete()`. Mass deletes do
not dispatch per-model events, so `CalendarEventObserver::deleted()` never forgets the affected
`PublicMeetingReadModelCache` keys. A future event removed from Google can consequently remain in
the six cached upcoming events shown on its public meeting page. The sync test asserts only that
the database row disappeared; it never warms or re-reads the public cache.

**Suggested fix:** capture the affected non-null meeting slugs before the bulk delete and explicitly
forget their read models, or delete hydrated models so the observer runs. Add a regression that
warms a meeting read model, syncs an upstream deletion, and proves the next public read omits it.

### O43 · [P2] Calendar sync can race and overwrite a new manual categorisation

PR #1225's preservation rule reads the existing event at
`GoogleCalendarSyncService:117`, decides at lines 145–147 whether it is automatic, then performs a
separate upsert at lines 149–153. If the row is automatic at the read and an administrator manually
categorises it before the upsert, the sync writes its pattern-derived slug and `true` flag over the
new manual choice. The surviving Livewire screen exposes both categorisation and **Sync now**, and
the scheduled sync can overlap either action; sequential tests do not cover the race.

**Suggested fix:** make the decision and write atomic with a transaction plus `lockForUpdate`, or a
conditional compare-and-set that cannot update the categorisation fields once the stored flag is
false. Exercise a concurrent change at the read/write boundary.

### O44 · [P2/deploy] The podcast DTO schema change is not self-invalidating

PR #1227 adds the required readonly `PodcastFeedItemReadModel::$preacherName` property while
retaining the unversioned `podcast_feed_{service}` key. An object serialized by the previous release
rehydrates under the new class with that typed property uninitialised; `rss/feed.blade.php:40-42`
then reads it and throws. The PR's cache-clear note does not close the window:
`.github/workflows/deploy.yml:443` makes the new app public before `cache:clear` runs at lines
451–452, and a failed post-swap clear leaves the incompatible value in place.

**Suggested fix:** version cache keys whenever a serialized DTO schema changes (for example,
`podcast_feed_v2_*`) so deployment correctness does not depend on command timing. Keep the broad
post-deploy clear as hygiene, not as the compatibility mechanism, and pin the versioned rollover.

### O45 · [P2/operational] The Google service account's read-only demotion is unverified

Backlog item 3.5 says the service account **drops write scope**, but PR #1225 only removes the
application's write calls and states that the account can now be demoted on the Google side. The
package's requested API scope cannot be narrowed in this repository; the enforceable least-
privilege change is the calendar-sharing role outside it. This checkout has no evidence of the
production account's current ACL, so the stage cannot claim that operational part complete.

**Suggested fix:** inspect the production calendar share, demote the service account to the
read-only event-details role if necessary, run both scheduled/manual sync once, and record the
role plus successful read. If it is already read-only, close this item with that evidence.

### O46 · [P3] A stale filter cache can delay new sitemap archive URLs by an extra day

PR #1224's daily sitemap calls cached `getSeriesForDisplay()` and `getScriptureBooks()` at
`SitemapService:147/156`. Those methods use `Cache::flexible(..., [300, 86400])`. If the filter
list is in its stale interval when the 04:00 command runs, Laravel returns the old value and defers
the refresh until after the command has written the file. The cache is then fresh, but the sitemap
is not generated again until the following day. A new series or Bible-book archive URL can
therefore lag by nearly 48 hours, rather than appearing the next morning as PR #1224 intended.

**Suggested fix:** have scheduled sitemap generation use fresh/uncached distinct filter queries (or
refresh them before rendering), and test a stale filter cache through the real command lifecycle.

### O47 · [P3] Scheduled sitemap replacement is not atomic

`SitemapService:39` delegates to Spatie's `writeToFile()`, whose installed implementation calls
`file_put_contents()` directly on the live `public/sitemap.xml`. The daily job now truncates and
rewrites a file nginx serves concurrently, so a crawler can receive empty/partial XML; an I/O
failure can also destroy the last-known-good sitemap until another successful run.

**Suggested fix:** render to a sibling temporary file, validate that it is complete XML, then use a
same-filesystem atomic rename. Cover failure-before-rename so the old sitemap demonstrably remains.

### O48 · [P3] `<podcast:person>` silently classifies every preacher as the episode host

PR #1227 emits `<podcast:person>{preacher}</podcast:person>` with no `role`. The official
[Podcast Namespace person specification](https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/tags/person.md)
defines omitted `role` as `host`, so visiting and one-off preachers are now advertised as the
podcast host rather than simply credited as the episode's preacher/speaker. The new test pins the
ambiguous form rather than the intended taxonomy.

**Suggested fix:** decide the correct Podcast Taxonomy role (including how regular and visiting
preachers differ), emit it explicitly, and assert the role as well as the person's escaped name.

### O49 · [P2] The feed advertises one-hour HTTP freshness over a five-minute origin cache

PR #1222 changes the feed's shipped origin-cache freshness to 300 seconds, but
`PodcastFeedController:35-38` still returns `Cache-Control: public, max-age=3600`, and its feature
test pins that value. A conforming client or intermediary may therefore reuse old XML for an hour
without contacting the origin. That defeats the claimed five-minute feed freshness and can keep a
zero-length enclosure response visible client-side after `PodcastFeedService` has already forgotten
its origin key for recovery.

**Suggested fix:** derive HTTP freshness from the configured fresh TTL, or require revalidation if
the feed must react to takedowns and enclosure repair immediately. Test header/config agreement and
the chosen recovery semantics.

### O50 · [P3] Item 3.2 retained the framework-private flexible-cache key it promised to delete

The backlog explicitly calls for deleting the `illuminate:cache:flexible:created:` key hack. PR
#1222 instead centralises and hard-codes it in `app/Support/FlexibleCache.php:11-15`, with a unit
test that now pins Laravel's private key format. Runtime currently works because the installed
framework uses the same prefix, but a framework change can silently stop invalidation from
cancelling an already-deferred refresh.

**Suggested fix:** remove knowledge of the framework-private key. Use only supported cache APIs;
if invalidation must supersede an already-deferred callback, use an app-owned version/generation
key or lock and test the race without asserting Laravel's internal key name.

### O51 · [P3] The Stage 7 source-of-truth documents still describe a state that did not land

`JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md:504` has no **Complete / PR #1227** marker for
3.6 even though #1227's commit message says it is marked on merge. The 3.5 text at lines 500–501
also says the `large`/`small` fallback chain still serves old conversions, while #1225 deliberately
removed those names from `PageImageCacheService` because unregistered conversions throw; the live
fallback is now the original media URL. `WORKSTREAM-3-PUBLIC-READ-PATH-2026-07-16.md:408-412`
retains the same superseded instruction.

**Suggested fix:** mark 3.6 complete with PR #1227, describe the actual canonical-conversion →
original-file fallback, and reconcile/archive the implementation workstream so future agents do not
restore invalid conversion names.

### Review verification

- An independent focused parallel Sail run passed **182 tests (420 assertions)** across all six PR
  surfaces; PHPUnit also reported 12 notices. Three delegated focused runs passed 149/529, 88/366
  and 110/269 tests/assertions respectively; those sets overlap and are not summed.
- `vendor/bin/sail composer phpstan` completed with **0 errors**, and `git diff --check` is clean.
- The cache timing was checked against Laravel 13's installed `Repository::flexible()` source; the
  non-atomic write against the installed Spatie sitemap implementation; migration statement
  boundaries against Laravel's MySQL schema grammar; and Page deletion against the dumped FK.
- Existing tests cover the intended happy paths, but not the deployment compatibility, access-
  transition caches, real Page-delete ordering, bulk-delete invalidation, sync race, stale sitemap
  generation, atomic replacement or old serialized DTO shape described above.
- This was a review-only task: no application fix or production/Google-side mutation was attempted.

## 🟠 Open — needs a fix, not yet owned by a plan

### O11 · Footer "Listen to evening sermons" links to the unfiltered archive

`resources/views/components/layout/footer.blade.php` (~line 15): the link labelled "Listen to
evening sermons" points at `/christ/sermons` instead of `/christ/sermons/evening`. One-line
`href` fix; both routes exist and work. Verified still present 2026-07-05.

**Action:** change the `href` to `/christ/sermons/evening`; keep `wire:navigate`.

### O12 · Seeder inconsistency: "The Prodigal Son" sermon has a completed log but no audio

`SermonSeeder` creates a `MediaProcessingLog` (processing_id `seed-prodigal-son-processing`,
status `completed`, `audio_file_path = sermons/seed/2024-11-24.mp3`) but leaves the `Sermon`
row's `audio_file_path` null, and the referenced file does not exist on the `public` disk. Local
dev/seeded environments render a sermon page with a dead audio player. **Dev-only** as far as
verified — but if the same pattern (completed log, null sermon path) exists in production it
would indicate a completion-transition bug worth checking while in there. **Pathfinder confirmed missing file 2026-07-14.**

**Action:** make the seeder set the sermon's `audio_file_path` and ship (or generate) a small
seed audio file; alternatively mark the seeded log `failed` so the UI states are honest.

### O13 · Heading-image resolution: committed assets invisible to `PageImageCacheService` (investigate before "fixing")

Two Pathfinder crawls (2026-07-05/06) and a follow-up audit (2026-07-14) report pages and `sitemap.xml` missing heading images.
Verified mechanism: `PageImageCacheService::resolveHeadingImageUrl()` resolves (1) Spatie Media
Library `headings` media, then (2) `Storage::disk('public')` at `pages/headings/{size}/{slug}.webp`
— it never reads the committed `public/images/headings/` directory, which is only referenced
*directly* via `asset()` (sitemap sermons image, sermon Blade share images, `page-card` default).
Confirmed 14+ affected pages in 2026-07-14 audit; see `docs/reports/pathfinder-findings-2026-07-14.md`.

**Do not blindly patch the service to read `public_path()`** — the intended primary source is
Media Library, and production pages may well have `headings` media attached (in which case this
is a local/seed-data gap, not a production bug). Investigate first:

1. In production: do `Page` rows have media in the `headings` collection (`media` table,
   `collection_name = 'headings'`)? If yes → the fix is local seeding, not the service.
2. If production pages genuinely resolve to `null` → decide between attaching the committed
   images as Media Library media (one-off import, matching the meetings pattern) or adding a
   `public_path()` fallback to the service.
3. Sitemap half: backlog item 3.4 removes per-page sitemap images entirely — if 3.4 lands first,
   only the on-page rendering half of this issue remains.

### O16 · The production free-Bible request CTA ends at a 404

The original 2026-07-11 UX report diagnosed the `#get-a-free-bible` jump link as a dead CTA. That
was incorrect: it intentionally moves to the section containing the real **Request a free Bible**
button. The real production failure is its destination: `/christ/free-bible` returned the site's
404 page on 2026-07-12.

Current `master` already contains the complete feature: the bespoke
`resources/views/pages/christ/free-bible.blade.php` view, `BibleRequestForm` Livewire component,
mail flow, feature tests, and a `free-bible` row in `PageSeeder`. `PageController::show()` requires
the matching `pages` row before it resolves the bespoke view, so production most likely lacks that
row (or has drifted slug/area data). This is an inference from the code and production behaviour;
verify the production row before changing code.

**Action:** inspect the production `pages` row for `area = christ`, `slug = free-bible`; restore or
correct it through the normal deployment/content process, then verify the page renders and the
form can deliver to the configured public address. Do not build a second form.

### O17 · Find Us omits the address, postcode, and a usable map link from its content

Verified on production 2026-07-12: `/church/find-us` explains parking, buses, and lifts well, but
the body never states the full address or `BR8 8JS` and contains no directions/map link. The
postcode appears only in the global footer. The heading image is a non-interactive map, so it does
not complete the primary task of opening directions on a phone.

**Who benefits:** first-time visitors travelling to a service or event.
**What observably improves:** the address can be copied and directions opened without searching
the footer or manually entering the postcode.

**Action:** put the full address and a prominent external map link near the start of the page.
Adding an exterior building photo would further reduce arrival uncertainty but needs a suitable
maintainer-supplied asset and consent decision.

### O18 · Christianity Explored invites sign-up but provides no response path

Verified on production and current seeded content 2026-07-12: the page ends its invitation with
"Why not sign up" but has no date, contact link, form, or named next step. Its meeting data is
deliberately `Occasional` with no time or location, so the details card cannot compensate.

**Who benefits:** visitors who want to explore Christianity without first attending a service.
**What observably improves:** a visitor can register interest or contact a named person directly
from the invitation.

**Action:** maintainer to decide whether another course is expected. If yes, add a standing
"Register interest" route and copy promising an invitation when dates are known. If no, decide
whether to replace the sign-up promise with a standing one-to-one offer. Reuse an existing contact
mechanism; do not create a speculative course date.

### O20 · Sunday mornings opens with a garbled sentence

Verified on production 2026-07-12: the flagship visitor page begins, "Our Sunday meetings are the
centre of our church life are our Sunday services...". The duplicated clause is also production
content, so correcting a seeder alone may not update the live page.

**Action:** correct the production page content and its source-of-truth fixture/import if one is
maintained. Keep the strong existing reassurance about dress, language, YouTube, and children.

### O21 · Sunday-morning details omit the known time and actionable location

Verified on production and current `MeetingSeeder` 2026-07-12: the page body says the main service
starts at 10:30am, but the details card shows only `Sunday / Crockenhill Baptist Church / All
welcome`. The template already renders a time when `Meeting::start_time` is populated;
`sunday-mornings` has `start_time = null` in both production output and the seeder. The location is
also only a name, with no postcode or directions link.

**Action:** set the canonical Sunday-morning start time to 10:30 in production and maintained seed
data, then verify the card renders it. Coordinate any address/map treatment with O17. Avoid
building against recurrence fields scheduled for removal in backlog item 3.5.

## 🟡 Open — owned by the July 2026 backlog (do not fix separately)

| Issue | Where it lives now |
|---|---|
| O5 · Legacy meeting photo folders `public/images/meetings/*` (gated on prod photo-migration; the `link/` folder is already gone) | Backlog item **2.6** |
| O6 · Redundant `.jpg` heading assets (33 files; the `.webp` siblings are live — prune `.jpg` only) | Backlog item **2.1** (issue-tracker intake block) |
| O8 · Dead `UpdateSermonRecord` job + `UpdateSermonRequest` form request (+ their test files) | Backlog item **2.1** |
| O9 · Dead `SermonValidationService` (+ Unit/Integration tests, stale config comment) | Backlog item **2.1** |
| O14 · Dead `public/images/podcast/*.webp` artwork + unused `PageImagePresenter::headingImageSrcset()` | Backlog item **2.1** (issue-tracker intake block) |
| O19 · Related-page cards surface legal/policy noise, out-of-season events, robotic "Learn about..." labels, and repeated title/description copy | Reassess while implementing backlog item **3.1**, which deletes/folds the current presentation path including `RelatedPagePresenter`; do not patch that scheduled-to-change seam first |

## 🔵 Newcomer-UX opportunities — owned by the active plan

The 2026-07-11 review also produced several coherent opportunities rather than discrete defects.
They are the evidence and intake for
`docs/plans/NEWCOMER-UX-BACKLOG-2026-07-11.md`, which owns their sequencing, dependencies, and
acceptance criteria. Keep findings here and delivery decisions in the plan.

Priority audience: first, someone unfamiliar with Christianity and nervous about attending;
second, a committed Christian new to the area. The initiative should improve one of three journeys:
attend a Sunday service, attend another event, or start learning about Jesus.

### N1 · Create an explicit mobile-first newcomer path

At 390px, the homepage hero occupies most of the first viewport but contains mission-statement
jump links rather than a service time or visit CTA. The main navigation has only Christ / Church /
Community and no newcomer-labelled entry point. Consider a single **New here?** navigation item
and page that assembles existing material: service times, the excellent Sunday-morning
what-to-expect copy, children, parking, and who to look for on arrival. Pair it with a homepage
hero CTA and visible Sunday times; do not rename the three existing top-level areas.

**Who benefits:** nervous first-time visitors, especially on mobile.
**What observably improves:** service time and a what-to-expect route are reachable from the first
viewport and one plainly labelled navigation choice.

### N2 · Surface children's provision before families commit to visiting

The homepage contains no mention of children, while the Sunday-morning page already explains that
children stay for the first part and then join Outback. Reuse a short version of that factual copy
on the homepage and any New here page.

**Who benefits:** parents and carers considering a first visit.
**What observably improves:** they can confirm children are welcome without discovering a deep
subpage first.

### N3 · Add human and arrival trust assets

The Leadership page has warm named biographies but no leader photographs or welcome video. Find Us
has no exterior building photo. Several public cards fall back to the blue/teal generated gradient
(`default.webp`), including otherwise people-focused activities. These are not broken interfaces;
they are an asset-and-consent decision.

**Who benefits:** visitors who want to recognise people and the building before arriving.
**What observably improves:** approved, current photographs replace selected placeholders and make
the arrival and greeting recognisable.

### N4 · Decide whether the site can promise current weekly information

Coffee Cup and Baby Talk are approachable alternatives to Sunday, but the homepage has no dated
"This week" view. Do not build one until a maintainer owns freshness. If approved, start with a
small manually maintained block; only automate after the content proves useful.

**Who benefits:** visitors looking for a lower-pressure first event.
**What observably improves:** the homepage states which approachable activities are actually
happening this week, with dates or an explicit freshness timestamp.

### N5 · Test visitor-question headings on the homepage

The homepage is organised around the church's three mission statements. Keep that language, but
consider adding plain-English scanning labels that answer visitor questions. This is a content
experiment, not a verified defect, and should be evaluated with N1 rather than shipped as isolated
copy churn.

**Who benefits:** visitors unfamiliar with church vocabulary.
**What observably improves:** first-click testing or analytics shows more use of visit, event, and
learn-about-Jesus routes without reducing access to the existing vision language.

**Maintainer decisions before promotion:** consent/appetite for identifiable photographs or video;
an owner for weekly content; and whether Christianity Explored will run again or should become a
standing one-to-one offer.

## 🟢 Investigated — keep, no action

- **O7 · Dual-spelling pastor redirects** — `config/redirects.php` maps both `aboutus/pastor` and
  `about-us/pastor` to `/church/pastor`. Two distinct legacy inbound URLs, not a duplicate.
  Documented so it isn't "cleaned up" by mistake.
- **O15 · `MediaProcessingRequest` abstract form request** — flagged as possibly dead
  (2026-07-06 Mortician); verdict **alive**. It is the base class for the six active media API
  form requests and centralises authorization + processing-id shape validation. Leave alone.
- **2026-07-11 newcomer review corrections** — rechecked against current `master` and production
  on 2026-07-12. The desktop menu is now an opaque, non-overlapping three-column overlay; homepage
  and Find Us hero contrast is sufficient with the current scrims; sermon freeze-frame taste is
  not a defect and the project already has thumbnail selection; the cited 16–30px inline/tall
  links are not by themselves a WCAG target-size failure (inline-text and spacing exceptions
  apply); and adding hero images to every landing page is a subjective redesign, not an audit
  finding. These claims should not be revived without new evidence.

## ✅ Resolved

- **O10 — Unused `<x-icon-button>` component** — removed in commit `aa31358c4` (PR #1024).
- **O1 — Dead mailable `App\Mail\LivestreamProcessingCompleted`** — removed (class, view, test, `AGENTS.md` reference). *(2026-06-18)*
- **O2 — Dead mailable `App\Mail\PermissionError`** — removed (class, view, test, `AGENTS.md` reference). *(2026-06-18)*
- **O3 — Dead asset directory `public/images/photos/`** — deleted (38 unreferenced files). *(2026-06-18)*
- **O4 — Duplicate `/christ/sermons` sitemap entry** — `SitemapService::addPages()` now excludes the christ-area `sermons` page; covered by `SitemapTest`. *(2026-06-18)*
- **R1 — Broken admin delete link** on the sermon detail page — fixed 2026-06-14.
- **R2 — `contacttus` redirect typo** — corrected to `contactus` in `config/redirects.php`.
