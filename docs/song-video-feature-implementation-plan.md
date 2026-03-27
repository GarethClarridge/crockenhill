# Song Video Feature — Implementation Plan

> Based on approved requirements: `docs/song-video-feature-requirements.md`
> Revised 2026-03-26 after architecture review (Codex findings + handler pattern decision)

## Architecture Decision: Type-Aware Publication Handlers

The existing section publication pipeline is sermon-centric — it requires admin approval, both extracted video and audio, and produces a `Sermon` record. Song videos need none of these: no approval, no audio, and they produce a `SongVideo` record instead.

Rather than adding song-specific conditionals alongside the existing children's-talk conditionals in `PrepareSectionPublicationCandidates`, we introduce a **publication handler** pattern. Each section type that can be published registers a handler that controls:

- What media to extract (video only, or video + audio)
- Whether admin approval is required
- How to publish (what artifact to create)
- How to clean up when a source section is superseded or deleted during resync

The extraction pipeline stays type-agnostic. Only the post-extraction publication flow becomes type-aware.

### Why now

`PrepareSectionPublicationCandidates` already branches on `ServiceSectionType::CHILDRENS_TALK` in two places (lines 138–141 and 174–176). `PublishApprovedServiceSection` has a children's-talk speaker check (line 126) inside what should be generic code. Adding songs as a third type via more conditionals would accelerate this drift. Two divergent types is the right time to generalize.

---

## PR Overview

| PR | Title | Dependencies | Scope |
|----|-------|-------------|-------|
| 1  | Song video data model & foundation | None | Migration, model, factory, relationships, unit tests |
| 2  | Section publication handler infrastructure | None | Handler interface, sermon handler (refactor), sync cleanup hooks, zero behavior change |
| 3  | Song video service & publication pipeline | PR 1, PR 2 | SongVideoService, SongPublicationHandler, config, pipeline tests |
| 4  | Song page video display | PR 1, PR 3 | Controller, blade template, feature tests |
| 5  | Admin song video management | PR 1, PR 3 | Livewire component updates, upload/feature/delete UI, feature tests |

---

## PR 1: Song Video Data Model & Foundation

### Goal
Create the `song_videos` table, `SongVideo` model, factory, and wire up relationships.

### Tasks

**Migration: `create_song_videos_table`**
```
- id (bigIncrements)
- song_id (foreignId, constrained, cascadeOnDelete)
- service_section_id (foreignId, nullable, unique, constrained, nullOnDelete)
- church_service_id (foreignId, nullable, constrained, nullOnDelete)
- video_file_path (string 500)
- duration (float, nullable)
- recorded_date (date, nullable)
- is_featured (boolean, default false)
- timestamps
- Index on [song_id, is_featured]
- Index on [song_id, recorded_date]
```

**Model: `app/Models/SongVideo.php`**
- Fillable: all columns except id/timestamps
- Casts: `is_featured` → boolean, `recorded_date` → date, `duration` → float
- Relationships: `song()`, `serviceSection()`, `churchService()`
- Scope: `scopeFeatured()`, `scopeForSong()`
- Helper: `isFeatured(): bool`

**Add relationships to `Song` model:**
- `videos(): HasMany` ordered by `recorded_date desc`
- `featuredVideo(): HasOne` where `is_featured = true`
- `displayVideo(): ?SongVideo` method — returns featured video, falling back to most recent. **Must sort nulls last** for `recorded_date` to avoid manual uploads (null date) outranking dated videos: `orderByRaw('recorded_date IS NULL, recorded_date DESC')`

**Factory: `database/factories/SongVideoFactory.php`**
- Default state with fake video path, duration, recorded_date
- States: `featured()`, `manual()` (no service_section_id/church_service_id, null recorded_date)

**Tests:**
- `tests/Unit/Models/SongVideoTest.php` — model casts, relationships, scopes
- `tests/Unit/Models/SongTest.php` — add tests for new `videos`, `featuredVideo`, `displayVideo` relationships
- `displayVideo` tests must cover: featured takes priority, most recent by date as fallback, manual uploads (null date) do not outrank dated videos, song with only manual uploads and no featured video returns null (forcing explicit featuring)

### Files Changed
- `database/migrations/xxxx_create_song_videos_table.php` (new)
- `app/Models/SongVideo.php` (new)
- `app/Models/Song.php` (add relationships)
- `database/factories/SongVideoFactory.php` (new)
- `tests/Unit/Models/SongVideoTest.php` (new)
- `tests/Unit/Models/SongTest.php` (extend)

---

## PR 2: Section Publication Handler Infrastructure

### Goal
Introduce a handler pattern for post-extraction publication, replacing scattered type-specific conditionals. Extract existing sermon/children's-talk logic into `SermonPublicationHandler`. **This is a pure refactor — zero behavior change, all existing tests must pass.**

### Architecture

**Interface: `app/Contracts/SectionPublicationHandler.php`**
```php
interface SectionPublicationHandler
{
    /** Whether extracted media should include audio (sermons: yes, songs: no) */
    public function requiresAudioExtraction(): bool;

    /** Whether previously extracted media can be reused for this section.
     *  Sermon handler checks both video + audio; song handler checks video only. */
    public function hasReusableExtractedMedia(ServiceSection $section): bool;

    /** Type-specific eligibility beyond the generic status checks.
     *  e.g. songs require confirmed match, sermons require high confidence. */
    public function isEligible(ServiceSection $section): bool;

    /** Runs after extraction — type-specific enrichment (e.g. speaker detection) */
    public function afterExtraction(ServiceSection $section): void;

    /** Whether admin approval is needed before publishing */
    public function requiresApproval(): bool;

    /** Create the downstream artifact (Sermon, SongVideo, etc.) */
    public function publish(ServiceSection $section): void;

    /** Clean up downstream artifacts when a section is superseded or deleted */
    public function onSectionRemoved(ServiceSection $section): void;
}
```

**Implementation: `app/Services/SectionPublication/SermonPublicationHandler.php`**
- `requiresAudioExtraction()`: true
- `isEligible()`: checks high confidence (current `require_high_confidence` config logic)
- `afterExtraction()`: runs `ChildrensTalkSpeakerService::detectAndStore()` for children's talk sections
- `requiresApproval()`: true
- `publish()`: extracted from `PublishApprovedServiceSection` — promotes assets, creates sermon via `SermonCreationService`, transitions to PUBLISHED
- `onSectionRemoved()`: extracted from `ServiceSectionSyncService::detachPublishedLinkBeforeStaleDelete()` — logs warning for published sections

**Factory: `app/Services/SectionPublication/SectionPublicationHandlerFactory.php`**
```php
class SectionPublicationHandlerFactory
{
    public function forSection(ServiceSection $section): ?SectionPublicationHandler
    {
        $handlers = config('media-processing.section_publishing.handlers', []);
        $class = $handlers[$section->section_type->value] ?? null;
        return $class ? app($class) : null;
    }
}
```

**Config change: `config/media-processing.php`**

Replace `extract_types` and `publishable_types` with a unified `handlers` map:
```php
'section_publishing' => [
    'enabled' => true,
    'handlers' => [
        'childrens_talk' => \App\Services\SectionPublication\SermonPublicationHandler::class,
        // 'sermon' => \App\Services\SectionPublication\SermonPublicationHandler::class, // future
    ],
    'require_high_confidence' => true,
    'retain_unpublished_hours' => 48,
],
```

A section type with a registered handler is extractable and publishable. Types without handlers are NOT_APPLICABLE. This replaces the separate `extract_types` and `publishable_types` arrays.

### Refactoring Targets

**`PrepareSectionPublicationCandidates`** — replace type conditionals with handler delegation:
- `eligibleByType` → `$handler !== null` (handler exists for this type)
- `eligibleByConfidence` → `$handler->isEligible($section)` (handler decides)
- Children's-talk-specific extraction ordering → `$handler->afterExtraction($section)` called after extraction
- `PENDING_APPROVAL` transition → only if `$handler->requiresApproval()`
- Non-approval path → dispatch auto-publish (deferred to PR 3, no auto-publish types exist yet)

**`PublishApprovedServiceSection`** — delegate to handler:
- Keep as the queue entry point for admin-approval-triggered publication
- Replace inline sermon creation logic with `$handler->publish($section)`
- Remove hardcoded children's talk speaker check (now in handler's `publish()`)

**`ServiceSectionSyncService`** — add handler cleanup hooks:
- In `cleanupExtractedAssets()` path (signature change): also call `$handler->onSectionRemoved($section)` if handler exists
- In stale section deletion: call `$handler->onSectionRemoved($section)` before delete
- Replace inline `detachPublishedLinkBeforeStaleDelete()` with handler call

**`ServiceSectionPublicationTransitionService`** — update `isPublishableType()` and state machine:
- Check handler registry instead of `publishable_types` config
- Add `PUBLISHED` to allowed transitions from `NOT_APPLICABLE`:
  ```php
  ServiceSectionPublicationStatus::NOT_APPLICABLE => [
      ServiceSectionPublicationStatus::PENDING_APPROVAL,
      ServiceSectionPublicationStatus::PUBLISHED,  // auto-publish handlers
  ],
  ```
  This is required because auto-publish handlers (songs) skip the PENDING_APPROVAL → APPROVED flow entirely. The handler pattern controls when this transition is used — the transition service just allows it.

**Handler-aware extracted media reuse:**
- The existing `ServiceSection::hasExtractedMedia()` requires both video AND audio paths. Song sections only have video (no audio). The preparation job's `shouldReuseExtractedMedia()` logic must become handler-aware.
- Add a `hasReusableExtractedMedia(ServiceSection $section): bool` method to the handler interface. `SermonPublicationHandler` checks both video and audio; `SongPublicationHandler` checks video only.
- `PrepareSectionPublicationCandidates` calls `$handler->hasReusableExtractedMedia($section)` instead of the model's `hasExtractedMedia()`.

### Tests

All existing tests must pass unchanged — this is a pure refactor:
- `tests/Feature/Jobs/PrepareSectionPublicationCandidatesTest.php` — no changes needed
- `tests/Feature/Jobs/PublishApprovedServiceSectionTest.php` — no changes needed
- `tests/Unit/Services/ServiceSectionSyncServiceTest.php` — no changes needed

New tests:
- `tests/Unit/Services/SectionPublication/SermonPublicationHandlerTest.php` — unit test the extracted logic
- `tests/Unit/Services/SectionPublication/SectionPublicationHandlerFactoryTest.php` — resolution, null for unknown types

### Files Changed
- `app/Contracts/SectionPublicationHandler.php` (new)
- `app/Services/SectionPublication/SermonPublicationHandler.php` (new)
- `app/Services/SectionPublication/SectionPublicationHandlerFactory.php` (new)
- `config/media-processing.php` (edit — replace extract_types/publishable_types with handlers map)
- `app/Jobs/PrepareSectionPublicationCandidates.php` (refactor — delegate to handlers)
- `app/Jobs/PublishApprovedServiceSection.php` (refactor — delegate to handler)
- `app/Services/ServiceSectionSyncService.php` (edit — add handler cleanup hooks)
- `app/Services/ServiceSectionPublicationTransitionService.php` (edit — use handler registry, add NOT_APPLICABLE → PUBLISHED transition)
- `tests/Unit/Services/SectionPublication/SermonPublicationHandlerTest.php` (new)
- `tests/Unit/Services/SectionPublication/SectionPublicationHandlerFactoryTest.php` (new)
- `tests/Unit/Services/ServiceSectionPublicationTransitionServiceTest.php` (extend — verify NOT_APPLICABLE → PUBLISHED is allowed)

---

## PR 3: Song Video Service & Publication Pipeline

### Goal
Create `SongVideoService` and `SongPublicationHandler`, enable automatic extraction and publication of confirmed song segments from livestreams.

### Tasks

**Service: `app/Services/SongVideoService.php`**

Methods:
- `getVideoUrl(SongVideo $video): string` — generates CDN or local URL using `Storage::disk(sermon_disk)->url()`, same pattern as `SermonStorageService::getVideoUrl()`
- `getDisplayVideoForSong(Song $song): ?SongVideo` — returns featured or most recent video (eager-loadable query, nulls-last ordering)
- `featureVideo(SongVideo $video): void` — transaction: unset any existing featured video for the song, set this one
- `unfeatureVideo(SongVideo $video): void` — unset `is_featured`
- `deleteVideo(SongVideo $video): void` — delete stored file from sermon_disk, delete record. **For auto-extracted videos** (has `service_section_id`): also reset the linked `ServiceSection` publication status to NOT_APPLICABLE and clear `published_at`, so the pipeline can re-extract on the next run. Without this reset, `PrepareSectionPublicationCandidates` skips PUBLISHED sections unconditionally.
- `createFromUpload(Song $song, UploadedFile $file): SongVideo` — store file on sermon_disk at `sermons/songs/{song_id}/{ulid}.mp4` (ULID prefix prevents filename collisions), create record
- `createFromExtraction(ServiceSection $section, string $videoPath): SongVideo` — create record linking to section's song, service, and recorded date

**Handler: `app/Services/SectionPublication/SongPublicationHandler.php`**
- `requiresAudioExtraction()`: false — songs only need video
- `isEligible()`: requires `song_match_type === CONFIRMED` and non-null `song_id` on the linked `ChurchServiceItem` (R1.2)
- `afterExtraction()`: no-op
- `requiresApproval()`: false
- `publish()`:
  1. Promote extracted video from candidate storage to sermon_disk at `sermons/songs/{song_id}/{section_id}.mp4`
  2. Call `SongVideoService::createFromExtraction()` to create the record
  3. Skip if `service_section_id` already exists in `song_videos` (idempotent via unique constraint)
  4. Transition section to PUBLISHED
- `onSectionRemoved()`: find `SongVideo` by `service_section_id`, delete stored file, delete record

**Config change: `config/media-processing.php`**
- Add `'song'` → `SongPublicationHandler::class` to the `handlers` map

**Auto-publish dispatch in `PrepareSectionPublicationCandidates`:**
- For handlers where `requiresApproval()` returns false, dispatch a new `AutoPublishServiceSection` job after extraction instead of transitioning to PENDING_APPROVAL
- `AutoPublishServiceSection` is a thin job that resolves the handler and calls `$handler->publish($section)` within a transaction

**Self-healing behavior:**
- When an admin deletes an auto-extracted `SongVideo` (via PR 5), `deleteVideo()` resets the linked `ServiceSection` publication status from PUBLISHED to NOT_APPLICABLE and clears `published_at`
- This is necessary because `PrepareSectionPublicationCandidates` skips PUBLISHED sections unconditionally (line 161). Without the reset, the pipeline would never re-process the section, even on full reprocessing
- Resync alone is NOT sufficient for self-healing — sections only reset during resync when the classification signature changes (different timecodes/type). If the same song is detected at the same timecodes, the signature is unchanged and resync preserves the section as-is
- After the reset, the next pipeline run re-extracts the video and the handler auto-publishes a new `SongVideo`. The unique constraint on `service_section_id` is satisfied because the old row was deleted
- This is intentional: auto-extracted song videos are renewable artifacts. The admin UI communicates this behavior

**Tests:**
- `tests/Unit/Services/SongVideoServiceTest.php` — featuring/unfeaturing logic, URL generation, file deletion, upload handling (ULID paths), duplicate prevention
- `tests/Unit/Services/SectionPublication/SongPublicationHandlerTest.php` — eligibility (confirmed vs inferred match), publish flow, cleanup on section removal, idempotent re-publish
- `tests/Feature/Jobs/AutoPublishServiceSectionTest.php` — happy path, handler resolution, error handling
- `tests/Feature/Jobs/PrepareSectionPublicationCandidatesTest.php` — extend with song extraction scenarios (confirmed match extracted, inferred match skipped, auto-publish dispatched instead of PENDING_APPROVAL)

### Files Changed
- `app/Services/SongVideoService.php` (new)
- `app/Services/SectionPublication/SongPublicationHandler.php` (new)
- `app/Jobs/AutoPublishServiceSection.php` (new)
- `config/media-processing.php` (edit — add song handler)
- `app/Jobs/PrepareSectionPublicationCandidates.php` (edit — auto-publish dispatch for non-approval handlers)
- `tests/Unit/Services/SongVideoServiceTest.php` (new)
- `tests/Unit/Services/SectionPublication/SongPublicationHandlerTest.php` (new)
- `tests/Feature/Jobs/AutoPublishServiceSectionTest.php` (new)
- `tests/Feature/Jobs/PrepareSectionPublicationCandidatesTest.php` (extend)

---

## PR 4: Song Page Video Display

### Goal
Show the display video (featured or most recent) on the song show page.

### Access
The song page is **members-only** (auth-gated at `routes/web.php:188`). The "Public" prefix on `PublicSongListController` means non-admin, not unauthenticated. This PR does not change access control.

### Tasks

**Controller: `PublicSongListController@show`**
- Load the display video for the song via `SongVideoService::getDisplayVideoForSong()`
- Pass video URL to the view (or null if no video exists)

**View: `resources/views/church/songs/show.blade.php`**
- Add video player section above usage history, matching sermon video player pattern:
  ```blade
  @if ($videoUrl)
  <video src="{{ $videoUrl }}" class="w-full rounded-lg" controls>
    Your browser does not support the video element.
  </video>
  @endif
  ```
- Follow existing design guide and frontend-design skill for styling/layout
- No empty state — section simply absent when no video

**Tests:**
- `tests/Feature/PublicSongPageTest.php` (or extend existing `PublicSongDetailTest.php`)
  - Song page with video shows player
  - Song page without video has no player markup
  - Featured video takes priority over most recent
  - Video URL uses correct CDN path
  - Unauthenticated users still redirected to login (existing behavior preserved)

### Files Changed
- `app/Http/Controllers/PublicSongListController.php` (edit)
- `resources/views/church/songs/show.blade.php` (edit)
- `tests/Feature/PublicSongPageTest.php` (new or extend existing)

---

## PR 5: Admin Song Video Management

### Goal
Add video management to the admin song show page: list all videos, feature/unfeature, delete, manual upload.

### Tasks

**Livewire component: `app/Livewire/Admin/ChurchServices/ShowSong.php`**
- Load all song videos with church service relationship
- Actions:
  - `featureVideo(int $videoId)` — calls `SongVideoService::featureVideo()`
  - `unfeatureVideo(int $videoId)` — calls `SongVideoService::unfeatureVideo()`
  - `deleteVideo(int $videoId)` — calls `SongVideoService::deleteVideo()` with confirmation
  - `uploadVideo()` — file upload via Livewire `WithFileUploads`, calls `SongVideoService::createFromUpload()`
- Properties:
  - `$uploadedVideo` — temporary file upload (Livewire)
- Validation for upload: video mimetypes, max file size

**View: `resources/views/livewire/admin/church-services/show-song.blade.php`**
- New "Videos" section after existing content
- Table/card list of all videos:
  - Recorded date (or "Manual upload")
  - Service type (morning/evening) — from church service relationship
  - Duration (formatted mm:ss)
  - Featured badge (highlighted when `is_featured`)
  - Actions: Feature/Unfeature toggle, Delete button (with confirmation)
- For auto-extracted videos, show a note: "Automatically extracted — will be recreated on reprocessing if deleted"
- Upload form at bottom: file input + upload button with loading state
- Follow frontend-design skill for styling consistency

**Tests:**
- `tests/Feature/Livewire/AdminSongVideoManagementTest.php`
  - Renders video list
  - Feature/unfeature toggles correctly (only one featured at a time)
  - Delete removes record and file
  - Upload creates record and stores file with ULID prefix
  - Upload validation (wrong mime type, too large)
  - Empty state when no videos

### Files Changed
- `app/Livewire/Admin/ChurchServices/ShowSong.php` (edit)
- `resources/views/livewire/admin/church-services/show-song.blade.php` (edit)
- `tests/Feature/Livewire/AdminSongVideoManagementTest.php` (new)

---

## Implementation Notes

### CDN URL Pattern
Both `SermonStorageService::getVideoUrl()` and the new `SongVideoService::getVideoUrl()` follow the same pattern: `Storage::disk(config('media-processing.storage.sermon_disk'))->url($path)`. Assess during PR 3 whether to extract a shared helper or keep it as a simple one-liner in both services.

### Storage Paths
- Livestream-extracted song videos: `sermons/songs/{song_id}/{section_id}.mp4`
- Manually uploaded song videos: `sermons/songs/{song_id}/{ulid}.mp4` (ULID prevents filename collisions from multiple uploads)
- This differs from sermon sections (`sermons/sections/{id}/video.mp4`) to keep song videos organized by song.

### Null `recorded_date` Ordering
Manual uploads have `recorded_date = null`. The `displayVideo()` query must sort nulls last to prevent manual uploads from outranking dated videos in the "most recent" fallback. Use `orderByRaw('recorded_date IS NULL, recorded_date DESC')`. A song with only manual uploads and no featured video intentionally returns no display video — the admin must explicitly feature one.

### Idempotency & Self-Healing
The unique constraint on `service_section_id` is the primary guard against duplicate `SongVideo` records. The handler checks for existence before creating and skips gracefully.

**Delete → re-extract cycle:** When an admin deletes an auto-extracted `SongVideo`, `deleteVideo()` actively resets the linked section's `publication_status` to NOT_APPLICABLE. This is required because `PrepareSectionPublicationCandidates` skips PUBLISHED sections unconditionally — resync alone does NOT reset the section unless the classification signature changes. After the reset, the next pipeline run re-extracts and re-publishes. The admin UI indicates this behavior for auto-extracted videos.

### Resync Cleanup
When `ServiceSectionSyncService` supersedes or deletes a section, it calls `$handler->onSectionRemoved()`. The `SongPublicationHandler` uses this hook to delete the linked `SongVideo` row and its stored file, preventing orphaned rows and broken URLs. The `nullOnDelete` FK handles cases where the handler is unavailable — the row survives with `service_section_id = null`, but the file may be orphaned (acceptable degradation).

### Section Publication Status for Songs
Song sections transition directly from NOT_APPLICABLE → PUBLISHED via the auto-publish handler. This requires a state machine change in `ServiceSectionPublicationTransitionService` (PR 2): adding PUBLISHED to the allowed transitions from NOT_APPLICABLE. The existing APPROVED → PUBLISHED path remains for approval-required types.

The `published_sermon_id` column on `ServiceSection` remains null for song sections — only sermon/children's-talk publication sets it. This is safe because `published_sermon_id` is nullable at the database level; the non-null requirement is enforced only in `PublishApprovedServiceSection` (the approval-path job), which songs never use.

If the section is later superseded during resync, it transitions back to NOT_APPLICABLE (existing behavior in `ServiceSectionSyncService::supersededReplacementPayload()`). The handler's `onSectionRemoved()` cleans up the `SongVideo` at that point. On re-extraction, a new `SongVideo` is created.

### PHPStan & Pint
Run after every PR. The project must stay at 0 PHPStan errors and pass Pint formatting.

---

## Codex Review Findings — Resolution Summary

| Finding | Resolution |
|---------|------------|
| PR 3 can't fit existing publication model (APPROVED→PUBLISHED, requires sermon_id + both media paths) | Handler pattern: `SongPublicationHandler` owns its own publish flow. Transition service updated to allow NOT_APPLICABLE → PUBLISHED for auto-publish handlers. `published_sermon_id` stays null (nullable at DB level, only enforced in the approval-path job). `hasReusableExtractedMedia()` on handler replaces model's `hasExtractedMedia()` which requires both video+audio. |
| Resync can orphan `SongVideo` rows when sections are deleted/superseded | `onSectionRemoved()` handler hook called from `ServiceSectionSyncService` during cleanup |
| Admin delete leaves no recovery path (PUBLISHED sections skipped) | `deleteVideo()` actively resets linked section from PUBLISHED → NOT_APPLICABLE (resync alone is insufficient — only resets on signature change). Next pipeline run re-extracts and re-publishes. |
| Manual uploads with null `recorded_date` may sort incorrectly | Nulls-last ordering in `displayVideo()` query; manual-only songs require explicit featuring |
| Upload filename collisions at `sermons/songs/{song_id}/{filename}` | ULID prefix: `sermons/songs/{song_id}/{ulid}.mp4` |
| "Public" song page is actually auth-gated | Clarified in PR 4: members-only, no access control change |
