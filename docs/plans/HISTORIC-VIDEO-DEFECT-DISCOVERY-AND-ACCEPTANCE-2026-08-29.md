# Historic Video Defect Discovery and Acceptance Plan

**Current worklist — reconciled 2026-10-30.** Historic outputs remain quarantined; no release batch
is authorised. The 10-21/22 round closed. Later shared-path fixes still need a measured round and
current-output acceptance. 1288's onset leak is fixed (§4.1a step 2). What remains before a freeze is
§4.1a steps 3–4 in the order the 10-30 review sets (step 3, "Order of the remaining work"; ledger done 10-30), no
operator decision outstanding (958 "either" and the residual-miss rule E23 decided 10-30) and internal-disk headroom.

This file owns **current video work and status**, not execution diaries or acceptance procedures.
The complete prior text is preserved in the [execution record through 10-29](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md).
Reconciliation below uses recorded evidence, not a fresh database or production census.

## 1. Outcome and boundaries

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

## 2. Completed work and archived evidence

| Recorded work | Reconciled status / evidence |
|---|---|
| Processing rounds through 10-22 | Closed; no release implied. [Closure record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md#10-22-later-the-round-closed) |
| Three duplicate/date pairs | Corrected locally 10-16, then re-detected, re-cut and seven identity holds released under X4 in the later 10-18 record. The old §4.4 instruction to do those steps is superseded. Other song/quality holds remain independent. See §4.4 below. |
| Ruled-evidence capture | Full capture/scoring and CI ratchet landed 10-23; later decodes and rulings supersede its counts. Do not restart the old 883-ruling capture backlog. Current reconciliation is §4.1a/§4.6. |
| Stored review-state repair | Re-derive → recompute → backfill → demote applied locally 10-25. The earlier instruction to apply it is complete; fresh policy/output checks are still needed after processing. |
| Song and speech edge fixes | Dependent openings, 1096's other-song title rule, 1120's song-order lyric extension, fading-song speech starts and the narrowed smeared-opening fix are committed. Six moved smeared starts were heard acceptable; 1250's internal start is met by the planner. They still need pipeline application and encoded-output verification. |
| Internal-join regression coverage | Added 10-29 (`abd330797`), including part-specific identity; capture's `open_defect` handling corrected. 86 previously unguarded passing entries now checked; 17 failing entries remain inputs to the next measurement. |
| K16 reword | Parked 10-29 (one gain 1131, one loss 968), then measured on every end and **adopted as measured 10-30** (operator option 2). **Built 10-31** (§4.1a step 4, `PROMPT_VERSION` 3, 962 trailing-part fix); losses 968, 1375, 1324 accepted, 1096 a further open miss; its two extra guards stay unbuilt. |
| Audience/content policy | Decided (G10–G11); production enforcement is a verification task under programme §8, not an open policy question. |

Earlier evidence: [10-03–10-16](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-10-03-TO-2026-10-16.md),
[09-24–10-02](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md),
[09-12–09-23](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md),
and [pilot/bulk](../archived-plans/HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md).
These explain decisions; they do not supply current route lists or counts.

## 3. Current measurement status

No new census was run for this consolidation. October 16 membership, readiness counts and disk
figures are historical. Even the later 426-run / 1,351-ruling counts predate subsequent changes.
Recount eligible runs, unresolved rulings and unassessable evidence on final code before freezing.
The audit's dated discovery totals similarly require hash reconciliation, not automatic credit.

## 4. Remaining work in execution order

### 4.0 Round status

The previous round is closed. There is no current dispatch authority in this document. Complete
§4.1a, then use the runbook for an authorised frozen round. Do not reuse October route lists.

### 4.1 Current processing work

The only current pre-freeze sequence is §4.1a. The old “one final round” narrative and its clone,
queue and route state are in the archive; a historical “final” label is not acceptance evidence.

### 4.1a Before the next round: regression coverage and combined verification (2026-10-29)

1. **Internal joins — implemented; retain regression protection.** Part-specific rulings cover
   1208 and 1235 without superseding the video's opening. 1250's approximate 28:59 internal start
   is protected by `SongEndEdgesTest`. Include all of these in final replay and encoded canary checks.
   The 17 still-open fixture entries from `storage/scratch/open-defect-fix-20261029/` need dispositions
   in step 3; the 86 newly guarded passing entries must remain guarded.
2. **1288 — resolved; next-word-sound rule adopted (10-29 night).** “Amen Heard Again” confirmed a
   real defect (B again: “It's not the second Amen that's the problem.”): `beforeWordHeardElsewhere()`
   stopped 1288's end on the decoded onset of “So” (3755.31), carrying ~50 ms of it. Measured
   read-only over 2,024 plans; the dip before the onset, not the leak, separates the cases.
   `CueSafeExtractionPlan::beforeNextWordSound()`: an end on a decoded next-word onset with the
   sound already up and a ≥20 dB dip in the preceding 0.25 s ends at that dip's quietest point.
   [Before The Next Word](https://claude.ai/artifact/8sq6bC4EP16CR9fnjdX6r7): all four B, operator:
   “significantly better … there isn't really a pause between words” (keep: 928 internal reading end
   1866.11, `part` §512; 1288 3755.19; 1340 3271.89; 1303 §3860 1129.84). Corpus replay moves exactly
   those four. 1288's three tests are green; full suite 10,905 passed, Dusk 63/63. Starts excluded.
3. **Measure the combined result on final code.** Recount eligible runs and open/unassessable
   rulings; compare current executed cuts with proposed plans, including internal joins, refusals,
   changed edges and conflicting rulings. Use existing replay/capture tools; write-capable planners
   run in rolled-back transactions outside an active round. Record exact membership and prerequisite
   routes: **1120 needs `ExtendSongsOverOwnLyrics`, not extraction alone**. Dispose of every move
   and refusal; reuse unchanged valid evidence and hear new/conflicting audible cases.
   **In progress 10-29.** Membership recounted: **425** eligible runs (completed, non-superseded
   historic), identical to the 10-24 list; nothing in flight. Replay
   `storage/scratch/combined-measure-20261029/` (`measure.php` → `plans-*.jsonl`, `analyze.py`): per run,
   one rolled-back transaction inside the run's staging context; plan A on stored sections (the
   `reExtract` route, and the actual route of 942, 974, 1363), then the round's recompose replayed
   (stamp + request on the latest banked attempt, chain from detection to the refining projection,
   including `ExtendSongsOverOwnLyrics`), then plan B. No provider call. 1120 confirmed: extraction
   alone ends §1999 at 952.46, recompose at the ruled 961.27; recompose also moves §2004's start
   1365 → 1355 (musical introduction, unheard). The first pass (`run1-no-ocr/`) is not usable for
   songs: OCR is an OpenAI vision call the replay cannot make. The corrected pass replays each
   unchanged song's banked OCR text instead. Results and dispositions pending.
   **Incident (10-29 night): the corrected pass deleted 126 recorded clip files.** It ran each run
   inside its staging context, so the sync's replacement branch (`cleanupExtractedAssets()`) deleted
   real files on `historic_staging`; the database rollback held (rows verified unchanged), the deletes
   did not. 87 short talks, 36 songs `not_applicable`, 3 songs `pending_approval`; list and recorded
   hashes in `combined-measure-20261029/deleted-clips.json`. No second copy: 123 none, 3 an older cut in
   quarantine; staging is single-copy (C2). The first pass (no staging context) deleted nothing.
   They are derived from the masters and need a re-cut before any review of them. **Operator 10-29
   night: "Let the next round do it."** The round's membership must include all 96 runs in that list,
   and its diff must show each of the 126 sections with a new recorded clip.
   Replays must not activate the staging context and must mark disks read-only.
   **The 17 open fixture entries, disposed (stand-in pass; confirm on the fixed pass):** met by the
   round 2 (1012 start, now governed by 10-25 `keep 0.0`; 1070 §3705); superseded by K16/the Amen rule
   4 (1053 §1610, 1186 §2410, 1070 end, 1218 end: each ends on the preacher's Amen, dropping only the
   song announcement or the congregation's Amen); conflicting ruling, to hear 2 (1115, 1127: a 10-18
   "cut off mid sentence" says later, K16 now ends earlier on the Amen); open misses to step 4: 9 —
   late sermon/reading starts 1136, 1308, 1262 §3289; `song_end_fade` song ends 1215 §2637, 1108 §1895,
   1108 §1897; singles 1262 §3278, 1262 §4913, 1108 canary-11 start. Section rulings on sermon sections
   (§1610, §3705, §2410) are judged on the sermon video's edge there. `gather.py` maps every
   `wrong_place` answer to `outward`; four are structure answers ("the middle of a different song"):
   1012 and 1282 are superseded, and 1273 §3416 and 1311 §4874 are **settled (disposed 10-31, (c) below)**.
   **Classes measured for step 4:** 38 sermon starts lie > 5 s after their section start, nearly all
   unchanged by the round; 2 ruled late (1136, 1308). `song_end_fade` places 234 song ends; of 63 ruled,
   54 met and 9 missed, early (941 §751, 976 §958, 1272 §3403) and late (1215, 1108 ×2) alike, so no
   single bias; the rest marginal (1250 §3127, 1292 §3653) or unchanged (1262 §3288).
   **Step 3 status at 10-30 (handover).** Done: membership (425), the guarded route replay on final code
   (`no-stand-in/`, option 1, committed `c24d55d60`), 1120's route, the 17 open entries, the K16
   shortenings, the late-start and `song_end_fade` classes, the K16 reword on every end. Still open:
   (a) the edge moves — 451 outputs move, 380 edges by ≥ 1 s (runbook §5: each ≥ 1 s move goes on a
   page unless a ruling the new cut meets already covers it; group the rest by `reason`);
   (b) 22 refused plans and 87 unassessable rulings, each with a cause; (c) structure dispositions for
   1273 §3416 and 1311 §4874 (**done 10-31**, below); (d) the draft page `storage/scratch/shortened-ends-listening-20261029/`
   (6 questions: 1004, 1005, 1115, 1127, 1157, 1219; built, not published) plus whatever (a) adds;
   (e) the reword adoption (step 4). Analyse with `python3 combined-measure-20261029/analyze.py
   combined-measure-20261029/no-stand-in`. Before any round: the internal disk had 4.4 GiB free on 10-30
   (runbook asks ≥ 10 GB and its watcher pauses under it; `storage/scratch` 26 GB, `laravel.log` 809 MB;
   the 10-25 binlog purge freed ≈ 22 GiB; nothing deleted without go). **Cleared 10-30 (operator: "Dump,
   check, purge"): 5.6 → 12 GiB free.** Full dump `/Volumes/Sonnics/_pre-round-disk-clear-20261030-backup/`
   (39 MB gz, SHA-256 in `SHA256SUMS`, `gzip -t` passes, completed 08:46:13, coordinate `binlog.000144` pos
   5227245, 61 tables, no `USE`/`CREATE DATABASE`); restored with `sql_log_bin=0` into a side database, every
   table's count equal to live, side database dropped; `PURGE BINARY LOGS TO 'binlog.000144'` (≈ 5.1 GB);
   `laravel.log` truncated (839 MB); `storage/scratch/binlog.000067` (21 Aug, single copy) moved to
   `/Volumes/Sonnics/_scratch-binlog-000067-20260821/` after `cmp`.
   **Order of the remaining work (review 10-30).** The step 3 replay and step 4's code interact: a banked
   K16 answer is read only when its whole identity matches, and the identity carries `prompt_version`
   (`SermonSongIntroductionCheck::question()`), while `measure.php` fakes OpenAI with no responses. After
   step 4.1 a replay therefore finds no K16 answer for any end. So:
   1. *Ledger first* (step 4.0): the rulings that score K16 ends are incomplete, and (a)'s "already covered
      by a ruling" test reads them.
   2. *(a), non-K16 moves, now* on `no-stand-in/`: subtract the moves an existing ruling the new cut meets
      already covers, group the rest by `reason`, and only then size the pages.
   3. *(a), K16-decided sermon ends:* judge the reword's measured end (`k16-reword-20261029/measured*.json`),
      not `no-stand-in/`'s v2 end; either overlay it in `analyze.py` or give `measure.php` a read-only
      stand-in from `answers/`. These are one-draw proposals; the round redraws them (step 4.5).
   **Steps 2–3 measured 10-30** (ledger at `5cdf5fa9d`; table `no-stand-in/moves-ge1s.json`, one row per
   ≥ 1 s move with `class`, `reason`, `k16`; a move is covered when a live ruling on that output is met
   at its new cut). Of 380 moves, **192 non-K16**: 111 covered, 7 contradict a ruling the executed cut
   met (928 sermon start +1.32 s, `word_pause`, against its 10-29 keep; 982 sermon start −5.3; 990 sermon
   start +6; 1073 §1716 start −10; 1130 §2066 start +2.75; 1272 §3403 end and §3404 start −5.5 at the
   "dip"), 3 ruled and missed both ways (1120 §2012, 1295 §3769, 1342 §4322), **71 unruled**: song starts
   38 (26 `song_start_sentence` later, 11 `word_pause`, 1 none), song ends 16 (12 `song_end_silence` later),
   short talks 7, sermon starts/joins 10. Sub-second regressions (not paged by the runbook, listed here):
   961 §828 end, 1042, 1094, 1281, 1379 sermon ends, 1292 §3653 end (0.5–0.93 s). **188 K16 sermon ends**,
   judged at the reword's end where it moves one (15 runs; raw line ends, before the edge refinement, so
   a ±1 s miss there is the refinement, not a loss): 123 met, 39 unruled, 11 contradict, 15 miss both
   ways. Of the 11, 8 drop only an announcement or the congregation's Amen against a 10-18–10-22 keep
   (961, 1038, 1076, 1194, 1218, 1219, 1296, 1326) — the 10-28 Amen ruling, shorter wins — 1007 drops the
   closing prayer, 1044 is the reword's end 0.31 s from its ruled either. Misses both ways: 1004, 1115,
   1127, 1167, 1283 against a 10-18 "cut off" (the (d) pattern; 1167, 1283 not yet on (d)); 1070, 1190,
   1201, 1299 end 1–2 s before a 10-28 ruled end that also drops "Let's stand"; 1255 against an old
   full-pass keep (drops "Amen."); 968, 1324, 1375 accepted losses; 1183, 1275 refinement. **Data
   defect:** canary-15 pair rulings carry no section id, so later rulings on the same song start never
   supersede them (1221 §2718, 1273 §3421, 1304 §3875 read as regressions though a newer ruling is met).
   **Triaged 10-30 (operator: "only ask me to listen to things you actually need my judgment on").** Each
   contradicted or missed edge was read from cues, decoded words and levels (`page1-triage-20261030/around.php`).
   *Settled, recorded* as `claude-review-20261030` (20 rulings, all met by the round): 13 sermon ends that stop after
   the preacher's last word or Amen and drop only a song introduction or the congregation's Amen, heard before the
   10-28 Amen ruling, recorded "either" so the cut heard stays acceptable (961, 1007 [closing prayer, still an end],
   1038, 1076, 1190, 1194, 1201, 1218, 1255, 1296, 1299, 1303, 1326); four 10-18 "cut off mid sentence" ends the round
   ends on the Amen before the announcement, recorded keep (1115, 1127, 1167, 1283 — 1283's Amen decodes at 3382.21,
   before the cut); 1073 §1716 start either 2700/2710 (the played-through introduction sounds from 2699.5; D6);
   1120 §2012 start either (same quiet); 1295 §3769 end either 350.695/352.0 (ends before the decoded "Let's join").
   *Needs the ear, 3:* page "Sermon Ends To Hear" https://claude.ai/artifact/KDXKyWuaYd1XKfY2ph4uWN (`rulings`,
   ids `<run>-sermon-end`, A/B; builder `ends-to-hear-20261030/`): 1004 (prayer's last phrase, timings disagree),
   1005 (garbled stretch, may be music), 1070 (does B keep the preacher's whole Amen). The 10-29 draft page
   (`shortened-ends-listening-20261029/`) is superseded: 1115, 1127, 1219 settled, 1157 covered.
   *Defects, the round's cut is wrong (code, no listening):* **1219** sermon end 3836.9 sits inside "Jesus said, I
   am the way and the truth" (decoded 3835.5–3838.2): the cue times there are loop filler, so K16's line end is
   ~20 s early (the loop-filler class: filler cue times overrun the speech they replace); **928** start +1.32 s drops "now" (`word_pause`); **982** start
   opens on the congregation's "amen" 5 s before the reading; **990** start `sentence_check` drops "But let's read
   from verse 1… Here is a trustworthy saying:"; **1130 §2066** `song_start_sentence` drops the first line "Glory be
   to God the Father"; **1342 §4322** `song_start_sentence` trusts words decoded over the song's opening (the reading
   ended at 1556; ruled 1567.44, round 1569.55); **1272 §3403/§3404** the known early `song_end_fade` at the dip.
   Sub-second regressions (961 §828, 1042, 1094, 1281, 1379, 1292 §3653) stay listed above. Still to triage: 71
   unruled non-K16 and 39 unruled K16 moves.
   **Sermon Ends To Hear answered 10-30** (source `ends-to-hear-20261030`): 1004 either, 1005 B, **1070 A — "B clips
   the preachers amen"**. Cause: `CueSafeExtractionPlan::beforeWordHeardElsewhere()` (1108's rule, 10-18) ends a
   spoken part where another decode heard the next word begin, and another decode's copy of the *last kept* word
   (1070's "Amen." at 1629.07 against 1628.78) passes as the next word. Class scan
   (`page1-triage-20261030/duplicate-onset-scan.php`, `duplicate-onset-scan.json`): of 2,353 planned segment ends, 15
   sit on the onset of a word decoded again < 0.6 s after an identical one — a clip where the repeated word is the
   last kept one (1070; to check: 1001 §312.2 "Amen.", 1133 "Amen.", 1033 "pray", 1083 "Why"), a sliver of a dropped
   word otherwise (1371 "Let's"). **Fixed 10-30 (operator: "Build the fix now").** Another decode's word is the
   last kept word heard again, not the next word, when it has the same text, starts within 0.1 s of the kept word's
   decoded end, and no ≥ 0.1 s below the levels threshold separates them (1070's Amen: no quiet; the congregation's
   Amen after the preacher's: 0.38 s in 1202, 0.72 s in 1133, both ruled right ending before it). Text and gap alone
   were tried first and failed: a 0.6 s reach moved 1133 into the congregation's Amen (replay), a 0.1 s gap moved
   1202 (ruled-endings suite). Replay of the 100 runs the rule can reach (`page1-triage-20261030/replay-100.log`,
   `no-stand-in/pilot.jsonl`): 9 of 573 outputs change — 1070 1629.07 → 1629.54 (into the silence after the
   Amen), and 8 ends 0.02–0.23 s later through the rest of the same word (1233 §2878, 1239, 1243 §3026, 1287 §3600,
   1305 §3889, 1310 §3936, 1338 §4763, 1343); none earlier. Tests: 1070, and 1133 as the guard. 7 fixture files
   recaptured and refreshed (`same-word-fixture-refresh-20261030/`, prior files in `before/`; 1016's sermon join
   +0.29 s too). Full suite 10,914 with only those 7 stale live cuts before the refresh; ruled endings 650/650
   after; Dusk 63/63. The 3 known 1288 reds no longer fail.
   **1219 traced 10-30.** 1219's transcript (`local_whisper`) merges a whole-second
   transcription with finer ones: 451 of 1,010 cues start on .9, and near the end they run ~11 s early ("Jesus
   said," cued 3824.9, decoded 3835.70; the same passage repeats with true times from 3843.62). K16 chose the line
   "and in no one else." and the cut went to its cue end, 3836.9, which the word pause then kept because a real
   pause falls there — between "the" and "way" of "I am the way". The kept cue's text is never checked against the
   decoded words at the cut. Scan (`page1-triage-20261030/line-word-scan.php`, `.json`): of 408 planned sermon ends
   with decoded words at the cut, 14 lack the kept cue's last word there; 2 on whole-second cues (1219, and 1005,
   ruled B right 10-30); most of the rest are decoder spellings (961 "discouragement(s)", 1108 "We delight" / "Be
   the light"); to read: 944, 1024, 1071 ("Thank you." / "Let's pray together"), 1136 (decoded "Bye" ×5), 1355,
   980, 1103, 1077. **Proposed fix:** test-first from 1219, a K16 end whose kept line's last words are not among the
   decoded words before the cut finds that line in the decoded words of a wider window and ends after it, or holds
   the end for review when it cannot; then replay the 14 and run the ruled-endings suite.
   **1219 fix BUILT 10-30 (operator: "Go — build it").** Measured first (`storage/scratch/line-reach-1219-20261030/`):
   128 runs keep a line whose text recurs before the song (nearly all a congregation's "Amen."), 14 lack the kept
   line's last word at the cut, and only 1219 is both; so the rule asks the copies only when the kept line's last word
   is not heard at its cue, and leaves an unheard line with no copy standing (the other 13 are decoder spellings, not
   moved or held). `CueSafeExtractionPlan::whereKeptLineWasHeard()`: the first later copy before the song whose decode
   heard that word is the line; copies none of which heard it leave the end to the operator
   (`spoken_end_thought_unresolved`). `spokenEndsBesideSongs()` returns the copies' ends once the cut's decode exists,
   and `PrepareOutputEdgeEvidence` decodes them in its follow-up pass. A held K16 end on a section end in silence now
   records its audit entry (it recorded none, so it was never held; reachable runs 1198, 980 unchanged). 1219's true
   copy decoded on the live run (3851.52–3855.94: "and in no one else." to 3854.96). Replay of the 142 runs the rule
   or the audit change can reach (`replay.log`, `no-stand-in/pilot.jsonl`; the 1070 pilot moved to
   `page1-triage-20261030/pilot-1070-replay.jsonl`): 1 of 750 outputs changes, 1219's end 3836.9 → 3855.26, after
   "else" and before "Let's stand" (3855.48). Tests: 1219's shape, the hold, the heard-line guard. Full suite
   10,917, Dusk 63/63.
   **Unruled moves triaged 10-30** (the 71 non-K16 and 39 K16 rows of `moves-ge1s.json`; scripts and outputs in
   `storage/scratch/unruled-triage-20261030/`: `stretch.php` the dropped or added cue text and decoded words,
   `before-cut.php` what an end keeps, `classes.php` the classifier's shares of each moved stretch, `levels.php`).
   *Settled, recorded* as `claude-review-20261030` (78 rulings, `gather.py`): 34 K16 ends keep the round's end, which
   stops after the preacher's last word or Amen and drops only a song introduction, quoted lyrics or the congregation's
   Amen (each Amen case checked: the preacher's Amen is decoded before the cut; 1381 by levels, its Amen's sound ending
   1689.15); 44 other moves are either, the executed cut and the round's both acceptable: song starts that drop only a
   spoken introduction or title (a musical introduction is always in; speech over it is spoken), song starts and ends
   that add only music or quiet, 932's song end before the prayer spoken over the music, and six sermon or talk edges
   that add a Bible announcement or drop a handover. Five sermon-join moves (983, 1009, 1250, 1266, 1322) read as
   either but are not recorded: the register has no shape for a join edge. 1005 was answered on Sermon Ends To Hear;
   **1314** is the round's own hold (`spoken_end_thought_unresolved`).
   *Needs the ear, 1:* **1284** sermon end 3538.72 sits in a 0.05 s dip between the preacher's "Amen." (sound
   3538.1–3538.65) and a louder Amen from 3538.75, decoded 3537.42 and 3538.69 by two windows; the congregation's or
   the preacher's cannot be told from words or levels.
   *Defects in the round's cut (code, no listening), by class:* **sung opening dropped** (`song_start_sentence`
   and `word_pause` take sung words for a spoken introduction; the 1130/1342 class): 949 §146759 "Glory be to God the
   Father", 981 §1054 "…our God reigns", 1239 §2980 "Restore… the honour of his name", 1257 §3204 "I will give thanks
   to you", 1264 §3317 "He who would valiant be", 1295 §3771 "You who reign in majesty"; and 1–2 s of introduction
   music dropped, 1106 §1878, 1245 §3051, 1260 §3252. **Song end takes in the speech after it** (`song_end_silence`
   / `song_end_fade` run on to a later quiet past words spoken over the last music; the open speech-over-fading-song
   class of 928/1250): 1009 §1277, 1034 §173997, 1080 §174347, 1234 §2888, 1243 §3024 ("Let's join together in
   prayer. Let's pray."), 1254 §3175, 1315 §4009, 1334 §4237. **Opens on the congregation's Amen** (982's class): 991
   §1168, 1321 sermon start. **1297** sermon start 1837.16 falls between "this" and "morning" ("Let's have our Bibles
   open, shall we, this morning at Matthew…"). **1193** sermon end 889.00 sits inside "…as we seek to do that"
   (decoded "do" 889.04, "that" 889.14): its whole-second cue ends before its words. *Soft misses:* 1300 §4639 talk end
   takes in "Let's stand and sing."; 1240 §2985 talk start opens on the self-correction "sorry, after dinner…"
   (the executed start had 20 s of quiet before "So each evening"); 1258 sermon end keeps "Well, David." of the
   announcement.
   **1284 asked 10-30:** page "Whose Amen" https://claude.ai/artifact/VjLNfVRfWtSmfkEGjpK6ES (`rulings`, id
   `1284-sermon-end`; A 3541.075 now, B 3538.72 round; builder `amen-1284-listening-20261030/`). Congregation's → B
   recorded keep; preacher's → A, and the cause is read before any rule.
   **Answered 10-30: either — "B is right, but A is acceptable - it's the preacher repeating their \"Amen\"".**
   Recorded (source `amen-1284-listening-20261030`, points 3538.72/3541.075); the round's B meets it, and on a sermon
   end the shorter wins, so nothing changes. The unruled-moves triage needs no further listening.
   **Defect-fix plan 10-30 (operator: "plan to fix all the defects"; plan only, each part waits for its go).** Every
   defect from the two triages, grouped by the rule that placed the cut (`reason` in `moves-ge1s.json`). Each part
   follows the same steps: (1) *measure* read-only on `no-stand-in/` whether one condition separates the defects
   from that rule's ruled-right moves, and write a failing test from one defect's fixture; (2) *build* the narrowest
   rule that separates them; (3) *replay* the runs the rule can reach with the guarded `measure.php --no-ocr-stand-in`
   (never in the staging context), score every move against the register, settle unruled moves from words, levels
   and rulings, and page only what needs the ear; (4) ruled-endings suite, full suite and Dusk, then commit. A part
   whose measurement finds no separating condition stops there and comes back as a finding, not a rule.
   *F1. Sung openings dropped, 11 song starts, all `song_start_sentence`:* 949 §146759, 981 §1054, 1239 §2980, 1257
   §3204, 1264 §3317, 1295 §3771 (first sung line), 1106 §1878, 1245 §3051, 1260 §3252 (1–2 s of introduction
   music), 1130 §2066 and 1342 §4322 (from the first triage). Suspected path: `afterSongOpeningSentence()` moves the
   start past a "sentence" it reads in sung words, and its move is not passed through `keptMusicalOpening()`, which
   guards only `placedEdge()`'s pause (D6: a musical opening is always in). Measure: for the 86 `song_start_sentence`
   moves of ≥ 1 s (58 meet a ruling), the classifier's music share and `isSung()` over the dropped words; the 58 are
   the guard set. Candidate rule: the sentence move stands only across speech. 1342's decode over the opening
   is the same question asked of the wide window (`wideSentence`). Largest class, so first.
   *F2. Song end takes in the speech after it, 8 + 1:* `song_end_silence` 1034 §173997, 1080 §174347, 1243 §3024,
   1254 §3175, 1315 §4009, 1334 §4237; `song_end_speech` 1009 §1277; `song_end_fade` 1234 §2888; 928/1250 are the
   open class (1250 met 10-29). Suspected path: `speechAfterSong()` skips a speech cue spoken over the last music
   (inside the section a cue counts only where its window's speech share passes `SPEECH_CUTOFF`; past the section
   `cueIsOnlyMusic()` can take it for lyrics), so `songEnd()` runs on to a later quiet. Measure: per defect, which cue
   holds the speech and which test let it through; then the same tests at the 63 ruled `song_end_fade` ends and the 31
   `song_end_silence` moves of ≥ 1 s (19 meet a ruling). Candidate rule: a cue whose decoded words are heard as speech (`heardAsSpeech`/`speechHeardBy`)
   bounds the end even over music. The 10-29 measurement (`speech-over-fade-20261029/`) is the starting point.
   *F3. 1272 §3403/§3404 early `song_end_fade` at a dip:* the known miss; the 10-30 class count found no single bias
   (54 of 63 met; early and late misses alike). Measure only after F2, since F2 changes the same end; if 1272 is
   still alone, record it as a known miss rather than tune the fade.
   *F4. Opens on the congregation's Amen, 3 starts (`word_pause` moved earlier):* 982 sermon (−5.29), 991 §1168 talk,
   1321 sermon. The start-side twin of the 10-28 Amen step-back. Measure: starts whose kept stretch before the first
   word of the section's own speech is only "Amen" (decoded words); candidate rule: a start does not move earlier
   onto a lone Amen that answers the previous item. Check against the 10-28 Amen ruling's end fixtures so the end
   side is untouched.
   *F5. Spoken starts placed inside a sentence, 3 starts:* 1297 sermon start between "this" and "morning"
   (`word_pause`, +4.24); 928 sermon start drops "now" (`word_pause`, +1.32, against its 10-29 keep); 990 sermon
   start drops "But let's read from verse 1… Here is a trustworthy saying:" (`sentence_check`, +6). Measure: what
   `wordPause()` and `sentenceChecked()` saw at each (pause lengths, the words either side, the sentence answer);
   these may be three causes, so each gets its own test, and a shared rule only if the measurement shows one.
   *F6. 1193 sermon end inside "…do that" (whole-second cue, `word_pause`, −2.01):* the loop-filler / drifted-cue
   class 1219 belonged to. Measure: whether the end's decode heard "do that" after the cut and why
   `beforeNextWordSound()` and the 1219 heard-word rule missed it; the fix belongs with the cue-drift class.
   *F7. Register data defect:* canary-15 pair rulings carry no section id, so newer rulings on the same song start
   never supersede them (1221 §2718, 1273 §3421, 1304 §3875). Fix in `gather.py` (bind the pair to its section by
   run and time) and regenerate; no pipeline change.
   **F7 DONE 10-30 (operator: "Start the defect fix plan").** The canary-15 pairs were already bound (`pair_sections`);
   the defect was every id-less section ruling (117, from canary 11/12/14, widening, word-edge, full-pass): all of a
   run's id-less rulings on one edge shared one key, so the newest replaced the rest whatever their section (1304
   §3879 by a §4884 ruling), and none was replaced by a later ruling naming its section (1221 §2718). `gather.py` now
   binds each to the section of its type whose edge is nearest the cut heard (`bound_section_id`), as the harness
   and `analyze.py` already did (sections: `defect-fix-f7-20261030/export-sections.php`). 43 flips, nothing else:
   26 live again, 17 replaced; far binds checked (widening ids carry the pre-widening edge). Round scoring re-run
   (`defect-fix-f7-20261030/{before,after}/`): regressions 14 → 13 (1221 cleared), no new one; revived 19 met, 4
   unassessable, 3 missed by both cuts (1108 §1897 start, 1346 §4366 end, 1346 §4368 start: old canary rulings no
   cut since has met; neither cut moves there, so not the round's). Ruled-endings 650 OK.
   **F1 MEASURED 10-30: no separating condition; the 11 go to the ear** (`defect-fix-f1-20261030/`). Over the 86
   `song_start_sentence` moves ≥ 1 s (58 meet a ruling): the classifier hears speech over nearly all of them (a band
   under speech still reads as speech); the music class separates 1106, 1245, 1260 (share ≥ 0.93, all others ≤ 0.38),
   but `the_classifier_cannot_veto_a_song_opening_sentence_trim` keeps the trim of an announcement classified as music,
   and 1260 drops "and sing.", that very case. A lyric line is dropped as often in ruled-right moves (1200's "I will give
   thanks to you" is 1257's text; 1198, 1228, 939, 1292) as in the eleven. Lyric order on the transcript cues after the
   cut (`lyrics.php`; the edge decodes stop at the cut): "repeated after" marks a read-out line (1228, 1273, 1295
   §3773, 1306, 1357, all covered), but "continues" fires on 1130 only and also on covered 1223 and 1305, and six
   defects show no lyric after the cut. So the triage's "sung opening dropped" calls, made from cue text, are unproven.
   Page **"Sung Or Spoken"** https://claude.ai/artifact/RZ7kwPJXdZpNiz5MBhKTgs (`rulings`, ids `<run>-s<section>-start`,
   A executed / B round; builder `song-openings-listening-20261030/`). On answers: a B-wrong set decides whether a
   rule is worth building (candidate: the next cue is the next lyric line, nothing spoken between).
   **F2/F3 MEASURED 10-30: the 9 go to the ear** (`defect-fix-f2-20261030/`). Of the 39 song-end moves ≥ 1 s, the
   covered ones add no decoded words (music only; a speech cue may start inside, its words later), the nine add
   decoded words of a speech cue, which the classifier hears as music (`speechAfterSong()` skips the cue). But 1216
   §2655 adds "let's pray again" over the music and was ruled keep at the longer end ("B cuts off the music"), and
   1295 §3769 the other way was either, so speech over the last music is not by itself wrong. Page **"Speech Over The
   Last Chords"** https://claude.ai/artifact/3hcYFYWpoVTz1DFCkv6LTs (`rulings`, ids `<run>-s<section>-end`, A
   executed / B round; 1272's B is the earlier; builder `song-ends-listening-20261030/`). On answers: a rule only if
   the B-wrong ends separate (candidate: decoded words of a non-lyric cue before the end, with music fading).
   **F5 read 10-30** (`defect-fix-f5-20261030/around.php`), three mechanisms: 1297's section boundary falls inside
   "…shall we, this morning", and the pause rule took the 0.4 s pause after "this" (the executed start, "Let's",
   opens the sentence); 928's "now" is a 1.18 s smear over the song's last music (the 10-29 recorded one-word miss);
   990's banked sentence-check answer asked to drop "But let's read from verse 1… a trustworthy saying" after a
   decode smeared "and 16" across the cut, and `sentenceChecked()` applied it as asked. Candidate guards, each to be
   measured on every move of its kind before building: a start-side pause may not sit inside a cue that runs on
   across it (1297); a start-side sentence-check trim may not cross a cue that opens a new sentence (990).
   **F4 BUILT 10-30** (`defect-fix-f4-20261030/`). Of the five spoken starts moved earlier by ≥ 1 s, the three
   defects gain only an "Amen" (1011, 1104 gain a Bible announcement). Words: 982's start sat at the head of a 5.3 s
   smear of the prayer's "amen" (the reading's "now" at 988.68, the executed start heard right), 991's on "Amen."'s
   onset, 1321's in the pause before "Amen.". `CueSafeExtractionPlan::afterClosingAmen()`: a spoken start whose first
   kept word is a lone Amen opens where it stops; song starts are left alone. First built for every start, the replay
   broke 1282 §3533 (a song start heard right at 1902.16 with "Amen." from 1902.55: the introduction plays in under
   it), so it was narrowed to spoken starts. Reach (`reach.php`: any planned segment whose first decoded word is
   "Amen"): 20 runs; replay on the narrowed rule (`replay2.log`, `diff2.log`): 9 of 109 outputs change: 7 spoken
   starts move past a closing Amen (982 → 988.68, 991 → 567.37, 1155, 1239, 1251's second part, 1255 §3180, 1313
   §3976, 1321 → 809.89; each checked in `check*.php`: the Amen ends the prayer before), and two ends shift by the
   1070 fix `1773110fc`, which postdates the round's plans (00:11 vs 03:34): 1239's part end +0.02, **1305 §3889 talk
   end 943.42 → 943.51, 0.06 s inside "Let's"** (a second decode's "matters." ends 943.45; sub-second, listed with the
   others). Scored (`scored2/`): 982's regression clears (13 → 12), nothing else changes status. 1285 §3573's 20 s
   "Amen." smear is a song start, so untouched. Tests: three spoken shapes and the 1282 song guard.
   The full suite then caught an overlap: a start may step past the Amen only when the next word begins after it stops
   (`output_edges_use_the_largest_adjacent_pause`, "amen" sounding under "the"); guard added, replay unchanged
   (`diff3.log`, 0 outputs differ). Five ruled-endings fixtures recaptured at the new cuts
   (`amen-start-fixture-refresh-20261030/`, prior files in `before/`: 982, 1251, 1255, 1313, 1321). 1255 §3180's
   full-pass "right" start (559.98) keeps the congregation's answering "Amen.", heard before the 10-28 Amen ruling, so
   recorded either 559.98/560.34 (`claude-review-20261030`), as the triage's thirteen sermon ends.
   **Sung Or Spoken and Speech Over The Last Chords ANSWERED 10-30** (sources `song-openings-listening-20261030`,
   `song-ends-listening-20261030`; 14 rulings, approximate neither times kept in notes). *F1 closed, no rule:* the
   round's later start is right or acceptable for 9 of 11 (B 1239, 1295, 1342, 981; either 949, 1106, 1257, 1260,
   1264), so the triage's "sung opening dropped" was wrong; 1130 neither ("A clips the first word of the song being
   quoted. B drops the first line": right just before "Glory", ~459.9; its full-pass keep at A stays live and is
   now doubtful) and 1245 neither ("Both include previous music": later than B) are single misses. *F2/F3 confirmed,
   not buildable on today's evidence:* the round's longer end is right or acceptable for 3 of 9 (B 1254, 1334; either
   1315), A for 1009, 1080, and neither, between the two, for 1034 (≈58:28), 1234 (≈11:15), 1243 (≈5:31) and 1272
   (≈14:43). Measured (`onset.php`): the decoded onset of the speech cue is not the end either: ruled-right ends run
   2–3 s past it (1347 696.04 → kept 699.36, 1111, 1216, 1012), and the neither times sit 0.3–3.8 s after it. The end
   heard is where the music stops under the speech; `song_end_silence` runs on to the later quiet because the speech
   keeps the level up, and the classifier calls the whole stretch music (1243 M-class 1 to B). **Next for F2 is
   evidence, not a rule:** a finer music-presence signal at song ends (where the last chord dies under speech), measured
   on these 9 and the 30 covered song-end moves before any rule; plan only, awaits go.
   **F2 evidence plan 10-30 (operator: "Do both"; plan only, measurement awaits go).** *Why:* the classifier
   (`scripts/classify_audio.py`, AST `MIT/ast-finetuned-audioset-10-10-0.4593`) scores fixed, non-overlapping 5 s
   windows; at 1243 music falls 0.75 (325–330) → 0.42 (330–335) → 0.01, so the music's end (heard ≈331) is inside one
   window but not placed within it. *Step 1, measure (read-only, local GPU server, no paid calls):* re-score with the
   same model and revision, in 2 s windows on a 0.25 s hop, the stretch [earlier cut − 8 s, later cut + 4 s] of the 39
   song-end moves ≥ 1 s (`moves-ge1s.json`) and 1216; write `storage/scratch/song-end-music-20261030/`; take as the
   music's end the first time the music score stays below the run's own cut-off (`MUSIC_CUTOFF` 0.4) for ≥ 1 s, and
   compare it with the rulings (keep/either points within `RIGHT` 0.31 s) and the four neither times (±1 s). *Pass:*
   every ruled-right end at or after it and before the next item's first sung or spoken line, and the six
   round-wrong ends brought within their ruling; also a sample of 20 unmoved ruled song ends, so the signal does not
   move what is right. *Step 2, only if step 1 passes:* the narrowest rule (candidate: `song_end_silence` and
   `song_end_fade` do not run past the measured music end when a speech cue starts before the quiet they reach), the
   fine scores banked as an edge artifact beside the edge words so the planner stays offline; test-first from 1243;
   replay; suites. *Stop:* if the fine scores do not place the end within ~1 s of the heard times, F2 stays an
   accepted miss class and comes back as a finding.
   **F5 MEASURED 10-30: no guard built** (`defect-fix-f5-20261030/`). A decoded-word "mid-sentence" test on every
   spoken output's outer start and end in the round (`scan.php`: no closing punctuation before the cut and a
   lower-case word after it, or a word straddling an end) finds 146 edges, of which 56 carry right rulings and 5
   wrong (1311 "a ‖ great", 1327 "I've ‖ asked", 1009, 1017 all ruled keep): word punctuation and timing cannot judge a
   sentence at the cut, so neither candidate guard is safe. The audits (`audit.php`, rolled back) settle two of the
   three: **1297**'s start has its sentence check set up but never asked (`asked: false`; the replay is offline),
   and the round's own `PrepareOutputEdgeEvidence` asks it before cutting, so it is not a code defect today; **928**
   stays the recorded one-word miss; **990** is the banked answer itself (asked to drop "But let's read…"), left as
   a recorded miss unless the sentence check's prompt is revisited.
   **F6 BUILT 10-30** (`defect-fix-f5-20261030/`). 1193's audit: K16 keeps "May God help us tonight, as we seek to do
   that." (cue 887.00–889.00), the pause rule chose the right pause (889.35–889.52, after "that", before "let's"), and
   `beforeNextLine()` cut back to the next cue's start, 889.00: the kept word "that" (decoded 889.14) counts as kept
   only if it starts before the next cue, and whole-second cues start that cue at 889.00. Fix: a kept word the next
   line does not say, decoded within `KEPT_WORD_CUE_DRIFT` (0.5 s) of the next cue's start, is the closing line's.
   Reach (`reach-f6.php`): 23 runs. Replays against a baseline on the same code without the change
   (`pilot-f6-baseline.jsonl`, so the 1070/1219 fixes do not show as moves): unbounded, 4 of 104 outputs change, one
   wrong (1273 took in the answering "Amen." decoded 1.34 s into "Let's sing", against its 10-28 keep at 4029.06);
   bounded, 3 change, all ends that finished their last word: **1193 889.00 → 889.43**, 1031 462.72 → 463.90 (after
   "joy.", which 462.72 cut into), 1375 1318.00 → 1318.86 (after "life."). Register: 1375's review end superseded
   (keep 1318.86), 1031 gains 463.90 beside the operator's either points. Tests: 1193 and the 1273 guard.
   **Defect-fix plan status 10-30:** F7, F4, F6 done; F1 closed (no defect); F2/F3 confirmed, evidence plan awaits go (later: measured, then REOPENED with fades, below);
   F5 no guard (1297 is asked by the round's sentence check; 928, 990 recorded misses).
   **F2 evidence MEASURED 10-30 (operator: "Go on F2 evidence"): stop condition met, no rule**
   (`song-end-music-20261030/`: `export.php`, `score.py` via the local classifier server, same model and revision,
   2 s windows on a 0.25 s hop; `analyze.py`, `report.json`). 59 stretches: 39 song-end moves ≥ 1 s, 1216, and 19
   unmoved ruled-keep song ends as controls. For every music-end definition tried (cut-off 0.5/0.4/0.3/0.2 × hold
   0/0.5/1/2 s), capping the round's end at the music's end loses more ruled ends than it gains (best: 24 met against
   the round's 31, gaining 1, losing 8) and cuts 7–14 of the 19 controls short. No offset rescues it: ruled-right ends
   sit −2.0 to +7.4 s from the measured music end (median +0.8), the six round-wrong targets −4.0 to +1.3, inside the
   same range, and mostly before the music ends (where speech starts while music is still heard), while 1216, 1347
   and 1111 were kept well past the speech's start. What the ear decides is whether the speech belongs to the next item,
   which sound classes do not carry. **F2/F3 recorded as an accepted miss class** (6 of 9 heard ends: 1009, 1080 at the
   executed cut; 1034, 1234, 1243, 1272 between, approximate times in their notes). Option, not started: a review hold
   on song ends whose added stretch holds decoded words of a non-lyric cue (all six, and the covered 1216, 1111, 1347,
   1012), so weekly runs ask instead of cutting.
   **F2/F3 REOPENED 10-30: the accepted-miss record above is withdrawn** (operator: "Speech always belongs to the next
   item. The only questions are: can we accurately detect when the music stops and the speech starts, and what do we
   do with speech overlapping music … isn't this why we introduced fades?"; register E24). The measurement above asked
   whether speech "belongs" and scored music-end estimates against rulings heard as hard cuts: the Speech Over The Last
   Chords page clipped every version with a 0.03 s anti-click fade only, never E7's 1.5–4 s fade, so a "keep the
   longer end" answer (1216 "B cuts off the music", 1254, 1334, 1347, 1111, 1012) judged an abrupt outro, not where the
   speech belongs. And the pipeline gives those ends no fade either: `songEnd()`'s `song_end_silence` return carries
   no `fade_out` (only `song_end_speech` and `song_end_fade` do), so a song end that runs past speech to the later
   quiet is also cut hard. Trace (`storage/scratch/song-end-fade-20261030/diag.php`, today's code reproduces the
   round on 12 of 13; 1272 now plans 891.88, not the round's 879.99): in 10 of the 13 the speech cue is found, and
   `$silenceUntil`'s extension past the cue's start (954, 1216, 1366, all heard as hard cuts) lets the quiet after
   the speech win; first decoded words under the music smear over 2–4 s (1347 "Let's" 696.04–699.87, 1216 "Well,"
   1350.04–1352.31, 1334 "Let's" from 1205.48, inside the song), so a decoded onset is not yet the speech's start.
   *Next:* re-ask the 9 and the four speech-keeping rulings (1216, 1111, 1347, 1012) with A = the song ending at its
   speech cue's start with the E7 fade, B = the round's end as the pipeline would render it (hard for
   `song_end_silence`). Page **"Song Ends With The Fade"** https://claude.ai/artifact/CpwEnD2Ezt35hACn5ttkKP
   (`rulings`, ids `<run>-s<section>-end`; builder `song-end-fade-20261030/build.php`, A's speech-cue starts picked by
   hand; A's fade is E7's minimum 1.5 s on 12 of 13, since the lyrics run up to the speech). *First answers, 10-30:*
   1012 either, 1111 either, 1216 B; operator: none of the three has speech in it. So the four "speech-keeping"
   rulings above kept no speech: their longer ends stop before the speech, and the claim that they judged a hard cut
   over speech is withdrawn for them. A (the speech cue's start) was 1.9–6.5 s early on all three: under music the
   transcript line opens before it is said (1216's "Well, let's pray" opens 6.5 s early, recorded in `songEnd()` since
   10-26; 1012's "I've asked Laurie" decodes as a 2.5 s smear, speech heard from ≈1000). A cue's start is not the
   speech's start (Q1), so the rule cannot be "end at the speech cue"; the remaining nine are read with that in mind.
   *Page cut to six and ANSWERED 10-30* (source `song-end-fade-20261030`, answers in `answers/rulings/`; 1034, 1254,
   1334, 1347 left out, their cue opening early as above): **A with the fade right for 1009, 1080, 1234, 1243, 1272;
   1315 either.** 1234, 1243 and 1272 were "neither" heard as hard cuts; with E7's 1.5 s fade (4 s on 1272) the
   speech-cue start is right, so the fade answers Q2. *Q1 signal, from the 13:* the classifier's speech score in the 5 s
   window holding the cue's start is 0.25–0.49 on all five A-right ends (speech mixed into the music, under
   `SPEECH_CUTOFF` 0.5, which is why `speechAfterSong()`/`cueIsOnlyMusic()` passed over them) and 0.00–0.08 on the
   three heard early (1012, 1111, 1216) and the four left out (1034, 1254, 1334; speech only in the next window).
   1347 (0.45) is the one untested reading; 1315 (0.02) took either. *Candidate rule, not built:* a song end that
   reaches a speech cue whose start window holds speech mixed with music (≥ ~0.2) ends at that cue's start with E7's
   fade, and the silence search does not run past it; a cue opening in music alone keeps today's path. Measure first
   on every song end in the round (how many move, against the ruled song ends) before a test from 1243.
   *Measured 10-30 (operator: "Go ahead"; `song-end-fade-20261030/measure.php`, `analyze.py`, `report.json`; the
   page's nine answers gathered into the register, superseding the hard-cut rulings on the same ends):* 1,194 song
   ends in the round, 206 ruled. 174 run past their first worded cue; at 148 of those the cue opens in a window with
   speech < 0.1 (the 1216 kind), which the rule leaves alone. The threshold barely matters: 26 moves at 0.1, 21 at
   0.2–0.25, 19 at 0.3. At 0.2: 21 move, all earlier, 9 ruled; 5 gained (the page's own five, so circular), 3 lost,
   all against hard-cut rulings from before E24 — 1272 §3400 lands at 254.08, between its either points 253.145 and
   259.775; 1269 §3362 at 605.02, 1.9 s before its earlier either point 606.92; 1347 at 696.88 against its 10-28 keep
   699.36, never heard faded. 13 unruled: four large (1146 −5.9, 1248 −6.4, 1215 −2.5, 929 −2.0), 1313 −1.4, and
   eight under 1.1 s, all but one where the cue's window is already speech ≥ 0.5 (fragments such as "together.",
   "'s pray" at 992, 1363 open mid-sentence). Not built: the losses and the large unruled moves need the ear first. Page
   **"Fade Rule Check"** https://claude.ai/artifact/1eKfJQGGJUeG6pQ2fj1iWi (`rulings`, ids `<run>-s<section>-end`, A the
   rule's cut with its fade, B today's end, hard; builder `song-end-fade-check-20261030/`): 1269, 1272 §3400, 1347,
   929, 1146, 1215, 1248, 1313. On answers: drop speech ≥ 0.5 cues from the rule, test-first from 1243, build, replay.
   *Fade Rule Check ANSWERED 10-30:* A for 1269, 1272 §3400, 929, 1146, 1215, 1248; either 1347, 1313; no B, no
   neither. 929 (0.59) and 1215 (0.51) open in speech ≥ 0.5 and are right at A, so the rule keeps them. Gathered
   (`gather.py`; `export-sections.php` now takes extra runs, for runs whose first section ruling is new): at 0.2,
   21 moves, 14 ruled, **11 gained, 0 lost** (met 180 → 191 of 211). **BUILT 10-30 (uncommitted)** (operator: "Go
   ahead"): `CueSafeExtractionPlan::songEnd()` now wraps the old rule (`songEndBySoundOrSpeech()`): where the first
   worded cue from the song's end opens in a window with speech ≥ `SPEECH_OVER_MUSIC` 0.2 and the end runs past it,
   the song ends at that cue's start with E7's fade, reason `song_end_speech_over_music` (also accepted by
   `afterSongSound()`, so the spoken part after starts with its speech: 1271 §3390 → 459.64). Replay of the 20 runs
   (`song-end-fade-20261030/replay-*.log`, baseline = HEAD without the rule, `diff.py`): first 1272 §3404 lost its
   start dip (885.5, ruled keep 10-21) and fell to `song_start_sentence` 895.93, taking "Great is thy faithfulness";
   `atSongChangeDip()` now lets a next song start at the dip when the song before ends at speech over its music
   before it (977 §1005's `song_end_speech` guard unchanged). Final diff: 43 of 268 outputs, the 21 song ends in
   both plan sets and 1271 §3390; nothing else. Tests: 1243 shape (`SongEndEdgesTest`), 1272 dip start
   (`RuledEndingsRegressionTest`, moved out of the shared-dip set), 946's held-word expectation → its earlier either
   point 378.52. Full suite 10,925: only 7 stale fixture live cuts (the intended moves); refreshed
   (`song-end-fade-fixture-refresh-20261030/`, prior files in `before/`); ruled endings 650/650; Dusk 63/63; Pint,
   PHPStan clean. Still open: the eight sub-second / unruled moves (1104, 1284, 1271, 992, 1259, 971, 1363 and 946's
   either) were not heard; fade lengths are the E7 minimum on all but 1313 (3.0 s). Where a cue opening in music
   alone is wrong even faded, the cue's start is not the speech's start and Q1 (detection) stays the work.
   **Committed `43c7028e7`.** *The eight unheard moves triaged 10-31, none needs the ear*
   (`storage/scratch/sub-second-fade-triage-20261031/`, `diag.php` → `diag.json`, read-only): 946 lands on its
   earlier either point; 971, 1259, 1363 have music to the window boundary and the speech from the cue's start (the
   round's hard cut took the head of "Well", "Indeed,", "Let's"), better by E24; 1271 both cuts follow "please sit
   down." (459.31) with ≈ 10 s quiet after; 1284 no lyric lost (lyrics end 379, whole-second cue 383, no decoded
   words); 1104 both cuts inside a smeared "Amen.", the 1.5 s fade over the held "own", the shape the Fade Rule
   Check heard right. **992 §163678 is a defect:** the recording ends (1581.5) before "Restore, O Lord" is sung; the
   section is the leader reading its words and "Let's stand and sing this one through together." with no music
   (music ≤ 0.02 throughout). Its cues are one or two words each, so the rule's "first worded cue" is "together."
   and the end moves 1580.74 → 1580.08 into the sentence. The rule checks speech in the cue's window, never music.
   *Guard, not built:* music in the cue's window or the one before (971 0.69, 1259 0.65, 1363 0.60 before; 992
   0.01; every heard end ≥ 0.27 in its own window), so 992 alone of the 21 moves reverts. *Structure defect, new:* the
   round cut a song video of 992 §163678 (1565.38–1580.74) with no singing in it, against the music-span ruling (sung
   or wholly instrumental); no ruling or plan line covered it before.
   **Both built 10-31 (operator: "Do both of those … shouldn't we measure across the whole corpus?").** *Guard:*
   `speechOverMusic()` also needs music ≥ `MUSIC_UNDER_SPEECH` 0.2 in the line's window or the one before. Guard
   fixtures captured `--current` (`storage/scratch/music-guard-fixtures-20261031/`): 992 §163678 back at 1580.74
   (`song_end_silence`), 971 §1086 605.00, 1259 §3243 4370.80, 1363 §215989 3275.11 still at the line; the 10-23
   fixtures of 992/1259/1363 take the older path from older evidence (992's to the same "together." cut, as
   `song_end_fade`), so they could not test it. Test fails without the guard (992 at 1580.08). Replay of the 20 runs against the rule's (`diff.py`, `pilot-guard.jsonl`): 2 of 268 outputs change, 992 §163678 in both plan sets, 1580.08 → 1580.74; nothing else.
   *Corpus measure* (`storage/scratch/song-sections-without-music-20261031/`, read-only, every run not superseded):
   318 runs with song sections, 1,305 sections; the 72 weekly song sections have no timeline because their 17 runs
   were processed 05-08 – 07-10 and never re-run (3 of them, 890/893/894, have one), not because weekly processing
   lacks it: the livestream chain both kinds share has run `ClassifyServiceAudio` before detection since `734205f44`
   (09-26), pinned by `ProcessingPipelineBuilderTest`, and the chain's historic branches are queue, nested-job and
   storage plumbing, not cut decisions. Of 1,233 timed (all historic),
   17 have no window with music ≥ `MUSIC_CUTOFF` (all ≤ 0.19; the next lowest has 0.40), 7 more under 25 % music
   share. The 17 are not all spoken: 1252 "Happy Birthday", 1352 and 1138 §2121 "Unidentified singing", 938 "The
   Lord's My Shepherd" are, by title, sung unaccompanied, which the classifier hears as speech or neither. So the
   music score cannot tell a reading from singing, and **dropping the song video was not built**; instead
   `SongSectionWithoutMusic` (risk `song_section_without_music`, boundary evidence `song_music`, VERSION 12,
   catalogue `song-section-without-music`) holds any timed song clip with no music window for a reviewer. Of the
   17, only 992 and 1381 §167073 reach a clip (`pending_approval`), both already held as `short_song_clip`, so no
   decision changes today; the hold adds the diagnosis and stops a longer spoken item publishing itself. Banked
   boundary evidence under VERSION 11 is stale until the backfill (operator's word) or the round re-assesses it.
   **Step 3b, weekly runs join the round by retire and re-import — PLANNED 10-31 (route revised; sermons replaced, B), awaits go**
   (operator: weekly processing "should be the same as the historic processing"; chose "join the historic rounds",
   then "retire and re-import sounds sensible" over teaching the round tools a second kind of run). The chain is
   already shared; the runs are not. *Why they were skipped:* [Findings 2026-09-04] §15: a live completed weekly run
   holds its identity shut (`checkExistence` skips it as done), `historic-import:retire-run` takes only a historic
   run, and re-processing published services was a content change; §16 recorded the operator's choice to repair in
   place. The 10-31 choice replaces §16. *Population* (live, non-historic livestream runs): 23. Completed with songs
   and never re-run since July: **884, 888, 908, 909, 910, 911, 912, 913, 914, 916, 917, 920, 921** (13); retried on
   10-15 code (X6), with superseded historic duplicates beside them: 890 (adopts 1378's sermon 869), 893 (own sermon
   1310 over 1189), 894 (adopts 1126's sermon 1051); failed: 918 (3 songs, sermon 865), 915, 919 (identities they
   hold shut), 882, 904; no songs: 883 (sermon 842), run 1. 909's sermon 857 is quarantined; 1377, its historic
   duplicate, was superseded 09-07.
   *What the record shows (read-only, 10-31):* a historic re-import of a weekly service lands on the same church
   service and **cuts a second sermon** (1126 → 1051 beside 894's; 1378 → 869 beside 890's), and
   `BetterSermonRecording` settles which one the service keeps only when one recording is better; same-quality
   copies are not settled. Retirement **refuses a published sermon** (`HistoricSermonWithdrawal::guardWithdrawable`)
   because it deletes the row, and 13 of the 17 sermons are published (843, 851, 858–867, 870). R11 already says a
   published sermon's edge may not be set.
   *Decided (operator 10-31, "B - replace them"; R11 amended):* the re-import cuts a new quarantined sermon like any
   historic item, and release **replaces** the retired run's published sermon with it, keeping its public address.
   Until release the published sermon stays live; nothing published is withdrawn early. The sermon-end rules (K16
   reword, Amen rules) reach these 13. Release today publishes only a quarantined sermon
   (`HistoricSermonPublicationService::commit`) and has no notion of replacing one; retirement deletes a sermon row
   and refuses a published one.
   *Steps, each measured first and waiting for go:* (1) read-only census per identity: manifest item present, its
   source the same recording the weekly run used (hash or size and duration), the round's dispatch resolution
   (`skip-exists` / `skip-pending-review` today); 890/893/894 checked against their X6 adoptions; and what refers to
   each published sermon (public URL shape, slug, foreign keys, song usage, analytics), which decides step 3's
   mechanism. (2) Retirement for a non-historic run: `retire-run` (or a sibling) accepts a live weekly livestream run
   with no operation, records the retirement and supersedes it; a published sermon stays in place and is marked as
   awaiting replacement by that identity; a non-published sermon is withdrawn as now; 915 and 919 the same. The
   re-import must not adopt the old sermon (`BetterSermonRecording` skips a retired holder). Tests first. (3) Release
   replaces: when a released sermon's identity carries a published sermon awaiting replacement, the swap happens in
   the same transaction — either the new cut moves into the published row (id, slug and URL unchanged) or the new
   row takes the slug and the old row's URL redirects 301 — chosen from step 1's references; the old assets go under
   `superseded/` with an inventory, as retirement does now. Tests first, including rollback leaving the old sermon
   published. (4) A rolled-back replay of one identity end to end, disks read-only, then the census again. (5) The
   identities ride in the next round as ordinary historic items: membership, custody (quarantine, no auto-publish)
   and the re-run actions need no change. This machine is not production, so a local round changes only local copies.
   *Step 1 census — DONE 10-31* (`storage/scratch/weekly-retire-census-20261031/`, read-only): 23 live non-historic
   livestream runs; every dated one has an `include` manifest item. **Different files, same services:** only 882
   and 883 used the manifest's file (hash and size equal). The others used a compressed upload (`Sunday 1st March
   2026.mp4`, 225–432 MB) where the manifest holds the master (OBS `.mkv` or YouTube backup, 1.3–16× larger), so
   the re-import reads a better copy with its own timings. **884 is the reverse:** the weekly run read the 4.7 GB
   master `10-33.mkv`, the manifest holds the 149 MB `Sunday 26th April 2026.mp4`; **decided (operator 10-31): correct the item first** — point `2026-04-26-morning` at `10-33.mkv` (hash-checked) before the round that carries it; the manifest is bound to operation 4 by hash, so how a corrected item reaches a round (amended manifest and rebinding, or a per-item source override) is measured with step 2. **Who holds an identity shut today:** completed 883, 884, 888, 890, 893, 894,
   908–914, 916, 917, 920, 921 (`skip-exists`, 18) and 915, 919 (`skip-pending-review`). 918, 882 and 904 failed
   without blocking; 882 and 904 share identities with 916 and 913. Run 1 has no identity and no manifest item and
   is out of scope. **References to a published sermon:** only `media_processing_logs.sermon_id` and
   `sermon_scripture_filters` (both pipeline-derived); no other table or morph refers to one. The public address is
   `/{year}/{month}/{slug}` and the podcast GUID is `sermon-{id}`, so keeping the row keeps both: **step 3 moves
   the new cut into the published row** (id, slug and GUID unchanged) rather than a new row with a redirect, which
   there is no table for. 11 of the 13 published slugs are still placeholders (`sunday-7th-may-2023`); keeping the
   address keeps them, and re-slugging stays the separate `sermons:reslug-placeholders` question.
   *Step 2 retirement — BUILT 10-31, not applied:* `historic-import:retire-run --operation=<round>` accepts a live
   weekly livestream run with a date and service (item key = its identity); a published sermon is kept and recorded
   `awaiting_replacement`, an unpublished one withdrawn as before; the record carries `lane`, `operation_id` and
   `published_sections` (song clips leave every reader with their run, so step 3 must find and replace them too). A
   historic run's published sermon is still refused. Two guards added, tests first: a run with a retirement record
   never wins `ProcessingRunSupersessionService::reconcile` (only exclusion barred it, so a retired run with more
   confirmed songs would have been un-superseded when the re-import projected), and is never
   `BetterSermonRecording`'s holder. Dry run against operation 4 (`weekly-retire-census-20261031/retire-dry-run.txt`):
   19 runs resolve; 14 sermons kept, 857 (909, 1.1 GiB) and 1310 (893, 114 MiB) would be withdrawn. **Corrections
   to the census:** the blocking completed runs are 17, not 18; **918 must be retired too** (failed, blocks nothing,
   but its published sermon 865 needs the replacement marker or the re-import leaves two). **893 and 894 must not
   be retired as the manifest stands:** their items point at the X6 partial captures — `2024-01-21-morning` →
   `11-04.mkv` (1189's 1,840 s sermon-only file, set aside as `duplicate_recording`) and `2024-11-17-morning` →
   `11-02.mkv` (1126's 2,106 s sermon capture) — so a re-import would lose each service's songs and, for 893, re-run
   the file X6 set aside. Their full 720p recordings survive on `historic_temp` (sizes equal; no hash was recorded).
   890's item (`10-31.mkv`, 1378's full 1080p recording) is sound. **Decided (operator 10-31): correct both items
   to the full recordings, like 884,** then retire and re-import; 893 cuts its own sermon (1189 stays excluded) and
   894's re-import adopts 1126's 1080p sermon by the existing rule, as X6 wants. *Next (awaits go):* the manifest
   correction for 884, 893 and 894 (measure how a corrected item reaches a round under operation 4's hash binding,
   find 884's `10-33.mkv`, hash the two `historic_temp` copies), then step 3, release replacing a kept sermon and the
   published sections. Apply the retirement (19 runs plus 918) only after both, on the operator's word.
   **Superseded the same day — manifest correction is not possible under operation 4.** The importer's lock per
   item is `historic-video\0{manifestHash}\0{item_key}` (`HistoricVideoImporter::manifestItemDedupKey`), so an
   amended manifest re-keys all 474 items; and a *completed* own run counts as done even when superseded
   (`completedOwnJobKeys`), so `2024-01-21-morning` (1189) and `2024-11-17-morning` (1126) can never dispatch
   again. Operation 4 runs per identity: 1378 (890's, full `10-31.mkv`, 1080p), 1189 (893's, excluded), 1126
   (894's), 1377 (909's, full `10-31.mkv`, 1080p), each superseded by reconcile in favour of the weekly run; the
   other 16 identities have none. **Decided (operator 10-31): 893 and 894 keep their 10-15 results and are not
   retired; 884 is left out** (keeps its July cut from the 4.7 GB master; the 884/893/894 corrections above are
   withdrawn). **Retirement set, 17:** re-imported — 883, 888, 908, 910, 911, 912, 913, 914, 915, 916, 917, 918,
   919, 920, 921; handed back to their operation 4 run, which the round re-runs as an ordinary member — 890 (→ 1378)
   and 909 (→ 1377). Note 1377 still points at 909's sermon 857 (`sermon_id`), which retirement withdraws.
   *Step 3 release replacement — BUILT 10-31* (`RetiredRunReplacement`, called by
   `HistoricSermonPublicationService::releaseRecords` after the commit and on a completed retry; HIR7's claim,
   ledger and commit unchanged). A released cut whose date and service match a weekly run retired into this
   operation with `awaiting_replacement` is folded into that published sermon in one transaction: the pipeline's
   columns (paths, disk, operation, run link, title, reference, preacher, summary, quality, duration …) move;
   id, slug, date, service, state, download count and display choices stay, so the URL and the podcast GUID
   (`sermon-{id}`; the feed carries the latest 100, about 8 of the 13) are unchanged. The cut's run, sections and
   speaker samples are re-pointed, its scripture filters dropped (the observer rebuilds the row's from the new
   reference), the cut deleted; the old files stay on disk, recorded as `replaced_content` in the retirement and
   in the journal (`sermon_replaced`). The attempt's membership hash now comes from the named ids (sorted, unique:
   the same list as before, so no existing attempt changes), and a folded id resolves to its row, so a retry is
   still an exact no-op. Song clips: when the re-import's clip of a song is released, the retired run's published
   clip of the same song in the same service is withdrawn (state quarantined, bytes kept, `song_video_withdrawn`);
   a song the re-import does not release keeps its old clip. Census: 14 published song videos sit on the retired
   runs (908, 909, 911, 913, 917, 918, 920, 921), 890's four are quarantined; the 13 published sermons have no
   `asset_disk` (public default) and no operation. *Left:* apply the 17 retirements (operator's word), then the
   round. Release itself runs only where the round's release runs; nothing here publishes.
   **Retirements APPLIED 10-31 (operator: "Apply it")** to operation 4 (`weekly-retire-census-20261031/
   retire-apply-17.txt`): 17 runs retired and superseded, 13 published sermons kept awaiting replacement (842, 851,
   858–867, 870), 857 withdrawn (4 files under `quarantine/superseded/<operation>/857/` with `retirement.json`;
   1377's `sermon_id` nulled by the foreign key, so its re-run cuts its own), none of the 17 still blocks its
   identity, 884/893/894 untouched. Before applying, the Staging drive's Docker bind had gone stale after the drives
   were unplugged (owner-only folders read as empty in every container); `diskutil verifyVolume` plus a Docker
   Desktop restart cleared it. *Next:* the 15 re-imported identities and the 1377/1378 re-runs ride in the next
   round (processing on the operator's word).
   *Not in scope as defects:* the soft misses (1300 §4639, 1240 §2985, 1258) stay as cut unless a part above reaches
   them (soft misses are not worth breaking others); the sub-second regressions (961 §828, 1042, 1094, 1281, 1379,
   1292 §3653) are re-scored after each replay; 1288 merged Amens stay open on their own line (1273 §3416 and 1311 §4874 are disposed, (c) below); the five sermon-join eithers wait for a join shape in the register.
   *Order:* F7 (no pipeline code, cleans the scoring every replay reads), F1, F2, F3, F4, F5, F6. F1 and F2 share no
   code path; F4 and F5 both touch spoken starts, so F5's replay runs after F4 is in. One replay per part keeps each
   move attributable.
   4. (b), (c), then step 4.1–4.4; (d) is unaffected: its six ends (1004, 1005, 1115, 1127, 1157, 1219) are
      unchanged by the reword's measured draw. Publish (d) with whatever (a) and step 4.0's 958 add.
      **(d) superseded** (1004, 1005 answered; 1115, 1127, 1219 settled; 1157 covered: see above).
      **(b) causes given 10-30** (`storage/scratch/refusals-unassessable-20261030/`: `classify.py` → `causes.json`,
      `drift.py`, `bounds.py`; read-only, on `no-stand-in/` re-scored against today's register, which makes 98
      unassessable rulings, not 87; the pass predates `1773110fc`, `1208799c1`, `e95366011`, `02d2dfa5c`).
      *22 refused plans, none a defect:* 10 outputs of four **excluded runs** the round skips (955
      `source_audio_silent`; 1043 `rehearsal_duplicate`; 1051, 1098 `private_occasion`, X2): the measurement's
      `eligible-runs.txt` omitted G8's "not excluded", so the round's membership is **421**, not 425
      (`HistoricVideoRoundMembership` and both corpus-rerun actions already refuse excluded runs); 2 **no-sermon
      services** whose composition review is the right answer (978 LCM evening, 1144 holiday club); 6 sermon ends
      whose **K16 answer is banked under an older identity** (942, 1092, 1147, 1150 on stored sections; 1174, 1197
      after recompose moves the join; the identity hashes the prompt's lines), which the round asks live and step
      4's version bump re-asks anyway; 4 **edge-word windows not decoded** (954 §796, 1197 §165081, 1284 §3561 new
      after recompose; 1261's sermon never decoded), which the round decodes (the replay forbids HTTP).
      *98 unassessable rulings:* 7 sit on those refused plans. 73 are **section-boundary rulings on sections with no
      clip** (readings, prayers, `other`, sermon sections), which `analyze.py` only scores against clip plans; scored
      against the section's own bounds (`bounds.py`) 54 are met and 19 miss: 9 at no output edge (structure only:
      1025, 1028, 1034 ×2, 1120, 1224, 1259, 1314, 1268 §4738); 6 whose output edge is met or covered by an output
      ruling (1053, 1186, 1073 §267201 ×2, 1148, 1070 by `1773110fc`); 2 already listed above as open misses
      (1262 §3286 reading start = "1262 §3289", 1262 §3289 = "1262 §4913"); 2 **internal joins of D21's split
      sermons** (1073 part end 2694.92, ruled mid-sentence at 2697.92 on 10-18; 1268 join 1856.66, ruled
      mid-sentence, inward of 1855.0), which wait with the five join eithers for a join shape. The other 18, plus
      canary13:073 (scored as a false open miss against the wrong song), are **19 rulings whose section id now
      holds a neighbouring section** (canary 13: 949, 1012; canary 15 pairs: 1036, 1300; reading-end 949;
      endings 1036; full-pass 1255): those runs gained an earlier section after the pages were built, and the
      sync matches rows by `section_order`, so each later id holds the next item (949's "I Will Sing Of The Lamb"
      §4857 is now the reading; 1036's "Holy Holy Holy" §1479 is now the sermon). A register defect like F7's,
      unfixed: `capture.php` already re-keys "… opens/ends (" titles, but `gather.py` and `analyze.py` do not,
      and canary 15's "Song: …" titles match neither.
      **Re-bound 10-30 (operator: "Yes, do that fix")** in `scripts/rulings/section_binding.py` (tests
      `test_section_binding.py`), run by `gather.py` before F7's binding and supersession, on a fresh export of every
      ruled run's sections with titles (`section-id-drift-20261030/export-sections.php`, 227 runs). A ruling whose id
      is gone, holds another type, or holds another title the page named (not edges3, whose titles are relabelled),
      takes the section of its type, and title where one matches, whose edge is nearest the cut heard, within 30 s;
      an edge within 5 s of the cut keeps the id (1012's 4.66 start: re-detection split that clip, and §1288 still
      opens there). The page's id stays as `page_section_id`. **21 re-bound** (the 19 plus two already superseded);
      6 supersessions move, each to a later ruling on the true section (949 "Man Of Sorrows" end by canary 15's pair,
      949's song start canary 12 by canary 13, 1012 talk ends by the 10-12 endings, 1255 talk start by today's
      either). Re-scored: all 15 live re-bound rulings with a clip are met unchanged; open misses 55 → 54,
      unassessable 98 → 81. Ruled endings 650/650 (`section-id-drift-20261030/ruled-endings.log`).
   **(c) disposed 10-31: neither is a structure defect now; nothing to build.** Both "wrong place" answers were
   heard on canary 13's morning cuts (10-08): 1273 §3416 "All Heaven Declares" opening at 546.4, inside "Come People
   Of The Risen King"; 1311 §4874, the third "I Will Sing Of The Lamb" between the baptisms, opening at 1543.24,
   halfway through. Re-detection moved both before canary 14 that evening, which heard the new starts as right
   (`canary14:006-1273-start` 563.64, the previous song's end; `canary14:014-1311-start` 1514.14, after Ralph's
   baptism). Those rulings carried no section id until F7 (`4d72f2ab4`) bound them to §3416 and §4874, which
   superseded the two structure answers; the 10-29 "stand" reading predates that binding. Today's sections (563.64–675.06;
   1514.1–1593.26) and the replay's cuts (`no-stand-in/`, executed = A = B: 563.64–675.89, 1514.14–1585.26) meet
   every live ruling on both songs (ends: canary 13 keep 675.893; 10-20 either 1583.17 / 1585.26).
   Membership check passed: all 96 runs in `deleted-clips.json` are in the 425 eligible runs.
   **3a. Prerequisite found by step 3 — re-detection discarded unchanged sections' evidence (built
   10-29 night; operator: "Build it", then option "1"; corpus re-measure running, then suite and commit).**
   *Problem.* `ServiceSectionSyncService::hasMaterialSignatureChange()` decides whether a re-detected
   section is the same one. When it says no, the replacement branch drops the old metadata (banked
   `song_ocr_text`, the match record), resets publication to `not_applicable`, cleans up extracted
   assets and detaches song videos. It compared the section's item, its title and its exact times,
   and three of those misfired on unchanged spans: detection's classified sections carry only the
   draws' `oosItemId`, so a section bound to the run's own item arrived with none (933); planned items
   the draws did not cite arrived with none too, though the projection after the sync rebound the same
   item (108 in the corpus, e.g. 967 §1073); the projection recreates the run's own items each round,
   so ids churned (131, e.g. 939 §636: 20150 → cited 4883 → bound 24911); and re-detection's arithmetic
   returned 2010.5800000000002 for a stored 2010.5799999999999 (1120 §2009). On recompose that meant
   songs lost OCR confirmation, their catalogue titles ("Where, O grave…" → "Song") and the K16 answers
   that name them, or were re-OCR'd with paid calls where a source was staged, and unchanged clips
   stopped describing their recorded cut and lost their publication. Shared path: any re-detection of
   an already-projected run (`redetectServiceStructure`, on corpus re-run or recovered-transcript
   grounds, weekly runs included); a plain retry re-detects only if detection itself failed, before
   any item is bound.
   *Decision (operator, option 1 of three):* a section is unchanged when its type and span, to the
   millisecond, are unchanged, whatever its item or title. Matching re-derives the song
   (`withoutSongBinding()`), and a person-reviewed binding stays while the span is unchanged. Rejected:
   also requiring the normalised title (would still replace reworded draw titles) and moving the
   decision after the projection (a larger restructuring).
   *Built:* `materialIdentity()` returns type and millisecond-rounded bounds; the first, narrower carve-out
   for run-projected items was removed. `ChurchServiceItem::isAttestedOnlyByDetection()` (from
   `MatchSongsFromTranscript::onlyTheRunAttests()`) stays for matching's song withdrawal. Tests, red
   first: four unchanged-span shapes keep evidence, publication, clip and song video (933, 967 none
   cited, 939 another cited, a reworded title), float noise keeps publication, a moved bound or a changed
   type is still replaced. Two older expectations changed with the decision: canary 9's
   `an_unbound_section_given_a_different_title_*` now keeps its clip over the same span, and the
   re-detection that names another song drops the binding without marking the section superseded.
   Sync 47/47; matching, item, recompose, re-detection, sync and projection tests 498/498; Pint, PHPStan.
   *Measured (guarded replay, no OCR stand-in, `combined-measure-20261029/`):* the first, carve-out
   fix (`no-stand-in-fix1/`) cut unchanged-span publication resets from 582 to 169, leaving 60 songs
   unconfirmed and 60 re-titled; **option 1 (`no-stand-in/`) leaves 7 changed sections of 425 runs, none
   the defect:** 0 unconfirmed, 1 respelt; 4 songs (981 §1043, 1020 §1340, 1304 §3875, 1305 §3888; three
   published) whose span genuinely changes at detection and is restored by the later song-edge steps,
   so they reset and need re-approval after the round (only option 3 would avoid it; recorded as a
   residual); 2 move between two catalogue songs both titled "Great Is The Lord" (318/317; 977 §1009,
   1305 §3886: an OpenLP duplicate for curation); 1344 §4342's fuzzy title-hint match no longer stands
   (current policy). Refusals 27 → 22 and unassessable rulings 93 → 87 against the stand-in pass. No
   provider call, job or file write in any pass. Separate finding: the projection recreates the run's own items
   each round. **Measured 10-31, harmless; nothing to build** (`storage/scratch/item-churn-20261031/`, read-only on
   the replay's sections A/B and today's rows): 1,085 sections on 260 runs get a new item (433 prayers, 253 sermons,
   182 talks, 157 readings, 30 notices, 18 songs, 12 welcomes). The 1,084 items they leave are all `livestream`-
   sourced, in 259 services none of which has a reviewed canonical revision (reviewed services are staged, never
   synced), and carry only projection output (`section_summary`, `source_evidence`, `livestream_projection`, 157
   `scripture_reference`): no occurrence state or manual decision, no canonical identity, no usage report, no other
   section's expected or matched item, no route. None has planned provenance, so none is in the ensemble prompt and
   churn cannot change a draw's input hash. Of the 18 songs, 17 keep their song (several rebind from the run's item
   to an email or OpenLP one); 977 §1009 is the "Great Is The Lord" duplicate above. Cost: ~1,084 soft-deleted rows
   per round.
4. **Dispose of residual misses** (rule decided 10-30: register E23 — measured class, no mend without breaking a heard-right edge, no substantive loss → recorded soft miss; 928's one word accepted under it). 928 still loses one word at a speech start over fading music;
   this is separate from its internal end in step 2. The measured class is 2 of 30 fade ends (928
   and 1220, the latter ruled either). Merely classifying mixed words as unsung does not fix 928's
   gap search. Record measured repair or containment for the class. 1131 is an early-end miss today
   (current 1410.28; ruled 1461.42) that the adopted reword mends (below). Include all further material
   misses from step 3; neither a recorded miss nor a fixture marked open is acceptance.
   **Residual misses still needing a disposition (review 10-30):** 928's speech start over fading music
   (class 2 of 30, 1220 ruled either); the 9 open misses from step 3 (late sermon/reading starts 1136,
   1308, 1262 §3289; `song_end_fade` ends 1215 §2637, 1108 §1895, 1108 §1897; singles 1262 §3278,
   1262 §4913, 1108 canary-11 start); the conflicting rulings 1115 and 1127 (on page (d)). Each gets one
   of: a measured rule (only where the class has a single cause: `song_end_fade` missed 9 of 63, early and
   late alike, so it has none), a code-found hold that keeps the output in review, or an accepted loss in
   the operator's words. **Operator decision owed:** what counts as a finished disposition when no rule
   fits, hold or accepted loss.
   **K16 class measured 10-29 night (step 3, `combined-measure-20261029/shortened-ends.json`):** of 188
   sermon ends the round would shorten, 176 drop only an announcement, lyrics or the congregation's
   Amen; 7 drop the closing prayer with an end that still reads as one (acceptable under K16 as softened
   10-29 night); **2 drop the last point: 1131 and 1140** (new: "As we draw to our close… how can you not
   glorify God?"); 4 need hearing (1004, 1005, 1157, 1219; draft page `shortened-ends-listening-20261029/`).
   1140's banked answer is untied, outside the parked reword's 116-end measurement. **Reword asked on
   1140 (operator: "You can make the calls"; 3 Luna calls, `k16-reword-20261029/answers/1140.json`):**
   it keeps the application, 1808.62 → 1853.29, though that cue carries a dangling "Let's" of the next
   line; 1131 → its ruled 1461.42. Record now 2 mended, 1 broken (968), untied ends unmeasured. Next: ask
   the reword on every untied end after the fixed re-measure (≈735 calls, ≈$0.55 at the 08-18 price
   snapshot), recording each answer's full identity but **not banking it** (operator: redraw changes
   "are useful learning for future weekly processing"). If adopted, the round redraws every K16 end as
   weekly would; its decided ends compared with the measured ones give the redraw change rate (§4.2),
   and only flips that change a heard cut go to listening.
   **Untied ends measured 10-30 (`k16-reword-20261029/measured-untied.json`, answers with identities in
   `answers/`, nothing banked):** 417 K16 ends (113 tied, 301 untied, 5 unanswered, 6 without a question);
   643 Luna calls, ≈ $0.31. The reword changes 10 untied ends. Read from the transcript: better 1140,
   1044 (keeps the closing prayer), 1098 (keeps the preacher's Amen), 1008, 1071, 1086, 1096 (drop
   housekeeping, a communion transition, an after-service invitation); worse **1375 and 1324, which
   take in quoted lyrics and the next hymn's title** — the reworded prompt's "even when it mentions,
   quotes or leads into the song" contradicts K16's "reading lyrics aloud alone does not establish that
   they conclude the sermon"; 958 (a point built round the hymn title "Facing a Task
   Unfinished") was listed to hear, but is already ruled: see step 0 (ii). With the tied result: 9 better,
   3 worse (968, 1375, 1324), and 958 a fourth loss unless "either" is reaffirmed.
   **Adopt the reword as measured (operator 10-30: option 2, "Current version seems fine to me"; not
   refined for 1375/1324; plan only — do it in a fresh session).** The losses 968, 1375 and 1324 are
   accepted. The round then redraws every K16 end (§4.2 redraw stability; nothing banked to stop it).
   0. *Ledger first (review 10-30).* `edge-rulings.json` does not hold the K16 answers that score this
      change. (i) The 10-29 tied page (`k16-tied-listening-20261029/answers/rulings/`, 11 operator answers,
      A = the current end, B = through to the song) was never gathered: `scripts/rulings/gather.py` has
      sources for `three-ends` and `k16-reword` but none for it. Add it, re-gather and supersede the
      agent-review rulings it contradicts: **1131** B (24:21.4 ≈ 1461.4) against the unsuperseded
      `sermon-own-last-line-20261028` keep 1410.59; **1001** B (32:19.2) against
      `sermon-own-line-songs-20261028` keep 1924.76 (respect the archive's mid-sentence note on 1001's
      third answer). Then check the other nine (938 A, 1244 A; 956, 973, 1031, 1111, 1318, 1364 either)
      against what the ledger holds; 1031, 1318 and 1364 have no current sermon-end ruling at all.
      (ii) **958 is ruled, not unheard:** current `sermon-own-last-line-20261028` keep 1993.88 (agent
      review, "matching the 10-28 preferred cut"); the operator's 10-28 either (1993.81 / 2017.775) is
      superseded. The reword moves it to 2016.78, so under the ledger it is a fourth loss. **Operator
      decision owed:** reaffirm "either" (2016.78 then acceptable) or accept the loss alongside 968, 1375
      and 1324. (iii) 1140, 1375 and 1324 have no ruling: their gain and losses were read from the
      transcript. Record each as an `answer_source: claude-review` ruling so the ratchet carries the decision.
      **Done 10-30.** `gather.py` gains source `k16-tied-20261029` (11 rulings): 1131 keep 1461.42 and the
      nine others supersede or agree with what the ledger held; 1183's B stays superseded by its later
      reword answer. 1001's B was chosen over a mid-sentence A (1927.2), not over "…Make it today.", so it
      is recorded as either 1924.76 / 1939.2. **958: operator reaffirmed "either"** (`operator-20261030`,
      1993.88 / 2016.78), so the reword's 958 end is no loss. `claude-review-20261030`: 1140 outward from
      1808.62 (the reword's 1853.29 keeps the last point but carries a dangling "Let's", so it is not kept);
      1375 keep 1318 and 1324 keep 3307. Ledger 1,771 rulings; no other ruling changed beyond 8 newly
      superseded. `RuledEndingsRegressionTest` 41/41.
   1. *Code, test first.* `SermonSongIntroductionCheck::SYSTEM_PROMPT` takes exactly the two
      replacements in `k16-reword-20261029/measure.php` (saved whole in `system-prompt.txt`) and
      `PROMPT_VERSION` 2 → 3, so every banked answer stops matching. `SermonSongIntroductionCheckTest`
      pins version 2 (line ~480); update it red-first, with a test that a banked v2 answer is not read.
   2. *Re-bank the ruled-endings fixtures, no re-capture.* 587 of 591 fixtures (322 runs) carry banked
      K16 artifacts, matched by an identity that includes `prompt_version`, so all would fail on missing
      evidence after step 1. Measured 10-30: of 1,061 artifacts, 585 ask exactly the question tonight's
      measurement asked, 451 differ only in `media_processing` (the encoder/version a fixture is pinned
      to; question text, sermon end and song start identical, so the measured answer stands), 23 are a
      different question (older transcript, sermon end or song start) and 2 are runs with no measured
      answer (893, 945; not eligible). For each artifact write the v3 artifact: the same identity with
      `prompt_version: 3`, calls from `answers/<run>.json` where the question fields match, otherwise
      the reword asked on the artifact's own stored `prompt` (≈ 60 Luna calls for the 25), and the
      decision computed through `decision()` exactly as `bank()` does; remove the v2 artifact so
      fixtures do not double. The seven fixtures with a `song_introduction` block replay their own
      calls and need the block's calls re-asked the same way. Fixture `files` are lists of
      `{disk, path, content}`; the artifact kind is `sermon-song-introduction-<sha256 of identity>`.
   3. *Ruled endings.* Run them; every changed result is checked against its ruling, not adjusted to
      pass. Of the named runs only 968 has a sermon-end fixture today (`run-968-2026-10-29`, its
      three-ends and k16-reword rulings), so as captured the suite shows **968 newly missed and nothing
      else**: 1131's fixture (`run-1131-2026-10-23`) holds only its start ruling, and 1140, 1375, 1324 and
      958 have no fixture. Capture fixtures for the step 0 rulings first; then expect 1131 and 1140 newly
      met and 968, 1375 and 1324 (and 958 unless reaffirmed) newly missed, recorded as accepted losses
      under this decision (with the operator's words) rather than silently re-expected.
   4. Focused tests, PHPStan, Pint, the full parallel suite, Dusk; record; commit to master.
   5. *Live:* nothing to apply now. The round's `TranscribeOutputEdges` asks every K16 end afresh
      (≈ 1,000 calls, ≈ $0.50); compare its decided ends with `measured*.json` for the redraw change
      rate, and send only flips that change a heard cut to listening.
   **BUILT 10-31 (operator: "Yes", then "Yes to all three" on the findings below).** Step 1: the reword is
   `SermonSongIntroductionCheck::SYSTEM_PROMPT` byte for byte (sha256 `43233283…`, the hash every measured answer was
   asked under), `PROMPT_VERSION` 3; tests red first (version pin, a v2 answer not read, the rule sent). Step 2
   (`storage/scratch/k16-rebank-20261031/`: `rebank.php` → `mapping.json`, `apply.py`): 1,069 artifacts in 591
   fixtures re-banked, 1,044 from `answers/` and 25 asked (11 distinct prompts, **25 Luna calls**); each v2 identity
   was checked to hash to its kind after two repairs (`putJson` drops a whole-second time's `.0`, 1087's 2083.0; some
   fixtures were re-saved with sorted keys). Today's `decision()` moves 26 artifacts on 10 runs. Step 3 found what the
   plan missed: every moved end first fails its fixture's pinned live cut, so only then can its ruling be read —
   - **962 was a planner defect:** its sermon output ends with the Lord's Prayer as a part of its own; the answer ends
     the sermon at 3757.1, before that part starts, and the last span inverted (`PublicationPlanValidator` threw).
     `CueSafeExtractionPlan::forSpans()` now drops a part the K16 end falls before (test
     `an_end_before_a_trailing_part_drops_that_part`); the question and operator-answer identities keep the output as
     composed. 962 → 3757.26, at the operator's either (3757.1 / 3798.94).
   - **Three new end windows decoded** read-only with local Whisper (`decode.php`, nothing banked on the runs; identities
     from each fixture's own rebuild) and added to their fixtures (`add-words.py`): 1071 1051.64–1058.64, 1096
     3678.98–3683.74, 1275 4119.42–4124.78.
   - **Pinned cuts re-pinned** from each fixture's replay (`refresh-live.py`, prior files in `before/`) on 11
     fixtures, `captured_on` 10-31 so the rulings since apply. Outcomes: 1131 met (1461.74 v keep 1461.42); 1044, 1183,
     1275 at an either point; 968 the accepted loss, recorded `operator-20261030:968-sermon-end` (either 2670.9 /
     2672.68, the operator's 10-30 words); **1071** drops "Let's pray together. Thank you." before an unrecorded prayer,
     recorded `claude-review-20261031:1071-sermon-end` either 1057.7 / 1060.04 under the 10-28 sermon-end ruling;
     **1096 is a further loss the measurement mislabelled "better":** already an open miss (outward from 3692.28: the
     invitation to talk after the service clipped), the reword ends earlier still, on "…God's Son, the Lord Jesus."
     (3682.99), dropping the invitation whole. Its end fixture carries no fixture-level gate, so it stays an open miss
     on its ruling; a K16 "evangelistic invitation after the sermon" class is unmeasured.
   Step 4: ruled endings 658/658, full parallel suite 10,953 (1 skipped), PHPStan, Pint, rulings unit tests, Dusk 63/63.
   Step 5 unchanged: the round asks every K16 end afresh. **Step 4 of §4.1a is done; next is step 5 (validate, freeze).**
5. **Validate, freeze and canary.** Before freezing: ≥ 10 GB free on the internal disk (step 3);
   the structure dispositions for 1273 §3416 and 1311 §4874 (done 10-31, step 3 (c)); the item-churn finding (step 3a) measured
   or explicitly deferred (measured 10-31: harmless); and the round's checklist naming the four songs that reset and need
   re-approval after it (981 §1043, 1020 §1340, 1304 §3875, 1305 §3888) and the 126 re-cut sections.
   Focused tests, PHPStan, Pint and full parallel suite on final
   code; Dusk for browser behaviour. Follow runbook §§3/6 for live headroom, backups, frozen
   membership, worker revision and pause/resume. Inspect actual song-to-speech transitions and
   internal joins, and the audit's representative local acceptance journey before bulk expansion.
   Stop expansion on regressions or unassessable results. Acceptance follows the round, not its label.
   **Round checklist (drafted 10-31; read-only checks run 10-31 20:00Z; nothing frozen, dispatched or backed up):**
   - *Membership, 423 re-runs + 15 imports.* The 421 measured runs (`combined-measure-20261029/eligible-runs.txt`, 425
     less the excluded 955, 1043, 1051, 1098), which hold all 96 runs of the 126 deleted clips (`deleted-clips.json`;
     the diff must show each with a new recorded clip); **1377 and 1378**, handed back from retired 909 and 890, never
     in the combined measurement (canary one of them); the **15 re-imported identities** through
     `sermons:import-historic-videos --manifest … --only=<keys>` under operation 4 (883, 888, 908, 910–921's items;
     release folds each cut into the kept published sermon, `RetiredRunReplacement`). Not members: 884, 893, 894
     (keep their results), 1126, run 1.
   - **Owed before the snapshot (finding 10-31): 1377 and 1378 are still superseded** (`superseded_at` set, by 909 and
     890), so `rerun-extract` would refuse 1377 ("superseded or retired"); retirement superseded the weekly runs but
     nothing re-ran the reconcile. `services:supersede-duplicate-runs --service=795 --service=790` (dry run) makes
     1377 and 1378 the winners again; it needs `--execute` on the operator's word. **Applied 10-31 20:09Z
     (operator: "Do step 1"):** 1377 and 1378 live (`superseded_at` null); 909 → by 1377, 890 and 889 (a 10-15 weekly
     duplicate) → by 1378; retirement records unchanged; both services' review recomputed to `needs_review`.
   - *Routes.* `reExtract`, never recompose: 942, 974, 1363 (hand edits); 1378 (banked edge evidence invalid, runbook
     §4.2). Held sermons by `sermons:re-extract --held-section`. Every K16 end is asked afresh (≈ 1,000 calls,
     ≈ $0.50) by `TranscribeOutputEdges`, prayer-last sermons included.
   - *After the round:* re-approve 981 §1043, 1020 §1340, 1304 §3875, 1305 §3888 (their spans change at detection);
     check the 126 re-cut clips; compare decided K16 ends with `k16-reword-20261029/measured*.json` (redraw change
     rate, §4.2) and send only flips that change a heard cut to listening; 977 §1009 / 1305 §3886 "Great Is The Lord"
     duplicate for curation.
   - *Freeze checks, 10-31 20:00Z:* git clean at `dbf6b2903`; `SPEAKER_IDENTIFICATION_ENABLED=false`; queues empty on
     DB 0/1 (only `:notify` tokens), `failed_jobs` 517 (old; the watcher's baseline); main's workers booted 19:48:44Z,
     after the last commit; internal disk 56 GiB free (Docker sees 57 GiB), Staging 485 GiB, **Sonnics 53 GiB (98 %
     full)** against a backup of ≈ 14 GiB private storage plus the dump; Staging and Sonnics at 5 Gb/s, Staging read
     168 MB/s; clone `../crockenhill-round` at `8189bfdea` (150 commits behind; lockfiles unchanged, so `vendor/`
     stands), `sail-8.4/app` present, `docker-compose.round.yml` tracked. Re-measure all of it immediately before the
     freeze (runbook §6).
   - *Then, each on the operator's word:* backup (dump + private storage + binlogs, SHA-256, to
     Sonnics); clone to the round commit; runbook §3 setup; snapshot; staged start.

Detailed measurements and prior interpretations are in the
[pre-freeze record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md#41a-before-the-next-round-regression-coverage-and-combined-verification-2026-10-29).

### 4.2 Regular uploads are the lasting outcome

Weekly processing resumes after the historic import is done (R8); preparation can proceed now.
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
- [ ] Measure model redraw stability: a weekly run gets one draw. Where a round re-asks a model question
  (first case: K16 if the reword is adopted, §4.1a step 4), compare the decided outcome with the one
  measured before and record the change rate and the flips; do not bank answers to suppress it.
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

| Decision | Current evidence / next action |
|---|---|
| Next processing round | Not ready to freeze: complete §4.1a in the 10-30 order (ledger, edge moves and K16 build done 10-31 → validate), the listening pages (958 and E23 decided 10-30); internal disk cleared to 12 GiB 10-30. Membership (425) is counted. |
| Content acceptance | Not established for the next outputs: audit current artifacts under §4.3/§4.5. |
| Production convergence and delivery | Not established by local processing: programme census, rehearsal and destination checks remain. |
| Public release | No batch authorised. Programme §8 owns the separate signed act. |

**Maintenance:** replace statuses in place, linking durable evidence. Do not append session diaries
or copy procedures into this worklist. Archive completed execution detail with a dated supersession
header; preserve unresolved work explicitly. New facts belong here only when they change next action,
acceptance status or an unresolved dependency.
