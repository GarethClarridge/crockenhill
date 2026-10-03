# Cut What Was Identified, In Sync

**Date:** 2026-10-02 · **Status:** IMPLEMENTED on master; corpus benchmark passed, canary review and operator acceptance pending · **Blocks:** canary 10
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
  identified sections **strictly between consecutive selected parts of the same item**:
  for example, the sermon and an identified continuation across an interruption. Reading →
  sermon and sermon → concluding prayer join different items; D1 deliberately excludes their
  intervening speech, so these gaps are not composition findings. Speech before the first selected section or
  after the last is not a composition finding; the ensemble owns those outer edges through
  talk-edge checks (decision 21) and answered rulings. Raise interior findings through
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
- **D4 — separate production follow-up:** already-published weekly clips have the same
  section-edge defect: weekly processing uses the same floored cue display and refinement
  path. The exact-cue fixes and shared-cue cut widening below apply to future weekly processing too. Published weekly
  media may also have the sync defects described above.
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
1112 nested ruling; `fbfd9d1cd` delivers C1–C4 and S1–S4. The corpus benchmark exposed an
unindexed WebM whose container omits duration; `cf8a44371` measures its packet extent instead,
with synthetic and real-WebM regressions. Media processing version is **2**.
The shared cutter has one paired encode path; no smart-cut, TS join or source-audio remux path
remains. Composition owns its review flag, so it cannot clear an upstream boundary doubt.
Reviewed membership is bound to section/evidence identity and invalidated on section changes.

Validation on the settled code: **9,140 tests / 94,239 assertions passed**, PHPStan **0 errors**,
Pint clean, and the frontend build passed. The source-aware regressions include the documented
16 ms picture jump, MP3-to-AAC timing, legitimate source irregularities, simultaneous sound/picture
events, shifted-but-regular audio as a negative control, and the real late `loudnorm` flush defect.
The full Dusk browser gate passes (**61 tests / 150 assertions**), including mobile keyboard
review.

The private corpus benchmark passed for **27 selected output assets**, plus an isolated repeat
of run 936's known smart-cut defect span. It covers all 24 current outputs from formerly-smart
sources and three representative multi-span/high-bitrate/VP9 sermons. Those 24 outputs contain
**28 current logical source spans**; this does not claim reconciliation of the investigation's
historical count of 29. The extra isolated 936 check repeats a span already covered.
The first attempt's WebM failure is retained alongside its successful post-fix retry.
Evidence: `storage/app/private/cut-sections-benchmark-20261002/{manifest,report,retry-failed-report,summary}.json`.

At the configured `veryfast` preset, the longest active encode plus timing check was **470.9 s**
and maximum observed PHP peak memory was **560.4 MB**. Run 1028's 549.0 s deliberate process
pause is retained in the raw report and excluded from active timing (its pause receipt has a
stale asset ID; run, kind and process identify the paused sermon). Candidate encode/check totals
per sampled run were at most **162.9 s**. These measurements leave headroom under the existing
3,600 s extraction and 1,800 s candidate job limits and 2,048 MB memory limit; they measure the
cut/check operation, not the entire pipeline. No timeout or memory settings were increased.
Temporary benchmark outputs were removed; source files and published asset rows were untouched.

The read-only census was replayed over **475 runs**, with **0 resolver errors**, section-exact
selected spans and **0 unsectioned seconds added**. It parks 331 runs for composition or selected
boundary review, preserves 77 content-held runs, and leaves 67 without an extraction blocker.
Evidence: `storage/app/private/cut-rule-census-after-sections-20261002/census.json`.
The original census remains unchanged in `cut-rule-census-20261002/census.json`.

The fresh same-16 canary snapshot is `cut-sections-canary10-20261002/before.json`, on `fbfd9d1cd`,
with membership hash `0bbc51836e06a7d9e9ed7b3edd8c0e185723495670bc4f6e323da78291eb4c2e` and the
existing bound listening routes. The saved-draw dry run reports **16 ready, 0 refused**.
The post-WebM-fix snapshot is `cut-sections-canary10-20261002/before-webm-fix.json` on
`cf8a44371`, with the same membership and **16 ready, 0 refused** in its dry run. All four
historic worker lanes were restarted after that code commit while queues were empty.
A local database backup was captured before dispatch. After explicit operator approval on
2026-10-03, both private external backups were refreshed with checksum comparison and no
deletions. The same 16 saved-draw recompositions were dispatched and **all 16 completed**;
historic queues drained and **0 new failed jobs** were recorded (479 pre-existing entries).
The banked structure draws were reused; the normal downstream song-matching stages still ran.
Receipt: `cut-sections-canary10-20261002/preflight-approved.json`.

The fresh-snapshot and original-canary custody diffs each report **0 runs needing attention**.
They report **15** and **12** runs respectively with media custody pending extraction, not final
custody clearance. Recomposition invalidated 42 section-media pointers pending regeneration;
no new media cuts were dispatched. Reports: `after-recompose-diff.json` and
`original-before-diff-after-recompose.json` in the same private evidence directory.

The settled recomposition confirms membership/coverage questions on **15 of the 16 canary runs**
(all except 1117): 98 uncovered-speech findings and one unresolved reading-membership finding.
`composition-review.json` and `composition-review.md` in the same private evidence directory
record the selected IDs, exact findings and links to each service's composition editor.
The ensemble batch report (`ensemble-after-recompose.json`) has **0 open ensemble questions**,
**0 open talk-edge checks** and **0 unreplayable runs**; composition review is the remaining gate.
These must be settled through the existing review path before Tier C can satisfy the new bar;
no automatic gap filling or release of existing content holds is authorised. Canary acceptance,
Tier A and published weekly-media recuts remain pending under their existing controls.

### C4 scope ruling and read-only replay — 2026-10-03

The operator narrowed C4 to interior gaps only. Reading/prayer membership questions remain
unchanged. No word lists, duration/word-count thresholds or special treatment of “Amen” were
introduced. The earlier canary evidence contained 98 uncovered-speech findings: 91 after the
last selected section (55 at least 10 s afterwards), four before the first, and three between
selected sections. These included 20 “Amen” occurrences, song announcements and lyrics;
median 0.9 s/two words, maximum 14.4 s/13 words. The three existing interior findings end a
pre-sermon prayer deliberately excluded by D1 (1025: 2019.0–2020.0 s; 1112: 2046.9 s).
The ensemble batch already had zero open questions and zero open talk-edge checks.

The requested regression failed first on the old 205–210 s tail finding, then passed with
the narrowed check. It also covers speech between a selected reading and sermon, leading
speech, identified intervening content and silence. The cutter, selected membership and
timestamps are unchanged; media processing version remains **2**. No UI code changed.

**Actual read-only canary result:** **53 uncovered-speech findings plus 1250's one unresolved
reading question, across 12 runs**, not the expected three plus one. The previous window
started near the sermon rather than at the selected reading, so it did not examine much of
the reading-to-sermon gap. The corrected consecutive-selection check retains the original
three inner findings and additionally finds 50 uncovered cue fragments in those interior
gaps. No rule was added to suppress these findings. Counts by run:

| Run | Uncovered speech | Reading membership |
|---|---:|---:|
| 936 | 9 | 0 |
| 949 | 1 | 0 |
| 1025 | 10 | 0 |
| 1028 | 1 | 0 |
| 1108 | 1 | 0 |
| 1112 | 6 | 0 |
| 1117 | 3 | 0 |
| 1221 | 1 | 0 |
| 1250 | 0 | 1 |
| 1304 | 12 | 0 |
| 1346 | 7 | 0 |
| 1356 | 2 | 0 |

964, 1050, 1311 and 1358 have no composition findings in the preview.
Evidence and service review links:
`storage/app/private/cut-sections-canary10-20261002/composition-review-c4-readonly.{json,md}`.
These fresh compositions were calculated inside rolled-back transactions. Stored review
state still describes the preceding dispatched round; no question was answered or hold released.

**475-run census:** composition/boundary parking falls from **331 to 225**, content-held
classification from **77 to 73**, and unblocked plans rise from **67 to 177**; zero resolver
errors. Risk-bearing runs (overlapping categories) are 228 uncovered-speech, 29 reading
membership and six prayer membership. Same-state old/new replays confirm **zero changed
selected memberships or output spans**. The four held classifications that become unblocked
(980, 1258, 1314, 1340) retain their content-hold flags: removing the composition flag allows
their existing, bound held-span repair authority to apply; no hold was released.
Evidence: `storage/app/private/cut-rule-census-c4-20261003/{census,summary}.json` and the
same-state old-scope replay `storage/app/private/cut-rule-census-c4-before-20261003/census.json`.

Validation: the focused resolver suite passes (**56 tests / 137 assertions**), full parallel
suite passes (**9,140 tests / 94,244 assertions**, 162 existing PHPUnit notices), PHPStan has
**0 errors**, and Pint passes. Logs: `/tmp/cut-c4-{red,focused,full,phpstan,pint}.txt`.
Dusk was not rerun because review UI behaviour did not change. After this code commit, freeze
the same membership in `cut-sections-canary10-20261002/before-c4-scope.json`, restart all four
historic worker lanes and record start times, then require **16 ready** in the saved-draw dry
run. Stop there: no recomposition, Tier C dispatch, hold release or composition answer is
authorised by this ruling.

Readiness completed on code commit **`87c22ab72`** (03:26:25 UTC): the new snapshot retains
membership `0bbc51836e06a7d9e9ed7b3edd8c0e185723495670bc4f6e323da78291eb4c2e` and the same
bound listening routing. All four historic worker lanes started at **03:26:52 UTC**, after
the code commit. The saved-draw dry run reports **16 ready, 0 refused**; queues remain empty.
Receipt: `storage/app/private/cut-sections-canary10-20261002/c4-preflight-readonly.json`;
logs `/tmp/cut-c4-{snapshot,worker-restart,saved-draw-dry}.txt` and
`/tmp/cut-c4-worker-{ffmpeg,whisper,llm,orchestration}.txt`. No recomposition or Tier C was
dispatched, no hold released and no composition question answered. This readiness record is
a documentation-only commit; it does not change the snapshot's bound code revision.


### Canary 10 follow-up rulings: exact cue edges and same-item C4 — 2026-10-03

**A — operator-confirmed edge clipping.** The operator listened to nine reading ends in the
canary sermon videos and saved eight `clipped` verdicts and one `other_speaker` verdict
(1356). The clipped words were 949 “Spirit”, 1108 “found”, 1221 “for it”, 1304 “Amen”,
1112 “this morning”, 1117 “today”, 1028 “while” and 936 “Amen”. Evidence:
[edge listening artifact](https://claude.ai/artifact/JtMfn2ppNniKRUrnMfVo9Y) and
`storage/scratch/edge-listening-20261003/saved/rulings/*.json`. These are operator listening
findings, not a claim that a timestamp scan itself proves audible clipping.

The prompt displays cue times as floored whole seconds. For example, run 949's last cue is
2443.94–2447.26 s, displayed as `[2443-2447]`; a returned end of 2447 s cuts inside it.
Other confirmed cue ends are 1108 2390.98, 1221 2246.92, 1304 1629.28, 1112 1850.58,
1117 1800.64 and 1028 1752.38 s. Silence snapping did not recover the missing words.
The supplied investigation reported about 45% of 506 edges straddling transcript cues,
with reading/short-talk/prayer/notices ends particularly affected; the reproducible current
before/after counts below supersede those approximate figures.

**Ruling and implementation:** before silence snapping, sound refinement and talk-edge checks,
map a returned whole-second start to the exact start of the unique cue with that floored
start, and an end to the exact end of the unique cue with that floored end. Missing or
ambiguous matches keep the proposal and record the match count in section notes. Fractional
proposals retain their existing meaning. The prompt and `toPromptText()` display are unchanged.
`TranscriptCueBoundaries` is used by saved-draw replay, fresh ensemble draws, the single-draw
weekly/shadow path and the evaluation harness. Song music-span and sustained-sound refinement
still run afterwards. Media processing version is **3**, because cut bounds change.

**B — operator ruling: C4 checks gaps within one item.** Of the previous 53 canary findings,
the operator classified 43 as linking speech between different items, deliberately excluded
by D1, and ten as edge clipping addressed by A. C4 now examines only consecutive selected
parts identified as the same sermon through the existing continuation relationship. Reading →
sermon and sermon → concluding prayer gaps are excluded. Reading/prayer membership checks,
content holds and operator answers are unchanged; no word lists or thresholds were added.

Red → green regressions first demonstrated the 949 end (2447 → 2447.26), a floored start,
and the erroneous reading → sermon C4 finding. Coverage also exercises every section type,
adjacent cues without overlap, ambiguous/missing matches, fractional proposals, music intros
and sustained-sound widening after mapping, and a sermon → continuation gap that still flags
uncovered speech, intentionally identified intervening content and silence. Version-change
tests now use the next configured version so future bumps retain meaningful coverage.


**Read-only same-16 saved-draw replay:** `straddle.php` now replays the latest immutable
four-draw bundle with the existing answers. Composition is previewed on a copied run inside
an always-rolled-back transaction, avoiding asset cleanup on authoritative sections. No
provider call, dispatch, section update, answer or hold release occurred. Evidence:
`storage/app/private/cut-cue-edges-20261003/{before,after,composition-review,summary,remaining-ends}.json`.
The scan retains its original 0.05 s cue-interior margin; this is a measurement convention,
not a new cutting rule. Current results are **240/506 edges before, 151/508 after**:

| Type | Start inside/total, before → after | End inside/total, before → after |
|---|---|---|
| bible_reading | 14/31 → 11/31 | 23/31 → 10/31 |
| notices | 7/19 → 4/19 | 12/19 → 6/19 |
| other | 14/43 → 15/43 | 24/43 → 11/43 |
| prayer | 18/51 → 10/52 | 33/51 → 10/52 |
| sermon | 6/16 → 4/16 | 4/16 → 3/16 |
| short_talk | 6/20 → 7/20 | 13/20 → 6/20 |
| song | 22/60 → 20/60 | 32/60 → 26/60 |
| welcome | 3/13 → 3/13 | 9/13 → 5/13 |

**End edges are not near zero: 150/253 → 77/254.** Exact cue mapping removes the floor
error, but the later rules can reintroduce cue straddling. Every remaining end is retained
with its transcript fragment, section notes and reason in `remaining-ends.json`:

| Remaining reason | Ends |
|---|---:|
| Missing cue match; proposal kept | 19 |
| Ambiguous cue match; proposal kept | 12 |
| Saved operator resolution reinstates its recorded bounds | 17 |
| Silence snap retreats inside a restored cue | 11 |
| Snap/sound refinement advances into a different or overlapping cue | 10 |
| Existing adjacent-overlap reconciliation changes the restored edge | 5 |
| Silence snap moves an already-exact integer cue edge | 3 |

Among the eight audibly clipped reading ends, five no longer straddle at the scan's margin:
949 2447.25, 1028 1752.38, 1112 1850.58, 1221 2246.93 and 1304 1629.49 s. Three still do:
936 restores 2532.16 but snaps back to 2531.95 (0.21 s inside); 1117 restores 1800.64 but
snaps back to 1800.52 (0.12 s inside); 1108's existing ruling
`c4f169de-5cf8-486e-8463-dc176888a7ef` reinstates 2389.99 (0.99 s inside the cue ending
2390.98). This replay does **not** establish that the audible defect is fully repaired.
Changing silence-snap direction or the bounds held in existing operator resolutions is not
part of these two rulings. No new fallback or answer was invented to force the counts down.

The two extra edges are a residual prayer in 964: cue restoration lengthens the draft prayer's
end beyond an existing short-talk ruling ending at 1166 s; the ruling applier retains the
remaining prayer fragment. Its start is 1166 s. This is a deterministic replay outcome,
not a new draw or an authoritative section change.

**Canary findings per run:** 936, 949, 964, 1025, 1028, 1050, 1108, 1112, 1117, 1221,
1304, 1311, 1346, 1356 and 1358 each have **zero composition risks**. 1250 has **one unresolved
reading-membership risk**. All sixteen have **zero C4 speech findings, zero open ensemble
questions and zero hard validation failures**. The copied-run extraction preview parks three
runs under `sermon_composition_review`: 1050 and 1356 retain `structure_sermon_interruption_merged`,
and 1250 has both that flag and its reading-membership question. Thirteen copied-run plans
are unblocked. This preview does not release or adjudicate any original run's content hold.

**475-run census of the existing authoritative section state:** **358 unblocked, 46 parked
for composition/selected-boundary review, 71 content-held**, with **zero resolver errors**
and **zero unsectioned seconds added**. Compared with the preceding C4 census, parking falls
from 225 to 46, content-held classification from 73 to 71, and unblocked plans rise from
177 to 358. Risk-bearing runs remain **29 reading membership, six prayer membership**, and
now **three same-item coverage risks**: 943 lacks required coverage evidence; 1073 has one
2653–2654 s speech fragment; 1116 has one 1026–1026.73 s fragment plus its reading question.
Counts overlap. Content-hold flags are retained: the classification change reflects existing
bound repair authority becoming usable after the composition blocker is removed. This is
not a dispatch or a hold release. Evidence:
`storage/app/private/cut-rule-census-same-item-20261003/{census,summary}.json`.


Validation: **9,147 tests / 94,271 assertions pass**, with 162 existing PHPUnit notices.
The final changed-file suites pass (**89 tests / 223 assertions**); the broader focused run
covered live detection, saved-draw execution, silence snap, sustained sound and the evaluation
harness (its one version-test assertion was corrected to use the configured next version,
then rechecked). PHPStan has **zero errors**; Pint passes, including both new files. Dusk is
not required because there is no UI change. Logs: `/tmp/cut-cue-{red,green,focused,focused-final,full,phpstan,pint,pint-new,replay,census}.txt`.

Next authorised operations are limited to committing on master, a fresh snapshot of these same
16 runs with the existing listening routing, restarting the four historic worker lanes and
checking that each started after the code commit, and a **saved-draw dry run only**. This
readiness is permission to inspect readiness, not permission to dispatch. Stop before any
recomposition dispatch, Tier C, hold release or new answer. The residual edge findings above
remain visible for the operator's next decision.


Readiness completed on code commit **`f18b56ea9`** (06:36:16 UTC). The fresh snapshot
`cut-sections-canary10-20261002/before-cue-edges.json` preserves membership
`0bbc51836e06a7d9e9ed7b3edd8c0e185723495670bc4f6e323da78291eb4c2e` and the existing bound
listening routes. All four historic worker lanes restarted at **06:37:06 UTC**, 50 s after
the code commit, and each checkout reports that full commit. The saved-draw dry run reports
**16 ready, 0 refused, 0 not reached**. Historic queues contain no queued, reserved or delayed
jobs. Receipt: `cut-sections-canary10-20261002/cue-edges-preflight-readonly.json`; logs
`/tmp/cut-cue-{snapshot,worker-restart,saved-draw-dry,queues}.txt` and
`/tmp/cut-cue-worker-{ffmpeg,whisper,llm,orchestration}{,-commit}.txt`.
No recomposition or Tier C was dispatched, no hold released and no answer recorded. The
remaining clipped edges are not acceptance evidence. This readiness record is a documentation-only
follow-up; it does not change the snapshot's bound code revision.


### Canary 10 follow-up — final spoken-cue invariant (operator ruling, 2026-10-03)

The operator rules that **no section edge may fall inside a transcript cue**, regardless of
which composition step caused it. This supersedes the earlier unique-match-only fallback and
its restriction on changing silence snap or saved resolution bounds. It applies to every
section type, weekly/live detection and saved-draw replay. The whole-second prompt display is
unchanged. Published weekly clips have the same defect recorded in D4; this change does not
repair already-published media.

Evidence from `f18b56ea9`: end straddles fell from 150 to 77 at the original 0.05 s measurement
margin. The remaining causes were silence retreat (14), floor-derived saved rulings (17),
missing/ambiguous matches (31), and advancing snap/overlap handling (15). Three readings the
operator heard clipped remained clipped: 936 restored 2532.16 then snapped to 2531.95 (“Amen”);
1117 restored 1800.64 then snapped to 1800.52; 1108's ruling
`c4f169de-5cf8-486e-8463-dc176888a7ef` reinstated 2389.99 inside the cue ending 2390.98 (“found”).
Their three regression tests were written first and all failed before the fix
(`/tmp/cut-invariant-red.txt`). They now pass, retaining the ruling's chosen reading.

Implementation: displayed times restore to the earliest matching start/latest matching end,
including ambiguous matches. Missing matches are handled by the final check. Silence proposals
stay in the speech-free interval adjacent to their contained cues; crossing a cue returns to
the cue boundary. The final check moves interior starts outwards to cue starts and interior
ends outwards to cue ends, following overlapping cues until the edge is outside them all.
It runs after sound/overlap refinement and again after ensemble composition and saved ruling
application. Recorded resolution choices and versions stay intact; only clipped times move.
MediaProcessingVersion is **4**.

**Historical shared-cue exception (superseded by the ruling below):** if that outward move would overlap a neighbouring section, retain
the incoming edges and raise `shared_cue`, naming both sections and the cue. It is a structure
question, not an invented split or answer. `structure_shared_cue` forces review on the affected
sections and the run's sermon, preventing automatic extraction even when the transition is
elsewhere in the service. The question and retained edges are regenerated after rulings.

**Read-only same-16 replay:** all writes used copied runs in rolled-back transactions. No new
draw, provider call, authoritative section update, dispatch, answer or hold release occurred.
This scan uses the **strict cue interior**, with no 0.05 s tolerance: 288/506 edges before,
20/510 after. All 20 remaining edges (nine ends, eleven starts) are covered by the eleven
shared-cue questions below. **Unexplained interior ends: 0; starts: 0.** The stricter baseline
is not directly comparable with the preceding tolerance-based 240/506 report. Evidence:
`storage/app/private/cut-cue-invariant-20261003/{before,after,remaining-edges,shared-cue-questions,composition-review,summary}.json`.
Each retained edge lists its question IDs; the question file names both sections and quotes
the complete cue. Reading ends now include 936 **2532.16**, 1117 **1800.64**, 1108 **2390.98**.

| Type | Strict interior starts/total, before → after | Strict interior ends/total, before → after |
|---|---|---|
| bible_reading | 15/31 → 0/31 | 26/31 → 1/31 |
| notices | 10/19 → 0/19 | 16/19 → 1/19 |
| other | 18/43 → 2/43 | 32/43 → 1/43 |
| prayer | 23/51 → 1/52 | 37/51 → 1/52 |
| sermon | 8/16 → 2/16 | 5/16 → 0/16 |
| short_talk | 8/20 → 3/20 | 13/20 → 0/20 |
| song | 25/60 → 2/60 | 34/60 → 3/60 |
| welcome | 6/13 → 1/14 | 12/13 → 2/14 |

Shared-cue questions, each retained for operator review:

| Run | Cue seconds | Left section → right section | Cue text |
|---|---|---|---|
| 936 | 2593.98–2594.38 | Prayer before the sermon → Serving God by his grace | name. |
| 936 | 3815.96–3816.08 | Introduction to closing hymn → Who Is on the Lord's Side | the |
| 964 | 1354.72–1355.24 | All My Days → Christian Institute Update | Now, |
| 964 | 3929.68–3940.68 | Shine, Jesus, Shine → Closing Prayer | And that truly is our prayer to you this morning. |
| 1117 | 708.66–713.62 | Cast Your Burden On The Lord → Open Doors and the Persecuted Church | Shirev ayo kamotan |
| 1221 | 87.25–91.18 | Revelation 5 → Call to worship | of Kings and the Lord of Lords. |
| 1221 | 112.62–114.14 | Call to worship → King Of Kings Majesty | Let's stand and sing King of Kings. |
| 1221 | 2459.57–2460.50 | Word One-to-One promotional video announcement → Jesus the Good Shepherd | service. |
| 1311 | 1304.84–1306.48 | Untitled song → Aled's baptism | Where is our first candidate? |
| 1346 | 34.21–35.10 | Opening words → I Will Sing the Wondrous Story | me. |
| 1346 | 362.08–366.41 | Opening prayer → George Washington Carver | things in Jesus' name and for his sake. Amen. Well, I |

Canary findings per run: shared questions are **936: 2; 964: 2; 1117: 1; 1221: 3; 1311: 1;
1346: 2**; all other ten runs have zero. 1311 also has one talk-end question for Naomi's testimony
next to baptismal instructions. 1250 retains one reading-membership composition risk; the other
fifteen have zero composition risks. All sixteen have zero C4 speech findings and pass hard
validation. Copied extraction plans park **nine** runs under `sermon_composition_review`:
936, 964, 1117, 1221, 1311 and 1346 have shared-cue flags; 1050, 1250 and 1356 have interruption-
merge flags (1250 also has the reading question).
**Seven** plans are unblocked: 949, 1025, 1028, 1108, 1112, 1304 and 1358. A test-first cleanup
removes the obsolete disagreement flag when a saved choice resolves a temporary shared cue
(`/tmp/cut-invariant-shared-red.txt`); it does not invent or alter an answer. These copied plans do not
adjudicate original content holds. The replay has two more sections than the authoritative
baseline: residual prayer/welcome fragments retained around saved choices, not new draws.

**475-run census:** apply restoration and the final check to existing authoritative sections
inside rolled-back transactions, preserving IDs, membership and content-hold metadata; then
compose and resolve. This measures the invariant on existing sections, not 475 new ensemble
compositions. Evidence:
`storage/app/private/cut-rule-census-cue-invariant-20261003/{census,summary}.json`.
There are **61 unblocked, 336 composition/selected-boundary review, 76 content-held, and two
invalid-selected-bounds refusals**. Shared-cue questions total **1,527 across 382 runs**:
320 composition-parked and 62 content-held (overlapping reasons, not extra runs). Each census
row contains its questions and parking reason. Reading membership remains 29, prayer membership
six; the only remaining same-item coverage risk is 943's missing evidence. The 1073 and 1116
speech gaps no longer appear after outward cue correction. The source-bound refusals are 1089
and 1136: their corrected sermon ends are 2291.78 and 1221.00 s, beyond their source durations
2291.333 and 1220.20 s respectively. The existing selected-span source validation refuses
them. No duration tolerance or fallback is invented.

The 382 shared-cue runs are a material increase in review workload. It follows the operator's
all-section invariant, including transitions outside the sermon cut; it is not acceptance
of any ambiguous transition. No shared question has been answered.


Validation: the final focused suites pass **217 tests / 815 assertions**, including live
and saved-draw detection, silence limits, all section types, shared cues, missing/ambiguous
matches, overlapping cues, music intros, saved choices and review exports. The full parallel
suite passes **9,156 tests / 94,298 assertions**, with 162 existing PHPUnit notices. PHPStan
reports **zero errors** and Pint passes. No browser behavior or template changed, so Dusk is
not required. Logs: `/tmp/cut-invariant-{red,shared-red,focused,review-focused,full-clean,phpstan,pint,replay,census}.txt`.

The operational readiness receipt for this follow-up is
`cut-sections-canary10-20261002/cue-invariant-preflight-readonly.json`. It binds the code commit,
the fresh same-16 snapshot `before-cue-invariant.json` and original listening routing, each
historic worker's checkout and start time, and the saved-draw dry-run result. Logs use
`/tmp/cut-invariant-{snapshot,worker-restart,saved-draw-dry,queues}.txt` and
`/tmp/cut-invariant-worker-{ffmpeg,whisper,llm,orchestration}{,-commit}.txt`.
Readiness is permission to inspect readiness only. Stop before recomposition dispatch, Tier C,
hold release or answers. Shared-cue questions and source-bound refusals are not acceptance.


### Canary 10 follow-up — whole shared cues in both outputs (operator ruling, 2026-10-03)

**The operator rules that missing content is the defect; overlap between neighbouring clips
is acceptable. A cue shared by touching sections belongs to both outputs.** This supersedes
the shared-cue question/flag/parking exception above. Sections retain their non-overlapping
joins, their existing model, validation and review choices. There is no guessed allocation of
words to either item and no new operator question.

Evidence at `e3a70c5bf`, in
`storage/app/private/cut-rule-census-cue-invariant-20261003/census.json`: 1,527 shared-cue
questions across 382 of 475 runs (median four), exceeding the batch stop rule of 2.0 composer
disputes per run. Parking rose from 46 to 336. Only 372 questions touch an edge of the planned
sermon cut; the other 1,155 concern unrelated items but the old run-wide flag parked the
sermon anyway. Ninety-eight runs had no other parking reason. Of the joins, 895 have a song
on one side and 632 are both spoken; 482 cues exceed five seconds.

**Implementation:** the common cut planner expands interior starts to the cue start and ends
to the cue end, following overlapping cues until no cut edge is inside any cue. It applies
at extraction planning to sermons, songs, readings and talks. Sermon audio and video use the
same expanded spans; spans within one output merge when they overlap or meet. The existing
sermon and publication-candidate extraction audits record each changed edge, original/final
time, cue(s), and seconds added. Section joins remain unchanged for shared cues; the final
section invariant continues to correct non-shared edges. Shared-cue questions and flags are
removed on composition/replay and retired flags cannot block sermon extraction.

Non-shared questions flag only the sections whose edges they touch. The sermon evidence gate
checks its selected cut spans; unrelated questions remain available in their existing review.
A parked sermon retains only candidate preparation from its job chain, so unrelated clips
can still be prepared. Candidate preparation skips held/questioned outputs and preserves the
sermon's parked status. Existing holds, review choices and saved versions remain intact.
C4 evaluates the remaining same-item gaps after widening, so a cue now included in the output
does not produce a false uncovered-speech finding. Existing source-bound validation still
refuses invalid selected or widened sermon spans. MediaProcessingVersion is **5**.

**Test-first evidence:** the four requested cases failed before implementation
(`/tmp/cut-shared-output-red.txt`) and now pass: 936's sermon starts at **2593.98** while its
prayer section still ends at **2594.18**; 1221's song starts at **112.62** while its section
join stays at **113.38**; a song-to-song shared cue neither asks a question nor parks the
sermon; widened sermon/continuation spans merge into one cut. Further red-to-green checks
cover C4 after merged widening, clipping audits across overlapping cues for each clip type,
and preparing an unrelated song without cutting held/questioned songs or overwriting the
sermon's manual-review status. Logs include `/tmp/cut-shared-{c4-red,output-park-red,output-candidate-hold-red}.txt`.

**Read-only same-16 cut-span replay:** `straddle.php` replays the same saved draws and rulings,
composes copied runs in rolled-back transactions, and scans the planned output spans rather
than unrelated section edges. Candidate output types are enumerated before publication
eligibility; both sermon media use one audited plan. All **276 planned cut edges** are outside
strict cue interiors: **zero starts, zero ends, zero shared-cue questions**. Before widening,
selected output edges have 25 strict straddles out of 280 edges. Spans that merge reduce the edge count.
Evidence: `storage/app/private/cut-shared-output-20261003/{before,after,listening-list,composition-review,summary}.json`.
| Output | Interior starts / total, before → after | Interior ends / total, before → after |
|---|---|---|
| sermon | 3/29 → 0/27 | 1/29 → 0/27 |
| song | 4/60 → 0/60 | 7/60 → 0/60 |
| bible_reading | 1/31 → 0/31 | 4/31 → 0/31 |
| short_talk | 3/20 → 0/20 | 2/20 → 0/20 |

The listening list below records every widened edge, longest first. Ten entries are only
floating-point differences below 1e-9 seconds; they are retained in the strict audit, with
no measurement tolerance applied to cutting.

| Run | Output / edge | Cue seconds | Seconds added | Cue text |
|---|---|---|---|---|
| 949 | sermon video and audio / start | 2738.9600–2744.9900 | 6.03 | him in jesus name we ask amen thank you mark it is such a |
| 1028 | bible_reading Psalm 150 (section 4) / start | 196.3000–201.2000 | 4.9 | Well, all that said, let me read to us, before we pray, |
| 1117 | short_talk Open Doors and the Persecuted Church (section 9) / start | 708.6600–713.6200 | 4.493 | Shirev ayo kamotan |
| 1346 | short_talk George Washington Carver (section 4) / start | 362.0800–366.4100 | 3.921 | things in Jesus' name and for his sake. Amen. Well, I |
| 964 | song Shine, Jesus, Shine (section 15) / end | 3929.6800–3940.6800 | 3.19 | And that truly is our prayer to you this morning. |
| 1025 | song Praise My Soul The King Of Heaven (section 5) / start | 418.2200–420.2800 | 2.06 | We thank him for it this morning. |
| 1311 | song Song after Ralph's baptism (section 18) / start | 1512.1600–1514.1000 | 1.94 | of the Holy Spirit. |
| 1311 | song  (section 14) / end | 1304.8400–1306.4800 | 0.82 | Where is our first candidate? |
| 1221 | song King Of Kings Majesty (section 4) / start | 112.6200–114.1400 | 0.76 | Let's stand and sing King of Kings. |
| 1346 | song I Will Sing the Wondrous Story (section 2) / start | 34.2100–35.1000 | 0.445 | me. |
| 1221 | sermon video and audio / start | 2459.5700–2460.5000 | 0.38 | service. |
| 964 | song All My Days (section 8) / end | 1354.7200–1355.2400 | 0.26 | Now, |
| 964 | short_talk Christian Institute Update (section 9) / start | 1354.7200–1355.2400 | 0.26 | Now, |
| 936 | sermon video and audio / start | 2593.9800–2594.3800 | 0.2 | name. |
| 1221 | bible_reading Revelation 5 (section 2) / end | 87.2500–91.1800 | 0.1729 | of Kings and the Lord of Lords. |
| 1250 | song All I Once Held Dear (section 16) / end | 4469.0400–4475.4800 | 9.09e-13 | Glory to you, there is no greater thing |
| 964 | sermon video and audio / end | 3723.8400–3724.1800 | 4.55e-13 | Amen. |
| 1108 | short_talk Interview with Colin (section 11) / end | 2164.8000–2166.7200 | 4.55e-13 | And then we'll hand over to you to preach. |
| 1346 | song Take My Life and Let It Be (section 9) / end | 3546.1600–3552.7200 | 4.55e-13 | Let's pray once again. |
| 964 | bible_reading Salt and Light (section 11) / end | 1817.8200–1818.8600 | 2.27e-13 | So this is the |
| 949 | song How Deep the Father's Love for Us (section 8) / end | 718.2200–720.1800 | 1.14e-13 | Whenever we meet together like this, |
| 1250 | short_talk F is for Fish (section 6) / end | 983.6200–985.6800 | 1.14e-13 | into the world. |
| 964 | song God, We Praise You (section 4) / end | 369.6200–374.2800 | 5.68e-14 | Savior, be God, come in today. |
| 1025 | bible_reading Psalm 103 (section 4) / end | 418.2200–420.2800 | 5.68e-14 | We thank him for it this morning. |
| 949 | bible_reading Call to worship (section 3) / end | 132.4600–134.9200 | 2.84e-14 | I'm going to stand and sing in just a few moments, |

Canary findings per run: **1311 has one talk-end question**; the other fifteen runs have
zero structure questions. **1250 has one reading-membership composition risk**; the other
fifteen have zero composition risks. All sixteen have zero C4 findings and pass hard
validation. Thirteen copied sermon plans are unblocked: **936, 949, 964, 1025, 1028, 1108,
1112, 1117, 1221, 1304, 1311, 1346, 1358**. Three remain parked for selected-section/composition
review: **1050** (merged interruption), **1250** (merged interruption and reading membership),
**1356** (merged interruption). The talk question in 1311 does not park its sermon. These
copied replay plans do not adjudicate the authoritative runs' existing content holds.

**475-run read-only census:** apply restoration and the retained non-shared section invariant
to authoritative sections, then scope existing bank questions and compose expanded cuts
inside rolled-back transactions. IDs, membership, holds and existing repair authority stay
intact. This is a census of existing sections, not 475 new ensemble compositions. Evidence:
`storage/app/private/cut-rule-census-shared-output-20261003/{census,summary}.json`.
Mutually exclusive results: **352 unblocked, 75 content-held, 46 composition/section review,
two invalid-bounds refusals**. No shared-cue parking remains. Each of 475 rows has zero new
cue questions and zero outstanding non-shared questions in its current stored bank; the
fresh same-16 replay's separate talk question above is reported separately. The summary
lists every parked run and its flags/risks. Existing repair authority permits 1343's named
held-span repair; its hold remains. 1089 has a hold as well as an invalid source bound and
is counted under the invalid-bound refusal, once.

Remaining membership findings are **29 reading** and **six prayer** (reasons overlap holds).
Run 943 retains the only C4 finding: required evidence for its same-item gap is missing.
Among the 46 non-held review runs, overlapping reasons are reading membership (29), prayer
membership (five), material boundary risk (six), interruption merged (four), low confidence
(three), explicit composition-review flag (three), OoS mismatch (four), incomplete sermon
evidence (one), and C4 missing evidence (one). The source-bound refusals remain 1089 and
1136, whose sermon ends exceed their source durations; no fallback or changed tolerance.

Across all enumerated sermon and candidate outputs, **1,895 edges widen**: **939 add <1 s,
593 add 1–5 s, 363 add >5 s**. Median **1.04 s**, p90 **8.28 s**, p95 **14.06 s**, maximum
**39.13 s**. Sixty-one entries are numerical differences below 1e-9 s. Counts include
parked output previews and include sermon audio/video once because their spans are identical.
Every edge and its cue is recorded in the census output plans.

**Gates:** focused integration/unit suites pass **242 tests / 941 assertions**; the final
candidate-preparation suite after the output-hold and parked-status regressions passes
**21 tests / 112 assertions**. The full parallel suite passes **9,162 tests / 94,345
assertions**, with 162 existing PHPUnit notices. PHPStan reports **zero errors**; Pint passes
for dirty files and the new planner/review classes. No UI behavior or template changed;
Dusk is not required. Logs:
`/tmp/cut-shared-output-{focused-final,candidates-final,full,phpstan,pint-final,canary,census}.txt`.

**Post-commit operational evidence:** the readiness receipt is
`storage/app/private/cut-sections-canary10-20261002/shared-output-preflight-readonly.json`.
It records the committed master revision, fresh same-16 snapshot
`before-shared-output.json`, original listening routing/hash, each of the four historic
workers' revision and UTC start after the commit, and the saved-draw dry-run result.
The same sixteen IDs are **936, 949, 964, 1025, 1028, 1050, 1108, 1112, 1117, 1221, 1250,
1304, 1311, 1346, 1356, 1358**; membership SHA-256
`0bbc51836e06a7d9e9ed7b3edd8c0e185723495670bc4f6e323da78291eb4c2e`.
Operational logs use `/tmp/cut-shared-output-{snapshot,worker-restart,saved-draw-dry,queues}.txt`
and `/tmp/cut-shared-output-worker-{ffmpeg,whisper,llm,orchestration}{,-commit}.txt`.

Stop before recomposition dispatch, Tier C, hold release or answers. Previewed widened cuts
and readiness checks do not accept the parked outputs or repair already-published media.

### Canary 10 follow-up — word pauses at output edges (operator ruling, 2026-10-03)

**An output edge sits in a pause between words, never inside a word. Missing content is
still worse than extra: when a successfully decoded window returns no words, retain the
whole-cue widening. Missing cached evidence is a blocker, not that fallback.** This
supersedes whole-cue widening when edge word timings are available. Source listening:
[operator artifact](https://claude.ai/artifact/AFkNEHMZspTPuduzHx3FpG),
`storage/scratch/widening-listening-20261003/{edges.json,saved/rulings/*.json}`.

The operator accepted 11 of 14 listened canary edges and two of five available long corpus
edges. The prayer intrusions in 936, 1346 and 949, song intrusion in 1342, and stretched
filler cues in 1148 and 1267 show why cue granularity cannot decide these cuts. The supplied
census found 377 widenings in 171 runs on cues longer than words × 1 second + 2 seconds,
adding 56 minutes. Run 1028's reading start is additionally included for re-listening.

**Implementation:** `TranscribeOutputEdges` is a separate job before media extraction,
after final projection in the livestream chain. Detection-only/Tier B does not decode edge
windows. Re-extraction and post-review chains include the step too. Historic jobs use the
Whisper lane; ordinary jobs retain the audio transcription queue. It decodes only windows
covering cues crossing or touching an output edge, with one second of context on either
side, from archived full-service audio. It uses the local server's `verbose_json` word
request and maps times back by the window offset. Every run follows this path; the 32 runs
with old raw word timestamps receive no shortcut. Stored transcripts, draws and section
bounds are unchanged by this refinement.

Each window is a private service artifact whose identity binds run, exact window bounds,
transcription model and the complete MediaProcessingVersion signature. Version is **6**.
Planning reads those artifacts without making inference calls. A missing or invalid cache
entry returns `edge_word_timings_missing`: no sermon media is extracted; a missing candidate
window is recorded on that candidate and other candidates can continue. C4 composition
records pending edge evidence until the separate job has populated it, then recomputes from
the cache. No new operator answer or approval state is introduced.

The planner enumerates consecutive-word pauses, including leading and trailing pauses,
chooses the largest, retains an original edge inside it, otherwise clamps the edge to its
nearest boundary. Equal-length ties choose the nearest pause. Separately timed punctuation
is joined to its preceding word, retaining its end time. Overlapping word intervals cannot
create a false pause. Cue interiors exclude the one-millisecond boundary tolerance,
including for retained no-word widening. Word/cue text disagreement is recorded but does
not override the timing. Existing source-bound checks and holds remain in force.

Both extraction audits retain the window, selected pause, words either side, original and
new time, signed seconds moved, no-word reason and text disagreement. The legacy
`cue_edge_widening` audit name and absolute `seconds_added` field remain compatible; the new
`seconds_moved` field distinguishes earlier from later edges. Spans meeting after refinement
merge within one output; sermon audio and video continue to share that plan.

**Test-first:** the eight initial cases failed on the previous whole-cue planner
(`/tmp/cut-word-edges-red.txt`). Synthetic word windows reproduce 936, 1346, 949, 1342 and
both long filler shapes. Additional tests cover empty windows, missing entries, model and
version changes, sub-millisecond cue noise, disagreeing text, window-offset mapping and
retry cache reuse. A separate red-to-green candidate test proves that a missing window
blocks only its output and records the reason (`/tmp/cut-word-edges-output-block-red.txt`).
Existing fallback tests now explicitly bank empty decoded windows instead of treating a
missing artifact as an empty result. Pipeline and dispatch assertions include the new step.

**Residual observed limitation:** the real server returns sung words in 1148's stretched
"Thank you." window. Under the specified largest-pause rule, its end moves from 352.268 to
362.250 seconds (**+9.982 seconds**), not the hoped-for <2 seconds. The synthetic filler
regression passes, but this real listening criterion has not been met. No movement cap or
semantic reassignment was added: the operator explicitly requires using disagreeing word
timings. This edge remains a re-listening finding; the change does not claim operator
acceptance of it.

**Separate song-boundary detection finding:** the saved 1267 ruling says, "This is actually
two songs, which should be separated by silence." This concerns detecting two songs and
their intervening silence, not output-edge refinement. No song split or detection repair is
part of this change.

**Scope correction (2026-10-03):** the operator subsequently instructed, "We don't need to
do the full corpus. Only the canary matters for now." The 475-run timing collection was
stopped immediately. Already appended timing artifacts are retained; no corpus cuts were
made. Final replay and listening evidence cover the same sixteen canary runs only. The
listening list has **15 canary edges, including 1028 bible_reading start** (already one of
those fifteen in the supplied edge file). Corpus listening subjects are deferred; the
earlier 1148 and 1267 findings above remain observations, not accepted cuts.

**Measured canary evidence:** `storage/app/private/cut-word-edges-20261003/` contains
`replay.json`, `summary.json` and `listening-list.json`. The cache supplies **152 unique
windows** for **127 outputs / 280 input edges** across sixteen runs. Refinement merges
touching spans, leaving 276 planned edges. There are **zero blocked outputs, zero no-word
fallbacks, and zero planned audited edges inside returned words**. There are 148 text
disagreement audit entries (context and sung text are still used as instructed).

Absolute movement across all 280 input edges, including unchanged edges, is: median
effectively 0 seconds, p90 **4.920 seconds**, p95 **7.060 seconds**, maximum **27.540 seconds**.
Signed movement ranges from -16.940 to +27.540 seconds. Summed cached window extraction and
Whisper compute is **210.291 seconds (3 minutes 30 seconds)**; per-window median is 1.210
seconds and p95 2.010 seconds. Windows contain 1,364.110 seconds of audio in total. This
compute measure excludes full-source retrieval, artifact writes and repeated experimental
decodes. Large movements and disagreements require operator listening; no acceptance is
inferred from an edge being outside a word.

**Quality gates:** focused tests pass **335 tests / 1,725 assertions**; the full parallel
suite passes **9,178 tests / 94,404 assertions**, with 162 existing PHPUnit notices.
PHPStan reports zero errors and Pint passes for changed and new PHP files. No browser
behaviour changes require Dusk. Evidence logs are `/tmp/cut-word-edges-{focused-complete,
full-pass,phpstan-pass,pint-complete,canary-final-replay,canary-final-summary,canary-listening}.txt`.

**Committed preflight:** implementation commit on master is
`6b355b8cd24f41d3dfc2bcb4a3adec1cf20ecdd2` (2026-10-03 11:46:27 UTC). Fresh same-16 snapshot
`storage/app/private/cut-sections-canary10-20261002/before-word-edges.json` was taken at
11:46:43 UTC, binding the original routing and membership hashes recorded above. All four
historic workers (FFmpeg, Whisper, LLM and orchestration) restarted at **11:46:55 UTC**,
after that commit, and each reports that revision. The saved-draw recomposition **dry run**
returned **16 ready, zero refused, zero not reached**. Redis has only the existing historic
notify keys, with no pending historic job keys.

The hash-bound receipt is
`storage/app/private/cut-sections-canary10-20261002/word-edges-preflight-readonly.json`.
Operational logs are `/tmp/cut-word-edges-{snapshot,worker-restart,saved-draw-dry,queues}.txt`
and `/tmp/cut-word-edges-worker-{ffmpeg,whisper,llm,orchestration}{,-commit}.txt`.
**Stopped here:** no recomposition dispatch, Tier C, hold release or answers. The additional
documentation-only receipt commit does not change the snapshotted processing code.

**Operator relisten of word-pause edges — 2026-10-03.** All **15 of 15** canary edges were
ruled **right** (nothing missing, nothing extra worth worrying about), including run 1028's
reading start and every edge previously judged not good: 936's and 949's sermon starts, which
now begin after the prayer's "Amen", 1346's and 1117's talk starts, and the song edges that
moved earlier (964 "Shine, Jesus, Shine" end −4.42 s; 1221 "King of Kings" start −0.77 s).
Page: https://claude.ai/artifact/Ew2BkX4cLawe7Wev4FRRYd; clips, edges and saved rulings in
`storage/scratch/word-edge-listening-20261003/{edges.json,clips/,saved/rulings/}`. Edge
placement is accepted for the canary. Still open: 1148's +9.98 s corpus edge (not a canary
run; watch for it in batch listening samples), 1267's two-songs detection finding, and 1250's
reading-membership question, which must be answered before its sermon is cut.

**Operator ruling — 1250 reading membership, 2026-10-03.** "For 1250 all the readings are
for the sermon." Run 1250 (2022-12-04, sermon "Job's Final Defence", no sermon reference):
the sermon video and audio comprise Job 29 (§73103, 1352–1527 s), Job 30 (§3131, 1739–1958 s),
Job 31 (§3132, 2182–2448 s) and the sermon (§4910, 2454–4310 s), with the intervening items
excluded. To be recorded through the existing composition review path, not by a code rule.

### Authorized Canary 10 dispatch — 2026-10-03 (cut dispatch held for 1311)

The operator authorized recomposition and extraction for the same sixteen runs, with 1050,
1250 and 1356's interruption-merged sermons explicitly parked pending listening. No Tier A,
canary acceptance, hold release or answer other than 1250's reading membership is authorized.
Operational evidence lives in `storage/app/private/canary10-word-run-20261003/`.

**Preflight and dispatch:** `preflight.json` binds exact membership, the reused
`cut-sections-canary10-20261002/before-word-edges.json` snapshot and unchanged code revision
`e09d11928eeb5a3401e9e5068b4eb247234c3b3a7696b6ce35b505533fb09cde`. HEAD `8ea5a0a99`
is documentation only; the last code commit is `6b355b8cd`, at 11:46:27 UTC. Every historic
worker started at 12:29:54 UTC and passed staging/temp read-write checks. App write probes
created and removed files on both mounts. The internal disk had 20,249,888 KiB free and
staging 489,518,068 KiB free. Queued, reserved and delayed queues were empty; only historic
notify keys existed. Both Sonnics backups were refreshed by `rsync -a --checksum`, excluding
`._*`, without destination deletions. Ruling-backup verification found permission-only
differences on the external filesystem and no content differences. The saved-draw dry run
returned 16 ready; the executed command dispatched exactly 16, with no refusals.
Logs: `/tmp/canary10-word-{recompose-dry,recompose-dispatch,pre-backup-artifacts,
pre-backup-rulings,pre-verify-rulings,worker-{ffmpeg,whisper,llm,orchestration}}.txt`.

**Recomposition:** all sixteen completed; queues drained. `before.json`,
`after-recompose.json` and `timing-results.json` record unchanged attempt counts (32 total),
attempt IDs and immutable draw-bank hashes. No new draw was made. Failed jobs remain at the
479-entry pre-dispatch baseline: zero new failures. Both the original and recomposed latest
banks retain 63 valid immutable draws and 936's
one invalid draw (slot 2, `non_chronological`): reduced coverage is three valid votes on that
run, rather than four. All 64 artifact checksums match; no new provider draw or new input
was made. `evidence-planned.json` and `936-slot-replay.json` record that evidence.
`batch-before.json` and the latest bank both expose one open ensemble question on **1311**, question
`ea480c55f1eaa6e5a690af4c315a4720f532e361d806e5135c3ce2c004cf0ae5`:
Naomi's testimony, 1056.60–1149.31 seconds, with an end beside baptismal service instructions.
The word-edge relisten answers for 1311 concern songs, not this testimony. It was not
answered. This conflicts with the requested zero-open-question condition and the explicit
ban on other answers. The operator was asked whether to park 1311 and cut the remaining
twelve, or hold all cuts pending its review. **No Tier C has been dispatched while that
decision is pending.** The thirteen excluding the interruption-merged runs pass the
extraction command's dry run (`/tmp/canary10-word-extract-dry.txt`); that readiness does not
resolve the open-question condition.

**1250 ruling recorded:** recomposition reassigned section IDs, so the literal old-ID request
was safely refused by `reviewComposition` (it would have selected songs and a prayer).
References and source bounds establish the exact correspondence:

| Selected item | Operator's earlier ID | Current ID | Current source bounds (s) |
|---|---:|---:|---:|
| Job 29:1–25 | 73103 | 3128 | 1348.56–1527.00 |
| Job 30:1–31 | 3131 | 73104 | 1735.74–1958.77 |
| Job 31:1–40 | 3132 | 4910 | 2182.57–2448.52 |
| Sermon | 4910 | 73105 | 2454.06–4310.36 |

The existing `SermonExtractionPlanResolver::reviewComposition` accepted all four current IDs
in the run's staging context, recording verified admin user 1, input identity and time
**12:57:59 UTC**. `ruling-receipt.json` records the ruling and remap. No code or type change,
intervening-item inclusion, interruption-flag clearance or other answer was made. Its planned
word-refined spans are 1354.38–1527.00, 1742.20–1958.77, 2182.57–2448.52 and
2454.06–4310.36 seconds. It still requires review because the interruption-merge flag remains.
The initial refusal and accepted remap are in `/tmp/canary10-word-ruling{,-remapped}.txt`.

**Edge timings:** `TranscribeOutputEdges` ran on the historic Whisper queue for all sixteen,
followed by the existing deferred-plan recording and sermonless completion tail. No media
was cut. It checked **153 windows**, reused **152**, and decoded **one** (1304), taking
**2.135 seconds** of new extraction/Whisper compute. All jobs completed. There are **zero
no-word fallbacks, zero cache-blocked output plans and zero new failed jobs**. Review blockers
are separate from missing-cache blockers. Receipts: `timings-receipt.json`,
`timing-results.json`, `timing-summary.json`; log `/tmp/canary10-word-timings-dispatch.txt`.

**Read-only checks before extraction:** `diff-original-before-extraction.json` against
`canary10-20261002/before.json` and `diff-latest-before-extraction.json` against the latest
snapshot each report **zero runs needing attention**, no live hold lost and nothing public.
They report **13 and 14 runs pending extraction**, respectively. The latest diff includes
25 lost section-media pointers pending regeneration; these are not cleared custody and the
bar's media-restoration condition has not passed. Both command logs use the corresponding
`/tmp/canary10-word-diff-*-before-extraction.txt` names.

The requested `storage/scratch/canary10/score.php` was rerun; `score.json` reports **zero
talk-count errors across all sixteen**. Its copied-run cut probe cannot borrow caches keyed
to the real run, so its empty cuts are supplemented by `accuracy-direct.json`: every current
selected span, word-pause adjustment and sermon truth span/alternative is shown directly.
All sixteen planned sermon boundaries are within the ruled tolerances. Reading membership
is shown separately because that truth file labels sermon edges, not preparatory reading
membership. These are planned comparisons, not acceptance of cuts that have not happened.
The original A/V scan, gap scan and score were preserved as `baseline-{avscan,gapscan,score}.json`.
No new outputs exist yet, so full new-output scans, current-version/reuse checks and final
in-cutter timing evidence remain pending Tier C.

**Listening and parked joins:** `listening-list.json` contains 90 entries: 68 word-pause
movements greater than two seconds and 22 selected-section or raw-draw interruption join
entries. `joins.json` contains the joins with sermon parts, intervening items, transcript
lines before/after each join, source clips and review URLs. Raw-draw interruption splits
are labelled as candidates, never accepted splits. Parked review links are
[1050](http://localhost/admin/services/699), [1250](http://localhost/admin/services/400) and
[1356](http://localhost/admin/services/1105). 1050's raw draw 1 contains reading interruptions
275–346, 387–425, 517–536 and 580–605 seconds (draw 3 also proposes 517–536); 1250 draw 3
proposes the Job 30 reading at 3070–3122 between sermon parts ending 3070 and resuming 3124;
1356 draws 1/3 propose Isaiah reading 2573–2605 between sermon parts around 2572/2573 and
2605/2606. 1250's four selected parts additionally have planned joins 1527→1742.20,
1958.77→2182.57 and 2448.52→2454.06, with the intervening songs/prayer excluded.

1050's known final-frame irregularity was checked against the source at 919–922 seconds:
the video has a **0.067-second excess gap at source PTS 920.321**, matching the baseline
cut's 0.0666-second excess gap. Source audio has no irregularity in that window. Evidence:
`/tmp/canary10-word-1050-source-tail.txt`. 1050 remains parked, with no new cut claimed.

**Post-run backup and batch report:** both Sonnics backups completed again with
`rsync -a --checksum`, no deletions, exit zero (`/tmp/canary10-word-post-backup-{artifacts,
rulings}.txt`). The batch report was then run and saved as `batch-after-backup.json`:
16 replayable runs, one open question, no unreplayable bundle, 33 applied rulings, 17 stale
and one conflicting ruling (1112's already-declared exception). No stale/conflicting ruling
was newly answered. The report log is `/tmp/canary10-word-batch-after-backup.txt`.
`receipt.json` binds the evidence hashes and explicitly records pending cuts and unpassed bar
conditions. `open-question.json` provides 1311's question, transcript lines, source and
[review URL](http://localhost/admin/services/1061). No Tier C, Tier A, acceptance, hold release
or answer other than 1250's recorded reading decision occurred. The operator's 1311 handling
choice is still required before the held cut dispatch can proceed.

### Canary 10 continuation — 1311 answered, 2026-10-03

The operator confirmed question `ea480c55…`: Naomi's testimony is right as proposed,
1056.60–1149.31 s; the father's words from 1152.28 belong to baptism. Exported the exact
question and applied the saved `alt0` choice through `structure:ensemble-apply-answers`,
dry run first (one ready, zero attention), then `--execute` (one applied, zero failed).
Verified admin 1 is the recorded operator. `1311-answer-receipt.json` records the decision;
`after-1311-answer.json` and `batch-after-1311-answer.json` show zero open questions on all
sixteen, unchanged 32 attempts/attempt IDs/draw hashes, zero unreplayable runs. Only this
explicitly authorized answer and the earlier 1250 composition ruling were recorded.

Refreshed §4.0 preflight passed on unchanged bound code and the reused same-sixteen snapshot.
Workers started 13:18:13 UTC, after the last code commit; all queues were empty, mounts passed
read/write probes, free space was 20,118,940 KiB internally and 490,289,896 KiB on staging.
Both Sonnics backups refreshed with `rsync -a --checksum`, without deletions. At 13:50–13:51 UTC
Tier C dispatched thirteen, zero refused; 1050/1250/1356 remain parked with interruption flags
unchanged. Receipts: `preflight-tierc.json`, `tierc-dispatch.txt`, all under
`storage/app/private/canary10-word-run-20261003/`. Post-cut results are recorded below.

### Canary 10 cut receipts and bar — 2026-10-03, stopped without acceptance

**Result: the thirteen authorized runs completed Tier C; the acceptance bar did not pass.**
1050, 1250 and 1356 remain parked for interruption-join review. No hold was released, no
Tier A or fresh draw ran, and no additional operator answer was recorded. The only new answer
in this continuation was the explicitly authorized 1311 testimony confirmation; 1250's
four-section composition ruling remains as recorded above.

All paths below are relative to `storage/app/private/canary10-word-run-20261003/` unless
otherwise stated. `receipt.json` binds the evidence hashes and stop boundary.

1. **Preflight and dispatch.** `preflight-tierc.json`, `1311-answer-receipt.json` and
   `batch-after-1311-answer.json` record empty queued/reserved/delayed queues, unchanged bound
   code/snapshot, the worker and mount checks, free space and checksum backups. The same
   sixteen remain frozen; Tier C dispatched only 936, 949, 964, 1025, 1028, 1108, 1112, 1117,
   1221, 1304, 1311, 1346 and 1358. `tierc-dispatch.txt`: thirteen dispatched, zero refused.
   They completed between the 13:50–13:51 UTC dispatch and 15:53:33 UTC. Queues then drained;
   failed jobs stayed at 479 (zero new). `final-state.json` records all sixteen, including the
   three whose media remains deferred. Attempt counts remain 32, with unchanged IDs and
   immutable draw-bank hashes; no new draw was made.
2. **Timing collection.** The separate all-sixteen step checked 153 windows, reused 152 and
   decoded one (1304) in **2.135 compute seconds**. Tier C rechecked 135 windows on the Whisper
   queue, all cache hits, zero new decodes. Zero no-word fallbacks and zero missing-word-cache
   output blockers. Stored full-service transcripts and draws were not re-transcribed.
   `timing-summary.json`, `timing-results.json`, `edge-inventory.json` and
   `final-check-summary.json` record the evidence. Of the newly generated cuts, 98 edges have
   word-pause audits, 83 record text disagreement; timings were used as ruled. Another 62 edges
   are in transcript gaps with no crossing/touching cue: their original edges are retained,
   with that reason in the inventory. Absolute word-pause movement: median **0.92 s**, p90
   **7.22 s**, p95 **10.61 s**, maximum **27.54 s**. These are movements from selected section
   edges; the listening list separately includes prior-cut comparisons.
3. **Sync and generation.** There are **70 distinct new videos**: thirteen main sermons and
   57 section candidates. Sixty are in quarantine; ten diagnostic candidates remain private in
   staging. All exist, have post-dispatch timestamps and current version-6 extraction
   signatures. `composition-version-check.json` verifies every actual selected cut plan against
   the cached plan; source-span float serialization differs by at most 4.55e-13 s.
   `in-cutter-final.json` records **70 paired cut checks plus 17 post-enhancement checks**, all
   passed, zero source anomalies and zero timing refusals. The original AV/gap scripts ran on
   every new file, with input from the original measurement harness extended to include all
   retained section candidates. The initial inventory covered sixty promoted/review files;
   the ten otherwise omitted diagnostic files were scanned separately, without rescanning
   those sixty. `new-videos-all.tsv`, `avscan-final.json`, `gapscan-final.json` and
   `file-generation-check.json`: **70/70 clean**, no packet jumps, frame gaps or repeats.
   Maximum audio/video packet-end difference is **29 ms**, below one 30 fps frame; AAC priming
   and final packet padding explain packet boundary offsets, while decoded-frame checks pass.
   1050's known source final-frame gap remains evidenced in `1050-source-tail.txt` (0.067 s
   source excess versus 0.0666 s baseline); 1050 was not recut. Parked outputs are not claimed
   to have current versions or new-file checks.
4. **Custody — failed restoration condition.** `diff-original-final.json` reports nine
   attention runs (25 attention strings) and two pending runs. `diff-latest-final.json` reports
   eleven attention runs (13 attention strings) and one pending run. All **48 attention/pending
   strings** have per-item explanations and current section/flag context in
   `attention-explained.json`; none was waived or cleared. Both diffs confirm no lost live
   hold and no output made public. The latest diff has eight media losses: **1108 §1897;
   1221 §2718 and §73036** remain held and were skipped by candidate preparation after their
   prior extraction signatures were invalidated. **1250 §3124, §3127, §73103, §3131 and §4911**
   remain pending because 1250 is parked. An earlier progress message incorrectly treated the
   held clips' absent paths as proof of no restoration loss: the snapshot retains their
   extraction signatures/timestamps and the custody diff correctly flags their loss.
   Other attention items concern review routing: recomposition resolves prior composition/
   ensemble flags; unsupported publication types become `not_applicable`; existing song policy
   publishes eligible regenerated clips into private quarantine. These explanations are
   evidence for operator review, not acceptance of review exits.
5. **1311 answer reached the bank, not the clip projection.** The answer action deliberately
   preserves projected sections when old extracted media exists. Its result has zero open
   questions and Naomi's banked section has no disagreement flag, but projected **§73575**
   retains `structure_ensemble_disagrees`, so candidate preparation skipped it. The planned
   span is **1056.70–1149.31** after the cached start adjustment; the father's words from
   1152.28 remain excluded. **No new Naomi clip exists.** The operator's answer should have
   been projected with `rerun-recompose` while media was still deferred, before Tier C; that
   necessary ordering was missed. After the cuts, the 1311-only replay dry run refused:
   `already re-run on this commit at 2026-10-03T12:48:32+00:00`. The guard permits continuation
   only while media is deferred. No repeat was dispatched, no stamp or flag was edited to
   bypass it, and no code/freeze change or fresh draw was invented. This remains an explicit
   blocker, despite the zero-open-question result.
6. **Accuracy.** Re-ran `storage/scratch/canary10/score.php`: zero talk-count errors. Its
   rolled-back copied-run cut probes cannot use caches keyed to the real run and leave all
   sixteen cut probes unscored (`edge_word_timings_missing`/`held_either_way`).
   `accuracy-direct.json` supplies every real selected sermon plan, actual cut audit and truth
   span; `accuracy-talk-direct.json` supplies all twenty talk plans and their truth spans.
   There are zero sermon/talk boundary mismatches within the ruled tolerances. Thirteen main
   sermons and seventeen talks have new cut audits; the three sermons and two talks on parked
   runs, plus Naomi, remain unrendered. Preparatory reading membership is shown separately
   because the truth file labels sermon/talk boundaries; 1250 follows the recorded operator
   ruling. The older truth alternative allowing the father does not replace today's 1149.31
   ruling. Correct planned boundaries do not pass the missing-output or custody bar.
7. **Listening and other read-only findings.** `listening-list.json` has **106 entries**:
   movements over two seconds from section edges or prior cut plans/bounds, the requested
   selected-section and interruption joins, and prior accepted whole-cue comparisons clearly
   labelled. It retains the existing listening shape, source windows, old/new edges, cached
   words either side and cue text context where word timings are absent. `joins.json` has
   22 selected/interruption join entries, including 1250's four-part joins. For 1050, 1250 and
   1356 it gives raw-draw sermon parts, the intervening readings, transcript lines at both
   joins and `/admin/services/{service}` review URLs; differing draw candidates are labelled
   rather than asserted as an accepted split. No word decode was run at interior joins.
   The separate **1267 two-songs/silence boundary finding remains separate from this fix**.
   Five existing talk texts were preserved and flagged `sermon_text_predates_evidence`:
   1117 §1960/§1962/§1967 and 1346 §4368/§4373; no text or answer was changed.
   Two additional pipeline observations are recorded without fixes: pre-promotion quality
   assessments read prior quarantine files, so their approvals are not used as evidence for
   these new cuts; and seventeen published song sections still name `historic_staging`, while
   their `SongVideo` canonical quarantine files exist and are newly generated
   (`published-song-section-disk-pointers.json`). The source-aware checks and final scans
   inspect the actual new files. One read-only exporter namespace error was corrected; its
   transaction rolled back and no extraction job failed.
8. **Backups and final report.** Refreshed both Sonnics backups with `rsync -a --checksum`,
   excluding `._*`, without deletions; both exited zero. The detection-ruling export
   `storage/app/private/detection-rulings/canary10-word-edges-20261003-after.json` now contains
   the current banks, operator rulings, composition review and section/hold state; its SHA-256
   matches the backup. Then ran the batch report: `batch-final-after-backup.json`, zero open
   questions on all sixteen, zero deferred/unreplayable runs, 34 applied/17 stale/one
   conflicting answer. The conflict is the predeclared 1112 exception; the one degraded
   attempt is 936's pre-existing invalid `non_chronological` slot, not a new failed draw.
   The implementation quality gates recorded for unchanged code `6b355b8cd` continue to
   apply (focused/full parallel suite, PHPStan and Pint). This operational continuation changes
   only these two plans; no application code, dependencies or stored service transcript changed.

**Stop:** no Canary 10 acceptance, Tier A, hold release, extra answer or recompose bypass.
Sync, current-version generation and direct boundary checks pass for generated files; media
restoration and Naomi's projection/output remain unresolved. Operator listening and acceptance
are still required, after those gaps are addressed through an authorized path.

### Canary 10 four-defect repair and same-sixteen re-run — 2026-10-03

The operator authorised repairs of the four failures in the preceding receipt, followed by
another saved-draw round of the same sixteen. No acceptance, Tier A, hold release or new answer
is authorised. 1050, 1250 and 1356 remain parked until the operator confirms their joins.

All four defects were reproduced before their fixes: an answer banked on a run with media
without recomposition; a content-held song skipped before extraction; fresh staging video
assessed from stale quarantine bytes; and a song section retaining its staging pointer after
promotion of its canonical SongVideo. Tier C now refuses an unapplied ensemble projection in
both `rerun-extract` and the orchestrator, naming `historic-import:rerun-recompose`; the answer
command prints that next step. Content-held non-sermon candidates now extract and run their
post-extraction hooks before the manual-review route withholds publication. Content-held
sermons and ensemble-disagreement skips retain their guards. The regression also exercises
weekly auto-publication and `HistoricReleaseReviewHolds`: both still withhold the held song.
Pre-promotion quality assessment reads the configured fresh output disk for a re-extraction
and records the assessed disk/path. Song promotion binds the section's disk and canonical
video path in its locked custody transaction, including an already-promoted replay.

Gates: focused tests **84 passed**; full parallel suite **9,181 tests, 94,429 assertions**, no
failures (163 PHPUnit notices); PHPStan zero errors; Pint passed. Queues were empty throughout
testing. No UI changed and no Dusk ran. The paired cutter and output recipe are unchanged, so
media-processing version remains **6**. Private evidence for this new operation is
`storage/app/private/canary10-defect-rerun-20261003/`; its `before-state.json` records 32
unchanged attempts, zero open questions, the saved Naomi answer and 1250's recorded four-part
selection. Implementation is ready for the commit, worker restart, fresh bound snapshot and
preflight; dispatch/results will be recorded below. Canary 10 remains unaccepted.


### Canary 10 four-defect continuation — stopped on run 964, 2026-10-03

The authorised same-sixteen saved-draw recomposition completed on code commit
`288975926fcf42be6405990499a21d1ff9432cbb` (revision
`ef594e1344d9bbb85dd253fc36c0320660b9b49db10b0468a50c8c1c9701a3cd`).
All 16 runs completed, all 32 attempt IDs and bank hashes are unchanged, all 64 immutable
saved draw artifacts match their checksums, and there are zero open questions. Failed jobs
remain 479, with no new failures. Receipts: `after-recompose.json`,
`evidence-after-recompose.json` and `recompose-dispatch.txt` in
`storage/app/private/canary10-defect-rerun-20261003/`.

1311's projected Naomi section 73575 is 1056.60–1149.31 s with no disagreement flag;
the father's words begin in the next section at 1152.28 s. 1250's existing composition review
survived: by content, the selected sections are Job 29:1–25, Job 30:1–31, Job 31:1–40,
and the sermon 2454.06–4310.36 s. No replacement ruling or question answer was needed.
1050, 1250 and 1356 remain parked; their interruption flags were not cleared.

**New blocker: stop before timing or Tier C.** The 13-run extraction dry run returned
12 ready and one refusal, run 964. Its saved composition and projected structure differ
only at `/sections/6/review_flags`: the bank has `[]`, while projection added
`["structure_micro_section"]`. No projected section has `structure_ensemble_disagrees`.
The new equality guard therefore refuses this successfully recomposed run with the
instruction to run `historic-import:rerun-recompose`. This is recorded, not resolved,
because the operator explicitly required stopping on any new blocker. Evidence:
`964-projection-blocker.json`, `tierc-dry-run.txt`, and the enclosing `receipt.json`.
Review: [run 964's service](http://localhost/admin/services/982).

Queues drained after recomposition; every worker still started at 17:13:48 UTC, after the
code commit, and refreshed mount probes passed. Internal free space was 19,677,424 KiB
and staging 491,847,568 KiB. No timing or extraction jobs were dispatched in this
continuation. There are no new cuts to scan, assess or listen to; both custody diffs,
accuracy checks, listening list and final batch report remain unperformed. Canary 10 is
not accepted, Tier A has not started, and no hold or question was resolved.

Both Sonnics backups completed again after recomposition with `rsync -a --checksum`,
excluding `._*` and without `--delete` (both exit 0). The stopped receipt records these
results, empty queues and evidence SHA-256 hashes. This stop is documentation only;
the four-defect code commit and its passing quality gates remain unchanged.


### Canary 10 projection-provenance ruling and repair — 2026-10-03

The operator ruled that run 964's extraction refusal was a false positive: projection
legitimately derives flags that are absent from the bank. The 1166.00–1167.21 s
“Prayer for Families” fragment and its `structure_micro_section` flag remain unchanged;
it is a recorded artefact, not an output. Whole-structure equality is removed.

Projection now records the latest bank attempt ID and SHA-256 of the complete composition
and ruling history in `service_structure_projection`. Detection and saved-draw recomposition
write that provenance atomically with the sections and projected structure, under the run
lock; changed bank inputs before projection refuse the write. Answer application records
new provenance only when it actually synchronises sections. An answer banked after media
exists retains the previous stamp and refuses cutting until recomposed. The extraction
check refuses missing or mismatched provenance, explicit `sections_synced=false`, and any
remaining `structure_ensemble_disagrees` section.

Test-first regressions reproduced the validator-only flag refusal, missing-provenance
acceptance, and same-content re-answer acceptance. Detection and immediate answer projection
were also checked for the new stamp. The integration case banks an answer after media
exists, proves Tier C and the general dispatch path refuse, and recomposes through
`DetectServiceStructure` before proving the new provenance clears the guard. Focused gates:
65 tests / 328 assertions passed, PHPStan zero errors, Pint completed; the full parallel
suite passed 9,184 tests / 94,441 assertions (163 PHPUnit notices, no failures). Receipts will be kept in
`storage/app/private/canary10-provenance-rerun-20261003/`, separate from the stopped attempt.
The cutter and encoding recipe are unchanged; MediaProcessingVersion remains 6.


**Operator confirmation received, stitched sermons, 2026-10-03.** The operator listened
to every listed passage and ruled it part of the sermon: 1050, John 15:18–20 and
2 Timothy 3:10–12 (275–346 s), 1 Peter 1:6–9 (387–425 s), 1 Peter 4:13–14
(517–536 s), Acts 5:41 (580–605 s); 1250, Job 30:24–31 (3070–3122 s);
1356, Isaiah 61:10 (2573–2605 s). Evidence:
[operator listening page](https://claude.ai/artifact/Fmzqu1GAvjCVb99m5XD9er) and
`storage/scratch/stitched-sermon-listening-20261003/passages.json` with six saved
`part_of_sermon` rulings under `saved/rulings/`.

The existing `ConfirmServiceSection` path was inspected before any write. The three
sermon sections currently carry only `structure_sermon_interruption_merged`, have no
other review flag or content hold, and have resolved identified-section membership.
1250's separate four-part composition ruling remains on the run. Confirmation will be
recorded against the fresh recomposed sections through that path, with a transaction
refusing any change beyond the interruption flag and its review audit. All sixteen are
then authorised for timing and Tier C, conditional on current provenance and the
unchanged preflight and acceptance checks. No canary acceptance or public release is
included in this ruling.


**Same-sixteen replay and stitched confirmations completed, 18:37 UTC.** Code commit
`ca1af7f59e4da955a4db2b29c94dbbba94b398cf`; all six workers restarted at 18:22:25 UTC
and passed mount probes. Fresh `snapshot.json` binds the same membership hash
`0bbc51836e06a7d9e9ed7b3edd8c0e185723495670bc4f6e323da78291eb4c2e` and listening
routing hash `2fc8903c7e239847a9a12ab449535e1b8398c588bfb88488c3bc47e230819f16`.
Internal free space was 19,608,148 KiB and staging 491,895,840 KiB; queues were empty,
both checksum backups completed without deletion, and saved-draw dry run was 16 ready.
`preflight-recompose.json` and `recompose-dispatch.txt` bind the dispatch.

All 16 completed, with 32 unchanged attempts, no new draw, all 64 artifact checksums
matching, zero open questions and failed jobs unchanged at 479. `after-recompose.json`
and `evidence-after-recompose.json` record the replay. Run 964 retains its 1.21 s prayer
fragment and derived flag, but its current projection provenance now passes the guard.
Naomi's ruled section is reflected in the projection, and 1250 retains all three Job
readings plus its sermon.

The existing `ConfirmServiceSection` action then recorded the three authorised merged
sermons (sections 1582, 73105 and 4485) as verified operator 1. The transaction checked
that each section had only the interruption flag and reason, preserved all other section
metadata, and verified run metadata—including provenance and 1250's composition ruling—
was unchanged. Receipt: `confirm-stitches-receipt.json`; preserved operator evidence is
in `stitched-sermon-rulings/`, including hashes of the six saved decisions and passages.
`after-stitch-confirmation.json` confirms no remaining interruption flag, no projection
refusal and no parked run. The all-sixteen extraction dry run is 16 ready / zero refused
(`tierc-dry-run.txt`). Timing and cutting have not yet been dispatched.


**All-sixteen edge timing completed, 18:48 UTC.** `preflight-timings.json` records empty
queues, refreshed staging/temp probes in the app and all workers, unchanged post-commit
worker starts, 19,475,968 KiB internal free space, 491,895,836 KiB staging free space,
and both refreshed checksum backups (exit 0). `timings-receipt.json` binds all 16 chains.
The new timing steps checked 153 windows, reused all 153 and decoded zero new windows
(zero decode compute seconds). All 16 resolved plans use word-pause audits: 182 planned
audits, no no-word fallback and no missing-cache blocker. `timing-window-summary.json`,
`timing-plan-summary.json`, `timing-results.json` and `evidence-planned.json` record this.
All runs completed and queues drained; failed jobs remain 479. Provenance remains current,
all three interruption confirmations remain settled, and 1250's four-part membership
remains intact. Tier C is awaiting its refreshed backup/preflight, not yet dispatched.


**Tier C dispatched for all sixteen, 19:00 UTC.** Fresh post-timing extraction dry run
was 16 ready / zero refused. `preflight-tierc.json` binds empty queues, all worker starts
still 18:22:25 UTC after the code commit, refreshed staging/temp read/write probes,
19,444,604 KiB internal free space, 491,895,836 KiB staging free space and both refreshed
checksum backups (exit 0, no deletion). `tierc-dispatch.txt` records 16 dispatched / zero
refused. No run remains parked. The initial `receipt.json` records the running state;
cut/file checks, both custody comparisons, accuracy, the listening list and final backups
and batch report remain pending. No test suite or Dusk is running alongside queue work.


**Tier C stopped on stale-file quality assessment, 20:38 UTC.** All sixteen main
sermon cuts passed their source-aware timing checks. Tier C checked 153 edge windows,
reused all 153 and decoded none. There were no timing errors or new failed jobs; the
failed-job baseline remains 479. All 32 attempt IDs and draw-bank hashes are unchanged,
all 64 saved draw checksums match, no question is open, projection provenance is current
for all sixteen, and the three authorised interruption confirmations remain settled.
Run 964's 1166–1167.21 s prayer fragment is unchanged and outside the outputs; 1250's
four-part composition ruling is preserved.

**New blocker: the quality repair still selects the prior file in the real pipeline.**
`SermonMetadataIntegrationService` spends `re_extraction.requested` when the new video is
stored, replacing it with `replacement_authorised`. By the time
`AssessSermonVideoQuality` runs, `isReExtraction()` is false; its disk selection therefore
falls back to the sermon's existing quarantine disk. For run 936, the assessment at
20:35:04 UTC cites `historic_quarantine:sermons/876/video.mp4`, modified at 15:19:07 UTC,
while this round's file exists on `historic_staging` and was stored at 19:03:01 UTC.
The seven assessments completed before the stop (936, 949, 964, 1025, 1028, 1050, 1108)
all cite prior quarantine files. Six approvals and 1050's `mostly_black` rejection are
not evidence about this round's new files; no fresh-file quality verdict is claimed.
Evidence: `quality-blocker.json`, `evidence-quality-blocker.json`,
`in-cutter-stopped.json` and `stopped-state.json` under
`storage/app/private/canary10-provenance-rerun-20261003/`.
Review: [run 936](http://localhost/admin/services/544),
[run 1050](http://localhost/admin/services/699).

All six worker containers were stopped; five exited normally and the active historic
FFmpeg worker was terminated after its stop grace period (exit 137). Payloads were
preserved, not deleted, cancelled, retried or settled. `stopped-workers.json` and
`stopped-queues.txt` record 15 pending and one reserved historic FFmpeg job, with every
other monitored queue empty. All sixteen runs remain in processing at the AI-analysis
completed step; none has completed the section-cut/promotion tail. The three held clips
(1108 §1897, 1221 §2718/§73036) and Naomi §73575 still have no media. Both final custody
diffs, AV/gap scans, final accuracy and disk-pointer checks, the listening list and final
checksum backups remain incomplete. The pre-dispatch backups passed as recorded above.
The failure is recorded without another fix or resumed dispatch. No canary acceptance,
Tier A, content-hold release or ensemble answer was made.


The read-only stopped-batch report completed at 20:46:50 UTC (`batch-stopped.json`):
16 bundles replay, 32 attempts, zero open or deferred questions, 34 applied / 17 stale /
one conflicting ruling (the predeclared 1112 exception), and one degraded attempt
(the pre-existing 936 invalid slot). It is a stopped-state report, not the final report
after completed cuts and backups. `stopped-pending-payloads.jsonl` and
`stopped-reserved-payloads.txt` preserve the exact remaining payloads: eight pending and
one reserved assessment, plus seven pending thumbnail-chain continuations. The operation's
`receipt.json` now records `stopped_quality_reads_prior_quarantine_file`. Do not restart
these workers or resume this queued round on changed code without a separately recorded
recovery; no payload was retired or database status changed to conceal the interruption.
