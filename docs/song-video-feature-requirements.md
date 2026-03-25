# Song Video Feature — Requirements

> Approved: 2026-03-25

## Background

Songs are tracked via `ChurchServiceItem` records linked to `Song` records. Livestream processing segments videos into `ServiceSection` records, but **only CHILDRENS_TALK sections are currently extracted** — song sections are identified but their video/audio is never extracted or stored.

The extraction pipeline (`PrepareSectionPublicationCandidates`) is type-agnostic — it checks a config array to decide what to extract. Adding `'song'` enables automatic extraction.

## Goals

Allow users to see and play a video of each song being sung, sourced automatically from livestream recordings. Admins can manage and feature videos.

---

## R1: Automatic Song Video Extraction

**R1.1** Add `'song'` to the `media-processing.section_publishing.extract_types` config array so that SONG-type `ServiceSection` records are automatically extracted during livestream processing.

**R1.2** Only sections with CONFIRMED `song_match_type` are eligible for extraction. INFERRED and UNMATCHED sections are skipped.

**R1.3** Extracted song videos stored using the existing pattern on the configured `sermon_disk` (DigitalOcean Spaces CDN in production).

**R1.4** Reuse `VideoExtractionService` and `PrepareSectionPublicationCandidates` — gated by config change and confirmed-match filter only.

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

- `Song hasMany SongVideo` (ordered by `recorded_date desc`)
- `Song hasOne SongVideo` (featured — where `is_featured = true`)
- `SongVideo belongsTo Song`
- `SongVideo belongsTo ServiceSection`
- `SongVideo belongsTo ChurchService`

**R2.3** Business rule: Only one `SongVideo` per song can have `is_featured = true`. Setting a new featured video must unset the previous one (database transaction).

**R2.4** Unique constraint on `service_section_id` to prevent duplicate records from reprocessing.

## R3: Public Song Page — Video Display

**R3.1** On the public song show page (`/church/songs/{slug}`), display a single video at the top of the page, above the usage history.

**R3.2** Video selection priority: **featured video** if one exists, otherwise **most recent** by `recorded_date`.

**R3.3** Use the same HTML5 `<video>` player pattern as the sermon page: native controls, rounded corners. No poster/thumbnail.

**R3.4** Video URL served via CDN using the same `Storage::disk()->url()` / CDN endpoint pattern as `SermonStorageService`.

**R3.5** If no video exists for the song, no video section is shown (no empty state).

**R3.6** Create a `SongVideoService` for generating video URLs, reusing the existing CDN logic from `SermonStorageService` rather than duplicating it.

## R4: Admin Song Management

**R4.1** On the admin song show page, add a "Videos" section displaying all available song videos.

**R4.2** Each video entry shows: recorded date, service type, duration, featured badge, and action buttons.

**R4.3** Admin actions:

- **Feature** a video (unfeaturing any previously featured video for that song)
- **Unfeature** a video
- **Delete** a video (removes `SongVideo` record and stored files)
- **Upload** a video manually (creates a `SongVideo` with no `service_section_id` or `church_service_id`)

**R4.4** The featured video has a visual indicator (badge/highlight) in the list.

## R5: Song Video Publishing Pipeline

**R5.1** After extraction, a new job or extension to the existing pipeline creates the `SongVideo` record by:

1. Copying extracted video from candidate storage to permanent sermon_disk
2. Creating the `SongVideo` record with metadata (song_id, duration, recorded_date, etc.)

**R5.2** Fully automatic — no admin approval step.

**R5.3** Unique constraint on `service_section_id` prevents duplicates on reprocessing.

## R6: Testing

**R6.1** Unit tests for `SongVideo` model, factory, and relationships.

**R6.2** Feature tests for public song page with/without video, featured vs most-recent fallback.

**R6.3** Feature tests for admin video listing, featuring, deletion, manual upload.

**R6.4** Feature tests for song video creation during extraction pipeline.

**R6.5** Existing song and sermon tests must continue to pass.

---

## Out of Scope

- Thumbnails / poster images
- Retention policies / auto-deletion
- Audio-only extraction or playback
- Playlist/autoplay between videos
- Public listing of all song videos across songs
- Video for INFERRED or UNMATCHED songs
