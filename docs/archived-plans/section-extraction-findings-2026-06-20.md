> **Archived 2026-07-05.** Findings from the June 2026 section-extraction regression runs. All items were fixed or consciously parked via the (archived) SERMON-SECTION-EXTRACTION-REMAINING-FIXES-2026-06-21.md plan. Kept as reference for the behaviours the test harness (docs/operations/section-extraction-testing.md) exercises.

# Section Extraction — Findings (2026-06-20)

Findings from section-extraction regression testing across nine real Sunday recordings (May 2026 down to April 2023), covering content-anchor classifier behaviour, RMS/visual analysis limitations, song matching, OoS alignment, and sermon extraction.

---

## F1 — Adjacent bible_reading sections not merged when reader pauses mid-reading

**Observed:** 24 May 2026, sections 5 & 6.

The classifier correctly identifies Joshua 1:1-9 as a bible reading, but because the preacher paused between verses, it emits two adjacent `bible_reading` sections with different reference slices (Joshua 1:1-4 and Joshua 1:4-9). A `reading_reference_conflict` flag also fires on section 5 because its AI-derived reference (1:1-4) differs from the OoS entry (1:1-9).

**Expected behaviour:** Adjacent `bible_reading` sections whose time boundaries are contiguous (or nearly so) should be merged into a single section. The OoS reference should take precedence over the per-slice AI reference when they cover the same passage.

**Where to look:** `ClassifySpeechSections` — the `mergeAdjacentSameTypeSections` method (line 614) skips merges when both sections are ≥ 30 seconds long, unless the type is `childrens_talk`. The adjacent Joshua sections are each > 60 s, so they pass the type check but fail the duration guard. Relax the duration condition for `bible_reading` sections — two adjacent readings are almost always a continuation of one passage, never two unrelated passages back-to-back. The `reading_reference_conflict` flag resolution may also need attention in `ResolveReadingReferences`.

---

## F2 — Children's-talk publication was not exercised by the regression harness

**Observed:** 14 June 2026, section 6 (`childrens_talk`, 9:08–18:16).

The content-anchor classifier correctly identifies the children's talk as a distinct `childrens_talk` section. The local regression runner then stops after `ExtractSermon`, with `status=processing / current_step=extraction_complete`.

The runner does not execute `PrepareSectionPublicationCandidates`, approval, or `PublishApprovedServiceSection`. The absence of a children's-talk `Sermon` record is therefore expected and is not evidence of a production extraction defect.

**Follow-up test:** Exercise the full section-publication workflow separately and verify that the children’s-talk section receives extracted candidate media, enters approval, and creates a `Sermon` with `content_type=childrens_talk` after approval.

---

## F3 — Non-adjacent bible+sermon concat uses a reading >30 min before the sermon

**Observed:** 14 June 2026 extraction metadata: strategy `non_adjacent_bible_plus_sermon_concat` paired section 2 (bible_reading, 0:55–3:08) with section 15 (sermon, 40:20–68:48). The gap between the reading and the sermon is approximately 37 minutes.

**Expected behaviour:** The `non_adjacent_bible_plus_sermon_concat` strategy should only pair a bible reading with the sermon when the reading is reasonably close in time — a reading from the opening notices block, 37 minutes before the sermon, is almost certainly not the text being preached from. A maximum gap threshold (e.g. 15 minutes) would prevent this.

**Where to look:** `SermonExtractionPlanResolver` — add a maximum gap guard before returning the `non_adjacent_bible_plus_sermon_concat` plan.

---

---

## F4 — ~~Heidelberg Catechism study misclassified as `childrens_talk`~~ (Retracted)

**Retracted:** The Catechism study (21 January 2024, section 11) is genuinely structured as a children's talk slot in this service — the `childrens_talk` label is correct. Not a pipeline defect.

---

## F5 — `non_adjacent_bible_plus_sermon_concat` consistently selects the earliest reading, not the closest

**Observed:** Scenarios C and D (and previously noted in F3 for Scenario B).

| Scenario | Reading selected | Distance before sermon | Closer reading passed over | Distance |
|---|---|---|---|---|
| B (Jun 14) | Section 2 (0:55–3:08) | ~37 min | — | — |
| C (Apr 23) | Revelation 1 (5:50–6:40) | ~29 min | Jude 1:1-7 (29:13–31:37) | ~4 min |
| D (Jan 24) | Romans 8 (5:29–6:52) | ~26 min | Colossians 1:15-23 (20:06–22:16) | ~10 min |
| F (Mar 25) | Revelation reading (4:30–6:30) | ~31 min | Luke reading (25:22–27:02) | ~11 min |
| H (Dec 25) | 60s preamble (5:48–6:48) | ~31 min | Substantive reading (23:01–26:11) | ~11 min |

In each case the strategy pairs the first `bible_reading` section in the service rather than the one immediately or most recently before the sermon. The reading closest to the sermon is overwhelmingly more likely to be the preached text.

**Expected behaviour:** When multiple `bible_reading` sections exist, `non_adjacent_bible_plus_sermon_concat` should prefer the one with the smallest gap before the sermon start, subject to the existing proximity threshold from F3.

**Where to look:** `SermonExtractionPlanResolver::findPreferredSection()` — the query that selects the bible reading uses `->orderBy('section_order')->orderByDesc('duration')`, which always returns the first section in order. Replace or supplement this with an additional ordering that minimises the gap between the reading's `end_time` and the sermon's `start_time`.

Note: Scenario G (28 September 2025) appears to select the correct reading (Luke 23, section 12) rather than the earlier Nehemiah reading (section 3). This is coincidental — section 3 carries `conf=0.72` and `needs_manual_review=true`, so it is excluded by the query's `.where('needs_manual_review', false)` and confidence threshold filters. If section 3 had been classified at higher confidence, the wrong reading would have been selected again. The "closest reading" behaviour in G is a filter side-effect, not correct algorithm design.

---

## F6 — ~~Short pre-sermon intro block absorbed into adjacent section (Scenario C)~~ (Retracted)

**Retracted:** Gemini's order of service was wrong, not the pipeline. The Scenario C children's talk (30 April 2023) included its own Bible reading (John 3:14-18, 15:43–16:41) and a closing "Look and Live" application block (16:43–18:59). These are all part of the same children's talk — the pipeline correctly kept them together. The "Sermon Part 1" label in Gemini's OoS was a mis-segmentation.

---

## F7 — Sub-second / multi-second micro-sections created for OoS slide transitions

**Observed:** 21 January 2024, sections 2 (0:11–0:13, **2 seconds**, aligned to OoS "Notices" slide) and 8 (8:36–8:39, **3 seconds**, aligned to OoS "Reading" slide).

The content-anchor classifier emits a section break at each detected anchor point, even when the anchor fires at an effectively instantaneous slide cue with no substantive speech. A 2-second or 3-second section cannot carry meaningful media and adds noise to the section list.

**Expected behaviour:** Sections below a minimum duration threshold (e.g. 10–15 seconds) should either be merged into the adjacent section or suppressed, unless the OoS item itself requires a discrete section record.

**Where to look:** `ClassifySpeechSections` or the post-classification merge pass in `ServiceSectionSyncService` — apply a minimum-duration guard after splitting, before writing section records.

---

## F8 — Unmatched opening song (Scenario D, recording started late)

**Observed:** 21 January 2024, section 3 (0:13–3:27, unmatched).

A ~3-minute song appears at the very start of the recording and is not mentioned in Gemini's order of service. The pipeline has no OoS anchor to match against.

**Expected behaviour:** No match is the correct outcome for this case — the OoS omission is the root cause, not a matching failure. Lower priority. 

---

## F9 — Closing song "Oh Jesus I Have Promised" unmatched despite clear transcript (Scenario D)

**Observed:** 21 January 2024, section 20 (64:34–66:19, unmatched).

The closing song is clearly identified in the transcript ("Oh, Jesus, I ha...") and is listed in Gemini's order of service. It was not matched to a song database entry, most likely because the database entry uses a different title form (e.g. "O Jesus I Have Promised" or "Oh Jesus, I Have Promised").

**Expected behaviour:** The song should be found via fuzzy title matching or transcript matching regardless of the specific "O"/"Oh" variant or punctuation difference.

**Where to look:** `MatchSongsFromTranscript` — check whether "Oh Jesus I Have Promised" exists in the song database under an alternate title, and whether the normalisation step strips leading "O"/"Oh" variants before comparing.

---

## Context

- These findings were produced by re-running `ClassifySpeechSections` and downstream jobs against stored transcripts using `scripts/section-extraction/run-downstream.php`.
- The runner now restores a saved post-transcription baseline before every classifier pass. Earlier runs reused already-rewritten sections and were not reliable classifier comparisons.
- The content-anchor classifier (`SpeechSectionClassificationService` — commit fc0777b) is otherwise working correctly: it surfaces previously hidden sections and correctly identifies the children's talk.
- The old `manual_review_required` outcome for the 14 June service was a downstream effect of the time-ratio classifier's failure; it is no longer triggered and is not a regression.

---

*Scenarios E–I observed on 2026-06-21 using commit `fc0777b` and classifier model `gpt-5`.*

---

## F10 — RMS analysis fails to segment a 65-minute speech block containing multiple songs (Scenario E)

**Observed:** 17 November 2024.

`GenerateRmsLog` and `PerformVisualAnalysis` produced only three segments across the entire 68-minute recording: a single 65-minute speech block (`rms=-50`, `vis=-`), a two-minute closing song, and a 45-second speech tail. All songs, prayers, readings, and the children's talk that Gemini's notes (and the OoS) place in the first 65 minutes were invisible to the RMS/visual analyser.

`ClassifyServiceSections` then assigned `sermon` at `conf=0.9` to the 65-minute block. Because `ClassifySpeechSections` deliberately skips pre-classified `sermon` sections, the downstream pipeline produced only three sections for the full service. Five songs were expected; only one was matched.

The exact cause is unclear — the recording may have had lower congregational volume than the RMS threshold, or the November 2024 recording setup may have predated the visual overlay transitions that `PerformVisualAnalysis` relies on. The `vis=-` result on a 65-minute block strongly suggests the visual analyser found no slide or scene changes, which is a separate failure from the RMS amplitude threshold.

**Expected behaviour:** When `PerformVisualAnalysis` produces no transitions across a very long segment (e.g. > 20 minutes), the pipeline should not silently treat it as a single continuous section. It should either flag the run for manual review or force the downstream classifier to attempt a split regardless of the initial `sermon` label.

**Where to look:** `SermonCandidateConfidenceService` — the `evaluate()` method filters speech segments that are ≥ 1200 s (20 min) and selects the dominant one; there is no upper bound on candidate duration. In Scenario E, the 3916 s block is the only qualifying segment and the 1.5× ratio guard never fires (no competing segment), so it becomes a high-confidence sermon candidate regardless of its 65-minute length. Adding a maximum candidate duration (e.g. 2700 s / 45 min) would reject it and force the run to manual review rather than silently extracting the wrong content. `ClassifySpeechSections` — consider re-entering a block pre-labelled `sermon` when its duration exceeds a plausible single-sermon ceiling.

**Note:** Gemini's comparison notes do not include an entry for the 17 November 2024 service. The expected structure (five songs, Luke 15 reading, guest preacher) comes only from the review questions in the testing guide and the imported OoS; there is no Gemini ground-truth to compare against for this scenario.

---

## F11 — Heidelberg Catechism study misclassified as `childrens_talk` in a service where it is not a children's slot (Scenario F)

**Observed:** 16 March 2025, section 5 (`childrens_talk`, 14:30–19:30).

Gemini's OoS labels this slot "Heidelberg Catechism Reflection (Lord's Prayer)". Unlike the January 2024 service (finding F4, retracted) where the Catechism study was genuinely structured as a children's slot, the March 2025 service has a distinct adult-format Catechism block placed between two corporate prayer sections. The classifier nonetheless assigned `childrens_talk`.

This is distinct from F4: in January 2024 the Catechism replaced the children's talk slot and was labelled accordingly in the OoS. In March 2025 there is no children's talk in the service at all, but the classifier still emits `childrens_talk`. The pipeline also reports `expected_childrens_talks=null` for this scenario, so the stable-assertion harness will not catch this automatically.

**Expected behaviour:** The classifier should distinguish a structured adult catechism study from a children's talk. Signals that could help: age-appropriate vocabulary, Q&A format with theological depth, absence of the "children going out" or "come back to us" phrasing typical of children's talks.

**Where to look:** `SpeechSectionClassificationService` — classifier prompt or post-processing rules that gate `childrens_talk` classification. Consider requiring a minimum set of structural cues (children leaving, parent-addressed language) before assigning this label.

---

## F12 — Closing benediction incorrectly resolved to Luke 18:9-14 via transcript AI (Scenario F)

**Observed:** 16 March 2025, section 18 (`bible_reading`, 67:28–67:56, 28 seconds).

The section appears at the very end of the recording and carries `align=content_anchor → "Luke 18:9-14"` with `READING=Luke 18:9-14(transcript_ai)`. The transcript excerpt is `"The grace of our Lord Jesus Christ be with you all"` — a standard Pauline benediction, not Luke 18. The AI reference resolver incorrectly identified this closing blessing as a reading of Luke 18:9-14, presumably because Luke 18 was the day's preached text and appeared earlier in the OoS.

`reading_reference_conflict` is not set, which means the resolver accepted the spurious reference without contradiction. The section is also 28 seconds long — below the minimum-duration threshold suggested in F7.

**Expected behaviour:** The reference resolver should not match a benediction-formula phrase to a passage reference. A short section at the very end of a service that contains a known benediction form ("The grace of our Lord…", "Now to him who is able…", "May the Lord bless you…") should not be classified as a scripture reading at all, or at minimum should emit a low-confidence flag.

**Where to look:** `ResolveReadingReferences` — add a benediction-pattern guard. Also note that the `reading_reference_conflict` flag failed to fire because the OoS item (Luke 18:9-14) was the same reference the AI produced, creating a false appearance of agreement rather than a genuine match.

---

## F13 — Same song listed twice in OoS matched to wrong occurrence (Scenario E)

**Observed:** 17 November 2024, section 2 (`song`, confirmed "All I Have Is Christ" [song#58], 65:16–67:37), aligned to OoS item 4091.

The service played "All I Have Is Christ" twice: once before the sermon (OoS item 4091, position 2) and once after (OoS item 4097, position 8). The detected song section at 65:16–67:37 occurs well after the 65-minute "sermon" block and therefore corresponds to the post-sermon occurrence (item 4097). The pipeline matched it to the pre-sermon item 4091 instead, presumably because 4091 is the first OoS item sharing `song_id=58`.

**Expected behaviour:** When a song appears multiple times in the OoS, the matching logic should prefer the OoS item whose position in the service order is consistent with the section's time offset. The pre-sermon occurrence (position 2 of 9) cannot plausibly be at 65 minutes into a 68-minute recording.

**Where to look:** `MatchSongsFromTranscript` — the song-to-OoS-item disambiguation step when multiple items share the same `song_id`. Add a temporal ordering guard: prefer the OoS item whose relative position in the OoS matches the section's relative position in the timeline.

---

## F14 — `reading_reference_conflict` not raised when transcript content contradicts the AI-resolved reference (Scenario E)

**Observed:** 17 November 2024, section 3 (`bible_reading`, 67:37–68:22).

The section is aligned to OoS item 4096 ("Luke 15:1-32", bibles type). The AI reference resolver (`transcript_ai`) also reports `READING=Luke 15:1-32`, so the flag does not fire — both sources agree on Luke 15. However, the transcript excerpt reads "We are therefore Christ's ambassadors, as though…", which is 2 Corinthians 5:20, not Luke 15. The AI likely inferred Luke 15 from the proximity of the "Luke 15:1-32" OoS item rather than independently reading the text.

This is the inverse of the normal `reading_reference_conflict` scenario: instead of catching disagreement between two sources, the flag is suppressed by a false agreement. The pipeline has no mechanism to notice that the winning reference is inconsistent with the actual transcript text.

**Expected behaviour:** The resolver should emit a low-confidence warning (or a new flag such as `reading_reference_anchor_mismatch`) when the OoS-influenced AI resolution does not agree with a direct content scan of the transcript text.

**Where to look:** `ResolveReadingReferences` — consider a second-pass validation that verifies the resolved scripture reference is actually present in the transcript text, rather than accepting the AI's answer uncritically when it matches the OoS item title.

**Note:** This finding is partly a consequence of F10 (the whole Scenario E is structurally compromised). The section at 67:37–68:22 appears to be a closing remark, not the Luke 15 reading. Interpreting it in isolation is unreliable.

---

## F15 — Presentation-type OoS items not aligned when no matching audio anchor is found (Scenario G)

**Observed:** 28 September 2025. OoS items 4681 ("2025-0928 Andrew Talk.pptx"), 4683 ("epap.pptx"), and 4684 ("Reading") were not aligned to any pipeline section (no `item=XXXX` in the output). The three items cover the children's talk, a harvest appeal/prayer block, and the Luke 23 reading respectively.

Item 4681 (Andrew Talk.pptx) corresponds to section 7 (`childrens_talk`, 10:50–22:20), but the alignment didn't fire. Items 4683 (epap.pptx) and 4684 (Reading) correspond to sections 10 and 12, also without alignment.

The items that did align (notices, songs) are those whose slide content is directly mentioned in the speech transcript around the time of the slide change, giving the content-anchor classifier a clear text signal. "Andrew Talk.pptx", "epap.pptx", and "Reading" are generic slide identifiers with no distinctive spoken text that would trigger an anchor.

**Expected behaviour:** Presentation-type OoS items that cannot be content-anchored should still receive a positional alignment if they can be inferred from context (e.g. the Andrew Talk slide fires at the same moment as the children's talk section boundary). A fallback positional alignment — "assign the nearest unmatched OoS item of the appropriate type" — would improve traceability without requiring audio anchors.

**Where to look:** `AlignWithOos` — the fallback alignment pass for non-song, non-notices OoS items.

---

## F16 — Song split across RMS segment boundary leaves one half unmatched (Scenarios F, H)

**Observed:** 16 March 2025 and 28 December 2025. Scenario F: sections 7 (`song`, confirmed "Let Your Kingdom Come", 21:30–22:37) and 8 (`song`, unmatched, 22:37–24:42).

The RMS analyser placed a boundary at 22:37 that bisects "Let Your Kingdom Come" (Gemini: 21:04–24:34). The classifier split recognises the first part (21:30–22:37, within the speech segment) and confirms it against the OoS, but the second part (22:37–24:42, the RMS-detected song segment) remains unmatched at `conf=0.3`. Together the two halves span 21:30–24:42, consistent with Gemini's timing.

Scenario H (28 December 2025) shows two independent instances of the same pattern: "Come, adore the humble King" is split between section 14 (32:21–33:36, in-speech portion, matched from transcript) and section 15 (33:36–37:35, step2 song segment, unmatched), and "In A Stable Long Ago" is split between section 10 (19:24–19:55, 31s, in-speech tail) and section 11 (19:55–23:01, step2 song segment, unmatched). In both H instances the in-speech portion is matched but the RMS song segment is unmatched due to having no transcript.

This is structurally similar to F1 (adjacent bible_reading sections not merged), but for songs: two adjacent `song` sections that form a single continuous piece.

**Expected behaviour:** Adjacent `song` sections with contiguous boundaries should be merged when one is a confirmed match and the adjacent segment's audio characteristics are consistent with a continuation. The `mergeAdjacentSameTypeSections` pass referenced in F1 could apply here.

**Where to look:** `ClassifySpeechSections` — the `mergeAdjacentSameTypeSections` method (line 614) skips the merge here because both sections exceed the 30-second minimum duration threshold (67 s and 125 s respectively). The guard prevents legitimate distinct sections from being collapsed, but it is too conservative for songs: two adjacent `song` sections with contiguous boundaries are almost never two unrelated pieces. Relax the duration condition for `song` sections, or add a separate post-match merge that collapses adjacent song sections when one carries a confirmed OoS match and the other's audio classification is consistent with a continuation.

---

## F17 — Custom "Reading" OoS item with no bibles-type passage title leaves bible_reading sections without a scripture annotation (Scenario H)

**Observed:** 28 December 2025. The OoS contains only a custom `"Reading"` item (type `custom/other`) and no bibles-type item (which would carry an explicit passage title). Two sections are classified as `bible_reading` (section 5 at 5:48–6:48 and section 12 at 23:01–26:11), but neither carries a `READING=` annotation in the output. Transcript AI did not resolve a scripture reference from either section's full transcript.

The OoS "Reading" item (4828) is aligned by `AlignWithOos` to section 2 (0:18–1:48), which is pre-service background audio reclassified as `other`. It is not aligned to either actual `bible_reading` section. Both reading sections therefore enter `ResolveReadingReferences` with no OoS passage hint, and transcript AI fails to extract a confident scripture citation.

As a consequence, the extracted sermon has no associated scripture passage. The extraction planner also compounds this with F5: it selects section 5 (earliest, 60 seconds — almost certainly a transitional phrase such as "I'm going to turn our attention now to...") rather than section 12 (23:01–26:11, 3.2 minutes, the substantive reading closer to the sermon). The 60-second preamble section is a poor candidate for a paired reading regardless of passage resolution.

**Expected behaviour:** When no bibles-type OoS item exists and transcript AI cannot extract a reference, the pipeline should flag the absence (e.g. `reading_reference_missing`) rather than silently omitting the annotation. A bibles-type item is the more reliable source; without one, a fallback should note the resolution gap so an operator can fill it in manually.

**Where to look:** `ResolveReadingReferences` — add a `reading_reference_missing` flag when a `bible_reading` section completes without a `READING=` annotation and no bibles-type OoS item was available as a hint. Also consider `SermonExtractionPlanResolver` for a minimum reading duration threshold that would exclude 60-second preamble sections from the candidate pool.

---

## F18 — Song name mentioned verbally in a sermon introduction is spuriously matched to an OoS song item (Scenario I)

**Observed:** 22 March 2026, section 9 (31:38–32:35, 57 seconds).

After the bible reading and a prayer, the preacher introduces "Purify My Heart" with the words "Our next song picks up one of the themes in our..." During this spoken introduction the name "O God Beyond All Praising" is mentioned as a thematic reference. `MatchSongsFromTranscript` detects the mention, finds OoS item 4959 ("O God Beyond All Praising #187", not yet matched), and creates an `inferred` match against section 9 with `flags=song_alignment_inferred,unmatched_song_section` and `conf=0.74`.

The inferred match is incorrect: "O God Beyond All Praising" was not performed in section 9. The 57-second window is the preacher's spoken introduction to the song that follows (section 10, "Purify My Heart"), not a performance of "O God Beyond All Praising" itself. As a side effect, "O God Beyond All Praising" is marked as matched (albeit inferred), and its actual position in the service (likely the opening song block) goes undocumented.

The pipeline correctly flags the uncertainty with `[review]` status and `conf=0.74`, which prevents automatic publication and surfaces the section for operator review. The incorrect match is therefore contained rather than silently applied.

**Expected behaviour:** Song name detection in transcript text should distinguish between a section that *performs* a song and one that merely *mentions* a song title in passing or as a thematic reference. Candidates with `song_alignment_inferred` and a detected song name in the preacher's spoken text (not in a lyrical pattern) warrant a lower confidence ceiling — perhaps `conf < 0.65` — and should never consume an unmatched OoS item unless corroborated by audio energy at the section boundary.

**Where to look:** `MatchSongsFromTranscript` — when a transcript section already carries a type other than `song` from the classifier (here it was created as a song sub-section within a speech block), but the evidence is a song-name mention in a spoken-sentence pattern ("our next song...", "we sang..."), apply a penalty that keeps confidence below the `[review]` threshold and does not consume the OoS item. Consider a `song_name_reference_only` flag distinct from `song_alignment_inferred`.
