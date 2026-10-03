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
