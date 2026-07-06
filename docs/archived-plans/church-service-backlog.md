> **Archived 2026-07-05.** Implementation backlog for the March 2026 PRD (`church-service-prd.md`, also archived). Superseded by the LLM-first pipeline work and the July 2026 backlog. Do not work from this file.

# Implementation Backlog: Church Service Processing

**Source**: [PRD v2](church-service-prd.md)
**Date**: March 2026

Each item includes what exists, what needs to change, and which PRD section it implements.

---

## Phase 1: Incremental Assembly Foundation

*Goal: The classifier works without an OoS. Late-arriving OoS data triggers re-alignment, not a missed opportunity.*

### 1.1 Add `church_service_id` FK to `media_processing_logs`

**PRD ref**: §6.3

**What exists**: `MediaProcessingLog` has no link to `ChurchService`. The `ServiceSectionClassifier` resolves the connection at runtime via `MediaProcessingIdentityResolver` (date + service type lookup). If no match is found, classification is skipped entirely.

**Work**:
- Migration: add nullable `church_service_id` FK to `media_processing_logs` (nullOnDelete).
- When `ServiceSectionClassifier` resolves a `ChurchService`, write the ID back to the processing log.
- Add `churchService()` belongsTo relationship on `MediaProcessingLog`.
- Add `mediaProcessingLogs()` hasMany on `ChurchService`.

**Tests**: Migration, relationship, classifier writes FK on match.

---

### 1.2 Make classifier produce sections without a ChurchService

**PRD ref**: §6.2 Required Behaviors

**What exists**: `ServiceSectionClassifier::classify()` returns `skipped: true` when no `ChurchService` is found (unless `require_matching_church_service` is false, in which case it returns empty sections). The classifier is structurally incapable of producing section data without OoS items to iterate over.

**Work**:
- When no `ChurchService` exists, the classifier should still iterate over `LivestreamSegment` records and produce `ServiceSection` records:
  - Song segments → `ServiceSectionType::SONG` (no title, low confidence).
  - Longest speech segment meeting sermon criteria (§7.2) → `ServiceSectionType::SERMON`.
  - Other speech segments → `ServiceSectionType::OTHER` with `needs_manual_review = true`.
- Confidence metadata should record `classification_mode: 'audio_only'` (vs existing `'openlp_aligned'`).
- Remove or deprecate the `require_matching_church_service` config flag — the classifier should always produce what it can.

**Tests**: Classifier with no ChurchService produces sermon + song + other sections. Classifier with ChurchService still produces OoS-aligned results (existing tests still pass).

---

### 1.3 Sermon detection confidence policy

**PRD ref**: §7.2

**What exists**: Current logic uses `is_sermon_candidate` flag on `LivestreamSegment` (longest speech segment ≥ 300 seconds). No comparison against other candidates.

**Work**:
- Implement the two-part rule: auto-select only when exactly one speech block ≥ 20 minutes AND it is ≥ 1.5× the next-longest.
- If ambiguous: do not auto-create the sermon. Set processing log to a review state. Send `ManualReviewRequired` email.
- Update `ExtractSermon` job to check this policy before extracting.

**Tests**: Single clear sermon → auto-extract. Two long speech blocks of similar length → flagged for review. No speech block ≥ 20 min → flagged.

---

### 1.4 Re-alignment when OoS arrives after livestream processing

**PRD ref**: §6.3

**What exists**: No trigger exists. If a `ChurchService` is created after a livestream is processed, nothing happens.

**Work**:
- Add a model observer or event listener on `ChurchService` (created/updated).
- On fire: query `MediaProcessingLog` for matching date + service with completed processing.
- If found: dispatch a lightweight `ReconcileServiceSections` job that:
  - Links `media_processing_logs.church_service_id`.
  - Runs the OoS alignment pass (Phase 3, Step 5 of the pipeline) against existing `ServiceSection` records.
  - Updates song titles, labels, and confidence scores.
  - Flags mismatches for review.
- This job must NOT re-run audio analysis, extraction, or transcription.

**Tests**: Create ChurchService after processing → reconciliation dispatched. ChurchService updated → reconciliation re-dispatched. No matching processing log → no dispatch.

---

### 1.5 Source-aware merge in ChurchServiceItemSyncService

**PRD ref**: §6.3 (OpenLP arrives after email/manual)

**What exists**: `ChurchServiceItemSyncService` does stable matching by type + search title, then position fallback. It overwrites all fields on match. No awareness of which source provided the item.

**Work**:
- Add `source` enum column to `church_service_items`: `email`, `openlp`, `manual`.
- Migration to add the column (nullable, backfill existing as `openlp`).
- When OpenLP items merge into an existing service that was created from email:
  - Match songs by normalised title (not position).
  - Enrich with `openlp_search_title`, `song_id` linkage, and metadata.
  - Do NOT overwrite speech items (prayers, notices, children's talks) that the email provided but OpenLP doesn't have.
- When email items merge into an existing service from OpenLP:
  - Add speech items that OpenLP didn't have.
  - Do NOT remove song metadata that OpenLP provided.

**Tests**: Email creates service with prayers/songs → OpenLP enriches songs without removing prayers. OpenLP creates service → email adds prayers without removing song metadata.

---

### 1.6 Implement evidence-aware precedence and review reopening

**PRD ref**: §§5.1.1, 6.1, 6.3, 7.3-7.5

**What exists**: The current implementation has most of the source-aware merge foundations, but four follow-up gaps remain:
- partial OpenLP imports can still remove unmatched human-entered songs;
- inferred song labels are not separated from confirmed song matches for public counting;
- later conflicting imports do not reliably reopen review on a previously reviewed service;
- item-level canonical list changes do not reliably trigger lightweight reconciliation after processing.

**Work**:
- Change `ChurchServiceItemSyncService` so OpenLP-over-email precedence applies only to song metadata (`title`, `openlp_search_title`, `song_id`, song metadata), while unmatched lower-priority song items are preserved and flagged for review unless the import is explicitly marked as replace-mode.
- Add an explicit distinction between confirmed song matches and inferred review-only song labels on `ServiceSection`/alignment metadata, and update public song usage queries to count only confirmed livestream matches.
- Reopen `ChurchService.needs_review` automatically when a later email/OpenLP/manual import conflicts with a previously reviewed canonical service list.
- Dispatch lightweight reconciliation when the canonical item list changes after processing, not only when `ChurchService.date` or `ChurchService.service` changes.
- Record enough conflict metadata to show admins why review was reopened and which fields/items disagreed.

**Tests**: Partial OpenLP merge does not silently delete unmatched human-entered songs. OpenLP still wins over email for song title/search/catalog data. Late conflicting import reopens review on a previously reviewed service. Review-only inferred song labels are excluded from public song usage. Item edits after processing dispatch reconciliation.

---

### 1.7 Emit canonical-list-changed events after commit

**PRD ref**: §6.3

**What exists**: Lightweight reconciliation currently depends on touching `ChurchService.updated_at` and a model observer. That is too implicit: item-level changes can be missed, and create/import flows can fire before related items are fully committed.

**Work**:
- Add an explicit after-commit domain event, for example `ChurchServiceCanonicalListChanged`.
- Dispatch it when the canonical item list materially changes via:
  - manual service save;
  - email import;
  - OpenLP import;
  - any future admin/item sync flow that changes active items, ordering, or metadata used by reconciliation.
- Move reconciliation dispatch logic out of timestamp-touch side effects and into an event listener that:
  - resolves matching completed livestream runs;
  - dispatches `ReconcileServiceSections` once per matching run;
  - records why reconciliation was triggered.
- Do not emit the event when a save makes no material canonical-list change.

**Tests**: Manual edit emits one after-commit canonical-list-changed event. Email import emits one event after items are saved. OpenLP import emits one event after items are saved. No-op save emits no event. Matching completed livestream runs receive reconciliation jobs from the listener.

---

## Phase 2: OoS Ingestion

*Goal: The church can get their order of service into the system via email, manual form, or OpenLP.*

### 2.1 Mailgun inbound webhook endpoint

**PRD ref**: §6.1 Source 1

**What exists**: Mailgun is configured for outbound email. No inbound routing exists.

**Work**:
- Create `POST /api/webhooks/mailgun/inbound` endpoint.
- Verify Mailgun signature (timestamp + token + signing key).
- Deduplicate by `Message-Id` header.
- Store raw inbound email metadata in a new `inbound_emails` table:
  - `message_id`, `from`, `subject`, `body_plain`, `body_html`, `received_at`, `status` (pending/processed/failed/rejected), `processing_metadata` (json).
- Dispatch `ProcessInboundOosEmail` job.

**Tests**: Valid signature → accepted. Invalid signature → 403. Duplicate message ID → 200 but not reprocessed. Missing fields → 422.

---

### 2.2 AI-assisted email body parsing

**PRD ref**: §6.1 Source 1

**What exists**: Nothing — this is new.

**Work**:
- Create `OosEmailParserService` that:
  - Takes the email body (plain text preferred, HTML fallback).
  - Extracts date from subject line or body.
  - Extracts service type (morning/evening) from subject or body.
  - Sends body to LLM with a structured prompt to extract `[{type, title}]` items.
  - Returns parsed items with a confidence score per the policy in §7.1.
- Create/update `ChurchService` + items via existing `ChurchServiceItemSyncService`.
- Set `source: 'email'` on created items.
- Set `needs_review = true` when confidence is 0.75–0.89.
- When confidence < 0.75: store the payload on `inbound_emails` but do not create/update the service. Admin can review and manually import.

**Tests**: Well-structured email → high confidence parse. Ambiguous email → needs_review. Garbage body → low confidence, no service created. Date extraction from various subject formats.

---

### 2.3 Manual admin form for OoS create/edit

**PRD ref**: §6.1 Source 2

**What exists**: `UploadChurchService` Livewire component handles OpenLP upload. No manual entry form exists.

**Work**:
- New Livewire component: `ManageChurchService` (or extend existing admin service views).
- Form fields: date picker, service type dropdown.
- Dynamic item list:
  - Type dropdown (all `ServiceSectionType` values).
  - Title text input.
  - Song autocomplete against `Song` catalog (when type is Song).
  - Drag-to-reorder (or up/down buttons).
  - Add/remove item buttons.
- On save: create/update via `ChurchServiceItemSyncService` with `source: 'manual'`.
- Edit mode: load existing `ChurchService` and populate form.
- Route: `GET /admin/services/create`, `GET /admin/services/{churchService}/edit`.

**Tests**: Create new service with mixed item types. Edit existing service (add, remove, reorder items). Song autocomplete returns matches. Duplicate date+service rejected.

---

### 2.4 Inbound email admin review UI

**PRD ref**: §7.1 (< 0.75 confidence payloads)

**What exists**: Nothing.

**Work**:
- Livewire component showing pending inbound emails (status = pending or failed).
- Display: sender, date, subject, parsed preview of items.
- Actions: approve (create service from parsed data), edit and approve (open manual form pre-populated), reject (mark as rejected).
- Link from admin dashboard.

**Tests**: Pending email displayed. Approve creates service. Reject marks as rejected. Edit opens pre-populated manual form.

---

### 2.5 Extract a shared OpenLP import application service

**PRD ref**: §§6.1, 6.3

**What exists**: OpenLP import orchestration is duplicated between the API controller and Livewire upload component. Both paths parse the archive, create/update `ChurchService`, sync items, link songs, and trigger reconciliation side effects separately.

**Work**:
- Create a shared application service or action, for example `ImportChurchServiceFromOpenLp`.
- Move the shared workflow into that service:
  - parse the `.osz`;
  - create/update `ChurchService`;
  - sync items;
  - link songs;
  - trigger canonical-list-changed side effects;
  - return a structured result for both UI and API callers.
- Update the Livewire upload component and API controller to call the shared service.
- Keep request/response and validation concerns in the controller/component; move business logic into the shared service.

**Tests**: Livewire upload still imports correctly via the shared service. API upload still imports correctly via the shared service. Shared service handles update-vs-create consistently across both entry points.

---

## Phase 3: Classification Rework

*Goal: Speech blocks are decomposed into individual sections using transcription and AI, not just OoS alignment.*

### 3.1 Targeted speech segment transcription

**PRD ref**: §6.2 Pipeline Step 3

**What exists**: `TranscribeAudio` job transcribes the sermon audio. `AudioTranscriptionService` supports chunking. No mechanism to transcribe arbitrary speech segments.

**Work**:
- New job: `TranscribeSpeechSegments`.
- For each non-sermon speech-type `ServiceSection` (type `OTHER` or any speech segment not yet transcribed):
  - Extract audio for that segment's time range using existing `VideoExtractionService`.
  - Transcribe via `AudioTranscriptionService`.
  - Store transcript on `ServiceSection.metadata.transcript`.
- Queue: use existing `audio-processing` queue.
- Skip segments shorter than a configurable minimum (e.g., 10 seconds — likely silence gaps).
- Config toggle: `media-processing.section_classification.transcribe_speech_segments` (default true).

**Tests**: Speech segment → audio extracted → transcribed → transcript stored in metadata. Short segment skipped. Config disabled → job no-ops.

---

### 3.2 AI section classification and splitting

**PRD ref**: §6.2 Pipeline Step 4

**What exists**: Nothing — this is new.

**Work**:
- New job: `ClassifySpeechSections`.
- For each transcribed non-sermon speech segment:
  - Send transcript to LLM with prompt to:
    - Identify section boundaries using phrases like "Let us pray", "Good morning children", "Our reading today is from..."
    - Label each sub-section with a `ServiceSectionType`.
    - Return timestamps (relative to segment start) for each split point.
    - Return a confidence score per sub-section.
  - If the segment contains multiple sections: split the original `ServiceSection` into multiple records with correct `start_time`/`end_time`.
  - Apply confidence thresholds from §7.3.
  - Set `confidence_source: 'ai_transcript'` in metadata.
- New service: `SpeechSectionClassificationService` (encapsulates the prompt building, response parsing, and section splitting logic).
- Handle edge case: mid-sermon songs (§11.2) — short song segments (< 90 seconds) embedded in the longest speech block should be folded into the sermon, not treated as standalone songs.

**Tests**: Single-type speech block → one section, correctly labelled. Multi-type speech block → split into correct sub-sections. Low confidence → marked for review. Mock LLM responses.

---

### 3.3 OoS alignment pass

**PRD ref**: §6.2 Pipeline Step 5

**What exists**: The current classifier IS the OoS alignment — it iterates OoS items and matches to segments. This needs to become a post-classification enrichment step instead.

**Work**:
- New service: `OosAlignmentService`.
- Input: set of classified `ServiceSection` records + optional `ChurchService` with items.
- For each OoS song item: find the detected `SONG` section with the best normalised title match. Assign the song title and `song_id`. Do not match by position alone.
- For OoS bible reading item: find the detected `BIBLE_READING` section. Enrich with the passage reference from the OoS item title. Store as `reading_reference` in section metadata (not in `Sermon.reference`).
- For other OoS items: match to detected sections by type. If types agree, raise confidence. If they disagree, lower confidence and flag mismatch.
- Compute service-level review triggers (§7.5).
- This service is called:
  - At the end of the livestream processing pipeline (if OoS exists).
  - By `ReconcileServiceSections` job (from 1.4) when OoS arrives later.

**Tests**: OoS songs matched by title not position. OoS and detected structure agree → confidence raised. OoS disagrees → flagged. Song order differs from OoS → no anomaly. Late OoS arrival → alignment updates existing sections.

---

### 3.4 Confidence scoring framework

**PRD ref**: §7.3, §7.5

**What exists**: Confidence is stored as string `'high'|'low'|'none'` in `ServiceSection.metadata.confidence_level`. No numeric score.

**Work**:
- Migration: add `confidence` decimal column to `service_sections` (0.0–1.0).
- Populate from AI classification output (3.2) and OoS alignment adjustments (3.3).
- Map existing string levels for backward compat: high=0.90, low=0.50, none=0.10.
- Implement service-level review trigger logic (§7.5):
  - Ambiguous sermon → flag.
  - Unmatched songs → flag.
  - OoS/detected structure mismatch → flag.
  - >20% of sections below 0.85 → flag.
- Set `ChurchService.needs_review = true` when triggered.

**Tests**: Confidence stored and queryable. Service flagged when >20% sections below threshold. Individual triggers work correctly.

---

### 3.5 Wire new pipeline into job chain

**PRD ref**: §6.2

**What exists**: Current pipeline: `AnalyzeSegments` → `ClassifyServiceSections` → `PrepareSectionPublicationCandidates`. The classify step is OoS-first.

**Work**:
- Update the livestream processing job chain:
  1. `AnalyzeSegments` (existing)
  2. `ClassifyServiceSections` (reworked in 1.2 — produces audio-only sections)
  3. `TranscribeSpeechSegments` (new from 3.1)
  4. `ClassifySpeechSections` (new from 3.2)
  5. `AlignWithOos` (new wrapper job that calls `OosAlignmentService` from 3.3)
  6. `ExtractSermon` (existing, with sermon confidence check from 1.3)
  7. Remaining existing pipeline (transcribe sermon, AI analysis, thumbnails, etc.)
  8. `PrepareSectionPublicationCandidates` (existing)
- Update `SermonJobPipelineService` or `ProcessingPipelineBuilder` to include new steps.

**Tests**: Full pipeline integration test with mock services. Pipeline with OoS. Pipeline without OoS. Pipeline with late OoS (reconciliation path).

---

### 3.6 Separate confirmed song matches from inferred review labels

**PRD ref**: §§5.4, 7.4

**What exists**: Inferred OoS song labels currently reuse the same linkage shape as confirmed matches, which makes downstream consumers treat review-only labels as if they were confirmed catalog-linked detections.

**Work**:
- Add explicit match state for detected song sections, for example `confirmed`, `inferred`, `unmatched`.
- Reserve confirmed state for strong title/catalog matches only.
- Keep inferred state for review-only labels that help admins but must not drive public song usage counts.
- Update `OosAlignmentService` to write the explicit song-match state.
- Update public song usage queries to count only confirmed livestream matches.
- Update review UI to show the difference between inferred and confirmed song matches.

**Tests**: Title-matched songs are marked confirmed. Order-inferred labels are marked inferred. Unmatched songs remain unmatched. Public song usage excludes inferred matches and includes confirmed ones only. Review dashboard surfaces inferred labels distinctly from confirmed matches.

---

## Phase 4: Children's Talks

*Goal: Children's talks are a first-class content type with their own public section.*

### 4.1 Add `content_type` to sermons

**PRD ref**: §6.4

**What exists**: `Sermon` has `source_type` (SermonSourceType) but no `content_type`. Children's talks are published as plain `Sermon` records with no distinguishing field.

**Work**:
- Create `SermonContentType` enum: `Sermon`, `ChildrensTalk`.
- Migration: add `content_type` string column to `sermons` (default `'sermon'`).
- Add cast in `Sermon` model.
- Add scopes: `Sermon::whereSermon()`, `Sermon::whereChildrensTalk()`.
- Update `PublishApprovedServiceSection` to set `content_type = childrens_talk` when publishing a children's talk section.
- Backfill: any existing sermons published from children's talk sections should be updated (check `ServiceSection` linkage).

**Tests**: New sermon defaults to content_type sermon. Published children's talk has correct content_type. Scopes filter correctly.

---

### 4.2 Exclude children's talks from sermon listings and podcast

**PRD ref**: §6.4

**What exists**: `SermonController::index()` queries by date without content_type filter. `PodcastFeedService` uses `Sermon::forPodcast()` scope.

**Work**:
- Update `SermonController::index()` to filter `whereSermon()`.
- Update `PodcastFeedService` to filter `whereSermon()`.
- Update any Livewire sermon listing components to filter.
- Update sermon search/browse to exclude children's talks.
- Verify admin sermon list still shows both (or has a filter toggle).

**Tests**: Sermon index excludes children's talks. Podcast feed excludes children's talks. Admin listing shows both.

---

### 4.3 Children's Corner public pages

**PRD ref**: §6.4 Public UX

**What exists**: Nothing — this is new public-facing work.

**Work**:
- Route: `GET /christ/childrens-corner` → listing page (paginated, 12 per page).
- Route: `GET /christ/childrens-corner/{sermon:slug}` → detail page.
- Both routes behind `auth` middleware until public launch.
- `ChildrensCornerController` querying `Sermon::whereChildrensTalk()`.
- Listing view: title, date, speaker (if known), media availability indicators. Reusable `x-childrens-talk-card` component.
- Detail view: simplified — title, date, speaker, video/audio player. No AI summary or key points by default.
- Navigation: "Children's Corner" link in Christ section header nav, visible to authenticated users only.
- Use the `frontend-design` skill for all UI work.

**Tests**: Listing shows only children's talks. Detail page renders. Sermon-type records not shown. Pagination works. Guests redirected to login. Nav link visible to authenticated users, hidden from guests.

---

## Phase 5: Sermon Display Enhancements

*Goal: Sermon pages show preached passage and service reading as distinct items.*

### 5.1 Display service reading reference on sermon page

**PRD ref**: §6.5

**What exists**: `Sermon.reference` stores the AI-extracted preached passage. The sermon show view displays it. No service reading concept exists on the page.

**Work**:
- When rendering a sermon page, look up the linked `ServiceSection` (via `published_sermon_id` on `ServiceSection`, or via `MediaProcessingLog` → `ServiceSection` chain).
- From the linked service sections, find the `BibleReading` section for the same service.
- Extract the reading reference from the section's OoS-enriched metadata or linked `ChurchServiceItem.title`.
- Display on sermon page as "Reading: [reference]" (distinct from the preached passage).
- Link the reference to BibleGateway: `https://www.biblegateway.com/passage/?search={reference}&version=NIVUK`.
- If no reading found, don't show anything — degrade silently.

**Tests**: Sermon with linked reading → displayed. Sermon without reading → no reading shown. Reference links to BibleGateway.

---

## Phase 6: Public Song Usage

*Goal: Public page showing most-sung worship songs. For livestreamed services, only songs actually detected in the recording count. For non-livestreamed services (e.g. evening services), the order of service is trusted directly.*

### 6.1 Public song listing page

**PRD ref**: §6.6

**What exists**: Admin `ListSongs` Livewire component at `/admin/services/songs` with usage counts derived from `church_service_items`. No public page.

**Work**:
- Route: `GET /church/worship-songs` behind `auth` middleware until public launch.
- New Livewire component: `PublicSongList` (or a public controller with a blade view).
- Query: songs ranked by usage count. A `ChurchServiceItem` song counts toward usage if either:
  - **Livestreamed service**: a `ServiceSection` of type `SONG` was detected in the livestream AND has a confident `song_id` match linking back to the same song — i.e. the song was actually heard. Detection-only (no `song_id` match) does not count. OoS-only (no detected section) does not count.
  - **Non-livestreamed service**: the `ChurchService` has no associated `MediaProcessingLog` (no livestream was ever processed), in which case the OoS `ChurchServiceItem` is trusted as-is. Evening services are not livestreamed; their song usage should still be counted.
- The simplest query approach: count `ChurchServiceItem` rows with `song_id` set, joined to `church_services`, where either (a) the `church_service` has a completed `media_processing_log` AND the item is linked to a detected `ServiceSection` of type SONG, or (b) the `church_service` has no `media_processing_log` at all.
- Display: title, authors, usage count, last sung date.
- Filters: "All time" and "This year" (date range on linked `ChurchService.date`).
- Songs with zero qualifying usage should not appear.
- Navigation link visible to authenticated users only until public launch.
- Use the `frontend-design` skill for UI.

**Note**: The `ServiceSection` → `ChurchServiceItem` linkage (`church_service_item_id` on `ServiceSection`) is the join point for the livestreamed-service case. For the non-livestreamed case, the absence of a `MediaProcessingLog` for the parent `ChurchService` is the signal to trust the OoS directly.

**Tests**: Livestreamed service — detected+matched song counted, OoS-only song not counted. Non-livestreamed service — OoS songs counted without requiring a detected section. Date filter works. Ranking correct. Guests redirected to login.

---

### 6.2 Song detail public page

**PRD ref**: §6.6 (implied)

**What exists**: Admin `ShowSong` component shows lyrics, authors, songbooks, and usage history.

**Work**:
- Route: `GET /church/worship-songs/{song:canonical_key}` (or by ID) behind `auth` middleware until public launch.
- Display: title, authors, lyrics (if available), usage history with dates.

**Tests**: Song detail renders. Lyrics displayed when available. Usage history correct. Guests redirected to login.

---

## Phase 7: Review Workflow (extends existing)

*Goal: All review items — low confidence sections, unmatched songs, OoS mismatches, pending publications — are visible in one admin view.*

### 7.1 Consolidated admin review dashboard

**PRD ref**: §6.7

**What exists**: `PrepareSectionPublicationCandidates` sets `publication_status = pending_approval`. Some admin views exist for church services. No consolidated review queue.

**Work**:
- New Livewire component: `ServiceReviewDashboard` (or extend existing admin service views).
- Query and display:
  - `ServiceSection` records with `needs_manual_review = true`.
  - `ServiceSection` records with `publication_status = pending_approval`.
  - `ChurchService` records with `needs_review = true`.
  - Unmatched song sections (type SONG, no song_id).
  - Low-confidence sections (confidence < 0.85).
- Group by service date for easy scanning.
- Actions per section:
  - Approve/reject publication.
  - Correct section type or title.
  - Play extracted audio/video preview.
  - Link to manual edit form for the canonical service list.
- Actions per service:
  - Mark as reviewed (clears `needs_review`).
  - Open full service edit.
- Route: `GET /admin/services/review`.

**Tests**: Items appear in review queue. Approve/reject updates status. Correcting type persists. Mark reviewed clears flag.

---

## Phase 8: Workflow Polish and Admin UX

*Goal: Reduce manual review time, expose the right evidence during review, and close the remaining children’s-talk workflow gap.*

### 8.1 Add evening-service OoS end-to-end regression coverage

**PRD ref**: §§5.4, 6.1, 6.3

**What exists**: `OosEmailParserService` already infers evening services from `PM`/`evening` hints, `ProcessInboundOosEmail` imports email-driven services, and `PublicSongUsageService` already trusts non-livestreamed services directly. What is missing is a single regression test proving the full evening-service path works from inbound email through song-usage counting.

**Work**:
- Add a feature test that processes an evening OoS email with song items and asserts the resulting `ChurchService.service` is `evening`.
- Assert the imported service remains valid for song usage without any associated `MediaProcessingLog`.
- Assert linked songs appear in the usage query/history exactly once and are not treated as livestream-dependent.
- Cover both explicit `evening` wording and `PM`/time-hint based detection paths where practical.

**Tests**: Evening OoS email imports as an evening service. Imported evening songs count in public/admin usage without a livestream. Morning/evening detection does not regress existing morning cases.

---

### 8.2 Add children’s-talk speaker detection with review/override

**PRD ref**: §§6.4, 11 edge case 5

**What exists**: Children’s talks already publish as `Sermon` records with `content_type = childrens_talk`, and sermon speaker identification already exists for the main sermon pipeline. But that identification path is tied to the sermon record created from the full sermon flow, not the extracted children's-talk section. There is no automatic speaker detection for children's talks, and no review UI to handle low-confidence or incorrect detected speakers before publication.

**Work**:
- Store predicted and reviewed children’s-talk speaker data on `ServiceSection.metadata` (the audio comes from the section, making it the natural owner). The reviewed result carries through to the published `Sermon`’s preacher fields via `SermonCreationOptions`.
- Add automatic speaker identification for `childrens_talk` sections using the extracted section audio and the existing speaker-profile/preacher matching infrastructure.
- Record detection metadata suitable for review:
  - matched `preacher_id`,
  - matched name,
  - confidence,
  - source,
  - and no-match/ambiguous outcome details.
- Extend the review dashboard so `childrens_talk` sections show the detected speaker and:
  - auto-clear high-confidence matches without requiring an extra confirmation step,
  - flag low-confidence or ambiguous matches for review,
  - allow admins to choose a different existing `Preacher`,
  - or enter a free-text fallback speaker name.
- Carry the reviewed speaker through `PublishApprovedServiceSection`, `SermonCreationOptions`, and `SermonCreationService` so published children’s talks use the confirmed speaker immediately.
- Ensure low-confidence or ambiguous results stay reviewable rather than silently falling back to the sermon preacher.
- Surface the final speaker consistently on Children’s Corner cards/detail pages and in admin publication context.

**Tests**: A confident speaker match is accepted automatically for children’s talks. Low-confidence or ambiguous results remain flagged for review. Admin override beats the automatic suggestion during publication. Existing sermon-speaker identification behavior remains unchanged for normal sermons.

---

### 8.3 Add safe batch actions to the review dashboard

**PRD ref**: §6.7

**What exists**: `ServiceReviewDashboard` supports only per-section actions (`approve`, `reject`, `requeue`, `saveSection`) and a per-service `markServiceReviewed`. This is workable but slow for clean services with multiple pending approvals.

**Work**:
- Add a service-level batch action: `Approve all pending publications for this service`.
- Limit the first implementation to safe, deterministic bulk actions only:
  - no bulk type/title mutation,
  - no bulk approval when required extracted assets are missing,
  - no silent clearing of manual-review flags unrelated to publication.
- Queue publication jobs for each newly approved section and return one concise success/failure summary to the admin.
- Record batch approval metadata so the action is auditable.
- Optionally follow with a second batch action later, such as resolving all review-only flags that already match canonical data.

**Tests**: Approve-all only affects sections belonging to the selected service. Each eligible section moves to `approved` and queues a publish job. Ineligible sections remain unchanged and are reported clearly.

---

### 8.4 Show the original inbound email alongside parsed review data

**PRD ref**: §6.1 Source 1

**What exists**: `InboundEmail` stores `body_plain` and `body_html`, but the admin review screen only shows the parsed preview and parser warnings. Admins cannot inspect the original email body when debugging parser failures or ambiguous imports.

**Work**:
- Add an expandable “Original email” panel to the inbound-email review UI.
- Show:
  - plain text body,
  - sanitized HTML rendering,
  - and raw parser metadata/warnings in a developer-friendly format.
- Keep the raw-body view read-only and collapsed by default to preserve scanning speed.
- Ensure HTML output is sanitized before rendering in admin.

**Tests**: Plain-text emails render correctly. HTML emails render a sanitized preview. Missing body variants degrade gracefully. Unsafe markup is not executed.

---

### 8.5 Allow admins to re-run the inbound email parser against stored emails

**PRD ref**: §6.1 Source 1

**What exists**: Raw inbound email data is stored durably, but once an email is pending/failed, admins cannot re-run parsing with improved prompts or parser logic without resending the email.

**Work**:
- Add a “Re-parse email” action to the inbound email review screen.
- Re-run `OosEmailParserService` against the stored inbound email body and replace the cached parse payload in `processing_metadata.parsing`.
- Refresh the preview, warnings, and import eligibility state after the re-parse.
- Keep the operation idempotent and non-destructive:
  - do not import automatically unless the admin explicitly approves afterwards,
  - keep prior failure/review metadata if still relevant.
- Record a re-parse timestamp on `processing_metadata` so that 10.2 (version metadata) can later backfill model/prompt version information for re-parsed emails.

**Tests**: Re-parse updates stored parsing metadata. Improved parse results become visible immediately in review. Failed/pending emails can be retried without duplication or accidental import. Re-parse timestamp is recorded.

---

### 8.6 Make children’s-talk routing, API, and admin surfaces content-type-aware

**PRD ref**: §§6.4, 6.7

**What exists**: Children’s talks correctly share the `Sermon` model via `content_type = childrens_talk`, and dedicated Children’s Corner pages already exist. But some shared helpers and surfaces still behave as if every `Sermon` is an ordinary sermon: canonical URL helpers and sitemap tags still assume sermon URLs (children’s talks are currently emitted in the sitemap with `/christ/sermons/` URLs that 404 for unauthenticated users — this is a bug), `/api/sermons` is sermon-only in index but the show endpoint still returns children’s talks, and shared admin listing/edit surfaces still use sermon-specific copy and actions. The current Children’s Corner auth gate is deliberate as a release toggle, but that toggle is not yet applied consistently across these other surfaces.

**Work**:
- Keep the shared `Sermon` model; do not introduce a separate `ChildrensTalk` model.
- Add a single content-type-aware exposure policy for children’s talks and apply it consistently to:
  - Children’s Corner routes,
  - generic sermon routes,
  - canonical URL helpers,
  - sitemap generation,
  - and any public API exposure.
- Ensure generic sermon routes do not render `childrens_talk` records as ordinary sermons:
  - when Children’s Corner is not released publicly, children’s talks remain non-public,
  - when released, children’s talks use the Children’s Corner route as canonical and sermon routes redirect or reject them.
- Make sitemap output and shared URL helpers branch on `content_type` so children’s talks do not emit sermon URLs.
- Make `/api/sermons` consistently sermon-only for both list and detail endpoints. `/api/sermons/{id}` must return 404 for children’s talks.
- If public API access to children’s talks is needed later, add a dedicated endpoint instead of mixing them silently into sermon responses.
- Add explicit `content_type` awareness where shared resources are reused outside sermon-only contexts.
- Update the admin listing so it:
  - shows a content-type badge,
  - uses type-aware view links (children’s talks link to the Children’s Corner show route, not the sermon show route),
  - and no longer presents every row as an ordinary sermon.
- Update the shared edit surface so `childrens_talk` records get type-aware headings, help text, and field visibility:
  - sermon-only fields such as preached-passage-oriented reference and AI sermon points should be hidden or clearly de-emphasized unless intentionally used.

**Tests**: Children’s talks do not leak through sermon routes when the release gate is off. Children’s talks are excluded from sitemap while auth gate is active. Canonical URLs and sitemap entries use Children’s Corner URLs when publicly released. `/api/sermons/{id}` returns 404 for children’s talks. Admin listing view links route to Children’s Corner for children’s talks. Edit UI labels and visible fields adapt for children’s talks.

---

## Phase 9: Observability and Service Health

*Goal: Make the multi-job pipeline inspectable and measurable so admin debugging and ongoing tuning are practical.*

### 9.1 Add a per-run processing timeline view on the service detail page

**PRD ref**: Post-PRD operational follow-up to §§6.2, 6.3, 6.7

**What exists**: `ShowChurchService` already lists related livestream runs and their `ServiceSection` records. `SermonProcessingStep` already stores per-step timestamps and status, but the church-service livestream chain does not surface those steps in admin and newer section-classification jobs do not consistently write step rows yet.

**Work**:
- Instrument the church-service-specific livestream jobs with explicit step logging:
  - `ClassifyServiceSections`,
  - `TranscribeSpeechSegments`,
  - `ClassifySpeechSections`,
  - `AlignWithOos`,
  - `ExtractSermon`,
  - `PrepareSectionPublicationCandidates`.
- Add a service-page timeline component showing:
  - ordered step name,
  - status,
  - start/completion times,
  - duration,
  - and error message where relevant.
- Distinguish currently running, skipped, failed, and completed steps clearly.
- Keep it scoped to existing runs first; aggregate dashboards can build on the same data later.

**Tests**: Timeline steps render in chronological order. Completed steps show durations. Failed steps expose their message. Uninstrumented/absent steps degrade gracefully.

---

### 9.2 Add a planned-vs-actual comparison view for each service

**PRD ref**: §§5.1, 5.2, 6.7

**What exists**: Admin can see canonical OoS items on the service page and detected sections on the same page, but only as separate tables. There is no dedicated diff view that makes mismatches obvious at a glance.

**Work**:
- Build a comparison presenter/service that aligns:
  - canonical `ChurchServiceItem` entries,
  - detected `ServiceSection` records,
  - and OoS/song-match metadata.
- Surface side-by-side comparison with explicit states such as:
  - matched,
  - missing from livestream,
  - extra detected section,
  - mismatched type,
  - inferred song label,
  - unmatched song.
- Link this view from both the service detail page and the review dashboard.
- Keep source-precedence semantics visible: the livestream remains the record of what happened; the canonical list remains the reviewed plan/final list.

**Tests**: Matching items render as matched. Missing/extra/mismatched cases show the correct state. Song match-type badges are correct for confirmed vs inferred vs unmatched matches.

---

### 9.3 Add confidence and review trend reporting

**PRD ref**: §7

**What exists**: Numeric confidence is stored on `ServiceSection`, review flags are already generated, and the review dashboard exposes current queue counts. There is no aggregate reporting over time.

**Work**:
- Create an aggregate reporting service that groups service/section review data by week.
- Initial metrics should include:
  - low-confidence section rate,
  - unmatched-song rate,
  - services-needing-review rate,
  - pending children’s-talk publication count/rate,
  - and auto-clear vs manual-review proportions.
- Add a small admin reporting surface using server-rendered Blade tables with inline sparklines or simple bar charts (Livewire component, no JS charting library required for v1). Avoid over-building BI infrastructure.
- Make date range and service-type filters available once the base queries are stable.

**Tests**: Weekly aggregates are correct across date boundaries. Empty weeks do not break the report. Service-type filtering and count denominators are correct.

---

## Phase 10: Feedback Loops and Controlled Reprocessing

*Goal: Turn admin corrections into durable quality improvements without creating unsafe automatic behavior.*

### 10.1 Add a reviewed song-title alias feedback loop

**PRD ref**: Post-PRD follow-up to §§5.4, 6.7

**What exists**: Admins can manually correct unmatched or inferred song links in the review flow, but those corrections only fix the current item. There is no durable alias store that improves future automatic matching.

**Work**:
- Add a small reviewed alias model/table, for example `song_title_aliases`, linked to `Song`.
- Capture aliases only from explicit admin confirmation, not from every inferred match.
- Store provenance fields such as:
  - source title,
  - normalized title,
  - approved by,
  - approved at,
  - originating service/item if useful for audit.
- Teach the song-linking/matching path to consult approved aliases before falling back to weaker normalization.
- Expose aliases in admin song detail for maintenance.

**Tests**: Approved aliases improve future matching. Unapproved/manual one-off corrections do not create aliases. Alias collisions/conflicts are handled safely.

---

### 10.2 Record parser/classifier version metadata and support targeted reprocessing

**PRD ref**: Post-PRD operational follow-up

**What exists**: The system stores some parsing/classification metadata, but not a clear, durable record of the model/prompt version used for email parsing and speech classification. Reclassification exists per service, but there is no audited bulk workflow tied to version changes.

**Work**:
- Record model/version/prompt-hash metadata for:
  - inbound email parsing,
  - speech-section classification,
  - and OoS alignment decisions where practical.
- Surface that metadata on admin service/inbound-email screens.
- Add targeted reprocessing tools for admins, beginning with:
  - reclassify a service,
  - then optionally reclassify a bounded date range filtered by old version metadata.
- Keep reprocessing explicit and auditable; do not auto-migrate historical decisions silently.

**Tests**: New runs persist version metadata. Service-level reclassification preserves auditability. Date-range filtering for targeted reprocessing selects the expected runs only.

---

## Phase 11: Notices

*Goal: Move from “notice sections can be detected” to a reviewable, subscribable notices workflow in two safe phases.*

### 11.1 Build an editorial notice-review surface

**PRD ref**: §6.8

**What exists**: The classification pipeline already detects `NOTICES` sections and can transcribe non-song/non-sermon speech sections into `ServiceSection.metadata`. There is no admin experience dedicated to reviewing, editing, or curating notices.

**Work**:
- Decide whether v1 of notices should remain section-backed or introduce a lightweight first-class notice projection.
- Add an admin view listing notice sections with:
  - transcript,
  - extracted clip preview where available,
  - service/date context,
  - and review state.
- Allow admins to edit the transcript and mark it as reviewed/ready for digest use.
- Keep the initial scope editorial only:
  - no subscription model yet,
  - no public/member delivery yet.

**Tests**: Notice sections appear in the review surface. Transcript edits persist. Reviewed notices can be filtered separately from raw detected notices.

---

### 11.2 Add subscriber management and weekly notice digests

**PRD ref**: §6.8

**What exists**: No subscriber model, no opt-in flow, and no notice-delivery pipeline exists yet.

**Work**:
- Add subscriber and delivery-tracking models/tables (`notice_subscribers`, `notice_digest_deliveries`).
- Add opt-in/opt-out flows:
  - Authenticated members: toggle in account settings or members area.
  - Non-members: public subscribe form with email confirmation (double opt-in).
  - All subscribers: signed unsubscribe link in every digest email.
- Create a weekly digest assembly job that selects reviewed notices only.
- Add delivery logging and idempotency so the same weekly digest is not sent twice to the same subscriber accidentally.
- Add a basic admin preview of the outgoing digest email before send.

**Tests**: Subscribers can opt in and unsubscribe. Weekly digest includes only reviewed notices in the correct window. Duplicate delivery is prevented. Digest preview renders with empty and populated states.

---

## Summary: Dependency Order

```
Phase 1 (Foundation)
  1.1 church_service_id FK
  1.2 Classifier without OoS ─────────────────────────┐
  1.3 Sermon detection confidence                      │
  1.4 Re-alignment on late OoS (depends on 1.1)       │
  1.5 Source-aware merge                               │
  1.6 Evidence-aware precedence + review reopening    │
  1.7 Canonical-list-changed event                    │
                                                       │
Phase 2 (OoS Ingestion) ──── can start in parallel ───┤
  2.1 Mailgun webhook                                  │
  2.2 Email parsing (depends on 2.1)                   │
  2.3 Manual admin form                                │
  2.4 Email review UI (depends on 2.1)                 │
  2.5 Shared OpenLP import service                     │
                                                       │
Phase 3 (Classification Rework) ── depends on 1.2 ────┘
  3.1 Speech segment transcription
  3.2 AI classification + splitting (depends on 3.1)
  3.3 OoS alignment pass (depends on 3.2)
  3.4 Confidence scoring (depends on 3.2, 3.3)
  3.5 Wire pipeline (depends on all of Phase 3)
  3.6 Confirmed vs inferred song match state

Phase 4 (Children's Talks) ── independent, can start after Phase 1
  4.1 content_type column
  4.2 Exclude from listings (depends on 4.1)
  4.3 Children's Corner pages (depends on 4.1)

Phase 5 (Sermon Display) ── depends on Phase 3 (needs section linkage)
  5.1 Service reading reference

Phase 6 (Public Songs) ── depends on Phase 3 (needs detected-song counting)
  6.1 Public song listing
  6.2 Song detail page (optional)

Phase 7 (Review) ── depends on Phase 3 (needs confidence data)
  7.1 Consolidated review dashboard

Phase 8 (Workflow Polish) ── builds on existing review and ingestion flows
  8.1 Evening-service OoS regression (depends on Phases 2, 6)
  8.2 Children's-talk speaker detection + override (depends on Phases 4, 7)
  8.3 Batch review actions (depends on Phase 7)
  8.4 Original inbound email view (depends on Phase 2)
  8.5 Re-run inbound parser (depends on Phase 2)
  8.6 Content-type-aware children’s-talk routing/API/admin surfaces (depends on Phase 4)

Phase 9 (Observability) ── depends on Phase 3 data and job instrumentation
  9.1 Processing timeline
  9.2 Planned vs actual diff (depends on 3.3, 7.1)
  9.3 Confidence/review trends (depends on 3.4, 7.1)

Phase 10 (Feedback Loops) ── depends on reviewed admin corrections existing
  10.1 Song alias feedback loop (depends on 6.x, 7.1)
  10.2 Version metadata + targeted reprocessing (depends on 9.1)

Phase 11 (Notices) ── depends on Phase 3 non-sermon section detection
  11.1 Editorial notice review surface
  11.2 Subscribers + weekly digests (depends on 11.1)
```

**Phases 1 and 2 can run in parallel.** Phase 4 can also start independently once 1.2 is done (so the classifier produces children's talk sections). Phases 5, 6, and 7 all depend on Phase 3 being complete.

**Suggested next delivery wave**: all of Phase 8 (8.1–8.6). Item 8.5 pairs naturally with 8.4 (both are inbound-email review improvements) and is small enough to include without risk. That wave gives the best mix of safety, admin time savings, workflow completeness, and content-type consistency before moving into broader observability and notices work.
