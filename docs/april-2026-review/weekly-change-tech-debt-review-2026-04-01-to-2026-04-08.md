# Weekly Change Tech-Debt Review

Date: 2026-04-16

Scope: committed changes from 2026-04-01 through 2026-04-08 inclusive. This review covers committed Git history only and excludes the current uncommitted working tree. The reviewed range was `d2f6dd0f8e31ab0f914e57b834acf39668772bd0..003e478e1c2171c62572acd0580983d2ad0f4940`.

## Findings

### 1. High: private Children's Talk assets still bypass the guarded asset boundary after the April private-storage hardening

File refs: `app/Presenters/SermonViewPresenter.php`, `app/Services/SermonStorageService.php`, `resources/views/components/childrens-talk-card.blade.php`, `resources/views/childrens-corner/show.blade.php`, `app/Http/Controllers/SermonAssetController.php`, `config/filesystems.php`

The private-storage migration for Children's Talk media was strengthened during this window, but the rendering path still emits direct storage URLs from `SermonViewPresenter::{audioUrl,thumbnailUrl,cardThumbnailUrl,videoUrl}()`. Those methods delegate to `SermonStorageService`, which resolves URLs directly from the backing disk.

That works for public disks, but it breaks the intended contract for private assets. In this app the `local` disk points at `storage/app` and has no public URL configured in `config/filesystems.php`, while the guarded delivery path for private sermon assets is the `SermonAssetController` route set. Once a Children's Talk asset has been moved under `private/...`, the presenter can still emit a storage URL like `/storage/private/...` instead of the guarded route. The result is a bad combination of behaviors on live surfaces:

- the Children's Corner card and detail page can render broken media URLs
- meta tags and schema output can advertise non-canonical private asset URLs
- the code no longer consistently routes private asset access through the controller boundary that was added specifically to guard it

This is a correctness bug today, not just a theoretical architecture concern, because the read-side and the storage hardening now disagree on which component owns private asset delivery.

Smallest safe improvement: make the presenter or a dedicated asset-URL service choose between direct-public URLs and guarded asset routes based on asset privacy, then add focused regression coverage for Children's Corner cards, detail pages, and metadata output after a private-storage move.

### 2. High: `DeleteLivestreamUpload` can delete sermons it does not actually own

File refs: `app/Actions/DeleteLivestreamUpload.php`, `tests/Feature/Actions/DeleteLivestreamUploadTest.php`

The new April 8 upload-deletion flow is intentionally destructive, but its ownership check is too broad. `DeleteLivestreamUpload::loadOwnedSermons()` starts from the correct predicate, `where('livestream_processing_id', $processingId)`, then broadens the query with an `orWhere(...)` that also includes:

- `media_processing_logs.sermon_id`
- every `service_sections.published_sermon_id` attached to the run

That means a sermon only needs to be referenced by the run to be treated as owned by the run. A manually curated sermon or a sermon later repointed onto a section can therefore be deleted when an operator removes a broken upload, even if that sermon was not created by that upload. Because `service_sections.published_sermon_id` is unique and `nullOnDelete()`, the delete also silently clears the section's publication link, which makes the damage harder to spot.

The current feature coverage only exercises upload-owned sermons, so the regression path is not protected by tests.

Smallest safe improvement: restrict destructive sermon deletion to rows with explicit upload ownership, and treat section unlinking as a separate operation from sermon-row deletion. Add a regression test where a service section points at a sermon that is referenced by the run but owned elsewhere, and assert that deleting the upload preserves that sermon.

### 3. Medium: the processing phase registry drifted out of sync with the real job graph after `AssessSermonVideoQuality` was inserted

File refs: `app/Services/ProcessingPipelineBuilder.php`, `app/Services/ProcessingPhaseRegistry.php`, `tests/Unit/Services/ProcessingPhaseRegistryTest.php`

April inserted `AssessSermonVideoQuality` ahead of `GenerateThumbnail` in the direct-video, auto-trim, and livestream pipelines. The retry/progress registry was not updated to match that extra job, and the tests now lock in the stale offsets.

Concrete example: in the livestream chain, `PrepareSectionPublicationCandidates` is job offset `16`, because it now runs after `AssessSermonVideoQuality` and `GenerateThumbnail`. The registry and its test still map `preparing_section_publication_candidates` to offset `15`. Similar late-stage offsets are shifted for `sending_notification` and `cleanup`, and there is no explicit phase mapping for the new `assessing_video_quality` step at all.

This means the execution model and the operator model are no longer the same thing:

- progress reporting no longer faithfully describes the pipeline that is actually running
- late-stage retry plans can resume from the wrong job
- the tests now reinforce the drift instead of catching it

This is classic operational tech debt: the feature itself exists, but the surrounding retry and observability contract is now partially inaccurate.

Smallest safe improvement: derive phase offsets from the same source that defines the pipeline, add an explicit `assessing_video_quality` phase mapping, and add invariant tests that compare the registry offsets against the real pipeline arrays for direct video, auto-trim video, and livestream processing.

## Tech-Debt Summary

Yes, this change window introduced tech debt, and some of it is already user-facing.

The main risk is concentrated in three seams:

- private Children's Talk asset delivery now has two competing owners, and the wrong one can win at render time
- the new broken-upload deletion flow infers ownership too loosely for a destructive action
- the processing retry/progress model drifted from the actual pipeline after the new video-quality step was inserted

The good news is that the mitigation work is still bounded. One asset-URL boundary fix, one upload-deletion ownership fix, and one pipeline/registry reconciliation pass should remove most of the risk without rolling back the broader April improvements.
