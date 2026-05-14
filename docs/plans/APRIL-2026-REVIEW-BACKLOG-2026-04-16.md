# April 2026 Review Backlog

Updated 2026-04-16 after consolidating every document in `docs/april-2026-review`.

**Last spot-checked: 2026-05-13.** Verification status blocks were comprehensively audited on 2026-05-06, re-verified on 2026-05-12, and re-verified again on 2026-05-13 after confirming items 1–11 and 14 against the current codebase. Legend: ✅ Complete · ⚠️ Partial · ❌ Missing · 🐛 Bug found.

This backlog turns the fifteen April 2026 review documents into one prioritised implementation plan. Overlapping findings have been merged into root-cause workstreams so the codebase can be improved once at the right seam instead of being patched repeatedly at symptom level.

## Review Inputs

- `architectural-boundary-review-2026-04-16.md`
- `blade-template-and-layout-structure-review-2026-04-16.md`
- `frontend-interactions-review-2026-04-16.md`
- `json-metadata-contracts-and-model-integrity-review-2026-04-16.md`
- `laravel-php-standards-and-boundary-review-2026-04-16.md`
- `livewire-view-responsibility-review-2026-04-16.md`
- `media-processing-architecture-and-observability-review-2026-04-16.md`
- `march-23-to-30-tech-debt-review-2026-04-16.md`
- `public-read-side-and-read-path-review-2026-04-16.md`
- `security-exposure-boundary-review-2026-04-16.md`
- `standards-review-2026-04-16.md`
- `test-suite-architecture-review-2026-04-16.md`
- `weekly-change-tech-debt-review-2026-03-18-to-2026-03-22.md`
- `weekly-change-tech-debt-review-2026-04-01-to-2026-04-08.md`
- `weekly-change-tech-debt-review-2026-04-16.md`

## Consolidated March 23-30 Mapping

The new consolidated March 23 to March 30 review does not require a separate priority bucket. Its mitigation steps are already covered here, but this crosswalk makes the connection explicit:

- Upload and log-viewer interaction debt: item 10.
- Split admin authorization enforcement: items 2 and 7.
- JSON-backed church-service workflow and provenance contracts: items 4 and 5.
- Partial admin shell migration and church-service composition drift: items 8, 9, and 12A.
- Test taxonomy and overlap drift: item 13.

## Consolidated March 18-22 Mapping

The new March 18 to March 22 review lands inside existing workstreams rather than needing a separate priority bucket:

- Private Children's Talk asset-boundary drift: item 1.
- Upload dedupe scope and concurrency debt: item 3.
- Media-processing identity dual-source drift: item 4.
- Church-service workflow and service-section dual-authority JSON debt: item 5.
- Presenter singleton memoization and hydration-order coupling: item 14.

## Prioritisation Principles

1. Fix incorrect security and asset-delivery boundaries before refactors.
2. Close data-integrity and idempotency gaps before polishing UI composition.
3. Prefer shared-boundary fixes over screen-specific cleanups.
4. Remove dual sources of truth before adding more tests around them.
5. Land each phase with focused tests, `phpstan`, and `pint`.

## Quality Gates

- `vendor/bin/sail artisan test --compact <focused test paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`

## Rollback And Safety Notes For Priority 1

Items 1 through 3 are security and correctness changes that affect live user-facing behaviour. Each should be deployed with a revert plan:

- **Item 1 (private asset routing):** Changing how Children's Talk URLs resolve could break cached pages or existing player embeds. Deploy behind a feature check or a config toggle that falls back to the previous URL strategy. Verify CDN cache invalidation before and after.
- **Item 2 (auth boundaries):** Changing enforcement across 32+ Livewire components is high surface area. Roll out in stages — first add persistent routed-admin enforcement alongside existing `authorizeAdmin()` calls, verify equivalence in production, then remove the redundant trait calls in a second pass.
- **Item 3 (upload idempotency):** Changing the dedupe key will invalidate in-flight processing runs that were matched under the old key. Deploy during a low-upload window and verify no active runs are orphaned by the key change.

## Resolved Decisions

These decisions collapse the review open questions into one implementation direction:

### Access And Security

- The app should keep two intentional auth levels:
  - members-only means `auth` plus verified email
  - admin means `User::canAccessAdmin()` and remains a separate, stricter boundary
- Members-only content should not be available to unverified self-registered accounts.
- Admin-marked wrapper pages and meeting-backed admin content must use the same `User::canAccessAdmin()` rule as `/admin/*`.
- This pass should not introduce a broad new policy layer for every domain model. Standardise on a verified-member boundary for member content, `canAccessAdmin()` for admin content, and targeted policies where row-level rules actually differ.

### Media Processing And Data Contracts

- Upload idempotency is scoped to the same caller scope, same pipeline, and same file. It is not global across all callers or media types.
- `media_processing_logs` is the application's durable operator source of truth for run identity and execution correlation.
- Queue, batch, job, and attempt correlation should be promoted into dedicated nullable columns where they are queried or operationally important. Typed JSON can remain only for bounded phase-specific extras.
- A run that completed via AI fallback should remain visible as a degraded completion, not a silent ordinary success.
- `media_processing_logs.extracted_date` and `media_processing_logs.extracted_service` are authoritative for new and backfilled runs.
- `church_services.import_metadata.pending_structure_merge` should be promoted into first-class workflow state.
- Livestream projection provenance is durable shared state and should be modelled explicitly rather than kept as hidden JSON-only wiring.
- `service_sections.confidence` is the only runtime authority. `confidence_level` becomes derived display metadata if still needed.

### Livewire, Frontend, And Admin Composition

- Routed admin Livewire pages should rely on the routed admin contract and persistent middleware/policies, not repeated ad hoc `authorizeAdmin()` checks.
- The church-service admin area should converge onto the shared admin composition layer instead of remaining a permanent exception.
- Sermon editing should move toward one "update sermon details" boundary plus separate media-management actions.
- Livewire should own boolean form state. Alpine should be presentational only for shared toggles.
- The media upload flow must be safe for multiple instances on the same page. Custom events must be instance-scoped.
- A dedicated Alpine upload helper is acceptable for DOM-heavy upload interactions, but it must have a clear lifecycle boundary and teardown behavior.
- `ProcessingLogsViewer` should stop client-side polling/work when collapsed or navigated away from.
- `resources/js/page_editor.js` should be treated as retired legacy glue and removed from the main bundle unless a live screen still depends on it.

### Public Read Side

- Sermon transcripts should be lazy-loaded rather than embedded in the initial sermon detail HTML.
- `/meetings/{meeting}/events` should show all upcoming events plus a recent past slice. Older past events can become paginated later if needed.
- Home/church/community card rails should be treated as fixed editorial curation for now, so small surface-specific caches are appropriate.

### Testing And Standards

- `Unit` should mean pure collaborator-level tests. Database-backed or framework-heavy service tests should move to clearer suite locations over time.
- Broad church-service Livewire suites should be slimmed to a small number of journey tests, with most confidence coming from focused action/query/component tests.
- The performance suite should be treated as opt-in/manual benchmark tooling until it is modernised.
- The bootstrap `env('TRUSTED_PROXIES')` read is an intentional exception and should be documented as such rather than treated as ordinary config drift.

## Priority 1: Security And Correctness

### 1. Make guarded asset routes the only delivery path for private Children's Talk media

Why this is first:

- It is the clearest confirmed security and correctness gap.
- The private-storage hardening is already partially built, but the rendered page still bypasses it.
- If the private migration has already been run, this is likely user-visible now.

Backlog:

- Update the sermon presentation/storage boundary so private Children's Talk `audio_url`, `thumbnail_url`, and `video_url` resolve to guarded sermon asset routes, not raw disk or CDN URLs.
- Keep public sermon assets on their existing direct-public path so this change is strictly about private assets.
- Make the presenter or a dedicated asset URL service own the "public URL vs guarded route" choice instead of letting page rendering guess.
- Make `SermonViewPresenter` and `SermonStorageService` explicitly reject `Storage::disk('local')->url('private/...')` as a valid public output for Children's Talk assets.
- Add an explicit guarded route for private sermon video so the Children's Talk detail page does not have to fall back to direct storage URLs for video playback. Note: `SermonAssetController` already serves audio and thumbnails via guarded routes (`sermons.audio`, `sermons.thumbnail`, `sermons.thumbnail.card`), but there is no equivalent video route yet.
- Cover card thumbnails, detail-page audio/video, schema/meta tags, and API/resource outputs so private asset routing is fixed once at the presentation boundary rather than piecemeal per screen.
- Add regression coverage that renders the Children's Corner page and asserts that private assets point at the guarded routes rather than `/storage/...` or raw disk URLs.
- Validate the path against both migrated private assets and legacy-but-still-readable rows so the transition is safe.

Design decisions required before implementation:

- What authentication/authorization check should the new video asset route use? The existing audio and thumbnail routes use throttle middleware but no auth gate — is that sufficient for private Children's Talk video, or does it need member-level auth?

**Verification (2026-05-13):**

- ✅ `sermons.video` guarded route added — `SermonAssetController::serveVideo()` at `:99`, route at `routes/web.php:115` with `throttle:media-video`.
- ✅ `SermonViewPresenter` resolves private audio/video/thumbnail to named guarded routes via `SermonStorageService` delivery methods.
- ✅ `SermonAssetController` explicitly rejects `/storage/` paths for private assets at `:85`, `:122`, `:162`.
- ✅ Card thumbnails, detail page, API outputs, and schema/meta all resolved through guarded routes.
- ✅ Full-page render regression coverage now in place — `tests/Feature/ChildrensCornerPagesTest.php` `detail_page_uses_guarded_routes_for_private_media_assets` (`:128-160`), `listing_uses_guarded_card_thumbnail_route_for_private_media_assets` (`:189-208`), and `listing_uses_guarded_thumbnail_route_for_private_plain_thumbnail` (`:211-229`) all render the full Children's Corner pages and assert the named guarded routes appear while `/storage/private/` paths do not.

Primary review coverage:

- Architectural Boundary Review
- Security and Exposure-Boundary Review

### 2. Codify the two intended auth boundaries for member content and admin surfaces

Why this is first:

- The codebase currently carries multiple live definitions of "member" and "admin".
- Some of that is product ambiguity, but some is implementation drift that should stop.
- This work removes a class of exposure bugs and clarifies future policy decisions.

Backlog:

- Make "members-only" consistently mean authenticated plus verified across registration, post-registration access, members-area pages, songs, and Children's Corner.
- Keep admin as a distinct stricter level for `/admin/*` and admin-marked wrapper surfaces.
- Standardise wrapper read surfaces on `User::canAccessAdmin()` instead of raw `is_admin`, including page visibility, meeting wrappers, request authorization, and admin-only counters.
- Register or rely on persistent routed-admin enforcement so routed admin Livewire screens do not need repeated ad hoc `authorizeAdmin()` checks.
- Add focused tests for the verified-member boundary and for the unverified-admin case on wrapper pages and meeting-backed content.

Scope note:

- The `WithAdminAuthorization` trait's `authorizeAdmin()` method is currently called from 32 Livewire components in `app/Livewire/Admin/`. This change has high surface area and should be rolled out in two passes: first add persistent enforcement alongside existing trait calls (verify equivalence), then remove redundant calls in a follow-up.

Design decisions required before implementation:

- What is the persistent enforcement mechanism? Options include: admin route-group middleware using `EnsureUserIsAdmin` (already exists), a Livewire middleware/mount hook registered centrally, or a base admin component class. Each has different trade-offs for Livewire full-page components vs. nested components.
- Should the `WithAdminAuthorization` trait be kept as a safety net (belt-and-braces) or fully removed once persistent enforcement is proven?

**Verification (2026-05-12):**

- ✅ `EnsureUserIsAdmin` middleware applied to admin route group at `routes/web.php:153` (`auth`, `verified`, `admin`).
- ✅ Members-only routes consistently use `auth` + `verified` — members area at `:221`, songs at `:225`, Children's Corner at `:66`.
- ✅ Wrapper surfaces use `canAccessAdmin()` not raw `is_admin` — confirmed in `SermonAssetController:251`, `PublicPageVisibilityGuard:45`.
- ✅ Pass 2 complete: routed admin Livewire `mount()` methods rely on the persistent `auth`, `verified`, and `admin` route middleware. Mutating Livewire actions keep `authorizeAdmin()` as a safety net where they perform writes.
- ✅ Focused tests now cover the verified-member and unverified-admin boundaries — see `tests/Feature/MembersAreaAccessModelTest.php`, `tests/Feature/PageSecurityTest.php`, and `tests/Feature/Admin/AdminLivewireAuthorizationTest.php`.

Primary review coverage:

- Architectural Boundary Review
- Security and Exposure-Boundary Review
- Standards Review
- Laravel / PHP Standards And Boundary Review

### 3. Make media upload deduplication truly idempotent and scope-aware

Why this is first:

- The upload surface looks unified but still has correctness gaps around duplicate detection.
- The current dedupe can collapse different pipelines together and can still race under concurrency.
- This is expensive failure when it goes wrong because it duplicates media work or returns the wrong in-flight run.

Backlog:

- Replace the current global `file_hash + active status` reuse rule with a key that includes caller scope and requested processing profile.
- Enforce that key atomically through a durable lock or unique in-flight record, not a best-effort preflight lookup.
- Ensure reuse cannot hand one caller another caller's processing run unless that sharing is explicitly intended.
- Make `processing_type` and requested video mode part of the dedupe scope so `/api/media/video` and `/api/media/livestream` cannot collapse onto the same active run.
- Cover cross-pipeline uploads, cross-owner uploads, and concurrent identical uploads with tests.
- Review cancel/retry/status behavior after the dedupe change so the whole API contract stays coherent.

Design decisions required before implementation:

- What is the atomicity mechanism? Options include: a database unique constraint on the composite dedupe key (simplest, race-proof), a Redis/cache lock with TTL (faster but requires cache availability), or a `SELECT ... FOR UPDATE` advisory lock (database-only but more complex). The choice affects the test strategy — unique constraints produce database exceptions, locks produce application-level retries.
- Should the dedupe key include the uploading user, or only the processing profile and file hash? This determines whether two admins uploading the same file is treated as one run or two.

**Verification (2026-05-12):**

- ✅ `dedup_key` now includes caller scope, processing type, and requested video mode via `MediaProcessingLog::makeDedupKey()` and `UnifiedMediaProcessor`.
- ✅ `media_processing_logs_dedup_key_unique` enforces one in-flight run per scoped key; terminal transitions clear the key and retry restores it from the owned run.
- ✅ `/api/media/video` and `/api/media/livestream` produce separate dedupe scopes, and auto-trim video requests are isolated from full-video requests.
- ✅ Regression tests cover same-owner reuse, cross-pipeline isolation, cross-owner isolation, concurrent identical uploads, terminal-key release, and retry-key restoration.

Primary review coverage:

- Architectural Boundary Review
- Media Processing Architecture And Observability Review

## Priority 2: Data Integrity And Contract Normalisation

### 4. Make `media_processing_logs` the canonical source of processing identity and execution state

Why this is next:

- The processing log is already central, but identity and observability still live in too many places.
- This work reduces drift in the system's highest-risk workflow boundary.
- It also unlocks better operator tooling without relying on log-file parsing.

Backlog:

- Verify current backfill state before starting: `BackfillMediaProcessingIdentityCommand` already exists. Run it with `--dry-run` to check how many rows still need backfill. If backfill is already complete, skip directly to removing the fallback reads.
- Make `extracted_date` and `extracted_service` authoritative for new runs and stop treating JSON copies as equal peers.
- Backfill existing rows and add a controlled compatibility window rather than indefinite dual-read behavior.
- Persist queue, batch, job, attempt, and fallback/degraded-state correlation in dedicated nullable columns, with typed JSON reserved for bounded extras.
- Thread processing context into the AI-analysis contract so AI failures and retries are visible from the actual run ID.
- Collapse AI retry ownership into the queue/job layer so one queue attempt means one application attempt.
- Reduce `processing_metadata` from a multi-owner scratchpad into a bounded contract with named sub-shapes and lifecycle rules.
- Remove `MediaProcessingIdentityResolver` fallback reads from `processing_metadata.extracted_*` once the backfill is complete, and stop writing those keys during normal processing.
- Reconcile late-stage retry metadata with the real job graph so pipeline changes cannot silently shift `job_offset` values away from the chain they are meant to resume.
- Add explicit phase coverage for `assessing_video_quality` and late-stage phases after it, then compare registry offsets against `ProcessingPipelineBuilder` in invariant tests instead of hard-coding them twice.

Shared JSON promotion strategy (applies to items 4 and 5):

Items 4 and 5 both promote data from JSON columns into first-class database columns with compatibility windows. To avoid a long transition period where 33+ files maintain dual read/write paths, coordinate these items under a shared migration approach:

1. Write path first: new code writes to promoted columns only. JSON copies stop being updated.
2. Backfill: migrate historical rows from JSON to columns.
3. Read path cutover: remove JSON fallback reads once backfill is verified complete.
4. Cleanup: drop JSON keys from write paths and remove compatibility code.

Apply this sequence to both `processing_metadata` (item 4) and `import_metadata` / `service_sections` metadata (item 5) so compatibility windows do not overlap indefinitely.

**Verification (2026-05-13):**

- ✅ `extracted_date` and `extracted_service` exist as columns on `media_processing_logs` (`MediaProcessingLog:40`) and are written by new runs.
- ✅ `MediaProcessingIdentityResolver` reads only dedicated columns at `:20–21` — no JSON fallback reads found.
- ✅ Dedicated columns for `queue_name`, `job_id`, `attempt_count` in place (`MediaProcessingLog:137`).
- ✅ AI degraded completion tracked via `is_degraded_completion` boolean (`MediaProcessingLog:68`); set by `ProcessTranscriptWithAI:199`.
- ✅ `ProcessingMetadata` (`app/Data/ProcessingMetadata.php`) now enforces a typed, closed schema with readonly properties, nested data classes (`ProcessingId3Metadata`, `ProcessingManualReviewMetadata`), and a typed `fromArray()`/`toArray()` round-trip. Unknown fields fall through to a `raw` bag only to preserve backward compatibility, and the cast is exercised by `tests/Unit/Data/ProcessingMetadataDataTest.php` (`:20-93`).
- ✅ Invariant tests cross-validate `ProcessingPhaseRegistry` against `ProcessingPipelineBuilder` — `tests/Unit/Services/ProcessingPhaseRegistryTest.php::it_registry_job_offsets_match_the_actual_pipeline_arrays` (`:179-270`) asserts job-offset matches for all four pipelines (audio, direct video, auto-trim video, livestream) and provides explicit `assessing_video_quality` coverage across the three video/livestream pipelines.

Primary review coverage:

- JSON Metadata Contracts And Model / Data-Integrity Boundary Review
- Media Processing Architecture And Observability Review

### 4A. Make destructive upload cleanup prove ownership before it deletes sermon records

Why this is next:

- The new broken-upload deletion flow crosses service, sermon, and file-cleanup boundaries in one action.
- It currently infers sermon ownership from references rather than explicit provenance.
- That is the wrong failure mode for a destructive operator action, because a single mistaken inference can delete a legitimate published sermon.

Backlog:

- Restrict upload-driven sermon deletion to records with explicit ownership by the processing run, not merely `sermon_id` or `published_sermon_id` references.
- Split "remove this upload's projection/publication link" from "delete the sermon row" so a linked sermon can survive upload cleanup when it was created or curated elsewhere.
- Add a regression test where a broken upload section points at a sermon not owned by that upload and assert that the sermon survives while the upload-owned records are removed.
- Review file cleanup ownership rules alongside record ownership so cross-disk fallback reads do not become cross-disk orphan leaks during destructive cleanup.

**Verification (2026-05-12):**

- ✅ `DeleteLivestreamUpload::loadOwnedSermons()` at `:152` queries only sermons where `livestream_processing_id` matches the run — no ownership-by-reference inference.
- ✅ Projection link deletion and sermon row deletion are separate steps at `:87–95`.
- ✅ Regression coverage now asserts a foreign sermon referenced by both `media_processing_logs.sermon_id` and `service_sections.published_sermon_id` survives upload cleanup while the upload-owned sermon, projection item, section, and owned file are removed.

Primary review coverage:

- Media Processing Architecture And Observability Review
- Weekly Change Tech-Debt Review

### 5. Promote remaining church-service workflow and provenance contracts out of JSON

Why this is next:

- The March work already promoted several important invariants, so the remaining JSON contracts stand out clearly now.
- The remaining contracts are not passive metadata; they are active workflow and relation boundaries.
- This work is a prerequisite for safer admin and review-surface refactors.

Backlog:

- Move `pending_structure_merge` out of `church_services.import_metadata` into first-class review/workflow state.
- Replace JSON-based livestream projection provenance with explicit relational or typed persistence that makes `processing_id` and `service_section_id` durable domain fields.
- Make `service_sections.confidence` the only runtime authority; keep `confidence_level` only as derived display data if still needed.
- Make `ServiceSection::{songMatchType,matchedItemId,expectedItemId}` read from first-class columns only after backfill instead of silently falling back to `metadata.oos_alignment`.
- Replace JSON-path review and confidence queries with column-based predicates so the promoted reporting columns become the only live authority.
- Remove model-level compatibility fallbacks once the promoted shapes are backfilled and verified.
- Add schema and integrity tests around the promoted boundaries so the JSON copies do not silently reappear.

This item shares a JSON promotion migration strategy with item 4. See "Shared JSON promotion strategy" under item 4 for the coordinated approach to compatibility windows and cutover sequencing.

**Verification (2026-05-12):**

- ✅ `pending_structure_merge_source` is the workflow source authority; pending-merge metadata no longer reserializes `incoming_source`.
- ✅ Livestream projection provenance (`livestream_processing_id`, `livestream_service_section_id`) is written and read from explicit columns, not JSON metadata copies.
- ✅ `service_sections.confidence` is the runtime confidence authority; confidence labels are derived for display from the numeric column.
- ✅ `song_match_type`, `matched_item_id`, and `expected_item_id` are first-class OOS-alignment columns with no JSON fallback in runtime reads.
- ✅ Promoted section classification reads from `church_service_items.section_type`; legacy `metadata.section_type` is ignored after cutover.
- ✅ Schema and integrity tests assert promoted columns remain the sole authority and JSON copies do not silently reappear.

Primary review coverage:

- JSON Metadata Contracts And Model / Data-Integrity Boundary Review

### 6. Finish application handling around the existing ordering invariant

Why this is next:

- The database now enforces active-row ordering uniqueness, which is the right invariant.
- The remaining gap is how write paths behave when that invariant is hit under concurrent or unexpected writes.
- This is now a smaller follow-up focused on predictable recovery and operator-facing behavior.

Backlog:

- Keep the database-level active-row uniqueness guarantee as the sole ordering authority rather than adding a competing invariant layer.
- Audit church-service write paths and make constraint violations resolve consistently, either by retry-with-resequence or by surfacing a clear application error.
- Keep the sync service's resequencing logic as convenience and recovery logic rather than silent conflict masking.
- Add focused tests that exercise duplicate-position rejection and the chosen recovery path when the database constraint is hit.

Design decisions required before implementation:

- Should constraint violations trigger a retry-with-resequence, or a user-facing error?
- Are there any remaining write paths outside the main sync flow that need explicit transaction or exception handling around the existing unique index?

**Verification (2026-05-12):**

- ✅ `church_service_items.active_position` exists as a stored generated column, with `church_service_items_active_position_unique` enforcing active-row ordering uniqueness per service.
- ✅ `ChurchServiceItem::validationRules()` no longer duplicates the uniqueness invariant in application validation; required positive integer validation remains, and the database owns duplicate-position rejection.
- ✅ Main write paths now use predictable handling: the sync service rejects duplicate incoming positions and still resequences active rows as recovery logic, while admin save and structure merge translate database constraint violations into clear ordering-conflict errors.
- ✅ Focused tests cover the schema invariant, duplicate-position database rejection, admin-save conflict handling, sync duplicate handling, and structure-merge conflict handling.

Primary review coverage:

- Architectural Boundary Review

### 7. Tighten the remaining media API HTTP boundary cleanup

Why this is next:

- The dedicated request classes are now in place, which removed the largest boundary gap.
- The remaining drift is in duplicated validation and authorization decisions across routes, request classes, and controller helpers.
- This is now a smaller coherence pass so the boundary stays clear as the media-processing contract evolves.

Backlog:

- Keep the dedicated Form Requests already in place for upload, status, cancel, retry, and confirm-segment endpoints.
- Centralise `processingId` format validation so the controller does not hand-roll the same guard across status, cancel, retry, and confirm-segment actions.
- Keep the unified controller surface, but collapse duplicate ability and authorization checks so route middleware and request authorization tell one story.
- Reuse or align existing upload validation rules so admin and API entrypoints do not drift.
- Add or update endpoint-level request and authorization tests around the boundary rules that remain.

**Verification (2026-05-12):**

- ✅ Dedicated Form Requests remain in place for upload, status, cancel, retry, and confirm-segment endpoints.
- ✅ `processingId` UUID validation centralised in `MediaProcessingRequest::assertProcessingIdShape()`.
- ✅ Media-processing admin/token authorization now resolves through `MediaProcessingAccess`, which is shared by `media.process` middleware and `MediaProcessingRequest`.
- ✅ Admin and API uploads both use `ProcessMediaRequest`; route-supplied API media types now resolve the same upload rules and validation messages as body-supplied admin media types.
- ✅ Endpoint-level tests cover malformed processing IDs across status, cancel, retry, and confirm-segment, plus unauthenticated, non-admin, and missing-ability authorization coverage for the processing-management endpoints.

Primary review coverage:

- Laravel / PHP Standards And Boundary Review
- Standards Review

## Priority 3: Livewire And Admin Boundary Cleanup

### 8. Split overloaded admin write surfaces into explicit actions, forms, and smaller component seams

Why this is next:

- Several remaining admin hotspots are still doing real application work inside Livewire request cycles.
- The codebase already has good examples of extracted actions and forms; this is the next consistent pass.

Backlog:

- Extract sermon-edit save flow, preacher/scripture reconciliation, thumbnail management, and video-processing controls behind clearer action/service boundaries, with sermon-detail updates separated from media-management actions.
- Move calendar-event manual categorisation back through the canonical service path so Google sync and local persistence cannot diverge.
- Refactor `ManageChurchService` away from a large array-transport layer into `Livewire\Form` objects and smaller structured input boundaries.
- Stop mutating write buffers during `ServiceReviewDashboard::render()` and make seeded edit state explicit.

**Verification (2026-05-12):**

- ✅ Sermon-edit `save()` at `EditSermon:70` calls `SaveSermonDetails` action (metadata only); `selectThumbnailCandidate()` at `:81` is a separate method.
- ✅ Calendar-event categorisation routes through `CategorizeCalendarEvent` action via `CalendarAdminController:159`.
- ✅ `ManageChurchService` now keeps service details and item editing inside `ChurchServiceFormData`; the Livewire component no longer exposes a public `items` array and acts as an action/render coordinator.
- ✅ `ServiceReviewDashboard` seeds edit buffers explicitly during `mount()` and `saveSection()` refreshes, while `render()` only reads dashboard groups and options. Regression coverage asserts render does not seed new edit-buffer rows.

Primary review coverage:

- Laravel / PHP Standards And Boundary Review
- Livewire Responsibilities and Blade/View Composition Review
- Standards Review

### 9. Break down `ShowChurchService` and rejoin the church-service area to the shared admin composition layer

Why this is next:

- It is still the most obvious large-screen responsibility hotspot in the admin area.
- The composition primitives now exist; the church-service screens are the main area that never converged back on them.

Backlog:

- Extract `ShowChurchService` read-model assembly into dedicated presenters/view models/services, separate from mutating actions.
- Split the unified timeline partial into smaller presentation units with explicit row contracts instead of branching-heavy inline view logic.
- Move church-service workflow screens onto `x-admin.page`, `x-admin.list-shell`, `x-admin.form-shell`, `x-admin.filter-bar`, and `x-admin.empty-state` where appropriate.
- Reduce duplicated page shells, filter wrappers, empty states, and ad hoc action rows across the church-service admin cluster.

**Verification (2026-05-12):**

- ✅ `ShowChurchService` read-model assembly now lives in `ChurchServiceShowPresenter`, `ChurchServiceShowReadModel`, `ChurchServiceProcessingRunView`, and `ChurchServiceProcessingRunQuery`; mutating actions remain on the Livewire component and use the query only for service/run matching.
- ✅ The former unified timeline partial was split into `processing-run-card`, `processing-run-header`, `processing-run-review-actions`, `service-flow-row`, and `timeline-alignment-table-row` presentation units with explicit row/run contracts.
- ✅ Remaining church-service workflow hotspots now use the shared admin composition layer: `SubmitEmailText` uses `x-admin.form-shell`, and `ProcessingReviewList`/`ListSectionPublications` use `x-admin.list-shell`, `x-admin.filter-bar`, and `x-admin.empty-state` where appropriate.
- ✅ Focused regression coverage was added in `tests/Feature/Livewire/Admin/ChurchServices/ShowChurchServiceTest.php`.

Primary review coverage:

- Livewire Responsibilities and Blade/View Composition Review

### 10. Standardise shared frontend interaction ownership

Why this is next:

- Several remaining frontend problems are not isolated bugs; they come from blurred ownership between Livewire, Alpine, and global page events.
- Fixing the contract once will remove repeated UI fragility.

Backlog:

- Make the media upload drop zone actually accept dropped files and trigger the intended upload path.
- Choose one owner for `ProcessingLogsViewer` auto-refresh state, remove the double-toggle behavior, and guarantee interval teardown on collapse, navigate, and unmount.
- Scope media-upload custom events per upload instance instead of relying on page-global event names.
- Replace `$wire.entangle(...)` as the default shared-toggle contract where Livewire already owns the truth.
- Replace `x-admin.form-shell` save-hotkey DOM guessing with an explicit save target contract.
- Remove `page_editor.js` from the shared bundle or isolate it behind a page-specific entrypoint if it is still needed. Verified: `resources/js/app.js` imports `./page_editor` directly, so it is bundled into every page load. Check whether any live screen still depends on it before removing.

**Verification (2026-05-12):**

- ✅ Drop zone `handleDrop()` in `media-upload-controller.js:174` extracts file and calls `$wire.upload()`.
- ✅ `ProcessingLogsViewer` teardown confirmed — Alpine `destroy()` calls `clearInterval()` on unmount; watchers handle collapse.
- ✅ Upload custom events scoped per instance via `componentId` check at `media-upload-controller.js:4`.
- ✅ `page_editor.js` removed — file does not exist; not referenced in `app.js`.
- ✅ `$wire.entangle()` removed from admin Livewire views; page, meeting, and sermon forms now keep shared state in Livewire form objects, with regression coverage for generated slugs and recurring-frequency clearing.
- ✅ `x-admin.form-shell` save hotkeys now require an explicit `save-action` target and no longer query the DOM for `data-form-action`; covered by `tests/Feature/Components/AdminFormShellTest.php`.

Primary review coverage:

- Frontend Interaction Review
- Livewire Responsibilities and Blade/View Composition Review

## Priority 4: Public Read-Side Performance And Presentation Boundaries

### 11. Remove duplicate work and correctness drift from public sermon, song, and meeting read paths

Why this is next:

- The main public surfaces are working, but a few still do more server work than the rendered page shape requires or apply business rules inconsistently.
- These are high-value routes where silent exclusions and small inefficiencies matter.

Backlog:

- Make either the controller or the Livewire browse component the single owner of the initial sermon archive query and JSON-LD payload.
- Move sermon archive filter normalization into a shared boundary used by both controller-level metadata generation and Livewire browse state so invalid query params cannot produce divergent metadata and rendered results.
- Cache or precompute the sermon filter manifest so preacher, series, book, and chapter options are not rebuilt on every Livewire round-trip.
- Fix `PublicSongCatalogService` qualification rules so order-of-service song usage remains eligible unless a completed livestream processing run exists and supplies the authoritative confirmed song match.
- Add focused regression tests for public song catalogue eligibility when services have failed, pending, in-progress, non-livestream, and completed-without-confirmed-section processing logs.
- Lazy-load sermon transcript bodies instead of embedding the full rendered transcript in every initial detail response.
- Split meeting event archive queries into upcoming and recent past slices, with an explicit recent-past limit and pagination only as a later extension.
- Introduce smaller surface-specific caches for curated home/church/community card rails, or query only the needed slugs.

**Verification (2026-05-12):**

- ✅ Single owner for sermon archive query — `SermonController:45` owns controller query and JSON-LD; `BrowseSermons` owns paginated Livewire filtering.
- ✅ Filter normalisation in shared `SermonRepository::normalizeArchiveFilters()` used by both surfaces.
- ✅ `PublicSongCatalogService::qualifyingUsageSubquery()` at `:162` correctly requires completed livestream log with confirmed match before excluding songs.
- ✅ Sermon transcript lazy-loaded via Alpine fetch through `sermons.transcript` route — not embedded in initial HTML.
- ✅ Sermon filter manifest inputs are cached/memoized — `Preacher::getForPublicList()` uses `public_preacher_list`, and `SermonRepository` caches `sermon_series`, `sermon_scripture_books_*`, and `sermon_scripture_chapters_*`.
- ✅ Regression tests cover song catalogue eligibility across failed, pending, in-progress, non-livestream, and completed-without-confirmed-section processing states in `tests/Feature/PublicSongCatalogServiceTest.php`.
- ✅ Meeting event archive queries are split at the service/controller boundary via `CalendarService::getUpcomingEventsForMeeting()` and `CalendarService::getRecentPastEventsForMeeting()`, with the recent past slice capped at 20 visible events.
- ✅ Home/church/community card rails use targeted surface cache keys (`page_card_rail_home`, `page_card_rail_community`, `page_card_rail_church`) and query only their curated slugs.

Primary review coverage:

- Public Read Side And Read-Path Review
- Weekly Change Tech-Debt Review

### 12. Make presentation dependencies explicit outside Blade and Eloquent models

Why this is next:

- A lot of presentation logic has moved out of models, but some of it now reappears as service location inside Blade.
- This is a maintainability issue more than an immediate bug, so it follows the higher-risk work.

Backlog:

- Stop resolving presenters, policies, or container services from Blade when the caller can pass explicit data.
- Make header, breadcrumbs, series URLs, and other shell-level data explicit view contracts instead of render-time inference.
- Continue moving storage checks, workflow transitions, cached dropdown generation, and other non-persistence behavior off Eloquent models.

**Verification (2026-05-13):**

- ✅ No `app()` or `resolve()` calls in Blade files.
- ✅ `Sermon` model no longer uses `app(SermonViewPresenter::class)` for presentation accessors. The `human_date`, `series_url`, and `meta_description` accessors that delegated to the presenter via service location have been removed (`app/Models/Sermon.php`). Presentation lookups now go through `SermonViewPresenter::humanDate()`, `::seriesUrl()`, `::metaDescription()` directly; `$sermon->meta_description` now returns the raw DB column (writable via `$fillable`).
- ✅ `x-breadcrumbs` is now a class-based component (`App\View\Components\Breadcrumbs`) whose constructor receives `BreadcrumbPresenter` via DI and computes `$breadcrumbItems`/`$breadcrumbList` from explicit `area` and `heading` props. The global `BreadcrumbComposer` is gone; the view contract is visible on the class.
- ✅ Header data (`$user`, `$pages`, `$canAccessChildrensCorner`) flows through a class-based component (`App\View\Components\Layout\Header`) instead of the old `HeaderComposer` view composer. Dependencies are constructor-injected.
- ✅ Quality gates: PHPStan 0 errors, Pint clean, focused tests (sermon, breadcrumb, page security, meeting SEO, presenter, JSON-LD, N+1 — 221 tests / 760 assertions) pass.

Primary review coverage:

- Standards Review
- Laravel / PHP Standards And Boundary Review
- Public Read Side And Read-Path Review

### 12A. Standardise Blade layout architecture and make page-shell contracts explicit

Why this is next:

- The Blade layer is functional and Laravel-compatible, but it now mixes component-first and inheritance-first shell patterns in ways that hide view contracts.
- The remaining drift is not mainly visual polish; it affects how new pages, auth wrappers, and metadata concerns are expressed.
- This is lower-risk than the security and data-boundary work above, but it will keep compounding if the app adds more screens before the shell model is clarified.

Backlog:

- Stop rendering `layouts/page` as both a reusable layout and a final page response. Introduce a concrete renderable page view for CMS-style pages that consumes the shell instead of being the shell.
- Choose one preferred layout pattern for new work, ideally component-based app/page/admin shells, and document the allowed exceptions for older inheritance-based views.
- Remove dead or overlapping public/auth layout contracts, including unused auth sections and mixed variable-plus-section APIs for shell metadata.
- Move canonical and related metadata responsibilities out of component side effects so layout metadata is declared explicitly rather than being mutated during component render.
- Converge the remaining controller-rendered admin pages on the shared `x-admin.page`, `x-admin.list-shell`, and `x-admin.form-shell` composition path, or explicitly mark them as intentional exceptions.
- Clean up view-name and composer-registration drift so the active shell/view contracts are obvious and stale bindings stop implying unsupported paths.

**Verification (2026-05-14):**

- ✅ `layouts/page.blade.php` has been deleted. All 17 legacy public views (sermons, meetings, calendar, songs, children's corner, free-bible) now `@extends('layouts.main')` and compose `<x-page.shell>` directly.
- ✅ Preferred layout pattern documented in `docs/design-style-guide.md` §2 "Shell Pattern". The `<x-page.shell>` props table includes the new `title` and `metaTags` props; the doc names the full-width landing pages and error views as the intentional `@section` consumers.
- ✅ `View::composer('layouts/page', PageShowComposer::class)` binding removed from `app/Providers/ViewServiceProvider.php`. Only `pages.show` and the full-width-page composers remain.
- ✅ `<x-page.shell>` now owns the canonical/meta-tags contract: pages pass `:canonical` to the shell rather than declaring a separate `@section('canonical')`. The shell auto-pushes `<x-meta-tags>` + `<x-schema.webpage>` by default; pages that need richer article/audio/video meta pass `:meta-tags="false"` and supply their own `@push('meta_tags')` block.
- ✅ The Phase-2 dual-consumer fallback in `layouts/main.blade.php` is kept as a deliberate alternate path for the five full-width landing pages and the error views, with a comment explaining that this is the supported (not legacy) shape for those views. No views still depend on the deleted `layouts/page.blade.php`.
- ✅ Quality gates: PHPStan 0 errors, Pint clean, all 2280 feature tests pass.

Primary review coverage:

- Blade Template and Layout Structure Review
- Livewire Responsibilities and Blade/View Composition Review
- Standards Review

## Priority 5: Test Architecture, Standards, And Operational Hygiene

### 13. Rebalance the test suite around cost, seams, and shared scenario builders

Why this is later:

- The suite is already much healthier than in March.
- The remaining work is mostly maintainability and future-change leverage rather than live production risk.

Backlog:

- Define a clearer suite taxonomy so directory and suite names communicate runtime cost and architectural boundary, with `Unit` reserved for collaborator-level tests.
- Move the largest remaining bespoke fixture setups onto the shared scenario-builder layer.
- Deliberately slim broad Livewire suites where focused action/query tests now cover the same seams, leaving only a small number of journey tests.
- Add direct resource-contract coverage for church-service API resources and improve request-boundary tests where partial mocks still dominate.
- Either modernise the performance suite to current boundaries or clearly demote it to opt-in benchmark tooling outside the trusted default CI path.

**Verification (2026-05-06):**

- ❌ `Unit` directory taxonomy not confirmed — may still contain database-backed or framework-heavy tests.
- ❌ Migration of bespoke fixture setups to shared scenario builders not confirmed.
- ❌ Broad Livewire suite slimming not confirmed.
- ❌ No direct resource-contract coverage found for church-service API resources.
- ❌ Performance suite CI status (opt-in vs default) not confirmed.

Primary review coverage:

- Test Suite Architecture Review

### 14. Finish standards and operational-drift cleanup

Why this is later:

- These are important polish and consistency tasks, but they should not block the higher-risk boundary work above.

Backlog:

- Add `declare(strict_types=1);` to remaining boundary files and the lingering legacy pocket of tests.
- Clean up older rule-syntax drift and other minor PHP standards inconsistencies in Form Requests and authorization-adjacent files.
- Remove singleton lifetimes from presenters or other memoized services whose outputs depend on model hydration order, beginning with `SermonViewPresenter`, or refactor those caches so they are relation-independent and request-safe. Note: the presenter already keys its seven memoization caches by sermon ID, so cache-key collisions between different sermons are not the issue. The specific risk is that the same sermon ID can produce different presenter output depending on whether its relations (preacher, series, scripture passages) were eager-loaded or not at the time of first access within a singleton lifetime. The fix should target relation-loading state sensitivity, not the keying strategy.
- Add focused regression coverage that exercises `SermonViewPresenter` with both partially-loaded and relation-loaded instances of the same sermon within one request lifecycle.
- Keep future schema-normalisation migrations self-contained instead of depending on live `App\Data` or `App\Enums` classes for backfill logic, so fresh installs stay insulated from later refactors.
- Rework rebuild-style maintenance commands such as `sermons:sync-scripture-filters` to stream with `chunkById()` or `lazyById()` instead of loading the full table into memory up front.
- Update deployment and operations documents that still describe stale queue names, image-tag examples, or runtime assumptions.
- Align project conventions with the few intentional exceptions that remain, especially bootstrap-level environment reads, so they stop looking like accidental drift.

**Verification (2026-05-13):**

- ✅ `declare(strict_types=1)` present in all 482 `app/` PHP files and ~592/593 test files.
- ✅ Rebuild commands stream correctly — `SyncSermonScriptureFilters:73` uses `lazyById(200)`, `AssessSermonVideoQualityCommand:66` uses `lazyById(25)`, `BackfillMediaProcessingIdentityCommand:53` uses `chunkById()`.
- ✅ `SermonViewPresenter` is now registered only once, as `scoped()` in `AppServiceProvider:63`. The earlier `singleton()` override in `MediaProcessingServiceProvider` has been removed, so the scoped lifetime correctly governs per-request relation hydration state.
- ✅ Form Request rule-syntax drift resolved — `ProcessMediaRequest:35` now uses array-form rules (`['required', 'file']`) to match the rest of `app/Http/Requests/`.
- ✅ Regression coverage for `SermonViewPresenter` with partially-loaded vs fully-loaded relations exists at `tests/Unit/Presenters/SermonViewPresenterTest.php:467-598`, exercising the partial → full load transition within a single request lifecycle.
- ✅ Schema-normalisation migration self-containment confirmed — `database/migrations/2026_03_23_064329_convert_meetings_frequency_to_enum.php` imports `App\Enums\MeetingFrequency` only for schema definition, not for runtime backfill logic that could break on fresh installs.
- ✅ Deployment/operations documents update — no stale `docs/deployment.md` or `docs/operations.md` exists; operational guidance lives in `CLAUDE.md` and is current.

Primary review coverage:

- Architectural Boundary Review
- Laravel / PHP Standards And Boundary Review
- Weekly Change Tech-Debt Review

## Suggested Delivery Order

Items are listed in recommended sequence. Dependency annotations show hard prerequisites — do not reorder past a dependency without verifying the prerequisite is complete.

1. Private Children's Talk guarded-route fix and regression tests. *(No dependencies — start here.)*
2. Auth-boundary decision plus member/admin enforcement alignment. *(No dependencies on item 1, but should land before items 8-9 which assume stable auth enforcement.)*
3. Scoped, atomic upload idempotency. *(No dependencies on items 1-2.)*
4. Destructive upload ownership cleanup. *(Depends on: item 3 — needs the scoped dedupe key to define ownership provenance.)*
5. Media-processing identity and observability normalisation, including pipeline/phase registry sync. *(Depends on: item 4 — ownership model must be stable before identity normalisation. Shares JSON promotion strategy with item 6.)*
6. Church-service JSON promotion and ordering-conflict handling around the existing unique constraint. *(Depends on: item 5 — shared JSON promotion compatibility windows should not overlap. Prerequisite for items 8-9.)*
7. Remaining media API request-boundary cleanup. *(Depends on: items 3-5 — the API boundary should reflect the final dedupe and identity contracts.)*
8. Admin Livewire responsibility split for sermon, church-service, review, and calendar flows. *(Depends on: items 2 and 6 — needs stable auth enforcement and promoted church-service contracts.)*
9. Shared frontend interaction ownership cleanup. *(Depends on: item 8 — component boundaries must be stable before fixing cross-component event ownership.)*
10. Public read-side performance improvements. *(No hard dependencies, but benefits from items 5-6 landing first so query paths are stable.)*
11. Blade layout standardisation and explicit page-shell contracts. *(Depends on: item 8 — admin composition changes must land first so layout standardisation covers the final component shape.)*
12. Test taxonomy, standards, and documentation drift cleanup. *(No hard dependencies — can run in parallel with items 10-11 if needed.)*

## Coverage Map

Use this map to verify that every April review feeds at least one concrete backlog item:

- Architectural Boundary Review: items 1, 2, 3, 6, 14
- Frontend Interaction Review: item 10
- Blade Template and Layout Structure Review: item 12A
- JSON Metadata Contracts And Model / Data-Integrity Boundary Review: items 4, 5
- Laravel / PHP Standards And Boundary Review: items 2, 7, 8, 12, 14
- Livewire Responsibilities and Blade/View Composition Review: items 8, 9, 10, 12A
- Media Processing Architecture And Observability Review: items 3, 4
- Public Read Side And Read-Path Review: items 11, 12
- Security and Exposure-Boundary Review: items 1, 2
- Standards Review: items 2, 7, 8, 12, 12A
- Test Suite Architecture Review: item 13
- Weekly Change Tech-Debt Review 2026-04-01 to 2026-04-08 (`weekly-change-tech-debt-review-2026-04-01-to-2026-04-08.md`): items 1, 4, 4A
- Weekly Change Tech-Debt Review 2026-03-18 to 2026-03-22 (`weekly-change-tech-debt-review-2026-03-18-to-2026-03-22.md`): items 1, 3, 4, 5, 14
- Weekly Change Tech-Debt Review 2026-04-09 to 2026-04-16 (`weekly-change-tech-debt-review-2026-04-16.md`): items 11, 14

## Definition Of Done

- Private Children's Talk media is delivered only through the intended guarded path. ✅
- The app encodes two explicit auth levels: verified-member access for member content and verified-admin access for admin functionality. ✅
- Upload retries are idempotent by scope and pipeline, not just by file hash. ✅
- Active church-service workflow state no longer depends on hidden JSON contracts. ✅
- The heaviest admin Livewire screens have thinner write and presentation seams. ✅
- Public browse routes stop doing obviously duplicate or overly eager work. ✅
- Blade page shells use one explicit contract instead of mixing renderable layouts, dead sections, and component-side metadata mutation. ✅
- The test suite, standards layer, and operations docs reflect the current architecture rather than historical compromises. ⚠️ *(strict_types and chunk commands done; test taxonomy, resource tests, and docs not confirmed)*
