# Historic Video Defect Discovery and Acceptance Plan

**Current worklist — reconciled from recorded evidence 2026-11-06.** The 11-02/03 round and
its subsequent repair sessions are closed. Later fixes still need pipeline application and
current-output acceptance. Historic outputs remain quarantined; no release batch is authorised.
Weekly processing stays paused until historic completion (G5/R8, reaffirmed 11-06).

**The next round is meant to be the last corpus-wide one (operator 11-10, G13).** §4.1f is the
checklist that makes that possible; nothing freezes until each of its rows is done or disposed of.

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
- K20 corrects the K17 overgeneralisation. K18's passage-name check is one source of evidence for
  a sermon introduction. The run 1301 omission is fixed in the resolver (§4.1b). The recut and output verification are pending.
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
close §4.1f's last-round checklist, then follow the round runbook for freeze, preflight, staged
start and closure. Do not reuse old
423-run/15-import routing lists without reconciliation.

### 4.1 Current processing work

§4.1a is the pre-freeze sequence; §4.1b separates remaining repairs from post-round verification.
The outcome-review follow-up in §4.1c informs that work without making a broad architecture
refactor a new prerequisite. The cut-planner refactor from the 11-11 code review is
[architecture AM15](ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md#am15--cut-planner-ordered-typed-rules-over-evidence-loaded-once):
only its test-only plan snapshot (AM15.0) may land before the freeze, and the rest follows the
round. §4.1e proposes speaker-identification repair and a bounded proposal
pass before the next run; execution and re-enabling remain undecided. §4.1f (11-10) orders all of
this so that the next round can be the last corpus-wide one. Preserve accepted limitations
and specific operator rulings.

### 4.1a Before the next round: regression coverage and combined verification (2026-10-29)

1. **Settle the current start work.** K19 reach-back is built and ruled (§4.1b); opening thanks needs
   no rule; K20's run 1301 omission is fixed in the resolver. Write reproducing tests before fixes,
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
   **Replay continuation 11-12** (`storage/scratch/final-replay-20261112/`): the first edge pass's
   route-derived list omitted 17 completed runs. Both extra preparation processes completed (17/17,
   exit 0), bringing coverage to all 440 completed replay candidates; the other 12 candidates are
   failed runs. The extra logs add three unchecked sentence edges to the original 107 (110 total);
   these are unresolved placements, not positive sentence-completeness evidence. Initial preflight
   stopped on MySQL's `Out of sort memory` before writing a baseline; no replay or inventory ran.
   Retried with `SET SESSION sort_buffer_size = 67108864` on the flight connection, retaining the
   unchanged ordered SHA-256 query and audited harness. Preflight passed and bound two shards;
   the 452-run replay is in progress. Postflight must use the same session buffer setting and pass
   before inventory. No source changes, tests, commits or migration during this replay.
   **Early findings, not final results:** run 938 has invalid song plans (including §627:
   start 1827, proposed end 1819.49). The tail-speech search reaches before a short section's
   start and picks up its introduction; the publication validator correctly rejects the interval.
   Preserve this replay before adding a start-bound regression/fix. Static unchecked-edge accounting
   (`unchecked-sentence-analysis.json` in the replay folder) identifies 61 of 110 as lacking a timing
   window; 49 remain unknown. This is a conservative current-evidence reconciliation, not a
   preparation-time snapshot or acceptance of those cuts. Replay/postflight results remain pending.
   **Completed replay/postflight:** all 452 runs are journalled and both shards exited 0.
   Pre/post snapshots match for binlog, every table's rows/checksum, local disk listings,
   server, source hashes, membership and shard count. Postflight nevertheless correctly **FAILS**
   on replay errors: invalid section plans on 14 runs (15 section errors), and missing edge timings
   in the fallback sermon plan on 13 runs. `postflight-differences.json` contains only these errors;
   there is no `postflight-pass.json`, and inventory was withheld. These final counts supersede
   the partial counts above. Repair/re-evidence and a new clean replay are required before inventory.
   **Repairs built 11-13 (WP10, WP12):** fourteen of the fifteen section errors were one mechanism
   from `5d8db2cf8` (11-07, after that day's replay): the song tail-speech search read words before
   the section's own start, so on short songs it took the spoken introduction as the cut. It now
   ignores pre-section words and hands back to the existing song-end rules when nothing usable is
   left. 1339 was a section end rounded 5 ms past the source (from `b8dbf24fc`); ends within the
   sermon planner's existing one-second overrun allowance now stop at the measured source end. The
   thirteen missing-timing runs were an evidence gap: preparation did not enumerate the composed
   introduction opening (1027, 1053, 1072; now taken from the resolver's own rule), and ten failed
   runs were outside the 11-12 preparation list. No ruled ending moved (596 captures); suite 11,278
   green. The first replay's analysis (`final-replay-20261112/analysis/WP11-report.md`) is
   provisional: 430 unassessable sermon ends fall to one (1019, no banked K16 answer) plus the
   error runs. Reported, not fixed: three further places where preparation and
   planning derive windows separately (duration-normalised section ends, dependent-song review
   before edge overrides, exposed-edge follow-up from raw section bounds); no local case found.
   The church update migration was applied to the working database and the schema dump regenerated.
   **Completed 11-13 on `46fa18690`** (`storage/scratch/final-replay-20261113/`; evidence top-up in
   `edge-step-20261113/`, 27/27 prepared): fresh guarded 452-run replay, post-flight PASS, inventory
   run. Pre/post database, binlog, disk and source snapshots match; zero execution errors, zero
   unexpected holds; 1019/1335 remains the named K16 content hold (no banked ending answer).
   Analysis in `final-replay-20261113/analysis/WP14-report.md`: 573 outer edges move against stored
   cuts (40 starts, 533 ends); 117 unchecked sentence edges (40 internal joins, 77 exposed), all
   stopped by the absent timing window beside a cue gap, none unidentified; unassessable sermon
   ends 430 (11-07) to 1. Of the 425 runs clean on 11-12, only 1172/2324 changes (the same
   tail-search correction; its end returns to the stored 2072.36). Thirteen of the fourteen repaired
   song ends return to their 11-07 plan; 938/627 ends 2.35 s earlier on a valid in-section onset.
   Inventory: 1,037 sections / 2,209 records, 377 processing-human sections, 0 unassessable
   (11-07: 1,062 / 2,268, 375, 39). The human sections are 335 talk-type/speaker (whole classes
   owned by T8 / §4.1f row 7 and §4.1e) plus 42 others; about 34 are potential sound or ending
   questions. **Owed before §4.1f row 1 can close:** 59 moved ends miss their latest banked ruling
   time; later hearings settle nine, leaving **50 needing a Q13 disposition** (listed with both
   times and the ruling wording in WP14 §5), and 1019's held disposition must be carried into the
   freeze record or answered. Harness note: `run-shards.sh` needs `rg` on the path.
   **Triage of the 50, 11-13** (`storage/scratch/ruling-reconcile-20261113/WP15-report.md`,
   `triage.json`; words, levels and register, no listening): **28 settled** (the interval between
   the banked and replayed end holds only decay, room floor or next-item speech; E21/E24/E28–E30
   cited per row), **17 need the operator's ear** (page "Ending Reconciliation",
   `https://claude.ai/artifact/67P3QTk4KTZWfY9EMq2bLK`, `rulings` collection, A/B map in
   `versions.json`; unanswered), **5 defects**. A scan of the same classes over all 452 runs /
   1,294 song outputs adds four more defective ends, so nine ends in four mechanisms, none fixed:
   tail onset placed after the next item's utterance has begun (1007/1261, 1314/3989); onset
   agreement starting on a later part of the next item (1138/2124); spoken voice labelled as the
   last sung phrase, kept without removal (1245/3050, 964/94692, 1069/1696, 1174/2336); cut and
   fade truncating a final sung word (1268/4731, 1180/2371). The scan's 129 broad-screen
   candidates are a screen, not defects. Row 1 stays open on the 17 answers and these four
   mechanisms; a fix changes planning code, so it owes another replay.
   **Answers and first fixes, 11-13.** The operator answered 16 of the 17 (1294/166088 unanswered);
   banked through `scripts/rulings/gather.py` (17 records, 17 earlier ones superseded; no fixture
   is captured after them, so no regression assertion changes). Replayed end stands in 8 (1023,
   1073/1712, 1119, 1148, 1222, 1250, 1256, 1272); returns to the banked end in 5 (979 sermon
   2142.78, 1196, 1208, 1267, 1304); needs a new end in 3 (1073/267202 before the breath at
   3774.41; 1200 before "Please" at 458.44; 1307 between 333.8 and 336.115). Remarks not banked
   as rules, awaiting the operator: "if speech must be included, whole sentences are preferred"
   (1200) and three mentions of a breath at the cut. Design study of the four mechanisms in
   `storage/scratch/end-defects-20261113/WP16-report.md`. **Built:** the late-agreement fix (with
   stems banked and no outro ending found, the earlier cut stands; moves 1138, 1232, 1235) and
   the final-sung-word fix (`SongTailLastLyric`: the bound song's lyrics protect a last word two
   decodes agree on, fade starts after it; tolerance half the word's letters; 5 moves, 2
   fade-only; 1268's fade becomes 0.27 s, unheard). **Not built:** the tail-speech change (keep
   the earlier end when the extension crosses a speech-only window; 51 moves, agrees with every
   answered case it touches, 41 without a banked time) and the spoken-as-sung mechanism (no valid
   design; the rollback variant was measured and rejected). 979, 1267 and 1304 are not covered by
   either built fix. **Later 11-13:** the tail-speech change is built as measured (an earlier end
   stands when the tail's move crosses a speech-only window, unless that end cuts a lyric-placed
   last sung word; the all-windows veto is kept; offline re-measure reproduces the 51 moves
   exactly; none heard). A study of the six rejected ends and the spoken-as-sung mechanism
   (`storage/scratch/end-defects-20261113-wp19/WP19-report.md`) proposes four rules, none built:
   keep a lone Amen after a kept line (979; 1 move); never split a spoken line two windows hear
   starting (1200; 9 moves, none away); tell spoken from sung words in the last voiced run (10
   moves, fixes 1245/964/1069/1174, two away: 1267/3353 by 0.14 s, 1304/3875 by 0.30 s); let
   tail ends take the E29 outro treatment (1304/3870, 1307) — unmeasured, because `songEnd()`
   returns before E29 and no tail cut has separated stems (0 of 284). Seven operator questions
   are put in that report and are unanswered. **Built after that:** the lone-Amen rule (untied
   answers only; 979 → 2142.64, the only sermon end moved) and the line-split rule (needs bound
   lyrics; runs after the speech-crossing check and before the lyric guard; six ends move, none
   away from a ruling: 1040/1507, 1200/2509 → 458.137, 1210/2577, 1288/3611, 1302/166251 by
   −6.37 s, 1381/167073; unheard). Still unbuilt: spoken-from-sung and E29 for tail ends. The
   replay owed must follow the last of these. 1019/1335 stays the named K16 content hold and is to be listed as held in
   the freeze record.
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
   **Speed the ruled-endings set once the processing logic stops changing (operator 11-09).** It
   is about a third of the suite's test time: about 1,060 s of 3,069 s on 11-09. Each case rebuilds
   its run from a gzipped capture and re-plans it, and a run with several rulings is rebuilt once
   per entry. Candidates: rebuild once per capture, cache decoded fixtures per process, retire
   superseded entries. Time each case before choosing. Architecture AM15.2 (planned 11-11) is the
   route once the round has closed: each case plans from evidence built in memory, not from a
   rebuilt run. Measured 11-09:
   - The suite was capped at 4 workers by `ParallelTestingProcessLimiter` and is now capped at 8
     (`a74cb355a`): 13:28 → 8:21–11:01.
   - Beyond the worker count, the limit is the project folder shared from the Mac (gRPC FUSE),
     not CPU or MySQL. Test storage in `/tmp` (22 failures: commands build `storage/` paths
     themselves) and CLI opcache (no gain) were tried and dropped.
6. **Freeze and execute only through the runbook.** First close §4.1f: the edge evidence, the
   complete replay, the moved acceptance checks and the production census all come before the
   freeze (11-10). Fresh membership, revision/worker checks,
   storage headroom, backups and pause/resume preflight; representative encoded transitions and
   internal joins before expansion. At close, verify actual destination generations with hashes,
   application-container readability and the post-round items below. Acceptance follows under the
   audit; completion is not a release decision.

### 4.1b Exploratory pre-round findings (2026-11-05)

Reconciled through the 11-06 record; older interpretations and exact evidence are preserved in
[the snapshot's §4.1b](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-11-06.md#41b-exploratory-pre-round-findings-2026-11-05).

| Work/status | Next action and evidence |
|---|---|
| **K19 reach-back — built 11-06** | `openedOnStraddledInvitation()`; operator answered “Opening Invitations” (record below). Changed starts 1244/1247/1250 join/1308/1339 need recapture after application. Keep 954's previously approved join after checking the source dropout below. |
| **1301 introduction — fixed in code (K20)** | The resolver now accepts a section labelled “Sermon introduction” when its transcript corroborates the preacher's own preaching or sermon preparation. The existing music check still applies. The two source-backed 1301 captures add 1914.10–1959.96 and retain the same ending. A scan of 442 frozen packets found 33 preceding `other` windows, of which only 1301 met the new criteria. A read-only check of the current local sections and 1301 transcript confirmed the same window. Apply through the round, retaining 1301's separate R10 recompose route. No-passage stretches on 1044/1258/960/1163 still need evidence-based disposition. |
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

**954 sermon join, source check (11-11).** The 11-05 transcript-based review reported a lost
invitation at the join. The staged master matches run 954's recorded SHA-256. Both channels
show the dropout. The 1562.51 reach-back adds a brief signal around 1562.54–1562.92, followed
by 4.48 s below −80 dBFS peak before the feed begins to return at 1567.40. Sustained
speech-level audio resumes around 1568.10. The transcript alone cannot establish that the
invitation is recoverable across that gap. Keep the 1357.00 → 1567.85 join that the operator
heard and approved on 10-22. Record the dropout as a source limitation. The earlier preview
may contain a faint fragment whose intelligibility is unverified. Source measurements and
decoded previews of both joins are in `storage/scratch/954-invitation-preflight/`.
Verify the applied join in the round.

### 4.1c Outcome-review follow-up (agreed 2026-11-06; implementation pending)

Apply these directions to the existing repair and acceptance work. Do not restart completed
discovery, replace §4.1a's current freeze sequence with a new programme, or infer that the work
below has already been implemented. Broader orchestration work stays with architecture AM8.

| Follow-up | Next useful result and verification | Owner |
|---|---|---|
| Introduction completeness (K20) | The run 1301 omission is fixed in the resolver and verified against source replays and handover/song counterexamples. Apply it and verify the resulting output. Keep unreached cases explicit. | This plan §4.1b / extraction planning |
| Ending disagreement (K21) | Banked-vote inventory and selector comparison below. Reconcile the missing banks and measure a conclusion-aware choice before changing the selector. No automatic human question for every model disagreement. | This plan / `SermonSongIntroductionCheck` |
| Competing boundaries (E27) | Exercise speech over a musical introduction and speech over an outro using actual rendered transitions. Compare with named rulings, preserve complete content, and report compromises; do not claim a fade removes speech selectively. | This plan / edge planning; audit owns audible acceptance |
| Generalisation and operator effort (D24/Q15) | Report source-first completeness and unflagged errors on fresh recordings alongside questions/review minutes per service. Preserve the distinction between known-case regression evidence and independent evaluation. | Post-round audit §0 |
| Bounded repeated work (G8) | Full corpus comparison yields explicit reuse/reprocess/unassessable dispositions and affected stages. A round names the defect classes/evidence gaps it addresses and its stopping condition; unchanged valid evidence is reused. | Round runbook §6; audit stopping rule |
| Independent outputs (G12) | Characterise sermon enrichment failures delaying song preparation; propose the smallest orchestration change that isolates outputs while preserving shared provenance, retries, custody and per-output publication checks. | Architectural maintainability AM8, at its existing gate |

**K21 banked-vote inventory (2026-11-10).** Checked the 450 non-excluded historic runs in the
11-07 replay membership against the current question identities. The edge-preparation and cut
planner spans agreed on the questions and bank matches. There were 203 matching banks, 396
questions without a matching bank and six runs without a question. The matching banks contained
three earlier-line fallbacks and no middle-line fallback. The comparison ran the banked calls
through the current selector and its existing end guards.
The evidence and reproducible read-only analysis are in `storage/scratch/k21-ending-votes-20261110/`.

| Run | Finding and next disposition |
|---|---|
| 956 | The recorded cut and final model vote end at 3816.10, on “preaching” in the middle of the conclusion. The later vote ends on “the” at 3822.50. The 11-04 answer to the identical prompt reaches the sentence break at 3822.78, outside the active either ruling. Banked word timings corroborate the approved alternatives at 3798.50 (“resurrection”) and 3831.84 (“quoted”). The saved 3798.50 end and repaired reading join replay together. Apply and verify them in one cut in the round. |
| 1244 and 1274 | The preacher and congregation Amens attracted different votes. Earlier and later selection produce the same preacher’s Amen after the existing step-back. The planned ends are 3788.96 and 4076.84. Keep the current handling. |
| 1140 | The recorded 1813.085 cut omits the last application. The 11-04 trial matches the current prompt text, but there is no answer for the current full identity. Its three distinct sermon lines offer an early ending before the application, a middle line cut mid-clause at 1841.95, and a late line through the song announcement. Banked word timings place the end of “Let’s do that, shall we?” at 1852.91, just before the call to sing. Replay that as a candidate and hear the transition before choosing an end. |

The older 11-04 sample of 611 answer sets contained one middle fallback and five earlier
fallbacks. All six prompts still match, and five lack a bank for the full current identity.
Besides 1140, three disagreed over adjacent Amens (1052, 1077, 1244). The other two would
extend into a prayer invitation (1161) or a recitation of song lyrics (1375). Selecting the
earlier or later numbered line supplies no general replacement. Finish the edge banks and
repeat the inventory before the freeze. Measure any adjudication against complete spoken
conclusions and the named rulings, including cases where an answer needs a boundary inside a
transcript line.

**Named repair preflight (2026-11-10).** Edge preparation completed for both runs. For 956,
the approved 3798.50 end was saved and the planner placed it exactly. The newly banked inner
answer also moved the reading join from 2070.00 to 2074.915, adding the leader's thanks to the
reader. One current decode places the start of the next invitation at 2074.82. The earlier
bank chose “commandment” at 2069.74, but its prompt differs after the handover near the music,
so the current identity does not accept it. The selector now steps back before a fragmented
thanks to the reader followed by the call to sing. Of 212 banked reading answers compared,
only 956's current answer moved, to “commandment” at 2069.74. Its full plan keeps the reading
end at 2070.00 and places the saved sermon end at 3798.50. Banked word timings put the end of
“commandment” before the reading cut and the leader's thanks more than three seconds after it.
The round applies both ends in one cut. For 1140, the new bank still selects the early
conclusion. The operator approved the 1852.91 preview,
and the named re-cut completed in local quarantine with that exact recorded end. The new
video is 1751.478 s and decoded in full. The approval clip, encoded ending and run record are
in the same scratch directory.

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
- **Full-tail relabel (11-07, later):** reading the full decoded text changed 26 labels, so the
  230-character reading was unreliable. Now 83 sung, 22 sung then speech (SP), 19 speech,
  5 unclear, 1 garbage (`labels_full.json`). Words per minute (the `SungSpanInsideSermon` /
  `MistypedSungSections` measure) works here *inverted*. Whisper decodes congregational hymns
  densely (sung median 76 wpm), while spoken tails are a wordless outro plus a short "Thank you"
  (median 9). At <40: 16 of 19 speech, 2 of 83 sung. But 3 speech tails decode at speech rate
  (136468 benediction 60, 176320 46, 2164 43), and any whole-tail exemption (≥40 wpm and music
  ≥0.9) **also releases 15–18 of the 22 SP tails**, publishing a final "Shall we pray together?".
  The E24 cue-start classifier test finds 7 of 22 SP onsets and **0 of 19** speech tails (a cue
  opens before the line is said: "Thank you" reads speech 0.01), and fires on 3795's sung Amen.
  **Order: fix (b) first.** Extend `songEndBySoundOrSpeech`'s word-timed onset search to speech
  inside the section. Only then add (a) an exemption on what remains, re-measured on planned cuts.
- **Listening page published and answered 11-07** (https://claude.ai/artifact/WRdeYyjsBhJvTQFFih9RNN,
  27 questions; answers in `storage/scratch/trailing-tails-listening-20261107/answers/rulings/`,
  ids `<run>-s<section>`).
- **Fix (b) diagnosis (11-07, go given; nothing built).** In all 41 SP/speech tails, the planner
  bounds the song at a *later* speech cue (at or after the stored end) and cuts just before it.
  The spoken line inside the tail is kept as song, because `speechAfterSong()` takes an inside
  cue only where the classifier hears speech at its start (11-06 guard, 1311 §4931), which it
  almost never does here (`sp_diag.jsonl`). Two blockers stop a test-first build. (1) There is no
  word-level evidence: `OutputEdgeWordTimings::timingWindow()` decodes only the cues touching the
  edge ±1 s, and these lines are 5–30 s inside. (2) The text is doubtful: 10 of the tails' spoken
  lines are a lone "Thank you.", Whisper's commonest hallucination over music, where the
  classifier hears music. Those 10 are added to the listening page (now 27 questions). Next: the
  operator's answers, then a decode of a word-timing window from the last sung line to the
  stored end (local whisper; needs a go), then the rule test-first.
- **Tail answers (11-07, 27 rulings).** Results: 11 sung, 2 all speech, 9 sung then speech,
  5 other. Measured against the replay's stored and planned ends (`trailing_replay.jsonl`):
  - **Speech starts close to the stored end, not 5–30 s inside.** Each sung-then-speech note gives
    an onset 0.2–9.4 s before the stored end (669 2.0, 1435 0.8, 2187 1.7, 2904 4.4, 3182 0.2,
    3430 4.2, 3447 9.4, 136468 4.1, 176320 2.2). The inside cue that carries the words opens
    8–34 s earlier, over music (176320 "In just a moment…" opens 12 s early; 136468's
    benediction opens 8 s early). So blocker (1) is smaller than diagnosed. A word-timing window
    from about 12 s before the stored end covers every onset, so no window from the last sung
    line is needed.
  - **The 10 "Thank you." lines were real speech at the cue in 1 case.** That case is 1986, all
    speech. 5 are decoding errors over singing (1435, 1477, 2991, 3297, 136390). In 2 the real
    speech comes 21–34 s after the cue (3430, 3447). In 2 the song runs past the end (1780, 3427),
    and in 1 the tail holds the start of the next song (2639, from 26:13). Blocker (2) is
    confirmed: neither the cue text nor its start time is evidence of speech.
  - **New defect: the song section ends while singing continues (5).** 1780, 1915 and 3103 sing
    to at least the clip's end, 5–6 s past the stored end. 3427 and 961 continue beyond it. In
    1780, 1915 and 3103 the stored end sits on the start of the next speech cue, which opens
    early, the same mechanism as above. The planned cut ends 1.5–6 s short in these three.
  - **The planned cut is acceptable on 9 tails and wrong on 6 known ones.** Acceptable: 2187,
    3182, 3430, 136468, 176320, 1986, and 2639 (it ends 1.5 s before the next song). It keeps
    speech on 669 (+4 s), 3447 (+5 s) and 2164 (+9 s, no risk raised). It drops singing on
    2991 (22 s), 2904 (12 s) and 1435 (4 s), all on the `timed_transcript_wordless_gap_before_final_cue`
    or inclusive bases. The planned check still flags 7 of the 11 all-sung tails (check
    precision, defect 1).
  - Next: a word-timing decode of [stored end − 12 s, stored end + 8 s] on the 41 SP/speech tails
    and the 5 song-continues sections (local whisper; needs a go). Then the onset rule
    test-first, judged against these 27 rulings.
- **Word-timing decode (11-07, read-only, `storage/scratch/tail-words-20261107/`).** Covered 55
  sections: the 41 SP/speech tails, the 5 song-continues sections and all 27 ruled. 2174 (run
  1145) has no audio.
  - **A window over 30 s is unusable at the edge.** Whisper joins its two chunks 30 s in. Words
    before the join were dropped (3430 lost all its speech), and the first word after it snapped
    to the join (+6.1 s in 9 sections). Even single-chunk windows stack words at their own edges,
    so any word within 0.25 s of a window edge is ignored. The windows used are
    [end − 22, end + 6] (`words2.jsonl`) and [end − 12, end + 6] (`words3.jsonl`). The 18 s window
    did worse: it missed 2904 and 669.
  - **Over music, words smear.** Whisper stretches words back across the singing ("moment"
    2.5 s; 3447's "number of you" 15 s), and decoded pace does not separate singing from speech.
    Sung lines decode at 2.7–3.8 words a second, speech at 3.1–5.7.
  - **Candidate rule (`rule.py`).** Take the last run of 3+ words, each ≤1 s, with gaps ≤1 s,
    starting before the stored end. The classifier's 5 s window at its start, or the next, must
    read speech ≥0.5. Join an earlier qualifying run within 3 s, then cut 0.5 s before the start.
    On the 27 rulings: 669 (+2.2 → −4.0), 1435 (−5.0 → −0.5) and 2904 (−16.3 → −4.0) are fixed.
    136468, 176320, 2187 and 3182 stay within a second of the ruled onset. 3430 and 3447 are
    missed (Whisper lost the speech in both decodes; the planned cut stands). No all-sung tail
    moves (336237 fires at +0.4, after its end). The parameters were chosen on these same 27
    rulings.
  - **Unruled:** the rule fires on 24 of the 31 decodable tails and moves the cut ≥0.5 s on 21.
    That includes 3795 by −13.1 s (a doxology after the song, classifier speech 0.88: spoken or
    sung?). The 14 moves of ≥1 s are on a listening page,
    https://claude.ai/artifact/FwEwZTpnr4MhBe2NBcB2kH (`storage/scratch/tail-onset-listening-20261107/`,
    `answers-map.json` maps earlier/later to planned/rule). The page has no answers yet.
  - The 5 song-continues sections are not addressed. Their decodes show a wordless outro after
    the last lyric, then speech, and the song end needs extending, not shortening. That is a
    separate fix.
  - Next: the operator's answers. If the rule holds, build it test-first in
    `songEndBySoundOrSpeech` with a durable decode window kind in `OutputEdgeWordTimings`.
- **Endings answered (11-07, 14 rulings; `tail-onset-listening-20261107/answers/`).** Rule
  right 3 (1073, 1521, 2303), planned right 2 (1089, 3796), either 7, neither 2 (3795: the
  earlier ending still has a spoken "Amen"; 1897: even the earlier one has "Well let's…").
  - Three times the rule ended too late: a word before the run is real speech, decoded as a
    smeared word (1089 "this" −5.7, 1897 "to" −4.5) or as a lone word (3795 "Amen" −13.7, 1.4 s
    before the run). Extending back through every non-music window fixes 1089 and 3795, but it
    cuts 11 s of 2904's quiet singing, which also reads as neither music nor speech (E25). The
    same applies to a "move later than planned only across music" guard: it fixes 1089 and
    breaks 2904. No signal here separates 1089 from 2904.
  - **Rule v3a (`rule3.py`, `onset3`):** v1, extended back only through words whose classifier
    window reads speech ≥0.5 (gap ≤3 s). Only 3795 changes, to −14.2. Across all 41 rulings it
    moves 20 cuts. Rule right where planned was wrong: 7 (669, 1435, 2904, 1073, 1521, 2303,
    3795). Planned right where the rule is wrong: 2. 1089 keeps about 5.5 s of speech ("…this
    year to the Lord"), and 3796 ends about 2 s before the planned cut, losing the end of the
    singing (its stacked "Do you please sit down" is timed early). 1897 is wrong both ways, the
    rule less so. Either is fine on 7, and 4 are within a second of the ruled onset. Unchanged
    misses: 3430, 3447, 1986, 2164. No all-sung tail moves.
- **Kept looking (11-07, operator: "keep looking").**
  - The timeline's `singing` score does not separate 2904 from 1089: both read about 0.02 speech,
    about 0.04 music and 0.000 singing before the speech.
  - **Cross-window agreement (`words4.jsonl`, `consensus.py`):** seven 12 s windows 3 s apart over
    [end − 22, end + 8]. A word counts when two windows agree on it (same text, starts within
    0.4 s, ≥1 s from both edges, ≤1 s long). This removes the edge stacking and the smearing.
    3430 is found for the first time (−3.9 against ruled −4.2), and 2904 lands at −3.7 (−4.4),
    176320 at −2.3 (−2.2). The first word or two of an utterance usually drops out (136468 by
    1.4 s, 2187 by 0.8 s), and 3796 and 669 have no agreed words.
  - **Rule v5 (`rule5.py`, gate from `score_all.py pure`):** an agreed-word run (≥3 words, gaps
    ≤1.5 s) starting before the end. In at least one classifier window over its first 2 s, speech
    is ≥0.5 and music <0.5. The cut is the run start − 1.5 s. When no agreed run passes, fall back
    to v3a (with the same pure gate), then to the planned cut. A cut is never moved later than
    planned through windows that are all pure speech (1986).
  - **The pure gate matters.** With speech-only, both v3a and v5 move 3 of the 74 decodable
    sung-labelled tails earlier. In 2819 the sung "O Church of Christ rejoice" sits in a window
    reading speech 0.54 with music 0.55; 3811 is "your Holy Name"; 3546's words are stacked.
    With the pure gate, **0 of 74**.
  - **v5 (pure) on the 41 rulings: 5 bad**, against 10 for the planned cut (1780 excluded from
    both: song continues, separate fix). It fixes 176320, 3182, 2904, 3430, 1435 and 1073, and
    669 goes from keeping 4.2 s of speech to losing 2.0 s of outro (at the limit). 3796 now
    stays planned (right). It makes one cut worse than planned: 1089, where the onset falls
    between the rule's −3.8 and the planned −6.2. Still wrong: 2303 (no pure run; the planned cut
    stands, the old rule's −3.1 was right), 1521 (−6.5; the margin overshoots the ruled −5.5),
    and 3447 (keeps 5.1 s).
- **Kept looking once more (11-07, `rule6.py`, toggles via env).** Nothing beats v5 (pure gate).
  - **1089 is probably right under v5, not a miss.** Each window that starts mid-music pins an
    utterance's first word to its own start, but the window starting at −4 places "I want to
    dedicate" at −3.3, so v5's −3.8 cut leaves the speech out. The ruling only says −6.2 beats
    −0.8, and −3.8 lies between. It counts as untested, not wrong.
  - **Lead-in words from windows that do not pin them** (onset = their median, cut − 0.5): this
    moves 3430 back onto sung words (cuts 3.4 s). It is not kept.
  - **The 28 s decodes as extra voters:** their stacked words agree by chance with pinned words
    elsewhere (336237 cuts sung, 3182 cuts 2.4 s, 216616 a new sung false move). Not kept.
  - **Gate over 3 s instead of 2:** no change. 2303 never forms an agreed run (only one sliding
    window decodes it cleanly).
  - **Singing-aware gate** (also pass speech ≥0.6 with singing <0.01: speech over an
    instrumental outro reads singing 0.000, while 2819/3811's sung lines read 0.039–0.048): this
    fixes 2303, but the v3a fallback then fires on stacked words in 3796 (ruled planned) and 3546
    (sung-labelled). It is a wash and is not kept. Stacked word groups are right in 136468/2187
    and wrong in 3796/3546; no signal here tells which.
- **v5 BUILT (11-07, operator: "build it").** `SongTailSpeechOnset` holds the rule (windows,
  agreement, pure-speech gate, wide fallback, never later than planned through speech alone).
  `CueSafeExtractionPlan::songEnd()` asks it after the existing rules (now `songEndByCues()`), and
  its cut replaces theirs with reason `song_end_tail_speech_onset` (the replaced decision is kept
  under `replaced`, with a 1.5 s fade). `PrepareOutputEdgeEvidence` banks the eight tail windows
  (`window_rule` `song-tail-slide-12-step-3-v1`) for every song before the song cuts are planned.
  - The rule reads the decoder's **unjoined tokens** (`OutputEdgeWordTimings::read()['tokens']`,
    new), because joining a punctuation token stretches the word before it. With joined words,
    669 and 3795 lost their fixes ("together." ran over 1 s).
  - Re-scored as built, on the scratch decodes (`php_rule.php`): it matches v5 on every tail
    except 136468. There the window between the cuts reads music 0.4989 (rounded to 0.50 in the
    scratch analysis), so the planned −5.25 stands, within the ruled −4.1's 2 s. 0 of 74 sung
    tails move.
  - **Evidence is optional.** Without the tail windows banked, the earlier decision stands, so no
    run's plan fails. The windows are decoded by the edge step, so they arrive with the rest of
    the edge step (runbook §4.2, the round's first stage), not before.
  - **Not measured: song ends outside the 130 trailing tails.** It applies to every song end once
    its windows are banked. The round's redraw listing must show the `song_end_tail_speech_onset`
    moves on non-trailing songs before they are trusted.
  - **Not changed: the next item's start.** `afterSongSound()` only moves a start later, and this
    reason is not in its list, so speech the song no longer keeps does not reach the next item's
    clip either, where that item starts at the stored boundary. This is unchanged from before
    and stays open.
  - **Remaining misses and what they would need:** 1521 and 2303 start speech over an instrumental
    outro, in mixed 5 s windows (finer classifier windows). 3447's "please sit down" is 5 s
    before the next speech, over music (a merge across music). 669 loses 2.0 s of outro (at the
    limit).
- **Defect (a), check precision: a lyric-ended tail (11-07, read-only, nothing built;
  `storage/scratch/tail-lyrics-20261107/`).** Each tail cue was matched against its own song's
  `lyrics_plain` with the existing readers (`SongLyricsOutsideSection::wordPairs`, or ≥2
  `SongLyricIdentityCheck::contentWords` with half or more in the lyrics). All 130 sections have a
  song with lyrics.
  - **Signal:** "the last content cue in the tail is the song's own lyric" holds for 67 of 83 sung
    tails, 2 of 22 SP (3430, 3795), 0 of 19 speech and 4 of 5 unclear. The sung misses are sung
    Amens (1099, 1680, 2782, 2304) and garbled last lines.
  - **Guard: the last lyric cue must be ≤12 s long.** Whisper stretches its final cue to 30 s over
    the outro, and speech hides in it: 2187 (ruled speech at 38:57), 3430 and 669. With the guard
    the rule releases **53 of 83 sung tails and 0 of the 46 others**. Against the 27 tail rulings,
    no ruled speech is released. Pairs alone (no word-share) release 39.
  - **The same rule cannot see a song that carries on past the cut.** Of the 4 released sections
    that have rulings, 3 are ruled "song continues" (961, 1915, 3103). Only 336237 is plainly
    right. The singing after the cut has no decoded words, and the classifier's music share just
    after the stored end does not mark it either (1915 reads 0.0, while 29 of the 49 unruled
    released sections read ≥0.5). The trailing hold on these is coincidental (it fired on an
    earlier instrumental break), but releasing it would leave a short clip with no hold. The
    5 song-continues sections stay the separate fix noted above.
  - **Listening page (11-07):** https://claude.ai/artifact/C5NUzCJsTdG9245ov3qkar
    (`storage/scratch/song-end-listening-20261107/`, `rulings`, ids `<run>-s<section>`). A
    stratified 16 of the 49 unruled released sections: 8 with music after the stored end, 8
    without. It asks whether the planned cut ends right, is cut short, or keeps speech.
  - **Answered 11-07 (16 rulings; the page was rebuilt as A = the clip's ending with the planned
    fade applied, B = the 12 s after it).** No clip keeps speech (0 of 16). Every
    `song_end_silence` end is right (6 of 6: 862, 1087, 2168, 3041, 4046, 4713). Every fade end is
    cut short (10 of 10): 7 `song_end_speech`, 3 `song_end_fade`, and 147, which has no replayed
    edge. In 9 the outro's last note rings into the first second after the cut. In 3282 it rings
    for 6 s, and in 4623 the song's last word is after the cut (speech starts 3 s later).
  - **Mechanism: a stretched first spoken word.** The banked edge decode stretches the first spoken
    word back over the last note: "I" 1.84 s (1814), "Oh," 1.92 s (1572), "Let's" 1.75 s (4623),
    "It's" 1.12 s (175597), "Oh," 5.9 s (2513), "Please" 0.84 s (796). `song_end_speech` cuts at
    `cutInGap()`, 0.1 s before that word's start, so the fade (E7) takes the note. E24 places the
    cut at the speech onset; the onset is what is wrong, not the fade.
  - **Corpus (final replay, `smear_census.jsonl`):** the first word at a `song_end_speech` cut has a
    median length of 0.56 s. It is ≥0.6 s at 154 of 329 cuts and ≥1 s at 82. At `song_end_fade`
    cuts with a word there, 62 of 117 are ≥0.6 s. This is a weekly defect, not a historic one,
    and nothing holds it. Length alone does not separate it: 2819's 0.59 s and 3886's
    zero-length stacked "We" were also cut short.
  - **For the exemption:** this defect is in exempt and unheld clips alike, so the exemption
    neither causes nor hides it. The song-continues cases (961, 1915, 3103: seconds of singing
    after the cut, an early-opening speech cue) remain separate.
  - Next: fix the speech onset at fade cuts first (find where the spoken word really starts,
    measured on these 10 plus the RA3 tail rulings), then the exemption on what remains.
- **Fade-cut onset: level rules measured and rejected (11-07, read-only,
  `song-end-listening-20261107/rise_rule.py`, `levels_census.jsonl`).** Scored on the 10 answers
  and on the latest `edge-rulings.json` song-end ruling at each fade cut (97 sections).
  - In the levels, speech shows as a sharp rise after the outro decays (+1.7 s 1814, +1.7 1572,
    +1.5 147, about +1.5 2819, +0.8 3886, +1.3 175597, +1.2 796). Of the other 3, 3282's next item
    is a song, 4623 is loud music throughout (speech at +2.9), and 2513 has no rise.
  - **Plain rise rule** (≥8 dB over the 0.3 s low, ≤3 s ahead, cut at the low): moves 303 of 576
    fade cuts (median 1.1 s) and breaks 30 ruled-right ends. Rejected.
  - **Gated on a stretched first word** (≥0.6–1.0 s, rise inside it): fixes 2 of 10 (1814, 1572)
    and breaks 3–5 ruled-right ends (canary-13 1221 §2719 and 1304 §4884, 1294 §166088, 1198
    §2484). Rejected. 3886's and 2819's first words are not stretched (the decode stacked or
    dropped them), and 796's and 175597's rise falls after the stretched word ends.
  - 1814's older `keep` (queue sample 10-24, "song") answered whether the tail was song. It is not
    an edge ruling and does not conflict with "cut short".
  - Next candidate: v5's cross-window agreed words, used *after* the cut. The edge step already
    banks the 8 tail windows over [end − 22, end + 8] for every song, so this needs no new
    evidence in the round. Measuring it needs those windows decoded for the 10 answered sections
    and the ruled fade-cut ends (local whisper).
- **Agreed words after the cut: measured; the evidence cannot separate the two groups (11-07,
  `song-end-listening-20261107/decode5.php`, `words5.jsonl`, `agreed_rule.py`).**
  - **Windows:** four 12 s windows from cut − 9/−7/−5/−3 s (one Whisper chunk each, well under
    28 s). Two-window agreement with a 1 s edge margin covers cut − 6 to cut + 6. 103 sections
    (9 answered, 94 ruled), 0 errors.
  - **Production coverage gap:** the banked tail windows sit at the stored end
    ([end − 22, end + 8]), so they reach two-window agreement only to end + 5. 59 of 576 fade
    cuts lie beyond that (up to +27.9 s), and a build would need windows on the cut.
  - **Agreed speech starts about 1 s after the cut in both groups.** In the cut-short answers it
    starts at +0.94 to +2.0 s; in the 57 ruled ends with a run, the median is +1.07 s
    (quartiles +0.72 to +2.06). A rule moving the cut to onset − 0.3 s moves 5 of the 9 answered
    ends and breaks 32–35 ruled-right ends (canary 13/15; the 10-20, 10-21 and 10-24 pages).
  - So today's "cut short" and those earlier "right" rulings describe the same kind of ending. Per
    "rulings are evidence", this is re-asked with clips: the A/B page
    https://claude.ai/artifact/DPy8bRhbWWBa4Cvc8gxQBF (`storage/scratch/song-end-compare-20261107/`,
    `answers-map.json` maps A/B to current/later). 13 pairs: 5 of today's and 8 ruled right,
    each the last 12 s with the pipeline's fade. If the later ending wins generally, the earlier
    rulings were acceptable, not best, and the rule is worth building. If not, today's answers
    were stricter than the standard.
  - **Answered 11-07 (13 pairs):** later 2 (2819; 3010, ruled `keep` before), current 2 (1508,
    175130, both ruled right before), either 9. Operator: "Where either is fine I prefer the
    longer cut." That gives later 11, current 2. So the earlier rulings at these cuts were
    acceptable, not best.
    **E21 is unchanged.** Operator 11-07: "Either means either. I don't want a blanket rule that
    later of the either options is better. But here on song ends that is generally the case. The
    aim is for everything up to the first spoken word to be in the song." That is E24's target
    (speech belongs to the next item), not a tie-break.
  - **The 2 where current wins already have speech at the cut.** The planner cut in the pause
    between two spoken sentences ("…sit down" / "I'm going to leave"; "Let us pray together." /
    "Let us just close with"). The agreed run is the *second* sentence. Raw decoded words cannot
    guard this: 3010's "in prayer" at +0.2–1.3 s is not speech (operator: "I can't hear any
    speech in the gap"), and it never agrees between windows.
  - **Guard: music at the cut.** In the classifier window holding cut + 0.5 s, music reads 0.02
    (1508) and 0.25 (175130); in every later/either pair it reads ≥0.48 (3010 0.56, 1814 0.48).
    "Music < 0.3 means no move" blocks those 2 and 8 ruled ends where the music had already stopped
    (0.01–0.02: 3304, 3598, 4876, 4858, 1895, 2694, 3524, 4648). After the guard, 27 ruled
    `keep`/`either` ends still move later. In the A/B sample every guarded move on a ruled end
    was later or either (2719, 4884, 2718, 3010, 3734, 4685), so they read as acceptable, not best.
    Parameters were chosen on these 13 pairs. A build must replay all 576 fade cuts and put a
    stratified sample of the moves on a listening page before it is trusted.
- **Fade-cut onset BUILT (11-07, operator: "Yes, start"; uncommitted).** `SongEndSpeechOnset`
  (rule `song-end-slide-12-step-2-v1`, reason `song_end_agreed_speech_onset`):
  - **Windows:** four 12 s windows on the cut (cut − 9/−7/−5/−3 s).
  - **Rule:** the first run of ≥2 agreed words from cut − 0.5 s, cut at its start − 0.3 s, only later.
  - **Guard:** nothing moves where music < 0.3 in the classifier window at cut + 0.5 s.
  - **Shared agreement:** v5's cross-window agreement moved to `AgreedEdgeWords`, which
    `SongTailSpeechOnset` now uses unchanged (its 10 unit tests pass).
  - **Planner:** `CueSafeExtractionPlan::songEnd()` asks it only when v5 does not fire and the
    decision is `song_end_speech` or `song_end_fade`. The fade is recomputed from the decision's
    new `last_sound` (E7), and the replaced decision is kept under `replaced`.
  - **The next item still starts at the replaced cut** (`afterSongSound()`): agreement can miss the
    speech's first word (796's "Would you"). The two outputs overlap by the moved second (E27).
  - **Banking:** `PrepareOutputEdgeEvidence` banks the windows on each fade cut after the song plans
    (at the replaced cut where the rule already moved it), before the sentence check. Without them,
    the old decision stands.
  - **Scored as built on the 103 decoded sections** (`php_rule.php`): it matches the scratch scorer
    except 3131 (0.07 s; a window trimmed at the recording's end). All 13 A/B pairs behave as heard.
    Of the ruled ends, 27 met `keep`/`either` rulings now move later (acceptable, not best; to be
    heard), 2 unmet rulings become met, and 1 unmet ruling moves and stays unmet.
  - **Ruled-endings fixtures are not re-captured.** They bank no cut windows, so the rule stays
    inert there. Re-capturing would turn the 27 moves into fixture failures before they are heard.
  - **Corpus replay (all 576 fade cuts decoded, 0 errors; `php_rule.jsonl`):** 246 move later.
    The median move is 1.4 s, p90 3.5 s, max 5.4 s: under 0.5 s 29, 0.5–1 s 74, 1–2 s 61,
    2–4 s 62, ≥4 s 20. That is wider than the A/B pairs (0.57–1.76 s), and the long moves are the
    risk (the agreed run may be a later sentence).
  - **Listening page:** https://claude.ai/artifact/FeRiRZuCdxy2DgaEKaz47R
    (`storage/scratch/song-end-moves-20261107/`, `answers-map.json`). 20 unheard moves,
    stratified by size (3/3/4/5/5), current versus the rule's ending, each the last 12 s with a
    fade. The later fade is approximated as min(4, fade + move); it is exact unless the old fade
    was raised to the 1.5 s minimum.
  - Full suite green (11,100 tests), Dusk 63 green, PHPStan and Pint clean. Next: the answers. A
    size cap or a stricter run would come from them.
  - **Answers (11-08, 20 of 20; `answers/rulings/`):** later 2 (3131, 3695), either 10, current 4
    (828 +5.2 s, 2312 +4.4 s, 3639 +4.2 s, 336053 +2.1 s), neither 4. By size: under 2 s, no
    current; 2–4 s, 1 later, 2 either, 1 current, 1 neither; ≥4 s, 3 current, 1 either, 1
    neither, never later. In the rejected long moves the speech's first phrase ("Please sit
    down", "Let's pray") was decoded but not agreed (stacked or stretched timings), so the run
    found was the next sentence.
    - A words-only guard (text heard in two windows, at any timing, between the cut and the run)
      was scored and rejected: it blocks 17 of 20, including both preferred moves. 336053
      (current) and 3695 (later) have near-identical decodes ("Please sit down" then "and let's
      …" at +2.2–2.4 s), so no word rule separates them.
    - **Neither notes:** 1305 and 1354 want about halfway; 1353 says both fade the last sung word;
      1305 and 1354 say the earlier ending fades the last sung word too. This is about how the fade
      starts, not where the cut is. 1259: the speech comes straight after the last sung word, so
      it is a hard cut or speech over the outro.
  - **4 s limit BUILT (11-08):** `SongEndSpeechOnset::MOST`; a move of 4 s or more keeps the cut.
    It cancels 20 of the 246 corpus moves, leaving 226. Unit test from 1168 §2312.
  - **Fade start measured (11-08, `storage/scratch/song-end-fade-20261108/plans.jsonl`, all 576
    fade cuts):** the planner's last sung sound is within 1.5 s of the cut in 371 (within 0.5 s in
    287), so E7's 1.5 s minimum starts the fade before it. Often it is the cut itself: the decoded
    sung word is stretched to the speech. Elsewhere it is too early, because the transcript missed
    the last sung line (4461: −3.7 s, though "Glory to you, O Lord" is sung up to −0.8 s). Agreed
    cut-window words find a sung word in only 156 of 576, so they cannot place the last note.
    - The moves page approximated the later fade as min(4, fade + move). Where the old fade had
      been raised to 1.5 s, that started the later fade up to 2 s earlier than the build would
      (336053: −1.5 s played against +0.58 s built). 4451's "both fade the last word" is real
      for the current ending.
  - **Fade listening page:** https://claude.ai/artifact/3rvQUS5YRYBuCTAoE2ddLD
    (`song-end-fade-20261108/`, `answers-map.json`). 11 short-gap endings (8 heard before, 3
    new) at their built cuts, each with 1.5 s / 0.75 s / 0.3 s fades in random order, plus the
    4 s after. A shorter minimum would amend E7.
  - **Fade answers (11-08, 11 of 11): a shorter fade is rejected and E7 stands.** 8 answered:
    all fine 5 (2 of them "longer better"), 1.5 s 3 (1 "longer better"); a shorter fade was never
    preferred. The fade takes the last word only where the cut itself is too early:
    - 828 needs about 1 s more and 3891 about 1.5 s more. Both are long moves that the 4 s limit
      now leaves at the old cut; on the moves page, 3891 was "about halfway".
    - 4451: the speech starts straight after the last sung word, so any fade before the cut takes
      the word ("not sure what to do"), as 3234 on the moves page did.
  - **4 s limit REMOVED (11-08, E28):** "Arbitrary time limits seem wrong." The rule moves cuts as
    built (246 of 576). Next: identify the last sung word and the first spoken one; fades may run
    over the speech; overlap is fine.
  - **Evidence step 1, DTW word times (11-08, `storage/scratch/song-end-dtw-20261108/`):** a second
    whisper-server with `--dtw large.v3.turbo --no-flash-attn` (flash attention silently disables
    DTW). `t_dtw` marks a word's end (median 0.03 s from whisper's end on clear speech). The median
    number of agreed words per section rises from 7.5 to 12. On the 23 heard sections decoded first,
    the rule is right on 20 (17 with the current timings). The long moves now find the first phrase
    (2312 +0.46, 3891 +2.00, 4461 +1.40, 3879 +0.52, 828 +1.67, against a heard +1). Misses:
    3695 early, 336053 late.
    - **Corpus decode done (576, 0 errors; `score.py 0.8`).** A DTW word starts at the previous
      word's end, or 0.8 s before its own end after a pause. On all 31 heard sections the rule is
      right on 28 with DTW, against 25 with the current timings (0.6 s gives 26).
      - Misses: 3010 early (+0.06 against a heard +1.07; the operator heard no speech in the gap),
        3695 early, 336053 late.
      - Corpus moves: 238 (246 now), median 0.72 s (1.4), p90 3.1 s (3.5), ≥4 s 10 (20).
      - Against the current timings, 168 sections end more than 0.5 s earlier, 58 more than 0.5 s
        later, and 350 within 0.5 s.
  - **Target logic (operator, 11-08):**
    - At a clear silence after the music, the song ends at the silence.
    - With an outro and then talking, the outro fades from the end of the last sung word to the
      first spoken word.
    - When the talking starts at once, the fade runs over the speech until the outro has finished,
      not for a fixed time.
    - Open: a long outro (fade all of it, or play on and fade at the end?); music under a prayer
      (underscoring); the song continuing after speech.
  - **Vocal separation (Demucs htdemucs, two stems, about 3 s of CPU per 24 s clip;
    `song-end-separation-20261108/`):**
    - The instrument track shows the outro ending under speech: 4451 by +1.9, 3891 by +5.4, 828 by
      +1.9.
    - The voice track shows a pause between singing and speech that the mix hides (3891: 0 to
      +2.7).
    - 3234 is singing, speech ("Let's remain standing, because"), then the next song ("All heaven
      declares").
    - The operator proposed taking the speech out of the voice track instead of fading. Page
      https://claude.ai/artifact/XZ5CGHMdyGKtgJW6ETByf7 compares current / fade logic / speech
      removed (4451, 3891; 828 has two versions).
    - If removal is adopted, it removes only speech positively identified as speech; the lyrics
      protect sung words.
    - **Answers (11-08, "seems promising"):**
      - 3891: all fine ("C [removed] could do with a fade to tidy up").
      - 4451: none; removal "is promising, but could probably do with a fade to deal with the
        leftover sounds where the speech has been removed".
      - 828: the fade logic (end at the silence).
      - Round 2 on the same page: removed, against removed with the outro faded from the first
        spoken word to the outro's end.
      - Round 2 answer (operator): the fade is better than none, but starts too late: "if you start
        a fade on the first spoken word, the first sound of the speech is still quite audible".
        Start it on the last sung word, with a maximum length ("it doesn't make sense to fade out a
        whole musical outro unnecessarily").
      - **Fade rule (operator, 11-08), for speech over an outro:** fade from the end of the last sung
        word to the end of the outro. If the last sung word is more than 4 s before the speech,
        fade from 4 s before the speech instead. The 4 s caps how long before the speech the fade
        may start, not the fade's length.
      - Round 3 plays this rule against round 2's fade. For 3891 it is 0.1 to 5.4; for 4451, 0.1 to
        1.9. A first draft with a 4 s maximum length was replaced before it was answered.
      - **Round 3 answers:**
        - 3891: the operator's rule is right, against the round 2 fade.
        - 4451: can't tell. "I don't think the fade is going to rescue this one unfortunately.
          We might have to accept it." Where the speech follows the singing at once, the ending is
          an accepted imperfection, not a target.

**RA3e — Song ends that run into speech: build plan (11-08, operator: "plan and then implement it
yourself and then commit to master").** Implements the target logic heard on 11-08 (§4.1d above;
E28, and E29 below). Scope: song ends whose cut is `song_end_speech` / `song_end_fade` (576 in the
corpus). The other song-end reasons are unchanged. Each phase is committed on its own, with its
tests green.

1. **DTW word times for the cut decodes.**
   - Infra: a second whisper-server, `com.crockenhill.whisper-aligned-server`, on :2025, run with
     `--dtw large.v3.turbo --no-flash-attn` (flash attention silently disables DTW). Config:
     `media-processing.transcription.local_whisper_aligned_url`, env `LOCAL_WHISPER_ALIGNED_URL`.
   - Data path: `transcribeEdgeWindow($path, aligned: true)` sends the request there and keeps each
     word's `t_dtw` as `aligned_end`.
   - Evidence: `SongEndSpeechOnset` windows carry `word_timing: dtw`. Identity and `read()` require
     it, so DTW decodes never mix with plain ones. Tokens keep `aligned_end`.
   - `AgreedEdgeWords` times a word from its aligned end: it starts at the previous word's end, or
     0.8 s before its own end after a pause. `RULE` becomes `song-end-slide-12-step-2-dtw-v2`.
   - Pass: the heard sections match the scratch scorer (28 of 31).
2. **Vocal separation evidence.**
   - Dependency: Demucs (htdemucs, two stems) in both Dockerfiles, with its weights baked in. The
     local containers get it by pip until they are rebuilt.
   - `scripts/separate_song_end.py <audio> <start> <end> <out-dir>` writes the instrument track
     (FLAC) and JSON levels, voice and instruments in dB every 0.1 s, plus the model and revision.
   - `SongEndStems` runs it on cut − 8 s … cut + 16 s and banks both as service artifacts.
     `PrepareOutputEdgeEvidence` banks it for every fade cut, after the cut decodes.
3. **The decision (`CueSafeExtractionPlan`).**
   - Inputs: the first spoken word S = the agreed run's start (`SongEndSpeechOnset`); from the
     separated tracks, the last sung word L and the outro end E.
     - **L** = the end of the voice track's last activity before S, followed by a quiet of at
       least 0.3 s. If there is no such quiet, L = S: the speech follows the singing at once.
     - **E** = the first time after L when the instrument track falls 25 dB below its level over
       the 3 s before L, and stays there for 1 s.
   - **Silence** (E ≤ S): the song ends at E, with a 0.1 s fade. `song_end_outro_silence`.
   - **Speech over the outro** (E > S): the song ends at E. From L the voice track is replaced by
     the instrument track (0.15 s crossfade), and the sound fades from max(L, S − 4) to E.
     `song_end_outro_under_speech`, with `voice_removed_from`.
   - **No separation banked:** the agreed-speech cut as now.
   - The next item still starts at the replaced cut (E28: overlap is fine).
4. **Rendering.**
   - `extractMedia()` takes the voice removal: the instrument file and the offset in the last span.
     It builds that span's sound as the original up to L, crossfaded to the instruments, before the
     loudness treatment and the fade.
   - `forSection()` carries it as `audio_voice_removal`. A changed removal re-extracts the
     candidate, as a changed fade already does.
5. **Measure and record.**
   - Replay on the heard sections: 3891, 4451 and 828 must reproduce the heard boundaries.
   - Count the corpus split: silence / speech over the outro / no evidence.
   - Register E29, then the full suite, Dusk, PHPStan and Pint, then commit.
   - Still open: music under a prayer (underscoring); the song continuing after speech; the lyric
     check that protects sung words from removal (removal starts only at L, which the voice track's
     quiet places, not at a non-lyric word).

   **Built 11-08.**
   - **Phase 1 committed (`0fc461f85`).** The production class matches the scorer on all 576 DTW
     decodes (`song-end-dtw-20261108/php_rule.jsonl`), 28 of 31 heard right.
   - **Launch agent: loaded by the operator 11-08** (:2025, DTW on, reachable from Docker). The Sail
     image was rebuilt with demucs 4.0.1. The session could not install launch agents. The plist is
     `scripts/launchd/com.crockenhill.whisper-aligned-server.plist`; load it with
     `cp scripts/launchd/com.crockenhill.whisper-aligned-server.plist ~/Library/LaunchAgents/ &&
     launchctl load ~/Library/LaunchAgents/com.crockenhill.whisper-aligned-server.plist`. Until
     then, aligned cut decodes refuse and those song ends keep their planned cut.
   - **Phase 2.** demucs 4.1.0 needs `sphn`, which has no Linux wheel, so both images pin 4.0.1
     (same htdemucs weights, `955717e8`). The running containers got it by pip, with weights in
     `/opt/torch`. The container separates 24 s in 13 s.
   - **Phase 3, as built.**
     - The talking starts where the voice track comes back (at least 0.2 s of voice) within 1 s
       of the agreed onset. 828's onset was 0.7 s early against its voice.
     - The singing stopped where the quiet before it began, if that quiet lasts at least 0.3 s.
       Voice blips under 0.2 s are skipped.
     - The outro has ended when the instruments stay 20 dB below their level over the 3 s before
       min(L, cut) for 1 s, or until the talking, if that comes sooner (talking leaks into the
       instrument track). Plan step 3 said 25 dB; 828's outro fell only 20 dB before the talking.
     - On the separated heard ends: 3891 under the speech (L 0, talking +2.8, end +4.3); 828
       silence at +1.8 (heard about +1.9); 2312 under the speech, faded from 4 s before the
       talking. 4451's aligned decodes find no onset after its cut, so it keeps its cut.
     - The ending keeps reason `song_end_agreed_speech_onset` and adds `song_end_outro`
       (`silence` / `under_speech`), `speech`, `last_sung` and `outro_end`.
   - **Phase 4.** `extractMedia(voiceRemoval:)` crossfades the recording into the instrument track
     over 0.15 s, before treatment and the fade. A real-ffmpeg test checks that the recording's
     tones give way to the track's, by more than 20 dB both ways.
   - **Corpus split (11-08, `song-end-outro-20261108/corpus_eval.jsonl`).** All 238 fade cuts whose
     aligned decodes find an onset were separated on the host (mps, about 3 s each, 0 errors):
     - **Under the speech: 126.** End median +3.1 s (p90 +6.3, max +14.8). Fade median 3.65 s
       (p90 6.4, max 17.4). In 24 there is no pause before the talking.
     - **Silence: 107.** End median +0.8 s (p90 +2.8). 19 end before the planned cut.
     - **No ending (underscoring): 5.**
     - Time the outro runs on after the talking starts: under 2 s 82, 2–4 s 29, 4–8 s 12, 8 s or
       more 3. Those long ones are the open underscoring question; E28 rules out a time limit,
       so they wait for the operator's ear.
   - Full suite green (11,122 tests, the same 163 PHPUnit notices as before); PHPStan and Pint
     clean.
   - **Rendered-endings page (11-08):** https://claude.ai/artifact/RTt8A25Do85ydYyMaPUbhE
     (`song-end-rendered-20261108/`). 18 unheard ends: 6 under the speech, 6 with the outro running
     4 s or more under the talking (the 3 longest included), and 6 at a silence (2 before the
     planned cut).
     - Rendered as `extractMedia` would: the 0.15 s crossfade into the instrument track, the fade
       from max(L, S − 4) to E, a 0.1 s fade at a silence.
     - A = the ending as published, B = the 5 s after.
     - **Answers (11-08, 18 of 18):**
       - Under the speech 6/6 right; silence 6/6 right.
       - Long 4/6 too long (1966, 2031, 173622, 3926; 1966 "outro ends about 69:39" = cut + 2.6
         against E + 14.8); 3769 and 3627 right.
       - Operator: the long ones leave removed speech over near-silence, which sounds bad.
       - Operator: some silences are the recording itself cutting from music. Explore a fade.
     - **Cause, long:** under talking, the instrument track carries short spikes of leaked speech, so
       the strict 1 s all-quiet test only passed in pauses. Built (uncommitted): once the talking
       starts, a second is quiet when half its frames are 20 dB down. Also fixed a float floor
       that put the talking one frame early, and E = V is now `under_speech`, not silence.
       - 1966 → +3.2, 2031 → +3.0, 173622 → +5.1, 3926 → +4.8.
       - Under the speech, the median end − V falls from 1.4 to 0.53 s; ≥ 4 s from 15 to 5.
       - Earlier heard (3891, 336053, 2312) move 0.3–0.7 s earlier; the rest are unchanged.
     - **Hard recording cuts:** 17 of 107 silence ends drop ≥ 30 dB in one frame to ≤ −70 dB
       (1314, 212330, 637 among them).
     - **Round-two page (11-08):** https://claude.ai/artifact/Twxci7xCtYcsSVYfPCpnS5
       (`song-end-round2-20261108/`, blind order in `versions.json`):
       - 12 heard: before vs new.
       - 6 unheard, still ≥ 3 s under the talking.
       - 6 hard cuts at fades of 0.1 / 1.5 / 3 s.
     - **Round-two answers (11-08, 24 of 24):**
       - Heard 12: new better 6 (1966, 2031, 3627, 3769, 3926, 936), either 5, neither 1 (173622:
         "shorter is better, but still too long").
       - 3926: shorter is better but still too long. The operator suspects a spike from the OBS
         switch from the song scene to the speech scene; ideally finish before it or hide it in the
         fade.
       - Unheard 6: right 5; 2922 too long (E + 13.3).
       - Hard cuts: 3 s chosen for 1314 and 637, 1.5 s for 212330; 1236 "3 s or 1.5 s fine"; 4359
         any. 0.1 s chosen nowhere.
       - 176320: too short; ends about 3 s later. The stream drops out for 1.4 s at −2.4, the outro
         comes back at about −50 dB, then the recording cuts out for good at +0.3.
     - **Built 11-08 (round 3):**
       - The half-quiet second under the talking is adopted.
       - `recording_cut`: the sound falls ≥ 30 dB below the second before, to ≤ −70 dB, within
         0.3 s. It fades over 3 s into the cut. That is 17 of 107 silences, all 6 heard among them.
       - A muted stretch the music returns from (a ≥ 25 dB rise to above −60 dB, for 0.5 s, before
         the talking) is a dropout. The search resumes there, against the returned level. Only
         176320 (→ +0.3, recording cut) and 175132 (→ +0.6) are affected.
       - Tried and rejected:
         - Requiring the end frame itself to be quiet moved 105 ends 0.5 s later (1966 → +3.7).
         - Requiring quiet all the way to the talking sent 4359 and 931 to no ending.
       - **Amended (operator, 11-08): never fade the last sung word.**
         - A recording cut fades from max(E − 3, L).
         - 3 s would have crossed L in 11 of 17 cuts.
         - Fades now: 3 s ×6, 1–2 s ×5, 0.3–0.7 s ×3, 0.1 s ×3 (2758, 4269, and 1166, where
           the singing runs into the cut).
         - 637 drops from the 3 s it was ruled with to 0.3 s.
       - **Spike measurement (11-08, `spike_rows.json`):** the largest instrument frame in the 3 s
         after V, against the median of the 0.5 s before V.
         - Heard too long: 173622 +25 dB, 3926 +33, 2922 +35, all above the outro's own level.
         - Heard right/new/either: ≤ +14 dB (3769 +14, 3473 +14).
         - Corpus (125 under the speech): ≥ 20 dB in 7, ≥ 15 in 12, ≥ 10 in 24.
         - Shape:
           - Mostly 0.2–0.3 s bursts while the voice is no louder (173622, 2922, 1991, 176323):
             not leaked talking.
           - 3926 instead stays −27 to −36 dB for over 1 s from the talking.
         - Ending at the spike's onset (≥ 20 dB) would move 2922 (13.3 → about 3.6), 3926 (4.8 →
           about 1.6), 173622 (5.1 → about 2.6) and 1991 (3.4 → 3.2), and no heard right ending.
         - Whether it is the OBS switch is unverified; the video's scene change at the same moment
           would show it.
         - An absolute noise floor (quiet at ≤ −60 dB) also fixes 2922 and 173622 but cuts 2819,
           ruled right at +7.6, to +1.6. Rejected.
       - **Video check (11-08, `scene_check.json`, read-only from the staging sources):**
         - 48 endings checked: the 24 biggest jumps and the 24 smallest.
         - At a 0.25 threshold, no video scene change coincides with a spike.
         - 3926 and 2922 stay flat (scene score about 0.02) through their spikes.
         - 173622's only change is 2.2 s before the talking, about 3 s before its spike.
         - The OBS-switch explanation is not supported visually. A mic unmuted or handled, with no
           change on screen, would fit the bursts.
       - **Quiet-frame page (11-08):** https://claude.ai/artifact/AYzx4H7TqF1DHspQG1H5eN
         (`song-end-quietframe-20261108/`). Current against end-frame-quiet; 8 heard, 6 unheard;
         blind order in `versions.json`.
       - **Quiet-frame answers (11-08):** either 10, neither 4 (2331, 3627, 3811 "speech too
         noticeable in both", 3926). "Doesn't matter much either way": the current rule stays.
       - **Audible talking (11-08):**
         - Measure: the 90th percentile, over V..E, of the voice level plus the fade's gain.
         - All 6 heard bad: −41 to −29 dB.
         - Heard fine: ≤ −43 dB, except 1966 (−36).
         - Built on the working copy only (`endBeforeTheTalkingShows`, −42 dB, uncommitted): the
           latest end ≤ E at which that percentile stays at or under −42.
         - Moves 21 of 125 endings with talking over the outro (median 1.1 s sooner, max 3.2 s).
           Only 1966 among the heard fine.
         - The 95th percentile or the maximum also cut endings ruled right (216672 → +1.9, 4407 →
           +2.1). Rejected.
         - 2922 still ends at +10.1.
         - Page: https://claude.ai/artifact/YLC7BLkRnpNovuwm6KeUNF
           (`song-end-talking-20261108/`): 7 heard, 6 unheard it moves, 4 unheard just under the
           threshold (single version).
       - **Audible-talking answers (11-08, 17 of 17):**
         - Heard 7: new better 3 (2331, 3811, 3926), either 1 (3627), neither 3.
         - Unheard moved 6: new better 4, either 2.
         - Kept 4: right 4.
         - No ending preferred the current version. **Built and committed.**
         - The "neither" endings are all about where the singing ends:
           - 173622: "the last sung word isn't at 72:20, that's the first spoken sound (an intake
             of breath). The last sung word is a few seconds earlier." A breath on the voice
             track stopped the walk back.
           - 2922: "should finish about 35:09" (= cut + 0.4). The accompaniment is almost
             inaudible, all vocal, so the outro's level is meaningless.
           - 1966: the new end "removes the 'has done' at the end of the lyrics". The singing
             runs later than L (−5.3).
       - Open:
         - The last sung word: breaths (173622), singing past the pause (1966). The lyric check
           would place it.
         - Inaudible accompaniment (2922). Measured: the accompaniment is ≥ 30 dB under the
           singing in only 3 of 235 endings, and one is 2819, ruled right at +7.6. Not a class;
           not built.
         - Last sung word, measured:
           - 39 of 234 ends put L at the edge of the 6 s window.
           - The voice level is taken over onset − 6 to onset + 2, so loud talking sets it. Singing
             more than 20 dB under the talking then reads as quiet (1966: singing −47 to −55 until
             about −2.8, talking −23).
           - A breath above the threshold that sets reads as voice (173622: −56 at −0.4 to +0.2,
             singing ends near −3.5).
           - Some edge cases are genuine long instrumentals (2312).
         - The spike rule is measured, not built; audible talking now covers 3926.
       - **Last sung word, built on the working copy (11-08, uncommitted; `lastSung()`):**
         - Voice is judged against the singing's own level: the 90th percentile over the whole
           separated stretch before V.
         - A sung phrase lasts ≥ 0.5 s and peaks within 10 dB of that level.
         - Voice running into V is the talking's onset, unless no pause precedes it (4451).
         - With no sung phrase, L is the edge of the stretch (2312).
         - Results: 1966 −5.3 → −2.8; 173622 +0.2 → −3.8 (now a silence at +1.8); 3627 +2.6 →
           −0.9 (E 4.6 → 6.3).
         - L moves in 88 of 238 endings, audibly in 63. L at the window edge: 46, up from 39.
           Mostly voice tracks with no singing (2081, 336237) or singing that stops early.
         - Page: https://claude.ai/artifact/MrFLq8vz5eZbXRam64qduq
           (`song-end-lastsung-20261108/`): 3 heard, 9 unheard; current vs new.
         - **Answers (11-08):**
           - New 1 (1966), current 1 (3627), either 8.
           - Neither 2: 173622 "both still have the breathy sound"; 175597 "both end with
             speech".
           - Committed for 1966 (lyrics no longer taken out).
           - Both neithers are silence endings with voice after L that nothing removes: 28 of
             102 silence or recording-cut ends have voice above the singing threshold between L
             and E.
         - **Leftover voice, built on the working copy (11-08, uncommitted;
           `voiceAfterTheSinging`):**
           - Silence and recording-cut endings with voice after L take the voice out from L, as
             endings under the talking do. That covers 28 endings.
           - Page: https://claude.ai/artifact/J3wmKgHXTnYZ9PwcLgnTkH
             (`song-end-breath-20261108/`): 10 endings, as now vs removed.
           - **Answers (11-08):**
             - Removed better 3 (2858, 3586, 907), either 4, current 0.
             - Neither 3:
               - 175597 and 173622 sounded "identical" both ways.
               - 677: "this is not actually the end of this song".
             - Committed.
             - 175597's spoken word is as loud in the instrument track as in the voice track
               (−41 against −42 dB), so removal cannot take it out.
             - 173622's breathy sound is an instrument-track jump from −57 to −19 dB at +0.9.
             - Ending at the first leftover voice would cut 1.3–3.1 s of outro from the three
               endings removal improved.
           - **Instrument jumps after L (≥ 20 dB above the 0.5 s before):** 10 endings.
             - 173622 +0.9, 2922 +2.7 (E 10.1; heard +0.4), 3926 +0.9 (E 1.8), 1340, 1542,
               1991, 752.
             - Also 1966 −0.4, and the dropout returns 176320 and 175132, which are music.
           - **Noise rule, built on the working copy (11-08, uncommitted;
             `noiseAfterTheSinging`):**
             - After L, the first instrument frame ≥ 20 dB above the median of the 0.5 s before.
               That median must be above digital silence, so dropout returns are excluded.
             - The song ends before it. 7 endings move:
               - 173622 1.8 → 0.9
               - 2922 10.1 → 4.8
               - 3926 1.8 → 0.9, now a silence
               - 1966 2.1 → −0.4, now a silence
               - 752 1.7 → 0.8
               - 1340 3.2 → 2.9
               - 1991 3.4 → 3.3
             - Page: https://claude.ai/artifact/4nfXnkULB63GcfpZNPgu7C
               (`song-end-noise-20261108/`).
             - **Answers (11-09):**
               - Noise better 3 (173622, 2922, 3926), either 3 (1966, 1991, 752), current 0.
               - Committed.
               - Notes:
                 - 2922 "still too long".
                 - 173622 "only removes the second of two spikes". The first, at −0.3, is a
                   breath that is mostly in the voice track (−82 → −54 dB); removal takes that
                   part out, leaving +8 dB in the instrument track over a −54 dB outro.
                 - 752: "neither really deals with the problem".
                 - 1340: "both have a whole line of speech".
             - Ending at sudden voice-track jumps does not separate breaths from the endings
               removal fixed: 57 endings have one, mostly the breath before the talking, including
               907 and 2858. Rejected.
         - 3234, underscoring, and the lyric protection check.
       - **Song-end lane closed 11-09 (operator: "record the six misses").** No more one-ending
         rules. These six stay as they are now cut, as named misses (audit "Named residue"):
         - 2922: still too long (heard end about cut + 0.4; now E 4.8). The accompaniment is
           nearly inaudible, so the outro level means nothing; 3 of 235 endings are like this,
           and one of them (2819) is ruled right. Not a class.
         - 173622: the first breath (−0.3) is left. It is mostly in the voice track; removal takes
           that part out, leaving +8 dB in the instrument track over a −54 dB outro.
         - 752: "neither really deals with the problem" (noise rule 1.7 → 0.8).
         - 1340: a whole line of speech is left in both versions (3.2 → 2.9).
         - 175597: the spoken word is as loud in the instrument track as in the voice track
           (−41 against −42 dB), so voice removal cannot take it out.
         - 677: "this is not actually the end of this song". This belongs to the song carrying on
           past the cut (961/1915/3103), which RA3 step (a) takes next, not to the ending rules.
         - Still open and owned by RA3 below: the song carrying on past the cut, the lyric-ended
           tail exemption, the planner missing speech inside the section, music under a prayer
           (underscoring) and the lyric protection check. 3234 and 4451 (speech straight after the
           last sung word) are accepted imperfections (11-08).
       - **The song carrying on past the cut (11-09, read-only so far;
         `storage/scratch/song-continues-20261109/`).** The 11-07 "song continues" notes were
         re-read against where each clip stopped and against today's planned cut (guarded probe,
         `probe.php`: read-only disks, autocommit off, savepoint, no staging context):
         - 961 (heard to ≥ 1127.3, the clip end) now ends at 1133.72 before "do please sit down";
           3427 (≥ 267.5, clip end) now ends at 274.07. Inclusive widening moved both; neither is
           listened to yet. 3427's fade still holds the reader's "Psalm 148" (272.2–273.9, the
           cue opens at 261.5), and reading § 3428 still starts at 261.5, inside the song.
         - 1915: the operator's 36:01 was the clip end. Separated, the outro ends 2159.0, digital
           silence to 2171.5, so today's `song_end_silence` 2159.46 is right.
         - 3103: today 1998.14 (`song_end_speech`); with stems banked the outro rule ends it at the
           silence +2.5 (2000.6), matching "33:20". Arrives with the edge step.
         - 677: not an ending. The doxology is § 678, the same title, starting at 677's end with no
           gap; both already hold `adjacent_same_song`, and run 945 is a stopped run with its own
           route. Owned by the adjacent same-song merge work above.
         - **1780 is a real class.** The cut (810.98) sits on a zero-length garbage cue ("Savaş 2")
           after a hallucinated "Thank you." (791–810.98). The aligned decodes agree on sung words
           after the cut; separated, singing runs to +1.5, the outro to +4.3, digital silence to
           +8.5, then speech. `beforeAgreedSpeech()` asks `SongEndOutro` only after an agreed speech
           onset, and 1780 has none (the 815 window reads music 0.53), so the old cut stands.
         - Screen: 338 of 576 fade cuts have no agreed onset. 17 of them have words agreed across
           windows after the cut with the cut in music; separated, 5 continue (1780; 4634 sings to
           +3.5; 3539 keeps a −30 dB outro to the speech at +3.5; 4562 holds a note to +3; 336327
           runs on about 11 s), 2 are fine (806: the next song starts at the cut; 1094), 1 unclear
           (2581). The word screen cannot see a wordless outro, so all 338 are being separated
           (`corpus/`) to measure the class before any rule.
         - **Candidate (`eval.php`):** with no agreed onset, the talking = the first voice return
           after a quiet ≥ 0.3 s following the cut; then `SongEndOutro::ending()` unchanged. It moves
           224 of 338 (174 later, median +2.25 s; 50 earlier) and moves 24 ruled ends by ≥ 1 s,
           about 12 of them ruled keep. Narrowing to a last sung word ≥ +0.5 s still moves 4–5 of
           its 6 ruled ends, and 50 endings would end before their own last sung word. Not
           rejected (operator 11-09: "accepted cuts aren't necessarily perfect"): page
           https://claude.ai/artifact/Pwm6ZoAdDRKbyeqMkPe7Xa (`rulings`, ids `<run>-s<section>`,
           `versions.json` maps Version 1/2 to current/new): the 24 ruled moves, 1780/4634/3539/
           4562/336327 plus 2771 (talking at the cut), and 8 fresh moves. Round 1: 17 answered
           (`versions-v1.json`).
         - **Guarded v2 (`eval2.php`):** two guards. (1) Speech at the cut: the classifier window at
           cut + 0.5 s reads music < 0.3 (or none), so the cut stands. This is the onset rule's own
           guard (175130, 3598). (2) The outro is measured from max(cut, last sung word), so it never
           ends before its singing (4562). v2 moves 106 of 338 (86 later, 20 earlier). Kinds: 161
           speech_at_cut, 39 no_ending, 24 no talking seen; silence 60, under_speech 49,
           recording_cut 5. Round 2 (same page, `versions.json`) asked 15: 7 ruled moves, 8 fresh.
         - **Answers 11-09 (32), scored against v2:**
           - *silence* (outro dies before the talking): 4 new (1780, 2702, 3539, 163060), 5 either,
             4562 neither on the v1 clip ("about 58:43"; v2 ends 58:43.8). None current.
           - *recording_cut*: 1462 either.
           - *under_speech* with a fade ≥ 2 s: 3 new (3515, 4634, 165462), 5 either, and 336327
             neither (singing read as talking; "halfway through the last verse"). None current.
           - *under_speech* with a fade < 2 s (talking starts as the singing stops, so the new end
             is near a hard cut at the speech): 4 current (1521, 2806, 175905, 216555), 3754
             either. None new.
           - Unmoved by v2: 175130, 3598, 3661 current (the guards hold them); 3350, 3642, 4874
             either; 1897 neither (both cuts keep "Well let's turn to", a speech_at_cut cut that
             is too late: separate from this lane).
           - Corpus: under_speech fade < 2 s = 18 of 49. Without them v2 moves 88.
           - Open: 336327 (verse over steady music reads as talk on the voice track), and 1897.
             **Recorded as named misses 11-09 (operator), with 672 and 336013** (voice removal
             leaves the talking audible under the music). Cut as the current rules leave them;
             audit "Named residue".
         - **Round 3 (11-09, 13 fresh under_speech, `versions.json`; round 2's map kept as
           `versions-r2.json`): the 2 s fade line does not hold.**
           - Above 2 s (6): 2399 new; 2274, 2595, 2540 either; 3107 (2.6 s) and 3127 (2.1 s)
             current.
           - Below 2 s (7): 2897 and 2707 current; 3374, 175829, 1674 either. 672 and 336013
             neither, though the new one is better: "the spoken 'We have heard a joyful sound' is
             audible", "still has quite a bit of speech noise". The voice removal leaves the talking
             audible under the music.
           - All 27 under_speech answers: 8 current, 6 new (counting 672/336013), 12 either, 336327
             neither. No measured feature (fade, last sung, speech, voiced at cut, music after,
             noise) separates the 8. The silence and recording-cut endings stay 14 answers with
             none current.
         - **Built 11-09 (E30):** `SongEndOutro::endingFromTheVoiceTrack()`, asked by
           `CueSafeExtractionPlan::beforeAgreedSpeech()` when no onset is agreed and
           `SongEndSpeechOnset::musicPlaysAfter()` holds. It keeps only silence and recording-cut
           endings. Reason `song_end_voice_track_talking` keeps the replaced cut for the next item
           and for evidence preparation. Checked over the corpus (`check-app.php`): it moves 64,
           the scorer's 65 less 3895, whose music after the cut is just under 0.3 (the scorer
           rounded it to 0.3). Not built: under_speech endings; 336327; the voice removal leaving
           talking audible (672, 336013).
       - **Lyric-ended tail exemption re-measured 11-09 (read-only, nothing built;
         `storage/scratch/tail-exemption-20261109/`, guarded like `probe.php`, E30 laid over from
         `song-continues-20261109/corpus/`).** 1,174 historic song sections planned, no errors.
         - The trailing hold fires on 104 planned cuts (122 on stored bounds): 94 of the 11-07 130
           plus 10 new. The rule (last tail content cue is the song's own lyric, ≤ 12 s, no content
           words after it) releases 35: 30 of 58 sung, 2 of 22 sung-then-spoken (2351, 2762: the
           speech is already after the cut), 1 spoken (3427) and 2 new (637, 176225).
         - **Today's planned cuts are the 11-07 cuts for all 35.** The fade-cut onset, RA3e outro
           and E30 move a cut only once the edge step banks agreed words, DTW and stems, and none
           of the 35 has them. So the 9 released "cut short" rulings (796, 1572, 1814, 2819,
           175597, 3282, 3886, 4623, plus 3103 continues) are still cut short here; the exemption
           would publish them without a hold. A re-measure on round cuts needs the edge step's
           evidence for these sections first.
         - **New false release: 3427.** The hymn is Psalm 148 and the reader then reads Psalm 148,
           so the reading's "Praise the Lord from the heavens" (273.9, inside the fade) matches the
           song's own lyrics. A lyric match cannot tell a psalm hymn from its psalm reading.
         - Only 336237 is ruled right among the released.
         - **Edge step run on the 91 runs behind the 104 holds (11-09, operator go;
           `storage/scratch/edge-step-20261109/`).** 91 prepared, 0 failed: 3,336 tail windows,
           544 fade-cut windows, 136 stem separations; 1 h 46 min on 4 processes (GPU-bound, runbook
           updated). It moves 151 of 1,175 planned song ends.
         - **Re-measure on round cuts** (`scored-after-edge.txt`; pre-edge results in `pre-edge/`):
           101 held (91 of the 130, 10 new). The rule releases 24: 18 sung, 3 sung-then-spoken
           (837, 1927, 3795: their cuts moved 1–15 s earlier, before the speech), 1 spoken (3427),
           2 new. Known wrong among them: 3427 (psalm reading), and 1814, 3282, 3886, still ruled
           cut short (the edge evidence did not move 3282 or 3886; 1814 moved 1 s earlier). 175597
           is a named miss ("That's fine." has no content words, so the rule cannot see it). 2819
           releases with "Let's pray" in the transcript, but under voice removal (below). Ruled
           right: 336237 only; 17 unruled.
         - **The hold reads speech that voice removal has taken out.** 9 held cuts end
           `under_speech` with voice removal (796, 936, 2404, 216616, 2762, 2819, 3811, 4333, 4623).
           The trailing check measures transcript to the end of the fade, so the removed talking
           counts as trailing content; 8 of the 9 are held for words that the output does not
           carry. The check should judge the audible output: to the speech onset where the voice is
           removed after it. That is a precision fix to the hold itself, separate from the
           exemption, and it needs listening to confirm the removed talking is inaudible
           (672/336013 say it sometimes is not).
         - **Verdict:** the exemption as specified is not safe alone (4 known-wrong releases of 7
           ruled or known). Next: the audible-tail fix to the hold, then a listening page of the
           unruled releases before any build.
         - **Listening page (11-09):** https://claude.ai/artifact/3LVxbfRgnmNUgUTiqRpjwb
           (`storage/scratch/tail-exemption-listening-20261109/`, `rulings`, ids `<run>-s<section>`): the 17
           unruled releases ("does A end right?") and the 9 voice-removed tails ("can you hear the talking?"),
           rendered from the round cuts with voice removal mirrored from `withoutVoice()`.
         - **Answered 11-09 (26):** voice-removed tails 9 of 9 inaudible, so the audible-tail fix to the
           hold is supported. Released: 16 of 17 right; 837 cut short softly ("ideally an extra second
           with the last note of the outro").
         - **Speech guard on the exemption:** treat the last lyric cue as sung only where the
           classifier window at its start reads speech < 0.5. That stops 3 of the 24: 3427 (psalm reading, 0.61),
           175597 (named miss, 0.61) and 2819 (0.62, under voice removal; the audible-tail fix takes its
           "Let's pray" out of the tail anyway). Every right release reads ≤ 0.03.
         - With the guard, it releases 21: 17 right (16 + 336237), 837 soft, and 1814, 3282, 3886 ruled cut
           short on 11-07 (their cuts unchanged or earlier). The hold only catches those three by
           coincidence: their cuts are the defect, not their tails.
       - **Plan: the song hold judges the clip that publishes (11-09, operator "Yes" to plan + measure;
         nothing built).** Found while scoping the audible-tail fix: production's
         `SongPublicationBoundaryEvidenceService::assess()` judges the stored bounds (plus operator
         edge answers, `asCut()`), not the cut that publishes. That cut, with its fade and voice removal,
         is recorded as `publication_candidate_extraction` in `PrepareSectionPublicationCandidates`
         *before* `requiresApproval()` runs, so the hold can read it. One change, three parts:
         1. `asCut()` takes the recorded candidate cut's outer bounds when present (operator answers are
            already inside it; the planner applies them).
         2. Replace the 5 s `CANDIDATE_END_TOLERANCE_SECONDS` cliff, which 11-07 found clears 32 real
            holds when the planned cut is judged (above). Candidates, measured read-only first: (R1) test
            transcript reach against the stored end, the bound the transcript was segmented against,
            while the tail is measured to the cut; (R2) no cliff at all.
         3. End the judged tail where the recorded cut takes the voice out (`audio_voice_removal.from`):
            past it the clip carries no voice (9 of 9 heard inaudible, page 3LVxb).
         Measure on all historic song sections: holds under stored bounds (today), planned cut + current
         cliff, R1, R2, each with and without part 3; list every section whose hold changes, score
         against the tail labels, the tail/end rulings and page 3LVxb. Then re-run the exemption on the
         chosen variant. Build test-first only after the operator has the numbers. Later users of the
         same "voice present until" bound: the lyric protection check, anything reading cut text.
       - **Measured 11-09 (`storage/scratch/hold-variants-20261109/`, read-only, the service's own private
         steps called through a bound closure; S reproduces production `assess()` on all 1,175).** Trailing holds:
         S (today) 122 · P0 planned cut + today's reach test 100 · R1 reach against stored end 107 · R2 no
         reach test 157 · with part 3: P0V 104, R1V 111, R2V 161.
         - **R2 rejected:** it adds about 55 holds on clips whose last seconds have no cue (many with
           round stored ends, 2000.0, 2115.0): what the reach test exists for, including 637 and 176225,
           heard right on page 3LVxb.
         - **R1 (part 1 + 2):** 23 released, 8 new. Of the released, 12 are labelled or ruled and look
           right: the cut now ends before the speech (921, 1986, 2991, 4220, 4276, 136468, 837 soft), heard
           right (3753, 3795, 4713), or the song-continues fix (961, 1915). 4 are the voice-removed cuts
           whose last gap sits just before the talking (692, 3103, 3627, 176323). 7 unlabelled (1265, 2447,
           3981, 4336, 94692, 173672, 175128) end 4–18 s *later* than stored: a new short gap near the end
           makes the tail < 10 s. Whether the extra seconds are song needs listening. The 8 new holds
           (511, 2072, 2108, 3222, 3561, 4134, 4677, 232943) are unlabelled.
         - **Part 3 alone adds holds (correction to the earlier claim that 8 of the 9 voice-removed cuts
           are held for removed words).** In all 9 the judged tail starts at an instrumental break 40–50 s
           earlier; cutting the talking leaves it well over 10 s. In 4 (692, 3103, 3627, 176323), removing
           the talking moves the last gap back to the break, and the hold then sees a long sung tail. So
           part 3 belongs with the exemption (the line before the cut becomes the song's own lyric), not
           with the hold alone.
         - **The weakness under all of it is "the last wordless gap"**: an instrumental break reads as the
           gap before trailing content (defect (a)), and moving an end creates or removes a near-end gap.
           The variants above only move that brittleness around.
         - **Heard 11-10 (page SW7Za1JVWsWWVdxLEfmAaP, `storage/scratch/later-endings-listening-20261110/`,
           answers in `answers/rulings/`): 5 of the 7 later ends are right** (2447, 3981, 4336, 173672,
           175128). **Two are cut faults that today's hold catches only by accident** (it judges the stored
           end, not the cut):
           - **1265** `song_end_silence`, ends 1394.35, 2 s before the next speech: "the extra seconds are
             random noise. Should finish about 23:07" (≈1387, stored end 1385.8). The silence end runs
             through non-song noise to the gap before the speech.
           - **94692** `song_end_voice_track_talking` (E30), ends 3937.99 with a 0.1 s fade: "speech in
             the last second". The prayer cue is timed from 3929.68; the voice track put the talking
             later, and the end still lands about a second into it.
           So R1 as it stands would publish 2 wrong ends out of 7 unlabelled releases. The hold is right to
           judge the published cut; the faults are in the planner's end. Fix (or class-measure) those two
           mechanisms before R1 is built.
         - **Class-measured 11-10 (`storage/scratch/end-faults-20261110/`, read-only, all 1,175, no errors).**
           - **A (1265's mechanism):** 1265 ends in digital zero from 1386.4. Bursts at 1391.5 (0.6 s) and
             1394.0 (0.05 s) fall in 5 s windows classed music, so `beforeMusicResumes` treats each as the
             music coming back and steps on to the next silence. Of 593 silence ends, 21 skipped a silence
             for a burst with a longest run < 1 s in a music window. One is 1959, the case the rule was
             written for (music did come back: longest run 0.35 s, 0.95 s above threshold in 2 s), so
             "burst < 1 s" alone would break it. The levels cannot separate the classes yet; they need the ear.
           - **B (94692's mechanism):** the `song_end_fade` cut at 3937.79 was already inside the talking
             (voice track active from 3936.4, classifier speech 0.69, singing 0.004). `talkingAfter` only
             looks after the cut, so E30 took the talking for the last sung voice. Only 132 ends have banked
             stems; on those, 9 have a voice-track onset in a speech window 0.4–2.6 s before the end.
             Already heard on today's cut: 94692 bad; 2447, 1521, 3107, 2858 right. 2303 ends at the later
             version the 11-07 tail-onset page turned down (329.4 was chosen), now with a 2.47 s fade. 2187 was heard only
             on an older cut. 3628 and 3666 are unheard.
           - Paged on QJrrG1msJ4HKgQrtJpCZ77: the 19 unheard A ends (version 1 = the earlier silence,
             version 2 = today's) and B ends 2187, 2303, 3628, 3666.
           - **Heard 11-10 (`end-faults-20261110/answers/rulings/`). A: no one chose today's end.** The
             earlier silence: 529, 682, 1172, 1749, 2108, 2566, 2834, 3280, 3589, 3603, 3645, 4089.
             Either: 941, 1635, 1680, 3237, 3672, 174798. 4616 neither: "outro ends about 72:36, but the
             speech needs to be removed. Or it could end before the speech at 72:32" (speech over the
             outro, its own case). With 1265, that makes 19 of 19 for the earlier silence or either.
             **The levels do not separate 1959:** after the skipped silence it has 22% of the time above
             threshold and a longest run of 0.37 s, against 2566 at 29%/0.28 s, 3603 at 28%/0.53 s and
             3589 at 29%/0.51 s (`probe4.php`). 1959 is re-asked on 358G41D77A9Dzu2rVkoqvP (its ruling is
             from canary 13, and rulings are evidence). If it comes back as the earlier silence or either,
             the measured rule is "a silence of ≥ 1 s after the last sung sound ends the song unless the
             sound after it holds for ≥ 1 s".
           - **B:** 2187 right ("no speech, but an intake of breath"). 2303 speech at 5:30 and 3666 speech
             at 6:27, both `song_end_fade`, onset 2.2 and 2.6 s before the end. 3628 speech at 7:25, "but
             faded so not really a problem". Of the 9 voice-track hits, 3 are bad (94692, 2303, 3666), 1 is
             soft (3628) and 5 are right (2447, 1521, 3107, 2858, 2187). Stems exist for only 132 of the
             1,175 ends, so the class's size outside them is unknown.
           - **1959 re-asked 11-10 (358G41D77A9Dzu2rVkoqvP, `end-faults-1959-20261110/answers/`): version 1,
             the earlier silence (714.41).** This supersedes canary 14's "right" at 720.0, and so does the
             reason `beforeMusicResumes` was written. Canary 13's earlier complaint (713.62 clipped "shielded
             by your powerful hand") is about an end 0.8 s before this one. **All 21 heard class A ends agree:
             the earlier silence or either.**
       - **A silence ends the song unless the music truly comes back (class A).**
         1. **Rule.** In `beforeMusicResumes`, the sound after a found silence counts as the music
            coming back only when it stays above the level threshold for ≥ 1 s
            (`SONG_END_SILENCE_SECONDS`, the length the silence itself must last), not just when its first
            sample sits in a window classed music. Otherwise the found silence is returned. No new
            threshold: the silence and its return are judged over the same length.
         2. **Scope.** `beforeMusicResumes` has three callers: the `song_end_silence` path (line ~2746,
            the one measured), the `firstQuiet` path (~2784) and the `lastGap` path (~2792). Before
            building, re-run `end-faults-20261110/measure.php` with the rule applied to all three
            (read-only) and list every end that moves. Ends on the other two paths that were never heard go
            on a page before they change; if any of them must not move, limit the rule to the silence path.
            **Scope replay 11-10:** all 1,175 songs, no errors. The rule moves 50 ends across all three
            paths, or 36 on the silence path alone. The 14 extra moves come from `firstQuiet` (3)
            and `lastGap` (11). The full list and 30 listening comparisons are in
            `storage/scratch/silence-end-scope-20261110/{changes.tsv,index.html}`. Start with 946 §788:
            its 3870.89 end was ruled right on 10-22, and both candidates move it to 3867.64.
            **Heard 11-10:** the proposed §788 end is right. All 20 comparisons on the silence path
            accepted the proposed end or either. The current cuts were preferred for fallback cases
            §175130 and §3201, so the sustained-return check applies only to the silence path when
            cutting the song itself. The following spoken output keeps its own boundary, since the
            noise omitted by the song cut does not belong to the spoken item. §4567 was ruled
            neither, with an end at 7:20, and remains a separate miss.
            Two of the new earlier rulings still fail under either candidate: §529's preferred
            silence starts before the planner searches, and §3589's preferred gap never qualifies
            as a full second of sampled silence. Neither can be fixed by changing what follows an
            already detected silence.
         3. **Tests first** (`SongEndEdgesTest`):
            - 1265 shape: digital zero after the last sung sound, then a 0.6 s and a 0.05 s burst in a
              music window. The end is at the first silence.
            - 1959 shape: a 1.6 s silence, then a return whose longest run is 0.37 s. The end is at the
              first silence. This replaces the canary 13 test at line ~752, whose expectation (play on to
              720) is now ruled wrong.
            - Music that really comes back: a silence, then ≥ 1 s held above threshold. It still plays on.
         4. **Fixture.** Mark `canary14:003-1117-end` (720.0, keep) superseded in
            `tests/Fixtures/Rulings/edge-rulings.json`. Add the 11-10 rulings from
            `end-faults-20261110/answers/` and `end-faults-1959-20261110/answers/`, with 1959, 1265 and the
            12 v1 ends as `inward` and the 6 either ends as `either`. The 30 scope page rulings are also
            in the register. The original captures retain their input evidence, with revised cut
            expectations only for the affected song outputs.
         5. **Then** replay the hold variants with the fix in. The hold change (R1, the hold judges the
            published cut) waits on this.
            **Replay with the fix 11-10:** re-running the 36 changed cuts with the applied planner
            reproduced the silence-only candidate results. R1 holds 107, as before, but now holds
            §1265 and releases §4134. It still releases §94692 with speech in its tail. Counts and
            section IDs are in `storage/scratch/silence-end-scope-20261110/hold-final/summary.json`.
       - **Not planned yet: class B.** 3 bad / 1 soft / 5 right on the voice track, and the track covers
         only 132 ends. It needs its own design: talking the voice track hears inside the fade, told
         apart from breaths and the right endings. 4616 (speech over the outro, "end before the speech
         at 72:32 or remove it") is a single case and joins the named residue.

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
exceptions, not automatic confirmation. §4.1e owns the proposed speaker-identification repair and
resumption sequence. Reuse the workbench; no general redesign. Any proposal to
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

### 4.1e Speaker identification before the next run (proposed 2026-11-08)

**Status: go given 11-09 (operator: "We're going to do it"), not started.** The operator asked on
11-08 to consider fixing and resuming speaker identification before the next processing run. On 11-09
they authorised doing it as this section proposes: implementation, dependency changes, re-enrolment and
inference. Automatic assignment still needs the separately agreed adoption decision in the target below.
P3 and the historic speaker-off preflight/watch stay in force until that work replaces them. Weekly resumption still waits for historic completion under G5/R8.

**Target:** useful ranked speaker proposals for confirmation before the next run, followed by
automatic assignment only if measured accuracy supports a separately agreed adoption decision.
Reduce repeated naming work without overwriting confirmed identities or clearing real identity,
content, access or publication obligations. Do not simply turn the existing implementation back on.

#### Evidence and current blockers

The [September root-cause record](SPEAKER-IDENTIFICATION-ROOT-CAUSE-2026-09-03.md), §§10–12, owns
the experiments and their limits. On the same 104 recordings, ECAPA-TDNN with five averaged windows
improved rank-1 identification from 73.1% to 95.2% over the current Resemblyzer opening-window
approach. On the larger evaluation, nearest-recording accuracy was 89.5%, falling to 76.6% when the
three dominant speakers were excluded. These results justify evaluating the replacement, not
unrestricted automatic naming. The application still binds only Resemblyzer or the null provider.

Repository review on 11-08 found:

- `TalkSpeakerService::detectAndStore()` auto-accepts a match without consulting the configured
  `shadow`/`enforce` mode. Setting shadow globally therefore does not protect short-talk identities.
- `IdentifySpeaker` preserves ID3 assignments but can overwrite a manual assignment on an enforced
  match. Its shadow path also writes confidence and, on a decline, the review flag; it is not a
  purely observational pass.
- Detection-only livestream rounds stop before `IdentifySpeaker`; enabling the flag alone cannot
  add proposals to that round. The historic watcher also stops on identification enabled or queued.
- Local `.env` is disabled but retains `mode=enforce`. Defaults and dated production-action counts
  are not evidence of the effective settings or current profile pool in any executing worker.

This review checked repository code and saved evidence, not current production state. The old
zero-profile and fabricated "Visiting Speaker" causes have code fixes; verify outstanding data
cleanup rather than assuming either that it was applied or that it remains untouched.

#### Proposed delivery sequence

1. **Make proposal mode dependable.** Reproduce the sermon/short-talk mode and identity-protection
   gaps with failing tests before fixing them. Define one consistent contract: proposals may update
   their own evidence, but cannot become a reviewed identity, overwrite confirmed names, withdraw
   existing identity questions or alter other holds, visibility or publication. Disabled, no-match,
   ambiguous, missing-audio and provider-error cases remain distinguishable. Reject stale results
   and make retries idempotent.
2. **Integrate the evaluated encoder.** (The `speaker-id-ecapa` branch named in earlier notes is not in
   the repository, checked 11-10; start from the September record's method.) Subject to dependency approval, add ECAPA-TDNN using the
   existing speaker-identification interface. Pin the model revision and compatible CPU runtime
   dependencies, bake weights into the image and verify offline inference. Version the extraction
   policy and keep ECAPA embeddings separate from Resemblyzer profiles: dimensions, scores and old
   thresholds are not interchangeable. Start from the measured five-window approach; explicitly
   evaluate shorter talks and insufficient-speech cases rather than assuming five distinct minutes
   exist. Use the actual talk's audio, not a whole service containing several speakers.
3. **Build a verified reference library.** Use confirmed manual/ID3 identities across recording
   history, excluding placeholder names, catalogue codes, disputed labels and unverified model
   assignments. Reconcile aliases. Reuse saved embeddings only when source identity and extraction
   provenance can be verified; otherwise regenerate the affected evidence. Evaluate comparison with
   multiple recordings per person rather than assuming one centroid captures their history.
   Evaluate grouping recurring unknown voices so one confirmed name can reduce repeated questions;
   group membership alone must not propagate an unreviewed name as fact.
4. **Validate before adoption.** Hold out recordings across dates/eras, recent services, short talks,
   infrequent speakers and speakers absent from the reference library. Exclude the same recording,
   duplicate excerpts and shared-source copies from both reference and test sets. Report proposal
   rank accuracy, false accepted identities, abstentions/coverage and results by speaker population,
   with denominators and uncertainty. Derive any acceptance/margin rules from this encoder's evidence;
   old Resemblyzer thresholds and aggregate accuracy do not establish safe automatic assignment.
5. **Run a bounded proposal pass only after approval.** Reconcile the eligible current recordings
   and existing confirmations. Reuse suitable retained clips without repeating transcription or
   video processing; missing clips need explicit evidence acquisition, not an invented result.
   Store ranked names, source/span identity, model/extraction/reference-library versions and scores.
   Bind proposals to current evidence, preserve stale results as historical rather than current,
   and reuse RA6's review/batch-confirmation workflow with per-item exceptions.

#### Before freeze and any later automatic assignment

Present the implementation, held-out results, remaining unknowns, runtime cost and expected operator
work before deciding whether the next run can include proposals. Choose explicitly between a
separate bounded speaker pass and a tested integration into the selected pipeline mode. Before any
historic dispatch, record the permitted scope in P3's owning rulings register and reconcile the
runbook/preflight/watch with it; do not bypass the existing speaker-off stop or silently enable the
feature for every worker. Check effective cached configuration and worker versions, with a tested
return to disabled mode. Switching off does not undo prior assignments, which is why proposals and
confirmed identities must stay separate.

Run focused regression tests, PHPStan, Pint and the full parallel suite for implementation; Dusk
where review interactions change. Test provider failures and queue timing as well as successful
matches. Mock tests prove application contracts; actual held-out audio proves model usefulness.

Automatic assignment is a later decision requiring agreed precision/coverage criteria and evidence
for the populations it would cover. Unknown or ambiguous identities retain honest wording and
review; a high score is not confirmation. Planning this work grants no processing or release
authority. If evidence is insufficient before the next run, keep identification disabled and
record the residual identity work explicitly.

### 4.1f Making the next round the last (review 2026-11-10)

**Status: planned 11-10 from a review the operator asked for; nothing built, run or decided by
this section.** The operator wants the next round to be the last and as much automation as is
reasonable (G13). The review read this plan, the runbook, the audit, the programme, the register
and the 11-07 review inventory. It ran no processing and did not look at production.

**"Last round" means the last corpus-wide dispatch.** A later finding is repaired on the named run
through the routes the runbook already has (`sections:recut`, `ProcessingRunOrchestrator::reExtract`,
`sermons:re-extract --held-section`), under the same hash and hold checks. That is repair through
the pipeline, not another round. It only works if the repair route outlives the round (row 8).

**Why the round would not be the last as the plan stood.** A round allows one recompose and one cut
per run per commit, and any commit restarts every worker (runbook §1, §4.2). A defect found after
the freeze therefore cannot be fixed inside the round. Four things were set to surface defects
only after the freeze: the audit's checks, the edge evidence, the unbuilt cut-changing work and
production. Each row below moves one of them ahead of the freeze.

| # | Before the freeze | Done when | Owner |
|---|---|---|---|
| 1 | **Edge evidence for every member, then a complete replay.** Run `historic-import:prepare-output-edges` on every remaining member before the freeze, not as the round's first stage. It writes evidence files and the run's and sermon's composition metadata (`compose()`; source read 11-11), and never changes a run's status or cuts. 42 runs were prepared 11-07 and 91 more were timed 11-09 (`storage/scratch/edge-step-20261109/`); recount what is left. **Pass run 11-12 on `99b6287a9`, authorised by the operator** (`storage/scratch/edge-step-20261112/`): all 423 members (435 in the 11-07 inventory less the 12 stopped runs), four shards, 09:13–12:57 UTC, every shard exit 0 and no error field in any run's output. Decoded fresh: 6,768 of 9,936 song-tail windows, 1,104 of 1,612 fade-cut windows, 32 of 8,679 edge word windows; 276 of 403 fade cuts had stems separated; the rest were already banked. Sentence checks: 388 edges, 1 asked, 107 reported unchecked (to be explained by the replay). It must be topped up for any window a later change moves. The replay and the inventory are next. Run it after the last edge-moving change (row 3), because evidence is keyed by window, model and signature. Then rerun the replay and the inventory. | No sermon end is unassessable for missing evidence (430 on 11-07); stems exist at every fade cut; replay and inventory are rerun on the candidate revision with every moved edge accounted for. | §4.1a steps 2–3; runbook §4.2 |
| 2 | **Acceptance checks whose failure means reprocessing.** Run them now on current outputs and the replay's planned cuts, with rendered audio where the question is audible: source-first completeness on a fresh declared sample (omitted talks, readings, songs and long `other` sections); the transcription-gap follow-up and H10's transcript-negative runs (a failure needs re-transcription); every assembled sermon join; the detector/transcript evaluation; song protocol stage 2/M2/M4. Checks that need the final bytes (full decode, hashes, delivery, thumbnails, editorial fields) stay after the round. | Each lane reports checked/unassessable counts and findings. Every finding is fixed and measured, held, or recorded as residue before the freeze. The post-round audit then confirms; it should not be the first look. | Audit §0 "Before the freeze" and §7 |
| 3 | **Every open cut-changing item gets one of two answers: built and measured, or left as it is and named.** No item stays "pending" at the freeze. The list is below. | Each item below carries a build commit and measurement, or a recorded disposition (held, soft miss under E23, named residue). | §§4.1a–4.1d |
| 4 | **Stopped runs.** 15 historic-era runs are stopped and not excluded (1145 is excluded). 11 are parked on a sermon content hold: 1003, 1019, 1039, 1084, 1095, 1107, 1121, 1176, 1187, 1373, 1380. Four failed for their own reason: 882 (no RMS data for a song period), 904 (transcription interrupted by a host shutdown), 945 (structure failed validation, sections cover 64%), 1143 (several speech blocks over the sermon threshold). | Each has a route written down (held-section cut and release page, retry through its own failure, or exclusion with a reason) and is either a round member or explicitly out. | RA1 routes; runbook §4.2 |
| 5 | **Anything that changes encoded bytes or file tags.** Diagnose 1055's +5.6 dBTP video peak and scan the corpus for the same cause. **Diagnosed 11-12** (`storage/scratch/peak-1055-20261112/`): the treatment reaches the encoder at −1.5 dBTP; the video's audio is one channel duplicated to stereo, and FFmpeg's AAC decoder reconstructs the encoder's noise-substitution (PNS) bands with a right-channel transient of +5.6 in the first second. Apple's decoder gives +0.1 on the same file. `-aac_pns 0` removes it in both decoders (−1.1/−1.1) with no change in loudness and 0.03% in size; mid/side and intensity stereo are not the cause. The reproduction's first 47 packets match the delivered file byte for byte. Stored reports: 57 videos over the peak target, 55 on this dual-mono speech path (parts by excess: 33 under 1 dB, 18 at 1–3, 7 at 3–6, 1 over 6). **Measured on all 57 failing parts in current runs, 11-12** (each part's audio encoded alone, with and without PNS; `pns-results.jsonl`, 119 min): with PNS off 51 of 57 meet the −0.5 target against 23 as encoded alone; a left/right difference over 0.5 dB appears in 21 baselines and in none with PNS off; loudness moves by at most 0.1 LU; PNS off is never worse by more than 0.2 dB. Six stay above target: 1346 part 2 at +3.2 on both channels and 1341 part 2 at +0.4 (a second cause, not PNS), and four within 0.2 dB of target. **Limit of this measurement:** only 20 baselines reproduce their recorded peak, because a later part of a joined output is encoded after the parts before it; a faithful check of those encodes the whole joined output. No cut position is involved. **Fix not built:** the flag belongs in the shared video audio encode (`VideoExtractionService`), and the encode recipe is also described in `MediaProcessingVersion`, so adding it raises which existing outputs count as stale and how the round re-encodes them (391 runs are routed to the edge step only). `HistoricVideoReencodeConcatenator` encodes AAC too and has not been checked. **Ruled 11-12 (register A5):** build the option for every new encode, keep it out of the processing signature (edge words, stems, sentence checks and song-introduction checks are all keyed by that signature, so adding it there would discard every banked window), and re-encode only the affected outputs in the round. **Built 11-12 (`cbd6a12ff`):** `-aac_pns 0` on the shared video audio encode; the signature is pinned by a test; each treatment report records `video_encoding.aac_pns: false`, so a new encode can be told from an old one. **Round route (proposed, not run):** 49 sermons through `sermons:re-extract`, and 6 song sections (961/827, 962/841, 964/874, 1052/1602, 1059/3697, 1309/3929) by expiring those candidates and `sections:recut`; the list is in `storage/scratch/peak-1055-20261112/pns-manifest.json`. Six parts are expected to stay above target under A4 (964/874; sermons of runs 1009, 1017, 1042, 1341, 1346). The fallback concatenator keeps duplicated stereo, so it can show the same fault; its recipe is fingerprinted and was left alone. P5's partial-recording wording: the podcast summary is built and tested (`5f816aa63`); the downloadable MP3's tags are not, and no tag-writing path exists. Read 11-12: the flag is set by hand on the sermon (`SaveSermonDetails`) after the MP3 is encoded on every path, and a direct audio upload is published as uploaded, so tagging cannot ride on the existing encode. It needs either a new finalisation step before publication or a rewrite of a published file when the flag changes. Three local recordings are flagged, all livestream. The public page and the feed already say it; the API resource does not carry the flag. **Ruled 11-12 (register P6): named residue; the tags are not built.** **Built 11-12 (`b3c71c516`):** the API response carries the flag and the page's wording, and the audio descriptions in structured data say it too. Speaker's name, read from code 11-11: branded thumbnails bake it in and can be rebuilt from the retained frame; feeds read current data; MP3s carry no speaker tag. So §4.1e's late names need thumbnails regenerated and feeds refreshed, not a cut; recheck once MP3 tagging lands. | The peak has a cause and a corpus count, fixed or accepted; P5 wording is built and tested; the list of name-bearing artefacts and their regeneration route is recorded. | Audit §7 "Thumbnails and partial recordings"; sound treatment stage |
| 6 | **Production census and one small era rehearsal.** Run programme §0.4 step 0 on the server (read-only) and compare identities with the local graph. Rehearse export and quarantined apply of one small era, with media, on a production copy. Check the song catalogue: clips bind local song IDs, and an OpenLP merge re-binds nothing. | Create/enrich/leave-alone dispositions exist for every production row in the historic range; membership changes are fed into row 1's recount; the rehearsal applied media end to end; catalogue differences are listed with their relink route. | Programme §0.4, IC5, §7 |
| 7 | **Talk-type detection for the weekly pipeline.** See "Talk-type detection" below. Type does not change a cut, so the work must be shaped so that it needs no round: a step of its own over finished sections, not a change to the structure prompt. Speaker identification is §4.1e's and is not repeated here. | **Design accepted 11-11 with one amendment (register T8).** **Ground-truth page published 11-11** (334 pending talks; `storage/scratch/talk-type-truth-20261111/`; answers in the page's `rulings` collection, keyed by section id; no answers yet). Still to do: the type step is confirmed to run on existing sections without re-detection. Measurement and any ruling on automatic confirmation can follow the round. | Register T1, T2, G10; RA6 |
| 8 | **Repair route after the round.** **Decided 11-11 (register C7): the route is kept.** Masters on the Sonnics archive, a database dump at closeout and the promotion tooling outlive IC8. | One named run is taken through each single-run route as a test before the freeze (processing; not yet authorised). | §4.6; programme IC8 |

**Row 3: the open cut-changing items (state on 11-10).**

- Song hold judges the published cut (R1, §4.1d RA3): measured, not built. The 5 s
  `CANDIDATE_END_TOLERANCE_SECONDS` is still in `SongPublicationBoundaryEvidenceService`. With the
  silence fix in (`741918dbd`), R1 holds 107 and still releases §94692 with speech in its tail,
  so it waits on class B or an explicit guard.
- Lyric-ended tail exemption with its speech guard: measured 11-09, not built.
- Class B, speech inside a fade end (94692, 2303, 3666 bad; 3628 soft): no design. Stems cover
  132 of 1,175 ends, so its size is unknown until row 1 has run. Design it after row 1, or record
  the class as named residue with its hold kept.
- Still listed open under RA3: the planner missing speech inside the section (the onset rule is
  built, `5d8db2cf8`; the surviving mixed-music cases need the replay), music under a prayer (E29
  sets the default; whether cuts find the prayer onset needs the replay) and the lyric protection
  check (no check over rendered outputs exists). Under-speech endings are disposed of by E30: the
  cut stands, the audible residue stays named, and it reopens only on new separating evidence.
- Done, with application left to the round: K21's first banked-vote inventory and the local 1140
  repair (`52906772a`), the 956 reading-join guard (`82e980be4`), 954's approved join with its
  dropout recorded (§4.1b), and K20's run 1301 omission (`15abead41`; R10's recompose still applies).
  Open: K21's recount after edge evidence, E27 overlap on rendered transitions (needs listening),
  1233's start (needs a wider decode) and the other four no-passage stretches under §4.1b
  (1044, 1258, 960, 1163).
- RA4 (173 records on 11-07) and RA5 (331, 49 of them unassessable): no build; both wait for the
  inventory after the replay. RA6's answer preservation (the 1148/1197 renumbering cases) matters
  most here: a recompose that re-asks settled questions is manual work the round would create.
  **RA6 answer preservation built 11-11, uncommitted:** composition approval hashed numeric
  section ids, so equivalent sections with new ids re-asked. A review now records a content
  identity for each selected section (type, span, references, continuation, talk type and
  access; not title or model notes), and an existing approval gains it the next time a
  persisting `compose()` finds it still valid exactly (`upgraded_from: exact_legacy_match`).
  All 18 local reviewed runs hold the old form, so they are upgraded by the edge step's
  `compose()` before any recompose. A changed span, reference, membership or access still
  re-asks. Full suite 11,191 tests with no failure, PHPStan and Pint clean. Audit 11-12 (pure
  identity calls, no `compose()` on real runs): 17 of the 18 validate under both the old and the
  new gate; 1250's approval is already stale and re-asks either way. Section sync and section
  removal now bank a still-valid approval before they replace any row (built 11-12), so the 17
  are preserved with no prior manual step. Banking writes the review record only; if it fails
  for any reason but a database error it is reported and the sync carries on. The 1148 case was not harmless renumbering: its old sections became
  short dropout fragments and sermon material moved into new rows, so re-asking it was right.
  1197 kept its ids and was invalidated by rewritten wider dependencies.
- **Added 11-11, read from code and not reproduced:** a song end the operator sets by ear keeps
  the planner's fade and voice removal (`CueSafeExtractionPlan::withOperatorEdges()`). An end set
  later than the separated-audio window (the cut + 16 s) would leave the clip's tail silent. Local
  count 11-11: two songs have an operator-set end; both have a fade recorded, neither a voice
  removal, though their cuts may predate E29. Architecture AM15.0 step 3 writes the test and AM-D6
  holds the options. **Ruled 11-11 (register E31):** the fade and voice removal are kept and an end outside
  the window is refused. **Built 11-12, uncommitted:** the planner refuses such an end through the
  refused-edge route, reading the window stored beside the instrument track (no fixed duration);
  a fade alone needs no separated track and is never refused for this. Reproduced first: an end
  at 4507.36 s was accepted with coverage ending at 4507.11 s. Extraction also rejects voice
  removal that outruns its track, allowing 1 ms because the windows are recorded to the
  millisecond. Measured 11-12: no stored cut carries voice removal yet; the 43 planner captures
  of 11-09 clear their tracks by 8.4–16.6 s; 137 banked tracks are sample-exact FLAC and one
  window differs from its file by 2 µs. 3803 and 3922 are unchanged.
- Built but unapplied work is not on this list. The round applies it: K18/K19/K20 starts, split-song
  snap, the ten stale generations, the 33 Scripture refreshes, the song-end rules through `741918dbd`.

**Talk-type detection (row 7): state on 11-10 and planned work. No ruling is changed here.**
The operator's direction (11-10): the weekly pipeline needs more work before it detects talk type
automatically. Speaker identification has its own plan (§4.1e) and is not duplicated here.

What exists. The structure model proposes `talk_type` for each short talk as one field of its
structure answer (`OpenAiServiceStructureService`), with no confidence and no reasons. Only a type
the operator confirms is published (T2), and every short talk also needs approval
(`TalkPublicationHandler::requiresApproval()` returns `true`). Local count on 11-10, 355 short-talk
sections:

| Proposed type | Sections |
|---|---:|
| `childrens_talk` | 134 |
| `partner_update` | 44 |
| `testimony` | 18 |
| none | 159 |

- **No type has ever been confirmed locally**, so the proposals have never been measured.
- **45% have no proposal.** The prompt allows a children's talk only on structural cues and says
  "null when none of these is clear". The unproposed titles are recurring kinds: catechism talks,
  heroes of faith, Bible-character series, "Family Talk". 156 of the 159 are bound to an
  order-of-service item, so there is evidence the proposal does not use.
- **Ruled 11-10 (T7): catechism, hero-of-faith and Bible-character talks are children's talks.**
  Those names identify the series the talk belongs to, and the series is to be recorded. T1's
  four types stand. The `sermons` row already has a `series` field; the section proposal does not
  carry one yet.
- **A wrong non-sermon type is not harmless.** Standalone testimonies are withheld by default
  (G10), so a testimony typed as a children's talk would be released to members.

Planned work, in order:

1. **Ground truth.** One published review page (ruled 11-11: an artefact, not an admin screen) on
   which the operator confirms the type of the pending talks (type
   only; transcript opening, the bound item's title, the proposal). "None of the four" is an
   allowed answer. These are the first confirmed types and the only measure available.
   **Built 11-11:** <https://claude.ai/artifact/VympHgFStUNCyG59j8NTBr>. 334 talks, not the 355
   counted on 11-10: 20 belong to superseded runs and one to an excluded run. Proposals among the
   334: 116 children's talk, 43 partner update, 18 testimony, 157 none. The page shows the proposal
   beside each talk, as planned here, so the answers may lean towards it.
   **Changed by the operator 11-11, after seeing the page:** confirming every talk by hand is not
   sensible ("I'm not really doing anything you can't do"); the proposals seen so far looked right.
   The talks are read by agents, blind to the proposal, rules are derived from the readings and
   applied automatically, and the operator sees only the talks where rule, reading and proposal
   disagree. The rules must handle two cases the operator named: speech that is not a talk in its
   own right (a comment on a reading or song, an extended introduction, a link), and family
   services, where a children's talk and a sermon are told apart by whether the same service has
   a full-length sermon. Study in `storage/scratch/talk-type-rules-20261111/` (started 11-11).
   Automatic confirmation still needs step 5's ruling on the numbers.
   **Study run 11-11/12** (evidence and rules by Sol, blind reading by Luna, tables by Claude):
   nine ordered rules, frozen before the held-out half was run, decide 93 of 167 held-out talks
   with a reading agreeing on 88, and 99 of 167 derivation talks with 98 agreeing. "Full-length
   sermon" measures at about 16 minutes: the longest sermon per service has a gap between 825 s
   and 1,100 s. All 334 now carry a type: children's talk 228, partner update 41, not a talk 33,
   testimony 14, sermon 7, could not tell 11. The operator's seven answers on the first page all
   match. Luna agreed with Sol on 121 of the 166 talks both read and over-called sermons (10).
   Sol's assessment after seeing the held-out half: keep the T7 item rule, the declared-series
   rule, the testimony frame, the family main-address rule and direct address to children;
   leave partner updates, links and the three memorised recurring series to the model step.
   **Open for the operator (page republished with these 15):** an address to adults in a service
   that also has a full sermon (11), a member's report on a conference or event (3), a
   remembrance of church members (1). 4775 is typed not a talk: its video was not captured (66
   words in 3 minutes). Files: `final-labels.json`, `results.json`, `rule-changes.md`,
   `residue-final.json` in the study folder.
   **The operator answered all 15 on 11-12 (register T9;** `operator-answers-20261112.json`**).**
   Ten are family talks: nine of the eleven addresses to adults in a morning service that has a
   full sermon, and the remembrance. Four are none of the four: the three event reports and
   "Making Bible reading profitable", with the note that a type for general short talks is
   wanted. One is open: 190427 (evening, 12 May 2024, run 944) was answered none of the four with
   a question. Its recording is 56 minutes with no songs: a reading of Psalm 77, this 13-minute
   talk on Psalm 77, then a 39-minute sermon on another text. That fits the operator's guess
   that two recordings are joined, in which case this is a sermon.
   **Ruled 11-12 (T10):** the new type is the church update, members only. **Built 11-12
   (`a7d54421c`, corrected by `41556695f`):** a fifth type, absent from public pages, listings,
   search, feed, sitemap, API and structured data, with a test for each; "none of these types"
   stays a separate review answer; the children's talk type is shown as "Family Talk"
   everywhere. Full suite 11,250 tests with no failure, Dusk 64, PHPStan and Pint clean.
   **Owed:** the migration that widens `sermons.content_type` has not been applied to the
   working database and the schema dump has not been regenerated (the dump freshness check fails
   until it is); on production the migration runs at deploy. The old wording remains as data in
   3 sermon titles, 3 slugs, 6 service-item titles and 1 section title, untouched. A
   members-only talk's media link is a storage URL that works for anyone who has it, as for
   other members-only talks.
   **Still owed from T9:** the rules "morning family slot means family talk" and "evening
   services have no children's talks", which belong to the type step (T8).
2. **Apply T7 and record the series.** A talk in a catechism, hero-of-faith or Bible-character
   series is proposed as a children's talk, and the series is proposed beside the type and
   published in the talk's `series` field. Any other kind that step 1 answers "none of the four"
   is grouped and put to the operator; nothing further is assumed.
3. **Make type detection its own step.** A classifier over a finished short-talk section: its
   transcript, its bound order-of-service item, its recurring title or series and its place in the
   service. It records the proposed type, the proposed series, a confidence and the reasons. T7's
   series rule is applied from the bound item's title before the model is asked, and which of the
   two decided is recorded (amendment accepted 11-11, T8). It does not touch the structure
   prompt, so it moves no section and can be rerun on existing sections at any time. The same step
   runs for weekly uploads; nothing is historic-only.
4. **Measure by type against step 1.** A confusion table, with testimony errors and "typed when
   it should be none" counted separately. Hold out part of step 1's answers from any tuning.
5. **Decide automatic confirmation.** The operator sees step 4's numbers and rules which types,
   if any, are confirmed without review, amending T2. Testimony stays manual unless ruled
   otherwise (G10). Whether approval then follows without a separate click is a further ruling.

Steps 1 and 3's design come before the freeze; the rest can follow the round, because a
type changes access and labelling, not a cut.

**Order.** Row 3 first (it changes cuts), with row 7's design alongside; then row 1 and the replay; rows 2, 4, 5, 6 and 8
can run alongside. Then §4.1a steps 5–6 and the runbook. If a row cannot be finished, record what
is left out and why: an explicit hold or named residue is a disposition, an unlisted gap is the
next round.

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
- [ ] Land architecture AM15 (cut planner) between the last round's close and the take-over check
  below, if AM-D5 is answered that way, so the check runs on the planner weekly will use. AM15
  changes no cut; its acceptance is the round's recorded cuts replayed unchanged.
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
- [ ] **Before the freeze (moved from "before IC8" on 11-10, §4.1f row 8):** the repair route is
  kept (decided 11-11, register C7); the test of each single-run route on a named run is owed. The
  masters/promotion tooling currently live locally. After the last round, a finding is repaired on
  the named run through the existing single-run routes, not by another corpus-wide dispatch.

### 4.7 Legacy sermon transcripts (decided 2026-10-17; planned, not built)

The decided scope and implementation contract now live once in
[programme §6, legacy transcript follow-up](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#legacy-transcript-follow-up-decided-2026-10-17).
It includes production-only sermons and starts after historic completion and weekly take-over.

## 5. Go/no-go

| Decision | Recorded status / next evidence |
|---|---|
| Last-round readiness | §4.1f, added 11-10: eight rows open. Reconciled against the code 11-11/12: rows 7 and 8 have their decisions (T8, T9, C7); row 5 is ruled (A5, P6) with two small builds owed; row 3's answer preservation and operator-end guard are built and committed (`a6abce118`, `8f523439f`). **The operator authorised the edge evidence pass on 11-12 if it takes under eight hours.** Most of the rest of row 3 waits on it. No freeze until each is done or disposed of. |
| Processing | November round and named repairs closed. K19 reach-back built and ruled 11-06, not yet applied; it and the other later built fixes need the measured routes and output checks above. No new dispatch authority. |
| Content acceptance | Incomplete. Ten stale main generations require verified recovery; other coverage, content and editorial obligations are in the audit ledger. The old 21-container-path incident is resolved, not an outstanding loss. |
| Production convergence and delivery | Fresh programme census, rehearsal and destination verification still required. |
| Public release | No batch authorised; programme §8 owns the separate act. |
| Weekly processing | Paused until historic completion, explicitly reaffirmed 11-06. |

Update current statuses in place. Keep detailed execution diaries in dated evidence records;
link their closure or carry an item as explicitly unverified. Never infer closure from archival.
