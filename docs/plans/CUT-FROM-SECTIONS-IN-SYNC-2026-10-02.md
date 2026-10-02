# Cut What Was Identified, In Sync

**Date:** 2026-10-02 · **Status:** IMPLEMENTED on master; corpus validation and operator acceptance pending · **Blocks:** canary 10
acceptance and Tier A ([main plan §4.0](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md))

## 1. Why

Canary 10's cuts met the predeclared bar as written. The operator then ruled on two findings:

- **Any drift between sound and picture is a defect.** One file being unnoticeable proves nothing
  about the next.
- **A cut is the video for what was identified.** Detection has already found the talk or song.
  A second set of rules at cut time, deciding the times all over again, should not exist.

This plan records the original investigation (§2, §3), the revised implementation (§4) and
the operator's composition decision (§5). The original measurements were read-only; the
revision below does not represent a new media experiment. Evidence is in
`storage/app/private/canary10-20261002/` (`cut-avscan.json`, `cut-gapscan.json`) and
`storage/app/private/cut-rule-census-20261002/census.json`. Scripts are
`storage/scratch/canary10-avscan-20261002.py`, `canary10-srcwindow-20261002.py` and
`cut-rule-census-20261002.php`.

## 2. Sound and picture: four causes, all in our code

A scan of all 73 canary 10 outputs, comparing every audio and video timestamp, found 54 clean.
Every remaining defect traces to one of four causes, and none is in the recordings. The sources
checked (1221, 1108 and 936) are regular where the cuts are not.

| # | Cause | Where it shows | Effect |
|---|---|---|---|
| A1 | **`loudnorm` leaves a hole in the sound's timestamps.** Song videos get a second audio pass (`AudioEnhancementService::enhanceVideo`: noise reduction, `dynaudnorm`, `loudnorm`). When `loudnorm` flushes its 3 s look-ahead, it skips 9–88 ms. | 9 of 21 song videos, always 2.9–3.0 s before the end | The last 3 s of sound runs up to 88 ms late, or a gap is played, depending on the player |
| A2 | **Each span's sound is cut separately from its picture,** to a slightly different length. Two-span sermons (reading, then sermon) glue the spans by picture length. | 9 of 16 sermons, at the reading/sermon join (8–42 ms jump) | A gap or overlap in sound at the join. Whether the rest stays in sync depends on the player |
| A3 | **A smart cut's re-encoded piece starts half a frame late.** The piece seeks half a frame early (so as not to miss the first frame) and encodes on the source's clock, so its first frame is at +17 ms. The join places pieces by declared length and assumes each starts at 0. Whether the offset cancels depends on the encoder's frame reordering. | 1 of 29 smart cuts (936, 59.25 s); reproduced with the live cutter | Picture runs 16 ms behind sound for the rest of the sermon |
| A4 | **Old cuts are reused.** A section's media signature is its type, start and end only (`ServiceSection::mediaSignaturePayload`), not the cutter that made it. A re-run keeps any cut whose span did not change. | 2 of 57 clips in canary 10, both cut 09-27 before the smart-cut fix `9a2e688f1`, both defective. Corpus: 1,244 of 1,314 section clips, 460 of 480 song videos and 440 of 456 sermons predate that fix | Defects fixed in the cutter survive the re-run |

**Why our checks missed them.** `VideoExtractionService::cutIsAligned` compares only where sound
and picture start and how long they run. A shift in the middle passes, and so does anything
added after the cut (A1). The canary's gap scan looked only at picture.

Two things are not defects: the −23 ms first audio packet (encoder pre-roll, hidden by the
container's edit list), and 1050's last frame (it is in the recording).

`loudnorm` is reproduced and fixed in isolation. Adding `asetpts=N/SR/TB` after it gives
continuous sound with the same 8,271 frames and the source's duration (192.052 s against
192.049 s). `aresample=async=1` does the same.

## 3. Cut rules: what the sermon cut does today

Census: the current plan (`SermonExtractionPlanResolver::resolve`, in a rolled-back
transaction) for all 475 runs with a sermon section, 447 of them historic. Every planned second
is classified by what it contains. The local database holds pre-re-run sections, so the counts
describe the rules applied to today's sections, not the outcome of the re-run.

| Route | Runs | What the cut contains beyond the sermon section |
|---|---|---|
| Sections: sermon plus the chosen reading (glued if not adjacent) | 311 | The reading, median 150 s; one run takes 19 s of a song |
| Sections: sermon only | 47 | Nothing beyond the sermon (median 3 s) |
| *All section routes:* sermon end moved "to the next song" | 211 of 358 | Median 5 s, up to 452 s, including time no section covers |
| Old detector, sermon section content-held | 71 | Not cut; the run parks for review (correct) |
| **Old detector, sermon section rejected** | **46** | Median 344 s extra; **26 contain songs (5,271 s)**, 32 contain other readings |

**Why 44 sermon sections were rejected:** 34 for `transcript_repetition_suspect`, a flag about
the *transcript text*; 4 for `structure_sermon_interruption_merged` (canary 10's 1050, 1250 and
1356); and 6 for low confidence or an OoS mismatch. A text flag therefore swaps in a different
detector's times, and those cuts include songs. A further two were rejected as longer than
45 minutes.

The other clips (songs, readings, talks) are already cut at their section's own times
(`PrepareSectionPublicationCandidates`). Only the sermon carries the extra rules.

## 4. Revised design

### Cut rules

- **C1. Sermon media takes an ordered list of accepted sections.** Include the sermon reading
  when separate from the sermon, the sermon (including identified continuation sections), and
  an identified concluding prayer if it occurs before the post-sermon song. Do not duplicate a
  reading or prayer already inside the sermon section. Structure/review identifies membership;
  the cutter neither ranks readings nor infers a prayer from elapsed time. It uses exactly the
  selected sections' boundaries, excluding intervening songs, unrelated sections and uncovered
  gaps. An absent optional reading/prayer does not itself create a review question; uncertainty
  about membership or boundaries belongs to the existing structure/review process.
  **Resolve membership when composing the structure:** use the existing `sermon_reference`
  and `reading_reference` fields and passage normalisation to select an unambiguous separate
  sermon reading. Include identified sermon continuations and the concluding prayer before
  the post-sermon song. A prayer immediately following the sermon is the straightforward
  automatic case; intervening sections or multiple plausible prayers require review of
  membership, not automatic exclusion or inclusion. Missing references or multiple plausible
  readings must not fall through to duration/proximity scoring: where membership is uncertain,
  use the existing review process. Record the ordered selection in existing structure metadata
  and show it in existing review; recompute it when sections change, respecting recorded review
  decisions. This is a deterministic composition step using existing detection fields, not a
  new prompt/schema field. Saved draws remain reusable inputs to recompose; the resulting
  selection still needs validation.
- **C2. Consume the existing review decision; never substitute another detector.** An unresolved
  structure decision or content hold preventing use of a selected section keeps extraction
  parked through the existing review mechanism, including its recorded operator-authority path.
  The cutter only validates usable input: selected sections belong to the source run, have
  finite ordered bounds within the source, and do not overlap or repeat. Invalid input stops
  extraction with a specific reason; it does not select another span. Delete `baselinePlan`
  and the cut-time use of `sermon_start_time`/`sermon_end_time`, confidence thresholds,
  duration ceilings, reading ranking, gap absorption and end extension. Text repair and
  publication holds remain owned by their existing stages, not a new cutter trust policy.
- **C3. Reuse the existing plan and jobs.** Keep the existing ordered-span representation with
  its source section IDs; resolve timestamps from those sections for execution and record them
  in the existing extraction audit. Do not introduce a second persisted composition model,
  resolver hierarchy, queue or review workflow. Individual song, reading and talk clips retain
  their section membership and use the same shared cutter. Sermon audio uses the same selected
  content as its video, preferably extracted from the resulting video as today.
- **C4. Review uncovered speech upstream.** During structure composition/validation, use the
  existing timestamped transcript and segmentation evidence to identify speech outside all
  identified sections that could be lost from the selected sermon content. Raise it through
  the existing structure-review mechanism and correct or explicitly resolve the section
  coverage before cutting the affected output. Gap duration alone is not evidence of speech;
  silence and intentionally excluded, identified songs or other sections do not trigger this
  check. Do not add another detector or fill gaps inside the cutter.

### Sync

- **S1. One encoding path for sound and picture.** Trim each selected span's picture and sound
  together and encode once, using `trim`/`atrim` and the paired concat filter for multiple
  spans. Handle the source clock, segment timestamp origins and frame/sample rounding
  explicitly; concat requires zero-based segments and may pad shorter audio. A shared pass
  reduces the failure surface but is not proof of sync by itself. Delete `smartCutPlan`,
  `writeSmartCut`, the transport-stream join and separate source-audio mux. In canary 10,
  69 of 98 cuts were already full re-encodes. Benchmark representative single- and multi-span
  outputs, including the 29 previously smart-cut spans, against the available processing window
  and existing job timeouts. There is no 15% veto or permanent second cutter: if runtime is
  unacceptable, address encoding settings/capacity or scheduling before batch dispatch.
- **S2. Fix and test enhancement timing.** Reproduce A1 in a regression test, then verify the
  proposed `asetpts=N/SR/TB` correction after the filter chain. Assert sound/picture event
  alignment and preserved audio content through the final seconds, not just continuous packet
  timestamps. Final enhanced media must receive the output checks too.
- **S3. Prove sync with media tests and check each output against its source.** Use small fixtures
  with known simultaneous sound/picture events to test off-keyframe cuts, non-zero source
  timestamps, multiple spans and joins, the last seconds, and enhancement. Include a negative
  control with shifted audio and regular timestamps so the test cannot mistake regularity for
  sync. Derive explicit numerical tolerances from frame/sample resolution and encoder delay;
  they account for representation limits, not permission to introduce cumulative drift.
  For each real output, retain checks for readable required streams, valid timing and expected
  duration, and scan the whole output for unexpected introduced timing discontinuities. Compare
  anomalies with the corresponding selected source intervals, mapping through cuts and joins;
  account for timestamp rebasing, frame/sample rounding, encoder pre-roll and legitimate source
  irregularities. Run the check after cutting and after any enhancement pass, while source
  evidence is available. An unexplained introduced discontinuity blocks acceptance of the
  output with a specific reason; neither constant packet spacing nor exact equality with source
  packets is required. Test the checker against known defective outputs and legitimate source
  irregularities, including 1050's final frame, and measure its runtime with the cutter.
  This catches detectable per-file timing failures; it does not prove content alignment or
  replace the simultaneous-event fixtures. Confirmed processing-induced sync defects fail
  acceptance even when timestamps look regular.
- **S4. Version generated media explicitly.** Add one explicit media-processing version to the
  existing reuse signatures/provenance for affected section clips, song videos and sermons.
  Bump it when cutting or enhancement behaviour changes; do not infer it from Git revisions.
  Include the selected input sections/bounds and relevant output settings in reuse decisions.
  Missing or older versions require regeneration. Canary 10 must regenerate every output;
  recording a new version without regenerating the asset is not sufficient.
  Test that a version change invalidates reuse and document the bump requirement beside the
  cutter and enhancement implementation. Do not pin source-code hashes: harmless edits change
  them while behaviour changes in dependencies or unlisted helpers can escape them.

## 5. Decisions and scope

- **D1 — operator decision, 2026-10-02:** “Sermons should be the sermon reading (if separate
  from the sermon itself), the sermon, and a concluding prayer (if before the post-sermon
  song).” This applies to both sermon audio and video. C1 implements it using named sections,
  without extending the sermon boundary to the song.
- **D2 — review boundary:** keep existing structure/content authority and publication holds;
  remove cut-time reclassification and fallback selection as specified in C2.
- **D3 — implementation direction:** one shared encoding path, with performance measured for
  scheduling and capacity rather than an arbitrary percentage threshold for retaining smart cuts.
- **D4 — separate production follow-up:** already-published weekly media may be affected too.
  A read-only source-aware audit can identify candidates for repair; a timestamp anomaly alone
  is not proof of drift. Production re-cuts/publication are a separate operational action, not
  authorised by this documentation revision and not a prerequisite for implementing the cutter.

## 6. Order

1. Write failing regressions for the known cut/enhancement defects and wrong-span fallbacks.
   Add composition coverage for separate/embedded readings, sermon continuations, a concluding
   prayer before the post-sermon song, excluded prayers after that song, absent optional parts,
   intervening songs/gaps, ambiguous or missing reading references, multiple prayer candidates,
   uncovered speech versus silence, existing holds and invalid bounds. Test the source-aware
   output checker against known failures and legitimate source irregularities, and test version
   invalidation. Reuse existing test suites.
2. Record deterministic section membership and check uncovered speech during composition
   (C1, C4), simplify the resolver and shared cutter (C2–C3, S1), fix enhancement (S2), add
   source-aware output checks (S3), and version reuse (S4). Preserve the same selected content
   in audio, video and saved sermon text. Inventory
   all callers, including weekly and auto-trim processing, so deleting the fallback cannot
   leave a caller silently depending on it. Use the existing review path where accepted
   sections are unavailable. Complete the 1112 matcher fix already required before batch 1.
3. Run the media regressions and normal project gates (focused/full tests, PHPStan and Pint;
   Dusk if browser behaviour changes). Benchmark the completed path and check job timeouts.
   Replay the census to confirm every proposed sermon span comes from its selected sections;
   inspect changed compositions and holds rather than requiring the old cut times to survive.
4. **Canary 10 again** on the same 16 runs, subject to the existing dispatch/custody controls:
   fresh snapshot, saved draws and regenerated Tier C outputs. Require section-exact membership,
   current media versions, resolved membership/coverage questions, passing source-aware output
   checks and no confirmed processing-induced sync defect. Check
   known failure points and actual sound/picture alignment at joins and after enhancement.
5. Accept canary 10 under the existing operator acceptance process, then Tier A. Track the
   already-published weekly-media audit separately (D4).

## 6. Implementation and validation

Implementation commits: `aec30eb0e` first committed the revised plan; `0098afe2c` fixes the
1112 nested ruling; `fbfd9d1cd` delivers C1–C4 and S1–S4. Media processing version is **2**.
The shared cutter has one paired encode path; no smart-cut, TS join or source-audio remux path
remains. Composition owns its review flag, so it cannot clear an upstream boundary doubt.
Reviewed membership is bound to section/evidence identity and invalidated on section changes.

Validation on the settled code: **9,138 tests / 94,233 assertions passed**, PHPStan **0 errors**,
Pint clean, and the frontend build passed. The source-aware regressions include the documented
16 ms picture jump, MP3-to-AAC timing, legitimate source irregularities, simultaneous sound/picture
events, shifted-but-regular audio as a negative control, and the real late `loudnorm` flush defect.
The full Dusk browser gate passes (**61 tests / 150 assertions**), including mobile keyboard
review. The corpus benchmark is being completed; its results belong here before canary acceptance.

The read-only census was replayed over **475 runs**, with **0 resolver errors**, section-exact
selected spans and **0 unsectioned seconds added**. It parks 331 runs for composition or selected
boundary review, preserves 77 content-held runs, and leaves 67 without an extraction blocker.
Evidence: `storage/app/private/cut-rule-census-after-sections-20261002/census.json`.
The original census remains unchanged in `cut-rule-census-20261002/census.json`.

The fresh same-16 canary snapshot is `cut-sections-canary10-20261002/before.json`, on `fbfd9d1cd`,
with membership hash `0bbc51836e06a7d9e9ed7b3edd8c0e185723495670bc4f6e323da78291eb4c2e` and the
existing bound listening routes. The saved-draw dry run reports **16 ready, 0 refused**.
A local database backup was captured before dispatch. Refreshing the external backups requires
explicit approval for the private payload and `/Volumes/Sonnics` destination; no canary job has
been dispatched from this snapshot yet.

The census names membership/coverage questions on **15 of the 16 canary runs** (all except 1117).
These must be settled through the existing review path before Tier C can satisfy the new bar;
no automatic gap filling or release of existing content holds is authorised. Canary acceptance,
Tier A and published weekly-media recuts remain pending under their existing controls.
