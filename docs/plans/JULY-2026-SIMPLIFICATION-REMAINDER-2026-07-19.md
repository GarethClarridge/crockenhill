# July 2026 Simplification Backlog — Remainder Implementation Plan

> **Progress update (amended 2026-07-29): R1–R11 are merged; R12–R15 remain.** Verified against the live
> code, not against commit messages: the 11 heuristic services and 6 jobs are gone,
> `ServiceStructureMode` has only `Shadow`/`Primary`, `scripts/section-extraction/` is gone
> (R9); `VisualAnalysisService`/`SongClusteringService`/`PerformVisualAnalysis`/`ExtractVideoFrames`
> are gone (R10); `CreateSermonTranscriptFromService` replaces the second Whisper pass on the
> livestream/video chain, `TranscriptionAudioProfile` is the single audio-prep owner, and 1.7c/1.7e
> landed — with **1.7f also delivered** (`ce4e3178c`), although this plan had left it unscheduled
> (R11). R1–R7 merged 2026-07-20/21 (`9fe4590a9`..`10063f9c6`).
>
> **R8 is the exception: its *documentation* is complete, its *deletions* are not.** Every gate row
> in the R8 table below still reads BLOCKED/PARTIAL except the preacher-cutover and WebP rows, and
> no one-shot tool has been deleted. R8 is now effectively an operator data-convergence workstream
> (see `docs/operations/r8-data-convergence-runbook.md`), and several of its rows have been
> overtaken by the historic-archive plan — see the ownership note under R12.
>
> **Still open:** R12 (ownership moved again on 2026-07-31 — it now belongs to
> [HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md](HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md),
> the single implementation plan and production gate; the historic-media and R8 documents this plan
> used to point at are decision records only and must not be executed by their own work-package
> numbering. As of 2026-08-07 that plan's implementation has landed but its rehearsal has not
> started, so **R12 remains blocked and no one-shot may be deleted**),
> R13, R14 (all five duplicate suites still present), R15. **R9–R11 merging releases the gate on
> WP7 of [CODE-QUALITY-REMEDIATION-2026-07-19.md](CODE-QUALITY-REMEDIATION-2026-07-19.md)** (the
> PHPStan level-9 ratchet) and on
> [SERVICE-WORKBENCH-REDESIGN-2026-07-23.md](SERVICE-WORKBENCH-REDESIGN-2026-07-23.md), which has
> since largely landed.
>
> **Status (2026-07-19): approved to start.** The D22 promotion soak (backlog items 1.2 + 1.4)
> **passed** — maintainer sign-off 2026-07-19. This plan is the just-in-time implementation plan
> the backlog's protocol requires for the remaining `[design]` items, plus the execution sequence
> for the remaining `[mechanical]` and `[operational]` items. It was written against commit
> `4748bcd81` with every claim below re-verified against the live code (the backlog doc itself is
> stale in several places — see "Verified state corrections").
>
> **Dependencies:** parent tracker is
> [JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md);
> read its Implementation protocol first. Cited review sections live in
> `docs/reviews/july-2026-simplification/`.
>
> **Agents must not, without maintainer input:** (a) run any production check or production
> command themselves — every ☐ gate below is operator-executed (see
> `docs/plans/production_audit_workflow` conventions: public repo, counts only); (b) start item
> R6 (canonical-conflict collapse) before the sequencing decision in its section is resolved;
> (c) delete `HistoricVideoImporter` (R12) — its gate closes last, after the bulk backfill;
> (d) schedule R11 (1.7f schema-field features) — operator-appetite only.

## What remains (one-screen summary)

| # | Item (backlog ref) | Tag | Blocked by | Status (2026-07-24) |
|---|---|---|---|---|
| R1 | Bookkeeping: record soak pass, tick gates, fix stale statuses | mechanical | — | ✅ `9fe4590a9` |
| R2 | 1.3 auto-trim migration to the LLM path | design | — (mode is already primary) | ✅ `953bbc758` |
| R3 | 4.4 CRUD consistency pass (verified residual scope) | mechanical | — | ✅ `e314fb52b` |
| R4 | 4.5 authorization gates cleanup (verified residual scope) | mechanical | — | ✅ `c2850d36f` |
| R5 | 5.5 timeline family relocation | mechanical | — | ✅ `f0bf31d59` |
| R6 | 5.1 canonical-conflict state collapse | design | sequencing vs. review-queue-noise plan | ✅ `10063f9c6` (noise plan landed first; no conflict) |
| R7 | 5.2 one-call OOS email parsing | design | — | ✅ `7d718f499` |
| R8 | 2.4 legacy importer/backfill sweep + 2.6 platform one-shot sweep | mechanical | per-item prod gates | ◐ **evidence + runbook done (`c648a79b3`, `bd8b6b063`); zero deletions executed** — every gate below still BLOCKED/PARTIAL |
| R9 | 1.5 delete the church-service heuristic cluster | mechanical | R2 | ✅ `ae5f0e188`..`a130092ee` |
| R10 | 1.6 delete the media visual stack + song clusters | design | R9 + re-homing pre-work | ✅ `b9af5930b` |
| R11 | 1.7a/b/c/e consolidations (1.7f unscheduled; 1.7d closed) | design | R10 (1.7a/b/c); 1.7e last | ✅ `f332427ea`, `0681beaa1`, `05e343ceb`, `8875019e0` — **plus 1.7f, delivered anyway (`ce4e3178c`)** |
| R12 | Bulk historic backfill (~500) → `HistoricVideoImporter` deletion (2.5) | operational→mechanical | 1.7a | ☐ **open — ownership moved** to the historic-archive plan (see note below) |
| R13 | 5.3/5.4 deferred re-measures | design | soak data recorded in R1 | ☐ open — R1 recorded the D6/5.3 observations as *not captured*, so R13 starts with a measurement window |
| R14 | 7.1 duplicate-suite fold-ins + 7.2 conventions in `AGENTS.md` | mechanical | R9/R10/R8 | ☐ open — all five flat suites still present; R9/R10 no longer block it |
| R15 | Archive trackers; hand off to Phase 9 | mechanical | R14 | ☐ open |

Suggested batching: **R1 → {R2, R3, R4, R5, R7, R8 in parallel} → R9 → R10 → R11 → R12 → R13/R14 → R15.**
R6 slots wherever its sequencing decision lands.

**R12 ownership (amended 2026-07-29).** R12's "process the ~500-item historic backlog" is local
media acquisition in
[HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md](HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md),
whose artifact-retention baseline has landed. Its remaining HM work adds isolated processing,
explicit source identity, complete output readiness and portable Bundle A. **Do the acquisition
work from that plan, not from R12's paragraph.** Canonical Email/OpenLP/Livestream projection,
Bundle B review transfer and the sole production sequence belong to
[R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md](R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md).
`ImportHistoricVideoBatchCommand` and `HistoricVideoImporter` close only after Bundle A and Bundle B
promote, exact audits pass and the rollback window expires.

**R14 note (2026-07-24):** the workbench redesign deliberately routed its new coverage into
`tests/Feature/Livewire/Admin/ChurchServices/` in preparation for this fold-in, but it also
*modified* `tests/Feature/Livewire/AdminChurchServiceTest.php` (`98dd4cab5`), so re-diff that file
rather than assuming its assertions are unchanged since 2026-07-19.

## Verified state corrections (2026-07-19)

The backlog document under-records what has already shipped. Do **not** re-plan or re-execute
these:

- **Workstream 4 items 4.1, 4.2, 4.3 are done**, absorbed by the service-UI consolidation and
  follow-ups: `App\Livewire\Admin\MediaUpload` exists with a backed `App\Enums\UploadState`;
  `ProcessingLogService`, `ProcessingLogEntry`, `ProcessingLogCollection`, `ProcessingLogsViewer`
  and the standalone `ProcessingReview` component no longer exist anywhere in `app/`; retired
  per-run review URLs redirect (commit `7e483b437`). Only the 4.4/4.5 residue below is open.
- **Workstream 6 is done in full** (PRs #1188/#1191): migrations are squashed
  (`database/schema/mysql-schema.sql` + 5 post-squash migrations); the five stock config files,
  `debugbar.php`, the `auth.php` `api` guard and dead `calendar.php` keys are gone; `livewire.php`
  is v4-shaped with the two deliberate overrides; `church.php` and `health.php` exist,
  `sermons.php`/`opening-hours.php`/`organization.php`/`monitoring.php` do not. 6.5's
  `AGENTS.md` fixes and the one-shot retirement convention are in.
- **Parts of 4.4 are done**: `App\Actions\ServiceReview\MarkServiceReviewed` is the shared
  action; `ListUsers` already uses `WithFilterableListing`; `ServiceReviewDashboardQuery` already
  carries its post-rename name.
- **Parts of 7.1 are done**: the `PublicSongUsageServiceTest`, `SongLyricSnippetBuilderTest`,
  `PublicMeetingReadModelCacheTest` and `SermonViewPresenterTest` duplicates are already single
  files (deleted with 2.1/3.3). The surviving pairs are listed under R14.
- **Workstream 1 seams 1.1a–1.1d merged** (#1156–#1159, 2026-07-12); `structure:evaluate` and
  `structure:shadow-report` exist as the successor regression mechanism.
- Still present and still to do, confirmed by file existence: all 11 heuristic services + 6 jobs
  of the 1.5 list, `scripts/section-extraction/`, `ServiceStructureMode::Off`,
  `VisualAnalysisService`, `SongClusteringService`, every 2.4/2.6 one-shot tool,
  `MeetingPolicy`, the 800-line `OosEmailParserService`, the 167-line
  `ChurchServiceReviewStateService`, and the four timeline classes in `app/Support/`.

---

## R1 — Bookkeeping (do first; ~30 min)

1. In the backlog doc: tick the 1.4 soak checklist line (maintainer sign-off 2026-07-19), mark
   1.2/1.4 complete, and correct the stale statuses listed above (4.1–4.3, Workstream 6) so no
   future session re-derives them.
2. Record the D6/5.3 soak observations where R13 can find them: the count of
   pending-structure-merge occurrences during the soak and the operator's accept/reject choices
   (backlog 1.4 gate required these; if they were not captured, say so explicitly in the 5.3
   section rather than inventing them — R13 then starts with a measurement window instead).
3. Update `docs/plans/README.md`: point the spine entry at this plan for execution order.
4. Note in `docs/operations/llm-structure-promotion-soak.md`'s header that the soak completed
   and the runbook is now historical (Stage summaries stay useful for the R12 backfill).

## R2 — Item 1.3: auto-trim migration [design]

**Why now:** production is already in primary mode, so backlog "shape (a) — swap at the flip"
and "shape (b) — mode-independent path" have converged: a straight swap is the simple correct
shape. This is the **only remaining code gate before R9** — the four classification jobs it
removes from the auto-trim chain are on the 1.5 deletion list.

Current chain (`app/Services/Processing/ProcessingPipelineBuilder.php:99`):
`ValidateVideoFile → GenerateRmsLog → AnalyzeSegments → ClassifyServiceSections →
TranscribeSpeechSegments → ClassifySpeechSections → ReclassifyIntroOutroSections →
ExtractSermon → … `.

**Target chain:** `ValidateVideoFile → GenerateRmsLog → AnalyzeSegments →
TranscribeFullService → DetectServiceStructure → ExtractSermon → …` (AnalyzeSegments **stays**
until R10 — it still produces the `sermon_start_time`/`sermon_end_time` baseline that
`SermonExtractionPlanResolver` reads, and its no-sermon-candidate failure gate).

Steps:

1. Read church review §4.2.5 in full, then verify in code exactly which guards restrict
   `TranscribeFullService` and `DetectServiceStructure` to livestream runs. Widen **only those
   two guards** to accept auto-trim `MediaType::Video` runs. Do not widen projection, song, or
   publication guards — they are not in the auto-trim chain and must not start firing for
   auto-trim uploads.
2. Confirm what `ExtractSermon` / `SermonExtractionPlanResolver` actually consume in the
   auto-trim path. The design intent: extraction boundaries keep coming from `AnalyzeSegments`'
   baseline (unchanged), while the LLM structure replaces the classification refinement. If code
   inspection shows the four removed jobs feed extraction *inputs* (not just section metadata),
   **stop and flag** per the backlog's stop rule.
3. Update the pipeline-chain tests that pin the auto-trim sequence
   (`ProcessingPipelineBuilderTest`, `ProcessingPhaseRegistryTest`, plus any test asserting
   auto-trim is mode-independent — the soak runbook Stage 0 notes these pins exist). Update
   `ProcessingPhaseRegistry` rows/offsets for the auto-trim pipeline in the same PR (memory:
   inserting/removing chain jobs ripples every `job_offset`).
4. Add one characterisation test: an auto-trim video run in primary mode produces a sermon with
   plausible boundaries and the expected step sequence, using the mock detector.
5. Quality gates; Dusk not needed unless the upload form surface changed (it shouldn't).

**Operational follow-up:** the next real auto-trim upload in production is the acceptance check.
One clean run → R9 unblocks (the soak already proved the shared LLM path).

## R3 — Item 4.4: CRUD consistency pass [mechanical] (verified residual scope)

Read admin review F4/F5/O2/O3 first. Remaining sub-items, verified open:

1. **`service-tracking.enabled` route-group middleware.** `abortIfDisabled()` copies live in
   `ListChurchServices`, `ShowSong`, `ListSongs`, `ShowChurchService`, `ReviewInbox`,
   `SubmitEmailText`, `UploadChurchService`, `ManageChurchService` (Livewire) **plus**
   `PublicSongListController` and `MailgunInboundWebhookController`. Add a small
   `EnsureServiceTrackingEnabled` middleware; apply via route group for the admin pages and
   per-route for the public song list and the webhook (the webhook should keep returning its
   current disabled-state response shape — verify before swapping, an aborting middleware may
   change what Mailgun sees on retry).
2. **`ManagesSectionPublication`**: replace the three `method_exists($this, 'authorizeAdmin')`
   guards (lines 18/43/67) with `use WithAdminAuthorization;` in the trait and direct calls.
3. **`ListChurchServices` onto `WithFilterableListing`** (currently `WithSortableListing`) —
   align with the seven sibling list components that already use it.
4. **Structural test**: extend `AdminLivewireComponentsUseTraitTest` to assert per-action
   `authorizeAdmin()` calls (admin F3's durable fix). Check what it already asserts first.
5. **Sermon-delete convergence**: `ListSermons` (Livewire delete) and
   `SermonAdminController::destroy` (`routes/web.php:139`) both exist. Converge on the Livewire
   path; retire the controller route or reduce it to a redirect — check for external references
   (emails, docs) to the POST route first.
6. Named eager-load scope for church-service items; inline or docblock
   `ReviewsServiceSections`; document the `AdminListComponent` recipe in `AGENTS.md`.

Skip (already done): shared `markServiceReviewed()`, `ListUsers` alignment, query rename.

## R4 — Item 4.5: authorization gates cleanup [mechanical] (scope shrunk by drift)

The old backlog text no longer matches the code: there are **no** `@can('manage-*')` blocks in
views, no `Gate::define` calls, and no `UpdateMeetingRequest`; `MeetingController` is a
public show-only controller (`routes/web.php:79`) that must **stay**. Verified remaining scope:

1. Grep for every `MeetingPolicy` consumer (`authorize(`, `can(`, `Gate::`, `->cannot(`) —
   expected result: only the `AuthServiceProvider` registration and `MeetingPolicyTest`.
2. Delete `app/Policies/MeetingPolicy.php`, its registration line in `AuthServiceProvider`
   (leave `SermonPolicy` — it backs the Sermon create Gate used by the upload flow), and
   `tests/Integration/Policies/MeetingPolicyTest.php` in the same commit.
3. Audit `tests/Integration/AuthorizationGatesTest.php`: keep assertions that cover live
   behaviour (`canAccessAdmin()`, SermonPolicy), delete only what asserted the removed policy.
4. Then archive `docs/architecture/simplification-backlog.md` (the backlog says to archive it
   once 4.5 lands) with a pointer header.

## R5 — Item 5.5: timeline family relocation [mechanical]

Move `ProcessingRunTimelineBuilder` (192), `ServiceRecordTimeline` (353), `ServiceFlowBuilder`
(344), `ServiceTimelineBuilder` (49) from `app/Support/` into the church-service domain
namespace; merge the 49-line `ServiceTimelineBuilder` pass-through into `ServiceFlowBuilder`
while moving. Apply the R3 namespace-move checklist (memory): fix explicit `use` statements,
external siblings, the moved files' own sibling references, **and grep Blade templates/strings
for inline FQCNs** — then run the full suite, which is the only net that catches Blade misses.
Do **not** re-proportion content — that waits for R9 thinning the timeline steps. Decide the
target namespace by looking at where `ServiceSectionConfidence` and the projection services
live (`app/Services/ChurchService/…` or `app/Support/ChurchService/…` — follow the majority).

## R6 — Item 5.1: canonical-conflict state collapse [design] — **sequencing decision required**

**Conflict of jurisdiction:** the
[REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md](../archived-plans/REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md)
plan owns the review-predicate semantics of exactly this state (its evidence base found 401/401
services with canonical-conflict "detected"), and 5.1 owns its storage. Landing 5.1 first would
rewrite columns the noise plan's Workstream A predicates read; landing noise-A first means 5.1
collapses a freshly-touched surface. **Recommendation: land noise-plan Workstreams A/B first,
then execute 5.1 against the settled predicates** — the noise plan is smaller, already
verified, and improves the operator's daily queue immediately. Confirm with maintainer before
starting either.

5.1 execution shape (write the detailed diff-level plan only after the sequencing decision):
per church §4.5/R3 — pick column storage; shrink to `needs_review` + one human-readable reason
string; keep `canonical_conflict_history` JSON as the audit trail; delete one enum, most of a
second, six columns' ceremony, and most of `ChurchServiceReviewStateService` (167 lines).
Touched files (verified): `ChurchService` model, `MarkServiceReviewed` action,
`ChurchServiceImportMetadata`, `ChurchServiceReviewSynchronizer`,
`ChurchServiceReviewStateService`. Column drops must use expand/contract migrations
(`AGENTS.md` rule adopted after the 3.5 deploy).

## R7 — Item 5.2: one-call OOS email parsing [design]

Per church §4.7/R4 + opportunity 5. `OosEmailParserService` is 800 lines; ~300 are date/service
regex extraction. Steps:

1. Read the current `OpenAiOosEmailItemExtractor` (the pattern to extend) and map exactly which
   `OosEmailParserService` methods are regex date/service extraction vs. item parsing vs.
   validation.
2. Extend the extractor's typed schema to return `{date, service, items, confidence}` in one
   call. Keep the **deterministic validation gate** exactly as specified: date parses, service
   in enum, future-dated tolerance, existing confidence thresholds.
3. Delete the regex date/service path; keep whatever the deterministic gate needs.
4. Tests: rewrite the parser tests around the new seam using the existing mock-extractor
   pattern; keep every fixture email the current tests exercise (informal formats are the
   cases the LLM is expected to *improve* on — the fixtures become the eval evidence). Run the
   real-detector spot-check on 2–3 archived inbound emails locally before merging (the OOS
   archive import made these available).
5. Risk note: `ProcessInboundOosEmail` and the Mailgun webhook are the callers — verify the
   failure path still lands emails in the manual queue, not a silent drop, when the LLM call
   errors.

## R8 — Items 2.4 + 2.6: gated one-shot deletions [mechanical]

Each deletion PR: delete tool + companion + tests in one commit, record the pre-deletion git tag
in the PR description. **Every gate is operator-run** (production checks; counts only in any
public output). Verified all tools still exist. Suggested order — gates first, deletions batched
by gate result:

| Delete | Gate (operator) | Status |
|---|---|---|
| `LegacySermonImporter` + `ImportLegacySermonBatchCommand` (~1,520) | Confirm tape digitisation and asset-backed promotion of valuable local-only MP3s finished for good | **BLOCKED 2026-07-20:** local has 830 sermons vs production's 704; 808 local legacy MP3 records point at verified Spaces objects, but row-level reconciliation and a safe portable promotion command are not yet complete |
| `GenerateProdSermonPatchCommand` (669) | Stale patch formally abandoned; every local-only sermon promoted/rejected; local→prod row merges never again | **BLOCKED 2026-07-20:** do not run the May patch (711 inserts/99 updates). Its Spaces paths are real, but its date/service matching, missing preacher IDs, omitted provenance/relations, disabled FKs and lack of transaction remain unsafe |
| `PreacherCutoverCommand` + service (387) | `sermons WHERE preacher_id IS NULL` count = 0 | ✅ 2026-07-20: `0` |
| `LegacyPlayDateSongUsageImporter` + command (~700) | play_date backfill confirmed complete | **BLOCKED 2026-07-20:** `6,203` source rows are not accounted for by the importer's own two skip predicates |
| `LegacySongReconciler` + `reconciledSongId` thread + schema probes (~500) | `Song::withTrashed()` null/blank/`legacy-song-%` canonical-key count = 0 | **BLOCKED 2026-07-20:** `1,207` rows remain |
| `songs.alternative_title` column + `play_date` table | After both song rows above. **`praise_number` and `alternate_title` are live — do not touch either.** Expand/contract migration | **BLOCKED:** both prerequisite song gates are open |
| `ConvertJpgToWebp` + test | Confirm the repo-wide conversion path is spent | ✅ 2026-07-20: 41 JPGs remain but zero code references would change; classified below. Do not run the real converter |
| `ImportOpenLpDirectoryCommand` + test | Confirm the complete historic `.osz` archive is imported/reviewed in production and no further imports are expected | **BLOCKED 2026-07-20 (local rehearsal advanced):** the original archive is located and checksummed. Its 536 files contain 105 byte-identical duplicates, leaving 431 unique sources; operator curation retains 428. The curated local dry run processed all 428 with 29 creates, 399 updates, 21 review outcomes and 0 failures. Production remains untouched; finish the local review/apply/idempotency gate, then repeat in production |
| `BackfillMediaProcessingIdentityCommand` + test | Dry run reports `Would update = 0` | **BLOCKED 2026-07-20:** 36 of 41 candidates would update; 5 have no usable metadata |
| `FixUploadDirectories` | Runtime roots writable; production entrypoint owns permissions; confirm no external provisioning invokes it | **PARTIAL 2026-07-20:** all four mounted roots exist and are writable as `www`; external automation confirmation remains |
| `MeetingMigratePhotosCommand` + `MeetingPhotoMigrationService` + `public/images/meetings/{1150,baby-talk,bible-study,buzz-club,coffee-cup,sunday-services}/` (verified still present) | Confirm production photo import completed (photos serve from Spatie Media Library) | **PARTIAL 2026-07-20:** 0 to migrate / 48 skipped / 0 errors, but the six-pair `sunday-services` folder was not examined because no current meeting has that slug |
| `ExtractVideoFrames` + `ExportVisualMetricsCommand` | None — they die with R10 regardless; delete there, not here | rides R10 |
| `ImportOosArchiveCommand` + `OosArchiveEvaluator` + `OosArchiveMarkdownParser` + their 3 test files (~2,100 lines; added 2026-07-19 by the Phase 9 review, F3.2) | Production import/idempotency run passes after `.osz` import; maintainer confirms the paper archive is final | **BLOCKED 2026-07-20:** local has 2 email-sourced services; production has none |
| `BackfillSongPraiseNumbersCommand` + test (added 2026-07-19 by the Phase 9 review, F3.2) | Operator confirms prod praise-number backfill + `service-tracking:link-songs` ran after PR #1171 | **BLOCKED 2026-07-20:** praise-number drift is 0, but song-link drift is 3 (`3` updates / `0` clears). Deletion must also update `AuditBackfillsCommand`/`BackfillAudit` so they do not name a deleted remedy |
| `ImportHistoricVideoBatchCommand` + `HistoricVideoImporter` (~1,500) | **Closes last** — after the R12 bulk backfill completes | **DEFERRED by operator 2026-07-20:** the necessary historic-video source set has not yet been gathered; no import was dispatched. Resume only after the operator declares a selected batch complete; still rides R12 |

Correction (2026-07-20): the earlier R8 row incorrectly scheduled `songs.praise_number` for
deletion. It is live data: `SongCatalogSyncService` writes it and `SongTitleResolver` reads it as
an active matching rung. Preserve it alongside `songs.alternate_title`; only the legacy spelling
`songs.alternative_title` is deletion-scheduled.

### R8 production evidence and remediation order (2026-07-20)

Operator execution, local/production convergence, rollback boundaries and private evidence handling
are defined in
[`docs/operations/r8-data-convergence-runbook.md`](../operations/r8-data-convergence-runbook.md).

#### Local operator checkpoint — session ended 2026-07-20

Production was not accessed or mutated. Resume from this checkpoint rather than repeating the
completed local work:

- A verified compressed pre-R8 local database backup, source checksums and private reports are
  retained under the gitignored `storage/scratch/r8/20260720-182803/` evidence directory.
- The authoritative songs SQLite was synced locally: 1,173 source rows collapsed to 1,159
  canonical songs (10 created, 1,149 reconciled). Song linking then applied 3 updates and its
  idempotency dry run reported 0 updates / 0 clears. Required local catalogue, link-drift and
  scripture-filter gates are now clean; speaker-profile and missing-passage counts remain advisory.
- The original OpenLP archive was mounted read-only from the external drive. It contains 536
  files, of which the 105-file nested subset is byte-identical to files at the root. The 431-file
  unique set was curated by the operator into 428 intended imports: 7 ambiguous filenames received
  explicit date/service aliases and 3 sources were explicitly excluded. The private curated
  manifest and decisions are in the evidence directory.
- The curated `.osz` dry run reported 428 processed, 29 creates, 399 updates, 21 review outcomes
  and 0 failures. The 21 are accounted for: 18 services already carried local review flags, one
  additional livestream/OpenLP structure merge would be staged, and two email/OpenLP canonical
  conflicts would auto-merge. The operator explicitly accepted both email auto-merges because
  OpenLP is authoritative over email-derived plans. Before the real local import, inspect or
  explicitly accept the remaining 18 existing flags and the one new livestream merge; then run
  the real import and an idempotency rehearsal.
- The temporary create-only sermon promotion exporter/importer is implemented locally with focused
  coverage and zero PHPStan errors. Its 11 focused tests pass (83 assertions). The full suite ran
  5,811 tests with one reproducible unrelated failure in `AdminAttentionCountsTest`: its fixture
  chooses a random section type although the assertion requires a structurally reviewable type.
- Historic-video import is deliberately out of scope for the next resumption until the operator
  has gathered and classified the required source files. The scratch-root filename dry run was not
  a valid batch and dispatched no work.

Next session: inspect/decide the 19 remaining local OpenLP review cases, apply the curated archive,
run its idempotency check, and only then plan the production maintenance window. Do not begin the
historic-video phase.

Counts below are private operator evidence; publish only the totals:

- The old sermon patch must not be run: production has 704 sermons (2012-06-03–2026-01-25)
  against local's 830 (2003-08-30–2026-06-28), with 8 vs 17 duplicate stable-identity groups.
  Counts do not identify the candidates. Local's 808 `audio_upload` sermons all use distinct
  canonical `sermons/audio/...` keys, and the configured `do_spaces` audit found all 823 referenced
  audio objects present. Implement a temporary, tested JSON promotion exporter/importer that
  verifies the shared non-secret Spaces fingerprint, source SHA-256/size, preacher remapping and
  conflicts before a create-only transaction. Preserve one real provenance log per promoted row;
  regenerate natural-value scripture filters; never copy local numeric IDs. Re-import original
  media only when an object/provenance cannot be verified. The three failed-log sermons (local IDs
  35–37) and the missing transcript on local sermon 39 require explicit operator decisions.
- Production has only 2 OpenLP church services and no email-sourced service, against local's 395
  OpenLP + 2 email-sourced services. Locate and checksum the original `.osz` archive, import it in
  production first, then run the Markdown archive's create-only import. Keep both one-shots until
  their production idempotency/review gates pass.
- Preacher cutover passed: zero sermons lack `preacher_id`.
- The song catalogue is still predominantly pre-cutover: 1,207 null/blank/placeholder canonical
  keys remain. Run `service-tracking:sync-songs --dry-run` against the authoritative current
  OpenLP SQLite source, review the reconciliation report, take a production DB backup, then run
  the real sync. Re-run the exact three-part count; it must reach zero before deleting the
  reconciler or its `reconciledSongId` path.
- The `play_date` import is not complete: 6,203 rows fail both of the importer's accounting
  predicates. Do this **after** catalogue reconciliation so legacy song IDs are preserved: dry-run
  `service-tracking:import-legacy-song-usage` against an authoritative production `play_date`
  dump, then run it for real and repeat the accounting query until it returns zero.
- Praise-number population itself is complete (`0` updates; 2 already set; 1,205 titles have no
  number). Song linking is not: the exact audit reports 3 links to update. Run the real
  `service-tracking:link-songs` only after the catalogue sync and `play_date` import, then require
  both `songs_missing_praise_numbers = 0` and `song_link_drift = 0` from `backfill:audit --json`.
- Media identity backfill is not complete: run the real
  `media-processing:backfill-extracted-identity`, then repeat the dry run. `Would update` must be
  zero; the five rows with no usable metadata may remain as an accepted residue.
- Meeting migration passed for every folder whose slug still maps to a Meeting (0 to migrate,
  0 errors). The legacy `sunday-services` folder contains six JPG/WebP pairs but was invisible to
  the command because current meetings use different slugs. Before deleting that folder, verify
  all 12 files have corresponding Media Library rows attached to the intended current meeting(s)
  and visually check those public meeting pages.
- The WebP dry run found 41 JPGs and zero source-reference rewrites: 26 are counterparts in the
  deletion-scheduled meeting folders, 2 are intentionally live podcast artwork referenced from
  `config/podcast.php`, and 13 are stored sermon thumbnails whose database paths must remain.
  This confirms the repo-rewriting command is spent; deleting it must not delete or rename the
  podcast artwork or stored sermon thumbnails.
- The Docker directory check passed for `storage/app/livewire-tmp`, `storage/app/temp`,
  `storage/app/public`, and `storage/logs`. Confirm separately that no host cron/systemd/operator
  provisioning invokes `upload:fix-directories`; do not run the mutating command as a test.

For the later song schema contract, the reconciler's null/blank/`legacy-song-%` predicate and the
`play_date` accounting predicate above are separate gates. Release A must first remove the last
runtime reads/writes; only a later Release B may drop `songs.alternative_title` and `play_date`.

## R9 — Item 1.5: delete the church-service heuristic cluster [mechanical]

**Do not start until** R2 has merged and one production auto-trim run is clean. The noise plan's
"must not touch" list becomes moot here — but if noise-plan work is in flight on review
surfaces, coordinate merges to avoid conflicts on `ReviewInbox`/workbench files.

Scope (all verified present): 11 services in `app/Services/ChurchService/`
(`StructuralSectionAligner`, `SpeechSectionClassificationService`, `SongSectionAligner`,
`OosAlignmentService`, `ServiceSectionClassifier`, `ReadingReferenceExtractor`,
`SectionItemAlignmentScorer`, `SectionAlignmentBaselineRestorer`, `AlignmentTriggerCalculator`,
`PresentationItemClassifier`, `MediaInterludeCueDetector`), 6 jobs (`ClassifySpeechSections`,
`ResolveReadingReferences`, `TranscribeSpeechSegments`, `ReclassifyIntroOutroSections`,
`ClassifyServiceSections`, `AlignWithOos`), the heuristic branches of
`ProcessingPipelineBuilder`, `ServiceStructureMode::Off`, `scripts/section-extraction/` +
`SectionExtractionScriptsTest`, and the heuristic-path test estate named in church §4.9.

Commit discipline (each commit independently green; references before referents):

1. One commit per job deletion, bundling: the job class, its `ProcessingPipelineBuilder`
   branch, its `ProcessingPhaseRegistry` imports/phase-rows/anchor-offsets, its dedicated test
   file, and updates to `ProcessingPipelineBuilderTest`/`ProcessingPhaseRegistryTest` and the
   ~14 test files importing job classes (list is in church §4.9 — re-grep, it will have
   drifted). Order jobs so no commit leaves a dangling reference.
2. Then the now-unreferenced services, in dependency order (grep before each: the noise plan or
   corpus-follow-up work may have added callers since the review — the backlog's stop rule
   applies).
3. Then the mode-enum collapse: remove `Off`, leaving `Shadow`/`Primary`. Check every
   `ServiceStructureMode` match/case site and config default (`media-processing.php`), and the
   `.env.example` guidance. Decide the new default (`primary` — matching production).
4. Then `scripts/section-extraction/` + its test.
5. Sweep for dead branches: the workbench `reclassify()` affordance and
   `timeline-alignment-*` partials (admin §9); `MatchSongsFromTranscript`'s
   `OosAlignmentService` type-hint (1.1b flagged it for removal at flip time — verify it's
   still referenced, then strip).
6. Implementation facts to honour (recorded during 1.1, memory-backed): the reconcile re-run
   never re-opens completed runs; the transcript artifact survives cleanup but the RMS log does
   not, so reconcile re-detection loses silence-snapping gracefully — do not "fix" this while
   deleting.

Expected size: ~5,000 production + ~8,900 test + 1,123 script lines. Full quality gates plus
Dusk (upload form path). One PR per commit-group is acceptable; a single stacked PR series is
preferred so CI proves each stage.

## R10 — Item 1.6: media visual stack + song clusters [design]

**Do not start until R9 is merged.** This is a segmentation migration, not a free deletion.

### Implementation addendum (2026-07-21)

R9 is merged. `DetectServiceStructure` is now the only producer of automatic sermon bounds:
after primary-mode validation it writes the eligible LLM sermon section to
`sermon_start_time`/`sermon_end_time`; validator failures already take the existing manual-review
route with the persisted RMS speech blocks. The primary-mode fixture-to-extraction-plan
characterisation in `DetectServiceStructureTest` pins this hand-off. R10 therefore removes the
visual producer and the RMS longest-speech candidate gate, while retaining RMS segmentation for
silence snapping and manual review. The named database columns remain for this deploy's contract
phase and will be dropped only in a later release after this code is live.

**Pre-work (the actual design task):** `AnalyzeSegments` still consumes
`$this->processingLog->song_clusters` (line 296) and produces (a) the no-sermon-candidate
failure gate and (b) the `sermon_start_time`/`sermon_end_time` baseline read by
`SermonExtractionPlanResolver`. Re-home both onto the LLM structure:

1. Derive the sermon-boundary baseline from the primary-mode `ServiceStructure` (the detected
   sermon section's bounds, snapped by the surviving RMS silence data).
2. Derive the failure gate: "LLM structure contains no sermon section of plausible length" →
   route to manual review via the existing `awaitingManualSermonReview` path (respect the
   20-min minimum + 1.5× ratio confidence guard — memory: livestream extraction thresholds).
3. Land a **characterisation test on primary-mode segment boundaries** (fixture structure in →
   expected extraction plan out) *before* removing the producer.
4. Then delete: `VisualAnalysisService` (881), `PerformVisualAnalysis` (326) + its
   `buildLivestreamParallelJobs` branch and the `media-processing.visual_analysis.enabled`
   config key, the visual/cluster halves of `VideoSegmentationService` and `AnalyzeSegments`,
   `ExportVisualMetricsCommand`, `ExtractVideoFrames`, `SongClusteringService` +
   `SongCluster`/`SongClusterCollection`/`SongClusterCollectionCast`, and the
   `song_clusters`/`visual_confidence`/`visual_sample_count`/`calibration_method` columns
   (expand/contract: code stops reading first, columns drop in a later deploy).
5. Survives: `GenerateRmsLog`, the silence-parsing half of `RmsAnalysisService`, speech blocks
   for manual review.

Write the diff-level sequencing as a short addendum to this plan when R9 lands (the shape of
`AnalyzeSegments` after R9's edits isn't knowable yet).

## R11 — Item 1.7 consolidations (each its own commit, in order)

- **1.7a — one Whisper pass** (media O2/F5). Slice the full-service transcript
  (`ChurchServiceTranscript::sliceText()` exists) for the sermon transcript instead of
  re-transcribing extracted audio. Both interface families verified present:
  `ServiceTranscriptionInterface` (full service) and `TranscriptionServiceInterface`
  (`TranscribeAudio` on extracted audio). Delete the second family *for the livestream/video
  paths*; check first whether any path (audio-only uploads?) has no full-service transcript to
  slice — if audio uploads transcribe directly, `TranscribeAudio` survives for that pipeline
  only, and the "family deletion" is scoped to the redundant second pass. Acceptance: public
  transcript available right after structure detection; per-service Whisper cost halves.
  **Gates R12.** Note for tests: `SONG_MATCHING_LOCAL_WHISPER_URL`/local-whisper wiring
  (memory) may be affected — keep the local dev route working.
- **1.7b — one ffmpeg audio-preparation helper** (media F5): single owner of the
  transcription-target profile; delete the other three compression paths and the
  `getVideoMetadata` double. Re-verify the "three paths" claim against current code first.
- **1.7c — one song matcher** (songs F5/F6, church opportunity 4): shed
  `MatchSongsFromTranscript`'s third tier (per-section extraction + Whisper) in favour of
  `sliceText()`; consolidate title-hint regexes + fuzzy lyrics windowing behind one typed
  matching call; canonical-key bedrock stays deterministic; prune the 11 `song_matching`
  config keys to the active matcher and OCR settings. **OCR is retained**: it covers
  lyrics-on-screen-only services (maintainer decision 2026-07-21).
- **1.7e — registry rationalisation** (media F3), last: anchor-job offsets for all pipelines,
  progress from chain position, step-name normalisation at the write site, delete alias lists.
  Much smaller after R9/R10 shrink the chains.
- **1.7d** closed (speaker stack kept, D3). **1.7f** unscheduled — operator appetite only.

## R12 — Bulk historic backfill → 2.5 deletion [operational → mechanical]

After 1.7a lands: process the ~500-item historic backlog through
`ImportHistoricVideoBatchCommand` (the soak runbook's Stage 3 mechanics apply; watch `app-temp`
disk headroom — memory: temp disk is the bottleneck; check *container* disk, not host `df`).
If per-service cost still matters, the `TranscriptionServiceInterface` seam offers the
local-Metal whisper.cpp sidecar route or `TRANSCRIPTION_SERVICE_TYPE=local` on the prod box
(see 1.7a text in the backlog). Only when the maintainer confirms the drive import is finished
for good: delete `HistoricVideoImporter` + `ImportHistoricVideoBatchCommand` (~1,500 lines) —
nothing else references them (re-verify at deletion time).

## R13 — Items 5.3 / 5.4: deferred re-measures [design]

With soak + backfill data:

- **5.3 staged structure-merge** (~950 lines): count pending-structure-merge fires and operator
  choices (R1 step 2 recorded the soak's; the R12 backfill adds volume, but note D22's caveat —
  bulk-import ordering differs from live email-then-livestream ordering, so weight live-Sunday
  evidence higher). If it rarely fires and the operator always accepts the incoming email:
  collapse to "merge + `needs_review` + diff note".
- **5.4 `ChurchServiceItemSyncService`**: post-promotion semantics settled — does `Livestream`
  still need full merge authority? Does cross-source song-title matching still fire? Then
  policy/mechanics split only if still warranted.

Each produces a short decision note appended to the backlog's decision log (D-numbered), then
either a small implementation PR or an explicit "keep" closure.

## R14 — Items 7.1 + 7.2: test-suite roll-up [mechanical]

After R8/R9/R10 (so nothing is folded twice). Verified surviving duplicate pairs:

| Legacy flat file | Fold into | Lines |
|---|---|---|
| `tests/Feature/Livewire/Admin/EditSermonTest.php` | `Admin/Sermons/EditSermonTest.php` | 564 |
| `tests/Feature/Livewire/Admin/ListSermonsTest.php` | `Admin/Sermons/` per-component home | 221 |
| `tests/Feature/Livewire/AdminUserTest.php` | `Admin/Users/*` | 439 |
| `tests/Feature/Livewire/AdminMeetingTest.php` | `Admin/Meetings/*` | 183 |
| `tests/Feature/Livewire/AdminChurchServiceTest.php` | `Admin/ChurchServices/*` per-component suites | 1,402 |

Method: diff assertions, port anything the per-component home lacks, delete the flat file in
the same commit. Cross-cutting trait behaviour (sort sanitisation etc.) stays only at the
trait level + structural tests. Then 7.2: write the four conventions into `AGENTS.md`
(deletion-with-subject, one suite per component, one integrity home — pick the directory while
here, eval manifests over characterisation suites for probabilistic seams).

## R15 — Closure

1. Confirm every Definition-of-done box in the backlog is ticked or explicitly rejected;
   record any rejections in the decision log.
2. Archive: this plan and the backlog move to `docs/archived-plans/` with pointer headers;
   `docs/architecture/simplification-backlog.md` archived at R4 if not already.
3. ~~Hand off to **Phase 9** (code-quality review)~~ — **Phase 9 ran early on 2026-07-19**
   (maintainer waived the gate): findings in
   `docs/reviews/july-2026-simplification/code-quality-review-2026-07-19.md`, implementation in
   [CODE-QUALITY-REMEDIATION-2026-07-19.md](CODE-QUALITY-REMEDIATION-2026-07-19.md). Its WP7
   (phpstan level-9 ratchet) is gated on R9–R11 here; at closure, confirm WP7's gate is
   released and note it in that plan.

## Quality gates (unchanged, every PR)

`vendor/bin/sail artisan test --compact --parallel` (focused per PR, full before merge) ·
`vendor/bin/sail composer phpstan` at 0 · `vendor/bin/sail bin pint --dirty` ·
`vendor/bin/sail artisan dusk` for anything touching public routes or the upload form.
Capture first-run test output with `tee` (AGENTS.md diagnosis trick) — never re-run the suite
just to read failure names.

## Consolidated operator checklist (production; counts only in public output)

- [ ] One clean production auto-trim run after R2 merges (gates R9)
- [ ] Temporary sermon bundle commands implemented/tested/deployed; Spaces fingerprint and
      per-object SHA-256/size verification pass
- [ ] Tape digitisation + valuable local-only MP3 promotion finished (gates `LegacySermonImporter` deletion)
- [ ] Stale sermon patch formally abandoned + local-only ledger resolved (gates `GenerateProdSermonPatchCommand`)
- [x] `sermons WHERE preacher_id IS NULL` = 0 — confirmed 2026-07-20 (gates `PreacherCutoverCommand`)
- [ ] play_date backfill confirmed complete — **6,203 unaccounted rows on 2026-07-20** (gates `LegacyPlayDateSongUsageImporter`)
- [ ] Null/blank/`legacy-song-%` canonical keys incl. trashed = 0 — **1,207 on 2026-07-20** (gates `LegacySongReconciler` + column drops)
- [x] `ConvertJpgToWebp` retirement confirmed 2026-07-20 — zero code-reference changes; remaining JPGs classified above
- [ ] Complete `.osz` archive imported/reviewed in production and declared final (gates `ImportOpenLpDirectoryCommand`)
- [ ] Media identity dry run `Would update = 0` — **36 on 2026-07-20** (gates `BackfillMediaProcessingIdentityCommand`)
- [ ] No external provisioning invokes `upload:fix-directories` — container writability passed 2026-07-20
- [ ] OoS paper archive production import/idempotency passed after `.osz`; archive declared final (gates `ImportOosArchiveCommand`/`OosArchiveEvaluator` — Phase 9 F3.2)
- [ ] Prod praise-number backfill + song-link convergence after #1171 — praise drift `0`, link drift **3** on 2026-07-20 (gates `BackfillSongPraiseNumbersCommand` — Phase 9 F3.2)
- [ ] Meeting photo import to Media Library confirmed — mapped folders passed; `sunday-services` mapping remains to verify
- [ ] Maintainer decision: R6 sequencing vs. review-queue-noise plan
- [x] Maintainer answer: church Q4 lyrics-on-screen — retain OCR (2026-07-21)
- [ ] Bulk backfill complete + drive import declared finished (gates `HistoricVideoImporter` deletion — last)
