# PRD v2: Church Service Processing & Content Management

**Date**: March 2026  
**Status**: Draft v2  
**Author**: Revised from codebase analysis + stakeholder decisions

---

## 1. Vision

Transform raw church service recordings into structured, browsable content.

A single livestream upload, optionally enriched by an order of service (OoS), should produce:

- A sermon page with audio, video, transcript, summary, key points, preacher, and preached passage.
- A dedicated Children's Corner section for children's talks.
- Public worship song usage statistics based on what was actually sung in the livestream.
- A review workflow that flags any mismatch between the imported OoS and what happened in the service.
- In a future phase, transcribed notices delivered by email to subscribers.

The system must remain useful even when no OoS is provided. OoS improves accuracy and labeling, but it is not required for v1.

---

## 2. Core Product Principles

1. **Livestream audio/video is authoritative for what actually happened.**  
   The livestream defines the actual structure of the service: songs, speech blocks, sermon, children's talk, notices, and reading segments.

2. **Source precedence is fact-specific, not global.**  
   When multiple sources exist, the best source depends on the fact being resolved: livestream for actual occurrence/order/timing, OpenLP over email for song titles and catalog linking, and human-reviewed admin edits for the final canonical service list.

3. **OoS is an enrichment and review aid, not the source of truth for actual events.**  
   Email, manual entry, and OpenLP provide the planned or expected running order. They improve naming and matching, but they do not silently override livestream structure.

4. **Differences must be flagged, not hidden.**  
   If sources disagree materially, the system should surface the discrepancy for review rather than auto-mutating records without visibility.

5. **Reviewed admin decisions are canonical until new conflicting data reopens review.**  
   Once an admin has reviewed or corrected a service, later imported conflicts should automatically reopen review instead of silently overwriting the reviewed state.

6. **One canonical service list is enough.**  
   The app only needs to store the final version of the order-of-service list on `ChurchService` + `ChurchServiceItem`. It does not need to preserve multiple per-source item lists. Source metadata may still be stored for audit/debugging.

7. **Song usage reflects what actually happened, not just what was planned.**
   For livestreamed services, a song only counts when a song segment is detected in the recording and confidently linked to a catalog song. For non-livestreamed services (e.g. evening services), the OoS is trusted directly since no audio evidence exists to confirm or deny.

8. **`Sermon.reference` means the preached passage.**  
   The public reading reference is related but separate. It should not overwrite the sermon passage field.

---

## 3. User Stories

### Worship Coordinator (Admin)

1. Upload or ingest an order of service by email, OpenLP, or manual entry.
2. Upload a livestream recording and have it automatically segmented.
3. Review mismatches between planned and actual service items.
4. Approve children's talks for publication.
5. See which songs were actually sung most often and when they were last used.

### Website Visitor (Public)

6. Browse sermons with audio, video, transcript, summary, key points, and preached passage.
7. Browse children's talks in a dedicated Children's Corner section.
8. View public song usage statistics.

### Church Member (Authenticated, Future)

9. Subscribe to weekly notices by email after they are transcribed.

---

## 4. Current State Summary

### 4.1 Existing Capabilities

- Livestream upload and segmentation pipeline already exists.
- Sermon extraction, transcription, AI sermon analysis, and thumbnail generation already exist.
- OpenLP `.osz` import already creates `ChurchService` and `ChurchServiceItem` records.
- `ChurchService` already supports `needs_review`.
- `ServiceSection` records already exist for classified sections and support review state.
- Children's talk extraction and publication flow already exists, but published output is still represented as a normal `Sermon`.
- Song catalog import and song linking already exist.
- Admin song usage views already exist.
- An admin section publication queue already exists.

### 4.2 Existing Gaps

- OoS ingestion only supports OpenLP today.
- The current section classifier is effectively OoS-aligned first and degrades poorly when no matching `ChurchService` exists.
- There is no durable link from a processed livestream to a `ChurchService`.
- Children's talks do not have a first-class content distinction inside the `sermons` domain.
- Public song usage pages do not exist.
- The sermon page does not cleanly separate preached passage from service reading.
- Reconciliation between OoS and livestream is not yet explicit or event-driven.

---

## 5. Domain Model and Data Semantics

### 5.1 Canonical Service List

`ChurchService` + `ChurchServiceItem` represent the canonical service list for a date/service pair.

- Initially this list is created from the best available source data, whether that is email, OpenLP, manual entry, or a merge of those sources.
- If the livestream later reveals that the service differed from the imported OoS, the system flags those differences.
- An admin may then edit the canonical list to reflect the final reviewed version of the service.
- Once reviewed, the canonical list remains authoritative until later conflicting imported data reopens review.
- The application does not need to keep separate full item lists per source.

### 5.1.1 Source Precedence By Fact Type

- If only one source exists, the system should trust that source as the best available evidence within its domain.
- If multiple sources exist, the system should reconcile by fact type rather than applying a single whole-source winner.
- `livestream` is authoritative for actual occurrence, order, timing, and presence/absence of service sections.
- `OpenLP` is authoritative over `email` for song titles, `openlp_search_title`, and catalog linkage via `song_id`.
- `email` and `manual` entry may add speech-item intent that OpenLP often omits (for example prayer, notices, and children's talks).
- Reviewed manual/admin edits are canonical.
- Imports should default to merge semantics. Destructive replacement should be explicit rather than implied by a partial import.
- Later imported conflicts after review should reopen review automatically rather than silently overwrite the reviewed canonical list.

### 5.2 Actual Detected Service Structure

`ServiceSection` represents what the system detected in the livestream.

- It is derived from audio/video analysis and transcript-based classification.
- It captures actual timings, types, confidence, review state, and publication readiness.
- It can link back to `ChurchServiceItem` when there is a confident match.

### 5.3 Sermon References

- `Sermon.reference` stores the **preached passage**.
- The **service reading reference** should come from the matched `BibleReading` section or linked `ChurchServiceItem`.
- The sermon page may display both, but they must remain distinct concepts.

### 5.4 Songs

- For **livestreamed services**, a song counts toward usage statistics only when a song segment is actually detected in the livestream AND is confidently linked to a catalog song via `song_id`. An OoS song with no detected segment does not count. A detected segment with no `song_id` match does not count publicly.
- For **non-livestreamed services** (e.g. evening services), no audio evidence exists to confirm or deny what was sung. In this case the OoS is trusted directly — any `ChurchServiceItem` of type song with a `song_id` counts toward usage.
- `song_id` should be assigned when there is a strong normalized title match to the song catalog. When both sources exist, OpenLP is preferred over email for song-title evidence.
- **Position is not relevant for catalog matching** — a song is identified by its title, not by where it falls in the running order. Position is useful for matching *which detected song segment corresponds to which OoS song* (i.e., labelling), but the catalog lookup itself is always by normalised title.
- **Order differences are expected.** If the OoS lists songs A, B, C, D but the livestream order is A, C, B, D, that's fine — the system should match each detected song to its OoS counterpart by title, not assume position parity. An order mismatch is not an anomaly worth flagging.
- If a song segment is detected but no strong title match exists, it should remain unmatched and be flagged for review rather than force-linked by position. Review-only inferred labels may help admins, but they must not count publicly as confirmed song usage.

---

## 6. Requirements by Domain

### 6.1 Order of Service Ingestion

#### Goal

Allow the church to create or update the canonical service list from the sources they already use, with email as the primary path.

#### Sources

1. **Email ingestion (primary)**
   - Use Mailgun inbound routing for `oos@crockenhill.org`.
   - Mailgun should POST inbound email data to a Laravel webhook endpoint.
   - The system should verify the Mailgun signature before accepting the request.
   - The system should deduplicate inbound emails by `Message-Id` or equivalent stable identifier.
   - The system should store enough raw inbound metadata to support reprocessing/debugging.
   - The body content should be parsed into ordered items using AI-assisted extraction when needed.

2. **Manual admin form (fallback)**
   - Admin can create or edit a service manually.
   - Admin selects date and service type.
   - Admin adds ordered items with type and title.
   - Songs support autocomplete against the song catalog.
   - Admin can reorder, edit, add, and remove items.

3. **OpenLP import (supported secondary source)**
   - Existing `.osz` import remains available.
   - OpenLP remains the best structured source for song titles and catalog linking.

#### Parsing and Merge Rules

- `date + service type` must remain unique.
- Email parsing may set `needs_review = true` when confidence is below threshold.
- If the service already exists, incoming data updates the canonical list rather than creating a duplicate.
- If only one source exists, the system should trust that source as the best available representation within its domain.
- If multiple sources exist, source precedence is resolved per fact type rather than by a single whole-source winner.
- OpenLP is preferred over email for song titles and catalog linking.
- Email/manual data may add speech items that OpenLP does not contain.
- Imports default to merge semantics. Partial imports must not implicitly delete unmatched lower-priority items unless an explicit replace workflow is used.
- When the livestream is available, differences between imported OoS data and detected actual sections are flagged for admin review.
- After review, the admin-edited canonical list becomes the final version until later conflicting imported data automatically reopens review.

---

### 6.2 Livestream Processing and Section Classification

#### Goal

Make section detection work with or without an OoS, while using OoS to improve naming and confidence when available.

#### Why the classification rework is needed

The current classifier depends on the OoS to tell it what each speech segment contains. But OpenLP — the only OoS source today — only exports songs and bible readings. Prayers, welcomes, notices, and children's talks happen live without slides and are absent from the export. This creates structural holes:

```
Actual service:      Welcome → Song → Prayer → Children's Talk → Song → Bible Reading → Sermon → Song
OpenLP exports:               Song →                             Song → Bible Reading                → Song
RMS detects:         [speech₁] [song₁]  [-----speech₂-----]     [song₂] [-----speech₃-----]        [song₃]
```

- `speech₂` contains prayer + children's talk merged into one continuous block (no audible gap between speakers).
- `speech₃` contains bible reading + sermon merged into one block.
- The classifier only knows about the songs and the bible reading from OpenLP.
- `speech₂` gets **no classification** — it is orphaned because no OoS item expects it.
- `speech₃` gets matched to "bible reading" but actually contains both the reading and the sermon.

The email OoS will improve this (it includes prayers, notices, children's talks), but even then, the OoS represents what was *planned*. The actual service may differ — items reordered, prayers added spontaneously, a children's talk dropped. The classifier must therefore be able to decompose speech blocks using audio evidence (transcription + AI boundary detection), with the OoS as enrichment rather than structural template.

#### Required Pipeline

1. **Audio/video structural analysis**
   - Detect songs, speech blocks, and silence.
   - Preserve the existing RMS and visual analysis pipeline where useful.

2. **Sermon identification**
   - Detect the main sermon automatically when confidence is high.
   - If the sermon candidate is ambiguous, flag the whole service for review.

3. **Speech segment transcription**
   - Transcribe non-sermon speech blocks.
   - Use targeted transcription rather than transcribing the full service again where possible.

4. **AI section classification**
   - Classify speech blocks into likely sections such as welcome, prayer, notices, children's talk, Bible reading, sermon, or other.
   - Split merged speech blocks where boundary phrases clearly indicate multiple sections.

5. **OoS alignment and enrichment**
   - If a canonical service list exists, compare detected sections against it.
   - Use OoS titles to improve labels and song matches.
   - Use a Bible reading item to surface the public reading reference.
   - Do not silently overwrite actual section detection when OoS disagrees.
   - Record mismatches for review.

#### Required Behaviors

- The pipeline must work when no OoS exists.
- The classifier must not skip all section creation purely because no `ChurchService` exists yet.
- The system should still extract the sermon even if non-sermon sections remain low confidence.

---

### 6.3 Event-Driven Incremental Assembly

#### Goal

Handle the reality that email, OpenLP, manual entry, and livestream uploads may arrive in any order.

#### Required Behavior

| Event | Expected behavior |
|---|---|
| OoS arrives first | Create/update the canonical service list and link songs where possible. |
| Livestream arrives first | Process the livestream without OoS, create detected sections, and mark lower-confidence enrichment where needed. |
| OoS arrives after livestream | Re-run an alignment/reconciliation pass against existing detected sections and flag differences. |
| OpenLP arrives after email/manual | Enrich song titles and song linkage from OpenLP, preserve speech items that OpenLP omits, and reopen review automatically if reviewed data now conflicts. |
| Canonical service list changes after processing | Re-run lightweight reconciliation, not full media processing. If reviewed data now conflicts with a later import, reopen review automatically. |

#### Implementation Requirements

- Add a durable optional link from processed livestreams to the matching `ChurchService`.
- Reconciliation must be re-triggerable when new OoS data arrives later.
- Reconciliation must be re-triggerable when item-level canonical list changes occur, not only when the service date/service pair changes.
- Later imported conflicts against a reviewed service must automatically reopen review.
- Lightweight re-alignment must be cheaper than full sermon/video reprocessing.

This is foundational work and should happen early, not late.

---

### 6.4 Children's Talks

#### Goal

Publish children's talks as first-class public content while keeping the existing sermon/media infrastructure.

#### Requirements

- Keep the same underlying content model as sermons.
- Add a distinct content type on `sermons`, for example:
  - `sermon`
  - `childrens_talk`
- Publishing a children's talk from a `ServiceSection` should create a `Sermon` with `content_type = childrens_talk`.
- Children's talks should have their own public listing page and detail presentation.
- Children's talks should be excluded from the main sermon listing and podcast feed.
- The dedicated public area should be "Children's Corner".

#### Public UX

- Listing page with title, date, speaker if known, and media availability.
- Detail page can be simpler than a sermon page and does not need AI key points by default.

---

### 6.5 Sermon Display

#### Goal

Make sermon pages reflect both the preached passage and the service context without conflating them.

#### Requirements

- Display the preached passage prominently from `Sermon.reference`.
- If there was a distinct public Bible reading, display it separately as "Reading".
- Reading reference should come from the linked service section/service item, not by overwriting the sermon passage field.
- Passage references may link out to BibleGateway or equivalent.

---

### 6.6 Public Song Usage Page

#### Goal

Expose song usage statistics publicly. Livestreamed services use actual detected singing; non-livestreamed services trust the order of service.

#### Requirements

- Public route and page for ranked worship songs.
- Show title, authors, usage count, and last sung date.
- Support at least:
  - all time
  - this year
- Song usage is counted differently depending on whether a livestream exists:
  - **Livestreamed service**: a song counts only when a song section was actually detected in the livestream AND the section has a confident `song_id` match. OoS-only songs without a detected section do not count.
  - **Non-livestreamed service**: when no livestream was processed for the service (e.g. evening services), the OoS `ChurchServiceItem` is trusted directly. Any song item with a `song_id` counts toward usage.
- Unmatched detected songs (no `song_id`) should be reviewable in admin but not shown publicly as canonical entries.

---

### 6.7 Review Workflow

#### Goal

Make disagreement and low confidence visible to admins in one place.

#### Requirements

- Extend the existing admin section review queue rather than creating a separate unrelated workflow.
- Review UI should surface:
  - low-confidence section classifications
  - unmatched detected songs
  - OoS vs livestream mismatches
  - ambiguous sermon detection
  - pending children's talk publications
- Admin should be able to:
  - approve or reject candidate publications
  - inspect extracted media
  - confirm or correct section type/title
  - update the canonical service list when the imported OoS was wrong

---

### 6.8 Notices (Future Phase)

#### Goal

Eventually extract, transcribe, store, display, and email service notices.

#### Requirements

- Identify notice sections from the classification pipeline.
- Extract notice audio.
- Transcribe notice audio.
- Store notice transcript on the relevant section record or equivalent service-linked model.
- Add subscribers, opt-in, and email delivery in a later phase.

This is intentionally deferred and should not block the v1 classification redesign.

---

## 7. Confidence and Review Policy

Confidence is orthogonal to source precedence.

- Source precedence chooses the best source for a given fact.
- Confidence determines whether the system may accept that fact automatically, store it provisionally, or require manual review.
- Even when only one source exists, low-confidence inferred labels may remain provisional rather than being treated as fully canonical.

### 7.1 OoS Email Parse Confidence

- `>= 0.90`: auto-import without review.
- `0.75 - 0.89`: import and set `needs_review = true`.
- `< 0.75`: do not auto-update the canonical service list; retain the payload for manual review.

### 7.2 Sermon Detection Confidence

Auto-select the sermon only when:

- exactly one speech block is at least 20 minutes long, and
- it is at least 1.5x longer than the next-longest speech block.

Otherwise:

- do not silently choose;
- flag the service for manual review.

### 7.3 Section Classification Confidence

- Confidence should gate downstream automation such as section auto-labelling, sermon auto-extraction, public song counting, and publication workflows.
- `>= 0.85` and no anomalies/conflicts: accept automatically.
- `0.60 - 0.84`: create/update the section but mark it for manual review.
- `< 0.60`: classify as `other` or `unclassified` and require review.

### 7.4 Song Matching Confidence

- For song-title and catalog-linking facts, OpenLP is preferred over email whenever OpenLP data exists.
- A song gets a `song_id` only when there is a strong normalized title match to the catalog.
- Position alone must not be used to force a song match.
- Weak or ambiguous matches remain unmatched and require review.
- Inferred or position-based review labels may help admin review, but they must not count publicly as confirmed song usage.

### 7.5 Service-Level Review Triggers

The service should be flagged for review when any of the following is true:

- sermon detection is ambiguous;
- a late OoS changes the alignment result for already processed sections;
- later imported data conflicts with a previously reviewed canonical service list;
- one or more detected song sections remain unmatched;
- the imported OoS and actual detected structure materially disagree;
- more than 20% of sections are below `0.85` confidence.

---

## 8. Proposed Delivery Phases

### Phase 1: Incremental Assembly Foundation

- Add durable livestream-to-service linking.
- Support re-alignment when OoS data arrives after processing.
- Ensure the classifier can produce useful section data without a pre-existing `ChurchService`.

### Phase 2: OoS Ingestion

- Add Mailgun inbound webhook support for OoS emails.
- Add dedupe, signature verification, and review thresholds.
- Add manual admin form for create/edit/reorder.
- Keep OpenLP import as a supported secondary source.

### Phase 3: Classification Rework

- Implement targeted transcription of non-sermon speech blocks.
- Implement AI-based split and label classification for speech segments.
- Add confidence scoring and structured mismatch reporting.
- Align detected sections against the canonical service list when available.

### Phase 4: Children's Talks

- Add `content_type` to `sermons`.
- Publish children's talks through the existing sermon/media pipeline.
- Exclude children's talks from main sermon listings and podcast feeds.
- Build the Children's Corner public surface.

### Phase 5: Sermon Display Enhancements

- Preserve `Sermon.reference` as preached passage.
- Display service reading separately when present.

### Phase 6: Public Song Usage

- Build the public worship songs page.
- Use only actual detected-and-matched songs for statistics.
- Add filters such as all time and this year.

### Future Phase: Notices

- Extract, transcribe, store, and deliver notices by email.

---

## 9. Success Metrics

| Metric | Target |
|---|---|
| Sermon extraction accuracy | >95% of livestreams produce a correctly bounded sermon |
| Children's talk detection | >90% correct when the talk occurs clearly in the livestream |
| Song linkage rate | >95% of detected songs with known titles link to a catalog entry |
| Manual intervention rate | <10% of services require structural admin correction |
| OoS-free usefulness | Services without OoS still produce sermon extraction and usable section classification |
| Public song page | Live and populated by Phase 6 |

---

## 10. Out of Scope

- Multi-site or multi-church support
- Real-time livestream processing
- Full scripture text display on-site
- Public song CRUD
- Preserving separate full item lists for every OoS source
- PDF/Word OoS parsing in v1
- Notice subscription and delivery in v1

---

## 11. Known Edge Cases

1. **Multiple sermons per service.** Rare but possible (e.g., a guest speaker gives a short word, then the regular preacher gives the main message). The sermon detection confidence policy (§7.2) handles this — if no single candidate clearly dominates, the service is flagged for manual review rather than silently picking the wrong segment.

2. **Songs sung during the sermon.** Some preachers ask the congregation to sing a verse mid-sermon. The RMS/visual analysis may detect this as a short song segment, splitting the sermon into two speech blocks. The classifier should tolerate short song segments (under ~90 seconds) embedded within what is otherwise the longest speech block and treat them as part of the sermon rather than as separate songs.

3. **Communion services.** The order of service includes additional elements (communion hymn, words of institution, distribution silence) that don't map to current `ServiceSectionType` values. These should fall into `Other` gracefully. If communion services become frequent enough to warrant tracking, a `Communion` section type could be added later.

4. **Joint or special services.** Combined services (church anniversary, baptism, carol service) may have a significantly different structure from the typical morning/evening pattern. The classifier should degrade gracefully — produce what it can confidently identify and flag the rest for review — rather than force-fitting a standard template.

5. **Children's talk speaker.** The speaker identification system targets the sermon preacher. Children's talks are typically given by a different person. There is currently no mechanism to identify or assign the children's talk speaker automatically. For v1, the speaker can be set manually during admin review.

---

## 12. Open Design Notes

- The current admin section publication queue should be extended, not replaced.
- The current service classifier should evolve from OoS-first matching to audio-first detection with OoS enrichment.
- The final reviewed OoS list may differ from the originally imported email/OpenLP plan, and that is acceptable.
