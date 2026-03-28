# Livestream Processing Fixes — Service 2566 Analysis

## Background

Processing of service 2566 (25 Jan 2026, morning livestream) revealed four systemic issues. This plan addresses them in dependency order.

---

## Issue 1: Section Ordering is Wrong

**Symptom**: The timeline on the admin page shows songs grouped first (positions 1–5), then speech sections (6–15), rather than chronological order.

**Root Cause**: In `AnalyzeSegments::analyzeWithVisualGuidance()` (line 349), song segments are created first with `segmentOrder` values 0–4 (line 380). Then `fillGapsWithSpeechSegments()` (line 392) starts numbering speech segments from 5+ (line 436). Although the returned `$allSegments` array IS in chronological order (interleaved correctly by start_time at line 421), the `segmentOrder` property on each segment retains the non-chronological assignment.

This propagates because `ServiceSectionClassifier::classify()` orders by `segment_order` first (line 57):
```php
->orderBy('segment_order')
->orderBy('start_time')
```
...putting all songs before all speech. `ClassifySpeechSections` then preserves this wrong ordering when it reassigns `section_order = $index + 1` (line 112).

**Fix**: After `fillGapsWithSpeechSegments()` returns (line 392), reassign `segmentOrder` sequentially based on the array position, which is already in chronological order:

**File**: `app/Jobs/AnalyzeSegments.php` (~line 392)

```php
// After: $segments = $this->fillGapsWithSpeechSegments(...)
foreach ($segments as $index => $segment) {
    $segment->segmentOrder = $index;
}
```

**Tests**:
- Assert stored `LivestreamSegment` records have `segment_order` values matching chronological `start_time` order
- Multi-song test with interleaved speech to verify ordering

---

## Issue 2: Song Lyric Matching via Video Frame OCR

**Symptom**: 4 of 5 songs unmatched. Whisper returns its own system prompt text ("This is a Christian sermon preached at Crockenhill Baptist Church...") instead of actual lyrics when transcribing music-only audio.

**Root Cause**: Whisper hallucinates its conditioning prompt for music-heavy content where speech intelligibility is low. The one song that matched ("Come People of the Risen King") only succeeded because some sung words broke through with 0.619 confidence.

**Proposed Approach**: OCR lyrics from video frames using a cheap vision API model. Songs are projected on screen as large black text on white highlight strips over a blurred background — clearly readable by vision models. This replaces the Whisper transcription path entirely for song matching.

### Design decisions

1. **API-based OCR, not local Tesseract.** The production environment is a 2GB DigitalOcean droplet already running PHP-FPM, Nginx, MySQL, Redis, and FFmpeg during processing. Adding Tesseract (~35–55MB image size, 100–300MB runtime memory) would create memory pressure during the heaviest workload. The API approach adds zero Docker footprint and costs pennies at ~10 calls/week. The OpenAI client and API key are already configured.

2. **Extract frames near the start of the song, not mid-song.** The existing `SongLyricsMatchingService::matchFromLyrics()` compares against the first 200 chars of each song's `lyrics_plain` (line 99–100). By extracting frames at ~10% into the song (past any instrumental intro, still showing opening lyrics), the OCR text aligns with what the matcher expects. No matcher changes needed.

3. **No fallback chain.** If the vision model can't read the lyrics from the frame, accept the song as unmatched for manual review. No Tesseract retry, no Whisper fallback — keep it simple.

4. **Control flow**: The current Whisper path is gated by `transcribe_song_openings` config (line 120 of `MatchSongsFromTranscript`). OCR should be a separate matching strategy tried between title-hint and Whisper transcription, gated by its own config key.

### Implementation

**A. New service**: `app/Services/SongLyricOcrService.php`
- Takes a song section's start/end time and a local video path
- Extracts 1–2 frames near the start of the song (~10% into duration) using existing `FrameExtractionService::extractBaseFrame()` (line 40)
- Sends the frame to `gpt-5.4-mini` vision API with a prompt: *"Read the projected song lyrics visible on screen. Return only the lyrics text, one line per line. If no lyrics are visible, return NONE."*
- Returns the cleaned OCR text or null
- Cleans up extracted frame files

**B. Modify control flow**: `app/Jobs/MatchSongsFromTranscript.php`
- Add a new method `matchSectionFromVideoOcr()` as a third matching strategy
- Insert it in the matching loop (line 136–154) between `matchSectionFromTitleHint` and `matchSectionFromOpeningTranscript`:

```php
foreach ($unmatchedSongs as $section) {
    if ($this->matchSectionFromTitleHint($section, $lyricsMatchingService)) { ... continue; }
    if ($ocrEnabled && $localSourcePath !== null) {
        if ($this->matchSectionFromVideoOcr($section, $localSourcePath, $lyricsMatchingService, $ocrService)) { ... continue; }
    }
    if ($transcribeEnabled && $localSourcePath !== null) {
        if ($this->matchSectionFromOpeningTranscript(...)) { ... }
    }
}
```
- Gated by its own config key: `song_matching.ocr_enabled`
- Uses the same local video path resolution already done for Whisper
- OCR text feeds directly into existing `matchFromLyrics()` — no matcher changes needed

**C. No matcher changes needed.** Since we extract opening-position frames, the OCR text aligns with the existing first-200-chars comparison in `SongLyricsMatchingService`. The `matchFromLyrics()` method works as-is.

**Config additions to `config/media-processing.php`**:
```php
'song_matching' => [
    // existing keys...
    'ocr_enabled' => env('SONG_MATCHING_OCR_ENABLED', true),
    'ocr_model' => env('SONG_MATCHING_OCR_MODEL', 'gpt-5.4-mini'),
],
```

**Tests**:
- Unit `SongLyricOcrServiceTest`: mock OpenAI vision response → verify clean lyrics text returned; no-text frame → returns null; frame cleanup after use
- Unit `MatchSongsFromTranscriptTest`: test three-strategy waterfall (title hint → OCR → Whisper); OCR disabled skips to Whisper

---

## Issue 3: Children's Talk Not Detected

**Symptom**: No `childrens_talk` section identified. Section 79 is a 14-minute "bible_reading" (606–1469s) that likely contains the children's talk. Section 81 confirms it happened: *"The young people go out for their class."*

**Root Cause**: The audio segmentation produced one long speech segment covering the entire gap between songs 2 and 3. The AI classification service (`SpeechSectionClassificationService`) classified this 14-minute block as `bible_reading` without splitting it. The existing `demoteSecondarySermons` logic (line 332 of `ClassifySpeechSections`) only handles cases where the AI classifies multiple sections as `sermon` — it doesn't detect children's talks misclassified as other types.

### Constraints the plan must respect

1. **Existing inference system**: The pipeline already runs through `ProcessingPipelineBuilder` → OOS alignment → `SectionAlignmentBaselineRestorer` (line 22), which recalculates review ownership each pass. The `OOS_REVIEW_FLAGS` array (lines 22–28) already includes `'inferred_childrens_talk'` and `'ambiguous_childrens_talk'`. Any new children's talk detection must **extend the existing OOS alignment system** rather than create a parallel one. Specifically, dismissal markers should set flags that `SectionAlignmentBaselineRestorer` already knows how to manage.

2. **Positional context not currently in the prompt**: The user prompt at `buildUserPrompt()` (line 359) only sends segment duration, current coarse type, sermon context, and transcript. It does NOT include position within the service or time offset. The "better positional context from Issue 1 fix" claim requires actually adding position data to the prompt — it won't happen automatically.

### Implementation

**A. Add positional context to the AI prompt**

**File**: `app/Services/SpeechSectionClassificationService.php` — `buildUserPrompt()` (line 359)

Add to the prompt lines:
- Section start/end time within the overall recording (available from `$section->start_time` / `$section->end_time`)
- Section position (e.g. "This is speech section 3 of 5")
- Duration hint for long sections: *"This segment is N minutes long. Consider whether it contains a children's talk sub-section."*

This gives the AI actual positional information it currently lacks.

**B. Extend OOS alignment with dismissal-marker inference**

**File**: `app/Services/OosAlignmentService.php` (or the appropriate alignment step)

Rather than adding post-classification logic in `ClassifySpeechSections`, add children's talk inference to the OOS alignment pass, where `inferred_childrens_talk` flags are already defined:
- After structural alignment, scan section transcripts for dismissal phrases ("young people go out", "children can go to their class/group")
- When a dismissal marker is found, check whether the preceding section is long (>5 min) and typed as `bible_reading` or `other`
- If so, set `review_flag: 'inferred_childrens_talk'` and `needs_manual_review: true` on the preceding section
- This integrates naturally with `SectionAlignmentBaselineRestorer`, which already clears and rebuilds `inferred_childrens_talk` flags each pass

**Tests**:
- Unit: 14-minute `bible_reading` section followed by `other` section containing dismissal text → verify `inferred_childrens_talk` flag set on the long section
- Unit: short `bible_reading` (<5 min) followed by dismissal → verify flag NOT set
- Verify `SectionAlignmentBaselineRestorer` properly clears and re-applies the flag on re-run

---

## Issue 4: Musical Intro/Outro Classified as Songs

**Symptom**: Section 69 (0–103s) is the musical intro, not a congregational song. Section 83 (4129–4142s, 13 seconds) is the outro. Both are classified as songs.

**Root Cause**: The visual analysis detects bright frames (title slides, countdown slides) with similar characteristics to projected lyrics. The 60s minimum duration filter in `SongClusteringService` catches very short bursts but allows the 103-second intro. The 13-second outro should have been filtered but apparently wasn't (possibly created through a different code path after boundary refinement).

### Constraints the plan must respect

**Reclassifying songs to `other` in `ServiceSectionClassifier` is dangerous.** `MatchSongsFromTranscript` (line 102) only processes `section_type = SONG` sections. `SongSectionAligner` (line 48) filters to `section_type === SONG`. `StructuralSectionAligner` (line 43) explicitly excludes songs. If a legitimate opening song were wrongly reclassified to `other`, it would become invisible to song matching and alignment — an unrecoverable error at that stage.

### Implementation — late-stage metadata flagging, not early reclassification

Instead of reclassifying before alignment, add detection **after** song matching and OOS alignment have run. At that point, unmatched songs in intro/outro positions are safe to reclassify because alignment has already completed.

**A. Post-alignment intro/outro reclassification**

**File**: `app/Jobs/PrepareSectionPublicationCandidates.php` (or a new step after `MatchSongsFromTranscript`)

After `AlignWithOos` and `MatchSongsFromTranscript` have run, apply heuristics:
- First song section that starts within 120s of recording start AND remains `unmatched` after all matching → reclassify to `other` with `metadata.review_reason = 'possible_musical_intro'`
- Last song section that ends within 30s of recording end AND is shorter than 60s AND remains `unmatched` → reclassify to `other` with `metadata.review_reason = 'possible_musical_outro'`
- **Only reclassify `unmatched` songs** — if the intro actually matched a song in the catalog, leave it as a song

This preserves the song pipeline's ability to process all songs, and only reclassifies those that went through matching without finding a hit.

**B. Post-refinement duration filter for the 13s outro**

**File**: `app/Jobs/PerformVisualAnalysis.php`

The 13-second outro segment should have been caught by the 60s minimum filter. Investigate whether boundary refinement in `AnalyzeSegments` is creating sub-60s segments after the filter ran. If so, add a post-refinement duration check that drops segments below `MIN_SONG_DURATION`.

**Config**: `config/media-processing.php`
```php
'section_classification' => [
    // existing keys...
    'intro_max_start_seconds' => 120,
    'outro_min_remaining_seconds' => 30,
    'outro_max_duration_seconds' => 60,
],
```

**Tests**:
- Unit: unmatched first song at 0.0 → reclassified to `other` with intro flag
- Unit: matched first song at 0.0 → left as song (not reclassified)
- Unit: unmatched last song, short, near end → reclassified to `other` with outro flag
- Edge case: legitimate opening worship song starting at 300s → NOT reclassified

---

## Implementation Order

| Phase | Issue | Rationale |
|-------|-------|-----------|
| 1 | **Section Ordering** | Simplest fix; affects all downstream processing. Also requires a one-off repair for service 2566's stored `segment_order` values. |
| 2 | **Intro/Outro** | Must run after alignment, so safe. Reduces false positives in the song list. |
| 3 | **Children's Talk** | Benefits from correct ordering (Phase 1) and extends existing OOS alignment flags. |
| 4 | **Song Lyric OCR** | Most complex (new service, matcher changes, vision API). Independent but benefits from Phase 1. |

### Repairing service 2566

After all phases are implemented, service 2566 needs reprocessing:
1. Fix stored `segment_order` on `livestream_segments` (tinker or one-off command)
2. Trigger `buildSectionReclassificationChainJobs()` which starts at `ClassifyServiceSections` and runs the full downstream chain including the new intro/outro, children's talk, and OCR logic
