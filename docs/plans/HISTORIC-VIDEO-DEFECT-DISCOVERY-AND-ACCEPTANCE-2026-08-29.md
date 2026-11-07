# Historic Video Defect Discovery and Acceptance Plan

**Current worklist — reconciled from recorded evidence 2026-11-06.** The 11-02/03 round and
its subsequent repair sessions are closed. Later fixes still need pipeline application and
current-output acceptance. Historic outputs remain quarantined; no release batch is authorised.
Weekly processing stays paused until historic completion (G5/R8, reaffirmed 11-06).

This cleanup made no fresh corpus/production census and ran no processing. Detailed execution
history through 11-06 is in the [archived record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-11-06.md).
Use the current work below; an archived “next”, “owed” or “final round” is not a second backlog.

## 1. Outcome and boundaries

Turn source recordings into clean, complete videos of talks and songs with minimal manual review.
Produce faithful, usable historic outputs through the shared weekly livestream pipeline, then
accept exact artifacts before the programme's separately authorised release. Processing completion,
content acceptance, delivery verification and release authority are distinct.

| Responsibility | Authoritative document |
|---|---|
| Binding operator rulings | [Rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md) |
| Starting, freezing, watching, stopping and closing a local round | [Round runbook](HISTORIC-ROUND-RUNBOOK.md) |
| Output-quality checks, evidence, sampling and agent-first escalation | [Post-round audit](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md) |
| Song measurement units and protocol | [Song acceptance](HISTORIC-SONG-ACCEPTANCE-MEASUREMENT-2026-10-11.md) |
| Production census, cross-source convergence, release and retirement | [Programme](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) |

Follow those owners rather than copying their procedures here. The audit owns the operator's
2026-10-29 preference for Codex/Claude doing supported checks before escalating residual questions.
Containment is not acceptance; a missing or unassessable check is not a pass.

### 1.1 Outcome review — handoff for Claude (operator agreed 2026-11-06)

The operator accepted the design review with two explicit corrections: **weekly processing stays
paused until historic completion**, and **minimising manual review is a goal**, stronger than merely
keeping the queue manageable. The [rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md) owns
the amended decisions; §4.1c below owns their implementation follow-up.

- G5/R8 are reaffirmed. Do not pursue the rejected proposal for early weekly resumption.
- G7/Q15 replace "the review queue is the point": minimise operator work while measuring complete,
  usable outputs, material omissions and contamination. Escalate consequential residual uncertainty.
- G8 keeps full corpus comparison but requires a reason for repeating model work, rendering or
  listening. Reuse valid evidence; use the audit's bounded stopping rule and E23's soft-miss policy.
- K20 corrects the K17 overgeneralisation. K18's passage-name check is evidence, not the meaning of
  "sermon introduction"; 1301's omission remains a known gap, not an accepted exclusion.
- D24 retains the ensemble provisionally and requires source-first checks of unanimous results.
  K21 calls for a measured replacement of consequential middle/earlier-line ending selection.
- E27 sets the overlap priority: substantive speech/complete singing, musical introductions, then
  natural transitions. Incidental overlapping speech can be necessary; a fade cannot isolate it.
- G12 makes each talk/song independently renderable; architecture AM8 owns the orchestration
  follow-up. Unrelated sermon enrichment should not gate song preparation.

**Status:** decisions and planned work only. No processing code, outputs, holds or release status
were changed by this write-up. The §4.1b K19 reach-back was separate work, answered and built
11-06 on its own listening page; this review did not answer it. Preserve specific
rulings under Q13 and test measured changes before adoption. The architecture work retains its
existing sequencing; this review does not make a broad refactor a new prerequisite for the round.

## 2. Completed work and archived evidence

| Recorded closure | Evidence and practical consequence |
|---|---|
| 11-02/03 round | Snapshot `round-20261102a`: 420/423 members and 13/15 imports completed at close; these are historical counts, followed by repairs, not today's acceptance denominator. Host encoder retained at `--jobs 2`. |
| Stuck runs and held compositions | 11-03 repairs covered 1148/1150/1178/1190/1196/18424/18432; 11-04 resolved compositions on 962/1042/1197/1148/1150. All five latter runs completed. New transcript-review items remain below. |
| Deleted clips and re-approvals | All 126 clips deleted by the 10-29 replay were re-cut in the November round. The four previously predicted re-approvals were unnecessary after spans were restored. Do not repeat either old checklist. |
| No-recorded-song class (E26) | Built and applied 11-04: announcement-only candidates no longer make song clips; three pending candidates became `not_applicable`. This does not clear other song holds. |
| Container access and asset audit | 21 host-valid clips became readable after recreating the stale app container. Audit disk/batch resolution was fixed. Fresh-container checks remain in the runbook; the old “1,507 lost files” reading was never a loss count. |
| Promotion and Scripture fixes | Standalone historic recuts now include promotion; output-generation verification added. Oversized Scripture tracking tokens are stored whole and failed storage is surfaced. Code fixes are recorded complete; data application/verification is still owed below. |
| Introduction/start fixes | K18 recognition, K19 opening preservation and quiet-speech/dead-feed protection built 11-06. These are measured code changes, not proof that current delivered outputs incorporate them. |

Exact commits, tests, measurements, listening answers, backup receipts and rejected variants are
in the [11-06 snapshot](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-11-06.md).
Earlier history remains in the [10-29 record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md)
and its linked September/October logs. The rulings register owns decisions; the audit owns acceptance.

## 3. Current measurement status

Recount eligible runs, open/unassessable rulings and changed output generations on final code.
The 11-05 local inventory and 11-06 samples are dated evidence, not final release membership.
The last recorded ruled-endings result is 659/659 after the K19 reach-back was built and its six
moved captures rebaselined on their own evidence (§4.1b). Those captures still need recapture after
the change is applied to outputs.

Discovery stopped at 45 services after two clean batches. The supplemental Luna review inspected
100 services / 1,116 section contexts without a new confirmed boundary class; the operator stopped
its remaining round-4 A/B work on 11-06. Do not resume it from an older audit handoff. These results
neither clear known defects nor establish semantic/audio acceptance. The audit's current ledger
records the full-decode and complementary-media checks, their limits and what must be rebound.

## 4. Remaining work in execution order

### 4.0 Round status

No round is active or newly authorised by this plan. Finish and measure the chosen repairs,
then follow the round runbook for freeze, preflight, staged start and closure. Do not reuse old
423-run/15-import routing lists without reconciliation.

### 4.1 Current processing work

§4.1a is the pre-freeze sequence; §4.1b separates remaining repairs from post-round verification.
The outcome-review follow-up in §4.1c informs that work without making a broad architecture
refactor a new prerequisite. Preserve accepted limitations and specific operator rulings.

### 4.1a Before the next round: regression coverage and combined verification (2026-10-29)

1. **Settle the current start work.** K19 reach-back is built and ruled (§4.1b); opening thanks needs
   no rule; 1301's substantive introduction remains a K20 miss. Write reproducing tests before fixes,
   preserve handover/song counterexamples and measure each change. Do not revive the rejected broad
   first-sentence rule or closing-prayer prompt experiments from the archive.
2. **Replay final code across the eligible corpus.** Compare recorded executed cuts with plans,
   including internal joins, refusals and review consequences. Disks must be read-only: a database
   rollback does not undo file deletion. Reconcile section identities and latest rulings, with
   explicit reuse/reprocess/unassessable dispositions under G8. Use real banked evidence; missing
   edge/K16 evidence is a prerequisite to gather, not a pass or an invented stand-in.
   **First replay RUN 11-07** on `f80bf4fdf` (`storage/scratch/final-replay-20261107/STATUS.md`): 452 runs, 44 min.
   No moved edge breaks its latest ruling; 0 review-flag changes. Changed: 5 full sermon plans, 17 sermon starts,
   12 K18 part additions, 13 songs (945 never cut, 1295 held), 2 talks, 10 structures (split-song/music snap).
   **430 sermon ends are unassessable** (K16/edge words under signature v16 are missing), so the next round's edge
   step is the prerequisite for a complete comparison. The first pass leaked 4 writes after a deadlock (983/1042
   `introduction: null` + `updated_at`; restored 11-07, see below); the guarded pass committed nothing.
   **Edge step on the 42 changed runs, 11-07** (`historic-import:prepare-output-edges`, 15 min, 0 failures;
   945/1019 left out as stopped runs; `storage/scratch/edge-step-20261107/`). Their word decodes were already
   banked under v16 (45 s of Whisper in all); the missing evidence was the K16 answers and sentence checks.
   Replayed after it (`final-replay-20261107-after-edge/`): all 42 are assessable. No moved edge breaks its
   latest ruling (16 met, 12 have no ruling). 0 review-flag changes. 30 sermons change:
   K18 adds a first part on 990/1040/1074/1169/1172/1224/1313/1315/18434 and leaves the existing edges alone;
   962 now ends at 3798.94, the cut its 10-29 ruling preferred; 893's last end moves −27.3 s (met).
   **1337 FIXED 11-07 (held content, not held bounds).** K18's introduction starts at the song section's end
   (1773.94), and the cue-safe start rightly widened to the first spoken word, 1770.33. But song 4276's section
   had grown past its music over that sentence: its own cut ends at 1769.42 (E24), and its wrong-song hold was
   recorded on 1659–1767.49. `heldSectionsCrossed` now judges a held song, talk or reading by its own planned
   cut plus its holds' recorded spans, inside its bounds. A held sermon, or a held section whose cut cannot be
   planned, keeps its whole span. Measured on all 35 runs with a held section: only 1337 changes (no longer
   refused; same cut, end 3818.96 against ruled 3819.0). 1304 (inside 3870's hold span), 1049 (12 ms into
   1572's boundary hold) and 1347 (song into held sermon 4386's bounds) stay refused
   (`storage/scratch/held-crossing-20261107/`).
   The first replay pass's four leaked rows were restored 11-07 to their binlog before-images (JSON and
   `updated_at` verified identical).
3. **Record the required routes.** 942/974/1363 retain X7's hand-edited structures (`reExtract`, no
   recompose). 1295 remains held under S10. Split-song fixes need recompose; 1120's lyric extension
   cannot be reached by extraction alone. K18's extraction-plan fix reaches 942 without structural
   edits. Derive every other route from current dependencies rather than copying the old round.
   **Routes derived 11-07** from the replay (`historic-import:review-inventory`, `routes.json` in
   `storage/scratch/review-inventory-20261107/`): 435 runs. Edge step only: 391. Re-extract sermon: 27 + 5 without
   the edge step. Recompose: 10 (1020/1073/1106/1138/1220/1261/1294/1308/18422; 945 is a stopped run and is routed
   through its own failure first). Section re-cuts: 945 and 1295 (1295's four songs stay held under S10). X7's
   942/974/1363 need no recompose (their structures replay unchanged). The edge step was run on the 42 changed
   runs (step 2); the rest is the round's first stage (runbook §4.2, "Edge evidence for the rest of the corpus").
4. **Verify changed and unresolved outcomes.** Keep internal-join coverage (1208/1235/1250 and the
   split-sermon joins), all applicable heard-edge guards, and explicit open-defect fixtures. Recount
   old unassessable/miss cohorts rather than treating 17/87/98 as current counts. Use E23 dispositions;
   reconcile named old misses through the audit's residue ledger, not silent closure. Capture 1096's
   10-31 acceptable ends if still absent from the regression evidence. Reconcile the 1262 reading/join
   cases and 1108/1215 song-edge cases against current identities and rulings.
5. **Run the applicable quality gates on final code.** Focused regressions, PHPStan, Pint, full
   parallel suite and Dusk for browser behaviour. Rebind captures after newly gathered edge evidence;
   keep a fixture's expected output separate from its listening ruling. Review conflicting changes
   under Q13 and E27. No processing tests run in the round clone.
6. **Freeze and execute only through the runbook.** Fresh membership, revision/worker checks,
   storage headroom, backups and pause/resume preflight; representative encoded transitions and
   internal joins before expansion. At close, verify actual destination generations with hashes,
   application-container readability and the post-round items below. Acceptance follows under the
   audit; completion is not a release decision.

### 4.1b Exploratory pre-round findings (2026-11-05)

Reconciled through the 11-06 record; older interpretations and exact evidence are preserved in
[the snapshot's §4.1b](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-11-06.md#41b-exploratory-pre-round-findings-2026-11-05).

| Work/status | Next action and evidence |
|---|---|
| **K19 reach-back — built 11-06** | `openedOnStraddledInvitation()`; operator answered “Opening Invitations” (record below). Changed starts 1244/1247/1250 join/1308/1339 need recapture after application. 954 span 1's invitation is still dropped by the dead-feed move (unassessed). |
| **1301 introduction — known miss** | K18's current passage-name mechanism misses it; K20/§4.1c own the content-based correction. No-passage stretches on 1044/1258/960/1163 need evidence-based disposition, not automatic inclusion. |
| **Opening thanks / 1233 — no rule** | Operator 11-06 “Opening Thanks”: either on 941/1128/1230/1117, A (keep the thanks) on 1258, so no opening-thanks rule; rulings `operator-20261106:*` in `gather.py`. 1233's preferred start about 1651.0 remains an open-defect fixture (needs a wider decode window). |
| **Quiet spoken openings — built, apply/verify** | `transcribedSpeechSounds()` protects 1308 and 928's internal reading start. 1308's announcement reach-back is now K19's (built 11-06, 1513.54 → 1509.34). 1136 is now an accepted source limitation (keep 15.06); 1354 drops only an optional invitation under K19. |
| **K18/K19 captures — evidence refresh** | Changed starts need current edge/K16 evidence and recapture after application. K18 starts at 0.0 on 1027/1053/1072 currently lack decoded edge evidence. Preserve 1040's either and 1154's preferred exclusion; apply Q13/E27 to conflicts. |
| **Split songs — built, application pending** | 1294 is ruled one continuous song, one clip; do not ask again. Recompose applies the blind-window/music snap fix (also affects 1073/1106 and other measured spans). Verify actual clips, identity holds and continuity. Evidence: `storage/scratch/split-song-snap-20261105/`. |
| **Ten stale main-output generations — repair built, data verification pending** | 1025/1055/1133/1199/1214/1286/1299/1305/1325/1349 retain old quarantine generations. Preserve staged bytes; the recorded recovery route is the next full extraction/promotion chain. Under G8, any narrower recovery must be explicitly evidenced. Verify video/audio/transcript together with `historic-import:verify-output-generations --hash`. |
| **33 Scripture refreshes — code fixed, application pending** | Verify the migration/schema, retry through the chosen round and check current passage timestamps/content. Exact sermon IDs are retained in the snapshot §4.1b; no refresh was performed by the diagnosis. |
| **1225/1250 degraded analysis — post-round check** | Verify fresh analysis, cleared degraded flags, transcript stamps and displayed metadata after the planned extraction. Use the narrower existing reanalysis route if extraction is not needed. A completed run alone does not settle this. |
| **Audible edge and transcript residue — acceptance follow-up** | Preserve the heard clipped endings 1235 §2915 (“now”) and 1236 §2936 (“land”) as regression cases; reconcile repairs/current outputs. Check 1307 §4665's faded announcement lead under E27. Review `transcript_repetition_suspect` on 962 §846 and 1197 §2476 rather than assuming a loop from text repetition. |
| **K16 sentence-line change — built, verify actual outcomes** | v3 is the current recorded implementation; the broad v4 closing-prayer variants were rejected. Reconcile actual outcomes against its measured gains/losses, including 1140's conclusion. A narrower closing-prayer proposal for 1007/1178/1293 is unbuilt and unaccepted; assess only under K21/G8, not as an automatic next experiment. |

**K19 reach-back, 11-06 night (operator: "we do need to do these"); built after the answers below.** Trial
`storage/scratch/k19-reach-20261106/trial.patch`, built as `CueSafeExtractionPlan::openedOnStraddledInvitation()`. Where detection cut inside a sentence that is an
invitation or the reading's announcement (`OPENING_INVITATION`), and the cut sits at detection or at that sentence's end,
the start goes back to the sentence's start: overriding K19's "never earlier than detection". The sentence's start is a
sentence boundary or the window's silent opening margin (≥ 0.5 s, not at the recording's opening, so a clipped window is
none); never across music (`musicShare` ≥ 0.5 refuses: 1154's stretch is 0.97, held at its "A is better" cut). Replay
(`storage/scratch/k19-reach-20261106/`, read-only, against the committed `dead-feed-speech-20261106/after.json`): **5 of
729 starts change, all earlier, none ruled**: 1308 1513.54 → 1509.34 ("Colossians chapter 4, starting to read at verse
2."), 1339 1809.85 → 1803.21 ("Genesis chapter 37 verses 1 to 11."), 1247 962.01 → 959.91 and 1250 join 2182.57 →
2179.72 (both were opening mid-sentence at detection), 1244 997.04 → 992.32 ("we're going to turn to our Bible reading").
Not reached: 1354 (window clipped to "Let's" by the song's last cue; within K19 as ruled), 954 span 1 (the reach-back is
undone by the dead-feed move to 1567.85; its "do be encouraged to turn… to Titus" stays out). Ruled endings: 6 failures,
all fidelity on the moved runs' captures, no ruling broken. **Paged "Opening Invitations"**
(`k19-reach-listening-20261106`, https://claude.ai/artifact/Mz3YfpveTjy4rDsvcJZSkR): 1250 is the judgement call (the
preacher's handover to the reader, cf. 1292/1233); the other four check the regained sentence sounds whole (B cuts on
the first word's onset). **Operator 11-06:** 1250 B, 1308 can't tell (inaudible on its near-dead feed; its 10-18 "late"
stands and B meets it), 1339 B, 1247 either, 1244 B; so no handover guard. **Built:** failing tests first
(`WordTimedOutputEdgesTest::a_start_detection_cut_inside_an_invitation_opens_on_that_sentence`: 1308, 1250, music
refusal, 1354 clipped window), K19 docblock amended; replay of the built rule = the trial's five moves exactly
(`built.json`); rulings `operator-20261106:{1250,1339,1247,1244}-sermon-start*`; six captures rebaselined on their own
evidence (`rebaseline.py`, originals in `before/`, `live_revision`); ruled endings 659/659.

### 4.1c Outcome-review follow-up (agreed 2026-11-06; implementation pending)

Apply these directions to the existing repair and acceptance work. Do not restart completed
discovery, replace §4.1a's current freeze sequence with a new programme, or infer that the work
below has already been implemented. Broader orchestration work stays with architecture AM8.

| Follow-up | Next useful result and verification | Owner |
|---|---|---|
| Introduction completeness (K20) | Reproduce 1301's omitted introduction; distinguish semantic membership from passage-name detection. Measure a correction against known introductions and handover/song counterexamples, including fresh source evidence. Keep unreached cases explicit. | This plan §4.1b / extraction planning |
| Ending disagreement (K21) | Inventory middle/earlier-line fallback cases; separate harmless alternatives from differences that lose a conclusion or add unrelated content. Reuse banked/source evidence first; measure the replacement before changing the selector. No automatic human question for every model disagreement. | This plan / `SermonSongIntroductionCheck` |
| Competing boundaries (E27) | Exercise speech over a musical introduction and speech over an outro using actual rendered transitions. Compare with named rulings, preserve complete content, and report compromises; do not claim a fade removes speech selectively. | This plan / edge planning; audit owns audible acceptance |
| Generalisation and operator effort (D24/Q15) | Report source-first completeness and unflagged errors on fresh recordings alongside questions/review minutes per service. Preserve the distinction between known-case regression evidence and independent evaluation. | Post-round audit §0 |
| Bounded repeated work (G8) | Full corpus comparison yields explicit reuse/reprocess/unassessable dispositions and affected stages. A round names the defect classes/evidence gaps it addresses and its stopping condition; unchanged valid evidence is reused. | Round runbook §6; audit stopping rule |
| Independent outputs (G12) | Characterise sermon enrichment failures delaying song preparation; propose the smallest orchestration change that isolates outputs while preserving shared provenance, retries, custody and per-output publication checks. | Architectural maintainability AM8, at its existing gate |

### 4.1d Pre-round review automation build plan (2026-11-06)

**Status: proposed implementation, requested by the operator.** Reduce human decisions by resolving
their causes before review, while preserving complete and accurate outputs. This section owns the
bounded pre-round build sequence; §4.1a still owns freeze readiness, the audit owns acceptance, and
the runbook owns dispatch. Planning does not start a round, change data, approve publication or
amend a ruling. Extend the existing pipeline/services; do not build a second review engine or make
AM8's broader orchestration refactor a prerequisite.

#### Baseline and what the counts mean

Read-only local database measurement on 2026-11-06, after the November round and subsequent repairs:

| Current reason | Sections | Implication for the build |
|---|---:|---|
| Any processing-review reason | 898 | Distinct sections; excludes publication approval as a reason, but can overlap approval candidates |
| Explicit manual-review boolean | 473 across 281 runs | Only part of the queue; scanning this flag alone misses much of the work |
| Talk-type confirmation | 335 | T2 explicitly reserves confirmation; classification also controls access under T1 |
| Speaker review | 302 | Historic speaker identification remains disabled under P3; investigate banked predictions and eligibility, do not trust them automatically |
| Song-publication concerns | 228 | 171 have this as their sole processing-review reason; highest-priority measured candidate for removing whole questions |
| Low confidence / inferred song / unmatched song | 70 / 46 / 31 | Separate dashboard predicates, overlapping the above |
| Pending publication approval | 564 | Separate obligation, not an automatic-clearance target |

The 473 explicitly flagged sections include 53 stale sermon-text flags, 43 content-defect holds,
35 repetition suspects, 106 low-confidence structure flags and 28 missing-reading flags. These
are overlapping annotations, not independent decisions or estimates of removable work. Cross-type
OoS inversion is already non-blocking under `SectionReviewFlagPolicy`; the 37 occurrences are not
37 extra clearance opportunities. Song-publication reason occurrences include trailing content
130, short clip 28, partial-source corroboration 22, lyrics outside the section 20, no identified
song 13, moved musical introduction 12, no music 11 and adjacent same song 10. Several can occur
on one section; stored reason evidence may itself need refreshing.

Existing dry runs: `services:rederive-structure-review-flags` inspected 459 runs, proposed **zero**
section changes, and could not fully rederive 24 runs because structures were absent or had drifted.
`services:recompute-section-review-flags` proposed 11 row changes and seven service resyncs; only
two currently flagged, non-superseded sections would lose their manual flag (§1594/run 1051 and
§1829/run 1098). Neither command was applied. Generic flag cleanup is therefore a small task,
not the main strategy. Recount at implementation/freeze; these figures are not release membership.

#### Delivery sequence

**RA1 — Exact review inventory and dependency-aware routing (first, small).** Extend existing
reporting/query paths to emit one record per actionable reason with run/section/service identity,
current output generation, source/evidence fingerprints, hold origin, relevant ruling and the
next operation. Use `ServiceReviewDashboardQuery::reviewReasons()` and its SQL predicate as the
shared definition; add agreement tests rather than inventing a cheaper competing count. Include
service-only, merge and stopped-run obligations separately; distinguish excluded/superseded records
from unresolved work. The 13 non-excluded stopped runs in the earlier census need explicit routes,
including 945's structure failure and 1143's multiple speech blocks; do not revive excluded 1145.

Take the inventory against §4.1a step 2's read-only replay of final code, not only today's
stored outputs: built-but-unapplied work (split-song/music-window snap, K18/K19 starts, the ten
stale main generations, Scripture refreshes) may already remove some reasons, and RA3–RA5 must not
be built against defects that application will clear. Assign each reason to **already resolved by
current evidence**, **resolved by pending application of built work** (named fix, verified after
the round), **automatic repair/evidence work**, **human decision reserved by ruling**, or
**unassessable with a named missing input**. Report unique
sections/questions removable only when *all* reasons clear, plus stages to reuse/repeat. Compare all
eligible runs under G8. Reuse current manifests/replay artifacts in `storage/scratch/`; any new
one-shot reporting command needs the project's deletion trigger. Avoid the full dashboard's
blob-heavy sorted load, which failed with MySQL sort-memory exhaustion during this assessment;
use bounded reads and test exact count equivalence.

**RA1 BUILT 11-07 (`9105d4dc4`):** `ServiceReviewInventoryQuery` + `ReviewReasonRouter` + `PendingApplication`,
reported by the one-shot `historic-import:review-inventory` (read-only; fails unless its section count equals
the dashboard's). Live, against the 11-07 replay: 1,062 sections / 2,268 records; **898 processing-review
sections** (= the 11-06 baseline): 27 clear without human work (pending application and/or current evidence; the
only flagless booleans are 1594/1829, matching the recompute dry run), 457 need only automatic stages or pending work, 375 keep a
human decision (talk type 335, speaker 302 and non-recheckable holds are reserved), 39 are blocked on missing
edge evidence. Records by stage: RA3 353, RA5 331, RA4 173, RA2 56, sound treatment 22, hold recheck 3. 37
cross-type inversions and 25 missing-reading flags with a known passage are resolved under the current policy.
Found while building: the candidate predicate's legacy song clause has no `reviewReasons()` entry; it is
inventoried as `song_without_catalogue_item`. Also listed: 456 services flagged for review and 455 pending
merges (all but one are the same services: convergence obligations, not review automation), and 74 stopped
runs, of which 16 are historic-era (≥ 880): 15 unresolved plus excluded 1145.

**RA2 — Complete evidence refresh before escalating stale text (early).** Reuse
`CreateSermonTranscriptFromService`, `FlagSermonTextPredatesEvidence`, transcript recovery and
`ContentHoldRechecker`. Determine which of the 53 stale-text sections have settled current spans;
re-slice those through the existing writer and refresh dependent analysis/Scripture only where
invalidated. Changed or unsettled spans route through the existing composition/extraction chain.
Do not re-encode unchanged media just to refresh text. Clear a stale-text reason only after the
saved text matches the current source transcript and executed spans, including composite joins;
failed writes, incomplete evidence and stale published/holding generations retain their obligations.
Recheck code-found holds only through their recorded checker; human-found holds remain proposals
for the operator under D5. Integrate this ordering into normal processing so the same stale-text
queue does not recur. Test partial failures, retries and a second idempotent pass.

**RA2 BUILT AND APPLIED 11-07 (`da9be3499`; `--execute` reconciled 30 runs, rewrote 0 texts; one `sermon_text_predates_evidence` flag remains, §2175 on excluded 1145; the 8 runs still owed are failed or have no sermon: 894, 930, 945, 978, 1095, 1144, 1145, 1380).** The 53 stale-text records were not stale text. In every
completed owed run (33) the round re-sliced the sermon on 11-02/03 from the transcript the run
holds (its derivation stamp equals a fresh hash of that transcript), but `TranscribeFullService`
had replaced the September recovery transcript without updating `service_transcript_content`,
so the run compared a correct derivation against a stamp for a transcript it no longer held. The
same gap would have hidden real staleness: a re-transcription with no re-slice read as current.
Fix: `MediaProcessingLog::installServiceTranscript()` writes path, windows, screen and content
stamp in one metadata write; every writer that installs new cues uses it (`TranscribeFullService`
both branches, recovery replay, H10b repair, untranscribed-speech recovery, repetition recovery).
`putServiceTranscriptPath()` stays for re-pointing the same content (migration, screen).
`historic-import:regenerate-owed-sermon-text` now corrects the content stamp on reconcile, but
only after re-reading the held transcript and confirming it is still the one inspected. It also
reconciles held-back (unsettled-span) runs whose text already is the slice, without rewriting
anything for them. Dry run 11-07: **30 runs reconcile (25 settled + 5 held back), 0 texts
rewritten.** Short-talk sections carry the run-level hold only (talks store no text), so they
clear with their run. Remaining:
- 2057/4090/73105: their cuts change on application; re-extraction re-slices through
  `CreateSermonTranscriptFromService`.
- §4153 (1327) `sermon_evidence_incomplete`: 242 s at 1628–1870 s, where two banked
  untranscribed-speech recoveries both failed. A re-slice can't settle it; it needs new evidence (RA4's
  escalation budget), not RA2.
- `published_reference_contradicts_sermon` (963 §173549, 1066 §1689): both analyses are current
  and both contradictions are editorial (963 is an all-age Christmas talk on 2 Cor 9:15 published
  as the carol reading; 1066 expounds 2 Chronicles 1 *and* Colossians 2). Re-routed: RA2 only
  when the analysis is owed, otherwise an operator decision (as on 10-18 via `SaveSermonDetails`).
- Runs 894/930 (owed, no sermon) and 978/1144 (no recorded plan) carry no RA2 record.

**RA3 — Song boundary repair plus post-cut verification (largest measured opportunity).** Start
with the 130 trailing-content cases and classify the *actual rendered* defect: stale evidence,
removable unrelated speech, genuine continuing singing, instrumental ending, overlap or missing
source. Reuse `SongPublicationReviewPolicy`, `SongPublicationBoundaryEvidenceService` and existing
edge planners. Complete application routes for already-built split-song/music-window fixes
(1294's one-song ruling is settled), lyric extension and musical-introduction protection; do not
rebuild them or re-ask accepted cases. Compare each repair's planned and executed boundaries and
regenerate boundary evidence against the new media fingerprint before re-evaluating its reason.

Automatic resolution requires intact first/last sung lines and musical introduction/ending, no
unresolved second song, and compliance with E27/S11. A short duration alone neither proves a
fragment nor proves completeness. For short/partial sources, seek bounded corroboration; absence
of evidence remains unresolved. Adjacent same-song sections merge only through supported existing
rules/rulings and continuity evidence, never title equality alone. Preserve doxologies, medleys,
quiet introductions, repeated verses and speech/music overlap as counterexamples. Sound-treatment
and loudness failures use their existing repair/measurement stages. Removing a boundary concern
does not itself approve or publish the clip.

**RA3 measured 11-07 (read-only; nothing built).** Of 362 RA3 records over 275 sections, 125 are
`song_boundary_trailing_content`, from 130 sections (about 70 sections have only RA3 reasons
open). Scratch: `storage/scratch/ra2-20261107/trailing_*`, `tail_signal*`, `tails.txt`.
- **Evidence vs rendered clip.** The check judges the stored inclusive bounds: `asCut()` applies
  operator edge answers but not the planner's edge rules. 74 of 123 extracted clips end elsewhere
  (39 later, 34 partway into the tail). Re-judging against `CueSafeExtractionPlan::forSection()`
  drops 32 reasons, but **none of the 128 planned clips stops before its tail**. All 32 drops are
  the 5 s `CANDIDATE_END_TOLERANCE_SECONDS` cliff (147's end moved 0.14 s). Re-judging alone would
  hide 33 real holds: **rejected**. Judging the planned cut is still right, but only with the cliff
  replaced.
- **What the tails are** (from the decoded words; only the first 230 characters were read, so
  speech late in a tail can be missed): about 88 continuing singing (the wordless gap was an
  instrumental break, e.g. 147 is the second half of *Great Is The Lord*, not a prayer), 18 speech
  only ("Thank you. Please take your seats", a spoken benediction), 22 singing then speech, and
  1 decoded garbage (3182). Labels in `labels.json`.
- **Two defects, not one.** (a) Check precision: sung tails are held. (b) Planner recall: the
  spoken tails sit up to 60 s inside the song section, but the song-end rules (E24
  `speechOverMusic`, `songEndBySoundOrSpeech`) look only around the stored end
  (`SONG_END_LEAD`/`REACH`), so speech the structure left inside the song is never cut.
- **Signal not established.** The whole-tail classifier speech share separates sung (90th
  percentile 0.05) from spoken (median 0.2), but dilution misses 9 of 18 spoken tails at 0.2. The
  per-window peak flags 30 of 88 "sung" tails at 0.2, which is either label error (truncated
  reading) or speech next to the tail. Next: label from the full tail using word timings plus
  per-window classes, then a listening page for the cases that stay ambiguous. Only then propose
  (a) a sung-tail exemption and (b) an inside-section song-end search, each with a replay and
  counterexamples (doxologies, sung Amens: 1099, 2782).

**RA4 — Targeted song identity and transcript-repetition recovery (after RA1 routing).** Extend
`MatchSongsFromTranscript` and `SongCatalogueTitlePolicy` only where the inventory demonstrates
missing resolution. Reuse admitted catalogue/lyrics and permissible independent service evidence;
respect D4's exclusion of email from detection and do not count a projection derived from the same
transcript as independent corroboration. Require distinguishing sung content for identity;
announced titles, shared choruses, markers, multiple songs and suspect transcripts retain explicit
contradiction handling. A high model score alone does not establish identity or completeness.

For the 35 repetition suspects, reuse `ServiceTranscriptRepetitionRecovery` and its padded-window
decode, but first characterise legitimate repetition (1197 §2476 call-and-response, songs and
prayers) versus decoder loops and sparse/missing speech (including 962 §846). A fresh decode that
merely looks less repetitive is insufficient evidence of faithful recovery. Preserve surrounding
cues, compare against source audio, and retain unobservable windows when evidence remains missing.
Missing decodes cannot clear holds; fluent replacement text cannot prove that words were recovered.
Refresh dependent text/structure through RA2 only after adoption under measured rules. Bank retries
and evidence so an unchanged failure is not repeatedly sent to models. No new arbitrary retry
count: define and test a finite per-class escalation budget against the existing retry contracts.

**RA5 — Resolve consequential structure questions from evidence (bounded, measured).** Reuse
`CutAwareEnsembleComposer`, `EnsembleReviewGate`, output-edge evidence and the extraction planner.
Before asking about low confidence, order, missing reading or disagreement, obtain the missing
bounded evidence and compare actual candidate cuts, identity, metadata and access consequences.
Already-equivalent outcomes may settle under D11–D16; identical empty/unavailable plans must never
count as agreement. A sermon with a supported current reference needs no missing-reading question
under D3; do not invent the passage or assume a printed order reflects what happened.

Incorporate the existing K20 introduction, K21 ending and E27 overlap work rather than creating
parallel heuristics. Low confidence is not cured by raising the stored score. Protect omissions,
quiet speech, internal readings, dropouts and split sermons even when the ensemble agrees. Changes
to a general rule require saved-draw replay, counterexamples and the D9 operator decision. A model
answer must cite current source evidence and satisfy deterministic constraints; disagreement that
changes substantive content remains unresolved. Missing evidence is a separate outcome, not a vote.

**RA6 — Preserve valid answers and make reserved decisions cheap (before freeze).** Reuse existing
answer/hold actions and evidence identities. Recomposition must not repeatedly ask an already
settled question when its semantic subject and all relevant dependencies are unchanged; changes to
content, spans, type, access or supporting evidence must invalidate the affected answer. Test the
1148/1197 section-renumbering cases: matching numeric IDs or matching labels is not enough, and a
reused answer must never silently discard a sermon part. Do not weaken the existing fingerprint
gate to recover an old approval.

For 335 talk-type and 302 speaker-review occurrences, prepare source-backed proposed decisions
with the shortest useful evidence and group related questions by service. Audit why banked speaker
predictions are still surfaced while historic identification is disabled; suppressing a stale
prediction must not silently assign a speaker, change visibility or erase a real identity task.
Keep honest unknown identities under P3. T2 currently requires talk-type confirmation and T1 makes
it access-sensitive: implement proposal/batch-confirmation assistance with per-item evidence and
exceptions, not automatic confirmation. Reuse the workbench; no general redesign. Any proposal to
remove that human gate needs a separately recorded ruling and measured classification/access
evidence, so it is not a prerequisite for this round. The same boundary applies to human-origin
content holds, sermon absence, unresolved interruption questions and release acceptance.

#### Common implementation and accuracy contract

- Keep resolution inside the existing owning service/job/action. A successful resolution withdraws
  only the reason it proves false, recomputes remaining section/service obligations and records the
  evidence and rule version. It must not forge `manual_review.confirmed_at` or erase hold history.
- Evidence records bind source hash, relevant transcript/structure/rulings, executed spans and
  output generation as applicable. Failed or partial writes cannot leave a cleared flag. Retry,
  concurrent evidence replacement and supersession must be idempotent and reject stale results.
- Run expensive work only for changed/missing dependencies or declared evaluation samples.
  Preserve G8's whole-corpus comparison, existing staging/custody and publication gates. No automatic
  data apply, model dispatch or release follows from this plan.
- Prove reported bugs red → green, cover multiple simultaneous holds, source-limited cases,
  legitimate repetition, same-title different songs and changed/reused rulings. Add query/reason
  parity and no-publication/access-change assertions. Run focused tests, PHPStan, Pint and the full
  parallel suite for implementation; Dusk for changed workbench interactions. Mocks establish
  contracts, not model quality or audible correctness.

#### Verification, sequencing and stopping

Ship RA1 first (on the final-code replay), then RA2 and the already-built repairs/RA3 boundary
path; RA3 classifies only reasons the replay shows survive application. RA4 and RA5 expand only
where the census and real-evidence trials show worthwhile reductions; RA6 supports these stages
and prepares the residual human queue. Finish §4.1a's existing start work before freezing the
combined code. This is a sequence of bounded changes, not a requirement to solve every review
class or introduce a new platform before dispatch. No clearance percentage is promised from counts.

For each change, report before/after unique questions and sections, remaining reasons, automated
repairs, failed/unassessable cases, model/encode cost and operator questions/minutes per service.
Also report lost substantive words/verses, added unrelated material, wrong identities/types,
incorrectly cleared holds and access/publication changes. Measure saved work only after all
blocking reasons for an item settle; moving a problem to a hidden flag is not improvement.

Use the output audit and song protocol's existing sampling/acceptance contract, not a second
competing sample rule. Replay the eligible corpus read-only, then examine newly auto-resolved
classes and a predeclared stratified sample of apparent passes/unflagged outputs, including fresh
recordings not used to tune the rule. Keep known regressions separate from generalisation evidence;
source-first review must not infer correctness from agreement between related transcripts/models.
Check actual audio and internal joins where text cannot answer the question. Report denominators,
uncertainty and unassessable cases; zero observed misses is not proof of zero risk.

Do not enable automatic clearance for a class with an unexplained material regression or inadequate
evidence; keep its existing hold while other proven improvements proceed. Freeze only after the
chosen changes pass their tests and real-output comparisons, current routing/membership is recorded,
and remaining human decisions are explicit. Apply through the next authorised round, then verify
actual destination generations and rerun the affected checks before acceptance. Stop expanding a
class when the bounded evidence work is exhausted: present the exact consequential residual
question rather than opening another whole-corpus discovery or processing round.

### 4.2 Regular uploads are the lasting outcome

Weekly processing resumes after the historic import is done (G5/R8, explicitly reaffirmed 11-06);
preparation can proceed now. Early weekly resumption was considered and rejected.
Historic livestream and weekly livestream share processing, but historic evidence does not certify
production hardware/backends or direct-upload modes. The old mode-by-mode inventory is dated evidence
in the [record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md#42-regular-uploads-are-the-lasting-outcome), not current runtime proof.

- [ ] Confirm where weekly runs (production per the standing ruling) and verify actual transcription,
  timed cues/edge evidence, classifier capacity, models, encoder, worker settings and speaker-off state.
- [ ] Make consequential review journeys usable in admin: edge questions, content-hold release,
  held-sermon recuts and talk–reading answers. Carry Q1's shared review-question/consequence pattern,
  durable answers and common publication predicate; talks stay ask-only. Verify implementation before
  assuming the old “not built” status still holds.
- [ ] Run two recent services through the production weekly path at take-over, measuring time,
  CPU and cost, with all questions answerable and outputs accepted under the audit procedure.
- [ ] Preserve originals for the backlog from 2026-08-30 onward and process oldest first after take-over.
- [ ] Extend the source-reviewed evaluation set beyond deterministic cut fixtures to structure and
  song identity; score before and after take-over. Keep mock-response tests distinct from model evidence.
- [ ] Measure model redraw stability when a pipeline attempt legitimately needs new answers under
  G8. Compare its decided outcomes with the prior measurement and report consequential flips;
  do not selectively reuse answers to conceal instability. Reuse valid evidence where permitted.
  The November K16 redraw measurements are archived evidence, not an unperformed reword trial.
- [ ] Audit already-published weekly clips read-only for the redesigned edge/sync classes; any
  production recuts require their own operational authority.

**Direct-upload scope:** the 09-22 local whole-sermon reproduction found no pathological loops in
five cases; it did not establish a rate or provider equivalence. No speculative safeguard is owed
for that unreproduced case. Direct-mode timed transcription/repetition work is the decided legacy
transcript follow-up in programme §6 (after historic completion and weekly take-over), not a blocker
for this livestream round. Another backend needs its own verification before use.

### 4.3 Acceptance evidence still owed

The [audit](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md) owns the complete checklist, agent-first procedure, sampling and stopping rule;
its §7 reconciles the former September checkbox backlog. Do not recreate those boxes here.
Outstanding lanes are source-first completeness and independent clean-case checks, full media and
join verification, detector evaluation, song acceptance, final editorial QA and real delivery.
Changed artifacts invalidate dependent checks. Older flags and “no issue found” reviews require
current evidence; unknown disposition remains open, not silently passed.

### 4.4 Bind deferred identity disputes

**The three adoption decisions are complete locally, not deferred.** The 10-18 record supersedes
this section's old pending instructions: kept runs 1120, 1034 and 1375 were corrected, re-detected,
re-cut and their seven identity holds released under X4. Poorer runs 930, 1035 and 1374 remain
excluded; retained assets were not deleted. The run-to-service mapping is 1120→617, 1034→1018,
1375→1121. Other quality holds, including 1034's song-boundary holds, are separate.

- [ ] Verify that mapping, exclusions and current assets in the next membership reconciliation and
  production census. Do not repeat adoption or clear unrelated holds. The dated evidence is in the
  [complete record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md), under “Duplicate identity, 1034, 1120, 1375: released under X4”.

### 4.5 Prove acceptance, then converge and release

- [ ] Finish the audit's current-artifact acceptance ledger (§7), including the song protocol,
  editorial QA, reviewed speaker/partial-recording presentation and deferred thumbnail generation.
- [ ] Supply accepted membership and residual dispositions to programme §0.4/IC5 for the fresh
  cross-source census and rehearsal; an unresolved item stays held rather than blocking unrelated
  accepted outputs without cause.
- [ ] Complete programme §§7–8 for production apply, release authorisation and destination observation.
  This file neither grants release nor duplicates those procedures.

### 4.6 Lasting evaluation and long-term custody (added 2026-10-16)

- [ ] Re-score the current ruling ledger on final evidence and maintain the passing CI ratchet.
  The full 10-23 capture supersedes the 883/168-capture backlog; later wider decodes reduced active
  unassessable rulings to six in the 10-27/28 record, but subsequent changes require a fresh count.
  Keep unmet/unassessable cases explicit and preserve original hearing evidence and part identity.
- [ ] Add replay coverage for structure answers, the loop page and song stage 1; the edge ratchet
  alone does not cover them.
- [ ] A second copy of source masters remains **operator-deferred** until a destination is available
  (likely the staging drive after processing). Do not confuse this with C2's deliberate single-copy
  quarantine/staging outputs. Retain C6 until processing/custody permits cleanup to resume.
- [ ] Before IC8, decide and retain a maintained post-release media repair route, or explicitly
  record that released outputs are final. The masters/promotion tooling currently live locally.

### 4.7 Legacy sermon transcripts (decided 2026-10-17; planned, not built)

The decided scope and implementation contract now live once in
[programme §6, legacy transcript follow-up](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#legacy-transcript-follow-up-decided-2026-10-17).
It includes production-only sermons and starts after historic completion and weekly take-over.

## 5. Go/no-go

| Decision | Recorded status / next evidence |
|---|---|
| Processing | November round and named repairs closed. K19 reach-back built and ruled 11-06, not yet applied; it and the other later built fixes need the measured routes and output checks above. No new dispatch authority. |
| Content acceptance | Incomplete. Ten stale main generations require verified recovery; other coverage, content and editorial obligations are in the audit ledger. The old 21-container-path incident is resolved, not an outstanding loss. |
| Production convergence and delivery | Fresh programme census, rehearsal and destination verification still required. |
| Public release | No batch authorised; programme §8 owns the separate act. |
| Weekly processing | Paused until historic completion, explicitly reaffirmed 11-06. |

Update current statuses in place. Keep detailed execution diaries in dated evidence records;
link their closure or carry an item as explicitly unverified. Never infer closure from archival.
