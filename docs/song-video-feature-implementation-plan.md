# Song Video Feature — Implementation Plan

> Based on approved requirements: `docs/song-video-feature-requirements.md`

## PR Overview

| PR | Title | Dependencies | Scope |
|----|-------|-------------|-------|
| 1  | Song video data model & foundation | None | Migration, model, factory, relationships, unit tests |
| 2  | Song video service layer | PR 1 | SongVideoService, CDN URLs, featuring logic, file operations |
| 3  | Automatic extraction pipeline | PR 1, PR 2 | Config, confirmed-match filter, auto-publish job, pipeline tests |
| 4  | Public song page video display | PR 1, PR 2 | Controller, blade template, feature tests |
| 5  | Admin song video management | PR 1, PR 2 | Livewire component updates, upload/feature/delete UI, feature tests |

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
- `displayVideo(): ?SongVideo` method — returns featured video, falling back to most recent

**Factory: `database/factories/SongVideoFactory.php`**
- Default state with fake video path, duration, recorded_date
- States: `featured()`, `manual()` (no service_section_id/church_service_id)

**Tests:**
- `tests/Unit/Models/SongVideoTest.php` — model casts, relationships, scopes
- `tests/Unit/Models/SongTest.php` — add tests for new `videos`, `featuredVideo`, `displayVideo` relationships

### Files Changed
- `database/migrations/xxxx_create_song_videos_table.php` (new)
- `app/Models/SongVideo.php` (new)
- `app/Models/Song.php` (add relationships)
- `database/factories/SongVideoFactory.php` (new)
- `tests/Unit/Models/SongVideoTest.php` (new)
- `tests/Unit/Models/SongTest.php` (extend)

---

## PR 2: Song Video Service Layer

### Goal
Create `SongVideoService` for URL generation, featuring logic, and file operations. Reuse CDN patterns from `SermonStorageService`.

### Tasks

**Service: `app/Services/SongVideoService.php`**

Methods:
- `getVideoUrl(SongVideo $video): string` — generates CDN or local URL using `Storage::disk(sermon_disk)->url()`, same pattern as `SermonStorageService::getVideoUrl()`
- `getDisplayVideoForSong(Song $song): ?SongVideo` — returns featured or most recent video (eager-loadable query)
- `featureVideo(SongVideo $video): void` — transaction: unset any existing featured video for the song, set this one
- `unfeatureVideo(SongVideo $video): void` — unset `is_featured`
- `deleteVideo(SongVideo $video): void` — delete stored file from sermon_disk, delete record
- `createFromUpload(Song $song, UploadedFile $file): SongVideo` — store file on sermon_disk at `sermons/songs/{song_id}/{filename}`, create record
- `createFromServiceSection(ServiceSection $section, string $videoPath): SongVideo` — create record linking to section's song, service, and recorded date

**Shared CDN logic:**
- Extract the CDN URL resolution from `SermonStorageService` into a shared trait or helper if duplication is significant, or simply reuse the same `Storage::disk()->url()` call (assess during implementation).

**Tests:**
- `tests/Unit/Services/SongVideoServiceTest.php` — featuring/unfeaturing logic, URL generation, file deletion, upload handling, duplicate prevention

### Files Changed
- `app/Services/SongVideoService.php` (new)
- `tests/Unit/Services/SongVideoServiceTest.php` (new)

---

## PR 3: Automatic Extraction Pipeline

### Goal
Enable automatic extraction of confirmed song segments from livestreams, and auto-publish them as `SongVideo` records.

### Tasks

**Config change: `config/media-processing.php`**
- Add `'song'` to `section_publishing.extract_types`
- Add `'song'` to `section_publishing.publishable_types`

**Filter: Confirmed match gate in `PrepareSectionPublicationCandidates`**
- Add check in the eligibility logic: for SONG-type sections, require `song_match_type === CONFIRMED` and a non-null `song_id` on the linked `ChurchServiceItem`
- Sections without confirmed matches are skipped (not extracted)

**New job: `app/Jobs/PublishSongVideo.php`**
- Dispatched after `PrepareSectionPublicationCandidates` completes extraction for a SONG section
- Flow:
  1. Validate section has extracted video path and confirmed song match
  2. Promote video file from candidate storage to sermon_disk using same `promoteExtractedAsset` pattern as `PublishApprovedServiceSection`
  3. Target path: `sermons/songs/{song_id}/{section_id}.mp4`
  4. Call `SongVideoService::createFromServiceSection()` to create the record
  5. If `service_section_id` already exists in `song_videos`, skip (idempotent)
- No approval step — runs automatically after extraction
- Uses `ShouldQueue` interface for background processing

**Integration point:**
- In `PrepareSectionPublicationCandidates`, after successful extraction of a SONG section, dispatch `PublishSongVideo` job instead of setting `PENDING_APPROVAL` status
- This diverges from the children's talk flow (which requires approval) — songs go straight to published

**Tests:**
- `tests/Feature/Jobs/PublishSongVideoTest.php` — happy path, duplicate prevention, missing video handling, unconfirmed match rejection
- `tests/Feature/Jobs/PrepareSectionPublicationCandidatesTest.php` — extend with song extraction scenarios (confirmed vs unconfirmed)

### Files Changed
- `config/media-processing.php` (edit)
- `app/Jobs/PrepareSectionPublicationCandidates.php` (edit — add confirmed match gate, dispatch song job)
- `app/Jobs/PublishSongVideo.php` (new)
- `tests/Feature/Jobs/PublishSongVideoTest.php` (new)
- `tests/Feature/Jobs/PrepareSectionPublicationCandidatesTest.php` (extend)

---

## PR 4: Public Song Page Video Display

### Goal
Show the display video (featured or most recent) on the public song show page.

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

**Service: `PublicSongUsageService`**
- No changes needed — video display is independent of usage stats

**Tests:**
- `tests/Feature/PublicSongPageTest.php` (or extend existing song page tests)
  - Song page with video shows player
  - Song page without video has no player markup
  - Featured video takes priority over most recent
  - Video URL uses correct CDN path

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
- Upload form at bottom: file input + upload button with loading state
- Follow frontend-design skill for styling consistency

**Tests:**
- `tests/Feature/Livewire/AdminSongVideoManagementTest.php`
  - Renders video list
  - Feature/unfeature toggles correctly (only one featured at a time)
  - Delete removes record and file
  - Upload creates record and stores file
  - Upload validation (wrong mime type, too large)
  - Empty state when no videos

### Files Changed
- `app/Livewire/Admin/ChurchServices/ShowSong.php` (edit)
- `resources/views/livewire/admin/church-services/show-song.blade.php` (edit)
- `tests/Feature/Livewire/AdminSongVideoManagementTest.php` (new)

---

## Implementation Notes

### CDN URL Pattern
Both `SermonStorageService::getVideoUrl()` and the new `SongVideoService::getVideoUrl()` follow the same pattern: `Storage::disk(config('media-processing.storage.sermon_disk'))->url($path)`. Assess during PR 2 whether to extract a shared helper or keep it as a simple one-liner in both services.

### Storage Paths
- Livestream-extracted song videos: `sermons/songs/{song_id}/{section_id}.mp4`
- Manually uploaded song videos: `sermons/songs/{song_id}/{filename}.mp4`
- This differs from sermon sections (`sermons/sections/{id}/video.mp4`) to keep song videos organized by song.

### Idempotency
The unique constraint on `service_section_id` is the primary guard against duplicate `SongVideo` records. The `PublishSongVideo` job should check for existence before creating and skip gracefully.

### PHPStan & Pint
Run after every PR. The project must stay at 0 PHPStan errors and pass Pint formatting.
