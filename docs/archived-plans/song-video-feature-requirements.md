> **Archived 2026-07-05.** Feature shipped — see `song-video-feature-implementation-plan.md` (completed April 2026).

# Song Video Feature — Requirements

> Approved: 2026-03-25
> Revised: 2026-03-26 (architecture review — handler pattern, state machine, delete semantics)

## Background

Songs are tracked via `ChurchServiceItem` records linked to `Song` records. Livestream processing segments videos into `ServiceSection` records, but **only CHILDRENS_TALK sections are currently extracted** — song sections are identified but their video/audio is never extracted or stored.

The extraction pipeline (`PrepareSectionPublicationCandidates`) is type-agnostic — it checks a handler registry to decide what to extract. The post-extraction publication flow is type-specific: each publishable section type registers a handler that controls approval, artifact creation, and cleanup. Adding a song handler enables automatic extraction and publication.

## Goals

Allow authenticated users to see and play a video of each song being sung, sourced automatically from livestream recordings. Admins can manage and feature videos.

---

## R1: Automatic Song Video Extraction & Publication

**R1.1** Register a song publication handler in the `media-processing.section_publishing.handlers` config so that SONG-type `ServiceSection` records are automatically extracted and published during livestream processing.

**R1.2** Only sections with CONFIRMED `song_match_type` are eligible for extraction. INFERRED and UNMATCHED sections are skipped. This eligibility check is owned by the song handler, not hardcoded in the pipeline.

**R1.3** Song extraction is video-only — no audio extraction. This differs from sermon/children's-talk extraction which requires both video and audio.

**R1.4** Extracted song videos stored using the existing pattern on the configured `sermon_disk` (DigitalOcean Spaces CDN in production).

**R1.5** Reuse `VideoExtractionService` and `PrepareSectionPublicationCandidates` for extraction. Publication uses a type-specific handler that creates a `SongVideo` record (not a `Sermon`).

**R1.6** Song publication is fully automatic — no admin approval step. This differs from children's talks which require admin approval before publication. The section transitions directly from NOT_APPLICABLE to PUBLISHED via the handler.

## R2: Song Video Data Model

**R2.1** Create a `song_videos` table and `SongVideo` model:

| Column               | Type                         | Notes                                       |
| -------------------- | ---------------------------- | ------------------------------------------- |
| `id`                 | bigIncrements                | PK                                          |
| `song_id`            | foreignId                    | FK to songs                                 |
| `service_section_id` | foreignId, nullable, unique  | FK to service_sections (null for manual)     |
| `church_service_id`  | foreignId, nullable          | FK to church_services (null for manual)      |
| `video_file_path`    | string(500)                  | Path on sermon_disk                         |
| `duration`           | float, nullable              | Duration in seconds                         |
| `recorded_date`      | date, nullable               | Service date (null for manual uploads)       |
| `is_featured`        | boolean, default false       | Only one per song can be featured           |
| `created_at`         | timestamp                    |                                             |
| `updated_at`         | timestamp                    |                                             |

**R2.2** Relationships:

- `Song hasMany SongVideo` (ordered by `recorded_date desc`, **nulls last**)
- `Song hasOne SongVideo` (featured — where `is_featured = true`)
- `SongVideo belongsTo Song`
- `SongVideo belongsTo ServiceSection`
- `SongVideo belongsTo ChurchService`

**R2.3** Business rule: Only one `SongVideo` per song can have `is_featured = true`. Setting a new featured video must unset the previous one (database transaction).

**R2.4** Unique constraint on `service_section_id` to prevent duplicate records from reprocessing.

**R2.5** Display video selection: featured video takes priority; fallback is most recent by `recorded_date` with **nulls sorted last**. A song with only manual uploads (null `recorded_date`) and no featured video has no display video — the admin must explicitly feature one.

## R3: Song Page — Video Display

**R3.1** On the song show page (`/church/songs/{slug}`), display a single video at the top of the page, above the usage history. This page is members-only (auth-gated) — "public" means non-admin, not unauthenticated.

**R3.2** Video selection priority: **featured video** if one exists, otherwise **most recent** by `recorded_date` (nulls last). See R2.5 for manual-upload-only behavior.

**R3.3** Use the same HTML5 `<video>` player pattern as the sermon page: native controls, rounded corners. No poster/thumbnail.

**R3.4** Video URL served via CDN using the same `Storage::disk()->url()` / CDN endpoint pattern as `SermonStorageService`.

**R3.5** If no display video exists for the song, no video section is shown (no empty state).

**R3.6** Create a `SongVideoService` for generating video URLs, reusing the existing CDN logic from `SermonStorageService` rather than duplicating it.

## R4: Admin Song Management

**R4.1** On the admin song show page, add a "Videos" section displaying all available song videos.

**R4.2** Each video entry shows: recorded date (or "Manual upload"), service type, duration, featured badge, source indicator (auto-extracted or manual), and action buttons.

**R4.3** Admin actions:

- **Feature** a video (unfeaturing any previously featured video for that song)
- **Unfeature** a video
- **Delete** a video (removes `SongVideo` record and stored files). For auto-extracted videos: also resets the linked `ServiceSection` publication status to NOT_APPLICABLE, allowing re-extraction on next processing run. The admin UI should indicate this: "Automatically extracted — will be recreated on reprocessing if deleted."
- **Upload** a video manually (creates a `SongVideo` with no `service_section_id` or `church_service_id`)

**R4.4** The featured video has a visual indicator (badge/highlight) in the list.

**R4.5** Manual uploads use a ULID-prefixed filename to prevent collisions when multiple files are uploaded for the same song.

## R5: Song Video Publishing Pipeline

**R5.1** After extraction, the song publication handler creates the `SongVideo` record by:

1. Promoting the extracted video from candidate storage to permanent sermon_disk
2. Creating the `SongVideo` record with metadata (song_id, duration, recorded_date, etc.)
3. Transitioning the `ServiceSection` to PUBLISHED status

**R5.2** Fully automatic — no admin approval step. The handler's `requiresApproval()` returns false, so the pipeline dispatches auto-publication immediately after extraction.

**R5.3** Unique constraint on `service_section_id` prevents duplicates on reprocessing. Handler checks for existence before creating and skips gracefully.

**R5.4** Deleting an auto-extracted `SongVideo` resets the linked `ServiceSection` publication status from PUBLISHED to NOT_APPLICABLE. This allows the extraction pipeline to re-extract and re-publish the video on the next processing run. Without this reset, the pipeline skips PUBLISHED sections unconditionally.

**R5.5** When the source `ServiceSection` is superseded (classification changed) or deleted as stale during resync, the song handler's cleanup hook deletes the linked `SongVideo` row and its stored file, preventing orphaned records and broken URLs.

## R6: Testing

**R6.1** Unit tests for `SongVideo` model, factory, and relationships.

**R6.2** Feature tests for song page with/without video, featured vs most-recent fallback, nulls-last ordering, manual-upload-only behavior.

**R6.3** Feature tests for admin video listing, featuring, deletion (including section status reset for auto-extracted), manual upload (ULID filenames).

**R6.4** Feature tests for song video creation during extraction pipeline, including confirmed-match eligibility gate and auto-publish dispatch.

**R6.5** Feature tests for resync cleanup: handler removes SongVideo when source section is superseded or deleted.

**R6.6** Existing song, sermon, and children's talk tests must continue to pass — the handler refactor is a behavioral no-op for existing types.

---

## Out of Scope

- Thumbnails / poster images
- Retention policies / auto-deletion
- Audio-only extraction or playback
- Playlist/autoplay between videos
- Public listing of all song videos across songs
- Video for INFERRED or UNMATCHED songs
- Unauthenticated access to song pages (currently members-only)
