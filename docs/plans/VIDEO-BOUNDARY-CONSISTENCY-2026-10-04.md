# Video boundary consistency: findings and implementation handover

> **Status — 2026-10-05:** F01, F02, F03, F05, F06, F08, F09 and F10 implemented
> regression-first and merged to `master` (`a9980a23a`, unpushed). See the §6 completion
> record. Open: F04, F07 (S5), S6 and investigations I1–I5. The canary 10 Tier C round was
> abandoned and its queued jobs removed; the next canary runs on these fixes.

> **Status — 2026-10-04:** planning only. Ten findings/cases collected in a bounded,
> read-only investigation of detection → composition → operator answers → section
> projection → extraction. No fixes or regression tests were implemented or executed.
> The user requested this document for a fresh session; that does not authorise processing
> runs, provider calls, worker starts, operator rulings, or publication.
>
> **Supersession and ownership:** this document consolidates this conversation's findings
> and proposed code work. It supersedes no operational decision or existing plan.
> [Cut from sections](CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md) retains the cut-policy and
> stopped-canary history; [historic incremental convergence](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
> retains import, release and production authority. This is a bounded correctness workstream,
> not a second historic-import execution plan or approval for a wholesale rewrite.

## 1. Start here in a fresh session

1. Read `AGENTS.md` and the latest shutdown/frozen-branch instructions at the end of the
   cut-from-sections plan, including its **2026-10-04 frozen-branch warning**.
2. Keep workers stopped and preserve queued payloads. Do not drain, retry, replace, retire,
   dispatch or consume them. Do not make provider calls, change operator answers, or run
   application replay commands merely because their names suggest a preview: some write
   metadata or sections. Documentation is the only change authorised by the handover request.
3. Establish the code baseline without switching the shared processing checkout:
   investigation target was **`8a5929681b2b00a0a3cd35e69895368ff2061aef`** on
   **`fix-silent-sermon-omissions`**. The actual checkout was **`fix-historic-video-custody`**,
   HEAD **`b708fc4c1b35864b3f33ee55f35d98a7cb68e4c4`**, clean before this documentation change.
   An initial conversational statement that the checkout was ahead of the target was corrected:
   it does **not** contain all target-branch fixes. Target-specific files were read with
   `git show 8a5929681:path`. Recheck branch divergence and current fixes before implementation.
4. Pick one small slice below after the user requests implementation. Read applicable skills;
   prove each reported defect with a failing regression before correcting it. Do not modify
   the frozen processing branch to run these fixes against preserved jobs.
5. Report each meaningful finding/result promptly. The user explicitly prefers bounded work
   and incremental reports to an exhaustive, usage-heavy investigation.

### Evidence limits

- “Confirmed code defect/gap” below means supported by static control-flow inspection or a
  concrete algorithmic counterexample. It does **not** mean an executed failing test or a
  demonstrated defective published recording. Reproduction is still required.
- Saved transcripts were inspected; recordings were not listened to. Transcript timestamps
  are source-relative seconds, not listening-confirmed word boundaries.
- Discovery candidates are **not proven held out**. No accuracy/generalisation claim follows
  from these examples, nor should they later be advertised as independent validation.
- No tests, Artisan replay, provider calls, edits to application code, dispatches, worker starts,
  queue changes or operator rulings occurred during the investigation. Documentation was
  subsequently requested. Worker/queue state was not independently re-audited in this pass;
  the session left it untouched.
- File/line references below describe the investigated revision. Locate methods afresh rather
  than assuming line numbers in the current checkout match.

## 2. Finding register

All entries are open. Suggested corrections are proposals, not new operator policy.

### F01 — Saved reference corrections can lose authority

**Classification:** confirmed code defect; no affected recording established.

**Location:** `app/Services/ChurchService/Structure/ServiceStructureEnsembleRulingApplier.php`,
`apply()` lines 188–210 and `contradicted()` lines 389–405.

When fresh draws agree and no question pairs with a saved answer, `contradicted()` compares
only section type/start/end. An operator-corrected `reading_reference` or `sermon_reference`
can be contradicted at identical times without triggering restoration; the answer becomes
stale. Section projection can therefore carry the wrong reference into reading selection.

**Consequence:** loss of a settled content decision; a reading can be omitted, selected wrongly,
or unnecessarily parked even though the operator already corrected its identity.

**Smallest regression:** saved reference correction plus a fresh unanimous section with identical
times and the wrong reference. Assert the settled reference survives replay and reaches the
projected section. Cover reading and sermon references.

**Correction:** compare output-relevant identity fields as well as timing. Define exactly what
the answer settled; do not treat every incidental metadata difference as a contradiction.

### F02 — The ensemble's 900-second reading window disagrees with extraction

**Classification:** confirmed cross-stage mismatch; no affected recording established.

**Location:** `ServiceStructureEnsembleComposer.php`, `pairableReadings()` lines 596–614,
`compatible()` lines 496–499; `app/Services/Sermon/SermonExtractionPlanResolver.php`,
`compose()` lines 62–85. Composer is under `app/Services/ChurchService/Structure/`.

Composition excludes readings more than `max_pairing_gap_seconds` (configured 900 seconds)
before the sermon from the extraction-sensitive reading set. Such readings need only
substantially overlap to be grouped as the same item. Extraction can select a matching
reading anywhere before the sermon, without that time limit.

**Consequence:** materially different voter boundaries can be treated as agreement for a
reading that will actually be cut; omitted verses need not generate a boundary question.

**Smallest regression:** one matching reading more than 900 seconds earlier, with voter end
times differing enough to lose a verse. Assert the extraction-relevant disagreement survives.

**Correction:** use the actual extraction eligibility rule upstream. Do not simply restore an
arbitrary time limit to extraction and thereby introduce a new omission.

### F03 — Single-token anchoring can choose the wrong repeated word

**Classification:** confirmed algorithmic counterexample; recording impact unverified.

**Location:** `app/Services/ChurchService/CueSafeExtractionPlan.php`,
`cueBoundaryPause()` lines 122–129, introduced/extended by the target fix.

The anchor uses only the first/last normalised token of the cue and chooses the closest
matching occurrence within two seconds. Timing drift can make a later occurrence of “the”
closer than the cue's actual first word.

**Consequence:** a cut intended to preserve the whole cue can discard its opening words, or
retain the wrong closing occurrence.

**Smallest regression:** cue starts at 10s; its first “the” is at 9.2s and a later “the” at
10.1s. Assert the opening phrase survives. Add the analogous repeated end-token case.

**Correction:** align contextual token sequences; ambiguous alignment should remain unresolved
rather than silently choosing a repeated occurrence. Preserve the already-fixed lone-cue cases.

### F04 — Two readings separated by prayer: concrete listening/regression case

**Classification:** observed transcript structure, not a proven extraction defect.

**Service:** discovery run **1240**, **2023-02-12 morning**. Saved transcript:
`storage/app/private/service-artifacts/service-transcripts/2023-02-12/morning-012e82b3-6d52-4f95-a154-646cb351a25b.normalized-region-recovered.json`.

- About **1065.72–1092.72s (17:46–18:13):** introduction to two chapters, starting Job 36.
- **1274.72–1280.72s:** “We're going to pray and then we'll come back and read chapter 37.”
- **1466.72–1487.72s:** repeated “To bring peace”, potentially an ASR loop.
- **1488.72s (24:48.72):** Job 37 begins.
- Later sermon text explicitly discusses both chapters.

**Relevant code:** `SermonExtractionPlanResolver::compose()` lines 63–87 and
`reviewComposition()` lines 197–207.

**Risk/consequence:** merging the two readings into one span can include the intervening
prayer; selecting only one loses part of the preached reading. The current resolver parks
multiple partial matches, which is protective: do not report this as an existing silent omission.

**Smallest regression:** Job 36 reading → prayer → Job 37 reading → sermon on both chapters.
Assert review is required, and an explicit two-reading selection produces disjoint spans
excluding the intervening prayer. Listen to establish the true prayer ending before using
this service as boundary truth; make no operator ruling from transcript text alone.

### F05 — Answer replay discards validator annotations before projection

**Classification:** confirmed code defect; no affected recording established.

**Location:** `app/Services/ChurchService/Structure/ServiceStructureEnsembleReplay.php`
lines 124–138; `app/Actions/ServiceReview/AnswerServiceStructureEnsembleQuestion.php`
lines 138–143; `ServiceStructureValidator::annotateSoftFlags()` around lines 1043–1047.

Replay validates the corrected structure but returns the unannotated `$corrected['structure']`
instead of `$validated->structure`. The answer action projects that version. Normal detection
consumes the validator result, so the paths differ.

**Consequence:** newly introduced talk fragments or talk → prayer/reading → talk structures
can lose the review flags intended to catch incomplete or incorrectly separated items.

**Smallest regression:** a correction splits one section into talk → prayer → talk. Assert
both replay output and persisted sections retain `structure_talk_interrupted`. A newly created
sub-minute talk is another focused case for `structure_talk_fragment`.

**Correction:** propagate the annotated validated structure, retaining hard-failure behaviour
and consistent provenance. Ensure tests cover the answer-to-projection path, not only validation.

### F06 — Saved answers can truncate one another while both remain “applied”

**Classification:** confirmed control-flow gap; no affected recording established.

**Location:** `ServiceStructureEnsembleRulingApplier::apply()` lines 184–185,
`makeRoomFor()` lines 281–313, `settle()` line 353.

`makeRoomFor()` trims all overlapping neighbours, including sections already installed by
another saved answer. There is no final verification that each applied resolution still holds.
Existing conflict checks about competing answer/question matches do not cover this case.

**Consequence:** reading accepted at **100–200s**, then a separately accepted talk at
**180–300s**, can leave reading **100–180s** while both answers are recorded as applied.
Content ownership becomes processing-order dependent rather than an explicit operator decision.

**Smallest regression:** two distinct scoped answers with overlapping replacement spans;
assert an unresolved conflict and extraction hold. Reverse answer/question order to ensure
neither order silently chooses ownership.

**Correction:** distinguish detector-owned neighbours from operator-settled content; reconcile
conflicts explicitly and verify all applied resolutions against the final structure. Preserve
legitimate trimming of unreviewed detector neighbours.

### F07 — Song-edge repair can strand a sermon conclusion

**Classification:** confirmed missing safeguard; concrete candidate requires listening and
comparison with its actual saved section/extraction plan before claiming an observed omission.

**Location:** `app/Services/ChurchService/Structure/SongSpeechEdges.php`, `trimmed()`
lines 188–217; `SermonExtractionPlanResolver::uncoveredSpeech()` lines 373–385;
`ServiceStructureValidator::checkCoverage()` lines 726–744.

Song repair removes spoken material from song edges but neither assigns the exposed speech
elsewhere nor asks who owns it. Sermon coverage checks gaps between identified sermon parts,
not speech after the final part. Global coverage can still pass the configured 0.7 floor.

**Consequence:** when detection ends the sermon early and puts its conclusion into a song,
sound refinement can improve the song clip while leaving the sermon incomplete.

**Candidate:** discovery run **1219**, **2023-07-16 morning**. Artifacts:
`storage/app/private/service-artifacts/service-transcripts/2023-07-16/morning-4e18d5fe-8b57-4608-961f-63c62a34f4b5.normalized-region-recovered.json`
and the same stem's `.classes.json`.

- **3799.90–3822.90s (63:19.90–63:42.90):** hymn words inside the concluding address.
- **3824.90–3836.90s:** returns to “I am the way…” and “Our trust must be in him.”
- **3836.90s onward:** hymn announcement; transcript also contains suspicious repetitions.
- Classifier windows strongly support speech through the quotation/conclusion, switching
  toward music around **3870s (64:30)**. Classification is supporting evidence, not listening truth.

**Smallest regression:** early song boundary swallowing a sermon conclusion, followed by
sound trimming. Require an ownership question for the exposed speech adjacent to the sermon.
Do not automatically absorb it: hymn announcements are legitimate excluded speech too.

### F08 — Reference equivalence uses overlap; extraction uses containment

**Classification:** confirmed cross-stage inconsistency, distinct from F02's time window.

**Location:** `ServiceStructureEnsembleComposer::referencesAgree()` lines 921–941;
`SermonExtractionPlanResolver::compose()` lines 62–87.

Composition treats references as equivalent if they overlap each other and overlap the same
counterpart passages. Extraction treats full containment differently from partial overlap.
For reading **John 8:12–20**, sermon references **John 8:12** and **John 8:12–30** can therefore
count as agreement, while only the first automatically selects the reading.

**Consequence:** choice of representative controls whether uncertainty is surfaced; upstream
“unanimity” does not imply the same output membership or review requirement.

**Concrete scope example, not observed vote disagreement:** discovery run **1223**,
**2023-06-18 morning**, transcript
`storage/app/private/service-artifacts/service-transcripts/2023-06-18/morning-12878414-ce0a-4744-8f59-fdbba9f5d201.normalized.json`.
Reading John 8:12–20 is around **1314.44–1394.28s**; at **1691.30–1718.92s** the preacher
identifies verse 12 as the sermon focus. No saved conflicting votes were established for this run.

**Smallest regression/correction:** the synthetic reference variants above must remain distinct
when they change membership/review. Compare outcomes through the shared membership evaluator,
rather than adding another independent approximation of its rules.

### F09 — Final boundary movement can cross a previously checked content hold

**Classification:** confirmed missing final-span guard; no actual disclosure established.

**Location:** `SermonExtractionPlanResolver::resolve()` lines 257–281;
`CueSafeExtractionPlan::forSpans()` lines 34–83; `app/Jobs/ExtractSermon.php` extraction guards.

Holds and exact-span repair authority are checked against selected section bounds before
word/cue adjustment. The adjusted/merged spans are then checked only for valid source bounds,
not overlap with held neighbours. Authority matching the original bounds also does not by
itself constrain the adjusted media span.

**Consequence:** an allowed selected section can import held content from an excluded neighbour
through boundary widening. Section membership is not a sufficient final media safety check.

**Smallest regression:** unheld sermon ending at **200s**, held neighbour starting at **200s**,
shared cue/evidence that widens the final end beyond **200s**. Assert extraction is held.
Also cover a specifically authorised repair whose adjusted span crosses into another held item.

**Correction:** check final spans against all held intervals and the applicable authority after
adjustment/merging. Audit the section-candidate path too: `PrepareSectionPublicationCandidates`
calls `CueSafeExtractionPlan::forSection()` around line 449. Do not solve by silently cutting
through a word to remain within the old section bounds; an unsatisfiable boundary needs review.

### F10 — Cue anchoring can report a pause where word intervals overlap

**Classification:** confirmed algorithmic counterexample; no overlapping cached example found
in the small word-artifact sample inspected.

**Location:** `CueSafeExtractionPlan::cueBoundaryPause()` lines 135–146, compared with
`pause()` lines 159–173; word-evidence validation is in `OutputEdgeWordTimings::read()`.

The general pause finder tracks the occupied frontier of overlapping words. The newer anchor
path considers only the adjacent word and uses `min`/`max` to manufacture a non-negative gap.
Preceding word **9.8–10.5s**, anchor **10.0–10.3s**, original start **10.0s** yields a reported
pause at **10.0s**, inside the preceding word.

**Consequence:** a supposedly word-safe cut can contain a clipped preceding word or cut through
overlapping speech. F03 is different: it chooses the wrong lexical occurrence even when word
intervals do not overlap.

**Smallest regression/correction:** overlapping intervals at both start and end anchors. Require
a genuinely unoccupied boundary or unresolved-edge review. Reuse one occupied-interval model
for both anchoring and general pause selection. Relevant existing suite:
`tests/Integration/Services/ChurchService/WordTimedOutputEdgesTest.php`.

## 3. Architectural direction discussed with the user

The user noted that boundary rules accumulated in response to real videos and may not be
consistently designed. The proposed response preserves that knowledge rather than deleting
guards or substituting a larger model. This direction was discussed, not authorised for wholesale
implementation or adopted as a replacement for existing operator policy.

### Separate three decisions

1. **What happened in the service?** Detect content blocks and evidence without forcing the
   publication model onto detection. Permit multiple preaching blocks and uncertain relationships.
   “One published sermon” should not force a block into `other` or cause a premature merge.
2. **What belongs in each output?** An explicit ordered membership plan can include multiple
   readings, interrupted sermon parts, and selected prayers while excluding intervening items.
   Continuation/reading relationships should be structured proposals. Free-text notes explain;
   regex matches on those notes should not be the long-term source of extraction authority.
3. **Where can it safely be cut?** Choose supported boundaries within allowed intervals. Pauses,
   word alignment and whole cues are techniques constrained by membership, not authorities that
   can silently replace it. Conflicting evidence or overlapping speech can make a safe cut impossible.

### Shared contracts and final checks

- One membership evaluator serves ensemble comparison, composition, review and extraction.
  Proposal equivalence includes membership and review outcomes, not merely timestamp overlap.
- Operator answers have explicit scope: identity, membership, relationship or boundary. Accepting
  a reference does not implicitly settle its timing. Decisions are bound to relevant source/evidence,
  with explicit supersession and conflict handling.
- Preserve supporting/dissenting detections, transcript and sound evidence, and boundary provenance.
  Do not collapse uncertainty into a confidence score or silently discard it during projection.
- A final, immutable media plan is validated after all adjustments: required content included;
  excluded/held content respected; operator answers still satisfied; nearby unassigned speech
  accounted for; supported, ordered, source-bounded cuts.
- FFmpeg executes that plan unchanged. Technical media checks establish that output matches the
  plan; they cannot establish that the plan selected the right spoken content.
- Introduce these contracts incrementally around existing sections/metadata first. Do not assume
  new tables, a parallel persisted composition system, or dependencies are necessary.

## 4. Proposed delivery slices

These are implementation candidates for a fresh authorised session, not permission to resume
the stopped operation. Each slice is independently reviewable; avoid a wholesale rewrite.

| Slice | Findings | Observable outcome | Minimum acceptance |
|---|---|---|---|
| S1: Preserve settled decisions through replay | F01, F05, F06 | The operator's accepted content is not silently changed, and new warnings reach projected sections | Red→green replay/answer-to-projection tests; contradictory resolutions hold; deterministic behaviour under ordering changes |
| S2: Guard final media spans | F09 | Content excluded by a hold cannot leak through a later edge adjustment | Selected/unselected neighbouring holds and explicit repair-authority tests after widening/merging; inspect both sermon and section candidate consumers |
| S3: Align reading membership rules | F02, F08; fixture F04 | Reading selection and ensemble disagreement use the same semantics | Distant matching reading; containment versus overlap; multipart reading with intervening prayer; correct review outcomes |
| S4: Unify safe boundary selection | F03, F10 | Repeated words and overlapping timing evidence cannot produce a falsely safe cut | Start/end cases, ambiguous alignment, no-word/missing-cache behaviour, existing lone/shared-cue regressions preserved |
| S5: Preserve ownership when sound repairs expose speech | F07 | Fixing a song does not silently discard a sermon conclusion | Exposed sermon-adjacent speech produces a reviewable ownership question; ordinary announcement not automatically absorbed |
| S6: Consolidate publication plan contracts | Cross-cutting | Final validated plan is the sole input to extraction | First consumers are sermon resolution and section candidate preparation; existing rules become proposals constrained by the same invariants |

S1 and S2 are the suggested first priorities. Prefer small fixes proving the invariants before
extracting shared abstractions. S6 should consolidate demonstrated needs, not delay those fixes.
For further investigation, start with I1 (transcript completeness) and I3 (replay stability)
below; that research priority does not displace S1/S2's implementation priority.
No change should erase an existing corpus-derived safety rule merely because it looks irregular.
If a correction changes operator policy rather than faithfully implementing it, identify that
decision explicitly before applying it to real services.

## 5. Validation and evidence handling

- **Completion criterion for every implementation slice:** name the invariant restored, identify
  the downstream stages that could violate it, and demonstrate that it survives those stages.
  A helper returning the expected value is insufficient when projection, refinement, reuse or
  extraction can subsequently change the result. Record the evidence in §6.
- Follow repository test-first, Sail, PHPStan, Pint and appropriate full-suite requirements when
  implementation is authorised. Tests must use isolated fixtures, fake providers and queues;
  never use the preserved operational payloads or real provider calls as test infrastructure.
- Extend the canonical suites, not legacy duplicate suites on the do-not-invest list. No one-off
  tinker/replay scripts where a feature or unit regression provides the same proof.
- Test the complete decision path where necessary: saved answer → validated structure → projected
  section metadata → selected content → final media spans. Helper-only tests cannot establish
  that a later stage preserved the decision.
- Keep synthetic counterexamples distinct from listening-confirmed source cases. For real cases,
  record what was heard, the accepted content membership and boundary tolerance before declaring
  a regression truth. Listening and rulings remain separate acts.
- Preserve rejected approaches and historical rules through existing evidence/tests, not duplicated
  production paths. Add metamorphic tests where useful: answer order cannot change ownership;
  an irrelevant section cannot alter reading selection; a boundary refinement cannot cross a hold.
- Saved candidate membership is in
  `storage/app/private/canary10-safety-fixes-20261004/next-batch-candidates.json`.
  Those candidates have unknown evaluation exposure. Keep them as discovery/development cases;
  establish independent held-out provenance separately before making generalisation claims.
- Other transcripts sampled without a specific new defect established: 2023-01-01, 2023-01-29,
  2023-03-26, 2023-05-14, 2023-05-21 and 2023-05-28 morning. Do not imply this was a corpus audit.

## 6. Completion record for the next session

For each F-number, record: reproduced/not reproduced; exact regression; correction commit;
the restored invariant and downstream preservation proof; focused and required quality results;
observed recording impact if established; and remaining
listening/policy questions. Mark an entry resolved only after the relevant downstream path is
verified. Synthetic proof alone does not prove a historical service was harmed or repaired.

For each I-number below, record the selected sample/fixtures and why they were selected,
evidence inspected, supported conclusion, limitations, and any resulting F-number or policy
question. “No issue found in this bounded check” is a valid result; do not silently expand into
a corpus audit. Do not count an investigation hypothesis as a confirmed defect.

### Completion record — 2026-10-05

All entries below were reproduced by a regression that failed before the correction; that
proof is synthetic. Downstream effect was measured by read-only recomputation over the
canary 10 runs (the only runs with banked ensembles), using saved draws, answers and edge
word caches; no provider calls, writes or dispatches. Gates on the final commit: full suite
(9,246 tests), PHPStan, Pint, Dusk (61).

| F | Commit | Invariant restored | Downstream proof | Canary impact |
|---|---|---|---|---|
| F03, F10 | `ed7476790` | A cue-boundary cut anchors on the cue's opening/closing three-word run, never a nearer repeated word; it lands only in a gap no word is sounding in (one occupied-frontier model shared with the largest-pause rule). An ambiguous run hands the edge to the largest pause. | `WordTimedOutputEdgesTest` through `forSpans` | All 44 word-pause edges identical to `8a5929681` |
| F05 | `dbd66118f` | Replay returns the validator's annotated structure; the answer action projects it. | Answer → projected sections in `DetectServiceStructureTest` | — |
| F01 | `24325d42f` | An answer's settled reading/sermon reference is part of what unanimous output must honour. | Applier unit test + fresh-draw re-detection → projected `readingReference` | — |
| F09 | `6636494ff` | Final spans are checked against every held section the cut does not itself select, after widening/merging; authority does not excuse crossing another hold. Sermon plan → review (`sermon_span_crosses_held_section`); section candidate → blocked (`span_crosses_held_section`), including reused media. | Resolver and `PrepareSectionPublicationCandidates` tests; `ExtractSermon` parks on any `requires_review` | No canary sermon plan crosses a hold |
| F06 | `5bf6dd20c` | Answers whose settled sections overlap (beyond snap tolerance, other than the same section twice) apply in neither order; both are conflicting and their questions stay open. An unpaired answer reopens its own question keyed to its ruling, so a fresh answer revises it. | Applier tests in both orders, unpaired case, revision resolving the conflict | No change (1112's existing conflict is unchanged) |
| F02, F08 | `519511837` | One membership rule (`ScriptureReferenceResolver::sermonReadingMembership`) for extraction's reading choice and the composer's held readings; no time window; references compare by membership relation. When no reading matches, every earlier reading is still held (a voter may misname the preached reading). | Composer and resolver suites; `competing_reading_references…` feature test | Only 1250: Job 29 held, ends at the majority 1519.04 not the tie-break 1527; its last verse ends at 1519.04 and 1519–1527 is silence in the transcript (not listened) |

Corrections to this plan's text: F08's John 8:12 / 8:12–30 example is not a case —
`referencesAgree` accepts 8:12–30 against the reading 8:12–20 by containment, so both
references cut the reading. The regression uses a crossing reference (8:18–30). F05 only
loses flags that the answered whole earns and no single draw did; each draw's own flags
travel with its sections.

Remaining: F04 (fixture plus listening), F07/S5, S6, I1–I5. `flagMissingPreachedReading`
still uses the 900-second window and plain overlap rather than the shared membership rule.
Listening: relisten 1250's Job 29 end at the next canary.

The investigation's main conclusion is that later boundary mutations are not consistently
checked against earlier membership, hold and operator decisions. The smallest useful design
improvement is shared decision semantics plus validation of the final spans—not another layer
of independent service-specific thresholds.

### Completion record — 2026-10-05, second session (branch `fix-boundary-consistency-2`)

Same method: red test first, read-only recomputation of the canary 16 (saved draws and
answers through `ServiceStructureEnsembleReplay`, which re-refines every slot from its raw
draw, so the sound stage is re-run), old code against new. Gates on `2e07b458e`: Pint,
PHPStan, full suite (9,255 tests; the PHPUnit notices are pre-existing, none from the suites
touched), Dusk (61). Workers stayed stopped; no provider calls, dispatches or writes.

| Item | Commit | Result | Canary impact |
|---|---|---|---|
| `flagMissingPreachedReading` | `e9259209c` | Fires exactly when `sermonReadingMembership()` could cut no reading: no 900 s window; a matching, partially overlapping or unnamed reading pairs. | None: no section, flag or question changed. 1311 keeps its flag under both rules (Acts 8:26-39 vs readings Psalm 96 and Acts 16:25–40); it is demoted because the sermon names its passage. |
| F04 | `f395e643f` | Fixture in run 1240's shape (Job 36 → prayer → Job 37 → sermon "Job 36-37"). **Passes on existing code**: the choice goes to review (`sermon_reading_membership_unresolved`), and the explicit three-section selection cuts each reading and the sermon as disjoint spans excluding the prayer. Not a defect. | — |
| F07 / S5 | `0d226587e` | When `SongSpeechEdges` trims a song next to the sermon (start trim after it, end trim before it), the sermon gets `structure_sermon_adjacent_speech_unowned` with the interval in a note. Bounds unchanged, nothing absorbed (D1), **extraction not held** (operator, 10-05). Ensemble flags are a union but notes came from the representative draw, and an answered section kept preserved flags but not their notes; both now carry the interval. | 7 of 16 sermons gain the question: 949 (4602.4–4620.0), 1025 (3953.5–3985.0), 1028 (4098.9–4115.0), 1112 (3717.7/3731.1–3755.0), 1117 (3980.6–4015.0), 1250 (4313.9–4335.0), 1304 (3683.6–3700.0). No edge, section or dispute changed. Transcript text reads as hymn announcements after the closing "Amen" in all seven; 1025's 3953–3980 ("he is our only hope and Saviour… strength to serve him hour by hour") is the one worth a listen. Not a ruling. |
| I3 | `2ba70ce6f` | See below. | — |

Frequency measured before building F07: 27 songs in the local DB carry a start trim, 9 of
them straight after a sermon (1025, 1028, 1060, 1112, 1250, 1258, 1274, 1303, 1340); one song
carries an end trim, not before a sermon. Candidate run 1219 does not show the defect in its
saved structure: the sermon ends at 3838 where the announcement starts (3836.9), so a trim
there exposes only the announcement. It still needs a listen before it is treated as truth.

**I1 — content lost before detection.** Selection, fixed before comparing text: stems with
all four transcript stages (`raw`, `normalized`, `-repetition-recovered`, `-region-recovered`)
sorted by date, earliest/median/latest of the five: 2021-01-17 (run 1340), 2022-12-04 (1250),
2026-04-05 (1009).

- *Limitation:* the stage files are not one chain. All three runs were re-transcribed after
  their recovery passes (1340 and 1009 on 09-17, 1250 by the 09-25 corpus re-run), so `raw`
  and `normalized` are a later decode than the two `-recovered` files. 1250's stamps show its
  repetition stage was derived from its region stage, not the reverse. Comparing across them
  measures decode differences, not stage loss, and was discarded. The current pipeline's
  intermediate between pathology recovery and the stored transcript is not banked separately.
- *1250 (current chain):* raw → normalized is cue-for-cue identical (1,142), and the
  detector input in the banked ensemble is exactly those cues. No loss.
- *1009:* raw → normalized: 951 = 951; one 0-length raw segment dropped. No loss.
- *1340 — finding:* the pathology detector flags 1355.08–1595.06 in the raw decode (eight
  30-second "Thank you." cues). The current transcript has one cue in that window (1582.3,
  "Montgomery Boyce tells the story…") and **no unobservable window**: 1355–1582 (about 227 s)
  carries neither text nor a marker. The previous generation had banked 1348–1588
  `retranscription_failed`. Mechanism, from code: `ServiceTranscriptRecovery::recoverUsing()`
  deletes every original cue in a window once its retry is non-empty, then places only the
  retry's cues and its looping residue. The part of the window the retry left uncovered becomes
  indistinguishable from "decoded, nothing said". The classifier (supporting, not listening)
  reads 1360–1505 as near silence and 1520–1565 as weak music, so no speech is shown lost
  here; the loss is of the uncertainty marker. The 09-17 retry for this window is not banked
  locally or on Sonnics/Staging, so the retry's exact coverage is unconfirmed.
- *Smallest fixture proposed (not built):* `recoverUsing()` with one detected window
  [100, 400] and a retry whose only cue sits at [280, 290] window-relative. Assert the
  uncovered remainder is either marked unobservable or proven silent by the RMS spans the
  retry decoded. Building it needs a policy decision: should a retry's uncovered *sound* span
  be marked `retranscription_failed`?

**I3 — replay stability and locality.**

- *Progressive drift:* not possible on the supported paths. Replay always restarts from the
  saved raw draws; the only automated writer of section times is detection's projection, and
  extraction (`SermonExtractionPlanResolver`, `CueSafeExtractionPlan`, candidates) computes
  widened spans per call without writing them back. `DetectServiceStructure`'s read of stored
  sections is a report-only diff. Existing proof: `test_projecting_the_same_run_twice_is_idempotent`,
  `ServiceSectionSyncServiceTest::it_updates_existing_rows_and_replaces_removed_rows_idempotently`.
- *Same answer again:* answering the same question again with the same content (the answer
  action's next revision) changes nothing (new test). Two answers claiming the *same* revision
  conflict by design (`count($latest) !== 1`) and reopen their questions; the answer action
  cannot write one (row lock, max revision + 1), and none of the 54 stored answers do.
- *Order:* independent answers give identical structure and disputes in either order (new
  test); overlapping ones conflict in either order (F06, existing).
- *Locality — counterexample:* moving an unrelated section leaves the automatic sermon cut
  and selection unchanged (new test). But a **reviewed** sermon composition is bound to
  `inputIdentity()`, which hashes every section in the run (id, type, times, references), so
  moving any unrelated section, such as the first hymn's edge, invalidates the operator's
  selection and sends it back to review. Nothing is cut wrongly; an operator decision is asked
  again. Smallest counterexample: `a_reviewed_selection_survives_recomposition_but_not_changed_section_bounds`
  with the edited section swapped for an unrelated opening song. Natural home: S6.

Remaining after this session: S6 (approved by the operator 10-05 — see below), I2, I4, I5,
the I1 fixture decision above.

### Completion record — S6, sermon plans (branch `s6-publication-plan`)

Operator approved the sermon-first design on 10-05; this session's branch was merged to master
first (`02369f97f`, unpushed). Each step started red. Gates on the final commit: Pint, PHPStan,
full suite (9,265 tests, notices pre-existing), Dusk (61).

| Step | Commit | Invariant |
|---|---|---|
| Dependency-scoped identity | `a1fa5255c` | A composition, and the operator's review of it, is bound to the sermon parts, the readings before them, and every section from the first of those to the first song after the sermon (that song included), plus the coverage evidence. Without exactly one sermon, every section counts. Fixes I3's counterexample. |
| One validator | `67d78a941` | `SermonPublicationPlanValidator` refuses impossible final spans (empty, reversed, overlapping, unordered, past the source) and reports `selected_section_not_cut` and `crosses_held_section` (F09). Coverage is "reaches", not "contains": the word-pause rule may legitimately move an edge inward. |
| Only fresh, validated plans | `f0a2dfce7` | `resolve()` composes every time instead of reading back a stored composition with a matching identity, reuses only the operator's review, and runs the final spans through the validator; violations go to review and are recorded as `plan_violations`. |

**Defect found while measuring:** a stored composition keeps its identity when the membership
rules change, so `resolve()` kept serving 964's 10-04 composition (sermon only) after the
10-05 rule (F02/F08) began matching its reading by containment ("Matthew 5:13-16; John
8:12-18" for a sermon on Matthew 5:13-16). Canary 10 cut 964's sermon without that reading.

Canary 16 (read-only: `resolve()` in rolled-back transactions, master's resolver against the
branch): two plans change, no violations, no errors.
- **964** now cuts its reading 1683.99–1817.82 before the sermon. It starts at the leader's
  hand-over ("I'm going to ask Ralph now… Matthew chapter 5, 13 to 16"); the end falls in the
  pause before "So this is the word of the Lord" (1817.82–1819.52), because the section's own
  end (1818.86) splits that phrase. Listen to the end at the next canary.
- **1250** asks its reading-membership question again once: its reviewed selection
  [3128, 73104, 4910, 73105] was keyed under the full-run identity, the only stored review in
  the database. It was not re-keyed: that would rewrite a stored operator decision.

### Completion record — S6 section candidates and the I1 ruling (branch `s6-section-candidates`)

S6 sermon plans merged to master (`382de9633`, unpushed). Gates on the final commit: Pint,
PHPStan, full suite (9,268 tests, notices pre-existing), Dusk (61).

| Item | Commit | Result |
|---|---|---|
| Validator moved | `ff69b026b` | `PublicationPlanValidator` now lives in `App\Services\ChurchService`, beside `CueSafeExtractionPlan`; no behaviour change. |
| Section candidates | `9247fa8aa` | Fresh cuts, and reused media's recorded cuts, pass the validator. Crossing a hold keeps `span_crosses_held_section`; an impossible cut, or one that misses its own section, blocks that candidate as `cut_plan_invalid` (with `plan_violations`) without stopping the others. Canary 16: 20 candidate sections, all with recorded cuts, no violations either way. **Gap:** 1,243 of 1,263 local candidates have provenance from before cuts were recorded (no `segments`); they keep today's reuse unjudged. Judging them means re-cutting — I4's question. |
| I1 ruling | `26029de5c` | Operator, 10-05: **mark uncovered sound**. `retranscribe()` records a sound span that decoded to nothing, or could not be decoded while its siblings were, as a window-relative `retranscription_failed` window; `recoverUsing()` places the retry's windows on the recording clock (the superseded-transcript fallback can then carry earlier text in). Measured silence stays unmarked. Future transcription passes only; no recovery retries are banked locally, so frequency is unmeasured. 1340's own shape (sound only at the window's tail, silence elsewhere) correctly stays unmarked under this rule. |

### Completion record — I4, I2 and I5 (branch `boundary-investigations`)

S6 candidates and the I1 fix merged to master (`27bc8b8a7`, unpushed).

**I4 — cache and derived-output invalidation.** Traced the multipart sermon 1250 and the
canary's section candidates (songs; the canary holds no talk candidate, so the talk path was
read from code and its tests).

| Artifact | Reused when | Bound to | Verdict |
|---|---|---|---|
| Sermon video | Never reused: every valid plan is cut again; the store step replaces the stored video when spans (±tolerance), duration or `MediaProcessingVersion` differ (`authoriseReplacementIfCutChanged`) | Final spans | Correct: membership, answers and word evidence all reach the spans |
| Sermon audio | Never reused; re-extracted with the video | Final spans | Correct |
| Sermon text | Re-sliced by `CreateSermonTranscriptFromService`, which every pipeline shape runs straight after `ExtractSermon` | Stamp records the service transcript's content hash only (`sermonDerivationIsOwed`) | **Latent gap:** the stamp cannot tell that spans changed; harmless while the pipeline always re-slices after a cut. No fix. |
| Candidate video/audio | `processing_id` + `mediaSignature()` = type, start, end, `MediaProcessingVersion` | Section bounds and a hand-bumped version, **not** the cut (which also follows transcript cues, edge words and cutting rules) | **Defect, observed:** all 20 canary candidates matched their signatures, but 5 would be reused 2–6 s off the cut planned now (1028 §1395 start −2.12, §1398 end +6.22, §1400 start −2.12; 1108 §1901 start −3.54; 1112 §3734 start −2.54), consistent with the 10-05 cue-boundary rule changing cuts without a version bump. **Fixed** `3ff5b7947`: media with a recorded cut is reused only when it equals the cut planned now, otherwise cut again. Legacy provenance with no recorded cut (1,243 of 1,263 locally) keeps today's reuse. |
| Candidate approval facts | Speaker, talk type, song identity | Not media | Correct to reuse (existing test) |

**I2 — included, excluded and unresolved speech.** Runs 1240 (F04) and 1219 (F07): every
transcript cue assigned by midpoint to the sermon output (`compose()` in a rolled-back
transaction), a section's own output (song, short talk), an excluded section, or no section;
plus classifier speech-dominant windows (speech ≥ 0.6, singing ≤ 0.2) inside
song/prayer/other. *Limitation:* neither run has banked edge-word timings, so the account is
by section membership, not final cut; classifier and transcript are evidence, not listening.

| Run | Sermon output | Own outputs | Excluded | Unresolved |
|---|---|---|---|---|
| 1240 | 2,242 s of cues | songs 445, talk 176 | reading 155, prayer 112, notices 14 | 103 (mostly 30 s "Thank you." filler over music before 120 s and 558–620) |
| 1219 | 1,497 | songs 562, talk 331 | prayer 655, notices 81, other 7 | 1 |

Inclusions and exclusions the existing evidence cannot justify:
1. **1240: a prayer inside the sermon output, unasked.** The stored structure has one reading
   §2986 "Job 36–37" (1062.7–1638.8) holding Job 36, "We're going to pray and then we'll
   come back and read chapter 37… Let's pray" (1274.7), the prayer, a 10× "To bring peace"
   loop (1467.7–1487.7, unmarked after repetition recovery) and Job 37 (1488.7). The sermon
   plan selects §2986 with no risk. F04's fixture assumed two reading sections; detection
   merged them, and nothing downstream looks inside a selected section. Listen 1274–1489.
2. **1219 §2698 "prayer" (1247–1772)** opens with ≈4 minutes of a Christian Institute
   missionary item before the prayer; excluded only by its label. Whether such an item is a
   publishable short talk is an operator question.
3. **1219 §2699 song (1772–2037)** opens with the leader's follow-up (literature, "be
   informed", ≈1773–1800+) and ends with the reading's introduction ("Can I invite you to turn
   with me to John chapter 14?", 2023.4–2037.9), inside the song just before the reading the
   sermon output starts with. Decision 16 includes an introduction that names the book. A
   start/end trim would leave it unowned, and F07 asks only beside the *sermon* section, not
   beside a reading the sermon output includes.

Proposals, not built: (a) extend F07's question to speech exposed beside *any* section of the
sermon output (measure first); (b) a detection or plan check for a selected reading that
contains a spoken "let's pray" handover (needs a ruling on evidence: transcript text alone
must not decide membership). The artifacts do support this account at section level; a
cut-level account needs banked edge words.

**I5 — shared errors in apparently clean output. Predeclared 2026-10-05, before any output was
looked at:**
- *Population:* runs with a stored service transcript and a sermon section, excluding the
  canary 16, the discovery runs 1219/1240, and the I1 runs 1009/1340: 440 runs.
- *Selection:* the four runs with the lowest SHA-256 of `processing_id`. Outcome-blind,
  reproducible, and no replacement after seeing results.
- *Exposure history:* recorded per run from memory/plans once selected; unknown stays unknown.
- *Review dimensions,* per output (sermon output, published song/talk sections): missing
  content, unwanted content, incorrect joins, clipped words; each with source-relative window,
  checked extent and uncertainty.
- *Method:* transcript and classifier evidence read across each output's edges (±120 s),
  every gap between outputs, and every internal join, located from the transcript first and
  only then compared with the plan's bounds. No listening (none available to this session):
  every observation is a candidate for listening, not a verdict. Discovery/retrospective
  evaluation; four services establish no corpus rate.

**I5 — results (sample drawn by the predeclared rule after `195b656db`).** Runs 1177
(2024-03-17), 1180 (2024-02-25), 993 (2026-07-12), 1310 (2021-08-15). Exposure: 1177 none
recorded; 1180 song §2381 demoted and one edge checked in earlier censuses; 993 one mixed
section sampled in a listening census; 1310 sections held in an earlier round and its source
ends mid-sermon. Reviewed: every sermon-output and talk edge (reading start/end, sermon
start/end, talk start/end), 1180's long `other`, and the classifier where the transcript was
silent. Not reviewed: song clip interiors. Outputs reviewed: 4 sermon outputs (8 spans), 3
talks. None of the sampled clips has a recorded cut (I4's legacy gap), so section bounds stand
in for clip cuts. Every item below is a listening candidate, not a verdict.

*Missing content* (4 observations in 3 of 4 services; all unflagged except where noted):
1. **1180 sermon opening, 1965–1991.** Classifier speech 0.56–0.92 from 1965; no cue and no
   unobservable window until "Chapter 2, Colossians…" at 1991.0, where the sermon starts.
   The blind window 1811–1963 stops just short. The sermon carries no review flag.
2. **993 sermon opening, ≈2105–2117.** Classifier speech 0.85–0.95 from 2105; no cue until
   2117.1, which opens mid-sentence ("the last part of Joshua chapter 5"). The 12 s sit inside
   song §1187.
3. **1310 talk opening, ≈390–413.** Classifier speech 0.63–0.89 under one 29 s "love you."
   lyric cue (384.2–413.2); the talk starts mid-sentence at 412.8 ("…decide what he wants").
   The 22 s sit inside song §3935.
4. **1180 §2374 "other", 693–1109:** a ≈7-minute talk to non-Christians (forgiveness, the
   Apostles' Creed, the church), unpublished and unasked as `other`.
5. (Known) 1310's recording ends mid-sentence at 3282; the sermon is held.

*Unwanted content / mid-utterance cuts:* 1177 sermon end includes the hymn announcement after
the closing "Amen" (4083.1–4089.8) and ends mid-announcement; 993 talk ends 1.1 s into "Let's
stand and sing again"; 1310 talk ends 3.5 s into "So let's stand and sing it through
together", mid-utterance. 1310's sermon starts ≈14 s before its first speech (music/quiet).

*Clipped words (candidates):* 1177 talk end 1675.9 inside "What comfort, what joy it is."
(to 1677.3); 993 reading end 1469.0 before "…word of God." ends (1469.4); 1180 sermon end
3802.0 where its "Amen" starts; 1310 reading end 1616.8 where the reader's "Amen" starts;
1177 reading end 1804.0 where the next utterance starts.

*Incorrect joins:* none observed; each sermon output joins reading and sermon around the
excluded song/prayer by design.

**Shared error, proposed as F11 (not built):** speech straight after singing is lost before
detection. ASR leaves it untranscribed or folds it into a long lyric or filler cue; the
following section starts at the first real text; nothing marks it. Every voter sees the same
transcript, so agreement cannot catch it (I5's premise). Existing rules miss it: 993's 12 s
tail is under `SongSpeechEdges`' 20 s floor, and 1180's speech lies in a gap no section owns.
The classifier timeline (`audio_timeline_path`) already holds the evidence. Smallest check: a
section start following a song, where the timeline reads sustained speech before the first
cue, raises an ownership/blind-window question; the decode itself may also deserve a targeted
retry (I1's mechanism). Measure across the corpus before choosing; needs an operator ruling.

Four services establish no rate. The sample was not replaced after its results were seen.

Still open: a non-blocking account of unowned adjacent speech and unobservable windows in the
publication plan. Deferred: no consumer reads it yet, F07's flag already reaches review, and
I1's windows now reach detection directly.

Listening at the next canary: 1250's Job 29 end (1519.04), the edges moved by the 10-04
cue-boundary rule, the seven exposed-speech intervals above (1025 first), and 964's reading end.

### Completion record — F11 measured, I2 proposals built (branch `boundary-f11-i2`)

Branched from master `123cf2886` (unpushed). Workers stayed stopped; no provider calls,
dispatches or transcript regeneration. Docker Desktop's file sharing hung mid-session (a fresh
container could not list a project directory); Docker was restarted and only the non-worker
containers started (`docker start`); all six workers stayed `Exited`.

**F11 — measured read-only (scripts and outputs in `storage/scratch/f11/`).** For every section
that follows a song: the first transcribed cue (≤15 s) at or after the section's start; the
contiguous run of classifier windows before it reading speech (speech ≥ 0.6, singing ≤ 0.2,
clipped to the song's start); and how much of that run no cue of ≤15 s or unobservable window
covers ("lost"). A hit is ≥10 s lost. The method finds all three I5 cases (1180 21 s, 993 12 s,
1310 23 s). *Generation:* transcripts carry no stamp and the transcription fingerprint is
identical across runs, so generation is the transcript file a run points at: A
`-region-recovered` (09-08), B `-repetition-recovered` (09-09), C `.normalized` recorded
09-17..09-26 (the re-transcriptions, current decoder), D `.normalized` with no artifact record
(older). Canary 16 spans all four.

| Generation | Runs | Starts after a song | Hits ≥10 s | ≥20 s | Runs with a hit |
|---|---|---|---|---|---|
| A region-recovered | 90 | 336 | 37 (11.0%) | 19 | 31 |
| B repetition-recovered | 86 | 291 | 24 (8.2%) | 9 | 22 |
| C re-transcribed (current) | 20 | 76 | 3 (3.9%) | 0 | 3 |
| D unrecorded normalized | 87 | 293 | 18 (6.1%) | 5 | 16 |
| All | 283 | 996 | 82 (8.2%) | 33 | 72 |
| Canary 16 (14 with songs) | 14 | 54 | 3 (5.6%) | 0 | 3 |

Hits by the section that follows: prayer 33, sermon 20, other 12, reading 9, children's talk 5,
notices 3. *Same-run comparison:* the 13 runs whose current transcript is a re-decode and
which still hold an older stage file: 2 hits in 52 starts on the current decode, 5 on the
older recovered stages of the same runs. Re-decoding reduces F11, it does not remove it.
*Bias:* sections were detected on each run's current transcript, which favours the current
file. Rows across generations are different runs, not a controlled comparison.

Transcript context (`storage/scratch/f11/context.txt`; supporting evidence, not listening):
- **949 (C, canary)** prayer 2706.4: "Alleluia" ends 2685; 2690–2706 speech 0.66–0.83, no cue;
  the prayer's first cue opens mid-sentence "pray. Our Heavenly Father…".
- **1025 (B, canary)** prayer 630.8: 600.8–630.8 is one 30 s "Thank you." filler cue over
  speech 0.54–0.79 from 610.
- **1311 (A, canary)** baptism 1604: 1585–1604 speech 0.75–0.87, no cue, before "Do you confess
  your faith?".
- 1253 (D): "Let's come to the Lord in prayer" and "Lord, let's pray" as two 30 s looping cues
  (521.8–581.8). 1080 (A): the prayer's opening sits under four "How great Thou art" cues
  (634.6–700.6, speech 0.68–0.87). 1119 (B): 86 s of sermon under three ". . ." cues
  (1180–1270). 1342 (B), 1240 (B), 1135 (A): openings under 30 s "Thank you." cues. 928 (D):
  36 s with no cue before "I could preach on it…". 948, 1258 (C): 10–12 s partial gaps.
- *A different defect in the same net:* 1138 (A) and 1280 (A) hit because the "song" section
  before them is speech throughout (296 s and 147 s): a mislabelled song, not a lost lead-in.

**I2 (a) — built** (`f321dd9d2`). *Measured first,* local DB stored structures: 28 trimmed
songs (27 start trims); 9 beside the sermon (F07), 5 straight after a reading before the
sermon (964, 1060, 1112, 1258, 1343; membership decides which count), none beside a
concluding prayer. `SongSpeechEdges` now asks `structure_sermon_adjacent_speech_unowned` on the
sermon when a trim exposes speech beside any section its media may be cut from: every sermon
section, a reading `sermonReadingMembership()` could cut, or the concluding prayer (the sole
prayer straight after the sermon, before the next song — `compose()`'s rule). The note names
the neighbour ("the reading's own words or an excluded announcement?"); still not held. The
old "next to another item" test used sermon → prayer → song, which is now the concluding-prayer
case; its fixture moved to notices and the prayer became a positive test. *Canary replay*
(`ServiceStructureEnsembleReplay`, rolled back, master vs branch): 5 sermons gain a reading-side
interval — 1025 (1756.8–1775.0), 1112 (1851.3–1870.0), 1117 (1803.2–1865.0), 1250
(1527.7–1570.0), 1304 (1638.3–1660.0) — all already carrying F07's question, so no new question;
no bound, section or dispute changed. Every interval reads as a hymn announcement after "This is
the word of the Lord" (1250's is the leader's reflection on Job leading into the song).

**I2 (b) — built** (`86430a442`). `compose()` adds the risk
`sermon_reading_contains_prayer_handover` when a reading the sermon could take (the selected
one, or the candidates under membership review) holds a "let's/let us pray" or "let's/let us
… in prayer" cue with ≥30 s of the reading section after it. It sends the composition to review
(as `sermon_reading_membership_unresolved` does), which holds extraction until the operator
answers; **the selection never changes on transcript text**. The operator's answer is the
existing composition review. *Recall check:* every cue containing "pray" inside such readings
corpus-wide (127): the rest are the readings' own words ("when you pray…", Matthew 6,
Philippians 4); the first rule ("let's pray" only) missed 1197, so the phrase was widened
red-first. *Measured* (`compose()` on all 475 runs with a sermon, rolled back, master vs
branch): 2 change, selection unchanged in both, canary 16 none:
- **1240** Job 36–37: "Let's pray." at 1281.7 (I2's case).
- **1197** Matthew 2:1-12 (1432.0–1583.85): "Let's bow our heads again in prayer together."
  at 1546.2, then the prayer to "Amen" at 1584.3; the prayer section starts at 1586.99, so the
  reading's end swallowed 38 s of the prayer. New case, not yet listened to.

*Operator, 10-05:* (b) keeps holding extraction (composition review), unlike F07's ask-only,
because the span itself is in doubt. **F11 ruled: mark + ask** — record the stretch as an
unobservable window so detection and composition see it, raise a non-blocking question with
the interval on the following section, and treat it as a target for a later re-decode. Not
built yet. Branch merged to master (unpushed).

Gates: Pint; PHPStan (one finding, a redundant nullsafe, fixed `ecd91c8ca`); full parallel suite 9,275 tests with two failures that pass alone (an ffmpeg loudnorm timeout under load, `RepairHistoricSermonTranscriptSpansCommandTest`), notices pre-existing; Dusk 61.

### Completion record — F11 built: mark + ask (branch `boundary-f11`)

Branched from master `a1ebc19c8` (unpushed). Workers stayed stopped; no provider calls,
dispatches or transcript regeneration; stored transcripts untouched.

**Where the window lives: derived, not banked.** Neither offered option works as stated.
Transcription runs before the classifier and before detection, so it has no timeline and no
sections. Detection's input has the timeline but still no sections. A rule that doesn't
depend on sections ("any ≥10 s uncovered classifier speech") finds 453 stretches in 226 runs,
5.5× the anchored measurement, and still misses 13 of its 82 hits. Anchoring on a preceding
"singing" window instead catches 9 (the classifier's singing score is weak). The anchor has to
be a detected section. So `UntranscribedSpeechBeforeSection` runs last in `SoundStage`, per
draw, from transcript + timeline + the draw's sections. It was not added to the transcript's
`unobservableWindows` list, because the two readers of that list treat a window as sound nobody
heard (`SongPublicationBoundaryEvidenceService::gapIsUnobservable()` stops trusting a song end
beside one), and an F11 stretch is speech the classifier did hear. The interval lives in the
question's note on the following section. Detection's draws, the ensemble composer, the ruling
applier, the validator and the stored structure all carry it, and a later re-decode can find it
by the flag. Being derived, a re-decode that fills the gap clears it, and nothing goes stale.
Flag recompute (no timeline) leaves it alone.

**Rule as built** (`854cb6944`, `2018ccd6b`). For a non-song section straight after a song:
take the first cue of ≤15 s whose midpoint is at or after the section's start. Chain back over
cues ending within 1 s of it (993's "the last" | "part of Joshua chapter 5"). Then walk back
over classifier windows with speech ≥0.6, stopping at the song's start, a cue of ≤15 s or a
recorded unobservable window. A stretch of ≥10 s gets `structure_untranscribed_speech_before_section`
with the interval in a note. Bounds don't change. The flag is non-disqualifying in
`SermonAutoExtractionPolicy`. Departures from the §6 measurement, each measured:
- *No singing cap.* Speech ≥0.6 with singing ≤0.2 gives the same 82 hits as speech ≥0.6
  alone, and `AudioTimeline` doesn't carry singing (nor do banked ensemble inputs).
- *Only the stretch abutting the first words.* The measurement summed every uncovered piece of
  the speech run. In long runs (1217: 394 s of cues in a 463 s run) those are gaps between lines
  the transcript holds, not the section's opening.
- *1138/1280's shape excluded* when the song reads as speech (`SoundClass::Speech`) for ≥0.5 of
  its length. The corpus split is clean: 1153 1.0, 1119 0.68, 1280's notices 0.52, then every
  other hit ≤0.33. 1138 and 1217 drop out under the abutting rule.

Tests, red first: 949's shape, 1025's 30 s filler cue, 993's chained opening, the
1138/1280 negative, plus covered/windowed/short/not-after-a-song controls, the composer and
ruling applier carrying the interval note (shared `isIntervalQuestionNote()`, F07's too), and
SoundStage asking only when it has a timeline.

**Measured read-only** (`storage/scratch/f11/corpus.php`, `summarise_built.py`; the built
class over stored sections and current transcripts). 62 of 1,004 starts after a song (6.2%),
58 runs. All 62 are among the original 82, and none is new:

| Generation | Runs | Starts | Hits | ≥20 s | Runs with a hit |
|---|---|---|---|---|---|
| A region-recovered | 90 | 339 | 28 (8.3%) | 13 | 27 |
| B repetition-recovered | 86 | 292 | 17 (5.8%) | 6 | 16 |
| C re-transcribed (current) | 20 | 77 | 1 (1.3%) | 0 | 1 |
| D unrecorded normalized | 88 | 296 | 16 (5.4%) | 4 | 14 |

By section type: prayer 25, sermon 17, other 9, bible reading 6, short talk 4, notices 1.
*Canary 16 replay* (`replay.php`, rolled back, master vs branch): 949 prayer 2690.0–2706.4,
1025 prayer 615.0–630.8 and 1311 other (baptism) 1585.0–1604.1 gain the question. Nothing else
changes: no bound, section, dispute or other flag.

*Sample* (`sample.txt`, 13 windows): most read as a lost opening, with the first cue
mid-sentence (949 "pray. Our Heavenly Father", 1219 "of the fact that you are a unique God",
1222, 1280's prayer "To make us wise…", 1347, 1230). *Limit:* 14 of 62 windows overlap a
>15 s cue with real vocabulary. 4 are loops or lyrics; in about 10 (1011 "The Apostle Peter was
a very confident…", 1240, 1263 "turn with me please to Hebrews chapter 2", 1345 "let's join in
prayer") the words exist in an over-stretched cue (~0.5 words/s, so no word-rate gate separates
them) before the section starts. The question still applies, since the section starts after
them, but "untranscribed" overstates those.

Open for the operator: (1) the note's wording for over-long real cues; (2) on a short talk the
question sets `needs_manual_review`, which moves the talk's publication candidate to
not-applicable until reviewed (4 corpus hits, none in the canary). Sermon extraction is not held.

Gates: Pint; PHPStan (one list-shape finding, fixed); full parallel suite 9,287 passed (notices
pre-existing); Dusk 61.

**10-05 follow-up (`8b29c48d9`):** note wording ruled and built: "Speech with no transcribed line at …s, before this section's first transcribed words: the section's opening, or the end of something else?" Red test first. No note with the earlier wording was ever stored (DB text columns and stored JSON checked), so `isNote()` matches only the new prefix. The flag id is unchanged.

**Listening queue for the next canary** (supersedes the lists above): 1250's Job 29 end
(1519.04); the edges moved by the 10-04 cue-boundary rule; the F07 exposed-speech intervals
(1025 first); 964's reading end; **1197's reading end, 1546–1584** (the prayer inside Matthew
2, I2(b)); **the F11 hits 949 2690–2706, 1025 610–631, 1311 1585–1604**.

## 7. Further bounded investigations — added 2026-10-04

The user requested these additions after reviewing the gaps in the plans. They are **open
hypotheses and research tasks**, not newly confirmed defects or authorisation to execute them
against the parked operation. Start with I1 and I3. Use saved evidence/code inspection first;
run future tests only in isolated fixtures with fake providers/queues when implementation or
testing is authorised. Listening does not itself make an operator ruling. No provider calls,
workers, queued-payload changes, dispatches or publication are authorised by these additions.

### I1 — Content lost before detection

**Question:** does every source speech interval reach detector input, or retain an explicit
uncertainty marker when it cannot? Four agreeing detectors cannot recover missing input.

**Trace:** source timeline → transcription chunks and joins → repetition/region recovery →
normalised transcript → detector prompt. Inspect missing chunk tails, chunk-relative/source-
relative offsets, and suppression of genuine repeated speech as well as hallucinated repetition.

**Bound:** select three services with saved recovery artifacts or chunk joins. Record the
selection before comparing intermediate text. Inspect existing artifacts; identify precise
disagreement windows for listening rather than retranscribing. If required intermediates are
missing, record the evidence limitation; do not regenerate them or substitute confidence claims.

**Deliverable:** for each selected join/recovery window, show what speech evidence survived,
what was removed, why, and whether uncertainty reaches detection/review. If a defect is found,
propose the smallest fixture proving it. A supported negative result is limited to those windows.

**First consumer:** detection-input validation and its existing recovery/review mechanisms.

### I2 — Account for included, excluded and unresolved speech

**Question:** can the final plan explain content ownership, including speech hidden inside a
wrongly labelled song, prayer or `other` section? Complete timestamp coverage is not proof of
correct semantic ownership.

**Bound:** choose two services, preferably reusing the split-reading and spoken-hymn discovery
cases F04/F07 where evidence permits. Map source speech to (a) an output's selected content,
(b) intentionally excluded content with its reason/authority, or (c) unresolved content. Check
inside labelled sections as well as unsectioned gaps. Do not assume ASR absence means silence.

**Deliverable:** a compact interval/accounting view and a list of exclusions that existing
evidence cannot justify. Determine whether current artifacts can support this account before
proposing new detection. This is an investigative method, not a new requirement to review every
ordinary spoken interval manually or to absorb intentional gaps contrary to D1.

**First consumer:** S5's ownership questions and S6's final-plan validation.

### I3 — Replay stability and locality

**Question:** do composed decisions reach a stable result, and can unrelated changes move a cut?
Deterministic execution of the same original inputs does not prove idempotence of transformed
output or independence of genuinely unrelated decisions.

**Bound:** inspect the existing canonical suites and add a small isolated fixture set when
testing is authorised. Keep source/evidence/rule versions fixed. Test:

- Repeating supported composition/projection/revalidation paths does not progressively move
  boundaries or alter membership; compare semantic output, excluding expected audit timestamps.
- Reapplying the same saved answer does not alter content or duplicate effects.
- Reordering independent answers, with immutable identities unchanged, does not alter ownership.
- Changing a section outside the output's actual dependencies does not move its cut or change
  reading selection. State why it is independent; a relevant song/reading is not a valid control.

Do not feed a composed structure into a raw-detector interface merely to manufacture a failure;
exercise actual supported replay/refinement paths and their handoff types.

**Deliverable:** invariant tests or a documented existing proof for each property; smallest
counterexample for any failure. No saved operational replay or new provider draws are needed.

**First consumer:** S1/S3/S4 and the existing ensemble replay/section projection paths.

### I4 — Cache and derived-output invalidation

**Question:** does a reused artifact still represent the current content decision, not merely
valid bytes from the same source or cutter version?

**Bound:** trace one multipart sermon and one standalone talk through reuse predicates and
saved receipts. Inspect how changes to selected membership, references, operator answers and
word evidence propagate to video, audio, transcript excerpts and publication candidates.
Do not regenerate media. Check whether each change actually alters an artifact's semantics;
irrelevant metadata changes should not require needless encoding.

**Deliverable:** a dependency-to-artifact table showing reuse allowed/invalidated and the
identity/hash/version that establishes it. Identify missing dependency bindings with a minimal
isolated regression. Correct custody hashes prove bytes survived, not that they implement the
current decision; conversely an unchanged effective plan may legitimately reuse its media.

**First consumer:** existing media signatures, recorded-output provenance and candidate reuse,
feeding S6. Do not introduce a parallel cache/provenance system.

### I5 — Shared errors in apparently clean, unanimous output

**Question:** can evaluation detect content every voter omitted or misassigned, including when
no question was raised? Agreement and low question counts are not omission-recall measures.

**Bound:** before looking at outputs, predeclare a small source-based sample (initially four
services), selection method, exposure history and review dimensions. Include unanimous,
apparently clean output rather than selecting only known failures. Use existing artifacts and
targeted source review; record evidence gaps rather than buying new draws. Fix the sample before
review and do not replace difficult cases after seeing their results.

**Deliverable:** separate observations for missing content, unwanted content, incorrect joins
and clipped words, with source-relative windows, checked extent and uncertainty. Source review
must be independent of merely accepting the detector's boundaries; it does not require a second
human. Report denominators and limits; four services cannot establish corpus accuracy. Prior
exposure that is unknown stays unknown. Call this discovery/retrospective evaluation unless
held-out provenance is actually established.

**First consumer:** the focused historic-video plan's acceptance evidence. This task supplements
its existing source-based review; it does not replace or retroactively alter a predeclared
canary threshold, declare a pass, or authorise release. Any new numeric acceptance threshold
needs its own prospective decision.

## 8. One pattern for review questions — added 2026-10-05

**Status:** planned, not built. Operator discussion 2026-10-05, after F11. Measure (§8.5)
before proposing build slices; build after the next canary unless the operator says otherwise.

### 8.1 Why

What a review question *does* currently comes from which lists its flag is on and from the
section type. The question itself doesn't declare it.
- *Sermon output* (sermon, selected reading, concluding prayer — every section
  `SermonExtractionPlanResolver` cuts from): allowlisted in
  `SermonAutoExtractionPolicy::NON_DISQUALIFYING_REVIEW_FLAGS`, so ask-only. Anything not on the
  list blocks extraction, so a new flag that misses the list silently becomes a hold.
- *Short talks and songs:* any `needs_manual_review` moves the section out of publication
  candidates (`PrepareSectionPublicationCandidates`); ask-only cannot exist there.
- *Prayer, notices, other:* not published; a flag is only a queue entry.
- *Answers:* ask-only questions are answered by `ConfirmServiceSection`, which clears **every**
  flag on the section. The confirmation does not appear to survive re-detection
  (`ServiceSectionSyncService` carries content holds and song-match reviews forward, not a
  general confirmation), so a re-run re-asks. Holds have durable answers (composition review
  keyed to input identity, ensemble rulings replayed, content holds carried by sync).
- *Weekly and historic differ in pipeline semantics.* A weekly sermon is created
  `Published` and is public with its questions open. A historic sermon is created
  `Quarantined`, and `HistoricReleaseReviewHolds` refuses release while any sermon or short-talk
  section has `needs_manual_review` (plus `SpanQuestioningFlags` elsewhere). That gate is a
  review rule weekly doesn't have. It breaks the 09-25 ruling (no historic-only pipeline
  behaviour): processing the historic corpus is not testing the weekly review semantics.

### 8.2 Rulings (operator, 2026-10-05)

1. **One standard pattern.** Each question declares its consequence once, and every gate reads it.
2. **Hold vs ask, by the mistake the output could contain.** *Hold* when the output might
   *include* something it shouldn't (I2(b)'s prayer inside a reading, a sung span inside a
   sermon, a merged interruption): the cut waits for the answer. *Ask* when the output is right
   but might *omit* something at its edge (F07, F11, a missing preached reading): the work is
   done before the answer.
3. **Nothing with an open question goes public, on either path** ("option B"). Hold and ask
   differ only in whether the work is done before the answer.
4. **Not by reusing quarantine.** Weekly runs on the server; historic runs locally with a
   promotion step. The review rule is shared; custody and promotion stay path-specific tooling.
5. **F11's note wording:** say "speech with no transcribed line" rather than "untranscribed".
   About 10 of 62 corpus windows hold the words in an over-stretched (>15 s) cue.

### 8.3 Design

1. **Consequence declared once.** Each review question declares `hold` or `ask`, probably on its
   `DetectorCatalogue` entry, which already records surface and severity. Every gate reads it:
   sermon extraction (replacing `NON_DISQUALIFYING_REVIEW_FLAGS`), section publication
   candidates (so ask-only exists for talks: an asked talk is still extracted and becomes a
   candidate), and `SectionReviewFlagPolicy`. Flags the policy demotes today (cross-type
   inversion, benediction suspect, conditional types) need an explicit place in the scheme, not
   a third implicit list. A test pins every existing flag's current consequence, so slice 1
   changes no behaviour.
2. **A publication gate on the content.** One predicate, shared by both paths: "this
   sermon/talk/song has an open question that blocks exposure". It reads the review state of
   every section the published output is cut from. *Enforced at the read:*
   `SermonExposurePolicy::isWholeContentPublic()` (and the song-video equivalent) also requires
   no blocking open question. Weekly content is created as now and becomes visible when the
   last question is answered: no file moves, no state flip. Its exposure-input list and cache
   eviction must include the review state.
3. **Historic promotion consumes the gate instead of defining one.** Quarantine and promotion
   are unchanged as custody tooling. `HistoricReleaseReviewHolds` stops carrying its own rules
   (`SpanQuestioningFlags`, "any flagged sermon or short talk") and calls the shared predicate
   as a pre-flight: don't promote what the pipeline wouldn't expose. Run exclusions stay as
   they are (that's an operator ruling about the recording, not a review question).
4. **Durable answers.** Answer per question, keyed to flag + interval + evidence identity,
   recorded like composition reviews. A re-run doesn't re-open an answered question, and
   answering one doesn't clear another. Replaces blanket confirm for ask-only questions.
   Under option B this matters more: an unanswered re-asked question hides content.

### 8.4 Open questions (check, don't assume)

- **State across promotion.** If historic answers are given locally before promotion, the
  pre-flight covers it. Does a question raised or re-derived on the server after promotion hide a
  historic sermon there? Under one rule it should; measure the effect.
- **Every read surface.** Listings, sermon page, feeds, sitemap, API, the church-service page,
  song videos, and the public caches (`SermonExposurePolicy`'s exposure-input list,
  `SermonObserver` eviction). Find any read that bypasses `isWholeContentPublic()`.
- **What the gate reads for a sermon output.** The sermon's own section, the selected reading
  and the concluding prayer (from the stored composition), or the run's sections as the release
  gate does today (macro sections run-wide)? Pick one rule and measure the difference.
- **Production.** The read-side change is a prod deployment and goes on the
  "before weekly resumes" list ([[weekly-processing-paused-during-historic]]).
  Already-published weekly sermons with open flags would disappear on deploy; count them on
  prod (local data proves nothing about prod).

### 8.5 Measure first (read-only)

1. Every review flag in use: current consequence on each path (sermon output, talk/song
   candidate, historic release, weekly exposure) vs its proposed hold/ask class. List
   disagreements.
2. Under the design: how many local sermons/talks/song videos the gate would hide, by flag;
   how many of those are already released.
3. Re-ask cost: sections with a recorded confirmation whose flags a re-detection would re-raise
   (replay against banked draws, rolled back).
4. Read surfaces that don't go through `isWholeContentPublic()`.

### 8.7 Measured 2026-10-05 (read-only; branch `review-pattern-measure`)

Scripts and outputs in `storage/scratch/review-pattern/`: `consequences.php` (each flag through the
real policies), `gate.php` (the §8.3 gate over local sermons, talks and song videos; fresh
compositions inside a rolled-back transaction, since `compose()` writes), `replay.php` +
`reask.py` (banked draws through the current code, rolled back), `flag-inventory.tsv`,
`gate-summary.txt`. Nothing was written to runs, sections or media.

**(a) Current consequence vs the inclusion/omission rule.** `SectionReviewFlagPolicy` raises a
question for every flag on every type, except `structure_oos_cross_type_inversion` and
`structure_benediction_suspect` (never), and low-confidence/micro/macro/OoS-mismatch on
welcome, notices, prayer and other. Sermon output: the 12 flags on `NON_DISQUALIFYING_REVIEW_FLAGS`
are ask, everything else holds. Talk and song candidates: any question holds (the media is cut
first, then the candidate goes not-applicable); `structure_ensemble_disagrees` and a content hold
stop it before the cut. Historic release: any question on any sermon or short-talk section of the
run holds the sermon; elsewhere only the four `SpanQuestioningFlags`. Weekly: a sermon is public
on creation, so ask = public with the question open. Disagreements:

1. **Defect: `structure_sermon_contains_sung_span` holds nothing.** The rule (and §8.2's own
   example) says hold. Until `fbfd9d1cd` (10-02, the cut-from-sections rewrite) the resolver
   turned the flag into a `sermon_contains_sung_span` composition risk. That rewrite dropped it,
   and `SungSpanInsideSermon`'s docblock still says the planner holds. A sermon with an
   unclaimed hymn inside (885/949's case) now auto-extracts, and on weekly it publishes. No local
   section carries the flag today, so nothing is exposed by it.
2. **Text questions on a right cut.** `transcript_repetition_suspect` and
   `sermon_text_predates_evidence` say the *text* includes words nobody said (inclusion), while
   the media is right. Today they're ask, because holding blocks the regeneration that repairs
   them. The rule needs to say which output it judges. Under option B they hide 67 and 30 sermons.
3. **Label questions fit neither class.** `published_reference_contradicts_sermon`,
   `structure_oos_same_type_inversion`, `talk_speaker_review`, `unmatched_song_section`, the song
   identity flags: the sound is right and the label may be wrong. Today: ask on the sermon output,
   hold on candidates.
4. **Omission flags that hold today:** `section_truncated_by_source` (the recording stopped
   early), `sermon_evidence_incomplete` (text blind), `structure_talk_interrupted` (talk ending
   cut off). By the rule these are ask.
5. **`structure_talk_audio_dropout`** is neither inclusion nor omission at an edge (a dead feed
   inside). Today it's ask on the sermon output and hold on a talk.
6. **Ask can't exist on talks.** F11 on a short talk (4 corpus hits) holds the candidate (§8.1).
7. **The concluding prayer's own doubts are never asked.** Low-confidence, micro and macro flags
   are demoted on prayer, although a prayer is cut into the sermon. On the selected reading they
   hold.
8. **Release doesn't read the reading or the prayer.** F11 on a concluding prayer (25 corpus hits
   on prayers) or a reading question releases with the question open: 3 historic sermons
   (1154 and 1244 low-confidence reading, 1203 same-type inversion) are gated by their own output
   but not by release.
9. **Release reads other outputs' questions.** 13 historic sermons are held only because of a
   children's talk in the same run (`talk_speaker_review` ×5, low-confidence ×3, repetition ×3,
   micro, interrupted).
10. **Song publication review** (`song_publication_review` reasons, its own surface) is already
    shaped as ask: the clip is cut, then approval waits. Trailing content and spoken framing are
    inclusion, so hold by the rule; opening/closing missing is omission. No local song has
    reasons outstanding.

**(b) What the gate would hide locally.** 1,265 sermons with runs (443 historic, all quarantined;
822 non-historic, all published). 808 of the 822 hang off placeholder runs with **no sections**
(created 2026-05-04 21:05–21:16, a legacy backfill): the gate needs an explicit rule for content
with no review state. Of the 14 with sections, the composition gate hides 6, all published (3
because a fresh composition now asks which reading, 3 for `transcript_repetition_suspect`), and the
run-wide gate hides 7. Historic: composition gate 157, release today 178, none released. Of the
157, 50 are hidden only by ask-class flags (repetition 25, text predates 24, missing reading 6,
same-type inversion 3, parts not extracted 2): option B's addition. By flag (composition gate):
content hold 77, repetition 67, text predates 30, missing reading 25, reading membership 21,
material risk 6, low confidence 6, same-type inversion 6. Short talks: 218 sections, 53 open (21
pending approval, 32 not applicable); no talk is published from a section locally (the 3 published
children's talks have none). Song videos: 491, 180 with an open section (89 with no recorded flag,
79 content holds, 7 macro); none of the 36 published has an open section. *Production is
unmeasured* (local proves nothing about prod; §8.4).

**§8.4 What the gate reads for a sermon output** (historic, 443): G1, the sections the composition
selects (reading + sermon 346, sermon alone 85, + prayer 8): **157**. G2, release today (any
sermon/talk question run-wide, span flags within reach): **178**. G3, G1 plus run-wide span flags
on other sections (the 1040/1198 macro-song case), without talk questions: **167**. G2 − G1 = 24:
13 talk questions, 10 macro/micro songs, 1 from an older run of the same sermon. G1 − G2 = 3 (the
reading questions above).

**(c) Re-ask cost.** No section locally has ever been confirmed (`manual_review.confirmed_at`
on 0 of 4,733; answers have gone through ensemble rulings, 13 runs, and composition review, 1).
So the cost isn't observable here; the mechanism is. *Claim checked, half right* (throwaway
integration test, `ConfirmationAcrossSyncTest.php.txt`): on an **unchanged** section, sync
**keeps** `manual_review.confirmed_at` (metadata is merged) but takes `needs_manual_review` and
`review_flags` from the new detection, so the question reopens with the confirmation still
recorded. Only the dashboard's low-confidence path reads it. On a **moved** section the record is
dropped. Replaying the 16 canary banks on current code re-raises every stored detection flag on
the 240 sections that still match (`oos_cross_type_inversion` 18/18, micro 17/17,
low-confidence 15/15, …) and adds 10 new ask questions (F07 ×7, F11 ×3). Content holds come back
through sync, song identity through matching. So every Confirm answer is re-asked by any
re-detection.

**(d) Reads that bypass `isWholeContentPublic()`.** The PHP predicate guards single-record pages
and assets (sermon page, assets, URL builder, legacy redirects, canonical URL, sitemap tags,
thumbnails). Every listing uses an SQL twin: `SermonBuilder::publiclyReleased()` (API, the
`SermonRepository` listings that also feed the podcast feeds, `whereVisibleInSitemap()`, route
canaries), and raw `where publication_state = published` in `SermonRepository` (l.298),
`PreacherListCache`, `PublicServiceContentEligibility` (the church-service page). Song videos have
only `SongVideo::publiclyReleased()` plus a PHP check in `PublicChurchServiceArchiveService`.
`SongUsageReport::publiclyReleased()` exposes which song was sung, a label that song-identity
questions doubt. `Preacher::latestSermon()` has no publication filter (unused today). Cache
eviction (`SermonObserver`) fires only on the sermon's own `EXPOSURE_ATTRIBUTES`; a section's
review state changes on a different row. So read-side enforcement needs the SQL form as well,
or a stored per-sermon/per-video "open question" fact that both forms read and that eviction
watches.

### 8.6 Proposed slices (after measurement)

1. Consequence declared once; gates read it; pin test; no behaviour change. Ships the F11
   wording change if not already done.
2. Durable per-question answers.
3. Shared publication predicate + read-side enforcement (weekly) + promotion pre-flight
   (historic), replacing `HistoricReleaseReviewHolds`' own rules.
4. Ask-only for talks (candidate proceeds, exposure waits).
