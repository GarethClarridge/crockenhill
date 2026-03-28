# Livestream Processing Improvements Plan

Three related improvements to the livestream processing pipeline: better children's talk detection, transcript-informed song matching, and a redesigned admin service view.

---

## 1. Improve AI Children's Talk Detection

### Problem

The `SpeechSectionClassificationService` prompt gives the AI no guidance on distinguishing children's talks from sermons. In the Feb 1 service, a 9-minute Esther talk aimed at children was classified as `sermon` because it has sermon-like structure (Bible exposition, narrative, application to Jesus). The AI has no reason to suspect it isn't a sermon.

There are two compounding issues:
1. **The prompt has no domain knowledge** — it doesn't know that services virtually never have two sermons
2. **`ClassifySpeechSections` skips `SERMON` sections entirely** — so even if the prompt were better, the main sermon block (detected by RMS as the longest speech segment) is never sent for AI transcript analysis and therefore never gets a chance to be reclassified

### Approach

A two-part fix: improve the AI prompt with contextual cues, and add a post-classification pass that detects "second sermon" situations.

### Changes

#### A. Enhance the system prompt (`SpeechSectionClassificationService::openAiResponse`)

Add domain-specific rules to the existing system message at line 136:

```
- A church service almost never has two sermons. If you detect what looks like a sermon,
  consider whether it might be a children's talk instead. Children's talks are characterised by:
  • Shorter duration (typically 5–15 minutes vs 25–45 minutes for sermons)
  • Interactive language: "can anybody tell me?", "what do you think?", "hands up"
  • References to visual aids: "let's have the next slide", "can you see in the picture"
  • Simpler vocabulary and narrative-driven Bible teaching (often retelling a story)
  • Often ends with a brief prayer then transitions to a song
  If in doubt between sermon and childrens_talk for a shorter expository section, prefer
  childrens_talk and flag an anomaly explaining why.
```

**File**: `app/Services/SpeechSectionClassificationService.php` (lines 136–145)

#### B. Pass service-level context in the user prompt

Currently `buildUserPrompt()` only passes the segment's own transcript. Add a `service_context` parameter that tells the AI what other sections have already been classified in this service. Specifically: whether a sermon section already exists elsewhere in the service.

**File**: `app/Services/SpeechSectionClassificationService.php`

New method signature for `classify()`:
```php
public function classify(ServiceSection $section, array $serviceContext = []): array
```

New `buildUserPrompt()` addition:
```
Service context: {n} sections already classified for this service.
A sermon section of {duration}s has already been identified at {start}–{end}.
```

**File**: `app/Jobs/ClassifySpeechSections.php`

Before the main loop, scan `$existingSections` for any `SERMON` sections and build a context array to pass through.

#### C. Add post-classification "second sermon" demotion — AFTER song folding

> **Critical ordering constraint**: `demoteSecondarySermons()` must run **after** `foldShortSongsIntoSermon()`, not before. The existing fold path merges `SERMON → short SONG → SERMON` clusters into a single sermon section (`ClassifySpeechSections` line 301, with regression coverage at `ClassifySpeechSectionsTest` line 222). If demotion ran first, the trailing sermon fragment would be reclassified to `childrens_talk`, breaking the fold match and leaving a fragmented sermon.

Add `demoteSecondarySermons()` in `ClassifySpeechSections::handle()` — called **after** `foldShortSongsIntoSermon()` returns and **before** section_order reassignment:

```php
// Current flow (line 103-110):
$rewrittenSections = $this->foldShortSongsIntoSermon($rewrittenSections);
$rewrittenSections = $this->demoteSecondarySermons($rewrittenSections); // NEW — after folding
foreach ($rewrittenSections as $index => &$rewrittenSection) {
    $rewrittenSection['section_order'] = $index + 1;
}
```

Logic:
1. Collect all sections with `section_type === 'sermon'` (post-fold, so legitimate SERMON→hymn→SERMON clusters are already merged)
2. If there is more than one sermon section:
   - Keep the longest as the primary sermon
   - For each shorter "sermon", if its duration is under a configurable threshold (default: 900s / 15 min), reclassify it as `childrens_talk`
   - Set `needs_manual_review = true` with `review_reason = 'demoted_secondary_sermon_to_childrens_talk'`
   - Store the original classification in metadata: `original_ai_classification = 'sermon'`
3. If the shorter "sermon" is *longer* than 15 minutes, leave it as sermon but flag it with `review_reason = 'multiple_sermons_detected'`

This catches the case even when the AI doesn't pick up on children's talk cues.

**Config** (`config/media-processing.php`):
```php
'section_classification' => [
    // ... existing keys ...
    'childrens_talk_max_duration_seconds' => 900,
],
```

#### D. Update mock response patterns

Add more children's talk trigger phrases to the mock classifier:

```php
ServiceSectionType::CHILDRENS_TALK->value => [
    'good morning children',
    'children',
    'can anybody tell me',
    'hands up',
    'let\'s have the next slide',
    'boys and girls',
],
```

**File**: `app/Services/SpeechSectionClassificationService.php` (lines 220–227)

### Testing

- Unit test: `SpeechSectionClassificationServiceTest` — mock OpenAI to return a response with two `sermon` sections; verify the shorter one gets demoted
- Unit test: verify the enhanced prompt includes service context when a sermon already exists
- Unit test: verify that demotion runs **after** song folding — set up a `SERMON → short SONG → SERMON` → `short SERMON` sequence; confirm folding merges the first cluster, then demotion targets only the remaining short sermon
- Feature test: full pipeline with mock transcription where speech contains children's talk markers; verify `childrens_talk` classification
- Edge case: service with genuinely two sermons (morning + evening combined recording) — verify the longer-than-threshold sermon is flagged but not demoted

---

## 2. Transcript-Informed Song Matching

### Problem

Song segments detected by RMS/visual analysis have no titles — they're just "Song" with `song_match_type: unmatched`. Meanwhile, the AI transcript classification *does* find song titles in the spoken introductions (e.g., "Let's stand and sing together, though the nations rage" → section 60, and "We are going to sing a song entitled Your Word" → section 66). These two data sources are never connected.

Additionally, when no Order of Service is submitted, the `AlignWithOos` job has nothing to align to.

### Approach

Three-pronged strategy:
1. **Harvest song title hints** from AI-classified transcript sections and feed them into the alignment-aware song matching pipeline
2. **Transcribe the first ~30 seconds of each song segment** (post-classification, in a new alignment-aware step) and match against existing `lyrics_plain` on the Song model
3. **Create a single new pipeline job** that runs after OoS alignment, handles both title-hint and lyrics-based matching, and correctly clears stale review state

### Changes

#### A. Harvest song title hints from transcript sections

After `ClassifySpeechSections` produces its rewritten sections, scan for a pattern: a speech section classified as `song` (an announcement) immediately before an RMS-detected `song` section. Extract the song title from the speech section's transcript.

**New service**: `app/Services/SongTitleHintExtractor.php`

```php
class SongTitleHintExtractor
{
    /**
     * Scan classified sections for song introduction patterns.
     * Returns a map of RMS song section_order => extracted title hint.
     *
     * @param array $sections Rewritten section payloads from ClassifySpeechSections
     * @return array<int, string> Map of section_order => title hint
     */
    public function extract(array $sections): array
}
```

Logic:
1. Walk through sections in order
2. When a section has `classification_mode: ai_transcript` and `ai_requested_section_type: song`, extract the likely song title from the transcript using a simple heuristic:
   - Look for patterns like "sing {title}", "song entitled {title}", "song called {title}", "hymn {number}", etc.
   - Also check `ai_notes` for title mentions
3. Look at the *next* section — if it's an RMS-detected song (`classification_mode: audio_only`, `section_type: song`), associate the extracted title with that song section
4. Also check the *previous* section — sometimes the announcement bleeds into the start of the RMS song section

**Integration point**: Call from `ClassifySpeechSections::handle()` after the main classification loop, before sync. Write extracted titles into the song section's metadata as `song_title_hint`.

#### B. Wire `song_title_hint` into the existing matching seam

> **Key correction**: Storing the hint in metadata alone doesn't improve matching accuracy. `SongSectionAligner::songCandidatesFromSection()` (line 273) only reads from `section.title`, `oos_alignment.song_title_matched`, `oos_alignment.matched_item_title`, and `linked_song_canonical_key`. The hint must be surfaced through one of these paths.

Two integration points:

1. **`SongSectionAligner::songCandidatesFromSection()`** — add `$metadata['song_title_hint']` as a candidate source:
   ```php
   $section->title,
   $metadata['song_title_hint'] ?? null,          // NEW
   $metadata['oos_alignment']['song_title_matched'] ?? null,
   $metadata['oos_alignment']['matched_item_title'] ?? null,
   $metadata['linked_song_canonical_key'] ?? null,
   ```
   **File**: `app/Services/SongSectionAligner.php` (line 278)

2. **`LivestreamSectionToServiceItemMapper::resolveTitle()`** — use the hint as the item title instead of the generic "Song" label:
   ```php
   private function resolveTitle(ServiceSection $section): string
   {
       if (is_string($section->title) && trim($section->title) !== '') {
           return trim($section->title);
       }

       $hint = $section->metadata['song_title_hint'] ?? null;
       if ($section->section_type === ServiceSectionType::SONG && is_string($hint) && trim($hint) !== '') {
           return trim($hint);
       }

       return $section->section_type->label();
   }
   ```
   **File**: `app/Services/LivestreamSectionToServiceItemMapper.php` (line 82)

   Note: the title-resolution seam is in the mapper, not the projection service.

#### C. Transcribe song openings — in a new post-classification job, not TranscribeSpeechSegments

> **Critical ordering constraint**: `TranscribeSpeechSegments` runs *before* `ClassifySpeechSections` in the pipeline (`ProcessingPipelineBuilder` line 105–106). The `song_title_hint` is only written during classification. Therefore, the plan to skip songs "that already have `song_title_hint`" cannot work if transcription runs first.

Instead of modifying `TranscribeSpeechSegments`, song opening transcription belongs in the new `MatchSongsFromTranscript` job (see section D below), which runs *after* classification and alignment. This job already has the full context: which songs are still unmatched, which have title hints, and which need lyric-based fallback.

Song opening transcription logic (within `MatchSongsFromTranscript`):
1. For each `SONG` section that is still `unmatched` after OoS alignment AND has no `song_title_hint`
2. Extract audio for the first 30 seconds only (configurable: `song_opening_transcription_seconds`)
3. Transcribe via Whisper
4. Store in metadata as `song_opening_transcript`
5. Attempt lyric matching (see section E)

**Config** (`config/media-processing.php`):
```php
'section_classification' => [
    // ... existing keys ...
    'transcribe_song_openings' => true,
    'song_opening_transcription_seconds' => 30,
],
```

#### D. New pipeline job: `MatchSongsFromTranscript`

**New job**: `app/Jobs/MatchSongsFromTranscript.php`

Runs after `AlignWithOos` in the pipeline. Handles the full song matching fallback chain for sections still marked `unmatched`.

> **Critical requirement**: This job must re-run the alignment bookkeeping after mutating song sections. `OosAlignmentService` (line 82–100) computes unmatched-song flags via `UnmatchedSongReviewApplicator` (line 35) and syncs the `ChurchService` review state via `ReviewSynchronizer` (line 100). If `MatchSongsFromTranscript` changes a song from `unmatched` to `inferred` without clearing those flags, the service will be stuck in "needs review" with stale `unmatched_song_section` review flags.

Approach:
1. Load all song sections for this processing run
2. For each `unmatched` song section:
   a. Check `song_title_hint` → `Song::canonicalizeKey()` lookup → fuzzy title match
   b. If still unmatched and `transcribe_song_openings` enabled → extract + transcribe first 30s → `SongLyricsMatchingService::matchFromLyrics()`
   c. If a match is found:
      - Update `ServiceSection.song_match_type` to `inferred`
      - Store match details in metadata: `transcript_song_match: { song_id, title, confidence, match_source: 'title_hint' | 'lyrics' }`
      - Update the linked `ChurchServiceItem.song_id` and `title`
3. **After all mutations**: re-run `UnmatchedSongReviewApplicator::apply()` to refresh review flags
4. **After flag refresh**: re-run `ReviewSynchronizer::sync()` on the ChurchService to clear stale review state
5. Persist all section changes

**Pipeline integration** (`app/Services/ProcessingPipelineBuilder.php`):

Insert `MatchSongsFromTranscript` into the sequential chain after `AlignWithOos` and before `ExtractSermon`:

```
... → AlignWithOos → MatchSongsFromTranscript → ExtractSermon → ...
```

#### E. Match song lyrics against catalog using existing `lyrics_plain`

**New service**: `app/Services/SongLyricsMatchingService.php`

> **Correction**: The `Song` model already has a `lyrics_plain` column (Song.php line 23, fillable at line 61). No new migration or `first_line` column is needed.

```php
class SongLyricsMatchingService
{
    /**
     * Given a short transcript excerpt (e.g. first 30s of a song),
     * attempt to match it against the Song catalog using lyrics_plain.
     *
     * @return array{song_id: int|null, confidence: float, matched_title: string|null}
     */
    public function matchFromLyrics(string $transcript): array
}
```

Matching strategy:
1. **Title extraction first**: Look for the song title in the first line (many songs start with their title)
2. **Canonical key lookup**: Run `Song::canonicalizeKey()` on extracted phrases and check the songs table
3. **Fuzzy lyrics search**: For songs with non-null `lyrics_plain`, compare the transcribed opening against the first ~200 chars of `lyrics_plain` using `similar_text()` scoring
4. Require a minimum confidence threshold (0.6) to return a match

### Testing

- Unit test: `SongTitleHintExtractorTest` — verify extraction from various announcement patterns ("let's sing X", "song entitled X", "hymn number X")
- Unit test: `SongLyricsMatchingServiceTest` — verify fuzzy matching against `lyrics_plain`
- Unit test: verify `SongSectionAligner::songCandidatesFromSection()` includes `song_title_hint`
- Unit test: verify `MatchSongsFromTranscript` re-runs `UnmatchedSongReviewApplicator` after matching
- Feature test: full pipeline with mock transcription where speech announces song titles; verify songs get matched and review flags are cleared
- Feature test: song opening transcription produces lyrics that match a catalog entry via `lyrics_plain`
- Edge case: song announced but title doesn't match any catalog entry — verify `unmatched` with hint stored
- Edge case: no song announcement at all — verify lyric matching is attempted
- Regression: verify existing `foldShortSongsIntoSermon` and OoS alignment behaviour is unchanged

---

## 3. Redesign the Admin Service View

### Problem

The current service view at `/admin/services/{id}` shows a tabular timeline with columns for #, Type, Planned, Source, Detected, Timing, Status, and Publication. This is data-dense and hard to scan. It's designed around the OoS alignment workflow (planned vs. detected) which is irrelevant for livestream-only services.

The conversational summary format I provided earlier was more useful because it:
- Shows the service as a sequential narrative (what actually happened)
- Gives a short human-readable description of each section's content
- Makes it immediately obvious what's a song, what's a talk, what's a prayer
- Includes timestamps in a readable format

### Approach

> **Key constraint**: The new "service flow" view must be layered **on top of** the existing `ServiceRecordTimeline` model, not replace it. The timeline model (`ServiceRecordTimeline::build()`) produces merged planned/detected rows that surface critical operator signals: `planned_only` items (things expected but not detected), `mismatched` rows (OoS conflicts), `unplanned` rows (unexpected sections), inferred matches, and review reasons. Building from raw sections alone would lose these signals.

The flow view should be the **default visual layer** but must incorporate the planned/detected merge data, not bypass it.

### Design

#### New "Service Flow" layout

A vertical list of sections in chronological order. Each section is a compact card/row:

```
┌─────────────────────────────────────────────────────────────────────┐
│  0:00 – 0:18    Welcome                                            │
│  ░░░░░░░░░░░░░  Welcomes congregation; comments on the weather     │
├─────────────────────────────────────────────────────────────────────┤
│  0:18 – 0:33    Notices                                            │
│  ░░░░░░░░░░░░░  Sings happy birthday to Kaya and Monica            │
├─────────────────────────────────────────────────────────────────────┤
│  0:33 – 3:10    Notices                                            │
│  ░░░░░░░░░░░░░  Refreshments, evening meeting, house groups,       │
│                 Deacons meeting Fri, Rising Lights promo            │
├─────────────────────────────────────────────────────────────────────┤
│  3:10 – 4:30    Bible Reading — Psalm 2                            │
│  ░░░░░░░░░░░░░  Introduces and reads Psalm 2                      │
├─────────────────────────────────────────────────────────────────────┤
│  ♫ 7:03 – 10:34   Song — "Though the Nations Rage"                │
│  ░░░░░░░░░░░░░  Unmatched · 3m 31s                                │
├─────────────────────────────────────────────────────────────────────┤
│  10:34 – 14:50  Prayer                                             │
│  ░░░░░░░░░░░░░  Opening prayer; thanks for fellowship, church,     │
│                 acknowledges sin, commits the meeting               │
├─────────────────────────────────────────────────────────────────────┤
│  📖 14:50 – 23:53   Children's Talk — Esther                      │
│  ░░░░░░░░░░░░░  Interactive retelling of the book of Esther;       │
│                 points children to Jesus                   ⚠ Review │
├─────────────────────────────────────────────────────────────────────┤
│  🎤 41:40 – 71:04   Sermon                                        │
│  ░░░░░░░░░░░░░  2 Peter 1:12-15 — "Stirred up by reminder"       │
│                 Published → View sermon                             │
├─────────────────────────────────────────────────────────────────────┤
│  ⚠ (planned only)  Bible Reading                                   │
│  ░░░░░░░░░░░░░  Expected from OoS but not detected in recording   │
└─────────────────────────────────────────────────────────────────────┘
```

Note the last entry: `planned_only` items from the existing timeline model are preserved and shown with a warning indicator.

#### Key design decisions

1. **Built from `ServiceRecordTimeline` rows, not raw sections** — the flow view consumes the same merged row data that the existing table uses, preserving planned-only items, mismatch detection, and review state
2. **AI summary as the description line** — use `ai_notes[0]` from section metadata, truncated to ~120 chars. If no AI notes, use a transcript excerpt (first 80 chars)
3. **Section type badge with icon** — each type gets a small icon prefix:
   - Song: `♫`
   - Sermon: `🎤`
   - Children's Talk: `📖`
   - Bible Reading: `📕`
   - Prayer / Welcome / Notices / Other: no icon, just text
4. **Planned/detected context preserved** — for `matched` rows: show both planned title and detected type. For `mismatched` rows: show amber indicator with "Expected X, detected Y". For `planned_only` rows: show as a distinct muted card with "Not detected" indicator. For `unplanned` rows: show as a distinct card with "Not in plan" indicator
5. **Song metadata inline** — show matched song title if available, "Unmatched" badge if not, duration
6. **Sermon metadata inline** — show sermon title, Bible reference, link to published sermon if exists
7. **Review flags** — small amber badge on the right edge for sections needing review, with reason on hover/tooltip
8. **Confidence indicator** — subtle: high confidence = no indicator, low = amber dot, none = red dot
9. **Expandable detail** — click/tap a section to expand and see: full transcript excerpt, confidence details, planned vs detected comparison, metadata, segment IDs
10. **Table view still accessible** — existing table preserved as a collapsible "Detailed alignment table" below the flow

### Changes

#### A. New support class: `ServiceFlowBuilder`

**New file**: `app/Support/ServiceFlowBuilder.php`

Transforms `ServiceRecordTimeline` row arrays (not raw sections) into "flow items" for the Blade template. This class consumes the output of `ServiceRecordTimeline::build()` — the same data the existing table uses.

```php
class ServiceFlowBuilder
{
    /**
     * @param list<array<string, mixed>> $timelineRows Output from ServiceRecordTimeline::build()
     * @param MediaProcessingLog $processingLog For sermon metadata from ai_analysis
     * @return list<array{
     *     section_id: ?int,
     *     row_type: string,
     *     start_time: ?float,
     *     end_time: ?float,
     *     duration_formatted: ?string,
     *     type: ?ServiceSectionType,
     *     type_label: string,
     *     icon: string,
     *     title_suffix: ?string,
     *     description: string,
     *     planned_title: ?string,
     *     planned_context: ?string,
     *     needs_review: bool,
     *     review_reason: ?string,
     *     mismatch_reason: ?string,
     *     confidence_level: ?string,
     *     song_match_type: ?string,
     *     song_title: ?string,
     *     published_sermon: ?Sermon,
     *     metadata: array,
     *     transcript_excerpt: ?string,
     * }>
     */
    public static function build(array $timelineRows, MediaProcessingLog $processingLog): array
}
```

Key logic:
- `icon` mapping: `song → ♫`, `sermon → 🎤`, `childrens_talk → 📖`, `bible_reading → 📕`, others → empty
- `title_suffix`: For songs → matched song title, `song_title_hint`, or nothing. For sermons → AI-generated title from `processing_log.ai_analysis`. For bible readings → reference if in `ai_notes`
- `description`: Look up section metadata from the loaded relation, use `ai_notes[0]` if available, otherwise first 120 chars of transcript excerpt, otherwise contextual default ("Congregational singing" for songs, "No description available" for others)
- `planned_context`: For `matched` rows → "Planned: {planned_title}". For `mismatched` → "Expected {expected_type}, detected {actual_type}". For `planned_only` → "Expected from Order of Service — not detected". For `unplanned` → "Not in Order of Service"
- Rows sorted by `start_time` where available; `planned_only` rows without timestamps appended at end

#### B. Update `ShowChurchService` Livewire component

**File**: `app/Livewire/Admin/ChurchServices/ShowChurchService.php`

Add a `buildServiceFlows()` method that transforms the existing `serviceTimelines` data:

```php
private function buildServiceFlows(
    array $serviceTimelines,
    EloquentCollection $processingRuns
): array {
    return collect($serviceTimelines)
        ->mapWithKeys(fn (array $rows, int $runId): array => [
            $runId => ServiceFlowBuilder::build(
                $rows,
                $processingRuns->find($runId)
            ),
        ])
        ->all();
}
```

Pass `serviceFlows` to the view alongside existing `serviceTimelines`.

#### C. New Blade partial: `service-flow.blade.php`

**New file**: `resources/views/livewire/admin/church-services/partials/service-flow.blade.php`

The vertical card-based layout described above. Uses Alpine.js `x-data` / `x-show` / `x-collapse` for expand/collapse (already available in the project).

Each row type renders differently:
- **`matched`/`unplanned`**: Full card with timestamp, icon, type, description, badges
- **`mismatched`**: Amber-bordered card showing both planned and detected info
- **`planned_only`**: Muted card with "Not detected" badge, no timestamps

#### D. Update `unified-timeline.blade.php`

The service flow partial becomes the primary content. The existing table is preserved below in a collapsible `<details>` element:

```blade
{{-- Primary: human-readable service flow --}}
@include('livewire.admin.church-services.partials.service-flow', [
    'serviceFlow' => $serviceFlow,
])

{{-- Secondary: detailed alignment table (collapsed by default) --}}
<details class="mt-4">
    <summary class="text-sm text-gray-500 cursor-pointer hover:text-gray-700">
        Show detailed alignment table
    </summary>
    <div class="mt-2">
        {{-- existing table markup (unchanged) --}}
    </div>
</details>
```

#### E. Add duration bar visualisation (optional enhancement)

A thin horizontal bar at the top of each section card showing its relative position/duration within the total service. Purely CSS — a `div` with percentage-based `left` and `width` on a gray background track.

This gives a visual sense of "this song was short" vs "this sermon was long" without needing to read the timestamps.

### Data requirements

The `ai_notes` field is already populated by the AI classification. It typically contains 1–3 bullet points summarising each section. This is the primary source for the description line.

For sections classified as `audio_only` (RMS-detected songs and the main sermon), there are no `ai_notes`. For these:
- Songs: show "Congregational singing" + matched title if available + duration
- Main sermon: show the sermon title from `media_processing_logs.ai_analysis`

Section metadata is accessible via the already-loaded `serviceSections` relation on each processing run — no additional queries needed.

### Testing

- Unit test: `ServiceFlowBuilderTest` — verify correct icon mapping, description extraction, section ordering
- Unit test: verify `planned_only` rows are preserved with correct context string
- Unit test: verify `mismatched` rows show both expected and detected types
- Unit test: verify fallback when `ai_notes` is empty (uses transcript excerpt)
- Feature test: render the Livewire component with a processing run; assert the service flow HTML contains expected section descriptions and the detailed table is still present in collapsed state
- Browser test (Dusk): verify expand/collapse works, verify toggle between flow and table view
- Visual check: compare rendered output against the mockup above

---

## Implementation Order

These three features have dependencies and should be built in this order:

### Phase 1: Children's Talk Detection (Feature 1)
- No dependencies on other features
- Prompt changes and post-classification demotion (after fold, not before)
- Can be tested independently with existing processing runs via "Reclassify"

### Phase 2: Song Title Hints + Matching Wiring (Feature 2A + 2B)
- Extract titles from transcript in `ClassifySpeechSections`
- Wire `song_title_hint` into `SongSectionAligner::songCandidatesFromSection()` and `LivestreamSectionToServiceItemMapper::resolveTitle()`
- No new transcription needed — works with existing data
- Quickest win for song matching

### Phase 3: Service Flow View (Feature 3)
- Depends on Feature 1 for correct section types
- Depends on Feature 2 for song title hints in the UI
- New `ServiceFlowBuilder` class (consuming `ServiceRecordTimeline` output) + Blade partial

### Phase 4: Song Lyric Matching (Feature 2C + 2D + 2E)
- Most complex — requires transcribing song segments and matching lyrics
- New `MatchSongsFromTranscript` job with alignment-aware review state management
- Song opening transcription lives here (not in `TranscribeSpeechSegments`) since it needs classification results
- Matches against existing `lyrics_plain` column — no migration needed
- Can be deferred if title hints cover most cases

### Each phase should be:
1. Implemented with tests
2. Validated with `pint --dirty` + `phpstan` + `test --parallel` + `dusk`
3. Tested against the Feb 1 recording by running "Reclassify" from the admin UI

---

## Files to Create

| File | Purpose |
|------|---------|
| `app/Services/SongTitleHintExtractor.php` | Extract song titles from transcript announcements |
| `app/Services/SongLyricsMatchingService.php` | Match song opening lyrics against `lyrics_plain` catalog |
| `app/Jobs/MatchSongsFromTranscript.php` | Pipeline job: fill song gaps + re-run review bookkeeping |
| `app/Support/ServiceFlowBuilder.php` | Transform timeline rows into human-readable flow items |
| `resources/views/livewire/admin/church-services/partials/service-flow.blade.php` | New service flow Blade partial |
| `tests/Unit/Services/SongTitleHintExtractorTest.php` | Unit tests for title extraction |
| `tests/Unit/Services/SongLyricsMatchingServiceTest.php` | Unit tests for lyric matching |
| `tests/Unit/Support/ServiceFlowBuilderTest.php` | Unit tests for flow builder |
| `tests/Feature/Jobs/MatchSongsFromTranscriptTest.php` | Feature test for the new pipeline job |

## Files to Modify

| File | Change |
|------|--------|
| `app/Services/SpeechSectionClassificationService.php` | Enhanced prompt + service context parameter |
| `app/Jobs/ClassifySpeechSections.php` | Pass service context + `demoteSecondarySermons()` **after** fold |
| `app/Services/SongSectionAligner.php` | Add `song_title_hint` to `songCandidatesFromSection()` |
| `app/Services/LivestreamSectionToServiceItemMapper.php` | Use `song_title_hint` in `resolveTitle()` |
| `app/Services/ProcessingPipelineBuilder.php` | Insert `MatchSongsFromTranscript` in chain after `AlignWithOos` |
| `app/Livewire/Admin/ChurchServices/ShowChurchService.php` | Add `serviceFlows` data from timeline rows |
| `resources/views/livewire/admin/church-services/show-church-service.blade.php` | Pass flow data |
| `resources/views/livewire/admin/church-services/partials/unified-timeline.blade.php` | Default to flow, table as details |
| `config/media-processing.php` | New config keys |
| `tests/Unit/Services/SpeechSectionClassificationServiceTest.php` | Test enhanced prompt and demotion |
| `tests/Feature/Jobs/ClassifySpeechSectionsTest.php` | Test secondary sermon demotion + fold ordering |
