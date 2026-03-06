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

## Phase 3: Classification Rework

*Goal: Speech blocks are decomposed into individual sections using transcription and AI, not just OoS alignment.*

### 3.1 Targeted speech segment transcription

**PRD ref**: §6.2 Pipeline Step 3

**What exists**: `TranscribeAudio` job transcribes the sermon audio. `AudioTranscriptionService` supports chunking. No mechanism to transcribe arbitrary speech segments.

**Work**:
- New job: `TranscribeSpeechSegments`.
- For each non-sermon `ServiceSection` with type `OTHER` or `SONG` (misclassified) or any speech segment not yet transcribed:
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
- Route: `GET /christ/childrens-corner` → listing page.
- Route: `GET /christ/childrens-corner/{sermon:slug}` → detail page.
- Controller or Livewire component querying `Sermon::whereChildrensTalk()`.
- Listing view: title, date, speaker (if known), media availability indicators.
- Detail view: simplified — title, date, speaker, video/audio player. No AI summary or key points by default.
- Navigation: add "Children's Corner" link to the Christ section navigation.
- Use the `frontend-design` skill for all UI work.

**Tests**: Listing shows only children's talks. Detail page renders. Sermon-type records not shown. Navigation link present.

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

### 5.2 Show service context on sermon page

**PRD ref**: §6.5

**What exists**: Sermon pages show date and preacher. No service slot or service linkage displayed.

**Work**:
- If the sermon has a linked `ChurchService` (via processing log or service section):
  - Show "Morning Service, 2 March 2026" or equivalent.
- Keep this lightweight — a single line of context, not a full service breakdown.

**Tests**: Linked service → context shown. No linked service → no context shown.

---

## Phase 6: Public Song Usage

*Goal: Public page showing most-sung worship songs based on actual detected singing.*

### 6.1 Public song listing page

**PRD ref**: §6.6

**What exists**: Admin `ListSongs` Livewire component at `/admin/services/songs` with usage counts derived from `church_service_items`. No public page.

**Work**:
- Route: `GET /church/worship-songs`.
- New Livewire component: `PublicSongList` (or a public controller with a blade view).
- Query: songs ranked by usage count, but only counting usage where:
  - A `ServiceSection` of type `SONG` was detected in a livestream.
  - The section has a confident `song_id` match.
  - This means querying through `ServiceSection` → `song_id` (or `ChurchServiceItem` → `song_id` where the item is linked to a detected section), not just `ChurchServiceItem` alone.
- Display: title, authors, usage count, last sung date.
- Filters: "All time" and "This year" (date range on linked `ChurchService.date`).
- Songs with zero detected-and-matched usage should not appear.
- Use the `frontend-design` skill for UI.

**Note**: This may require adding `song_id` to `ServiceSection` or querying through the `ChurchServiceItem` linkage. Evaluate which is simpler during implementation.

**Tests**: Only detected+matched songs shown. OoS-only songs excluded. Date filter works. Ranking correct.

---

### 6.2 Song detail public page (optional)

**PRD ref**: §6.6 (implied)

**What exists**: Admin `ShowSong` component shows lyrics, authors, songbooks, and usage history.

**Work**:
- Route: `GET /church/worship-songs/{song:canonical_key}` (or by ID).
- Display: title, authors, lyrics (if available), usage history with dates.
- Keep it simple — this is a nice-to-have extension of the listing.

**Tests**: Song detail renders. Lyrics displayed when available. Usage history correct.

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

## Summary: Dependency Order

```
Phase 1 (Foundation)
  1.1 church_service_id FK
  1.2 Classifier without OoS ─────────────────────────┐
  1.3 Sermon detection confidence                      │
  1.4 Re-alignment on late OoS (depends on 1.1)       │
  1.5 Source-aware merge                               │
                                                       │
Phase 2 (OoS Ingestion) ──── can start in parallel ───┤
  2.1 Mailgun webhook                                  │
  2.2 Email parsing (depends on 2.1)                   │
  2.3 Manual admin form                                │
  2.4 Email review UI (depends on 2.1)                 │
                                                       │
Phase 3 (Classification Rework) ── depends on 1.2 ────┘
  3.1 Speech segment transcription
  3.2 AI classification + splitting (depends on 3.1)
  3.3 OoS alignment pass (depends on 3.2)
  3.4 Confidence scoring (depends on 3.2, 3.3)
  3.5 Wire pipeline (depends on all of Phase 3)

Phase 4 (Children's Talks) ── independent, can start after Phase 1
  4.1 content_type column
  4.2 Exclude from listings (depends on 4.1)
  4.3 Children's Corner pages (depends on 4.1)

Phase 5 (Sermon Display) ── depends on Phase 3 (needs section linkage)
  5.1 Service reading reference
  5.2 Service context

Phase 6 (Public Songs) ── depends on Phase 3 (needs detected-song counting)
  6.1 Public song listing
  6.2 Song detail page (optional)

Phase 7 (Review) ── depends on Phase 3 (needs confidence data)
  7.1 Consolidated review dashboard
```

**Phases 1 and 2 can run in parallel.** Phase 4 can also start independently once 1.2 is done (so the classifier produces children's talk sections). Phases 5, 6, and 7 all depend on Phase 3 being complete.
