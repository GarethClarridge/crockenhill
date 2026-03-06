# PRD: Church Service Processing & Content Management

**Date**: March 2026
**Status**: Draft
**Author**: Generated from codebase analysis + stakeholder input

---

## 1. Vision

Transform raw church service recordings into structured, browsable content. A single livestream upload — combined with an order of service — should automatically produce individually addressable items: a sermon page with audio/video/transcript and bible reference, a children's talk in its own section, song usage statistics on a public page, and (in future) transcribed notices emailed to subscribers.

---

## 2. User Stories

### Worship Coordinator (Admin)
1. **Upload order of service** before or after the service, via OpenLP export or manual web form.
2. **Upload livestream recording** and have it automatically segmented against the order of service.
3. **Review extracted segments** — approve children's talks for publication, verify sermon boundaries, check song linkage.
4. **See song usage statistics** — which songs are sung most, when each was last used.

### Website Visitor (Public)
5. **Browse sermons** with audio, video, transcript, summary, key points, and associated bible reading reference.
6. **Browse children's talks** in a dedicated "Children's Corner" section, separate from sermons.
7. **See most popular worship songs** on a public page showing usage frequency.

### Church Member (Authenticated)
8. *(Future)* **Subscribe to notice emails** — receive transcribed weekly notices after each service.

---

## 3. Current State Assessment

### What Exists (Built & Working)

| Capability | Status | Key Files |
|---|---|---|
| Livestream upload & RMS segmentation | Complete | `VideoSegmentationService`, `RmsAnalysisService`, `AnalyzeSegments` job |
| Visual song detection | Complete | `VisualAnalysisService`, `SongClusteringService` |
| Sermon extraction from livestream | Complete | `ExtractSermon` job, `VideoExtractionService` |
| Audio transcription (Whisper) | Complete | `AudioTranscriptionService`, `TranscribeAudio` job |
| AI sermon analysis (title, summary, points, reference) | Complete | `SermonAnalysisService`, `ProcessTranscriptWithAI` job |
| Speaker identification | Complete | `ResemblyzerSpeakerIdentificationService` |
| Thumbnail generation | Complete | `ThumbnailGenerationService` |
| OpenLP order-of-service import (.osz) | Complete | `OpenLpServiceParser`, `ChurchServiceController` |
| ChurchService + ChurchServiceItem models | Complete | Models, migrations, relationships |
| ServiceSection classification (heuristic + OoS alignment) | Complete | `ServiceSectionClassifier`, `ClassifyServiceSections` job |
| Children's talk extraction & approval workflow | Complete | `PrepareSectionPublicationCandidates`, `PublishApprovedServiceSection` jobs |
| Song catalog (OpenLP SQLite import) | Complete | `SongCatalogSyncService`, `Song` model |
| Song ↔ service item linking | Complete | `ChurchServiceSongLinker` |
| Song admin UI (list + detail with usage stats) | Complete | `ListSongs`, `ShowSong` Livewire components |
| Public sermon pages (audio, video, transcript) | Complete | Sermon views, `SermonController` |
| Podcast feed | Complete | `PodcastController` |

### What Exists but Needs Extension

| Capability | Current State | Gap |
|---|---|---|
| Order of service input | OpenLP .osz only | Need manual web form for admin entry |
| Song usage page | Admin-only UI | Need public-facing page |
| Children's talks display | Published as `Sermon` records with `SermonService::Other` | Need dedicated "Children's Corner" section, not mixed with sermons |
| Bible reading on sermon page | Reference extracted by AI analysis, stored as `Sermon.reference` | Display works but reference comes from AI, not explicitly from OoS bible reading item |
| Service section review | `ServiceSectionPublicationStatus` enum exists | Admin UI for reviewing/approving segments may be incomplete |

### What's Missing (Not Yet Built)

| Capability | Notes |
|---|---|
| Manual order-of-service web form | Admin form to type in running order (type + title per item) |
| Public song usage page | Public route showing most-sung songs with frequency |
| Dedicated children's talk section | Separate public listing/display distinct from sermons |
| Children's talk content type | Currently reuses `Sermon` model — may need its own model or a clear content-type distinction |
| Bible reading reference from OoS | Explicit linkage: OoS bible reading item → sermon record's reference field |
| Notice transcription | Extract notice segment audio → transcribe → store/display |
| Notice email subscription | Subscriber management + automated email delivery of transcribed notices |
| Prayer metadata recording | Log prayer occurrence in service timeline (metadata only) |

---

## 4. Requirements by Domain

### 4.1 Order of Service Ingestion

**Current**: OpenLP .osz file upload via API, parsed synchronously.

**Required additions**:

- **Email ingestion (primary)**: The OoS is currently emailed as structured text in the email body to specific people. Set up a dedicated inbox (e.g., `oos@crockenhill.org`) that the sender CCs. The system polls or receives via webhook, parses the structured text, and creates/updates the `ChurchService` + `ChurchServiceItem` records.
  - **Parsing**: AI-assisted extraction of item types and titles from the email body text. The format is semi-structured (likely a list) but not machine-formatted, so an LLM call to extract `[{type, title}]` is more robust than regex.
  - **Date extraction**: From email subject line or body; confirm against existing services.
  - **Confidence**: If parsing is ambiguous, create the service with `needs_review = true` for admin confirmation.
  - **Deduplication**: If a service already exists for that date+type (e.g., from OpenLP upload), merge items rather than creating a duplicate.

- **Manual web form** (admin only, fallback): Select date + service type, then add ordered items with type (dropdown from `ServiceSectionType`) and title (free text). Songs should offer autocomplete against the song catalog. Useful when the email isn't sent or for corrections.

- **OpenLP import** (keep): Existing .osz upload remains as an additional source. Particularly valuable for song metadata (lyrics, CCLI numbers) that the email won't contain.

- **Validation**: Date + service type must be unique. Items must have a type. Songs should ideally match a catalog entry (warn if not).
- **Edit capability**: Admin can edit/reorder items after creation (currently deferred to Phase 2 in existing plan).

### 4.2 Livestream Processing & Segmentation

**Current**: Fully functional pipeline: RMS → visual analysis → segment classification → sermon extraction → transcription → AI analysis.

**Required additions**:
- **Bible reading → sermon linkage**: When classifying service sections, if a `BibleReading` section is identified from the OoS, its title (e.g., "John 3:16-21") should be explicitly written to the sermon's `reference` field, rather than relying solely on AI extraction from the transcript. AI-extracted reference remains as fallback.
- **Song sections → catalog linkage**: When a `Song` service section is classified and matched to an OoS item that has a `song_id`, the service section should carry that `song_id` through. This ensures song usage counting captures livestream-processed services.

### 4.3 Children's Talks — Dedicated Section

**Current**: Children's talks are extracted from livestreams and published as `Sermon` records with `source_type = livestream`.

**Required changes**:
- **Distinct content type**: Children's talks need to be clearly distinguishable from sermons. Options:
  - **(A) New model** (`ChildrensTalk`) — cleanest separation but duplicates media handling.
  - **(B) Enum value on Sermon** — add `SermonContentType` enum (sermon, childrens_talk) — minimal schema change, leverages existing infrastructure.
  - **(C) Tag/category system** — more flexible but over-engineered for two types.
  - **Recommendation**: Option B. Add a `content_type` column to `sermons` table with enum values `sermon` and `childrens_talk`. Filter by content type in queries.
- **Dedicated public route**: `/christ/childrens-corner` or similar, listing only children's talks.
- **Dedicated blade view**: Simplified display (no "key points" or "summary" — just title, date, speaker, video/audio).
- **Excluded from sermon listings**: Children's talks should not appear in the main sermons index or podcast feed.

### 4.4 Song Usage — Public Page

**Current**: Admin UI shows most-used songs with usage count, service count, and last-used date.

**Required additions**:
- **Public route**: `/church/worship-songs` or similar.
- **Display**: Ranked list of most-sung songs. Show title, authors, usage count, last sung date. Optionally show lyrics.
- **Filtering**: By date range (e.g., "this year", "all time"). Possibly by service type.
- **No CRUD**: Public page is read-only. Song management stays admin-only via OpenLP.

### 4.5 Sermon Display Enhancements

**Current**: Sermon pages show audio player, video player, transcript, AI-generated summary and key points.

**Required additions**:
- **Bible reading reference display**: Show the associated bible reading prominently (e.g., "Reading: John 3:16-21") sourced from the OoS item, with AI-extracted reference as fallback.
- **Reference as link**: Display as a link to BibleGateway or similar (reference text only, no full scripture fetch).

### 4.6 Notice Transcription & Email (Future Phase)

**Deferred** — documented here for completeness and to inform architecture decisions.

- **Extract notice audio** from livestream using existing segment extraction.
- **Transcribe** using existing Whisper integration.
- **Store** transcribed notice text on the `ServiceSection` record.
- **Display** on site (possibly on service detail page or standalone notices page).
- **Email subscription**: Subscriber model, opt-in form, automated email after each service's notices are transcribed.
- **Architecture note**: The `ServiceSection` model already supports this — `extracted_audio_path` and `metadata` fields exist. The main work is: (1) configuring notice sections for extraction, (2) running transcription on extracted audio, (3) building the subscriber/email system.

---

## 5. What I Think Is Missing

### 5.1 Critical: Speech Segment Boundary Detection

**This is the most significant architectural gap.** The classification pipeline cannot reliably identify individual speech items (prayer, children's talk, bible reading, notices) within a continuous speech block.

**Root cause — OpenLP doesn't export speech items.** OpenLP exports songs, bible readings, and custom slides. Prayers, welcomes, notices, and children's talks are not OpenLP plugin items — they happen live without slides. The OoS from OpenLP therefore has structural holes:

```
Actual service:      Welcome → Song → Prayer → Children's Talk → Song → Bible Reading → Sermon → Song
OpenLP exports:               Song →                             Song → Bible Reading                → Song
RMS detects:         [speech₁] [song₁]  [-----speech₂-----]     [song₂] [-----speech₃-----]        [song₃]
```

- `speech₂` contains prayer + children's talk merged (no gap between speakers)
- `speech₃` contains bible reading + sermon merged
- The classifier only knows about songs and the bible reading from OpenLP
- `speech₂` gets **no classification** — it's orphaned
- `speech₃` gets matched to "bible reading" but actually contains both bible reading and sermon

**The planned solution** (`TranscribeSplitCandidates` + `AiClassifySections` jobs from the service-sections plan Phase 5) **was never implemented.** Split candidates are never detected because the OoS doesn't tell the system "there should be multiple items here."

**Proposed approach**: The livestream audio is ground truth; the OoS (from any source) is "what was planned" and may differ from what actually happened. The classification pipeline should be:

```
1. Audio analysis (RMS/visual)     → structural skeleton: songs, speech blocks, silence
2. Sermon identification           → longest speech segment ≥ 20 min (flag if none)
3. Speech segment transcription    → targeted Whisper on each non-sermon speech block
4. AI classification of transcripts → split & label each speech block using boundary
                                      phrases ("Let us pray", "Good morning children",
                                      "Our reading today is from...")
5. OoS alignment (if available)    → confirm/enrich labels, flag mismatches, add song
                                      titles and bible references
```

**Key principles**:
- **Audio is authoritative for structure.** If the OoS says "Song → Prayer → Song" but audio shows two speech segments between the songs, believe the audio — there were two speech items.
- **OoS is a labelling aid, not a structural template.** It suggests what each speech block probably contains and what the songs are called, but never overrides audio evidence.
- **Discrepancies lower confidence, not override.** When OoS and audio disagree, flag for review with reduced confidence score rather than silently picking one.
- **Works without OoS.** The pipeline should produce useful results (sermon extracted, other segments labelled by AI) even with no OoS at all. OoS just makes it better.

This inverts the current design: instead of OoS-first classification with AI as fallback, use **audio analysis + AI transcript classification as primary, OoS as validation/enrichment.**

### 5.2 Temporal Ordering: Multiple Inputs Arriving at Different Times

Information about a single service arrives in stages:

```
Thursday/Friday:  OoS email arrives          → ChurchService + items created
Sunday pre-service: OpenLP file uploaded     → enriches items with song metadata (lyrics, CCLI)
Sunday post-service: Livestream uploaded     → segmentation + classification runs
```

But the order isn't guaranteed. The livestream might be uploaded before the OoS, or the OpenLP file might arrive days later. Each input should incrementally build the picture without blocking on the others.

**Current problem**: There is no FK or stored link between `MediaProcessingLog` and `ChurchService`. The `ServiceSectionClassifier` resolves the connection at runtime via date+service type lookup. If the livestream is processed before the OoS exists, the classifier finds no matching `ChurchService` and either skips classification entirely or produces empty sections. There's no trigger to re-run when the OoS arrives later.

**Required behaviour — event-driven incremental assembly**:

| Event | What should happen |
|---|---|
| **OoS arrives (email/manual/OpenLP), no livestream yet** | Create/update `ChurchService` + items. Link songs to catalog. Record is ready for whenever the livestream arrives. |
| **Livestream arrives, OoS already exists** | Process normally: audio analysis → sermon extraction → transcription → AI classification of speech segments → OoS alignment pass enriches labels. |
| **Livestream arrives, no OoS yet** | Process with degraded enrichment: audio analysis → sermon extraction → transcription → AI classification labels speech segments without OoS confirmation. Song titles and bible references come from AI only (lower confidence). |
| **OoS arrives after livestream already processed** | Trigger a re-classification pass: run the OoS alignment step (Phase 4, Step 5) against existing service sections. Update song titles, bible references, and confidence scores. Flag any new mismatches for review. |
| **OpenLP arrives after email OoS** | Merge into existing `ChurchService`: match songs by position/title, enrich with lyrics/CCLI metadata, link to song catalog. Don't overwrite speech items (prayers, notices) that the email provided but OpenLP doesn't have. |
| **OpenLP arrives after livestream processed** | Enrich existing service items with song metadata. If classification already ran, update song section titles with catalog matches. |

**Implementation notes**:
- Add an optional `church_service_id` FK on `media_processing_logs` (nullable, set when resolved) as a cache to avoid repeated date+service lookups.
- When a `ChurchService` is created or updated, check if any `MediaProcessingLog` exists for the same date+service. If one exists with completed processing, dispatch a lightweight re-classification job.
- The `ChurchServiceItemSyncService` already handles merge/update for incoming items. Extend it to handle source-aware merging: OpenLP items should enrich (add song metadata) rather than overwrite email-sourced items that have richer type information.

### 5.3 Other Gaps in Existing Architecture

1. **No `content_type` distinction on Sermon model.** Children's talks are published as sermons with no distinguishing field. The `SermonService` enum (morning/evening/other) is used but `other` is too vague — it could mean a guest speaker, a special event sermon, or a children's talk. This is the most critical gap for the children's corner feature.

2. **No manual OoS entry form.** The system is entirely dependent on OpenLP exports. If the worship coordinator doesn't use OpenLP (or forgets to export), there's no way to input the service order. This breaks the entire classification pipeline since OoS is required for segment identification.

3. **No public song page.** Song data is rich (lyrics, authors, CCLI numbers, usage stats) but entirely locked behind admin UI. The public site has no way to surface this.

4. **Bible reading reference is AI-dependent.** The OoS knows exactly which passage was read (it's in the service item title), but this isn't explicitly passed to the sermon record. The AI must re-discover it from the transcript, which can fail or be imprecise.

5. **No explicit sermon ↔ service section linkage on display.** The `ServiceSection` knows which sermon it produced (`published_sermon_id`), but the sermon page doesn't show "this sermon was part of the morning service on 2 March 2026" with links to the other service components.

6. **Song usage counts may miss services without OoS.** If a service is recorded but no OoS is uploaded, the songs from that service won't appear in usage statistics. There's no retroactive linking mechanism triggered by a late OoS upload.

7. **No admin review dashboard for pending segments.** The `ServiceSectionPublicationStatus::PendingApproval` state exists, but it's unclear if there's a consolidated admin view showing all segments awaiting review across all recent services.

### 5.2 Functional Gaps

8. **No service-level "what happened" summary.** You chose "feed items only" (no full service page), which is fine, but there's no lightweight mechanism to see "on 2 March, the songs were X, Y, Z, the reading was John 3, and the sermon was about grace." This data exists in `ChurchService` + items but isn't surfaced anywhere public.

9. **No handling for services without livestream.** If a service happens but isn't livestreamed (equipment failure, midweek service), the OoS can still be uploaded but the song/service tracking benefits are limited to just recording what was planned.

10. **No duplicate service detection.** If someone uploads the same OoS twice (or uploads OpenLP then also enters manually), the unique constraint on date+service_type handles it, but the UX around "this service already exists, do you want to update it?" could be clearer.

11. **Preacher ↔ children's talk speaker.** When a children's talk is published, who gave it? The speaker identification system targets the sermon preacher. Children's talks may be given by a different person. Currently there's no mechanism to identify or assign the children's talk speaker.

### 5.3 Edge Cases to Consider

12. **Multiple sermons per service.** Rare but possible (e.g., a guest speaker gives a short word, then the regular preacher gives the main sermon). The current "longest speech segment = sermon" heuristic would miss the shorter one. **Proposed handling**: If no single sermon candidate exceeds 20 minutes, flag the service for manual review before automatically creating the sermon record. This avoids silently picking the wrong segment.

13. **Songs sung during the sermon.** Some preachers ask the congregation to sing a verse mid-sermon. This would be detected as a song segment, splitting the sermon. The visual/RMS analysis may handle this already (short song periods within a long speech segment), but it's worth verifying.

14. **Communion services.** The order of service for a communion service includes additional elements (communion hymn, words of institution, distribution) that don't map to current `ServiceSectionType` values. Currently these would fall into `Other`.

15. **Joint/special services.** Combined services (e.g., church anniversary, baptism service) may have a significantly different structure from the typical morning/evening pattern. The classification should degrade gracefully rather than force-fitting the wrong template.

---

## 6. Proposed Implementation Phases

### Phase 1: Content Type Distinction (Foundation)
- Add `content_type` enum column to `sermons` table (`sermon`, `childrens_talk`)
- Update `PublishApprovedServiceSection` to set `content_type = childrens_talk`
- Add scope `Sermon::whereChildrensTalk()` and `Sermon::whereSermon()`
- Filter existing sermon listings to exclude children's talks
- Exclude children's talks from podcast feed

### Phase 2: Children's Corner Public Section
- New route `/christ/childrens-corner`
- New controller/view for children's talk listing
- Simplified display template (no summary/points)
- Navigation integration

### Phase 3: OoS Email Ingestion
- Set up dedicated inbox (e.g., `oos@crockenhill.org`)
- Email polling/webhook receiver (Laravel Mailbox or similar)
- AI-assisted parsing of structured text body into `[{type, title}]` items
- Date extraction from subject/body
- Create `ChurchService` + `ChurchServiceItem` records
- Merge logic when service already exists (e.g., OpenLP uploaded first)
- Low-confidence parses flagged `needs_review` for admin confirmation
- **Key advantage over OpenLP**: Emails include prayers, notices, welcomes, children's talks — the full service structure

### Phase 3b: Manual Order of Service Form (Fallback)
- Livewire admin component for manual OoS entry
- Date + service type selection
- Ordered item list with type dropdown + title field (all `ServiceSectionType` values available)
- Song autocomplete against catalog
- Reorder/delete items
- Saves to existing `ChurchService` + `ChurchServiceItem` models
- Used when email isn't sent, or for corrections

### Phase 4: Speech Segment Decomposition (Core Classification Rework)
This is the critical phase that makes individual segment identification reliable. The livestream audio is ground truth; the OoS is treated as "what was planned" and may differ from what actually happened.

- **Step 1 — Audio skeleton** (existing, keep as-is): RMS/visual analysis produces structural skeleton of songs, speech blocks, and silence.
- **Step 2 — Sermon identification** (existing, keep as-is): Longest speech segment ≥ 20 min. If none, flag entire service for manual review.
- **Step 3 — Implement `TranscribeSplitCandidates` job**: For each non-sermon speech segment, extract audio and run targeted Whisper transcription. This is cheaper than transcribing the full service.
- **Step 4 — Implement `AiClassifySections` job**: Send each transcript to AI with instructions to identify boundary phrases ("Let us pray", "Good morning children", "Our reading today is from...") and return split timestamps + section type labels.
- **Step 5 — OoS alignment pass** (new): If an OoS exists for this service, run a validation/enrichment pass:
  - Confirm song titles (match OoS songs to detected song segments by position)
  - Enrich bible reading sections with the specific passage reference from the OoS
  - Flag any structural mismatches (e.g., OoS has 3 songs but audio has 4) for review
  - OoS agreement raises confidence; disagreement lowers it but does not override audio
- **Confidence scoring**: Each section gets a confidence score from AI classification. OoS agreement/disagreement adjusts it. Low-confidence sections flagged for manual review.
- **Fallback without AI**: If transcription/AI disabled, label remaining speech segments as `Other` with `needs_manual_review = true`
- **Fallback without OoS**: Pipeline still works — sermon extracted, other segments labelled by AI, but song titles and bible references may be less precise

### Phase 5: Bible Reading → Sermon Reference Linkage
- When a `BibleReading` section is identified (from OoS title or AI transcript analysis), extract the passage reference
- Write to sermon record's `reference` field, prioritising OoS-sourced reference over AI-extracted
- Display on sermon page as "Reading: [reference]" with BibleGateway link

### Phase 6: Public Song Usage Page
- New route `/church/worship-songs`
- Public Livewire component showing ranked song list
- Usage count, last sung date, authors
- Date range filtering (this year / all time)
- Optional: show lyrics on detail view (public version of existing admin `ShowSong`)

### Phase 7: Admin Segment Review Dashboard
- Consolidated view of all `ServiceSection` records with `PendingApproval` status
- Approve/reject actions
- Preview extracted audio/video
- Batch operations for efficiency
- Show AI classification confidence alongside each segment

### Phase 8: Event-Driven Incremental Assembly (see §5.2)
- Add optional `church_service_id` FK on `media_processing_logs` as a resolved-link cache
- When a `ChurchService` is created/updated, check for existing processed livestreams on the same date+service
- If found, dispatch a lightweight re-classification job (OoS alignment pass only, not full re-processing)
- Source-aware merge in `ChurchServiceItemSyncService`: OpenLP enriches song metadata without overwriting richer email-sourced type information
- Update song linkage and confidence scores; flag new mismatches for review

### Future: Notice Transcription & Email
- Configure notice sections for extraction in `section_publishing.extract_types`
- Transcribe extracted notice audio (may already be transcribed from Phase 4 split candidate processing)
- Store transcript on `ServiceSection.metadata`
- Build subscriber model and opt-in form
- Automated email delivery post-transcription

---

## 7. Success Metrics

| Metric | Target |
|---|---|
| Sermon extraction accuracy | >95% of livestreams produce a correctly-bounded sermon |
| Children's talk extraction | >90% correctly identified when OoS is provided |
| Song linkage rate | >95% of OoS songs linked to catalog entries |
| Manual intervention rate | <10% of services need admin correction |
| Public song page | Live and populated within Phase 5 delivery |

---

## 8. Out of Scope

- Multi-site / multi-church support
- Real-time livestream processing (always post-hoc)
- Full scripture text display (reference + link only)
- Song editing/CRUD in admin (OpenLP remains source of truth)
- AI classification without order of service (OoS required)
- PDF/Word order of service parsing
- Public service detail pages (items feed into individual pages only)
