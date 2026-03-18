# Read-Path Performance Review

Date: 2026-03-18

Scope reviewed:
- Public pages and page presenters/composers
- Sermon listing/detail pages
- Meeting pages and calendar-backed reads
- Podcast feeds
- Sitemap generation
- Sermon/media file-serving endpoints
- Admin dashboards and review queues
- Jobs that shape read-time data or duplicate expensive derived work

Notes:
- This is a code review, not a profiled production trace.
- The local database was empty during review, so the findings below are structural hot spots and scale risks rather than measured regressions.

## Highest-Leverage Findings

### 1. Service review dashboard rebuilds the whole queue on every Livewire render

Evidence:
- `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:276-287` rebuilds groups and summary inside `render()`.
- `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:513-560` reloads every flagged `ServiceSection` with nested `processingLog`, `churchServiceItem`, `churchService`, and `song` relations.
- `resources/views/livewire/admin/church-services/service-review-dashboard.blade.php:257-297` uses `wire:model.live` / `wire:model.live.debounce` for section type, title, preacher, and speaker edits, so typing re-runs the full query-and-grouping pipeline.

Why it matters:
- The dashboard work is `O(flagged sections)` for every Livewire request, not just for the first page load.
- The current shape makes editor interactions pay for queue reconstruction, grouping, sorting, URL generation, and repeated metadata parsing every time.

Recommended direction:
- Split the queue list from the edit form state so typing does not rebuild the queue.
- Move the queue into a read model / projection table (for example: one row per review candidate with resolved service identity, counts, preview URLs, and review flags).
- Change edit fields to `defer`/`blur` semantics where live typing is not required.
- Cache `preacherOptions()` instead of querying on every render.

### 2. The sermon pipeline analyzes the transcript twice

Evidence:
- `app/Jobs/ProcessTranscriptWithAI.php:69-145` reads the transcript, calls `analyzeSermon(...)`, stores `ai_analysis`, and updates the sermon record.
- `app/Jobs/UpdateSermonRecord.php:41-80` then loads the sermon again, re-fetches the latest processing log, and updates the sermon from a fresh analysis.
- `app/Jobs/UpdateSermonRecord.php:126-149` re-reads the transcript and calls `analyzeSermon(...)` again instead of consuming `processing_logs.ai_analysis`.

Why it matters:
- This duplicates the most expensive CPU / network work in the non-livestream pipeline.
- It also re-reads the transcript from storage and re-queries existing series just to produce fields that already exist.

Recommended direction:
- Collapse `UpdateSermonRecord` into a lightweight finalizer that consumes `ai_analysis`.
- If the finalizer only exists for slug finalization and notification dispatch, move those responsibilities into `ProcessTranscriptWithAI` or a minimal follow-up job that does not re-run AI.

### 3. Podcast feed generation still performs per-sermon storage IO, and the cache is intentionally left stale

Evidence:
- `app/Services/PodcastFeedService.php:51-64` loads up to 100 sermons and maps over each one.
- `app/Services/PodcastFeedService.php:70-78` calls `getFileSize(...)` for every item, which becomes one storage round-trip per sermon on cache misses.
- `app/Observers/SitemapCacheObserver.php:63-64` explicitly does not clear podcast feed cache when sermons or preachers change.

Why it matters:
- On a cold/missed feed cache, the request performs a burst of storage calls just to emit `<enclosure length>`.
- After edits, the feed can stay stale until TTL expiry because invalidation is intentionally skipped.

Recommended direction:
- Persist `audio_file_size`, public enclosure URL, and episode image URL onto `sermons` or into a feed-specific projection.
- Cache the final XML or a fully enriched feed manifest rather than recomputing per item on request.
- Restore feed invalidation on relevant model changes and fix tests around that behavior instead of encoding staleness into production behavior.

## Medium-Priority Findings

### 4. The presenter/composer layer re-queries route models instead of reusing controller-loaded data

Evidence:
- `app/View/Presenters/SermonDetailPresenter.php:16-52` re-queries the sermon for detail pages even though `SermonController@show` already resolved it.
- `app/View/Presenters/SectionPagePresenter.php:15-47` re-queries `Page` records for section routes rather than using `PageRepository` cache.
- `app/Repositories/PageRepository.php:19-29` already provides an area-level cached page source.

Why it matters:
- Every sermon detail request does an avoidable second sermon lookup.
- Public section pages bypass the existing cached repository and keep a second read path alive in the presenter layer.

Recommended direction:
- Push controller-resolved page/sermon metadata into the layout data and let presenters consume that instead of re-querying.
- Standardize on a cached page view model sourced from `PageRepository` for section/area pages.

### 5. Sermon detail pages load the whole service-section graph just to find a Bible reading reference

Evidence:
- `app/Services/SermonPageContextService.php:54-72` loads `processingLog.serviceSections.churchServiceItem` and then filters in PHP.
- `app/Services/SermonPageContextService.php:101-110` scans the entire section collection to find the first `BIBLE_READING`.

Why it matters:
- The page needs one derived field (`reading_reference`) but currently hydrates the whole section tree.
- This couples public sermon pages to classification/reconciliation internals and makes the read path more complex than necessary.

Recommended direction:
- Query only the first reading section directly from `service_sections` with the needed join.
- Better still, persist `reading_reference` onto the sermon or published section during reconciliation/publication so the sermon page becomes a single-row read.

### 6. Public page and meeting payloads still derive markdown and hero image URLs on demand

Evidence:
- `app/Http/Controllers/PageController.php:54-83` reads the page, renders markdown, and resolves desktop/tablet/mobile hero URLs on every request.
- `app/Models/Page.php:237-309` falls back to `Storage::disk('public')->exists(...)` for image detection and URL resolution.
- `resources/views/components/page-card.blade.php:16-17` resolves `heading_image_small_url` at render time for public landing cards.
- `app/Http/Controllers/MeetingController.php:41-72` loads `page` but not `page.media`, then resolves `heading_image_url` and runs separate upcoming/past event queries on every show request.

Why it matters:
- Public pages repeatedly pay for markdown conversion and media/storage probing even though the content changes infrequently.
- Meeting pages still assemble a mini view model on demand instead of reading a stable cached payload.

Recommended direction:
- Introduce a cached page payload keyed by `page.id` + `updated_at` that stores rendered HTML, meta description, and resolved image URLs.
- Eager load `page.media` anywhere hero image accessors are used.
- Consider a cached meeting page DTO that includes upcoming/past event slices and invalidates on calendar sync or meeting/page updates.

### 7. Hot thumbnail routes still proxy through PHP and compute file hashes on demand

Evidence:
- `resources/views/components/sermon-card.blade.php:11-14`, `resources/views/components/childrens-talk-card.blade.php:12-20`, and `resources/views/sermons/sermon.blade.php:96-100` all point public traffic at sermon thumbnail routes.
- `app/Http/Controllers/SermonAssetController.php:101-137` checks existence, resolves the path, calls `filemtime(...)`, and computes `md5_file(...)` on every thumbnail request.
- `app/Http/Controllers/SermonAssetController.php:42-58` also keeps audio delivery on the PHP path unless a CDN endpoint is configured.

Why it matters:
- Popular listing pages turn image delivery into application work instead of static/CDN work.
- Computing `md5_file(...)` per request is especially wasteful for immutable thumbnails.

Recommended direction:
- Emit stable versioned storage URLs directly from the read model and let the web server/CDN serve them.
- If the route must remain, prefer redirecting to storage/CDN URLs and avoid per-request hashing.

## Lower-Priority but Worth Addressing

### 8. Sitemap generation still does extra in-memory work and duplicate page image checks

Evidence:
- `app/Services/SitemapService.php:57-63` already scopes sermons with `whereVisibleInSitemap()` and then filters them again in PHP with `shouldIncludeInSitemap(...)`.
- `app/Models/Page.php:348-372` calls `hasImage()` and then `image_url`, which can duplicate media/storage checks per page.

Why it matters:
- Sitemap generation is already cached, so this is not the hottest path, but it is still doing avoidable work every time the file is regenerated.
- As the number of pages/sermons grows, this keeps sitemap generation tied to per-model accessor behavior.

Recommended direction:
- Remove the redundant sermon filter when `whereVisibleInSitemap()` already expresses visibility.
- Use pre-resolved page image data for sitemap tags rather than calling two accessors that can both probe storage.

### 9. The livestream review queue over-fetches `MediaProcessingLog`

Evidence:
- `app/Livewire/Admin/ChurchServices/ProcessingReviewList.php:22-34` paginates `MediaProcessingLog` with `select *`.
- `resources/views/livewire/admin/church-services/processing-review-list.blade.php:24-91` only needs `processing_id`, `original_filename`, `updated_at`, `extracted_date`, `extracted_service`, and the manual review metadata from `processing_metadata`.
- `media_processing_logs` also carries large JSON fields (`ai_analysis`, `visual_samples`, `song_clusters`, `processing_metadata`, `rms_stats`).

Why it matters:
- The queue page loads much heavier rows than it renders.
- This is a smaller issue than the main service-review dashboard, but it is easy to fix and keeps admin reads leaner.

Recommended direction:
- Add an explicit `select(...)` for the queue view.
- If manual review becomes a first-class workflow, project `reason_code`, `flagged_at`, `speech_segment_count`, and `confirmed_segment_id` into dedicated columns or a queue projection.

## Suggested Execution Order

1. Remove duplicate AI work by shrinking or deleting `UpdateSermonRecord`.
2. Build a review-queue projection for `ServiceReviewDashboard`, then stop using `wire:model.live` for large-form fields.
3. Add a feed projection or persisted enclosure metadata so the podcast feed stops doing per-item storage probes.
4. Introduce cached page / meeting read models for rendered content and hero assets.
5. Simplify sermon detail context by persisting or directly querying the reading reference.
6. Move public image delivery off the PHP hot path.
7. Tidy lower-cost admin/sitemap over-fetching once the higher-leverage items are done.
