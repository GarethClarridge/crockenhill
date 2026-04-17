# Weekly Change Tech-Debt Review

Date: 2026-04-16

Scope: committed changes from 2026-03-18 through 2026-03-22 inclusive. This review covers committed Git history only and excludes the current uncommitted working tree. The reviewed net range on `master` was `3a246a7fccfdf895deea073b869acb87c2b0200a..76dada590d8571062813a56a219410e1dd015b40`.

## Findings

### 1. High: private Children's Talk media hardening is incomplete because the read-side still bypasses the guarded asset boundary

File refs: `routes/web.php:80-92`, `app/Http/Controllers/SermonAssetController.php:27-61`, `app/Presenters/SermonViewPresenter.php:113-123`, `app/Presenters/SermonViewPresenter.php:280-295`, `app/Presenters/SermonViewPresenter.php:397-408`, `app/Services/SermonStorageService.php:119-147`, `app/Services/SermonStorageService.php:223-228`, `app/Services/SermonStorageService.php:404-412`, `resources/views/childrens-corner/show.blade.php:16-21`, `resources/views/childrens-corner/show.blade.php:95-109`

This window added a guarded delivery controller for private Children's Talk audio and thumbnails, but the presentation path still generates direct storage URLs. `SermonViewPresenter::{audioUrl,thumbnailUrl,videoUrl}()` delegates straight to `SermonStorageService`, and `SermonStorageService::resolvePublicUrl()` simply builds a disk URL. The Children's Corner detail page then renders those URLs directly into meta tags and the `<audio>` and `<video>` elements.

That leaves two problems in the same seam. First, the guarded route set is incomplete: there is no equivalent guarded video route in `routes/web.php`, so private video can only be rendered through the direct-storage path. Second, even where guarded controller routes do exist, the presenter does not choose them for private assets. The result is that private asset delivery now depends on which code path produced the URL, not on one explicit security boundary.

Smallest safe improvement: make the presenter or a dedicated asset-URL service the only owner of the "direct public URL vs guarded route" choice, add a guarded video route for private sermon video, and add page-render regression tests for Children's Corner cards, detail pages, and metadata output after a private-storage move.

### 2. High: upload deduplication was introduced as a global hash match, so it can reuse the wrong in-flight run and it still races under concurrency

File refs: `app/Services/UnifiedMediaProcessor.php:65-70`, `app/Services/UnifiedMediaProcessor.php:236-270`, `tests/Feature/Api/MediaUploadDeduplicationTest.php:45-230`

The March upload dedupe work matches only on `file_hash` plus an active status. `UnifiedMediaProcessor::findActiveDuplicate()` does not scope that lookup by media type, requested processing mode, or caller ownership. A video upload and a livestream upload of the same bytes can therefore collide, and there is no protection against one caller being handed another caller's in-flight processing ID if the hashes match.

The method also documents its own time-of-check/time-of-use gap: two concurrent identical uploads can both miss the lookup and both create new runs. The current test file locks in only the happy path for same-content, same-type uploads and does not cover cross-pipeline, cross-owner, or concurrent submissions.

Smallest safe improvement: scope dedupe by pipeline and caller boundary, then enforce that scope atomically with a unique in-flight claim or durable lock. Add negative tests for video-vs-livestream collisions, cross-owner collisions, and concurrent identical uploads.

### 3. High: the reporting-state promotion added columns, but the live code still treats JSON metadata as a second authority

File refs: `database/migrations/2026_03_22_120000_promote_service_reporting_state_to_columns.php:172-193`, `app/Models/ServiceSection.php:197-222`, `app/Support/ServiceSectionConfidence.php:16-32`, `app/Services/SectionAlignmentBaselineRestorer.php:91-104`, `app/Services/SermonExtractionPlanResolver.php:203-215`

The March 22 promotion migrated `song_match_type`, `matched_item_id`, `expected_item_id`, and `confidence` into first-class columns, which is the right direction. The problem is that the current runtime still keeps the JSON contract alive as an equal peer. `ServiceSection::{songMatchType,matchedItemId,expectedItemId}()` silently falls back to `metadata.oos_alignment`, `ServiceSectionConfidence::resolve()` still derives runtime confidence from `confidence_score` and `confidence_level` in metadata, and `SectionAlignmentBaselineRestorer` explicitly preserves redundant writes to both the column and JSON because different parts of the app still read both.

That means the promotion did not actually simplify the authority model. New code still has to keep two representations in sync, and the read side still reaches into JSON for filtering and planning. `SermonExtractionPlanResolver` is a good example: even after the migration, it still queries `metadata->confidence_level = 'high'` instead of querying the numeric column that was added to support exactly this sort of decision.

Smallest safe improvement: pick the new columns as the only runtime authority, backfill the remaining rows, then remove the metadata fallbacks from `ServiceSection`, `ServiceSectionConfidence`, and query code. `confidence_level` can remain as derived display data if needed, but it should stop affecting runtime decisions.

### 4. Medium: the church-service review-state refactor stopped short of promoting pending merge workflow out of JSON

File refs: `app/Data/ChurchServiceImportMetadata.php:15-24`, `app/Data/ChurchServiceImportMetadata.php:79-80`, `app/Queries/ServiceReviewDashboardQuery.php:184-189`, `tests/Unit/Services/ChurchServiceStructureMergeServiceTest.php:74-78`

The March 22 review-state migration did good work by promoting review and canonical-conflict state to columns, but `pending_structure_merge` stayed in `church_services.import_metadata`. The dashboard summary still counts pending merges with `JSON_CONTAINS_PATH(...)`, and the DTO layer still serializes `pendingStructureMerge` back into the JSON blob as active workflow state.

That leaves the review area with a split contract: some review state is indexable, constrained, and explicit, while one of the most operationally important workflow flags is still hidden in JSON. The result is predictable tech debt: query complexity, no schema-level validation, and more compatibility code when the next review-surface refactor lands.

Smallest safe improvement: promote pending-merge workflow state into first-class columns or a dedicated related record, update dashboard queries to use that explicit state, and then remove the JSON-path dependency from `ServiceReviewDashboardQuery`.

### 5. Medium: media-processing identity was promoted into columns, but the resolver and tests still preserve `processing_metadata` as a live fallback path

File refs: `app/Services/MediaProcessingIdentityResolver.php:18-27`, `app/Services/MediaProcessingIdentityResolver.php:97-112`, `tests/Unit/Services/MediaProcessingIdentityResolverTest.php:44-60`, `tests/Unit/Services/MediaProcessingIdentityResolverTest.php:140-166`

March promoted `extracted_date` and `extracted_service` into first-class columns on `media_processing_logs`, but `MediaProcessingIdentityResolver` still treats the old `processing_metadata.extracted_*` keys as an equally valid read path whenever either column is null. The unit tests codify that compatibility behavior as part of the intended contract.

That keeps the identity boundary more complicated than it needs to be. Every matching query now has to express both shapes, and the app cannot fully retire the metadata copies because the fallback remains production logic instead of a bounded backfill bridge. This is exactly the kind of "temporary forever" compatibility layer that quietly slows later cleanup.

Smallest safe improvement: finish the backfill, stop writing `extracted_*` keys into `processing_metadata`, and make the resolver and query scopes column-only once the compatibility window ends.

### 6. Medium: `SermonViewPresenter` memoization now depends on hydration order because the presenter is a singleton

File refs: `app/Providers/MediaProcessingServiceProvider.php:18-22`, `app/Presenters/SermonViewPresenter.php:173-197`, `app/Presenters/SermonViewPresenter.php:312-353`, `app/Presenters/SermonViewPresenter.php:432-440`, `tests/Unit/Presenters/SermonViewPresenterTest.php:37-184`

The March read-side cleanup added a lot of memoization to `SermonViewPresenter`, but the presenter is also registered as a container singleton. Its cache keys are based only on sermon ID, field type, and `updated_at`. Several of the cached methods are relation-sensitive, though: `displayPreacherName()` prefers `preacherProfile` when loaded and falls back to `sermon->preacher` otherwise, while `preacherUrl()` and `displayReference()` make similar relation-aware choices.

That means the first hydration shape wins for the lifetime of the singleton instance. If the presenter sees a partially-loaded sermon first and a relation-loaded instance of the same sermon later in the request lifecycle, the second call reuses the stale cached answer. The current tests only exercise one hydration shape at a time, so this subtle coupling is not protected.

Smallest safe improvement: either stop binding `SermonViewPresenter` as a singleton or restrict its cache to relation-independent values. Add a regression test that uses the same presenter instance with partially-loaded and relation-loaded versions of the same sermon in one request lifecycle.

## Tech-Debt Summary

Yes. This March 18-22 window improved a lot of architecture, but it also introduced or codified several pieces of tech debt that are worth paying down while the changes are still fresh.

The highest-risk debt is concentrated in three seams:

- private Children's Talk media now has two competing delivery paths, and the guarded path is incomplete
- upload dedupe is not scoped tightly enough and is not atomic
- multiple March state promotions stopped with dual authority still split across columns and JSON

The good news is that the mitigation work is still bounded. Most of it is not a rollback; it is the follow-through needed to finish the boundary moves that this change window already started.
